<?php
/**
 * PressHub AI Editor — uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen (after
 * deactivation). Removes everything the plugin created:
 *
 *   - every presshub_ai_* option (provider, models, tuning, API keys,
 *     rate limits, preset library, migration flag, retention);
 *   - per-user metas (author presets, default preset, disabled defaults)
 *     for ALL users;
 *   - every presshub_research log post (deep-research history).
 *
 * Research crons are cleared here as well (belt-and-braces; the
 * deactivation hook already clears them).
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$presshub_ai_options = [
    // General / provider.
    'presshub_ai_provider',
    'presshub_ai_model',
    // Per-provider model + tuning.
    'presshub_ai_model_openai',
    'presshub_ai_model_anthropic',
    'presshub_ai_model_gemini',
    'presshub_ai_temperature_openai',
    'presshub_ai_temperature_anthropic',
    'presshub_ai_temperature_gemini',
    'presshub_ai_max_tokens_openai',
    'presshub_ai_max_tokens_anthropic',
    'presshub_ai_max_tokens_gemini',
    'presshub_ai_timeout_openai',
    'presshub_ai_timeout_anthropic',
    'presshub_ai_timeout_gemini',
    // Secrets (autoload disabled).
    'presshub_ai_api_key',
    'presshub_ai_google_cloud_api_key',
    'presshub_ai_github_token',
    // "Remove stored key" checkboxes (stored as 0/1 flags).
    'presshub_ai_remove_api_key',
    'presshub_ai_remove_google_cloud_api_key',
    'presshub_ai_remove_github_token',
    // Provider extras.
    'presshub_ai_openai_org',
    'presshub_ai_anthropic_version',
    'presshub_ai_imagen_region',
    'presshub_ai_gcloud_project_id',
    // Rate limits + research retention.
    'presshub_ai_rate_limit_enabled',
    'presshub_ai_rate_limit_per_hour',
    'presshub_ai_rate_limit_window_seconds',
    'presshub_ai_research_retention_days',
    // Presets + migration flag.
    'presshub_ai_default_presets',
    'presshub_ai_migrated_models',
];

foreach ( $presshub_ai_options as $presshub_ai_option ) {
    delete_option( $presshub_ai_option );
}

// Per-user preset data for every user.
$presshub_ai_user_meta_keys = [
    'presshub_ai_author_presets',
    'presshub_ai_default_preset_id',
    'presshub_ai_disabled_default_presets',
];

$presshub_ai_user_ids = get_users( [ 'fields' => 'ID' ] );
foreach ( $presshub_ai_user_ids as $presshub_ai_user_id ) {
    foreach ( $presshub_ai_user_meta_keys as $presshub_ai_meta_key ) {
        delete_user_meta( $presshub_ai_user_id, $presshub_ai_meta_key );
    }
}

// All research log posts (any status).
$presshub_ai_research_posts = get_posts( [
    'post_type'      => 'presshub_research',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
] );
foreach ( $presshub_ai_research_posts as $presshub_ai_post_id ) {
    wp_delete_post( $presshub_ai_post_id, true );
}

// Belt-and-braces: no scheduled work may survive uninstall.
wp_clear_scheduled_hook( 'presshub_ai_cleanup_research' );
wp_clear_scheduled_hook( 'presshub_ai_do_research' );
