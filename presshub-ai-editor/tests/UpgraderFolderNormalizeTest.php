<?php
/**
 * UpgraderFolderNormalizeTest — the upgrader_package_options filter must
 * force the canonical 'presshub-ai-editor' destination folder for updates
 * of this plugin, so a renamed/typo'd install directory can never break
 * the auto-update rename step ("Unable to rename the update to match the
 * existing directory").
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

// --- Extra stubs needed to load the main plugin file in the harness ---
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'http://example.test/wp-content/plugins/presshub-ai-editor/';
    }
}

// The embedded PUC library cannot load outside a full WP install.
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) {
        $GLOBALS['DEACTIVATION_HOOKS'][] = $callback;
    }
}

// Boot the plugin so its filters are registered.
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;

function check_upgrader( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

// Case 1: plugin update with a typo'd installed folder (the pressshub case).
$options = [
    'destination_name' => 'pressshub-ai-editor',
    'hook_extra'       => [ 'plugin' => 'pressshub-ai-editor/presshub-ai-editor.php' ],
];
$result  = apply_filters( 'upgrader_package_options', $options );
check_upgrader(
    'typo folder name was not normalized to canonical slug',
    isset( $result['destination_name'] ) && 'presshub-ai-editor' === $result['destination_name']
);

// Case 2: canonical name stays untouched.
$options = [
    'destination_name' => 'presshub-ai-editor',
    'hook_extra'       => [ 'plugin' => 'presshub-ai-editor/presshub-ai-editor.php' ],
];
$result  = apply_filters( 'upgrader_package_options', $options );
check_upgrader(
    'canonical destination name was altered',
    isset( $result['destination_name'] ) && 'presshub-ai-editor' === $result['destination_name']
);

// Case 3: other plugins are never touched.
$options = [
    'destination_name' => 'akismet',
    'hook_extra'       => [ 'plugin' => 'akismet/akismet.php' ],
];
$result  = apply_filters( 'upgrader_package_options', $options );
check_upgrader(
    'unrelated plugin options were modified',
    isset( $result['destination_name'] ) && 'akismet' === $result['destination_name']
);

// Case 4: missing hook_extra (theme/other contexts) is a no-op.
$options = [ 'destination_name' => 'whatever' ];
$result  = apply_filters( 'upgrader_package_options', $options );
check_upgrader(
    'options without hook_extra were modified',
    isset( $result['destination_name'] ) && 'whatever' === $result['destination_name']
);

// Case 5: GitHub auto-zip style folder (presshub-1.2.0) normalizes too.
$options = [
    'destination_name' => 'presshub-1.2.0',
    'hook_extra'       => [ 'plugin' => 'presshub-1.2.0/presshub-ai-editor.php' ],
];
$result  = apply_filters( 'upgrader_package_options', $options );
check_upgrader(
    'GitHub auto-zip folder was not normalized',
    isset( $result['destination_name'] ) && 'presshub-ai-editor' === $result['destination_name']
);

if ( $failures > 0 ) {
    fwrite( STDERR, "UpgraderFolderNormalizeTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "UpgraderFolderNormalizeTest: OK (5 cases)\n";
