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

    public static function register_options(): void {
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
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_sources', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_sources' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_harvest_time', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_harvest_time' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_generation_time', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_generation_time' ],
            'type'              => 'string',
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
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_host_female', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_host_female' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_host_male', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_host_male' ],
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
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_status', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_status' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_text_prompt', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_prompt' ],
            'type'              => 'string',
        ] );
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_podcast_prompt', [
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
        add_settings_field( 'presshub_ai_briefing_harvest_time', __( 'Morning Harvest Time (HH:MM)', 'presshub-ai-editor' ), [ $render, 'render_briefing_harvest_time_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_generation_time', __( 'Briefing Generation Time (HH:MM)', 'presshub-ai-editor' ), [ $render, 'render_briefing_generation_time_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_preset', __( 'Text Story Preset', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_preset_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_preset', __( 'Podcast Dialogue Preset', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_preset_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_target_duration', __( 'Target Podcast Duration', 'presshub-ai-editor' ), [ $render, 'render_briefing_target_duration_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_host_female', __( 'Female Host Name', 'presshub-ai-editor' ), [ $render, 'render_briefing_host_female_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_host_male', __( 'Male Host Name', 'presshub-ai-editor' ), [ $render, 'render_briefing_host_male_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_female', __( 'Female Voice Model (TTS)', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_female_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_male', __( 'Male Voice Model (TTS)', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_male_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_speed', __( 'Voice Speaking Rate / Speed', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_speed_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_pitch', __( 'Voice Pitch Tuning', 'presshub-ai-editor' ), [ $render, 'render_briefing_voice_pitch_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_style', __( 'Speaking Delivery Style', 'presshub-ai-editor' ), [ $render, 'render_briefing_tts_style_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_custom_style', __( 'Custom Speaking Style Prompt', 'presshub-ai-editor' ), [ $render, 'render_briefing_tts_custom_style_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_category', __( 'Text Briefing Category', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_category_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_category', __( 'Podcast Category', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_category_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_status', __( 'Text Briefing Post Status', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_status_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_status', __( 'Podcast Post Status', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_status_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_prompt', __( 'Text Story System Prompt', 'presshub-ai-editor' ), [ $render, 'render_briefing_text_prompt_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_prompt', __( 'Podcast Dialogue System Prompt', 'presshub-ai-editor' ), [ $render, 'render_briefing_podcast_prompt_field' ], 'presshub-ai', 'presshub_ai_briefing' );
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

    public static function sanitize_briefing_sources( $value ) {
        $value = wp_unslash( $value );
        $is_array = is_array( $value );
        $items = $is_array ? $value : preg_split( '/[\r\n,]+/', (string) $value );
        $clean = [];
        foreach ( (array) $items as $item ) {
            if ( ! is_string( $item ) ) {
                continue;
            }
            $item = trim( strip_tags( $item ) );
            if ( '' === $item ) {
                continue;
            }
            if ( preg_match( '/^https?:\/\/[^\s]+$/i', $item ) ) {
                $clean[] = $item;
            }
        }
        $clean = array_values( array_unique( $clean ) );
        return $is_array ? $clean : implode( "\n", $clean );
    }

    public static function sanitize_harvest_time( $value ) {
        return self::sanitize_time_format( $value, self::default_briefing_harvest_time() );
    }

    public static function sanitize_generation_time( $value ) {
        return self::sanitize_time_format( $value, self::default_briefing_generation_time() );
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

    public static function sanitize_briefing_tts_engine( $value ): string {
        $clean = is_string( $value ) ? sanitize_text_field( trim( $value ) ) : '';
        return in_array( $clean, [ 'gemini', 'google_cloud' ], true ) ? $clean : self::default_briefing_tts_engine();
    }

    public static function sanitize_briefing_tts_model( $value ): string {
        $clean = self::sanitize_text( $value );
        return '' !== $clean ? substr( $clean, 0, 100 ) : self::default_briefing_tts_model();
    }

    public static function sanitize_voice_female( $value ): string {
        $allowed = [
            'Aoede',
            'Kore',
            'Leda',
            'Callirrhoe',
            'Autonoe',
            'el-GR-Wavenet-A',
            'el-GR-Chirp3-HD-Aoede',
            'el-GR-Chirp3-HD-Achernar',
            'el-GR-Standard-A',
            'el-GR-Neural2-A',
        ];
        $value = trim( (string) wp_unslash( $value ) );
        if ( 'el-GR-Neural2-A' === $value ) {
            return 'el-GR-Wavenet-A';
        }
        return in_array( $value, $allowed, true ) ? $value : self::default_briefing_voice_female();
    }

    public static function sanitize_voice_male( $value ): string {
        $allowed = [
            'Fenrir',
            'Puck',
            'Charon',
            'Zephyr',
            'Orus',
            'el-GR-Chirp3-HD-Achird',
            'el-GR-Chirp3-HD-Algenib',
            'el-GR-Chirp3-HD-Algieba',
            'el-GR-Chirp3-HD-Alnilam',
            'el-GR-Wavenet-B',
            'el-GR-Standard-B',
            'el-GR-Neural2-B',
        ];
        $value = trim( (string) wp_unslash( $value ) );
        if ( in_array( $value, [ 'el-GR-Neural2-B', 'el-GR-Wavenet-B', 'el-GR-Standard-B' ], true ) ) {
            return 'el-GR-Chirp3-HD-Achird';
        }
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
}

