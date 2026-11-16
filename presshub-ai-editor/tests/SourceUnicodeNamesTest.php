<?php
/**
 * SourceUnicodeNamesTest — #263: Greek briefing source names survive a save
 * and damaged names are repaired once.
 *
 *  1. A posted JSON value with \uXXXX escapes (what wp_json_encode wrote into
 *     the hidden field before) decodes to Greek, not "u0397...".
 *  2. A still-slashed JSON value (AJAX path) decodes too.
 *  3. The hidden field is now rendered with JSON_UNESCAPED_UNICODE.
 *  4. repair_unslashed_unicode() fixes "u0397u03bb" and leaves ASCII alone.
 *  5. The one-time migration repairs stored names and sets its flag.
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
$checks   = 0;
function su_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$greek  = 'Ελληνικό Κανάλι';
$source = [
    'id'       => 'src_test0001',
    'name'     => $greek,
    'url'      => 'https://example.com/news',
    'type'     => 'text_news',
    'enabled'  => true,
    'category' => 'Πολιτική',
    'notes'    => '',
];

// 1. Escaped JSON, already unslashed by options.php.
$escaped = json_encode( [ $source ] );
su_check( 'fixture uses \\u escapes', false !== strpos( $escaped, '\\u0395' ) );
$out = PressHub_AI_Settings_Storage::normalize_sources( $escaped );
su_check( 'escaped JSON keeps Greek name', isset( $out[0]['name'] ) && $greek === $out[0]['name'] );
su_check( 'escaped JSON keeps Greek category', isset( $out[0]['category'] ) && 'Πολιτική' === $out[0]['category'] );

// 2. Still-slashed JSON (magic-quoted request data).
$out = PressHub_AI_Settings_Storage::normalize_sources( addslashes( $escaped ) );
su_check( 'slashed JSON keeps Greek name', isset( $out[0]['name'] ) && $greek === $out[0]['name'] );
$out = PressHub_AI_Settings_Storage::normalize_sources( addslashes( json_encode( [ $source ], JSON_UNESCAPED_UNICODE ) ) );
su_check( 'slashed unescaped JSON keeps Greek name', isset( $out[0]['name'] ) && $greek === $out[0]['name'] );

// 3. Render uses unescaped unicode.
$render = file_get_contents( dirname( __DIR__ ) . '/includes/class-settings-render.php' );
su_check( 'hidden sources field encodes with JSON_UNESCAPED_UNICODE', false !== strpos( $render, 'wp_json_encode( $sources, JSON_UNESCAPED_UNICODE' ) );

// 4. Repair helper.
su_check( 'repair restores Greek', 'Ελ' === PressHub_AI_Settings_Storage::repair_unslashed_unicode( 'u0395u03bb' ) );
su_check( 'repair leaves ASCII escapes-looking text', 'menu0041 ok' === PressHub_AI_Settings_Storage::repair_unslashed_unicode( 'menu0041 ok' ) );
su_check( 'repair leaves plain text', 'Cyprus Mail' === PressHub_AI_Settings_Storage::repair_unslashed_unicode( 'Cyprus Mail' ) );

// 5. One-time migration.
$damaged         = $source;
$damaged['name'] = 'u0395u03bbu03bbu03b7u03bdu03b9u03bau03cc';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_sources'] = [ $damaged ];
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_migrated_source_unicode'] );
presshub_ai_migrate_repair_source_unicode();
$stored = get_option( 'presshub_ai_briefing_sources' );
su_check( 'migration repairs stored name', is_array( $stored ) && 'Ελληνικό' === ( $stored[0]['name'] ?? '' ) );
su_check( 'migration sets its flag', '1' === get_option( 'presshub_ai_migrated_source_unicode' ) );

$stored[0]['name'] = 'u0395';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_sources'] = $stored;
presshub_ai_migrate_repair_source_unicode();
su_check( 'migration runs only once', 'u0395' === ( get_option( 'presshub_ai_briefing_sources' )[0]['name'] ?? '' ) );

if ( $failures ) {
    fwrite( STDERR, "SourceUnicodeNamesTest: {$failures} failure(s) of {$checks} checks\n" );
    exit( 1 );
}
echo "OK ({$checks} checks)\n";
