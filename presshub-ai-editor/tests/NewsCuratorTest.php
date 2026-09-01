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
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
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

// =========================================================================
// 7. Selective article filtering ($selected_article_ids)
// =========================================================================

$multi_articles = [
    [
        'id'      => 'art-1',
        'title'   => 'Πρώτο Άρθρο: Οικονομία',
        'source'  => 'Kathimerini',
        'url'     => 'https://kathimerini.gr/1',
        'content' => 'Περιεχόμενο πρώτου άρθρου.',
    ],
    [
        'id'      => 'art-2',
        'title'   => 'Δεύτερο Άρθρο: Τεχνολογία',
        'source'  => 'In.gr',
        'url'     => 'https://in.gr/2',
        'content' => 'Περιεχόμενο δεύτερου άρθρου.',
    ],
    [
        'id'      => 'art-3',
        'title'   => 'Τρίτο Άρθρο: Αθλητισμός',
        'source'  => 'Sport24',
        'url'     => 'https://sport24.gr/3',
        'content' => 'Περιεχόμενο τρίτου άρθρου.',
    ],
];

// Test 7a: Filtering by explicit ID
$filtered_prompt = $curator->build_prompt( $multi_articles, '__none__', '2026-08-26', [ 'art-2' ] );
nc_check( 'filter_articles: only selected article appears in user prompt', false !== strpos( $filtered_prompt['user_prompt'], 'Δεύτερο Άρθρο' ) && false === strpos( $filtered_prompt['user_prompt'], 'Πρώτο Άρθρο' ) && false === strpos( $filtered_prompt['user_prompt'], 'Τρίτο Άρθρο' ) );
nc_check( 'filter_articles: article count in user prompt is 1', false !== strpos( $filtered_prompt['user_prompt'], 'Αριθμός Άρθρων: 1' ) );

// Test 7b: Filtering by index or URL
$filtered_by_url = $curator->build_prompt( $multi_articles, '__none__', '2026-08-26', [ 'https://sport24.gr/3' ] );
nc_check( 'filter_articles: filtering by URL matches correctly', false !== strpos( $filtered_by_url['user_prompt'], 'Τρίτο Άρθρο' ) && false === strpos( $filtered_by_url['user_prompt'], 'Πρώτο Άρθρο' ) );

// Test 7c: Multiple selected IDs
$filtered_multi = $curator->build_prompt( $multi_articles, '__none__', '2026-08-26', [ 'art-1', 'art-3' ] );
nc_check( 'filter_articles: multi-selection includes both chosen articles', false !== strpos( $filtered_multi['user_prompt'], 'Πρώτο Άρθρο' ) && false !== strpos( $filtered_multi['user_prompt'], 'Τρίτο Άρθρο' ) && false === strpos( $filtered_multi['user_prompt'], 'Δεύτερο Άρθρο' ) );
nc_check( 'filter_articles: multi-selection count is 2', false !== strpos( $filtered_multi['user_prompt'], 'Αριθμός Άρθρων: 2' ) );

// Test 7d: Empty selected_article_ids retains all articles
$unfiltered = $curator->build_prompt( $multi_articles, '__none__', '2026-08-26', [] );
nc_check( 'filter_articles: empty selected list retains all 3 articles', false !== strpos( $unfiltered['user_prompt'], 'Αριθμός Άρθρων: 3' ) );


// =========================================================================
// 8. get_briefing_content() Lookup (Post & Snapshot File)
// =========================================================================

// Test 8a: Lookup from existing post created in step 5
$retrieved_post_content = $curator->get_briefing_content( '2026-08-26' );
nc_check( 'get_briefing_content: retrieves content from existing briefing post', null !== $retrieved_post_content && false !== strpos( $retrieved_post_content, 'Αναλυτικό κείμενο της πρωινής ενημέρωσης' ) );

// Test 8b: Lookup from snapshot storage file
$test_date_file = '2026-08-27';
$file_dir = $harvester->get_snapshot_dir( $test_date_file );
if ( ! is_dir( $file_dir ) ) {
    mkdir( $file_dir, 0777, true );
}
file_put_contents( trailingslashit( $file_dir ) . 'briefing-text.md', "# Δοκιμαστικό Briefing 27ης Αυγούστου\n\nΠεριεχόμενο από snapshot file." );

$retrieved_file_content = $curator->get_briefing_content( $test_date_file );
nc_check( 'get_briefing_content: retrieves content from snapshot markdown file', null !== $retrieved_file_content && false !== strpos( $retrieved_file_content, 'Δοκιμαστικό Briefing 27ης Αυγούστου' ) );

// Test 8c: Non-existent date returns null
$null_content = $curator->get_briefing_content( '1980-01-01' );
nc_check( 'get_briefing_content: non-existent date returns null', null === $null_content );


// =========================================================================
// 9. Issue #65 — duplicate-title (Bug A) & body-h1 strip
// =========================================================================
//
// The curator's `<h1>` block must not be duplicated into the post title
// and must not be left in the post body. The title prefix and date
// format are configured via Settings-First options
// (presshub_ai_briefing_text_title_prefix and
// presshub_ai_briefing_text_title_date_format).

// Reset post store between sub-tests so test posts don't bleed.
$GLOBALS['WP_INSERTED_POSTS'] = [];
$GLOBALS['POST_META_STORE'] = [];
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_prefix'] );
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_date_format'] );

// Test 9a: Default Settings produce a clean title with the prefix +
// headline + date suffix, and the body has the leading <h1> stripped.
$post_id_default = $curator->create_wordpress_post(
    "<h1>Πρωινή Ενημέρωση – 26/08/2026: Ιστορική Συμφωνία στην Αθήνα</h1>\n<h2>Πολιτική</h2>\n<p>Αναλυτική κάλυψη της συμφωνίας.</p>",
    '2026-08-26'
);
$default_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
nc_check(
    'issue65/9a: title contains headline exactly once (no duplicate prefix)',
    false === strpos( $default_post['post_title'], 'Πρωινή Ενημέρωση: Πρωινή Ενημέρωση' )
    && false !== strpos( $default_post['post_title'], 'Πρωινή Ενημέρωση' )
    && false !== strpos( $default_post['post_title'], 'Ιστορική Συμφωνία στην Αθήνα' )
    && false !== strpos( $default_post['post_title'], '26/08/2026' )
);
nc_check(
    'issue65/9a: body does not start with the duplicate <h1> block',
    false === strpos( $default_post['post_content'], '<h1>Πρωινή Ενημέρωση' )
);
nc_check(
    'issue65/9a: body starts with the first <h2> or <p> after the strip',
    ( false !== strpos( $default_post['post_content'], '<h2>Πολιτική</h2>' ) || false !== strpos( $default_post['post_content'], 'Αναλυτική κάλυψη' ) )
);
nc_check(
    'issue65/9a: post meta _presshub_text_title_prefix_applied is recorded',
    get_post_meta( $post_id_default, '_presshub_text_title_prefix_applied', true ) === 'Πρωινή Ενημέρωση:'
);
nc_check(
    'issue65/9a: post meta _presshub_text_title_date_format_applied is recorded',
    get_post_meta( $post_id_default, '_presshub_text_title_date_format_applied', true ) === 'd/m/Y'
);

// Test 9b: Setting an empty prefix disables the prefix in the title.
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_prefix'] = '';
$GLOBALS['WP_INSERTED_POSTS'] = [];
$post_id_empty_prefix = $curator->create_wordpress_post(
    "<h1>Σύντομη Είδηση Χωρίς Πρόθεμα</h1>\n<p>Σώμα άρθρου.</p>",
    '2026-08-26'
);
$empty_prefix_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
nc_check(
    'issue65/9b: empty prefix yields title without "Πρωινή Ενημέρωση"',
    false === strpos( $empty_prefix_post['post_title'], 'Πρωινή Ενημέρωση' )
    && false !== strpos( $empty_prefix_post['post_title'], 'Σύντομη Είδηση' )
    && false !== strpos( $empty_prefix_post['post_title'], '26/08/2026' )
);

// Test 9c: Custom prefix "BREAKING:" produces "BREAKING: <hl> - DD/MM/YYYY".
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_prefix'] = 'BREAKING:';
$GLOBALS['WP_INSERTED_POSTS'] = [];
$post_id_breaking = $curator->create_wordpress_post(
    "<h1>Σεισμός 5.8R στην Κρήτη</h1>\n<p>Σώμα.</p>",
    '2026-08-26'
);
$breaking_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
nc_check(
    'issue65/9c: custom prefix "BREAKING:" yields "BREAKING: <hl> - DD/MM/YYYY"',
    false !== strpos( $breaking_post['post_title'], 'BREAKING: Σεισμός 5.8R στην Κρήτη - 26/08/2026' )
);

// Test 9d: Empty date format omits the date suffix.
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_prefix'] = 'Πρωινή Ενημέρωση:';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_date_format'] = '';
$GLOBALS['WP_INSERTED_POSTS'] = [];
$post_id_no_date = $curator->create_wordpress_post(
    "<h1>Είδηση Χωρίς Ημερομηνία</h1>\n<p>Σώμα.</p>",
    '2026-08-26'
);
$no_date_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
nc_check(
    'issue65/9d: empty date format omits the date suffix',
    false === strpos( $no_date_post['post_title'], '26/08/2026' )
    && false === strpos( $no_date_post['post_title'], '/2026' )
    && false !== strpos( $no_date_post['post_title'], 'Είδηση Χωρίς Ημερομηνία' )
);

// Test 9e: Headline without leading <h1> still strips the first <h2> defensively.
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_prefix'] = 'Πρωινή Ενημέρωση:';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_date_format'] = 'd/m/Y';
$GLOBALS['WP_INSERTED_POSTS'] = [];
$post_id_no_h1 = $curator->create_wordpress_post(
    "<h2>Κύρια Ενότητα</h2>\n<p>Σώμα.</p>",
    '2026-08-26',
    'Κύρια Είδηση Χωρίς H1'
);
$no_h1_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
nc_check(
    'issue65/9e: leading <h2> stripped defensively when no <h1> present',
    false === strpos( $no_h1_post['post_content'], '<h2>Κύρια Ενότητα</h2>' )
    && false !== strpos( $no_h1_post['post_content'], 'Σώμα.' )
);

// Test 9f: get_briefing_status() exposes the new masthead fields
// (Issue #65 acceptance: "get_briefing_status() includes the new
// text_title_prefix_applied, text_title_date_applied flags").
$admin = new PressHub_AI_Briefing_Admin();
$status_payload = $admin->get_briefing_status( '2026-08-26' );
nc_check(
    'issue65/9f: get_briefing_status includes text_title_prefix_applied',
    is_array( $status_payload ) && array_key_exists( 'text_title_prefix_applied', $status_payload )
);
nc_check(
    'issue65/9f: get_briefing_status includes text_title_date_format_applied',
    is_array( $status_payload ) && array_key_exists( 'text_title_date_format_applied', $status_payload )
);
nc_check(
    'issue65/9f: get_briefing_status includes text_title_prefix_current',
    is_array( $status_payload ) && array_key_exists( 'text_title_prefix_current', $status_payload )
);
nc_check(
    'issue65/9f: get_briefing_status includes text_title_date_format_current',
    is_array( $status_payload ) && array_key_exists( 'text_title_date_format_current', $status_payload )
);

// Test 9g: sanitize_briefing_text_title_prefix / sanitize_briefing_text_title_date_format
// are exported and behave per the documented contract.
$prefix_helper = PressHub_AI_Settings_Storage::sanitize_briefing_text_title_prefix( 'BREAKING:' );
nc_check( 'issue65/9g: sanitize_briefing_text_title_prefix echoes valid input', 'BREAKING:' === $prefix_helper );
$prefix_long = str_repeat( 'x', 100 );
$prefix_clamped = PressHub_AI_Settings_Storage::sanitize_briefing_text_title_prefix( $prefix_long );
nc_check( 'issue65/9g: sanitize_briefing_text_title_prefix clamps to 60 chars', strlen( $prefix_clamped ) === 60 );

$df_valid = PressHub_AI_Settings_Storage::sanitize_briefing_text_title_date_format( 'Y-m-d' );
nc_check( 'issue65/9g: sanitize_briefing_text_title_date_format accepts valid token', 'Y-m-d' === $df_valid );
$df_invalid = PressHub_AI_Settings_Storage::sanitize_briefing_text_title_date_format( '<?php exit;' );
nc_check(
    'issue65/9g: sanitize_briefing_text_title_date_format rejects invalid token',
    'd/m/Y' === $df_invalid
);
$df_empty = PressHub_AI_Settings_Storage::sanitize_briefing_text_title_date_format( '' );
nc_check( 'issue65/9g: sanitize_briefing_text_title_date_format accepts empty (disables suffix)', '' === $df_empty );


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
echo "NewsCuratorTest: OK (38 checks)\n";
