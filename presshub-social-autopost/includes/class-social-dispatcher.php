<?php
/**
 * Dispatcher orchestrating post publish hooks, queue generation, and background execution.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Dispatcher {

    /**
     * Hook into post status transitions to automatically enqueue social posts.
     *
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     * @return void
     */
    public static function handle_post_status_transition( string $new_status, string $old_status, $post ): void {
        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }

        // Only trigger on first publish transition of standard posts
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }

        if ( 'post' !== $post->post_type ) {
            return;
        }

        // Check if auto-publish is globally enabled
        $auto_publish = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_auto_publish_enabled' )
            ? PressHub_Social_Settings_Storage::get_auto_publish_enabled()
            : true;

        if ( ! $auto_publish ) {
            return;
        }

        self::enqueue_for_post( $post->ID );
    }

    /**
     * Enqueue social posts for a given post across selected platforms.
     *
     * @param int   $post_id
     * @param array $platforms_override Optional list of platforms to post to.
     * @return array<int> Array of enqueued job IDs.
     */
    public static function enqueue_for_post( int $post_id, array $platforms_override = [] ): array {
        if ( $post_id <= 0 ) {
            return [];
        }

        // 1. Determine target platforms
        if ( ! empty( $platforms_override ) ) {
            $platforms = array_map( 'sanitize_key', $platforms_override );
        } else {
            $meta_platforms = get_post_meta( $post_id, '_presshub_social_platforms', true );
            if ( is_array( $meta_platforms ) && ! empty( $meta_platforms ) ) {
                $platforms = array_map( 'sanitize_key', $meta_platforms );
            } else {
                $platforms = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_enabled_platforms' )
                    ? PressHub_Social_Settings_Storage::get_enabled_platforms()
                    : [ 'twitter', 'facebook', 'instagram', 'tiktok' ];
            }
        }

        // Filter valid platforms only
        $platforms = array_values( array_intersect( $platforms, PressHub_Social_Queue::VALID_PLATFORMS ) );
        if ( empty( $platforms ) ) {
            return [];
        }

        // 2. Resolve media from post thumbnail if present
        $media_url  = null;
        $media_type = null;
        if ( function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post_id ) ) {
            $thumb_id = get_post_thumbnail_id( $post_id );
            if ( $thumb_id ) {
                $media_url  = wp_get_attachment_url( $thumb_id ) ?: null;
                $media_type = 'image';
            }
        }

        $interval = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_dispatch_interval' )
            ? max( 0, PressHub_Social_Settings_Storage::get_dispatch_interval() )
            : 0;

        $base_time = time();
        $job_ids   = [];

        foreach ( $platforms as $index => $platform ) {
            // Determine engine for platform
            $engine = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_platform_engine' )
                ? PressHub_Social_Settings_Storage::get_platform_engine( $platform )
                : 'api';

            // Check for per-post custom copy override
            $copy_meta = get_post_meta( $post_id, '_presshub_social_copy_' . $platform, true );
            $copy      = is_string( $copy_meta ) ? trim( $copy_meta ) : '';

            $scheduled_at = gmdate( 'Y-m-d H:i:s', $base_time + ( $index * $interval ) );
            $failed_error = null;

            if ( '' === $copy ) {
                // Generate copy via AI Copywriter
                if ( class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'generate_for_post' ) ) {
                    $generated = PressHub_Social_AI_Copywriter::generate_for_post( $post_id, $platform );
                    if ( is_wp_error( $generated ) ) {
                        $failed_error = $generated->get_error_message();
                        $copy         = sprintf( '[AI Copy Generation Failed: %s]', $failed_error );
                    } else {
                        $copy = (string) $generated;
                    }
                } else {
                    $post = get_post( $post_id );
                    $copy = $post ? wp_strip_all_tags( $post->post_title ) : 'New post published.';
                }
            }

            $job_data = [
                'post_id'      => $post_id,
                'platform'     => $platform,
                'engine'       => $engine,
                'post_copy'    => $copy,
                'media_url'    => $media_url,
                'media_type'   => $media_type,
                'scheduled_at' => $scheduled_at,
                'status'       => $failed_error ? 'failed' : 'pending',
                'last_error'   => $failed_error,
            ];

            $job_id = PressHub_Social_Queue::enqueue( $job_data );
            if ( ! is_wp_error( $job_id ) && $job_id > 0 ) {
                $job_ids[] = $job_id;
                if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'info' ) ) {
                    PressHub_Social_Logger::info(
                        sprintf( 'Enqueued social job #%d for post #%d on %s (engine: %s, scheduled: %s)', $job_id, $post_id, $platform, $engine, $scheduled_at )
                    );
                }
            }
        }

        return $job_ids;
    }

    /**
     * Dispatch an individual social job immediately.
     *
     * @param int $job_id
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function dispatch_job( int $job_id ): bool|WP_Error {
        $job = PressHub_Social_Queue::get_job( $job_id );
        if ( ! $job ) {
            return new WP_Error( 'job_not_found', sprintf( __( 'Social job #%d not found.', 'presshub-social-autopost' ), $job_id ) );
        }

        // If already published, do not re-dispatch
        if ( 'published' === ( $job['status'] ?? '' ) ) {
            return true;
        }

        // Transition job to processing
        PressHub_Social_Queue::mark_processing( $job_id );

        $platform = sanitize_key( (string) ( $job['platform'] ?? '' ) );
        $engine   = sanitize_key( (string) ( $job['engine'] ?? 'api' ) );

        // If media card/video generation is required for Instagram/TikTok and URL is empty, try worker
        if ( ( 'instagram' === $platform || 'tiktok' === $platform ) && empty( $job['media_url'] ) ) {
            $post_id = (int) ( $job['post_id'] ?? 0 );
            $post    = get_post( $post_id );
            $title   = $post ? $post->post_title : 'Social Card';

            if ( 'tiktok' === $platform ) {
                $card_res = PressHub_Social_Worker_Client::generate_video( [
                    'post_id' => $post_id,
                    'title'   => $title,
                    'copy'    => $job['post_copy'] ?? '',
                ] );
            } else {
                $card_res = PressHub_Social_Worker_Client::generate_card( [
                    'post_id' => $post_id,
                    'title'   => $title,
                    'copy'    => $job['post_copy'] ?? '',
                ] );
            }

            if ( ! is_wp_error( $card_res ) && ! empty( $card_res['url'] ) ) {
                $job['media_url']  = $card_res['url'];
                $job['media_type'] = ( 'tiktok' === $platform ) ? 'video' : 'image';
            }
        }

        // Route dispatch according to configured engine
        if ( 'automation' === $engine ) {
            $result = PressHub_Social_Worker_Client::dispatch( $job );
        } else {
            $result = PressHub_Social_Meta_API_Client::publish( $job );
        }

        if ( is_wp_error( $result ) ) {
            $error_message = $result->get_error_message();
            PressHub_Social_Queue::mark_failed( $job_id, $error_message, [ 'error_code' => $result->get_error_code() ] );
            return $result;
        }

        $external_id  = (string) ( $result['external_post_id'] ?? '' );
        $external_url = (string) ( $result['external_post_url'] ?? '' );
        $metadata     = (array) ( $result['metadata'] ?? [] );

        PressHub_Social_Queue::mark_published( $job_id, $external_id, $external_url, $metadata );

        if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'info' ) ) {
            PressHub_Social_Logger::info(
                sprintf( 'Social job #%d successfully published on %s (%s)', $job_id, $platform, $external_url ?: $external_id ),
                [ 'external_id' => $external_id, 'external_url' => $external_url ]
            );
        }

        return true;
    }

    /**
     * Process all due pending jobs in the queue.
     *
     * @return array Summary of processed batch.
     */
    public static function process_due_queue(): array {
        $due_jobs = PressHub_Social_Queue::get_due_pending_jobs();
        $summary  = [
            'total_due' => count( $due_jobs ),
            'succeeded' => 0,
            'failed'    => 0,
            'job_ids'   => [],
        ];

        foreach ( $due_jobs as $job ) {
            $job_id = (int) $job['id'];
            $summary['job_ids'][] = $job_id;

            $res = self::dispatch_job( $job_id );
            if ( true === $res ) {
                $summary['succeeded']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
