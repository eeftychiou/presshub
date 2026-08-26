<?php
/**
 * NewsCuratorTest — Unit tests for PressHub_AI_News_Curator.
 *
 * Test cases:
 *   1. Preset resolution using PressHub_AI_Preset_Store and PressHub_AI_Preset_Resolver for 'curation' endpoint.
 *   2. Prompt hydration: replaces {date}, {sources_list}, {articles_count}, {articles_context} in base prompt.
 *   3. Graceful handling of empty articles, missing fields, or empty sources.
 *   4. Markdown-to-HTML conversion via PressHub_AI_Markdown::to_html().
 *   5. WordPress post creation with title format, category, status, and meta (_presshub_briefing_date, _presshub_briefing_type).
 *   6. Full end-to-end generate_story() workflow with snapshot integration and error handling.
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
function nc_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$test_upload_dir = sys_get_temp_dir() . '/presshub-curator-test-' . uniqid();
$GLOBALS['UPLOAD_DIR'] = $test_upload_dir;

$curator = new PressHub_AI_News_Curator();

// =========================================================================
// 1. Preset resolution using Store and Resolver for curation agent
// =========================================================================

$GLOBALS['OPTIONS_STORE'] = [];
$GLOBALS['USER_META_STORE'] = [];
$GLOBALS['CURRENT_USER_ID'] = 12;

// Seed plugin presets & author custom presets
$GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
    [
        'slug'             => 'wire-style',
        'name'             => 'Wire style',
        'instruction_text' => 'Δώσε έμφαση στα άμεσα γεγονότα και στις σύντομες προτάσεις.',
        'enabled'          => true,
    ],
    [
        'slug'             => 'analytical-style',
        'name'             => 'Analytical',
        'instruction_text' => 'Κάνε εις βάθος ανάλυση των οικονομικών και πολιτικών επιπτώσεων.',
        'enabled'          => true,
    ],
];

$GLOBALS['USER_META_STORE'][12]['presshub_ai_author_presets'] = [
    [
        'slug'             => 'custom-briefing',
        'name'             => 'Custom Briefing',
        'instruction_text' => 'Χρησιμοποίησε ζωηρό και αφηγηματικό τόνο στην εισαγωγή.',
        'enabled'          => true,
    ],
];

$sample_articles = [
    [
        'title'   => 'Συνάντηση Κορυφής στην Αθήνα',
        'source'  => 'Kathimerini',
        'url'     => 'https://kathimerini.gr/news/1',
        'content' => 'Σημαντικές συνομιλίες για τα ενεργειακά δίκτυα.',
    ],
    [
        'title'   => 'Ανάπτυξη 2.5% για την ελληνική οικονομία',
        'source'  => 'In.gr',
        'url'     => 'https://in.gr/economy/2',
        'content' => 'Θετικά στοιχεία ανακοίνωσε η ΕΛΣΤΑΤ.',
    ],
];

// Test 1a: Explicit preset slug lookup
$prompt_explicit = $curator->build_prompt( $sample_articles, 'custom-briefing', '2026-08-26' );
nc_check( 'preset: build_prompt returns array with system and user prompts', is_array( $prompt_explicit ) && isset( $prompt_explicit['system_prompt'], $prompt_explicit['user_prompt'] ) );
nc_check( 'preset: explicit author preset text appended', false !== strpos( $prompt_explicit['system_prompt'], 'Χρησιμοποίησε ζωηρό και αφηγηματικό τόνο' ) );

// Test 1b: Plugin default preset lookup via slug
$prompt_plugin = $curator->build_prompt( $sample_articles, 'analytical-style', '2026-08-26' );
nc_check( 'preset: plugin default preset text appended', false !== strpos( $prompt_plugin['system_prompt'], 'Κάνε εις βάθος ανάλυση των οικονομικών' ) );

// Test 1c: Author default preset configured in store
$GLOBALS['USER_META_STORE'][12]['presshub_ai_default_preset_id'] = 'custom-briefing';
$prompt_default = $curator->build_prompt( $sample_articles, '', '2026-08-26' );
nc_check( 'preset: author default preset applies when preset_id is empty', false !== strpos( $prompt_default['system_prompt'], 'Χρησιμοποίησε ζωηρό και αφηγηματικό τόνο' ) );

// Test 1d: Sentinel __none__ disables presets
$prompt_none = $curator->build_prompt( $sample_articles, '__none__', '2026-08-26' );
nc_check( 'preset: __none__ sentinel omits preset instructions', false === strpos( $prompt_none['system_prompt'], 'Χρησιμοποίησε ζωηρό' ) && false === strpos( $prompt_none['system_prompt'], 'Κάνε εις βάθος ανάλυση' ) );


// =========================================================================
// 2. Prompt hydration ({date}, {sources_list}, {articles_count}, {articles_context})
// =========================================================================

$sample_articles_3 = [
    [
        'title'   => 'Νέα μέτρα για τη στέγαση',
        'source'  => 'Kathimerini',
        'url'     => 'https://kathimerini.gr/stegasi',
        'content' => 'Επιδότηση ενοικίου για νέους εργαζόμενους.',
    ],
    [
        'title'   => 'Ρεκόρ αφίξεων στον τουρισμό',
        'source'  => 'AMNA',
        'url'     => 'https://amna.gr/tourismos',
        'content' => 'Αύξηση 10% στις τουριστικές αφίξεις.',
    ],
    [
        'title'   => 'Επενδύσεις στην καθαρή ενέργεια',
        'source'  => 'Kathimerini', // Duplicate source to test deduplication
        'url'     => 'https://kathimerini.gr/prasini-energeia',
        'content' => 'Νέα αιολικά πάρκα στη βόρεια Ελλάδα.',
    ],
];

$test_date = '2026-08-26';
$hydrated_prompts = $curator->build_prompt( $sample_articles_3, '__none__', $test_date );
$sys_prompt = $hydrated_prompts['system_prompt'];
$usr_prompt = $hydrated_prompts['user_prompt'];

nc_check( 'hydration: {date} placeholder replaced', false !== strpos( $sys_prompt, '2026-08-26' ) && false === strpos( $sys_prompt, '{date}' ) );
nc_check( 'hydration: {articles_count} replaced with 3', false !== strpos( $sys_prompt, '3 άρθρα' ) && false === strpos( $sys_prompt, '{articles_count}' ) );
nc_check( 'hydration: {sources_list} deduplicated (Kathimerini, AMNA)', false !== strpos( $sys_prompt, 'Kathimerini, AMNA' ) && false === strpos( $sys_prompt, '{sources_list}' ) );
nc_check( 'hydration: {articles_context} replaced with structured articles', false !== strpos( $sys_prompt, 'Νέα μέτρα για τη στέγαση' ) && false === strpos( $sys_prompt, '{articles_context}' ) );
nc_check( 'hydration: user_prompt contains date', false !== strpos( $usr_prompt, '2026-08-26' ) );
nc_check( 'hydration: user_prompt contains article count', false !== strpos( $usr_prompt, '3' ) );
nc_check( 'hydration: user_prompt contains article context', false !== strpos( $usr_prompt, 'Ρεκόρ αφίξεων στον τουρισμό' ) );


// =========================================================================
// 3. Graceful handling of empty articles or empty sources
// =========================================================================

// Test 3a: Empty array of articles
$empty_prompts = $curator->build_prompt( [], '__none__', '2026-08-26' );
nc_check( 'empty_articles: system_prompt does not have raw placeholders', false === strpos( $empty_prompts['system_prompt'], '{date}' ) && false === strpos( $empty_prompts['system_prompt'], '{articles_count}' ) );
nc_check( 'empty_articles: count is 0', false !== strpos( $empty_prompts['system_prompt'], '0 άρθρα' ) );
nc_check( 'empty_articles: context fallback present', false !== strpos( $empty_prompts['system_prompt'], 'Δεν υπάρχουν διαθέσιμα άρθρα.' ) );
nc_check( 'empty_articles: sources fallback present', false !== strpos( $empty_prompts['system_prompt'], 'Καμία πηγή' ) );

// Test 3b: Articles with missing fields
$sparse_articles = [
    [
        'title'   => '',
        'source'  => '',
        'url'     => '',
        'content' => 'Μόνο περιεχόμενο χωρίς τίτλο και πηγή.',
    ],
];
$sparse_prompts = $curator->build_prompt( $sparse_articles, '__none__', '2026-08-26' );
nc_check( 'sparse_articles: title fallback applied', false !== strpos( $sparse_prompts['system_prompt'], 'Χωρίς τίτλο' ) );
nc_check( 'sparse_articles: source fallback applied', false !== strpos( $sparse_prompts['system_prompt'], 'Άγνωστη πηγή' ) );
nc_check( 'sparse_articles: content rendered safely', false !== strpos( $sparse_prompts['system_prompt'], 'Μόνο περιεχόμενο' ) );


// =========================================================================
// 4. Markdown-to-HTML conversion via PressHub_AI_Markdown::to_html()
// =========================================================================

$markdown_sample = "# Πρωινή Ενημέρωση\n\n**Κεντρικός Τίτλος:** Μεγάλες εξελίξεις σήμερα.\n\n## Πολιτική\n\n- Σημαντική ομιλία στη Βουλή\n- Ψηφίστηκε το νομοσχέδιο\n\n> Δήλωση του πρωθυπουργού για την οικονομία";
$converted_html = PressHub_AI_Markdown::to_html( $markdown_sample );

nc_check( 'markdown_to_html: converts h1 heading', false !== strpos( $converted_html, '<h1>Πρωινή Ενημέρωση</h1>' ) );
nc_check( 'markdown_to_html: converts h2 heading', false !== strpos( $converted_html, '<h2>Πολιτική</h2>' ) );
nc_check( 'markdown_to_html: converts bold text', false !== strpos( $converted_html, '<strong>Κεντρικός Τίτλος:</strong>' ) );
nc_check( 'markdown_to_html: converts unordered list', false !== strpos( $converted_html, '<ul><li>Σημαντική ομιλία στη Βουλή</li><li>Ψηφίστηκε το νομοσχέδιο</li></ul>' ) );
nc_check( 'markdown_to_html: converts blockquote', false !== strpos( $converted_html, '<blockquote>' ) && false !== strpos( $converted_html, 'Δήλωση του πρωθυπουργού' ) );


// =========================================================================
// 5. Post creation via wp_insert_post()
// =========================================================================

$GLOBALS['WP_INSERTED_POSTS'] = [];
$GLOBALS['POST_META_STORE'] = [];
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_category'] = 15;
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_status'] = 'draft';

$created_post_id = $curator->create_wordpress_post(
    "<h2>Κύρια Είδηση</h2><p>Αναλυτικό κείμενο της πρωινής ενημέρωσης.</p>",
    '2026-08-26',
    'Ιστορική Συμφωνία στην Αθήνα'
);

nc_check( 'create_post: returns valid post ID', is_int( $created_post_id ) && $created_post_id > 0 );
nc_check( 'create_post: inserted posts recorded', ! empty( $GLOBALS['WP_INSERTED_POSTS'] ) );

$last_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
nc_check( 'create_post: title format matches "Πρωινή Ενημέρωση: [Headline] - [Date]"', false !== strpos( $last_post['post_title'], 'Πρωινή Ενημέρωση: Ιστορική Συμφωνία στην Αθήνα - 26/08/2026' ) );
nc_check( 'create_post: category option applied', isset( $last_post['post_category'] ) && [ 15 ] === $last_post['post_category'] );
nc_check( 'create_post: custom status option applied', ( $last_post['post_status'] ?? '' ) === 'draft' );
nc_check( 'create_post: meta _presshub_briefing_date saved', get_post_meta( $created_post_id, '_presshub_briefing_date', true ) === '2026-08-26' );
nc_check( 'create_post: meta _presshub_briefing_type is text', get_post_meta( $created_post_id, '_presshub_briefing_type', true ) === 'text' );

// Test 5b: Default status fallback ('pending') when option unset
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_status'] );
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_category'] );

$default_status_post_id = $curator->create_wordpress_post(
    "# Αυτόματος Τίτλος\n\nΠεριεχόμενο άρθρου.",
    '2026-08-26'
);
$default_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
nc_check( 'create_post: defaults to pending status when option empty', ( $default_post['post_status'] ?? '' ) === 'pending' );
nc_check( 'create_post: extracts headline automatically from Markdown # heading', false !== strpos( $default_post['post_title'], 'Αυτόματος Τίτλος' ) );


// =========================================================================
// 6. Full end-to-end generate_story() workflow
// =========================================================================

$harvester = new PressHub_AI_News_Harvester();
$test_date_e2e = '2026-08-26';
$harvester->save_snapshot( $test_date_e2e, [
    'date'            => $test_date_e2e,
    'harvested_at'    => gmdate( 'c' ),
    'sources'         => [ 'https://kathimerini.gr' ],
    'blocked_sources' => [],
    'articles'        => [
        [
            'title'   => 'Εγκαίνια του νέου μετρό',
            'source'  => 'Kathimerini',
            'url'     => 'https://kathimerini.gr/metro',
            'content' => 'Ξεκίνησε η λειτουργία της νέας γραμμής μετρό.',
        ],
    ],
] );

// Mock API Client
class Mock_PressHub_AI_API_Client extends PressHub_AI_API_Client {
    public function __construct() {}
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null ) {
        return "# Εγκαίνια Μετρό: Νέα Εποχή για τις Μετακινήσεις\n\nΣε πανηγυρικό κλίμα πραγματοποιήθηκαν τα εγκαίνια.\n\n## Συγκοινωνίες\n\n- 5 νέοι σταθμοί\n- 100.000 επιβάτες ημερησίως";
    }
}

$mock_client = new Mock_PressHub_AI_API_Client();
$result = $curator->generate_story( $test_date_e2e, $mock_client );

nc_check( 'generate_story: returns success payload array', is_array( $result ) );
nc_check( 'generate_story: post_id created', isset( $result['post_id'] ) && $result['post_id'] > 0 );
nc_check( 'generate_story: html_content converted properly', false !== strpos( $result['html_content'], '<h1>Εγκαίνια Μετρό' ) );
nc_check( 'generate_story: headline extracted', false !== strpos( $result['headline'], 'Εγκαίνια Μετρό' ) );
nc_check( 'generate_story: articles_count is 1', ( $result['articles_count'] ?? 0 ) === 1 );

// Test 6b: Missing snapshot returns WP_Error gracefully
$err_result = $curator->generate_story( '1990-01-01', $mock_client );
nc_check( 'generate_story: missing snapshot returns WP_Error', is_wp_error( $err_result ) && 'no_articles' === $err_result->get_error_code() );

// Cleanup test uploads dir
if ( is_dir( $test_upload_dir ) ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $test_upload_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $files as $fileinfo ) {
        $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
        @$todo( $fileinfo->getRealPath() );
    }
    @rmdir( $test_upload_dir );
}

if ( $failures > 0 ) {
    fwrite( STDERR, "NewsCuratorTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "NewsCuratorTest: OK (30 checks)\n";
