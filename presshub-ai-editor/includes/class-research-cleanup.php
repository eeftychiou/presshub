<?php
/**
 * Stale research-log cleanup.
 *
 * presshub_research posts are created for every deep-research request and
 * are hidden from the admin UI (show_ui => false), so without a retention
 * policy they accumulate forever. This class deletes completed/failed
 * research logs older than a configurable number of days, once per day
 * via WP-Cron.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Research_Cleanup {

    /**
     * Default retention: keep completed/failed logs for 30 days.
     */
    const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Register the daily cron event and its handler.
     */
    public static function register() {
        if ( ! wp_next_scheduled( 'presshub_ai_cleanup_research' ) ) {
            wp_schedule_event( time(), 'daily', 'presshub_ai_cleanup_research' );
        }
        add_action( 'presshub_ai_cleanup_research', [ self::class, 'run' ] );
    }

    /**
     * Delete stale research logs.
     *
     * When no explicit $days is passed (the cron path), the retention
     * window is read from the presshub_ai_research_retention_days option
     * (settings page, Rate Limits section), falling back to
     * DEFAULT_RETENTION_DAYS.
     *
     * @param int|null $days Retention window. Logs with a terminal status
     *                  (completed/failed) older than this many days are
     *                  deleted. Non-terminal logs are always kept.
     * @return int Number of posts deleted.
     */
    public static function run( $days = null ) {
        if ( null === $days ) {
            $days = (int) get_option( 'presshub_ai_research_retention_days', self::DEFAULT_RETENTION_DAYS );
        }
        $days = max( 1, (int) $days );

        $stale = get_posts( [
            'post_type'      => 'presshub_research',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'date_query'     => [
                'column' => 'post_date',
                'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
            ],
            'meta_query'     => [
                [
                    'key'     => '_research_status',
                    'value'   => [ 'completed', 'failed' ],
                    'compare' => 'IN',
                ],
            ],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        $deleted = 0;
        foreach ( $stale as $post_id ) {
            if ( wp_delete_post( $post_id, true ) ) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
