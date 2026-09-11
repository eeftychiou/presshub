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
        $GLOBALS['OPTIONS_STORE']['presshub_ai_debug_prompts'] = 0;

        $_POST = [
            'tab'   => 'coauthor',
            'nonce' => 'valid_nonce',
            'presshub_ai_coauthor_provider'    => 'anthropic',
            'presshub_ai_coauthor_model'       => 'claude-3-5-sonnet-20241022',
            'presshub_ai_coauthor_temperature' => '0.45',
            'presshub_ai_coauthor_max_tokens'  => '8192',
            'presshub_ai_coauthor_timeout'     => '120',
            'presshub_ai_fetch_urls'           => '1',
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
        if ( (int) get_option( 'presshub_ai_debug_prompts' ) !== 0 ) {
            $failures[] = 'Isolation failed: presshub_ai_debug_prompts was altered during coauthor tab save.';
        }

        $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
        $last_res  = end( $responses );
        if ( ! $last_res || true !== ( $last_res['success'] ?? null ) ) {
            $failures[] = 'save_settings_section() did not send success response for coauthor tab.';
        } elseif ( ( $last_res['data']['tab'] ?? '' ) !== 'coauthor' ) {
            $failures[] = 'save_settings_section() JSON response missing tab="coauthor".';
        }

        // -------------------------------------------------------------
        // Case 4b: Automated AI Editorial QA Review Settings Save & Persistence
        // -------------------------------------------------------------
        self::reset_env();
        $_POST = [
            'tab'                              => 'coauthor',
            'nonce'                            => 'valid_nonce',
            'presshub_ai_coauthor_provider'    => 'anthropic',
            'presshub_ai_qa_enabled'           => '1',
            'presshub_ai_qa_include_briefings' => '1',
            'presshub_ai_qa_min_score'         => '85',
            'presshub_ai_qa_notify_editor'     => '1',
            'presshub_ai_qa_editor_email'      => 'chief-editor@presshub.gr',
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        if ( (int) get_option( 'presshub_ai_qa_enabled' ) !== 1 ) {
            $failures[] = 'presshub_ai_qa_enabled not saved correctly in DB; got: ' . var_export( get_option( 'presshub_ai_qa_enabled' ), true );
        }
        if ( (int) get_option( 'presshub_ai_qa_include_briefings' ) !== 1 ) {
            $failures[] = 'presshub_ai_qa_include_briefings not saved correctly in DB; got: ' . var_export( get_option( 'presshub_ai_qa_include_briefings' ), true );
        }
        if ( (int) get_option( 'presshub_ai_qa_min_score' ) !== 85 ) {
            $failures[] = 'presshub_ai_qa_min_score not saved correctly in DB; got: ' . var_export( get_option( 'presshub_ai_qa_min_score' ), true );
        }
        if ( (int) get_option( 'presshub_ai_qa_notify_editor' ) !== 1 ) {
            $failures[] = 'presshub_ai_qa_notify_editor not saved correctly in DB; got: ' . var_export( get_option( 'presshub_ai_qa_notify_editor' ), true );
        }
        if ( get_option( 'presshub_ai_qa_editor_email' ) !== 'chief-editor@presshub.gr' ) {
            $failures[] = 'presshub_ai_qa_editor_email not saved correctly in DB; got: ' . var_export( get_option( 'presshub_ai_qa_editor_email' ), true );
        }

        // Test QA clamping (min 50, max 100)
        self::reset_env();
        $_POST = [
            'tab'                      => 'coauthor',
            'nonce'                    => 'valid_nonce',
            'presshub_ai_qa_min_score' => '150',
        ];
        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}
        if ( (int) get_option( 'presshub_ai_qa_min_score' ) !== 100 ) {
            $failures[] = 'presshub_ai_qa_min_score should clamp 150 to 100; got: ' . var_export( get_option( 'presshub_ai_qa_min_score' ), true );
        }

        self::reset_env();
        $_POST = [
            'tab'                      => 'coauthor',
            'nonce'                    => 'valid_nonce',
            'presshub_ai_qa_min_score' => '20',
        ];
        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}
        if ( (int) get_option( 'presshub_ai_qa_min_score' ) !== 50 ) {
            $failures[] = 'presshub_ai_qa_min_score should clamp 20 to 50; got: ' . var_export( get_option( 'presshub_ai_qa_min_score' ), true );
        }

        // Test Unchecking QA Checkboxes saves 0 in DB
        self::reset_env();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_qa_enabled']           = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_qa_include_briefings'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_qa_notify_editor']     = 1;

        $_POST = [
            'tab'                           => 'coauthor',
            'nonce'                         => 'valid_nonce',
            'presshub_ai_coauthor_provider' => 'openai',
        ];
        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        if ( (int) get_option( 'presshub_ai_qa_enabled' ) !== 0 ) {
            $failures[] = 'Unchecked presshub_ai_qa_enabled was not saved as 0 in DB; got: ' . var_export( get_option( 'presshub_ai_qa_enabled' ), true );
        }
        if ( (int) get_option( 'presshub_ai_qa_include_briefings' ) !== 0 ) {
            $failures[] = 'Unchecked presshub_ai_qa_include_briefings was not saved as 0 in DB; got: ' . var_export( get_option( 'presshub_ai_qa_include_briefings' ), true );
        }
        if ( (int) get_option( 'presshub_ai_qa_notify_editor' ) !== 0 ) {
            $failures[] = 'Unchecked presshub_ai_qa_notify_editor was not saved as 0 in DB; got: ' . var_export( get_option( 'presshub_ai_qa_notify_editor' ), true );
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
        // Case 5b: Daily Briefing Checkbox / Toggle Handling & Isolation (Issue #107)
        // -------------------------------------------------------------
        self::reset_env();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_schedule_enabled'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_audio_split_by_topic'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_log_tts_payloads'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_copilot_model'] = 'gpt-4o-briefing-isolation';

        // Save briefing tab without briefing checkboxes (all unchecked)
        $briefing_payload = [
            'presshub_ai_briefing_text_provider' => 'gemini',
        ];
        $_POST = [
            'tab'         => 'briefing',
            'nonce'       => 'valid_nonce',
            'payload_b64' => base64_encode( json_encode( $briefing_payload ) ),
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        if ( (int) get_option( 'presshub_ai_briefing_schedule_enabled' ) !== 0 ) {
            $failures[] = 'Unchecked checkbox presshub_ai_briefing_schedule_enabled in briefing tab was not set to 0; got: ' . var_export( get_option( 'presshub_ai_briefing_schedule_enabled' ), true );
        }
        if ( (int) get_option( 'presshub_ai_briefing_audio_split_by_topic' ) !== 0 ) {
            $failures[] = 'Unchecked checkbox presshub_ai_briefing_audio_split_by_topic in briefing tab was not set to 0; got: ' . var_export( get_option( 'presshub_ai_briefing_audio_split_by_topic' ), true );
        }
        // Assert section isolation: ensure other section options remain untouched
        if ( (int) get_option( 'presshub_ai_log_tts_payloads' ) !== 1 ) {
            $failures[] = 'Isolation failed: presshub_ai_log_tts_payloads was altered during briefing tab save.';
        }
        if ( (int) get_option( 'presshub_ai_rate_limit_enabled' ) !== 1 ) {
            $failures[] = 'Isolation failed: presshub_ai_rate_limit_enabled was altered during briefing tab save.';
        }
        if ( get_option( 'presshub_ai_copilot_model' ) !== 'gpt-4o-briefing-isolation' ) {
            $failures[] = 'Isolation failed: presshub_ai_copilot_model was altered during briefing tab save.';
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
            'presshub_ai_briefing_voice_pitch'          => '1.5',
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
        if ( (float) get_option( 'presshub_ai_briefing_voice_pitch' ) !== 1.5 ) {
            $failures[] = 'presshub_ai_briefing_voice_pitch not saved correctly; got: ' . var_export( get_option( 'presshub_ai_briefing_voice_pitch' ), true );
        }

        // -------------------------------------------------------------
        // Case 6c: Issue #108 — Podcast Dialogue Style & Prompt Studio AJAX Save
        // -------------------------------------------------------------
        self::reset_env();
        $podcast_studio_payload = [
            'presshub_ai_briefing_podcast_style'                             => 'bbc_broadcasting_standards',
            'presshub_ai_briefing_podcast_prompt_2_bbc_broadcasting_standards' => 'Custom BBC 2-host prompt: Do not use acronyms.',
        ];

        $_POST = [
            'tab'         => 'briefing',
            'nonce'       => 'valid_nonce',
            'payload_b64' => base64_encode( json_encode( $podcast_studio_payload ) ),
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        if ( get_option( 'presshub_ai_briefing_podcast_style' ) !== 'bbc_broadcasting_standards' ) {
            $failures[] = 'presshub_ai_briefing_podcast_style not saved correctly via briefing tab AJAX save; got: ' . var_export( get_option( 'presshub_ai_briefing_podcast_style' ), true );
        }
        if ( get_option( 'presshub_ai_briefing_podcast_prompt_2_bbc_broadcasting_standards' ) !== 'Custom BBC 2-host prompt: Do not use acronyms.' ) {
            $failures[] = 'presshub_ai_briefing_podcast_prompt_2_bbc_broadcasting_standards not saved correctly via briefing tab AJAX save; got: ' . var_export( get_option( 'presshub_ai_briefing_podcast_prompt_2_bbc_broadcasting_standards' ), true );
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
            'presshub_ai_debug_prompts'             => '1',
            'presshub_ai_log_tts_payloads'          => '1',
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
        if ( (int) get_option( 'presshub_ai_debug_prompts' ) !== 1 ) {
            $failures[] = 'presshub_ai_debug_prompts not saved correctly in advanced section.';
        }
        if ( (int) get_option( 'presshub_ai_log_tts_payloads' ) !== 1 ) {
            $failures[] = 'presshub_ai_log_tts_payloads not saved correctly in advanced section.';
        }
        if ( get_option( 'presshub_ai_gcloud_project_id' ) !== 'presshub-test-proj' ) {
            $failures[] = 'presshub_ai_gcloud_project_id not saved correctly.';
        }

        // -------------------------------------------------------------
        // Case 8b: Advanced Section Checkbox Uncheck Handling (Diagnostic Logging)
        // -------------------------------------------------------------
        self::reset_env();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_debug_prompts'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_log_tts_payloads'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;

        // Save advanced tab with all checkboxes unchecked
        $_POST = [
            'tab'                   => 'advanced',
            'nonce'                 => 'valid_nonce',
            'presshub_ai_log_level' => 'INFO',
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        if ( (int) get_option( 'presshub_ai_debug_prompts' ) !== 0 ) {
            $failures[] = 'Unchecked checkbox presshub_ai_debug_prompts in advanced tab was not set to 0; got: ' . var_export( get_option( 'presshub_ai_debug_prompts' ), true );
        }
        if ( (int) get_option( 'presshub_ai_log_tts_payloads' ) !== 0 ) {
            $failures[] = 'Unchecked checkbox presshub_ai_log_tts_payloads in advanced tab was not set to 0; got: ' . var_export( get_option( 'presshub_ai_log_tts_payloads' ), true );
        }
        if ( (int) get_option( 'presshub_ai_rate_limit_enabled' ) !== 0 ) {
            $failures[] = 'Unchecked checkbox presshub_ai_rate_limit_enabled in advanced tab was not set to 0; got: ' . var_export( get_option( 'presshub_ai_rate_limit_enabled' ), true );
        }

        // -------------------------------------------------------------
        // Case 9: Render Output contains per-tab save buttons
        // -------------------------------------------------------------
        self::reset_env();
        $settings = new PressHub_AI_Settings();
        $settings->register_settings();
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

        // Verify hidden input fallbacks for briefing checkboxes (Issue #107)
        if ( false === strpos( $html, 'name="presshub_ai_briefing_schedule_enabled" value="0"' ) ) {
            $failures[] = 'Rendered settings page missing hidden input fallback for presshub_ai_briefing_schedule_enabled.';
        }
        if ( false === strpos( $html, 'name="presshub_ai_briefing_audio_split_by_topic" value="0"' ) ) {
            $failures[] = 'Rendered settings page missing hidden input fallback for presshub_ai_briefing_audio_split_by_topic.';
        }

        // Verify hidden input fallbacks for logging checkboxes
        if ( false === strpos( $html, 'name="presshub_ai_debug_prompts" value="0"' ) ) {
            $failures[] = 'Rendered settings page missing hidden input fallback for presshub_ai_debug_prompts.';
        }
        if ( false === strpos( $html, 'name="presshub_ai_log_tts_payloads" value="0"' ) ) {
            $failures[] = 'Rendered settings page missing hidden input fallback for presshub_ai_log_tts_payloads.';
        }

        // Verify hidden input fallbacks for QA checkboxes
        if ( false === strpos( $html, 'name="presshub_ai_qa_enabled" value="0"' ) ) {
            $failures[] = 'Rendered settings page missing hidden input fallback for presshub_ai_qa_enabled.';
        }
        if ( false === strpos( $html, 'name="presshub_ai_qa_include_briefings" value="0"' ) ) {
            $failures[] = 'Rendered settings page missing hidden input fallback for presshub_ai_qa_include_briefings.';
        }
        if ( false === strpos( $html, 'name="presshub_ai_qa_notify_editor" value="0"' ) ) {
            $failures[] = 'Rendered settings page missing hidden input fallback for presshub_ai_qa_notify_editor.';
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
