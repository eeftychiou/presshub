<?php
/**
 * PressHub AI Editor — settings facade (Concern #2 split: T5).
 *
 * Backwards-compatible shell. Forwards every public method on the original
 * PressHub_AI_Settings class to one of the three focused modules:
 *
 *   - Storage   (PressHub_AI_Settings_Storage): register_options + sanitize_*
 *   - Render    (PressHub_AI_Settings_Render):  render_* + mask_key + getters
 *   - Migration (PressHub_AI_Settings_Migration): default_* + migrate_legacy_model
 *
 * The original ~2455-line class is split into those three modules in T1-T4;
 * T5 collapses this file down to a thin forwarder layer.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-provider-defaults.php';
require_once __DIR__ . '/class-settings-storage.php';
require_once __DIR__ . '/class-settings-render.php';
require_once __DIR__ . '/class-settings-migration.php';

class PressHub_AI_Settings {

    const PROVIDERS = [ 'openai', 'anthropic', 'gemini' ];


    // ------------------------------------------------------------------
    // Lifecycle (instance).
    // ------------------------------------------------------------------

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_settings_page' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        // Idempotent legacy-model migration; no-op after the first run.
        self::migrate_legacy_model();
        // Idempotent deprecated-Gemini-model migration; rewrites any saved
        // gemini-2.0-flash / gemini-2.0-flash-exp values to the current valid
        // models so TTS requests no longer hit Google's dead endpoints first.
        self::migrate_deprecated_gemini_models();
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
     * P3: capability required to view/edit PressHub AI settings,
     * filterable via presshub_ai_settings_cap.
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
    // Option registration → Storage.
    // ------------------------------------------------------------------

    public function register_settings(): void {
        PressHub_AI_Settings_Storage::register_options();
    }


    // ------------------------------------------------------------------
    // Rendering → Render module.
    // ------------------------------------------------------------------

    public function render_settings_page() {
        $renderer = new PressHub_AI_Settings_Render();
        $renderer->render_settings_page();
    }

    /**
     * Stand-alone render of the API key field. Used by SettingsPageTest
     * and any caller that wants just the masked-key widget, not the
     * whole settings page. Delegates to the render module.
     */
    public function render_api_key_field(): void {
        $renderer = new PressHub_AI_Settings_Render();
        $renderer->render_api_key_field();
    }

    public function render_github_token_field(): void {
        $renderer = new PressHub_AI_Settings_Render();
        $renderer->render_github_token_field();
    }

    public function render_google_cloud_api_key_field(): void {
        $renderer = new PressHub_AI_Settings_Render();
        $renderer->render_google_cloud_api_key_field();
    }

    public function render_providers_grid(): void {
        $renderer = new PressHub_AI_Settings_Render();
        $renderer->render_providers_grid();
    }

    public function render_research_retention_days_field(): void {
        $renderer = new PressHub_AI_Settings_Render();
        $renderer->render_research_retention_days_field();
    }

    public static function mask_key( $key ): string {
        return PressHub_AI_Settings_Render::mask_key( $key );
    }

    public static function get_active_providers_options( string $current_val = '', string $default_label = '' ): string {
        return PressHub_AI_Settings_Render::get_active_providers_options( $current_val, $default_label );
    }

    public static function get_speech_providers_options( string $selected_id = '', string $default_label = '' ): string {
        return PressHub_AI_Settings_Render::get_speech_providers_options( $selected_id, $default_label );
    }


    // ------------------------------------------------------------------
    // Defaults + legacy migration → Migration module.
    // ------------------------------------------------------------------

    public static function default_model( $provider ): string {
        return PressHub_AI_Settings_Migration::default_model( $provider );
    }

    public static function default_temperature(): float {
        return PressHub_AI_Settings_Migration::default_temperature();
    }

    public static function default_max_tokens(): int {
        return PressHub_AI_Settings_Migration::default_max_tokens();
    }

    public static function default_timeout( $provider ): int {
        return PressHub_AI_Settings_Migration::default_timeout( $provider );
    }

    public static function default_briefing_sources(): array {
        return PressHub_AI_Settings_Migration::default_briefing_sources();
    }

    public static function default_briefing_harvest_time(): string {
        return PressHub_AI_Settings_Migration::default_briefing_harvest_time();
    }

    public static function default_briefing_generation_time(): string {
        return PressHub_AI_Settings_Migration::default_briefing_generation_time();
    }

    public static function default_briefing_target_duration(): string {
        return PressHub_AI_Settings_Migration::default_briefing_target_duration();
    }

    public static function default_briefing_host_female(): string {
        return PressHub_AI_Settings_Migration::default_briefing_host_female();
    }

    public static function default_briefing_host_male(): string {
        return PressHub_AI_Settings_Migration::default_briefing_host_male();
    }

    public static function default_briefing_tts_engine(): string {
        return PressHub_AI_Settings_Migration::default_briefing_tts_engine();
    }

    public static function default_briefing_tts_model(): string {
        return PressHub_AI_Settings_Migration::default_briefing_tts_model();
    }

    public static function default_briefing_voice_female(): string {
        return PressHub_AI_Settings_Migration::default_briefing_voice_female();
    }

    public static function default_briefing_voice_male(): string {
        return PressHub_AI_Settings_Migration::default_briefing_voice_male();
    }

    public static function default_briefing_tts_style(): string {
        return PressHub_AI_Settings_Migration::default_briefing_tts_style();
    }

    public static function default_briefing_tts_custom_style(): string {
        return PressHub_AI_Settings_Migration::default_briefing_tts_custom_style();
    }

    public static function migrate_legacy_model(): void {
        PressHub_AI_Settings_Migration::migrate_legacy_model();
    }

    public static function migrate_deprecated_gemini_models(): void {
        PressHub_AI_Settings_Migration::migrate_deprecated_gemini_models();
    }
}
