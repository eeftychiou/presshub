<?php
/**
 * StakeholdersTest — Issue #197: fiches for the people in the news.
 *
 *  1. Name matching tolerates Greek case endings; short single words never match.
 *  2. merge_people: create, update, one position per topic per day, mentions per
 *     edition, edited and locked fiches respected, single names skipped.
 *  3. Editor save, positions text format, merging duplicates.
 *  4. parse_response and extraction with a mock client (recorded as memory_stakeholders).
 *  5. Injection: only for budgeted actions, within budget, excluded fiches left out,
 *     and the generation record stores the prompt as sent.
 *  6. Wiring and the Memory → People tab.
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
function sh_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$S = 'PressHub_AI_Stakeholders';
update_option( 'presshub_ai_memory_enabled', 1 );
delete_option( $S::OPTION );
sh_check( 'enabled with memory on', $S::is_enabled() );

// 1. Name matching.
$re = $S::name_pattern( 'Νίκος Χριστοδουλίδης' );
sh_check( 'pattern: nominative', 1 === preg_match( $re, $S::fold( 'Ο Νίκος Χριστοδουλίδης δήλωσε' ) ) );
sh_check( 'pattern: genitive', 1 === preg_match( $re, $S::fold( 'του Νίκου Χριστοδουλίδη' ) ) );
sh_check( 'pattern: accents and case ignored', 1 === preg_match( $re, $S::fold( 'ΝΙΚΟΣ ΧΡΙΣΤΟΔΟΥΛΙΔΗΣ' ) ) );
sh_check( 'pattern: other surname does not match', 0 === preg_match( $re, $S::fold( 'Νίκος Αναστασιάδης' ) ) );
sh_check( 'pattern: short single word never matches', '' === $S::name_pattern( 'Νίκος' ) );
sh_check( 'pattern: long single word allowed', '' !== $S::name_pattern( 'Χριστοδουλίδης' ) );
sh_check( 'slug folds accents', 'νικοσ-χριστοδουλιδησ' === $S::slug( 'Νίκος Χριστοδουλίδης' ) );

// 2. merge_people.
$people = [
    [ 'name' => 'Νίκος Χριστοδουλίδης', 'name_en' => 'Nikos Christodoulides', 'role' => 'Πρόεδρος της Δημοκρατίας', 'party' => '',
      'positions' => [ [ 'topic' => 'Κυπριακό', 'position' => 'Ζητά επανέναρξη των συνομιλιών.' ] ] ],
    [ 'name' => 'Μάκης Κεραυνός', 'role' => 'Υπουργός Οικονομικών', 'positions' => [] ],
    [ 'name' => 'Μάκης', 'role' => 'Κάποιος' ],
    'junk',
];
$st = $S::merge_people( $people, '2026-11-14', '2026-11-14-morning', 'http://example.test/a' );
sh_check( 'merge: two created, single name skipped', 2 === $st['created'] && 1 === $st['skipped'] && 1 === $st['positions'] );
$f = $S::get( 'νικοσ-χριστοδουλιδησ' );
sh_check( 'merge: fiche fields', is_array( $f ) && 'Πρόεδρος της Δημοκρατίας' === $f['role'] && 'Nikos Christodoulides' === $f['name_en'] && 1 === $f['mentions'] && '2026-11-14' === $f['first_seen'] );
sh_check( 'merge: position with date and link', '2026-11-14' === $f['positions'][0]['date'] && 'http://example.test/a' === $f['positions'][0]['url'] );

// Same edition again: no extra mention, same-topic position replaced.
$S::merge_people( [ [ 'name' => 'Νίκος Χριστοδουλίδης', 'positions' => [ [ 'topic' => 'κυπριακό', 'position' => 'Νέα διατύπωση.' ] ] ] ], '2026-11-14', '2026-11-14-morning' );
$f = $S::get( 'νικοσ-χριστοδουλιδησ' );
sh_check( 'merge: same edition counted once', 1 === $f['mentions'] );
sh_check( 'merge: one position per topic per day', 1 === count( $f['positions'] ) && 'Νέα διατύπωση.' === $f['positions'][0]['position'] );
sh_check( 'merge: empty role in reply keeps the old role', 'Πρόεδρος της Δημοκρατίας' === $f['role'] );

// Next edition, matched by the English name.
$S::merge_people( [ [ 'name' => 'Nikos Christodoulides', 'positions' => [ [ 'topic' => 'Ενέργεια', 'position' => 'Στηρίζει τη διασύνδεση.' ] ] ] ], '2026-11-14', '2026-11-14-noon' );
$f = $S::get( 'νικοσ-χριστοδουλιδησ' );
sh_check( 'merge: matched by English name, not a new fiche', 2 === count( $S::all() ) && 2 === $f['mentions'] && 2 === count( $f['positions'] ) );

// 3. Editor save and lock.
$saved = $S::save_edit( 'μακησ-κεραυνοσ', [
    'name' => 'Μάκης Κεραυνός', 'role' => 'Υπουργός Οικονομικών (εκδότης)', 'aliases' => 'Κεραυνός, M. Keravnos',
    'positions' => "2026-11-10 | Προϋπολογισμός | Πλεόνασμα 2% | http://example.test/b\nκακή γραμμή",
], 5 );
sh_check( 'save: marked edited with aliases and parsed positions', is_array( $saved ) && 'edited' === $saved['origin'] && 2 === count( $saved['aliases'] ) && 1 === count( $saved['positions'] ) && 5 === $saved['updated_by'] );
sh_check( 'save: unknown fiche', is_wp_error( $S::save_edit( 'nobody', [ 'name' => 'x' ] ) ) );
sh_check( 'save: name required', is_wp_error( $S::save_edit( 'μακησ-κεραυνοσ', [ 'name' => '  ' ] ) ) );
sh_check( 'positions text round trip', "2026-11-10 | Προϋπολογισμός | Πλεόνασμα 2% | http://example.test/b" === $S::positions_text( $saved['positions'] ) );

$S::merge_people( [ [ 'name' => 'Μάκης Κεραυνός', 'role' => 'Άλλος ρόλος', 'positions' => [ [ 'topic' => 'Φόροι', 'position' => 'Όχι νέους φόρους.' ] ] ] ], '2026-11-15', '2026-11-15-morning' );
$k = $S::get( 'μακησ-κεραυνοσ' );
sh_check( 'edited fiche: role kept, positions and sightings added', 'Υπουργός Οικονομικών (εκδότης)' === $k['role'] && 2 === count( $k['positions'] ) && 'Φόροι' === $k['positions'][0]['topic'] && '2026-11-15' === $k['last_seen'] );

$S::save_edit( 'μακησ-κεραυνοσ', [ 'locked' => true ] );
$S::merge_people( [ [ 'name' => 'Μάκης Κεραυνός', 'positions' => [ [ 'topic' => 'Χ', 'position' => 'Ψ.' ] ] ] ], '2026-11-16', '2026-11-16-morning' );
sh_check( 'locked fiche: never changed automatically', 2 === count( $S::get( 'μακησ-κεραυνοσ' )['positions'] ) );

// Merge a duplicate.
$S::merge_people( [ [ 'name' => 'Ν. Χριστοδουλίδης', 'role' => '', 'positions' => [ [ 'topic' => 'Μεταναστευτικό', 'position' => 'Αυστηρότεροι έλεγχοι.' ] ] ] ], '2026-11-13', '2026-11-13-morning' );
sh_check( 'duplicate created', 3 === count( $S::all() ) );
$m = $S::merge_fiches( 'νικοσ-χριστοδουλιδησ', 'ν-χριστοδουλιδησ', 5 );
sh_check( 'merge fiches: alias, positions, sightings moved, duplicate removed', is_array( $m ) && in_array( 'Ν. Χριστοδουλίδης', $m['aliases'], true ) && 3 === count( $m['positions'] ) && 3 === $m['mentions'] && '2026-11-13' === $m['first_seen'] && 2 === count( $S::all() ) );
sh_check( 'merge fiches: same fiche refused', is_wp_error( $S::merge_fiches( 'νικοσ-χριστοδουλιδησ', 'νικοσ-χριστοδουλιδησ' ) ) );
sh_check( 'merged alias now matches in later merges', '' !== $S::find_slug( $S::all(), 'Ν. Χριστοδουλίδης' ) );

// Review follow-ups: punctuation-only names skipped; merged mentions survive the seen_in cap.
$before = count( $S::all() );
$pst    = $S::merge_people( [ [ 'name' => '-- ..' ] ], '2026-11-15', '2026-11-15-morning' );
sh_check( 'merge: punctuation-only name skipped', 1 === $pst['skipped'] && $before === count( $S::all() ) );
$raw = get_option( $S::OPTION );
$raw['big-a'] = [ 'name' => 'Άλφα Πρώτος', 'mentions' => 30, 'seen_in' => [ 'x1', 'x2' ] ];
$raw['big-b'] = [ 'name' => 'Βήτα Δεύτερος', 'mentions' => 25, 'seen_in' => [ 'x2', 'x3' ] ];
update_option( $S::OPTION, $raw );
$big = $S::merge_fiches( 'big-a', 'big-b' );
sh_check( 'merge fiches: mentions kept beyond the seen_in cap, shared edition once', 54 === $big['mentions'] );
$S::merge_fiches( 'big-a', 'big-a' );
$raw = get_option( $S::OPTION );
unset( $raw['big-a'] );
update_option( $S::OPTION, $raw );

// 4. parse_response.
sh_check( 'parse: fenced JSON', 1 === count( (array) $S::parse_response( "```json\n{\"people\":[{\"name\":\"Α Β\"}]}\n```" ) ) );
sh_check( 'parse: JSON inside prose', is_array( $S::parse_response( 'Ορίστε: {"people":[]} τέλος' ) ) );
sh_check( 'parse: invalid', is_wp_error( $S::parse_response( 'όχι json' ) ) && is_wp_error( $S::parse_response( '{"x":1}' ) ) );

class SH_Mock_Client extends PressHub_AI_API_Client {
    public $sys = '';
    public $user = '';
    public $action = '';
    public function __construct() {}
    public function set_action( string $action ): PressHub_AI_API_Client { $this->action = $action; return $this; }
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->sys  = $sys_prompt;
        $this->user = $user_prompt;
        return json_encode( [ 'people' => [
            [ 'name' => 'Αννίτα Δημητρίου', 'role' => 'Πρόεδρος της Βουλής', 'party' => 'ΔΗΣΥ', 'positions' => [ [ 'topic' => 'Εκλογές', 'position' => 'Ζητά συναίνεση.' ] ] ],
        ] ] );
    }
}

global $wpdb;
$wpdb->tables          = [];
$wpdb->queries         = [];
$wpdb->auto_increments = [];
PressHub_AI_Generation_Recorder::create_table();

$GLOBALS['POSTS_STORE'][901] = [ 'ID' => 901, 'post_content' => '<p>Η Αννίτα Δημητρίου ζήτησε συναίνεση.</p>', 'post_date' => '2026-11-15 07:00:00', 'post_status' => 'publish' ];
update_post_meta( 901, '_presshub_briefing_date', '2026-11-15' );
update_post_meta( 901, '_presshub_briefing_edition', 'noon' );
$mock = new SH_Mock_Client();
$res  = $S::extract_for_post( 901, $mock );
sh_check( 'extract: merged one new fiche', is_array( $res ) && 1 === $res['created'] );
sh_check( 'extract: prompt filled (date, edition, known people)', false !== strpos( $mock->sys, '2026-11-15' ) && false !== strpos( $mock->sys, 'Μεσημβρινή' ) && false !== strpos( $mock->sys, 'Νίκος Χριστοδουλίδης' ) && false === strpos( $mock->sys, '{known_people}' ) );
sh_check( 'extract: tags stripped from the brief', false !== strpos( $mock->user, 'Η Αννίτα Δημητρίου ζήτησε' ) && false === strpos( $mock->user, '<p>' ) );
sh_check( 'extract: action set', 'memory_stakeholders' === $mock->action );
$a = $S::get( 'αννιτα-δημητριου' );
sh_check( 'extract: edition key and link recorded', is_array( $a ) && in_array( '2026-11-15-noon', $a['seen_in'], true ) && 'http://example.test/?p=901' === $a['positions'][0]['url'] );
$rec = PressHub_AI_Generation_Recorder::get( (int) $res['record_id'] );
sh_check( 'extract: generation record of kind memory_stakeholders', is_array( $rec ) && 'memory_stakeholders' === $rec['kind'] );
sh_check( 'extract: missing post', is_wp_error( $S::extract_for_post( 999, $mock ) ) );

// 5. Injection.
sh_check( 'budget: curation and podcast', 2500 === $S::budget_for( 'briefing_curation' ) && 1500 === $S::budget_for( 'podcast_script' ) );
sh_check( 'budget: none for the extraction itself', 0 === $S::budget_for( 'memory_stakeholders' ) && 0 === $S::budget_for( 'briefing_memory' ) );
$material = 'Ο Πρόεδρος Χριστοδουλίδης συναντήθηκε με την Αννίτα Δημητρίου. Ο Νίκος Χριστοδουλίδη μίλησε ξανά.';
sh_check( 'apply: unbudgeted action untouched', 'SYS' === $S::apply( 'SYS', $material, 'classify_intent' ) && null === $S::last_applied() );
$out = $S::apply( 'SYS', $material, 'briefing_curation' );
sh_check( 'apply: block appended', 0 === strpos( $out, "SYS\n\n=== ΦΑΚΕΛΟΙ ΠΡΟΣΩΠΩΝ" ) );
sh_check( 'apply: both people with roles', false !== strpos( $out, 'Νίκος Χριστοδουλίδης / Nikos Christodoulides — Πρόεδρος της Δημοκρατίας' ) && false !== strpos( $out, 'Αννίτα Δημητρίου — Πρόεδρος της Βουλής, ΔΗΣΥ' ) );
sh_check( 'apply: most mentioned first', strpos( $out, 'Νίκος' ) < strpos( $out, '- Αννίτα' ) );
sh_check( 'apply: person not in the material left out', false === strpos( $out, 'Κεραυνός' ) );
$last = $S::last_applied();
sh_check( 'apply: last_applied lists the people and the prompt', is_array( $last ) && 2 === count( $last['people'] ) && $out === $last['prompt'] && md5( 'SYS' ) === $last['original_hash'] );
sh_check( 'apply: no names in the material sends nothing', 'SYS' === $S::apply( 'SYS', 'Καιρός και αθλητικά.', 'briefing_curation' ) );

$hits  = $S::find_in_text( $material );
$small = $S::render( $hits, 300 );
$len   = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
sh_check( 'render: within a small budget', '' === $small || $len( $small ) <= 300 );
sh_check( 'render: zero budget', '' === $S::render( $hits, 0 ) );

$S::save_edit( 'αννιτα-δημητριου', [ 'excluded' => true ] );
$out2 = $S::apply( 'SYS', $material, 'podcast_script' );
sh_check( 'excluded fiche never sent', false === strpos( $out2, 'Αννίτα' ) && false !== strpos( $out2, 'Χριστοδουλίδης' ) );

// Recorder: fiches listed in config, prompt stored as sent (also with the agenda on top).
$t0   = PressHub_AI_Generation_Recorder::begin_call();
$sent = $S::apply( 'ΑΡΧΙΚΟ', $material, 'briefing_curation' );
$rid  = PressHub_AI_Generation_Recorder::finish_call( $t0, 'briefing_text', null, 'ΑΡΧΙΚΟ', $material, 'OK' );
$rec  = PressHub_AI_Generation_Recorder::get( (int) $rid );
$cfg  = is_array( $rec['config'] ?? null ) ? $rec['config'] : json_decode( (string) ( $rec['config'] ?? '' ), true );
sh_check( 'record: system prompt as sent', $sent === $rec['system_prompt'] );
sh_check( 'record: fiches in config', [ 'νικοσ-χριστοδουλιδησ' ] === ( $cfg['stakeholders'] ?? null ) && ( $cfg['stakeholders_chars'] ?? 0 ) > 0 );
sh_check( 'record: cleared', null === $S::last_applied() );

$A = 'PressHub_AI_Editorial_Agenda';
$A::save( [ [ 'title' => 'Κυπριακό', 'priority' => 5 ] ], '', 1, '' );
$t1    = PressHub_AI_Generation_Recorder::begin_call();
$step1 = $A::apply( 'ΑΡΧΙΚΟ', 'briefing_curation' );
$step2 = $S::apply( $step1, $material, 'briefing_curation' );
$rid2  = PressHub_AI_Generation_Recorder::finish_call( $t1, 'briefing_text', null, 'ΑΡΧΙΚΟ', $material, 'OK' );
$rec2  = PressHub_AI_Generation_Recorder::get( (int) $rid2 );
sh_check( 'record: agenda then fiches, prompt as sent', $step2 === $rec2['system_prompt'] && false !== strpos( $step2, 'ΑΤΖΕΝΤΑ' ) && false !== strpos( $step2, 'ΦΑΚΕΛΟΙ' ) );

// 6. Wiring.
$api_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-api-client.php' );
$cp      = substr( $api_src, strpos( $api_src, 'public function call_provider(' ) );
$cp      = substr( $cp, 0, strpos( $cp, 'call_anthropic(' ) );
sh_check( 'wiring: call_provider applies fiches after the agenda', false !== strpos( $cp, 'PressHub_AI_Stakeholders::apply( $sys_prompt, $user_prompt, $this->get_action() )' ) && strpos( $cp, 'Editorial_Agenda::apply' ) < strpos( $cp, 'Stakeholders::apply' ) );
$digest_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-memory-digest-builder.php' );
sh_check( 'wiring: digest build fires the hook', false !== strpos( $digest_src, "do_action( 'presshub_ai_memory_digest_built', \$post_id" ) );
sh_check( 'wiring: kind registered', in_array( 'memory_stakeholders', PressHub_AI_Generation_Recorder::KINDS, true ) );
$uninstall = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
sh_check( 'wiring: uninstall removes the option', false !== strpos( $uninstall, "'presshub_ai_stakeholders'" ) );

$GLOBALS['SCHEDULED_EVENTS'] = [];
$S::schedule_extraction( 901 );
$ev = $GLOBALS['SCHEDULED_EVENTS'][0] ?? [];
sh_check( 'schedule: extraction queued for the post', $S::CRON_HOOK === ( $ev['hook'] ?? '' ) && [ 901 ] === ( $ev['args'] ?? null ) );
$S::register_hooks();
sh_check( 'schedule: hooked to the digest build', false !== has_action( 'presshub_ai_memory_digest_built', [ $S, 'schedule_extraction' ] ) );

// Tab.
$list = $S::build_tab( [] );
sh_check( 'tab: list shows fiches with status', false !== strpos( $list, 'Νίκος Χριστοδουλίδης' ) && false !== strpos( $list, 'excluded' ) && false !== strpos( $list, 'locked' ) );
$form = $S::build_tab( [ 'fiche' => 'μακησ-κεραυνοσ', 'sh_notice' => 'saved' ] );
sh_check( 'tab: edit form with merge select and notice', false !== strpos( $form, 'name="positions"' ) && false !== strpos( $form, 'name="merge_from"' ) && false !== strpos( $form, 'notice-success' ) && false !== strpos( $form, $S::POST_SAVE ) );
$html = PressHub_AI_Memory_Editor::build_page( [ 'tab' => 'stakeholders' ] );
sh_check( 'memory page: People tab active and rendered', false !== strpos( $html, 'tab=stakeholders' ) && false !== strpos( $html, 'presshub-stakeholders' ) && false !== strpos( $html, 'nav-tab-active' ) );

if ( $failures > 0 ) {
    echo "StakeholdersTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "StakeholdersTest: OK ({$checks} checks)\n";
