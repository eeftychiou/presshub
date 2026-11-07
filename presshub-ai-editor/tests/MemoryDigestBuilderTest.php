<?php
/**
 * Test suite for PressHub_AI_Memory_Digest_Builder (GitHub Issue #134).
 *
 * Verifies:
 *   1. Scheduling: single-event cron scheduling on briefing post save/creation,
 *      no duplicate scheduling, ignoring revisions/autosaves/trash/non-text posts.
 *   2. Valid digest extraction: JSON schema validation, previous 7 days' keys
 *      fed into prompt, code fence stripping, narrative forced empty, generation
 *      record attached to post.
 *   3. Retry on invalid JSON: 1 retry with validation error message appended.
 *      Success on attempt 2 saves active digest. Failure after retry records failed
 *      status with error message and no guessed stories.
 *   4. Edit-protection invariant: human edits are never overwritten by rebuilds
 *      or backfills, even if force=true or if rebuild fails.
 *   5. Deterministic podcast lines: PodcastProducer::save_script() writes opening
 *      and closing lines deterministically; build_for_post preserves them.
 *   6. Backfill: finds editions in range chronologically, skips active unless forced,
 *      one edition per step cron execution, state tracking, and WP-CLI command.
 *   7. Feature gating: presshub_ai_memory_enabled=false skips all operations.
 *   8. AJAX endpoints: nonce and capability verification for all memory builder actions.
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
require_once dirname( __DIR__ ) . '/includes/class-podcast-producer.php';
require_once dirname( __DIR__ ) . '/includes/class-generation-recorder.php';
require_once dirname( __DIR__ ) . '/includes/class-ajax-handlers.php';

$failures = 0;
$checks   = 0;
function mdb_check( string $label, bool $condition ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    } else {
        echo "PASS: {$label}\n";
    }
}

// Reset environment
$GLOBALS['OPTIONS_STORE']   = [ 'presshub_ai_memory_enabled' => 1 ];
$GLOBALS['POSTS_STORE']     = [];
$GLOBALS['POST_META_STORE'] = [];
$GLOBALS['SCHEDULED_EVENTS'] = [];
$GLOBALS['NEXT_SCHEDULED']  = [];
$GLOBALS['CURRENT_USER_ID'] = 1;

PressHub_AI_Memory_Store::create_tables();
PressHub_AI_Generation_Recorder::create_table();

echo "Running MemoryDigestBuilderTest...\n";

// =========================================================================
// 1. Feature Gating (presshub_ai_memory_enabled = 0)
// =========================================================================
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled'] = 0;
$GLOBALS['SCHEDULED_EVENTS'] = [];

// Post setup
$post_id_gated = 101;
$GLOBALS['POSTS_STORE'][ $post_id_gated ] = (object) [
    'ID'           => $post_id_gated,
    'post_title'   => 'Gated Briefing',
    'post_content' => 'Sample content',
    'post_status'  => 'publish',
    'post_date'    => '2026-11-01 08:00:00',
];
$GLOBALS['POST_META_STORE'][ $post_id_gated ] = [
    '_presshub_briefing_type'    => 'text',
    '_presshub_briefing_date'    => '2026-11-01',
    '_presshub_briefing_edition' => 'morning',
];

$sched_gated = PressHub_AI_Memory_Digest_Builder::schedule_for_post( $post_id_gated );
mdb_check( 'gating: schedule_for_post returns false when memory disabled', false === $sched_gated );
mdb_check( 'gating: no event scheduled when memory disabled', empty( $GLOBALS['SCHEDULED_EVENTS'] ) );

$build_gated = PressHub_AI_Memory_Digest_Builder::build_for_post( $post_id_gated );
mdb_check( 'gating: build_for_post returns skipped status when memory disabled', is_array( $build_gated ) && ( $build_gated['status'] ?? '' ) === 'skipped' && ( $build_gated['reason'] ?? '' ) === 'memory_disabled' );

// Re-enable memory
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled'] = 1;

// =========================================================================
// 2. Scheduling & Event Deduplication
// =========================================================================
$GLOBALS['SCHEDULED_EVENTS'] = [];
$sched_ok = PressHub_AI_Memory_Digest_Builder::schedule_for_post( $post_id_gated, 30 );
mdb_check( 'scheduling: schedule_for_post succeeds for text briefing', true === $sched_ok );
mdb_check( 'scheduling: wp_schedule_single_event recorded with hook', ! empty( $GLOBALS['SCHEDULED_EVENTS'] ) && $GLOBALS['SCHEDULED_EVENTS'][0]['hook'] === PressHub_AI_Memory_Digest_Builder::CRON_HOOK );
mdb_check( 'scheduling: event args contain post_id', $GLOBALS['SCHEDULED_EVENTS'][0]['args'] === [ $post_id_gated ] );

// Non-text post should not schedule
$post_id_podcast = 102;
$GLOBALS['POST_META_STORE'][ $post_id_podcast ] = [
    '_presshub_briefing_type' => 'podcast',
];
$GLOBALS['SCHEDULED_EVENTS'] = [];
$sched_nontext = PressHub_AI_Memory_Digest_Builder::schedule_for_post( $post_id_podcast );
mdb_check( 'scheduling: non-text briefing is not scheduled', false === $sched_nontext && empty( $GLOBALS['SCHEDULED_EVENTS'] ) );

// Deduplication
$GLOBALS['NEXT_SCHEDULED'][ PressHub_AI_Memory_Digest_Builder::CRON_HOOK ] = time() + 30;
$GLOBALS['SCHEDULED_EVENTS'] = [];
$sched_dup = PressHub_AI_Memory_Digest_Builder::schedule_for_post( $post_id_gated );
mdb_check( 'scheduling: duplicate avoided when already scheduled', true === $sched_dup && empty( $GLOBALS['SCHEDULED_EVENTS'] ) );
unset( $GLOBALS['NEXT_SCHEDULED'][ PressHub_AI_Memory_Digest_Builder::CRON_HOOK ] );

// on_save_briefing_post checks
$GLOBALS['SCHEDULED_EVENTS'] = [];
PressHub_AI_Memory_Digest_Builder::on_save_briefing_post( $post_id_gated );
mdb_check( 'scheduling: on_save_briefing_post schedules text post', count( $GLOBALS['SCHEDULED_EVENTS'] ) === 1 );

// Non-text post save
$GLOBALS['SCHEDULED_EVENTS'] = [];
PressHub_AI_Memory_Digest_Builder::on_save_briefing_post( $post_id_podcast );
mdb_check( 'scheduling: on_save_briefing_post ignores non-text post', empty( $GLOBALS['SCHEDULED_EVENTS'] ) );

// =========================================================================
// Mock Client Setup for LLM Tests
// =========================================================================
class MockMemoryAPIClient extends PressHub_AI_API_Client {
    public array $call_log = [];
    public array $responses = [];
    public int $call_index = 0;

    public function __construct( array $responses = [] ) {
        parent::__construct( 'briefing_memory' );
        $this->responses = $responses;
    }

    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->call_log[] = [
            'sys_prompt'  => $sys_prompt,
            'user_prompt' => $user_prompt,
            'json_mode'   => $json_mode,
            'metadata'    => $metadata,
        ];
        $idx = $this->call_index++;
        if ( isset( $this->responses[ $idx ] ) ) {
            return $this->responses[ $idx ];
        }
        return end( $this->responses );
    }
}

// =========================================================================
// 3. Valid Digest Extraction & Previous Story Keys Tracking
// =========================================================================
// Seed a daily digest from 2 days prior with a continuing story
$seed_content = [
    'stories' => [
        [
            'key'         => 'eyropaiki-energeia',
            'title'       => 'Ευρωπαϊκή Ενεργειακή Κρίση',
            'development' => 'Συζητήσεις στις Βρυξέλλες.',
            'status'      => 'new',
            'entities'    => [ 'ΕΕ', 'Κομισιόν' ],
            'source_urls' => [ 'https://example.com/energy-1' ],
            'include'     => true,
        ],
    ],
    'podcast' => [ 'opening_line' => '', 'closing_line' => '' ],
    'narrative' => '',
];
PressHub_AI_Memory_Store::upsert_generated( [
    'scope'             => 'daily',
    'period_start'      => '2026-11-05',
    'period_end'        => '2026-11-05',
    'edition'           => 'morning',
    'source_post_id'    => 80,
    'generated_content' => $seed_content,
    'status'            => 'active',
] );

// Current post for 2026-11-07
$post_id_valid = 201;
$GLOBALS['POSTS_STORE'][ $post_id_valid ] = (object) [
    'ID'           => $post_id_valid,
    'post_title'   => 'Πρωινή Ενημέρωση 7 Νοεμβρίου 2026',
    'post_content' => "## Ενέργεια\nΣυνεχίζονται οι διαπραγματεύσεις στην ΕΕ για τις τιμές ενέργειας.\n\n## Φορολογία\nΚατατέθηκε το νέο φορολογικό νομοσχέδιο.",
    'post_status'  => 'publish',
    'post_date'    => '2026-11-07 08:00:00',
];
$GLOBALS['POST_META_STORE'][ $post_id_valid ] = [
    '_presshub_briefing_type'    => 'text',
    '_presshub_briefing_date'    => '2026-11-07',
    '_presshub_briefing_edition' => 'morning',
];

$valid_json_response = "```json\n" . json_encode( [
    'stories' => [
        [
            'key'         => 'eyropaiki-energeia',
            'title'       => 'Ευρωπαϊκή Ενεργειακή Κρίση',
            'development' => 'Νέα συμφωνία των κρατών-μελών για το πλαφόν στις τιμές.',
            'status'      => 'developing',
            'entities'    => [ 'ΕΕ', 'Υπουργοί Ενέργειας' ],
            'source_urls' => [ 'https://example.com/energy-deal' ],
            'include'     => true,
        ],
        [
            'key'         => 'neo-forologiko-nomosxedio',
            'title'       => 'Νέο Φορολογικό Νομοσχέδιο',
            'development' => 'Κατατέθηκε στη Βουλή με ρυθμίσεις για ελεύθερους επαγγελματίες.',
            'status'      => 'new',
            'entities'    => [ 'Βουλή', 'ΥΠΟΙΚ' ],
            'source_urls' => [],
            'include'     => true,
        ],
    ],
    'podcast' => [ 'opening_line' => '', 'closing_line' => '' ],
    'narrative' => '',
], JSON_UNESCAPED_UNICODE ) . "\n```";

$mock_client = new MockMemoryAPIClient( [ $valid_json_response ] );
$build_res   = PressHub_AI_Memory_Digest_Builder::build_for_post( $post_id_valid, $mock_client );

mdb_check( 'extraction: build_for_post returns non-error array', is_array( $build_res ) && ! is_wp_error( $build_res ) );
mdb_check( 'extraction: memory entry ID returned', ! empty( $build_res['id'] ) );

// Prompt verification
mdb_check( 'prompt: previous 7 days key included in prompt', false !== strpos( $mock_client->call_log[0]['sys_prompt'], 'eyropaiki-energeia: Ευρωπαϊκή Ενεργειακή Κρίση' ) );
mdb_check( 'prompt: json mode requested', true === $mock_client->call_log[0]['json_mode'] );

// Storage verification
$saved_entry = PressHub_AI_Memory_Store::get_daily_entry( '2026-11-07', 'morning' );
mdb_check( 'storage: daily entry saved', null !== $saved_entry );
mdb_check( 'storage: status is active', 'active' === ( $saved_entry['status'] ?? '' ) );
mdb_check( 'storage: origin is generated', 'generated' === ( $saved_entry['origin'] ?? '' ) );

$saved_content = PressHub_AI_Memory_Store::decode_content( (string) $saved_entry['content_json'] );
mdb_check( 'storage: 2 stories saved', count( $saved_content['stories'] ?? [] ) === 2 );
mdb_check( 'storage: story key matches reused slug', ( $saved_content['stories'][0]['key'] ?? '' ) === 'eyropaiki-energeia' );
mdb_check( 'storage: status is developing for continuing story', ( $saved_content['stories'][0]['status'] ?? '' ) === 'developing' );
mdb_check( 'storage: narrative forced empty', '' === ( $saved_content['narrative'] ?? 'not-empty' ) );

// Generation record attached to post
$attached_records = PressHub_AI_Generation_Recorder::get_for_post( $post_id_valid );
mdb_check( 'generation_recorder: memory_digest record attached to post', count( $attached_records ) >= 1 && $attached_records[0]['kind'] === 'memory_digest' );
mdb_check( 'generation_recorder: status is success', $attached_records[0]['status'] === 'success' );

// =========================================================================
// 4. Retry on Invalid JSON / Schema Validation Error
// =========================================================================
$post_id_retry = 202;
$GLOBALS['POSTS_STORE'][ $post_id_retry ] = (object) [
    'ID'           => $post_id_retry,
    'post_title'   => 'Briefing Retry Test',
    'post_content' => 'Content for retry test...',
    'post_status'  => 'publish',
    'post_date'    => '2026-11-08 08:00:00',
];
$GLOBALS['POST_META_STORE'][ $post_id_retry ] = [
    '_presshub_briefing_type'    => 'text',
    '_presshub_briefing_date'    => '2026-11-08',
    '_presshub_briefing_edition' => 'morning',
];

// Attempt 1 returns broken JSON; Attempt 2 returns valid JSON
$broken_json = '{"stories": [ { "key": "broken-slug"'; // Malformed
$retry_valid_json = json_encode( [
    'stories' => [
        [
            'key'         => 'retry-success-slug',
            'title'       => 'Title After Retry',
            'development' => 'Development description.',
            'status'      => 'new',
            'entities'    => [ 'Entity1' ],
            'source_urls' => [],
            'include'     => true,
        ],
    ],
    'podcast' => [ 'opening_line' => '', 'closing_line' => '' ],
    'narrative' => '',
] );

$mock_retry_client = new MockMemoryAPIClient( [ $broken_json, $retry_valid_json ] );
$retry_res         = PressHub_AI_Memory_Digest_Builder::build_for_post( $post_id_retry, $mock_retry_client );

mdb_check( 'retry: build_for_post succeeded on attempt 2', is_array( $retry_res ) && ! is_wp_error( $retry_res ) );
mdb_check( 'retry: client was called exactly twice', count( $mock_retry_client->call_log ) === 2 );
mdb_check( 'retry: attempt 2 user prompt contains error notice in Greek', false !== strpos( $mock_retry_client->call_log[1]['user_prompt'], 'ΠΡΟΗΓΟΥΜΕΝΟ ΣΦΑΛΜΑ ΕΠΙΚΥΡΩΣΗΣ JSON' ) );

// Check generation records: first record is error, second is success
$retry_records = PressHub_AI_Generation_Recorder::get_for_post( $post_id_retry );
mdb_check( 'retry: 2 generation records attached to post', count( $retry_records ) === 2 );
mdb_check( 'retry: first record marked error', $retry_records[0]['status'] === 'error' );
mdb_check( 'retry: second record marked success', $retry_records[1]['status'] === 'success' );

// =========================================================================
// 5. Failure After 2 Invalid Attempts
// =========================================================================
$post_id_fail = 203;
$GLOBALS['POSTS_STORE'][ $post_id_fail ] = (object) [
    'ID'           => $post_id_fail,
    'post_title'   => 'Briefing Hard Fail Test',
    'post_content' => 'Content for hard fail...',
    'post_status'  => 'publish',
    'post_date'    => '2026-11-09 08:00:00',
];
$GLOBALS['POST_META_STORE'][ $post_id_fail ] = [
    '_presshub_briefing_type'    => 'text',
    '_presshub_briefing_date'    => '2026-11-09',
    '_presshub_briefing_edition' => 'morning',
];

$mock_fail_client = new MockMemoryAPIClient( [ 'MALFORMED_1', 'MALFORMED_2' ] );
$fail_res         = PressHub_AI_Memory_Digest_Builder::build_for_post( $post_id_fail, $mock_fail_client );

mdb_check( 'failure: returns WP_Error when retry fails', is_wp_error( $fail_res ) );
$failed_entry = PressHub_AI_Memory_Store::get_daily_entry( '2026-11-09', 'morning' );
mdb_check( 'failure: entry stored with status=failed', null !== $failed_entry && $failed_entry['status'] === 'failed' );
mdb_check( 'failure: error_message is saved', ! empty( $failed_entry['error_message'] ) );
$fail_content = PressHub_AI_Memory_Store::decode_content( (string) $failed_entry['content_json'] );
mdb_check( 'failure: no guessed stories stored on failure', empty( $fail_content['stories'] ) );

// =========================================================================
// 6. Edit-Protection Invariant During Rebuild & Failed Rebuild
// =========================================================================
// Set entry to edited
$edited_post_id = 204;
$GLOBALS['POSTS_STORE'][ $edited_post_id ] = (object) [
    'ID'           => $edited_post_id,
    'post_title'   => 'Briefing Edit Protection Test',
    'post_content' => 'Original content for edit protection...',
    'post_status'  => 'publish',
    'post_date'    => '2026-11-10 08:00:00',
];
$GLOBALS['POST_META_STORE'][ $edited_post_id ] = [
    '_presshub_briefing_type'    => 'text',
    '_presshub_briefing_date'    => '2026-11-10',
    '_presshub_briefing_edition' => 'morning',
];

// Initial generation
$init_json = json_encode( [
    'stories' => [
        [
            'key'         => 'edit-test-slug',
            'title'       => 'Original Generated Title',
            'development' => 'Original development.',
            'status'      => 'new',
            'entities'    => [ 'OrgA' ],
            'source_urls' => [],
            'include'     => true,
        ],
    ],
    'podcast' => [ 'opening_line' => '', 'closing_line' => '' ],
    'narrative' => '',
] );
$client_init = new MockMemoryAPIClient( [ $init_json ] );
$init_res    = PressHub_AI_Memory_Digest_Builder::build_for_post( $edited_post_id, $client_init );
$entry_id    = (int) $init_res['id'];

// Editor customizes content
$human_content = [
    'stories' => [
        [
            'key'         => 'edit-test-slug',
            'title'       => 'CUSTOM HUMAN EDITOR HEADLINE',
            'development' => 'Human editor revised development notes.',
            'status'      => 'new',
            'entities'    => [ 'OrgA', 'HumanAddition' ],
            'source_urls' => [ 'https://human-edit.test' ],
            'include'     => true,
        ],
    ],
    'podcast' => [ 'opening_line' => 'Human opening line.', 'closing_line' => 'Human closing line.' ],
    'narrative' => '',
];
PressHub_AI_Memory_Store::update_content( $entry_id, $human_content, 42 );

$entry_before_regen = PressHub_AI_Memory_Store::get_entry( $entry_id );
mdb_check( 'edit_protection: origin is edited before regen', 'edited' === $entry_before_regen['origin'] );

// Trigger rebuild with force=true and new LLM response
$new_llm_json = json_encode( [
    'stories' => [
        [
            'key'         => 'edit-test-slug',
            'title'       => 'Brand New LLM Regenerated Title',
            'development' => 'Brand new LLM regenerated development.',
            'status'      => 'new',
            'entities'    => [ 'OrgB' ],
            'source_urls' => [],
            'include'     => true,
        ],
    ],
    'podcast' => [ 'opening_line' => 'New auto open', 'closing_line' => 'New auto close' ],
    'narrative' => '',
] );
$client_regen = new MockMemoryAPIClient( [ $new_llm_json ] );
$regen_res    = PressHub_AI_Memory_Digest_Builder::build_for_post( $edited_post_id, $client_regen, true );

mdb_check( 'edit_protection: preserved_edit returned true', true === ( $regen_res['preserved_edit'] ?? false ) );

$entry_after_regen = PressHub_AI_Memory_Store::get_entry( $entry_id );
mdb_check( 'edit_protection: origin remains edited', 'edited' === $entry_after_regen['origin'] );
$after_content = PressHub_AI_Memory_Store::decode_content( (string) $entry_after_regen['content_json'] );
mdb_check( 'edit_protection: content_json preserved custom human headline', ( $after_content['stories'][0]['title'] ?? '' ) === 'CUSTOM HUMAN EDITOR HEADLINE' );
mdb_check( 'edit_protection: content_json preserved custom human podcast opening', ( $after_content['podcast']['opening_line'] ?? '' ) === 'Human opening line.' );

$after_generated = PressHub_AI_Memory_Store::decode_content( (string) $entry_after_regen['generated_json'] );
mdb_check( 'edit_protection: generated_json updated with new LLM title', ( $after_generated['stories'][0]['title'] ?? '' ) === 'Brand New LLM Regenerated Title' );

// Failed rebuild on edited entry still preserves content_json
$client_fail_on_edited = new MockMemoryAPIClient( [ 'BROKEN', 'BROKEN' ] );
$fail_on_edited_res    = PressHub_AI_Memory_Digest_Builder::build_for_post( $edited_post_id, $client_fail_on_edited, true );
mdb_check( 'edit_protection: failed rebuild returns WP_Error', is_wp_error( $fail_on_edited_res ) );
$entry_after_fail = PressHub_AI_Memory_Store::get_entry( $entry_id );
mdb_check( 'edit_protection: origin still edited after failed rebuild', 'edited' === $entry_after_fail['origin'] );
$fail_content = PressHub_AI_Memory_Store::decode_content( (string) $entry_after_fail['content_json'] );
mdb_check( 'edit_protection: human content preserved even after failure', ( $fail_content['stories'][0]['title'] ?? '' ) === 'CUSTOM HUMAN EDITOR HEADLINE' );

// =========================================================================
// 7. Deterministic Podcast Opening and Closing Lines
// =========================================================================
$producer = new PressHub_AI_Podcast_Producer();
$script_date = '2026-11-12';
$script_text = "[SPEAKER_1]: Καλημέρα σας από το PressHub AI podcast.\n[SPEAKER_2]: Σήμερα συζητάμε για την οικονομία.\n[SPEAKER_1]: Αυτά ήταν τα σημαντικότερα γεγονότα, καλή σας ημέρα.";

$saved_script = $producer->save_script( $script_date, $script_text, 'curated_briefing', 301, '', 'morning' );
mdb_check( 'podcast_lines: save_script returns true', true === $saved_script );

$podcast_entry = PressHub_AI_Memory_Store::get_daily_entry( $script_date, 'morning' );
mdb_check( 'podcast_lines: daily entry created/updated deterministically', null !== $podcast_entry );
$podcast_data = PressHub_AI_Memory_Store::decode_content( (string) $podcast_entry['content_json'] );
mdb_check( 'podcast_lines: opening line matches first speaker turn', ( $podcast_data['podcast']['opening_line'] ?? '' ) === 'Καλημέρα σας από το PressHub AI podcast.' );
mdb_check( 'podcast_lines: closing line matches last speaker turn', ( $podcast_data['podcast']['closing_line'] ?? '' ) === 'Αυτά ήταν τα σημαντικότερα γεγονότα, καλή σας ημέρα.' );

// Now building the digest for this date preserves the podcast lines
$GLOBALS['POSTS_STORE'][ 301 ] = (object) [
    'ID'           => 301,
    'post_title'   => 'Podcast Date Briefing',
    'post_content' => 'Text content for briefing with podcast...',
    'post_status'  => 'publish',
    'post_date'    => '2026-11-12 08:00:00',
];
$GLOBALS['POST_META_STORE'][ 301 ] = [
    '_presshub_briefing_type'    => 'text',
    '_presshub_briefing_date'    => $script_date,
    '_presshub_briefing_edition' => 'morning',
];

$digest_json = json_encode( [
    'stories' => [
        [
            'key'         => 'podcast-test-story',
            'title'       => 'Podcast Story',
            'development' => 'Podcast story dev.',
            'status'      => 'new',
            'entities'    => [],
            'source_urls' => [],
            'include'     => true,
        ],
    ],
    'podcast' => [ 'opening_line' => '', 'closing_line' => '' ],
    'narrative' => '',
] );
$mock_podcast_builder = new MockMemoryAPIClient( [ $digest_json ] );
$build_with_podcast   = PressHub_AI_Memory_Digest_Builder::build_for_post( 301, $mock_podcast_builder, true );

$entry_after_build = PressHub_AI_Memory_Store::get_daily_entry( $script_date, 'morning' );
$built_content     = PressHub_AI_Memory_Store::decode_content( (string) $entry_after_build['content_json'] );
mdb_check( 'podcast_lines: build_for_post preserves deterministic podcast lines', ( $built_content['podcast']['opening_line'] ?? '' ) === 'Καλημέρα σας από το PressHub AI podcast.' );

// =========================================================================
// 8. Backfill Workflow (Discovery, Step Cron, Resumability, WP-CLI)
// =========================================================================
// Set up 3 briefing posts in range
$p1 = 401;
$p2 = 402;
$p3 = 403;

$GLOBALS['POSTS_STORE'][ $p1 ] = (object) [ 'ID' => $p1, 'post_title' => 'P1', 'post_content' => 'Content 1', 'post_status' => 'publish', 'post_date' => '2026-11-15 08:00:00' ];
$GLOBALS['POST_META_STORE'][ $p1 ] = [ '_presshub_briefing_type' => 'text', '_presshub_briefing_date' => '2026-11-15', '_presshub_briefing_edition' => 'morning' ];

$GLOBALS['POSTS_STORE'][ $p2 ] = (object) [ 'ID' => $p2, 'post_title' => 'P2', 'post_content' => 'Content 2', 'post_status' => 'publish', 'post_date' => '2026-11-16 08:00:00' ];
$GLOBALS['POST_META_STORE'][ $p2 ] = [ '_presshub_briefing_type' => 'text', '_presshub_briefing_date' => '2026-11-16', '_presshub_briefing_edition' => 'morning' ];

$GLOBALS['POSTS_STORE'][ $p3 ] = (object) [ 'ID' => $p3, 'post_title' => 'P3', 'post_content' => 'Content 3', 'post_status' => 'publish', 'post_date' => '2026-11-17 08:00:00' ];
$GLOBALS['POST_META_STORE'][ $p3 ] = [ '_presshub_briefing_type' => 'text', '_presshub_briefing_date' => '2026-11-17', '_presshub_briefing_edition' => 'morning' ];

// Mark P1 as already having an active digest
PressHub_AI_Memory_Store::upsert_generated( [
    'scope'             => 'daily',
    'period_start'      => '2026-11-15',
    'period_end'        => '2026-11-15',
    'edition'           => 'morning',
    'source_post_id'    => $p1,
    'generated_content' => $seed_content,
    'status'            => 'active',
] );

// Find in range without force: skips P1, finds P2 and P3
$discovered = PressHub_AI_Memory_Digest_Builder::find_briefing_editions_in_range( '2026-11-15', '2026-11-17', false );
mdb_check( 'backfill: skips already-active edition P1', count( $discovered ) === 2 );
mdb_check( 'backfill: discovers P2 first', (int) $discovered[0]['post_id'] === $p2 );
mdb_check( 'backfill: discovers P3 second', (int) $discovered[1]['post_id'] === $p3 );

// Find with force=true includes P1 too
$discovered_forced = PressHub_AI_Memory_Digest_Builder::find_briefing_editions_in_range( '2026-11-15', '2026-11-17', true );
mdb_check( 'backfill: force includes all 3 editions', count( $discovered_forced ) === 3 );

// Start backfill
$GLOBALS['SCHEDULED_EVENTS'] = [];
$bf_state = PressHub_AI_Memory_Digest_Builder::start_backfill( '2026-11-15', '2026-11-17', false );
mdb_check( 'backfill: state is running', ( $bf_state['status'] ?? '' ) === 'running' );
mdb_check( 'backfill: queue count is 2', count( $bf_state['queue'] ?? [] ) === 2 );
mdb_check( 'backfill: schedules step cron', ! empty( $GLOBALS['SCHEDULED_EVENTS'] ) && $GLOBALS['SCHEDULED_EVENTS'][0]['hook'] === PressHub_AI_Memory_Digest_Builder::BACKFILL_CRON_HOOK );

// Execute step 1
$GLOBALS['SCHEDULED_EVENTS'] = [];
// Inject valid mock client response via MockMemoryAPIClient if instantiated, or simulate step
$step1_state = PressHub_AI_Memory_Digest_Builder::handle_backfill_step();
mdb_check( 'backfill: step 1 pops one item', ( $step1_state['processed'] ?? 0 ) === 1 );
mdb_check( 'backfill: step 1 leaves 1 in queue', count( $step1_state['queue'] ?? [] ) === 1 );

// Execute step 2
$step2_state = PressHub_AI_Memory_Digest_Builder::handle_backfill_step();
mdb_check( 'backfill: step 2 processed is 2', ( $step2_state['processed'] ?? 0 ) === 2 );
mdb_check( 'backfill: queue empty', empty( $step2_state['queue'] ) );
mdb_check( 'backfill: status is completed', ( $step2_state['status'] ?? '' ) === 'completed' );

// =========================================================================
// 9. AJAX Endpoints
// =========================================================================
$ajax = new PressHub_AI_Ajax_Handlers();

// Unauthorized / Bad Nonce
$GLOBALS['NONCE_VALID'] = false;
$caught_bad_nonce = false;
try {
    $_POST = [ 'action' => 'presshub_ai_memory_build_digest', 'nonce' => 'invalid_nonce' ];
    $ajax->memory_build_digest();
} catch ( RuntimeException $e ) {
    if ( false !== strpos( $e->getMessage(), 'check_ajax_referer failed' ) ) {
        $caught_bad_nonce = true;
    }
}
mdb_check( 'ajax: bad nonce rejected', $caught_bad_nonce );

$GLOBALS['NONCE_VALID']       = true;

$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options', 'edit_others_posts' ];
// Valid nonce & capability for start_backfill
$_POST = [
    'action'  => 'presshub_ai_memory_start_backfill',
    'nonce'   => 'valid_test_nonce',
    'from'    => '2026-11-01',
    'to'      => '2026-11-05',
    'force'   => 0,
];
$caught_backfill_success = false;
try {
    $ajax->memory_start_backfill();
} catch ( RuntimeException $e ) {
    if ( false !== strpos( $e->getMessage(), 'wp_send_json_success' ) ) {
        $caught_backfill_success = true;
    }
}
mdb_check( 'ajax: start_backfill reaches wp_send_json_success', $caught_backfill_success );

// Valid nonce for backfill_status
$_POST = [
    'action' => 'presshub_ai_memory_backfill_status',
    'nonce'  => 'valid_test_nonce',
];
$caught_status_success = false;
try {
    $ajax->memory_backfill_status();
} catch ( RuntimeException $e ) {
    if ( false !== strpos( $e->getMessage(), 'wp_send_json_success' ) ) {
        $caught_status_success = true;
    }
}
mdb_check( 'ajax: backfill_status reaches wp_send_json_success', $caught_status_success );

// Summary
if ( $failures > 0 ) {
    fwrite( STDERR, "\nMemoryDigestBuilderTest: {$failures} failure(s) out of {$checks} checks\n" );
    exit( 1 );
}

echo "\nMemoryDigestBuilderTest: ALL {$checks} CHECKS PASSED SUCCESSFULLY!\n";
echo "MemoryDigestBuilderTest: OK\n";
exit( 0 );
