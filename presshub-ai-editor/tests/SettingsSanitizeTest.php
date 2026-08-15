<?php
/**
 * TDD tests for the P5 sanitize callbacks registered by
 * PressHub_AI_Settings::register_settings().
 *
 * The callbacks are pulled from $GLOBALS['SANITIZE_CALLBACKS'] (recorded by
 * the register_setting stub) so the tests exercise the actual registration
 * wiring, not just the static methods.
 *
 * Covers: provider whitelist, key stripping (+ masked/empty preserve),
 * temperature clamp to [0,2] with fallback to 0.7, integer limits for
 * max_tokens/timeout/rate limits, boolean coercion, slug sanitization for
 * project id + region, and per-provider model fallbacks.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-settings.php';

class SettingsSanitizeTest
{
    public static function run(): void {
        $failures = [];

        $cbs = self::callbacks();

        // --- Case 1: provider whitelist ---
        if ( self::sanitize( $cbs, 'presshub_ai_provider', 'foo' ) !== 'openai' ) {
            $failures[] = "provider 'foo' should sanitize to 'openai'.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_provider', 'anthropic' ) !== 'anthropic' ) {
            $failures[] = "provider 'anthropic' should pass through.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_provider', 'GEMINI' ) !== 'openai' ) {
            $failures[] = "provider 'GEMINI' (case-sensitive) should sanitize to 'openai'.";
        }

        // --- Case 2: API key HTML is stripped ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_api_key', '<script>alert(1)</script>' ) !== 'alert(1)' ) {
            $failures[] = "api key '<script>alert(1)</script>' should have HTML stripped.";
        }

        // --- Case 3: empty key post preserves the saved key ---
        self::reset_options();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'sk-existing';
        if ( self::sanitize( $cbs, 'presshub_ai_api_key', '' ) !== 'sk-existing' ) {
            $failures[] = "empty api key post should preserve the saved key.";
        }

        // --- Case 4: empty key post with no saved value stays empty ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_api_key', '' ) !== '' ) {
            $failures[] = "empty api key post with no saved value should stay empty.";
        }

        // --- Case 5: masked value is never stored as a real key ---
        self::reset_options();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'sk-1234567890';
        if ( self::sanitize( $cbs, 'presshub_ai_api_key', '••••7890' ) !== 'sk-1234567890' ) {
            $failures[] = "a masked key post should preserve the saved key, not store the mask.";
        }

        // --- Case 6: temperature clamp [0,2], out-of-range falls back to 0.7 ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_temperature_openai', '5.0' ) !== 0.7 ) {
            $failures[] = "temperature 5.0 should fall back to 0.7.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_temperature_openai', '0.5' ) !== 0.5 ) {
            $failures[] = "temperature 0.5 should pass through.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_temperature_openai', '-1' ) !== 0.7 ) {
            $failures[] = "temperature -1 should fall back to 0.7.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_temperature_openai', 'abc' ) !== 0.7 ) {
            $failures[] = "non-numeric temperature should fall back to 0.7.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_temperature_openai', '2' ) !== 2.0 ) {
            $failures[] = "temperature 2 (upper bound) should pass through.";
        }

        // --- Case 7: max_tokens int clamp [1, 8192] ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_max_tokens_anthropic', '500' ) !== 500 ) {
            $failures[] = 'max_tokens 500 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_max_tokens_anthropic', '0' ) !== 1 ) {
            $failures[] = 'max_tokens 0 should clamp to 1.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_max_tokens_anthropic', '99999' ) !== 8192 ) {
            $failures[] = 'max_tokens 99999 should clamp to 8192.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_max_tokens_anthropic', 'abc' ) !== 1 ) {
            $failures[] = 'non-numeric max_tokens should clamp to 1.';
        }

        // --- Case 8: timeout int clamp [5, 300] ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_timeout_gemini', '45' ) !== 45 ) {
            $failures[] = 'timeout 45 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_timeout_gemini', '1' ) !== 5 ) {
            $failures[] = 'timeout 1 should clamp to 5.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_timeout_gemini', '1000' ) !== 300 ) {
            $failures[] = 'timeout 1000 should clamp to 300.';
        }

        // --- Case 9: rate limit per-hour clamp [1, 10000] ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_per_hour', '50' ) !== 50 ) {
            $failures[] = 'per_hour 50 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_per_hour', '0' ) !== 1 ) {
            $failures[] = 'per_hour 0 should clamp to 1.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_per_hour', '50000' ) !== 10000 ) {
            $failures[] = 'per_hour 50000 should clamp to 10000.';
        }

        // --- Case 10: rate limit window clamp [1, 86400] ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_window_seconds', '3600' ) !== 3600 ) {
            $failures[] = 'window 3600 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_window_seconds', '0' ) !== 1 ) {
            $failures[] = 'window 0 should clamp to 1.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_window_seconds', '9999999' ) !== 86400 ) {
            $failures[] = 'window 9999999 should clamp to 86400.';
        }

        // --- Case 11: boolean coercion for the enable checkbox ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_enabled', '1' ) !== 1 ) {
            $failures[] = "enabled '1' should sanitize to 1.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_enabled', '0' ) !== 0 ) {
            $failures[] = "enabled '0' should sanitize to 0.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_enabled', '' ) !== 0 ) {
            $failures[] = "enabled '' should sanitize to 0.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_rate_limit_enabled', 'on' ) !== 1 ) {
            $failures[] = "enabled 'on' should sanitize to 1.";
        }

        // --- Case 12: gcloud project id slug sanitization ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_gcloud_project_id', 'My_Project!' ) !== 'my_project' ) {
            $failures[] = "project id 'My_Project!' should become 'my_project'.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_gcloud_project_id', 'foo bar' ) !== 'foobar' ) {
            $failures[] = "project id 'foo bar' should become 'foobar'.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_gcloud_project_id', '' ) !== 'presshub-ai' ) {
            $failures[] = "empty project id should fall back to 'presshub-ai'.";
        }

        // --- Case 13: imagen region slug sanitization ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_imagen_region', 'EUROPE-WEST4' ) !== 'europe-west4' ) {
            $failures[] = "region 'EUROPE-WEST4' should become 'europe-west4'.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_imagen_region', '' ) !== 'us-central1' ) {
            $failures[] = "empty region should fall back to 'us-central1'.";
        }

        // --- Case 14: per-provider model sanitization + defaults ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_model_openai', '<b>gpt-4o</b>' ) !== 'gpt-4o' ) {
            $failures[] = "model '<b>gpt-4o</b>' should have HTML stripped.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_model_openai', '' ) !== 'gpt-4o' ) {
            $failures[] = "empty openai model should fall back to 'gpt-4o'.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_model_anthropic', '' ) !== 'claude-3-5-sonnet-20240620' ) {
            $failures[] = "empty anthropic model should fall back to 'claude-3-5-sonnet-20240620'.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_model_gemini', '' ) !== 'gemini-1.5-pro-latest' ) {
            $failures[] = "empty gemini model should fall back to 'gemini-1.5-pro-latest'.";
        }

        // --- Case 15: google cloud key + github token sanitization ---
        self::reset_options();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'gc-key';
        if ( self::sanitize( $cbs, 'presshub_ai_google_cloud_api_key', '' ) !== 'gc-key' ) {
            $failures[] = 'empty google cloud key post should preserve the saved key.';
        }
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_github_token', '<script>x</script>' ) !== 'x' ) {
            $failures[] = 'github token should have HTML stripped.';
        }

        // --- Case 16: provider extras text sanitization ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_anthropic_version', ' 2024-01-01 ' ) !== '2024-01-01' ) {
            $failures[] = "anthropic version should be trimmed.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_anthropic_version', '<b>2023-06-01</b>' ) !== '2023-06-01' ) {
            $failures[] = "anthropic version should have HTML stripped.";
        }
        if ( self::sanitize( $cbs, 'presshub_ai_openai_org', ' org-abc ' ) !== 'org-abc' ) {
            $failures[] = "openai org should be trimmed.";
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

    private static function callbacks(): array {
        $GLOBALS['REGISTERED_SETTINGS'] = [];
        $GLOBALS['SANITIZE_CALLBACKS']  = [];
        $settings = new PressHub_AI_Settings();
        $settings->register_settings();
        return $GLOBALS['SANITIZE_CALLBACKS'];
    }

    private static function sanitize( array $cbs, string $option, $value ) {
        return call_user_func( $cbs[ $option ], $value );
    }

    private static function reset_options(): void {
        $GLOBALS['OPTIONS_STORE'] = [];
    }
}

SettingsSanitizeTest::run();
