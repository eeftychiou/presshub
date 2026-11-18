<?php
/**
 * KnowledgeCutsTest — every cut a context limit makes is recorded.
 *
 *  1. PressHub_AI_Knowledge_Cuts: describe() per reason, sanitize(), log().
 *  2. Policy fiches: the at-most-3 limit and "no room" are reported; apply()
 *     records a cut even when nothing was sent.
 *  3. The generation record stores the cuts and the inspector shows them.
 *  4. The agenda records a dropped theme with the call.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) { $GLOBALS['DEACTIVATION_HOOKS'][] = $callback; }
}
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function kc_check( string $label, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}

$C = 'PressHub_AI_Knowledge_Cuts';
$P = 'PressHub_AI_Policy_Fiches';
$A = 'PressHub_AI_Editorial_Agenda';
$log_file = sys_get_temp_dir() . '/presshub-cuts-test-' . uniqid() . '.log';
PressHub_AI_Logger::set_log_file_path( $log_file );
PressHub_AI_Generation_Recorder::create_table();

// 1. describe(), sanitize(), log().
$lines = $C::describe( [
    [ 'item' => 'a', 'reason' => 'no_room', 'limit' => 1200, 'room' => 100, 'needed' => 300 ],
    [ 'item' => 'b', 'reason' => 'shortened', 'parts' => [ 'facts', 'questions' ], 'limit' => 3000, 'needed' => 3400 ],
    [ 'item' => 'guidance', 'reason' => 'clipped', 'limit' => 600, 'needed' => 900, 'kept' => 500 ],
    [ 'item' => 'd', 'reason' => 'max_items', 'limit' => 3 ],
    [ 'item' => 'e', 'reason' => 'bogus' ],
] );
kc_check( 'describe: one line per known reason, unknown dropped', 4 === count( $lines ) );
kc_check( 'describe: no room says needed, left and limit', false !== strpos( $lines[0], 'needed 300 characters, 100 left (limit 1200)' ) );
kc_check( 'describe: shortened names the parts', false !== strpos( $lines[1], 'without facts, questions' ) );
kc_check( 'describe: clipped says how much was kept', false !== strpos( $lines[2], 'cut to 500 of 900' ) );
kc_check( 'describe: max items says the maximum', false !== strpos( $lines[3], 'only 3 are sent' ) );
kc_check( 'sanitize: keeps numbers as numbers', [ [ 'item' => 'x', 'reason' => 'no_room', 'limit' => 5 ] ] === $C::sanitize( [ [ 'item' => 'x', 'reason' => 'no_room', 'limit' => '5', 'junk' => 'y' ] ] ) );
kc_check( 'sanitize: not a list gives an empty list', [] === $C::sanitize( 'x' ) );
$C::log( 'policy_fiches', 'social_copy_x', [ [ 'item' => 'a', 'reason' => 'no_room' ] ] );
$log = (string) @file_get_contents( $log_file );
kc_check( 'log: one line with the kind, the task and the items', false !== strpos( $log, 'Context limit cut 1 item(s) of policy_fiches from social_copy_x' ) );
$before = strlen( $log );
$C::log( 'policy_fiches', 'social_copy_x', [] );
kc_check( 'log: nothing when nothing was cut', strlen( (string) @file_get_contents( $log_file ) ) === $before );

// 2. Policy fiches: the at-most-3 limit and no room.
$hits = [];
foreach ( [ 'ενα', 'δυο', 'τρια', 'τεσσερα' ] as $i => $s ) {
    $hits[ $s ] = [ 'count' => 4 - $i, 'fiche' => $P::normalize( array_merge( $P::empty_fiche( 'Θέμα ' . $s ), [ 'status' => 'published', 'framing' => 'Κανόνας ' . $s . '.' ] ) ) ];
}
$four = $P::render( $hits, 'briefing_curation', 10000 );
kc_check( 'policy: 3 sent, the fourth recorded as over the maximum', 3 === count( $four['slugs'] ) && 1 === count( $four['left_out'] ) && 'τεσσερα' === $four['left_out'][0]['item'] && 'max_items' === $four['left_out'][0]['reason'] && $P::MAX_INJECTED === $four['left_out'][0]['limit'] );

// apply() with a budget too small for anything: nothing sent, cut recorded.
delete_option( $P::OPTION );
$pslug = $P::create( 'Συντάξεις', [ 'aliases' => [ 'συντάξεις' ] ] );
$P::save_core( $pslug, [ 'title' => 'Συντάξεις', 'aliases' => [ 'συντάξεις' ], 'framing' => str_repeat( 'Οι αριθμοί αποδίδονται. ', 10 ), 'status' => 'published' ], 1 );
update_option( 'presshub_ai_memory_enabled', '1' );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( $P::BUDGETS, [ 'social_copy_' => 20 ] ) );
$t0  = PressHub_AI_Generation_Recorder::begin_call();
$out = $P::apply( 'SYS', 'Νέα για τις συντάξεις σήμερα.', 'social_copy_facebook' );
kc_check( 'apply: nothing fits, prompt unchanged', 'SYS' === $out );
$last = $P::last_applied();
kc_check( 'apply: the cut is kept for the record', is_array( $last ) && [] === $last['fiches'] && 0 === $last['chars'] && ! empty( $last['left_out'] ) );
$rid = PressHub_AI_Generation_Recorder::finish_call( $t0, 'generic', null, 'SYS', 'U', 'OK' );
$rec = PressHub_AI_Generation_Recorder::get( (int) $rid );
$cfg = is_array( $rec['config'] ?? null ) ? $rec['config'] : json_decode( (string) ( $rec['config'] ?? '' ), true );
kc_check( 'record: policy cut stored with the call', ! empty( $cfg['policy_fiches_left_out'] ) && $pslug === $cfg['policy_fiches_left_out'][0]['item'] && 20 === $cfg['policy_fiches_left_out'][0]['limit'] );
kc_check( 'record: prompt as sent', 'SYS' === $rec['system_prompt'] );

// 3. Inspector.
$ins = PressHub_AI_Generation_Inspector::get_structured_record( (int) $rid );
$html = is_array( $ins ) ? PressHub_AI_Generation_Inspector::render_tab( $ins, 'overview' ) : '';
kc_check( 'inspector: "Left out because of limits" with the fiche', false !== strpos( $html, 'Left out because of limits' ) && false !== strpos( $html, esc_html( $pslug . ': left out' ) ) );
$plain_id = PressHub_AI_Generation_Recorder::record( [ 'kind' => 'generic', 'config' => [], 'system_prompt' => 'S', 'user_prompt' => 'U', 'response_text' => 'OK' ] );
$plain    = PressHub_AI_Generation_Inspector::get_structured_record( (int) $plain_id );
kc_check( 'inspector: no section when nothing was cut', false === strpos( PressHub_AI_Generation_Inspector::render_tab( $plain, 'overview' ), 'Left out because of limits' ) );
delete_option( 'presshub_ai_policy_fiches_budgets' );

// 4. Agenda: a dropped theme is recorded with the call.
$A::save( [
    [ 'title' => 'Κυπριακό', 'priority' => 5, 'notes' => str_repeat( 'Πλαισίωση. ', 20 ) ],
    [ 'title' => 'Δευτερεύον θέμα', 'priority' => 1, 'notes' => str_repeat( 'Σημείωση. ', 20 ) ],
], '', 1, '' );
update_option( 'presshub_ai_editorial_agenda_budgets', array_merge( $A::BUDGETS, [ 'social_copy_' => 300 ] ) );
$t1 = PressHub_AI_Generation_Recorder::begin_call();
$A::apply( 'SYS', 'social_copy_facebook' );
$rid2 = PressHub_AI_Generation_Recorder::finish_call( $t1, 'generic', null, 'SYS', 'U', 'OK' );
$rec2 = PressHub_AI_Generation_Recorder::get( (int) $rid2 );
$cfg2 = is_array( $rec2['config'] ?? null ) ? $rec2['config'] : json_decode( (string) ( $rec2['config'] ?? '' ), true );
kc_check( 'agenda: the dropped theme is in the record', ! empty( $cfg2['editorial_agenda_left_out'] ) && 'Δευτερεύον θέμα' === $cfg2['editorial_agenda_left_out'][0]['item'] );
delete_option( 'presshub_ai_editorial_agenda_budgets' );

@unlink( $log_file );
if ( $failures ) {
    echo "KnowledgeCutsTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "KnowledgeCutsTest: OK ({$checks} checks)\n";
