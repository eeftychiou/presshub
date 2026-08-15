<?php
/**
 * TDD test for PressHub_AI_API_Client Google Cloud project ID config.
 *
 * RED phase: this test should fail because generate_image_via_imagen()
 * hardcodes 'projects/presshub-ai/' in the URL with no way to override
 * the project via WordPress options.
 *
 * GREEN phase expectation: once we read get_option(
 * 'presshub_ai_gcloud_project_id', 'presshub-ai' ), the URL reflects the
 * configured value and falls back to 'presshub-ai' when the option is
 * absent so existing behaviour is preserved.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class ImagenProjectIdTest
{
    public static function run(): void {
        $failures = [];

        // Case 1: option unset -> fallback to 'presshub-ai'
        self::reset_world();
        $client = new PressHub_AI_API_Client();
        $url = $client->build_imagen_url();
        if ( ! str_contains( $url, 'projects/presshub-ai/locations/us-central1' ) ) {
            $failures[] = "Default URL should contain 'projects/presshub-ai/...', got: {$url}";
        }
        if ( ! str_contains( $url, '?key=test-key' ) ) {
            $failures[] = "URL should carry the API key, got: {$url}";
        }

        // Case 2: option set to custom project id
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_gcloud_project_id'] = 'my-newsroom-prod';
        $client = new PressHub_AI_API_Client();
        $url = $client->build_imagen_url();
        if ( ! str_contains( $url, 'projects/my-newsroom-prod/locations/us-central1' ) ) {
            $failures[] = "Custom URL should contain 'projects/my-newsroom-prod/...', got: {$url}";
        }
        if ( str_contains( $url, 'projects/presshub-ai/' ) ) {
            $failures[] = "Custom URL must not contain default 'presshub-ai', got: {$url}";
        }

        // Case 3: settings registers the new option.
        $registered = self::collect_registered_settings();
        if ( ! in_array( 'presshub_ai_gcloud_project_id', $registered, true ) ) {
            $failures[] = "Settings class should register presshub_ai_gcloud_project_id; registered: " . implode( ', ', $registered );
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    private static function reset_world(): void {
        $GLOBALS['POST_META_STORE'] = [];
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $GLOBALS['POST_STATUSES'] = [];
        $GLOBALS['HOOK_INVOCATION_COUNT'] = 0;
        $GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
        $GLOBALS['HOOK_INVOCATION_LOG'] = [];
        $GLOBALS['DO_ACTION_LOG'] = [];
        $GLOBALS['TRANSITION_HANDLERS'] = [];
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        // Provide a google cloud api key so URL building has something to embed.
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
    }

    private static function collect_registered_settings(): array {
        $GLOBALS['REGISTERED_SETTINGS'] = [];
        require_once __DIR__ . '/../includes/class-settings.php';
        $settings = new PressHub_AI_Settings();
        $settings->register_settings();
        return $GLOBALS['REGISTERED_SETTINGS'];
    }
}

ImagenProjectIdTest::run();