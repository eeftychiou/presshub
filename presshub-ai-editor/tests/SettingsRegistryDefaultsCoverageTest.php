<?php
/**
 * #309 (E9): every active, rendered, scalar setting declares its default in
 * the registry (rule 2.5), so the field can show "Default: …" and Reset.
 *
 * Not covered on purpose:
 *  - secrets and remove flags (no default to show);
 *  - prompts and templates (their default is an asset file, rule 7.3);
 *  - list (array) settings;
 *  - the legacy options of exception E1, which are being removed.
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

// Exception E1 (docs/settings-design-principles.md): hidden legacy options on their way out.
$legacy = [
    'presshub_ai_provider', 'presshub_ai_openai_org', 'presshub_ai_anthropic_version',
    'presshub_ai_briefing_morning_podcast_title_prefix', 'presshub_ai_briefing_featured_image_ids',
];
foreach ( [ 'openai', 'anthropic', 'gemini' ] as $p ) {
    foreach ( [ 'model', 'temperature', 'max_tokens', 'timeout' ] as $knob ) {
        $legacy[] = "presshub_ai_{$knob}_{$p}";
    }
}
foreach ( [ 'twitter', 'facebook', 'instagram', 'tiktok' ] as $p ) {
    $legacy[] = "presshub_social_enabled_{$p}";
}

$failures = [];
$checked  = 0;
foreach ( PressHub_Settings_Registry::all() as $key => $e ) {
    if ( 'active' !== ( $e['status'] ?? '' ) || empty( $e['rendered'] ) || ! empty( $e['secret'] ) || ! empty( $e['removes'] ) ) {
        continue;
    }
    if ( 'array' === ( $e['type'] ?? '' ) || false !== strpos( $key, 'prompt' ) || false !== strpos( $key, 'template' ) || in_array( $key, $legacy, true ) ) {
        continue;
    }
    ++$checked;
    if ( ! array_key_exists( 'default', $e ) && empty( $e['default_callback'] ) ) {
        $failures[] = "{$key} has no registry default (add 'default' or 'default_callback' in settings-registry-data.php).";
        continue;
    }
    $default = PressHub_Settings_Registry::default_value( (string) $key );
    if ( ! is_scalar( $default ) ) {
        $failures[] = "{$key} has a non-scalar default.";
    }
}

if ( $checked < 150 ) {
    $failures[] = "Expected the scalar settings to be checked; only {$checked} were.";
}
if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}
echo "SettingsRegistryDefaultsCoverageTest: {$checked} scalar settings declare their default.\nOK\n";
