<?php
/**
 * #309 (E3): Settings search finds keys, tokens and passwords by their label
 * and help, and never carries a value or a default for them.
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

$failures = [];
$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts', 'manage_options' ];
( new PressHub_AI_Settings() )->register_settings();
PressHub_Social_Settings::get_instance()->register_settings();

// Stored secrets that must never reach the index.
$GLOBALS['OPTIONS_STORE']['presshub_social_worker_secret'] = 'SuperSecretWorkerValue123';
$GLOBALS['OPTIONS_STORE']['presshub_ai_github_token']      = 'ghp_SuperSecretTokenValue';

$index   = PressHub_Settings_Registry::search_index();
$by_key  = [];
foreach ( $index as $row ) {
    $by_key[ $row['key'] ] = $row;
}
$secrets = [];
foreach ( PressHub_Settings_Registry::all() as $key => $e ) {
    if ( ! empty( $e['secret'] ) && ! empty( $e['rendered'] ) && empty( $e['removes'] ) && ! PressHub_Settings_Registry::is_unused( $key ) ) {
        $secrets[] = $key;
    }
}
if ( count( $secrets ) < 5 ) {
    $failures[] = 'Expected the rendered secrets in the registry; found ' . count( $secrets ) . '.';
}
foreach ( $secrets as $key ) {
    if ( ! isset( $by_key[ $key ] ) ) {
        $failures[] = "Secret {$key} is not in the Settings search index.";
        continue;
    }
    $row = $by_key[ $key ];
    if ( empty( $row['secret'] ) ) {
        $failures[] = "Secret {$key} is not marked as a secret in the index.";
    }
    if ( array_key_exists( 'default', $row ) ) {
        $failures[] = "Secret {$key} carries a default in the index.";
    }
    if ( '' === trim( (string) $row['label'] ) ) {
        $failures[] = "Secret {$key} has no label in the index.";
    }
}
$json = (string) json_encode( $index );
foreach ( [ 'SuperSecretWorkerValue123', 'ghp_SuperSecretTokenValue' ] as $value ) {
    if ( false !== strpos( $json, $value ) ) {
        $failures[] = 'A stored secret value appears in the search index.';
    }
}
$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/settings-search.js' );
if ( false === strpos( $js, '.presshub-secret[data-secret-for="' ) || false === strpos( $js, '.presshub-secret-replace' ) ) {
    $failures[] = 'settings-search.js should jump to a secret\'s Replace key button.';
}

if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}
echo 'SettingsSearchSecretsTest: ' . count( $secrets ) . " secrets searchable by label only.\nOK\n";
