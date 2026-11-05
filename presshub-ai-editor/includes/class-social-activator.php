<?php
/**
 * Plugin activation and deactivation handler for PressHub Social Auto-Post.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Activator {

    /**
     * Get the social queue table name.
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) )
            ? $wpdb->prefix . 'presshub_social_queue'
            : 'wp_presshub_social_queue';
    }

    /**
     * Plugin activation callback.
     *
     * @return void
     */
    public static function activate(): void {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = ( isset( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) )
            ? $wpdb->get_charset_collate()
            : '';

        // MySQL and SQLite-compatible table creation DDL.
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            platform varchar(32) NOT NULL,
            engine varchar(32) NOT NULL DEFAULT 'api',
            status varchar(32) NOT NULL DEFAULT 'pending',
            post_copy text NOT NULL,
            media_url text NULL,
            media_type varchar(32) NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            max_attempts int(11) NOT NULL DEFAULT 3,
            scheduled_at datetime NOT NULL,
            dispatched_at datetime NULL,
            external_post_id varchar(191) NULL,
            external_post_url text NULL,
            last_error text NULL,
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY status_scheduled (status, scheduled_at),
            KEY platform (platform)
        ) {$charset_collate};";

        if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
            $wpdb->query( $sql );
        }

        // Seed default options if settings storage is loaded.
        if ( class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'seed_defaults' ) ) {
            PressHub_Social_Settings_Storage::seed_defaults();
        }

        // Schedule cron dispatch event if not already scheduled.
        if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'presshub_social_cron_dispatch' ) ) {
            if ( function_exists( 'wp_schedule_event' ) ) {
                wp_schedule_event( time(), 'presshub_social_interval', 'presshub_social_cron_dispatch' );
            }
        }
    }

    /**
     * Plugin deactivation callback.
     *
     * @return void
     */
    public static function deactivate(): void {
        if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_unschedule_event' ) ) {
            $timestamp = wp_next_scheduled( 'presshub_social_cron_dispatch' );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, 'presshub_social_cron_dispatch' );
            }
        }
    }
}
