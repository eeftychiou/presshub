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

        // Platforms that get the post's shorts (platform => engine), the article captions
        // already made for them, and the Facebook link post's time (its Reels follow it).
        $short_targets      = [];
        $short_copies       = [];
        $facebook_link_time = null;
        $twitter_link_time  = null;

        // A post whose stories another post tells in video gets no video of its own (the
        // written daily brief, 2.84.0).
        $no_video = self::no_video_reason( $post_id );

        foreach ( $platforms as $index => $platform ) {
            // Determine engine for platform
            $engine = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_platform_engine' )
                ? PressHub_Social_Settings_Storage::get_platform_engine( $platform )
                : 'api';

            if ( '' !== $no_video && in_array( $platform, self::VIDEO_PLATFORMS, true ) ) {
                PressHub_Social_Logger::info( sprintf( 'Post #%d is not queued for %s: %s', $post_id, $platform, $no_video ) );
                continue;
            }

            // YouTube only gets videos: the shorts (as YouTube Shorts) and, for a podcast
            // post, the full episode for the podcast playlist. Anything else is not posted there.
            if ( 'youtube' === $platform ) {
                // Social post gate, before the episode's AI description and the shorts.
                $gate_reason = PressHub_Social_Post_Gate::check_post( $post_id, $platform );
                if ( '' !== $gate_reason ) {
                    PressHub_Social_Post_Gate::record_blocked( $post_id, $platform, $engine, $gate_reason );
                    continue;
                }
                $yt_shorts = self::shorts_wanted( $post_id, 'youtube', $engine );
                if ( $yt_shorts ) {
                    $short_targets['youtube'] = $engine;
                }
                $job_ids = array_merge( $job_ids, self::enqueue_youtube( $post_id, $engine, $media_url, $media_type, $base_time + ( $index * $interval ), $yt_shorts ) );
                continue;
            }

            // TikTok photo slides (#333): a story goes to TikTok once, as a video short or,
            // when TikTok gets no video of it, as a photo post in place of the classic post
            // (2.84.0). An article's slides use its usual TikTok caption.
            $platform_shorts = in_array( $platform, [ 'tiktok', 'instagram' ], true ) && self::shorts_wanted( $post_id, $platform, $engine );
            if ( 'tiktok' === $platform && ! $platform_shorts
                && class_exists( 'PressHub_Social_TikTok_Shorts' ) && PressHub_Social_TikTok_Shorts::slides_wanted( $post_id, $engine, false ) ) {
                // Social post gate, before the slides' AI copy and images.
                $gate_reason = PressHub_Social_Post_Gate::check_post( $post_id, $platform );
                if ( '' !== $gate_reason ) {
                    PressHub_Social_Post_Gate::record_blocked( $post_id, $platform, $engine, $gate_reason );
                    continue;
                }
                $slide_ids = self::enqueue_tiktok_slides( $post_id, $engine, $base_time + ( $index * $interval ), null, false );
                if ( ! empty( $slide_ids ) ) {
                    $job_ids = array_merge( $job_ids, $slide_ids );
                    continue;
                }
            }

            // TikTok and Instagram get the shorts instead of their usual post. They are queued
            // with the other platforms' shorts after this loop, on one story schedule.
            if ( $platform_shorts ) {
                // Social post gate, before any caption or plan is made: an article's short
                // tells its one story, checked now; a podcast's stories are checked when planned.
                $gate_reason = PressHub_Social_Post_Gate::check_post( $post_id, $platform );
                if ( '' !== $gate_reason ) {
                    PressHub_Social_Post_Gate::record_blocked( $post_id, $platform, $engine, $gate_reason );
                    continue;
                }
                $short_targets[ $platform ] = $engine;
                // With the daily brief, TikTok also gets the full podcast (2.85.0): the classic
                // TikTok post below, half a spacing after the brief.
                if ( 'tiktok' !== $platform || ! PressHub_Social_TikTok_Shorts::full_podcast_wanted( $post_id ) ) {
                    continue;
                }
                $full_podcast = true;
            } else {
                $full_podcast = false;
            }

            // X video posts (2.86.0): an article's X post is its short, with the usual X
            // text and link (the image post is its fallback). A podcast keeps its link
            // tweet below and gets its daily brief as an extra post after it.
            $twitter_shorts = 'twitter' === $platform && self::shorts_wanted( $post_id, 'twitter', $engine );
            if ( $twitter_shorts && PressHub_Social_TikTok_Shorts::SOURCE_ARTICLE === PressHub_Social_TikTok_Shorts::source_type( $post_id ) ) {
                $gate_reason = PressHub_Social_Post_Gate::check_post( $post_id, $platform );
                if ( '' !== $gate_reason ) {
                    PressHub_Social_Post_Gate::record_blocked( $post_id, $platform, $engine, $gate_reason );
                    continue;
                }
                $x_copy = self::article_short_copy( $post_id, 'twitter' );
                if ( '' !== $x_copy && class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'sanitize_twitter_copy' ) ) {
                    $x_copy = PressHub_Social_AI_Copywriter::sanitize_twitter_copy( $x_copy, function_exists( 'get_permalink' ) ? (string) ( get_permalink( $post_id ) ?: '' ) : '' );
                }
                $short_copies['twitter']  = $x_copy;
                $short_targets['twitter'] = $engine;
                continue;
            }

            // Social post gate, before any AI copy or images are made for the post.
            $gate_reason = $full_podcast ? '' : PressHub_Social_Post_Gate::check_post( $post_id, $platform );
            if ( '' !== $gate_reason ) {
                PressHub_Social_Post_Gate::record_blocked( $post_id, $platform, $engine, $gate_reason );
                // The post's Reels are stopped with its link post: an article's Reel tells the
                // same story, and any other post is only stopped here by another check.
                continue;
            }

            // Check for per-post custom copy override
            $copy_meta = get_post_meta( $post_id, '_presshub_social_copy_' . $platform, true );
            $copy      = is_string( $copy_meta ) ? trim( $copy_meta ) : '';

            $scheduled_at = gmdate( 'Y-m-d H:i:s', $base_time + ( $index * $interval ) );
            if ( $full_podcast ) {
                $scheduled_at = gmdate( 'Y-m-d H:i:s', $base_time + (int) floor( PressHub_Social_Settings_Storage::get_tiktok_shorts_spacing_minutes() * 60 / 2 ) );
            }
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
            // The post's story tag, so the story ledger knows what this job told.
            $with_story = PressHub_Social_Post_Gate::with_story( $post_id, $job_data['metadata'] ?? null );
            if ( null !== $with_story ) {
                $job_data['metadata'] = $with_story;
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
                        sprintf( 'Enqueued social job #%d for post #%d on %s (engine: %s, scheduled: %s%s%s)', $job_id, $post_id, $platform, $engine, $scheduled_at, $with_images ? ', with AI images' : '', $full_podcast ? ', the full podcast next to the daily brief' : '' )
                    );
                }
            }

            // Facebook Reels: the shorts go to the Page too, after its link post (which is
            // kept), with the short's own caption.
            if ( 'facebook' === $platform && self::shorts_wanted( $post_id, 'facebook', $engine ) ) {
                $short_targets['facebook'] = $engine;
                $facebook_link_time        = strtotime( $scheduled_at . ' UTC' ) ?: $base_time;
            }
            // X: a podcast's daily brief follows its link tweet as an extra video post.
            if ( $twitter_shorts ) {
                $short_targets['twitter'] = $engine;
                $twitter_link_time        = strtotime( $scheduled_at . ' UTC' ) ?: $base_time;
            }
        }

        // The shorts of every platform, from one story schedule.
        if ( ! empty( $short_targets ) ) {
            $job_ids = array_merge( $job_ids, self::enqueue_shorts( $post_id, $short_targets, $media_url, $media_type, $base_time, $short_copies, $facebook_link_time, $twitter_link_time ) );
        }
        // TikTok story posts (2.85.0): instant news of one story each, after the daily brief.
        // YouTube Shorts get the same stories as videos (2.88.0).
        if ( isset( $short_targets['tiktok'] ) ) {
            $job_ids = array_merge( $job_ids, self::enqueue_tiktok_story_posts( $post_id, (string) $short_targets['tiktok'], $base_time, $short_targets['youtube'] ?? null ) );
        }

        if ( ! empty( $needs_images ) ) {
            PressHub_Social_Post_Images::schedule_prepare( $post_id );
        }

        return $job_ids;
    }

    /**
     * Post text of an article short or article photo slides: the post's own copy for the
     * platform, else its usual AI caption. '' when both fail, which leaves the short's own
     * caption in place.
     */
    private static function article_short_copy( int $post_id, string $platform ): string {
        $copy_meta = get_post_meta( $post_id, '_presshub_social_copy_' . $platform, true );
        $copy      = is_string( $copy_meta ) ? trim( $copy_meta ) : '';
        if ( '' === $copy && class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'generate_for_post' ) ) {
            $generated = PressHub_Social_AI_Copywriter::generate_for_post( $post_id, $platform );
            if ( is_wp_error( $generated ) ) {
                PressHub_Social_Logger::warning( sprintf( '%s caption for article short of post #%d failed (%s); the short\'s own caption is used.', $platform, $post_id, $generated->get_error_message() ) );
            } else {
                $copy = trim( (string) $generated );
            }
        }
        return $copy;
    }

    /**
     * Queue a post's TikTok photo slides when TikTok Photo Slides are on: one photo post per
     * story. A podcast's slides take the short's own caption at claim time; an article's
     * take its usual TikTok caption. enqueue_for_post() queues them only when TikTok gets no
     * video short of the post (2.84.0), so a story is not posted to TikTok twice.
     *
     * @param int         $start_time Unix time of the post's first TikTok post.
     * @param string|null $copy       An article's TikTok caption when the caller already has it;
     *                                null makes it here.
     * @param bool        $between    Place each photo post halfway between two shorts (true),
     *                                or on the story's own slot (false, no shorts on TikTok).
     * @return int[] IDs of the queued slide jobs.
     */
    public static function enqueue_tiktok_slides( int $post_id, string $engine, int $start_time, ?string $copy = null, bool $between = true ): array {
        if ( ! class_exists( 'PressHub_Social_TikTok_Shorts' ) || ! PressHub_Social_TikTok_Shorts::slides_wanted( $post_id, $engine ) ) {
            return [];
        }
        if ( PressHub_Social_TikTok_Shorts::SOURCE_ARTICLE === PressHub_Social_TikTok_Shorts::source_type( $post_id ) ) {
            // An article's slides use its TikTok caption, the same text as its video short.
            if ( null === $copy ) {
                $copy = self::article_short_copy( $post_id, 'tiktok' );
            }
        } else {
            PressHub_Social_TikTok_Shorts::cancel_superseded( $post_id, 'tiktok' );
        }
        $ids = [];
        foreach ( PressHub_Social_TikTok_Shorts::build_slides_jobs( $post_id, $start_time, $copy, $between ) as $slides_job ) {
            $job_id = PressHub_Social_Queue::enqueue( $slides_job );
            if ( is_wp_error( $job_id ) ) {
                PressHub_Social_Logger::error( sprintf( 'Failed to enqueue TikTok photo slides for post #%d: %s', $post_id, $job_id->get_error_message() ) );
            } elseif ( $job_id > 0 ) {
                $ids[] = (int) $job_id;
                PressHub_Social_Logger::info( sprintf( 'Enqueued TikTok photo slides job #%d for post #%d (scheduled: %s)', $job_id, $post_id, $slides_job['scheduled_at'] ) );
            }
        }
        if ( $ids ) {
            PressHub_Social_TikTok_Shorts::schedule_prepare( $post_id );
        }
        return $ids;
    }

    /**
     * Queue a podcast's TikTok story posts (2.85.0) when the daily brief is on and TikTok
     * Story Posts is above 0: one video short or photo slides post per story, the first one
     * Time Between Shorts after the brief ($start_time). Their stories are planned and
     * checked against the story ledger when prepared.
     *
     * With YouTube Shorts on ($youtube_engine set), each story post also goes to YouTube as
     * a Short (2.88.0), the platform stagger after its TikTok post: always a video, also when
     * TikTok posts that story as photo slides (YouTube takes only videos). Both share the
     * story posts' plan, voice and images, so the worker renders a video story once.
     *
     * @param string|null $youtube_engine The YouTube engine when YouTube gets the post's shorts.
     * @return int[] IDs of the queued story post jobs.
     */
    public static function enqueue_tiktok_story_posts( int $post_id, string $engine, int $start_time, ?string $youtube_engine = null ): array {
        if ( ! class_exists( 'PressHub_Social_TikTok_Shorts' ) || ! PressHub_Social_TikTok_Shorts::story_posts_wanted( $post_id, $engine ) ) {
            return [];
        }
        $story_jobs = PressHub_Social_TikTok_Shorts::build_story_post_jobs( $post_id, $start_time );
        if ( null !== $youtube_engine ) {
            $story_jobs = array_merge( $story_jobs, PressHub_Social_TikTok_Shorts::youtube_story_post_jobs( $story_jobs, $youtube_engine, self::shorts_stagger_minutes() * 60 ) );
        }
        $ids = [];
        foreach ( $story_jobs as $story_job ) {
            $job_id = PressHub_Social_Queue::enqueue( $story_job );
            if ( is_wp_error( $job_id ) ) {
                PressHub_Social_Logger::error( sprintf( 'Failed to enqueue a %s story post for post #%d: %s', PressHub_Social_TikTok_Shorts::platform_label( (string) $story_job['platform'] ), $post_id, $job_id->get_error_message() ) );
            } elseif ( $job_id > 0 ) {
                $ids[] = (int) $job_id;
                $format = PressHub_Social_TikTok_Shorts::FORMAT_SLIDES === ( $story_job['metadata'][ PressHub_Social_TikTok_Shorts::JOB_META_KEY ]['format'] ?? '' ) ? 'photo slides' : 'video short';
                PressHub_Social_Logger::info( sprintf( 'Enqueued %s story post job #%d for post #%d (%s, scheduled: %s)', PressHub_Social_TikTok_Shorts::platform_label( (string) $story_job['platform'] ), $job_id, $post_id, $format, $story_job['scheduled_at'] ) );
            }
        }
        if ( $ids ) {
            PressHub_Social_TikTok_Shorts::schedule_prepare( $post_id );
        }
        return $ids;
    }

    /** Platforms that take only video (or photo posts) of a post's stories. */
    public const VIDEO_PLATFORMS = [ 'tiktok', 'instagram', 'youtube' ];

    /** Post meta that marks a trending roundup (PressHub_AI_Social_Trending::META_KEY). */
    public const TRENDING_META_KEY = '_presshub_social_trending_key';

    /**
     * Why a post gets no video posts (TikTok, Instagram, YouTube, Facebook Reels), or ''.
     * - The written daily brief (`_presshub_briefing_type` = text): its podcast follows and
     *   tells the same stories in video. Filter `presshub_social_briefing_text_video`
     *   (default false) gives it videos again, e.g. on a site without podcasts.
     * Its X and Facebook link posts go out as usual.
     */
    public static function no_video_reason( int $post_id ): string {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return '';
        }
        if ( 'text' === (string) get_post_meta( $post_id, '_presshub_briefing_type', true ) ) {
            $video = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'presshub_social_briefing_text_video', false, $post_id ) : false;
            if ( ! $video ) {
                return 'it is the written daily brief, and its podcast tells the same stories in video.';
            }
        }
        return '';
    }

    /** Order of the platforms inside one story's slot. Facebook and X last: their link posts go first. */
    public const SHORTS_PLATFORM_ORDER = [ 'tiktok', 'instagram', 'youtube', 'facebook', 'twitter' ];

    /**
     * Minutes between two platforms' posts of the same story (filter
     * `presshub_social_shorts_platform_stagger_minutes`, 0 to 60).
     */
    public const SHORTS_PLATFORM_STAGGER_MINUTES = 5;

    public static function shorts_stagger_minutes(): int {
        $minutes = function_exists( 'apply_filters' )
            ? (int) apply_filters( 'presshub_social_shorts_platform_stagger_minutes', self::SHORTS_PLATFORM_STAGGER_MINUTES )
            : self::SHORTS_PLATFORM_STAGGER_MINUTES;
        return max( 0, min( 60, $minutes ) );
    }

    /**
     * Whether a platform gets this post's shorts now: its shorts switch, a source to cut
     * them from and an engine that can post them (should_split()), and for Facebook on the
     * Graph API, a short of 90 seconds or less (the two-minute daily brief is not).
     */
    public static function shorts_wanted( int $post_id, string $platform, string $engine ): bool {
        if ( ! class_exists( 'PressHub_Social_TikTok_Shorts' ) || ! PressHub_Social_TikTok_Shorts::should_split( $post_id, $engine, $platform ) ) {
            return false;
        }
        if ( in_array( $platform, [ 'facebook', 'twitter' ], true ) && '' !== self::no_video_reason( $post_id ) ) {
            return false; // No video either; the Facebook or X link post stays.
        }
        if ( 'twitter' === $platform
            && PressHub_Social_TikTok_Shorts::SOURCE_ARTICLE !== PressHub_Social_TikTok_Shorts::source_type( $post_id )
            && ! PressHub_Social_Settings_Storage::get_tiktok_brief_enabled() ) {
            // X gets a podcast's daily brief only, never one tweet per story.
            PressHub_Social_Logger::info( sprintf( 'X video posts are on but podcast post #%d gets no X video: X gets only the daily brief, and Podcast Shorts Format is one short per story. Its X link post goes out as usual.', $post_id ) );
            return false;
        }
        if ( 'facebook' === $platform && 'api' === $engine
            && PressHub_Social_TikTok_Shorts::SOURCE_ARTICLE !== PressHub_Social_TikTok_Shorts::source_type( $post_id )
            && PressHub_Social_Settings_Storage::get_tiktok_brief_enabled() ) {
            // The Graph API takes Reels of 3 to 90 seconds, so the two-minute daily brief
            // cannot go there; the browser session posts it as a video.
            PressHub_Social_Logger::warning( sprintf( 'Facebook Reels are on but podcast post #%d gets no Reel: the daily brief is about two minutes and the Graph API takes Reels of up to 90 seconds. Use the Facebook automation engine, or set Podcast Shorts Format to one short per story.', $post_id ) );
            return false;
        }
        return true;
    }

    /**
     * Queue a post's shorts for every platform that gets them, from one story schedule:
     * story n goes out at $start_time + n x the time between shorts on every platform, the
     * platforms SHORTS_PLATFORM_STAGGER_MINUTES apart in SHORTS_PLATFORM_ORDER. A Facebook
     * Reel never goes out before the post's Facebook link post. The superseded shorts of
     * earlier podcasts are cancelled once per platform, and the preparation (one plan, one
     * voice track and image set per story, whatever the platforms) is scheduled once.
     *
     * Article shorts take each platform's usual caption ($copies, else made here); a
     * Facebook Reel and a podcast short take the short's own caption at claim time.
     *
     * @param array<string,string> $targets   Platform => engine.
     * @param array<string,string> $copies    Article captions already made, by platform.
     * @param int|null             $link_time Unix time of the Facebook link post, if any.
     * @param int|null             $twitter_link_time Unix time of a podcast's X link post, if any
     *                                                (its daily brief video follows it).
     * @return int[] IDs of the queued short jobs.
     */
    public static function enqueue_shorts( int $post_id, array $targets, ?string $media_url, ?string $media_type, int $start_time, array $copies = [], ?int $link_time = null, ?int $twitter_link_time = null ): array {
        if ( ! class_exists( 'PressHub_Social_TikTok_Shorts' ) || empty( $targets ) ) {
            return [];
        }
        $article = PressHub_Social_TikTok_Shorts::SOURCE_ARTICLE === PressHub_Social_TikTok_Shorts::source_type( $post_id );
        $stagger = self::shorts_stagger_minutes() * 60;
        $ids     = [];
        $slot    = 0;
        foreach ( self::SHORTS_PLATFORM_ORDER as $platform ) {
            if ( ! isset( $targets[ $platform ] ) ) {
                continue;
            }
            $engine = (string) $targets[ $platform ];
            if ( ! $article ) {
                PressHub_Social_TikTok_Shorts::cancel_superseded( $post_id, $platform );
            }
            $start = $start_time + ( $slot * $stagger );
            ++$slot;
            $copy = null;
            if ( 'facebook' === $platform ) {
                // A Reel is always the worker's job (it renders the video), also when the link
                // post goes through the API: the worker then uploads it through the Graph API.
                $engine = 'automation';
                if ( null !== $link_time ) {
                    $start = max( $start, $link_time + $stagger );
                }
            } elseif ( $article ) {
                $copy = $copies[ $platform ] ?? self::article_short_copy( $post_id, $platform );
            }
            if ( 'twitter' === $platform && null !== $twitter_link_time ) {
                $start = max( $start, $twitter_link_time + $stagger );
            }
            $label = 'facebook' === $platform ? 'Facebook Reel' : ( 'twitter' === $platform ? 'X video' : $platform . ' short' );
            foreach ( PressHub_Social_TikTok_Shorts::build_jobs( $post_id, $engine, $media_url, $media_type, $start, false, $platform, $copy ) as $short_job ) {
                $job_id = PressHub_Social_Queue::enqueue( $short_job );
                if ( is_wp_error( $job_id ) ) {
                    PressHub_Social_Logger::error( sprintf( 'Failed to enqueue %s for post #%d: %s', $label, $post_id, $job_id->get_error_message() ) );
                } elseif ( $job_id > 0 ) {
                    $ids[] = (int) $job_id;
                    PressHub_Social_Logger::info( sprintf( 'Enqueued %s job #%d for post #%d (scheduled: %s)', $label, $job_id, $post_id, $short_job['scheduled_at'] ) );
                }
            }
        }
        if ( $ids ) {
            PressHub_Social_TikTok_Shorts::schedule_prepare( $post_id );
        }
        return $ids;
    }

    /**
     * Queue a post's shorts as Facebook Reels when Facebook Reels are on: Shorts per Podcast
     * jobs for a podcast post, one for an article, the first one the stagger after the link post.
     *
     * @param int $link_time Unix time of the post's Facebook link post.
     * @return int[] IDs of the queued Reel jobs.
     */
    public static function enqueue_facebook_shorts( int $post_id, string $engine, ?string $media_url, ?string $media_type, int $link_time ): array {
        if ( ! self::shorts_wanted( $post_id, 'facebook', $engine ) ) {
            return [];
        }
        return self::enqueue_shorts( $post_id, [ 'facebook' => $engine ], $media_url, $media_type, $link_time, [], $link_time );
    }

    /**
     * Queue a post's full YouTube podcast episode when podcast episodes are on (its YouTube
     * Shorts are queued with the other platforms' shorts by enqueue_shorts()). The
     * description is the YouTube post copy (an editor's copy, else the AI copywriter's).
     *
     * @param bool $with_shorts Whether the post's shorts go to YouTube (only for the log line).
     *
     * @return array<int> Enqueued job IDs.
     */
    private static function enqueue_youtube( int $post_id, string $engine, ?string $media_url, ?string $media_type, int $start_time, bool $with_shorts = false ): array {
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

        if ( empty( $job_ids ) && ! $with_shorts ) {
            PressHub_Social_Logger::info( sprintf( 'Post #%d is not posted to YouTube: it gets only shorts (YouTube Shorts off, or no short for this post) and podcast episodes (podcast episodes off, or not a podcast post).', $post_id ) );
        }
        return $job_ids;
    }

    /**
     * Dispatch an individual social job immediately.
     *
     * @param int  $job_id
     * @param bool $gate False skips the social post gate (a job reposted by hand).
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function dispatch_job( int $job_id, bool $gate = true ): bool|WP_Error {
        $job = PressHub_Social_Queue::get_job( $job_id );
        if ( ! $job ) {
            // translators: %d: queue job ID
            return new WP_Error( 'job_not_found', sprintf( __( 'Social job #%d not found.', 'presshub-ai-editor' ), $job_id ) );
        }

        // If already published, do not re-dispatch
        if ( 'published' === ( $job['status'] ?? '' ) ) {
            return true;
        }

        $platform = sanitize_key( (string) ( $job['platform'] ?? '' ) );
        $engine   = sanitize_key( (string) ( $job['engine'] ?? 'api' ) );
        $post_id  = (int) ( $job['post_id'] ?? 0 );

        // Social post gate: checked again, before any copy is rewritten or anything
        // is sent, against what went out since the job was queued. A manual repost skips it.
        if ( $gate ) {
            $gate_reason = PressHub_Social_Post_Gate::check_job( $job );
            if ( '' !== $gate_reason ) {
                PressHub_Social_Post_Gate::block_job( $job_id, $gate_reason );
                return new WP_Error( PressHub_Social_Post_Gate::BLOCKED_CODE, 'Not posted: ' . $gate_reason );
            }
        }

        // If this job previously failed during AI copy generation, regenerate copy BEFORE
        // entering Pull Mode or dispatching so an error placeholder is never queued as pending.
        $current_copy = (string) ( $job['post_copy'] ?? '' );
        if ( self::is_failed_copy_placeholder( $current_copy ) ) {
            if ( $post_id <= 0 || ! class_exists( 'PressHub_Social_AI_Copywriter' ) ) {
                $error = new WP_Error( 'failed_copy_placeholder', __( 'Cannot dispatch job with an AI copy generation failure placeholder.', 'presshub-ai-editor' ) );
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
                $error = new WP_Error( 'failed_copy_placeholder', __( 'Regenerated copy is empty or invalid.', 'presshub-ai-editor' ) );
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
                __( 'Job is waiting for its AI images and will be sent once they are ready.', 'presshub-ai-editor' )
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
                __( 'Job is queued in Pull Mode and will be picked up automatically by your local worker on its next poll.', 'presshub-ai-editor' )
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
        $worker_mode = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_worker_mode' )
            ? PressHub_Social_Settings_Storage::get_worker_mode()
            : 'pull';
        // 2.76.1: in Pull Mode the worker claims the automation jobs, so the batch holds only the
        // jobs the cron sends itself. Before, with a small batch size, due automation jobs (e.g. while
        // the worker was down) filled the batch and the API jobs behind them were never sent.
        $due_jobs = PressHub_Social_Queue::get_due_pending_jobs( 0, null, [], [], 'pull' === $worker_mode ? 'automation' : null );

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
            } elseif ( is_wp_error( $res ) && PressHub_Social_Post_Gate::BLOCKED_CODE === $res->get_error_code() ) {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
