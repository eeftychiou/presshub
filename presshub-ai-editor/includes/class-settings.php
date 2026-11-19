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

require_once __DIR__ . '/class-capabilities.php';
require_once __DIR__ . '/class-settings-sections.php';

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
        if ( self::is_settings_hook( $hook ) || false !== strpos( (string) $hook, 'presshub-ai' ) ) {
            require_once __DIR__ . '/class-provider-defaults.php';
            require_once __DIR__ . '/class-provider-store.php';

            if ( function_exists( 'wp_enqueue_media' ) ) {
                wp_enqueue_media();
            }

            wp_enqueue_style( 'presshub-ai-admin-css', PRESSHUB_AI_URL . 'assets/admin.css', [], PRESSHUB_AI_VERSION );
            // #246: the settings renderers' styles (no inline style="" left), also used by Usage & logs.
            wp_enqueue_style( 'presshub-settings', PRESSHUB_AI_URL . 'assets/css/settings.css', [ 'presshub-ai-admin-css' ], PRESSHUB_AI_VERSION );
            wp_enqueue_script( 'presshub-ai-admin-js', PRESSHUB_AI_URL . 'assets/admin.js', [ 'jquery', 'wp-i18n' ], PRESSHUB_AI_VERSION, true );
            if ( self::is_settings_hook( $hook ) && class_exists( 'PressHub_Settings_Registry' ) ) {
                // #246: the one Settings page: its stylesheet (no inline styles in
                // the renderers), search in the section list, per-field defaults,
                // the unsaved-changes guard and the What's new panel.
                PressHub_Settings_Registry::ensure_registered();
                wp_enqueue_script( 'presshub-settings-search', PRESSHUB_AI_URL . 'assets/js/settings-search.js', [ 'jquery' ], PRESSHUB_AI_VERSION, true );
                wp_localize_script( 'presshub-settings-search', 'presshubSettingsIndex', PressHub_Settings_Registry::search_payload( PressHub_Settings_Sections::current() ) );
                wp_enqueue_script( 'presshub-settings-page', PRESSHUB_AI_URL . 'assets/js/settings-page.js', [ 'jquery', 'presshub-ai-admin-js', 'presshub-settings-search' ], PRESSHUB_AI_VERSION, true );
                if ( 'writing-qa' === PressHub_Settings_Sections::current() ) {
                    self::enqueue_image_templates_editor();
                }
            }
            wp_localize_script( 'presshub-ai-admin-js', 'presshubAI', [
                'ajax_url'             => admin_url( 'admin-ajax.php' ),
                'usage_url'            => admin_url( 'admin.php?page=presshub-ai-usage' ),
                'settings_section'     => self::is_settings_hook( $hook ) && class_exists( 'PressHub_Settings_Sections' ) ? PressHub_Settings_Sections::current() : '',
                'nonce'                => wp_create_nonce( 'presshub_ai_nonce' ),
                'provider_templates'   => PressHub_AI_Provider_Defaults::get_templates(),
                'configured_providers' => array_map( function( $p ) {
                    $p['masked_key'] = PressHub_AI_Provider_Store::mask_key( $p['api_key'] ?? '' );
                    $p['api_key']    = $p['masked_key'];
                    return $p;
                }, PressHub_AI_Provider_Store::get_all( false ) ),
            ] );
        }
    }

    /**
     * Writing & QA → Featured images: the prompt templates editor and its
     * translated strings.
     */
    private static function enqueue_image_templates_editor(): void {
        require_once __DIR__ . '/class-image-prompt-templates.php';
        $user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
        wp_enqueue_script( 'presshub-image-templates-settings', PRESSHUB_AI_URL . 'assets/js/image-templates-settings.js', [ 'jquery', 'presshub-ai-admin-js' ], PRESSHUB_AI_VERSION, true );
        wp_localize_script( 'presshub-image-templates-settings', 'presshubImageTemplates', [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'presshub_ai_nonce' ),
            'default_id'   => PressHub_AI_Image_Prompt_Templates::DEFAULT_ID,
            'current_user' => ( $user && isset( $user->display_name ) ) ? (string) $user->display_name : '',
            'strings'      => [
                'builtin'        => __( 'Built-in', 'presshub-ai-editor' ),
                'saved'          => __( 'Saved', 'presshub-ai-editor' ),
                /* translators: %s: display name of the editor who saved the template. */
                'saved_by'       => __( 'Saved by %s', 'presshub-ai-editor' ),
                'edit_above'     => __( 'Edit in the field above', 'presshub-ai-editor' ),
                'view'           => __( 'View', 'presshub-ai-editor' ),
                'edit'           => __( 'Edit', 'presshub-ai-editor' ),
                'copy'           => __( 'Copy', 'presshub-ai-editor' ),
                'delete'         => __( 'Delete', 'presshub-ai-editor' ),
                'heading_add'    => __( 'New template', 'presshub-ai-editor' ),
                /* translators: %s: template name. */
                'heading_edit'   => __( 'Edit template: %s', 'presshub-ai-editor' ),
                /* translators: %s: template name. */
                'heading_view'   => __( 'Template: %s', 'presshub-ai-editor' ),
                /* translators: %s: name of the template being copied. */
                'copy_name'      => __( '%s (copy)', 'presshub-ai-editor' ),
                'need_name'      => __( 'Give the template a name.', 'presshub-ai-editor' ),
                'need_text'      => __( 'The template text is empty.', 'presshub-ai-editor' ),
                'saving'         => __( 'Saving…', 'presshub-ai-editor' ),
                'deleting'       => __( 'Deleting…', 'presshub-ai-editor' ),
                /* translators: %s: template name. */
                'saved_msg'      => __( 'Template "%s" saved.', 'presshub-ai-editor' ),
                /* translators: %s: template name. */
                'deleted_msg'    => __( 'Template "%s" deleted.', 'presshub-ai-editor' ),
                /* translators: %s: template name. */
                'confirm_delete' => __( 'Delete the template "%s"? Editors can no longer pick it.', 'presshub-ai-editor' ),
                'error'          => __( 'Could not save the template. Try again.', 'presshub-ai-editor' ),
            ],
        ] );
    }

    /**
     * P3: capability required to view/edit PressHub AI settings,
     * filterable via presshub_ai_settings_cap.
     */
    public function settings_cap(): string {
        return PressHub_Capabilities::manage();
    }

    /** #235: Settings sits under the one PressHub menu (was Settings → PressHub AI). */
    public function add_settings_page() {
        add_submenu_page(
            class_exists( 'PressHub_Admin_Menu' ) ? PressHub_Admin_Menu::PARENT : 'presshub-ai-briefing-hub',
            __( 'PressHub — Settings', 'presshub-ai-editor' ),
            __( 'Settings', 'presshub-ai-editor' ),
            $this->settings_cap(),
            'presshub-ai',
            [ $this, 'render_settings_page' ]
        );
    }

    /** Whether an admin page hook is the PressHub Settings page (old and new hook names). */
    public static function is_settings_hook( $hook ): bool {
        $hook = (string) $hook;
        if ( 'settings_page_presshub-ai' === $hook ) {
            return true;
        }
        return class_exists( 'PressHub_Admin_Menu' ) && PressHub_Admin_Menu::is_page_hook( $hook, 'presshub-ai' );
    }


    // ------------------------------------------------------------------
    // Option registration → Storage.
    // ------------------------------------------------------------------

    public function register_settings(): void {
        PressHub_AI_Settings_Storage::register_options();
    }

    public static function get_harvest_time_budget(): int {
        return PressHub_AI_Settings_Storage::get_harvest_time_budget();
    }


    // ------------------------------------------------------------------
    // Rendering → Render module.
    // ------------------------------------------------------------------

    public function render_settings_page() {
        $renderer = new PressHub_AI_Settings_Render();
        $renderer->render_settings_page();
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

    public static function default_structured_sources(): array {
        return PressHub_AI_Settings_Migration::default_structured_sources();
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

    public static function default_briefing_noon_schedule_enabled(): int {
        return PressHub_AI_Settings_Migration::default_briefing_noon_schedule_enabled();
    }

    public static function default_briefing_noon_harvest_time(): string {
        return PressHub_AI_Settings_Migration::default_briefing_noon_harvest_time();
    }

    public static function default_briefing_noon_generation_time(): string {
        return PressHub_AI_Settings_Migration::default_briefing_noon_generation_time();
    }

    public static function default_briefing_noon_text_title_prefix(): string {
        return PressHub_AI_Settings_Migration::default_briefing_noon_text_title_prefix();
    }

    public static function default_briefing_noon_podcast_title_prefix(): string {
        return PressHub_AI_Settings_Migration::default_briefing_noon_podcast_title_prefix();
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
