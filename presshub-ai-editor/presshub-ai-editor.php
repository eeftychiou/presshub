<?php
/**
 * Plugin Name: PressHub AI Co-Pilot v1.1
 * Description: AI Co-Authoring and Editorial Workflow for PressHub.
 * Version: 1.1.0
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

function presshub_ai_init() {
    new PressHub_AI_Settings();
    new PressHub_AI_Metaboxes();
    new PressHub_AI_Ajax_Handlers();
    new PressHub_AI_Workflow();
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
        ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components'],
        PRESSHUB_AI_VERSION,
        true
    );
    wp_localize_script( 'presshub-ai-sidebar', 'presshubAI', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'presshub_ai_nonce' )
    ] );
});

add_action( 'presshub_ai_do_research', 'presshub_ai_execute_research_job' );

function presshub_ai_execute_research_job( $research_id ) {
    $research_post = get_post( $research_id );
    if ( ! $research_post || 'presshub_research' !== $research_post->post_type ) {
        return;
    }

    update_post_meta( $research_id, '_research_status', 'processing' );
    
    $prompt = get_post_meta( $research_id, '_research_prompt', true );
    $associated_post_id = get_post_meta( $research_id, '_associated_post_id', true );
    
    $associated_post = get_post( $associated_post_id );
    $post_content = $associated_post ? $associated_post->post_content : '';
    
    $api = new PressHub_AI_API_Client();
    
    $sys_prompt = "You are a senior investigative research assistant. Your task is to perform an in-depth topic synthesis and research synthesis.
Use the provided instructions and the current post draft context to compile a comprehensive, well-structured, and objective research report in clean HTML format. Use headings, lists, and quotes where appropriate. DO NOT output code block wrappers (like ```html). Only output the raw HTML.";

    $user_prompt = "User prompt / request: " . $prompt . "\n\nAssociated Post Content Context:\n" . $post_content;
    
    $report = $api->call_provider( $sys_prompt, $user_prompt, false, [] );
    
    if ( is_wp_error( $report ) ) {
        update_post_meta( $research_id, '_research_status', 'failed' );
        update_post_meta( $research_id, '_error_message', $report->get_error_message() );
        return;
    }
    
    $updated = wp_update_post( [
        'ID' => $research_id,
        'post_content' => wp_kses_post( $report )
    ] );
    
    if ( is_wp_error( $updated ) || 0 === $updated ) {
        update_post_meta( $research_id, '_research_status', 'failed' );
        update_post_meta( $research_id, '_error_message', is_wp_error( $updated ) ? $updated->get_error_message() : 'Failed to update research post content.' );
        return;
    }

    update_post_meta( $research_id, '_research_status', 'completed' );
}

