<?php
/**
 * MarkdownTest — PressHub_AI_Markdown::to_html() converts model Markdown
 * output into clean HTML for WordPress insertion (headings, bold/italic,
 * lists, links, blockquotes, paragraphs), strips code fences, and leaves
 * already-HTML output untouched. generate_draft() must return converted
 * HTML (integration case).
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
function md_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

// --- 1. headings ----------------------------------------------------------
$h = PressHub_AI_Markdown::to_html( "## Section title" );
md_check( 'h2 heading', false !== strpos( $h, '<h2>Section title</h2>' ) );
$h = PressHub_AI_Markdown::to_html( "### Sub section" );
md_check( 'h3 heading', false !== strpos( $h, '<h3>Sub section</h3>' ) );

// --- 2. inline bold / italic / code ----------------------------------------
$b = PressHub_AI_Markdown::to_html( "This is **very important** text." );
md_check( 'bold', false !== strpos( $b, '<strong>very important</strong>' ) );
$i = PressHub_AI_Markdown::to_html( "An *emphasised* word." );
md_check( 'italic', false !== strpos( $i, '<em>emphasised</em>' ) );
$c = PressHub_AI_Markdown::to_html( "Use `wp_insert_post` here." );
md_check( 'inline code', false !== strpos( $c, '<code>wp_insert_post</code>' ) );

// --- 3. lists ---------------------------------------------------------------
$ul = PressHub_AI_Markdown::to_html( "- first\n- second\n- third" );
md_check( 'ul opens', false !== strpos( $ul, '<ul>' ) );
md_check( 'ul closes', false !== strpos( $ul, '</ul>' ) );
md_check( 'ul items', false !== strpos( $ul, '<li>first</li><li>second</li><li>third</li>' ) );
$ol = PressHub_AI_Markdown::to_html( "1. one\n2. two" );
md_check( 'ol items', false !== strpos( $ol, '<ol>' ) && false !== strpos( $ol, '<li>one</li><li>two</li>' ) );

// --- 4. paragraphs -----------------------------------------------------------
$p = PressHub_AI_Markdown::to_html( "First paragraph.\n\nSecond paragraph." );
md_check( 'paragraphs wrapped', false !== strpos( $p, '<p>First paragraph.</p>' ) && false !== strpos( $p, '<p>Second paragraph.</p>' ) );

// --- 5. links -----------------------------------------------------------------
$l = PressHub_AI_Markdown::to_html( "See [this article](https://example.com/x)." );
md_check( 'link', false !== strpos( $l, '<a href="https://example.com/x">this article</a>' ) );

// --- 6. blockquote ------------------------------------------------------------
$q = PressHub_AI_Markdown::to_html( "> A quoted line" );
md_check( 'blockquote', false !== strpos( $q, '<blockquote>A quoted line</blockquote>' ) );

// --- 7. code fences stripped ---------------------------------------------------
$f = PressHub_AI_Markdown::to_html( "```html\n<p>Already HTML</p>\n```" );
md_check( 'fence removed', false === strpos( $f, '```' ) );
md_check( 'fenced content kept', false !== strpos( $f, '<p>Already HTML</p>' ) );

// --- 8. existing HTML passthrough (no double-wrapping) -------------------------
$raw = PressHub_AI_Markdown::to_html( "<p>Hello <strong>world</strong></p>" );
md_check( 'html passthrough', false !== strpos( $raw, '<p>Hello <strong>world</strong></p>' ) );

// --- 9. mixed document -----------------------------------------------------------
$mixed = PressHub_AI_Markdown::to_html(
    "## Lead\n\nIntro sentence with **bold**.\n\n- point A\n- point B\n\nClosing paragraph."
);
md_check( 'mixed: h2', false !== strpos( $mixed, '<h2>Lead</h2>' ) );
md_check( 'mixed: bold in paragraph', false !== strpos( $mixed, '<strong>bold</strong>' ) );
md_check( 'mixed: list', false !== strpos( $mixed, '<li>point A</li>' ) );
md_check( 'mixed: closing paragraph', false !== strpos( $mixed, '<p>Closing paragraph.</p>' ) );

// --- 10. integration: generate_draft returns converted HTML ----------------------
$GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
$GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
$GLOBALS['CURRENT_USER_ID'] = 5;
$GLOBALS['CAPTURE_FILTER'] = function () {
    return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
        'choices' => [ [ 'message' => [ 'content' => "## Draft title\n\nBody with **emphasis** and more text." ] ] ],
    ] ) ];
};
$api = new PressHub_AI_API_Client();
$draft = $api->generate_draft( 'Source text', 'Write it', [], '' );
unset( $GLOBALS['CAPTURE_FILTER'] );
md_check( 'draft: not a WP_Error', ! is_wp_error( $draft ) );
if ( ! is_wp_error( $draft ) ) {
    md_check( 'draft: markdown heading converted', false !== strpos( $draft, '<h2>Draft title</h2>' ) );
    md_check( 'draft: markdown bold converted', false !== strpos( $draft, '<strong>emphasis</strong>' ) );
    md_check( 'draft: no raw ** left', false === strpos( $draft, '**' ) );
    md_check( 'draft: paragraph wrapped', false !== strpos( $draft, '<p>Body with ' ) );
}

if ( $failures > 0 ) {
    fwrite( STDERR, "MarkdownTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "MarkdownTest: OK (24 checks)\n";
