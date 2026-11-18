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

require_once __DIR__ . '/class-capabilities.php';
require_once __DIR__ . '/class-settings-sections.php';

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
        return PressHub_Capabilities::social();
    }

    /**
     * URL of the Social publishing section of PressHub → Settings (#246),
     * optionally at a group anchor.
     */
    public static function settings_url( string $anchor = '' ): string {
        return PressHub_Settings_Sections::url( 'social-publishing', $anchor );
    }

    /**
     * Register admin menu and submenus.
     *
     * @return void
     */
    public function add_admin_menu(): void {
        $cap    = self::settings_cap();
        $parent = class_exists( 'PressHub_Admin_Menu' ) ? PressHub_Admin_Menu::PARENT : 'presshub-ai-briefing-hub';

        // #235: the Social Queue sits under the one PressHub menu.
        add_submenu_page(
            $parent,
            __( 'PressHub — Social Queue', 'presshub-ai-editor' ),
            __( 'Social Queue', 'presshub-ai-editor' ),
            $cap,
            self::MENU_SLUG,
            [ $this, 'render_queue_page' ]
        );

        // #246: Social settings are sections of PressHub → Settings now; the old
        // URL redirects there (PressHub_Admin_Menu::maybe_redirect_legacy).
    }

    /**
     * Render the main Social Queue page.
     *
     * @return void
     */
    public function render_queue_page(): void {
        if ( ! current_user_can( self::settings_cap() ) ) {
            wp_die( esc_html__( 'Unauthorized', 'presshub-ai-editor' ) );
        }

        if ( has_action( 'presshub_social_render_queue_page' ) ) {
            do_action( 'presshub_social_render_queue_page' );
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'PressHub Social Dispatch Queue', 'presshub-ai-editor' ); ?></h1>
            <p><?php echo esc_html__( 'Manage and monitor pending, publishing, and published social media posts.', 'presshub-ai-editor' ); ?></p>
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-top:15px;">
                <p><em><?php echo esc_html__( 'The queue management interface is active.', 'presshub-ai-editor' ); ?></em></p>
                <p>
                    <a href="<?php echo esc_url( self::settings_url() ); ?>" class="button button-primary">
                        <?php echo esc_html__( 'Configure Social Settings', 'presshub-ai-editor' ); ?>
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
            wp_die( esc_html__( 'Unauthorized', 'presshub-ai-editor' ) );
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? (string) $_GET['page'] : '';
        // #246: the reset link lives on PressHub → Settings → Social publishing; the old page slug still works.
        if ( self::SETTINGS_SLUG !== $page && PressHub_Settings_Sections::PAGE_SLUG !== $page ) {
            return;
        }
        if ( ! isset( $_GET['action'] ) || 'reset_prompts' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        if ( ! current_user_can( self::settings_cap() ) ) {
            wp_die( esc_html__( 'Unauthorized', 'presshub-ai-editor' ) );
        }
        check_admin_referer( 'presshub_social_reset_prompts' );

        PressHub_Social_Settings_Storage::reset_prompts_to_defaults();
        PressHub_Social_Logger::info( 'Operator reset social prompts and templates to asset defaults.' );

        wp_safe_redirect( add_query_arg( 'message', 'prompts_reset', self::settings_url( 'post-copy' ) ) );
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
            __( 'General Publishing Configuration', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_general'
        );

        add_settings_field(
            'presshub_social_auto_publish_enabled',
            __( 'Auto-Publish on Post', 'presshub-ai-editor' ),
            [ $render, 'render_auto_publish_enabled_field' ],
            'presshub_social_general',
            'presshub_social_general_sec'
        );

        add_settings_field(
            'presshub_social_enabled_platforms',
            __( 'Active Platforms', 'presshub-ai-editor' ),
            [ $render, 'render_enabled_platforms_field' ],
            'presshub_social_general',
            'presshub_social_general_sec'
        );

        add_settings_field(
            'presshub_social_copy_mode',
            __( 'Copy Generation Mode', 'presshub-ai-editor' ),
            [ $render, 'render_copy_mode_field' ],
            'presshub_social_general',
            'presshub_social_general_sec'
        );

        // #233: unused, listed under Advanced & System → Diagnostics → Scheduled for removal.
        if ( ! PressHub_Settings_Registry::is_unused( 'presshub_social_github_token' ) ) {
            add_settings_field(
                'presshub_social_github_token',
                __( 'GitHub Personal Access Token (Auto-Updates)', 'presshub-ai-editor' ),
                [ $render, 'render_github_token_field' ],
                'presshub_social_general',
                'presshub_social_general_sec'
            );
        }

        add_settings_section(
            'presshub_social_engines_sec',
            __( 'Platform Dispatch Engines', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_general'
        );

        foreach ( PressHub_Social_Settings_Storage::PLATFORMS as $platform ) {
            add_settings_field(
                'presshub_social_engine_' . $platform,
                ucfirst( $platform ) . ' ' . __( 'Engine', 'presshub-ai-editor' ),
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
            __( 'AI Copywriter', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_ai'
        );

        // #236: the provider, model and parameters are edited in Settings →
        // AI models; this shows which model writes the copy, with a link.
        add_settings_field(
            'presshub_social_ai_model_line',
            __( 'Model', 'presshub-ai-editor' ),
            [ $render, 'render_model_line' ],
            'presshub_social_ai',
            'presshub_social_ai_sec',
            [ 'task' => 'social_copy' ]
        );

        add_settings_section(
            'presshub_social_post_images_sec',
            __( 'AI Post Images', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_ai'
        );

        add_settings_field(
            'presshub_social_post_images_enabled',
            __( 'Generate Images for Posts', 'presshub-ai-editor' ),
            [ $render, 'render_post_images_enabled_field' ],
            'presshub_social_ai',
            'presshub_social_post_images_sec'
        );

        $image_platforms = [
            'twitter'   => [ __( 'X / Twitter Images', 'presshub-ai-editor' ), __( 'Landscape 16:9 images attached to the post (up to 4). An attached image replaces X\'s link preview card; the article link stays in the text.', 'presshub-ai-editor' ) ],
            'facebook'  => [ __( 'Facebook Images', 'presshub-ai-editor' ), __( 'Landscape 16:9 images (up to 4; more than 1 makes a multi-photo post). X and Facebook share the same images, so the second platform costs nothing extra.', 'presshub-ai-editor' ) ],
            'instagram' => [ __( 'Instagram Image', 'presshub-ai-editor' ), __( 'One square 1:1 image used as the background of the Instagram card. Reels from podcast shorts keep their own story images.', 'presshub-ai-editor' ) ],
            'tiktok'    => [ __( 'TikTok Image', 'presshub-ai-editor' ), __( 'One vertical 9:16 image used as the background of the TikTok video of an article. Podcast shorts keep their own story images.', 'presshub-ai-editor' ) ],
        ];
        foreach ( $image_platforms as $image_platform => [ $label, $desc ] ) {
            add_settings_field(
                'presshub_social_post_images_' . $image_platform,
                $label,
                [ $render, 'render_number_field' ],
                'presshub_social_ai',
                'presshub_social_post_images_sec',
                [
                    'option'  => 'presshub_social_post_images_' . $image_platform,
                    'default' => 1,
                    'min'     => 0,
                    'max'     => PressHub_Social_Settings_Storage::post_images_max( $image_platform ),
                    'unit'    => __( 'images', 'presshub-ai-editor' ),
                    'desc'    => $desc . ' ' . __( '0 keeps the featured image.', 'presshub-ai-editor' ),
                ]
            );
        }

        // ------------------------------------------------------------------
        // Tab 3: API Credentials
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_meta_sec',
            __( 'Meta Graph API (Facebook & Instagram)', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_credentials'
        );

        add_settings_field(
            'presshub_social_meta_page_id',
            __( 'Facebook Page ID', 'presshub-ai-editor' ),
            [ $render, 'render_text_field' ],
            'presshub_social_credentials',
            'presshub_social_meta_sec',
            [ 'option' => 'presshub_social_meta_page_id', 'desc' => __( 'Numeric Facebook Page ID.', 'presshub-ai-editor' ) ]
        );

        add_settings_field(
            'presshub_social_meta_access_token',
            __( 'Meta Page Access Token', 'presshub-ai-editor' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_meta_sec',
            [
                'option'        => 'presshub_social_meta_access_token',
                'desc'          => __( 'Long-lived Page Access Token with pages_manage_posts and pages_read_engagement permissions.', 'presshub-ai-editor' ),
                'test_platform' => 'facebook',
            ]
        );

        add_settings_field(
            'presshub_social_instagram_account_id',
            __( 'Instagram Account ID', 'presshub-ai-editor' ),
            [ $render, 'render_text_field' ],
            'presshub_social_credentials',
            'presshub_social_meta_sec',
            [
                'option'        => 'presshub_social_instagram_account_id',
                'desc'          => __( 'Instagram Professional/Business Account ID linked to your Meta Page.', 'presshub-ai-editor' ),
                'test_platform' => 'instagram',
            ]
        );

        add_settings_section(
            'presshub_social_twitter_sec',
            __( 'X / Twitter API v2 Credentials', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_credentials'
        );

        add_settings_field(
            'presshub_social_twitter_bearer_token',
            __( 'Twitter Bearer Token', 'presshub-ai-editor' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_twitter_sec',
            [ 'option' => 'presshub_social_twitter_bearer_token' ]
        );

        // #233: unused, listed under Advanced & System → Diagnostics → Scheduled for removal.
        if ( ! PressHub_Settings_Registry::is_unused( 'presshub_social_twitter_api_key' ) ) {
            add_settings_field(
                'presshub_social_twitter_api_key',
                __( 'Twitter API Key', 'presshub-ai-editor' ),
                [ $render, 'render_secret_field' ],
                'presshub_social_credentials',
                'presshub_social_twitter_sec',
                [ 'option' => 'presshub_social_twitter_api_key' ]
            );
        }

        // #233: unused, listed under Advanced & System → Diagnostics → Scheduled for removal.
        if ( ! PressHub_Settings_Registry::is_unused( 'presshub_social_twitter_api_secret' ) ) {
            add_settings_field(
                'presshub_social_twitter_api_secret',
                __( 'Twitter API Secret', 'presshub-ai-editor' ),
                [ $render, 'render_secret_field' ],
                'presshub_social_credentials',
                'presshub_social_twitter_sec',
                [ 'option' => 'presshub_social_twitter_api_secret' ]
            );
        }

        add_settings_field(
            'presshub_social_twitter_access_token',
            __( 'Twitter Access Token', 'presshub-ai-editor' ),
            [ $render, 'render_secret_field' ],
            'presshub_social_credentials',
            'presshub_social_twitter_sec',
            [
                'option'        => 'presshub_social_twitter_access_token',
                'test_platform' => PressHub_Settings_Registry::is_unused( 'presshub_social_twitter_access_secret' ) ? 'twitter' : '',
            ]
        );

        // #233: unused, listed under Advanced & System → Diagnostics → Scheduled for removal.
        if ( ! PressHub_Settings_Registry::is_unused( 'presshub_social_twitter_access_secret' ) ) {
            add_settings_field(
                'presshub_social_twitter_access_secret',
                __( 'Twitter Access Secret', 'presshub-ai-editor' ),
                [ $render, 'render_secret_field' ],
                'presshub_social_credentials',
                'presshub_social_twitter_sec',
                [
                    'option'        => 'presshub_social_twitter_access_secret',
                    'test_platform' => 'twitter',
                ]
            );
        }

        add_settings_section(
            'presshub_social_tiktok_sec',
            __( 'TikTok API Credentials', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_credentials'
        );

        add_settings_field(
            'presshub_social_tiktok_access_token',
            __( 'TikTok Access Token', 'presshub-ai-editor' ),
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
            __( 'TikTok Creator Open ID', 'presshub-ai-editor' ),
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
            __( 'Playwright Automation Worker', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_worker'
        );

        add_settings_field(
            'presshub_social_worker_mode',
            __( 'Worker Connection Mode', 'presshub-ai-editor' ),
            [ $render, 'render_worker_mode_field' ],
            'presshub_social_worker',
            'presshub_social_worker_sec'
        );

        add_settings_field(
            'presshub_social_worker_url',
            __( 'Worker Service Endpoint', 'presshub-ai-editor' ),
            [ $render, 'render_worker_url_field' ],
            'presshub_social_worker',
            'presshub_social_worker_sec'
        );

        add_settings_field(
            'presshub_social_worker_secret',
            __( 'Worker Secret Token', 'presshub-ai-editor' ),
            [ $render, 'render_worker_secret_field' ],
            'presshub_social_worker',
            'presshub_social_worker_sec'
        );

        add_settings_field(
            'presshub_social_worker_timeout',
            __( 'Worker Timeout', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_worker',
            'presshub_social_worker_sec',
            [
                'option' => 'presshub_social_worker_timeout',
                'push_only' => true, // #234: read-only with a reason in Pull mode.
                'min'    => 10,
                'max'    => 300,
                'unit'   => __( 'seconds', 'presshub-ai-editor' ),
                'desc'   => __( 'HTTP request timeout when waiting for worker response.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_section(
            'presshub_social_media_specs_sec',
            __( 'Images & video', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_ai'
        );

        // #234: Shorts & Reels tab.
        add_settings_section( 'presshub_social_shorts_where_sec', __( 'Where to post', 'presshub-ai-editor' ), [ $render, 'render_shorts_where_intro' ], 'presshub_social_shorts' );
        add_settings_section( 'presshub_social_shorts_script_sec', __( 'Schedule & script', 'presshub-ai-editor' ), '__return_empty_string', 'presshub_social_shorts' );
        add_settings_section( 'presshub_social_shorts_narration_sec', __( 'Narration', 'presshub-ai-editor' ), [ $render, 'render_shorts_narration_intro' ], 'presshub_social_shorts' );
        add_settings_section( 'presshub_social_shorts_music_sec', __( 'Music', 'presshub-ai-editor' ), '__return_empty_string', 'presshub_social_shorts' );
        add_settings_section( 'presshub_social_shorts_prompts_sec', '', '__return_empty_string', 'presshub_social_shorts_prompts' );

        add_settings_field(
            'presshub_social_twitter_max_chars',
            __( 'X/Twitter Max Characters', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_general',
            'presshub_social_engines_sec',
            [
                'option' => 'presshub_social_twitter_max_chars',
                'min'    => 280,
                'max'    => 4000,
                'desc'   => __( 'Standard limit is 280; set up to 4000 if your account has X Premium.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_instagram_card_width',
            __( 'Instagram Card Width', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_ai',
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
            __( 'Instagram Card Height', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_ai',
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
            __( 'TikTok Video Width', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_ai',
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
            __( 'TikTok Video Height', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_ai',
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
            __( 'TikTok Video Duration', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_ai',
            'presshub_social_media_specs_sec',
            [
                'option' => 'presshub_social_tiktok_video_duration',
                'min'    => 5,
                'max'    => 600,
                'unit'   => __( 'seconds', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_narration_enabled',
            __( 'TikTok Narration (TTS)', 'presshub-ai-editor' ),
            [ $render, 'render_tiktok_narration_enabled_field' ],
            'presshub_social_ai',
            'presshub_social_media_specs_sec'
        );

        add_settings_field(
            'presshub_social_tiktok_narration_outro',
            __( 'TikTok Narration Outro', 'presshub-ai-editor' ),
            [ $render, 'render_text_field' ],
            'presshub_social_ai',
            'presshub_social_media_specs_sec',
            [
                'option'      => 'presshub_social_tiktok_narration_outro',
                'default'     => PressHub_Social_TikTok_Narrator::DEFAULT_OUTRO,
                'placeholder' => PressHub_Social_TikTok_Narrator::DEFAULT_OUTRO,
                'desc'        => __( 'Spoken at the end of every TikTok narration. Leave empty to use the default.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_narration_voice',
            __( 'TikTok Narration Voice', 'presshub-ai-editor' ),
            [ $render, 'render_text_field' ],
            'presshub_social_ai',
            'presshub_social_media_specs_sec',
            [
                'option'      => 'presshub_social_tiktok_narration_voice',
                'placeholder' => 'Kore',
                'desc'        => __( 'Gemini voice name (e.g. Kore, Puck). Leave empty to use the Daily Briefing Presenter 1 voice.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_enabled',
            __( 'TikTok Shorts', 'presshub-ai-editor' ),
            [ $render, 'render_tiktok_shorts_enabled_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_where_sec'
        );

        add_settings_field(
            'presshub_social_instagram_shorts_enabled',
            __( 'Instagram Reels (Shorts)', 'presshub-ai-editor' ),
            [ $render, 'render_instagram_shorts_enabled_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_where_sec'
        );

        add_settings_field(
            'presshub_social_tiktok_article_shorts_enabled',
            __( 'Article Shorts', 'presshub-ai-editor' ),
            [ $render, 'render_tiktok_article_shorts_enabled_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_where_sec'
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_count',
            __( 'Shorts per Podcast', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_script_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_count',
                'default' => 3,
                'min'     => 1,
                'max'     => 5,
                'desc'    => __( 'How many top stories of each podcast become their own TikTok video.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_spacing_minutes',
            __( 'Time Between Shorts', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_script_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_spacing_minutes',
                'default' => 90,
                'min'     => 0,
                'max'     => 720,
                'unit'    => __( 'minutes', 'presshub-ai-editor' ),
                'desc'    => __( 'The first short is posted when the podcast is published, the next ones this many minutes apart. Shorts from an earlier edition that have not been posted yet are cancelled when the next podcast is published.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_max_words',
            __( 'Short Script Length', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_script_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_max_words',
                'default' => 130,
                'min'     => 60,
                'max'     => 250,
                'unit'    => __( 'words', 'presshub-ai-editor' ),
                'desc'    => __( 'Word cap for one short. 130 words is about 50 seconds of speech.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_images',
            __( 'Story Images per Short', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_script_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_images',
                'default' => 3,
                'min'     => 0,
                'max'     => 4,
                'unit'    => __( 'images', 'presshub-ai-editor' ),
                'desc'    => __( 'AI images made for each short with the site\'s image generator (9:16), shown in turn behind the captions. 0 uses a branded background. The podcast\'s featured image is never used.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_caption_words',
            __( 'Short Post Text Length', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_script_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_caption_words',
                'default' => 120,
                'min'     => 40,
                'max'     => 300,
                'unit'    => __( 'words', 'presshub-ai-editor' ),
                'desc'    => __( 'Length of the text posted with each short (a summary of the story, before the hashtags). Longer texts are found more often in TikTok search; 120 words is 4-6 short sentences.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_narrators',
            __( 'Short Narrators', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_narration_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_narrators',
                'default' => 1,
                'min'     => 1,
                'max'     => 2,
                'unit'    => __( 'voices', 'presshub-ai-editor' ),
                'desc'    => __( 'How many voices read each short. 1 (recommended) is one presenter talking straight to the viewer, read in one go for a natural flow. 2 alternates two voices like the podcast. Independent of the Daily Briefing host count.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_voice_1',
            __( 'Short Narrator Voice', 'presshub-ai-editor' ),
            [ $render, 'render_tiktok_shorts_voice_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_narration_sec',
            [
                'narrator'    => 1,
                'option'      => 'presshub_social_tiktok_shorts_voice_1',
                'default'     => PressHub_Social_Settings_Storage::DEFAULT_TIKTOK_SHORTS_VOICE_1,
                'placeholder' => __( 'Daily Briefing presenter 1 voice', 'presshub-ai-editor' ),
                'desc'        => __( 'Gemini TTS voice of the (first) narrator; the list has all 30 Gemini voices with their character. Lively choices: Puck (upbeat, male), Laomedeia (upbeat, female), Fenrir (excitable), Sadachbia (lively), Zephyr (bright). With the Google Cloud engine this is a text field for a Cloud voice such as el-GR-Chirp3-HD-Achird. "Daily Briefing presenter voice" (or an empty field) uses the Daily Briefing presenter 1 voice.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_voice_2',
            __( 'Second Narrator Voice', 'presshub-ai-editor' ),
            [ $render, 'render_tiktok_shorts_voice_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_narration_sec',
            [
                'narrator'    => 2,
                'option'      => 'presshub_social_tiktok_shorts_voice_2',
                'default'     => '',
                'placeholder' => __( 'Daily Briefing presenter 2 voice', 'presshub-ai-editor' ),
                'desc'        => __( 'Used only with 2 narrators. Pick a voice that sounds clearly different from the first. Empty uses the Daily Briefing presenter 2 voice.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_voice_speed',
            __( 'Short Voice Speed', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_narration_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_voice_speed',
                'default' => 110,
                'min'     => 70,
                'max'     => 150,
                'step'    => 5,
                'unit'    => '%',
                'desc'    => __( 'Speaking rate of the shorts, in percent of normal. Short videos keep attention with a slightly faster pace; 110% is brisk but clear. The tone is set in Shorts prompts → Narration Style, at the bottom of this tab.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_music_enabled',
            __( 'Shorts Background Music', 'presshub-ai-editor' ),
            [ $render, 'render_tiktok_shorts_music_enabled_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_music_sec'
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_music_volume',
            __( 'Music Volume', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_music_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_music_volume',
                'default' => 15,
                'min'     => 0,
                'max'     => 60,
                'unit'    => '%',
                'desc'    => __( 'Level of the music under the voice, in percent of its full volume. It dips further while someone speaks and fades out at the end. 15% is a quiet bed; 0 mutes it.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_music_tracks',
            __( 'Music Tracks per Mood', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_music_sec',
            [
                'option'  => 'presshub_social_tiktok_shorts_music_tracks',
                'default' => 2,
                'min'     => 1,
                'max'     => 5,
                'unit'    => __( 'tracks', 'presshub-ai-editor' ),
                'desc'    => __( 'How many tracks Generate Music Tracks makes for each mood. Shorts of the same mood take turns between them.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_music_model_line',
            __( 'Music model', 'presshub-ai-editor' ),
            [ $render, 'render_model_line' ],
            'presshub_social_shorts',
            'presshub_social_shorts_music_sec',
            [ 'task' => 'music' ]
        );

        add_settings_field(
            'presshub_social_tiktok_shorts_music_library',
            __( 'Music Tracks', 'presshub-ai-editor' ),
            [ $render, 'render_tiktok_shorts_music_library_field' ],
            'presshub_social_shorts',
            'presshub_social_shorts_music_sec'
        );

        // ------------------------------------------------------------------
        // Tab 5: Queue & Alerts
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_queue_sec',
            __( 'Queue Processing & Retry Budget', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_queue'
        );

        add_settings_field(
            'presshub_social_max_attempts',
            __( 'Max Retry Attempts', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_queue',
            'presshub_social_queue_sec',
            [
                'option' => 'presshub_social_max_attempts',
                'min'    => 1,
                'max'    => 10,
                'desc'   => __( 'Number of dispatch attempts before a job is marked failed.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_dispatch_interval',
            __( 'Dispatch Interval', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_queue',
            'presshub_social_queue_sec',
            [
                'option' => 'presshub_social_dispatch_interval',
                'min'    => 10,
                'max'    => 3600,
                'unit'   => __( 'seconds', 'presshub-ai-editor' ),
                'desc'   => __( 'Frequency for running the background cron queue dispatcher.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_batch_size',
            __( 'Batch Size', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_queue',
            'presshub_social_queue_sec',
            [
                'option' => 'presshub_social_batch_size',
                'min'    => 1,
                'max'    => 20,
                'desc'   => __( 'Maximum number of queue items processed per run.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_retry_base_delay',
            __( 'Retry Base Delay', 'presshub-ai-editor' ),
            [ $render, 'render_number_field' ],
            'presshub_social_queue',
            'presshub_social_queue_sec',
            [
                'option' => 'presshub_social_retry_base_delay',
                'min'    => 30,
                'max'    => 3600,
                'unit'   => __( 'seconds', 'presshub-ai-editor' ),
                'desc'   => __( 'Initial exponential backoff delay for retrying failed dispatches.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_section(
            'presshub_social_alerts_sec',
            __( 'Failure Alerts', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_queue'
        );

        add_settings_field(
            'presshub_social_alert_email_enabled',
            __( 'Enable Failure Email Alerts', 'presshub-ai-editor' ),
            [ $render, 'render_alert_email_enabled_field' ],
            'presshub_social_queue',
            'presshub_social_alerts_sec'
        );

        add_settings_field(
            'presshub_social_alert_email_address',
            __( 'Alert Recipient Email', 'presshub-ai-editor' ),
            [ $render, 'render_text_field' ],
            'presshub_social_queue',
            'presshub_social_alerts_sec',
            [
                'option'      => 'presshub_social_alert_email_address',
                'placeholder' => 'admin@example.com',
                'desc'        => __( 'Leave empty to use the WordPress administration email address.', 'presshub-ai-editor' ),
            ]
        );

        // ------------------------------------------------------------------
        // Tab 6: Prompts & Templates
        // ------------------------------------------------------------------
        add_settings_section(
            'presshub_social_prompts_sec',
            __( 'System Prompt & Platform Copy Templates', 'presshub-ai-editor' ),
            '__return_empty_string',
            'presshub_social_prompts'
        );

        add_settings_field(
            'presshub_social_prompt_system',
            __( 'System Prompt (All Platforms)', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_prompts',
            'presshub_social_prompts_sec',
            [
                'option' => 'presshub_social_prompt_system',
                'rows'   => 6,
                'desc'   => __( 'Core journalistic persona and instructions delivered to the LLM.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_prompt_post_images',
            __( 'AI Post Images: Planning Prompt', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_prompts',
            'presshub_social_prompts_sec',
            [
                'option' => 'presshub_social_prompt_post_images',
                'rows'   => 8,
                'desc'   => __( 'Used when AI Post Images are on (AI Copywriter tab). One call per article writes the image descriptions as JSON {"images":["...", ...]}, shared by every platform. Placeholders: {count} (the most images any platform asks for), {title}, {content} (the article text), {site_name}. Keep the rules that leave real people and text out of the images.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_prompt_post_image_style',
            __( 'AI Post Images: Image Prompt', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_prompts',
            'presshub_social_prompts_sec',
            [
                'option' => 'presshub_social_prompt_post_image_style',
                'rows'   => 3,
                'desc'   => __( 'Sent to the image generator for each post image. {description} is one description from the Planning Prompt, {orientation} the shape for the platform (e.g. "landscape 16:9"). This text sets the look. Without {description} the description is put first.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_prompt_tiktok_shorts',
            __( 'TikTok Shorts: Script Prompt', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_shorts_prompts',
            'presshub_social_shorts_prompts_sec',
            [
                'option' => 'presshub_social_prompt_tiktok_shorts',
                'rows'   => 10,
                'desc'   => __( 'Used only when TikTok Shorts are on, for podcast posts. One call writes every short as JSON {"shorts":[{"topic","hook","script","visuals","mood","caption"}]}: the spoken script, the on-screen hook, the image descriptions, the mood of the background music and the caption. Placeholders from Automation Worker → Media & Video Specifications: {count} (Shorts per Podcast), {max_words} (Short Script Length), {image_count} (Story Images per Short), {caption_words} (Short Post Text Length), {moods} (the moods of TikTok Shorts: Music Prompts, plus none), {speakers} (Short Narrators). From the podcast post: {title}, {date}, {script} (the podcast script). {site_name} is the site title.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_prompt_tiktok_article_short',
            __( 'Article Shorts: Script Prompt', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_shorts_prompts',
            'presshub_social_shorts_prompts_sec',
            [
                'option' => 'presshub_social_prompt_tiktok_article_short',
                'rows'   => 10,
                'desc'   => __( 'Used for posts that are not podcasts (articles, opinion pieces) when Article Shorts are on. One call writes the article\'s one short as JSON {"shorts":[{"topic","hook","script","visuals","mood","caption"}]}. The post text is still written by the TikTok or Instagram Caption Prompt; this caption is used only when that one fails. Placeholders: {max_words}, {image_count}, {caption_words}, {moods}, {speakers} as in the Script Prompt above. From the article: {title}, {category}, {excerpt}, {content} (the article text), {date}. {site_name} is the site title. Emptied, articles are posted the classic way.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_prompt_tiktok_shorts_image_style',
            __( 'TikTok Shorts: Image Prompt', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_shorts_prompts',
            'presshub_social_shorts_prompts_sec',
            [
                'option' => 'presshub_social_prompt_tiktok_shorts_image_style',
                'rows'   => 3,
                'desc'   => __( 'Sent to the image generator for each story image of a short (9:16). {description} is one image description written by the Script Prompt (its "visuals" rules decide what the images show); this text sets the look. Without {description} the description is put first. Empty sends the description alone.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_prompt_tiktok_shorts_image_moods',
            __( 'TikTok Shorts: Image Styles per Mood', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_shorts_prompts',
            'presshub_social_shorts_prompts_sec',
            [
                'option' => 'presshub_social_prompt_tiktok_shorts_image_moods',
                'rows'   => 5,
                'desc'   => __( 'One line per mood, "mood: style", written like the Image Prompt ({description} is the image description). A short uses the line of the mood its Script Prompt chose; a mood without a line (by default serious, urgent and none) uses the Image Prompt above. The defaults give neutral and upbeat stories bold editorial illustrations. Keep real people out of the images.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_prompt_tiktok_shorts_tts_style',
            __( 'TikTok Shorts: Narration Style', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_shorts_prompts',
            'presshub_social_shorts_prompts_sec',
            [
                'option' => 'presshub_social_prompt_tiktok_shorts_tts_style',
                'rows'   => 4,
                'desc'   => __( 'How the voice reads the shorts: tone, energy, pace and emotion, as an instruction to the Gemini TTS (write more than a few words). The default is an energetic, warm TikTok presenter. Empty uses the Daily Briefing speaking style.', 'presshub-ai-editor' ),
            ]
        );

        add_settings_field(
            'presshub_social_prompt_tiktok_shorts_music',
            __( 'TikTok Shorts: Music Prompts', 'presshub-ai-editor' ),
            [ $render, 'render_textarea_field' ],
            'presshub_social_shorts_prompts',
            'presshub_social_shorts_prompts_sec',
            [
                'option' => 'presshub_social_prompt_tiktok_shorts_music',
                'rows'   => 6,
                'desc'   => __( 'One line per mood, "mood: prompt". Each prompt is sent to Google Lyria to make that mood\'s background tracks (Automation Worker → Generate Music Tracks). The mood names are what the Script Prompt may choose ({moods}); "none" always means no music. A changed line makes that mood\'s tracks again on the next generate.', 'presshub-ai-editor' ),
            ]
        );

        $platform_labels = [
            'twitter'   => 'X/Twitter',
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok'    => 'TikTok',
        ];
        foreach ( PressHub_Social_Settings_Storage::PLATFORMS as $platform ) {
            $label    = $platform_labels[ $platform ] ?? ucfirst( $platform );
            $use_note = 'tiktok' === $platform
                ? __( ' Writes the caption of a normal TikTok post: an article, or the whole podcast when TikTok Shorts are off. Shorts write their own captions with the Shorts Script Prompt.', 'presshub-ai-editor' )
                : '';
            add_settings_field(
                'presshub_social_prompt_' . $platform,
                $label . ' ' . __( 'Caption Prompt', 'presshub-ai-editor' ),
                [ $render, 'render_textarea_field' ],
                'presshub_social_prompts',
                'presshub_social_prompts_sec',
                [
                    'option' => 'presshub_social_prompt_' . $platform,
                    'rows'   => 5,
                    // translators: %1$s: social platform label, %2$s: optional note, %3$s: optional extra placeholder for Twitter
                    'desc'   => sprintf( __( 'Writes the %1$s post copy with AI.%2$s Placeholders from the post: {title}, {excerpt}, {content}, {url}, {hashtags}. {site_name} is the site title.%3$s', 'presshub-ai-editor' ), $label, $use_note, 'twitter' === $platform ? __( ' {max_chars} is X/Twitter Max Characters (Automation Worker → Media & Video Specifications).', 'presshub-ai-editor' ) : '' ),
                ]
            );

            add_settings_field(
                'presshub_social_template_' . $platform,
                $label . ' ' . __( 'Static Fallback Template', 'presshub-ai-editor' ),
                [ $render, 'render_textarea_field' ],
                'presshub_social_prompts',
                'presshub_social_prompts_sec',
                [
                    'option' => 'presshub_social_template_' . $platform,
                    'rows'   => 3,
                    'desc'   => sprintf( __( 'Static template used when copy mode is set to Template. Placeholders: {title}, {excerpt}, {url}, {site_name}, {hashtags}.', 'presshub-ai-editor' ) ),
                ]
            );
        }
    }
}
