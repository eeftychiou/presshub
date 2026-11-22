<?php
/**
 * The social post gate (2.84.0): every post on every platform, the ones added later
 * too, passes it before anything is spent on it and again before it goes out. It runs a
 * list of checks; the first one that answers with a reason stops the post, which then
 * stays in the Social Queue as cancelled with "Not posted: <reason>".
 *
 * Where it runs:
 * 1. Queue (PressHub_Social_Dispatcher::enqueue_for_post()): before a link post, a tweet,
 *    an Instagram post or TikTok photo slides get their AI copy or AI images. A
 *    single-story post is tagged with its story first (tag_post(): one small AI call with
 *    the Social copy model, and only when something was posted inside the window).
 * 2. Plan (PressHub_Social_TikTok_Shorts::get_plan()): shorts and the daily brief video
 *    tell several stories that are only known once planned. edition_blocked() skips the
 *    plan's AI call when every story of the edition's memory digest is blocked;
 *    check_stories() checks the planned stories before any script, voice or render.
 * 3. Out (worker claim in PressHub_Social_REST_Controller::handle_claim() and API
 *    dispatch in PressHub_Social_Dispatcher::dispatch_job()): check_job() checks again,
 *    with no AI call, against what went out since the post was queued.
 *
 * Checks: `presshub_social_post_gate_checks` filters the list (name => callable that
 * takes the context array and returns '' to pass or the reason). The first check is the
 * story ledger (PressHub_Social_Story_Check), with Story Window and Most Posts per Story
 * per Platform in Settings → Social publishing → Platforms → Social post gate. A job reposted by hand
 * from the Social Queue skips the gate (`metadata.gate_bypass`).
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Post_Gate {

    /** Job metadata key of a post's story tag: {story_key, topic, whats_new, dev_hash}. */
    const STORY_META = 'story';

    /** Post meta that caches a post's story tag, so it is asked for once. */
    const POST_META = '_presshub_social_story';

    /** Job metadata flag of a job that skips the gate (reposted by hand). */
    const BYPASS_META = 'gate_bypass';

    /** Error code of a job the gate stopped. */
    const BLOCKED_CODE = 'social_gate_blocked';

    /** Statuses that count as told when a job goes out: only what went out before it. */
    const OUT_STATUSES = [ 'published', 'processing' ];

    /** Most characters of an article sent to the story tag prompt. */
    const TAG_TEXT_CHARS = 1500;

    /** Test override of the story tag AI call: fn( $sys, $user ) => string|WP_Error. */
    private static $ai_caller = null;

    /** Memory stories per date, for one request. */
    private static array $memory_cache = [];

    /** Untagged fallbacks of posts whose tag call failed, for one request (not kept on the post, so a later request asks again). */
    private static array $tag_fallbacks = [];

    public static function set_ai_caller( ?callable $caller ): void {
        self::$ai_caller = $caller;
    }

    public static function reset_cache(): void {
        self::$memory_cache  = [];
        self::$tag_fallbacks = [];
    }

    /**
     * The checks, in order: name => callable( array $context ): string. The context has
     * stage ('queue', 'plan' or 'out'), post_id, platform, stories (each {story_key,
     * topic, whats_new, dev_hash}), brief (true when the stories are parts of one video)
     * and job (the queue row, at stage 'out').
     */
    public static function checks(): array {
        $checks = [ 'story_ledger' => [ __CLASS__, 'story_ledger_check' ] ];
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'presshub_social_post_gate_checks', $checks );
            if ( is_array( $filtered ) ) {
                $checks = $filtered;
            }
        }
        return $checks;
    }

    /** '' when the post may go, else the reason of the first check that stops it. */
    public static function check( array $context ): string {
        $context += [ 'stage' => 'queue', 'post_id' => 0, 'platform' => '', 'stories' => [], 'brief' => false, 'job' => null ];
        foreach ( self::checks() as $name => $check ) {
            if ( ! is_callable( $check ) ) {
                continue;
            }
            $reason = trim( (string) call_user_func( $check, $context ) );
            if ( '' !== $reason ) {
                return $reason;
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Stage 1: queue
    // ------------------------------------------------------------------

    /**
     * Whether a post may be queued on a platform: '' or the reason. A single-story post
     * is tagged first (see tag_post()); a post with no tag passes the story ledger, and
     * the other checks still run.
     */
    public static function check_post( int $post_id, string $platform ): string {
        $tag = self::tag_post( $post_id );
        return self::check( [
            'stage'    => 'queue',
            'post_id'  => $post_id,
            'platform' => $platform,
            'stories'  => null === $tag ? [] : [ $tag ],
        ] );
    }

    /**
     * Job metadata with the post's story tag, so the ledger knows what the job told.
     */
    public static function with_story( int $post_id, ?array $metadata ): ?array {
        $tag = self::tag_post( $post_id, false );
        if ( null === $tag ) {
            return $metadata;
        }
        $metadata                     = is_array( $metadata ) ? $metadata : [];
        $metadata[ self::STORY_META ] = $tag;
        return $metadata;
    }

    /**
     * Queues the post as cancelled with the reason, so the Social Queue shows why it was
     * not posted. No copy is written for it.
     */
    public static function record_blocked( int $post_id, string $platform, string $engine, string $reason, ?array $metadata = null ): int {
        $post  = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        $title = $post ? trim( (string) ( is_object( $post ) ? ( $post->post_title ?? '' ) : ( $post['post_title'] ?? '' ) ) ) : '';
        $id    = PressHub_Social_Queue::enqueue( [
            'post_id'    => $post_id,
            'platform'   => $platform,
            'engine'     => $engine,
            'post_copy'  => '' !== $title ? $title : sprintf( 'Post #%d', $post_id ),
            'status'     => 'cancelled',
            'last_error' => 'Not posted: ' . $reason,
            'metadata'   => self::with_story( $post_id, $metadata ),
        ] );
        PressHub_Social_Logger::info( sprintf( 'Social post gate: post #%d is not posted on %s: %s', $post_id, $platform, $reason ) );
        return is_wp_error( $id ) ? 0 : (int) $id;
    }

    /**
     * Whether a post tells one story of its own, so it can be tagged with it: not a daily
     * brief or podcast (their stories are in the memory digest and their shorts' plans),
     * and not a trending roundup.
     */
    public static function single_story( int $post_id ): bool {
        if ( '' !== (string) get_post_meta( $post_id, '_presshub_briefing_type', true ) ) {
            return false;
        }
        if ( '' !== (string) get_post_meta( $post_id, PressHub_Social_Dispatcher::TRENDING_META_KEY, true ) ) {
            return false;
        }
        return true;
    }

    /**
     * A single-story post's story tag {story_key, topic, whats_new, dev_hash}, or null.
     * Asked for once and kept on the post. The AI is asked only when the gate is on and
     * something was posted inside the window (otherwise nothing can repeat, and the post
     * is known in the ledger by its title as "post-<id>").
     *
     * @param bool $ask False reads the kept tag only.
     */
    public static function tag_post( int $post_id, bool $ask = true ): ?array {
        if ( $post_id <= 0 || ! PressHub_Social_Story_Check::enabled() || ! self::single_story( $post_id ) ) {
            return null;
        }
        $kept = get_post_meta( $post_id, self::POST_META, true );
        if ( is_array( $kept ) && isset( $kept['story_key'] ) ) {
            return $kept;
        }
        if ( isset( self::$tag_fallbacks[ $post_id ] ) ) {
            return self::$tag_fallbacks[ $post_id ];
        }
        $title = self::post_title( $post_id );
        $tag   = [ 'story_key' => 'post-' . $post_id, 'topic' => $title, 'whats_new' => '', 'dev_hash' => '' ];
        if ( ! $ask ) {
            return $tag;
        }
        $told = PressHub_Social_Story_Check::told( $post_id );
        if ( ! empty( $told ) ) {
            $memory = self::memory( $post_id );
            $asked  = self::ask_tag( $post_id, $title, $told, $memory );
            if ( null === $asked ) {
                // Logged; the post goes out untagged rather than not at all, and the other
                // platforms of this request do not ask again.
                self::$tag_fallbacks[ $post_id ] = $tag;
                return $tag;
            }
            $tag = array_merge( $tag, $asked );
            $key = PressHub_Social_Story_Check::key( $tag['story_key'] );
            $tag['story_key'] = '' !== $key ? $key : 'post-' . $post_id;
            $tag['dev_hash']  = isset( $memory[ $tag['story_key'] ] ) ? $memory[ $tag['story_key'] ]['hash'] : '';
        }
        update_post_meta( $post_id, self::POST_META, $tag );
        return $tag;
    }

    /** {story_key, whats_new} from the AI, or null (logged) when it failed. */
    private static function ask_tag( int $post_id, string $title, array $told, array $memory ): ?array {
        $template = PressHub_Social_Prompt_Loader::load( 'social_story_tag.txt' );
        if ( '' === trim( (string) $template ) ) {
            PressHub_Social_Logger::warning( 'Social post gate: the story tag prompt (social_story_tag.txt) is missing; post #' . $post_id . ' is not tagged.' );
            return null;
        }
        $post    = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        $excerpt = $post ? trim( (string) ( is_object( $post ) ? ( $post->post_excerpt ?? '' ) : ( $post['post_excerpt'] ?? '' ) ) ) : '';
        $content = $post ? (string) ( is_object( $post ) ? ( $post->post_content ?? '' ) : ( $post['post_content'] ?? '' ) ) : '';
        $content = trim( (string) preg_replace( '/\s+/u', ' ', function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : strip_tags( $content ) ) );
        $content = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, self::TAG_TEXT_CHARS ) : substr( $content, 0, self::TAG_TEXT_CHARS );
        $block   = PressHub_Social_Story_Check::prompt_block( $told, $memory, PressHub_Social_Settings_Storage::get_story_repeat_hours(), 'στην απάντησή σου' );
        $user    = strtr( $template, [
            '{title}'   => $title,
            '{excerpt}' => $excerpt,
            '{content}' => $content,
            '{repeats}' => $block,
        ] );
        $sys = 'You tag news articles with the story they tell. Reply with valid JSON only.';

        if ( null !== self::$ai_caller ) {
            $raw = call_user_func( self::$ai_caller, $sys, $user );
        } else {
            $config = PressHub_Social_AI_Copywriter::resolve_ai_provider_config( true );
            if ( is_wp_error( $config ) || ! class_exists( 'PressHub_AI_API_Client' ) ) {
                PressHub_Social_Logger::warning( 'Social post gate: no Social copy model for the story tag of post #' . $post_id . ( is_wp_error( $config ) ? ' (' . $config->get_error_message() . ')' : '' ) . '; it is not tagged.' );
                return null;
            }
            try {
                $client = new PressHub_AI_API_Client( $config );
                if ( method_exists( $client, 'set_action' ) ) {
                    $client->set_action( 'social_story_tag' );
                }
                $raw = $client->call_provider( $sys, $user, true, [], $config['temperature'] ?? null, [ 'post_id' => $post_id, 'purpose' => 'social_story_tag' ] );
            } catch ( \Throwable $e ) {
                $raw = new WP_Error( 'ai_dispatch_exception', $e->getMessage() );
            }
        }
        if ( is_wp_error( $raw ) || ! is_string( $raw ) || '' === trim( $raw ) ) {
            PressHub_Social_Logger::warning( 'Social post gate: the story tag of post #' . $post_id . ' failed (' . ( is_wp_error( $raw ) ? $raw->get_error_message() : 'empty answer' ) . '); it is not tagged.' );
            return null;
        }
        $fixes   = [];
        $error   = null;
        $decoded = PressHub_Social_AI_Json::decode( $raw, $fixes, $error );
        if ( ! is_array( $decoded ) || ! isset( $decoded['story_key'] ) ) {
            PressHub_Social_Logger::warning( 'Social post gate: the story tag of post #' . $post_id . ' is not valid JSON with a story_key; it is not tagged.' );
            return null;
        }
        return [
            'story_key' => (string) $decoded['story_key'],
            'whats_new' => trim( (string) ( $decoded['whats_new'] ?? '' ) ),
        ];
    }

    // ------------------------------------------------------------------
    // Stage 2: plan (shorts and the daily brief video)
    // ------------------------------------------------------------------

    /**
     * The planned stories that may not go out on these platforms (the format of
     * PressHub_Social_Story_Check::find_repeats()). $plan is a parsed, annotated plan.
     */
    public static function check_stories( int $post_id, array $plan, array $platforms, ?array $told = null, ?array $memory = null ): array {
        if ( ! PressHub_Social_Story_Check::enabled() ) {
            return [];
        }
        $told   = null === $told ? PressHub_Social_Story_Check::told( $post_id, $platforms ) : $told;
        $memory = null === $memory ? self::memory( $post_id ) : $memory;
        return self::repeats_on_every_platform( $plan, $told, $memory, $platforms );
    }

    /**
     * One plan serves every platform of a post, so a planned story is left out only when
     * it is a repeat on each of them; a story still blocked on some platform is stopped
     * there by check_job() when it goes out. Without platforms, the told list is one.
     */
    private static function repeats_on_every_platform( array $plan, array $told, array $memory, array $platforms ): array {
        $platforms = array_values( array_unique( array_filter( array_map( 'strval', $platforms ) ) ) );
        if ( count( $platforms ) < 2 ) {
            return PressHub_Social_Story_Check::find_repeats( $plan, $told, $memory );
        }
        $common = null;
        foreach ( $platforms as $platform ) {
            $on       = array_values( array_filter( $told, fn( $e ) => $platform === ( $e['platform'] ?? '' ) ) );
            $repeats  = PressHub_Social_Story_Check::find_repeats( $plan, $on, $memory );
            $by_place = [];
            foreach ( $repeats as $r ) {
                $by_place[ $r['short'] . '|' . ( null === $r['story'] ? '-' : $r['story'] ) ] = $r;
            }
            $common = null === $common ? $by_place : array_intersect_key( $common, $by_place );
            if ( empty( $common ) ) {
                return [];
            }
        }
        return array_values( (array) $common );
    }

    /**
     * Why a daily brief's or podcast's plan need not be asked for at all: every story of
     * its edition's memory digest is blocked on every one of these platforms even if it had news
     * (resolved, posted the most times, or no development since it was told). '' when a
     * plan is worth asking for, or the digest is not there yet.
     */
    public static function edition_blocked( int $post_id, array $told, array $memory, array $platforms = [] ): string {
        if ( empty( $told ) || ! PressHub_Social_Story_Check::enabled() ) {
            return '';
        }
        $keys = self::edition_keys( $post_id );
        if ( empty( $keys ) ) {
            return '';
        }
        $plan = [];
        foreach ( $keys as $key ) {
            // Assume news on every story: only what news cannot unblock counts here.
            $plan[] = [ 'story_key' => $key, 'topic' => $memory[ $key ]['title'] ?? $key, 'whats_new' => '?' ];
        }
        $plan    = PressHub_Social_Story_Check::annotate( $plan, $memory );
        $repeats = self::repeats_on_every_platform( $plan, $told, $memory, $platforms );
        if ( count( $repeats ) < count( $plan ) ) {
            return '';
        }
        return sprintf( 'every story of this edition was already posted with nothing new since (%s)', PressHub_Social_Story_Check::describe( $repeats ) );
    }

    /** The story keys of the memory digest of a briefing post's date and edition. */
    private static function edition_keys( int $post_id ): array {
        $date    = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        $edition = (string) get_post_meta( $post_id, '_presshub_briefing_edition', true );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! class_exists( 'PressHub_AI_Memory_Store' ) ) {
            return [];
        }
        try {
            $rows = PressHub_AI_Memory_Store::get_entries_for_range( $date, $date, 'daily', 'active' );
        } catch ( \Throwable $e ) {
            return [];
        }
        $keys = [];
        foreach ( (array) $rows as $row ) {
            if ( '' !== $edition && (string) ( $row['edition'] ?? '' ) !== $edition ) {
                continue;
            }
            $content = PressHub_AI_Memory_Store::decode_content( (string) ( $row['content_json'] ?? '' ) );
            foreach ( (array) ( $content['stories'] ?? [] ) as $story ) {
                $key = PressHub_Social_Story_Check::key( (string) ( $story['key'] ?? '' ) );
                if ( '' !== $key && ( ! isset( $story['include'] ) || false !== $story['include'] ) ) {
                    $keys[ $key ] = $key;
                }
            }
        }
        return array_values( $keys );
    }

    // ------------------------------------------------------------------
    // Stage 3: out (worker claim and API dispatch)
    // ------------------------------------------------------------------

    /**
     * Whether a queued job may go out now: '' or the reason. No AI call: the stories are
     * the ones kept on the job, compared with what went out on its platform since.
     */
    public static function check_job( array $job ): string {
        $meta = self::decode( $job['metadata'] ?? null );
        if ( ! empty( $meta[ self::BYPASS_META ] ) ) {
            return '';
        }
        $ts      = $meta[ PressHub_Social_TikTok_Shorts::JOB_META_KEY ] ?? null;
        $stories = [];
        $brief   = false;
        if ( is_array( $ts ) ) {
            if ( ! empty( $ts['test'] ) ) {
                return '';
            }
            if ( ! empty( $ts['brief'] ) && is_array( $ts['stories'] ?? null ) ) {
                $stories = array_values( array_filter( $ts['stories'], 'is_array' ) );
                $brief   = true;
            } elseif ( '' !== trim( (string) ( $ts['topic'] ?? '' ) ) ) {
                $stories = [ $ts ];
            }
        } elseif ( is_array( $meta[ self::STORY_META ] ?? null ) ) {
            $stories = [ $meta[ self::STORY_META ] ];
        }
        return self::check( [
            'stage'    => 'out',
            'post_id'  => (int) ( $job['post_id'] ?? 0 ),
            'platform' => (string) ( $job['platform'] ?? '' ),
            'stories'  => $stories,
            'brief'    => $brief,
            'job'      => $job,
        ] );
    }

    /** Cancels a job the gate stopped, with the reason as its note. */
    public static function block_job( int $job_id, string $reason ): void {
        PressHub_Social_Queue::update_job( $job_id, [
            'status'     => 'cancelled',
            'last_error' => 'Not posted: ' . $reason,
        ] );
        PressHub_Social_Logger::info( sprintf( 'Social post gate: job #%d is not posted: %s', $job_id, $reason ) );
    }

    // ------------------------------------------------------------------
    // The story ledger check
    // ------------------------------------------------------------------

    /**
     * The first check: a story the platform already told inside Story Window goes out
     * again only with a new development, and at most Most Posts per Story per Platform
     * times. A video of several stories (the daily brief) stops only when fewer than two
     * of its stories are left.
     */
    public static function story_ledger_check( array $context ): string {
        if ( ! PressHub_Social_Story_Check::enabled() || empty( $context['stories'] ) || '' === (string) $context['platform'] ) {
            return '';
        }
        $post_id  = (int) $context['post_id'];
        $statuses = 'out' === $context['stage'] ? self::OUT_STATUSES : PressHub_Social_Story_Check::TOLD_STATUSES;
        $told     = PressHub_Social_Story_Check::told( $post_id, [ (string) $context['platform'] ], null, $statuses );
        if ( empty( $told ) ) {
            return '';
        }
        $memory = self::memory( $post_id );
        if ( ! empty( $context['brief'] ) ) {
            $plan = [ [ 'brief' => true, 'stories' => array_values( $context['stories'] ) ] ];
        } else {
            $plan = array_values( $context['stories'] );
        }
        $repeats = PressHub_Social_Story_Check::find_repeats( $plan, $told, $memory );
        if ( empty( $repeats ) ) {
            return '';
        }
        if ( ! empty( $context['brief'] ) && count( $context['stories'] ) - count( $repeats ) >= 2 ) {
            return '';
        }
        return PressHub_Social_Story_Check::describe( $repeats );
    }

    // ------------------------------------------------------------------

    /** The editorial memory's stories for a post's date, read once per request. */
    private static function memory( int $post_id ): array {
        $date = PressHub_Social_TikTok_Shorts::plan_date( $post_id );
        if ( ! isset( self::$memory_cache[ $date ] ) ) {
            self::$memory_cache[ $date ] = PressHub_Social_Story_Check::memory_stories( $date );
        }
        return self::$memory_cache[ $date ];
    }

    private static function post_title( int $post_id ): string {
        $post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        return $post ? trim( (string) ( is_object( $post ) ? ( $post->post_title ?? '' ) : ( $post['post_title'] ?? '' ) ) ) : '';
    }

    private static function decode( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        $decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? $decoded : [];
    }
}
