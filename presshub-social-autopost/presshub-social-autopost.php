<?php
/**
 * Plugin Name: PressHub Social Auto-Post
 * Plugin URI: https://presshub.ai
 * Description: Automated multi-platform social media auto-posting pipeline for WordPress with AI copy generation, queue dispatcher, Meta API, and browser automation worker integration.
 * Version: 2.8.3
 * Author: PressHub Team
 * Author URI: https://presshub.ai
 * Text Domain: presshub-social-autopost
 * License: GPL v2 or later
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// If PressHub AI Co-Pilot v2.7.2+ (or v2.8.0+) is loaded, Social Auto-Post is natively integrated.
if ( defined( 'PRESSHUB_AI_VERSION' ) && version_compare( PRESSHUB_AI_VERSION, '2.7.2', '>=' ) ) {
    add_action( 'admin_notices', function() {
        if ( current_user_can( 'activate_plugins' ) ) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>PressHub Social Auto-Post</strong> is now natively included within <strong>PressHub AI Co-Pilot</strong>. You may safely deactivate and delete this standalone plugin without losing any data or configuration.</p>
            </div>
            <?php
        }
    } );
    return;
}

// Plugin constants
define( 'PRESSHUB_SOCIAL_VERSION', '2.8.3' );
define( 'PRESSHUB_SOCIAL_FILE', __FILE__ );
define( 'PRESSHUB_SOCIAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'PRESSHUB_SOCIAL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload / require all plugin classes in includes/
 */
$includes = [
    'class-social-logger.php',
    'class-social-prompt-loader.php',
    'class-social-settings-storage.php',
    'class-social-settings-render.php',
    'class-social-settings.php',
    'class-social-ai-copywriter.php',
    'class-social-activator.php',
    'class-social-queue.php',
    'class-social-meta-api-client.php',
    'class-social-worker-client.php',
    'class-social-dispatcher.php',
    'class-social-metabox.php',
    'class-social-queue-admin.php',
    'class-social-ajax-handlers.php',
    'class-social-rest-controller.php',
];

foreach ( $includes as $file ) {
    $filepath = PRESSHUB_SOCIAL_PATH . 'includes/' . $file;
    if ( file_exists( $filepath ) ) {
        require_once $filepath;
    }
}

/**
 * Register REST API routes.
 */
add_action( 'rest_api_init', [ 'PressHub_Social_REST_Controller', 'register_routes' ] );

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook( __FILE__, [ 'PressHub_Social_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PressHub_Social_Activator', 'deactivate' ] );

/**
 * Register custom cron schedule interval.
 */
add_filter( 'cron_schedules', function( array $schedules ): array {
    if ( ! isset( $schedules['presshub_social_interval'] ) ) {
        $schedules['presshub_social_interval'] = [
            'interval' => 60,
            'display'  => did_action( 'init' ) ? __( 'Every Minute (PressHub Social)', 'presshub-social-autopost' ) : 'Every Minute (PressHub Social)',
        ];
    }
    return $schedules;
} );

/**
 * Hook cron action to dispatcher.
 */
add_action( 'presshub_social_cron_dispatch', [ 'PressHub_Social_Dispatcher', 'process_due_queue' ] );

/**
 * Hook post status transition for auto-posting (priority 20 runs after editorial workflow gate).
 */
add_action( 'transition_post_status', [ 'PressHub_Social_Dispatcher', 'handle_post_status_transition' ], 20, 3 );

/**
 * Initialize GitHub Update Checker (embedded library, matching presshub-ai-editor).
 * PRESSHUB_AI_SKIP_UPDATE_CHECKER / PRESSHUB_SOCIAL_SKIP_UPDATE_CHECKER lets hosts
 * and the unit-test harness disable the embedded updater.
 */
if ( ! defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' )
    && ! defined( 'PRESSHUB_SOCIAL_SKIP_UPDATE_CHECKER' )
    && file_exists( PRESSHUB_SOCIAL_PATH . 'includes/plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once PRESSHUB_SOCIAL_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';

    $presshub_social_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/eeftychiou/presshub/',
        __FILE__,
        'presshub-social-autopost'
    );

    $presshub_social_update_checker->setBranch( 'main' );
    $presshub_social_update_checker->getVcsApi()->enableReleaseAssets( '/^presshub-social-autopost.*\.zip$/i' );

    $social_github_token = class_exists( 'PressHub_Social_Settings_Storage' )
        ? PressHub_Social_Settings_Storage::get_github_token()
        : (string) get_option( 'presshub_ai_github_token', '' );
    if ( ! empty( $social_github_token ) ) {
        $presshub_social_update_checker->setAuthentication( $social_github_token );
    }
}

/**
 * Force canonical plugin folder name during upgrades.
 */
add_filter(
    'upgrader_package_options',
    function ( $options ) {
        if ( empty( $options['hook_extra']['plugin'] ) || ! is_string( $options['hook_extra']['plugin'] ) ) {
            return $options;
        }
        if ( false !== strpos( $options['hook_extra']['plugin'], 'presshub-social-autopost.php' ) ) {
            $options['destination_name'] = 'presshub-social-autopost';
        }
        return $options;
    }
);

/**
 * Initialize components.
 */
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'seed_defaults' ) ) {
        PressHub_Social_Settings_Storage::seed_defaults();
    }
    if ( class_exists( 'PressHub_Social_Settings' ) && method_exists( 'PressHub_Social_Settings', 'init' ) ) {
        PressHub_Social_Settings::init();
    }
    if ( class_exists( 'PressHub_Social_Metabox' ) && method_exists( 'PressHub_Social_Metabox', 'init' ) ) {
        PressHub_Social_Metabox::init();
    }
    if ( class_exists( 'PressHub_Social_Queue_Admin' ) && method_exists( 'PressHub_Social_Queue_Admin', 'init' ) ) {
        PressHub_Social_Queue_Admin::init();
    }
    if ( class_exists( 'PressHub_Social_Ajax_Handlers' ) && method_exists( 'PressHub_Social_Ajax_Handlers', 'init' ) ) {
        PressHub_Social_Ajax_Handlers::init();
    }
} );

/**
 * Enqueue admin scripts and stylesheets.
 */
add_action( 'admin_enqueue_scripts', function( string $hook_suffix ) {
    $is_post_edit = in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true );
    $is_queue_page = str_contains( $hook_suffix, 'presshub-social-queue' );
    $is_settings_page = str_contains( $hook_suffix, 'presshub_social' ) || str_contains( $hook_suffix, 'presshub-social' );

    if ( ! $is_post_edit && ! $is_queue_page && ! $is_settings_page ) {
        return;
    }

    // Stylesheet
    wp_enqueue_style(
        'presshub-admin-social',
        PRESSHUB_SOCIAL_URL . 'assets/css/admin-social.css',
        [],
        PRESSHUB_SOCIAL_VERSION
    );

    $js_vars = [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'presshub_social_nonce' ),
    ];

    // Post edit screen metabox script
    if ( $is_post_edit ) {
        wp_enqueue_script(
            'presshub-social-metabox',
            PRESSHUB_SOCIAL_URL . 'assets/js/admin-social-metabox.js',
            [ 'jquery' ],
            PRESSHUB_SOCIAL_VERSION,
            true
        );
        wp_localize_script( 'presshub-social-metabox', 'presshubSocial', $js_vars );
    }

    // Queue dashboard script
    if ( $is_queue_page ) {
        wp_enqueue_script(
            'presshub-social-queue',
            PRESSHUB_SOCIAL_URL . 'assets/js/admin-social-queue.js',
            [ 'jquery' ],
            PRESSHUB_SOCIAL_VERSION,
            true
        );
        wp_localize_script( 'presshub-social-queue', 'presshubSocial', $js_vars );
    }

    // Settings screen script
    if ( $is_settings_page ) {
        wp_enqueue_script(
            'presshub-social-settings',
            PRESSHUB_SOCIAL_URL . 'assets/js/admin-social-settings.js',
            [ 'jquery' ],
            PRESSHUB_SOCIAL_VERSION,
            true
        );
        wp_localize_script( 'presshub-social-settings', 'presshubSocial', $js_vars );
    }
} );
