<?php
/**
 * Test suite for PressHub_AI_Memory_Rollup_Builder (GitHub Issue #136).
 *
 * Verifies: week detection inside the window, scheduling, input built from edited
 * content_json (include=false stories dropped), links, retry then failed, staleness after a
 * digest edit (and no automatic rebuild), edited rollup preserved on rebuild, missing days,
 * previous-week continuity, generation records, gating, range processing (CLI core) and wiring.
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
require_once dirname( __DIR__ ) . '/includes/class-memory-store.php';
require_once dirname( __DIR__ ) . '/includes/class-memory-digest-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-memory-rollup-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-generation-recorder.php';

$failures = 0;
$checks   = 0;
function mrb_check( string $label, bool $condition ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    } else {
        echo "PASS: {$label}\n";
    }
}

class MockRollupClient extends PressHub_AI_API_Client {
    public array $call_log = [];
    public array $responses = [];
    public int $call_index = 0;

    public function __construct( array $responses = [] ) {
        parent::__construct( 'briefing_memory' );
        $this->responses = $responses;
    }

    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->call_log[] = [ 'sys_prompt' => $sys_prompt, 'user_prompt' => $user_prompt, 'json_mode' => $json_mode, 'metadata' => $metadata ];
        $idx = $this->call_index++;
        return $this->responses[ $idx ] ?? end( $this->responses );
    }
}

function mrb_story( string $key, string $title = '', string $dev = 'Εξέλιξη.', bool $include = true ): array {
    return [
        'key' => $key, 'title' => '' !== $title ? $title : "Τίτλος {$key}", 'development' => $dev,
        'status' => 'new', 'entities' => [ 'Οντότητα' ], 'source_urls' => [], 'include' => $include,
    ];
}

function mrb_content( array $stories ): array {
    return [ 'stories' => $stories, 'podcast' => [ 'opening_line' => '', 'closing_line' => '' ], 'narrative' => '' ];
}

function mrb_seed_digest( string $date, array $stories, string $edition = 'morning' ): int {
    $res = PressHub_AI_Memory_Store::upsert_generated( [
        'scope' => 'daily', 'period_start' => $date, 'period_end' => $date, 'edition' => $edition,
        'generated_content' => mrb_content( $stories ),
        'status' => 'active',
    ] );
    return (int) $res['id'];
}

function mrb_rollup_json( array $keys, string $narrative = 'Συμπαγής αφήγηση υποβάθρου της εβδομάδας.' ): string {
    $stories = [];
    foreach ( $keys as $k ) {
        $stories[] = mrb_story( $k, "Τίτλος {$k}", "Εβδομαδιαία εξέλιξη {$k}." );
    }
    return json_encode( [ 'narrative' => $narrative, 'stories' => $stories ], JSON_UNESCAPED_UNICODE );
}

function mrb_links( int $rollup_id ): array {
    global $wpdb;
    $t    = PressHub_AI_Memory_Store::get_links_table();
    $ids  = [];
    foreach ( (array) ( $wpdb->tables[ $t ] ?? [] ) as $row ) { // The stub $wpdb cannot run SELECTs on the links table.
        if ( (int) $row['rollup_id'] === $rollup_id ) {
            $ids[] = (int) $row['digest_id'];
        }
    }
    sort( $ids );
    return $ids;
}

// Environment: today = Wed 2026-11-18, background window 30 days -> from 2026-10-19.
$GLOBALS['OPTIONS_STORE']      = [ 'presshub_ai_memory_enabled' => 1, 'presshub_ai_memory_background_days' => 30, 'presshub_ai_memory_daily_days' => 7 ];
$GLOBALS['CURRENT_TEST_TIME']  = '2026-11-18 10:00:00';
$GLOBALS['POSTS_STORE']        = [];
$GLOBALS['POST_META_STORE']    = [];
$GLOBALS['SCHEDULED_EVENTS']   = [];
$GLOBALS['NEXT_SCHEDULED']     = [];
$GLOBALS['CURRENT_USER_ID']    = 1;

PressHub_AI_Memory_Store::create_tables();
PressHub_AI_Generation_Recorder::create_table();

echo "Running MemoryRollupBuilderTest...\n";

// ---- Date helpers ----------------------------------------------------------------------
mrb_check( 'week_bounds: Wednesday maps to Mon-Sun', PressHub_AI_Memory_Rollup_Builder::week_bounds( '2026-11-04' ) === [ '2026-11-02', '2026-11-08' ] );
mrb_check( 'week_bounds: Sunday stays in its own week', PressHub_AI_Memory_Rollup_Builder::week_bounds( '2026-11-08' ) === [ '2026-11-02', '2026-11-08' ] );
mrb_check( 'week_bounds: Monday maps to itself', PressHub_AI_Memory_Rollup_Builder::week_bounds( '2026-11-09' )[0] === '2026-11-09' );

// ---- Prompt asset ----------------------------------------------------------------------
$prompt = PressHub_AI_Prompt_Loader::get_weekly_rollup_prompt();
mrb_check( 'prompt: weekly_rollup asset loads (Greek, placeholders)', false !== strpos( $prompt, 'εβδομαδιαία' ) && false !== strpos( $prompt, '{week_start}' ) && false !== strpos( $prompt, '{story_keys}' ) );

// ---- Seed digests ------------------------------------------------------------------------
// Week 2026-10-12 (older than the window): must never be listed.
mrb_seed_digest( '2026-10-14', [ mrb_story( 'palaio-thema' ) ] );
// Week 2026-10-19: no digests at all -> not listed (nothing to summarise).
// Week 2026-10-26: two digests.
$d_1026 = mrb_seed_digest( '2026-10-26', [ mrb_story( 'energeia' ) ] );
$d_1028 = mrb_seed_digest( '2026-10-28', [ mrb_story( 'energeia' ), mrb_story( 'forologia' ) ] );
// Week 2026-11-02: four digests (Mon, Tue, Thu, Fri) -> missing Wed, Sat, Sun.
$d_1102 = mrb_seed_digest( '2026-11-02', [ mrb_story( 'energeia', 'Ενέργεια', 'Αρχική εξέλιξη ενέργειας.' ), mrb_story( 'aporriptea', 'Απόρρητο θέμα', 'Να μην εμφανιστεί.', false ) ] );
$d_1103 = mrb_seed_digest( '2026-11-03', [ mrb_story( 'forologia' ) ] );
$d_1105 = mrb_seed_digest( '2026-11-05', [ mrb_story( 'energeia' ) ] );
$d_1106 = mrb_seed_digest( '2026-11-06', [ mrb_story( 'ygeia' ) ], 'noon' );
// Week 2026-11-09: full week, two editions on one day.
$wk3 = [];
for ( $i = 9; $i <= 15; $i++ ) {
    $wk3[] = mrb_seed_digest( sprintf( '2026-11-%02d', $i ), [ mrb_story( 'energeia' ) ] );
}
$wk3[] = mrb_seed_digest( '2026-11-09', [ mrb_story( 'ygeia' ) ], 'noon' );
// Week 2026-11-16: current (incomplete) week -> must not be listed.
mrb_seed_digest( '2026-11-16', [ mrb_story( 'energeia' ) ] );

// ---- 1. Detection ------------------------------------------------------------------------
$missing = PressHub_AI_Memory_Rollup_Builder::find_missing_weeks();
$starts  = array_column( $missing, 'week_start' );
mrb_check( 'detection: completed weeks in window with digests and no rollup', $starts === [ '2026-10-26', '2026-11-02', '2026-11-09' ] );
mrb_check( 'detection: incomplete current week excluded', ! in_array( '2026-11-16', $starts, true ) );
mrb_check( 'detection: week older than window excluded', ! in_array( '2026-10-12', $starts, true ) );
mrb_check( 'detection: digest-less week excluded', ! in_array( '2026-10-19', $starts, true ) );
mrb_check( 'detection: digest_count reported', 2 === $missing[0]['digest_count'] && 8 === $missing[2]['digest_count'] );
mrb_check( 'detection: background_days=0 yields nothing', [] === PressHub_AI_Memory_Rollup_Builder::find_missing_weeks( null, 0 ) );

// ---- 2. Scheduling -------------------------------------------------------------------------
$GLOBALS['SCHEDULED_EVENTS'] = [];
$scheduled = PressHub_AI_Memory_Rollup_Builder::handle_cron_scan();
mrb_check( 'scheduling: one single event per week', $scheduled === [ '2026-10-26', '2026-11-02', '2026-11-09' ] && count( $GLOBALS['SCHEDULED_EVENTS'] ) === 3 );
mrb_check( 'scheduling: events use week hook with week_start arg', $GLOBALS['SCHEDULED_EVENTS'][1]['hook'] === PressHub_AI_Memory_Rollup_Builder::WEEK_CRON_HOOK && $GLOBALS['SCHEDULED_EVENTS'][1]['args'] === [ '2026-11-02' ] );
mrb_check( 'scheduling: events are spread out', $GLOBALS['SCHEDULED_EVENTS'][1]['time'] > $GLOBALS['SCHEDULED_EVENTS'][0]['time'] );

// ---- 3. Gating ------------------------------------------------------------------------------
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled'] = 0;
$GLOBALS['SCHEDULED_EVENTS'] = [];
$gate_client = new MockRollupClient( [ mrb_rollup_json( [ 'energeia' ] ) ] );
mrb_check( 'gating: scan schedules nothing when disabled', [] === PressHub_AI_Memory_Rollup_Builder::handle_cron_scan() && empty( $GLOBALS['SCHEDULED_EVENTS'] ) );
$gate_res = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-11-02', false, $gate_client );
mrb_check( 'gating: build_week skipped, no LLM call, no entry', is_array( $gate_res ) && 'skipped' === $gate_res['status'] && 'memory_disabled' === $gate_res['reason'] && 0 === count( $gate_client->call_log ) && null === PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' ) );
PressHub_AI_Memory_Rollup_Builder::process_range( '2026-11-01', '2026-11-15', true, $gate_client );
mrb_check( 'gating: range processing makes no LLM call', 0 === count( $gate_client->call_log ) );
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled'] = 1;

// ---- 4. Invalid week start --------------------------------------------------------------------
$bad = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-11-04', false, new MockRollupClient( [ 'x' ] ) );
mrb_check( 'validation: non-Monday week_start rejected', is_wp_error( $bad ) && 'invalid_week_start' === $bad->get_error_code() );

// ---- 5. Build week 10-26 (no previous rollup) ---------------------------------------------------
$c1  = new MockRollupClient( [ "```json\n" . mrb_rollup_json( [ 'energeia', 'forologia' ], 'Αφήγηση πρώτης εβδομάδας.' ) . "\n```" ] );
$r1  = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-10-26', false, $c1 );
mrb_check( 'build: week built (fence stripped)', is_array( $r1 ) && 'built' === $r1['status'] && ! empty( $r1['id'] ) );
mrb_check( 'build: provider called once with json mode', 1 === count( $c1->call_log ) && true === $c1->call_log[0]['json_mode'] );
mrb_check( 'build: prompt says no previous rollup', false !== strpos( $c1->call_log[0]['user_prompt'], 'Δεν υπάρχει σύνοψη προηγούμενης εβδομάδας' ) );
$e1 = PressHub_AI_Memory_Store::get_weekly_entry( '2026-10-26' );
mrb_check( 'build: weekly entry shape (period, edition, active, generated)', $e1 && '2026-10-26' === $e1['period_start'] && '2026-11-01' === $e1['period_end'] && '' === $e1['edition'] && 'active' === $e1['status'] && 'generated' === $e1['origin'] );
$cnt1 = PressHub_AI_Memory_Store::decode_content( (string) $e1['content_json'] );
mrb_check( 'build: narrative and 2 stories stored with existing keys', 'Αφήγηση πρώτης εβδομάδας.' === $cnt1['narrative'] && count( $cnt1['stories'] ) === 2 && 'energeia' === $cnt1['stories'][0]['key'] );
mrb_check( 'links: digests used are linked', mrb_links( (int) $r1['id'] ) === [ min( $d_1026, $d_1028 ), max( $d_1026, $d_1028 ) ] );
mrb_check( 'links: return value lists digest ids', count( $r1['digest_ids'] ) === 2 );

// Generation record
$rec = PressHub_AI_Generation_Recorder::get( (int) $e1['generation_record_id'] );
mrb_check( 'record: memory_rollup record linked from entry', $rec && 'memory_rollup' === $rec['kind'] && 'success' === $rec['status'] && '2026-10-26' === $rec['briefing_date'] );
mrb_check( 'record: generation_record_id returned', (int) $r1['generation_record_id'] === (int) $e1['generation_record_id'] );

// ---- 6. Missing days, edited input, include=false, previous narrative ----------------------------
// Editor corrects a daily digest BEFORE the rollup is built.
PressHub_AI_Memory_Store::update_content( $d_1102, mrb_content( [ mrb_story( 'energeia', 'Ενέργεια', 'ΔΙΟΡΘΩΜΕΝΗ ΑΠΟ ΣΥΝΤΑΚΤΗ εξέλιξη.' ), mrb_story( 'aporriptea', 'Απόρρητο θέμα', 'Να μην εμφανιστεί.', false ) ] ), 1 );

$c2 = new MockRollupClient( [ mrb_rollup_json( [ 'energeia', 'forologia', 'ygeia' ], 'Αφήγηση δεύτερης εβδομάδας.' ) ] );
$r2 = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-11-02', false, $c2 );
mrb_check( 'input: week 2 built', is_array( $r2 ) && 'built' === $r2['status'] );
$up2 = $c2->call_log[0]['user_prompt'];
mrb_check( 'input: edited content_json used', false !== strpos( $up2, 'ΔΙΟΡΘΩΜΕΝΗ ΑΠΟ ΣΥΝΤΑΚΤΗ' ) && false === strpos( $up2, 'Αρχική εξέλιξη ενέργειας' ) );
mrb_check( 'input: include=false story left out', false === strpos( $up2, 'Απόρρητο θέμα' ) && false === strpos( $c2->call_log[0]['sys_prompt'], 'aporriptea' ) );
mrb_check( 'input: previous week narrative supplied', false !== strpos( $up2, 'Αφήγηση πρώτης εβδομάδας.' ) );
mrb_check( 'input: allowed story keys in system prompt', false !== strpos( $c2->call_log[0]['sys_prompt'], 'energeia' ) && false !== strpos( $c2->call_log[0]['sys_prompt'], 'ygeia' ) );
mrb_check( 'missing: return value lists missing dates', $r2['missing_dates'] === [ '2026-11-04', '2026-11-07', '2026-11-08' ] );
mrb_check( 'missing: dates in prompt', false !== strpos( $up2, '2026-11-04, 2026-11-07, 2026-11-08' ) );
$e2   = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' );
$cnt2 = PressHub_AI_Memory_Store::decode_content( (string) $e2['content_json'] );
mrb_check( 'missing: recorded in the stored entry', ( $cnt2['missing_dates'] ?? [] ) === [ '2026-11-04', '2026-11-07', '2026-11-08' ] && 'active' === $e2['status'] );
mrb_check( 'links: week 2 links the 4 digests', count( mrb_links( (int) $r2['id'] ) ) === 4 );

// A full week records no missing_dates key.
$c3 = new MockRollupClient( [ mrb_rollup_json( [ 'energeia', 'ygeia' ] ) ] );
$r3 = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-11-09', false, $c3 );
$e3 = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-09' );
mrb_check( 'missing: full week stores no missing_dates', [] === $r3['missing_dates'] && ! array_key_exists( 'missing_dates', PressHub_AI_Memory_Store::decode_content( (string) $e3['content_json'] ) ) );
mrb_check( 'build: existing entry without force is skipped', 'skipped' === PressHub_AI_Memory_Rollup_Builder::build_week( '2026-11-09', false, $c3 )['status'] && 1 === count( $c3->call_log ) );

// ---- 7. Retry then success; retry then failure -----------------------------------------------------
$c4 = new MockRollupClient( [ '{"narrative": "abc", "stories": [', mrb_rollup_json( [ 'energeia' ], 'Μετά την επανάληψη.' ) ] );
$r4 = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-11-09', true, $c4 );
mrb_check( 'retry: succeeds on attempt 2 with 2 calls', is_array( $r4 ) && 'built' === $r4['status'] && 2 === count( $c4->call_log ) );
mrb_check( 'retry: error appended to second prompt', false !== strpos( $c4->call_log[1]['user_prompt'], 'ΠΡΟΗΓΟΥΜΕΝΟ ΣΦΑΛΜΑ ΕΠΙΚΥΡΩΣΗΣ JSON' ) );
$e3b = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-09' );
mrb_check( 'retry: content replaced by valid attempt', 'Μετά την επανάληψη.' === PressHub_AI_Memory_Store::decode_content( (string) $e3b['content_json'] )['narrative'] );
mrb_check( 'retry: first record error, second success', 'error' === PressHub_AI_Generation_Recorder::get( (int) $r4['generation_record_id'] - 1 )['status'] && 'success' === PressHub_AI_Generation_Recorder::get( (int) $r4['generation_record_id'] )['status'] );

// Unknown key / missing narrative are validation errors that trigger the retry.
$c5 = new MockRollupClient( [ mrb_rollup_json( [ 'epinoimeno-key' ] ), mrb_rollup_json( [ 'energeia' ], '' ) ] );
$r5 = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-11-09', true, $c5 );
mrb_check( 'validation: unknown key then empty narrative fails after exactly one retry', is_wp_error( $r5 ) && 'memory_rollup_failed' === $r5->get_error_code() && 2 === count( $c5->call_log ) );
mrb_check( 'validation: retry prompt names the unknown key error', false !== strpos( $c5->call_log[1]['user_prompt'], 'unknown_rollup_story_key' ) );
$e3c = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-09' );
mrb_check( 'failure on existing entry with stories: content kept, status stale, error recorded', 'stale' === $e3c['status'] && '' !== (string) $e3c['error_message'] && 'Μετά την επανάληψη.' === PressHub_AI_Memory_Store::decode_content( (string) $e3c['content_json'] )['narrative'] );

$c6 = new MockRollupClient( [ mrb_rollup_json( [ 'energeia' ], str_repeat( 'α', 2500 ) ) ] );
$r6 = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-11-09', true, $c6 );
mrb_check( 'validation: over-long narrative rejected after retry', is_wp_error( $r6 ) && 2 === count( $c6->call_log ) );

// Failed week without prior entry.
mrb_seed_digest( '2026-10-20', [ mrb_story( 'nea-evdomada' ) ] );
$c7 = new MockRollupClient( [ 'MALFORMED_1', 'MALFORMED_2' ] );
$r7 = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-10-19', false, $c7 );
$e7 = PressHub_AI_Memory_Store::get_weekly_entry( '2026-10-19' );
mrb_check( 'failure: WP_Error returned after retry', is_wp_error( $r7 ) && 'memory_rollup_failed' === $r7->get_error_code() && 2 === count( $c7->call_log ) );
$e7c = PressHub_AI_Memory_Store::decode_content( (string) $e7['content_json'] );
mrb_check( 'failure: entry stored failed with error, no partial content', $e7 && 'failed' === $e7['status'] && '' !== (string) $e7['error_message'] && [] === $e7c['stories'] && '' === $e7c['narrative'] );
$rec7 = PressHub_AI_Generation_Recorder::get( (int) $e7['generation_record_id'] );
mrb_check( 'failure: last generation record is memory_rollup with error', $rec7 && 'memory_rollup' === $rec7['kind'] && 'error' === $rec7['status'] );

// Provider error is also a failure (retried once).
mrb_seed_digest( '2026-10-13', [ mrb_story( 'prin-to-parathyro' ) ] ); // out of window but explicit build allowed
$c8 = new MockRollupClient( [ new WP_Error( 'http_500', 'LLM down' ) ] );
$r8 = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-10-12', false, $c8 );
mrb_check( 'failure: provider error retried once then failed', is_wp_error( $r8 ) && 2 === count( $c8->call_log ) && 'failed' === PressHub_AI_Memory_Store::get_weekly_entry( '2026-10-12' )['status'] );

// No digests at all
$r9 = PressHub_AI_Memory_Rollup_Builder::build_week( '2026-09-28', false, new MockRollupClient( [ 'x' ] ) );
mrb_check( 'no digests: WP_Error and no entry created', is_wp_error( $r9 ) && 'no_digests' === $r9->get_error_code() && null === PressHub_AI_Memory_Store::get_weekly_entry( '2026-09-28' ) );

// Excluded rollup stays excluded across failure and success
$e_excl = PressHub_AI_Memory_Store::get_weekly_entry( '2026-10-26' );
PressHub_AI_Memory_Store::set_status( (int) $e_excl['id'], 'excluded' );
PressHub_AI_Memory_Rollup_Builder::build_week( '2026-10-26', true, new MockRollupClient( [ 'BAD', 'BAD' ] ) );
mrb_check( 'excluded: stays excluded on failed rebuild', 'excluded' === PressHub_AI_Memory_Store::get_weekly_entry( '2026-10-26' )['status'] );
PressHub_AI_Memory_Rollup_Builder::rebuild( (int) $e_excl['id'], new MockRollupClient( [ mrb_rollup_json( [ 'energeia' ], 'Νέα αφήγηση.' ) ] ) );
mrb_check( 'excluded: stays excluded on successful rebuild', 'excluded' === PressHub_AI_Memory_Store::get_weekly_entry( '2026-10-26' )['status'] );
PressHub_AI_Memory_Store::set_status( (int) $e_excl['id'], 'active' );

// ---- 8. Staleness after a digest edit; not rebuilt automatically ---------------------------------------
$e2_before = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' );
mrb_check( 'stale: week 2 active before the digest edit', 'active' === $e2_before['status'] );
PressHub_AI_Memory_Store::update_content( $d_1103, mrb_content( [ mrb_story( 'forologia', 'Φορολογία', 'Νέα διόρθωση συντάκτη.' ) ] ), 1 );
$e2_after = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' );
mrb_check( 'stale: digest edit marks the rollup stale', 'stale' === $e2_after['status'] );
$GLOBALS['SCHEDULED_EVENTS'] = [];
$missing_now = array_column( PressHub_AI_Memory_Rollup_Builder::find_missing_weeks(), 'week_start' );
PressHub_AI_Memory_Rollup_Builder::handle_cron_scan();
mrb_check( 'stale: stale rollup is not detected as missing and not auto-scheduled', ! in_array( '2026-11-02', $missing_now, true ) && ! in_array( '2026-11-02', array_map( fn( $e ) => $e['args'][0] ?? '', $GLOBALS['SCHEDULED_EVENTS'] ), true ) );

// Rebuild of a stale, generated rollup replaces content and returns to active.
$cr    = new MockRollupClient( [ mrb_rollup_json( [ 'energeia', 'forologia' ], 'Ανανεωμένη αφήγηση.' ) ] );
$rr    = PressHub_AI_Memory_Rollup_Builder::rebuild( (int) $e2_after['id'], $cr );
$e2_rb = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' );
mrb_check( 'rebuild: stale generated rollup refreshed and active again', is_array( $rr ) && 'built' === $rr['status'] && 'active' === $e2_rb['status'] && 'Ανανεωμένη αφήγηση.' === PressHub_AI_Memory_Store::decode_content( (string) $e2_rb['content_json'] )['narrative'] );
mrb_check( 'rebuild: uses the digest edit as input', false !== strpos( $cr->call_log[0]['user_prompt'], 'Νέα διόρθωση συντάκτη.' ) );
$rr_nf = PressHub_AI_Memory_Rollup_Builder::rebuild( 999999, $cr );
mrb_check( 'rebuild: unknown id returns WP_Error', is_wp_error( $rr_nf ) && 'rollup_not_found' === $rr_nf->get_error_code() );
$rr_daily = PressHub_AI_Memory_Rollup_Builder::rebuild( $d_1102, $cr );
mrb_check( 'rebuild: daily entry id rejected', is_wp_error( $rr_daily ) );

// ---- 9. Edited rollup preserved on rebuild ---------------------------------------------------------------
$edited_content = [
    'stories'   => [ mrb_story( 'energeia', 'Ενέργεια', 'ΧΕΙΡΟΓΡΑΦΗ ΔΙΟΡΘΩΣΗ ΡΟΛΟΥ.' ) ],
    'podcast'   => [ 'opening_line' => '', 'closing_line' => '' ],
    'narrative' => 'ΧΕΙΡΟΓΡΑΦΗ ΑΦΗΓΗΣΗ.',
];
PressHub_AI_Memory_Store::update_content( (int) $e2_rb['id'], $edited_content, 1 );
// A digest changes again so the edited rollup goes stale.
PressHub_AI_Memory_Store::update_content( $d_1105, mrb_content( [ mrb_story( 'energeia', 'Ενέργεια', 'Ακόμη μία διόρθωση.' ) ] ), 1 );
$e2_ed = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' );
mrb_check( 'edited: rollup is edited and stale before rebuild', 'edited' === $e2_ed['origin'] && 'stale' === $e2_ed['status'] );

$ce  = new MockRollupClient( [ mrb_rollup_json( [ 'energeia' ], 'ΝΕΑ ΓΕΝΝΗΜΕΝΗ ΑΦΗΓΗΣΗ.' ) ] );
$re  = PressHub_AI_Memory_Rollup_Builder::rebuild( (int) $e2_ed['id'], $ce );
$e2e = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' );
mrb_check( 'edited: rebuild reports preserved_edit', is_array( $re ) && ! empty( $re['preserved_edit'] ) && 'edited' === $re['origin'] );
mrb_check( 'edited: content_json untouched by rebuild', $e2e['content_json'] === $e2_ed['content_json'] && $e2e['version_hash'] === $e2_ed['version_hash'] );
mrb_check( 'edited: generated_json refreshed', false !== strpos( (string) $e2e['generated_json'], 'ΝΕΑ ΓΕΝΝΗΜΕΝΗ ΑΦΗΓΗΣΗ.' ) );
mrb_check( 'edited: rebuilt edited rollup stays stale so the editor reviews the diff', 'stale' === (string) $e2e['status'] );
mrb_check( 'edited: origin stays edited and the error message is cleared by the rebuild', 'edited' === $e2e['origin'] && '' === (string) $e2e['error_message'] );

// Failed rebuild on edited rollup keeps status and content.
PressHub_AI_Memory_Store::update_content( $d_1106, mrb_content( [ mrb_story( 'ygeia', 'Υγεία', 'Αλλαγή.' ) ] ), 1 );
mrb_check( 'edited: stale again after another digest edit', 'stale' === PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' )['status'] );
$rf  = PressHub_AI_Memory_Rollup_Builder::rebuild( (int) $e2_ed['id'], new MockRollupClient( [ 'BAD', 'BAD' ] ) );
$e2f = PressHub_AI_Memory_Store::get_weekly_entry( '2026-11-02' );
mrb_check( 'edited: failed rebuild keeps editor content and status, records error', is_wp_error( $rf ) && $e2f['content_json'] === $e2_ed['content_json'] && 'stale' === $e2f['status'] && '' !== (string) $e2f['error_message'] );

// ---- 10. Range processing (CLI core) ----------------------------------------------------------------------
mrb_seed_digest( '2026-08-04', [ mrb_story( 'augoustos' ) ] );
mrb_seed_digest( '2026-08-12', [ mrb_story( 'augoustos' ) ] );
$cp  = new MockRollupClient( [ mrb_rollup_json( [ 'augoustos' ], 'Αφήγηση Αυγούστου.' ) ] );
$sum = PressHub_AI_Memory_Rollup_Builder::process_range( '2026-08-01', '2026-08-31', false, $cp );
mrb_check( 'range: builds each missing past week once', is_array( $sum ) && 2 === $sum['total'] && 2 === $sum['succeeded'] && 0 === $sum['failed'] && 2 === count( $cp->call_log ) );
mrb_check( 'range: weeks reported', array_keys( $sum['weeks'] ) === [ '2026-08-03', '2026-08-10' ] && 'built' === $sum['weeks']['2026-08-03'] );
$sum2 = PressHub_AI_Memory_Rollup_Builder::process_range( '2026-08-01', '2026-08-31', false, $cp );
mrb_check( 'range: second run without force builds nothing (idempotent)', 0 === $sum2['total'] && 2 === count( $cp->call_log ) );
$sum3 = PressHub_AI_Memory_Rollup_Builder::process_range( '2026-08-01', '2026-08-31', true, $cp );
mrb_check( 'range: --force rebuilds existing weeks', 2 === $sum3['total'] && 2 === $sum3['succeeded'] && 4 === count( $cp->call_log ) );
$sum4 = PressHub_AI_Memory_Rollup_Builder::process_range( '2026-11-16', '2026-11-30', false, $cp );
mrb_check( 'range: incomplete current week is not built', 0 === $sum4['total'] );
$sum5 = PressHub_AI_Memory_Rollup_Builder::process_range( '2026-09-01', '2026-08-01' );
mrb_check( 'range: reversed range is an error', is_wp_error( $sum5 ) && 'invalid_date_range' === $sum5->get_error_code() );
$cf = new MockRollupClient( [ 'BAD', 'BAD' ] );
mrb_seed_digest( '2026-07-07', [ mrb_story( 'ioulios' ) ] );
$sum6 = PressHub_AI_Memory_Rollup_Builder::process_range( '2026-07-01', '2026-07-31', false, $cf );
mrb_check( 'range: failures are counted', 1 === $sum6['total'] && 1 === $sum6['failed'] && 'failed' === $sum6['weeks']['2026-07-06'] );

mrb_check( 'context: rollup narrative is readable by the store consumers', 'Αφήγηση Αυγούστου.' === PressHub_AI_Memory_Store::decode_content( (string) PressHub_AI_Memory_Store::get_weekly_entry( '2026-08-03' )['content_json'] )['narrative'] );

// ---- 11. Wiring ----------------------------------------------------------------------------------------------
$main = (string) file_get_contents( dirname( __DIR__ ) . '/presshub-ai-editor.php' );
mrb_check( 'wiring: builder required and initialised', false !== strpos( $main, 'class-memory-rollup-builder.php' ) && false !== strpos( $main, 'PressHub_AI_Memory_Rollup_Builder::init()' ) );
mrb_check( 'wiring: cron ensured on activation/upgrade and cleared on deactivation', substr_count( $main, 'PressHub_AI_Memory_Rollup_Builder::ensure_cron_scheduled()' ) >= 2 && false !== strpos( $main, 'PressHub_AI_Memory_Rollup_Builder::clear_cron()' ) );
mrb_check( 'wiring: cron hook name is the one from the issue', 'presshub_ai_memory_build_rollups' === PressHub_AI_Memory_Rollup_Builder::CRON_HOOK );
$GLOBALS['RECURRING_EVENTS'] = [];
$GLOBALS['SCHEDULED_CRONS']  = [];
$GLOBALS['NEXT_SCHEDULED']   = [];
PressHub_AI_Memory_Rollup_Builder::ensure_cron_scheduled();
$hooks = array_merge( array_column( (array) $GLOBALS['RECURRING_EVENTS'], 'hook' ), array_column( (array) $GLOBALS['SCHEDULED_CRONS'], 'hook' ) );
mrb_check( 'wiring: ensure_cron_scheduled registers the daily event', in_array( 'presshub_ai_memory_build_rollups', $hooks, true ) );

if ( $failures > 0 ) {
    fwrite( STDERR, "\nMemoryRollupBuilderTest: {$failures} failure(s) out of {$checks} checks\n" );
    exit( 1 );
}

echo "\nMemoryRollupBuilderTest: ALL {$checks} CHECKS PASSED SUCCESSFULLY!\n";
echo "MemoryRollupBuilderTest: OK\n";
exit( 0 );
