<?php
/**
 * UrlFetcherTest — server-side URL fetching + article-text extraction for
 * draft sources (design decision 2026-08-16: models cannot browse URLs, so
 * the plugin fetches and extracts the article text before prompting).
 *
 * Cases:
 *   1. extract_urls finds http(s) URLs and ignores plain notes.
 *   2. fetch_article fetches via wp_remote_get and extracts readable text
 *      (article container preferred, script/nav stripped, truncated).
 *   3. fetch failure (network error / non-200) returns ''.
 *   4. process_sources: notes kept, URL replaced by fetched content.
 *   5. process_sources: unfetchable URL → explanatory note, URL retained.
 *   6. process_sources: disabled (filter/option) → sources pass through.
 *   7. generate_draft integration: user prompt contains fetched content;
 *      CAPTURED_GETS shows the request was made.
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
function uf_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$GLOBALS['OPTIONS_STORE']['presshub_ai_fetch_urls'] = '1';

// --- 1. extract_urls ------------------------------------------------------
$urls = PressHub_AI_URL_Fetcher::extract_urls( 'See https://example.com/a and http://sub.example.org/b?x=1 for context, plus plain notes.' );
uf_check( 'extract_urls: count', count( $urls ) === 2 );
uf_check( 'extract_urls: first', isset( $urls[0] ) && 'https://example.com/a' === $urls[0] );
uf_check( 'extract_urls: second', isset( $urls[1] ) && 'http://sub.example.org/b?x=1' === $urls[1] );
uf_check( 'extract_urls: dedupe', count( PressHub_AI_URL_Fetcher::extract_urls( 'a https://x.io/1 b https://x.io/1 c' ) ) === 1 );

// --- 2. fetch_article: extraction + truncation ------------------------------
$html = '<html><head><title>T</title></head><body>'
    . '<nav><a href="/">Menu</a></nav>'
    . '<article><h1>Swiss neutrality</h1>'
    . '<p>Switzerland survived between Italy, France and Germany.</p>'
    . '<p>It survived two world wars.</p></article>'
    . '<footer>Copyright</footer></body></html>';

$GLOBALS['GET_RESPONSE_FILTER'] = function ( $url, $args ) use ( $html ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => $html ];
};
$GLOBALS['CAPTURED_GETS'] = [];
$text = PressHub_AI_URL_Fetcher::fetch_article( 'https://example.com/article' );
uf_check( 'fetch_article: request recorded', count( $GLOBALS['CAPTURED_GETS'] ) === 1 );
uf_check( 'fetch_article: url recorded', ( $GLOBALS['CAPTURED_GETS'][0][0] ?? '' ) === 'https://example.com/article' );
uf_check( 'fetch_article: contains paragraph text', false !== strpos( $text, 'Switzerland survived between Italy' ) );
uf_check( 'fetch_article: heading kept', false !== strpos( $text, 'Swiss neutrality' ) );
uf_check( 'fetch_article: nav stripped', false === strpos( $text, 'Menu' ) );
uf_check( 'fetch_article: footer stripped', false === strpos( $text, 'Copyright' ) );

// Truncation.
$big = '<article>' . str_repeat( '<p>word</p>', 30000 ) . '</article>';
$GLOBALS['GET_RESPONSE_FILTER'] = function ( $url, $args ) use ( $big ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => $big ];
};
$text = PressHub_AI_URL_Fetcher::fetch_article( 'https://example.com/big' );
uf_check( 'fetch_article: truncated to cap', strlen( $text ) <= PressHub_AI_URL_Fetcher::MAX_CHARS_PER_URL + 100 );

// --- 3. fetch failure paths -------------------------------------------------
$GLOBALS['GET_RESPONSE_FILTER'] = function () { return new WP_Error( 'http_error', 'boom' ); };
uf_check( 'fetch_article: wp_error → empty', PressHub_AI_URL_Fetcher::fetch_article( 'https://example.com/x' ) === '' );

$GLOBALS['GET_RESPONSE_FILTER'] = function () { return [ 'response' => [ 'code' => 403 ], 'body' => 'denied' ]; };
uf_check( 'fetch_article: 403 → empty', PressHub_AI_URL_Fetcher::fetch_article( 'https://example.com/x' ) === '' );

unset( $GLOBALS['GET_RESPONSE_FILTER'] );

// --- 4. process_sources: success path ----------------------------------------
$GLOBALS['GET_RESPONSE_FILTER'] = function ( $url, $args ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => '<article><p>Turkey economic cooperation benefits.</p></article>' ];
};
$out = PressHub_AI_URL_Fetcher::process_sources( 'Focus on regional cooperation. https://example.com/turkey' );
uf_check( 'process_sources: notes kept', false !== strpos( $out, 'Focus on regional cooperation' ) );
uf_check( 'process_sources: fetched content present', false !== strpos( $out, 'Turkey economic cooperation benefits' ) );
uf_check( 'process_sources: source header present', false !== strpos( $out, 'Source article (fetched from' ) );

// --- 5. process_sources: failure path -----------------------------------------
unset( $GLOBALS['GET_RESPONSE_FILTER'] ); // network error by default
$out = PressHub_AI_URL_Fetcher::process_sources( 'Notes here. https://example.com/paywalled' );
uf_check( 'process_sources fail: note kept', false !== strpos( $out, 'Notes here' ) );
uf_check( 'process_sources fail: cannot-fetch note', false !== strpos( $out, 'could not fetch https://example.com/paywalled' ) );
uf_check( 'process_sources fail: url retained', false !== strpos( $out, 'https://example.com/paywalled' ) );

// --- 6. disabled -> passthrough ----------------------------------------------
$GLOBALS['OPTIONS_STORE']['presshub_ai_fetch_urls'] = '0';
$out = PressHub_AI_URL_Fetcher::process_sources( 'Plain https://example.com/x text' );
uf_check( 'process_sources: disabled passthrough', false !== strpos( $out, 'https://example.com/x' ) && false === strpos( $out, 'could not fetch' ) );
$GLOBALS['OPTIONS_STORE']['presshub_ai_fetch_urls'] = '1';

// --- 7. generate_draft integration --------------------------------------------
$GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
$GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
$GLOBALS['CURRENT_USER_ID'] = 5;
$GLOBALS['GET_RESPONSE_FILTER'] = function () {
    return [ 'response' => [ 'code' => 200 ], 'body' => '<article><p>Fetched rebuttal source content.</p></article>' ];
};
$GLOBALS['CAPTURED_REQUESTS'] = [];
$api = new PressHub_AI_API_Client();
$api->generate_draft( "Rebut this article: https://example.com/philenews", 'Write in Greek', [], '' );

$bodies = array_map( function ( $r ) {
    return is_array( $r[1]['body'] ?? null ) ? json_encode( $r[1]['body'] ) : (string) ( $r[1]['body'] ?? '' );
}, $GLOBALS['CAPTURED_REQUESTS'] );
$joined = implode( ' ', $bodies );
uf_check( 'draft: fetched content reached the model', false !== strpos( $joined, 'Fetched rebuttal source content' ) );
uf_check( 'draft: source header in request', false !== strpos( $joined, 'Source article (fetched from' ) );

if ( $failures > 0 ) {
    fwrite( STDERR, "UrlFetcherTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "UrlFetcherTest: OK (22 checks)\n";
