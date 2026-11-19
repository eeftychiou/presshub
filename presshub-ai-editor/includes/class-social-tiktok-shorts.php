<?php
/**
 * TikTok shorts: turns one podcast post into several 30-60 second TikTok videos,
 * one story each, instead of posting the whole episode.
 *
 * Flow:
 * 1. Enqueue (PressHub_Social_Dispatcher): when shorts are enabled and the post is a
 *    podcast post, the TikTok platform gets `presshub_social_tiktok_shorts_count` jobs
 *    instead of one, spaced `presshub_social_tiktok_shorts_spacing_minutes` apart. Each
 *    job carries `metadata.tiktok_short = {index, count}` and a placeholder copy, and a
 *    background preparation event is scheduled for the post.
 * 2. Prepare (WP-Cron `presshub_social_prepare_tiktok_shorts`, prepare_post()): one LLM
 *    call writes the plan of shorts (prompt `presshub_social_prompt_tiktok_shorts`, seeded
 *    from social_tiktok_shorts.txt), stored in post meta `_presshub_tiktok_shorts`. Each
 *    pending short job's script is then synthesized turn by turn with the Daily Briefing
 *    voices (turn cache under the briefing date), stitched, sideloaded and stored in the
 *    job's `metadata.tiktok_short` with its topic, hook and caption, plus the start and
 *    end of every spoken turn (`audio.segments`) for the worker's synced captions. Then
 *    up to `presshub_social_tiktok_shorts_images` story images are generated (9:16) from
 *    the plan's `visuals` and stored as `images`. A failed image is a WARNING, not a
 *    failure: the worker renders the short on a branded background instead (never on
 *    the podcast's featured image). Surplus jobs (the
 *    plan has fewer stories than jobs) are cancelled. Failures go through the queue's
 *    retry budget (mark_failed), so a job is never left in `processing`.
 * 3. Claim (PressHub_Social_REST_Controller::handle_claim): a short job is claimed only
 *    once it is prepared; an unprepared one stays pending and its preparation is
 *    (re)scheduled. attach_short() only reads the prepared metadata, so the claim request
 *    makes no LLM or TTS call. The job gets `audio_url`, `video_title` (the hook), the
 *    short's images and turn timings, and the caption in place of the placeholder copy
 *    (a copy an editor wrote is kept).
 *
 * Test renders (enqueue_test_render(), "Render Test TikTok Shorts" on a podcast post):
 * the same jobs flagged `metadata.tiktok_short.test`, queued now and prepared with the
 * real prompts, voices and images. The worker renders them and saves the videos in its
 * output folder (tiktok-short-tests/) instead of posting, then reports `test_rendered`;
 * the job ends as cancelled with the video's path in its note. Shorts need not be
 * enabled for a test render, only Pull Mode.
 *
 * Background music (PressHub_Social_TikTok_Music): the plan picks a `mood` per story; at
 * claim time music_for() adds one of that mood's generated tracks (`music_url`,
 * `music_volume`) when music is on. `none` and a mood without tracks get no music.
 *
 * Article shorts (`presshub_social_tiktok_article_shorts_enabled`, default on): a post that
 * is not a podcast (an article, an opinion piece) gets ONE short job per platform whose
 * shorts are on, marked `metadata.tiktok_short.source = article`. Its plan is written from
 * the article text with its own prompt (`presshub_social_prompt_tiktok_article_short`),
 * then voiced, illustrated and scored like a podcast short. The post text stays the usual
 * AI caption of that platform (the plan's caption is used only when that failed). If the
 * short still cannot be prepared after the retry budget, the job falls back to the classic
 * article post (fallback_to_classic()) instead of failing, so the article is still posted.
 * A new podcast never cancels article shorts.
 *
 * The same shorts go to Instagram Reels and YouTube Shorts (`PLATFORMS`). A YouTube short
 * is uploaded by the worker with the YouTube Data API; a failed YouTube article short fails
 * instead of falling back to a normal post, since YouTube takes only videos.
 *
 * Zero-Silent-Fallback: an empty prompt, an invalid plan or a failed synthesis fails the
 * job; a short job is never posted as the full podcast or with its placeholder copy.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_TikTok_Shorts {

    /** Hashtags a short's caption gets when the AI wrote none (the prompt asks for these first). */
    const DEFAULT_HASHTAGS = '#κύπρος #cyprus #ειδήσεις';

    /** Post meta holding the generated plan of shorts for a podcast post. */
    public const PLAN_META = '_presshub_tiktok_shorts';

    /** Job metadata key marking a short job and holding its prepared short. */
    public const JOB_META_KEY = 'tiktok_short';

    /** `metadata.tiktok_short.source` of a short made from an article (not a podcast). */
    public const SOURCE_ARTICLE = 'article';

    /** Most characters of article text sent to the article short prompt. */
    public const ARTICLE_TEXT_MAX_CHARS = 15000;

    /** Prefix of the placeholder copy set at enqueue time. */
    public const PLACEHOLDER_PREFIX = '[TikTok short ';

    /** WP-Cron hook that prepares the shorts of a post. */
    public const PREPARE_HOOK = 'presshub_social_prepare_tiktok_shorts';

    /** Post meta counting failed preparation runs since the last clean one. */
    public const PREP_ATTEMPTS_META = '_presshub_tiktok_shorts_prep_attempts';

    /**
     * Optional LLM override for tests.
     * Signature: fn( string $sys_prompt, string $user_prompt ): string|WP_Error
     *
     * @var callable|null
     */
    private static $ai_caller = null;

    /**
     * Optional synthesizer override for tests.
     * Signature: fn( array $short, array $job ): array{url:string,id:int,voices?:array}|WP_Error
     *
     * @var callable|null
     */
    private static $synthesizer = null;

    /**
     * Optional image generator override for tests.
     * Signature: fn( string $prompt, int $post_id, array $short ): array{url:string,id:int}|WP_Error
     *
     * @var callable|null
     */
    private static $image_generator = null;

    public static function set_image_generator( ?callable $generator ): void {
        self::$image_generator = $generator;
    }

    public static function set_ai_caller( ?callable $caller ): void {
        self::$ai_caller = $caller;
    }

    public static function set_synthesizer( ?callable $synthesizer ): void {
        self::$synthesizer = $synthesizer;
    }

    public static function register_hooks(): void {
        if ( function_exists( 'add_action' ) ) {
            add_action( self::PREPARE_HOOK, [ __CLASS__, 'prepare_post' ], 10, 1 );
        }
    }

    /**
     * Whether a post is a Daily Briefing podcast post with a script to cut shorts from.
     */
    public static function is_podcast_post( int $post_id ): bool {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return false;
        }
        return 'podcast' === (string) get_post_meta( $post_id, '_presshub_briefing_type', true )
            && '' !== trim( (string) get_post_meta( $post_id, '_presshub_podcast_script', true ) );
    }

    /**
     * What a post's shorts are cut from: 'podcast' (a Daily Briefing podcast with its
     * script), 'article' (any other post with text), or '' (nothing to cut from).
     */
    public static function source_type( int $post_id ): string {
        if ( self::is_podcast_post( $post_id ) ) {
            return 'podcast';
        }
        if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
            return '';
        }
        // A podcast post without its script keeps going out as the full podcast.
        if ( 'podcast' === (string) get_post_meta( $post_id, '_presshub_briefing_type', true ) ) {
            return '';
        }
        return '' !== self::article_text( $post_id ) ? self::SOURCE_ARTICLE : '';
    }

    /**
     * The article's text for its short: the post content without markup, shortcodes or
     * audio players, cut to ARTICLE_TEXT_MAX_CHARS.
     */
    public static function article_text( int $post_id ): string {
        $post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        if ( ! $post ) {
            return '';
        }
        $content = (string) ( is_object( $post ) ? ( $post->post_content ?? '' ) : ( $post['post_content'] ?? '' ) );
        if ( function_exists( 'strip_shortcodes' ) ) {
            $content = strip_shortcodes( $content );
        }
        $content = (string) preg_replace( '/<(script|style|audio|video|figure)\b[^>]*>.*?<\/\1>/isu', ' ', $content );
        $content = (string) preg_replace( '/<\/(p|h[1-6]|li|blockquote|div)>|<br\s*\/?>/iu', "\n", $content );
        $content = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : strip_tags( $content );
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // &nbsp; (U+00A0) and other Unicode spaces count as spaces, so an empty spacer
        // paragraph is no text.
        $content = (string) preg_replace( '/[ \t\p{Zs}\x{200B}\x{FEFF}]+/u', ' ', $content );
        $content = trim( (string) preg_replace( '/\s*\n\s*/u', "\n", $content ) );
        return mb_substr( $content, 0, self::ARTICLE_TEXT_MAX_CHARS );
    }

    /**
     * Whether a job is a short made from an article (one short, falls back to the classic post).
     */
    public static function is_article_job( array $job ): bool {
        if ( ! self::is_short_job( $job ) ) {
            return false;
        }
        return self::SOURCE_ARTICLE === ( self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ]['source'] ?? '' );
    }

    /**
     * Whether shorts can be made with this engine: they are claimed by the local worker,
     * so they need the automation engine in Pull Mode.
     */
    public static function engine_supported( string $engine ): bool {
        return 'automation' === $engine && 'pull' === PressHub_Social_Settings_Storage::get_worker_mode();
    }

    /**
     * Whether the TikTok/Instagram job for this post should be split into shorts. With shorts enabled
     * but an unsupported engine, the full podcast is posted and a WARNING says why.
     */
    public static function should_split( int $post_id, string $engine, string $platform = 'tiktok' ): bool {
        if ( 'instagram' === $platform ) {
            $enabled = PressHub_Social_Settings_Storage::get_instagram_shorts_enabled();
        } elseif ( 'youtube' === $platform ) {
            $enabled = PressHub_Social_Settings_Storage::get_youtube_shorts_enabled();
        } else {
            $enabled = PressHub_Social_Settings_Storage::get_tiktok_shorts_enabled();
        }
        if ( ! $enabled ) {
            return false;
        }
        $source = self::source_type( $post_id );
        if ( '' === $source ) {
            return false;
        }
        if ( self::SOURCE_ARTICLE === $source ) {
            if ( ! PressHub_Social_Settings_Storage::get_tiktok_article_shorts_enabled() ) {
                return false;
            }
            if ( '' === PressHub_Social_Settings_Storage::get_tiktok_article_short_prompt() ) {
                PressHub_Social_Logger::warning( sprintf( 'Article Shorts are on but post #%d is posted the classic way: the Article Shorts Script Prompt (presshub_social_prompt_tiktok_article_short) is empty.', $post_id ) );
                return false;
            }
            if ( ! self::engine_supported( $engine ) ) {
                PressHub_Social_Logger::warning( sprintf(
                    'Article Shorts are on but post #%d goes to %s the classic way: shorts need the automation engine in Pull Mode (engine: %s, worker mode: %s).',
                    $post_id,
                    self::platform_label( $platform ),
                    $engine,
                    PressHub_Social_Settings_Storage::get_worker_mode()
                ) );
                return false;
            }
            return true;
        }
        if ( ! self::engine_supported( $engine ) ) {
            PressHub_Social_Logger::warning( sprintf(
                '%s shorts are enabled but post #%d is posted as the full podcast: shorts need the automation engine in Pull Mode (engine: %s, worker mode: %s).',
                self::platform_label( $platform ),
                $post_id,
                $engine,
                PressHub_Social_Settings_Storage::get_worker_mode()
            ) );
            return false;
        }
        return true;
    }

    public static function placeholder_copy( int $index, int $count ): string {
        return sprintf( '%s%d/%d: the script, audio and caption are being prepared]', self::PLACEHOLDER_PREFIX, $index + 1, $count );
    }

    public static function is_placeholder_copy( string $copy ): bool {
        return '' === trim( $copy ) || str_starts_with( $copy, self::PLACEHOLDER_PREFIX );
    }

    /**
     * Issue #189: when a new podcast post gets its shorts, cancel the shorts of earlier
     * podcast posts that have not been posted yet. A morning short left for 13:00 would
     * otherwise go out with 06:45 news while the noon edition is being published.
     * Test renders, and jobs already claimed by the worker, are left alone. Filter:
     * `presshub_social_tiktok_shorts_cancel_superseded` (default true).
     *
     * @param int $post_id The new podcast post.
     * @return int[] IDs of the cancelled jobs.
     */
    public static function cancel_superseded( int $post_id, string $platform = 'tiktok' ): array {
        $on = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'presshub_social_tiktok_shorts_cancel_superseded', true, $post_id, $platform ) : true;
        if ( ! $on || $post_id <= 0 ) {
            return [];
        }
        $label     = 'instagram' === $platform ? 'Instagram Reel' : self::platform_label( $platform );
        $cancelled = [];
        foreach ( PressHub_Social_Queue::query_jobs( [ 'status' => 'pending', 'platform' => $platform, 'limit' => 100 ] ) as $job ) {
            $job_post = (int) ( $job['post_id'] ?? 0 );
            if ( $job_post === $post_id || $job_post <= 0 ) {
                continue;
            }
            $short = self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ] ?? null;
            // Test renders and article shorts are never superseded by a podcast.
            if ( ! is_array( $short ) || ! empty( $short['test'] ) || self::SOURCE_ARTICLE === ( $short['source'] ?? '' ) ) {
                continue;
            }
            if ( PressHub_Social_Queue::cancel_job( (int) $job['id'] ) ) {
                $cancelled[] = (int) $job['id'];
                PressHub_Social_Logger::info( sprintf(
                    '%s short job #%d (post #%d, short %d/%d) cancelled: podcast post #%d is newer.',
                    $label,
                    (int) $job['id'],
                    $job_post,
                    (int) ( $short['index'] ?? 0 ) + 1,
                    (int) ( $short['count'] ?? 0 ),
                    $post_id
                ) );
            }
        }
        return $cancelled;
    }

    /**
     * Build the job rows for the shorts of a post: Shorts per Podcast jobs for a podcast
     * post, one job for an article.
     *
     * @param string|null $copy Post text of an article short (its usual AI caption); null
     *                          or '' uses the placeholder, filled with the plan's caption.
     * @return array<int, array> Job data arrays for PressHub_Social_Queue::enqueue().
     */
    public static function build_jobs( int $post_id, string $engine, ?string $media_url, ?string $media_type, int $start_time, bool $test = false, string $platform = 'tiktok', ?string $copy = null ): array {
        $article = self::SOURCE_ARTICLE === self::source_type( $post_id );
        $count   = $article ? 1 : PressHub_Social_Settings_Storage::get_tiktok_shorts_count();
        $spacing = $test ? 0 : PressHub_Social_Settings_Storage::get_tiktok_shorts_spacing_minutes() * 60;
        $jobs    = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $meta = [ 'index' => $i, 'count' => $count ];
            if ( $article ) {
                $meta['source'] = self::SOURCE_ARTICLE;
            }
            if ( $test ) {
                $meta['test'] = true;
            }
            $jobs[] = [
                'post_id'      => $post_id,
                'platform'     => $platform,
                'engine'       => $engine,
                'post_copy'    => ( $article && null !== $copy && '' !== trim( $copy ) ) ? $copy : self::placeholder_copy( $i, $count ),
                'media_url'    => $media_url,
                'media_type'   => $media_type,
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', $start_time + ( $i * $spacing ) ),
                'status'       => 'pending',
                'metadata'     => [ self::JOB_META_KEY => $meta ],
            ];
        }
        return $jobs;
    }

    /**
     * Queue a test render of a post's shorts (a podcast's, or an article's one short): the
     * real prompts, voices and images, rendered by the worker into its output folder and
     * never posted.
     *
     * @return int[]|WP_Error IDs of the queued test jobs.
     */
    public static function enqueue_test_render( int $post_id ) {
        if ( '' === self::source_type( $post_id ) ) {
            return new WP_Error( 'tiktok_short_no_script', sprintf( 'post #%d is neither a Daily Briefing podcast post with a script nor an article with text.', $post_id ) );
        }
        if ( 'pull' !== PressHub_Social_Settings_Storage::get_worker_mode() ) {
            return new WP_Error( 'tiktok_short_needs_pull', 'test renders are made by the local worker, so the worker must be in Pull Mode.' );
        }

        $ids = [];
        foreach ( self::build_jobs( $post_id, 'automation', null, null, time(), true ) as $job ) {
            $id = PressHub_Social_Queue::enqueue( $job );
            if ( is_wp_error( $id ) ) {
                foreach ( $ids as $done ) {
                    PressHub_Social_Queue::cancel_job( $done );
                }
                return $id;
            }
            $ids[] = (int) $id;
        }
        self::schedule_prepare( $post_id );
        PressHub_Social_Logger::info( sprintf( 'Queued a test render of %d TikTok shorts for post #%d (jobs %s). They are rendered by the worker and not posted.', count( $ids ), $post_id, implode( ', ', $ids ) ) );
        return $ids;
    }

    /**
     * Whether a job is a test render of a short (rendered by the worker, never posted).
     */
    public static function is_test_job( array $job ): bool {
        if ( ! self::is_short_job( $job ) ) {
            return false;
        }
        return ! empty( self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ]['test'] );
    }

    /**
     * Schedule the background preparation of a post's shorts (once at a time).
     */
    public static function schedule_prepare( int $post_id, int $delay = 0 ): void {
        if ( $post_id <= 0 || ! function_exists( 'wp_schedule_single_event' ) ) {
            return;
        }
        $args = [ $post_id ];
        if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::PREPARE_HOOK, $args ) ) {
            return;
        }
        wp_schedule_single_event( time() + max( 0, $delay ), self::PREPARE_HOOK, $args );
    }

    /** Platforms that get the shorts. */
    public const PLATFORMS = [ 'tiktok', 'instagram', 'youtube' ];

    /** Name of a shorts platform for log lines. */
    public static function platform_label( string $platform ): string {
        $labels = [ 'tiktok' => 'TikTok', 'instagram' => 'Instagram', 'youtube' => 'YouTube' ];
        return $labels[ $platform ] ?? ucfirst( $platform );
    }

    public static function is_short_job( array $job ): bool {
        if ( ! in_array( $job['platform'] ?? '', self::PLATFORMS, true ) ) {
            return false;
        }
        $meta = self::decode_metadata( $job['metadata'] ?? null );
        return is_array( $meta[ self::JOB_META_KEY ] ?? null ) && isset( $meta[ self::JOB_META_KEY ]['index'] );
    }

    /**
     * Whether a short job has its audio (with turn timings), hook, caption and images
     * prepared. A job prepared before turn timings and images existed is prepared again
     * (the turn audio comes from the disk cache).
     */
    public static function is_ready( array $job ): bool {
        $short = self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ] ?? null;
        return is_array( $short )
            && ! empty( $short['audio']['audio_url'] )
            && ! empty( $short['audio']['segments'] )
            && is_array( $short['images'] ?? null )
            && '' !== trim( (string) ( $short['hook'] ?? '' ) )
            && '' !== trim( (string) ( $short['caption'] ?? '' ) );
    }

    /**
     * The generated caption of a prepared short job, or '' before preparation.
     */
    public static function generated_caption( array $job ): string {
        $short = self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ] ?? null;
        return is_array( $short ) ? trim( (string) ( $short['caption'] ?? '' ) ) : '';
    }

    /**
     * Attach a prepared short's audio, caption and on-screen hook to a claimed job.
     * Makes no LLM or TTS call: an unprepared job is an error (claims skip those).
     *
     * @return array|WP_Error
     */
    public static function attach_short( array $job ) {
        if ( ! self::is_short_job( $job ) ) {
            return $job;
        }
        $job_id = (int) ( $job['id'] ?? 0 );
        if ( ! self::is_ready( $job ) ) {
            return self::fail( $job_id, new WP_Error( 'tiktok_short_not_ready', 'the short has not been prepared yet.' ) );
        }

        $short = self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ];

        // The placeholder becomes the short's caption; a copy an editor wrote stays.
        $copy = (string) ( $job['post_copy'] ?? '' );
        if ( self::is_placeholder_copy( $copy ) ) {
            $copy = (string) $short['caption'];
            if ( $job_id > 0 ) {
                PressHub_Social_Queue::update_job_copy( $job_id, $copy );
            }
        }

        $job['post_copy']    = $copy;
        $job['test_render']  = ! empty( $short['test'] );
        $job['audio_url']    = (string) $short['audio']['audio_url'];
        $job['video_title']  = (string) $short['hook'];
        $job['tiktok_short'] = [
            'index' => (int) $short['index'],
            // Stories the plan actually has (surplus jobs are cancelled), for the n/N badge.
            'count' => (int) ( $short['total'] ?? $short['count'] ?? 0 ),
            'topic'    => (string) ( $short['topic'] ?? '' ),
            'hook'     => (string) $short['hook'],
            'images'   => array_values( array_filter( array_map( fn( $img ) => is_array( $img ) ? (string) ( $img['url'] ?? '' ) : '', (array) ( $short['images'] ?? [] ) ) ) ),
            'segments' => array_values( (array) ( $short['audio']['segments'] ?? [] ) ),
        ];
        if ( self::SOURCE_ARTICLE === ( $short['source'] ?? '' ) ) {
            $job['tiktok_short']['source'] = self::SOURCE_ARTICLE;
            $job['tiktok_short']['badge']  = self::article_badge( (int) ( $job['post_id'] ?? 0 ) );
        }
        $job['tiktok_short'] = array_merge( $job['tiktok_short'], self::music_for( $short, $job_id ) );
        return $job;
    }

    /**
     * The post's primary category name: its first category that is not Uncategorized,
     * or '' when it has none. Shared by the article short prompt and its badge.
     */
    public static function primary_category( int $post_id ): string {
        if ( $post_id <= 0 || ! function_exists( 'get_the_category' ) ) {
            return '';
        }
        $cats = get_the_category( $post_id );
        // The site's default category (Uncategorized, Χωρίς κατηγορία, or whatever it was
        // renamed to) is not a real category.
        $default_id = function_exists( 'get_option' ) ? (int) get_option( 'default_category', 0 ) : 0;
        foreach ( is_array( $cats ) ? $cats : [] as $cat ) {
            $cat_name = is_object( $cat ) ? (string) ( $cat->name ?? '' ) : ( is_array( $cat ) ? (string) ( $cat['name'] ?? '' ) : '' );
            $slug     = is_object( $cat ) ? (string) ( $cat->slug ?? '' ) : ( is_array( $cat ) ? (string) ( $cat['slug'] ?? '' ) : '' );
            $term_id  = is_object( $cat ) ? (int) ( $cat->term_id ?? 0 ) : ( is_array( $cat ) ? (int) ( $cat['term_id'] ?? 0 ) : 0 );
            $lc_name  = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $cat_name ) ) : strtolower( trim( $cat_name ) );
            if ( '' === trim( $cat_name ) || 'uncategorized' === $slug || 'uncategorized' === $lc_name || 'χωρίς κατηγορία' === $lc_name ) {
                continue;
            }
            if ( $default_id > 0 && $term_id === $default_id ) {
                continue;
            }
            return trim( $cat_name );
        }
        return '';
    }

    /**
     * On-screen badge of an article short: the post's primary category in capitals
     * without accents (e.g. ΑΠΟΨΗ), or ΑΡΘΡΟ.
     */
    public static function article_badge( int $post_id ): string {
        $name = self::primary_category( $post_id );
        if ( '' === $name ) {
            $name = 'Άρθρο';
        }
        $name = mb_strtoupper( $name, 'UTF-8' );
        return strtr( $name, [ 'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ' ] );
    }

    /**
     * Background music for a claimed short: the story's mood and one of that mood's
     * tracks, or nothing when music is off, the mood is `none` or the mood has no track
     * yet (a WARNING, the short is still rendered).
     *
     * @return array{mood?:string,music_url?:string,music_volume?:float}
     */
    public static function music_for( array $short, int $job_id ): array {
        if ( ! PressHub_Social_Settings_Storage::get_tiktok_shorts_music_enabled() ) {
            return [];
        }
        $mood = PressHub_Social_TikTok_Music::normalize_mood( $short['mood'] ?? '' );
        if ( PressHub_Social_TikTok_Music::NO_MUSIC === $mood ) {
            return [ 'mood' => $mood ];
        }
        $url = PressHub_Social_TikTok_Music::pick_track( $mood, $job_id );
        if ( '' === $url ) {
            PressHub_Social_Logger::warning( sprintf( 'TikTok short job #%d has no %s music track, so it is rendered without music. Generate the tracks in PressHub → Social settings → Automation Worker → Generate Music Tracks.', $job_id, $mood ) );
            return [ 'mood' => $mood ];
        }
        return [
            'mood'         => $mood,
            'music_url'    => $url,
            'music_volume' => round( PressHub_Social_Settings_Storage::get_tiktok_shorts_music_volume() / 100, 3 ),
        ];
    }

    /**
     * Prepare the shorts of a podcast post: the plan, then each pending job's audio.
     * Runs from WP-Cron. Failures go through the queue's retry budget.
     */
    public static function prepare_post( $post_id ): void {
        $post_id = (int) $post_id;
        $lock    = 'presshub_social_tiktok_shorts_lock_' . $post_id;
        if ( function_exists( 'get_transient' ) && get_transient( $lock ) ) {
            PressHub_Social_Logger::info( sprintf( 'TikTok shorts for post #%d are already being prepared.', $post_id ) );
            return;
        }
        if ( function_exists( 'set_transient' ) ) {
            set_transient( $lock, 1, 1800 ); // TTS plus image generation for every short.
        }

        try {
            self::run_prepare( $post_id );
        } finally {
            if ( function_exists( 'delete_transient' ) ) {
                delete_transient( $lock );
            }
        }
    }

    private static function run_prepare( int $post_id ): void {
        $all     = array_values( array_filter( PressHub_Social_Queue::get_jobs_for_post( $post_id ), [ __CLASS__, 'is_short_job' ] ) );
        $pending = array_values( array_filter( $all, fn( $j ) => 'pending' === ( $j['status'] ?? '' ) && ! self::is_ready( $j ) ) );
        if ( empty( $pending ) ) {
            return;
        }

        // The plan covers as many stories as jobs were queued, whatever the live setting says now.
        $count = 0;
        foreach ( $all as $j ) {
            $meta  = self::decode_metadata( $j['metadata'] ?? null )[ self::JOB_META_KEY ];
            $count = max( $count, (int) ( $meta['count'] ?? 0 ), (int) $meta['index'] + 1 );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- background cron; TTS is paced by the shared rate limiter.
        }

        $plan = self::get_plan( $post_id, $count );
        if ( is_wp_error( $plan ) ) {
            self::handle_failures( $post_id, array_map( fn( $j ) => [ $j, $plan ], $pending ) );
            return;
        }

        $date = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        if ( '' === $date ) {
            $date = function_exists( 'get_the_date' ) ? (string) get_the_date( 'Y-m-d', $post_id ) : '';
        }
        if ( '' === $date ) {
            $date = gmdate( 'Y-m-d' );
        }

        $audio_by_index  = [];
        $images_by_index = [];
        foreach ( $all as $existing ) {
            $em   = self::decode_metadata( $existing['metadata'] ?? null )[ self::JOB_META_KEY ] ?? [];
            $eidx = isset( $em['index'] ) ? (int) $em['index'] : -1;
            if ( $eidx < 0 ) {
                continue;
            }
            if ( ! empty( $em['audio']['audio_url'] ) && ! empty( $em['audio']['segments'] ) && ! isset( $audio_by_index[ $eidx ] ) ) {
                $audio_by_index[ $eidx ] = $em['audio'];
            }
            if ( is_array( $em['images'] ?? null ) && ! empty( $em['images_hash'] ) && ! isset( $images_by_index[ $eidx ] ) ) {
                $images_by_index[ $eidx ] = [ 'hash' => (string) $em['images_hash'], 'images' => $em['images'] ];
            }
        }

        $failures = [];
        foreach ( $pending as $job ) {
            $job_id   = (int) $job['id'];
            $metadata = self::decode_metadata( $job['metadata'] ?? null );
            $index    = (int) $metadata[ self::JOB_META_KEY ]['index'];
            $short    = $plan['shorts'][ $index ] ?? null;

            if ( ! is_array( $short ) ) {
                PressHub_Social_Queue::cancel_job( $job_id );
                PressHub_Social_Logger::warning( sprintf( 'TikTok short job #%d cancelled: the plan for post #%d has %d stories, so story %d does not exist.', $job_id, $post_id, count( $plan['shorts'] ), $index + 1 ) );
                continue;
            }

            $expected_audio_hash = self::audio_hash( $short['script'] );
            if ( isset( $audio_by_index[ $index ] ) && ( $audio_by_index[ $index ]['hash'] ?? '' ) === $expected_audio_hash ) {
                $audio = $audio_by_index[ $index ];
            } else {
                $audio = self::get_audio( $job, $short, $date );
                if ( is_wp_error( $audio ) ) {
                    $failures[] = [ $job, $audio ];
                    continue;
                }
                $audio_by_index[ $index ] = $audio;
            }

            $expected_images_hash = self::images_hash( $short );
            if ( isset( $images_by_index[ $index ] ) && $images_by_index[ $index ]['hash'] === $expected_images_hash ) {
                $images = $images_by_index[ $index ];
            } else {
                $images = self::get_images( $job, $short, $post_id );
                $images_by_index[ $index ] = $images;
            }

            $metadata[ self::JOB_META_KEY ] = array_merge( $metadata[ self::JOB_META_KEY ], [
                'total'       => count( $plan['shorts'] ),
                'topic'       => $short['topic'],
                'hook'        => $short['hook'],
                'caption'     => $short['caption'],
                'mood'        => (string) ( $short['mood'] ?? '' ),
                'audio'       => $audio,
                'images'      => $images['images'],
                'images_hash' => $images['hash'],
            ] );
            PressHub_Social_Queue::update_job( $job_id, [
                'metadata' => function_exists( 'wp_json_encode' ) ? wp_json_encode( $metadata ) : json_encode( $metadata ),
            ] );
            PressHub_Social_Logger::info( sprintf( 'TikTok short %d/%d prepared for job #%d (post #%d): "%s"', $index + 1, count( $plan['shorts'] ), $job_id, $post_id, $short['hook'] ) );
        }

        self::handle_failures( $post_id, $failures );
    }

    /**
     * Retry a failed preparation without touching the jobs' posting times.
     *
     * Preparation runs ahead of each short's slot, so a failure only records the error on
     * the job and reschedules the preparation with the queue's backoff
     * (presshub_social_retry_base_delay x 2^(n-1)). After presshub_social_max_attempts
     * failed runs the jobs still unprepared fail permanently (with the usual alert).
     *
     * @param array<int, array{0: array, 1: WP_Error}> $failures Job and its error.
     */
    private static function handle_failures( int $post_id, array $failures ): void {
        if ( empty( $failures ) ) {
            delete_post_meta( $post_id, self::PREP_ATTEMPTS_META );
            return;
        }

        $attempts     = (int) get_post_meta( $post_id, self::PREP_ATTEMPTS_META, true ) + 1;
        $max_attempts = PressHub_Social_Settings_Storage::get_max_attempts();

        if ( $attempts >= $max_attempts ) {
            delete_post_meta( $post_id, self::PREP_ATTEMPTS_META );
            foreach ( $failures as [ $job, $error ] ) {
                $job_id = (int) ( $job['id'] ?? 0 );
                // An article is still posted, the classic way, when its short cannot be made.
                // YouTube has no classic post: only videos go there, so its short fails.
                if ( self::is_article_job( $job ) && ! self::is_test_job( $job ) && 'youtube' !== ( $job['platform'] ?? '' ) && self::fallback_to_classic( $job, $error, $attempts ) ) {
                    continue;
                }
                $err    = self::fail( $job_id, $error );
                if ( $job_id > 0 && PressHub_Social_Queue::claim_for_processing( $job_id ) ) {
                    PressHub_Social_Queue::update_job( $job_id, [ 'attempts' => max( $max_attempts, (int) ( $job['max_attempts'] ?? $max_attempts ) ) ] );
                    PressHub_Social_Queue::mark_failed( $job_id, sprintf( '%s (preparation failed %d times)', $err->get_error_message(), $attempts ), [ 'error_code' => $error->get_error_code() ] );
                }
            }
            return;
        }

        update_post_meta( $post_id, self::PREP_ATTEMPTS_META, $attempts );
        $delay = PressHub_Social_Settings_Storage::get_retry_base_delay() * ( 2 ** ( $attempts - 1 ) );
        foreach ( $failures as [ $job, $error ] ) {
            $job_id = (int) ( $job['id'] ?? 0 );
            $err    = self::fail( $job_id, $error );
            if ( $job_id > 0 ) {
                PressHub_Social_Queue::update_job( $job_id, [ 'last_error' => $err->get_error_message() ] );
            }
        }
        PressHub_Social_Logger::warning( sprintf( 'TikTok shorts preparation for post #%d failed (run %d/%d); retrying in %ds. Posting times are unchanged.', $post_id, $attempts, $max_attempts, $delay ) );
        self::schedule_prepare( $post_id, $delay );
    }

    /**
     * Turn an article short whose preparation failed into the classic article post: the
     * short's metadata is removed (the failure is kept under `tiktok_short_fallback`), the
     * placeholder copy becomes the platform's usual AI caption, and the job stays pending,
     * so the next claim posts it with the narrated slideshow (TikTok) or the image post
     * (Instagram), with AI post images when those are on.
     */
    public static function fallback_to_classic( array $job, WP_Error $error, int $attempts = 0 ): bool {
        $job_id  = (int) ( $job['id'] ?? 0 );
        $post_id = (int) ( $job['post_id'] ?? 0 );
        if ( $job_id <= 0 ) {
            return false;
        }
        $platform = (string) ( $job['platform'] ?? 'tiktok' );
        $metadata = self::decode_metadata( $job['metadata'] ?? null );
        unset( $metadata[ self::JOB_META_KEY ] );
        $metadata['tiktok_short_fallback'] = [
            'error'      => $error->get_error_message(),
            'error_code' => $error->get_error_code(),
            'attempts'   => $attempts,
            'at'         => gmdate( 'c' ),
        ];

        $with_images = class_exists( 'PressHub_Social_Post_Images' ) && PressHub_Social_Post_Images::applies( $post_id, $platform );
        if ( $with_images ) {
            $metadata = array_merge( $metadata, PressHub_Social_Post_Images::job_metadata( $platform ) );
        }

        $copy = (string) ( $job['post_copy'] ?? '' );
        if ( self::is_placeholder_copy( $copy ) ) {
            $generated = class_exists( 'PressHub_Social_AI_Copywriter' ) ? PressHub_Social_AI_Copywriter::generate_for_post( $post_id, $platform ) : new WP_Error( 'missing_copywriter', 'PressHub_Social_AI_Copywriter is not loaded.' );
            // A failed caption gets the usual placeholder, which the claim regenerates.
            $copy = is_wp_error( $generated ) ? sprintf( '[AI Copy Generation Failed: %s]', $generated->get_error_message() ) : (string) $generated;
        }

        $updated = PressHub_Social_Queue::update_job( $job_id, [
            'metadata'   => function_exists( 'wp_json_encode' ) ? wp_json_encode( $metadata ) : json_encode( $metadata ),
            'post_copy'  => $copy,
            'last_error' => sprintf( 'Article short could not be made (%s); posted the classic way.', $error->get_error_message() ),
        ] );
        if ( ! $updated ) {
            return false;
        }
        if ( $with_images ) {
            PressHub_Social_Post_Images::schedule_prepare( $post_id );
        }
        PressHub_Social_Logger::warning( sprintf(
            '%s article short job #%d (post #%d) could not be made after %d attempts (%s); it falls back to the classic article post.',
            self::platform_label( $platform ),
            $job_id,
            $post_id,
            $attempts,
            $error->get_error_message()
        ), [ 'job_id' => $job_id, 'error_code' => $error->get_error_code() ] );
        return true;
    }

    /**
     * The worker could not render an article short (it reports `short_render_failed`).
     * Like a failed preparation, the job retries with the queue's backoff; once its last
     * attempt fails it falls back to the classic article post instead of failing for good.
     * Test renders and YouTube (videos only) never fall back. Returns whether it fell back,
     * in which case the job is pending again with fresh attempts for the classic post.
     */
    public static function fallback_after_render_failure( array $job, string $error ): bool {
        if ( ! self::is_article_job( $job ) || self::is_test_job( $job ) || 'youtube' === ( $job['platform'] ?? '' ) ) {
            return false;
        }
        $attempts     = (int) ( $job['attempts'] ?? 0 );
        $max_attempts = (int) ( $job['max_attempts'] ?? 3 );
        if ( $attempts < $max_attempts ) {
            return false;
        }
        $wp_error = new WP_Error( 'tiktok_short_render_failed', '' !== $error ? $error : 'the worker could not render the short' );
        if ( ! self::fallback_to_classic( $job, $wp_error, $attempts ) ) {
            return false;
        }
        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
        return PressHub_Social_Queue::update_job( (int) $job['id'], [
            'status'       => 'pending',
            'attempts'     => 0,
            'scheduled_at' => $now,
        ] );
    }

    /**
     * Get the plan of shorts for a podcast post, generating it when missing or stale.
     *
     * Keyed by a hash of the podcast script, the prompt, the requested count, the word
     * cap and the host count, so a regenerated podcast or an edited prompt makes a new plan.
     *
     * @return array{hash:string,shorts:array}|WP_Error
     */
    public static function get_plan( int $post_id, int $count ) {
        $source = self::source_type( $post_id );
        if ( '' === $source ) {
            return new WP_Error( 'tiktok_short_no_script', sprintf( 'post #%d is neither a podcast post with a script nor an article with text.', $post_id ) );
        }
        $article = self::SOURCE_ARTICLE === $source;

        $prompt = $article ? PressHub_Social_Settings_Storage::get_tiktok_article_short_prompt() : PressHub_Social_Settings_Storage::get_tiktok_shorts_prompt();
        if ( '' === $prompt ) {
            $option = $article ? 'presshub_social_prompt_tiktok_article_short' : 'presshub_social_prompt_tiktok_shorts';
            PressHub_Social_Logger::error( sprintf( 'TikTok shorts prompt is empty (%s).', $option ) );
            return new WP_Error( 'missing_tiktok_shorts_prompt', sprintf( 'the TikTok shorts prompt (%s) is empty. Restore it in PressHub → Social settings → Prompts.', $option ) );
        }

        $count     = $article ? 1 : max( 1, $count );
        $script    = $article ? self::article_text( $post_id ) : (string) get_post_meta( $post_id, '_presshub_podcast_script', true );
        $max_words = PressHub_Social_Settings_Storage::get_tiktok_shorts_max_words();
        $images    = PressHub_Social_Settings_Storage::get_tiktok_shorts_images();
        // An article's prompt also gets its title, category and excerpt: editing those
        // without touching the text makes a new plan too.
        $article_key = '';
        if ( $article ) {
            $post        = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
            $article_key = '|' . ( $post ? (string) ( is_object( $post ) ? ( $post->post_title ?? '' ) : ( $post['post_title'] ?? '' ) ) : '' )
                . '|' . self::primary_category( $post_id )
                . '|' . ( $post ? (string) ( is_object( $post ) ? ( $post->post_excerpt ?? '' ) : ( $post['post_excerpt'] ?? '' ) ) : '' );
        }
        $hash      = hash( 'sha256', $script . $article_key . '|' . $prompt . '|' . $count . '|' . $max_words . '|' . self::narrators() . '|' . $images . '|' . implode( ',', PressHub_Social_TikTok_Music::moods() ) . '|' . PressHub_Social_Settings_Storage::get_tiktok_shorts_caption_words() . '|yt' . (int) PressHub_Social_Settings_Storage::get_youtube_shorts_enabled() );

        $stored = get_post_meta( $post_id, self::PLAN_META, true );
        if ( is_array( $stored ) && ( $stored['hash'] ?? '' ) === $hash && ! empty( $stored['shorts'] ) ) {
            return $stored;
        }

        $user_prompt = $article
            ? self::build_prompt( $post_id, $prompt, '', $count, $max_words, $images, $script )
            : self::build_prompt( $post_id, $prompt, $script, $count, $max_words, $images );
        $sys_prompt  = PressHub_Social_Settings_Storage::get_system_prompt();

        PressHub_Social_Logger::info( $article
            ? sprintf( 'Generating the short of article post #%d.', $post_id )
            : sprintf( 'Generating %d TikTok shorts for podcast post #%d.', $count, $post_id ) );
        $raw = self::call_ai( $post_id, $sys_prompt, $user_prompt );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $shorts = self::parse_plan( (string) $raw, $count, $max_words, $images );
        if ( is_wp_error( $shorts ) ) {
            PressHub_Social_Logger::error( sprintf( 'TikTok shorts plan for post #%d is invalid: %s', $post_id, $shorts->get_error_message() ), [ 'response' => mb_substr( (string) $raw, 0, 500 ) ] );
            return $shorts;
        }

        $plan = [
            'hash'         => $hash,
            'generated_at' => gmdate( 'c' ),
            'shorts'       => $shorts,
        ];
        update_post_meta( $post_id, self::PLAN_META, $plan );
        return $plan;
    }

    /**
     * Fill the shorts prompt placeholders in one pass (a value is never re-expanded).
     */
    public static function build_prompt( int $post_id, string $template, string $script, int $count, int $max_words, int $image_count = 3, string $content = '' ): string {
        $post      = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        $title     = $post ? (string) ( is_object( $post ) ? ( $post->post_title ?? '' ) : ( $post['post_title'] ?? '' ) ) : '';
        $excerpt   = $post ? (string) ( is_object( $post ) ? ( $post->post_excerpt ?? '' ) : ( $post['post_excerpt'] ?? '' ) ) : '';
        $excerpt   = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $excerpt ) : strip_tags( $excerpt ) );
        $category  = self::primary_category( $post_id );
        $date      = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        if ( '' === $date && function_exists( 'get_the_date' ) ) {
            $date = (string) get_the_date( 'Y-m-d', $post_id );
        }
        $site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
        $speakers  = 1 === self::narrators()
            ? '[SPEAKER_1] μόνο: ένας αφηγητής που μιλά απευθείας στον θεατή, σε β\' ενικό, με ρυθμό και ζωντάνια'
            : '[SPEAKER_1] και [SPEAKER_2], εναλλάξ';

        return strtr( $template, [
            '{count}'     => (string) $count,
            '{max_words}' => (string) $max_words,
            '{image_count}' => (string) $image_count,
            '{caption_words}' => (string) PressHub_Social_Settings_Storage::get_tiktok_shorts_caption_words(),
            '{moods}'     => implode( ', ', PressHub_Social_TikTok_Music::moods() ),
            '{speakers}'  => $speakers,
            '{title}'     => $title,
            '{site_name}' => '' !== $site_name ? $site_name : 'PressHub',
            '{date}'      => $date,
            '{script}'    => $script,
            '{content}'   => $content,
            '{excerpt}'   => $excerpt,
            '{category}'  => $category,
        ] );
    }

    /**
     * Parse and validate the LLM's JSON plan.
     *
     * @param int $count       Stories requested; extra items are dropped, fewer are allowed.
     * @param int $max_words   Word cap from the prompt; a script over 1.5x the cap is rejected.
     * @param int $image_count Image descriptions kept per short (extra ones are dropped;
     *                         missing ones are allowed and the short uses fewer images).
     * @return array|WP_Error List of shorts {topic, hook, script, caption, visuals, mood}.
     *                        A missing or unknown mood becomes the default mood.
     */
    public static function parse_plan( string $raw, int $count, int $max_words = 0, int $image_count = 3 ) {
        $text  = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/iu', '', trim( $raw ) );
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( false === $start || false === $end || $end <= $start ) {
            return new WP_Error( 'tiktok_short_invalid_json', 'the AI response is not JSON.' );
        }
        $decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
        if ( ! is_array( $decoded ) || ! is_array( $decoded['shorts'] ?? null ) || empty( $decoded['shorts'] ) ) {
            return new WP_Error( 'tiktok_short_invalid_json', 'the AI response has no "shorts" list.' );
        }

        $shorts = [];
        foreach ( array_slice( array_values( $decoded['shorts'] ), 0, max( 1, $count ) ) as $i => $item ) {
            if ( ! is_array( $item ) ) {
                return new WP_Error( 'tiktok_short_invalid_json', sprintf( 'short %d is not an object.', $i + 1 ) );
            }
            $topic   = trim( (string) ( $item['topic'] ?? '' ) );
            $hook    = trim( (string) ( $item['hook'] ?? '' ) );
            $script  = trim( (string) ( $item['script'] ?? '' ) );
            // The audio is shared with YouTube Shorts, where "link in bio" means nothing:
            // the closing line then names the site instead (also fine on TikTok).
            if ( class_exists( 'PressHub_Social_YouTube' ) && PressHub_Social_Settings_Storage::get_youtube_shorts_enabled() ) {
                $script = PressHub_Social_YouTube::youtube_cta( $script );
            }
            $caption = trim( (string) preg_replace( '/[ \t]*\b(?:https?:\/\/|www\.)\S+/iu', '', (string) ( $item['caption'] ?? '' ) ) );
            if ( '' === $hook || '' === $script || '' === $caption ) {
                return new WP_Error( 'tiktok_short_invalid_json', sprintf( 'short %d is missing its hook, script or caption.', $i + 1 ) );
            }
            // The prompt asks for hashtags; when the model left them out, add the standard ones.
            $caption = PressHub_Social_AI_Copywriter::ensure_hashtags( $caption, self::DEFAULT_HASHTAGS );
            if ( ! preg_match( '/\[\s*SPEAKER_[12]\s*\]\s*:/iu', $script ) || preg_match( '/\[\s*SPEAKER_(?![12]\s*\])[^\]]*\]/iu', $script ) ) {
                return new WP_Error( 'tiktok_short_invalid_json', sprintf( 'short %d script must use only [SPEAKER_1]: and [SPEAKER_2]: lines.', $i + 1 ) );
            }
            if ( $max_words > 0 ) {
                $spoken = (string) preg_replace( '/\[\s*SPEAKER_[12]\s*\]\s*:/iu', ' ', $script );
                $words  = count( preg_split( '/\s+/u', trim( $spoken ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] );
                if ( $words > (int) ceil( $max_words * 1.5 ) ) {
                    return new WP_Error( 'tiktok_short_invalid_json', sprintf( 'short %d script has %d words, over the %d-word cap.', $i + 1, $words, $max_words ) );
                }
            }
            $visuals = [];
            foreach ( is_array( $item['visuals'] ?? null ) ? $item['visuals'] : [] as $visual ) {
                $visual = trim( preg_replace( '/\s+/u', ' ', is_string( $visual ) ? $visual : '' ) );
                if ( '' !== $visual ) {
                    $visuals[] = mb_substr( $visual, 0, 600 );
                }
            }
            $shorts[] = [
                'topic'   => '' !== $topic ? $topic : $hook,
                'hook'    => $hook,
                'script'  => $script,
                'caption' => $caption,
                'visuals' => array_slice( $visuals, 0, max( 0, $image_count ) ),
                'mood'    => PressHub_Social_TikTok_Music::normalize_mood( $item['mood'] ?? '' ),
            ];
        }
        return $shorts;
    }

    /**
     * Get the short's audio from the job's cache or synthesize it.
     *
     * @return array{hash:string,audio_url:string,attachment_id:int}|WP_Error
     */
    private static function get_audio( array $job, array $short, string $date ) {
        $metadata = self::decode_metadata( $job['metadata'] ?? null );
        $hash     = self::audio_hash( $short['script'] );
        $cached   = $metadata[ self::JOB_META_KEY ]['audio'] ?? null;
        if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash && ! empty( $cached['audio_url'] ) && ! empty( $cached['segments'] ) ) {
            return $cached;
        }

        $record_id    = self::begin_record( $job, $short, $hash );
        $owns_session = false;
        if ( $record_id > 0 && class_exists( 'PressHub_AI_TTS_Call_Recorder' ) && ! PressHub_AI_TTS_Call_Recorder::is_active() ) {
            PressHub_AI_TTS_Call_Recorder::begin_session( $record_id );
            $owns_session = true;
        }
        try {
            $result = null !== self::$synthesizer
                ? call_user_func( self::$synthesizer, $short, $job )
                : self::synthesize( $short, $job, $hash, $date );
        } finally {
            if ( $owns_session ) {
                PressHub_AI_TTS_Call_Recorder::end_session();
            }
        }
        self::finish_record( $record_id, $result );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result['url'] ) ) {
            return new WP_Error( 'tiktok_short_no_audio', 'synthesized audio has no URL.' );
        }
        if ( empty( $result['segments'] ) || ! is_array( $result['segments'] ) ) {
            return new WP_Error( 'tiktok_short_no_timings', 'synthesized audio has no turn timings for the captions.' );
        }

        return [
            'hash'                 => $hash,
            'audio_url'            => (string) $result['url'],
            'attachment_id'        => (int) ( $result['id'] ?? 0 ),
            'segments'             => array_values( $result['segments'] ),
            'generated_at'         => gmdate( 'c' ),
            'generation_record_id' => $record_id,
        ];
    }

    /**
     * Synthesize a short turn by turn with the Daily Briefing voices and sideload it.
     * Turns are cached on disk under the briefing date, so a retry after a failed turn
     * only re-synthesizes the turns that did not finish.
     *
     * @return array|WP_Error
     */
    private static function synthesize( array $short, array $job, string $hash, string $date ) {
        if ( ! class_exists( 'PressHub_AI_Audio_Synthesizer' ) || ! class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            return new WP_Error( 'tts_unavailable', 'PressHub_AI_Audio_Synthesizer is not loaded.' );
        }

        $synth    = new PressHub_AI_Audio_Synthesizer();
        $producer = new PressHub_AI_Podcast_Producer();
        $turns    = self::merge_turns( $producer->parse_script_turns( $short['script'] ), self::narrators() );
        if ( empty( $turns ) ) {
            return new WP_Error( 'tiktok_short_no_turns', 'the short script has no speaker turns.' );
        }

        $voices = self::voices( $synth );
        $speed  = self::speed();
        $pitch  = PressHub_AI_Settings_Storage::get_voice_pitch();
        $style  = self::style();
        $engine = PressHub_AI_Settings_Storage::get_briefing_tts_engine();

        $chunks = [];
        foreach ( $turns as $turn ) {
            $voice = 2 === self::speaker_number( $turn, self::narrators() ) ? $voices['speaker_2'] : $voices['speaker_1'];
            $audio = $synth->synthesize_turn( (string) $turn['text'], $voice, $speed, $pitch, null, $style, $date );
            if ( is_wp_error( $audio ) ) {
                return $audio;
            }
            $chunks[] = $audio;
        }

        $pause    = PressHub_AI_Settings_Storage::get_briefing_tts_turn_pause_ms();
        $is_mp3   = 'google_cloud' === $engine;
        $stitched = $is_mp3 ? $synth->stitch_audio_chunks( $chunks, $pause ) : $synth->stitch_wav_chunks( $chunks, $pause, 24000 );
        if ( is_wp_error( $stitched ) ) {
            return $stitched;
        }
        if ( ! is_string( $stitched ) || '' === $stitched ) {
            return new WP_Error( 'tts_empty_audio', 'TTS returned empty audio for the short.' );
        }

        $meta     = self::decode_metadata( $job['metadata'] ?? null );
        $index    = (int) ( $meta[ self::JOB_META_KEY ]['index'] ?? 0 );
        $post_id  = (int) ( $job['post_id'] ?? 0 );
        $filename = sprintf( 'tiktok-short-post-%d-%d-%s.%s', $post_id, $index + 1, substr( $hash, 0, 8 ), $is_mp3 ? 'mp3' : 'wav' );
        $upload   = $synth->sideload_audio_file( $filename, $stitched, $post_id, sprintf( 'TikTok short %d (post #%d)', $index + 1, $post_id ) );
        if ( is_wp_error( $upload ) ) {
            return $upload;
        }
        $upload['voices']   = $voices;
        $upload['segments'] = self::turn_timings( $turns, $chunks, $pause, $is_mp3 );
        return $upload;
    }

    /**
     * Start and end of each spoken turn in the stitched audio, in seconds, for the
     * worker's synced captions. WAV turns are measured exactly from their PCM length;
     * MP3 turns are estimated from the text length (marked `estimated`), and the worker
     * scales them to the real audio length.
     *
     * @param array<int, array{speaker?:string,text?:string}> $turns  Parsed turns, in order.
     * @param array<int, string>                              $chunks Audio of each turn.
     * @return array<int, array{speaker:int,text:string,start:float,end:float}>
     */
    public static function turn_timings( array $turns, array $chunks, int $pause_ms, bool $is_mp3 ): array {
        $segments = [];
        $t        = 0.0;
        $pause    = max( 0, $pause_ms ) / 1000;
        foreach ( array_values( $turns ) as $i => $turn ) {
            $text = trim( (string) ( $turn['text'] ?? '' ) );
            if ( $is_mp3 ) {
                $seconds = max( 0.5, mb_strlen( $text ) / 14 );
            } else {
                $seconds = self::pcm_seconds( (string) ( $chunks[ $i ] ?? '' ) );
            }
            if ( $i > 0 ) {
                $t += $pause;
            }
            $segment = [
                'speaker' => self::speaker_number( $turn, self::narrators() ),
                'text'    => $text,
                'start'   => round( $t, 3 ),
                'end'     => round( $t + $seconds, 3 ),
            ];
            if ( $is_mp3 ) {
                $segment['estimated'] = true;
            }
            $segments[] = $segment;
            $t         += $seconds;
        }
        return $segments;
    }

    /**
     * Length in seconds of a WAV buffer, or of raw 24 kHz 16-bit mono PCM.
     */
    public static function pcm_seconds( string $buffer ): float {
        $rate     = 24000;
        $channels = 1;
        $bits     = 16;
        $bytes    = strlen( $buffer );
        if ( $bytes >= 44 && 'RIFF' === substr( $buffer, 0, 4 ) && 'WAVE' === substr( $buffer, 8, 4 ) ) {
            $fmt = strpos( $buffer, 'fmt ' );
            if ( false !== $fmt && strlen( $buffer ) >= $fmt + 24 ) {
                $f        = unpack( 'vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr( $buffer, $fmt + 8, 16 ) );
                $channels = max( 1, (int) ( $f['channels'] ?? 1 ) );
                $rate     = max( 1, (int) ( $f['rate'] ?? 24000 ) );
                $bits     = max( 8, (int) ( $f['bits'] ?? 16 ) );
            }
            $data = strpos( $buffer, 'data' );
            if ( false !== $data && strlen( $buffer ) >= $data + 8 ) {
                $size  = (int) ( unpack( 'V', substr( $buffer, $data + 4, 4 ) )[1] ?? 0 );
                $avail = strlen( $buffer ) - $data - 8;
                $bytes = ( $size > 0 && $size <= $avail ) ? $size : $avail;
            } else {
                $bytes -= 44;
            }
        }
        return max( 0, $bytes ) / ( $rate * $channels * ( $bits / 8 ) );
    }

    /**
     * Fill the image style prompt with one image description.
     */
    public static function image_prompt( string $style, string $description ): string {
        if ( '' === trim( $style ) ) {
            return $description;
        }
        if ( str_contains( $style, '{description}' ) ) {
            return trim( strtr( $style, [ '{description}' => $description ] ) );
        }
        return trim( $description . ' ' . $style );
    }

    /**
     * The image style for a story's mood: its line in TikTok Shorts: Image Styles per Mood,
     * or the Image Prompt when the mood has none.
     */
    public static function image_style_for( string $mood ): string {
        $mood   = PressHub_Social_TikTok_Music::normalize_mood( $mood );
        $styles = PressHub_Social_TikTok_Music::parse_prompts( PressHub_Social_Settings_Storage::get_tiktok_shorts_image_moods(), true );
        return $styles[ $mood ] ?? PressHub_Social_Settings_Storage::get_tiktok_shorts_image_style();
    }

    /**
     * Generate the short's story images (9:16) from the plan's descriptions, or reuse the
     * job's earlier ones when the descriptions and the setting are unchanged.
     *
     * Never fails the short: a failed or missing image is a WARNING and the worker shows
     * the images it has, or a branded background when there are none.
     *
     * @return array{hash:string,images:array<int,array{url:string,id:int,prompt:string}>}
     */
    private static function images_hash( array $short ): string {
        $count   = PressHub_Social_Settings_Storage::get_tiktok_shorts_images();
        $visuals = array_slice( (array) ( $short['visuals'] ?? [] ), 0, $count );
        $style   = self::image_style_for( (string) ( $short['mood'] ?? '' ) );
        return hash( 'sha256', $count . '|' . $style . '|' . implode( "\n", $visuals ) );
    }

    private static function get_images( array $job, array $short, int $post_id ): array {
        $count   = PressHub_Social_Settings_Storage::get_tiktok_shorts_images();
        $visuals = array_slice( (array) ( $short['visuals'] ?? [] ), 0, $count );
        $style   = self::image_style_for( (string) ( $short['mood'] ?? '' ) );
        $hash    = self::images_hash( $short );
        $job_id  = (int) ( $job['id'] ?? 0 );

        $meta = self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ] ?? [];
        if ( is_array( $meta['images'] ?? null ) && ( $meta['images_hash'] ?? '' ) === $hash ) {
            return [ 'hash' => $hash, 'images' => $meta['images'] ];
        }
        if ( 0 === $count ) {
            return [ 'hash' => $hash, 'images' => [] ];
        }
        if ( empty( $visuals ) ) {
            PressHub_Social_Logger::warning( sprintf( 'TikTok short job #%d has no image descriptions in its plan (does the shorts prompt ask for "visuals"?); it will use the branded background.', $job_id ) );
            return [ 'hash' => $hash, 'images' => [] ];
        }

        $images = [];
        foreach ( $visuals as $i => $visual ) {
            $prompt = self::image_prompt( $style, $visual );
            try {
                if ( null !== self::$image_generator ) {
                    $result = call_user_func( self::$image_generator, $prompt, $post_id, $short );
                } elseif ( class_exists( 'PressHub_Social_Image_Library' ) ) {
                    // The shared library reuses an image already made for this post with the
                    // same description and style, and keeps it for later features.
                    $result = PressHub_Social_Image_Library::get_or_create( $post_id, $visual, $style, '9:16', [
                        'title'   => (string) $short['topic'],
                        'content' => (string) preg_replace( '/\[\s*SPEAKER_[12]\s*\]\s*:/iu', '', (string) $short['script'] ),
                    ], 'tiktok_short' );
                } else {
                    $result = new WP_Error( 'missing_image_library', 'PressHub_Social_Image_Library is not loaded.' );
                }
            } catch ( Throwable $e ) {
                $result = new WP_Error( 'image_generation_exception', $e->getMessage() );
            }

            if ( is_wp_error( $result ) || empty( $result['url'] ) ) {
                PressHub_Social_Logger::warning( sprintf(
                    'TikTok short job #%d: story image %d/%d failed: %s',
                    $job_id,
                    $i + 1,
                    count( $visuals ),
                    is_wp_error( $result ) ? $result->get_error_message() : 'no image URL returned'
                ) );
                continue;
            }
            $images[] = [
                'url'    => (string) $result['url'],
                'id'     => (int) ( $result['id'] ?? 0 ),
                'prompt' => $visual,
            ];
        }

        if ( empty( $images ) ) {
            PressHub_Social_Logger::warning( sprintf( 'TikTok short job #%d has no story image (all %d failed); it will use the branded background.', $job_id, count( $visuals ) ) );
        }
        return [ 'hash' => $hash, 'images' => $images ];
    }

    /**
     * Cache key of a short's audio: every setting that changes how it sounds.
     */
    private static function audio_hash( string $script ): string {
        $parts = [ $script, self::style(), (string) self::narrators(), (string) self::speed() ];
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            $parts[] = PressHub_AI_Settings_Storage::get_briefing_tts_engine();
            $parts[] = PressHub_AI_Settings_Storage::get_briefing_tts_model();
            $parts[] = (string) PressHub_AI_Settings_Storage::get_voice_pitch();
            $parts[] = (string) PressHub_AI_Settings_Storage::get_briefing_tts_turn_pause_ms();
        }
        if ( class_exists( 'PressHub_AI_Audio_Synthesizer' ) ) {
            $parts[] = implode( ',', self::voices( new PressHub_AI_Audio_Synthesizer() ) );
        }
        return hash( 'sha256', implode( '|', $parts ) );
    }

    /**
     * Voices of the shorts' narrators: the TikTok Shorts voice settings, or the Daily
     * Briefing presenter voices where those are empty. A voice made for the other TTS
     * engine (a Gemini name under Google Cloud, or the reverse) cannot be spoken, so the
     * presenter voice is used and a warning is logged.
     *
     * @return array{speaker_1:string,speaker_2:string}
     */
    private static function voices( PressHub_AI_Audio_Synthesizer $synth ): array {
        $engine = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_engine() : 'gemini';
        $voices = [];
        foreach ( [ 1, 2 ] as $n ) {
            $voice = PressHub_Social_Settings_Storage::get_tiktok_shorts_voice( $n );
            if ( '' !== $voice && ! self::voice_fits_engine( $voice, $engine ) ) {
                PressHub_Social_Logger::warning( sprintf( 'TikTok Shorts voice %d "%s" is not a %s voice; the Daily Briefing presenter %d voice is used instead.', $n, $voice, 'google_cloud' === $engine ? 'Google Cloud' : 'Gemini', $n ) );
                $voice = '';
            }
            $voices[ 'speaker_' . $n ] = '' !== $voice ? $voice : $synth->get_voice_for_speaker( 'presenter_' . $n );
        }
        return $voices;
    }

    /** Google Cloud voices are named like el-GR-Wavenet-A; Gemini voices are one word. */
    public static function voice_fits_engine( string $voice, string $engine ): bool {
        $is_cloud = false !== strpos( $voice, '-' );
        return 'google_cloud' === $engine ? $is_cloud : ! $is_cloud;
    }

    /** Delivery instruction: the TikTok Shorts narration style, else the Daily Briefing style. */
    private static function style(): string {
        $shorts = PressHub_Social_Settings_Storage::get_tiktok_shorts_tts_style();
        if ( '' !== $shorts ) {
            return $shorts;
        }
        if ( ! class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            return '';
        }
        $key    = PressHub_AI_Settings_Storage::get_briefing_tts_style();
        $custom = PressHub_AI_Settings_Storage::get_briefing_tts_custom_style();
        return ( 'custom' === $key && '' !== trim( $custom ) ) ? $custom : $key;
    }

    private static function narrators(): int {
        return PressHub_Social_Settings_Storage::get_tiktok_shorts_narrators();
    }

    /** Speaking rate multiplier of the shorts (TikTok Shorts: Voice Speed). */
    private static function speed(): float {
        return PressHub_Social_Settings_Storage::get_tiktok_shorts_voice_speed() / 100;
    }

    /** Narrator (1 or 2) of a parsed turn; always 1 with a single narrator. */
    public static function speaker_number( array $turn, int $narrators ): int {
        return ( 2 === $narrators && 'male' === ( $turn['speaker'] ?? '' ) ) ? 2 : 1;
    }

    /**
     * Join consecutive turns of the same narrator, so a single narrator is read in one
     * TTS call and keeps a natural flow instead of restarting at every line.
     *
     * @param array<int, array{speaker?:string,text?:string}> $turns
     * @return array<int, array{speaker?:string,text?:string}>
     */
    public static function merge_turns( array $turns, int $narrators ): array {
        $merged = [];
        $last   = 0;
        foreach ( $turns as $turn ) {
            $text = trim( (string) ( $turn['text'] ?? '' ) );
            if ( '' === $text ) {
                continue;
            }
            $speaker = self::speaker_number( $turn, $narrators );
            if ( ! empty( $merged ) && $speaker === $last ) {
                $merged[ count( $merged ) - 1 ]['text'] .= ' ' . $text;
                continue;
            }
            $turn['text'] = $text;
            $merged[]     = $turn;
            $last         = $speaker;
        }
        return $merged;
    }

    /**
     * Call the social AI provider (the copywriter's provider and model) in JSON mode.
     * The call is recorded as a generation record linked to the podcast post.
     *
     * @return string|WP_Error
     */
    private static function call_ai( int $post_id, string $sys_prompt, string $user_prompt ) {
        if ( null !== self::$ai_caller ) {
            $result = call_user_func( self::$ai_caller, $sys_prompt, $user_prompt );
        } else {
            $config = PressHub_Social_AI_Copywriter::resolve_ai_provider_config();
            if ( is_wp_error( $config ) ) {
                return $config;
            }
            if ( ! class_exists( 'PressHub_AI_API_Client' ) ) {
                return new WP_Error( 'missing_api_client', 'PressHub_AI_API_Client is not loaded.' );
            }
            $client = null;
            $t0     = PressHub_AI_API_Client::begin_generation_record();
            try {
                $client = new PressHub_AI_API_Client( $config );
                if ( method_exists( $client, 'set_action' ) ) {
                    $client->set_action( 'social_tiktok_shorts' );
                }
                $client->suppress_provider_prompt_log = true;
                $result = $client->call_provider( $sys_prompt, $user_prompt, true, [], $config['temperature'], [ 'platform' => 'tiktok', 'purpose' => 'tiktok_shorts' ] );
            } catch ( Exception $e ) {
                $result = new WP_Error( 'ai_dispatch_exception', $e->getMessage() );
            }
            if ( null !== $client ) {
                $client->suppress_provider_prompt_log = false;
                PressHub_AI_API_Client::finish_generation_record( $t0, 'generic', $client, $sys_prompt, $user_prompt, $result, [
                    'post_id' => $post_id,
                    'config'  => [ 'action' => 'social_tiktok_shorts', 'platform' => 'tiktok' ],
                ] );
            }
        }

        if ( is_wp_error( $result ) ) {
            PressHub_Social_Logger::error( 'TikTok shorts generation failed: ' . $result->get_error_message(), [ 'error_code' => $result->get_error_code() ] );
            return $result;
        }
        if ( ! is_string( $result ) || '' === trim( $result ) ) {
            return new WP_Error( 'empty_ai_response', 'the AI provider returned an empty response for the TikTok shorts.' );
        }
        return $result;
    }

    private static function fail( int $job_id, WP_Error $error ): WP_Error {
        $err = new WP_Error(
            'tiktok_short_failed',
            sprintf( 'TikTok short failed for job #%d: %s', $job_id, $error->get_error_message() )
        );
        PressHub_Social_Logger::error( $err->get_error_message(), [ 'job_id' => $job_id, 'error_code' => $error->get_error_code() ] );
        return $err;
    }

    /**
     * Create the `tiktok_short` generation record for a synthesis about to run.
     */
    private static function begin_record( array $job, array $short, string $hash ): int {
        if ( ! class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            return 0;
        }
        $post_id = (int) ( $job['post_id'] ?? 0 );
        $id      = (int) PressHub_AI_Generation_Recorder::record( [
            'kind'        => 'tiktok_short',
            'status'      => 'success',
            'post_id'     => $post_id,
            'config'      => [
                'module'   => 'tts',
                'action'   => 'tiktok_short',
                'platform' => 'tiktok',
                'job_id'   => (int) ( $job['id'] ?? 0 ),
            ],
            'inputs'      => [
                'job_id'     => (int) ( $job['id'] ?? 0 ),
                'post_id'    => $post_id,
                'topic'      => $short['topic'],
                'hook'       => $short['hook'],
                'cache_hash' => $hash,
            ],
            'user_prompt' => $short['script'],
        ] );
        if ( $id > 0 && $post_id > 0 ) {
            PressHub_AI_Generation_Recorder::attach_to_post( $post_id, $id );
        }
        return $id;
    }

    /**
     * @param array|WP_Error $result
     */
    private static function finish_record( int $record_id, $result ): void {
        if ( $record_id <= 0 || ! class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            return;
        }
        if ( is_wp_error( $result ) || empty( $result['url'] ) ) {
            PressHub_AI_Generation_Recorder::update_record( $record_id, [
                'status'        => 'error',
                'error_message' => is_wp_error( $result ) ? $result->get_error_message() : 'Synthesized audio has no URL.',
            ] );
            return;
        }
        $has_settings = class_exists( 'PressHub_AI_Settings_Storage' );
        PressHub_AI_Generation_Recorder::update_record( $record_id, [
            'tts' => [
                'engine'         => $has_settings ? PressHub_AI_Settings_Storage::get_briefing_tts_engine() : '',
                'model'          => $has_settings ? PressHub_AI_Settings_Storage::get_briefing_tts_model() : '',
                'voices'         => (array) ( $result['voices'] ?? [] ),
                'speed'          => self::speed(),
                'style'          => self::style(),
                'narrators'      => self::narrators(),
                'pitch'          => $has_settings ? PressHub_AI_Settings_Storage::get_voice_pitch() : null,
                'audio_url'      => (string) $result['url'],
                'attachment_id'  => (int) ( $result['id'] ?? 0 ),
                'synthesized_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
            ],
        ] );
    }

    /**
     * @param mixed $raw
     */
    private static function decode_metadata( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return [];
    }
}
