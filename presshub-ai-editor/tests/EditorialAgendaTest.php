<?php
/**
 * EditorialAgendaTest — Issue #198: editor-maintained current agenda fiche.
 *
 *  1. normalize/save: rows cleaned, versions counted, history kept.
 *  2. render: expired themes left out, priority order, budget drops the
 *     lowest priorities first.
 *  3. apply: placeholder replaced, appended for budgeted actions, never for
 *     memory digests / intent classification / QA, nothing before a save.
 *  4. call_provider wiring and the generation record (version + prompt as sent).
 *  5. weekly suggestion from a rollup, accept and dismiss.
 *  6. admin form parsing and the Memory → Agenda tab.
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
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function ea_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$A = 'PressHub_AI_Editorial_Agenda';
delete_option( $A::OPTION );
delete_option( $A::HISTORY_OPTION );
delete_option( $A::SUGGESTION_OPTION );

// 3a. Nothing before the first save.
ea_check( 'apply: no agenda yet leaves the prompt alone', 'SYS' === $A::apply( 'SYS', 'briefing_curation' ) );
ea_check( 'apply: no agenda yet strips the placeholder', 'A  B' === $A::apply( 'A {editorial_agenda} B', 'briefing_curation' ) );
ea_check( 'apply: nothing recorded when nothing sent', null === $A::last_applied() );

// 1. normalize + save
$norm = $A::normalize_themes( [
    [ 'title' => '  Κυπριακό   συνομιλίες ', 'priority' => 9, 'notes' => 'x', 'expires' => '2026-02-30' ],
    [ 'title' => '', 'priority' => 5 ],
    'junk',
    [ 'title' => 'Ενέργεια', 'priority' => 'abc', 'expires' => '2027-01-01' ],
] );
ea_check( 'normalize: empty and junk rows dropped', 2 === count( $norm ) );
ea_check( 'normalize: whitespace folded', 'Κυπριακό συνομιλίες' === $norm[0]['title'] );
ea_check( 'normalize: priority clamped to 5', 5 === $norm[0]['priority'] );
ea_check( 'normalize: invalid date dropped', '' === $norm[0]['expires'] );
ea_check( 'normalize: bad priority -> 3, valid date kept', 3 === $norm[1]['priority'] && '2027-01-01' === $norm[1]['expires'] );
$many = [];
for ( $i = 0; $i < 20; $i++ ) {
    $many[] = [ 'title' => "T{$i}" ];
}
ea_check( 'normalize: capped at MAX_THEMES', $A::MAX_THEMES === count( $A::normalize_themes( $many ) ) );

$v1 = $A::save( [ [ 'title' => 'Παλιό θέμα', 'priority' => 2 ] ], 'Οδηγία 1', 7, 'first' );
ea_check( 'save: first version is 1', 1 === $v1['version'] && 7 === $v1['updated_by'] );
ea_check( 'save: no history after the first save', [] === $A::get_history() );

$v2 = $A::save( [
    [ 'title' => 'Εκλογές 2028', 'priority' => 3, 'notes' => 'Ισορροπία κομμάτων' ],
    [ 'title' => 'Κυπριακό', 'priority' => 5, 'notes' => "Ουδέτερη ορολογία\nόχι «ψευδοκράτος» χωρίς πλαίσιο" ],
    [ 'title' => 'Ληγμένο', 'priority' => 5, 'expires' => '2020-01-01' ],
    [ 'title' => 'Ηλεκτρική διασύνδεση', 'priority' => 4 ],
    [ 'title' => 'Λιγότερο σημαντικό', 'priority' => 1 ],
], 'Αποφεύγουμε τον όρο Χ. Δίνουμε φωνή σε όλες τις πλευρές.', 7, 'second' );
$hist = $A::get_history();
ea_check( 'save: version increments', 2 === $v2['version'] && 2 === $A::get()['version'] );
ea_check( 'save: previous version goes to history', 1 === count( $hist ) && 1 === $hist[0]['version'] && 'first' === $hist[0]['note'] );

// 2. render
$today  = '2026-11-15';
$active = $A::active_themes( $A::get(), $today );
ea_check( 'active: expired theme dropped', 4 === count( $active ) );
ea_check( 'active: highest priority first, ties in editor order', 'Κυπριακό' === $active[0]['title'] && 'Ηλεκτρική διασύνδεση' === $active[1]['title'] && 'Εκλογές 2028' === $active[2]['title'] );

$full = $A::render( $A::get(), 5000, $today );
ea_check( 'render: header carries the version', false !== strpos( $full, 'ΑΤΖΕΝΤΑ (έκδοση 2)' ) );
ea_check( 'render: priority label', false !== strpos( $full, '[Προτεραιότητα 5/5] Κυπριακό' ) );
ea_check( 'render: framing notes indented', false !== strpos( $full, "Πλαισίωση: Ουδέτερη ορολογία\n  όχι" ) );
ea_check( 'render: expired theme not sent', false === strpos( $full, 'Ληγμένο' ) );
ea_check( 'render: guidance included', false !== strpos( $full, 'Δίνουμε φωνή σε όλες τις πλευρές' ) );
ea_check( 'render: zero budget sends nothing', '' === $A::render( $A::get(), 0, $today ) );

$len   = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
$tight = $A::render( $A::get(), $len( $full ) - 10, $today );
ea_check( 'render: budget drops the lowest priority first', '' !== $tight && false === strpos( $tight, 'Λιγότερο σημαντικό' ) && false !== strpos( $tight, 'Κυπριακό' ) );
ea_check( 'render: stays within budget', $len( $tight ) <= $len( $full ) - 10 );
$tiny = $A::render( $A::get(), 420, $today );
ea_check( 'render: very small budget keeps clipped guidance or nothing', '' === $tiny || $len( $tiny ) <= 420 );

// 3. apply
ea_check( 'budget: curation', 3000 === $A::budget_for( 'briefing_curation' ) );
ea_check( 'budget: social copy prefix', 800 === $A::budget_for( 'social_copy_facebook' ) );
ea_check( 'budget: seo prefix', 600 === $A::budget_for( 'seo_review' ) );
foreach ( [ 'briefing_memory', 'classify_intent', 'coauthor_scorecard', 'press_release_extract', 'podcast_audio', 'custom_test' ] as $excluded ) {
    ea_check( "budget: {$excluded} gets no agenda", 0 === $A::budget_for( $excluded ) );
    ea_check( "apply: {$excluded} prompt unchanged", 'SYS' === $A::apply( 'SYS', $excluded ) );
}

$out = $A::apply( 'Είσαι επιμελητής.', 'briefing_curation' );
ea_check( 'apply: appended after the prompt', 0 === strpos( $out, "Είσαι επιμελητής.\n\n=== ΤΡΕΧΟΥΣΑ ΣΥΝΤΑΚΤΙΚΗ ΑΤΖΕΝΤΑ" ) );
$last = $A::last_applied();
ea_check( 'apply: last_applied has version, action and prompt', is_array( $last ) && 2 === $last['version'] && 'briefing_curation' === $last['action'] && $out === $last['prompt'] && $last['chars'] > 0 );

$placed = $A::apply( "Αρχή\n{editorial_agenda}\nΤέλος", 'podcast_script' );
ea_check( 'apply: placeholder replaced in place', 0 === strpos( $placed, "Αρχή\n=== ΤΡΕΧΟΥΣΑ" ) && "\nΤέλος" === substr( $placed, -strlen( "\nΤέλος" ) ) && false === strpos( $placed, '{editorial_agenda}' ) );
$placed_excl = $A::apply( 'X {editorial_agenda}', 'classify_intent' );
ea_check( 'apply: explicit placeholder honoured even for unlisted actions', false !== strpos( $placed_excl, 'ΑΤΖΕΝΤΑ' ) );
ea_check( 'apply: non-string prompt passed through', null === $A::apply( null, 'briefing_curation' ) );

// 4. wiring: call_provider and the generation record.
$api_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-api-client.php' );
$cp      = substr( $api_src, strpos( $api_src, 'public function call_provider(' ) );
$cp      = substr( $cp, 0, strpos( $cp, 'call_anthropic(' ) );
ea_check( 'wiring: call_provider applies the agenda before the provider call', false !== strpos( $cp, 'PressHub_AI_Editorial_Agenda::apply( $sys_prompt, $this->get_action() )' ) );

global $wpdb;
$wpdb->tables          = [];
$wpdb->queries         = [];
$wpdb->auto_increments = [];
PressHub_AI_Generation_Recorder::create_table();

$t0    = PressHub_AI_Generation_Recorder::begin_call();
$orig  = 'Σύστημα επιμέλειας.';
$sent  = $A::apply( $orig, 'briefing_curation' );
$rid   = PressHub_AI_Generation_Recorder::finish_call( $t0, 'briefing_text', null, $orig, 'U', 'OK' );
$rec   = PressHub_AI_Generation_Recorder::get( (int) $rid );
$cfg   = is_array( $rec['config'] ?? null ) ? $rec['config'] : json_decode( (string) ( $rec['config'] ?? '' ), true );
ea_check( 'record: stored', is_array( $rec ) );
ea_check( 'record: agenda version in config', 2 === (int) ( $cfg['editorial_agenda_version'] ?? 0 ) );
ea_check( 'record: system prompt stored as sent', $sent === $rec['system_prompt'] );
ea_check( 'record: last_applied cleared after recording', null === $A::last_applied() );

$t1   = PressHub_AI_Generation_Recorder::begin_call();
$rid2 = PressHub_AI_Generation_Recorder::finish_call( $t1, 'generic', null, 'Plain', 'U', 'OK' );
$rec2 = PressHub_AI_Generation_Recorder::get( (int) $rid2 );
$cfg2 = is_array( $rec2['config'] ?? null ) ? $rec2['config'] : json_decode( (string) ( $rec2['config'] ?? '' ), true );
ea_check( 'record: a call without the agenda has no version', ! isset( $cfg2['editorial_agenda_version'] ) && 'Plain' === $rec2['system_prompt'] );

$rollup_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-memory-rollup-builder.php' );
ea_check( 'wiring: rollup build fires presshub_ai_memory_rollup_built', false !== strpos( $rollup_src, "do_action( 'presshub_ai_memory_rollup_built', \$rollup_id, \$week_start )" ) );

// 5. suggestion
$entry = [
    'id'           => 42,
    'scope'        => 'weekly',
    'period_start' => '2026-11-02',
    'period_end'   => '2026-11-08',
    'content_json' => json_encode( [ 'stories' => [
        [ 'key' => 'k1', 'title' => 'κυπριακό', 'development' => 'Ήδη στην ατζέντα', 'status' => 'developing' ],
        [ 'key' => 'k2', 'title' => 'Λειψυδρία', 'development' => 'Νέα μέτρα', 'status' => 'developing' ],
        [ 'key' => 'k3', 'title' => 'Απεργία λιμανιού', 'development' => 'Ξεκίνησε', 'status' => 'new' ],
        [ 'key' => 'k4', 'title' => 'Λήξη υπόθεσης', 'development' => 'Έκλεισε', 'status' => 'resolved' ],
    ] ] ),
];
$s = $A::suggest_from_rollup( $entry );
ea_check( 'suggest: made from open stories', is_array( $s ) && 2 === $s['added'] );
$titles = array_column( $s['themes'], 'title' );
ea_check( 'suggest: current themes kept first, known title not duplicated (case-insensitive)', 'Κυπριακό' === $titles[0] && 1 === count( array_filter( $titles, static function ( $t ) { return 'κυπριακό' === mb_strtolower( $t ); } ) ) );
ea_check( 'suggest: resolved story skipped, expired theme not carried', ! in_array( 'Λήξη υπόθεσης', $titles, true ) && ! in_array( 'Ληγμένο', $titles, true ) );
$by = array_column( $s['themes'], null, 'title' );
ea_check( 'suggest: developing -> 4, new -> 3', 4 === $by['Λειψυδρία']['priority'] && 3 === $by['Απεργία λιμανιού']['priority'] );
ea_check( 'suggest: stays pending, agenda unchanged', 2 === $A::get()['version'] && null !== $A::get_suggestion() );
ea_check( 'suggest: nothing new -> null', null === $A::suggest_from_rollup( [ 'content_json' => json_encode( [ 'stories' => [ [ 'title' => 'Κυπριακό', 'status' => 'new' ] ] ] ) ] ) );

$acc = $A::accept_suggestion( 9 );
ea_check( 'accept: saved as version 3 by the editor', is_array( $acc ) && 3 === $acc['version'] && 9 === $acc['updated_by'] && false !== strpos( $acc['note'], '2026-11-02' ) );
ea_check( 'accept: suggestion cleared, guidance kept', null === $A::get_suggestion() && false !== strpos( $acc['guidance'], 'Δίνουμε φωνή' ) );
ea_check( 'accept: nothing pending -> null', null === $A::accept_suggestion( 9 ) );
$A::suggest_from_rollup( array_merge( $entry, [ 'content_json' => json_encode( [ 'stories' => [ [ 'title' => 'Νέο', 'status' => 'new', 'development' => 'd' ] ] ] ) ] ) );
$A::dismiss_suggestion();
ea_check( 'dismiss: clears the suggestion', null === $A::get_suggestion() && 3 === $A::get()['version'] );

// 6. form + tab
$rows = $A::themes_from_post( [ 'agenda_themes' => [
    [ 'title' => 'Κρατάμε', 'priority' => '4', 'notes' => 'n', 'expires' => '' ],
    [ 'title' => 'Σβήνουμε', 'priority' => '2', 'remove' => '1' ],
    [ 'title' => '', 'priority' => '3' ],
] ] );
ea_check( 'form: removed rows dropped', 2 === count( $rows ) && 'Κρατάμε' === $rows[0]['title'] );
ea_check( 'form: empty rows dropped on save', 1 === count( $A::normalize_themes( $rows ) ) );

$tab = $A::build_tab( [ 'agenda_notice' => 'saved' ] );
ea_check( 'tab: save form posts the save action', false !== strpos( $tab, 'value="' . $A::POST_SAVE . '"' ) );
ea_check( 'tab: current themes editable', false !== strpos( $tab, 'value="Κυπριακό"' ) );
ea_check( 'tab: guidance in textarea', false !== strpos( $tab, 'Δίνουμε φωνή σε όλες τις πλευρές' ) );
ea_check( 'tab: history lists earlier versions', false !== strpos( $tab, 'v2 · ' ) && false !== strpos( $tab, 'v1 · ' ) );
ea_check( 'tab: notice shown', false !== strpos( $tab, 'Agenda saved' ) );
ea_check( 'tab: preview of the curation text', false !== strpos( $tab, 'ΑΤΖΕΝΤΑ (έκδοση 3)' ) );

$page = PressHub_AI_Memory_Editor::build_page( [ 'tab' => 'agenda' ] );
ea_check( 'memory page: agenda tab active', false !== strpos( $page, 'nav-tab nav-tab-active">Agenda' ) && false !== strpos( $page, $A::POST_SAVE ) );

if ( $failures > 0 ) {
    echo "EditorialAgendaTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "EditorialAgendaTest: OK ({$checks} checks)\n";
