<?php
/**
 * PolicyFichesTest — policy fiches (one per policy file).
 *
 *  1. Create, duplicates (title or alias), normalize, editor core save with history.
 *  2. Matching tolerates Greek case endings; drafts are never injected.
 *  3. Injection: budget per action, parts per action, QA header, unbudgeted
 *     actions untouched, within budget, and the generation record stores the
 *     prompt as sent after the agenda and the people fiches.
 *  4. Updates after a brief: additions only, never confirmed/false, known claims
 *     keep their status, locked and unsent fiches untouched, core changes wait
 *     as suggestions, answered questions, alerts for claims repeated as fact.
 *  5. Editor record edits and suggestions.
 *  6. New topics become drafts after three editions; first drafts from the
 *     agenda and memory with a mock client.
 *  7. Hub alerts, the Policies page and the wiring.
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
function pf_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$P   = 'PressHub_AI_Policy_Fiches';
$len = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
delete_option( $P::OPTION );
delete_option( $P::CANDIDATES_OPTION );
delete_option( $P::ALERTS_OPTION );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts' ];

// 1. Create and save.
$slug = $P::create( 'Συνταξιοδοτική μεταρρύθμιση', [ 'aliases' => [ 'συντάξεις', 'αύξηση συντάξεων' ], 'origin' => 'edited' ], 3 );
pf_check( 'create: slug from the folded title', 'συνταξιοδοτικη-μεταρρυθμιση' === $slug );
pf_check( 'create: duplicate title refused', is_wp_error( $P::create( 'ΣΥΝΤΑΞΙΟΔΟΤΙΚΗ ΜΕΤΑΡΡΥΘΜΙΣΗ' ) ) );
pf_check( 'create: alias of another fiche refused', is_wp_error( $P::create( 'Αύξηση συντάξεων' ) ) );
pf_check( 'create: empty title refused', is_wp_error( $P::create( '   ' ) ) );
$f = $P::get( $slug );
pf_check( 'create: starts as a draft, version 0', 'draft' === $f['status'] && 0 === $f['version'] && 'edited' === $f['origin'] );

$saved = $P::save_core( $slug, [
    'title'      => 'Συνταξιοδοτική μεταρρύθμιση',
    'aliases'    => [ 'συντάξεις', 'αύξηση συντάξεων', 'συνταξιούχοι' ],
    'principles' => 'Ποιος αποφασίζει και με ποιο κόστος· πώς συνδέονται οι σημερινές συντάξεις με τις παροχές του σημερινού εργατικού δυναμικού.',
    'framing'    => 'Οι κατηγορίες της κυβέρνησης προς τη Βουλή και τους κοινωνικούς εταίρους αποδίδονται, δεν γράφονται ως γεγονός.',
    'facts'      => $P::parse_facts_text( "Η αύξηση έως 100 ευρώ αφορά μόνο χαμηλοσυνταξιούχους | https://example.test/f1\nΤο νομοσχέδιο δεν έχει κατατεθεί" ),
    'status'     => 'published',
], 3 );
pf_check( 'save: version 1 with the previous core in history', 1 === $saved['version'] && 1 === count( $saved['history'] ) && 0 === $saved['history'][0]['version'] && 'draft' === $saved['history'][0]['status'] );
pf_check( 'save: facts parsed with links', 2 === count( $saved['facts'] ) && 'https://example.test/f1' === $saved['facts'][0]['url'] && '' === $saved['facts'][1]['url'] );
pf_check( 'save: facts text round trip', "Η αύξηση έως 100 ευρώ αφορά μόνο χαμηλοσυνταξιούχους | https://example.test/f1\nΤο νομοσχέδιο δεν έχει κατατεθεί" === $P::facts_text( $saved['facts'] ) );
pf_check( 'save: missing fiche', is_wp_error( $P::save_core( 'nope', [] ) ) );

$draft_slug = $P::create( 'Στεγαστική πολιτική', [ 'aliases' => [ 'στέγαση' ], 'principles' => 'Κόστος στέγασης.' ] );

// 2. Matching.
$material = 'Η κυβέρνηση υποστηρίζει ότι η αύξηση των συντάξεων έως 100 ευρώ μπλοκάρεται από τη Βουλή. Οι συνταξιούχοι περιμένουν. Και η στέγαση ακριβαίνει.';
$hits     = $P::find_in_text( $material );
pf_check( 'match: inflected alias found', isset( $hits[ $slug ] ) && $hits[ $slug ]['count'] >= 2 );
pf_check( 'match: drafts never injected', ! isset( $hits[ $draft_slug ] ) );
pf_check( 'match: drafts matched for updates', isset( $P::find_in_text( $material, null, false )[ $draft_slug ] ) );
pf_check( 'match: unrelated text', empty( $P::find_in_text( 'Καιρός και αθλητικά.' ) ) );

// 3. Injection.
pf_check( 'budget: per action and prefix', 3000 === $P::budget_for( 'briefing_curation' ) && 800 === $P::budget_for( 'social_copy_facebook' ) && 1500 === $P::budget_for( 'coauthor_scorecard' ) );
pf_check( 'budget: none for memory calls', 0 === $P::budget_for( 'memory_policy' ) && 0 === $P::budget_for( 'briefing_memory' ) && 0 === $P::budget_for( 'classify_intent' ) );
pf_check( 'apply: unbudgeted action untouched', 'SYS' === $P::apply( 'SYS', $material, 'classify_intent' ) && null === $P::last_applied() );
$out = $P::apply( 'SYS', $material, 'briefing_curation' );
pf_check( 'apply: block appended', 0 === strpos( $out, "SYS\n\n=== ΦΑΚΕΛΟΙ ΠΟΛΙΤΙΚΩΝ" ) && false !== strpos( $out, '=== ΤΕΛΟΣ ΦΑΚΕΛΩΝ ΠΟΛΙΤΙΚΩΝ ===' ) );
pf_check( 'apply: full parts for curation', false !== strpos( $out, 'Αρχές: ' ) && false !== strpos( $out, 'Πλαισίωση: ' ) && false !== strpos( $out, 'Επιβεβαιωμένα στοιχεία:' ) );
pf_check( 'apply: draft fiche left out', false === strpos( $out, 'Στεγαστική' ) );
$last = $P::last_applied();
pf_check( 'apply: last_applied with version', is_array( $last ) && [ $slug . '@v1' ] === $last['fiches'] && $out === $last['prompt'] && md5( 'SYS' ) === $last['original_hash'] );
$social = $P::apply( 'SYS', $material, 'social_copy_x' );
pf_check( 'apply: social copy gets framing only', false !== strpos( $social, 'Πλαισίωση: ' ) && false === strpos( $social, 'Αρχές: ' ) && false === strpos( $social, 'Επιβεβαιωμένα' ) );
$qa = $P::apply( 'SYS', $material, 'coauthor_scorecard' );
pf_check( 'apply: QA gate header', false !== strpos( $qa, 'ΕΛΕΓΧΟΣ ΜΕ ΒΑΣΗ ΤΟΥΣ ΦΑΚΕΛΟΥΣ' ) && false === strpos( $qa, 'Αρχές: ' ) );
pf_check( 'apply: nothing in the material sends nothing', 'SYS' === $P::apply( 'SYS', 'Καιρός.', 'briefing_curation' ) );
add_filter( 'presshub_ai_policy_fiches_enabled', '__return_false' );
pf_check( 'apply: filter turns it off', 'SYS' === $P::apply( 'SYS', $material, 'briefing_curation' ) );
$GLOBALS['FILTERS']['presshub_ai_policy_fiches_enabled'] = [];

$hits = $P::find_in_text( $material );
$full = $len( $P::render( $hits, 'briefing_curation', 10000 )['text'] );
$tiny = $P::render( $hits, 'briefing_curation', $full - 1 );
pf_check( 'render: smaller budget drops parts, stays within it', '' !== $tiny['text'] && $len( $tiny['text'] ) < $full && false === strpos( $tiny['text'], 'Επιβεβαιωμένα' ) && false !== strpos( $tiny['text'], 'Πλαισίωση: ' ) );
pf_check( 'render: too small a budget sends nothing', '' === $P::render( $hits, 'briefing_curation', 50 )['text'] );

global $wpdb;
$wpdb->tables          = [];
$wpdb->queries         = [];
$wpdb->auto_increments = [];
PressHub_AI_Generation_Recorder::create_table();

$A = 'PressHub_AI_Editorial_Agenda';
$A::save( [ [ 'title' => 'Κυπριακό', 'priority' => 5 ] ], '', 1, '' );
PressHub_AI_Stakeholders::merge_people( [ [ 'name' => 'Μάκης Κεραυνός', 'role' => 'Υπουργός Οικονομικών' ] ], '2026-11-15', '2026-11-15-morning' );
$people_material = $material . ' Ο Μάκης Κεραυνός μίλησε.';
$t0    = PressHub_AI_Generation_Recorder::begin_call();
$step1 = $A::apply( 'ΑΡΧΙΚΟ', 'briefing_curation' );
$step2 = PressHub_AI_Stakeholders::apply( $step1, $people_material, 'briefing_curation' );
$step3 = $P::apply( $step2, $people_material . "\nΑΡΧΙΚΟ", 'briefing_curation' );
$rid   = PressHub_AI_Generation_Recorder::finish_call( $t0, 'briefing_text', null, 'ΑΡΧΙΚΟ', $people_material, 'OK' );
$rec   = PressHub_AI_Generation_Recorder::get( (int) $rid );
$cfg   = is_array( $rec['config'] ?? null ) ? $rec['config'] : json_decode( (string) ( $rec['config'] ?? '' ), true );
pf_check( 'record: agenda, people and policy fiches, prompt as sent', $step3 === $rec['system_prompt'] && false !== strpos( $step3, 'ΑΤΖΕΝΤΑ' ) && false !== strpos( $step3, 'ΦΑΚΕΛΟΙ ΠΡΟΣΩΠΩΝ' ) && false !== strpos( $step3, 'ΦΑΚΕΛΟΙ ΠΟΛΙΤΙΚΩΝ' ) );
pf_check( 'record: policy fiches in config', [ $slug . '@v1' ] === ( $cfg['policy_fiches'] ?? null ) && ( $cfg['policy_fiches_chars'] ?? 0 ) > 0 );
pf_check( 'record: cleared', null === $P::last_applied() );
$t1    = PressHub_AI_Generation_Recorder::begin_call();
$only  = $P::apply( 'ΑΡΧΙΚΟ2', $material, 'podcast_script' );
$rid   = PressHub_AI_Generation_Recorder::finish_call( $t1, 'podcast_script', null, 'ΑΡΧΙΚΟ2', $material, 'OK' );
$rec   = PressHub_AI_Generation_Recorder::get( (int) $rid );
pf_check( 'record: policy fiches alone, prompt as sent', $only === $rec['system_prompt'] && false === strpos( $only, 'Επιβεβαιωμένα' ) );

// 4. Updates after a brief.
$P::save_record( $slug, [ 'new_claim' => [ 'claim' => 'Η Βουλή μπλοκάρει την αύξηση των συντάξεων', 'by' => 'Κυβέρνηση', 'status' => 'contested', 'counter' => 'Δεν έχει κατατεθεί νομοσχέδιο.' ], 'new_question' => 'Ποιο είναι το κόστος της αύξησης;' ], 3 );
$f   = $P::get( $slug );
$qid = $f['questions'][0]['id'];
$locked = $P::create( 'Κυπριακό πρόβλημα', [ 'aliases' => [ 'Κυπριακό' ], 'status' => 'published', 'locked' => true ] );
$before_core = [ $f['principles'], $f['framing'], $f['facts'], $f['version'] ];

$stats = $P::merge_update( [
    'fiches' => [
        [
            'slug'      => $slug,
            'timeline'  => [ [ 'text' => 'Ο υπουργός είπε ότι η αύξηση εξαρτάται από τη Βουλή.' ], [ 'text' => 'Ο υπουργός είπε ότι η αύξηση εξαρτάται από τη Βουλή.' ] ],
            'claims'    => [
                [ 'claim' => 'Οι κοινωνικοί εταίροι καθυστερούν τη μεταρρύθμιση', 'by' => 'Υπουργείο Εργασίας', 'status' => 'confirmed', 'repeated_as_fact' => true ],
                [ 'claim' => 'η βουλή μπλοκάρει την αύξηση των συντάξεων', 'by' => 'Κυβέρνηση', 'status' => 'unchecked', 'repeated_as_fact' => true ],
            ],
            'questions' => [ [ 'text' => 'Πώς συνδέονται οι σημερινές συντάξεις με τις μελλοντικές παροχές;' ] ],
            'answered'  => [ [ 'id' => $qid, 'answer' => 'Το κόστος είναι 40 εκατ. ετησίως.' ] ],
            'actors'    => [ 'ΠΕΟ', 'ΣΕΚ' ],
            'suggestions' => [ [ 'field' => 'facts', 'text' => 'Η αύξηση θα κοστίσει 40 εκατ. ετησίως.', 'reason' => 'Αναφέρθηκε από το υπουργείο.' ] ],
            'principles' => 'ΑΝΤΙΚΑΤΑΣΤΑΣΗ ΑΡΧΩΝ',
        ],
        [ 'slug' => $locked, 'timeline' => [ [ 'text' => 'Νέα συνάντηση.' ] ] ],
        [ 'slug' => $draft_slug, 'timeline' => [ [ 'text' => 'Δεν στάλθηκε.' ] ] ],
        [ 'slug' => 'unknown', 'timeline' => [ [ 'text' => 'x' ] ] ],
    ],
], '2026-11-16', '2026-11-16-morning', 'http://example.test/?p=77', [ $slug, $locked ] );
$f = $P::get( $slug );
pf_check( 'update: one timeline item, deduplicated, dated and linked', 1 === $stats['timeline'] && '2026-11-16' === $f['timeline'][0]['date'] && 'http://example.test/?p=77' === $f['timeline'][0]['url'] );
$new_claim = null;
$old_claim = null;
foreach ( $f['claims'] as $c ) {
    if ( false !== strpos( $c['claim'], 'εταίροι' ) ) {
        $new_claim = $c;
    } else {
        $old_claim = $c;
    }
}
pf_check( 'update: new claim added, never confirmed', is_array( $new_claim ) && 'unchecked' === $new_claim['status'] && 'auto' === $new_claim['origin'] && 1 === $stats['claims'] );
pf_check( 'update: known claim keeps its status', is_array( $old_claim ) && 'contested' === $old_claim['status'] && 'editor' === $old_claim['origin'] && 2 === count( $f['claims'] ) );
pf_check( 'update: question added and answered', 1 === $stats['questions'] && 1 === $stats['answered'] && 'answered' === $f['questions'][0]['status'] && false !== strpos( $f['questions'][0]['answer'], '[2026-11-16]' ) );
pf_check( 'update: actors added', in_array( 'ΠΕΟ', $f['actors'], true ) && in_array( 'ΣΕΚ', $f['actors'], true ) );
pf_check( 'update: core untouched, change waits as a suggestion', $before_core === [ $f['principles'], $f['framing'], $f['facts'], $f['version'] ] && 1 === count( $f['suggestions'] ) && 'facts' === $f['suggestions'][0]['field'] );
pf_check( 'update: last seen and mentions', '2026-11-16' === $f['last_seen'] && 1 === $f['mentions'] );
pf_check( 'update: locked fiche untouched', empty( $P::get( $locked )['timeline'] ) );
pf_check( 'update: fiche not sent untouched', empty( $P::get( $draft_slug )['timeline'] ) );
$alerts = $P::alerts_for( '2026-11-16' );
pf_check( 'update: alerts for both claims repeated as fact', 2 === count( $alerts ) && 2 === $stats['alerts'] && in_array( 'contested', array_column( $alerts, 'status' ), true ) );

// 5. Suggestions and record edits.
$sid = $f['suggestions'][0]['id'];
$acc = $P::resolve_suggestion( $slug, $sid, true, 3 );
pf_check( 'suggestion: accepted into the core as a new version', 3 === count( $acc['facts'] ) && 2 === $acc['version'] && empty( $acc['suggestions'] ) && 'published' === $acc['status'] );
pf_check( 'suggestion: unknown id', is_wp_error( $P::resolve_suggestion( $slug, 'nope', true ) ) );
$rec_f = $P::save_record( $slug, [ 'claims' => [ $new_claim['id'] => [ 'status' => 'false', 'counter' => 'Οι εταίροι δεν έχουν λάβει πρόταση.' ], $old_claim['id'] => [ 'remove' => true ] ] ], 3 );
pf_check( 'record: editor marks a claim false and removes one', 1 === count( $rec_f['claims'] ) && 'false' === $rec_f['claims'][0]['status'] && 'editor' === $rec_f['claims'][0]['origin'] );
$rendered = $P::render_fiche( $rec_f, $P::parts_for( 'briefing_curation' ) );
pf_check( 'render: false claim tagged with its counter-point', false !== strpos( $rendered, '[ανακριβής] «Οι κοινωνικοί εταίροι' ) && false !== strpos( $rendered, 'Αντίλογος: Οι εταίροι δεν έχουν λάβει πρόταση.' ) );

// 6. New topics and drafts.
delete_option( $P::CANDIDATES_OPTION );
$topic = [ [ 'title' => 'Νερό και λειψυδρία', 'aliases' => [ 'λειψυδρία', 'αφαλάτωση' ] ] ];
pf_check( 'topics: first edition counted only', 0 === $P::count_new_topics( $topic, '2026-11-14-morning' ) && null === $P::get( 'νερο-και-λειψυδρια' ) );
$P::count_new_topics( $topic, '2026-11-14-morning' );
pf_check( 'topics: same edition counts once', 0 === $P::count_new_topics( [ [ 'title' => 'Λειψυδρία', 'aliases' => [ 'αφαλάτωση' ] ] ], '2026-11-15-morning' ) );
pf_check( 'topics: third edition makes a draft', 1 === $P::count_new_topics( $topic, '2026-11-15-noon' ) && 'draft' === ( $P::get( 'νερο-και-λειψυδρια' )['status'] ?? '' ) );
pf_check( 'topics: covered topic ignored', 0 === $P::count_new_topics( [ [ 'title' => 'Συντάξεις' ] ], '2026-11-16-noon' ) && empty( get_option( $P::CANDIDATES_OPTION ) ) );

class PF_Mock_Client extends PressHub_AI_API_Client {
    public $sys = '';
    public $user = '';
    public $action = '';
    public $reply = '';
    public function __construct() {}
    public function set_action( string $action ): PressHub_AI_API_Client { $this->action = $action; return $this; }
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->sys  = $sys_prompt;
        $this->user = $user_prompt;
        return $this->reply;
    }
}
$mock = new PF_Mock_Client();

$GLOBALS['POSTS_STORE'][77] = [ 'ID' => 77, 'post_content' => '<p>Οι συνταξιούχοι περιμένουν την αύξηση των συντάξεων.</p>', 'post_date' => '2026-11-16 07:00:00', 'post_status' => 'publish' ];
update_post_meta( 77, '_presshub_briefing_date', '2026-11-16' );
update_post_meta( 77, '_presshub_briefing_edition', 'noon' );
$mock->reply = "```json\n" . json_encode( [ 'fiches' => [ [ 'slug' => $slug, 'timeline' => [ [ 'text' => 'Νέα δήλωση για τις συντάξεις.' ] ] ] ], 'new_topics' => [] ] ) . "\n```";
$res = $P::update_from_post( 77, $mock );
pf_check( 'update_from_post: merged', is_array( $res ) && 1 === $res['timeline'] && 1 === $res['fiches'] );
pf_check( 'update_from_post: prompt filled with date, edition and the matched fiche', false !== strpos( $mock->sys, '2026-11-16' ) && false !== strpos( $mock->sys, 'Μεσημβρινή' ) && false !== strpos( $mock->sys, $slug ) && false === strpos( $mock->sys, '{fiches}' ) );
pf_check( 'update_from_post: brief without tags, action set', false !== strpos( $mock->user, 'Οι συνταξιούχοι περιμένουν' ) && false === strpos( $mock->user, '<p>' ) && 'memory_policy' === $mock->action );
$rec = PressHub_AI_Generation_Recorder::get( (int) $res['record_id'] );
pf_check( 'update_from_post: generation record of kind memory_policy', is_array( $rec ) && 'memory_policy' === $rec['kind'] );
pf_check( 'update_from_post: missing post', is_wp_error( $P::update_from_post( 999, $mock ) ) );
$mock->reply = 'όχι json';
pf_check( 'update_from_post: invalid reply', is_wp_error( $P::update_from_post( 77, $mock ) ) );

$mock->reply = json_encode( [ 'fiches' => [
    [ 'title' => 'Δημόσια υγεία', 'aliases' => [ 'ΓεΣΥ', 'νοσοκομεία' ], 'principles' => 'Ποιος πληρώνει.', 'framing' => 'Οι αριθμοί αποδίδονται.', 'facts' => [ 'Το ΓεΣΥ ξεκίνησε το 2019.' ], 'claims' => [ [ 'claim' => 'Οι λίστες αναμονής μειώθηκαν', 'by' => 'ΟΑΥ', 'status' => 'confirmed' ] ], 'timeline' => [ [ 'date' => '2026-11-01', 'text' => 'Νέες προσλήψεις.' ] ], 'actors' => [ 'ΟΑΥ' ], 'questions' => [ 'Πόσο κοστίζει;' ] ],
    [ 'title' => 'Συντάξεις' ],
] ] );
$res = $P::build_drafts( $mock );
$h   = $P::get( 'δημοσια-υγεια' );
pf_check( 'drafts: one created, existing topic skipped', is_array( $res ) && 1 === $res['created'] );
pf_check( 'drafts: created as a draft with its parts, claims never confirmed', is_array( $h ) && 'draft' === $h['status'] && 'generated' === $h['origin'] && 1 === count( $h['facts'] ) && 'unchecked' === $h['claims'][0]['status'] && 'open' === $h['questions'][0]['status'] );
pf_check( 'drafts: prompt lists existing fiches, material has the agenda', false !== strpos( $mock->sys, 'Συνταξιοδοτική μεταρρύθμιση' ) && false === strpos( $mock->sys, '{existing}' ) && false !== strpos( $mock->user, 'Κυπριακό' ) );

$GLOBALS['SCHEDULED_EVENTS'] = [];
$GLOBALS['NEXT_SCHEDULED']   = [];
$P::schedule_update( 77 );
$ev = $GLOBALS['SCHEDULED_EVENTS'][0] ?? [];
pf_check( 'schedule: update queued after the brief', $P::CRON_HOOK === ( $ev['hook'] ?? '' ) && [ 77 ] === ( $ev['args'] ?? null ) );
pf_check( 'schedule: drafts queued', $P::schedule_drafts() && $P::DRAFT_HOOK === ( $GLOBALS['SCHEDULED_EVENTS'][1]['hook'] ?? '' ) && 'queued' === ( get_option( $P::DRAFT_STATE )['status'] ?? '' ) );
$P::register_hooks();
pf_check( 'schedule: hooked to the digest build', false !== has_action( 'presshub_ai_memory_digest_built', [ $P, 'schedule_update' ] ) );
pf_check( 'admin-post handlers registered', false !== has_action( 'admin_post_' . $P::POST_SAVE, [ $P, 'handle_save' ] ) && false !== has_action( 'admin_post_' . $P::POST_DRAFT, [ $P, 'handle_draft' ] ) );

// 7. Hub alerts, page, wiring.
$hub = $P::hub_alerts_html( [ [ 'title' => 'Αύξηση συντάξεων', 'content' => 'Η Βουλή φταίει, λέει η κυβέρνηση.' ] ], '2026-11-16' );
pf_check( 'hub: matched fiche with the claim to watch and the repeated claims', false !== strpos( $hub, 'presshub-policy-alerts' ) && false !== strpos( $hub, 'Συνταξιοδοτική μεταρρύθμιση' ) && false !== strpos( $hub, 'ανακριβής' ) && false !== strpos( $hub, 'repeated these claims as fact' ) );
pf_check( 'hub: nothing to show', '' === $P::hub_alerts_html( [ [ 'title' => 'Καιρός' ] ], '2026-01-01' ) );

$list = $P::build_page( [ 'notice' => 'created' ] );
pf_check( 'page: list with fiches, notice and the draft button', false !== strpos( $list, 'Συνταξιοδοτική μεταρρύθμιση' ) && false !== strpos( $list, 'notice-success' ) && false !== strpos( $list, $P::POST_DRAFT ) && false !== strpos( $list, $P::POST_CREATE ) );
$edit = $P::build_page( [ 'fiche' => 'δημοσια-υγεια' ] );
pf_check( 'page: edit form with core, claims, questions, preview', false !== strpos( $edit, 'name="principles"' ) && false !== strpos( $edit, 'name="new_claim[claim]"' ) && false !== strpos( $edit, 'name="new_question"' ) && false !== strpos( $edit, $P::POST_DELETE ) && false !== strpos( $edit, 'Draft: not used in prompts' ) );
$P::merge_update( [ 'fiches' => [ [ 'slug' => 'δημοσια-υγεια', 'suggestions' => [ [ 'field' => 'framing', 'text' => 'Κανόνας.', 'reason' => 'Λόγος.' ] ] ] ] ], '2026-11-16', 'x' );
$edit = $P::build_page( [ 'fiche' => 'δημοσια-υγεια' ] );
pf_check( 'page: pending suggestion with accept and dismiss', false !== strpos( $edit, $P::POST_SUGGESTION ) && false !== strpos( $edit, 'value="accept"' ) && false !== strpos( $edit, 'value="dismiss"' ) );
$admin = PressHub_AI_Fiches_Admin::build_policy_page( [] );
pf_check( 'page: under Policy Fiches with its title', false !== strpos( $admin, '<h1>Policies</h1>' ) && false !== strpos( $admin, 'presshub-fiches-page' ) );
pf_check( 'delete', $P::delete( 'δημοσια-υγεια' ) && null === $P::get( 'δημοσια-υγεια' ) && ! $P::delete( 'δημοσια-υγεια' ) );

$api_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-api-client.php' );
$cp      = substr( $api_src, strpos( $api_src, 'public function call_provider(' ) );
$cp      = substr( $cp, 0, strpos( $cp, 'call_anthropic(' ) );
pf_check( 'wiring: material captured before the agenda, fiches applied after the people', strpos( $cp, '$policy_material =' ) < strpos( $cp, 'Editorial_Agenda::apply' ) && strpos( $cp, 'Stakeholders::apply' ) < strpos( $cp, 'Policy_Fiches::apply( $sys_prompt, $policy_material, $this->get_action() )' ) );
pf_check( 'wiring: kind registered', in_array( 'memory_policy', PressHub_AI_Generation_Recorder::KINDS, true ) );
$uninstall = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
pf_check( 'wiring: uninstall removes the options', false !== strpos( $uninstall, "'presshub_ai_policy_fiches'" ) && false !== strpos( $uninstall, "'presshub_ai_policy_alerts'" ) );
$hub_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-briefing-admin.php' );
pf_check( 'wiring: Hub shows the alerts', false !== strpos( $hub_src, 'PressHub_AI_Policy_Fiches::hub_alerts_html(' ) );

delete_option( $P::OPTION );
delete_option( $P::CANDIDATES_OPTION );
delete_option( $P::ALERTS_OPTION );

if ( $failures > 0 ) {
    echo "PolicyFichesTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "PolicyFichesTest: OK ({$checks} checks)\n";
