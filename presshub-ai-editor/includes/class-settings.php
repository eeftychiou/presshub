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

        // --- P1: sections ---
        add_settings_section( 'presshub_ai_general', __( 'General', 'presshub-ai-editor' ), [ $this, 'render_general_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_providers', __( 'Providers', 'presshub-ai-editor' ), [ $this, 'render_providers_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_media', __( 'Media (Google Cloud)', 'presshub-ai-editor' ), [ $this, 'render_media_section' ], 'presshub-ai' );
        add_settings_section( 'presshub_ai_rate_limits', __( 'Rate Limits', 'presshub-ai-editor' ), [ $this, 'render_rate_limits_section' ], 'presshub-ai' );

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
        <div class="wrap">
            <h1><?php echo __( 'PressHub AI Settings', 'presshub-ai-editor' ); ?></h1>
            <form method="post" action="options.php" id="presshub-ai-settings-form">
                <?php settings_fields( 'presshub_ai_options' ); ?>
                <?php do_settings_sections( 'presshub-ai' ); ?>
                <?php if ( function_exists( 'submit_button' ) ) { submit_button(); } ?>
            </form>

            <hr />
            <h2><?php echo __( 'Test Connection', 'presshub-ai-editor' ); ?></h2>
            <p><?php echo __( 'Save your settings first, then verify each provider separately. Each test uses its own rate-limit budget so testing never burns your AI allowance.', 'presshub-ai-editor' ); ?></p>
            <button type="button" id="presshub-ai-test-api" class="button presshub-ai-test-api" data-provider=""><?php echo __( 'Test Active Provider', 'presshub-ai-editor' ); ?></button>
            <button type="button" class="button presshub-ai-test-api" data-provider="openai"><?php echo __( 'Test OpenAI', 'presshub-ai-editor' ); ?></button>
            <button type="button" class="button presshub-ai-test-api" data-provider="anthropic"><?php echo __( 'Test Anthropic', 'presshub-ai-editor' ); ?></button>
            <button type="button" class="button presshub-ai-test-api" data-provider="gemini"><?php echo __( 'Test Gemini', 'presshub-ai-editor' ); ?></button>
            <span id="presshub-ai-test-spinner" class="spinner" role="status"><span class="screen-reader-text"></span></span>
            <div id="presshub-ai-test-result" style="margin-top: 10px; font-weight: bold;"></div>
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
    // Section descriptions.
    // ------------------------------------------------------------------

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
        <input type="number" min="1" max="8192" step="1" name="<?php echo self::esc_attr_safe( $option ); ?>" id="<?php echo self::esc_attr_safe( $option ); ?>" value="<?php echo self::esc_attr_safe( $value ); ?>" class="small-text" />
        <p class="description"><?php echo __( 'Maximum tokens per response (1-8192). Default 2000.', 'presshub-ai-editor' ); ?></p>
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
        return max( 1, min( 8192, $n ) );
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

    public static function sanitize_openai_org( $value ) {
        return substr( self::sanitize_text( $value ), 0, 512 );
    }

    public static function sanitize_anthropic_version( $value ) {
        return substr( self::sanitize_text( $value ), 0, 50 );
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
    private static function sanitize_secret( $value, $option_name ) {
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
        $value = strtolower( (string) $value );
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
