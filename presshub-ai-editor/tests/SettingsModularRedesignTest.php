<?php
/**
 * TDD tests for Task 6: Settings UI Redesign: Dynamic Providers Manager, Modular Tabs & Token Viewer.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-token-logger.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';
require_once __DIR__ . '/../includes/class-settings.php';

class SettingsModularRedesignTest
{
    public static function run(): void {
        $failures = [];

        // -------------------------------------------------------------
        // Case 1: Modular Settings Registration & Sanitization
        // -------------------------------------------------------------
        self::reset_world();
        $settings = new PressHub_AI_Settings();
        $settings->register_settings();

        $registered = $GLOBALS['REGISTERED_SETTINGS'] ?? [];
        $modular_keys = [
            'presshub_ai_coauthor_provider',
            'presshub_ai_coauthor_model',
            'presshub_ai_coauthor_temperature',
            'presshub_ai_coauthor_max_tokens',
            'presshub_ai_coauthor_timeout',
            'presshub_ai_copilot_provider',
            'presshub_ai_copilot_model',
            'presshub_ai_copilot_temperature',
            'presshub_ai_copilot_max_tokens',
            'presshub_ai_copilot_timeout',
            'presshub_ai_briefing_text_provider',
            'presshub_ai_briefing_text_model',
            'presshub_ai_briefing_text_temperature',
            'presshub_ai_briefing_text_max_tokens',
            'presshub_ai_briefing_text_timeout',
            'presshub_ai_briefing_podcast_provider',
            'presshub_ai_briefing_podcast_model',
            'presshub_ai_briefing_podcast_temperature',
            'presshub_ai_briefing_podcast_max_tokens',
            'presshub_ai_briefing_podcast_timeout',
        ];

        foreach ( $modular_keys as $key ) {
            if ( ! in_array( $key, $registered, true ) ) {
                $failures[] = "Modular setting {$key} is not registered.";
            }
            if ( empty( $GLOBALS['SANITIZE_CALLBACKS'][ $key ] ) ) {
                $failures[] = "Modular setting {$key} has no sanitize callback.";
            }
        }

        // -------------------------------------------------------------
        // Case 2: Render Settings Page output contains all 6 tabs
        // -------------------------------------------------------------
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        ob_start();
        $settings->render_settings_page();
        $html = ob_get_clean();

        $expected_panes = [
            'presshub-tab-pane-providers',
            'presshub-tab-pane-coauthor',
            'presshub-tab-pane-briefing',
            'presshub-tab-pane-copilot',
            'presshub-tab-pane-token_logs',
            'presshub-tab-pane-advanced',
        ];

        foreach ( $expected_panes as $pane_id ) {
            if ( false === strpos( $html, 'id="' . $pane_id . '"' ) ) {
                $failures[] = "Settings page HTML missing pane: {$pane_id}";
            }
        }

        if ( false === strpos( $html, 'id="presshub-provider-modal"' ) ) {
            $failures[] = "Settings page HTML missing provider modal dialog.";
        }

        if ( false === strpos( $html, 'id="presshub-log-details-modal"' ) ) {
            $failures[] = "Settings page HTML missing log details modal dialog (#presshub-log-details-modal).";
        }
        if ( false === strpos( $html, 'id="presshub-log-error-container"' ) ) {
            $failures[] = "Settings page HTML missing #presshub-log-error-container.";
        }
        if ( false === strpos( $html, 'id="log-detail-error"' ) ) {
            $failures[] = "Settings page HTML missing #log-detail-error.";
        }
        if ( false === strpos( $html, 'id="presshub-copy-log-error"' ) ) {
            $failures[] = "Settings page HTML missing #presshub-copy-log-error.";
        }
        if ( false === strpos( $html, 'id="presshub-log-metadata-container"' ) ) {
            $failures[] = "Settings page HTML missing #presshub-log-metadata-container.";
        }

        // -------------------------------------------------------------
        // Case 3: AJAX Save Provider Endpoint
        // -------------------------------------------------------------
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['NONCE_VALID'] = true;
        $_REQUEST['_ajax_nonce'] = wp_create_nonce( 'presshub_ai_nonce' );
        $_POST['nonce']          = wp_create_nonce( 'presshub_ai_nonce' );

        $ajax = new PressHub_AI_Ajax_Handlers();

        $new_provider = [
            'name'          => 'Unit Test Provider',
            'type'          => 'groq',
            'base_url'      => 'https://api.groq.com/openai/v1',
            'api_key'       => 'gsk_test_key_123',
            'default_model' => 'llama-3.3-70b-versatile',
            'timeout'       => 120,
        ];
        $_POST['provider_data'] = json_encode( $new_provider );

        $res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->save_provider();
        } );

        if ( empty( $res['success'] ) || empty( $res['data']['provider']['id'] ) ) {
            $failures[] = 'save_provider should succeed and return provider array with id; got: ' . json_encode( $res );
        }

        $saved_id = $res['data']['provider']['id'] ?? '';
        $stored = PressHub_AI_Provider_Store::get( $saved_id );
        if ( ! $stored || $stored['name'] !== 'Unit Test Provider' ) {
            $failures[] = "Provider {$saved_id} was not properly stored in Provider Store.";
        }

        // -------------------------------------------------------------
        // Case 4: AJAX Test Provider Endpoint
        // -------------------------------------------------------------
        $_POST['provider_id'] = $saved_id;
        unset( $_POST['provider_data'] );

        $test_res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->test_provider();
        } );

        if ( ! isset( $test_res['data']['latency_ms'] ) ) {
            $failures[] = 'test_provider response must include latency_ms; got: ' . json_encode( $test_res );
        }

        // -------------------------------------------------------------
        // Case 5: AJAX Delete Provider Endpoint
        // -------------------------------------------------------------
        $_POST['provider_id'] = $saved_id;
        $del_res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->delete_provider();
        } );

        if ( empty( $del_res['success'] ) ) {
            $failures[] = 'delete_provider should succeed; got: ' . json_encode( $del_res );
        }

        if ( PressHub_AI_Provider_Store::get( $saved_id ) !== null ) {
            $failures[] = "Provider {$saved_id} should be deleted from Provider Store.";
        }

        // -------------------------------------------------------------
        // Case 6: AJAX Fetch Token Logs & Analytics
        // -------------------------------------------------------------
        // Seed log entry
        PressHub_AI_Token_Logger::log_llm_request(
            'unit_test_action',
            'openai',
            'gpt-4o',
            500,
            200,
            450,
            'success'
        );

        $_POST['page'] = 1;
        $_POST['per_page'] = 10;
        $_POST['date_range'] = 'all';

        $logs_res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->fetch_token_logs();
        } );

        if ( empty( $logs_res['success'] ) || empty( $logs_res['data']['logs']['items'] ) || empty( $logs_res['data']['summary'] ) ) {
            $failures[] = 'fetch_token_logs should return logs items and summary object; got: ' . json_encode( $logs_res );
        }

        // -------------------------------------------------------------
        // Case 7: AJAX Clear Token Logs
        // -------------------------------------------------------------
        $clear_res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->clear_token_logs();
        } );

        if ( empty( $clear_res['success'] ) ) {
            $failures[] = 'clear_token_logs should succeed; got: ' . json_encode( $clear_res );
        }

        // -------------------------------------------------------------
        // Case 8: Modular options in AJAX save_settings
        // -------------------------------------------------------------
        $_POST['payload'] = json_encode( [
            'presshub_ai_coauthor_provider' => 'groq',
            'presshub_ai_coauthor_model'    => 'llama-3.3-70b-versatile',
            'presshub_ai_copilot_provider'  => 'gemini',
            'presshub_ai_copilot_model'     => 'gemini-2.0-flash',
            'presshub_ai_briefing_text_provider' => 'anthropic',
            'presshub_ai_briefing_podcast_provider' => 'openai',
        ] );
        unset( $_POST['payload_b64'] );

        $save_set_res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->save_settings();
        } );

        if ( empty( $save_set_res['success'] ) ) {
            $failures[] = 'save_settings with modular keys should succeed; got: ' . json_encode( $save_set_res );
        }

        if ( get_option( 'presshub_ai_coauthor_provider' ) !== 'groq' ) {
            $failures[] = "presshub_ai_coauthor_provider was not saved as 'groq'.";
        }
        if ( get_option( 'presshub_ai_copilot_model' ) !== 'gemini-2.0-flash' ) {
            $failures[] = "presshub_ai_copilot_model was not saved as 'gemini-2.0-flash'.";
        }

        // -------------------------------------------------------------
        // Case 9: get_speech_providers_options filters only active Gemini providers with API key
        // -------------------------------------------------------------
        self::reset_world();
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'gemini-active',
            'type'          => 'gemini',
            'name'          => 'Active Gemini',
            'api_key'       => 'test-gemini-key',
            'default_model' => 'gemini-3.1-flash-tts-preview',
            'enabled'       => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'gemini-nokey',
            'type'          => 'gemini',
            'name'          => 'No Key Gemini',
            'api_key'       => '',
            'default_model' => 'gemini-2.0-flash',
            'enabled'       => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'gemini-disabled',
            'type'          => 'gemini',
            'name'          => 'Disabled Gemini',
            'api_key'       => 'test-key',
            'default_model' => 'gemini-2.0-flash',
            'enabled'       => false,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-active',
            'type'          => 'openai',
            'name'          => 'Active OpenAI',
            'api_key'       => 'test-openai-key',
            'default_model' => 'gpt-4o',
            'enabled'       => true,
        ] );

        $speech_opts = PressHub_AI_Settings::get_speech_providers_options( 'gemini-active', '-- Select Speech Provider --' );
        if ( false === strpos( $speech_opts, 'value="gemini-active"' ) ) {
            $failures[] = 'get_speech_providers_options must include active Gemini provider; got: ' . $speech_opts;
        }
        if ( false === strpos( $speech_opts, 'selected="selected"' ) ) {
            $failures[] = 'get_speech_providers_options must select the active provider option; got: ' . $speech_opts;
        }
        if ( false !== strpos( $speech_opts, 'value="gemini-nokey"' ) ) {
            $failures[] = 'get_speech_providers_options must NOT include Gemini provider without api_key; got: ' . $speech_opts;
        }
        if ( false !== strpos( $speech_opts, 'value="gemini-disabled"' ) ) {
            $failures[] = 'get_speech_providers_options must NOT include disabled Gemini provider; got: ' . $speech_opts;
        }
        if ( false !== strpos( $speech_opts, 'value="openai-active"' ) ) {
            $failures[] = 'get_speech_providers_options must NOT include non-Gemini provider; got: ' . $speech_opts;
        }

        // Test get_active_providers_options filters out unconfigured/disabled providers
        $active_opts = PressHub_AI_Settings::get_active_providers_options( 'gemini-active', '-- Default --' );
        if ( false === strpos( $active_opts, 'value="gemini-active"' ) ) {
            $failures[] = 'get_active_providers_options must include configured active provider; got: ' . $active_opts;
        }
        if ( false !== strpos( $active_opts, 'value="gemini-nokey"' ) ) {
            $failures[] = 'get_active_providers_options must NOT include provider without API key; got: ' . $active_opts;
        }
        if ( false !== strpos( $active_opts, 'value="gemini-disabled"' ) ) {
            $failures[] = 'get_active_providers_options must NOT include disabled provider; got: ' . $active_opts;
        }

        // -------------------------------------------------------------
        // Case 10: render_providers_grid only renders active/enabled providers
        // -------------------------------------------------------------
        ob_start();
        $settings->render_providers_grid();
        $grid_html = ob_get_clean();

        if ( false === strpos( $grid_html, 'data-provider-id="gemini-active"' ) ) {
            $failures[] = 'render_providers_grid must include enabled provider gemini-active; got: ' . $grid_html;
        }
        if ( false !== strpos( $grid_html, 'data-provider-id="gemini-disabled"' ) ) {
            $failures[] = 'render_providers_grid must NOT include disabled provider gemini-disabled; got: ' . $grid_html;
        }

        // -------------------------------------------------------------
        // Case 11: Speech AI Provider (logosAI) saves via AJAX whitelist
        // Regression: in v1.9.7 the field was rendered but missing from
        // $options_map, so save_settings() silently dropped its value.
        // -------------------------------------------------------------
        self::reset_world();
        $_POST['payload'] = json_encode( [
            'presshub_ai_briefing_podcast_tts_provider' => 'logosai',
        ] );
        unset( $_POST['payload_b64'] );

        $save_res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->save_settings();
        } );

        if ( empty( $save_res['success'] ) ) {
            $failures[] = 'save_settings with tts_provider should succeed; got: ' . json_encode( $save_res );
        }
        if ( get_option( 'presshub_ai_briefing_podcast_tts_provider' ) !== 'logosai' ) {
            $failures[] = "presshub_ai_briefing_podcast_tts_provider was not saved as 'logosai'; got: " . var_export( get_option( 'presshub_ai_briefing_podcast_tts_provider' ), true );
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }

        // -------------------------------------------------------------
        // Case 12: TTS style fields (logosAI) save via AJAX whitelist
        // Regression: in v1.9.7 the field was rendered but missing from
        // $options_map, so save_settings() silently dropped its value.
        // -------------------------------------------------------------
        self::reset_world();
        $_POST['payload'] = json_encode( [
            'presshub_ai_briefing_tts_style'        => 'storyteller',
            'presshub_ai_briefing_tts_custom_style' => 'Narrate like an old Greek fisherman recounting a myth',
        ] );
        unset( $_POST['payload_b64'] );

        $save_res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->save_settings();
        } );

        if ( empty( $save_res['success'] ) ) {
            $failures[] = 'save_settings with tts_style/custom_style should succeed; got: ' . json_encode( $save_res );
        }
        if ( get_option( 'presshub_ai_briefing_tts_style' ) !== 'storyteller' ) {
            $failures[] = "presshub_ai_briefing_tts_style was not saved as 'storyteller'; got: " . var_export( get_option( 'presshub_ai_briefing_tts_style' ), true );
        }
        if ( get_option( 'presshub_ai_briefing_tts_custom_style' ) !== 'Narrate like an old Greek fisherman recounting a myth' ) {
            $failures[] = 'presshub_ai_briefing_tts_custom_style was not saved verbatim; got: ' . var_export( get_option( 'presshub_ai_briefing_tts_custom_style' ), true );
        }

        // Also verify the sanitizer rejects invalid values (returns default).
        self::reset_world();
        $_POST['payload'] = json_encode( [
            'presshub_ai_briefing_tts_style' => '__not_a_real_style__',
        ] );
        unset( $_POST['payload_b64'] );
        self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->save_settings();
        } );
        if ( get_option( 'presshub_ai_briefing_tts_style' ) === '__not_a_real_style__' ) {
            $failures[] = 'sanitize_briefing_tts_style should reject invalid values; got: ' . var_export( get_option( 'presshub_ai_briefing_tts_style' ), true );
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
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['REGISTERED_SETTINGS'] = [];
        $GLOBALS['SANITIZE_CALLBACKS'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['JSON_RESPONSES'] = [];
        $GLOBALS['NONCE_VALID'] = true;
        $_POST = [];
        $_REQUEST = [];
    }

    private static function catch_ajax_response( callable $fn ): array {
        $before_count = count( $GLOBALS['JSON_RESPONSES'] ?? [] );
        try {
            $fn();
        } catch ( Throwable $e ) {
            // caught
        }
        $after = $GLOBALS['JSON_RESPONSES'] ?? [];
        if ( count( $after ) > $before_count ) {
            return end( $after );
        }
        return [ 'raw' => '' ];
    }
}

SettingsModularRedesignTest::run();
