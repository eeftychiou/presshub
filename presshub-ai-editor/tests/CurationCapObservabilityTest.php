<?php
/**
 * CurationCapObservabilityTest - Unit tests for Issue #61.
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
function cco_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

// Test 1: cap arithmetic on 100 x 1000-char articles
$curator = new PressHub_AI_News_Curator();

$big_articles = [];
for ( $i = 1; $i <= 100; $i++ ) {
    $big_articles[] = [
        'title'   => "Article {$i}",
        'source'  => 'TestSource',
        'url'     => "https://example.test/{$i}",
        'content' => str_repeat( 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩ ', 50 ),
    ];
}

$rendered = $curator->format_articles_context( $big_articles );

cco_check( 'cap: 100 input articles tracked as last_original_articles_count() = 100', 100 === $curator->last_original_articles_count() );
cco_check( 'cap: was_context_truncated() is true with 100 articles', $curator->was_context_truncated() );
cco_check( 'cap: last_capped_chars() is populated and > 0', $curator->last_capped_chars() > 0 );
cco_check( 'cap: last_capped_tokens_estimate() is populated and > 0', $curator->last_capped_tokens_estimate() > 0 );

// Per-block cost in the rendered prompt: a fully-truncated block is
// ~1,659 bytes (800-char UTF-8 content + ~60-byte block header + 27-byte
// truncation suffix). Separators between blocks are 5 bytes ("\n\n---\n\n").
// 40 blocks + 39 separators.
$per_block_max   = 1700; // safe upper bound per block
$per_sep         = 5;
$upper_bound     = 40 * $per_block_max + 39 * $per_sep;
$actual_len      = function_exists( 'mb_strlen' ) ? mb_strlen( $rendered ) : strlen( $rendered );
cco_check(
    "cap: rendered length ({$actual_len}) fits within cap upper bound ({$upper_bound})",
    $actual_len <= $upper_bound
);
cco_check( 'cap: rendered length is > 40 * 800 chars (cap floor)', $actual_len > 40 * 800 );
$pool_uncapped_len = 100 * ( function_exists( 'mb_strlen' ) ? mb_strlen( str_repeat( 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩ ', 50 ) ) : strlen( str_repeat( 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩ ', 50 ) ) );
cco_check( "cap: rendered length ({$actual_len}) << uncapped pool length ({$pool_uncapped_len})", $actual_len < $pool_uncapped_len / 2 );

cco_check( 'cap: rendered prompt contains 40 "### " block headers', 40 === substr_count( $rendered, '### ' ) );
cco_check( 'cap: rendered prompt contains 39 "---" separators', 39 === substr_count( $rendered, '---' ) );

cco_check( 'cap: article #50 (>= cap_articles) is excluded from rendered prompt', false === strpos( $rendered, 'Article 50' ) );
cco_check( 'cap: article #1 (<= cap_articles) is included in rendered prompt', false !== strpos( $rendered, 'Article 1' ) );


// Test 2: small input set fits under the cap, no truncation
$curator2 = new PressHub_AI_News_Curator();

$small_articles = [
    [
        'title'   => 'Short story',
        'source'  => 'TestSource',
        'url'     => 'https://example.test/1',
        'content' => 'Περιεχόμενο άρθρου.',
    ],
    [
        'title'   => 'Another short story',
        'source'  => 'TestSource',
        'url'     => 'https://example.test/2',
        'content' => 'Λίγο ακόμα περιεχόμενο.',
    ],
];

$rendered_small = $curator2->format_articles_context( $small_articles );

cco_check( 'no-truncation: was_context_truncated() is false with small input', ! $curator2->was_context_truncated() );
cco_check( 'no-truncation: last_original_articles_count() equals input count', 2 === $curator2->last_original_articles_count() );
cco_check( 'no-truncation: last_capped_chars() is > 0', $curator2->last_capped_chars() > 0 );
cco_check( 'no-truncation: last_capped_tokens_estimate() is > 0', $curator2->last_capped_tokens_estimate() > 0 );

$expected_small_chars = function_exists( 'mb_strlen' ) ? mb_strlen( $rendered_small ) : strlen( $rendered_small );
cco_check(
    "no-truncation: last_capped_chars() ({$curator2->last_capped_chars()}) matches rendered length ({$expected_small_chars})",
    $curator2->last_capped_chars() === $expected_small_chars
);


// Test 3: generate_briefing() return payload includes the new pool/cap observability fields
$curator3 = new PressHub_AI_News_Curator();

$harvester = new PressHub_AI_News_Harvester();
$test_date  = '2026-08-26';
$snapshot_articles = [];
for ( $i = 1; $i <= 50; $i++ ) {
    $snapshot_articles[] = [
        'id'      => "art-{$i}",
        'title'   => "Article {$i}",
        'source'  => 'TestSource',
        'url'     => "https://example.test/{$i}",
        'content' => str_repeat( "Greek content {$i} ", 50 ),
    ];
}
$harvester->save_snapshot( $test_date, [
    'date'            => $test_date,
    'harvested_at'    => gmdate( 'c' ),
    'sources'         => [ 'https://example.test' ],
    'blocked_sources' => [],
    'articles'        => $snapshot_articles,
] );

class CCO_Mock_API_Client extends PressHub_AI_API_Client {
    public function __construct() {}
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null ) {
        return "# Test Briefing\n\nGenerated for cap observability test.";
    }
}

$result = $curator3->generate_briefing( $test_date, new CCO_Mock_API_Client() );

cco_check( 'payload: generate_briefing() returns array (not WP_Error)', is_array( $result ) );
cco_check( 'payload: result has pool_chars', isset( $result['pool_chars'] ) );
cco_check( 'payload: result has pool_tokens_estimate', isset( $result['pool_tokens_estimate'] ) );
cco_check( 'payload: result has capped_chars', isset( $result['capped_chars'] ) );
cco_check( 'payload: result has capped_tokens_estimate', isset( $result['capped_tokens_estimate'] ) );
cco_check( 'payload: result has cap_articles', isset( $result['cap_articles'] ) );
cco_check( 'payload: result has cap_chars_per_article', isset( $result['cap_chars_per_article'] ) );

if ( is_array( $result ) ) {
    cco_check( 'payload: cap_articles defaults to 40', ( $result['cap_articles'] ?? 0 ) === 40 );
    cco_check( 'payload: cap_chars_per_article defaults to 800', ( $result['cap_chars_per_article'] ?? 0 ) === 800 );
    cco_check(
        'payload: pool_tokens_estimate > 0 (50 articles x ~280 tokens)',
        ( $result['pool_tokens_estimate'] ?? 0 ) > 0
    );
    cco_check(
        'payload: capped_tokens_estimate > 0',
        ( $result['capped_tokens_estimate'] ?? 0 ) > 0
    );

    cco_check(
        'payload: capped_tokens_estimate < pool_tokens_estimate when truncation occurs (Issue #61 invariant)',
        ( $result['capped_tokens_estimate'] ?? 0 ) < ( $result['pool_tokens_estimate'] ?? 0 )
    );
    cco_check(
        'payload: capped_chars < pool_chars when truncation occurs',
        ( $result['capped_chars'] ?? 0 ) < ( $result['pool_chars'] ?? 0 )
    );
    cco_check(
        'payload: articles_count_original = 50 (cap_articles kicked in)',
        ( $result['articles_count_original'] ?? 0 ) === 50
    );
    cco_check(
        'payload: articles_truncated = true',
        ( $result['articles_truncated'] ?? false ) === true
    );
}


// Test 4: filterable cap (presshub_ai_curation_max_articles)
$curator4 = new PressHub_AI_News_Curator();

$cap_filter = function () { return 5; };
add_filter( 'presshub_ai_curation_max_articles', $cap_filter );

$rendered_filtered = $curator4->format_articles_context( $big_articles );

cco_check( 'filter: cap=5 keeps last_original_articles_count() = 100 (input count, not kept count)', 100 === $curator4->last_original_articles_count() );
cco_check( 'filter: article #1 included in rendered prompt', false !== strpos( $rendered_filtered, 'Article 1' ) );
cco_check( 'filter: article #5 included in rendered prompt', false !== strpos( $rendered_filtered, 'Article 5' ) );
cco_check( 'filter: article #6 excluded from rendered prompt', false === strpos( $rendered_filtered, 'Article 6' ) );
cco_check( 'filter: was_context_truncated() is true with cap=5', $curator4->was_context_truncated() );
// The filter only changes the cap; the rendered string must shrink
// proportionally to reflect the smaller cap.
$expected_max = 5 * $per_block_max + 4 * 5;
$actual_filtered_len = function_exists( 'mb_strlen' ) ? mb_strlen( $rendered_filtered ) : strlen( $rendered_filtered );
cco_check(
    "filter: cap=5 rendered length ({$actual_filtered_len}) << cap=40 length ({$actual_len})",
    $actual_filtered_len < $actual_len / 4
);
cco_check(
    "filter: cap=5 rendered length ({$actual_filtered_len}) <= expected max ({$expected_max})",
    $actual_filtered_len <= $expected_max
);

remove_filter( 'presshub_ai_curation_max_articles', $cap_filter );

// =========================================================================
// Issue #61 - Test 5: render_hub_page() emits the "Of N pool tokens, M were
// sent to the LLM" subtext in the milestone card and the Inspector toolbar
// when the harvested pool exceeds the cap. This is the server-side
// first-paint mirror, matching the #57 defensive fallback pattern.
// =========================================================================

$render_test_date = '2026-08-27';
$render_articles  = [];
for ( $i = 1; $i <= 100; $i++ ) {
    $render_articles[] = [
        'title'   => "Render Article {$i}",
        'source'  => 'RenderSource',
        'url'     => "https://example.test/r/{$i}",
        'content' => str_repeat( 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩ ', 50 ),
    ];
}
( new PressHub_AI_News_Harvester() )->save_snapshot( $render_test_date, [
    'date'            => $render_test_date,
    'harvested_at'    => gmdate( 'c' ),
    'sources'         => [ 'https://example.test' ],
    'blocked_sources' => [],
    'articles'        => $render_articles,
] );

// Need edit_posts capability to render the page (render_hub_page
// wp_die()s without it, matching the real WordPress behaviour).
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
$briefing_admin = new PressHub_AI_Briefing_Admin();
ob_start();
$briefing_admin->render_hub_page( $render_test_date );
$rendered_html = (string) ob_get_clean();

cco_check( 'render: milestone card subtext element is present', false !== strpos( $rendered_html, 'id="presshub-milestone-llm-subtext"' ) );
cco_check( 'render: inspector toolbar subtext element is present', false !== strpos( $rendered_html, 'id="presshub-inspector-llm-subtext"' ) );
cco_check( 'render: subtext contains "pool tokens" wording', false !== strpos( $rendered_html, 'pool tokens' ) );
cco_check( 'render: subtext contains "cap:" wording', false !== strpos( $rendered_html, 'cap:' ) );
cco_check( 'render: subtext contains "40 articles"', false !== strpos( $rendered_html, '40 articles' ) );
cco_check( 'render: subtext contains "800 chars/article"', false !== strpos( $rendered_html, '800 chars/article' ) );
cco_check( 'render: subtext contains the data-cap-articles attribute', false !== strpos( $rendered_html, 'data-cap-articles="40"' ) );
cco_check( 'render: subtext contains the data-cap-chars attribute', false !== strpos( $rendered_html, 'data-cap-chars="800"' ) );
cco_check( 'render: subtext element has the title attribute for the hover tooltip', false !== strpos( $rendered_html, 'title="Pool tokens vs. tokens actually sent to the LLM' ) );


// =========================================================================
// Issue #61 - Test 6: render_hub_page() OMITS the subtext when the pool
// fits under the cap (small input, no truncation).
// =========================================================================

$render_small_date = '2026-08-28';
( new PressHub_AI_News_Harvester() )->save_snapshot( $render_small_date, [
    'date'            => $render_small_date,
    'harvested_at'    => gmdate( 'c' ),
    'sources'         => [ 'https://example.test' ],
    'blocked_sources' => [],
    'articles'        => [
        [ 'title' => 'Small', 'source' => 'X', 'url' => 'https://e/1', 'content' => 'Μικρό κείμενο.' ],
        [ 'title' => 'Small2', 'source' => 'X', 'url' => 'https://e/2', 'content' => 'Άλλο μικρό κείμενο.' ],
    ],
] );

$briefing_admin2 = new PressHub_AI_Briefing_Admin();
ob_start();
$briefing_admin2->render_hub_page( $render_small_date );
$rendered_small_html = (string) ob_get_clean();

cco_check( 'render: small input omits milestone subtext (no truncation)', false === strpos( $rendered_small_html, 'id="presshub-milestone-llm-subtext"' ) );
cco_check( 'render: small input omits inspector subtext (no truncation)', false === strpos( $rendered_small_html, 'id="presshub-inspector-llm-subtext"' ) );


// Summary
if ( $failures > 0 ) {
    fwrite( STDERR, "CurationCapObservabilityTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "CurationCapObservabilityTest: OK\n";
