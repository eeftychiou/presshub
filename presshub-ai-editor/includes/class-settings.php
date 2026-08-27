<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-provider-defaults.php';

/**
 * PressHub AI Editor settings page (P1-P6 overhaul).
 *
 * - P1: Settings-API sectioning (General / Providers / Media / Rate Limits)
 *       + a help tab.
 * - P2: per-provider model + tuning options (presshub_ai_model_openai,
 *       presshub_ai_temperature_*, presshub_ai_max_tokens_*,
 *       presshub_ai_timeout_*) with an idempotent migration of the legacy
 *       presshub_ai_model option into the active provider's key.
 * - P3: capability gate via the presshub_ai_settings_cap filter
 *       (default 'manage_options') on the page render.
 * - P4: masked rendering of API keys (value + placeholder show only the
 *       last 4 characters).
 * - P5: a sanitize_callback on every registered option.
 * - P6: provider extras (presshub_ai_openai_org, presshub_ai_anthropic_version,
 *       presshub_ai_imagen_region).
 */
class PressHub_AI_Settings {

    const PROVIDERS = [ 'openai', 'anthropic', 'gemini' ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        // Runs on plugins_loaded (settings are constructed there); guarded
        // by presshub_ai_migrated_models so it is a no-op on every load
        // after the first.
        self::migrate_legacy_model();
    }

    public function enqueue_scripts( $hook ) {
        if ( 'settings_page_presshub-ai' === $hook ) {
            wp_enqueue_style( 'presshub-ai-admin-css', PRESSHUB_AI_URL . 'assets/admin.css', [], PRESSHUB_AI_VERSION );
            wp_enqueue_script( 'presshub-ai-admin-js', PRESSHUB_AI_URL . 'assets/admin.js', [ 'jquery', 'wp-i18n' ], PRESSHUB_AI_VERSION, true );
            wp_localize_script( 'presshub-ai-admin-js', 'presshubAI', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'presshub_ai_nonce' )
            ] );
        }
    }

    /**
     * P3: the capability required to view/edit PressHub AI settings,
     * filterable for sites that delegate settings to a custom role.
     */
    public function settings_cap(): string {
        return (string) apply_filters( 'presshub_ai_settings_cap', 'manage_options' );
    }

    public function add_settings_page() {
        add_options_page(
            __( 'PressHub AI Settings', 'presshub-ai-editor' ),
            __( 'PressHub AI', 'presshub-ai-editor' ),
            $this->settings_cap(),
            'presshub-ai',
            [ $this, 'render_settings_page' ]
        );
    }

    // ------------------------------------------------------------------
    // Defaults (single source of truth: class-provider-defaults.php).
    // Thin delegators keep the public static API stable for callers.
    // ------------------------------------------------------------------

    public static function default_model( $provider ): string {
        return PressHub_AI_Provider_Defaults::default_model( $provider );
    }

    public static function default_temperature(): float {
        return PressHub_AI_Provider_Defaults::default_temperature();
    }

    public static function default_max_tokens(): int {
        return PressHub_AI_Provider_Defaults::default_max_tokens();
    }

    public static function default_timeout( $provider ): int {
        return PressHub_AI_Provider_Defaults::default_timeout( $provider );
    }

    public static function default_briefing_sources(): array {
        return [
            'https://www.kathimerini.gr',
            'https://www.tovima.gr',
            'https://www.naftemporiki.gr',
            'https://www.in.gr',
            'https://www.news247.gr',
        ];
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
        return 'Aoede';
    }

    public static function default_briefing_voice_male(): string {
        return 'Fenrir';
    }


    // ------------------------------------------------------------------
    // P2: legacy model migration (idempotent).
    // ------------------------------------------------------------------

    /**
     * Copy the legacy presshub_ai_model option into the ACTIVE provider's
     * per-provider key, but only when that key is empty (so an admin's
     * existing per-provider value is never overwritten). Runs once:
     * guarded by the presshub_ai_migrated_models option.
     */
    public static function migrate_legacy_model(): void {
        if ( get_option( 'presshub_ai_migrated_models' ) ) {
            return;
        }

        $legacy = (string) get_option( 'presshub_ai_model', '' );
        if ( '' !== $legacy ) {
            $provider = (string) get_option( 'presshub_ai_provider', 'openai' );
            if ( in_array( $provider, self::PROVIDERS, true ) ) {
                $key     = 'presshub_ai_model_' . $provider;
                $current = (string) get_option( $key, '' );
                if ( '' === $current ) {
                    update_option( $key, $legacy );
                }
            }
        }

        update_option( 'presshub_ai_migrated_models', 1 );
    }

    // ------------------------------------------------------------------
    // P1 + P2 + P5 + P6: option registration.
    // ------------------------------------------------------------------

    public function register_settings() {
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
        register_setting( 'presshub_ai_options', 'presshub_ai_briefing_tts_engine', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_briefing_tts_engine' ],
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

        // --- P1: sections ---
        add_settings_section( 'presshub_ai_general', __( 'General', 'presshub-ai-editor' ), [ $this, 'render_general_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_providers', __( 'Providers', 'presshub-ai-editor' ), [ $this, 'render_providers_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_media', __( 'Media (Google Cloud)', 'presshub-ai-editor' ), [ $this, 'render_media_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_rate_limits', __( 'Rate Limits', 'presshub-ai-editor' ), [ $this, 'render_rate_limits_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_briefing', __( 'Daily Briefing & AI Podcast', 'presshub-ai-editor' ), [ $this, 'render_briefing_section' ], 'presshub-ai' );

        // --- P1: fields ---
        add_settings_field( 'presshub_ai_provider', __( 'AI Provider', 'presshub-ai-editor' ), [ $this, 'render_provider_field' ], 'presshub-ai', 'presshub_ai_general' );
        add_settings_field( 'presshub_ai_fetch_urls', __( 'Fetch source URLs', 'presshub-ai-editor' ), [ $this, 'render_fetch_urls_field' ], 'presshub-ai', 'presshub_ai_general' );
        add_settings_field( 'presshub_ai_debug_prompts', __( 'Log AI prompts', 'presshub-ai-editor' ), [ $this, 'render_debug_prompts_field' ], 'presshub-ai', 'presshub_ai_general' );

        add_settings_field( 'presshub_ai_api_key', __( 'API Key', 'presshub-ai-editor' ), [ $this, 'render_api_key_field' ], 'presshub-ai', 'presshub_ai_providers' );
        foreach ( self::PROVIDERS as $provider ) {
            $label = ucfirst( $provider );
            add_settings_field( 'presshub_ai_model_' . $provider, sprintf( __( 'Model (%s)', 'presshub-ai-editor' ), $label ), [ $this, 'render_model_field' ], 'presshub-ai', 'presshub_ai_providers', [ 'provider' => $provider ] );
            add_settings_field( 'presshub_ai_temperature_' . $provider, sprintf( __( 'Temperature (%s)', 'presshub-ai-editor' ), $label ), [ $this, 'render_temperature_field' ], 'presshub-ai', 'presshub_ai_providers', [ 'provider' => $provider ] );
            add_settings_field( 'presshub_ai_max_tokens_' . $provider, sprintf( __( 'Max Tokens (%s)', 'presshub-ai-editor' ), $label ), [ $this, 'render_max_tokens_field' ], 'presshub-ai', 'presshub_ai_providers', [ 'provider' => $provider ] );
            add_settings_field( 'presshub_ai_timeout_' . $provider, sprintf( __( 'Timeout (%s)', 'presshub-ai-editor' ), $label ), [ $this, 'render_timeout_field' ], 'presshub-ai', 'presshub_ai_providers', [ 'provider' => $provider ] );
        }
        add_settings_field( 'presshub_ai_openai_org', __( 'OpenAI Organization ID (optional)', 'presshub-ai-editor' ), [ $this, 'render_openai_org_field' ], 'presshub-ai', 'presshub_ai_providers' );
        add_settings_field( 'presshub_ai_anthropic_version', __( 'Anthropic API Version', 'presshub-ai-editor' ), [ $this, 'render_anthropic_version_field' ], 'presshub-ai', 'presshub_ai_providers' );
        add_settings_field( 'presshub_ai_github_token', __( 'GitHub Token (optional)', 'presshub-ai-editor' ), [ $this, 'render_github_token_field' ], 'presshub-ai', 'presshub_ai_providers' );

        add_settings_field( 'presshub_ai_google_cloud_api_key', __( 'Google Cloud API Key (Imagen/TTS)', 'presshub-ai-editor' ), [ $this, 'render_google_cloud_api_key_field' ], 'presshub-ai', 'presshub_ai_media' );
        add_settings_field( 'presshub_ai_gcloud_project_id', __( 'Google Cloud Project ID (Imagen)', 'presshub-ai-editor' ), [ $this, 'render_gcloud_project_id_field' ], 'presshub-ai', 'presshub_ai_media' );
        add_settings_field( 'presshub_ai_imagen_region', __( 'Imagen Region', 'presshub-ai-editor' ), [ $this, 'render_imagen_region_field' ], 'presshub-ai', 'presshub_ai_media' );

        add_settings_field( 'presshub_ai_rate_limit_enabled', __( 'Enable Per-User Rate Limit', 'presshub-ai-editor' ), [ $this, 'render_rate_limit_enabled_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );
        add_settings_field( 'presshub_ai_rate_limit_per_hour', __( 'Requests per Window', 'presshub-ai-editor' ), [ $this, 'render_rate_limit_per_hour_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );
        add_settings_field( 'presshub_ai_rate_limit_window_seconds', __( 'Window Length (seconds)', 'presshub-ai-editor' ), [ $this, 'render_rate_limit_window_seconds_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );
        add_settings_field( 'presshub_ai_research_retention_days', __( 'Research Log Retention (days)', 'presshub-ai-editor' ), [ $this, 'render_research_retention_days_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );
        add_settings_field( 'presshub_ai_log_level', __( 'Diagnostic Log Level', 'presshub-ai-editor' ), [ $this, 'render_log_level_field' ], 'presshub-ai', 'presshub_ai_rate_limits' );

        add_settings_field( 'presshub_ai_briefing_sources', __( 'News Source URLs', 'presshub-ai-editor' ), [ $this, 'render_briefing_sources_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_engine', __( 'Voice Synthesis Engine', 'presshub-ai-editor' ), [ $this, 'render_briefing_tts_engine_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_tts_model', __( 'Voice Generation AI Model', 'presshub-ai-editor' ), [ $this, 'render_briefing_tts_model_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_harvest_time', __( 'Morning Harvest Time (HH:MM)', 'presshub-ai-editor' ), [ $this, 'render_briefing_harvest_time_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_generation_time', __( 'Briefing Generation Time (HH:MM)', 'presshub-ai-editor' ), [ $this, 'render_briefing_generation_time_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_preset', __( 'Text Story Preset', 'presshub-ai-editor' ), [ $this, 'render_briefing_text_preset_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_preset', __( 'Podcast Dialogue Preset', 'presshub-ai-editor' ), [ $this, 'render_briefing_podcast_preset_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_target_duration', __( 'Target Podcast Duration', 'presshub-ai-editor' ), [ $this, 'render_briefing_target_duration_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_host_female', __( 'Female Host Name', 'presshub-ai-editor' ), [ $this, 'render_briefing_host_female_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_host_male', __( 'Male Host Name', 'presshub-ai-editor' ), [ $this, 'render_briefing_host_male_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_female', __( 'Female Voice Model (TTS)', 'presshub-ai-editor' ), [ $this, 'render_briefing_voice_female_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_male', __( 'Male Voice Model (TTS)', 'presshub-ai-editor' ), [ $this, 'render_briefing_voice_male_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_speed', __( 'Voice Speaking Rate / Speed', 'presshub-ai-editor' ), [ $this, 'render_briefing_voice_speed_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_voice_pitch', __( 'Voice Pitch Tuning', 'presshub-ai-editor' ), [ $this, 'render_briefing_voice_pitch_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_category', __( 'Text Briefing Category', 'presshub-ai-editor' ), [ $this, 'render_briefing_text_category_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_category', __( 'Podcast Category', 'presshub-ai-editor' ), [ $this, 'render_briefing_podcast_category_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_status', __( 'Text Briefing Post Status', 'presshub-ai-editor' ), [ $this, 'render_briefing_text_status_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_status', __( 'Podcast Post Status', 'presshub-ai-editor' ), [ $this, 'render_briefing_podcast_status_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_text_prompt', __( 'Text Story System Prompt', 'presshub-ai-editor' ), [ $this, 'render_briefing_text_prompt_field' ], 'presshub-ai', 'presshub_ai_briefing' );
        add_settings_field( 'presshub_ai_briefing_podcast_prompt', __( 'Podcast Dialogue System Prompt', 'presshub-ai-editor' ), [ $this, 'render_briefing_podcast_prompt_field' ], 'presshub-ai', 'presshub_ai_briefing' );
    }


    // ------------------------------------------------------------------
    // P3: page render + P1 help tab.
    // ------------------------------------------------------------------

    public function render_settings_page() {
        if ( ! current_user_can( $this->settings_cap() ) ) {
            wp_die( __( 'You do not have permission to view PressHub AI settings.', 'presshub-ai-editor' ) );
        }

        $this->register_help_tab();

        if ( function_exists( 'settings_errors' ) ) {
            settings_errors();
        }
        ?>
        <div class="wrap presshub-ai-settings-wrap">
            <h1><?php echo __( 'PressHub AI Settings', 'presshub-ai-editor' ); ?></h1>

            <nav class="nav-tab-wrapper wp-clearfix" id="presshub-ai-settings-tabs" style="margin-bottom: 20px;">
                <a href="#general" class="nav-tab nav-tab-active" data-tab="general"><?php echo __( 'General & Models', 'presshub-ai-editor' ); ?></a>
                <a href="#media" class="nav-tab" data-tab="media"><?php echo __( 'Media & Voice (Google Cloud)', 'presshub-ai-editor' ); ?></a>
                <a href="#briefing" class="nav-tab" data-tab="briefing"><?php echo __( 'Daily Briefing & AI Podcast', 'presshub-ai-editor' ); ?></a>
                <a href="#rate_limits" class="nav-tab" data-tab="rate_limits"><?php echo __( 'Rate Limits & Retention', 'presshub-ai-editor' ); ?></a>
                <a href="#diagnostics" class="nav-tab" data-tab="diagnostics"><?php echo __( 'Connection Diagnostics', 'presshub-ai-editor' ); ?></a>
            </nav>

            <form method="post" action="options.php" id="presshub-ai-settings-form">
                <?php
                settings_fields( 'presshub_ai_options' );
                $GLOBALS['RENDERED_SECTIONS']['presshub-ai'] = true;
                ?>

                <div id="presshub-tab-pane-general" class="presshub-tab-pane">
                    <?php $this->render_section_with_fields( 'presshub_ai_general', __( 'General', 'presshub-ai-editor' ) ); ?>
                    <?php $this->render_section_with_fields( 'presshub_ai_providers', __( 'Providers', 'presshub-ai-editor' ) ); ?>
                </div>

                <div id="presshub-tab-pane-media" class="presshub-tab-pane" style="display: none;">
                    <?php $this->render_section_with_fields( 'presshub_ai_media', __( 'Media (Google Cloud)', 'presshub-ai-editor' ) ); ?>
                </div>

                <div id="presshub-tab-pane-briefing" class="presshub-tab-pane" style="display: none;">
                    <?php $this->render_section_with_fields( 'presshub_ai_briefing', __( 'Daily Briefing & AI Podcast', 'presshub-ai-editor' ) ); ?>
                </div>

                <div id="presshub-tab-pane-rate_limits" class="presshub-tab-pane" style="display: none;">
                    <?php $this->render_section_with_fields( 'presshub_ai_rate_limits', __( 'Rate Limits & Retention', 'presshub-ai-editor' ) ); ?>
                </div>

                <div class="presshub-settings-submit-wrap" style="margin-top: 20px; display: flex; align-items: center; gap: 10px;">
                    <?php if ( function_exists( 'submit_button' ) ) { submit_button( __( 'Save Changes', 'presshub-ai-editor' ), 'primary', 'submit', false ); } ?>
                    <span id="presshub-ai-save-spinner" class="spinner" role="status" style="float: none; margin: 0;"><span class="screen-reader-text"></span></span>
                </div>
            </form>

            <div id="presshub-tab-pane-diagnostics" class="presshub-tab-pane" style="display: none;">
                <h2><?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?></h2>
                <p><?php echo __( 'Save your settings first, then verify each provider separately. Each test uses its own rate-limit budget so testing never burns your AI allowance.', 'presshub-ai-editor' ); ?></p>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button type="button" id="presshub-ai-test-api" class="button presshub-ai-test-api" data-provider=""><?php echo __( 'Test Active Provider', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button presshub-ai-test-api" data-provider="openai"><?php echo __( 'Test OpenAI', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button presshub-ai-test-api" data-provider="anthropic"><?php echo __( 'Test Anthropic', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button presshub-ai-test-api" data-provider="gemini"><?php echo __( 'Test Gemini', 'presshub-ai-editor' ); ?></button>
                    <span id="presshub-ai-test-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
                </div>
                <div id="presshub-ai-test-result" style="margin-top: 15px; font-weight: bold;"></div>

                <hr style="margin: 25px 0;">
                <h2><?php echo __( 'Diagnostic Logs', 'presshub-ai-editor' ); ?></h2>
                <p><?php echo __( 'View recent internal diagnostic logs for debugging scraping, API calls, prompt hydration, and background jobs.', 'presshub-ai-editor' ); ?></p>
                <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;">
                    <button type="button" id="presshub-ai-refresh-logs" class="button button-secondary"><?php echo __( 'Refresh Logs', 'presshub-ai-editor' ); ?></button>
                    <button type="button" id="presshub-ai-clear-logs" class="button button-secondary"><?php echo __( 'Clear Logs', 'presshub-ai-editor' ); ?></button>
                    <span id="presshub-ai-log-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
                    <span id="presshub-ai-log-status" style="margin-left: 10px; color: #666;"></span>
                </div>
                <textarea id="presshub-ai-log-viewer" rows="16" class="large-text code" readonly="readonly" style="font-size: 12px; background: #1e1e1e; color: #d4d4d4; font-family: monospace;" placeholder="<?php echo esc_attr__( 'Click "Refresh Logs" to load diagnostic entries...', 'presshub-ai-editor' ); ?>"></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * P1/P9: add a help tab to the settings screen. Uses the WP_Screen
     * method when available (real WP); falls back to the global
     * add_help_tab() shim in the unit-test harness.
     */
    private function register_help_tab(): void {
        $args = [
            'id'      => 'presshub-ai-help',
            'title'   => __( 'PressHub AI Help', 'presshub-ai-editor' ),
            'content' => '<p>' . __( 'PressHub AI settings: choose a provider under General, then configure the per-provider model and tuning under Providers. Keys are shown masked — only the last 4 characters are displayed; leave a key field empty to keep the saved key. Media (Google Cloud) configures Imagen/TTS. Rate Limits caps per-user AI costs.', 'presshub-ai-editor' ) . '</p>',
        ];
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && is_object( $screen ) && method_exists( $screen, 'add_help_tab' ) ) {
                $screen->add_help_tab( $args );
                return;
            }
        }
        add_help_tab( $args );
    }

    // ------------------------------------------------------------------
    // Section descriptions & renderers.
    // ------------------------------------------------------------------

    public function render_section_with_fields( string $section_id, string $title ) {
        echo '<h2 id="wp-settings-section-' . esc_attr( $section_id ) . '">' . esc_html( $title ) . '</h2>';
        $callback_suffix = str_replace( 'presshub_ai_', '', $section_id );
        $callback = [ $this, 'render_' . $callback_suffix . '_section' ];
        if ( is_callable( $callback ) ) {
            call_user_func( $callback );
        }
        echo '<table class="form-table" role="presentation"><tbody>';
        if ( function_exists( 'do_settings_fields' ) ) {
            do_settings_fields( 'presshub-ai', $section_id );
        }
        echo '</tbody></table>';
    }

    public function render_general_section() {
        echo '<p>' . __( 'Choose which AI provider powers drafts, scorecards, chat and research.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_providers_section() {
        echo '<p>' . __( 'Each provider keeps its own model and tuning so switching providers never reuses another provider\'s model name.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_media_section() {
        echo '<p>' . __( 'Google Cloud credentials used for Imagen image generation and Text-to-Speech.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_rate_limits_section() {
        echo '<p>' . __( 'Cap the AI endpoints so a single user cannot rack up unbounded API costs. Disabled by default — opt-in only.', 'presshub-ai-editor' ) . '</p>';
    }

    // ------------------------------------------------------------------
    // Field renderers.
    // ------------------------------------------------------------------

    public function render_provider_field() {
        $provider = (string) get_option( 'presshub_ai_provider', 'openai' );
        ?>
        <select name="presshub_ai_provider" id="presshub_ai_provider">
            <option value="openai" <?php echo 'openai' === $provider ? 'selected="selected"' : ''; ?>><?php echo __( 'OpenAI', 'presshub-ai-editor' ); ?></option>
            <option value="anthropic" <?php echo 'anthropic' === $provider ? 'selected="selected"' : ''; ?>><?php echo __( 'Anthropic', 'presshub-ai-editor' ); ?></option>
            <option value="gemini" <?php echo 'gemini' === $provider ? 'selected="selected"' : ''; ?>><?php echo __( 'Google Gemini', 'presshub-ai-editor' ); ?></option>
        </select>
        <?php
    }

    /**
     * 1.2.4: fetch source URLs server-side before prompting. Models cannot
     * browse URLs, so each source URL in the draft request is fetched and
     * its article text is injected into the prompt (class-url-fetcher.php).
     */
    public function render_fetch_urls_field() {
        $enabled = get_option( 'presshub_ai_fetch_urls', '1' ) === '1';
        ?>
        <label for="presshub_ai_fetch_urls">
            <input type="checkbox" name="presshub_ai_fetch_urls" id="presshub_ai_fetch_urls" value="1" <?php echo $enabled ? 'checked="checked"' : ''; ?> />
            <?php echo esc_html__( 'Fetch and extract source URLs server-side before prompting (recommended — models cannot browse web pages).', 'presshub-ai-editor' ); ?>
        </label>
        <?php
    }

    /**
     * 1.2.5: prompt inspection toggle. Writes the exact SYSTEM and USER
     * prompts to wp-content/uploads/presshub-ai-debug.log (findable via
     * the host file manager — no wp-config or PHP error log hunting).
     */
    public function render_debug_prompts_field() {
        $enabled = get_option( 'presshub_ai_debug_prompts', '0' ) === '1';
        ?>
        <label for="presshub_ai_debug_prompts">
            <input type="checkbox" name="presshub_ai_debug_prompts" id="presshub_ai_debug_prompts" value="1" <?php echo $enabled ? 'checked="checked"' : ''; ?> />
            <?php echo esc_html__( 'Append every SYSTEM and USER prompt to wp-content/uploads/presshub-ai-debug.log (debugging only — disable in production).', 'presshub-ai-editor' ); ?>
        </label>
        <?php
    }

    /**
     * P4: render a masked key input. The raw key is never placed in the
     * DOM; only the last 4 characters are shown (in both the value and the
     * placeholder). The sanitize callback maps the masked/empty value back
     * to the saved key, so saving the form without touching the field keeps
     * the existing key.
     */
    public function render_api_key_field() {
        $saved = (string) get_option( 'presshub_ai_api_key', '' );
        $mask  = self::mask_key( $saved );
        ?>
        <input type="password" name="presshub_ai_api_key" id="presshub_ai_api_key" value="<?php echo self::esc_attr_safe( $mask ); ?>" placeholder="<?php echo self::esc_attr_safe( $mask ); ?>" class="regular-text" autocomplete="off" />
        <?php if ( '' !== $mask ) : ?>
            <p class="description"><?php echo __( 'Saved key ends in', 'presshub-ai-editor' ); ?> <code><?php echo self::esc_html_safe( $mask ); ?></code>. <?php echo __( 'Leave empty to keep it.', 'presshub-ai-editor' ); ?></p>
            <label>
                <input type="checkbox" name="presshub_ai_remove_api_key" id="presshub_ai_remove_api_key" value="1" />
                <?php echo __( 'Remove stored key', 'presshub-ai-editor' ); ?>
            </label>
        <?php else : ?>
            <p class="description"><?php echo __( 'Used for the active AI provider (OpenAI, Anthropic or Gemini).', 'presshub-ai-editor' ); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_model_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_model_' . $provider;
        $value    = (string) get_option( $option, self::default_model( $provider ) );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Default:', 'presshub-ai-editor' ); ?> <code><?php echo self::esc_html_safe( self::default_model( $provider ) ); ?></code></p>
        <?php
    }

    public function render_temperature_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_temperature_' . $provider;
        $value    = get_option( $option, self::default_temperature() );
        ?>
        <input type="number" min="0" max="2" step="0.1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Sampling temperature (0-2). Default 0.7. Intent classification and audio scripts are locked to 0.0 for determinism.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_max_tokens_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_max_tokens_' . $provider;
        $value    = (int) get_option( $option, self::default_max_tokens() );
        ?>
        <input type="number" min="1" max="32768" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Maximum tokens per response (1-32768). Default 10000.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_timeout_field( $args = [] ) {
        $provider = $args['provider'] ?? 'openai';
        $option   = 'presshub_ai_timeout_' . $provider;
        $value    = (int) get_option( $option, self::default_timeout( $provider ) );
        ?>
        <input type="number" min="5" max="300" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Request timeout in seconds (5-300). Default', 'presshub-ai-editor' ); ?> <?php echo (int) self::default_timeout( $provider ); ?>.</p>
        <?php
    }

    public function render_openai_org_field() {
        $option = 'presshub_ai_openai_org';
        $value  = (string) get_option( $option, '' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Optional. Sent as the OpenAI-Organization header. Only needed for multi-org accounts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_anthropic_version_field() {
        $option = 'presshub_ai_anthropic_version';
        $value  = (string) get_option( $option, '2023-06-01' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'anthropic-version header. Default 2023-06-01.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_github_token_field() {
        $saved = (string) get_option( 'presshub_ai_github_token', '' );
        $mask  = self::mask_key( $saved );
        ?>
        <input type="password" name="presshub_ai_github_token" id="presshub_ai_github_token" value="<?php echo self::esc_attr_safe( $mask ); ?>" placeholder="<?php echo self::esc_attr_safe( $mask ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php echo __( 'GitHub Personal Access Token (PAT). Only required if the GitHub repository is private to enable automatic updates.', 'presshub-ai-editor' ); ?></p>
        <?php if ( '' !== $mask ) : ?>
            <label>
                <input type="checkbox" name="presshub_ai_remove_github_token" id="presshub_ai_remove_github_token" value="1" />
                <?php echo __( 'Remove stored key', 'presshub-ai-editor' ); ?>
            </label>
        <?php endif; ?>
        <?php
    }

    public function render_google_cloud_api_key_field() {
        $saved = (string) get_option( 'presshub_ai_google_cloud_api_key', '' );
        $mask  = self::mask_key( $saved );
        ?>
        <input type="password" name="presshub_ai_google_cloud_api_key" id="presshub_ai_google_cloud_api_key" value="<?php echo self::esc_attr_safe( $mask ); ?>" placeholder="<?php echo self::esc_attr_safe( $mask ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php echo __( 'Required for Google Cloud Imagen and Text-to-Speech integration.', 'presshub-ai-editor' ); ?></p>
        <?php if ( '' !== $mask ) : ?>
            <label>
                <input type="checkbox" name="presshub_ai_remove_google_cloud_api_key" id="presshub_ai_remove_google_cloud_api_key" value="1" />
                <?php echo __( 'Remove stored key', 'presshub-ai-editor' ); ?>
            </label>
        <?php endif; ?>
        <?php
    }

    public function render_gcloud_project_id_field() {
        $option = 'presshub_ai_gcloud_project_id';
        $value  = (string) get_option( $option, 'presshub-ai' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Google Cloud project ID used in the Vertex AI Imagen endpoint URL. Defaults to presshub-ai.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_imagen_region_field() {
        $option = 'presshub_ai_imagen_region';
        $value  = (string) get_option( $option, 'us-central1' );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Vertex AI region for Imagen. Default us-central1. European tenants may use e.g. europe-west4 for data residency.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_rate_limit_enabled_field() {
        $option = 'presshub_ai_rate_limit_enabled';
        $value  = (int) get_option( $option, 0 );
        ?>
        <input type="hidden" name="<?php echo self::esc_attr_safe( $option ); ?>" value="0" />
        <label>
            <input type="checkbox" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="1" <?php echo $value ? 'checked="checked"' : ''; ?> />
            <?php echo __( 'Throttle AI actions when a user exceeds the configured budget', 'presshub-ai-editor' ); ?>
        </label>
        <?php
    }

    public function render_rate_limit_per_hour_field() {
        $option = 'presshub_ai_rate_limit_per_hour';
        $value  = (int) get_option( $option, 30 );
        ?>
        <input type="number" min="1" max="10000" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Maximum number of AI actions (draft, review, chat, research) a single user may make within the window. Defaults to 30.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_rate_limit_window_seconds_field() {
        $option = 'presshub_ai_rate_limit_window_seconds';
        $value  = (int) get_option( $option, 3600 );
        ?>
        <input type="number" min="1" max="86400" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Length of the rolling window in seconds. Defaults to 3600 (1 hour).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_research_retention_days_field() {
        $option  = 'presshub_ai_research_retention_days';
        $default = class_exists( 'PressHub_AI_Research_Cleanup' )
            ? PressHub_AI_Research_Cleanup::DEFAULT_RETENTION_DAYS
            : 30;
        $value   = (int) get_option( $option, $default );
        ?>
        <input type="number" min="1" max="3650" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'How long completed or failed research logs are kept before the daily cleanup deletes them. Defaults to 30 days.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_log_level_field() {
        $option   = 'presshub_ai_log_level';
        $selected = class_exists( 'PressHub_AI_Logger' ) ? PressHub_AI_Logger::get_configured_level() : 'INFO';
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="DEBUG" <?php echo 'DEBUG' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'DEBUG (Verbose: all API payloads, scheduling, turns & HTTP)', 'presshub-ai-editor' ); ?></option>
            <option value="INFO" <?php echo 'INFO' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'INFO (Standard: milestones, settings updates, jobs)', 'presshub-ai-editor' ); ?></option>
            <option value="WARNING" <?php echo 'WARNING' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'WARNING (Only warnings & failures)', 'presshub-ai-editor' ); ?></option>
            <option value="ERROR" <?php echo 'ERROR' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'ERROR (Only critical failures)', 'presshub-ai-editor' ); ?></option>
            <option value="OFF" <?php echo 'OFF' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'OFF (Disable file logging)', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Control verbosity of the internal diagnostic log file (stored at wp-content/uploads/presshub-ai/presshub-debug.log).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_section() {
        echo '<p>' . __( 'Automated morning Greek news briefing text curation and multi-voice conversational podcast generation.', 'presshub-ai-editor' ) . '</p>';
    }

    public function render_briefing_sources_field() {
        $option  = 'presshub_ai_briefing_sources';
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $option, 'options' );
        }
        $sources = get_option( $option, '' );
        if ( is_array( $sources ) ) {
            $sources = implode( "\n", $sources );
        }
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="5" class="large-text code" placeholder="https://news.in.gr&#10;https://www.kathimerini.gr"><?php echo esc_textarea( (string) $sources ); ?></textarea>
        <p class="description"><?php echo __( 'Enter one Greek news homepage or RSS/article URL per line. Leave empty to use default outlets.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_harvest_time_field() {
        $option = 'presshub_ai_briefing_harvest_time';
        $value  = (string) get_option( $option, self::default_briefing_harvest_time() );
        ?>
        <input type="time" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Time when morning Greek news sources are scraped and snapshot saved. Default 06:30.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_generation_time_field() {
        $option = 'presshub_ai_briefing_generation_time';
        $value  = (string) get_option( $option, self::default_briefing_generation_time() );
        ?>
        <input type="time" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Time when text story curation and podcast synthesis are triggered. Default 07:15.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_text_preset_field() {
        $option   = 'presshub_ai_briefing_text_preset';
        $selected = (string) get_option( $option, '' );
        $presets  = class_exists( 'PressHub_AI_Preset_Store' ) ? PressHub_AI_Preset_Store::get_plugin_defaults() : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="" <?php echo '' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Standard Editorial (Default)', 'presshub-ai-editor' ); ?></option>
            <?php foreach ( $presets as $preset ) : ?>
                <?php if ( ! empty( $preset['enabled'] ) ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $preset['slug'] ); ?>" <?php echo $selected === $preset['slug'] ? 'selected="selected"' : ''; ?>>
                        <?php echo self::esc_html_safe( $preset['name'] ); ?> (<?php echo self::esc_html_safe( $preset['slug'] ); ?>)
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo __( 'Instruction preset applied to the morning text briefing curation agent.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_preset_field() {
        $option   = 'presshub_ai_briefing_podcast_preset';
        $selected = (string) get_option( $option, '' );
        $presets  = class_exists( 'PressHub_AI_Preset_Store' ) ? PressHub_AI_Preset_Store::get_plugin_defaults() : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="" <?php echo '' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Standard Conversational (Default)', 'presshub-ai-editor' ); ?></option>
            <?php foreach ( $presets as $preset ) : ?>
                <?php if ( ! empty( $preset['enabled'] ) ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $preset['slug'] ); ?>" <?php echo $selected === $preset['slug'] ? 'selected="selected"' : ''; ?>>
                        <?php echo self::esc_html_safe( $preset['name'] ); ?> (<?php echo self::esc_html_safe( $preset['slug'] ); ?>)
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo __( 'Instruction preset applied to the podcast producer dialogue agent.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_target_duration_field() {
        $option   = 'presshub_ai_briefing_target_duration';
        $selected = (string) get_option( $option, self::default_briefing_target_duration() );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="3_min" <?php echo '3_min' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( '3 Minutes (~450 words, 6-8 dialogue turns)', 'presshub-ai-editor' ); ?></option>
            <option value="5_min" <?php echo '5_min' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( '5 Minutes (~750 words, 12-15 dialogue turns)', 'presshub-ai-editor' ); ?></option>
            <option value="10_min" <?php echo '10_min' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( '10 Minutes (~1500 words, 20+ dialogue turns)', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Target duration and word budget pacing for daily podcast synthesis.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_tts_engine_field() {
        $option = 'presshub_ai_briefing_tts_engine';
        $value  = (string) get_option( $option, self::default_briefing_tts_engine() );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="gemini" <?php echo 'gemini' === $value ? 'selected="selected"' : ''; ?>>
                <?php echo esc_html__( 'Google AI Studio (Gemini 2.0 Flash Natural Voices) — Recommended', 'presshub-ai-editor' ); ?>
            </option>
            <option value="google_cloud" <?php echo 'google_cloud' === $value ? 'selected="selected"' : ''; ?>>
                <?php echo esc_html__( 'Google Cloud Text-to-Speech (Legacy TTS)', 'presshub-ai-editor' ); ?>
            </option>
        </select>
        <p class="description">
            <?php echo esc_html__( 'Google AI Studio produces natural, expressive Greek conversational speech using your existing Gemini API key. No separate TTS configuration needed.', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_tts_model_field() {
        $option = 'presshub_ai_briefing_tts_model';
        $value  = (string) get_option( $option, self::default_briefing_tts_model() );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text code" placeholder="gemini-3.1-flash-tts-preview" />
        <p class="description">
            <?php echo esc_html__( 'Model ID used for speech synthesis (e.g. gemini-3.1-flash-tts-preview, gemini-2.5-flash-preview-tts, or custom endpoint).', 'presshub-ai-editor' ); ?>
        </p>
        <?php
    }

    public function render_briefing_host_female_field() {
        $option = 'presshub_ai_briefing_host_female';
        $value  = (string) get_option( $option, self::default_briefing_host_female() );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Name of the lead female presenter (e.g. Μαρία). Default Μαρία.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_host_male_field() {
        $option = 'presshub_ai_briefing_host_male';
        $value  = (string) get_option( $option, self::default_briefing_host_male() );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text" />
        <p class="description"><?php echo __( 'Name of the co-host / male commentator (e.g. Νίκος). Default Νίκος.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_female_field() {
        $option   = 'presshub_ai_briefing_voice_female';
        $selected = (string) get_option( $option, self::default_briefing_voice_female() );
        $synthesizer = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? new PressHub_AI_Audio_Synthesizer() : null;
        $gemini_voices = $synthesizer ? ( $synthesizer->get_available_voices( 'gemini' )['female'] ?? [] ) : [];
        $gc_voices     = $synthesizer ? ( $synthesizer->get_available_voices( 'google_cloud' )['female'] ?? [] ) : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <optgroup label="<?php echo esc_attr__( 'Google AI Studio (Gemini Natural Voices)', 'presshub-ai-editor' ); ?>">
                <?php foreach ( $gemini_voices as $key => $v ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php echo $selected === $key ? 'selected="selected"' : ''; ?>>
                        <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
            <optgroup label="<?php echo esc_attr__( 'Google Cloud TTS Voices', 'presshub-ai-editor' ); ?>">
                <?php foreach ( $gc_voices as $key => $v ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php echo $selected === $key ? 'selected="selected"' : ''; ?>>
                        <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        </select>
        <p class="description"><?php echo __( 'Voice model used for the female host (Μαρία).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_male_field() {
        $option   = 'presshub_ai_briefing_voice_male';
        $selected = (string) get_option( $option, self::default_briefing_voice_male() );
        $synthesizer = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? new PressHub_AI_Audio_Synthesizer() : null;
        $gemini_voices = $synthesizer ? ( $synthesizer->get_available_voices( 'gemini' )['male'] ?? [] ) : [];
        $gc_voices     = $synthesizer ? ( $synthesizer->get_available_voices( 'google_cloud' )['male'] ?? [] ) : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <optgroup label="<?php echo esc_attr__( 'Google AI Studio (Gemini Natural Voices)', 'presshub-ai-editor' ); ?>">
                <?php foreach ( $gemini_voices as $key => $v ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php echo $selected === $key ? 'selected="selected"' : ''; ?>>
                        <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
            <optgroup label="<?php echo esc_attr__( 'Google Cloud TTS Voices', 'presshub-ai-editor' ); ?>">
                <?php foreach ( $gc_voices as $key => $v ) : ?>
                    <option value="<?php echo self::esc_attr_safe( $key ); ?>" <?php echo $selected === $key ? 'selected="selected"' : ''; ?>>
                        <?php echo self::esc_html_safe( $v['label'] ?? $key ); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        </select>
        <p class="description"><?php echo __( 'Voice model used for the male host (Νίκος).', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_speed_field() {
        $option = 'presshub_ai_briefing_voice_speed';
        $value  = (float) get_option( $option, 1.0 );
        ?>
        <input type="number" min="0.85" max="1.25" step="0.05" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Speaking rate multiplier (0.85 to 1.25). Default 1.00.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_voice_pitch_field() {
        $option = 'presshub_ai_briefing_voice_pitch';
        $value  = (float) get_option( $option, 0.0 );
        ?>
        <input type="number" min="-4.0" max="4.0" step="0.5" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Voice pitch adjustment (-4.0 to 4.0 semitones). Default 0.0.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_text_category_field() {
        $option   = 'presshub_ai_briefing_text_category';
        $selected = (int) get_option( $option, 0 );
        $terms    = function_exists( 'get_terms' ) ? get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] ) : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="0" <?php echo 0 === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'None (Default Category)', 'presshub-ai-editor' ); ?></option>
            <?php if ( ! is_wp_error( $terms ) && is_array( $terms ) ) : ?>
                <?php foreach ( $terms as $term ) : ?>
                    <?php if ( is_object( $term ) && isset( $term->term_id ) ) : ?>
                        <option value="<?php echo (int) $term->term_id; ?>" <?php echo $selected === (int) $term->term_id ? 'selected="selected"' : ''; ?>>
                            <?php echo self::esc_html_safe( $term->name ); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <p class="description"><?php echo __( 'WordPress post category assigned to text briefing posts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_category_field() {
        $option   = 'presshub_ai_briefing_podcast_category';
        $selected = (int) get_option( $option, 0 );
        $terms    = function_exists( 'get_terms' ) ? get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] ) : [];
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="0" <?php echo 0 === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'None (Default Category)', 'presshub-ai-editor' ); ?></option>
            <?php if ( ! is_wp_error( $terms ) && is_array( $terms ) ) : ?>
                <?php foreach ( $terms as $term ) : ?>
                    <?php if ( is_object( $term ) && isset( $term->term_id ) ) : ?>
                        <option value="<?php echo (int) $term->term_id; ?>" <?php echo $selected === (int) $term->term_id ? 'selected="selected"' : ''; ?>>
                            <?php echo self::esc_html_safe( $term->name ); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <p class="description"><?php echo __( 'WordPress post category assigned to synthesized podcast posts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_text_status_field() {
        $option   = 'presshub_ai_briefing_text_status';
        $selected = (string) get_option( $option, 'pending' );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="pending" <?php echo 'pending' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Pending Review', 'presshub-ai-editor' ); ?></option>
            <option value="draft" <?php echo 'draft' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Draft', 'presshub-ai-editor' ); ?></option>
            <option value="publish" <?php echo 'publish' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Publish Immediately', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Default status for newly generated morning text briefing posts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_status_field() {
        $option   = 'presshub_ai_briefing_podcast_status';
        $selected = (string) get_option( $option, 'pending' );
        ?>
        <select name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>">
            <option value="pending" <?php echo 'pending' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Pending Review', 'presshub-ai-editor' ); ?></option>
            <option value="draft" <?php echo 'draft' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Draft', 'presshub-ai-editor' ); ?></option>
            <option value="publish" <?php echo 'publish' === $selected ? 'selected="selected"' : ''; ?>><?php echo __( 'Publish Immediately', 'presshub-ai-editor' ); ?></option>
        </select>
        <p class="description"><?php echo __( 'Default status for newly generated podcast posts.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_text_prompt_field() {
        $option  = 'presshub_ai_briefing_text_prompt';
        $default = class_exists( 'PressHub_AI_News_Curator' )
            ? ( new PressHub_AI_News_Curator() )->get_default_curation_prompt()
            : '';
        $value   = (string) get_option( $option, '' );
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="6" class="large-text code" placeholder="<?php echo esc_attr( __( 'Leave empty to use standard Greek editorial curation prompt...', 'presshub-ai-editor' ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <p>
            <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="">
                <?php echo __( 'Clear / Reset Custom Prompt', 'presshub-ai-editor' ); ?>
            </button>
            <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="<?php echo self::esc_attr_safe( $default ); ?>">
                <?php echo __( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
            </button>
        </p>
        <p class="description"><?php echo __( 'Custom system prompt for Greek text story curation. Leave empty to use standard prompt. Supports placeholders: {date}, {sources_list}, {articles_count}, {articles_context}.', 'presshub-ai-editor' ); ?></p>
        <?php
    }

    public function render_briefing_podcast_prompt_field() {
        $option  = 'presshub_ai_briefing_podcast_prompt';
        $default = class_exists( 'PressHub_AI_Podcast_Producer' )
            ? ( new PressHub_AI_Podcast_Producer() )->get_default_dialogue_prompt()
            : '';
        $value   = (string) get_option( $option, '' );
        ?>
        <textarea name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" rows="6" class="large-text code" placeholder="<?php echo esc_attr( __( 'Leave empty to use standard Greek conversational podcast prompt...', 'presshub-ai-editor' ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <p>
            <button type="button" class="button button-secondary presshub-reset-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="">
                <?php echo __( 'Clear / Reset Custom Prompt', 'presshub-ai-editor' ); ?>
            </button>
            <button type="button" class="button button-secondary presshub-show-default-prompt" data-target="<?php echo self::esc_attr_safe( $option ); ?>" data-default="<?php echo self::esc_attr_safe( $default ); ?>">
                <?php echo __( 'Load Default Template for Editing', 'presshub-ai-editor' ); ?>
            </button>
        </p>
        <p class="description"><?php echo __( 'Custom system prompt for Greek podcast dialogue generation. Leave empty to use standard prompt. Supports placeholders: {date}, {sources_list}, {articles_context}, {duration_text}, {word_budget}, {host1_name}, {host2_name}.', 'presshub-ai-editor' ); ?></p>
        <?php
    }


    // ------------------------------------------------------------------
    // P4 helpers.
    // ------------------------------------------------------------------

    /**
     * Mask a secret for display: '••••' + the last 4 characters.
     */
    public static function mask_key( $key ): string {
        $key = (string) $key;
        if ( '' === $key ) {
            return '';
        }
        $len  = strlen( $key );
        $tail = $len >= 4 ? substr( $key, -4 ) : $key;
        return '••••' . $tail;
    }

    // ------------------------------------------------------------------
    // P5: sanitize callbacks.
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
        return max( 1, min( 32768, $n ) );
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
    /**
     * Re-entrancy guard for sanitize_secret to prevent infinite recursion when
     * update_option() triggers the registered sanitize_option filter.
     *
     * @var array<string, bool>
     */
    private static $sanitizing_secrets = [];

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
    // Escaping helpers: prefer the WP functions when present, fall back to
    // native htmlspecialchars in the unit-test harness (no WP loaded).
    // ------------------------------------------------------------------

    private static function esc_attr_safe( $text ) {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $text );
        }
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }

    private static function esc_html_safe( $text ) {
        if ( function_exists( 'esc_html' ) ) {
            return esc_html( $text );
        }
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}
