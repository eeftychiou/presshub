<?php
/**
 * Test suite for PressHub_AI_Memory_Context_Builder and Issue #135 prompt injection.
 *
 * Covers:
 *  1. Gating & byte-identical fallback when disabled or 0-day window
 *  2. Window selection: Tier 2 (recent daily days newest first) and Tier 3 (background rollups + gap dailies with no gap and no overlap)
 *  3. Status & Story Inclusion Filtering: only 'active' and 'stale', flags 'stale', ignores 'excluded'/'failed'/'pending' and include=false stories
 *  4. Noon Edition: Same-day morning digest inclusion, raw dump omission, and fallback when morning digest missing/failed
 *  5. Podcast opening/closing lines: included for target=podcast, omitted for target=briefing
 *  6. Deterministic budget trimming order: (1) oldest background, (2) oldest recent days, (3) resolved stories before developing ones, manifest trimmed recording
 *  7. Context Estimator budget shrinking under tight model context limits
 *  8. Custom prompt fallback (user prompt insertion before articles) vs {memory_context} placeholder hydration
 *  9. Generation record wiring: manifest persisted in memory_json for both briefing_text and podcast_script
 *
 * @package PressHub_AI_Editor
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
require_once dirname( __DIR__ ) . '/includes/class-memory-context-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-generation-recorder.php';

$failures = 0;
$checks   = 0;
function mcb_check( string $desc, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        echo "FAIL: {$desc}\n";
    } else {
        echo "PASS: {$desc}\n";
    }
}

// Reset environment and mock tables
global $wpdb;
$entries_tbl = PressHub_AI_Memory_Store::get_entries_table();
$links_tbl   = PressHub_AI_Memory_Store::get_links_table();
$genrec_tbl  = PressHub_AI_Generation_Recorder::get_table_name();

function reset_test_db(): void {
    global $wpdb, $entries_tbl, $links_tbl, $genrec_tbl;
    $wpdb->tables[ $entries_tbl ] = [];
    $wpdb->tables[ $links_tbl ]   = [];
    $wpdb->tables[ $genrec_tbl ]  = [];
    $wpdb->auto_increments[ $entries_tbl ] = 0;
    $wpdb->auto_increments[ $links_tbl ]   = 0;
    $wpdb->auto_increments[ $genrec_tbl ]  = 0;
    $GLOBALS['OPTIONS_STORE'] = [];
    PressHub_AI_Prompt_Loader::clear_cache();
}

reset_test_db();

// Helper to seed a daily memory entry
function seed_daily_digest( string $date, string $edition = 'morning', string $status = 'active', array $stories = [], array $podcast = [] ): int {
    if ( empty( $stories ) ) {
        $stories = [
            [
                'key'         => "story-{$date}-{$edition}",
                'title'       => "Θέμα {$date} {$edition}",
                'development' => "Εξέλιξη για {$date} {$edition}",
                'status'      => 'developing',
                'entities'    => [ 'Κυβέρνηση' ],
                'source_urls' => [ "https://example.com/{$date}" ],
                'include'     => true,
            ],
        ];
    }
    $content = [
        'stories'   => $stories,
        'podcast'   => ! empty( $podcast ) ? $podcast : [ 'opening_line' => "Καλημέρα {$date}", 'closing_line' => "Αντίο {$date}" ],
        'narrative' => '',
    ];

    $res = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => $date,
        'period_end'        => $date,
        'edition'           => $edition,
        'generated_content' => $content,
        'status'            => $status,
    ] );
    return is_array( $res ) ? (int) $res['id'] : 0;
}

// Helper to seed a weekly rollup entry
function seed_weekly_rollup( string $start, string $end, string $status = 'active', string $narrative = 'Εβδομαδιαία ανασκόπηση.', array $stories = [] ): int {
    if ( empty( $stories ) ) {
        $stories = [
            [
                'key'         => "weekly-story-{$start}",
                'title'       => "Κεντρικό Θέμα Εβδομάδας {$start}",
                'development' => "Σύνοψη εβδομαδιαίας εξέλιξης",
                'status'      => 'developing',
                'entities'    => [ 'ΕΕ' ],
                'source_urls' => [ "https://example.com/weekly-{$start}" ],
                'include'     => true,
            ],
        ];
    }
    $content = [
        'stories'   => $stories,
        'podcast'   => [ 'opening_line' => '', 'closing_line' => '' ],
        'narrative' => $narrative,
    ];

    $res = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'weekly',
        'period_start'      => $start,
        'period_end'        => $end,
        'edition'           => '',
        'generated_content' => $content,
        'status'            => $status,
    ] );
    return is_array( $res ) ? (int) $res['id'] : 0;
}

// =========================================================================
// 1. Gating & Byte-Identical Output
// =========================================================================
reset_test_db();
$curator  = new PressHub_AI_News_Curator();
$producer = new PressHub_AI_Podcast_Producer();
$articles = [
    [ 'id' => 'art-1', 'title' => 'Είδηση 1', 'source' => 'Πηγή 1', 'url' => 'https://example.com/1', 'content' => 'Περιεχόμενο είδησης 1.' ],
];

// When memory is disabled
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled'] = 0;
$built = PressHub_AI_Memory_Context_Builder::build( '2026-09-10', 'morning', 'briefing' );
mcb_check( 'gating: text is empty when disabled', '' === $built['text'] );
mcb_check( 'gating: manifest enabled is false when disabled', false === $built['manifest']['enabled'] );

$prompt_res = $curator->build_prompt( $articles, '', '2026-09-10', [], 'morning' );
mcb_check( 'gating: curation system_prompt does not contain placeholder when disabled', false === strpos( $prompt_res['system_prompt'], '{memory_context}' ) );

// When days are 0
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled']         = 1;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_daily_days']      = 0;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_background_days'] = 0;
$built_0 = PressHub_AI_Memory_Context_Builder::build( '2026-09-10', 'morning', 'briefing' );
mcb_check( 'gating: text empty when 0 days', '' === $built_0['text'] );
mcb_check( 'gating: manifest disabled when 0 days', false === $built_0['manifest']['enabled'] );

// =========================================================================
// 2. Window Selection & Gap Filling (No Gap, No Overlap)
// =========================================================================
reset_test_db();
$today = '2026-09-30';
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled']         = 1;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_daily_days']      = 7;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_background_days'] = 30;

// Seed 10 past daily digests (2026-09-20 to 2026-09-29)
// For daily_days = 7, recent window is 2026-09-23 to 2026-09-29 (7 days).
for ( $d = 20; $d <= 29; $d++ ) {
    seed_daily_digest( "2026-09-{$d}" );
}

// Seed weekly rollups in background window (2026-08-31 to 2026-09-22)
// Rollup 1: 2026-09-01 to 2026-09-07
// Rollup 2: 2026-09-08 to 2026-09-14
seed_weekly_rollup( '2026-09-01', '2026-09-07' );
seed_weekly_rollup( '2026-09-08', '2026-09-14' );
// Rollup 2 ends on 2026-09-14.
// Days 2026-09-15 to 2026-09-22 are in background window but NOT covered by rollups.
// Daily digests for 2026-09-20, 21, 22 were seeded above, so they should fill the gap!

$mem_result = PressHub_AI_Memory_Context_Builder::build( $today, 'morning', 'briefing' );
mcb_check( 'window: memory text is non-empty', '' !== $mem_result['text'] );
$manifest = $mem_result['manifest'];
mcb_check( 'window: manifest enabled is true', true === $manifest['enabled'] );

$recent_entry_dates = [];
$bg_entry_periods   = [];
foreach ( $manifest['entries'] as $ent ) {
    if ( 'recent' === $ent['tier'] ) {
        $recent_entry_dates[] = $ent['period_start'];
    } elseif ( 'background' === $ent['tier'] ) {
        $bg_entry_periods[] = [ 'scope' => $ent['scope'], 'start' => $ent['period_start'], 'end' => $ent['period_end'] ];
    }
}

// Recent window: 7 days from 2026-09-23 to 2026-09-29
mcb_check( 'window: recent count is 7', 7 === count( $recent_entry_dates ) );
mcb_check( 'window: recent contains 2026-09-29', in_array( '2026-09-29', $recent_entry_dates, true ) );
mcb_check( 'window: recent contains 2026-09-23', in_array( '2026-09-23', $recent_entry_dates, true ) );
mcb_check( 'window: recent does NOT contain 2026-09-22 (belongs to bg)', ! in_array( '2026-09-22', $recent_entry_dates, true ) );
mcb_check( 'window: recent ordered newest first', $recent_entry_dates[0] === '2026-09-29' && end( $recent_entry_dates ) === '2026-09-23' );

// Background window: weekly rollups + gap dailies (2026-09-20, 21, 22)
$bg_gap_dailies = array_filter( $bg_entry_periods, fn($p) => 'daily' === $p['scope'] );
$bg_rollups     = array_filter( $bg_entry_periods, fn($p) => 'weekly' === $p['scope'] );
mcb_check( 'window: background has 2 weekly rollups', 2 === count( $bg_rollups ) );
mcb_check( 'window: background has gap-filling dailies', count( $bg_gap_dailies ) >= 3 );

// Verify no date overlap between weekly rollups and gap dailies
$rollup_covered_days = [ '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14' ];
$gap_dates = array_column( $bg_gap_dailies, 'start' );
$overlap = array_intersect( $rollup_covered_days, $gap_dates );
mcb_check( 'window: zero overlap between rollups and gap dailies', empty( $overlap ) );

// =========================================================================
// 3. Status & Story Inclusion Filtering
// =========================================================================
reset_test_db();
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled']    = 1;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_daily_days'] = 5;

// Active entry
$id_act = seed_daily_digest( '2026-09-28', 'morning', 'active' );
// Stale entry (should be included, flagged as stale)
$id_stl = seed_daily_digest( '2026-09-27', 'morning', 'stale' );
// Excluded entry (should NOT be included)
$id_exc = seed_daily_digest( '2026-09-26', 'morning', 'excluded' );
// Failed entry (should NOT be included)
$id_fld = seed_daily_digest( '2026-09-25', 'morning', 'failed' );

// Entry with mixed included/excluded stories
$mixed_stories = [
    [
        'key'         => 'story-inc',
        'title'       => 'Included Story',
        'development' => 'Should be rendered',
        'status'      => 'developing',
        'entities'    => [],
        'source_urls' => [],
        'include'     => true,
    ],
    [
        'key'         => 'story-exc',
        'title'       => 'Excluded Story',
        'development' => 'Should NOT be rendered',
        'status'      => 'developing',
        'entities'    => [],
        'source_urls' => [],
        'include'     => false,
    ],
];
seed_daily_digest( '2026-09-24', 'morning', 'active', $mixed_stories );

$status_test = PressHub_AI_Memory_Context_Builder::build( '2026-09-29', 'morning', 'briefing' );
$st_manifest = $status_test['manifest'];
$included_ids = array_column( $st_manifest['entries'], 'id' );

mcb_check( 'status: active entry included', in_array( $id_act, $included_ids, true ) );
mcb_check( 'status: stale entry included', in_array( $id_stl, $included_ids, true ) );
mcb_check( 'status: manifest has_stale flag is true', true === $st_manifest['has_stale'] );
mcb_check( 'status: excluded entry NOT included', ! in_array( $id_exc, $included_ids, true ) );
mcb_check( 'status: failed entry NOT included', ! in_array( $id_fld, $included_ids, true ) );

// Story inclusion filtering
mcb_check( 'stories: included story rendered', false !== strpos( $status_test['text'], 'Included Story' ) );
mcb_check( 'stories: include=false story NOT rendered', false === strpos( $status_test['text'], 'Excluded Story' ) );

// =========================================================================
// 4. Noon Edition Same-Day Digest & Fallback
// =========================================================================
reset_test_db();
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled']    = 1;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_daily_days'] = 3;
$test_date = '2026-09-30';

// Seed morning digest for today
$morn_stories = [
    [
        'key'         => 'morning-lead-story',
        'title'       => 'Πρωινό Κύριο Θέμα',
        'development' => 'Πρωινή ανακοίνωση υπουργείου',
        'status'      => 'new',
        'entities'    => [ 'Υπουργείο' ],
        'source_urls' => [],
        'include'     => true,
    ],
];
seed_daily_digest( $test_date, 'morning', 'active', $morn_stories );

// Build noon memory
$noon_mem = PressHub_AI_Memory_Context_Builder::build( $test_date, 'noon', 'briefing' );
mcb_check( 'noon: used_morning_digest is true', true === $noon_mem['used_morning_digest'] );
mcb_check( 'noon: text contains morning section header', false !== strpos( $noon_mem['text'], '### Σημερινή Πρωινή Ενημέρωση (2026-09-30)' ) );
mcb_check( 'noon: text contains morning story', false !== strpos( $noon_mem['text'], 'Πρωινό Κύριο Θέμα' ) );

// Verify curator build_prompt in noon edition does NOT inject raw morning briefing dump
$curator = new PressHub_AI_News_Curator();
$cur_articles = [
    [ 'id' => 'art-noon', 'title' => 'Μεσημβρινή Εξέλιξη', 'source' => 'ΑΠΕ', 'url' => 'https://example.com/n', 'content' => 'Νεότερα στοιχεία.' ],
];
$noon_prompts = $curator->build_prompt( $cur_articles, '', $test_date, [], 'noon' );
mcb_check( 'noon: build_prompt does NOT include raw dump delimiter', false === strpos( $noon_prompts['user_prompt'], '--- ΠΡΩΙΝΗ ΕΝΗΜΕΡΩΣΗ' ) );
mcb_check( 'noon: system prompt hydrated with memory', false !== strpos( $noon_prompts['system_prompt'], 'Πρωινό Κύριο Θέμα' ) );

// Test fallback when morning digest is missing or failed
reset_test_db();
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled'] = 1;
// Seed yesterday digest, but NO morning digest for today
seed_daily_digest( '2026-09-29', 'morning', 'active' );

$noon_fallback_mem = PressHub_AI_Memory_Context_Builder::build( $test_date, 'noon', 'briefing' );
mcb_check( 'noon fallback: used_morning_digest is false', false === $noon_fallback_mem['used_morning_digest'] );

// =========================================================================
// 5. Podcast Opening/Closing Lines (Target podcast vs briefing)
// =========================================================================
reset_test_db();
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled']    = 1;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_daily_days'] = 5;

seed_daily_digest( '2026-09-29', 'morning', 'active', [], [ 'opening_line' => 'Ατάκα έναρξης 29', 'closing_line' => 'Ατάκα κλεισίματος 29' ] );
seed_daily_digest( '2026-09-28', 'morning', 'active', [], [ 'opening_line' => 'Ατάκα έναρξης 28', 'closing_line' => 'Ατάκα κλεισίματος 28' ] );
seed_daily_digest( '2026-09-27', 'morning', 'active', [], [ 'opening_line' => 'Ατάκα έναρξης 27', 'closing_line' => 'Ατάκα κλεισίματος 27' ] );

// Target podcast: should include lines section
$pod_mem = PressHub_AI_Memory_Context_Builder::build( '2026-09-30', 'morning', 'podcast' );
mcb_check( 'podcast: contains lines section header', false !== strpos( $pod_mem['text'], '### Προηγούμενες Ατάκες Έναρξης & Κλεισίματος Podcast (ΜΗΝ τις επαναλάβετε)' ) );
mcb_check( 'podcast: contains opening line', false !== strpos( $pod_mem['text'], 'Ατάκα έναρξης 29' ) );
mcb_check( 'podcast: contains closing line', false !== strpos( $pod_mem['text'], 'Ατάκα κλεισίματος 28' ) );
mcb_check( 'podcast: manifest lines count is 3', 3 === ( $pod_mem['manifest']['podcast_lines_count'] ?? 0 ) );

// Target briefing: should NOT include podcast lines section
$brf_mem = PressHub_AI_Memory_Context_Builder::build( '2026-09-30', 'morning', 'briefing' );
mcb_check( 'briefing: does NOT contain podcast lines section', false === strpos( $brf_mem['text'], '### Προηγούμενες Ατάκες Έναρξης' ) );
mcb_check( 'briefing: does NOT contain podcast opening lines', false === strpos( $brf_mem['text'], 'Ατάκα έναρξης 29' ) );

// =========================================================================
// 6. Deterministic Budget Trimming Order
// =========================================================================
reset_test_db();
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled']         = 1;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_daily_days']      = 5;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_background_days'] = 20;

// Oldest background weekly rollup
seed_weekly_rollup( '2026-09-10', '2026-09-16', 'active', 'Παλιό ιστορικό παρασκήνιο.' );

// Older recent day (2026-09-26) with a resolved story and a developing story
seed_daily_digest( '2026-09-26', 'morning', 'active', [
    [
        'key'         => 'resolved-older-day',
        'title'       => 'Ολοκληρωμένη υπόθεση',
        'development' => 'Η υπόθεση έκλεισε οριστικά χθες.',
        'status'      => 'resolved',
        'entities'    => [],
        'source_urls' => [],
        'include'     => true,
    ],
    [
        'key'         => 'developing-older-day',
        'title'       => 'Εξελισσόμενη υπόθεση παλαιάς ημέρας',
        'development' => 'Συνεχίζονται οι διεργασίες.',
        'status'      => 'developing',
        'entities'    => [],
        'source_urls' => [],
        'include'     => true,
    ],
] );

// Newer recent day (2026-09-29)
seed_daily_digest( '2026-09-29', 'morning', 'active', [
    [
        'key'         => 'developing-new-day',
        'title'       => 'Κορυφαίο χθεσινό γεγονός',
        'development' => 'Σημαντικές δηλώσεις.',
        'status'      => 'developing',
        'entities'    => [],
        'source_urls' => [],
        'include'     => true,
    ],
] );

// Full render size
$untrimmed = PressHub_AI_Memory_Context_Builder::build( '2026-09-30', 'morning', 'briefing', 50000 );
$full_len  = mb_strlen( $untrimmed['text'], 'UTF-8' );
mcb_check( 'trimming: untrimmed has 0 trims', empty( $untrimmed['manifest']['trimmed'] ) );

// Set budget slightly smaller than full size to trigger step 1 (drop oldest background)
$bg_trim = PressHub_AI_Memory_Context_Builder::build( '2026-09-30', 'morning', 'briefing', $full_len - 150 );
mcb_check( 'trimming: trimmed count > 0', count( $bg_trim['manifest']['trimmed'] ) > 0 );
mcb_check( 'trimming: first trimmed is oldest background', 'background' === $bg_trim['manifest']['trimmed'][0]['tier'] );
mcb_check( 'trimming: background weekly rollup was trimmed', false === strpos( $bg_trim['text'], 'Παλιό ιστορικό παρασκήνιο' ) );

// Set tight budget so that oldest recent day's resolved story is trimmed before developing story
$tight_budget = 1400;
$tight_trim = PressHub_AI_Memory_Context_Builder::build( '2026-09-30', 'morning', 'briefing', $tight_budget );
$reasons = array_column( $tight_trim['manifest']['trimmed'], 'reason' );
mcb_check( 'trimming: background trimmed before recent', in_array( 'budget_oldest_background', $reasons, true ) );
mcb_check( 'trimming: resolved story trimmed before developing', in_array( 'budget_resolved_story', $reasons, true ) );
mcb_check( 'trimming: resolved story text removed', false === strpos( $tight_trim['text'], 'Ολοκληρωμένη υπόθεση' ) );
mcb_check( 'trimming: developing story on newest day kept', false !== strpos( $tight_trim['text'], 'Κορυφαίο χθεσινό γεγονός' ) );

// =========================================================================
// 7. Context Estimator Budget Shrinking
// =========================================================================
$shrunk_budget = PressHub_AI_Context_Estimator::compute_memory_budget_for_context( 12000, 20000, 8000 );
// Max capacity = 8000 * 3 = 24000 chars. Articles = 20000. Shrunk memory = 4000.
mcb_check( 'estimator: memory budget shrunk to 4000', 4000 === $shrunk_budget );

// =========================================================================
// 8. Custom Prompt Fallback vs Placeholder Hydration
// =========================================================================
reset_test_db();
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled']    = 1;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_daily_days'] = 3;
seed_daily_digest( '2026-09-29', 'morning', 'active', [
    [
        'key'         => 'custom-prompt-story',
        'title'       => 'Θέμα για Custom Prompt',
        'development' => 'Εξέλιξη custom prompt',
        'status'      => 'developing',
        'entities'    => [],
        'source_urls' => [],
        'include'     => true,
    ],
] );

// Custom curation prompt that does NOT include {memory_context}
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_prompt'] = "Custom system prompt without placeholder.\nDate: {date}\nCount: {articles_count}";
$custom_prompts = $curator->build_prompt( $articles, '', '2026-09-30', [], 'morning' );
mcb_check( 'custom prompt: system prompt does NOT have memory text', false === strpos( $custom_prompts['system_prompt'], 'Θέμα για Custom Prompt' ) );
mcb_check( 'custom prompt: user prompt contains memory text before articles', false !== strpos( $custom_prompts['user_prompt'], 'Θέμα για Custom Prompt' ) );
mcb_check( 'custom prompt: user prompt still contains articles', false !== strpos( $custom_prompts['user_prompt'], 'Είδηση 1' ) );

// Default prompt (contains {memory_context})
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_prompt'] );
$default_prompts = $curator->build_prompt( $articles, '', '2026-09-30', [], 'morning' );
mcb_check( 'default prompt: system prompt contains memory text at placeholder', false !== strpos( $default_prompts['system_prompt'], 'Θέμα για Custom Prompt' ) );
mcb_check( 'default prompt: user prompt does NOT duplicate memory text', false === strpos( $default_prompts['user_prompt'], 'Θέμα για Custom Prompt' ) );

// =========================================================================
// 9. Generation Record Wiring & Manifest Persistence
// =========================================================================
reset_test_db();
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled']    = 1;
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_daily_days'] = 3;
$GLOBALS['UPLOAD_DIR'] = sys_get_temp_dir() . '/presshub-mcb-test-' . uniqid();
PressHub_AI_Generation_Recorder::create_table();

seed_daily_digest( '2026-09-29', 'morning', 'active' );

$harvester = new PressHub_AI_News_Harvester();
$harvester->save_snapshot( '2026-09-30', [
    'date'            => '2026-09-30',
    'harvested_at'    => gmdate( 'c' ),
    'sources'         => [],
    'blocked_sources' => [],
    'articles'        => $articles,
] );

class Mock_API_Client_135 extends PressHub_AI_API_Client {
    public $response_text = "# Mock Briefing Output\n\nBriefing content.";
    public function __construct( string $text = '' ) {
        if ( '' !== $text ) {
            $this->response_text = $text;
        }
    }
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        return $this->response_text;
    }
    public function get_provider(): string { return 'gemini'; }
    public function get_provider_id(): string { return 'gemini'; }
    public function get_model(): string { return 'gemini-2.0-flash'; }
}

$stub_client = new Mock_API_Client_135();
$gen_res = $curator->generate_briefing( '2026-09-30', $stub_client, '', [], 'morning' );
mcb_check( 'genrec: generation_record_id returned', is_array( $gen_res ) && ! empty( $gen_res['generation_record_id'] ) );

$record = PressHub_AI_Generation_Recorder::get( (int) ( $gen_res['generation_record_id'] ?? 0 ) );
mcb_check( 'genrec: record exists', is_array( $record ) );
mcb_check( 'genrec: memory manifest is present in record', isset( $record['memory'] ) && is_array( $record['memory'] ) );
mcb_check( 'genrec: memory manifest enabled is true', true === ( $record['memory']['enabled'] ?? false ) );
mcb_check( 'genrec: memory entries array in record', ! empty( $record['memory']['entries'] ) );
mcb_check( 'genrec: memory used_chars recorded', ( $record['memory']['used_chars'] ?? 0 ) > 0 );
mcb_check( 'genrec: entry in record carries version_hash', ! empty( $record['memory']['entries'][0]['version_hash'] ) );

// Podcast script generation record wiring
$pod_client = new Mock_API_Client_135( "[SPEAKER_1]: Καλημέρα σας.\n[SPEAKER_2]: Καλημέρα, ας δούμε τις ειδήσεις." );
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_count'] = 2;
$pod_res = $producer->generate_dialogue_script( '2026-09-30', $pod_client, '', '5_min', 'harvested_articles', [], 'morning' );
mcb_check( 'genrec podcast: generation_record_id returned', is_array( $pod_res ) && ! empty( $pod_res['generation_record_id'] ) );

$pod_record = PressHub_AI_Generation_Recorder::get( (int) ( $pod_res['generation_record_id'] ?? 0 ) );
mcb_check( 'genrec podcast: record exists', is_array( $pod_record ) );
mcb_check( 'genrec podcast: memory manifest in podcast record', isset( $pod_record['memory'] ) && is_array( $pod_record['memory'] ) );
mcb_check( 'genrec podcast: memory manifest enabled is true', true === ( $pod_record['memory']['enabled'] ?? false ) );
mcb_check( 'genrec podcast: target is podcast', 'podcast' === ( $pod_record['memory']['target'] ?? '' ) );

// =========================================================================
// Summary
// =========================================================================
if ( $failures > 0 ) {
    echo "\nMemoryContextBuilderTest: {$failures} failure(s) out of {$checks} checks\n";
    exit( 1 );
}

echo "\nMemoryContextBuilderTest: OK ({$checks} checks passed)\n";
exit( 0 );
