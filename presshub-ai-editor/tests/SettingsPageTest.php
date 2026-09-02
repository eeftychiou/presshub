<?php
/**
 * TDD tests for the P1-P6 settings overhaul (class-settings.php).
 *
 * Covers:
 *   - P1: sections registered with the expected ids in the expected order,
 *     fields registered under the right sections, render_settings_page()
 *     invokes settings_fields() + do_settings_sections(), and a help tab is
 *     added.
 *   - P2: every registered option gets a callable sanitize callback; the
 *     legacy presshub_ai_model migration is idempotent (guarded by
 *     presshub_ai_migrated_models) and only writes into the active
 *     provider's key when that key is empty.
 *   - P3: render_settings_page() is gated by the presshub_ai_settings_cap
 *     filter (default manage_options).
 *   - P4: API-key fields render a masked value/placeholder (last 4 chars)
 *     and never render the raw key.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-settings.php';

class SettingsPageTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: sections registered with the expected ids, in order ---
        self::reset_world();
        $settings = new PressHub_AI_Settings();
        $settings->register_settings();
        $sections    = $GLOBALS['SECTIONS']['presshub-ai'] ?? [];
        $section_ids = array_column( $sections, 'id' );
        $expected    = [ 'presshub_ai_general', 'presshub_ai_providers', 'presshub_ai_media', 'presshub_ai_rate_limits', 'presshub_ai_briefing', 'presshub_ai_github' ];
        if ( $section_ids !== $expected ) {
            $failures[] = 'Sections should be registered in order ' . implode( ', ', $expected ) . '; got: ' . implode( ', ', $section_ids );
        }
        foreach ( $expected as $section ) {
            if ( ! in_array( $section, $section_ids, true ) ) {
                $failures[] = "Missing section: {$section}";
            }
        }

        // --- Case 2: fields land in the right sections ---
        $fields = $GLOBALS['FIELDS']['presshub-ai'] ?? [];

        $general = array_column( $fields['presshub_ai_general'] ?? [], 'id' );
        if ( ! in_array( 'presshub_ai_provider', $general, true ) ) {
            $failures[] = 'presshub_ai_provider should be registered in presshub_ai_general; got: ' . implode( ', ', $general );
        }

        $providers = array_column( $fields['presshub_ai_providers'] ?? [], 'id' );
        foreach ( [
            'presshub_ai_api_key',
            'presshub_ai_model_openai',
            'presshub_ai_model_anthropic',
            'presshub_ai_model_gemini',
            'presshub_ai_temperature_openai',
            'presshub_ai_temperature_anthropic',
            'presshub_ai_temperature_gemini',
            'presshub_ai_max_tokens_openai',
            'presshub_ai_max_tokens_anthropic',
            'presshub_ai_max_tokens_gemini',
            'presshub_ai_timeout_openai',
            'presshub_ai_timeout_anthropic',
            'presshub_ai_timeout_gemini',
            'presshub_ai_openai_org',
            'presshub_ai_anthropic_version',
        ] as $field ) {
            if ( ! in_array( $field, $providers, true ) ) {
                $failures[] = "{$field} should be registered in presshub_ai_providers; got: " . implode( ', ', $providers );
            }
        }

        $github = array_column( $fields['presshub_ai_github'] ?? [], 'id' );
        if ( ! in_array( 'presshub_ai_github_token', $github, true ) ) {
            $failures[] = 'presshub_ai_github_token should be registered in presshub_ai_github; got: ' . implode( ', ', $github );
        }

        $media = array_column( $fields['presshub_ai_media'] ?? [], 'id' );
        foreach ( [ 'presshub_ai_google_cloud_api_key', 'presshub_ai_gcloud_project_id', 'presshub_ai_imagen_region' ] as $field ) {
            if ( ! in_array( $field, $media, true ) ) {
                $failures[] = "{$field} should be registered in presshub_ai_media; got: " . implode( ', ', $media );
            }
        }

        $rate = array_column( $fields['presshub_ai_rate_limits'] ?? [], 'id' );
        foreach ( [ 'presshub_ai_rate_limit_enabled', 'presshub_ai_rate_limit_per_hour', 'presshub_ai_rate_limit_window_seconds', 'presshub_ai_research_retention_days' ] as $field ) {
            if ( ! in_array( $field, $rate, true ) ) {
                $failures[] = "{$field} should be registered in presshub_ai_rate_limits; got: " . implode( ', ', $rate );
            }
        }

        $briefing = array_column( $fields['presshub_ai_briefing'] ?? [], 'id' );
        foreach ( [
            'presshub_ai_briefing_sources',
            'presshub_ai_briefing_tts_engine',
            'presshub_ai_briefing_schedule_enabled',
            'presshub_ai_briefing_harvest_time',
            'presshub_ai_briefing_generation_time',
            'presshub_ai_harvest_time_budget',
            'presshub_ai_briefing_text_preset',
            'presshub_ai_briefing_podcast_preset',
            'presshub_ai_briefing_target_duration',
            'presshub_ai_briefing_host_female',
            'presshub_ai_briefing_host_male',
            'presshub_ai_briefing_voice_female',
            'presshub_ai_briefing_voice_male',
            'presshub_ai_briefing_voice_speed',
            'presshub_ai_briefing_voice_pitch',
            'presshub_ai_briefing_tts_style',
            'presshub_ai_briefing_tts_custom_style',
            'presshub_ai_briefing_text_category',
            'presshub_ai_briefing_podcast_category',
            'presshub_ai_briefing_text_status',
            'presshub_ai_briefing_podcast_status',
            'presshub_ai_briefing_text_prompt',
            'presshub_ai_briefing_podcast_prompt_1',
            'presshub_ai_briefing_podcast_prompt_2',
            'presshub_ai_briefing_podcast_prompt_3',
        ] as $field ) {
            if ( ! in_array( $field, $briefing, true ) ) {
                $failures[] = "{$field} should be registered in presshub_ai_briefing; got: " . implode( ', ', $briefing );
            }
        }

        // --- Case 3: every registered option has a callable sanitize callback ---
        $registered = $GLOBALS['REGISTERED_SETTINGS'] ?? [];
        $expected_options = [
            'presshub_ai_provider',
            'presshub_ai_api_key',
            'presshub_ai_openai_org',
            'presshub_ai_anthropic_version',
            'presshub_ai_github_token',
            'presshub_ai_model_openai',
            'presshub_ai_model_anthropic',
            'presshub_ai_model_gemini',
            'presshub_ai_temperature_openai',
            'presshub_ai_temperature_anthropic',
            'presshub_ai_temperature_gemini',
            'presshub_ai_max_tokens_openai',
            'presshub_ai_max_tokens_anthropic',
            'presshub_ai_max_tokens_gemini',
            'presshub_ai_timeout_openai',
            'presshub_ai_timeout_anthropic',
            'presshub_ai_timeout_gemini',
            'presshub_ai_google_cloud_api_key',
            'presshub_ai_gcloud_project_id',
            'presshub_ai_imagen_region',
            'presshub_ai_fetch_urls',
            'presshub_ai_debug_prompts',
            'presshub_ai_rate_limit_enabled',
            'presshub_ai_rate_limit_per_hour',
            'presshub_ai_rate_limit_window_seconds',
            'presshub_ai_research_retention_days',
            'presshub_ai_log_level',
            'presshub_ai_remove_api_key',
            'presshub_ai_remove_google_cloud_api_key',
            'presshub_ai_remove_github_token',
            'presshub_ai_briefing_tts_api_key',
            'presshub_ai_remove_briefing_tts_api_key',
            'presshub_ai_briefing_tts_engine',
            'presshub_ai_briefing_tts_model',
            'presshub_ai_briefing_tts_timeout',
            'presshub_ai_briefing_sources',
            'presshub_ai_briefing_schedule_enabled',
            'presshub_ai_briefing_harvest_time',
            'presshub_ai_briefing_generation_time',
            'presshub_ai_harvest_time_budget',
            'presshub_ai_curation_max_articles',
            'presshub_ai_curation_max_chars_per_article',
            'presshub_ai_briefing_text_preset',
            'presshub_ai_briefing_podcast_preset',
            'presshub_ai_briefing_target_duration',
            'presshub_ai_briefing_host_count',
            'presshub_ai_briefing_host_female',
            'presshub_ai_briefing_host_male',
            'presshub_ai_briefing_host_tertiary',
            'presshub_ai_briefing_voice_female',
            'presshub_ai_briefing_voice_male',
            'presshub_ai_briefing_voice_tertiary',
            'presshub_ai_briefing_voice_speed',
            'presshub_ai_briefing_voice_pitch',
            'presshub_ai_briefing_audio_split_by_topic',
            'presshub_ai_briefing_audio_transition_sfx',
            'presshub_ai_briefing_audio_intro_sfx',
            'presshub_ai_briefing_audio_outro_sfx',
            'presshub_ai_briefing_tts_style',
            'presshub_ai_briefing_tts_custom_style',
            'presshub_ai_briefing_text_category',
            'presshub_ai_briefing_podcast_category',
            'presshub_ai_briefing_text_status',
            // Issue #65 — Settings-First: title prefix and date format
            // for the generated Text Story post.
            'presshub_ai_briefing_text_title_prefix',
            'presshub_ai_briefing_text_title_date_format',
            'presshub_ai_briefing_podcast_status',
            'presshub_ai_briefing_text_prompt',
            'presshub_ai_briefing_podcast_prompt_1',
            'presshub_ai_briefing_podcast_prompt_2',
            'presshub_ai_briefing_podcast_prompt_3',
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

        $missing = array_diff( $expected_options, $registered );
        $extra   = array_diff( $registered, $expected_options );
        if ( $missing || $extra ) {
            $failures[] = 'Registered options should be exactly the expected set; missing: ' . implode( ', ', $missing ) . '; extra: ' . implode( ', ', $extra );
        }
        foreach ( $registered as $option ) {
            if ( empty( $GLOBALS['SANITIZE_CALLBACKS'][ $option ] ) || ! is_callable( $GLOBALS['SANITIZE_CALLBACKS'][ $option ] ) ) {
                $failures[] = "Option {$option} has no callable sanitize callback.";
            }
        }
        // The legacy single-model option must no longer be a form option.
        if ( in_array( 'presshub_ai_model', $registered, true ) ) {
            $failures[] = 'Legacy presshub_ai_model should not be registered as a form option.';
        }

        // --- Case 4: render invokes settings_fields + do_settings_sections and adds the help tab ---
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $settings = new PressHub_AI_Settings();
        ob_start();
        $html = '';
        try {
            $settings->render_settings_page();
            $html = ob_get_clean();
        } catch ( Throwable $e ) {
            ob_end_clean();
            $failures[] = 'render_settings_page() threw with manage_options cap: ' . $e->getMessage();
        }
        if ( empty( $GLOBALS['RENDERED_SETTINGS_FIELDS']['presshub_ai_options'] ) ) {
            $failures[] = 'render_settings_page() should call settings_fields( presshub_ai_options ).';
        }
        if ( empty( $GLOBALS['RENDERED_SECTIONS']['presshub-ai'] ) ) {
            $failures[] = 'render_settings_page() should call do_settings_sections( presshub-ai ).';
        }
        $tabs = $GLOBALS['HELP_TABS'] ?? [];
        if ( empty( $tabs ) ) {
            $failures[] = 'render_settings_page() should register a help tab.';
        } else {
            $tab = end( $tabs );
            if ( ( $tab['id'] ?? '' ) !== 'presshub-ai-help' ) {
                $failures[] = 'Help tab id should be presshub-ai-help; got: ' . var_export( $tab, true );
            }
            if ( false === strpos( (string) ( $tab['content'] ?? '' ), 'PressHub AI' ) ) {
                $failures[] = 'Help tab content should mention PressHub AI; got: ' . var_export( $tab, true );
            }
        }
        if ( false === strpos( $html, 'presshub-ai-settings-form' ) ) {
            $failures[] = 'render_settings_page() should output the settings form.';
        }

        // --- Case 5: capability gate — no cap means wp_die, no form output ---
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $settings = new PressHub_AI_Settings();
        ob_start();
        $thrown = null;
        try {
            $settings->render_settings_page();
        } catch ( Throwable $e ) {
            $thrown = $e;
        }
        $output = ob_get_clean();
        if ( ! $thrown || false === stripos( $thrown->getMessage(), 'permission' ) ) {
            $failures[] = 'render_settings_page() should wp_die without the settings cap; got: ' . ( $thrown ? $thrown->getMessage() : 'no exception' );
        }
        if ( false !== strpos( $output, '<form' ) ) {
            $failures[] = 'render_settings_page() must not emit the form without the settings cap.';
        }

        // --- Case 6: presshub_ai_settings_cap filter widens and narrows the gate ---
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts' ];
        add_filter( 'presshub_ai_settings_cap', function () { return 'edit_others_posts'; } );
        $settings = new PressHub_AI_Settings();
        ob_start();
        $html = '';
        try {
            $settings->render_settings_page();
            $html = ob_get_clean();
        } catch ( Throwable $e ) {
            ob_end_clean();
            $failures[] = 'render_settings_page() should render with the filtered cap: ' . $e->getMessage();
        }
        if ( false === strpos( $html, 'presshub-ai-settings-form' ) ) {
            $failures[] = 'render_settings_page() should output the form when the filtered cap is held.';
        }

        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ]; // holds manage_options but NOT the filtered cap
        add_filter( 'presshub_ai_settings_cap', function () { return 'edit_others_posts'; } );
        $settings = new PressHub_AI_Settings();
        ob_start();
        $thrown = null;
        try {
            $settings->render_settings_page();
        } catch ( Throwable $e ) {
            $thrown = $e;
        }
        ob_end_clean();
        if ( ! $thrown || false === stripos( $thrown->getMessage(), 'permission' ) ) {
            $failures[] = 'render_settings_page() should reject when the user lacks the filtered cap; got: ' . ( $thrown ? $thrown->getMessage() : 'no exception' );
        }

        // --- Case 7: masked API-key display (P4) ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'sk-1234567890';
        $settings = new PressHub_AI_Settings();
        ob_start();
        $settings->render_api_key_field();
        $html = ob_get_clean();
        if ( false === strpos( $html, '••••7890' ) ) {
            $failures[] = 'API key field should display the masked key (last 4 chars); got: ' . $html;
        }
        if ( false !== strpos( $html, 'sk-1234567890' ) ) {
            $failures[] = 'API key field must never render the raw key.';
        }

        // --- Case 8: legacy presshub_ai_model migration, idempotent ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'anthropic';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model']    = 'gpt-4o-legacy';
        PressHub_AI_Settings::migrate_legacy_model();
        if ( ( $GLOBALS['OPTIONS_STORE']['presshub_ai_model_anthropic'] ?? null ) !== 'gpt-4o-legacy' ) {
            $failures[] = 'Migration should copy the legacy model into the active provider key (anthropic).';
        }
        if ( empty( $GLOBALS['OPTIONS_STORE']['presshub_ai_migrated_models'] ) ) {
            $failures[] = 'Migration should set presshub_ai_migrated_models.';
        }
        // Idempotent: a second run must not overwrite a newer value.
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_anthropic'] = 'claude-3-5-sonnet-20241022';
        PressHub_AI_Settings::migrate_legacy_model();
        if ( $GLOBALS['OPTIONS_STORE']['presshub_ai_model_anthropic'] !== 'claude-3-5-sonnet-20241022' ) {
            $failures[] = 'Migration must be idempotent (must not re-run once the flag is set).';
        }
        // Only-if-empty: a pre-existing per-provider value is never overwritten.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']        = 'anthropic';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model']           = 'legacy-value';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_anthropic'] = 'claude-3';
        PressHub_AI_Settings::migrate_legacy_model();
        if ( $GLOBALS['OPTIONS_STORE']['presshub_ai_model_anthropic'] !== 'claude-3' ) {
            $failures[] = 'Migration must not overwrite a non-empty per-provider model.';
        }
        // Gemini target key works too.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'gemini';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model']    = 'gemini-legacy';
        PressHub_AI_Settings::migrate_legacy_model();
        if ( ( $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini'] ?? null ) !== 'gemini-legacy' ) {
            $failures[] = 'Migration should target the gemini key when gemini is active.';
        }

        // --- Case 9: "Remove stored key" checkboxes + retention field render ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']           = '«redacted:sk-…»';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_github_token']      = '«redacted:ghp-…»';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = '«redacted:gc-…»';
        $settings = new PressHub_AI_Settings();
        foreach ( [
            'render_api_key_field'           => 'presshub_ai_remove_api_key',
            'render_github_token_field'      => 'presshub_ai_remove_github_token',
            'render_google_cloud_api_key_field' => 'presshub_ai_remove_google_cloud_api_key',
        ] as $renderer => $remove_flag ) {
            ob_start();
            $settings->{$renderer}();
            $html = ob_get_clean();
            if ( false === strpos( $html, 'name="' . $remove_flag . '"' ) ) {
                $failures[] = "{$renderer} should render the {$remove_flag} checkbox; got: " . $html;
            }
        }
        // Without a saved key the remove checkbox must not render.
        self::reset_world();
        $settings = new PressHub_AI_Settings();
        ob_start();
        $settings->render_api_key_field();
        $html = ob_get_clean();
        if ( false !== strpos( $html, 'presshub_ai_remove_api_key' ) ) {
            $failures[] = 'render_api_key_field() must not offer "Remove stored key" when no key is saved.';
        }
        // Retention field renders with the default value and its option id.
        self::reset_world();
        $settings = new PressHub_AI_Settings();
        ob_start();
        $settings->render_research_retention_days_field();
        $html = ob_get_clean();
        if ( false === strpos( $html, 'name="presshub_ai_research_retention_days"' ) || false === strpos( $html, 'value="30"' ) ) {
            $failures[] = 'render_research_retention_days_field() should render the option with the 30-day default; got: ' . $html;
        }

        // --- Case 10: save_settings AJAX handler ---
        require_once __DIR__ . '/../includes/class-ajax-handlers.php';
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $_POST = [
            'nonce'                 => 'valid_nonce',
            'presshub_ai_provider'  => 'gemini',
            'presshub_ai_fetch_urls'=> '1',
            'presshub_ai_model_gemini' => 'gemini-2.0-flash',
            'presshub_ai_max_tokens_gemini' => '12000',
        ];

        $ajax = new PressHub_AI_Ajax_Handlers();
        $thrown = false;
        try {
            $ajax->save_settings();
        } catch ( Throwable $e ) {
            $thrown = true;
        }

        if ( ( $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] ?? '' ) !== 'gemini' ) {
            $failures[] = 'save_settings AJAX should update presshub_ai_provider to gemini; got: ' . var_export( $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] ?? null, true );
        }
        if ( ( $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini'] ?? '' ) !== 'gemini-2.0-flash' ) {
            $failures[] = 'save_settings AJAX should update presshub_ai_model_gemini; got: ' . var_export( $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini'] ?? null, true );
        }
        if ( ( $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_gemini'] ?? 0 ) !== 12000 ) {
            $failures[] = 'save_settings AJAX should update presshub_ai_max_tokens_gemini; got: ' . var_export( $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_gemini'] ?? null, true );
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
        $GLOBALS['OPTIONS_STORE']            = [];
        $GLOBALS['CURRENT_USER_CAPS']        = [];
        $GLOBALS['CURRENT_USER_ID']          = 0;
        $GLOBALS['REGISTERED_SETTINGS']      = [];
        $GLOBALS['SANITIZE_CALLBACKS']       = [];
        $GLOBALS['SECTIONS']                 = [];
        $GLOBALS['FIELDS']                   = [];
        $GLOBALS['RENDERED_SECTIONS']        = [];
        $GLOBALS['RENDERED_SETTINGS_FIELDS'] = [];
        $GLOBALS['HELP_TABS']                = [];
        $GLOBALS['FILTERS']                  = [];
    }
}

SettingsPageTest::run();
