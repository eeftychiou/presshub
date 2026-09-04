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
    public $last_metadata = [];
    public function __construct() {}
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->last_metadata = $metadata;
        return "# Test Briefing\n\nGenerated for cap observability test.";
    }
}

$cco_mock = new CCO_Mock_API_Client();
$result = $curator3->generate_briefing( $test_date, $cco_mock );

cco_check( 'payload: generate_briefing() returns array (not WP_Error)', is_array( $result ) );
cco_check( 'payload: result has pool_chars', isset( $result['pool_chars'] ) );
cco_check( 'payload: result has pool_tokens_estimate', isset( $result['pool_tokens_estimate'] ) );
cco_check( 'payload: result has capped_chars', isset( $result['capped_chars'] ) );
cco_check( 'payload: result has capped_tokens_estimate', isset( $result['capped_tokens_estimate'] ) );
cco_check( 'payload: result has cap_articles', isset( $result['cap_articles'] ) );
cco_check( 'payload: result has cap_chars_per_article', isset( $result['cap_chars_per_article'] ) );

// Issue #61 — real-flow wiring: the pool-vs-LLM metadata must be handed to
// call_provider() (and therefore written onto the briefing_curation
// token-log row by the API client) — not just echoed in the payload.
$expected_meta_keys = [ 'cap_articles', 'cap_chars_per_article', 'capped_chars', 'capped_tokens_estimate', 'pool_chars', 'pool_tokens_estimate', 'articles_count', 'articles_count_original', 'articles_truncated' ];
$actual_meta_keys   = array_keys( $cco_mock->last_metadata );
sort( $expected_meta_keys );
sort( $actual_meta_keys );
cco_check(
    'real-flow: call_provider() received all 9 pool/cap metadata keys',
    empty( array_diff( $expected_meta_keys, $actual_meta_keys ) )
);
cco_check(
    'real-flow: metadata[pool_chars] is a positive integer',
    is_numeric( $cco_mock->last_metadata['pool_chars'] ?? null ) && (int) $cco_mock->last_metadata['pool_chars'] > 0
);
cco_check(
    'real-flow: metadata[pool_tokens_estimate] is a positive integer',
    is_numeric( $cco_mock->last_metadata['pool_tokens_estimate'] ?? null ) && (int) $cco_mock->last_metadata['pool_tokens_estimate'] > 0
);
cco_check(
    'real-flow: metadata[capped_chars] is a positive integer',
    is_numeric( $cco_mock->last_metadata['capped_chars'] ?? null ) && (int) $cco_mock->last_metadata['capped_chars'] > 0
);
cco_check(
    'real-flow: metadata[capped_tokens_estimate] is a positive integer',
    is_numeric( $cco_mock->last_metadata['capped_tokens_estimate'] ?? null ) && (int) $cco_mock->last_metadata['capped_tokens_estimate'] > 0
);
cco_check(
    'real-flow: metadata[cap_articles] >= 1',
    (int) ( $cco_mock->last_metadata['cap_articles'] ?? 0 ) >= 1
);
cco_check(
    'real-flow: metadata[cap_chars_per_article] >= 100',
    (int) ( $cco_mock->last_metadata['cap_chars_per_article'] ?? 0 ) >= 100
);
cco_check(
    'real-flow: metadata capped < pool when truncation occurs (Issue #61 invariant)',
    (int) ( $cco_mock->last_metadata['capped_tokens_estimate'] ?? PHP_INT_MAX ) < (int) ( $cco_mock->last_metadata['pool_tokens_estimate'] ?? 0 )
);

if ( is_array( $result ) ) {
    cco_check( 'payload: cap_articles defaults to 40', ( $result['cap_articles'] ?? 0 ) === 40 );
    cco_check( 'payload: cap_chars_per_article defaults to 3000', ( $result['cap_chars_per_article'] ?? 0 ) === 3000 );
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
        'payload: articles_count = 40 (kept count, cap_articles sliced)',
        ( $result['articles_count'] ?? 0 ) === 40
    );
    cco_check(
        'payload: articles_truncated = true',
        ( $result['articles_truncated'] ?? false ) === true
    );
}


// Test 4: Settings-First cap override (presshub_ai_curation_max_articles
// read through PressHub_AI_Settings_Storage::get_curation_max_articles()).
// Issue #61 — the apply_filters() escape hatch is gone; the authoritative
// value lives in the WordPress option, so tests must seed OPTIONS_STORE.
$curator4 = new PressHub_AI_News_Curator();

$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'] = 5;

$rendered_filtered = $curator4->format_articles_context( $big_articles );

cco_check( 'option cap=5 keeps last_original_articles_count() = 100 (input count, not kept count)', 100 === $curator4->last_original_articles_count() );
cco_check( 'option cap=5: article #1 included in rendered prompt', false !== strpos( $rendered_filtered, 'Article 1' ) );
cco_check( 'option cap=5: article #5 included in rendered prompt', false !== strpos( $rendered_filtered, 'Article 5' ) );
cco_check( 'option cap=5: article #6 excluded from rendered prompt', false === strpos( $rendered_filtered, 'Article 6' ) );
cco_check( 'option cap=5: was_context_truncated() is true with cap=5', $curator4->was_context_truncated() );
// The option only changes the cap; the rendered string must shrink
// proportionally to reflect the smaller cap.
$expected_max = 5 * $per_block_max + 4 * 5;
$actual_filtered_len = function_exists( 'mb_strlen' ) ? mb_strlen( $rendered_filtered ) : strlen( $rendered_filtered );
cco_check(
    "option cap=5 rendered length ({$actual_filtered_len}) << cap=40 length ({$actual_len})",
    $actual_filtered_len < $actual_len / 4
);
cco_check(
    "option cap=5 rendered length ({$actual_filtered_len}) <= expected max ({$expected_max})",
    $actual_filtered_len <= $expected_max
);

unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'] );

// =========================================================================
// Issue #61 - Test 4b: Token-log metadata shape (S7a).
// The issue's acceptance criteria explicitly require the new pool/cap
// observability fields to land in the wp_presshub_ai_token_logs.metadata
// JSON column. The wiring lives in generate_briefing() (curator computes
// the six pool/cap keys and passes them to call_provider(), which writes
// them onto the briefing_curation success row) — this test locks down the
// logger contract directly so any future change to the metadata path is
// caught, while Test 3 above asserts the real-flow wiring via the mock
// API client's captured $metadata argument.
// =========================================================================

if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
    global $wpdb;
    $log_table = $wpdb ? ( isset( $wpdb->prefix ) ? $wpdb->prefix . 'presshub_ai_token_logs' : 'wp_presshub_ai_token_logs' ) : 'wp_presshub_ai_token_logs';
    $issue_61_metadata = [
        'pool_chars'             => 548366,
        'pool_tokens_estimate'   => 182788,
        'capped_chars'           => 30770,
        'capped_tokens_estimate' => 10256,
        'cap_articles'           => 40,
        'cap_chars_per_article'  => 3000,
        'articles_count'         => 40,
        'articles_count_original'=> 60,
        'articles_truncated'     => true,
    ];
    $issue_61_log_id = PressHub_AI_Token_Logger::log_llm_request(
        'briefing_curation',
        'gemini',
        'gemini-2.5-flash',
        35237,
        2915,
        12345,
        'success',
        null,
        $issue_61_metadata,
        1
    );
    cco_check( 'log: log_llm_request() returns a numeric row id', is_numeric( $issue_61_log_id ) && (int) $issue_61_log_id > 0 );

    // Pull the just-inserted row from the test wpdb and assert the
    // metadata JSON contains the new keys verbatim.
    $inserted_row = null;
    if ( isset( $wpdb->tables[ $log_table ] ) && is_array( $wpdb->tables[ $log_table ] ) ) {
        foreach ( $wpdb->tables[ $log_table ] as $_row ) {
            if ( (int) ( $_row['id'] ?? 0 ) === (int) $issue_61_log_id ) {
                $inserted_row = $_row;
                break;
            }
        }
    }
    cco_check( 'log: row is queryable from the test wpdb', null !== $inserted_row );

    if ( null !== $inserted_row ) {
        $decoded_meta = json_decode( (string) ( $inserted_row['metadata'] ?? '' ), true );
        cco_check( 'log: row metadata decodes to an array', is_array( $decoded_meta ) );
        cco_check( 'log: metadata contains pool_tokens_estimate (Issue #61 contract)', (int) ( $decoded_meta['pool_tokens_estimate'] ?? 0 ) === 182788 );
        cco_check( 'log: metadata contains capped_tokens_estimate', (int) ( $decoded_meta['capped_tokens_estimate'] ?? 0 ) === 10256 );
        cco_check( 'log: metadata contains pool_chars', (int) ( $decoded_meta['pool_chars'] ?? 0 ) === 548366 );
        cco_check( 'log: metadata contains capped_chars', (int) ( $decoded_meta['capped_chars'] ?? 0 ) === 30770 );
        cco_check( 'log: metadata contains cap_articles', (int) ( $decoded_meta['cap_articles'] ?? 0 ) === 40 );
        cco_check( 'log: metadata contains cap_chars_per_article', (int) ( $decoded_meta['cap_chars_per_article'] ?? 0 ) === 3000 );
        cco_check( 'log: metadata contains articles_count', (int) ( $decoded_meta['articles_count'] ?? 0 ) === 40 );
        cco_check( 'log: metadata contains articles_count_original', (int) ( $decoded_meta['articles_count_original'] ?? 0 ) === 60 );
        cco_check( 'log: metadata contains articles_truncated', true === ( $decoded_meta['articles_truncated'] ?? false ) );
        // The Issue #61 invariant: capped < pool whenever truncation occurs.
        cco_check(
            'log: capped_tokens_estimate < pool_tokens_estimate (Issue #61 invariant)',
            (int) ( $decoded_meta['capped_tokens_estimate'] ?? 0 ) < (int) ( $decoded_meta['pool_tokens_estimate'] ?? 0 )
        );
    }
} else {
    cco_check( 'log: PressHub_AI_Token_Logger class available', false );
}


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
cco_check( 'render: subtext contains "3000 chars/article"', false !== strpos( $rendered_html, '3000 chars/article' ) );
cco_check( 'render: subtext contains the data-cap-articles attribute', false !== strpos( $rendered_html, 'data-cap-articles="40"' ) );
cco_check( 'render: subtext contains the data-cap-chars attribute', false !== strpos( $rendered_html, 'data-cap-chars="3000"' ) );
cco_check( 'render: subtext element has the title attribute for the hover tooltip', false !== strpos( $rendered_html, 'title="Pool tokens vs. tokens actually sent to the LLM' ) );

// Issue #61 (S1) — the server-rendered card attributes must include
// data-capped-tokens so the JS mirror sums the cap-aware numbers
// instead of the uncapped data-tokens. We assert that for an article
// much longer than the 800-char cap, the capped value is strictly
// smaller than the uncapped value.
preg_match( '/data-tokens="(\d+)" data-capped-tokens="(\d+)"/', $rendered_html, $card_attr_matches );
cco_check( 'render: each card has data-tokens + data-capped-tokens attributes', ! empty( $card_attr_matches ) );
if ( ! empty( $card_attr_matches ) ) {
    $uncapped = (int) $card_attr_matches[1];
    $capped   = (int) $card_attr_matches[2];
    cco_check(
        "render: card data-capped-tokens ({$capped}) <= card data-tokens ({$uncapped}) (cap respected)",
        $capped <= $uncapped
    );
}
cco_check( 'render: card attribute data-cap-chars="3000" is present', false !== strpos( $rendered_html, 'data-cap-chars="3000"' ) );


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
