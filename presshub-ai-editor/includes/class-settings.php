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
        if ( 'settings_page_presshub-ai' === $hook || false !== strpos( (string) $hook, 'presshub-ai' ) ) {
            require_once __DIR__ . '/class-provider-defaults.php';
            require_once __DIR__ . '/class-provider-store.php';

            wp_enqueue_style( 'presshub-ai-admin-css', PRESSHUB_AI_URL . 'assets/admin.css', [], PRESSHUB_AI_VERSION );
            wp_enqueue_script( 'presshub-ai-admin-js', PRESSHUB_AI_URL . 'assets/admin.js', [ 'jquery', 'wp-i18n' ], PRESSHUB_AI_VERSION, true );
            wp_localize_script( 'presshub-ai-admin-js', 'presshubAI', [
                'ajax_url'             => admin_url( 'admin-ajax.php' ),
                'nonce'                => wp_create_nonce( 'presshub_ai_nonce' ),
                'provider_templates'   => PressHub_AI_Provider_Defaults::get_templates(),
                'configured_providers' => PressHub_AI_Provider_Store::get_all( false ),
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
        return 'gemini-2.0-flash';
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
        add_settings_field( 'presshub_ai_briefing_tts_api_key', __( 'Speech Generation API Key (Google AI Studio / Gemini)', 'presshub-ai-editor' ), [ $this, 'render_briefing_tts_api_key_field' ], 'presshub-ai', 'presshub_ai_briefing' );
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
    // P3: page render + P1 help tab + 6 Modular Tabs.
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
                <a href="#providers" class="nav-tab nav-tab-active" data-tab="providers"><?php echo __( 'AI Providers', 'presshub-ai-editor' ); ?></a>
                <a href="#coauthor" class="nav-tab" data-tab="coauthor"><?php echo __( 'AI Co-Author & Review', 'presshub-ai-editor' ); ?></a>
                <a href="#briefing" class="nav-tab" data-tab="briefing"><?php echo __( 'Daily Briefing Hub', 'presshub-ai-editor' ); ?></a>
                <a href="#copilot" class="nav-tab" data-tab="copilot"><?php echo __( 'AI Copilot & Assistant', 'presshub-ai-editor' ); ?></a>
                <a href="#token_logs" class="nav-tab" data-tab="token_logs"><?php echo __( 'Token & Usage Logs', 'presshub-ai-editor' ); ?></a>
                <a href="#advanced" class="nav-tab" data-tab="advanced"><?php echo __( 'Advanced & System', 'presshub-ai-editor' ); ?></a>
            </nav>

            <form method="post" action="options.php" id="presshub-ai-settings-form">
                <?php
                settings_fields( 'presshub_ai_options' );
                $GLOBALS['RENDERED_SECTIONS']['presshub-ai'] = true;
                ?>

                <!-- TAB 1: AI Providers Manager -->
                <div id="presshub-tab-pane-providers" class="presshub-tab-pane">
                    <?php $this->render_providers_grid(); ?>
                </div>

                <!-- TAB 2: AI Co-Author & Editorial Review -->
                <div id="presshub-tab-pane-coauthor" class="presshub-tab-pane" style="display: none;">
                    <h2><?php echo __( 'AI Co-Author & Editorial Review', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'Configure the AI engine, default model, and drafting behavior for draft generation and article reviews.', 'presshub-ai-editor' ); ?></p>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_coauthor_provider"><?php echo __( 'Active Co-Author Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_coauthor_prov = (string) get_option( 'presshub_ai_coauthor_provider', get_option( 'presshub_ai_provider', 'openai' ) ); ?>
                                    <select name="presshub_ai_coauthor_provider" id="presshub_ai_coauthor_provider" class="regular-text">
                                        <?php echo self::get_active_providers_options( $cur_coauthor_prov ); ?>
                                    </select>
                                    <p class="description"><?php echo __( 'Select which AI provider powers drafting and scorecard evaluation in the editor metabox.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_coauthor_model"><?php echo __( 'Custom Model Override', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_coauthor_model = (string) get_option( 'presshub_ai_coauthor_model', '' ); ?>
                                    <input type="text" name="presshub_ai_coauthor_model" id="presshub_ai_coauthor_model" value="<?php echo self::esc_attr_safe( $cur_coauthor_model ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default model', 'presshub-ai-editor' ); ?>" />
                                    <p class="description"><?php echo __( 'Optional specific model identifier for Co-Author (e.g. gpt-4o, claude-3-5-sonnet-20241022).', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_coauthor_max_tokens"><?php echo __( 'Max Completion Tokens', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_coauthor_tokens = (int) get_option( 'presshub_ai_coauthor_max_tokens', 10000 ); ?>
                                    <input type="number" min="1" max="32768" name="presshub_ai_coauthor_max_tokens" id="presshub_ai_coauthor_max_tokens" value="<?php echo self::esc_attr_safe( (string) $cur_coauthor_tokens ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'Maximum output tokens for generated drafts (1 - 32768). Default 10000.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_coauthor_temperature"><?php echo __( 'Sampling Temperature', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_coauthor_temp = get_option( 'presshub_ai_coauthor_temperature', '0.7' ); ?>
                                    <input type="number" min="0" max="2" step="0.05" name="presshub_ai_coauthor_temperature" id="presshub_ai_coauthor_temperature" value="<?php echo self::esc_attr_safe( (string) $cur_coauthor_temp ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'Creativity tuning for story drafting (0.0 to 2.0). Scorecards use deterministic 0.0.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_coauthor_timeout"><?php echo __( 'Request Timeout (seconds)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_coauthor_timeout = (int) get_option( 'presshub_ai_coauthor_timeout', 300 ); ?>
                                    <input type="number" min="5" max="300" name="presshub_ai_coauthor_timeout" id="presshub_ai_coauthor_timeout" value="<?php echo self::esc_attr_safe( (string) $cur_coauthor_timeout ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'HTTP timeout for draft generation requests. Default 300s.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo __( 'Source Processing & Debugging', 'presshub-ai-editor' ); ?></th>
                                <td>
                                    <?php $this->render_fetch_urls_field(); ?>
                                    <div style="margin-top: 8px;">
                                        <?php $this->render_debug_prompts_field(); ?>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <hr style="margin: 25px 0;">
                    <?php $this->render_section_with_fields( 'presshub_ai_general', __( 'Global & Legacy Provider Settings', 'presshub-ai-editor' ) ); ?>
                    <?php $this->render_section_with_fields( 'presshub_ai_providers', __( 'Provider Specific Fallbacks & Extras', 'presshub-ai-editor' ) ); ?>
                </div>

                <!-- TAB 3: Daily Briefing Hub -->
                <div id="presshub-tab-pane-briefing" class="presshub-tab-pane" style="display: none;">
                    <h2><?php echo __( 'Daily Briefing & AI Podcast Hub', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'Configure automated morning Greek news scraping, AI text story curation, and multi-host conversational podcast production.', 'presshub-ai-editor' ); ?></p>

                    <h3><?php echo __( '1. Text Story Curator Settings', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_provider"><?php echo __( 'Curator AI Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_text_prov = (string) get_option( 'presshub_ai_briefing_text_provider', '' ); ?>
                                    <select name="presshub_ai_briefing_text_provider" id="presshub_ai_briefing_text_provider" class="regular-text">
                                        <?php echo self::get_active_providers_options( $cur_text_prov, __( '-- Use Active Provider Default --', 'presshub-ai-editor' ) ); ?>
                                    </select>
                                    <p class="description"><?php echo __( 'AI Provider responsible for filtering and summarizing morning news into article drafts.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_model"><?php echo __( 'Curator Model Override', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_text_model = (string) get_option( 'presshub_ai_briefing_text_model', '' ); ?>
                                    <input type="text" name="presshub_ai_briefing_text_model" id="presshub_ai_briefing_text_model" value="<?php echo self::esc_attr_safe( $cur_text_model ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default', 'presshub-ai-editor' ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_sources"><?php echo __( 'News Source URLs', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_sources_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_harvest_time"><?php echo __( 'Morning Harvest Time', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_harvest_time_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_generation_time"><?php echo __( 'Generation Trigger Time', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_generation_time_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_category"><?php echo __( 'Text Briefing Category', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_category_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_status"><?php echo __( 'Text Post Status', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_status_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_preset"><?php echo __( 'Text Instruction Preset', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_preset_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_text_prompt"><?php echo __( 'Curator System Prompt', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_text_prompt_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <hr style="margin: 25px 0;">
                    <h3><?php echo __( '2. AI Podcast Producer & Audio Synthesis Settings', 'presshub-ai-editor' ); ?></h3>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_provider"><?php echo __( 'Scriptwriter AI Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_pod_prov = (string) get_option( 'presshub_ai_briefing_podcast_provider', '' ); ?>
                                    <select name="presshub_ai_briefing_podcast_provider" id="presshub_ai_briefing_podcast_provider" class="regular-text">
                                        <?php echo self::get_active_providers_options( $cur_pod_prov, __( '-- Use Active Provider Default --', 'presshub-ai-editor' ) ); ?>
                                    </select>
                                    <p class="description"><?php echo __( 'AI Provider responsible for writing conversational podcast scripts.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_model"><?php echo __( 'Scriptwriter Model Override', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_pod_model = (string) get_option( 'presshub_ai_briefing_podcast_model', '' ); ?>
                                    <input type="text" name="presshub_ai_briefing_podcast_model" id="presshub_ai_briefing_podcast_model" value="<?php echo self::esc_attr_safe( $cur_pod_model ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default', 'presshub-ai-editor' ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_target_duration"><?php echo __( 'Target Podcast Duration', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_target_duration_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_host_female"><?php echo __( 'Female Host Name', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_host_female_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_host_male"><?php echo __( 'Male Host Name', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_host_male_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_engine"><?php echo __( 'Voice Synthesis Engine', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_engine_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_api_key"><?php echo __( 'Speech API Key (Google AI Studio / Gemini)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_api_key_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_tts_model"><?php echo __( 'Voice AI Model', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_tts_model_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_voice_female"><?php echo __( 'Female Voice Model (TTS)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_female_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_voice_male"><?php echo __( 'Male Voice Model (TTS)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_male_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_voice_speed"><?php echo __( 'Voice Speaking Rate / Speed', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_speed_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_voice_pitch"><?php echo __( 'Voice Pitch Tuning', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_voice_pitch_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_category"><?php echo __( 'Podcast Category', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_category_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_status"><?php echo __( 'Podcast Post Status', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_status_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_preset"><?php echo __( 'Podcast Dialogue Preset', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_preset_field(); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_briefing_podcast_prompt"><?php echo __( 'Podcast System Prompt', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $this->render_briefing_podcast_prompt_field(); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- TAB 4: AI Copilot & Assistant -->
                <div id="presshub-tab-pane-copilot" class="presshub-tab-pane" style="display: none;">
                    <h2><?php echo __( 'AI Copilot & Research Assistant', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'Configure the AI provider, model, and parameters that power the interactive sidebar Copilot and autonomous research agents.', 'presshub-ai-editor' ); ?></p>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_provider"><?php echo __( 'Active Copilot Provider', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_prov = (string) get_option( 'presshub_ai_copilot_provider', '' ); ?>
                                    <select name="presshub_ai_copilot_provider" id="presshub_ai_copilot_provider" class="regular-text">
                                        <?php echo self::get_active_providers_options( $cur_copilot_prov, __( '-- Use Active Provider Default --', 'presshub-ai-editor' ) ); ?>
                                    </select>
                                    <p class="description"><?php echo __( 'Select which AI provider powers sidebar chat and background deep-research tasks.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_model"><?php echo __( 'Custom Model Override', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_model = (string) get_option( 'presshub_ai_copilot_model', '' ); ?>
                                    <input type="text" name="presshub_ai_copilot_model" id="presshub_ai_copilot_model" value="<?php echo self::esc_attr_safe( $cur_copilot_model ); ?>" class="regular-text code" placeholder="<?php echo esc_attr__( 'Leave empty to use provider default model', 'presshub-ai-editor' ); ?>" />
                                    <p class="description"><?php echo __( 'Optional specific model identifier for Copilot chat (e.g. gpt-4o, gemini-2.0-flash).', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_max_tokens"><?php echo __( 'Max Completion Tokens', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_tokens = (int) get_option( 'presshub_ai_copilot_max_tokens', 10000 ); ?>
                                    <input type="number" min="1" max="32768" name="presshub_ai_copilot_max_tokens" id="presshub_ai_copilot_max_tokens" value="<?php echo self::esc_attr_safe( (string) $cur_copilot_tokens ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'Maximum output tokens for Copilot conversational turns (1 - 32768). Default 10000.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_temperature"><?php echo __( 'Sampling Temperature', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_temp = get_option( 'presshub_ai_copilot_temperature', '0.7' ); ?>
                                    <input type="number" min="0" max="2" step="0.05" name="presshub_ai_copilot_temperature" id="presshub_ai_copilot_temperature" value="<?php echo self::esc_attr_safe( (string) $cur_copilot_temp ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'Sampling temperature for assistant chat (0.0 to 2.0). Default 0.7.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="presshub_ai_copilot_timeout"><?php echo __( 'Request Timeout (seconds)', 'presshub-ai-editor' ); ?></label></th>
                                <td>
                                    <?php $cur_copilot_timeout = (int) get_option( 'presshub_ai_copilot_timeout', 300 ); ?>
                                    <input type="number" min="5" max="300" name="presshub_ai_copilot_timeout" id="presshub_ai_copilot_timeout" value="<?php echo self::esc_attr_safe( (string) $cur_copilot_timeout ); ?>" class="small-text" />
                                    <p class="description"><?php echo __( 'HTTP timeout for chat and research calls. Default 300s.', 'presshub-ai-editor' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- TAB 5: Token & Usage Analytics Logs -->
                <div id="presshub-tab-pane-token_logs" class="presshub-tab-pane" style="display: none;">
                    <?php $this->render_token_logs_tab(); ?>
                </div>

                <!-- TAB 6: Advanced & System Settings -->
                <div id="presshub-tab-pane-advanced" class="presshub-tab-pane" style="display: none;">
                    <?php $this->render_section_with_fields( 'presshub_ai_media', __( 'Media & Vision Credentials (Google Cloud)', 'presshub-ai-editor' ) ); ?>
                    
                    <hr style="margin: 25px 0;">
                    <?php $this->render_section_with_fields( 'presshub_ai_rate_limits', __( 'Rate Limits, Research Retention & Logging', 'presshub-ai-editor' ) ); ?>

                    <hr style="margin: 25px 0;">
                    <h2><?php echo __( 'Connection Testing & Verification', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'Verify connectivity with any configured provider. Each test uses its own rate-limit budget and logs activity in the Token Logger dashboard.', 'presshub-ai-editor' ); ?></p>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" id="presshub-ai-test-api" class="button presshub-ai-test-api" data-provider=""><?php echo __( 'Test Active Provider', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="openai"><?php echo __( 'Test OpenAI', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="anthropic"><?php echo __( 'Test Anthropic', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button presshub-ai-test-api" data-provider="gemini"><?php echo __( 'Test Gemini', 'presshub-ai-editor' ); ?></button>
                        <span id="presshub-ai-test-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
                    </div>
                    <div id="presshub-ai-test-result" style="margin-top: 15px; font-weight: bold;"></div>

                    <hr style="margin: 25px 0;">
                    <h2><?php echo __( 'Internal Diagnostic Log Viewer', 'presshub-ai-editor' ); ?></h2>
                    <p><?php echo __( 'View recent internal diagnostic logs for debugging scraping, API calls, prompt hydration, and background jobs.', 'presshub-ai-editor' ); ?></p>
                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;">
                        <button type="button" id="presshub-ai-refresh-logs" class="button button-secondary"><?php echo __( 'Refresh Logs', 'presshub-ai-editor' ); ?></button>
                        <button type="button" id="presshub-ai-clear-logs" class="button button-secondary"><?php echo __( 'Clear Logs', 'presshub-ai-editor' ); ?></button>
                        <span id="presshub-ai-log-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
                        <span id="presshub-ai-log-status" style="margin-left: 10px; color: #666;"></span>
                    </div>
                    <textarea id="presshub-ai-log-viewer" rows="14" class="large-text code" readonly="readonly" style="font-size: 12px; background: #1e1e1e; color: #d4d4d4; font-family: monospace;" placeholder="<?php echo esc_attr__( 'Click "Refresh Logs" to load diagnostic entries...', 'presshub-ai-editor' ); ?>"></textarea>
                </div>

                <div class="presshub-settings-submit-wrap" id="presshub-settings-submit-wrap" style="margin-top: 20px; display: flex; align-items: center; gap: 10px;">
                    <?php if ( function_exists( 'submit_button' ) ) { submit_button( __( 'Save Changes', 'presshub-ai-editor' ), 'primary', 'submit', false ); } ?>
                    <span id="presshub-ai-save-spinner" class="spinner" role="status" style="float: none; margin: 0;"><span class="screen-reader-text"></span></span>
                </div>
            </form>

            <?php $this->render_provider_modal(); ?>
        </div>
        <?php
    }

    /**
     * Helper to render active configured providers as select options.
     */
    public static function get_active_providers_options( string $current_val = '', string $default_label = '' ): string {
        $providers = class_exists( 'PressHub_AI_Provider_Store' ) ? PressHub_AI_Provider_Store::get_all( false ) : [];
        $html = '';
        if ( '' !== $default_label ) {
            $selected = ( '' === $current_val ) ? ' selected="selected"' : '';
            $html .= '<option value=""' . $selected . '>' . esc_html( $default_label ) . '</option>';
        }
        foreach ( $providers as $prov ) {
            $id    = $prov['id'] ?? ( $prov['type'] ?? '' );
            $name  = $prov['name'] ?? ucfirst( $id );
            $model = $prov['default_model'] ?? '';
            $label = $name . ( $model ? ' (' . $model . ')' : '' ) . ( empty( $prov['enabled'] ) ? ' [' . __( 'Disabled', 'presshub-ai-editor' ) . ']' : '' );
            $selected = ( $current_val === $id ) ? ' selected="selected"' : '';
            $html .= '<option value="' . esc_attr( $id ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        return $html;
    }

    /**
     * Render the grid of provider cards.
     */
    public function render_providers_grid(): void {
        require_once __DIR__ . '/class-provider-store.php';
        require_once __DIR__ . '/class-provider-defaults.php';
        $providers = PressHub_AI_Provider_Store::get_all( false );
        ?>
        <div class="presshub-providers-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h2><?php echo __( 'Configured AI Providers', 'presshub-ai-editor' ); ?></h2>
                <p><?php echo __( 'Manage standard AI engines (OpenAI, Anthropic, Gemini, Google Cloud TTS, Groq, Mistral, DeepSeek, Local Ollama) and custom OpenAI-compatible endpoints.', 'presshub-ai-editor' ); ?></p>
            </div>
            <button type="button" class="button button-primary presshub-add-provider-btn" id="presshub-add-provider-btn" style="display: inline-flex; align-items: center; gap: 6px;">
                <span class="dashicons dashicons-plus-alt2" style="font-size: 16px; width: 16px; height: 16px;"></span>
                <?php echo __( 'Add Provider', 'presshub-ai-editor' ); ?>
            </button>
        </div>

        <div class="presshub-providers-grid" id="presshub-providers-grid">
            <?php if ( empty( $providers ) ) : ?>
                <div class="presshub-no-providers" style="grid-column: 1 / -1; padding: 30px; text-align: center; background: #fff; border: 1px dashed #ccd0d4; border-radius: 6px;">
                    <p><?php echo __( 'No AI providers configured yet. Click "Add Provider" above to create one.', 'presshub-ai-editor' ); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ( $providers as $provider ) :
                    $id          = $provider['id'] ?? '';
                    $name        = $provider['name'] ?? ucfirst( $id );
                    $type        = $provider['type'] ?? 'openai';
                    $base_url    = $provider['base_url'] ?? '';
                    $model       = $provider['default_model'] ?? '';
                    $timeout     = $provider['timeout'] ?? 300;
                    $temp        = $provider['temperature'] ?? 0.7;
                    $max_tokens  = $provider['max_tokens'] ?? 10000;
                    $enabled     = ! empty( $provider['enabled'] );
                    $is_system   = ! empty( $provider['is_system'] );
                    $has_key     = ! empty( $provider['api_key'] );
                ?>
                <div class="presshub-provider-card <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>" data-provider-id="<?php echo esc_attr( $id ); ?>" data-provider-type="<?php echo esc_attr( $type ); ?>">
                    <div class="presshub-card-top">
                        <div class="presshub-card-title-wrap">
                            <span class="presshub-provider-badge presshub-badge-<?php echo esc_attr( function_exists( 'sanitize_html_class' ) ? sanitize_html_class( $type ) : self::sanitize_slug( $type ) ); ?>"><?php echo esc_html( strtoupper( $type ) ); ?></span>
                            <h3 class="presshub-card-name"><?php echo esc_html( $name ); ?></h3>
                        </div>
                        <div class="presshub-card-status">
                            <span class="presshub-status-pill <?php echo $enabled ? 'pill-active' : 'pill-inactive'; ?>">
                                <?php echo $enabled ? __( 'Active', 'presshub-ai-editor' ) : __( 'Disabled', 'presshub-ai-editor' ); ?>
                            </span>
                        </div>
                    </div>

                    <div class="presshub-card-body">
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Default Model:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val code"><strong><?php echo esc_html( $model ?: '-' ); ?></strong></span>
                        </div>
                        <?php if ( '' !== $base_url ) : ?>
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Base URL:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val code" title="<?php echo esc_attr( $base_url ); ?>"><?php echo esc_html( strlen( $base_url ) > 32 ? substr( $base_url, 0, 30 ) . '…' : $base_url ); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Tuning:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val"><?php echo sprintf( 'Temp: %s | Max: %s | %ss', esc_html( (string) $temp ), esc_html( function_exists( 'number_format_i18n' ) ? number_format_i18n( $max_tokens ) : number_format( $max_tokens ) ), esc_html( (string) $timeout ) ); ?></span>
                        </div>
                        <div class="presshub-meta-row">
                            <span class="presshub-meta-label"><?php echo __( 'Credentials:', 'presshub-ai-editor' ); ?></span>
                            <span class="presshub-meta-val"><?php echo $has_key ? '••••••••' : ( 'ollama_local' === $type ? __( 'Not required', 'presshub-ai-editor' ) : '<span class="presshub-missing-key" style="color: #d63638;">' . __( 'No API Key set', 'presshub-ai-editor' ) . '</span>' ); ?></span>
                        </div>
                    </div>

                    <div class="presshub-card-footer">
                        <button type="button" class="button button-secondary presshub-test-provider-btn" data-provider-id="<?php echo esc_attr( $id ); ?>">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                            <?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?>
                        </button>
                        <button type="button" class="button button-secondary presshub-edit-provider-btn" data-provider-id="<?php echo esc_attr( $id ); ?>">
                            <?php echo __( 'Edit', 'presshub-ai-editor' ); ?>
                        </button>
                        <?php if ( ! $is_system ) : ?>
                        <button type="button" class="button button-link-delete presshub-delete-provider-btn" data-provider-id="<?php echo esc_attr( $id ); ?>" data-provider-name="<?php echo esc_attr( $name ); ?>">
                            <?php echo __( 'Delete', 'presshub-ai-editor' ); ?>
                        </button>
                        <?php endif; ?>
                        <span class="spinner presshub-card-spinner" role="status"></span>
                    </div>
                    <div class="presshub-card-test-result" style="display:none; margin-top: 10px; font-size: 12px;"></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Add/Edit Provider Modal Dialog.
     */
    public function render_provider_modal(): void {
        require_once __DIR__ . '/class-provider-defaults.php';
        $templates = PressHub_AI_Provider_Defaults::get_templates();
        ?>
        <div id="presshub-provider-modal" class="presshub-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="presshub-provider-modal-title" style="display:none;">
            <div class="presshub-modal presshub-provider-modal-content">
                <div class="presshub-modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid #dcdcde;">
                    <h2 id="presshub-provider-modal-title" style="margin:0; font-size: 16px;"><?php echo __( 'Add / Edit AI Provider', 'presshub-ai-editor' ); ?></h2>
                    <button type="button" class="presshub-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666;" aria-label="<?php echo esc_attr__( 'Close', 'presshub-ai-editor' ); ?>">&times;</button>
                </div>
                <div class="presshub-modal-body" style="padding: 20px; max-height: 70vh; overflow-y: auto;">
                    <form id="presshub-provider-form">
                        <input type="hidden" id="provider-form-id" name="id" value="" />

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="provider-form-template"><strong><?php echo __( 'Provider Preset Template:', 'presshub-ai-editor' ); ?></strong></label>
                            <select id="provider-form-template" class="widefat" style="margin-top: 4px;">
                                <option value=""><?php echo __( '-- Select Template Preset (Auto-fills defaults) --', 'presshub-ai-editor' ); ?></option>
                                <?php foreach ( $templates as $tmpl_key => $tmpl ) : ?>
                                    <option value="<?php echo esc_attr( $tmpl_key ); ?>"><?php echo esc_html( $tmpl['name'] ); ?> (<?php echo esc_html( $tmpl_key ); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo __( 'Selecting a preset template automatically sets the base URL, default model and tuning parameters.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="presshub-form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-name"><strong><?php echo __( 'Display Name:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="text" id="provider-form-name" name="name" class="widefat" required placeholder="e.g. OpenAI Production" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-type"><strong><?php echo __( 'Provider Type:', 'presshub-ai-editor' ); ?></strong></label>
                                <select id="provider-form-type" name="type" class="widefat" style="margin-top: 4px;">
                                    <?php foreach ( $templates as $tmpl_key => $tmpl ) : ?>
                                        <option value="<?php echo esc_attr( $tmpl_key ); ?>"><?php echo esc_html( $tmpl['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="provider-form-base-url"><strong><?php echo __( 'Base URL / Endpoint:', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="url" id="provider-form-base-url" name="base_url" class="widefat code" placeholder="https://api.openai.com/v1" style="margin-top: 4px;" />
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="provider-form-api-key"><strong><?php echo __( 'API Key / Secret Token:', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="password" id="provider-form-api-key" name="api_key" class="widefat" autocomplete="new-password" placeholder="<?php echo esc_attr__( 'Enter API Key (or leave blank to preserve saved)', 'presshub-ai-editor' ); ?>" style="margin-top: 4px;" />
                            <p class="description"><?php echo __( 'Stored securely with autoload disabled. Leave blank when editing to keep current secret.', 'presshub-ai-editor' ); ?></p>
                        </div>

                        <div class="presshub-form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-default-model"><strong><?php echo __( 'Default Model:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="text" id="provider-form-default-model" name="default_model" class="widefat code" placeholder="e.g. gpt-4o" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-available-models"><strong><?php echo __( 'Available Models (comma-separated):', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="text" id="provider-form-available-models" name="available_models" class="widefat code" placeholder="gpt-4o, gpt-4o-mini, o1" style="margin-top: 4px;" />
                            </div>
                        </div>

                        <div class="presshub-form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-temperature"><strong><?php echo __( 'Temperature (0.0 - 2.0):', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-temperature" name="temperature" class="widefat" step="0.05" min="0" max="2" value="0.7" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-max-tokens"><strong><?php echo __( 'Max Tokens:', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-max-tokens" name="max_tokens" class="widefat" step="100" min="1" max="32768" value="10000" style="margin-top: 4px;" />
                            </div>
                            <div class="presshub-form-group" style="flex: 1;">
                                <label for="provider-form-timeout"><strong><?php echo __( 'Timeout (seconds):', 'presshub-ai-editor' ); ?></strong></label>
                                <input type="number" id="provider-form-timeout" name="timeout" class="widefat" min="5" max="300" value="300" style="margin-top: 4px;" />
                            </div>
                        </div>

                        <div class="presshub-form-group" style="margin-bottom: 15px;">
                            <label for="provider-form-headers"><strong><?php echo __( 'Custom HTTP Headers (JSON optional):', 'presshub-ai-editor' ); ?></strong></label>
                            <textarea id="provider-form-headers" name="headers" class="widefat code" rows="2" placeholder='{"HTTP-Referer": "https://mysite.com"}' style="margin-top: 4px;"></textarea>
                        </div>

                        <div class="presshub-form-group">
                            <label>
                                <input type="checkbox" id="provider-form-enabled" name="enabled" value="1" checked="checked" />
                                <strong><?php echo __( 'Enable this provider for module assignment', 'presshub-ai-editor' ); ?></strong>
                            </label>
                        </div>
                    </form>
                    <div id="presshub-provider-form-notice" style="margin-top: 10px;"></div>
                </div>
                <div class="presshub-modal-footer" style="padding: 12px 20px; border-top: 1px solid #dcdcde; display: flex; align-items: center; gap: 8px;">
                    <button type="button" class="button button-secondary" id="presshub-provider-form-test">
                        <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                        <?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?>
                    </button>
                    <div style="margin-left: auto; display: flex; gap: 8px;">
                        <button type="button" class="button button-secondary presshub-modal-cancel"><?php echo __( 'Cancel', 'presshub-ai-editor' ); ?></button>
                        <button type="button" class="button button-primary" id="presshub-provider-form-save"><?php echo __( 'Save Provider', 'presshub-ai-editor' ); ?></button>
                    </div>
                    <span class="spinner" id="presshub-provider-form-spinner" role="status"></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Token & Activity Usage Analytics Dashboard.
     */
    public function render_token_logs_tab(): void {
        require_once __DIR__ . '/class-token-logger.php';
        ?>
        <div class="presshub-token-dashboard">
            <h2><?php echo __( 'Token & Activity Analytics Dashboard', 'presshub-ai-editor' ); ?></h2>
            <p><?php echo __( 'Monitor live token usage, Text-to-Speech character volumes, news crawling metrics, API latencies, and success rates.', 'presshub-ai-editor' ); ?></p>

            <div class="presshub-token-kpis" id="presshub-token-kpis">
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'Total Requests', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-total-requests">-</span>
                    <span class="kpi-subtitle"><?php echo __( 'API executions', 'presshub-ai-editor' ); ?></span>
                </div>
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'Total Tokens', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-total-tokens">-</span>
                    <span class="kpi-subtitle"><?php echo __( 'Prompt & completions', 'presshub-ai-editor' ); ?></span>
                </div>
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'TTS Characters', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-tts-chars">-</span>
                    <span class="kpi-subtitle"><?php echo __( 'Voice audio synthesized', 'presshub-ai-editor' ); ?></span>
                </div>
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'Scraped Articles', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-scraped-articles">-</span>
                    <span class="kpi-subtitle"><?php echo __( 'Harvested from sources', 'presshub-ai-editor' ); ?></span>
                </div>
                <div class="presshub-kpi-card">
                    <span class="kpi-title"><?php echo __( 'Success Rate', 'presshub-ai-editor' ); ?></span>
                    <span class="kpi-value" id="kpi-success-rate">100%</span>
                    <span class="kpi-subtitle"><?php echo __( 'Error-free runs', 'presshub-ai-editor' ); ?></span>
                </div>
            </div>

            <div class="presshub-token-filters">
                <div class="filter-group">
                    <label for="token-filter-range"><?php echo __( 'Date Range:', 'presshub-ai-editor' ); ?></label>
                    <select id="token-filter-range">
                        <option value="today"><?php echo __( 'Today', 'presshub-ai-editor' ); ?></option>
                        <option value="7d"><?php echo __( 'Last 7 Days', 'presshub-ai-editor' ); ?></option>
                        <option value="30d" selected="selected"><?php echo __( 'Last 30 Days', 'presshub-ai-editor' ); ?></option>
                        <option value="90d"><?php echo __( 'Last 90 Days', 'presshub-ai-editor' ); ?></option>
                        <option value="all"><?php echo __( 'All Time', 'presshub-ai-editor' ); ?></option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="token-filter-action"><?php echo __( 'Action:', 'presshub-ai-editor' ); ?></label>
                    <select id="token-filter-action">
                        <option value=""><?php echo __( 'All Actions', 'presshub-ai-editor' ); ?></option>
                        <option value="coauthor_draft"><?php echo __( 'Co-Author Draft', 'presshub-ai-editor' ); ?></option>
                        <option value="coauthor_scorecard"><?php echo __( 'Editorial Scorecard', 'presshub-ai-editor' ); ?></option>
                        <option value="copilot_chat"><?php echo __( 'Copilot Chat', 'presshub-ai-editor' ); ?></option>
                        <option value="copilot_research"><?php echo __( 'Copilot Research', 'presshub-ai-editor' ); ?></option>
                        <option value="briefing_curation"><?php echo __( 'Briefing Curation', 'presshub-ai-editor' ); ?></option>
                        <option value="podcast_script"><?php echo __( 'Podcast Script', 'presshub-ai-editor' ); ?></option>
                        <option value="podcast_audio"><?php echo __( 'Podcast Audio Synthesis', 'presshub-ai-editor' ); ?></option>
                        <option value="scrape_harvest"><?php echo __( 'News Harvester', 'presshub-ai-editor' ); ?></option>
                        <option value="custom_test"><?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?></option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="token-filter-provider"><?php echo __( 'Provider:', 'presshub-ai-editor' ); ?></label>
                    <select id="token-filter-provider">
                        <option value=""><?php echo __( 'All Providers', 'presshub-ai-editor' ); ?></option>
                        <option value="openai">OpenAI</option>
                        <option value="anthropic">Anthropic</option>
                        <option value="gemini">Gemini</option>
                        <option value="google_cloud_tts">Google Cloud TTS</option>
                        <option value="groq">Groq</option>
                        <option value="mistral">Mistral</option>
                        <option value="deepseek">DeepSeek</option>
                        <option value="ollama_local">Ollama / Local</option>
                        <option value="scraper">Web Scraper</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="token-filter-status"><?php echo __( 'Status:', 'presshub-ai-editor' ); ?></label>
                    <select id="token-filter-status">
                        <option value=""><?php echo __( 'All Statuses', 'presshub-ai-editor' ); ?></option>
                        <option value="success"><?php echo __( 'Success', 'presshub-ai-editor' ); ?></option>
                        <option value="error"><?php echo __( 'Error', 'presshub-ai-editor' ); ?></option>
                    </select>
                </div>
                <div class="filter-group filter-search" style="flex: 1;">
                    <label for="token-filter-search"><?php echo __( 'Search:', 'presshub-ai-editor' ); ?></label>
                    <input type="text" id="token-filter-search" placeholder="<?php echo esc_attr__( 'Search model, action, metadata...', 'presshub-ai-editor' ); ?>" />
                </div>
                <div class="filter-actions" style="display: flex; gap: 6px; align-items: flex-end;">
                    <button type="button" class="button button-secondary" id="token-filter-refresh"><?php echo __( 'Filter', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-secondary" id="token-export-csv"><?php echo __( 'Export CSV', 'presshub-ai-editor' ); ?></button>
                    <button type="button" class="button button-link-delete" id="token-clear-logs"><?php echo __( 'Clear Logs', 'presshub-ai-editor' ); ?></button>
                    <span class="spinner" id="token-logs-spinner" role="status"></span>
                </div>
            </div>

            <div class="presshub-table-responsive" style="margin-top: 15px;">
                <table class="wp-list-table widefat fixed striped presshub-token-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;"><?php echo __( 'Date / Time', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 130px;"><?php echo __( 'Action', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 110px;"><?php echo __( 'Provider', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 140px;"><?php echo __( 'Model', 'presshub-ai-editor' ); ?></th>
                            <th><?php echo __( 'Tokens / Metrics', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 90px;"><?php echo __( 'Latency', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 90px;"><?php echo __( 'Status', 'presshub-ai-editor' ); ?></th>
                            <th style="width: 80px;"><?php echo __( 'Details', 'presshub-ai-editor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="presshub-token-logs-tbody">
                        <tr><td colspan="8" style="text-align: center; padding: 20px;"><?php echo __( 'Loading token usage logs...', 'presshub-ai-editor' ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="presshub-pagination-wrap" id="presshub-token-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px;">
                <span class="pagination-info" id="token-pagination-info"><?php echo __( 'Showing 0 items', 'presshub-ai-editor' ); ?></span>
                <div class="pagination-buttons" style="display: flex; gap: 8px;">
                    <button type="button" class="button button-secondary" id="token-page-prev" disabled="disabled">&laquo; <?php echo __( 'Previous', 'presshub-ai-editor' ); ?></button>
                    <span id="token-page-current" style="display: flex; align-items: center; font-weight: 600;">1 / 1</span>
                    <button type="button" class="button button-secondary" id="token-page-next" disabled="disabled"><?php echo __( 'Next', 'presshub-ai-editor' ); ?> &raquo;</button>
                </div>
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

    public function render_briefing_tts_api_key_field() {
        $saved = (string) get_option( 'presshub_ai_briefing_tts_api_key', '' );
        $mask  = self::mask_key( $saved );
        ?>
        <input type="password" name="presshub_ai_briefing_tts_api_key" id="presshub_ai_briefing_tts_api_key" value="<?php echo self::esc_attr_safe( $mask ); ?>" placeholder="<?php echo self::esc_attr_safe( $mask ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php echo __( 'Dedicated Gemini / Google AI Studio API key used specifically for speech generation and podcast audio synthesis. If left empty, falls back to your main Gemini API key from the Providers tab.', 'presshub-ai-editor' ); ?></p>
        <?php if ( '' !== $mask ) : ?>
            <label>
                <input type="checkbox" name="presshub_ai_remove_briefing_tts_api_key" id="presshub_ai_remove_briefing_tts_api_key" value="1" />
                <?php echo __( 'Remove stored key', 'presshub-ai-editor' ); ?>
            </label>
        <?php endif; ?>
        <?php
    }

    public function render_briefing_tts_model_field() {
        $option = 'presshub_ai_briefing_tts_model';
        $value  = (string) get_option( $option, self::default_briefing_tts_model() );
        ?>
        <input type="text" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="regular-text code" placeholder="gemini-2.0-flash" />
        <p class="description">
            <?php echo esc_html__( 'Model ID used for speech synthesis (e.g. gemini-2.0-flash, gemini-2.0-flash-exp, gemini-3.1-flash-tts-preview, or custom endpoint).', 'presshub-ai-editor' ); ?>
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

    public static function sanitize_provider_id( $value ): string {
        return sanitize_text_field( trim( (string) wp_unslash( $value ) ) );
    }

    public static function sanitize_model_string( $value ): string {
        $clean = sanitize_text_field( trim( (string) wp_unslash( $value ) ) );
        return preg_replace( '#^models/#', '', $clean );
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
