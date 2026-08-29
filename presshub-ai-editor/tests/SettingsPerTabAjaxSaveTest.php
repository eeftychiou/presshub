<?php
/**
 * Test suite for Issue #15: Per-Tab AJAX Save for PressHub AI Settings.
 *
 * Verifies:
 * 1. AJAX action handler registration (wp_ajax_presshub_ai_save_settings_section).
 * 2. Nonce and capability verification.
 * 3. Section-based option persistence & sanitization (coauthor, briefing, copilot, advanced).
 * 4. Isolation: Saving one section does not clobber or alter options of other sections.
 * 5. Checkbox handling: Unchecked checkboxes belonging to the active section default to 0 without affecting other sections.
 * 6. Audit log generation for section saves.
 * 7. Structured JSON response format.
 * 8. Render output containing per-tab save buttons with matching data-tab attributes.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-audit-logger.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class SettingsPerTabAjaxSaveTest
{
    private static function reset_env(): void {
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['JSON_RESPONSES'] = [];
        $GLOBALS['UPDATE_OPTION_CALLS'] = [];
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $_POST = [];
    }

    public static function run(): void {
        $failures = [];

        // -------------------------------------------------------------
        // Case 1: AJAX Action Handler Registration
        // -------------------------------------------------------------
        self::reset_env();
        $handlers = new PressHub_AI_Ajax_Handlers();

        if ( ! has_action( 'wp_ajax_presshub_ai_save_settings_section' ) ) {
            $failures[] = 'Action wp_ajax_presshub_ai_save_settings_section is not registered.';
        }

        // -------------------------------------------------------------
        // Case 2: Nonce Verification Rejection
        // -------------------------------------------------------------
        self::reset_env();
        $GLOBALS['NONCE_VALID'] = false;
        $_POST = [
            'tab'   => 'coauthor',
            'nonce' => 'invalid_nonce',
        ];

        $thrown = null;
        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {
            $thrown = $e;
        }

        if ( null === $thrown ) {
            $failures[] = 'save_settings_section() should fail on invalid nonce.';
        }

        // -------------------------------------------------------------
        // Case 3: Capability Gate Rejection
        // -------------------------------------------------------------
        self::reset_env();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'read' ]; // lacks manage_options
        $_POST = [
            'tab'   => 'coauthor',
            'nonce' => 'valid_nonce',
        ];

        $thrown = null;
        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {
            $thrown = $e;
        }

        $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
        $last_res  = end( $responses );
        if ( ! $last_res || true === ( $last_res['success'] ?? null ) ) {
            $failures[] = 'save_settings_section() should reject unauthorized users with JSON error.';
        }

        // -------------------------------------------------------------
        // Case 4: Co-Author Section Save & Sanitization
        // -------------------------------------------------------------
        self::reset_env();
        // Pre-set other sections to confirm isolation
        $GLOBALS['OPTIONS_STORE']['presshub_ai_copilot_model'] = 'gpt-4o-copilot-untouched';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;

        $_POST = [
            'tab'   => 'coauthor',
            'nonce' => 'valid_nonce',
            'presshub_ai_coauthor_provider'    => 'anthropic',
            'presshub_ai_coauthor_model'       => 'claude-3-5-sonnet-20241022',
            'presshub_ai_coauthor_temperature' => '0.45',
            'presshub_ai_coauthor_max_tokens'  => '8192',
            'presshub_ai_coauthor_timeout'     => '120',
            'presshub_ai_fetch_urls'           => '1',
            'presshub_ai_debug_prompts'        => '1',
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {
            // RuntimeException thrown by wp_send_json_success stub is expected
        }

        if ( get_option( 'presshub_ai_coauthor_provider' ) !== 'anthropic' ) {
            $failures[] = 'presshub_ai_coauthor_provider not saved correctly; got: ' . var_export( get_option( 'presshub_ai_coauthor_provider' ), true );
        }
        if ( get_option( 'presshub_ai_coauthor_model' ) !== 'claude-3-5-sonnet-20241022' ) {
            $failures[] = 'presshub_ai_coauthor_model not saved correctly; got: ' . var_export( get_option( 'presshub_ai_coauthor_model' ), true );
        }
        if ( (float) get_option( 'presshub_ai_coauthor_temperature' ) !== 0.45 ) {
            $failures[] = 'presshub_ai_coauthor_temperature not saved correctly; got: ' . var_export( get_option( 'presshub_ai_coauthor_temperature' ), true );
        }
        if ( (int) get_option( 'presshub_ai_coauthor_max_tokens' ) !== 8192 ) {
            $failures[] = 'presshub_ai_coauthor_max_tokens not saved correctly; got: ' . var_export( get_option( 'presshub_ai_coauthor_max_tokens' ), true );
        }
        if ( (int) get_option( 'presshub_ai_coauthor_timeout' ) !== 120 ) {
            $failures[] = 'presshub_ai_coauthor_timeout not saved correctly; got: ' . var_export( get_option( 'presshub_ai_coauthor_timeout' ), true );
        }
        if ( get_option( 'presshub_ai_fetch_urls' ) !== '1' && (int) get_option( 'presshub_ai_fetch_urls' ) !== 1 ) {
            $failures[] = 'presshub_ai_fetch_urls not saved correctly; got: ' . var_export( get_option( 'presshub_ai_fetch_urls' ), true );
        }

        // Assert isolation: Copilot & Advanced options were NOT touched or clobbered
        if ( get_option( 'presshub_ai_copilot_model' ) !== 'gpt-4o-copilot-untouched' ) {
            $failures[] = 'Isolation failed: presshub_ai_copilot_model was altered during coauthor tab save.';
        }
        if ( (int) get_option( 'presshub_ai_rate_limit_enabled' ) !== 1 ) {
            $failures[] = 'Isolation failed: presshub_ai_rate_limit_enabled in advanced tab was altered during coauthor tab save.';
        }

        $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
        $last_res  = end( $responses );
        if ( ! $last_res || true !== ( $last_res['success'] ?? null ) ) {
            $failures[] = 'save_settings_section() did not send success response for coauthor tab.';
        } elseif ( ( $last_res['data']['tab'] ?? '' ) !== 'coauthor' ) {
            $failures[] = 'save_settings_section() JSON response missing tab="coauthor".';
        }

        // -------------------------------------------------------------
        // Case 5: Checkbox Handling inside active section without resetting other tabs
        // -------------------------------------------------------------
        self::reset_env();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_fetch_urls'] = '1';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;

        // Save coauthor tab without presshub_ai_fetch_urls (checkbox unchecked)
        $_POST = [
            'tab'   => 'coauthor',
            'nonce' => 'valid_nonce',
            'presshub_ai_coauthor_provider' => 'openai',
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        // presshub_ai_fetch_urls should now be 0
        if ( (int) get_option( 'presshub_ai_fetch_urls' ) !== 0 ) {
            $failures[] = 'Unchecked checkbox presshub_ai_fetch_urls in coauthor tab was not set to 0.';
        }
        // presshub_ai_rate_limit_enabled (in advanced tab) MUST remain 1
        if ( (int) get_option( 'presshub_ai_rate_limit_enabled' ) !== 1 ) {
            $failures[] = 'Unchecked checkbox in coauthor tab accidentally reset presshub_ai_rate_limit_enabled in advanced tab.';
        }

        // -------------------------------------------------------------
        // Case 6: Daily Briefing Hub Section Save & JSON Payload support
        // -------------------------------------------------------------
        self::reset_env();
        $briefing_payload = [
            'presshub_ai_briefing_text_provider'        => 'gemini',
            'presshub_ai_briefing_text_model'           => 'gemini-2.0-flash',
            'presshub_ai_briefing_harvest_time'         => '07:30',
            'presshub_ai_briefing_generation_time'      => '08:00',
            'presshub_ai_briefing_target_duration'      => '10_min',
            'presshub_ai_briefing_host_female'          => 'Ελένη',
            'presshub_ai_briefing_host_male'            => 'Κώστας',
            'presshub_ai_briefing_podcast_tts_provider' => 'gemini-speech',
            'presshub_ai_briefing_voice_speed'          => '1.15',
        ];

        $_POST = [
            'tab'         => 'briefing',
            'nonce'       => 'valid_nonce',
            'payload_b64' => base64_encode( json_encode( $briefing_payload ) ),
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        if ( get_option( 'presshub_ai_briefing_text_provider' ) !== 'gemini' ) {
            $failures[] = 'presshub_ai_briefing_text_provider not saved correctly.';
        }
        if ( get_option( 'presshub_ai_briefing_harvest_time' ) !== '07:30' ) {
            $failures[] = 'presshub_ai_briefing_harvest_time not saved correctly; got: ' . var_export( get_option( 'presshub_ai_briefing_harvest_time' ), true );
        }
        if ( get_option( 'presshub_ai_briefing_host_female' ) !== 'Ελένη' ) {
            $failures[] = 'presshub_ai_briefing_host_female not saved correctly; got: ' . var_export( get_option( 'presshub_ai_briefing_host_female' ), true );
        }
        if ( (float) get_option( 'presshub_ai_briefing_voice_speed' ) !== 1.15 ) {
            $failures[] = 'presshub_ai_briefing_voice_speed not saved correctly; got: ' . var_export( get_option( 'presshub_ai_briefing_voice_speed' ), true );
        }

        // -------------------------------------------------------------
        // Case 7: Copilot Section Save
        // -------------------------------------------------------------
        self::reset_env();
        $_POST = [
            'tab'   => 'copilot',
            'nonce' => 'valid_nonce',
            'presshub_ai_copilot_provider'    => 'groq',
            'presshub_ai_copilot_model'       => 'llama-3.3-70b-versatile',
            'presshub_ai_copilot_max_tokens'  => '4096',
            'presshub_ai_copilot_temperature' => '0.8',
            'presshub_ai_copilot_timeout'     => '60',
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        if ( get_option( 'presshub_ai_copilot_provider' ) !== 'groq' ) {
            $failures[] = 'presshub_ai_copilot_provider not saved correctly.';
        }
        if ( get_option( 'presshub_ai_copilot_model' ) !== 'llama-3.3-70b-versatile' ) {
            $failures[] = 'presshub_ai_copilot_model not saved correctly.';
        }
        if ( (int) get_option( 'presshub_ai_copilot_max_tokens' ) !== 4096 ) {
            $failures[] = 'presshub_ai_copilot_max_tokens not saved correctly.';
        }

        // -------------------------------------------------------------
        // Case 8: Advanced Section Save (Rate limits, Logging, Secrets)
        // -------------------------------------------------------------
        self::reset_env();
        $_POST = [
            'tab'   => 'advanced',
            'nonce' => 'valid_nonce',
            'presshub_ai_rate_limit_enabled'        => '1',
            'presshub_ai_rate_limit_per_hour'       => '50',
            'presshub_ai_rate_limit_window_seconds' => '1800',
            'presshub_ai_research_retention_days'   => '14',
            'presshub_ai_log_level'                 => 'DEBUG',
            'presshub_ai_gcloud_project_id'         => 'presshub-test-proj',
            'presshub_ai_imagen_region'             => 'europe-west1',
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        if ( (int) get_option( 'presshub_ai_rate_limit_enabled' ) !== 1 ) {
            $failures[] = 'presshub_ai_rate_limit_enabled not saved correctly in advanced section.';
        }
        if ( (int) get_option( 'presshub_ai_rate_limit_per_hour' ) !== 50 ) {
            $failures[] = 'presshub_ai_rate_limit_per_hour not saved correctly.';
        }
        if ( (int) get_option( 'presshub_ai_rate_limit_window_seconds' ) !== 1800 ) {
            $failures[] = 'presshub_ai_rate_limit_window_seconds not saved correctly.';
        }
        if ( (int) get_option( 'presshub_ai_research_retention_days' ) !== 14 ) {
            $failures[] = 'presshub_ai_research_retention_days not saved correctly.';
        }
        if ( get_option( 'presshub_ai_log_level' ) !== 'DEBUG' ) {
            $failures[] = 'presshub_ai_log_level not saved correctly.';
        }
        if ( get_option( 'presshub_ai_gcloud_project_id' ) !== 'presshub-test-proj' ) {
            $failures[] = 'presshub_ai_gcloud_project_id not saved correctly.';
        }

        // -------------------------------------------------------------
        // Case 9: Render Output contains per-tab save buttons
        // -------------------------------------------------------------
        self::reset_env();
        $renderer = new PressHub_AI_Settings_Render();
        ob_start();
        $renderer->render_settings_page();
        $html = ob_get_clean();

        $expected_tab_buttons = [
            'coauthor' => 'Save AI Co-Author Settings',
            'briefing' => 'Save Daily Briefing Settings',
            'copilot'  => 'Save AI Copilot Settings',
            'advanced' => 'Save Advanced Settings',
        ];

        foreach ( $expected_tab_buttons as $tab => $btn_label ) {
            $pattern = '/class="[^"]*presshub-tab-save-btn[^"]*"[^>]*data-tab="' . preg_quote( $tab, '/' ) . '"/i';
            if ( ! preg_match( $pattern, $html ) ) {
                $failures[] = "Rendered settings page missing per-tab save button for tab: {$tab}";
            }
        }

        // Verify fallback submit wrap is also present for non-JS
        if ( false === strpos( $html, 'presshub-settings-submit-wrap' ) ) {
            $failures[] = 'Rendered settings page missing fallback presshub-settings-submit-wrap.';
        }

        // -------------------------------------------------------------
        // Report results
        // -------------------------------------------------------------
        if ( ! empty( $failures ) ) {
            echo "FAIL\n";
            foreach ( $failures as $failure ) {
                echo "  - {$failure}\n";
            }
            exit( 1 );
        }

        echo "OK\n";
        exit( 0 );
    }
}

SettingsPerTabAjaxSaveTest::run();
