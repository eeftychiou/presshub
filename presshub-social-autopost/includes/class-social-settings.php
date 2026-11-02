<?php
/**
 * PressHub Social Settings Controller
 *
 * Registers the admin menu, Settings API sections, fields, and handles
 * administrative actions such as prompt restoration and script enqueuing.
 *
 * @package PressHub_Social_AutoPost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-social-logger.php';
require_once __DIR__ . '/class-social-settings-storage.php';
require_once __DIR__ . '/class-social-settings-render.php';

class PressHub_Social_Settings {

    const MENU_SLUG     = 'presshub-social-queue';
    const SETTINGS_SLUG = 'presshub-social-settings';

    /**
     * Singleton instance.
     *
     * @var PressHub_Social_Settings|null
     */
    private static ?PressHub_Social_Settings $instance = null;

    /**
     * Get singleton instance.
     *
     * @return PressHub_Social_Settings
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize singleton instance.
     *
     * @return self
     */
    public static function init(): self {
        return self::get_instance();
    }

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'handle_reset_prompts_action' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Required capability to view and modify PressHub Social settings.
     *
     * @return string
     */
    public static function settings_cap(): string {
        return (string) apply_filters( 'presshub_social_settings_cap', 'manage_options' );
    }

    /**
     * Register admin menu and submenus.
     *
     * @return void
     */
    public function add_admin_menu(): void {
        $cap = self::settings_cap();

        add_menu_page(
            __( 'PressHub Social', 'presshub-social-autopost' ),
            __( 'PressHub Social', 'presshub-social-autopost' ),
            $cap,
            self::MENU_SLUG,
            [ $this, 'render_queue_page' ],
            'dashicons-share',
            30
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Social Queue', 'presshub-social-autopost' ),
            __( 'Queue', 'presshub-social-autopost' ),
            $cap,
            self::MENU_SLUG,
            [ $this, 'render_queue_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Social Settings', 'presshub-social-autopost' ),
            __( 'Settings', 'presshub-social-autopost' ),
            $cap,
            self::SETTINGS_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Render the main Social Queue page.
     *
     * @return void
     */
    public function render_queue_page(): void {
        if ( ! current_user_can( self::settings_cap() ) ) {
            wp_die( esc_html__( 'Unauthorized', 'presshub-social-autopost' ) );
        }

        if ( has_action( 'presshub_social_render_queue_page' ) ) {
            do_action( 'presshub_social_render_queue_page' );
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'PressHub Social Dispatch Queue', 'presshub-social-autopost' ); ?></h1>
            <p><?php echo esc_html__( 'Manage and monitor pending, publishing, and published social media posts.', 'presshub-social-autopost' ); ?></p>
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-top:15px;">
                <p><em><?php echo esc_html__( 'The queue management interface is active.', 'presshub-social-autopost' ); ?></em></p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>" class="button button-primary">
                        <?php echo esc_html__( 'Configure Social Settings', 'presshub-social-autopost' ); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Settings page.
     *
     * @return void
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( self::settings_cap() ) ) {
            wp_die( esc_html__( 'Unauthorized', 'presshub-social-autopost' ) );
        }

        $render = new PressHub_Social_Settings_Render();
        $render->render_page();
    }

    /**
     * Handle explicit action to restore prompts to defaults.
     *
     * @return void
     */
    public function handle_reset_prompts_action(): void {
        if ( ! isset( $_GET['page'] ) || self::SETTINGS_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        if ( ! isset( $_GET['action'] ) || 'reset_prompts' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        if ( ! current_user_can( self::settings_cap() ) ) {
            wp_die( esc_html__( 'Unauthorized', 'presshub-social-autopost' ) );
        }
        check_admin_referer( 'presshub_social_reset_prompts' );

        PressHub_Social_Settings_Storage::reset_prompts_to_defaults();
        PressHub_Social_Logger::info( 'Operator reset social prompts and templates to asset defaults.' );

        wp_safe_redirect( add_query_arg( [
            'page'    => self::SETTINGS_SLUG,
            'tab'     => 'prompts',
            'message' => 'prompts_reset',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Admin page hook.
     * @return void
     */
    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, 'presshub-social' ) ) {
            return;
        }
    }

    /**
     * Register settings, sections, and fields with the Settings API.
     *
     * @return void
     */
    public function register_settings(): void {
        PressHub_Social_Settings_Storage::register_options();
        $render = new PressHub_Social_Settings_Render();

        // ------------------------------------------------------------------
        // Tab 1: General & Engines
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_general_sec',
            __( 'General Publishing Configuration', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_general'
        );

        add_settings_field(
            'presshub_social_auto_publish_enabled',
            __( 'Auto-Publish on Post', 'presshub-social-autopost' ),
            [ $render, 'render_auto_publish_enabled_field' ],
            'presshub_social_general',
            'presshub_social_general_sec'
        );

        add_settings_field(
            'presshub_social_enabled_platforms',
            __( 'Active Platforms', 'presshub-social-autopost' ),
            [ $render, 'render_enabled_platforms_field' ],
            'presshub_social_general',
            'presshub_social_general_sec'
        );

        add_settings_field(
            'presshub_social_copy_mode',
            __( 'Copy Generation Mode', 'presshub-social-autopost' ),
            [ $render, 'render_copy_mode_field' ],
            'presshub_social_general',
            'presshub_social_general_sec'
        );

        add_settings_field(
            'presshub_social_github_token',
            __( 'GitHub Personal Access Token (Auto-Updates)', 'presshub-social-autopost' ),
            [ $render, 'render_github_token_field' ],
            'presshub_social_general',
            'presshub_social_general_sec'
        );

        add_settings_section(
            'presshub_social_engines_sec',
            __( 'Platform Dispatch Engines', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_general'
        );

        foreach ( PressHub_Social_Settings_Storage::PLATFORMS as $platform ) {
            add_settings_field(
                'presshub_social_engine_' . $platform,
                ucfirst( $platform ) . ' ' . __( 'Engine', 'presshub-social-autopost' ),
                [ $render, 'render_platform_engine_field' ],
                'presshub_social_general',
                'presshub_social_engines_sec',
                [ 'platform' => $platform ]
            );
        }

        // ------------------------------------------------------------------
        // Tab 2: AI Copywriter
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_ai_sec',
            __( 'AI Copywriter Model & Tuning', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_ai'
        );

        add_settings_field(
            'presshub_social_ai_provider',
            __( 'AI Provider', 'presshub-social-autopost' ),
            [ $render, 'render_ai_provider_field' ],
            'presshub_social_ai',
            'presshub_social_ai_sec'
        );

        add_settings_field(
            'presshub_social_ai_model',
            __( 'Model Override', 'presshub-social-autopost' ),
            [ $render, 'render_ai_model_field' ],
            'presshub_social_ai',
            'presshub_social_ai_sec'
        );

        add_settings_field(
            'presshub_social_ai_temperature',
            __( 'Temperature', 'presshub-social-autopost' ),
            [ $render, 'render_ai_temperature_field' ],
            'presshub_social_ai',
            'presshub_social_ai_sec'
        );

        add_settings_field(
            'presshub_social_ai_max_tokens',
            __( 'Max Tokens', 'presshub-social-autopost' ),
            [ $render, 'render_ai_max_tokens_field' ],
            'presshub_social_ai',
            'presshub_social_ai_sec'
        );

        add_settings_field(
            'presshub_social_ai_timeout',
            __( 'Request Timeout', 'presshub-social-autopost' ),
            [ $render, 'render_ai_timeout_field' ],
            'presshub_social_ai',
            'presshub_social_ai_sec'
        );

        // ------------------------------------------------------------------
        // Tab 3: API Credentials
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_meta_sec',
            __( 'Meta Graph API (Facebook & Instagram)', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_credentials'
        );

        add_settings_field(
            'presshub_social_meta_page_id',
            __( 'Facebook Page ID', 'presshub-social-autopost' ),
            [ $render, 'render_text_field' ],
            'presshub_social_credentials',
            'presshub_social_meta_sec',
            [ 'option' => 'presshub_social_meta_page_id', 'desc' => __( 'Numeric Facebook Page ID.', 'presshub-social-autopost' ) ]
        );

        add_settings_field(
            'presshub_social_meta_access_token',
            __( 'Meta Page Access Token', 'presshub-social-autopost' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_meta_sec',
            [
                'option'        => 'presshub_social_meta_access_token',
                'desc'          => __( 'Long-lived Page Access Token with pages_manage_posts and pages_read_engagement permissions.', 'presshub-social-autopost' ),
                'test_platform' => 'facebook',
            ]
        );

        add_settings_field(
            'presshub_social_instagram_account_id',
            __( 'Instagram Account ID', 'presshub-social-autopost' ),
            [ $render, 'render_text_field' ],
            'presshub_social_credentials',
            'presshub_social_meta_sec',
            [
                'option'        => 'presshub_social_instagram_account_id',
                'desc'          => __( 'Instagram Professional/Business Account ID linked to your Meta Page.', 'presshub-social-autopost' ),
                'test_platform' => 'instagram',
            ]
        );

        add_settings_section(
            'presshub_social_twitter_sec',
            __( 'X / Twitter API v2 Credentials', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_credentials'
        );

        add_settings_field(
            'presshub_social_twitter_bearer_token',
            __( 'Twitter Bearer Token', 'presshub-social-autopost' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_twitter_sec',
            [ 'option' => 'presshub_social_twitter_bearer_token' ]
        );

        add_settings_field(
            'presshub_social_twitter_api_key',
            __( 'Twitter API Key', 'presshub-social-autopost' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_twitter_sec',
            [ 'option' => 'presshub_social_twitter_api_key' ]
        );

        add_settings_field(
            'presshub_social_twitter_api_secret',
            __( 'Twitter API Secret', 'presshub-social-autopost' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_twitter_sec',
            [ 'option' => 'presshub_social_twitter_api_secret' ]
        );

        add_settings_field(
            'presshub_social_twitter_access_token',
            __( 'Twitter Access Token', 'presshub-social-autopost' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_twitter_sec',
            [ 'option' => 'presshub_social_twitter_access_token' ]
        );

        add_settings_field(
            'presshub_social_twitter_access_secret',
            __( 'Twitter Access Secret', 'presshub-social-autopost' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_twitter_sec',
            [
                'option'        => 'presshub_social_twitter_access_secret',
                'test_platform' => 'twitter',
            ]
        );

        add_settings_section(
            'presshub_social_tiktok_sec',
            __( 'TikTok API Credentials', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_credentials'
        );

        add_settings_field(
            'presshub_social_tiktok_access_token',
            __( 'TikTok Access Token', 'presshub-social-autopost' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_tiktok_sec',
            [
                'option'        => 'presshub_social_tiktok_access_token',
                'test_platform' => 'tiktok',
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_open_id',
            __( 'TikTok Creator Open ID', 'presshub-social-autopost' ),
            [ $render, 'render_text_field' ],
            'presshub_social_credentials',
            'presshub_social_tiktok_sec',
            [ 'option' => 'presshub_social_tiktok_open_id' ]
        );

        // ------------------------------------------------------------------
        // Tab 4: Automation Worker
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_worker_sec',
            __( 'Playwright Automation Worker', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_worker'
        );

        add_settings_field(
            'presshub_social_worker_mode',
            __( 'Worker Connection Mode', 'presshub-social-autopost' ),
            [ $render, 'render_worker_mode_field' ],
            'presshub_social_worker',
            'presshub_social_worker_sec'
        );

        add_settings_field(
            'presshub_social_worker_url',
            __( 'Worker Service Endpoint', 'presshub-social-autopost' ),
            [ $render, 'render_worker_url_field' ],
            'presshub_social_worker',
            'presshub_social_worker_sec'
        );

        add_settings_field(
            'presshub_social_worker_secret',
            __( 'Worker Secret Token', 'presshub-social-autopost' ),
            [ $render, 'render_worker_secret_field' ],
            'presshub_social_worker',
            'presshub_social_worker_sec'
        );

        add_settings_field(
            'presshub_social_worker_timeout',
            __( 'Worker Timeout', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_worker',
            'presshub_social_worker_sec',
            [
                'option' => 'presshub_social_worker_timeout',
                'min'    => 10,
                'max'    => 300,
                'unit'   => __( 'seconds', 'presshub-social-autopost' ),
                'desc'   => __( 'HTTP request timeout when waiting for worker response.', 'presshub-social-autopost' ),
            ]
        );

        add_settings_section(
            'presshub_social_media_specs_sec',
            __( 'Media & Video Specifications', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_worker'
        );

        add_settings_field(
            'presshub_social_twitter_max_chars',
            __( 'X/Twitter Max Characters', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_worker',
            'presshub_social_media_specs_sec',
            [
                'option' => 'presshub_social_twitter_max_chars',
                'min'    => 100,
                'max'    => 4000,
                'desc'   => __( 'Standard limit is 280; set up to 4000 if your account has X Premium.', 'presshub-social-autopost' ),
            ]
        );

        add_settings_field(
            'presshub_social_instagram_card_width',
            __( 'Instagram Card Width', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_worker',
            'presshub_social_media_specs_sec',
            [
                'option' => 'presshub_social_instagram_card_width',
                'min'    => 600,
                'max'    => 2160,
                'unit'   => 'px',
            ]
        );

        add_settings_field(
            'presshub_social_instagram_card_height',
            __( 'Instagram Card Height', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_worker',
            'presshub_social_media_specs_sec',
            [
                'option' => 'presshub_social_instagram_card_height',
                'min'    => 600,
                'max'    => 2160,
                'unit'   => 'px',
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_video_width',
            __( 'TikTok Video Width', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_worker',
            'presshub_social_media_specs_sec',
            [
                'option' => 'presshub_social_tiktok_video_width',
                'min'    => 540,
                'max'    => 2160,
                'unit'   => 'px',
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_video_height',
            __( 'TikTok Video Height', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_worker',
            'presshub_social_media_specs_sec',
            [
                'option' => 'presshub_social_tiktok_video_height',
                'min'    => 960,
                'max'    => 3840,
                'unit'   => 'px',
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_video_duration',
            __( 'TikTok Video Duration', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_worker',
            'presshub_social_media_specs_sec',
            [
                'option' => 'presshub_social_tiktok_video_duration',
                'min'    => 5,
                'max'    => 60,
                'unit'   => __( 'seconds', 'presshub-social-autopost' ),
            ]
        );

        // ------------------------------------------------------------------
        // Tab 5: Queue & Alerts
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_queue_sec',
            __( 'Queue Processing & Retry Budget', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_queue'
        );

        add_settings_field(
            'presshub_social_max_attempts',
            __( 'Max Retry Attempts', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_queue',
            'presshub_social_queue_sec',
            [
                'option' => 'presshub_social_max_attempts',
                'min'    => 1,
                'max'    => 10,
                'desc'   => __( 'Number of dispatch attempts before a job is marked failed.', 'presshub-social-autopost' ),
            ]
        );

        add_settings_field(
            'presshub_social_dispatch_interval',
            __( 'Dispatch Interval', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_queue',
            'presshub_social_queue_sec',
            [
                'option' => 'presshub_social_dispatch_interval',
                'min'    => 10,
                'max'    => 3600,
                'unit'   => __( 'seconds', 'presshub-social-autopost' ),
                'desc'   => __( 'Frequency for running the background cron queue dispatcher.', 'presshub-social-autopost' ),
            ]
        );

        add_settings_field(
            'presshub_social_batch_size',
            __( 'Batch Size', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_queue',
            'presshub_social_queue_sec',
            [
                'option' => 'presshub_social_batch_size',
                'min'    => 1,
                'max'    => 20,
                'desc'   => __( 'Maximum number of queue items processed per run.', 'presshub-social-autopost' ),
            ]
        );

        add_settings_field(
            'presshub_social_retry_base_delay',
            __( 'Retry Base Delay', 'presshub-social-autopost' ),
            [ $render, 'render_number_field' ],
            'presshub_social_queue',
            'presshub_social_queue_sec',
            [
                'option' => 'presshub_social_retry_base_delay',
                'min'    => 30,
                'max'    => 3600,
                'unit'   => __( 'seconds', 'presshub-social-autopost' ),
                'desc'   => __( 'Initial exponential backoff delay for retrying failed dispatches.', 'presshub-social-autopost' ),
            ]
        );

        add_settings_section(
            'presshub_social_alerts_sec',
            __( 'Failure Alerts', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_queue'
        );

        add_settings_field(
            'presshub_social_alert_email_enabled',
            __( 'Enable Failure Email Alerts', 'presshub-social-autopost' ),
            [ $render, 'render_alert_email_enabled_field' ],
            'presshub_social_queue',
            'presshub_social_alerts_sec'
        );

        add_settings_field(
            'presshub_social_alert_email_address',
            __( 'Alert Recipient Email', 'presshub-social-autopost' ),
            [ $render, 'render_text_field' ],
            'presshub_social_queue',
            'presshub_social_alerts_sec',
            [
                'option'      => 'presshub_social_alert_email_address',
                'placeholder' => 'admin@example.com',
                'desc'        => __( 'Leave empty to use the WordPress administration email address.', 'presshub-social-autopost' ),
            ]
        );

        // ------------------------------------------------------------------
        // Tab 6: Prompts & Templates
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_prompts_sec',
            __( 'System Prompt & Platform Copy Templates', 'presshub-social-autopost' ),
            '__return_empty_string',
            'presshub_social_prompts'
        );

        add_settings_field(
            'presshub_social_prompt_system',
            __( 'System Prompt (All Platforms)', 'presshub-social-autopost' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_prompts',
            'presshub_social_prompts_sec',
            [
                'option' => 'presshub_social_prompt_system',
                'rows'   => 6,
                'desc'   => __( 'Core journalistic persona and instructions delivered to the LLM.', 'presshub-social-autopost' ),
            ]
        );

        foreach ( PressHub_Social_Settings_Storage::PLATFORMS as $platform ) {
            add_settings_field(
                'presshub_social_prompt_' . $platform,
                ucfirst( $platform ) . ' ' . __( 'AI Prompt', 'presshub-social-autopost' ),
                [ $render, 'render_textarea_field' ],
                'presshub_social_prompts',
                'presshub_social_prompts_sec',
                [
                    'option' => 'presshub_social_prompt_' . $platform,
                    'rows'   => 5,
                    'desc'   => sprintf( __( 'Prompt template for %s. Placeholders: {title}, {excerpt}, {content}, {url}, {site_name}%s.', 'presshub-social-autopost' ), ucfirst( $platform ), 'twitter' === $platform ? ', {max_chars}' : '' ),
                ]
            );

            add_settings_field(
                'presshub_social_template_' . $platform,
                ucfirst( $platform ) . ' ' . __( 'Static Fallback Template', 'presshub-social-autopost' ),
                [ $render, 'render_textarea_field' ],
                'presshub_social_prompts',
                'presshub_social_prompts_sec',
                [
                    'option' => 'presshub_social_template_' . $platform,
                    'rows'   => 3,
                    'desc'   => sprintf( __( 'Static template used when copy mode is set to Template. Placeholders: {title}, {excerpt}, {url}, {site_name}, {hashtags}.', 'presshub-social-autopost' ) ),
                ]
            );
        }
    }
}
