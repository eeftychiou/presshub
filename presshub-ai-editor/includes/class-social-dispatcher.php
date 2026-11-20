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
     * Prefix used when initial AI copy generation fails at enqueue time.
     */
    public const FAILED_COPY_PREFIX = '[AI Copy Generation Failed:';

    /**
     * Check whether a post_copy string is an AI copy generation failure placeholder.
     *
     * @param string $copy
     * @return bool
     */
    public static function is_failed_copy_placeholder( string $copy ): bool {
        return str_starts_with( ltrim( $copy ), self::FAILED_COPY_PREFIX );
    }

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

        // Verify post was not reverted by an earlier editorial workflow gate
        if ( function_exists( 'get_post' ) ) {
            $fresh = get_post( $post->ID );
            if ( $fresh instanceof WP_Post && 'publish' !== $fresh->post_status ) {
                return;
            }
        }

        // Check if auto-publish is globally enabled
        $auto_publish = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_auto_publish_enabled' )
            ? PressHub_Social_Settings_Storage::get_auto_publish_enabled()
            : true;

        if ( ! $auto_publish ) {
            return;
        }

        // In Classic Editor, transition_post_status fires before save_post.
        // Persist metabox selections and custom copy if present in $_POST before enqueuing.
        if ( ! empty( $_POST['presshub_social_metabox_nonce'] ) && class_exists( 'PressHub_Social_Metabox' ) && method_exists( 'PressHub_Social_Metabox', 'save_metabox' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            PressHub_Social_Metabox::save_metabox( $post->ID, $post );
        }

        // Guard against duplicate auto-enqueuing if jobs already exist for this post
        if ( self::has_publish_jobs( $post->ID ) ) {
            return;
        }

        self::enqueue_for_post( $post->ID );
    }

    /**
     * Whether a post already has social jobs, not counting test renders of its shorts
     * (those are never posted, so a test render on a draft must not stop auto-publish).
     */
    public static function has_publish_jobs( int $post_id ): bool {
        foreach ( PressHub_Social_Queue::get_jobs_for_post( $post_id ) as $job ) {
            if ( is_array( $job ) && class_exists( 'PressHub_Social_TikTok_Shorts' ) && PressHub_Social_TikTok_Shorts::is_test_job( $job ) ) {
                continue;
            }
            return true;
        }
        return false;
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
                $platforms = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_ready_platforms' )
                    ? PressHub_Social_Settings_Storage::get_ready_platforms()
                    : ( class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_enabled_platforms' )
                        ? PressHub_Social_Settings_Storage::get_enabled_platforms()
                        : [ 'twitter', 'facebook', 'instagram', 'tiktok' ] );
            }
        }

        // Filter valid platforms only
        $platforms = array_values( array_intersect( $platforms, PressHub_Social_Queue::VALID_PLATFORMS ) );
        if ( empty( $platforms ) ) {
            return [];
        }

        if ( class_exists( 'PressHub_Social_Settings_Storage' ) ) {
            $filtered_platforms = [];
            foreach ( $platforms as $platform ) {
                if ( method_exists( 'PressHub_Social_Settings_Storage', 'is_platform_enabled' ) && ! PressHub_Social_Settings_Storage::is_platform_enabled( $platform ) ) {
                    if ( class_exists( 'PressHub_Social_Logger' ) ) {
                        PressHub_Social_Logger::info( sprintf( 'Skipping social queue for post #%d on %s: platform is disabled in settings.', $post_id, $platform ) );
                    }
                    continue;
                }
                if ( method_exists( 'PressHub_Social_Settings_Storage', 'is_platform_configured' ) && ! PressHub_Social_Settings_Storage::is_platform_configured( $platform ) ) {
                    if ( class_exists( 'PressHub_Social_Logger' ) ) {
                        // WARNING: the platform is enabled but cannot post, so the operator should see it.
                        PressHub_Social_Logger::warning( sprintf( 'Skipping social queue for post #%d on %s: platform is enabled but not configured.', $post_id, $platform ) );
                    }
                    continue;
                }
                $filtered_platforms[] = $platform;
            }
            $platforms = $filtered_platforms;
        }

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

        $base_time    = time();
        $job_ids      = [];
        $needs_images = false;

        foreach ( $platforms as $index => $platform ) {
            // Determine engine for platform
            $engine = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_platform_engine' )
                ? PressHub_Social_Settings_Storage::get_platform_engine( $platform )
                : 'api';

            // YouTube only gets videos: the shorts (as YouTube Shorts) and, for a podcast
            // post, the full episode for the podcast playlist. Anything else is not posted there.
            if ( 'youtube' === $platform ) {
                $job_ids = array_merge( $job_ids, self::enqueue_youtube( $post_id, $engine, $media_url, $media_type, $base_time + ( $index * $interval ) ) );
                continue;
            }

            // Podcast posts can go to TikTok and Instagram as several short videos, one per top story.
            // Their copy is written with the short when the worker claims the job.
            // Articles go there as one short each, with the platform's usual AI caption as its
            // post text (when that fails, the short's own caption is used).
            if ( in_array( $platform, [ 'tiktok', 'instagram' ], true ) && class_exists( 'PressHub_Social_TikTok_Shorts' ) && PressHub_Social_TikTok_Shorts::should_split( $post_id, $engine, $platform ) ) {
                $short_copy = null;
                if ( PressHub_Social_TikTok_Shorts::SOURCE_ARTICLE === PressHub_Social_TikTok_Shorts::source_type( $post_id ) ) {
                    $copy_meta  = get_post_meta( $post_id, '_presshub_social_copy_' . $platform, true );
                    $short_copy = is_string( $copy_meta ) ? trim( $copy_meta ) : '';
                    if ( '' === $short_copy && class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'generate_for_post' ) ) {
                        $generated = PressHub_Social_AI_Copywriter::generate_for_post( $post_id, $platform );
                        if ( is_wp_error( $generated ) ) {
                            PressHub_Social_Logger::warning( sprintf( '%s caption for article short of post #%d failed (%s); the short\'s own caption is used.', $platform, $post_id, $generated->get_error_message() ) );
                        } else {
                            $short_copy = (string) $generated;
                        }
                    }
                } else {
                    PressHub_Social_TikTok_Shorts::cancel_superseded( $post_id, $platform );
                }
                $short_jobs = PressHub_Social_TikTok_Shorts::build_jobs( $post_id, $engine, $media_url, $media_type, $base_time + ( $index * $interval ), false, $platform, $short_copy );
                foreach ( $short_jobs as $short_job ) {
                    $job_id = PressHub_Social_Queue::enqueue( $short_job );
                    if ( is_wp_error( $job_id ) ) {
                        PressHub_Social_Logger::error( sprintf( 'Failed to enqueue %s short for post #%d: %s', $platform, $post_id, $job_id->get_error_message() ) );
                    } elseif ( $job_id > 0 ) {
                        $job_ids[] = $job_id;
                        PressHub_Social_Logger::info( sprintf( 'Enqueued %s short job #%d for post #%d (scheduled: %s)', $platform, $job_id, $post_id, $short_job['scheduled_at'] ) );
                    }
                }
                PressHub_Social_TikTok_Shorts::schedule_prepare( $post_id );
                continue;
            }

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

            if ( 'twitter' === $platform && ! $failed_error && class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'sanitize_twitter_copy' ) ) {
                $article_url = function_exists( 'get_permalink' ) ? ( get_permalink( $post_id ) ?: '' ) : '';
                $copy        = PressHub_Social_AI_Copywriter::sanitize_twitter_copy( $copy, $article_url );
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

            // AI post images: the job waits for its generated images (made in the
            // background), keeping the featured image as its fallback.
            $with_images = class_exists( 'PressHub_Social_Post_Images' ) && PressHub_Social_Post_Images::applies( $post_id, $platform );
            if ( $with_images ) {
                $job_data['metadata'] = PressHub_Social_Post_Images::job_metadata( $platform );
            }

            $job_id = PressHub_Social_Queue::enqueue( $job_data );
            if ( is_wp_error( $job_id ) ) {
                if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                    PressHub_Social_Logger::error(
                        sprintf( 'Failed to enqueue social job for post #%d on %s: %s', $post_id, $platform, $job_id->get_error_message() )
                    );
                }
            } elseif ( $job_id > 0 ) {
                $job_ids[] = $job_id;
                if ( $with_images ) {
                    $needs_images = true;
                }
                if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'info' ) ) {
                    PressHub_Social_Logger::info(
                        sprintf( 'Enqueued social job #%d for post #%d on %s (engine: %s, scheduled: %s%s)', $job_id, $post_id, $platform, $engine, $scheduled_at, $with_images ? ', with AI images' : '' )
                    );
                }
            }

            // Facebook Reels: the shorts go to the Page too, after its link post (which is
            // kept), with the short's own caption.
            if ( 'facebook' === $platform ) {
                $job_ids = array_merge( $job_ids, self::enqueue_facebook_shorts( $post_id, $engine, $media_url, $media_type, strtotime( $scheduled_at . ' UTC' ) ?: $base_time ) );
            }
        }

        if ( ! empty( $needs_images ) ) {
            PressHub_Social_Post_Images::schedule_prepare( $post_id );
        }

        return $job_ids;
    }

    /**
     * Queue a post's shorts as Facebook Reels when Facebook Reels are on: Shorts per Podcast
     * jobs for a podcast post, one for an article. The first Reel follows the link post by
     * the time between shorts. The Reel's text is the short's own caption, so no AI call is
     * made here.
     *
     * @param int $link_time Unix time of the post's Facebook link post.
     * @return int[] IDs of the queued Reel jobs.
     */
    public static function enqueue_facebook_shorts( int $post_id, string $engine, ?string $media_url, ?string $media_type, int $link_time ): array {
        if ( ! class_exists( 'PressHub_Social_TikTok_Shorts' ) || ! PressHub_Social_TikTok_Shorts::should_split( $post_id, $engine, 'facebook' ) ) {
            return [];
        }
        if ( PressHub_Social_TikTok_Shorts::SOURCE_ARTICLE !== PressHub_Social_TikTok_Shorts::source_type( $post_id ) ) {
            PressHub_Social_TikTok_Shorts::cancel_superseded( $post_id, 'facebook' );
        }
        $start = $link_time + PressHub_Social_Settings_Storage::get_tiktok_shorts_spacing_minutes() * 60;
        $ids   = [];
        foreach ( PressHub_Social_TikTok_Shorts::build_jobs( $post_id, $engine, $media_url, $media_type, $start, false, 'facebook' ) as $short_job ) {
            $job_id = PressHub_Social_Queue::enqueue( $short_job );
            if ( is_wp_error( $job_id ) ) {
                PressHub_Social_Logger::error( sprintf( 'Failed to enqueue Facebook Reel for post #%d: %s', $post_id, $job_id->get_error_message() ) );
            } elseif ( $job_id > 0 ) {
                $ids[] = (int) $job_id;
                PressHub_Social_Logger::info( sprintf( 'Enqueued Facebook Reel job #%d for post #%d (scheduled: %s)', $job_id, $post_id, $short_job['scheduled_at'] ) );
            }
        }
        if ( $ids ) {
            PressHub_Social_TikTok_Shorts::schedule_prepare( $post_id );
        }
        return $ids;
    }

    /**
     * Queue a post's YouTube videos: its shorts when YouTube Shorts are on, and the full
     * episode of a podcast post when podcast episodes are on. The description is the
     * YouTube post copy (an editor's copy, else the AI copywriter's); a podcast short
     * uses its own caption.
     *
     * @return array<int> Enqueued job IDs.
     */
    private static function enqueue_youtube( int $post_id, string $engine, ?string $media_url, ?string $media_type, int $start_time ): array {
        $job_ids = [];

        $copy_for = static function () use ( $post_id ): array {
            $copy_meta = get_post_meta( $post_id, '_presshub_social_copy_youtube', true );
            $copy      = is_string( $copy_meta ) ? trim( $copy_meta ) : '';
            if ( '' !== $copy ) {
                return [ $copy, null ];
            }
            $generated = PressHub_Social_AI_Copywriter::generate_for_post( $post_id, 'youtube' );
            if ( is_wp_error( $generated ) ) {
                return [ '', $generated->get_error_message() ];
            }
            return [ (string) $generated, null ];
        };

        if ( class_exists( 'PressHub_Social_TikTok_Shorts' ) && PressHub_Social_TikTok_Shorts::should_split( $post_id, $engine, 'youtube' ) ) {
            $short_copy = null;
            if ( PressHub_Social_TikTok_Shorts::SOURCE_ARTICLE === PressHub_Social_TikTok_Shorts::source_type( $post_id ) ) {
                [ $short_copy, $copy_error ] = $copy_for();
                if ( null !== $copy_error ) {
                    PressHub_Social_Logger::warning( sprintf( 'YouTube description for the article short of post #%d failed (%s); the short\'s own caption is used.', $post_id, $copy_error ) );
                }
            } else {
                PressHub_Social_TikTok_Shorts::cancel_superseded( $post_id, 'youtube' );
            }
            foreach ( PressHub_Social_TikTok_Shorts::build_jobs( $post_id, $engine, $media_url, $media_type, $start_time, false, 'youtube', $short_copy ) as $short_job ) {
                $job_id = PressHub_Social_Queue::enqueue( $short_job );
                if ( is_wp_error( $job_id ) ) {
                    PressHub_Social_Logger::error( sprintf( 'Failed to enqueue YouTube short for post #%d: %s', $post_id, $job_id->get_error_message() ) );
                } elseif ( $job_id > 0 ) {
                    $job_ids[] = $job_id;
                    PressHub_Social_Logger::info( sprintf( 'Enqueued YouTube short job #%d for post #%d (scheduled: %s)', $job_id, $post_id, $short_job['scheduled_at'] ) );
                }
            }
            PressHub_Social_TikTok_Shorts::schedule_prepare( $post_id );
        }

        if ( class_exists( 'PressHub_Social_YouTube' ) && PressHub_Social_YouTube::wants_episode( $post_id ) ) {
            [ $copy, $copy_error ] = $copy_for();
            $failed_error = null;
            if ( null !== $copy_error ) {
                $failed_error = $copy_error;
                $copy         = sprintf( '[AI Copy Generation Failed: %s]', $copy_error );
            }
            $job    = PressHub_Social_YouTube::episode_job( $post_id, $copy, $media_url, $media_type, gmdate( 'Y-m-d H:i:s', $start_time ), $failed_error );
            $job_id = PressHub_Social_Queue::enqueue( $job );
            if ( is_wp_error( $job_id ) ) {
                PressHub_Social_Logger::error( sprintf( 'Failed to enqueue YouTube podcast episode for post #%d: %s', $post_id, $job_id->get_error_message() ) );
            } elseif ( $job_id > 0 ) {
                $job_ids[] = $job_id;
                PressHub_Social_Logger::info( sprintf( 'Enqueued YouTube podcast episode job #%d for post #%d (scheduled: %s%s)', $job_id, $post_id, $job['scheduled_at'], $failed_error ? ', description failed' : '' ) );
            }
            // The English and Turkish episodes already voiced (the rest follow as they are).
            if ( class_exists( 'PressHub_AI_Podcast_Translations' ) ) {
                $job_ids = array_merge( $job_ids, PressHub_AI_Podcast_Translations::enqueue_ready( $post_id ) );
            }
        }

        if ( empty( $job_ids ) ) {
            PressHub_Social_Logger::info( sprintf( 'Post #%d is not posted to YouTube: it gets only shorts (YouTube Shorts off, or no short for this post) and podcast episodes (podcast episodes off, or not a podcast post).', $post_id ) );
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

        $platform = sanitize_key( (string) ( $job['platform'] ?? '' ) );
        $engine   = sanitize_key( (string) ( $job['engine'] ?? 'api' ) );
        $post_id  = (int) ( $job['post_id'] ?? 0 );

        // If this job previously failed during AI copy generation, regenerate copy BEFORE
        // entering Pull Mode or dispatching so an error placeholder is never queued as pending.
        $current_copy = (string) ( $job['post_copy'] ?? '' );
        if ( self::is_failed_copy_placeholder( $current_copy ) ) {
            if ( $post_id <= 0 || ! class_exists( 'PressHub_Social_AI_Copywriter' ) ) {
                $error = new WP_Error( 'failed_copy_placeholder', __( 'Cannot dispatch job with an AI copy generation failure placeholder.', 'presshub-social-autopost' ) );
                PressHub_Social_Queue::mark_failed( $job_id, $error->get_error_message(), [ 'error_code' => 'failed_copy_placeholder' ] );
                return $error;
            }
            $regenerated = PressHub_Social_AI_Copywriter::generate_for_post( $post_id, $platform );
            if ( is_wp_error( $regenerated ) ) {
                $error_message = $regenerated->get_error_message();
                PressHub_Social_Queue::mark_failed( $job_id, $error_message, [ 'error_code' => $regenerated->get_error_code() ] );
                return $regenerated;
            }
            $regenerated_str = trim( (string) $regenerated );
            if ( '' === $regenerated_str || self::is_failed_copy_placeholder( $regenerated_str ) ) {
                $error = new WP_Error( 'failed_copy_placeholder', __( 'Regenerated copy is empty or invalid.', 'presshub-social-autopost' ) );
                PressHub_Social_Queue::mark_failed( $job_id, $error->get_error_message(), [ 'error_code' => 'failed_copy_placeholder' ] );
                return $error;
            }
            $job['post_copy'] = $regenerated_str;
            PressHub_Social_Queue::update_job_copy( $job_id, $job['post_copy'] );
        }

        // A job waiting for its AI images is not sent until they are ready (or it has
        // waited too long and goes out with the featured image).
        if ( class_exists( 'PressHub_Social_Post_Images' ) && ! PressHub_Social_Post_Images::ready_to_post( $job ) ) {
            PressHub_Social_Post_Images::schedule_prepare( $post_id );
            return new WP_Error(
                PressHub_Social_Post_Images::PENDING_CODE,
                __( 'Job is waiting for its AI images and will be sent once they are ready.', 'presshub-social-autopost' )
            );
        }
        $job = PressHub_Social_Queue::get_job( $job_id ) ?? $job;

        // In Pull Mode, automation jobs are polled and executed by the local worker via REST.
        // Keep the job pending with scheduled_at = now so it is picked up immediately on the next poll.
        $worker_mode = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_worker_mode' )
            ? PressHub_Social_Settings_Storage::get_worker_mode()
            : 'pull';

        if ( 'automation' === $engine && 'pull' === $worker_mode ) {
            $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
            PressHub_Social_Queue::update_job( $job_id, [
                'status'       => 'pending',
                'scheduled_at' => $now,
            ] );
            return new WP_Error(
                'pull_mode_queued',
                __( 'Job is queued in Pull Mode and will be picked up automatically by your local worker on its next poll.', 'presshub-social-autopost' )
            );
        }

        // A TikTok short is only posted by the local worker in Pull Mode; anywhere else it
        // would go out with its placeholder copy and the full podcast.
        if ( class_exists( 'PressHub_Social_TikTok_Shorts' ) && PressHub_Social_TikTok_Shorts::is_short_job( $job ) ) {
            $error = new WP_Error( 'tiktok_short_needs_pull', sprintf( 'TikTok short job #%d can only be posted by the local worker in Pull Mode (engine: %s, worker mode: %s).', $job_id, $engine, $worker_mode ) );
            PressHub_Social_Logger::error( $error->get_error_message(), [ 'job_id' => $job_id ] );
            PressHub_Social_Queue::mark_processing( $job_id );
            PressHub_Social_Queue::mark_failed( $job_id, $error->get_error_message(), [ 'error_code' => 'tiktok_short_needs_pull' ] );
            return $error;
        }

        // YouTube jobs (shorts and episodes) are only uploaded by the local worker in Pull
        // Mode, which claims them with their upload metadata; nothing here can post them.
        if ( 'youtube' === $platform ) {
            $error = new WP_Error( 'youtube_needs_pull', sprintf( 'YouTube job #%d can only be uploaded by the local worker in Pull Mode (worker mode: %s).', $job_id, $worker_mode ) );
            PressHub_Social_Logger::error( $error->get_error_message(), [ 'job_id' => $job_id ] );
            PressHub_Social_Queue::mark_processing( $job_id );
            PressHub_Social_Queue::mark_failed( $job_id, $error->get_error_message(), [ 'error_code' => 'youtube_needs_pull' ] );
            return $error;
        }

        // Transition job to processing
        PressHub_Social_Queue::mark_processing( $job_id );

        // Dynamically resolve post thumbnail if media_url was not yet attached at enqueue time
        if ( empty( $job['media_url'] ) && $post_id > 0 && function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post_id ) ) {
            $thumb_id = get_post_thumbnail_id( $post_id );
            if ( $thumb_id ) {
                $thumb_url = wp_get_attachment_url( $thumb_id );
                if ( ! empty( $thumb_url ) ) {
                    $job['media_url']  = $thumb_url;
                    $job['media_type'] = 'image';
                }
            }
        }

        // If media card/video generation is required for Instagram/TikTok and URL is empty, try worker
        if ( ( 'instagram' === $platform || 'tiktok' === $platform ) && empty( $job['media_url'] ) ) {
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

        if ( is_wp_error( $result ) && PressHub_Social_Meta_API_Client::INSTAGRAM_PENDING_CODE === $result->get_error_code() ) {
            // Reels container still processing: check again shortly, keeping the job's retry budget.
            $data        = $result->get_error_data();
            $retry_after = is_array( $data ) && isset( $data['retry_after'] )
                ? (int) $data['retry_after']
                : PressHub_Social_Meta_API_Client::INSTAGRAM_PENDING_RECHECK_DELAY;
            PressHub_Social_Queue::defer_job( $job_id, $retry_after, $result->get_error_message() );
            return $result;
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
        $due_jobs    = PressHub_Social_Queue::get_due_pending_jobs();
        $worker_mode = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_worker_mode' )
            ? PressHub_Social_Settings_Storage::get_worker_mode()
            : 'pull';

        $summary  = [
            'total_due' => count( $due_jobs ),
            'succeeded' => 0,
            'failed'    => 0,
            'skipped'   => 0,
            'deferred'  => 0,
            'job_ids'   => [],
        ];

        foreach ( $due_jobs as $job ) {
            $job_id = (int) $job['id'];

            // In Pull Mode, automation jobs are reserved for the local worker polling via REST
            if ( 'pull' === $worker_mode && 'automation' === ( $job['engine'] ?? '' ) ) {
                $summary['skipped']++;
                continue;
            }

            $summary['job_ids'][] = $job_id;

            $res = self::dispatch_job( $job_id );
            if ( true === $res ) {
                $summary['succeeded']++;
            } elseif ( is_wp_error( $res ) && in_array( $res->get_error_code(), [ PressHub_Social_Meta_API_Client::INSTAGRAM_PENDING_CODE, 'social_post_images_pending' ], true ) ) {
                $summary['deferred']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
