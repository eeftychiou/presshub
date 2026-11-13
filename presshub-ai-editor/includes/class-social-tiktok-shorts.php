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
 * Zero-Silent-Fallback: an empty prompt, an invalid plan or a failed synthesis fails the
 * job; a short job is never posted as the full podcast or with its placeholder copy.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_TikTok_Shorts {

    /** Post meta holding the generated plan of shorts for a podcast post. */
    public const PLAN_META = '_presshub_tiktok_shorts';

    /** Job metadata key marking a short job and holding its prepared short. */
    public const JOB_META_KEY = 'tiktok_short';

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
     * Whether shorts can be made with this engine: they are claimed by the local worker,
     * so they need the automation engine in Pull Mode.
     */
    public static function engine_supported( string $engine ): bool {
        return 'automation' === $engine && 'pull' === PressHub_Social_Settings_Storage::get_worker_mode();
    }

    /**
     * Whether the TikTok job for this post should be split into shorts. With shorts enabled
     * but an unsupported engine, the full podcast is posted and a WARNING says why.
     */
    public static function should_split( int $post_id, string $engine ): bool {
        if ( ! PressHub_Social_Settings_Storage::get_tiktok_shorts_enabled() || ! self::is_podcast_post( $post_id ) ) {
            return false;
        }
        if ( ! self::engine_supported( $engine ) ) {
            PressHub_Social_Logger::warning( sprintf(
                'TikTok shorts are enabled but post #%d is posted as the full podcast: shorts need the automation engine in Pull Mode (engine: %s, worker mode: %s).',
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
     * Build the job rows for the shorts of a podcast post.
     *
     * @return array<int, array> Job data arrays for PressHub_Social_Queue::enqueue().
     */
    public static function build_jobs( int $post_id, string $engine, ?string $media_url, ?string $media_type, int $start_time, bool $test = false ): array {
        $count   = PressHub_Social_Settings_Storage::get_tiktok_shorts_count();
        $spacing = $test ? 0 : PressHub_Social_Settings_Storage::get_tiktok_shorts_spacing_minutes() * 60;
        $jobs    = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $jobs[] = [
                'post_id'      => $post_id,
                'platform'     => 'tiktok',
                'engine'       => $engine,
                'post_copy'    => self::placeholder_copy( $i, $count ),
                'media_url'    => $media_url,
                'media_type'   => $media_type,
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', $start_time + ( $i * $spacing ) ),
                'status'       => 'pending',
                'metadata'     => [ self::JOB_META_KEY => array_merge( [ 'index' => $i, 'count' => $count ], $test ? [ 'test' => true ] : [] ) ],
            ];
        }
        return $jobs;
    }

    /**
     * Queue a test render of a podcast post's shorts: the real prompts, voices and images,
     * rendered by the worker into its output folder and never posted.
     *
     * @return int[]|WP_Error IDs of the queued test jobs.
     */
    public static function enqueue_test_render( int $post_id ) {
        if ( ! self::is_podcast_post( $post_id ) ) {
            return new WP_Error( 'tiktok_short_no_script', sprintf( 'post #%d is not a Daily Briefing podcast post with a script.', $post_id ) );
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

    public static function is_short_job( array $job ): bool {
        if ( 'tiktok' !== ( $job['platform'] ?? '' ) ) {
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
        return $job;
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
            $date = gmdate( 'Y-m-d' );
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

            $audio = self::get_audio( $job, $short, $date );
            if ( is_wp_error( $audio ) ) {
                $failures[] = [ $job, $audio ];
                continue;
            }

            $images = self::get_images( $job, $short, $post_id );

            $metadata[ self::JOB_META_KEY ] = array_merge( $metadata[ self::JOB_META_KEY ], [
                'total'       => count( $plan['shorts'] ),
                'topic'       => $short['topic'],
                'hook'        => $short['hook'],
                'caption'     => $short['caption'],
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
     * Get the plan of shorts for a podcast post, generating it when missing or stale.
     *
     * Keyed by a hash of the podcast script, the prompt, the requested count, the word
     * cap and the host count, so a regenerated podcast or an edited prompt makes a new plan.
     *
     * @return array{hash:string,shorts:array}|WP_Error
     */
    public static function get_plan( int $post_id, int $count ) {
        if ( ! self::is_podcast_post( $post_id ) ) {
            return new WP_Error( 'tiktok_short_no_script', sprintf( 'post #%d is not a podcast post with a script.', $post_id ) );
        }

        $prompt = PressHub_Social_Settings_Storage::get_tiktok_shorts_prompt();
        if ( '' === $prompt ) {
            PressHub_Social_Logger::error( 'TikTok shorts prompt is empty (presshub_social_prompt_tiktok_shorts).' );
            return new WP_Error( 'missing_tiktok_shorts_prompt', 'the TikTok shorts prompt (presshub_social_prompt_tiktok_shorts) is empty. Restore it in PressHub Social → Settings → Prompts.' );
        }

        $count     = max( 1, $count );
        $script    = (string) get_post_meta( $post_id, '_presshub_podcast_script', true );
        $max_words = PressHub_Social_Settings_Storage::get_tiktok_shorts_max_words();
        $images    = PressHub_Social_Settings_Storage::get_tiktok_shorts_images();
        $hash      = hash( 'sha256', $script . '|' . $prompt . '|' . $count . '|' . $max_words . '|' . self::host_count() . '|' . $images );

        $stored = get_post_meta( $post_id, self::PLAN_META, true );
        if ( is_array( $stored ) && ( $stored['hash'] ?? '' ) === $hash && ! empty( $stored['shorts'] ) ) {
            return $stored;
        }

        $user_prompt = self::build_prompt( $post_id, $prompt, $script, $count, $max_words, $images );
        $sys_prompt  = PressHub_Social_Settings_Storage::get_system_prompt();

        PressHub_Social_Logger::info( sprintf( 'Generating %d TikTok shorts for podcast post #%d.', $count, $post_id ) );
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
    public static function build_prompt( int $post_id, string $template, string $script, int $count, int $max_words, int $image_count = 3 ): string {
        $post      = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        $title     = $post ? (string) ( is_object( $post ) ? ( $post->post_title ?? '' ) : ( $post['post_title'] ?? '' ) ) : '';
        $site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
        $speakers  = 1 === self::host_count() ? '[SPEAKER_1] μόνο' : '[SPEAKER_1] και [SPEAKER_2], εναλλάξ';

        return strtr( $template, [
            '{count}'     => (string) $count,
            '{max_words}' => (string) $max_words,
            '{image_count}' => (string) $image_count,
            '{speakers}'  => $speakers,
            '{title}'     => $title,
            '{site_name}' => '' !== $site_name ? $site_name : 'PressHub',
            '{date}'      => (string) get_post_meta( $post_id, '_presshub_briefing_date', true ),
            '{script}'    => $script,
        ] );
    }

    /**
     * Parse and validate the LLM's JSON plan.
     *
     * @param int $count       Stories requested; extra items are dropped, fewer are allowed.
     * @param int $max_words   Word cap from the prompt; a script over 1.5x the cap is rejected.
     * @param int $image_count Image descriptions kept per short (extra ones are dropped;
     *                         missing ones are allowed and the short uses fewer images).
     * @return array|WP_Error List of shorts {topic, hook, script, caption, visuals}.
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
            $caption = trim( (string) preg_replace( '/[ \t]*\b(?:https?:\/\/|www\.)\S+/iu', '', (string) ( $item['caption'] ?? '' ) ) );
            if ( '' === $hook || '' === $script || '' === $caption ) {
                return new WP_Error( 'tiktok_short_invalid_json', sprintf( 'short %d is missing its hook, script or caption.', $i + 1 ) );
            }
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
        $turns    = $producer->parse_script_turns( $short['script'] );
        if ( empty( $turns ) ) {
            return new WP_Error( 'tiktok_short_no_turns', 'the short script has no speaker turns.' );
        }

        $voices = self::voices( $synth );
        $speed  = PressHub_AI_Settings_Storage::get_voice_speed();
        $pitch  = PressHub_AI_Settings_Storage::get_voice_pitch();
        $style  = self::style();
        $engine = PressHub_AI_Settings_Storage::get_briefing_tts_engine();

        $chunks = [];
        foreach ( $turns as $turn ) {
            $voice = ( 'male' === ( $turn['speaker'] ?? '' ) && 1 !== self::host_count() ) ? $voices['speaker_2'] : $voices['speaker_1'];
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
                'speaker' => ( 'male' === ( $turn['speaker'] ?? '' ) && 1 !== self::host_count() ) ? 2 : 1,
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
     * Generate the short's story images (9:16) from the plan's descriptions, or reuse the
     * job's earlier ones when the descriptions and the setting are unchanged.
     *
     * Never fails the short: a failed or missing image is a WARNING and the worker shows
     * the images it has, or a branded background when there are none.
     *
     * @return array{hash:string,images:array<int,array{url:string,id:int,prompt:string}>}
     */
    private static function get_images( array $job, array $short, int $post_id ): array {
        $count   = PressHub_Social_Settings_Storage::get_tiktok_shorts_images();
        $visuals = array_slice( (array) ( $short['visuals'] ?? [] ), 0, $count );
        $style   = PressHub_Social_Settings_Storage::get_tiktok_shorts_image_style();
        $hash    = hash( 'sha256', $count . '|' . $style . '|' . implode( "\n", $visuals ) );
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
                } elseif ( class_exists( 'PressHub_AI_API_Client' ) ) {
                    $client = new PressHub_AI_API_Client();
                    $result = $client->generate_featured_image( $prompt, '9:16', $post_id, [
                        'title'   => (string) $short['topic'],
                        'content' => (string) preg_replace( '/\[\s*SPEAKER_[12]\s*\]\s*:/iu', '', (string) $short['script'] ),
                    ] );
                } else {
                    $result = new WP_Error( 'missing_api_client', 'PressHub_AI_API_Client is not loaded.' );
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
        $parts = [ $script, self::style(), (string) self::host_count() ];
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            $parts[] = PressHub_AI_Settings_Storage::get_briefing_tts_engine();
            $parts[] = PressHub_AI_Settings_Storage::get_briefing_tts_model();
            $parts[] = (string) PressHub_AI_Settings_Storage::get_voice_speed();
            $parts[] = (string) PressHub_AI_Settings_Storage::get_voice_pitch();
            $parts[] = (string) PressHub_AI_Settings_Storage::get_briefing_tts_turn_pause_ms();
        }
        if ( class_exists( 'PressHub_AI_Audio_Synthesizer' ) ) {
            $parts[] = implode( ',', self::voices( new PressHub_AI_Audio_Synthesizer() ) );
        }
        return hash( 'sha256', implode( '|', $parts ) );
    }

    /** @return array{speaker_1:string,speaker_2:string} */
    private static function voices( PressHub_AI_Audio_Synthesizer $synth ): array {
        return [
            'speaker_1' => $synth->get_voice_for_speaker( 'presenter_1' ),
            'speaker_2' => $synth->get_voice_for_speaker( 'presenter_2' ),
        ];
    }

    private static function style(): string {
        if ( ! class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            return '';
        }
        $key    = PressHub_AI_Settings_Storage::get_briefing_tts_style();
        $custom = PressHub_AI_Settings_Storage::get_briefing_tts_custom_style();
        return ( 'custom' === $key && '' !== trim( $custom ) ) ? $custom : $key;
    }

    private static function host_count(): int {
        return class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_count() : 2;
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
                'speed'          => $has_settings ? PressHub_AI_Settings_Storage::get_voice_speed() : null,
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
