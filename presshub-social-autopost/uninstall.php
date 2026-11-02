<?php
/**
 * Uninstallation cleanup for PressHub Social Auto-Post.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Drop queue database table
$table_name = ( isset( $wpdb ) && ! empty( $wpdb->prefix ) )
    ? $wpdb->prefix . 'presshub_social_queue'
    : 'wp_presshub_social_queue';

if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
}

// 2. Delete plugin settings / options
if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'presshub_social_%'" );
}

// 3. Unschedule cron hook
if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_unschedule_event' ) ) {
    $timestamp = wp_next_scheduled( 'presshub_social_cron_dispatch' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'presshub_social_cron_dispatch' );
    }
}
