<?php
/**
 * HarvestUrlAndTitleTest — two harvest losses seen in the live logs (Nov 2026).
 *
 *   1. tothemaonline.com feed links carry raw "–", "€", "ϊ" in the path, which
 *      FILTER_VALIDATE_URL rejects ("Invalid URL"). encode_iri() percent-encodes
 *      them so the article is fetched.
 *   2. socialistikiekfrasi.com pages have no <h1>/og:title and the same
 *      <title> on every page, so every article was dropped as shallow. The
 *      headline set in large type is used instead.
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
require_once dirname( __DIR__ ) . '/includes/class-news-harvester.php';

$failures = 0;
$checks   = 0;
function hu_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

// --- 1. encode_iri ---------------------------------------------------------
$raw = 'https://www.tothemaonline.com/Article/479105/symbolaio-thanatoy-€800000-sth-lemeso-–-ta-ekatommyria-ϊ';
hu_check( 'raw tothemaonline link fails FILTER_VALIDATE_URL', false === filter_var( $raw, FILTER_VALIDATE_URL ) );
$enc = PressHub_AI_URL_Fetcher::encode_iri( $raw );
hu_check( 'encoded link validates', false !== filter_var( $enc, FILTER_VALIDATE_URL ) );
hu_check( 'euro sign encoded', false !== strpos( $enc, '%E2%82%AC800000' ) );
hu_check( 'en dash encoded', false !== strpos( $enc, '-%E2%80%93-' ) );
hu_check( 'decodes back to the original', rawurldecode( $enc ) === $raw );
$ascii = 'https://example.com/a%20b?x=1&y=%E2%82%AC';
hu_check( 'ASCII URL untouched (no double encoding)', PressHub_AI_URL_Fetcher::encode_iri( $ascii ) === $ascii );
hu_check( 'empty stays empty', '' === PressHub_AI_URL_Fetcher::encode_iri( '' ) );

// fetch_article_data now fetches the encoded URL instead of failing.
$GLOBALS['CAPTURED_GETS'] = [];
$GLOBALS['GET_RESPONSE_FILTER'] = function ( $url ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => '<html><head><title>T</title></head><body><article><h1>Ένας τίτλος άρθρου εδώ</h1><p>' . str_repeat( 'Κείμενο άρθρου με αρκετές λέξεις. ', 20 ) . '</p></article></body></html>' ];
};
$data = PressHub_AI_URL_Fetcher::fetch_article_data( $raw );
hu_check( 'fetch_article_data succeeds for non-ASCII link', true === $data['success'] );
hu_check( 'request went to the encoded URL', isset( $GLOBALS['CAPTURED_GETS'][0][0] ) && $GLOBALS['CAPTURED_GETS'][0][0] === $enc );
unset( $GLOBALS['GET_RESPONSE_FILTER'] );

$harvester = new PressHub_AI_News_Harvester();
hu_check( 'is_article_url accepts non-ASCII article link', $harvester->is_article_url( $raw ) );

// --- 2. styled headline fallback ---------------------------------------------
$se_html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>Sosialistiki Ekfrasi</title></head><body>'
    . '<header><p><span style="font-size:30pt">Σοσιαλιστική Έκφραση μηνιαία έκδοση</span></p></header>'
    . '<article class="content">'
    . '<p class="MsoNormal"><em><span style="font-family:\'Candara\',sans-serif; font-size:16.0pt; ">Το Καφενείο της Πέμπτης στον Πολίτη &nbsp; 12 Νοεμβρίου 2026</span></em></p>'
    . '<p class="MsoNormal" style="line-height:28.0pt;"><span style="font-family:\'Candara\',sans-serif; font-size:28.0pt; ">Το όνειρο που κακοφόρμισε</span></p>'
    . '<p><span style="font-size:12pt">' . str_repeat( 'Σαν σανίδα από θλιβερό ναυάγιο ταξιδεύει η γηραιά μας ήπειρος. ', 15 ) . '</span></p>'
    . '</article></body></html>';
hu_check( 'styled headline found', 'Το όνειρο που κακοφόρμισε' === PressHub_AI_URL_Fetcher::extract_styled_headline( $se_html ) );
$ex = PressHub_AI_URL_Fetcher::extract_article_semantic( $se_html, 'https://www.socialistikiekfrasi.com/XGr/Articles/International/261114_01.php' );
hu_check( 'semantic title uses styled headline', 'Το όνειρο που κακοφόρμισε' === ( $ex['title'] ?? '' ) );
hu_check( 'article now passes the shallow-page check', $harvester->is_valid_harvested_article( [ 'title' => $ex['title'], 'content' => $ex['content'], 'tier' => $ex['tier'] ] ) );

// A good <title> is kept even when large type exists.
$good = '<html><head><title>Κυβέρνηση ανακοινώνει νέα μέτρα στήριξης</title></head><body><article><p><span style="font-size:30pt">Μεγάλο διαφημιστικό κείμενο εδώ</span></p><p>' . str_repeat( 'λέξη ', 60 ) . '</p></article></body></html>';
$ex2 = PressHub_AI_URL_Fetcher::extract_article_semantic( $good );
hu_check( 'three-word-plus <title> is kept', 'Κυβέρνηση ανακοινώνει νέα μέτρα στήριξης' === ( $ex2['title'] ?? '' ) );

// An <h1> always wins.
$h1 = '<html><head><title>Site</title></head><body><article><h1>Ο κανονικός τίτλος του άρθρου</h1><p><span style="font-size:30pt">Άλλο κείμενο με μεγάλα γράμματα</span></p><p>' . str_repeat( 'λέξη ', 60 ) . '</p></article></body></html>';
$ex3 = PressHub_AI_URL_Fetcher::extract_article_semantic( $h1 );
hu_check( '<h1> still wins', 'Ο κανονικός τίτλος του άρθρου' === ( $ex3['title'] ?? '' ) );

// Small or one-word text does not qualify; px sizes are converted.
hu_check( 'no headline below 18pt', '' === PressHub_AI_URL_Fetcher::extract_styled_headline( '<body><p><span style="font-size:14pt">Τρεις λέξεις εδώ τώρα</span></p></body>' ) );
hu_check( 'one-word large text ignored', '' === PressHub_AI_URL_Fetcher::extract_styled_headline( '<body><p><span style="font-size:40pt">Ανακοίνωση</span></p></body>' ) );
hu_check( '32px headline accepted', 'Νέος τίτλος σε pixels εδώ' === PressHub_AI_URL_Fetcher::extract_styled_headline( '<body><div style="font-size: 32px">Νέος τίτλος σε pixels εδώ</div></body>' ) );

// --- 3. Review round 1 follow-ups --------------------------------------------
hu_check( 'encode_iri is idempotent', PressHub_AI_URL_Fetcher::encode_iri( $enc ) === $enc );
$mixed = 'https://example.com/a%20b/€?q=ϊ#frag–x';
hu_check( 'mixed escapes + raw: only raw encoded', 'https://example.com/a%20b/%E2%82%AC?q=%CF%8A#frag%E2%80%93x' === PressHub_AI_URL_Fetcher::encode_iri( $mixed ) );
hu_check( 'IDN host left alone, path encoded', 'https://παράδειγμα.gr/%CE%AC' === PressHub_AI_URL_Fetcher::encode_iri( 'https://παράδειγμα.gr/ά' ) );

$nested = '<body><article><p><span style="font-size:28pt"><span style="color:#c00">Έκτακτο:</span> Το όνειρο που κακοφόρμισε</span></p></article></body>';
hu_check( 'nested same-name tag keeps the whole headline', 'Έκτακτο: Το όνειρο που κακοφόρμισε' === PressHub_AI_URL_Fetcher::extract_styled_headline( $nested ) );
hu_check( 'data-style is not a style attribute', '' === PressHub_AI_URL_Fetcher::extract_styled_headline( '<body><p data-style="font-size:30pt">Τρεις λέξεις εδώ τώρα</p></body>' ) );
hu_check( 'aside and comments are not headline candidates', 'Ο πραγματικός τίτλος εδώ' === PressHub_AI_URL_Fetcher::extract_styled_headline( '<body><aside><p style="font-size:40pt">Διαφήμιση μεγάλη στο πλάι</p></aside><!-- <p style="font-size:50pt">Σχόλιο του Word εδώ</p> --><p style="font-size:24pt">Ο πραγματικός τίτλος εδώ</p></body>' ) );
hu_check( 'more than 30 words is not a headline', '' === PressHub_AI_URL_Fetcher::extract_styled_headline( '<body><p style="font-size:30pt">' . str_repeat( 'λέξη ', 31 ) . '</p></body>' ) );

$hyph = '<html><head><title>COVID-19: Νέα μέτρα από σήμερα</title></head><body><article><p style="font-size:30pt">Προσφορά συνδρομής τώρα μόνο</p><p>' . str_repeat( 'λέξη ', 60 ) . '</p></article></body></html>';
$ex4 = PressHub_AI_URL_Fetcher::extract_article_semantic( $hyph );
hu_check( 'long <title> cut short by a hyphen does not take promo text', 'Προσφορά συνδρομής τώρα μόνο' !== ( $ex4['title'] ?? '' ) );
$og = '<html><head><meta property="og:title" content="Ο τίτλος από το og"><title>Site</title></head><body><article><p style="font-size:30pt">Άλλο κείμενο με μεγάλα γράμματα</p><p>' . str_repeat( 'λέξη ', 60 ) . '</p></article></body></html>';
hu_check( 'og:title still wins', 'Ο τίτλος από το og' === ( PressHub_AI_URL_Fetcher::extract_article_semantic( $og )['title'] ?? '' ) );

if ( $failures > 0 ) {
    fwrite( STDERR, "HarvestUrlAndTitleTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "HarvestUrlAndTitleTest: OK ({$checks} checks)\n";
