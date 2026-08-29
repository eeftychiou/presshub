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

// --- 8. extraction noise: php dump + boilerplate (1.2.7) ---------------------
$dirty = "array(1) {\n[0]=>\nint(453)\n}\nMain article body text.\nΕγγραφή στο Newsletter\nΣΧΟΛΙΑ\nRelated junk";
$clean = PressHub_AI_URL_Fetcher::strip_php_dump_noise( $dirty );
uf_check( 'noise: var_dump header removed', false === strpos( $clean, 'array(1)' ) );
uf_check( 'noise: var_dump value removed', false === strpos( $clean, 'int(453)' ) );
uf_check( 'noise: body kept', false !== strpos( $clean, 'Main article body text.' ) );
$cut = PressHub_AI_URL_Fetcher::cut_boilerplate( $clean );
uf_check( 'boilerplate: cut before newsletter', false === strpos( $cut, 'Εγγραφή στο Newsletter' ) );
uf_check( 'boilerplate: cut before comments', false === strpos( $cut, 'ΣΧΟΛΙΑ' ) );
uf_check( 'boilerplate: body kept', false !== strpos( $cut, 'Main article body text.' ) );
uf_check( 'boilerplate: english marker works', PressHub_AI_URL_Fetcher::cut_boilerplate( "Body text.\nComments\nmore" ) === 'Body text.' );
uf_check( 'boilerplate: no marker → unchanged', PressHub_AI_URL_Fetcher::cut_boilerplate( 'Just body text.' ) === 'Just body text.' );


// --- 9. Tier 1: JSON-LD Structured Data Extraction --------------------------
$json_ld_html = '<!DOCTYPE html>
<html>
<head>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "NewsArticle",
        "headline": "Κυβερνητικές ανακοινώσεις για τη φορολογία",
        "datePublished": "2026-08-26T09:00:00+03:00",
        "articleBody": "Αναλυτικά μέτρα στήριξης για τις επιχειρήσεις και τους επαγγελματίες ανακοίνωσε το οικονομικό επιτελείο. Το σχέδιο περιλαμβάνει μειώσεις συντελεστών και φοροελαφρύνσεις για το νέο έτος."
    }
    </script>
</head>
<body>
    <nav>Menu</nav>
    <div class="sidebar">Ads</div>
</body>
</html>';

$res_json_ld = PressHub_AI_URL_Fetcher::extract_article_semantic( $json_ld_html );
uf_check( 'json_ld tier: tier name is json_ld', ( $res_json_ld['tier'] ?? '' ) === 'json_ld' );
uf_check( 'json_ld tier: headline matches', ( $res_json_ld['title'] ?? '' ) === 'Κυβερνητικές ανακοινώσεις για τη φορολογία' );
uf_check( 'json_ld tier: content matches articleBody', false !== strpos( $res_json_ld['content'] ?? '', 'Αναλυτικά μέτρα στήριξης' ) );
uf_check( 'json_ld tier: datePublished extracted', ( $res_json_ld['published_at'] ?? '' ) === '2026-08-26T09:00:00+03:00' );


// --- 10. Tier 2: Semantic DOM News Container Extraction ----------------------
$dom_container_html = '<!DOCTYPE html>
<html>
<head><title>Οικονομικές Ειδήσεις</title></head>
<body>
    <header><nav><a href="/">Home</a></nav></header>
    <div class="entry-content">
        <h1>Νέα επένδυση στην πράσινη ενέργεια</h1>
        <div class="social-share">Share on Twitter</div>
        <p>Μεγάλο επενδυτικό σχέδιο ύψους 500 εκατομμυρίων ευρώ ανακοινώθηκε σήμερα.</p>
        <p>Το έργο θα δημιουργήσει πάνω από 1.000 νέες θέσεις εργασίας.</p>
        <div class="related-posts">Διαβάστε επίσης</div>
    </div>
    <footer>Footer notes</footer>
</body>
</html>';

$res_dom = PressHub_AI_URL_Fetcher::extract_article_semantic( $dom_container_html );
uf_check( 'dom tier: tier name is dom', ( $res_dom['tier'] ?? '' ) === 'dom' );
uf_check( 'dom tier: contains investment text', false !== strpos( $res_dom['content'] ?? '', 'Μεγάλο επενδυτικό σχέδιο' ) );
uf_check( 'dom tier: stripped social share', false === strpos( $res_dom['content'] ?? '', 'Share on Twitter' ) );
uf_check( 'dom tier: stripped related posts', false === strpos( $res_dom['content'] ?? '', 'Διαβάστε επίσης' ) );


// --- 11. Tier 3: OpenGraph & Meta Tags Fallback ------------------------------
$og_html = '<!DOCTYPE html>
<html>
<head>
    <meta property="og:title" content="Σημαντική διεθνής συνάντηση στην Αθήνα" />
    <meta property="og:description" content="Συζητήθηκαν κρίσιμα θέματα περιφερειακής ασφάλειας και συνεργασίας μεταξύ των δύο χωρών." />
    <meta property="article:published_time" content="2026-08-26T12:00:00Z" />
</head>
<body>
    <div>Paywall: Subscribe to read full article</div>
</body>
</html>';

$res_og = PressHub_AI_URL_Fetcher::extract_article_semantic( $og_html );
uf_check( 'opengraph tier: tier name is opengraph', ( $res_og['tier'] ?? '' ) === 'opengraph' );
uf_check( 'opengraph tier: title extracted', ( $res_og['title'] ?? '' ) === 'Σημαντική διεθνής συνάντηση στην Αθήνα' );
uf_check( 'opengraph tier: description extracted', false !== strpos( $res_og['content'] ?? '', 'Συζητήθηκαν κρίσιμα θέματα' ) );
uf_check( 'opengraph tier: published time extracted', ( $res_og['published_at'] ?? '' ) === '2026-08-26T12:00:00Z' );


// --- 12. fetch_article_data Structured Result --------------------------------
$GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $json_ld_html ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => $json_ld_html ];
};
$art_data = PressHub_AI_URL_Fetcher::fetch_article_data( 'https://example.com/tax-news' );
uf_check( 'fetch_article_data: success is true', true === $art_data['success'] );
uf_check( 'fetch_article_data: status_code is 200', 200 === $art_data['status_code'] );
uf_check( 'fetch_article_data: tier is json_ld', ( $art_data['tier'] ?? '' ) === 'json_ld' );
uf_check( 'fetch_article_data: char_count > 0', ( $art_data['char_count'] ?? 0 ) > 50 );

if ( $failures > 0 ) {
    fwrite( STDERR, "UrlFetcherTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "UrlFetcherTest: OK (30 checks)\n";
