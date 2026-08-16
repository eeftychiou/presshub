<?php
/**
 * Plugin Name: PressHub AI Co-Pilot
 * Description: AI Co-Authoring and Editorial Workflow for PressHub.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: presshub-ai-editor
 * Domain Path: /languages
 * Author: Antigravity
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PRESSHUB_AI_VERSION', '1.1.0' );
define( 'PRESSHUB_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSHUB_AI_URL', plugin_dir_url( __FILE__ ) );

// Include classes
require_once PRESSHUB_AI_DIR . 'includes/class-settings.php';
require_once PRESSHUB_AI_DIR . 'includes/class-metaboxes.php';
require_once PRESSHUB_AI_DIR . 'includes/class-ajax-handlers.php';
require_once PRESSHUB_AI_DIR . 'includes/class-api-client.php';
require_once PRESSHUB_AI_DIR . 'includes/class-workflow.php';
require_once PRESSHUB_AI_DIR . 'includes/class-research-cleanup.php';
require_once PRESSHUB_AI_DIR . 'includes/class-preset-sanitizer.php';
require_once PRESSHUB_AI_DIR . 'includes/class-preset-store.php';
require_once PRESSHUB_AI_DIR . 'includes/class-preset-resolver.php';
require_once PRESSHUB_AI_DIR . 'includes/class-admin-presets.php';
require_once PRESSHUB_AI_DIR . 'includes/class-author-presets.php';

/**
 * Deactivation: clear both research crons so a deactivated plugin stops
 * scheduling work. Options, user metas and research posts are deliberately
 * kept on deactivation — uninstall.php removes them on full uninstall.
 */
function presshub_ai_deactivate() {
    wp_clear_scheduled_hook( 'presshub_ai_cleanup_research' );
    wp_clear_scheduled_hook( 'presshub_ai_do_research' );
}
register_deactivation_hook( __FILE__, 'presshub_ai_deactivate' );

// Initialize GitHub Update Checker
// PRESSHUB_AI_SKIP_UPDATE_CHECKER lets hosts (and the unit-test harness)
// disable the embedded updater without deleting the library.
if ( ! defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' )
    && file_exists( PRESSHUB_AI_DIR . 'includes/plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once PRESSHUB_AI_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
    
    $presshub_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/eeftychiou/presshub/',
        __FILE__,
        'presshub-ai-editor'
    );
    
    $presshub_update_checker->setBranch( 'main' );
    $presshub_update_checker->getVcsApi()->enableReleaseAssets();
    
    $github_token = get_option( 'presshub_ai_github_token' );
    if ( ! empty( $github_token ) ) {
        $presshub_update_checker->setAuthentication( $github_token );
    }
}

function presshub_ai_init() {
    new PressHub_AI_Settings();
    new PressHub_AI_Metaboxes();
    new PressHub_AI_Ajax_Handlers();
    new PressHub_AI_Workflow();
    PressHub_AI_Research_Cleanup::register();
}
add_action( 'plugins_loaded', 'presshub_ai_init' );

add_action( 'init', function() {
    register_post_type( 'presshub_research', [
        'labels' => [
            'name' => 'AI Research Logs',
            'singular_name' => 'AI Research Log',
        ],
        'public' => false,
        'show_ui' => false,
        'supports' => [ 'title', 'editor', 'custom-fields' ],
    ] );
} );


add_action( 'enqueue_block_editor_assets', function() {
    wp_enqueue_script(
        'presshub-ai-sidebar',
        PRESSHUB_AI_URL . 'assets/sidebar.js',
        ['jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components'],
        PRESSHUB_AI_VERSION,
        true
    );
    wp_localize_script( 'presshub-ai-sidebar', 'presshubAI', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'presshub_ai_nonce' )
    ] );
});

add_action( 'presshub_ai_do_research', 'presshub_ai_execute_research_job' );

/**
 * NOTE ON WP-CRON DEPENDENCY:
 * Deep-research jobs are scheduled with wp_schedule_single_event(), which
 * only fires when WordPress itself is loaded (typically on site traffic).
 * On low-traffic sites a research job may sit 'pending' until someone
 * visits. For reliable execution, configure a real cron on the server:
 *
 *   wp config set DISABLE_WP_CRON true --raw
 *   # crontab:  * * * * * wp cron event run --due-now --path=/path/to/wp
 *
 * The AJAX response to the client includes 'scheduled_at' so the UI can
 * surface the expected execution time.
 */
function presshub_ai_execute_research_job( $research_id ) {
    $research_post = get_post( $research_id );
    if ( ! $research_post || 'presshub_research' !== $research_post->post_type ) {
        return;
    }

    // C-2 (Antigravity review): WP-Cron can fire the same scheduled event
    // twice (overlapping executions, manual `wp cron event run` while a
    // request-triggered run is still in flight). Once a job is
    // 'processing', a duplicate invocation must not start a second API
    // call — the first execution owns the job.
    if ( 'processing' === get_post_meta( $research_id, '_research_status', true ) ) {
        return;
    }

    update_post_meta( $research_id, '_research_status', 'processing' );
    
    $prompt = get_post_meta( $research_id, '_research_prompt', true );
    $associated_post_id = get_post_meta( $research_id, '_associated_post_id', true );
    
    $associated_post = get_post( $associated_post_id );
    $post_content = $associated_post ? $associated_post->post_content : '';
    
    $api = new PressHub_AI_API_Client();
    
    $sys_prompt = "You are a senior investigative research assistant. Your task is to perform an in-depth topic synthesis and research synthesis.\nUse the provided instructions and the current post draft context to compile a comprehensive, well-structured, and objective research report in clean HTML format. Use headings, lists, and quotes where appropriate. DO NOT output code block wrappers (like ```html). Only output the raw HTML.";

    // C-3 (Antigravity review): the base prompt is filtered FIRST so a
    // filter that replaces the default keeps working, then the resolved
    // per-author preset is appended on top, and the final composition is
    // exposed through a dedicated hook for consumers that need to see or
    // adjust the whole composed prompt.
    $sys_prompt = apply_filters( 'presshub_ai_research_system_prompt', $sys_prompt );

    // Per-author instruction presets (2026-08-15 design §3.3): the author
    // who initiated the research gets their preset appended to the system
    // prompt. Unknown/absent initiator (user id 0) resolves to null and
    // leaves the prompt untouched.
    $research_user_id = (int) get_post_meta( $research_id, '_research_user_id', true );
    $preset = PressHub_AI_Preset_Resolver::resolve_for_user( $research_user_id, 'research', null );
    if ( $preset !== null ) {
        $sys_prompt .= "\n\n" . $preset;
    }

    $sys_prompt = apply_filters( 'presshub_ai_composed_research_system_prompt', $sys_prompt );

    $user_prompt = "User prompt / request: " . $prompt . "\n\nAssociated Post Content Context:\n" . $post_content;
    
    $report = $api->call_provider( $sys_prompt, $user_prompt, false, [] );
    
    if ( is_wp_error( $report ) ) {
        update_post_meta( $research_id, '_research_status', 'failed' );
        update_post_meta( $research_id, '_error_message', $report->get_error_message() );
        error_log( sprintf(
            'PressHub AI: research job %d failed (provider error): %s',
            $research_id,
            $report->get_error_message()
        ) );
        return;
    }
    
    $updated = wp_update_post( [
        'ID' => $research_id,
        'post_content' => wp_kses_post( $report )
    ] );
    
    if ( is_wp_error( $updated ) || 0 === $updated ) {
        $update_error = is_wp_error( $updated ) ? $updated->get_error_message() : 'Failed to update research post content.';
        update_post_meta( $research_id, '_research_status', 'failed' );
        update_post_meta( $research_id, '_error_message', $update_error );
        error_log( sprintf(
            'PressHub AI: research job %d failed (post update): %s',
            $research_id,
            $update_error
        ) );
        return;
    }

    update_post_meta( $research_id, '_research_status', 'completed' );
}

