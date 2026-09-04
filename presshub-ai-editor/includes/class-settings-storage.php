<?php
/**
 * PressHub AI Editor — Settings storage module (Concern #2 split).
 *
 * Owns the Settings-API option registration + every sanitize_callback
 * registered against the 'presshub_ai_options' group. Plus the
 * shared autoload=false secret contract and the re-entrancy guard
 * that prevents infinite recursion when update_option() triggers the
 * registered sanitize_option filter.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Storage layer for PressHub AI Settings.
 *
 * Companion to {@see PressHub_AI_Settings} (thin facade),
 * {@see PressHub_AI_Settings_Render}, and
 * {@see PressHub_AI_Settings_Migration}.
 *
 * The $sanitizing_secrets guard is per-class (not global) so that the
 * recursion test in SettingsSanitizeTest:290-300 remains meaningful.
 *
 * NOTE: As of T1 this class is loaded in parallel with
 * PressHub_AI_Settings (which still owns the active sanitize_*
 * methods). Two classes with the SAME name cannot co-exist; this is a
 * DIFFERENT class, so the parallel-declaration is safe and the
 * 46/46 test floor remains green. Cutover happens in T5.
 */
class PressHub_AI_Settings_Storage {

    const PROVIDERS = [ 'openai', 'anthropic', 'gemini' ];

    /**
     * Re-entrancy guard for sanitize_secret to prevent infinite recursion when
     * update_option() triggers the registered sanitize_option filter.
     *
     * @var array<string, bool>
     */
    private static $sanitizing_secrets = [];


    // ------------------------------------------------------------------
    // Settings-API option registration.
    //
    // Body lifted verbatim from PressHub_AI_Settings::register_settings()
    // (class-settings.php lines 181-532). In the original class the
    // `[ __CLASS__, 'sanitize_*' ]` callbacks resolved to
    // PressHub_AI_Settings; here __CLASS__ resolves to this class so the
    // registered callbacks point at PressHub_AI_Settings_Storage. The
    // literal text is identical — PHP's __CLASS__ magic constant makes
    // the rewrite semantically correct without any string edits.
    // ------------------------------------------------------------------

    public static function init(): void {
        self::seed_default_podcast_prompts();
    }

    public static function register_options(): void {
        self::seed_default_podcast_prompts();

        // Render callbacks point at the render module (Concern #2 T5).
        $render = new PressHub_AI_Settings_Render();

        // --- General ---
        register_setting( 'presshub_ai_options', 'presshub_ai_provider', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_provider' ],
            'type'              => 'string',
        ] );

        // Fetch source URLs server-side (2026-08-16): models cannot browse
        // URLs, so the plugin fetches + extracts article text itself.
        register_setting( 'presshub_ai_options', 'presshub_ai_fetch_urls', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_fetch_urls' ],
            'type'              => 'boolean',
        ] );

        // Prompt inspection toggle (1.2.5): appends the exact SYSTEM/USER
        // prompts to wp-content/uploads/presshub-ai-debug.log.
        register_setting( 'presshub_ai_options', 'presshub_ai_debug_prompts', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_fetch_urls' ],
            'type'              => 'boolean',
        ] );

        // --- Providers ---
        register_setting( 'presshub_ai_options', 'presshub_ai_api_key', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_api_key' ],
            'type'              => 'string',
        ] );
        // "Remove stored key" checkboxes: posting 1 deletes the secret so
        // a compromised key can be revoked through the UI (the masked
        // round-trip alone can never clear a key).
        register_setting( 'presshub_ai_options', 'presshub_ai_remove_api_key', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_remove_api_key' ],
            'type'              => 'boolean',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_remove_google_cloud_api_key', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_remove_google_cloud_api_key' ],
            'type'              => 'boolean',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_remove_github_token', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_remove_github_token' ],
            'type'              => 'boolean',
        ] );
        foreach ( self::PROVIDERS as $provider ) {
            register_setting( 'presshub_ai_options', 'presshub_ai_model_' . $provider, [
                'sanitize_callback' => function ( $value ) use ( $provider ) {
                    return self::sanitize_model( $value, $provider );
                },
                'type' => 'string',
            ] );
            register_setting( 'presshub_ai_options', 'presshub_ai_temperature_' . $provider, [
                'sanitize_callback' => [ __CLASS__, 'sanitize_temperature' ],
                'type'              => 'number',
            ] );
            register_setting( 'presshub_ai_options', 'presshub_ai_max_tokens_' . $provider, [
                'sanitize_callback' => [ __CLASS__, 'sanitize_max_tokens' ],
                'type'              => 'integer',
            ] );
            register_setting( 'presshub_ai_options', 'presshub_ai_timeout_' . $provider, [
                'sanitize_callback' => [ __CLASS__, 'sanitize_timeout' ],
                'type'              => 'integer',
            ] );
        }
        register_setting( 'presshub_ai_options', 'presshub_ai_openai_org', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_openai_org' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_anthropic_version', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_anthropic_version' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_github_token', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_github_token' ],
            'type'              => 'string',
        ] );

        // --- Media (Google Cloud) ---
        register_setting( 'presshub_ai_options', 'presshub_ai_google_cloud_api_key', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_google_cloud_api_key' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_gcloud_project_id', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_gcloud_project_id' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_imagen_region', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_imagen_region' ],
            'type'              => 'string',
        ] );

        // --- Rate limits ---
        register_setting( 'presshub_ai_options', 'presshub_ai_rate_limit_enabled', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_boolean' ],
            'type'              => 'boolean',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_rate_limit_per_hour', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_rate_limit_per_hour' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_rate_limit_window_seconds', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_rate_limit_window_seconds' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_research_retention_days', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_research_retention_days' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_log_level', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_log_level' ],
            'type'              => 'string',
        ] );

        // --- Daily Briefing & AI Podcast ---
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_tts_api_key', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_tts_api_key' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_remove_briefing_tts_api_key', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_remove_briefing_tts_api_key' ],
            'type'              => 'boolean',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_tts_engine', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_tts_engine' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_tts_model', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_tts_model' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_tts_timeout', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_tts_timeout' ],
            'type'              => 'integer',
        ] );
        // Issue #80 — Settings-First: TTS payload debug log toggle. When enabled
        // every Gemini TTS API call appends detailed request/response JSON
        // entries to wp-content/uploads/presshub-ai-tts-debug.log via the
        // `presshub_ai_tts_payload_log` action, so operators can diagnose
        // voice drift or unexpected voice allocation without touching code.
        register_setting( 'presshub_ai_options', 'presshub_ai_log_tts_payloads', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_boolean' ],
            'type'              => 'boolean',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_sources', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_sources' ],
            'type'              => 'array',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_schedule_enabled', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_boolean' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_harvest_time', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_harvest_time' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_generation_time', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_generation_time' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_harvest_time_budget', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_harvest_time_budget' ],
            'type'              => 'integer',
        ] );
        // Issue #61 — Settings-First: the curation LLM context cap (max articles
        // and max chars per article) must be operator-configurable, not
        // hard-coded apply_filters() defaults. Both knobs follow the same
        // six-step registration pattern as presshub_ai_harvest_time_budget.
        register_setting( 'presshub_ai_options', 'presshub_ai_curation_max_articles', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_curation_max_articles' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_curation_max_chars_per_article', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_curation_max_chars_per_article' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_preset', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_preset_slug' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_preset', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_preset_slug' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_target_duration', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_duration' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_host_count', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_host_count' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_host_female', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_host_female' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_host_male', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_host_male' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_host_tertiary', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_host_tertiary' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_voice_female', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_voice_female' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_voice_male', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_voice_male' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_voice_tertiary', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_voice_tertiary' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_audio_split_by_topic', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_audio_split_by_topic' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_audio_transition_sfx', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_audio_transition_sfx' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_audio_intro_sfx', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_audio_transition_sfx' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_audio_outro_sfx', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_audio_transition_sfx' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_voice_speed', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_voice_speed' ],
            'type'              => 'number',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_voice_pitch', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_voice_pitch' ],
            'type'              => 'number',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_tts_style', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_tts_style' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_tts_custom_style', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_tts_custom_style' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_category', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_category_id' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_category', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_category_id' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_status', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_status' ],
            'type'              => 'string',
        ] );
        // Issue #65 — Settings-First: operator-configurable title prefix
        // prepended to the generated Text Story post title (e.g. "Πρωινή
        // Ενημέρωση:"). Empty string disables the prefix so the curator's
        // headline is used verbatim.
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_title_prefix', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_text_title_prefix' ],
            'type'              => 'string',
        ] );
        // Issue #65 — Settings-First: date() format token appended after
        // the title prefix and headline (default "d/m/Y"). Empty string
        // disables the date suffix.
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_title_date_format', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_text_title_date_format' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_status', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_status' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_prompt', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_prompt_1', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_prompt_2', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_prompt_3', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'type'              => 'string',
        ] );

        // Modular Module Provider & Model Options (Task 6)
        register_setting( 'presshub_ai_options', 'presshub_ai_coauthor_provider', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_provider_id' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_coauthor_model', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_model_string' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_coauthor_temperature', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_temperature' ],
            'type'              => 'number',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_coauthor_max_tokens', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_tokens' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_coauthor_timeout', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_timeout' ],
            'type'              => 'integer',
        ] );

        register_setting( 'presshub_ai_options', 'presshub_ai_copilot_provider', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_provider_id' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_copilot_model', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_model_string' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_copilot_temperature', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_temperature' ],
            'type'              => 'number',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_copilot_max_tokens', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_tokens' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_copilot_timeout', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_timeout' ],
            'type'              => 'integer',
        ] );

        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_provider', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_provider_id' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_model', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_model_string' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_temperature', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_temperature' ],
            'type'              => 'number',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_max_tokens', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_tokens' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_timeout', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_timeout' ],
            'type'              => 'integer',
        ] );

        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_provider', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_provider_id' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_model', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_model_string' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_temperature', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_temperature' ],
            'type'              => 'number',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_max_tokens', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_tokens' ],
            'type'              => 'integer',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_timeout', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_timeout' ],
            'type'              => 'integer',
        ] );
        // Issue #90 — Settings-First: podcast dialogue style selector.
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_style', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_podcast_style' ],
            'type'              => 'string',
        ] );
        // Issue #90 — per-style prompt overrides (3 styles × 3 host counts = 9).
        // Each option key uses the sanitize_briefing_prompt() callback (already in place).
        $styles_for_settings = [ 'default_greek_chat', 'bbc_broadcasting_standards', 'conversational_news_reporting' ];
        foreach ( $styles_for_settings as $style_key ) {
            foreach ( [ 1, 2, 3 ] as $host_count_n ) {
                register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_prompt_' . $host_count_n . '_' . $style_key, [
                    'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_prompt' ],
                    'type'              => 'string',
                ] );
            }
        }

        // --- P1: sections ---
        add_settings_section( 'presshub_ai_general', __( 'General', 'presshub-ai-editor' ), [ $render, 'render_general_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_providers', __( 'Providers', 'presshub-ai-editor' ), [ $render, 'render_providers_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_media', __( 'Media (Google Cloud)', 'presshub-ai-editor' ), [ $render, 'render_media_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_rate_limits', __( 'Rate Limits', 'presshub-ai-editor' ), [ $render, 'render_rate_limits_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_briefing', __( 'Daily Briefing & AI Podcast', 'presshub-ai-editor' ), [ $render, 'render_briefing_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_github', __( 'Plugin Updates & GitHub Integration', 'presshub-ai-editor' ), [ $render, 'render_github_section' ], 'presshub-ai' );

        // --- P1: fields ---
        add_settings_field( 'presshub_ai_provider', __( 'AI Provider', 'presshub-ai-editor' ), [ $render, 'render_provider_field' ], 'presshub-ai', 'presshub_ai_general' );
        add_settings_field( 'presshub_ai_fetch_urls', __( 'Fetch source URLs', 'presshub-ai-editor' ), [ $render, 'render_fetch_urls_field' ], 'presshub-ai', 'presshub_ai_general' );
        add_settings_field( 'presshub_ai_debug_prompts', __( 'Log AI prompts', 'presshub-ai-editor' ), [ $render, 'render_debug_prompts_field' ], 'presshub-ai', 'presshub_ai_general' );

        add_settings_field( 'presshub_ai_api_key', __( 'API Key', 'presshub-ai-editor' ), [ $render, 'render_api_key_field' ], 'presshub-ai', 'presshub_ai_providers' );
        foreach ( self::PROVIDERS as $provider ) {
            $label = ucfirst( $provider );
            add_settings_field( 'presshub_ai_model_' . $provider, sprintf( __( 'Model (%s)', 'presshub-ai-editor' ), $label ), [ $render, 'render_model_field' ], 'presshub-ai', 'presshub_ai_providers', [ 'provider' => $provider ] );
            add_settings_field( 'presshub_ai_temperature_' . $provider, sprintf( __( 'Temperature (%s)', 'presshub-ai-editor' ), $label ), [ $render, 'render_temperature_field' ], 'presshub-ai', 'presshub_ai_providers', [ 'provider' => $provider ] );
            add_settings_field( 'presshub_ai_max_tokens_' . $provider, sprintf( __( 'Max Tokens (%s)', 'presshub-ai-editor' ), $label ), [ $render, 'render_max_tokens_field' ], 'presshub-ai', 'presshub_ai_providers', [ 'provider' => $provider ] );
            add_settings_field( 'presshub_ai_timeout_' . $provider, sprintf( __( 'Timeout (%s)', 'presshub-ai-editor' ), $label ), [ $render, 'render_timeout_field' ], 'presshub-ai', 'presshub_ai_providers', [ 'provider' => $provider ] );
        }
        add_settings_field( 'presshub_ai_openai_org', __( 'OpenAI Organization ID (optional)', 'presshub-ai-editor' ), [ $render, 'render_openai_org_field' ], 'presshub-ai', 'presshub_ai_providers' );
        add_settings_field( 'presshub_ai_anthropic_version', __( 'Anthropic API Version', 'presshub-ai-editor' ), [ $render, 'render_anthropic_version_field' ], 'presshub-ai', 'presshub_ai_providers' );
        add_settings_field( 'presshub_ai_github_token', __( 'GitHub Token (optional)', 'presshub-ai-editor' ), [ $render, 'render_github_token_field' ], 'presshub-ai', 'presshub_ai_github' );

        add_settings_field( 'presshub_ai_google_cloud_api_key', __( 'Google Cloud API Key (Imagen/TTS)', 'presshub-ai-editor' ), [ $render, 'render_google_cloud_api_key_field' ], 'presshub-ai', 'presshub_ai_media' );
        add_settings_field( 'presshub_ai_gcloud_project_id', __( 'Google Cloud Project ID (Imagen)', 'presshub-ai-editor' ), [ $render, 'render_gcloud_project_id_field' ], 'presshub-ai', 'presshub_ai_media' );
        add_settings_field( 'presshub_ai_imagen_region', __( 'Imagen Region', 'presshub-ai-editor' ), [ $render, 'render_imagen_region_field' ], 'presshub-ai', 'presshub_ai_media' );

        add_settings_field( 'presshub_ai_rate_limit_enabled', __( 'Enable Per-User Rate Limit', 'presshub-ai-editor' ), [ $render, 'render_rate_limit_enabled_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );
        add_settings_field( 'presshub_ai_rate_limit_per_hour', __( 'Requests per Window', 'presshub-ai-editor' ), [ $render, 'render_rate_limit_per_hour_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );
        add_settings_field( 'presshub_ai_rate_limit_window_seconds', __( 'Window Length (seconds)', 'presshub-ai-editor' ), [ $render, 'render_rate_limit_window_seconds_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );
        add_settings_field( 'presshub_ai_research_retention_days', __( 'Research Log Retention (days)', 'presshub-ai-editor' ), [ $render, 'render_research_retention_days_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );
        add_settings_field( 'presshub_ai_log_level', __( 'Diagnostic Log Level', 'presshub-ai-editor' ), [ $render, 'render_log_level_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );

        add_settings_field( 'presshub_ai_briefing_sources', __( 'News Source URLs', 'presshub-ai-editor' ), [ $render, 'render_briefing_sources_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_engine', __( 'Voice Synthesis Engine', 'presshub-ai-editor' ), [ $render, 'render_briefing_tts_engine_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_api_key', __( 'Speech Generation API Key (Google AI Studio / Gemini)', 'presshub-ai-editor' ), [ $render, 'render_briefing_tts_api_key_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_model', __( 'Voice Generation AI Model', 'presshub-ai-editor' ), [ $render, 'render_briefing_tts_model_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_timeout', __( 'Speech Generation Request Timeout (seconds)', 'presshub-ai-editor' ), [ $render, 'render_briefing_tts_timeout_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        // Issue #80 — granular TTS payload debug toggle, surfaced under
        // the Daily Briefing section next to the speech timeout knob.
        add_settings_field( 'presshub_ai_log_tts_payloads', __( 'Log TTS Payload Details', 'presshub-ai-editor' ), [ $render, 'render_log_tts_payloads_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_schedule_enabled', __( 'Enable Scheduled Briefing Hub', 'presshub-ai-editor' ), [ $render, 'render_briefing_schedule_enabled_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_harvest_time', __( 'Morning Harvest Time (HH:MM)', 'presshub-ai-editor' ), [ $render, 'render_briefing_harvest_time_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_generation_time', __( 'Briefing Generation Time (HH:MM)', 'presshub-ai-editor' ), [ $render, 'render_briefing_generation_time_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_harvest_time_budget', __( 'Harvest Execution Time Budget (seconds)', 'presshub-ai-editor' ), [ $render, 'render_harvest_time_budget_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        // Issue #61 — Settings-First: curation LLM context cap knobs. These
        // rows live in the Daily Briefing section next to the harvest budget
        // and reference the News Pool Inspector in their descriptions.
        add_settings_field( 'presshub_ai_curation_max_articles', __( 'Maximum Articles Sent to Curation LLM', 'presshub-ai-editor' ), [ $render, 'render_curation_max_articles_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_curation_max_chars_per_article', __( 'Maximum Characters per Article (Curation)', 'presshub-ai-editor' ), [ $render, 'render_curation_max_chars_per_article_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_preset', __( 'Text Story Preset', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_preset_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_preset', __( 'Podcast Dialogue Preset', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_preset_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_target_duration', __( 'Target Podcast Duration', 'presshub-ai-editor' ), [ $render, 'render_briefing_target_duration_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_host_count', __( 'Podcast Presenters Count', 'presshub-ai-editor' ), [ $render, 'render_briefing_host_count_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        // Issue #90 — Hide host-name UI fields. Legacy options remain in the DB
        // for any code that still reads them; they just don't surface in the UI.
        // add_settings_field( 'presshub_ai_briefing_host_female', __( 'Female Host Name', 'presshub-ai-editor' ), [ $render, 'render_briefing_host_female_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        // add_settings_field( 'presshub_ai_briefing_host_male', __( 'Male Host Name', 'presshub-ai-editor' ), [ $render, 'render_briefing_host_male_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        // add_settings_field( 'presshub_ai_briefing_host_tertiary', __( 'Third Host Name', 'presshub-ai-editor' ), [ $render, 'render_briefing_host_tertiary_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        // Issue #90 — Settings-First: podcast dialogue style selector.
        add_settings_field( 'presshub_ai_briefing_podcast_style', __( 'Podcast Dialogue Style', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_style_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_female', __( 'Female Voice Model (TTS)', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_female_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_male', __( 'Male Voice Model (TTS)', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_male_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_tertiary', __( 'Third Host Voice Model (TTS)', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_tertiary_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_speed', __( 'Voice Speaking Rate / Speed', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_speed_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_pitch', __( 'Voice Pitch Tuning', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_pitch_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_audio_split_by_topic', __( 'Split Audio Synthesis by Topic', 'presshub-ai-editor' ), [ $render, 'render_briefing_audio_split_by_topic_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_style', __( 'Speaking Delivery Style', 'presshub-ai-editor' ), [ $render, 'render_briefing_tts_style_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_custom_style', __( 'Custom Speaking Style Prompt', 'presshub-ai-editor' ), [ $render, 'render_briefing_tts_custom_style_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_category', __( 'Text Briefing Category', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_category_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_category', __( 'Podcast Category', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_category_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_status', __( 'Text Briefing Post Status', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_status_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        // Issue #65 — Settings-First: operator-configurable title prefix
        // and date format for the generated Text Story post. Both fields
        // live in the Daily Briefing section immediately after the
        // existing post-status row so operators can adjust masthead shape
        // without touching code.
        add_settings_field( 'presshub_ai_briefing_text_title_prefix', __( 'Text Story Title Prefix', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_title_prefix_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_title_date_format', __( 'Text Story Title Date Format', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_title_date_format_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_status', __( 'Podcast Post Status', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_status_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_prompt', __( 'Text Story System Prompt', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_prompt_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_prompt_1', __( 'Podcast Dialogue System Prompt (1 Host)', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_prompt_1_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_prompt_2', __( 'Podcast Dialogue System Prompt (2 Hosts)', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_prompt_2_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_prompt_3', __( 'Podcast Dialogue System Prompt (3 Hosts)', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_prompt_3_field' ], 'presshub-ai', 'presshub_ai_briefing' );

        // Issue #90 — Per-style prompt overrides (9 = 3 styles × 3 host counts).
        // Each style's textareas are rendered via a dedicated render method
        // that displays all 3 host-count variants together with a collapsible
        // header for clarity.
        add_settings_field( 'presshub_ai_briefing_podcast_prompts_default_greek_chat', __( 'Podcast Prompt Overrides: Default Greek Chat', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_prompts_default_greek_chat_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_prompts_bbc_broadcasting_standards', __( 'Podcast Prompt Overrides: BBC Broadcasting Standards', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_prompts_bbc_broadcasting_standards_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_prompts_conversational_news_reporting', __( 'Podcast Prompt Overrides: Conversational News Reporting', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_prompts_conversational_news_reporting_field' ], 'presshub-ai', 'presshub_ai_briefing' );

        // Issue #90 — Live preview block: shows the resolved template after
        // style + host_count + per-style overrides have been applied.
        add_settings_field( 'presshub_ai_briefing_podcast_live_preview', __( 'Podcast Dialogue Live Preview', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_live_preview_field' ], 'presshub-ai', 'presshub_ai_briefing' );
    }


    // ------------------------------------------------------------------
    // Sanitize callbacks (P5).
    //
    // Body lifted verbatim from class-settings.php lines 2038-2370
    // (just the sanitize_* public statics). T5 cut the facade and
    // retargeted add_settings_field callbacks from $this to a
    // $render = new PressHub_AI_Settings_Render() instance so the
    // render_*_field callbacks resolve on the render module.
    // ------------------------------------------------------------------

    public static function sanitize_provider( $value ) {
        $value = self::sanitize_text( $value );
        return in_array( $value, self::PROVIDERS, true ) ? $value : 'openai';
    }

    /**
     * 1.2.4: fetch-source-URLs toggle ('1'/'0' only).
     */
    public static function sanitize_fetch_urls( $value ) {
        return '1' === $value || 1 === $value || 'on' === $value ? '1' : '0';
    }

    public static function sanitize_api_key( $value ) {
        return self::sanitize_secret( $value, 'presshub_ai_api_key' );
    }

    public static function sanitize_google_cloud_api_key( $value ) {
        return self::sanitize_secret( $value, 'presshub_ai_google_cloud_api_key' );
    }

    public static function sanitize_github_token( $value ) {
        return self::sanitize_secret( $value, 'presshub_ai_github_token' );
    }

    public static function sanitize_briefing_tts_api_key( $value ) {
        return self::sanitize_secret( $value, 'presshub_ai_briefing_tts_api_key' );
    }

    /**
     * "Remove stored key" checkbox sanitizers: posting 1 deletes the
     * secret option outright (the only UI path that can clear a key, since
     * empty/masked posts preserve the saved value). The flag option itself
     * is stored as 0 either way.
     */
    public static function sanitize_remove_api_key( $value ) {
        return self::sanitize_remove_key( $value, 'presshub_ai_api_key' );
    }

    public static function sanitize_remove_google_cloud_api_key( $value ) {
        return self::sanitize_remove_key( $value, 'presshub_ai_google_cloud_api_key' );
    }

    public static function sanitize_remove_github_token( $value ) {
        return self::sanitize_remove_key( $value, 'presshub_ai_github_token' );
    }

    public static function sanitize_remove_briefing_tts_api_key( $value ) {
        return self::sanitize_remove_key( $value, 'presshub_ai_briefing_tts_api_key' );
    }

    private static function sanitize_remove_key( $value, $option_name ) {
        if ( in_array( $value, [ 1, '1', true, 'on', 'true', 'yes' ], true ) ) {
            delete_option( $option_name );
        }
        return 0;
    }

    public static function sanitize_research_retention_days( $value ) {
        $n = (int) wp_unslash( $value );
        return max( 1, min( 3650, $n ) );
    }

    public static function sanitize_model( $value, $provider = 'openai' ) {
        $value = self::sanitize_text( $value );
        if ( '' === $value ) {
            return self::default_model( $provider );
        }
        return substr( $value, 0, 200 );
    }

    public static function sanitize_temperature( $value ) {
        $value = wp_unslash( $value );
        if ( ! is_numeric( $value ) ) {
            return self::default_temperature();
        }
        $temp = (float) $value;
        if ( $temp < 0.0 || $temp > 2.0 ) {
            return self::default_temperature();
        }
        return round( $temp, 2 );
    }

    public static function sanitize_max_tokens( $value ) {
        $n = (int) wp_unslash( $value );
        return max( 1, min( 65536, $n ) );
    }

    public static function sanitize_timeout( $value ) {
        $n = (int) wp_unslash( $value );
        return max( 5, min( 300, $n ) );
    }

    public static function sanitize_rate_limit_per_hour( $value ) {
        $n = (int) wp_unslash( $value );
        return max( 1, min( 10000, $n ) );
    }

    public static function sanitize_rate_limit_window_seconds( $value ) {
        $n = (int) wp_unslash( $value );
        return max( 1, min( 86400, $n ) );
    }

    public static function sanitize_boolean( $value ) {
        return in_array( $value, [ 1, '1', true, 'on', 'true', 'yes' ], true ) ? 1 : 0;
    }

    public static function sanitize_gcloud_project_id( $value ) {
        $clean = self::sanitize_slug( $value );
        return '' === $clean ? 'presshub-ai' : $clean;
    }

    public static function sanitize_imagen_region( $value ) {
        $clean = self::sanitize_slug( $value );
        return '' === $clean ? 'us-central1' : $clean;
    }

    public static function sanitize_log_level( $value ): string {
        $value = strtoupper( trim( (string) wp_unslash( $value ) ) );
        if ( in_array( $value, [ 'DEBUG', 'INFO', 'WARNING', 'ERROR', 'OFF' ], true ) ) {
            return $value;
        }
        return 'INFO';
    }

    public static function sanitize_openai_org( $value ) {
        return substr( self::sanitize_text( $value ), 0, 512 );
    }

    public static function sanitize_anthropic_version( $value ) {
        return substr( self::sanitize_text( $value ), 0, 50 );
    }

    /**
     * Supported media types for News Source Manager.
     *
     * @return array<string, array{label: string, description: string, active: bool, badge_label: string, badge_class: string, icon: string}>
     */
    public static function get_supported_media_types(): array {
        return [
            'text_news'     => [
                'label'       => __( 'News Website (HTML)', 'presshub-ai-editor' ),
                'description' => __( 'Standard digital news portal / website scraped by the HTML engine.', 'presshub-ai-editor' ),
                'active'      => true,
                'badge_label' => __( 'Active Harvester', 'presshub-ai-editor' ),
                'badge_class' => 'badge-active',
                'icon'        => 'dashicons-admin-site-alt3',
            ],
            'rss_feed'      => [
                'label'       => __( 'RSS / Atom Feed', 'presshub-ai-editor' ),
                'description' => __( 'Dedicated XML/RSS or Atom feed with structured story links.', 'presshub-ai-editor' ),
                'active'      => true,
                'badge_label' => __( 'Active Harvester', 'presshub-ai-editor' ),
                'badge_class' => 'badge-active',
                'icon'        => 'dashicons-rss',
            ],
            'youtube'       => [
                'label'       => __( 'YouTube Channel / Playlist', 'presshub-ai-editor' ),
                'description' => __( 'YouTube video channels, video playlists, and vlogs (planned multi-modal pipeline).', 'presshub-ai-editor' ),
                'active'      => false,
                'badge_label' => __( 'Planned Multi-Modal', 'presshub-ai-editor' ),
                'badge_class' => 'badge-planned',
                'icon'        => 'dashicons-video-alt3',
            ],
            'vlog'          => [
                'label'       => __( 'Video / Vlog Feed', 'presshub-ai-editor' ),
                'description' => __( 'Video feeds and streaming clips (planned multi-modal pipeline).', 'presshub-ai-editor' ),
                'active'      => false,
                'badge_label' => __( 'Planned Multi-Modal', 'presshub-ai-editor' ),
                'badge_class' => 'badge-planned',
                'icon'        => 'dashicons-video-alt',
            ],
            'podcast_audio' => [
                'label'       => __( 'Audio Podcast / RSS', 'presshub-ai-editor' ),
                'description' => __( 'Audio feeds and podcast RSS for future Whisper / Gemini audio transcription.', 'presshub-ai-editor' ),
                'active'      => false,
                'badge_label' => __( 'Planned Multi-Modal', 'presshub-ai-editor' ),
                'badge_class' => 'badge-planned',
                'icon'        => 'dashicons-format-audio',
            ],
        ];
    }

    /**
     * Normalize and sanitize news sources into structured schema.
     * Supports JSON strings, structured source arrays, legacy string lists, and plain URL arrays.
     *
     * @param mixed $value JSON string, array of objects, array of URLs, or newline-delimited string.
     * @return array[] List of structured source arrays.
     */
    public static function normalize_sources( $value ): array {
        if ( empty( $value ) ) {
            return PressHub_AI_Settings_Migration::default_structured_sources();
        }

        $value = wp_unslash( $value );

        // If JSON string, decode it
        if ( is_string( $value ) ) {
            $trimmed = trim( $value );
            if ( '' === $trimmed ) {
                return PressHub_AI_Settings_Migration::default_structured_sources();
            }
            if ( ( '[' === $trimmed[0] && ']' === substr( $trimmed, -1 ) ) || ( '{' === $trimmed[0] && '}' === substr( $trimmed, -1 ) ) ) {
                $decoded = json_decode( $trimmed, true );
                if ( is_array( $decoded ) ) {
                    $value = $decoded;
                }
            }
        }

        $supported_types = array_keys( self::get_supported_media_types() );
        $clean_sources   = [];
        $seen_urls       = [];

        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( is_string( $item ) ) {
                    // Legacy URL string in array
                    $url = trim( strip_tags( $item ) );
                    if ( '' === $url || ! preg_match( '/^https?:\/\/[^\s]+$/i', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                        continue;
                    }
                    $norm_url = strtolower( rtrim( $url, '/' ) );
                    if ( isset( $seen_urls[ $norm_url ] ) ) {
                        continue;
                    }
                    $seen_urls[ $norm_url ] = true;

                    $host      = (string) parse_url( $url, PHP_URL_HOST );
                    $host_name = ucfirst( preg_replace( '/^www\./i', '', $host ) );

                    $clean_sources[] = [
                        'id'           => 'src_' . substr( md5( $norm_url ), 0, 8 ),
                        'name'         => $host_name ?: $url,
                        'url'          => esc_url_raw( $url ),
                        'type'         => 'text_news',
                        'enabled'      => true,
                        'category'     => 'General',
                        'notes'        => '',
                        'max_articles' => 5,
                    ];
                } elseif ( is_array( $item ) ) {
                    // Structured source object
                    $raw_url = trim( (string) ( $item['url'] ?? '' ) );
                    if ( '' === $raw_url || ! preg_match( '/^https?:\/\/[^\s]+$/i', $raw_url ) || ! filter_var( $raw_url, FILTER_VALIDATE_URL ) ) {
                        continue;
                    }
                    $url      = esc_url_raw( $raw_url );
                    $norm_url = strtolower( rtrim( $url, '/' ) );
                    if ( isset( $seen_urls[ $norm_url ] ) ) {
                        continue;
                    }
                    $seen_urls[ $norm_url ] = true;

                    $id = sanitize_key( (string) ( $item['id'] ?? '' ) );
                    if ( empty( $id ) ) {
                        $id = 'src_' . substr( md5( $norm_url ), 0, 8 );
                    }

                    $host      = (string) parse_url( $url, PHP_URL_HOST );
                    $host_name = ucfirst( preg_replace( '/^www\./i', '', $host ) );
                    $name      = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
                    if ( '' === trim( $name ) ) {
                        $name = $host_name ?: $url;
                    }

                    $type = sanitize_key( (string) ( $item['type'] ?? 'text_news' ) );
                    if ( ! in_array( $type, $supported_types, true ) ) {
                        $type = 'text_news';
                    }

                    $enabled = true;
                    if ( isset( $item['enabled'] ) ) {
                        $val = $item['enabled'];
                        $enabled = ( true === $val || 1 === $val || '1' === $val || 'true' === $val );
                    }

                    $category = sanitize_text_field( (string) ( $item['category'] ?? 'General' ) );
                    if ( '' === trim( $category ) ) {
                        $category = 'General';
                    }

                    $notes = sanitize_textarea_field( (string) ( $item['notes'] ?? '' ) );

                    $max_articles = isset( $item['max_articles'] ) ? max( 1, min( 30, (int) $item['max_articles'] ) ) : 5;

                    $clean_sources[] = [
                        'id'           => $id,
                        'name'         => $name,
                        'url'          => $url,
                        'type'         => $type,
                        'enabled'      => $enabled,
                        'category'     => $category,
                        'notes'        => $notes,
                        'max_articles' => $max_articles,
                    ];
                }
            }
        } elseif ( is_string( $value ) ) {
            // Legacy plaintext newline-separated URLs
            $lines = preg_split( '/[\r\n,]+/', $value );
            foreach ( (array) $lines as $line ) {
                $line = trim( strip_tags( (string) $line ) );
                if ( '' === $line || ! preg_match( '/^https?:\/\/[^\s]+$/i', $line ) || ! filter_var( $line, FILTER_VALIDATE_URL ) ) {
                    continue;
                }
                $norm_url = strtolower( rtrim( $line, '/' ) );
                if ( isset( $seen_urls[ $norm_url ] ) ) {
                    continue;
                }
                $seen_urls[ $norm_url ] = true;

                $host      = (string) parse_url( $line, PHP_URL_HOST );
                $host_name = ucfirst( preg_replace( '/^www\./i', '', $host ) );

                $clean_sources[] = [
                    'id'           => 'src_' . substr( md5( $norm_url ), 0, 8 ),
                    'name'         => $host_name ?: $line,
                    'url'          => esc_url_raw( $line ),
                    'type'         => 'text_news',
                    'enabled'      => true,
                    'category'     => 'General',
                    'notes'        => '',
                    'max_articles' => 5,
                ];
            }
        }

        return array_values( $clean_sources );
    }

    /**
     * Sanitizer callback for presshub_ai_briefing_sources.
     *
     * @param mixed $value
     * @return array[] Sanitized structured sources.
     */
    public static function sanitize_briefing_sources( $value ): array {
        return self::normalize_sources( $value );
    }

    /**
     * Helper to retrieve structured briefing sources from database.
     *
     * @return array[] Structured sources.
     */
    public static function get_briefing_sources(): array {
        $raw = get_option( 'presshub_ai_briefing_sources', null );
        if ( null === $raw || '' === $raw || [] === $raw ) {
            return PressHub_AI_Settings_Migration::default_structured_sources();
        }
        return self::normalize_sources( $raw );
    }

    /**
     * Maximum number of source entries accepted in a single bulk import.
     */
    const BULK_IMPORT_MAX_LINES = 100;

    /**
     * Bulk-import a newline-separated list of news source URLs.
     *
     * Accepts three text formats per line (auto-detected):
     *   1. "Name | URL"  (pipe-delimited; URL may contain additional pipes)
     *   2. "Name,URL"    (CSV-style)
     *   3. Bare URL      (https?://...)
     *
     * Lines containing non-http(s) schemes, malformed URLs, or empty
     * content are silently skipped and counted as invalid. Existing
     * sources are kept untouched; URLs that already exist (normalized
     * by lowercased + trailing-slash trimmed) are skipped and counted
     * as duplicates.
     *
     * On success, the resulting merged list is persisted to the
     * `presshub_ai_briefing_sources` option and a configuration audit
     * entry of type `news_source_bulk_import` is recorded.
     *
     * @param string $raw_text  Raw paste content from the bulk-import modal.
     * @param array  $defaults  Optional defaults: type (text_news|rss_feed),
     *                          category (string), max_articles (1-30, int),
     *                          enabled (bool).
     * @return array{ added: int, skipped_duplicates: int, invalid: int,
     *                sources: array[], truncated: bool }
     */
    public static function bulk_import_sources( string $raw_text, array $defaults = [] ): array {
        $existing = self::get_briefing_sources();

        $default_type     = isset( $defaults['type'] ) && is_string( $defaults['type'] )
            ? sanitize_key( (string) $defaults['type'] )
            : 'text_news';
        $supported_types  = array_keys( self::get_supported_media_types() );
        if ( ! in_array( $default_type, $supported_types, true ) ) {
            $default_type = 'text_news';
        }

        $default_category = isset( $defaults['category'] ) && is_string( $defaults['category'] )
            ? sanitize_text_field( (string) $defaults['category'] )
            : 'General';
        if ( '' === trim( $default_category ) ) {
            $default_category = 'General';
        }

        $default_max_articles = isset( $defaults['max_articles'] )
            ? max( 1, min( 30, (int) $defaults['max_articles'] ) )
            : 5;

        $default_enabled = true;
        if ( array_key_exists( 'enabled', $defaults ) ) {
            $val = $defaults['enabled'];
            $default_enabled = ( true === $val || 1 === $val || '1' === $val || 'true' === $val );
        }

        $candidates = self::parse_bulk_source_lines( $raw_text );

        $truncated      = false;
        $invalid_count  = 0;
        $added_count    = 0;
        $dup_count      = 0;

        // Build a lookup of existing normalized URLs for fast dedup.
        $existing_keys = [];
        foreach ( $existing as $src ) {
            if ( empty( $src['url'] ) ) {
                continue;
            }
            $norm = strtolower( rtrim( (string) $src['url'], '/' ) );
            if ( '' !== $norm ) {
                $existing_keys[ $norm ] = true;
            }
        }

        $merged = $existing;

        $line_count = 0;
        foreach ( $candidates as $candidate ) {
            $line_count++;
            if ( $line_count > self::BULK_IMPORT_MAX_LINES ) {
                $truncated = true;
                break;
            }

            $raw_url  = isset( $candidate['url'] ) ? trim( (string) $candidate['url'] ) : '';
            $raw_name = isset( $candidate['name'] ) ? trim( (string) $candidate['name'] ) : '';

            if ( '' === $raw_url || ! preg_match( '/^https?:\/\/[^\s]+$/i', $raw_url ) || ! filter_var( $raw_url, FILTER_VALIDATE_URL ) ) {
                $invalid_count++;
                continue;
            }

            $url     = esc_url_raw( $raw_url );
            $norm_url = strtolower( rtrim( $url, '/' ) );

            if ( isset( $existing_keys[ $norm_url ] ) ) {
                $dup_count++;
                continue;
            }
            $existing_keys[ $norm_url ] = true;

            $host      = (string) parse_url( $url, PHP_URL_HOST );
            $host_name = ucfirst( preg_replace( '/^www\./i', '', $host ) );

            $name = sanitize_text_field( $raw_name );
            if ( '' === $name ) {
                $name = $host_name ?: $url;
            }

            $type = $default_type;

            $merged[] = [
                'id'           => 'src_' . substr( md5( $norm_url ), 0, 8 ),
                'name'         => $name,
                'url'          => $url,
                'type'         => $type,
                'enabled'      => $default_enabled,
                'category'     => $default_category,
                'notes'        => '',
                'max_articles' => $default_max_articles,
            ];
            $added_count++;
        }

        if ( $added_count > 0 ) {
            update_option( 'presshub_ai_briefing_sources', array_values( $merged ), false );

            if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
                $details = [
                    'added'              => $added_count,
                    'skipped_duplicates' => $dup_count,
                    'invalid'            => $invalid_count,
                    'default_type'       => $default_type,
                    'default_category'   => $default_category,
                    'max_articles'       => $default_max_articles,
                    'enabled_default'    => $default_enabled,
                    'truncated'          => $truncated,
                    'lines_submitted'    => $line_count,
                ];
                PressHub_AI_Audit_Logger::log(
                    'news_source_bulk_import',
                    'news_source',
                    'bulk_' . ( function_exists( 'current_time' ) ? current_time( 'timestamp' ) : time() ),
                    $details
                );
            }
        }

        return [
            'added'              => $added_count,
            'skipped_duplicates' => $dup_count,
            'invalid'            => $invalid_count,
            'sources'            => array_values( $merged ),
            'truncated'          => $truncated,
        ];
    }

    /**
     * Parse a bulk-import paste buffer into structured {name,url} candidates.
     *
     * Supports three formats per non-empty line:
     *   1. "Name | URL"  — pipe delimiter; the rightmost segment containing
     *                      http(s):// is treated as the URL.
     *   2. "Name,URL"    — CSV with the URL segment containing http(s)://.
     *   3. Bare URL      — the entire line.
     *
     * @param string $raw_text Raw paste content.
     * @return array<int, array{name?: string, url?: string}> Ordered candidates.
     */
    public static function parse_bulk_source_lines( string $raw_text ): array {
        if ( '' === trim( $raw_text ) ) {
            return [];
        }

        $candidates = [];
        // Split on newlines or commas that appear OUTSIDE of obvious URL segments.
        $lines = preg_split( '/[\r\n]+/', $raw_text );
        if ( ! is_array( $lines ) ) {
            return [];
        }

        foreach ( $lines as $line ) {
            $line = (string) $line;
            $trimmed = trim( strip_tags( $line ) );
            if ( '' === $trimmed ) {
                continue;
            }

            // Format 1: "Name | URL" — URL must contain http(s)://.
            if ( strpos( $trimmed, '|' ) !== false ) {
                $parts = explode( '|', $trimmed );
                // Find first segment containing http(s):// — that's the URL;
                // everything before it (joined by '|') is the name.
                $url_idx = -1;
                foreach ( $parts as $idx => $segment ) {
                    if ( preg_match( '/https?:\/\/[^\s]+/i', $segment ) ) {
                        $url_idx = $idx;
                        break;
                    }
                }
                if ( $url_idx > 0 ) {
                    $name_parts = array_slice( $parts, 0, $url_idx );
                    $url_part   = trim( $parts[ $url_idx ] );
                    $name       = trim( implode( '|', $name_parts ) );
                    $candidates[] = [
                        'name' => $name,
                        'url'  => $url_part,
                    ];
                    continue;
                }
                if ( $url_idx === 0 ) {
                    // Leading pipe with URL after: "| URL" — skip name.
                    $candidates[] = [
                        'name' => '',
                        'url'  => trim( $parts[0] ),
                    ];
                    continue;
                }
                // No http(s) in any segment — fall through and treat as
                // single URL candidate (will fail validation if invalid).
            }

            // Format 2: "Name,URL" — first segment containing http(s):// is URL.
            if ( strpos( $trimmed, ',' ) !== false ) {
                $parts = explode( ',', $trimmed );
                $url_idx = -1;
                foreach ( $parts as $idx => $segment ) {
                    if ( preg_match( '/https?:\/\/[^\s]+/i', $segment ) ) {
                        $url_idx = $idx;
                        break;
                    }
                }
                if ( $url_idx > 0 ) {
                    $name_parts = array_slice( $parts, 0, $url_idx );
                    $url_part   = trim( $parts[ $url_idx ] );
                    $name       = trim( implode( ',', $name_parts ) );
                    $candidates[] = [
                        'name' => $name,
                        'url'  => $url_part,
                    ];
                    continue;
                }
                if ( $url_idx === 0 ) {
                    $candidates[] = [
                        'name' => '',
                        'url'  => trim( $parts[0] ),
                    ];
                    continue;
                }
            }

            // Format 3: bare URL.
            $candidates[] = [
                'name' => '',
                'url'  => $trimmed,
            ];
        }

        return $candidates;
    }

    public static function sanitize_harvest_time( $value ) {
        return self::sanitize_time_format( $value, self::default_briefing_harvest_time() );
    }

    public static function sanitize_generation_time( $value ) {
        return self::sanitize_time_format( $value, self::default_briefing_generation_time() );
    }

    public static function sanitize_harvest_time_budget( $value ): int {
        $value = wp_unslash( $value );
        if ( ! is_numeric( $value ) ) {
            return 60;
        }
        $n = (int) $value;
        return max( 10, min( 900, $n ) );
    }

    /**
     * Sanitize the maximum number of articles forwarded to the curation LLM.
     *
     * Clamps to the documented safe bounds [1, 200]; falls back to the
     * default (40) for non-numeric input. Issue #61 — Settings-First: the
     * cap must be operator-configurable, and the bounds live here so
     * business logic never re-implements floor/ceiling guards.
     *
     * @param mixed $value Raw input value.
     * @return int Clamped integer within [1, 200], default 40.
     */
    public static function sanitize_curation_max_articles( $value ): int {
        $value = wp_unslash( $value );
        if ( ! is_numeric( $value ) ) {
            return 40;
        }
        $n = (int) $value;
        return max( 1, min( 200, $n ) );
    }

    /**
     * Sanitize the per-article character cap inside the curation prompt.
     *
     * Clamps to the documented safe bounds [100, 400000]; falls back to the
     * default (3000) for non-numeric input. The wide upper bound lets
     * operators with very large context models (e.g. Gemini Pro 1M,
     * Claude Sonnet 4.5) feed a single article in full. Issue #61.
     *
     * @param mixed $value Raw input value.
     * @return int Clamped integer within [100, 400000], default 3000.
     */
    public static function sanitize_curation_max_chars_per_article( $value ): int {
        $value = wp_unslash( $value );
        if ( ! is_numeric( $value ) ) {
            return 3000;
        }
        $n = (int) $value;
        return max( 100, min( 400000, $n ) );
    }

    /**
     * Helper to retrieve configured curation time budget from database (clamped 30–600s, default 120s).
     *
     * @return int Configured execution time budget in seconds for curation/script generation.
     */
    public static function get_curation_time_budget(): int {
        $budget = (int) get_option( 'presshub_ai_curation_time_budget', 120 );
        return ( $budget >= 30 && $budget <= 600 ) ? $budget : 120;
    }

    /**
     * Helper to retrieve configured audio synthesis time budget from database (clamped 30–900s, default 180s).
     *
     * @return int Configured execution time budget in seconds for TTS audio synthesis.
     */
    public static function get_curation_audio_time_budget(): int {
        $budget = (int) get_option( 'presshub_ai_curation_audio_time_budget', 180 );
        return ( $budget >= 30 && $budget <= 900 ) ? $budget : 180;
    }

    /**
     * Helper to retrieve configured briefing TTS model from database.
     *
     * @return string Configured TTS model or empty string to use speech provider default.
     */
    public static function get_briefing_tts_model(): string {
        return (string) get_option( 'presshub_ai_briefing_tts_model', '' );
    }

    /**
     * Helper to retrieve configured briefing TTS timeout from database (clamped 60–900s, default 300s).
     *
     * @return int Configured HTTP timeout in seconds for speech generation.
     */
    public static function get_briefing_tts_timeout(): int {
        $timeout = (int) get_option( 'presshub_ai_briefing_tts_timeout', 300 );
        return ( $timeout >= 60 && $timeout <= 900 ) ? $timeout : 300;
    }

    /**
     * Helper to retrieve configured podcast host count (clamped 1–3, default 2).
     *
     * @return int Configured number of hosts (1 solo, 2 co-hosts, 3 roundtable).
     */
    public static function get_briefing_host_count(): int {
        $count = (int) get_option( 'presshub_ai_briefing_host_count', 2 );
        if ( $count < 1 ) {
            return 1;
        }
        if ( $count > 3 ) {
            return 3;
        }
        return $count;
    }

    /**
     * Helper to retrieve configured tertiary host name (default 'Presenter 3').
     *
     * @return string Third host name.
     */
    public static function get_briefing_host_tertiary(): string {
        $host = (string) get_option( 'presshub_ai_briefing_host_tertiary', 'Presenter 3' );
        $host = trim( $host );
        return ! empty( $host ) ? $host : 'Presenter 3';
    }

    /**
     * Helper to retrieve the configured TTS engine key.
     *
     * Issue #89 — Settings-First pattern (AGENTS.md): business logic
     * must read option values via a static helper, not via direct
     * get_option() calls. Returns the legacy raw value ('gemini' or
     * 'google_cloud'); callers that need the new engine-key format
     * ('gemini-2.5' / 'gemini-3.1') should pass the result through
     * PressHub_AI_Audio_Synthesizer::get_available_voices() which has
     * the canonical 'gemini' → 'gemini-2.5' shim.
     *
     * @return string Engine key. One of: 'gemini', 'google_cloud'.
     */
    public static function get_briefing_tts_engine(): string {
        $engine = (string) get_option( 'presshub_ai_briefing_tts_engine', 'gemini' );
        $valid  = [ 'gemini', 'google_cloud' ];
        return in_array( $engine, $valid, true ) ? $engine : 'gemini';
    }

    /**
     * Helper to retrieve configured female voice persona.
     *
     * Issue #89 — Settings-First pattern (AGENTS.md): business logic must
     * read voice options via a static helper, not via direct get_option()
     * calls. The allow-list is derived from the bundled voice-catalog
     * manifest at `assets/data/tts-voice-catalog.json` so the helper
     * stays in sync with shipped voices without hardcoded duplication.
     *
     * Default is engine-aware: Gemini 2.5 / 3.1 default to 'Kore';
     * Google Cloud TTS defaults to 'el-GR-Wavenet-A'. Operators who
     * upgrade from a pre-#89 plugin keep their stored value as long as
     * it is present in the manifest.
     *
     * @return string Female voice persona identifier.
     */
    public static function get_voice_female(): string {
        $engine = self::get_briefing_tts_engine();
        $default = ( 'google_cloud' === $engine ) ? 'el-GR-Wavenet-A' : 'Kore';
        $voice = (string) get_option( 'presshub_ai_briefing_voice_female', $default );
        $allowed = self::voice_catalog_allow_list();
        return in_array( $voice, $allowed, true ) ? $voice : $default;
    }

    /**
     * Helper to retrieve configured male voice persona.
     *
     * Issue #89 — Settings-First pattern (AGENTS.md). Same allow-list
     * source as get_voice_female().
     *
     * @return string Male voice persona identifier.
     */
    public static function get_voice_male(): string {
        $engine = self::get_briefing_tts_engine();
        $default = ( 'google_cloud' === $engine ) ? 'el-GR-Chirp3-HD-Achird' : 'Fenrir';
        $voice = (string) get_option( 'presshub_ai_briefing_voice_male', $default );
        $allowed = self::voice_catalog_allow_list();
        return in_array( $voice, $allowed, true ) ? $voice : $default;
    }

    /**
     * Helper to retrieve configured tertiary voice persona (default 'Puck').
     *
     * @return string Third host voice persona.
     */
    public static function get_voice_tertiary(): string {
        $engine = self::get_briefing_tts_engine();
        $default = ( 'google_cloud' === $engine ) ? 'el-GR-Wavenet-C' : 'Puck';
        $voice = (string) get_option( 'presshub_ai_briefing_voice_tertiary', $default );
        $allowed = self::voice_catalog_allow_list();
        return in_array( $voice, $allowed, true ) ? $voice : $default;
    }

    /**
     * Helper to retrieve configured Presenter 1 voice persona (alias for get_voice_female).
     *
     * @return string Presenter 1 voice identifier.
     */
    public static function get_voice_presenter_1(): string {
        return self::get_voice_female();
    }

    /**
     * Helper to retrieve configured Presenter 2 voice persona (alias for get_voice_male).
     *
     * @return string Presenter 2 voice identifier.
     */
    public static function get_voice_presenter_2(): string {
        return self::get_voice_male();
    }

    /**
     * Helper to retrieve configured Presenter 3 voice persona (alias for get_voice_tertiary).
     *
     * @return string Presenter 3 voice identifier.
     */
    public static function get_voice_presenter_3(): string {
        return self::get_voice_tertiary();
    }

    /**
     * Build the canonical allow-list of voice identifiers from the bundled
     * voice-catalog manifest. Returns a flat array of `name` strings.
     *
     * Issue #89 — the sanitizers used to hardcode their allow-list as
     * a PHP array literal. That was brittle (any new voice required a
     * code change) and could drift from the bundled manifest. The
     * single source of truth is now the JSON manifest; this helper
     * reads it once and flattens it. If the manifest is unreadable the
     * helper falls back to the historical hardcoded Gemini 2.5 catalog
     * so the sanitizers never reject a previously-valid value.
     *
     * @return array<int, string> Flat list of permitted voice identifiers.
     */
    private static function voice_catalog_allow_list(): array {
        static $cached = null;
        if ( null !== $cached ) {
            return $cached;
        }
        $path = dirname( __DIR__ ) . '/assets/data/tts-voice-catalog.json';
        if ( is_file( $path ) && is_readable( $path ) ) {
            $decoded = json_decode( (string) file_get_contents( $path ), true );
            if ( is_array( $decoded ) && isset( $decoded['engines'] ) && is_array( $decoded['engines'] ) ) {
                $names = [];
                foreach ( $decoded['engines'] as $engine ) {
                    if ( ! is_array( $engine ) || empty( $engine['voices'] ) ) {
                        continue;
                    }
                    foreach ( $engine['voices'] as $voice ) {
                        if ( is_array( $voice ) && ! empty( $voice['name'] ) ) {
                            $names[] = (string) $voice['name'];
                        }
                    }
                }
                if ( ! empty( $names ) ) {
                    $cached = array_values( array_unique( $names ) );
                    return $cached;
                }
            }
        }
        // Last-resort fallback mirrors the historical hardcoded list so
        // operators with pre-#89 stored values keep resolving correctly.
        $cached = [
            'Fenrir', 'Puck', 'Charon', 'Zephyr', 'Orus',
            'Aoede', 'Kore', 'Leda', 'Callirrhoe', 'Autonoe',
            'el-GR-Wavenet-A', 'el-GR-Wavenet-B', 'el-GR-Wavenet-C',
            'el-GR-Standard-A', 'el-GR-Standard-B',
            'el-GR-Chirp3-HD-Aoede', 'el-GR-Chirp3-HD-Achernar',
            'el-GR-Chirp3-HD-Achird', 'el-GR-Chirp3-HD-Algenib',
            'el-GR-Chirp3-HD-Algieba', 'el-GR-Chirp3-HD-Alnilam',
            'el-GR-Neural2-A', 'el-GR-Neural2-B',
        ];
        return $cached;
    }

    /**
     * Helper to check if audio synthesis should be split and generated per topic (default true).
     *
     * @return bool True if audio should be split by topic, false otherwise.
     */
    public static function get_briefing_audio_split_by_topic(): bool {
        $val = get_option( 'presshub_ai_briefing_audio_split_by_topic', 1 );
        return (bool) (int) $val;
    }

    /**
     * Helper to get the selected sound effect to inject between topics (default 'silence').
     *
     * @return string SFX identifier.
     */
    public static function get_briefing_audio_transition_sfx(): string {
        return (string) get_option( 'presshub_ai_briefing_audio_transition_sfx', 'silence' );
    }

    public static function get_briefing_audio_intro_sfx(): string {
        return (string) get_option( 'presshub_ai_briefing_audio_intro_sfx', 'silence' );
    }

    public static function get_briefing_audio_outro_sfx(): string {
        return (string) get_option( 'presshub_ai_briefing_audio_outro_sfx', 'silence' );
    }

    public static function get_podcast_prompt_1( string $style = '' ): string {
        return self::resolve_podcast_prompt( 1, 'presshub_ai_briefing_podcast_prompt_1', $style );
    }

    /**
     * Issue #90 — overload of get_podcast_prompt_1() that accepts an explicit
     * style key. Precedence:
     *   1. presshub_ai_briefing_podcast_prompt_1_<style> (per-style override)
     *   2. PressHub_AI_Podcast_Producer::get_default_dialogue_prompt( 1, $style )
     * The legacy global override (presshub_ai_briefing_podcast_prompt_1)
     * is no longer consulted when a style is explicitly passed; operators
     * customize per-style from now on.
     */
    public static function get_podcast_prompt_1_for_style( string $style ): string {
        return self::resolve_podcast_prompt( 1, 'presshub_ai_briefing_podcast_prompt_1', $style );
    }

    public static function get_podcast_prompt_2( string $style = '' ): string {
        return self::resolve_podcast_prompt( 2, 'presshub_ai_briefing_podcast_prompt_2', $style );
    }

    public static function get_podcast_prompt_2_for_style( string $style ): string {
        return self::resolve_podcast_prompt( 2, 'presshub_ai_briefing_podcast_prompt_2', $style );
    }

    public static function get_podcast_prompt_3( string $style = '' ): string {
        return self::resolve_podcast_prompt( 3, 'presshub_ai_briefing_podcast_prompt_3', $style );
    }

    public static function get_podcast_prompt_3_for_style( string $style ): string {
        return self::resolve_podcast_prompt( 3, 'presshub_ai_briefing_podcast_prompt_3', $style );
    }

    /**
     * Issue #90 / Issue #108 — shared resolver for podcast prompt options. Reads
     * `${prefix}_${style}` first; if null (never saved in DB), retrieves default,
     * seeds it in the database and returns it.
     * If the stored value is empty or whitespace-only (operator explicitly cleared it),
     * returns '' without silent fallback.
     *
     * @param int    $host_count Number of presenters (1, 2, or 3).
     * @param string $option_prefix e.g. 'presshub_ai_briefing_podcast_prompt_1'.
     * @param string $style Style key. Empty string = use the active saved style.
     * @return string The resolved prompt template.
     */
    private static function resolve_podcast_prompt( int $host_count, string $option_prefix, string $style ): string {
        $style_key = '' !== $style ? $style : self::get_podcast_style();

        $per_style_option = $option_prefix . '_' . $style_key;
        $val              = get_option( $per_style_option, null );
        if ( null === $val || ( is_string( $val ) && str_starts_with( trim( $val ), 'You are' ) ) ) {
            if ( ! class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
                require_once __DIR__ . '/class-podcast-producer.php';
            }
            $val = PressHub_AI_Podcast_Producer::get_default_dialogue_prompt( $host_count, $style_key );
            update_option( $per_style_option, $val );
            return $val;
        }

        if ( '' === trim( (string) $val ) ) {
            return '';
        }

        return (string) $val;
    }

    /**
     * Issue #108 — Seed built-in default prompts for all 9 style x presenter count options
     * if they have not yet been stored in the database, or upgrade legacy English templates.
     */
    public static function seed_default_podcast_prompts(): void {
        if ( ! class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            require_once __DIR__ . '/class-podcast-producer.php';
        }
        $styles = [ 'default_greek_chat', 'bbc_broadcasting_standards', 'conversational_news_reporting' ];
        foreach ( $styles as $style_key ) {
            foreach ( [ 1, 2, 3 ] as $host_count ) {
                $option_name = 'presshub_ai_briefing_podcast_prompt_' . $host_count . '_' . $style_key;
                $current     = get_option( $option_name, null );
                if ( null === $current || ( is_string( $current ) && str_starts_with( trim( $current ), 'You are' ) ) ) {
                    $default = PressHub_AI_Podcast_Producer::get_default_dialogue_prompt( $host_count, $style_key );
                    update_option( $option_name, $default );
                }
            }
        }
    }

    /**
     * Issue #90 — Settings-First: get the active podcast dialogue style.
     *
     * @return string One of 'default_greek_chat', 'bbc_broadcasting_standards',
     *                'conversational_news_reporting'.
     */
    public static function get_podcast_style(): string {
        return (string) get_option( 'presshub_ai_briefing_podcast_style', 'default_greek_chat' );
    }

    /**
     * Issue #90 — Settings-First sanitizer for presshub_ai_briefing_podcast_style.
     * Accepts only one of the three known style keys; falls back to
     * 'default_greek_chat' for unknown / empty / non-string values.
     */
    public static function sanitize_podcast_style( $value ): string {
        $value = (string) wp_unslash( $value );
        $allowed = [ 'default_greek_chat', 'bbc_broadcasting_standards', 'conversational_news_reporting' ];
        return in_array( $value, $allowed, true ) ? $value : 'default_greek_chat';
    }

    /**
     * Helper to retrieve configured harvest time budget from database (clamped 10–900s, default 60s).
     *
     * @return int Configured execution time budget in seconds.
     */
    public static function get_harvest_time_budget(): int {
        $budget = (int) get_option( 'presshub_ai_harvest_time_budget', 60 );
        return ( $budget >= 10 && $budget <= 900 ) ? $budget : 60;
    }

    /**
     * Helper to retrieve the operator-configurable "Log TTS Payload Details"
     * toggle. Issue #80 — Settings-First: when enabled, every Gemini TTS API
     * call appends a detailed JSON entry (endpoint URL, masked headers,
     * speaker-voice mapping, prompt text, full request body, response
     * metadata) to wp-content/uploads/presshub-ai-tts-debug.log via the
     * `presshub_ai_tts_payload_log` action. Defaults to false so production
     * log size is preserved.
     *
     * @return bool True when TTS payload debug logging is enabled.
     */
    public static function get_log_tts_payloads(): bool {
        return '1' === (string) get_option( 'presshub_ai_log_tts_payloads', '0' );
    }

    /**
     * Helper to retrieve the maximum number of articles forwarded to the
     * curation LLM (clamped 1–200, default 40). Issue #61 — Settings-First:
     * the authoritative value lives in the WordPress option; this helper is
     * the single read path for all consumers (curator, briefing admin UI,
     * AJAX token-log metadata, and the News Pool Inspector mirror).
     *
     * @return int Configured curation max-articles cap.
     */
    public static function get_curation_max_articles(): int {
        $value = (int) get_option( 'presshub_ai_curation_max_articles', 40 );
        return ( $value >= 1 && $value <= 200 ) ? $value : 40;
    }

    /**
     * Helper to retrieve the per-article character cap inside the curation
     * prompt (clamped 100–400000, default 3000). Issue #61 — Settings-First.
     *
     * @return int Configured curation per-article char cap.
     */
    public static function get_curation_max_chars_per_article(): int {
        $value = (int) get_option( 'presshub_ai_curation_max_chars_per_article', 3000 );
        return ( $value >= 100 && $value <= 400000 ) ? $value : 3000;
    }

    /**
     * Issue #65 — Sanitize the operator-configurable title prefix prepended
     * to the generated Text Story post title. Clamps to a 60-character
     * max so a runaway value can't break the post title field; falls back
     * to the documented default "Πρωινή Ενημέρωση:" for empty or non-string
     * input. An explicitly empty string disables the prefix (the curator's
     * headline is then used verbatim).
     *
     * @param mixed $value Raw input value.
     * @return string Sanitized prefix (0–60 chars).
     */
    public static function sanitize_briefing_text_title_prefix( $value ): string {
        if ( ! is_string( $value ) ) {
            return self::default_briefing_text_title_prefix();
        }
        $clean = sanitize_text_field( wp_unslash( $value ) );
        return substr( trim( $clean ), 0, 60 );
    }

    /**
     * Issue #65 — Sanitize the operator-configurable date() format token
     * appended after the title prefix and headline. The token is validated
     * by running it through a sandboxed date() round-trip; anything that
     * produces output containing characters outside the safe ASCII set
     * (letters, digits, common separators) is rejected. Empty input is
     * accepted and disables the date suffix.
     *
     * @param mixed $value Raw input value.
     * @return string Sanitized format token (or empty string).
     */
    public static function sanitize_briefing_text_title_date_format( $value ): string {
        if ( ! is_string( $value ) ) {
            return self::default_briefing_text_title_date_format();
        }
        $clean = trim( (string) wp_unslash( $value ) );
        if ( '' === $clean ) {
            return '';
        }
        // Accept only printable ASCII letters, digits, and the standard
        // date() separators. This rejects PHP format injection / control
        // characters without trying to enumerate every legal token.
        if ( ! preg_match( '/^[A-Za-z0-9\/\-\.\s,:_]+$/', $clean ) ) {
            return self::default_briefing_text_title_date_format();
        }
        // Round-trip through date() to ensure PHP accepts it. We use the
        // current timestamp and a known-safe timezone-independent check.
        $sample = @date( $clean );
        if ( false === $sample || '' === $sample ) {
            return self::default_briefing_text_title_date_format();
        }
        // Cap at 30 characters so a malicious operator can't blow up the title.
        return substr( $clean, 0, 30 );
    }

    /**
     * Issue #65 — Default title prefix ("Πρωινή Ενημέρωση:"). Used as the
     * fallback when the option is unset or contains invalid input.
     */
    public static function default_briefing_text_title_prefix(): string {
        return 'Πρωινή Ενημέρωση:';
    }

    /**
     * Issue #65 — Default date() format token ("d/m/Y"). Used as the
     * fallback when the option is unset or contains invalid input.
     */
    public static function default_briefing_text_title_date_format(): string {
        return 'd/m/Y';
    }

    /**
     * Issue #65 — Helper to retrieve the operator-configurable title prefix
     * prepended to the generated Text Story post title. Always returns a
     * sanitized string in [0, 60] characters; falls back to the documented
     * default "Πρωινή Ενημέρωση:" when the option is missing or invalid.
     *
     * @return string Configured title prefix.
     */
    public static function get_briefing_text_title_prefix(): string {
        $value = get_option( 'presshub_ai_briefing_text_title_prefix', null );
        if ( null === $value ) {
            return self::default_briefing_text_title_prefix();
        }
        return self::sanitize_briefing_text_title_prefix( $value );
    }

    /**
     * Issue #65 — Helper to retrieve the operator-configurable date()
     * format token appended after the title prefix and headline. Returns
     * a sanitized token (or empty string to disable). Falls back to the
     * documented default "d/m/Y" when the option is missing or invalid.
     *
     * @return string Configured date format token.
     */
    public static function get_briefing_text_title_date_format(): string {
        $value = get_option( 'presshub_ai_briefing_text_title_date_format', null );
        if ( null === $value ) {
            return self::default_briefing_text_title_date_format();
        }
        return self::sanitize_briefing_text_title_date_format( $value );
    }

    private static function sanitize_time_format( $value, $default = '06:30' ): string {
        $value = trim( (string) wp_unslash( $value ) );
        if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches ) ) {
            return sprintf( '%02d:%02d', (int) $matches[1], (int) $matches[2] );
        }
        return $default;
    }

    public static function sanitize_preset_slug( $value ): string {
        $value = self::sanitize_slug( $value );
        return substr( $value, 0, 50 );
    }

    public static function sanitize_briefing_duration( $value ): string {
        $clean = strtolower( trim( (string) wp_unslash( $value ) ) );
        if ( in_array( $clean, [ '3_min', '5_min', '10_min' ], true ) ) {
            return $clean;
        }
        return self::default_briefing_target_duration();
    }

    public static function sanitize_briefing_host_female( $value ): string {
        $clean = self::sanitize_text( $value );
        return '' !== $clean ? substr( $clean, 0, 50 ) : self::default_briefing_host_female();
    }

    public static function sanitize_briefing_host_male( $value ): string {
        $clean = self::sanitize_text( $value );
        return '' !== $clean ? substr( $clean, 0, 50 ) : self::default_briefing_host_male();
    }

    public static function sanitize_briefing_host_count( $value ): int {
        $val = (int) $value;
        if ( $val < 1 ) {
            return 1;
        }
        if ( $val > 3 ) {
            return 3;
        }
        return $val;
    }

    public static function sanitize_briefing_host_tertiary( $value ): string {
        $clean = self::sanitize_text( $value );
        return '' !== $clean ? substr( $clean, 0, 50 ) : 'Κώστας';
    }

    public static function sanitize_voice_tertiary( $value ): string {
        // Issue #89 — allow-list is now derived from the bundled
        // voice-catalog manifest (see voice_catalog_allow_list()) so the
        // sanitizer stays in sync with shipped voices without duplicating
        // the canonical name list in PHP.
        $value  = trim( (string) wp_unslash( $value ) );
        $allowed = self::voice_catalog_allow_list();
        return in_array( $value, $allowed, true ) ? $value : 'Puck';
    }

    public static function sanitize_briefing_audio_split_by_topic( $value ): int {
        return ! empty( $value ) ? 1 : 0;
    }

    public static function sanitize_briefing_audio_transition_sfx( $value ): string {
        $clean = is_string( $value ) ? sanitize_text_field( trim( wp_unslash( $value ) ) ) : 'silence';
        $valid = [ 'silence' ];
        
        $audio_dir = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/audio/';
        if ( is_dir( $audio_dir ) ) {
            // Scan for both WAV and MP3
            $files = array_merge( (array) glob( $audio_dir . '*.wav' ), (array) glob( $audio_dir . '*.mp3' ) );
            if ( $files ) {
                foreach ( $files as $file ) {
                    $valid[] = basename( $file ); // keep extension for exact matching later
                }
            }
        }
        
        return in_array( $clean, $valid, true ) ? $clean : 'silence';
    }

    public static function sanitize_briefing_tts_engine( $value ): string {
        $clean = is_string( $value ) ? sanitize_text_field( trim( $value ) ) : '';
        return in_array( $clean, [ 'gemini', 'google_cloud' ], true ) ? $clean : self::default_briefing_tts_engine();
    }

    public static function sanitize_briefing_tts_model( $value ): string {
        $clean = self::sanitize_text( $value );
        return '' !== $clean ? substr( $clean, 0, 100 ) : '';
    }

    public static function sanitize_briefing_tts_timeout( $value ): int {
        $val = (int) $value;
        if ( $val < 60 ) {
            return 60;
        }
        if ( $val > 900 ) {
            return 900;
        }
        return $val;
    }

    public static function sanitize_voice_female( $value ): string {
        // Issue #89 — allow-list is now derived from the bundled
        // voice-catalog manifest. We keep the historical Neural2-A →
        // Wavenet-A alias below because legacy operators may have that
        // value stored from a pre-2.x plugin version.
        $value  = trim( (string) wp_unslash( $value ) );
        if ( 'el-GR-Neural2-A' === $value ) {
            return 'el-GR-Wavenet-A';
        }
        $allowed = self::voice_catalog_allow_list();
        return in_array( $value, $allowed, true ) ? $value : self::default_briefing_voice_female();
    }

    public static function sanitize_voice_male( $value ): string {
        // Issue #89 — allow-list is now derived from the bundled
        // voice-catalog manifest. We keep the historical alias mappings
        // below because legacy operators may have those values stored
        // from a pre-2.x plugin version.
        $value = trim( (string) wp_unslash( $value ) );
        if ( in_array( $value, [ 'el-GR-Neural2-B', 'el-GR-Wavenet-B', 'el-GR-Standard-B' ], true ) ) {
            return 'el-GR-Chirp3-HD-Achird';
        }
        $allowed = self::voice_catalog_allow_list();
        return in_array( $value, $allowed, true ) ? $value : self::default_briefing_voice_male();
    }

    public static function sanitize_voice_speed( $value ): float {
        $value = wp_unslash( $value );
        if ( ! is_numeric( $value ) ) {
            return 1.0;
        }
        $speed = (float) $value;
        return round( max( 0.85, min( 1.25, $speed ) ), 2 );
    }

    public static function sanitize_voice_pitch( $value ): float {
        $value = wp_unslash( $value );
        if ( ! is_numeric( $value ) ) {
            return 0.0;
        }
        $pitch = (float) $value;
        return round( max( -4.0, min( 4.0, $pitch ) ), 1 );
    }

    public static function sanitize_briefing_tts_style( $value ): string {
        $allowed = [
            'formal',
            'natural',
            'cheerful',
            'storyteller',
            'calm',
            'dramatic',
            'poetic',
            'epic',
            'whisper',
            'energetic',
            'custom',
        ];
        $value = strtolower( trim( (string) wp_unslash( $value ) ) );
        return in_array( $value, $allowed, true ) ? $value : self::default_briefing_tts_style();
    }

    public static function sanitize_briefing_tts_custom_style( $value ): string {
        $value = wp_unslash( $value );
        if ( ! is_string( $value ) ) {
            return '';
        }
        return substr( trim( $value ), 0, 2000 );
    }

    public static function sanitize_briefing_status( $value ): string {
        $allowed = [ 'pending', 'publish', 'draft' ];
        $value = strtolower( trim( (string) wp_unslash( $value ) ) );
        return in_array( $value, $allowed, true ) ? $value : 'pending';
    }

    public static function sanitize_category_id( $value ): int {
        $n = (int) wp_unslash( $value );
        return max( 0, $n );
    }

    public static function sanitize_briefing_prompt( $value ): string {
        $value = wp_unslash( $value );
        if ( ! is_string( $value ) ) {
            return '';
        }
        return substr( trim( $value ), 0, 10000 );
    }

    public static function sanitize_provider_id( $value ): string {
        return sanitize_text_field( trim( (string) wp_unslash( $value ) ) );
    }

    public static function sanitize_model_string( $value ): string {
        $clean = sanitize_text_field( trim( (string) wp_unslash( $value ) ) );
        return preg_replace( '#^models/#', '', $clean );
    }


    // ------------------------------------------------------------------
    // Private helpers (P5 + P6).
    // ------------------------------------------------------------------

    /**
     * Shared secret sanitizer: strips tags/newlines, caps length, and —
     * critically — preserves the saved value when the post is empty or is
     * the masked placeholder (so re-saving the form never wipes a key the
     * admin didn't touch).
     *
     * Secrets are persisted with autoload disabled (update_option 3rd arg)
     * so they are only fetched from the DB on demand instead of riding
     * along on every request. The Settings API's own update_option call
     * afterwards keeps the existing autoload value, so the flag sticks.
     */
    private static function sanitize_secret( $value, $option_name ) {
        if ( ! empty( self::$sanitizing_secrets[ $option_name ] ) ) {
            return $value;
        }

        self::$sanitizing_secrets[ $option_name ] = true;

        try {
            $value = wp_unslash( $value );
            if ( ! is_string( $value ) ) {
                $value = '';
            }
            $value    = trim( $value );
            $existing = (string) get_option( $option_name, '' );

            if ( '' === $value || false !== strpos( $value, '••••' ) ) {
                $final = $existing;
            } else {
                $value = wp_strip_all_tags( $value );
                $value = preg_replace( '/[\r\n\t]+/', ' ', $value );
                $value = trim( $value );
                if ( strlen( $value ) > 512 ) {
                    $value = substr( $value, 0, 512 );
                }
                $final = $value;
            }

            update_option( $option_name, $final, false );

            return $final;
        } finally {
            unset( self::$sanitizing_secrets[ $option_name ] );
        }
    }

    /**
     * Generic text sanitizer: unslash, strip tags, collapse newlines/tabs,
     * trim. Mirrors sanitize_text_field for the stub environment.
     */
    private static function sanitize_text( $value ) {
        $value = wp_unslash( $value );
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = sanitize_text_field( $value );
        $value = wp_strip_all_tags( $value );
        $value = preg_replace( '/[\r\n\t]+/', ' ', $value );
        return trim( $value );
    }

    /**
     * Slug-like sanitizer (lowercase alphanumerics, dashes, underscores).
     */
    private static function sanitize_slug( $value ) {
        $value = wp_unslash( $value );
        $value = wp_strip_all_tags( (string) $value );
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9\-_]/', '', $value );
        return substr( $value, 0, 100 );
    }


    // ------------------------------------------------------------------
    // Default delegators used by sanitize_* fallbacks.
    //
    // These delegate to PressHub_AI_Provider_Defaults (the single
    // source of truth — class-provider-defaults.php) so that the
    // storage class never diverges from the facade's return values.
    // sanitize_model → default_model, sanitize_temperature →
    // default_temperature, etc.
    //
    // The "briefing" defaults (host names, harvest time, voice,
    // style, etc.) live on PressHub_AI_Settings itself for now;
    // when the migration module is filled in (T4), these forwarders
    // become PressHub_AI_Settings_Migration::default_*() or move
    // directly onto that class.
    // ------------------------------------------------------------------

    public static function default_model( string $provider = 'openai' ): string {
        return PressHub_AI_Provider_Defaults::default_model( $provider );
    }

    public static function default_temperature(): float {
        return PressHub_AI_Provider_Defaults::default_temperature();
    }

    public static function get_briefing_schedule_enabled(): int {
        return (int) get_option( 'presshub_ai_briefing_schedule_enabled', 1 );
    }

    public static function default_briefing_harvest_time(): string {
        return '06:30';
    }

    public static function default_briefing_generation_time(): string {
        return '07:15';
    }

    public static function default_briefing_target_duration(): string {
        return '5_min';
    }

    public static function default_briefing_host_female(): string {
        return 'Μαρία';
    }

    public static function default_briefing_host_male(): string {
        return 'Νίκος';
    }

    public static function default_briefing_tts_engine(): string {
        return 'gemini';
    }

    public static function default_briefing_tts_model(): string {
        return 'gemini-3.1-flash-tts-preview';
    }

    public static function default_briefing_voice_female(): string {
        return 'Kore';
    }

    public static function default_briefing_voice_male(): string {
        return 'Fenrir';
    }

    public static function default_briefing_tts_style(): string {
        return 'formal';
    }

    /**
     * Get the map of options and their respective sanitization callbacks for a specific settings section/tab.
     *
     * @param string $section Section/tab identifier ('coauthor', 'briefing', 'copilot', 'advanced', 'general', 'providers', or 'all'/empty).
     * @return array<string, callable> Map of option_name => sanitization callback.
     */
    public static function get_section_options_map( string $section = '' ): array {
        $section = strtolower( trim( $section ) );
        $section = str_replace( 'presshub_ai_', '', $section );

        $coauthor_map = [
            'presshub_ai_coauthor_provider'    => [ __CLASS__, 'sanitize_provider_id' ],
            'presshub_ai_coauthor_model'       => [ __CLASS__, 'sanitize_model_string' ],
            'presshub_ai_coauthor_temperature' => [ __CLASS__, 'sanitize_temperature' ],
            'presshub_ai_coauthor_max_tokens'  => [ __CLASS__, 'sanitize_max_tokens' ],
            'presshub_ai_coauthor_timeout'     => [ __CLASS__, 'sanitize_timeout' ],
            'presshub_ai_fetch_urls'           => [ __CLASS__, 'sanitize_fetch_urls' ],
            'presshub_ai_debug_prompts'        => [ __CLASS__, 'sanitize_boolean' ],
        ];

        $briefing_map = [
            'presshub_ai_briefing_text_provider'        => [ __CLASS__, 'sanitize_provider_id' ],
            'presshub_ai_briefing_text_model'           => [ __CLASS__, 'sanitize_model_string' ],
            'presshub_ai_briefing_sources'              => [ __CLASS__, 'sanitize_briefing_sources' ],
            'presshub_ai_briefing_schedule_enabled'     => [ __CLASS__, 'sanitize_boolean' ],
            'presshub_ai_briefing_harvest_time'         => [ __CLASS__, 'sanitize_harvest_time' ],
            'presshub_ai_briefing_generation_time'      => [ __CLASS__, 'sanitize_generation_time' ],
            'presshub_ai_harvest_time_budget'           => [ __CLASS__, 'sanitize_harvest_time_budget' ],
            // Issue #61 — Settings-First: curation LLM context cap knobs.
            'presshub_ai_curation_max_articles'          => [ __CLASS__, 'sanitize_curation_max_articles' ],
            'presshub_ai_curation_max_chars_per_article' => [ __CLASS__, 'sanitize_curation_max_chars_per_article' ],
            'presshub_ai_briefing_text_category'        => [ __CLASS__, 'sanitize_category_id' ],
            'presshub_ai_briefing_text_status'          => [ __CLASS__, 'sanitize_briefing_status' ],
            // Issue #65 — Settings-First: title prefix and date format
            // for the generated Text Story post.
            'presshub_ai_briefing_text_title_prefix'       => [ __CLASS__, 'sanitize_briefing_text_title_prefix' ],
            'presshub_ai_briefing_text_title_date_format'  => [ __CLASS__, 'sanitize_briefing_text_title_date_format' ],
            'presshub_ai_briefing_text_preset'          => [ __CLASS__, 'sanitize_preset_slug' ],
            'presshub_ai_briefing_text_prompt'          => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_text_temperature'     => [ __CLASS__, 'sanitize_temperature' ],
            'presshub_ai_briefing_text_max_tokens'      => [ __CLASS__, 'sanitize_max_tokens' ],
            'presshub_ai_briefing_text_timeout'         => [ __CLASS__, 'sanitize_timeout' ],
            'presshub_ai_briefing_podcast_provider'     => [ __CLASS__, 'sanitize_provider_id' ],
            'presshub_ai_briefing_podcast_tts_provider' => [ __CLASS__, 'sanitize_provider_id' ],
            'presshub_ai_briefing_podcast_model'        => [ __CLASS__, 'sanitize_model_string' ],
            'presshub_ai_briefing_target_duration'      => [ __CLASS__, 'sanitize_briefing_duration' ],
            'presshub_ai_briefing_host_count'            => [ __CLASS__, 'sanitize_briefing_host_count' ],
            'presshub_ai_briefing_host_female'          => [ __CLASS__, 'sanitize_briefing_host_female' ],
            'presshub_ai_briefing_host_male'            => [ __CLASS__, 'sanitize_briefing_host_male' ],
            'presshub_ai_briefing_host_tertiary'         => [ __CLASS__, 'sanitize_briefing_host_tertiary' ],
            'presshub_ai_briefing_tts_style'            => [ __CLASS__, 'sanitize_briefing_tts_style' ],
            'presshub_ai_briefing_tts_custom_style'     => [ __CLASS__, 'sanitize_briefing_tts_custom_style' ],
            'presshub_ai_briefing_voice_female'         => [ __CLASS__, 'sanitize_voice_female' ],
            'presshub_ai_briefing_voice_male'           => [ __CLASS__, 'sanitize_voice_male' ],
            'presshub_ai_briefing_voice_tertiary'        => [ __CLASS__, 'sanitize_voice_tertiary' ],
            'presshub_ai_briefing_voice_speed'          => [ __CLASS__, 'sanitize_voice_speed' ],
            'presshub_ai_briefing_voice_pitch'          => [ __CLASS__, 'sanitize_voice_pitch' ],
            'presshub_ai_briefing_audio_split_by_topic'  => [ __CLASS__, 'sanitize_briefing_audio_split_by_topic' ],
            'presshub_ai_briefing_audio_transition_sfx' => [ __CLASS__, 'sanitize_briefing_audio_transition_sfx' ],
            'presshub_ai_briefing_audio_intro_sfx'      => [ __CLASS__, 'sanitize_briefing_audio_transition_sfx' ],
            'presshub_ai_briefing_audio_outro_sfx'      => [ __CLASS__, 'sanitize_briefing_audio_transition_sfx' ],
            'presshub_ai_briefing_podcast_category'     => [ __CLASS__, 'sanitize_category_id' ],
            'presshub_ai_briefing_podcast_status'       => [ __CLASS__, 'sanitize_briefing_status' ],
            'presshub_ai_briefing_podcast_preset'       => [ __CLASS__, 'sanitize_preset_slug' ],
            // Issue #108 — Settings-First: active podcast dialogue style and per-style prompt overrides.
            'presshub_ai_briefing_podcast_style'                                  => [ __CLASS__, 'sanitize_podcast_style' ],
            'presshub_ai_briefing_podcast_prompt_1_default_greek_chat'             => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_2_default_greek_chat'             => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_3_default_greek_chat'             => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_1_bbc_broadcasting_standards'     => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_2_bbc_broadcasting_standards'     => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_3_bbc_broadcasting_standards'     => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_1_conversational_news_reporting'  => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_2_conversational_news_reporting'  => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_3_conversational_news_reporting'  => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_1'     => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_2'     => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_prompt_3'     => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'presshub_ai_briefing_podcast_temperature'  => [ __CLASS__, 'sanitize_temperature' ],
            'presshub_ai_briefing_podcast_max_tokens'   => [ __CLASS__, 'sanitize_max_tokens' ],
            'presshub_ai_briefing_podcast_timeout'      => [ __CLASS__, 'sanitize_timeout' ],
            'presshub_ai_briefing_tts_engine'           => [ __CLASS__, 'sanitize_briefing_tts_engine' ],
            'presshub_ai_briefing_tts_api_key'          => [ __CLASS__, 'sanitize_briefing_tts_api_key' ],
            'presshub_ai_remove_briefing_tts_api_key'   => [ __CLASS__, 'sanitize_remove_briefing_tts_api_key' ],
            'presshub_ai_briefing_tts_model'            => [ __CLASS__, 'sanitize_briefing_tts_model' ],
            'presshub_ai_briefing_tts_timeout'          => [ __CLASS__, 'sanitize_briefing_tts_timeout' ],
            // Issue #80 — Settings-First: granular TTS payload debug toggle.
            'presshub_ai_log_tts_payloads'              => [ __CLASS__, 'sanitize_boolean' ],
        ];

        $copilot_map = [
            'presshub_ai_copilot_provider'    => [ __CLASS__, 'sanitize_provider_id' ],
            'presshub_ai_copilot_model'       => [ __CLASS__, 'sanitize_model_string' ],
            'presshub_ai_copilot_temperature' => [ __CLASS__, 'sanitize_temperature' ],
            'presshub_ai_copilot_max_tokens'  => [ __CLASS__, 'sanitize_max_tokens' ],
            'presshub_ai_copilot_timeout'     => [ __CLASS__, 'sanitize_timeout' ],
        ];

        $advanced_map = [
            'presshub_ai_github_token'                => [ __CLASS__, 'sanitize_github_token' ],
            'presshub_ai_remove_github_token'         => [ __CLASS__, 'sanitize_remove_github_token' ],
            'presshub_ai_google_cloud_api_key'        => [ __CLASS__, 'sanitize_google_cloud_api_key' ],
            'presshub_ai_remove_google_cloud_api_key' => [ __CLASS__, 'sanitize_remove_google_cloud_api_key' ],
            'presshub_ai_gcloud_project_id'           => [ __CLASS__, 'sanitize_gcloud_project_id' ],
            'presshub_ai_imagen_region'               => [ __CLASS__, 'sanitize_imagen_region' ],
            'presshub_ai_rate_limit_enabled'          => [ __CLASS__, 'sanitize_boolean' ],
            'presshub_ai_rate_limit_per_hour'         => [ __CLASS__, 'sanitize_rate_limit_per_hour' ],
            'presshub_ai_rate_limit_window_seconds'   => [ __CLASS__, 'sanitize_rate_limit_window_seconds' ],
            'presshub_ai_research_retention_days'     => [ __CLASS__, 'sanitize_research_retention_days' ],
            'presshub_ai_log_level'                   => [ __CLASS__, 'sanitize_log_level' ],
        ];

        $general_map = [
            'presshub_ai_provider'      => [ __CLASS__, 'sanitize_provider' ],
            'presshub_ai_fetch_urls'    => [ __CLASS__, 'sanitize_fetch_urls' ],
            'presshub_ai_debug_prompts' => [ __CLASS__, 'sanitize_boolean' ],
        ];

        $providers_map = [
            'presshub_ai_provider'                    => [ __CLASS__, 'sanitize_provider' ],
            'presshub_ai_api_key'                     => [ __CLASS__, 'sanitize_api_key' ],
            'presshub_ai_remove_api_key'              => [ __CLASS__, 'sanitize_remove_api_key' ],
            'presshub_ai_model_openai'                => function( $v ) { return PressHub_AI_Settings_Storage::sanitize_model( $v, 'openai' ); },
            'presshub_ai_temperature_openai'          => [ __CLASS__, 'sanitize_temperature' ],
            'presshub_ai_max_tokens_openai'           => [ __CLASS__, 'sanitize_max_tokens' ],
            'presshub_ai_timeout_openai'              => [ __CLASS__, 'sanitize_timeout' ],
            'presshub_ai_model_anthropic'             => function( $v ) { return PressHub_AI_Settings_Storage::sanitize_model( $v, 'anthropic' ); },
            'presshub_ai_temperature_anthropic'       => [ __CLASS__, 'sanitize_temperature' ],
            'presshub_ai_max_tokens_anthropic'        => [ __CLASS__, 'sanitize_max_tokens' ],
            'presshub_ai_timeout_anthropic'           => [ __CLASS__, 'sanitize_timeout' ],
            'presshub_ai_model_gemini'                => function( $v ) { return PressHub_AI_Settings_Storage::sanitize_model( $v, 'gemini' ); },
            'presshub_ai_temperature_gemini'          => [ __CLASS__, 'sanitize_temperature' ],
            'presshub_ai_max_tokens_gemini'           => [ __CLASS__, 'sanitize_max_tokens' ],
            'presshub_ai_timeout_gemini'              => [ __CLASS__, 'sanitize_timeout' ],
            'presshub_ai_openai_org'                  => [ __CLASS__, 'sanitize_openai_org' ],
            'presshub_ai_anthropic_version'           => [ __CLASS__, 'sanitize_anthropic_version' ],
        ];

        switch ( $section ) {
            case 'coauthor':
                return $coauthor_map;
            case 'briefing':
                return $briefing_map;
            case 'copilot':
                return $copilot_map;
            case 'advanced':
                return $advanced_map;
            case 'general':
                return $general_map;
            case 'providers':
                return $providers_map;
            case 'all':
            case '':
            default:
                return array_merge(
                    $general_map,
                    $providers_map,
                    $coauthor_map,
                    $briefing_map,
                    $copilot_map,
                    $advanced_map
                );
        }
    }

    /**
     * Record an audit log entry for saved configuration settings.
     *
     * @param array  $updated_keys List of updated option keys.
     * @param int    $count        Count of updated options.
     * @param string $section      Section or tab name.
     * @return int|null Inserted audit log ID.
     */
    public static function log_settings_saved( array $updated_keys = [], int $count = 0, string $section = 'presshub_ai_options' ): ?int {
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            return PressHub_AI_Audit_Logger::log(
                'settings_saved',
                'settings',
                $section,
                [
                    'section'       => $section,
                    'options_count' => $count ?: count( $updated_keys ),
                    'updated_keys'  => $updated_keys,
                ]
            );
        }
        return null;
    }
}

