<?php
/**
 * #309 (E10): the registry's labels, help texts and old names reach
 * translators and are shown in the site's language.
 *
 *  1. includes/settings-registry-strings.php is up to date with the registry
 *     (scripts/settings-registry-strings.php --check) and lists every label
 *     and help text as a __() call in the presshub-ai-editor domain.
 *  2. search_index() returns translated labels, help and old names.
 */

// A fake translation for one label and one help text.
if ( ! function_exists( 'translate' ) ) {
    function translate( $text, $domain = 'default' ) {
        $map = $GLOBALS['FAKE_TRANSLATIONS'] ?? [];
        return ( 'presshub-ai-editor' === $domain && isset( $map[ $text ] ) ) ? $map[ $text ] : $text;
    }
}
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
require_once dirname( __DIR__ ) . '/scripts/settings-registry-strings.php';

$failures = [];

// 1. The strings file is generated from the registry.
$cmd    = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/scripts/settings-registry-strings.php' ) . ' --check 2>&1';
$output = [];
exec( $cmd, $output, $rc );
if ( 0 !== $rc ) {
    $failures[] = 'includes/settings-registry-strings.php is stale: ' . implode( ' ', $output );
}
$strings = (string) file_get_contents( dirname( __DIR__ ) . '/includes/settings-registry-strings.php' );
$checked = 0;
foreach ( PressHub_Settings_Registry::all() as $key => $e ) {
    foreach ( [ 'label', 'help' ] as $field ) {
        $text = trim( (string) ( $e[ $field ] ?? '' ) );
        if ( '' === $text ) {
            continue;
        }
        ++$checked;
        $call = '__( ' . presshub_settings_registry_strings_literal( $text ) . ", 'presshub-ai-editor' );";
        if ( false === strpos( $strings, $call ) ) {
            $failures[] = "The {$field} of {$key} is not listed for translators.";
        }
    }
}
if ( false === strpos( $strings, "\nreturn;\n" ) ) {
    $failures[] = 'settings-registry-strings.php must return before its __() calls (never executed).';
}

// 2. search_index() uses the translations.
$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts', 'manage_options' ];
( new PressHub_AI_Settings() )->register_settings();
$entry = PressHub_Settings_Registry::get( 'presshub_ai_log_level' );
$GLOBALS['FAKE_TRANSLATIONS'] = [
    (string) $entry['label'] => 'Niveau de journal',
    (string) $entry['help']  => 'Aide traduite.',
];
$row = null;
foreach ( PressHub_Settings_Registry::search_index() as $r ) {
    if ( 'presshub_ai_log_level' === $r['key'] ) {
        $row = $r;
    }
}
if ( ! is_array( $row ) || 'Niveau de journal' !== $row['label'] || 'Aide traduite.' !== $row['help'] ) {
    $failures[] = 'search_index() should return the translated label and help; got ' . json_encode( $row );
}

if ( $checked < 300 ) {
    $failures[] = "Expected the registry's labels and help to be checked; only {$checked} were.";
}
if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}
echo "SettingsRegistryStringsTest: {$checked} registry strings listed for translators.\nOK\n";
