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
    // #237: removed in 2.46.0; a differing key may be kept one more release.
    'presshub_ai_gemini_api_key',
    'presshub_ai_briefing_tts_api_key',
    'presshub_ai_remove_briefing_tts_api_key',
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
    // Press release inbox (IMAP), including the stored mailbox password.
    'presshub_ai_press_inbox_enabled',
    'presshub_ai_press_inbox_host',
    'presshub_ai_press_inbox_port',
    'presshub_ai_press_inbox_encryption',
    'presshub_ai_press_inbox_verify_ssl',
    'presshub_ai_press_inbox_username',
    'presshub_ai_press_inbox_password',
    'presshub_ai_press_inbox_folder',
    'presshub_ai_press_inbox_processed_folder',
    'presshub_ai_press_inbox_allowed_senders',
    'presshub_ai_press_inbox_max_messages',
    'presshub_ai_press_inbox_max_age_hours',
    'presshub_ai_press_inbox_max_attachment_mb',
    'presshub_ai_press_inbox_timeout',
    'presshub_ai_press_inbox_extract_attachments',
    // Social media monitoring (X / TikTok) settings and post store.
    'presshub_ai_social_monitor_enabled',
    'presshub_ai_social_monitor_interval_min',
    'presshub_ai_social_monitor_max_age_hours',
    'presshub_ai_social_monitor_max_posts_per_account',
    'presshub_ai_social_monitor_max_posts_per_harvest',
    'presshub_ai_social_monitor_x_list_url',
    'presshub_ai_social_monitor_transcribe_videos',
    'presshub_ai_social_monitor_max_video_seconds',
    'presshub_ai_social_monitor_max_video_mb',
    'presshub_ai_social_monitor_posts',
    'presshub_ai_social_monitor_last_scan',
    'presshub_ai_social_monitor_scan_started',
    'presshub_ai_social_trending_enabled',
    'presshub_ai_social_trending_interval_hours',
    'presshub_ai_social_trending_platform_x',
    'presshub_ai_social_trending_platform_tiktok',
    'presshub_ai_social_trending_platform_instagram',
    'presshub_ai_social_trending_platform_facebook',
    'presshub_ai_social_trending_keywords',
    'presshub_ai_social_trending_hashtags',
    'presshub_ai_social_trending_facebook_pages',
    'presshub_ai_social_trending_category',
    'presshub_ai_social_trending_post_mode',
    'presshub_ai_social_trending_post_status',
    'presshub_ai_social_trending_title',
    'presshub_ai_social_trending_max_items',
    'presshub_ai_social_trending_max_per_platform',
    'presshub_ai_social_trending_max_age_hours',
    'presshub_ai_social_trending_min_engagement',
    'presshub_ai_social_trending_excluded_words',
    'presshub_ai_social_trending_briefing_enabled',
    'presshub_ai_social_trending_summary_enabled',
    'presshub_ai_social_trending_briefing_count',
    'presshub_ai_social_trending_briefing_pool',
    'presshub_ai_social_trending_state',
    'presshub_ai_social_trending_candidates',
    'presshub_ai_social_trending_posts',
    'presshub_ai_social_trending_ig_tag_ids',
    'presshub_ai_social_trending_images',
    'presshub_ai_image_metadata_enabled',
    'presshub_ai_image_prompt_templates',
    'presshub_ai_briefing_image_template',
    'presshub_ai_briefing_podcast_image_template',
    'presshub_ai_briefing_noon_image_template',
    'presshub_ai_briefing_noon_podcast_image_template',
    'presshub_ai_social_trending_image_template',
    'presshub_ai_social_trending_image_source',
    'presshub_ai_briefing_pending_steps',
    'presshub_ai_briefing_pending_steps_lock',
    'presshub_ai_seo_auto_enabled',
    'presshub_ai_briefing_seo_enhance_enabled',
    'presshub_ai_seo_max_tags',
    'presshub_ai_seo_review_enabled',
    'presshub_ai_editorial_agenda',
    'presshub_ai_editorial_agenda_history',
    'presshub_ai_editorial_agenda_suggestion',
    'presshub_ai_stakeholders',
    'presshub_ai_policy_fiches',
    'presshub_ai_policy_candidates',
    'presshub_ai_policy_alerts',
    'presshub_ai_policy_draft_state',
    'presshub_ai_editorial_agenda_budgets',
    'presshub_ai_stakeholders_budgets',
    'presshub_ai_policy_fiches_budgets',
    // Podcast post switches.
    'presshub_ai_podcast_show_notes_enabled',
    'presshub_ai_podcast_lead_enabled',
    'presshub_ai_migrated_social_fiche_budgets',
    // Settings clean-up (#237): its bookkeeping and the pre-clean-up copy.
    'presshub_settings_cleanup_version',
    'presshub_settings_cleanup_report',
    'presshub_settings_cleanup_export',
    'presshub_settings_cleanup_export_2',
    'presshub_settings_cleanup_notice',
    'presshub_settings_cleanup_lock',
];

// #309 (E7): every registered setting, from the registry itself, so a new
// setting is removed without editing this list ...
$presshub_ai_registry = defined( 'ABSPATH' ) && is_readable( __DIR__ . '/includes/settings-registry-data.php' ) ? include __DIR__ . '/includes/settings-registry-data.php' : [];
if ( is_array( $presshub_ai_registry ) ) {
    $presshub_ai_options = array_merge( $presshub_ai_options, array_keys( $presshub_ai_registry ) );
}
// ... and the stores and state PressHub keeps outside the registry.
$presshub_ai_options = array_merge( $presshub_ai_options, [
    'presshub_ai_configured_providers',
    'presshub_ai_providers_migrated',
    'presshub_ai_taxonomy_presets',
    'presshub_ai_role_presets',
    'presshub_social_tiktok_shorts_music_library',
    'presshub_social_youtube_refresh_token',
    'presshub_social_youtube_channel',
    'presshub_social_youtube_needs_reconnect',
    'presshub_social_youtube_paused_until',
    'presshub_ai_memory_backfill_state',
    'presshub_ai_log_file_suffix',
    'presshub_ai_log_paths_version',
    'presshub_ai_audit_logs_db_version',
    'presshub_ai_generation_records_db_version',
    'presshub_ai_memory_db_version',
    'presshub_ai_qa_reviews_db_version',
    'presshub_ai_token_logs_db_version',
    'presshub_social_worker_last_seen',
    'presshub_social_worker_sessions',
    // One-time migration and repair flags.
    'presshub_ai_migrated_models',
    'presshub_ai_migrated_max_tokens',
    'presshub_ai_migrated_source_unicode',
    'presshub_ai_migrated_provider_store_precedence',
    'presshub_ai_migrated_deprecated_gemini',
    'presshub_social_upgraded_prompts_v284',
    'presshub_social_upgraded_prompts_v269',
    'presshub_social_repaired_v263',
    'presshub_social_migrated_prompt_newlines',
] );
$presshub_ai_options = array_values( array_unique( $presshub_ai_options ) );

// Per-user preset data for every user.
$presshub_ai_user_meta_keys = [
    'presshub_ai_author_presets',
    'presshub_ai_default_preset_id',
    'presshub_ai_disabled_default_presets',
    // #309 (E7): the dismissed "What's new in settings" panel.
    'presshub_settings_whats_new_246_dismissed',
];

// C-6/P-4 (Antigravity review): with $wpdb available, the per-user metas
// collapse into ONE bulk DELETE — no user enumeration at all. Without it
// (hostile hosts), walk users in 200-user batches so uninstall stays
// bounded on large sites.
global $wpdb;

if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->usermeta ) ) {
    $presshub_ai_placeholders = implode( ', ', array_fill( 0, count( $presshub_ai_user_meta_keys ), '%s' ) );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( {$presshub_ai_placeholders} )",
        $presshub_ai_user_meta_keys
    ) );
} else {
    $presshub_ai_offset = 0;
    while ( true ) {
        $presshub_ai_user_ids = get_users( [
            'fields' => 'ID',
            'number' => 200,
            'offset' => $presshub_ai_offset,
        ] );
        if ( empty( $presshub_ai_user_ids ) ) {
            break;
        }
        foreach ( $presshub_ai_user_ids as $presshub_ai_user_id ) {
            foreach ( $presshub_ai_user_meta_keys as $presshub_ai_meta_key ) {
                delete_user_meta( $presshub_ai_user_id, $presshub_ai_meta_key );
            }
        }
        $presshub_ai_offset += count( $presshub_ai_user_ids );
    }
}

// #309 (E7): everything a site holds, run once per site on multisite (posts,
// post meta, options, transients and crons are per site; user meta is not).
$presshub_ai_uninstall_site = static function () use ( $presshub_ai_options ): void {
    global $wpdb;
    foreach ( $presshub_ai_options as $presshub_ai_option ) {
        delete_option( $presshub_ai_option );
    }
    if ( function_exists( 'delete_transient' ) ) {
        foreach ( [
            'presshub_connection_status',
            'presshub_social_youtube_access_token',
            'presshub_social_youtube_long_uploads',
            'presshub_ai_log_exposure_status',
            'presshub_ai_backup_exposure',
            'presshub_ai_qa_held_count',
            'presshub_ai_step_sweep_lock',
        ] as $presshub_ai_transient ) {
            delete_transient( $presshub_ai_transient );
        }
    }
    // Transients with a per-user or per-request suffix (settings import,
    // no-JS save result, YouTube OAuth state, TTS pacing).
    if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) && method_exists( $wpdb, 'esc_like' ) ) {
        foreach ( [ '_transient_presshub_', '_transient_timeout_presshub_' ] as $presshub_ai_prefix ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $presshub_ai_prefix ) . '%'
            ) );
        }
    }

    // All research log posts (any status), 200 per batch.
    $presshub_ai_offset = 0;
    while ( true ) {
        $presshub_ai_research_posts = get_posts( [
            'post_type'      => 'presshub_research',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'offset'         => $presshub_ai_offset,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        if ( empty( $presshub_ai_research_posts ) ) {
            break;
        }
        foreach ( $presshub_ai_research_posts as $presshub_ai_post_id ) {
            wp_delete_post( $presshub_ai_post_id, true );
        }
        $presshub_ai_offset += count( $presshub_ai_research_posts );
    }

    // SEO metadata and SEO review results stored on posts.
    if ( function_exists( 'delete_post_meta_by_key' ) ) {
        delete_post_meta_by_key( '_presshub_ai_seo' );
        delete_post_meta_by_key( '_presshub_trending_ai_image_queued' );
        delete_post_meta_by_key( '_presshub_trending_ai_release_pending' );
        delete_post_meta_by_key( '_presshub_trending_ai_watchdog_release' );
        delete_post_meta_by_key( '_presshub_trending_ai_image_cuts' );
        delete_post_meta_by_key( '_presshub_ai_seo_review' );
        delete_post_meta_by_key( '_presshub_policy_source_hash' );
        delete_post_meta_by_key( '_presshub_stakeholders_source_hash' );
    }

    // Belt-and-braces: no scheduled work may survive uninstall.
    wp_clear_scheduled_hook( 'presshub_ai_cleanup_research' );
    wp_clear_scheduled_hook( 'presshub_ai_do_research' );
    wp_clear_scheduled_hook( 'presshub_ai_social_trending_tick' );
    if ( function_exists( 'wp_unschedule_hook' ) ) {
        wp_unschedule_hook( 'presshub_ai_social_trending_ai_image' );
    }
};

if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) ) {
    foreach ( (array) get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $presshub_ai_site_id ) {
        switch_to_blog( (int) $presshub_ai_site_id );
        $presshub_ai_uninstall_site();
        restore_current_blog();
    }
} else {
    $presshub_ai_uninstall_site();
}
