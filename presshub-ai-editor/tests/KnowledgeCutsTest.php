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
kc_check( 'describe: no room without a room value', [ 'z: left out, needed 40 characters (limit 30)' ] === $C::describe( [ [ 'item' => 'z', 'reason' => 'no_room', 'limit' => 30, 'needed' => 40 ] ] ) );
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

// The next call in the same request does not inherit the cut.
delete_option( 'presshub_ai_policy_fiches_budgets' );
$P::apply( 'SYS', 'Καιρός και αθλητικά.', 'social_copy_facebook' );
kc_check( 'apply: a later call without cuts leaves no cut behind', null === $P::last_applied() );

// 3. Inspector.
$ins = PressHub_AI_Generation_Inspector::get_structured_record( (int) $rid );
$html = is_array( $ins ) ? PressHub_AI_Generation_Inspector::render_tab( $ins, 'overview' ) : '';
$esc_id = PressHub_AI_Generation_Recorder::record( [ 'kind' => 'generic', 'config' => [ 'policy_fiches_left_out' => [ [ 'item' => '<b>x</b>', 'reason' => 'no_room', 'limit' => 5, 'needed' => 9 ] ] ], 'system_prompt' => 'S', 'user_prompt' => 'U', 'response_text' => 'OK' ] );
$esc_html = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( (int) $esc_id ), 'overview' );
kc_check( 'inspector: item names are escaped', false === strpos( $esc_html, '<b>x</b>' ) && false !== strpos( $esc_html, '&lt;b&gt;x&lt;/b&gt;' ) );
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
update_option( 'presshub_ai_editorial_agenda_budgets', array_merge( $A::BUDGETS, [ 'social_copy_' => 5 ] ) );
$none = $A::apply( 'SYS', 'social_copy_facebook' );
$last = $A::last_applied();
kc_check( 'agenda: nothing fits, prompt unchanged, cut kept', 'SYS' === $none && is_array( $last ) && 0 === $last['chars'] && 2 === count( $last['left_out'] ) );
$A::clear_last_applied();
delete_option( 'presshub_ai_editorial_agenda_budgets' );

// People: the maximum number per call is recorded.
$S    = 'PressHub_AI_Stakeholders';
$many = [];
for ( $i = 0; $i < $S::MAX_INJECTED + 2; $i++ ) {
    $many[ 'p' . $i ] = [ 'count' => 1, 'fiche' => [ 'name' => 'Πρόσωπο ' . $i, 'name_en' => '', 'role' => '', 'party' => '', 'bio' => '', 'positions' => [] ] ];
}
$rep = $S::render_report( $many, 100000 );
kc_check( 'people: over the maximum recorded as max_items', $S::MAX_INJECTED === count( $rep['people'] ) && 2 === count( $rep['left_out'] ) && 'max_items' === $rep['left_out'][0]['reason'] );
$one = [ 'p' => [ 'count' => 1, 'fiche' => [ 'name' => 'Πρόσωπο', 'name_en' => '', 'role' => 'Υπουργός', 'party' => '', 'bio' => '', 'positions' => [ [ 'date' => '2026-11-01', 'topic' => 'x', 'position' => str_repeat( 'θέση ', 30 ) ] ] ] ] ];
$short = $S::render_report( $one, 40 );
kc_check( 'people: shortened names only the parts it had', 1 === count( $short['left_out'] ) && 'shortened' === $short['left_out'][0]['reason'] && [ 'positions' ] === $short['left_out'][0]['parts'] );

// 5. Articles: the per-article cap and the article count cap are recorded.
update_option( 'presshub_ai_curation_max_articles', 2 );
update_option( 'presshub_ai_curation_max_chars_per_article', 100 );
$curator  = new PressHub_AI_News_Curator();
$articles = [
    [ 'title' => 'Μακρύ άρθρο', 'source' => 'philenews', 'url' => 'https://a.test/1', 'content' => str_repeat( 'α', 250 ) ],
    [ 'title' => 'Σύντομο άρθρο', 'source' => 'politis', 'url' => 'https://a.test/2', 'content' => str_repeat( 'β', 50 ) ],
    [ 'title' => 'Τρίτο <b>άρθρο</b>', 'source' => 'sigma', 'url' => 'https://a.test/3', 'content' => str_repeat( 'γ', 50 ) ],
];
$before = strlen( (string) @file_get_contents( $log_file ) );
$curator->format_articles_context( $articles );
$bd  = $curator->last_articles_breakdown();
$sum = $C::article_cuts( $bd['sent'], $bd['dropped'], [ 'cap_articles' => 2, 'cap_chars_per_article' => 100 ] );
kc_check( 'articles: one shortened, 150 characters cut, one left out by the cap', 2 === $sum['sent'] && 1 === $sum['shortened'] && 150 === $sum['chars_cut'] && 1 === $sum['left_out'] );
$alog = substr( (string) @file_get_contents( $log_file ), $before );
kc_check( 'articles: one log line with the counts', false !== strpos( $alog, 'Article limits cut the briefing_curation prompt: 1 of 2 articles shortened to 100 characters (150 characters cut), 1 left out by the 2-article cap.' ) );
$alines = $C::describe_articles( $sum );
kc_check( 'articles: describe says what was cut and points to the Articles tab', 3 === count( $alines ) && false !== strpos( $alines[0], '1 of 2 articles cut to 100 characters' ) && false !== strpos( $alines[1], '1 articles left out: only 2 are sent' ) );
kc_check( 'articles: not selected by the editor is not a limit', 0 === $C::article_cuts( [], [ [ 'title' => 'x', 'reason' => 'not_selected' ] ], [] )['left_out'] );
kc_check( 'articles: nothing cut, nothing described', [] === $C::describe_articles( $C::article_cuts( [ [ 'title' => 'x', 'chars_sent' => 5, 'chars_original' => 5, 'truncated' => false ] ], [], [] ) ) );
$before = strlen( (string) @file_get_contents( $log_file ) );
update_option( 'presshub_ai_curation_max_articles', 40 );
update_option( 'presshub_ai_curation_max_chars_per_article', 3000 );
$curator->format_articles_context( $articles );
kc_check( 'articles: no log line when nothing was cut', strlen( (string) @file_get_contents( $log_file ) ) === $before );

$art_id = PressHub_AI_Generation_Recorder::record( [
    'kind'          => 'briefing_text',
    'config'        => [],
    'inputs'        => [ 'articles' => $bd, 'totals' => [ 'cap_articles' => 2, 'cap_chars_per_article' => 100, 'articles_truncated' => true ] ],
    'memory'        => [ 'enabled' => true, 'budget_chars' => 4000, 'entries' => [], 'trimmed' => [ [ 'entry_id' => 7, 'reason' => 'budget_oldest_background' ] ] ],
    'system_prompt' => 'S',
    'user_prompt'   => 'U',
    'response_text' => 'OK',
] );
$art_rec = PressHub_AI_Generation_Inspector::get_structured_record( (int) $art_id );
$ov      = PressHub_AI_Generation_Inspector::render_tab( $art_rec, 'overview' );
kc_check( 'inspector: articles cut shown under "Left out because of limits"', false !== strpos( $ov, 'Left out because of limits' ) && false !== strpos( $ov, '1 of 2 articles cut to 100 characters each' ) && false !== strpos( $ov, '1 articles left out' ) );
kc_check( 'inspector: memory trims shown with the budget', false !== strpos( $ov, '1 memory entries or stories left out to fit the memory budget of 4000 characters' ) );
kc_check( 'inspector: usage row gives the counts, not just yes', false !== strpos( $ov, 'yes: 1 shortened, 1 left out' ) );
$at = PressHub_AI_Generation_Inspector::render_tab( $art_rec, 'articles' );
kc_check( 'inspector: Articles tab shows the full length and the totals', false !== strpos( $at, 'Characters in full' ) && false !== strpos( $at, '>250<' ) && false !== strpos( $at, 'Characters cut from them' ) && false !== strpos( $at, 'Articles left out by the article cap' ) );
kc_check( 'inspector: article titles are escaped', false === strpos( $at, 'Τρίτο <b>άρθρο</b>' ) );
delete_option( 'presshub_ai_curation_max_articles' );
delete_option( 'presshub_ai_curation_max_chars_per_article' );

@unlink( $log_file );
if ( $failures ) {
    echo "KnowledgeCutsTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "KnowledgeCutsTest: OK ({$checks} checks)\n";
