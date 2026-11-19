<?php
/**
 * AI post images: generated images for the social posts of an article (X, Facebook,
 * Instagram and TikTok), in place of the article's featured image.
 *
 * Flow:
 * 1. Enqueue (PressHub_Social_Dispatcher::enqueue_for_post()): when AI post images are
 *    on and the platform's image count is above 0, the job is queued with
 *    `metadata.post_images = {state: pending, count, aspect}` and the post's
 *    preparation is scheduled (WP-Cron `presshub_social_prepare_post_images`). The job
 *    keeps the featured image in `media_url` as its fallback.
 * 2. Prepare (prepare_post()): one LLM call plans the image descriptions for the post
 *    (prompt `presshub_social_prompt_post_images`, JSON {"images":[...]}), stored in post
 *    meta `_presshub_social_image_plan`. Each pending job then gets its first `count`
 *    descriptions drawn in the platform's aspect through PressHub_Social_Image_Library,
 *    so two platforms with the same aspect (X and Facebook, 16:9) share the same images
 *    and a retry never pays twice. The job's `media_url` becomes the first image and
 *    `metadata.post_images` becomes {state: ready, images: [{url, id, description}]}.
 * 3. Dispatch: a pending job is not claimed by the worker (REST claim) or sent by the
 *    cron (process_due_queue) until it is prepared; the preparation is rescheduled
 *    instead. The worker uploads every image to X and Facebook (media_urls); the Meta
 *    API posts several images to Facebook as one multi-photo post; Instagram and TikTok
 *    use the image as the card or video background.
 *
 * Never blocks a post: a failed plan is retried with the queue's backoff and after
 * presshub_social_max_attempts runs, a failed image is a WARNING, and a job still
 * waiting MAX_WAIT seconds after its posting time is released. In every one of those
 * cases the job posts with the featured image (state `fallback`, with the reason).
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Post_Images {

    /** Job metadata key holding the job's image state and images. */
    public const JOB_META_KEY = 'post_images';

    /** WP-Cron hook that prepares the images of a post's jobs. */
    public const PREPARE_HOOK = 'presshub_social_prepare_post_images';

    /** Post meta holding the planned image descriptions. */
    public const PLAN_META = '_presshub_social_image_plan';

    /** Post meta counting failed planning runs since the last clean one. */
    public const PREP_ATTEMPTS_META = '_presshub_social_image_prep_attempts';

    /** WP_Error code of a job that waits for its images. */
    public const PENDING_CODE = 'social_post_images_pending';

    /** Seconds after its posting time that a job waits for its images before posting with the featured image. */
    public const MAX_WAIT = 1200;

    /** Aspect ratio of each platform's images. */
    public const ASPECTS = [
        'twitter'   => '16:9',
        'facebook'  => '16:9',
        'instagram' => '1:1',
        'tiktok'    => '9:16',
    ];

    /** Characters of article text sent to the planner. */
    private const CONTENT_LIMIT = 8000;

    /**
     * Optional LLM override for tests.
     * Signature: fn( string $sys_prompt, string $user_prompt ): string|WP_Error
     *
     * @var callable|null
     */
    private static $ai_caller = null;

    public static function set_ai_caller( ?callable $caller ): void {
        self::$ai_caller = $caller;
    }

    public static function register_hooks(): void {
        if ( function_exists( 'add_action' ) ) {
            add_action( self::PREPARE_HOOK, [ __CLASS__, 'prepare_post' ], 10, 1 );
        }
    }

    public static function aspect_for( string $platform ): string {
        $aspect = self::ASPECTS[ $platform ] ?? '1:1';
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = (string) apply_filters( 'presshub_social_post_image_aspect', $aspect, $platform );
            if ( in_array( $filtered, PressHub_Social_Image_Library::ASPECTS, true ) ) {
                $aspect = $filtered;
            }
        }
        return $aspect;
    }

    /**
     * Whether a new job for this post and platform should get AI images.
     */
    public static function applies( int $post_id, string $platform ): bool {
        return $post_id > 0
            && PressHub_Social_Settings_Storage::get_post_images_enabled()
            && PressHub_Social_Settings_Storage::get_post_images_count( $platform ) > 0;
    }

    /**
     * Metadata for a new job that waits for its images.
     */
    public static function job_metadata( string $platform ): array {
        return [
            self::JOB_META_KEY => [
                'state'     => 'pending',
                'count'     => PressHub_Social_Settings_Storage::get_post_images_count( $platform ),
                'aspect'    => self::aspect_for( $platform ),
                'queued_at' => gmdate( 'c' ),
            ],
        ];
    }

    /**
     * The job's image state: '' (no AI images asked for), pending, ready or fallback.
     */
    public static function state( array $job ): string {
        $meta = self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ] ?? null;
        return is_array( $meta ) ? (string) ( $meta['state'] ?? '' ) : '';
    }

    public static function is_pending( array $job ): bool {
        return 'pending' === self::state( $job );
    }

    /**
     * Whether the job may be posted now. A job waiting for its images may not, unless it
     * is MAX_WAIT seconds past its posting time: then it is released with the featured
     * image (and the preparation is not waited for again).
     */
    public static function ready_to_post( array $job ): bool {
        if ( ! self::is_pending( $job ) ) {
            return true;
        }
        $scheduled = strtotime( (string) ( $job['scheduled_at'] ?? '' ) . ' UTC' );
        if ( false !== $scheduled && time() - $scheduled > self::MAX_WAIT ) {
            self::release( $job, sprintf( 'the images were not ready %d minutes after the posting time', (int) ( self::MAX_WAIT / 60 ) ) );
            return true;
        }
        return false;
    }

    /**
     * URLs of the job's AI images, in order (empty when it has none).
     *
     * @return string[]
     */
    public static function media_urls( array $job ): array {
        $meta = self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ] ?? null;
        if ( ! is_array( $meta ) || 'ready' !== ( $meta['state'] ?? '' ) ) {
            return [];
        }
        $urls = [];
        foreach ( (array) ( $meta['images'] ?? [] ) as $image ) {
            if ( is_array( $image ) && ! empty( $image['url'] ) ) {
                $urls[] = (string) $image['url'];
            }
        }
        return $urls;
    }

    /**
     * Schedule the background preparation of a post's images (once at a time).
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

    /**
     * WP-Cron handler: prepare the images of the post's pending jobs.
     *
     * @param int|string $post_id
     */
    public static function prepare_post( $post_id ): void {
        $post_id = (int) $post_id;
        $lock    = 'presshub_social_post_images_lock_' . $post_id;
        if ( function_exists( 'get_transient' ) && get_transient( $lock ) ) {
            PressHub_Social_Logger::info( sprintf( 'AI post images for post #%d are already being prepared.', $post_id ) );
            return;
        }
        if ( function_exists( 'set_transient' ) ) {
            set_transient( $lock, 1, 900 );
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
        $pending = array_values( array_filter(
            PressHub_Social_Queue::get_jobs_for_post( $post_id ),
            fn( $j ) => 'pending' === ( $j['status'] ?? '' ) && self::is_pending( $j )
        ) );
        if ( empty( $pending ) ) {
            return;
        }
        // Oldest job first, so the first platform queued makes the images the others reuse.
        usort( $pending, fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- background cron making several image calls.
        }

        $count = 0;
        foreach ( $pending as $job ) {
            $count = max( $count, (int) ( self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ]['count'] ?? 0 ) );
        }
        $count = max( 1, min( 4, $count ) );

        $plan = self::get_plan( $post_id, $count );
        if ( is_wp_error( $plan ) ) {
            self::handle_plan_failure( $post_id, $pending, $plan );
            return;
        }
        if ( function_exists( 'delete_post_meta' ) ) {
            delete_post_meta( $post_id, self::PREP_ATTEMPTS_META );
        }

        $style   = PressHub_Social_Settings_Storage::get_post_image_style();
        $context = self::article_context( $post_id );
        self::forget_cuts(); // the image library makes no AI call with it
        foreach ( $pending as $job ) {
            $job_id = (int) $job['id'];
            $meta   = self::decode_metadata( $job['metadata'] ?? null )[ self::JOB_META_KEY ];
            $want   = max( 1, (int) ( $meta['count'] ?? 1 ) );
            $aspect = (string) ( $meta['aspect'] ?? self::aspect_for( (string) ( $job['platform'] ?? '' ) ) );

            $images = [];
            $errors = [];
            foreach ( array_slice( $plan['images'], 0, $want ) as $i => $description ) {
                $image = PressHub_Social_Image_Library::get_or_create( $post_id, $description, $style, $aspect, $context, 'post_images' );
                if ( is_wp_error( $image ) ) {
                    $errors[] = $image->get_error_message();
                    PressHub_Social_Logger::warning( sprintf( 'AI post image %d/%d for %s job #%d (post #%d) failed: %s', $i + 1, $want, (string) ( $job['platform'] ?? '' ), $job_id, $post_id, $image->get_error_message() ) );
                    continue;
                }
                $images[] = [
                    'url'         => $image['url'],
                    'id'          => $image['id'],
                    'key'         => $image['key'],
                    'description' => $image['description'],
                ];
            }

            // The job may have been posted, cancelled or edited while the images were made.
            $fresh = PressHub_Social_Queue::get_job( $job_id );
            if ( ! is_array( $fresh ) || 'pending' !== ( $fresh['status'] ?? '' ) || ! self::is_pending( $fresh ) ) {
                continue;
            }

            if ( empty( $images ) ) {
                self::release( $fresh, 'every image failed: ' . implode( '; ', array_unique( $errors ) ) );
                continue;
            }

            self::save_state( $fresh, [
                'state'       => 'ready',
                'images'      => $images,
                'prepared_at' => gmdate( 'c' ),
            ], [
                'media_url'  => $images[0]['url'],
                'media_type' => 'image',
            ] );
            PressHub_Social_Logger::info( sprintf( 'AI post images ready for %s job #%d (post #%d): %d of %d image(s), %s.', (string) ( $job['platform'] ?? '' ), $job_id, $post_id, count( $images ), $want, $aspect ) );
        }
    }

    /**
     * Retry a failed plan with the queue's backoff; after presshub_social_max_attempts
     * runs the jobs post with the featured image.
     *
     * @param array[] $pending
     */
    private static function handle_plan_failure( int $post_id, array $pending, WP_Error $error ): void {
        $attempts = function_exists( 'get_post_meta' ) ? (int) get_post_meta( $post_id, self::PREP_ATTEMPTS_META, true ) + 1 : 1;
        $max      = method_exists( 'PressHub_Social_Settings_Storage', 'get_max_attempts' ) ? max( 1, PressHub_Social_Settings_Storage::get_max_attempts() ) : 3;

        if ( $attempts >= $max ) {
            if ( function_exists( 'delete_post_meta' ) ) {
                delete_post_meta( $post_id, self::PREP_ATTEMPTS_META );
            }
            foreach ( $pending as $job ) {
                self::release( $job, sprintf( 'the image plan failed %d times: %s', $attempts, $error->get_error_message() ) );
            }
            return;
        }

        if ( function_exists( 'update_post_meta' ) ) {
            update_post_meta( $post_id, self::PREP_ATTEMPTS_META, $attempts );
        }
        $base  = method_exists( 'PressHub_Social_Settings_Storage', 'get_retry_base_delay' ) ? max( 30, PressHub_Social_Settings_Storage::get_retry_base_delay() ) : 60;
        $delay = (int) min( 900, $base * ( 2 ** ( $attempts - 1 ) ) );
        PressHub_Social_Logger::warning( sprintf( 'AI post image plan for post #%d failed (run %d of %d), retrying in %d s: %s', $post_id, $attempts, $max, $delay, $error->get_error_message() ) );
        self::schedule_prepare( $post_id, $delay );
    }

    /**
     * Post the job with its featured image from now on, and say why.
     */
    private static function release( array $job, string $reason ): void {
        self::save_state( $job, [
            'state'  => 'fallback',
            'reason' => $reason,
        ] );
        PressHub_Social_Logger::warning( sprintf( 'AI post images skipped for %s job #%d (post #%d), it posts with the featured image: %s', (string) ( $job['platform'] ?? '' ), (int) ( $job['id'] ?? 0 ), (int) ( $job['post_id'] ?? 0 ), $reason ) );
    }

    private static function save_state( array $job, array $state, array $fields = [] ): void {
        $metadata = self::decode_metadata( $job['metadata'] ?? null );
        $metadata[ self::JOB_META_KEY ] = array_merge( (array) ( $metadata[ self::JOB_META_KEY ] ?? [] ), $state );
        if ( 'ready' !== ( $state['state'] ?? '' ) ) {
            unset( $metadata[ self::JOB_META_KEY ]['images'] );
        }
        $fields['metadata'] = function_exists( 'wp_json_encode' ) ? wp_json_encode( $metadata ) : json_encode( $metadata );
        PressHub_Social_Queue::update_job( (int) ( $job['id'] ?? 0 ), $fields );
    }

    /**
     * The planned image descriptions for the post, from the stored plan when the post,
     * the prompt and the count are unchanged.
     *
     * @return array{hash:string,images:string[],generated_at:string}|WP_Error
     */
    public static function get_plan( int $post_id, int $count ) {
        $prompt = PressHub_Social_Settings_Storage::get_post_images_prompt();
        if ( '' === $prompt ) {
            return new WP_Error( 'missing_post_images_prompt', 'the AI post images prompt (presshub_social_prompt_post_images) is empty. Restore it in PressHub → Social settings → Prompts.' );
        }

        $context = self::article_context( $post_id );
        if ( '' === trim( $context['title'] . $context['content'] ) ) {
            self::forget_cuts();
            return new WP_Error( 'post_images_no_content', sprintf( 'post #%d has no title or text to plan images from.', $post_id ) );
        }

        $hash   = hash( 'sha256', $prompt . '|' . $count . '|' . $context['title'] . '|' . $context['content'] );
        $stored = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, self::PLAN_META, true ) : null;
        if ( is_array( $stored ) && ( $stored['hash'] ?? '' ) === $hash && ! empty( $stored['images'] ) ) {
            self::forget_cuts(); // no AI call: nothing was sent, so nothing was cut
            return $stored;
        }

        $site_name   = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
        $user_prompt = strtr( $prompt, [
            '{count}'     => (string) $count,
            '{title}'     => $context['title'],
            '{content}'   => $context['content'],
            '{site_name}' => '' !== $site_name ? $site_name : 'PressHub',
        ] );
        $sys_prompt = class_exists( 'PressHub_AI_Image_Prompt_Templates' )
            ? PressHub_AI_Image_Prompt_Templates::PHOTO_SYSTEM_PROMPT
            : 'You are a visual editor for a news publication. You output only the JSON asked for. Your images never depict identifiable people or public figures.';

        PressHub_Social_Logger::info( sprintf( 'Planning %d AI post image(s) for post #%d.', $count, $post_id ) );
        $raw = self::call_ai( $post_id, $sys_prompt, $user_prompt );
        self::forget_cuts(); // recorded with the planning call
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $images = self::parse_plan( (string) $raw, $count );
        if ( is_wp_error( $images ) ) {
            PressHub_Social_Logger::error( sprintf( 'AI post image plan for post #%d is invalid: %s', $post_id, $images->get_error_message() ), [ 'response' => mb_substr( (string) $raw, 0, 500 ) ] );
            return $images;
        }

        $plan = [
            'hash'         => $hash,
            'generated_at' => gmdate( 'c' ),
            'images'       => $images,
        ];
        if ( function_exists( 'update_post_meta' ) ) {
            update_post_meta( $post_id, self::PLAN_META, $plan );
        }
        return $plan;
    }

    /**
     * Parse the planner's JSON: {"images":["...", ...]} (a bare list is accepted too).
     *
     * @return string[]|WP_Error Up to $count non-empty descriptions.
     */
    public static function parse_plan( string $raw, int $count ) {
        $text    = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/iu', '', trim( $raw ) );
        $decoded = json_decode( $text, true );
        if ( ! is_array( $decoded ) ) {
            $start = strpos( $text, '{' );
            $end   = strrpos( $text, '}' );
            if ( false !== $start && false !== $end && $end > $start ) {
                $decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
            }
        }
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'post_images_invalid_json', 'the AI response is not JSON.' );
        }
        $list = array_key_exists( 'images', $decoded ) ? $decoded['images'] : ( array_is_list( $decoded ) ? $decoded : null );
        if ( ! is_array( $list ) ) {
            return new WP_Error( 'post_images_invalid_json', 'the AI response has no "images" list.' );
        }

        $images = [];
        foreach ( $list as $item ) {
            if ( is_array( $item ) ) {
                $item = $item['description'] ?? '';
            }
            $item = trim( (string) preg_replace( '/\s+/u', ' ', is_string( $item ) ? $item : '' ) );
            if ( '' !== $item ) {
                $images[] = mb_substr( $item, 0, 600 );
            }
        }
        if ( empty( $images ) ) {
            return new WP_Error( 'post_images_invalid_json', 'the AI response has no image descriptions.' );
        }
        return array_slice( $images, 0, max( 1, $count ) );
    }

    /**
     * The post's title and plain text for the planner and the Media Library metadata.
     *
     * @return array{title:string,content:string}
     */
    private static function article_context( int $post_id ): array {
        $post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        if ( ! $post ) {
            self::forget_cuts();
            return [ 'title' => '', 'content' => '' ];
        }
        $title   = (string) ( is_object( $post ) ? ( $post->post_title ?? '' ) : ( $post['post_title'] ?? '' ) );
        $content = (string) ( is_object( $post ) ? ( $post->post_content ?? '' ) : ( $post['post_content'] ?? '' ) );
        $content = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : strip_tags( $content );
        $content = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        if ( class_exists( 'PressHub_AI_Knowledge_Cuts' ) ) {
            // Noted for the planning call (social_post_images); a text that
            // fits drops an earlier post's note.
            $content = PressHub_AI_Knowledge_Cuts::clip_material( 'social_post_images', $content, self::CONTENT_LIMIT, __( 'Post text', 'presshub-ai-editor' ), '...' );
        } elseif ( mb_strlen( $content ) > self::CONTENT_LIMIT ) {
            $content = mb_substr( $content, 0, self::CONTENT_LIMIT ) . '...';
        }
        return [ 'title' => trim( wp_strip_all_tags( $title ) ), 'content' => $content ];
    }

    /** Drop the post text cut noted for the planning call (social_post_images). */
    private static function forget_cuts(): void {
        if ( class_exists( 'PressHub_AI_Knowledge_Cuts' ) ) {
            PressHub_AI_Knowledge_Cuts::forget_material( 'social_post_images' );
        }
    }

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
                    $client->set_action( 'social_post_images' );
                }
                $client->suppress_provider_prompt_log = true;
                $result = $client->call_provider( $sys_prompt, $user_prompt, true, [], 0.4, [ 'purpose' => 'social_post_images' ] );
            } catch ( Exception $e ) {
                $result = new WP_Error( 'ai_dispatch_exception', $e->getMessage() );
            }
            if ( null !== $client ) {
                $client->suppress_provider_prompt_log = false;
                PressHub_AI_API_Client::finish_generation_record( $t0, 'generic', $client, $sys_prompt, $user_prompt, $result, [
                    'post_id' => $post_id,
                    'config'  => [ 'action' => 'social_post_images' ],
                ] );
            }
        }

        if ( is_wp_error( $result ) ) {
            PressHub_Social_Logger::error( 'AI post image planning failed: ' . $result->get_error_message(), [ 'error_code' => $result->get_error_code() ] );
            return $result;
        }
        if ( ! is_string( $result ) || '' === trim( $result ) ) {
            return new WP_Error( 'empty_ai_response', 'the AI provider returned an empty response for the post images.' );
        }
        return $result;
    }

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
