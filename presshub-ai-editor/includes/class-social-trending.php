<?php
/**
 * Trending in Cyprus: a roundup post of the most engaging recent social posts.
 *
 * Every N hours (setting) a run collects candidate posts about Cyprus:
 *
 *   - X, TikTok, Facebook: the social worker (Pull Mode) asks for a plan,
 *     opens the search / Page URLs below with its reader browser sessions and
 *     sends back what it saw, with engagement counts.
 *       GET  /presshub-social/v1/trending/plan   → is a run waiting, and which URLs
 *       POST /presshub-social/v1/trending/posts  → the posts found for that run
 *   - Instagram: the Graph API Hashtag Search (top media) with the Instagram
 *     account and token already set for posting.
 *
 * When the worker reports (or has not reported after WORKER_WAIT_SEC), the
 * posts are ranked per platform by engagement and recency, mixed across
 * platforms, and written into one post in the roundup category. By default
 * there is one post per day that each run updates ("daily"); "rolling" keeps
 * a single post and "each" creates a new post every run.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';

class PressHub_AI_Social_Trending {

    const OPTION_STATE      = 'presshub_ai_social_trending_state';
    const OPTION_CANDIDATES = 'presshub_ai_social_trending_candidates';
    const OPTION_POSTS      = 'presshub_ai_social_trending_posts';
    const OPTION_IG_TAG_IDS = 'presshub_ai_social_trending_ig_tag_ids';
    /** item key => Media Library id of the image shown for a TikTok photo post. */
    const OPTION_IMAGES     = 'presshub_ai_social_trending_images';
    /** Most recent image entries kept in OPTION_IMAGES. */
    const IMAGES_KEPT       = 300;
    /** Posts picked by roundups, kept for the briefing until used or too old. */
    const OPTION_BRIEFING_POOL = 'presshub_ai_social_trending_briefing_pool';
    /** Pool entries older than this are dropped (seconds). */
    const BRIEFING_POOL_TTL = 259200;
    /** Characters of each post's text that go into the briefing item. */
    const BRIEFING_TEXT_CHARS = 500;

    const CRON_HOOK  = 'presshub_ai_social_trending_tick';
    /** One-off job that draws a roundup's AI featured image outside the worker's request. */
    const AI_IMAGE_HOOK = 'presshub_ai_social_trending_ai_image';
    /** Post meta: the AI image job for this roundup was queued (once per post). */
    const AI_IMAGE_META = '_presshub_trending_ai_image_queued';
    /** Post meta: the QA gate release waits for the AI image job, so social shares get the AI image. */
    const AI_RELEASE_META = '_presshub_trending_ai_release_pending';
    /** What ai_image_texts() cut when the AI image job was queued (agents.md rule 13). */
    const AI_IMAGE_CUTS_META = '_presshub_trending_ai_image_cuts';
    /** Longest wait for the AI image job before the roundup is released with the stand-in picture. */
    const AI_IMAGE_MAX_WAIT = 900;
    /** Post meta: the watchdog released this roundup before its AI image arrived. */
    const AI_WATCHDOG_META = '_presshub_trending_ai_watchdog_release';
    const RUN_ACTION = 'presshub_ai_social_trending_run_now';

    /** Post meta on roundup posts: the run key and the items shown. */
    const META_KEY   = '_presshub_social_trending_key';
    const META_ITEMS = '_presshub_social_trending_items';
    /** AI summary at the top of a roundup: [ 'hash' => md5 of the post keys, 'text' => paragraph, 'generated_at' => ISO time ]. */
    const META_SUMMARY = '_presshub_social_trending_summary';
    const SUMMARY_MAX_CHARS      = 900;
    const SUMMARY_POST_TEXT_CHARS = 500;

    /** A run publishes with what it has if the worker has not reported after this long. */
    const WORKER_WAIT_SEC = 2700;

    /** A plan handed to the worker that has not come back after this long may be handed out again. */
    const PLAN_LOCK_SEC = 1200;

    /** Roundup posts remembered by key (daily / each mode). */
    const MAX_POST_KEYS = 90;

    /** Candidates kept for one run. */
    const MAX_CANDIDATES = 600;

    const PLATFORMS        = [ 'x', 'tiktok', 'instagram', 'facebook' ];
    const WORKER_PLATFORMS = [ 'x', 'tiktok', 'facebook' ];

    const PLATFORM_LABELS = [
        'x'         => 'X',
        'tiktok'    => 'TikTok',
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
    ];

    /** X rejects long search queries; each query stays under this many characters. */
    const X_QUERY_MAX_CHARS = 450;

    /** X search pages per run (queries are split when the terms do not fit in one). */
    const MAX_X_PAGES = 4;

    /** TikTok pages per run (each one is a page visit): hashtag pages, then keyword searches. */
    const MAX_TIKTOK_TAGS    = 3;
    const MAX_TIKTOK_QUERIES = 2;

    /** @var callable|null fn( string $url ): array|WP_Error — HTTP GET override for tests. */
    public static $http_get = null;

    /* ------------------------------------------------------------------
     * Hooks
     * ---------------------------------------------------------------- */

    public static function register_hooks(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'cron_tick' ] );
        add_action( self::AI_IMAGE_HOOK, [ __CLASS__, 'run_ai_featured_image' ], 10, 2 );
        add_action( 'admin_post_' . self::RUN_ACTION, [ __CLASS__, 'handle_run_now' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_front_assets' ] );
        add_action( 'wp_body_open', [ __CLASS__, 'print_fb_root' ] );
        add_filter( 'the_content', [ __CLASS__, 'filter_legacy_facebook_embeds' ], 9 );
        if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
        }
    }

    public static function cron_tick(): void {
        self::tick();
    }

    /**
     * Admin "Refresh now": start a run even if the interval has not passed.
     */
    public static function handle_run_now(): void {
        if ( ! current_user_can( PressHub_Capabilities::manage() ) ) {
            wp_die( esc_html__( 'Unauthorized', 'presshub-ai-editor' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::RUN_ACTION );
        $state = self::maybe_start_run( null, true );
        $flag  = null === $state ? 'off' : 'started';
        $back  = wp_get_referer();
        wp_safe_redirect( add_query_arg( 'presshub_trending', $flag, $back ? $back : admin_url() ) );
        exit;
    }

    /* ------------------------------------------------------------------
     * Run state
     * ---------------------------------------------------------------- */

    public static function get_state(): array {
        $s = get_option( self::OPTION_STATE, [] );
        $s = is_array( $s ) ? $s : [];
        return array_merge( [
            'run_id'         => '',
            'started'        => 0,
            'open'           => false,
            'worker_pending' => false,
            'plan_handed'    => 0,
            'last_run'       => 0,
            'last_finished'  => 0,
            'last_post_id'   => 0,
            'last_count'     => 0,
            'last_note'      => '',
        ], $s );
    }

    private static function save_state( array $state ): void {
        update_option( self::OPTION_STATE, $state, false );
    }

    private static function worker_platforms(): array {
        $on = PressHub_AI_Settings_Storage::get_social_trending_platforms();
        $out = [];
        foreach ( self::WORKER_PLATFORMS as $p ) {
            if ( ! in_array( $p, $on, true ) ) {
                continue;
            }
            if ( 'facebook' === $p && empty( PressHub_AI_Settings_Storage::get_social_trending_facebook_pages() ) ) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    private static function instagram_ready(): bool {
        return in_array( 'instagram', PressHub_AI_Settings_Storage::get_social_trending_platforms(), true )
            && ! empty( PressHub_AI_Settings_Storage::get_social_trending_hashtags() )
            && '' !== self::ig_user_id() && '' !== self::meta_token();
    }

    /**
     * Start a run when one is due (or forced). Returns the open run's state, or null.
     */
    public static function maybe_start_run( ?int $now = null, bool $force = false ): ?array {
        $now   = $now ?? time();
        $state = self::get_state();
        if ( ! PressHub_AI_Settings_Storage::get_social_trending_enabled() ) {
            return null;
        }
        if ( ! empty( $state['open'] ) && ! $force ) {
            return $state;
        }
        // #179: a forced start must not replace a run the worker is reading right
        // now, or the worker's results come back to a closed run (HTTP 409).
        if ( $force && ! empty( $state['open'] ) && (int) $state['plan_handed'] > 0 && ( $now - (int) $state['plan_handed'] ) < self::PLAN_LOCK_SEC ) {
            self::log( 'info', sprintf( 'Social trending: run %s is still being read by the worker; not starting another one.', $state['run_id'] ) );
            return $state;
        }
        $interval = PressHub_AI_Settings_Storage::get_social_trending_interval_hours() * 3600;
        if ( ! $force && ( $now - (int) $state['last_run'] ) < $interval ) {
            return null;
        }
        // Quiet hours: no scheduled run overnight ("Refresh now" still works).
        if ( ! $force && self::in_quiet_hours( $now ) ) {
            return null;
        }
        $worker = self::worker_platforms();
        $ig     = self::instagram_ready();
        if ( empty( $worker ) && ! $ig ) {
            if ( '' === (string) $state['last_note'] || $force ) {
                $state['last_note'] = 'No platform can be read: switch on X, TikTok or Facebook (with Pages) for the worker, or set the Instagram account, token and hashtags.';
                self::save_state( $state );
                self::log( 'warning', 'Social trending: ' . $state['last_note'] );
            }
            return null;
        }

        $state['run_id']         = gmdate( 'YmdHis', $now ) . '-' . substr( md5( $now . '|' . mt_rand() ), 0, 6 );
        $state['started']        = $now;
        $state['open']           = true;
        $state['worker_pending'] = ! empty( $worker );
        $state['plan_handed']    = 0;
        $state['last_run']       = $now;
        $state['last_note']      = '';
        self::save_state( $state );
        update_option( self::OPTION_CANDIDATES, [], false );
        self::log( 'info', sprintf( 'Social trending: run %s started (worker: %s, Instagram: %s).', $state['run_id'], $worker ? implode( ', ', $worker ) : 'none', $ig ? 'yes' : 'no' ) );

        if ( $ig ) {
            $found = self::fetch_instagram( $now );
            self::add_candidates( $found );
        }
        if ( empty( $worker ) ) {
            self::finalize_run( $now );
            return self::get_state();
        }
        return $state;
    }

    /**
     * Hourly cron: start a due run; publish an open run whose worker never reported.
     */
    public static function tick( ?int $now = null ): void {
        $now   = $now ?? time();
        self::release_stale_ai_waits( $now );
        $state = self::get_state();
        if ( ! empty( $state['open'] ) && ( $now - (int) $state['started'] ) >= self::WORKER_WAIT_SEC ) {
            self::log( 'warning', sprintf( 'Social trending: the worker did not report run %s; publishing with what was collected.', $state['run_id'] ) );
            self::finalize_run( $now, 'The worker did not report in time (is it running in Pull Mode?).' );
            return;
        }
        self::maybe_start_run( $now );
    }

    /* ------------------------------------------------------------------
     * Worker plan
     * ---------------------------------------------------------------- */

    /**
     * What the worker should read now.
     */
    public static function get_worker_plan( bool $force = false, ?int $now = null ): array {
        $now   = $now ?? time();
        $state = $force ? self::maybe_start_run( $now, true ) : self::maybe_start_run( $now );
        $plan  = [
            'enabled'          => PressHub_AI_Settings_Storage::get_social_trending_enabled(),
            'due'              => false,
            'run_id'           => '',
            'targets'          => [],
            'max_age_hours'    => PressHub_AI_Settings_Storage::get_social_trending_max_age_hours(),
            'max_per_platform' => PressHub_AI_Settings_Storage::get_social_trending_max_per_platform() * 4,
        ];
        if ( null === $state || empty( $state['open'] ) || empty( $state['worker_pending'] ) ) {
            return $plan;
        }
        if ( (int) $state['plan_handed'] > 0 && ( $now - (int) $state['plan_handed'] ) < self::PLAN_LOCK_SEC ) {
            return $plan;
        }
        $plan['due']     = true;
        $plan['run_id']  = (string) $state['run_id'];
        $plan['targets'] = self::build_targets( $now );
        $state['plan_handed'] = $now;
        self::save_state( $state );
        return $plan;
    }

    /**
     * Pages the worker visits.
     *
     * @return array<int, array{platform:string, url:string, label:string}>
     */
    public static function build_targets( ?int $now = null ): array {
        $now      = $now ?? time();
        $worker   = self::worker_platforms();
        $keywords = PressHub_AI_Settings_Storage::get_social_trending_keywords();
        $min      = PressHub_AI_Settings_Storage::get_social_trending_min_engagement();
        $targets  = [];
        // The Top tab is mostly older posts unless told otherwise, and anything
        // past the age window is dropped later, so ask X for recent posts only.
        // since: takes a UTC date, so this window is up to a day wider than the
        // age limit; select() trims the rest.
        $since    = ' since:' . gmdate( 'Y-m-d', $now - PressHub_AI_Settings_Storage::get_social_trending_max_age_hours() * 3600 );

        if ( in_array( 'x', $worker, true ) && ! empty( $keywords ) ) {
            // Greek terms and Latin-script terms are searched separately: English
            // "Cyprus" draws global posts with far more likes, which would crowd out
            // local Greek discussion, so the Greek search uses lang:el and a lower bar.
            $greek = [];
            $latin = [];
            foreach ( $keywords as $k ) {
                $term = false !== strpos( $k, ' ' ) ? '"' . str_replace( '"', '', $k ) . '"' : $k;
                if ( preg_match( '/\p{Greek}/u', $k ) ) {
                    $greek[] = $term;
                } else {
                    $latin[] = $term;
                }
            }
            $groups = [
                [ $greek, ' lang:el', self::min_engagement_for_greek( $min ), 'X search (Greek)' ],
                [ $latin, '', max( 1, $min * 2 ), 'X search (English / other)' ],
            ];
            $x_pages = 0;
            foreach ( $groups as [ $terms, $lang, $faves, $label ] ) {
                $suffix = $lang . ' -filter:replies -filter:retweets' . ( $min > 0 ? ' min_faves:' . $faves : '' ) . $since;
                foreach ( self::chunk_or_terms( $terms, self::X_QUERY_MAX_CHARS - self::strlen( $suffix ) - 2 ) as $chunk ) {
                    if ( $x_pages >= self::MAX_X_PAGES ) {
                        break 2;
                    }
                    $x_pages++;
                    $targets[] = [
                        'platform' => 'x',
                        'url'      => 'https://x.com/search?q=' . rawurlencode( '(' . implode( ' OR ', $chunk ) . ')' . $suffix ) . '&src=typed_query&f=top',
                        'label'    => $label,
                    ];
                }
            }
        }
        if ( in_array( 'tiktok', $worker, true ) ) {
            // TikTok search has no date filter on the web and sorts by relevance, so
            // most results are older than the window. Hashtag pages surface newer
            // videos, so they come first; a couple of keyword searches add the rest.
            foreach ( array_slice( PressHub_AI_Settings_Storage::get_social_trending_hashtags(), 0, self::MAX_TIKTOK_TAGS ) as $tag ) {
                $targets[] = [
                    'platform' => 'tiktok',
                    'url'      => 'https://www.tiktok.com/tag/' . rawurlencode( $tag ),
                    'label'    => 'TikTok #' . $tag,
                ];
            }
            // One Greek and one other term, so two near-identical Greek forms do not use both searches.
            $picks = [];
            foreach ( $keywords as $k ) {
                $script = preg_match( '/\p{Greek}/u', $k ) ? 'el' : 'other';
                if ( ! isset( $picks[ $script ] ) ) {
                    $picks[ $script ] = $k;
                }
            }
            foreach ( array_slice( array_values( $picks ), 0, self::MAX_TIKTOK_QUERIES ) as $k ) {
                $targets[] = [
                    'platform' => 'tiktok',
                    'url'      => 'https://www.tiktok.com/search/video?q=' . rawurlencode( $k ),
                    'label'    => 'TikTok search: ' . $k,
                ];
            }
        }
        if ( in_array( 'facebook', $worker, true ) ) {
            foreach ( PressHub_AI_Settings_Storage::get_social_trending_facebook_pages() as $url ) {
                $targets[] = [
                    'platform' => 'facebook',
                    'url'      => $url,
                    'label'    => 'Facebook Page',
                ];
            }
        }
        return $targets;
    }

    private static function strlen( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
    }

    /**
     * Split search terms into groups whose "a OR b OR c" form fits in $max characters.
     *
     * @return array<int, string[]>
     */
    public static function chunk_or_terms( array $terms, int $max ): array {
        $chunks = [];
        $cur    = [];
        $len    = 0;
        foreach ( $terms as $t ) {
            $add = self::strlen( $t ) + ( $cur ? 4 : 0 );
            if ( $cur && $len + $add > $max ) {
                $chunks[] = $cur;
                $cur      = [];
                $len      = 0;
                $add      = self::strlen( $t );
            }
            $cur[] = $t;
            $len  += $add;
        }
        if ( $cur ) {
            $chunks[] = $cur;
        }
        return $chunks;
    }

    /**
     * Posts sent by the worker for a run.
     *
     * @return array|WP_Error {accepted:int, rejected:int, published:int}
     */
    public static function ingest_worker_posts( string $run_id, array $posts, array $errors = [], ?int $now = null ) {
        $now   = $now ?? time();
        $state = self::get_state();
        if ( empty( $state['open'] ) || '' === $run_id || $run_id !== (string) $state['run_id'] ) {
            $why = empty( $state['open'] )
                ? sprintf( 'no run is open (the last one, %s, already closed)', (string) $state['run_id'] )
                : sprintf( 'run %s replaced it', (string) $state['run_id'] );
            self::log( 'warning', sprintf( 'Social trending: results for run %s refused: %s.', $run_id, $why ) );
            return new WP_Error( 'stale_run', sprintf( 'This run is no longer open: %s.', $why ), [ 'status' => 409 ] );
        }
        if ( ! empty( $errors ) ) {
            self::log( 'warning', 'Social trending: worker reported problems.', [ 'errors' => array_slice( $errors, 0, 20 ) ] );
        }
        $allowed  = self::worker_platforms();
        $clean    = [];
        $rejected = 0;
        foreach ( array_slice( array_values( array_filter( $posts, 'is_array' ) ), 0, 500 ) as $raw ) {
            $item = self::normalize_item( $raw, $now );
            if ( null === $item || ! in_array( $item['platform'], $allowed, true ) ) {
                $rejected++;
                continue;
            }
            $clean[] = $item;
        }
        self::add_candidates( $clean );
        $state = self::get_state();
        $state['worker_pending'] = false;
        self::save_state( $state );
        $note    = empty( $errors ) ? '' : sprintf( 'The worker reported %d problem(s); see the log.', count( $errors ) );
        $post_id = self::finalize_run( $now, $note );
        return [ 'accepted' => count( $clean ), 'rejected' => $rejected, 'post_id' => (int) $post_id ];
    }

    /* ------------------------------------------------------------------
     * Candidates
     * ---------------------------------------------------------------- */

    private static function clean_text( $value, int $max ): string {
        $value = is_string( $value ) ? $value : '';
        $value = wp_strip_all_tags( $value, false );
        $value = preg_replace( "/[\x00-\x08\x0B\x0C\x0E-\x1F]/u", '', (string) $value );
        $value = trim( (string) $value );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }

    private static function count_value( $v ): int {
        if ( is_string( $v ) ) {
            $v = str_replace( [ ',', ' ' ], '', $v );
        }
        return is_numeric( $v ) ? max( 0, (int) $v ) : 0;
    }

    /**
     * Validate one candidate post (from the worker or Instagram).
     */
    public static function normalize_item( array $raw, int $now ): ?array {
        $platform = strtolower( (string) ( $raw['platform'] ?? '' ) );
        $id       = (string) ( $raw['id'] ?? '' );
        if ( ! in_array( $platform, self::PLATFORMS, true ) || ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $id ) ) {
            return null;
        }
        $created = $raw['created_at'] ?? '';
        $created_ts = is_numeric( $created ) ? (int) $created : (int) strtotime( (string) $created );
        $max_age    = PressHub_AI_Settings_Storage::get_social_trending_max_age_hours() * 3600;
        if ( $created_ts <= 0 || $created_ts > $now + 600 || $now - $created_ts > $max_age ) {
            return null;
        }
        $handle = strtolower( ltrim( (string) ( $raw['handle'] ?? '' ), '@' ) );
        $handle = preg_match( '/^[A-Za-z0-9_.\-]{1,60}$/', $handle ) ? $handle : '';
        $url    = (string) ( $raw['url'] ?? '' );
        if ( 'x' === $platform ) {
            if ( '' === $handle || ! preg_match( '/^\d+$/', $id ) ) {
                return null;
            }
            $url = 'https://x.com/' . $handle . '/status/' . $id;
        } elseif ( 'tiktok' === $platform ) {
            if ( '' === $handle || ! preg_match( '/^\d+$/', $id ) ) {
                return null;
            }
            if ( ! preg_match( '#^https://www\.tiktok\.com/@[A-Za-z0-9_.]+/(video|photo)/\d+$#', $url ) ) {
                $url = 'https://www.tiktok.com/@' . $handle . '/video/' . $id;
            }
        } elseif ( 'instagram' === $platform ) {
            if ( ! preg_match( '#^https://www\.instagram\.com/(p|reel|tv)/[A-Za-z0-9_\-]+/?$#', $url ) ) {
                return null;
            }
        } elseif ( 'facebook' === $platform ) {
            $url = self::facebook_url( $url, $id );
        }
        if ( function_exists( 'esc_url_raw' ) ) {
            $url = esc_url_raw( $url );
        }
        if ( '' === $url ) {
            return null;
        }

        $m     = is_array( $raw['metrics'] ?? null ) ? $raw['metrics'] : [];
        $media = is_array( $raw['media'] ?? null ) ? $raw['media'] : [];
        $mtype = in_array( $media['type'] ?? '', [ 'video', 'image' ], true ) ? $media['type'] : 'none';
        $thumb = (string) ( $raw['thumbnail'] ?? '' );
        $thumb = preg_match( '#^https://#', $thumb ) && function_exists( 'esc_url_raw' ) ? esc_url_raw( $thumb ) : ( preg_match( '#^https://#', $thumb ) ? $thumb : '' );

        return [
            'key'         => $platform . '_' . $id,
            'platform'    => $platform,
            'id'          => $id,
            'handle'      => $handle,
            'author_name' => self::clean_text( $raw['author_name'] ?? '', 120 ),
            'url'         => $url,
            'text'        => self::clean_text( $raw['text'] ?? '', 2000 ),
            'created_ts'  => $created_ts,
            'media_type'  => $mtype,
            'thumbnail'   => $thumb,
            'likes'       => self::count_value( $m['likes'] ?? 0 ),
            'comments'    => self::count_value( $m['comments'] ?? 0 ),
            'shares'      => self::count_value( $m['shares'] ?? 0 ),
            'views'       => self::count_value( $m['views'] ?? 0 ),
        ];
    }

    /**
     * Canonical https://www.facebook.com link for a post: path kept, and only
     * the query parameters that identify the post (story_fbid / fbid / id).
     */
    public static function facebook_url( string $url, string $id ): string {
        $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
        if ( ! preg_match( '/^(www\.|m\.|web\.)?facebook\.com$/', $host ) ) {
            return 'https://www.facebook.com/' . rawurlencode( $id );
        }
        $path  = (string) parse_url( $url, PHP_URL_PATH );
        $query = (string) parse_url( $url, PHP_URL_QUERY );
        parse_str( $query, $q );
        $keep = [];
        foreach ( [ 'story_fbid', 'fbid', 'id', 'v' ] as $k ) {
            if ( isset( $q[ $k ] ) && is_string( $q[ $k ] ) && preg_match( '/^[A-Za-z0-9_]{1,64}$/', $q[ $k ] ) ) {
                $keep[ $k ] = $q[ $k ];
            }
        }
        $path = preg_match( '#^/[A-Za-z0-9_.\-/]*$#', $path ) ? $path : '/';
        return 'https://www.facebook.com' . $path . ( $keep ? '?' . http_build_query( $keep ) : '' );
    }

    private static function add_candidates( array $items ): void {
        if ( empty( $items ) ) {
            return;
        }
        $store = get_option( self::OPTION_CANDIDATES, [] );
        $store = is_array( $store ) ? $store : [];
        foreach ( $items as $item ) {
            $k = $item['key'];
            if ( isset( $store[ $k ] ) ) {
                // Seen on two pages (e.g. two TikTok searches): keep the higher counts.
                foreach ( [ 'likes', 'comments', 'shares', 'views' ] as $f ) {
                    $item[ $f ] = max( (int) $item[ $f ], (int) $store[ $k ][ $f ] );
                }
                if ( '' === $item['text'] ) {
                    $item['text'] = $store[ $k ]['text'];
                }
            }
            $store[ $k ] = $item;
        }
        if ( count( $store ) > self::MAX_CANDIDATES ) {
            $store = array_slice( $store, -self::MAX_CANDIDATES, null, true );
        }
        update_option( self::OPTION_CANDIDATES, $store, false );
    }

    /** Who posted it, for the per-account cap: handle, else display name, else the post itself. */
    public static function account_key( array $item ): string {
        if ( '' !== (string) $item['handle'] ) {
            return (string) $item['handle'];
        }
        $name = trim( (string) $item['author_name'] );
        if ( '' !== $name ) {
            return 'name:' . ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name ) );
        }
        return 'post:' . $item['key'];
    }

    /**
     * Fingerprints of a post's story: the links it shares (other than the
     * platforms themselves) and the start of its text, normalised.
     *
     * @return string[]
     */
    public static function fingerprints( array $item ): array {
        $out  = [];
        $text = (string) $item['text'];
        if ( preg_match_all( '#https?://[^\s]+#u', $text, $m ) ) {
            foreach ( $m[0] as $u ) {
                $host = strtolower( (string) parse_url( $u, PHP_URL_HOST ) );
                $host = (string) preg_replace( '/^(www\.|m\.)/', '', $host );
                if ( '' === $host || preg_match( '/(^|\.)(x|twitter|t|tiktok|instagram|facebook|fb)\.(com|co|me|watch)$/', $host ) ) {
                    continue;
                }
                $path  = rtrim( (string) parse_url( $u, PHP_URL_PATH ), '/' );
                $out[] = 'u:' . $host . strtolower( $path );
            }
        }
        $norm = preg_replace( '#https?://\S+#u', ' ', $text );
        $norm = preg_replace( '/[#@][\p{L}\p{N}_.]+/u', ' ', (string) $norm );
        $norm = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', (string) $norm );
        $norm = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $norm ) : strtolower( (string) $norm ) );
        if ( self::strlen( $norm ) >= 25 ) {
            $out[] = 't:' . ( function_exists( 'mb_substr' ) ? mb_substr( $norm, 0, 60 ) : substr( $norm, 0, 60 ) );
        }
        return $out;
    }

    /** True when the post shares a fingerprint with one already kept; otherwise records it. */
    private static function seen_before( array $item, array &$seen ): bool {
        $fps = self::fingerprints( $item );
        foreach ( $fps as $fp ) {
            if ( isset( $seen[ $fp ] ) && $seen[ $fp ] !== $item['key'] ) {
                return true;
            }
        }
        foreach ( $fps as $fp ) {
            $seen[ $fp ] = $item['key'];
        }
        return false;
    }

    /** Matches one of the excluded words (spam / off-topic). */
    public static function is_excluded( array $item, array $words ): bool {
        if ( empty( $words ) ) {
            return false;
        }
        $hay = ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $item['text'] . ' ' . $item['author_name'] . ' ' . $item['handle'] ) : strtolower( $item['text'] . ' ' . $item['author_name'] . ' ' . $item['handle'] ) );
        foreach ( $words as $w ) {
            $w = function_exists( 'mb_strtolower' ) ? mb_strtolower( $w ) : strtolower( $w );
            if ( '' !== $w && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/u', $hay ) ) {
                return true;
            }
        }
        return false;
    }

    public static function engagement( array $item ): int {
        return (int) $item['likes'] + 2 * (int) $item['comments'] + 3 * (int) $item['shares'];
    }

    /**
     * Ranking within one platform: engagement (plus a little for views),
     * damped by age so a fresh post with traction beats an old big one.
     */
    public static function score( array $item, int $now ): float {
        $eng   = self::engagement( $item ) + (int) $item['views'] / 100;
        $age_h = max( 0, ( $now - (int) $item['created_ts'] ) / 3600 );
        return $eng / pow( $age_h + 2, 1.3 );
    }

    /**
     * Engagement bar for Greek-language posts: local discussion draws far fewer
     * likes than global English posts, so it gets half the bar (the Greek X
     * search asks for the same).
     */
    public static function min_engagement_for_greek( int $min ): int {
        return $min > 0 ? max( 1, (int) floor( $min / 2 ) ) : 0;
    }

    /**
     * Engagement bar for one candidate: the Greek bar when its text is Greek.
     */
    public static function min_engagement_for( array $item, int $min ): int {
        return preg_match( '/\p{Greek}/u', (string) ( $item['text'] ?? '' ) ) ? self::min_engagement_for_greek( $min ) : $min;
    }

    /**
     * Issue #192: how many posts one account may have in a roundup (per platform).
     * Filter: `presshub_social_trending_max_per_account` (default 1).
     */
    public static function max_per_account(): int {
        $n = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_trending_max_per_account() : 1;
        $n = function_exists( 'apply_filters' ) ? (int) apply_filters( 'presshub_social_trending_max_per_account', $n ) : $n;
        return max( 1, $n );
    }

    /**
     * Issue #192: local hour (site time zone) before which a new roundup is held as a
     * scheduled post, so its social posts go out when people read rather than at 04:00.
     * Filter: `presshub_social_trending_share_after_hour` (default 8, 0 turns it off).
     */
    public static function share_after_hour(): int {
        $h = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_trending_share_after_hour() : 8;
        $h = function_exists( 'apply_filters' ) ? (int) apply_filters( 'presshub_social_trending_share_after_hour', $h ) : $h;
        return max( 0, min( 23, $h ) );
    }

    /**
     * Is $now inside the roundup's quiet hours (site time zone)? The window runs
     * from the "from" hour up to, not including, the "until" hour and may wrap
     * past midnight (22 → 6). Equal hours mean no quiet hours.
     */
    public static function in_quiet_hours( int $now ): bool {
        $from  = PressHub_AI_Settings_Storage::get_trending_quiet_from_hour();
        $until = PressHub_AI_Settings_Storage::get_trending_quiet_until_hour();
        if ( $from === $until ) {
            return false;
        }
        $hour = (int) ( function_exists( 'wp_date' ) ? wp_date( 'G', $now ) : gmdate( 'G', $now ) );
        return $from < $until ? ( $hour >= $from && $hour < $until ) : ( $hour >= $from || $hour < $until );
    }

    /**
     * When a new roundup created at $now should go live, as [ local 'Y-m-d H:i:s', GMT
     * 'Y-m-d H:i:s' ], or null to publish now.
     *
     * @return array{0:string,1:string}|null
     */
    public static function held_publish_time( int $now ): ?array {
        $hour = self::share_after_hour();
        if ( $hour <= 0 ) {
            return null;
        }
        $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
        if ( ! $tz instanceof DateTimeZone ) {
            $offset = (float) get_option( 'gmt_offset', 0 );
            $tz     = new DateTimeZone( sprintf( '%+03d:%02d', (int) $offset, (int) round( abs( $offset - (int) $offset ) * 60 ) ) );
        }
        $local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
        if ( (int) $local->format( 'G' ) >= $hour ) {
            return null;
        }
        $at = $local->setTime( $hour, 0, 0 );
        return [ $at->format( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', $at->getTimestamp() ) ];
    }

    /**
     * Pick the posts for the roundup: best per platform, then mixed in turn.
     *
     * @return array[]
     */
    public static function select( array $candidates, int $now ): array {
        $min      = PressHub_AI_Settings_Storage::get_social_trending_min_engagement();
        $max      = PressHub_AI_Settings_Storage::get_social_trending_max_items();
        $per      = PressHub_AI_Settings_Storage::get_social_trending_max_per_platform();
        $max_age  = PressHub_AI_Settings_Storage::get_social_trending_max_age_hours() * 3600;
        $excluded = PressHub_AI_Settings_Storage::get_social_trending_excluded_words();
        $seen     = [];
        $by       = [];
        $per_auth = [];
        foreach ( $candidates as $c ) {
            if ( ! is_array( $c ) || $now - (int) $c['created_ts'] > $max_age ) {
                continue;
            }
            if ( self::engagement( $c ) < self::min_engagement_for( $c, $min ) || self::is_excluded( $c, $excluded ) ) {
                continue;
            }
            $c['score'] = self::score( $c, $now );
            $by[ $c['platform'] ][] = $c;
        }
        foreach ( $by as $p => $list ) {
            usort( $list, static function ( $a, $b ) {
                return $b['score'] <=> $a['score'];
            } );
            // At most max_per_account() posts by the same account (one by default, #192),
            // so one busy account cannot fill the roundup.
            $kept = [];
            foreach ( $list as $c ) {
                if ( self::seen_before( $c, $seen ) ) {
                    continue;
                }
                $ak = $p . ':' . self::account_key( $c );
                $per_auth[ $ak ] = ( $per_auth[ $ak ] ?? 0 ) + 1;
                if ( $per_auth[ $ak ] > self::max_per_account() ) {
                    continue;
                }
                $kept[] = $c;
                if ( count( $kept ) >= $per ) {
                    break;
                }
            }
            $by[ $p ] = $kept;
        }
        // Platform with the strongest top post goes first in each round.
        $order = array_keys( $by );
        usort( $order, static function ( $a, $b ) use ( $by ) {
            return ( $by[ $b ][0]['score'] ?? 0 ) <=> ( $by[ $a ][0]['score'] ?? 0 );
        } );
        $out      = [];
        $seen_out = [];
        for ( $i = 0; count( $out ) < $max; $i++ ) {
            $added = false;
            foreach ( $order as $p ) {
                if ( isset( $by[ $p ][ $i ] ) && count( $out ) < $max ) {
                    $added = true;
                    // The same story posted on two platforms is shown once (the first, stronger one).
                    if ( self::seen_before( $by[ $p ][ $i ], $seen_out ) ) {
                        continue;
                    }
                    $out[] = $by[ $p ][ $i ];
                }
            }
            if ( ! $added ) {
                break;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Instagram (Graph API Hashtag Search)
     * ---------------------------------------------------------------- */

    private static function ig_user_id(): string {
        return class_exists( 'PressHub_Social_Settings_Storage' ) ? trim( (string) PressHub_Social_Settings_Storage::get_instagram_account_id() ) : '';
    }

    private static function meta_token(): string {
        return class_exists( 'PressHub_Social_Settings_Storage' ) ? trim( (string) PressHub_Social_Settings_Storage::get_meta_access_token() ) : '';
    }

    private static function http_json( string $url ) {
        $res = is_callable( self::$http_get ) ? call_user_func( self::$http_get, $url ) : wp_remote_get( $url, [ 'timeout' => 20 ] );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            $msg = is_array( $json ) && isset( $json['error']['message'] ) ? (string) $json['error']['message'] : 'HTTP ' . $code;
            return new WP_Error( 'graph_error', $msg );
        }
        return $json;
    }

    /**
     * Top recent media for the configured hashtags.
     *
     * Hashtag ids are cached: the Graph API allows 30 different hashtags per
     * account in 7 days, and the lookup is what counts.
     *
     * @return array[] Normalised candidates.
     */
    public static function fetch_instagram( int $now ): array {
        $user  = self::ig_user_id();
        $token = self::meta_token();
        if ( '' === $user || '' === $token ) {
            return [];
        }
        $base   = 'https://graph.facebook.com/' . PressHub_Social_Meta_API_Client::GRAPH_API_VERSION;
        $ids    = get_option( self::OPTION_IG_TAG_IDS, [] );
        $ids    = is_array( $ids ) ? $ids : [];
        $out    = [];
        $fields = 'id,caption,media_type,comments_count,like_count,permalink,timestamp,media_url,thumbnail_url';
        foreach ( PressHub_AI_Settings_Storage::get_social_trending_hashtags() as $tag ) {
            if ( empty( $ids[ $tag ] ) ) {
                $found = self::http_json( $base . '/ig_hashtag_search?user_id=' . rawurlencode( $user ) . '&q=' . rawurlencode( $tag ) . '&access_token=' . rawurlencode( $token ) );
                if ( is_wp_error( $found ) || empty( $found['data'][0]['id'] ) ) {
                    self::log( 'warning', sprintf( 'Social trending: Instagram hashtag #%s lookup failed: %s', $tag, is_wp_error( $found ) ? $found->get_error_message() : 'not found' ) );
                    continue;
                }
                $ids[ $tag ] = (string) $found['data'][0]['id'];
                update_option( self::OPTION_IG_TAG_IDS, $ids, false );
            }
            $media = self::http_json( $base . '/' . rawurlencode( $ids[ $tag ] ) . '/top_media?user_id=' . rawurlencode( $user ) . '&fields=' . $fields . '&limit=50&access_token=' . rawurlencode( $token ) );
            if ( is_wp_error( $media ) ) {
                self::log( 'warning', sprintf( 'Social trending: Instagram #%s top media failed: %s', $tag, $media->get_error_message() ) );
                continue;
            }
            foreach ( (array) ( $media['data'] ?? [] ) as $m ) {
                if ( ! is_array( $m ) ) {
                    continue;
                }
                $type = strtoupper( (string) ( $m['media_type'] ?? '' ) );
                $item = self::normalize_item( [
                    'platform'    => 'instagram',
                    'id'          => (string) ( $m['id'] ?? '' ),
                    'url'         => (string) ( $m['permalink'] ?? '' ),
                    'text'        => (string) ( $m['caption'] ?? '' ),
                    'created_at'  => (string) ( $m['timestamp'] ?? '' ),
                    'author_name' => '',
                    'media'       => [ 'type' => 'VIDEO' === $type ? 'video' : 'image' ],
                    'thumbnail'   => (string) ( $m['thumbnail_url'] ?? ( 'VIDEO' === $type ? '' : ( $m['media_url'] ?? '' ) ) ),
                    'metrics'     => [ 'likes' => $m['like_count'] ?? 0, 'comments' => $m['comments_count'] ?? 0 ],
                ], $now );
                if ( null !== $item ) {
                    $out[] = $item;
                }
            }
        }
        self::log( 'info', sprintf( 'Social trending: %d recent Instagram post(s) from %d hashtag(s).', count( $out ), count( PressHub_AI_Settings_Storage::get_social_trending_hashtags() ) ) );
        return $out;
    }

    /* ------------------------------------------------------------------
     * Publishing
     * ---------------------------------------------------------------- */

    /**
     * Rank the collected posts and write the roundup post. Closes the run.
     *
     * @return int Post id (0 when nothing was published).
     */
    public static function finalize_run( ?int $now = null, string $note = '' ): int {
        $now        = $now ?? time();
        $state      = self::get_state();
        $candidates = get_option( self::OPTION_CANDIDATES, [] );
        $candidates = is_array( $candidates ) ? $candidates : [];
        $picked     = self::select( $candidates, $now );
        $post_id    = 0;

        if ( empty( $picked ) ) {
            $note = trim( 'No post passed the filters (age and minimum engagement), so the roundup was not changed. ' . $note );
            self::log( 'info', sprintf( 'Social trending: run %s found %d candidate(s), none selected.', $state['run_id'], count( $candidates ) ) );
        } else {
            $res = self::upsert_post( $picked, $now, (string) $state['run_id'] );
            if ( is_wp_error( $res ) ) {
                $note = trim( 'Could not save the post: ' . $res->get_error_message() . ' ' . $note );
                self::log( 'error', 'Social trending: ' . $note );
            } else {
                $post_id = (int) $res;
                self::log( 'info', sprintf( 'Social trending: run %s published %d post(s) into #%d.', $state['run_id'], count( $picked ), $post_id ) );
            }
        }

        if ( ! empty( $picked ) ) {
            self::add_to_briefing_pool( $picked, $now );
        }

        $state['open']           = false;
        $state['worker_pending'] = false;
        $state['last_finished']  = $now;
        $state['last_count']     = count( $picked );
        $state['last_note']      = $note;
        if ( $post_id > 0 ) {
            $state['last_post_id'] = $post_id;
        }
        self::save_state( $state );
        update_option( self::OPTION_CANDIDATES, [], false );
        return $post_id;
    }

    /* ------------------------------------------------------------------
     * Briefing: trending posts as one harvest item
     * ---------------------------------------------------------------- */

    /**
     * Keep a run's picks for the next briefing edition. A post already in the
     * pool keeps its used marker; its numbers and score are refreshed.
     */
    public static function add_to_briefing_pool( array $picked, int $now ): void {
        $pool = get_option( self::OPTION_BRIEFING_POOL, [] );
        $pool = is_array( $pool ) ? $pool : [];
        foreach ( $picked as $it ) {
            if ( ! is_array( $it ) || empty( $it['key'] ) ) {
                continue;
            }
            $key           = (string) $it['key'];
            $it['used_in'] = (string) ( $pool[ $key ]['used_in'] ?? '' );
            $it['pooled']  = (int) ( $pool[ $key ]['pooled'] ?? $now );
            $pool[ $key ]  = $it;
        }
        foreach ( $pool as $key => $it ) {
            if ( $now - (int) ( $it['created_ts'] ?? 0 ) > self::BRIEFING_POOL_TTL ) {
                unset( $pool[ $key ] );
            }
        }
        update_option( self::OPTION_BRIEFING_POOL, $pool, false );
    }

    /**
     * Short names of the news sites in the briefing source list (philenews,
     * sigmalive, ...), to recognise their social accounts.
     *
     * @return string[]
     */
    public static function news_site_names(): array {
        $names = [];
        foreach ( PressHub_AI_Settings_Storage::get_briefing_sources() as $src ) {
            if ( ! in_array( (string) ( $src['type'] ?? '' ), [ 'text_news', 'rss_feed' ], true ) ) {
                continue;
            }
            $host = strtolower( (string) parse_url( (string) ( $src['url'] ?? '' ), PHP_URL_HOST ) );
            $host = (string) preg_replace( '/^(www|m|en|el|gr)\./', '', $host );
            $name = (string) preg_replace( '/[^a-z0-9]/', '', explode( '.', $host )[0] ?? '' );
            if ( '' !== $name ) {
                $names[ $name ] = true;
            }
        }
        return array_keys( $names );
    }

    /**
     * Is this post by a news site in the source list? Its handle, without
     * punctuation, is the site's name or starts with it (omegalivecy for
     * omegalive.com.cy). Short names (bbc, rik) must match exactly or with
     * a cy suffix, so they do not catch unrelated accounts.
     */
    public static function is_news_account( array $item, array $site_names ): bool {
        $h = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $item['handle'] ?? '' ) ) );
        if ( '' === $h ) {
            return false;
        }
        foreach ( $site_names as $n ) {
            if ( $h === $n || $h === $n . 'cy' || ( strlen( $n ) >= 5 && 0 === strpos( $h, $n ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The trending posts for one briefing edition, as a single harvest item.
     * Picks the best unused posts from the pool, leaving out posts by monitored
     * accounts (they reach the harvest on their own) and by news sites in the
     * source list, and marks them used for this edition.
     *
     * @return array[] Zero or one article.
     */
    public static function take_for_briefing( string $date, string $edition, ?int $now = null ): array {
        if ( ! PressHub_AI_Settings_Storage::get_social_trending_briefing_enabled() ) {
            return [];
        }
        $now   = $now ?? time();
        $pool  = get_option( self::OPTION_BRIEFING_POOL, [] );
        $pool  = is_array( $pool ) ? $pool : [];
        $floor = $now - PressHub_AI_Settings_Storage::get_social_trending_max_age_hours() * 3600;
        $sites = self::news_site_names();
        $monitored = [];
        if ( class_exists( 'PressHub_AI_Social_Monitor' ) ) {
            foreach ( PressHub_AI_Social_Monitor::get_targets() as $t ) {
                $monitored[ $t['platform'] . ':' . strtolower( (string) $t['handle'] ) ] = true;
            }
        }

        $cands = [];
        foreach ( $pool as $key => $it ) {
            if ( '' !== (string) ( $it['used_in'] ?? '' ) || (int) ( $it['created_ts'] ?? 0 ) < $floor ) {
                continue;
            }
            if ( isset( $monitored[ $it['platform'] . ':' . strtolower( (string) $it['handle'] ) ] ) || self::is_news_account( $it, $sites ) ) {
                continue;
            }
            if ( '' === trim( (string) ( $it['text'] ?? '' ) ) ) {
                continue;
            }
            $it['score']   = self::score( $it, $now );
            $cands[ $key ] = $it;
        }
        if ( empty( $cands ) ) {
            return [];
        }
        uasort( $cands, static function ( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );
        $picked = array_slice( $cands, 0, PressHub_AI_Settings_Storage::get_social_trending_briefing_count(), true );
        if ( count( $cands ) > count( $picked ) ) {
            // Design principle: a limit that leaves content out says so.
            self::log( 'info', 'Social trending: the briefing count left out lower-scored posts.', [
                'picked'    => count( $picked ),
                'left_out'  => count( $cands ) - count( $picked ),
                'limit'     => PressHub_AI_Settings_Storage::get_social_trending_briefing_count(),
            ] );
        }
        foreach ( array_keys( $picked ) as $key ) {
            $pool[ $key ]['used_in'] = $date . '|' . $edition;
        }
        update_option( self::OPTION_BRIEFING_POOL, $pool, false );

        $state = self::get_state();
        return [ self::briefing_article( array_values( $picked ), (int) ( $state['last_post_id'] ?? 0 ), $now ) ];
    }

    /**
     * One harvest item that lists the trending posts.
     */
    public static function briefing_article( array $items, int $roundup_id, int $now ): array {
        $fmt   = static function ( int $ts ): string {
            return function_exists( 'wp_date' ) ? wp_date( 'j/n H:i', $ts ) : gmdate( 'j/n H:i', $ts );
        };
        $lines   = [];
        $lines[] = 'Δημοφιλείς αναρτήσεις για την Κύπρο στα μέσα κοινωνικής δικτύωσης, με βάση τις αντιδράσεις των χρηστών. Δείχνουν τι συζητά το κοινό: είναι απόψεις και ισχυρισμοί λογαριασμών, όχι επιβεβαιωμένες ειδήσεις. Αν χρησιμοποιηθούν, να παρουσιαστούν ως σύντομη αναφορά στη δημόσια συζήτηση, με απόδοση στον λογαριασμό.';
        $keys    = [];
        $cut     = [];
        foreach ( $items as $i => $it ) {
            $keys[] = (string) $it['key'];
            $label  = self::PLATFORM_LABELS[ $it['platform'] ] ?? $it['platform'];
            $name   = '' !== (string) $it['author_name'] ? (string) $it['author_name'] : (string) $it['handle'];
            $text   = trim( (string) preg_replace( '/\s+/u', ' ', (string) $it['text'] ) );
            if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) > self::BRIEFING_TEXT_CHARS ) {
                $cut[] = '@' . $it['handle'] . ' (' . ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) . ')';
                $text = ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::BRIEFING_TEXT_CHARS ) : substr( $text, 0, self::BRIEFING_TEXT_CHARS ) ) . '…';
            }
            $lines[] = sprintf(
                "%d. %s (@%s) στο %s, %s · %d likes, %d σχόλια, %d κοινοποιήσεις\n%s\n%s",
                $i + 1,
                $name,
                $it['handle'],
                $label,
                $fmt( (int) $it['created_ts'] ),
                (int) $it['likes'],
                (int) $it['comments'],
                (int) $it['shares'],
                $text,
                $it['url']
            );
        }
        $content = implode( "\n\n", $lines );
        $url     = $roundup_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $roundup_id ) : '';
        if ( '' === $url ) {
            $url = (string) ( $items[0]['url'] ?? '' );
        }
        if ( $cut ) {
            self::log( 'info', sprintf( 'Social trending: %d post text(s) cut to %d characters for the briefing.', count( $cut ), self::BRIEFING_TEXT_CHARS ), [ 'posts' => $cut ] );
        }
        return [
            'id'             => 'st_' . substr( md5( implode( ',', $keys ) ), 0, 12 ),
            'url'            => $url,
            'title'          => 'Τι συζητά η Κύπρος στα social',
            'content'        => $content,
            'source'         => 'Μέσα κοινωνικής δικτύωσης · δημοφιλείς αναρτήσεις',
            'source_type'    => 'social_post',
            'tier'           => 'social_post',
            'platform'       => 'mixed',
            'handle'         => '',
            'media_type'     => 'none',
            'has_transcript' => false,
            'published_at'   => gmdate( 'c', $now ),
            'char_count'     => function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content ),
            'is_manual'      => false,
            'is_social_post' => true,
            'is_trending'    => true,
            'trending_keys'  => $keys,
            'harvested_at'   => gmdate( 'c', $now ),
        ];
    }

    /**
     * Which post this run writes into.
     */
    public static function post_key( int $now, string $run_id ): string {
        switch ( PressHub_AI_Settings_Storage::get_social_trending_post_mode() ) {
            case 'rolling':
                return 'rolling';
            case 'each':
                return 'run:' . $run_id;
            default:
                return 'day:' . ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $now ) : gmdate( 'Y-m-d', $now ) );
        }
    }

    public static function ensure_category(): int {
        $name = PressHub_AI_Settings_Storage::get_social_trending_category();
        if ( function_exists( 'get_term_by' ) ) {
            $term = get_term_by( 'name', $name, 'category' );
            if ( $term && ! is_wp_error( $term ) ) {
                return (int) $term->term_id;
            }
        }
        $slug = sanitize_title( $name );
        $slug = '' !== $slug ? $slug : 'social-trending';
        if ( function_exists( 'get_term_by' ) ) {
            $term = get_term_by( 'slug', $slug, 'category' );
            if ( $term && ! is_wp_error( $term ) ) {
                return (int) $term->term_id;
            }
        }
        if ( ! function_exists( 'wp_insert_term' ) ) {
            return 0;
        }
        $res = wp_insert_term( $name, 'category', [ 'slug' => $slug ] );
        if ( is_wp_error( $res ) ) {
            $existing = $res->get_error_data( 'term_exists' );
            return is_numeric( $existing ) ? (int) $existing : 0;
        }
        return (int) ( $res['term_id'] ?? 0 );
    }

    private static function post_author(): int {
        if ( function_exists( 'get_users' ) ) {
            $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] );
            if ( ! empty( $admins ) ) {
                return (int) ( is_object( $admins[0] ) ? $admins[0]->ID : $admins[0] );
            }
        }
        return 1;
    }

    public static function build_title( int $now ): string {
        $tpl = PressHub_AI_Settings_Storage::get_social_trending_title();
        $fmt = static function ( string $f ) use ( $now ) {
            return function_exists( 'wp_date' ) ? wp_date( $f, $now ) : gmdate( $f, $now );
        };
        return strtr( $tpl, [
            '{date}' => $fmt( 'j/n/Y' ),
            '{time}' => $fmt( 'H:i' ),
        ] );
    }

    /**
     * @return int|WP_Error
     */
    /** Whether the QA gate is holding this roundup in Pending Review. */
    private static function held_by_gate( int $post_id, $post ): bool {
        if ( ! class_exists( 'PressHub_AI_Publish_Gate' ) || 'pending' !== ( $post->post_status ?? '' ) ) {
            return false;
        }
        if ( '' === (string) get_post_meta( $post_id, PressHub_AI_Publish_Gate::META_TARGET_STATUS, true ) ) {
            return false;
        }
        $review = get_post_meta( $post_id, PressHub_AI_Publish_Gate::META_REVIEW, true );
        return is_array( $review ) && PressHub_AI_Publish_Gate::holds( $review );
    }

    public static function upsert_post( array $items, int $now, string $run_id ) {
        $key     = self::post_key( $now, $run_id );
        $map     = get_option( self::OPTION_POSTS, [] );
        $map     = is_array( $map ) ? $map : [];
        $title   = self::build_title( $now );
        $status  = PressHub_AI_Settings_Storage::get_social_trending_post_status();
        $items_meta = function_exists( 'wp_json_encode' ) ? wp_json_encode( $items ) : json_encode( $items );

        $existing = (int) ( $map[ $key ] ?? 0 );
        if ( $existing > 0 ) {
            self::maybe_release_stale_ai_wait( $existing, $now );
        }
        $post     = $existing > 0 ? get_post( $existing ) : null;
        $update   = $post && 'trash' !== ( $post->post_status ?? '' );
        $new_set  = true;
        if ( $update ) {
            $prev_items = json_decode( (string) get_post_meta( $existing, self::META_ITEMS, true ), true );
            $new_set    = self::summary_hash( $items ) !== self::summary_hash( is_array( $prev_items ) ? $prev_items : [] );
            // A roundup the gate is holding keeps the text it was reviewed on
            // until a new set of posts arrives, so "Publish anyway" still
            // matches (and no summary is asked for).
            if ( ! $new_set && self::held_by_gate( $existing, $post ) ) {
                return $existing;
            }
        }
        $summary  = self::summary_for( $items, $update ? $existing : 0 );
        $content  = self::build_content( $items, $now, $summary['text'], (array) ( $summary['notes'] ?? [] ) );
        if ( $update ) {
            // A new set of posts is not shown until the QA gate has read it.
            if ( $new_set && class_exists( 'PressHub_AI_Publish_Gate' ) && PressHub_AI_Publish_Gate::applies( 'trending' ) ) {
                PressHub_AI_Publish_Gate::hold_for_review( $existing );
            }
            $fresh_post = get_post( $existing );
            $res = wp_update_post( [
                'ID'           => $existing,
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => (string) ( $fresh_post->post_status ?? $post->post_status ),
            ], true );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            update_post_meta( $existing, self::META_ITEMS, $items_meta );
            self::save_summary_meta( $existing, $summary );
            self::maybe_set_featured_image( $existing, $items );
            // A new set of posts is checked again (whether or not the AI
            // summary is on); the numbers changing alone is not.
            if ( $new_set && ! self::release_waits_for_ai_image( $existing ) ) {
                self::release_through_gate( $existing );
            }
            return $existing;
        }

        $cat     = self::ensure_category();
        $postarr = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => 'post',
            'post_author'  => self::post_author(),
            'meta_input'   => [
                self::META_KEY   => $key,
                self::META_ITEMS => $items_meta,
            ],
        ];
        if ( $cat > 0 ) {
            $postarr['post_category'] = [ $cat ];
        }
        $held = 'publish' === $status ? self::held_publish_time( $now ) : null;
        if ( null !== $held ) {
            $postarr['post_status']   = 'future';
            $postarr['post_date']     = $held[0];
            $postarr['post_date_gmt'] = $held[1];
            self::log( 'info', sprintf( 'Social trending: roundup scheduled for %s (site time) so its social posts go out then.', $held[0] ) );
        }
        // QA gate: saved as pending and released once the summary and image are in place.
        if ( class_exists( 'PressHub_AI_Publish_Gate' ) ) {
            $postarr = PressHub_AI_Publish_Gate::prepare_insert( $postarr, 'trending' );
            // An AI image is drawn after this request: hold the roundup as pending even when the
            // gate does not check Trending, so it and its social posts go out with that image.
            if ( self::ai_image_planned() && in_array( $postarr['post_status'], [ 'publish', 'future' ], true ) ) {
                $postarr['meta_input'][ PressHub_AI_Publish_Gate::META_TARGET_STATUS ] = $postarr['post_status'];
                $postarr['post_status'] = 'pending';
            }
        }
        $id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        if ( (int) $id <= 0 ) {
            return new WP_Error( 'insert_failed', 'wp_insert_post returned 0.' );
        }
        $map[ $key ] = (int) $id;
        if ( count( $map ) > self::MAX_POST_KEYS ) {
            $map = array_slice( $map, -self::MAX_POST_KEYS, null, true );
        }
        update_option( self::OPTION_POSTS, $map, false );
        self::save_summary_meta( (int) $id, $summary );
        self::maybe_set_featured_image( (int) $id, $items );
        if ( ! self::release_waits_for_ai_image( (int) $id ) ) {
            self::release_through_gate( (int) $id );
        }
        return (int) $id;
    }

    /** The roundup's featured image will be an AI image (the source and the feature are on). */
    private static function ai_image_planned(): bool {
        return 'auto_generate' === PressHub_AI_Settings_Storage::get_social_trending_image_source() && PressHub_AI_Settings_Storage::get_image_feature_enabled();
    }

    /**
     * Watchdog: a roundup whose AI image job has not run within AI_IMAGE_MAX_WAIT
     * (WP-Cron off, the job killed, the plugin deactivated) is released with the
     * stand-in picture, so it never stays pending.
     */
    public static function maybe_release_stale_ai_wait( int $post_id, ?int $now = null ): bool {
        if ( ! self::release_waits_for_ai_image( $post_id ) ) {
            return false;
        }
        $now    = $now ?? time();
        $queued = (int) get_post_meta( $post_id, self::AI_IMAGE_META, true );
        if ( $queued > 0 && ( $now - $queued ) < self::AI_IMAGE_MAX_WAIT ) {
            return false;
        }
        if ( false === delete_post_meta( $post_id, self::AI_RELEASE_META ) ) {
            return false; // The job claimed it first.
        }
        update_post_meta( $post_id, self::AI_WATCHDOG_META, (string) $now );
        self::log( 'warning', 'Social trending: the AI roundup image did not arrive in time; releasing with the top post picture.', [ 'post_id' => $post_id ] );
        self::release_through_gate( $post_id );
        return true;
    }

    private static function released_by_watchdog( int $post_id ): bool {
        return '' !== (string) get_post_meta( $post_id, self::AI_WATCHDOG_META, true );
    }

    /** The watchdog over the latest roundups. */
    private static function release_stale_ai_waits( int $now ): void {
        $map = get_option( self::OPTION_POSTS, [] );
        foreach ( array_slice( is_array( $map ) ? array_values( $map ) : [], -30 ) as $post_id ) {
            self::maybe_release_stale_ai_wait( (int) $post_id, $now );
        }
    }

    /** True while the AI image job for this roundup has not run: the job releases the post. */
    private static function release_waits_for_ai_image( int $post_id ): bool {
        return function_exists( 'get_post_meta' ) && '1' === (string) get_post_meta( $post_id, self::AI_RELEASE_META, true );
    }

    /** Legal check of the summary and quoted posts plus the measured SEO checks; then the configured status. */
    private static function release_through_gate( int $post_id ): void {
        if ( ! class_exists( 'PressHub_AI_Publish_Gate' ) ) {
            return;
        }
        try {
            PressHub_AI_Publish_Gate::release( $post_id, 'trending', 'trending_roundup' );
        } catch ( Throwable $t ) {
            self::log( 'warning', 'Social trending: the QA gate could not run: ' . $t->getMessage() );
        }
    }

    /* ------------------------------------------------------------------
     * AI summary at the top of the roundup
     * ---------------------------------------------------------------- */

    /** @var callable|null fn( string $sys, string $user ): string|WP_Error — AI override for tests. */
    public static $summary_caller = null;

    /** Same posts in the same order = same summary; the numbers changing does not call the AI again. */
    public static function summary_hash( array $items ): string {
        return md5( implode( ',', array_map( static function ( $it ) {
            return (string) ( $it['key'] ?? '' );
        }, array_values( $items ) ) ) );
    }

    /**
     * The summary for these posts: reused from the post when its posts did
     * not change, otherwise written by the AI. Empty when switched off or
     * when the AI fails (the roundup is published without it).
     *
     * @return array{hash:string,text:string,fresh:bool}
     */
    public static function summary_for( array $items, int $post_id ): array {
        $hash  = self::summary_hash( $items );
        $facts = self::fact_check_signature( $items );
        $none  = [ 'hash' => $hash, 'text' => '', 'notes' => [], 'facts' => $facts, 'fresh' => false ];
        if ( empty( $items ) || ! PressHub_AI_Settings_Storage::get_social_trending_summary_enabled() ) {
            return $none;
        }
        if ( $post_id > 0 ) {
            $prev = get_post_meta( $post_id, self::META_SUMMARY, true );
            // Reused while the posts and the fact checks they match stay the same.
            if ( is_array( $prev ) && $hash === ( $prev['hash'] ?? '' ) && $facts === (string) ( $prev['facts'] ?? '' ) && '' !== (string) ( $prev['text'] ?? '' ) ) {
                return [ 'hash' => $hash, 'text' => (string) $prev['text'], 'notes' => is_array( $prev['notes'] ?? null ) ? $prev['notes'] : [], 'facts' => $facts, 'fresh' => false ];
            }
        }
        $text = self::generate_summary( $items, $post_id );
        if ( is_wp_error( $text ) ) {
            self::log( 'warning', 'Social trending: the summary was not written, the roundup is saved without it: ' . $text->get_error_message() );
            return $none;
        }
        return [ 'hash' => $hash, 'text' => $text, 'notes' => self::$last_summary_notes, 'facts' => $facts, 'fresh' => true ];
    }

    /**
     * Which fact checks in the editorial memory these posts' texts match ('' when
     * none): a summary is written again when this changes, so a fact check
     * recorded after the roundup was first written still gets noted.
     */
    public static function fact_check_signature( array $items ): string {
        if ( ! class_exists( 'PressHub_AI_Fact_Checks' ) || ! PressHub_AI_Fact_Checks::is_enabled() ) {
            return '';
        }
        $text = implode( "\n", array_map( static function ( $it ) {
            return (string) ( $it['text'] ?? '' );
        }, array_values( $items ) ) );
        $hits = PressHub_AI_Fact_Checks::find_in_text( $text );
        if ( empty( $hits ) ) {
            return '';
        }
        return md5( implode( '|', array_map( static function ( $fc ) {
            return PressHub_AI_Fact_Checks::fold( $fc['claim'] ) . '#' . $fc['verdict'];
        }, $hits ) ) );
    }

    private static function save_summary_meta( int $post_id, array $summary ): void {
        if ( $post_id <= 0 || empty( $summary['fresh'] ) || '' === (string) $summary['text'] ) {
            return;
        }
        update_post_meta( $post_id, self::META_SUMMARY, [
            'hash'         => (string) $summary['hash'],
            'text'         => (string) $summary['text'],
            'notes'        => (array) ( $summary['notes'] ?? [] ),
            'facts'        => (string) ( $summary['facts'] ?? '' ),
            'generated_at' => gmdate( 'c' ),
        ] );
    }

    /**
     * The posts as the prompt lists them: rank, platform, author, numbers, text.
     * Each post text cut to SUMMARY_POST_TEXT_CHARS is noted for the summary
     * call (social_trending_summary).
     */
    public static function summary_posts_text( array $items ): string {
        $cuts = class_exists( 'PressHub_AI_Knowledge_Cuts' );
        if ( $cuts ) {
            PressHub_AI_Knowledge_Cuts::forget_material( 'social_trending_summary' );
        }
        $lines = [];
        foreach ( array_values( $items ) as $i => $it ) {
            $label  = self::PLATFORM_LABELS[ $it['platform'] ] ?? (string) $it['platform'];
            $author = '' !== (string) $it['author_name'] ? (string) $it['author_name'] : ( '' !== (string) $it['handle'] ? '@' . $it['handle'] : $label );
            $full   = self::strlen( trim( (string) preg_replace( '/\s+/u', ' ', (string) $it['text'] ) ) );
            $text   = self::excerpt( (string) $it['text'], self::SUMMARY_POST_TEXT_CHARS );
            if ( $cuts && $full > self::SUMMARY_POST_TEXT_CHARS ) {
                // Kept: the text sent, without the closing "…".
                /* translators: 1: rank, 2: author */
                PressHub_AI_Knowledge_Cuts::note_material( 'social_trending_summary', sprintf( __( 'Trending post %1$d (%2$s)', 'presshub-ai-editor' ), $i + 1, $author ), 'clipped', self::SUMMARY_POST_TEXT_CHARS, $full, self::strlen( $text ) - 1 );
            }
            $lines[] = sprintf(
                '%d. [%s] %s (%s likes/reactions, %s comments, %s shares): %s',
                $i + 1,
                $label,
                $author,
                (int) $it['likes'],
                (int) $it['comments'],
                (int) $it['shares'],
                '' !== $text ? $text : '(video or image without text)'
            );
        }
        return implode( "\n", $lines );
    }

    /**
     * Ask the social AI provider for the summary paragraph.
     *
     * @return string|WP_Error
     */
    public static function generate_summary( array $items, int $post_id = 0 ) {
        try {
            $template = class_exists( 'PressHub_AI_Prompt_Loader' ) ? (string) PressHub_AI_Prompt_Loader::load( 'social_trending_summary.txt' ) : '';
        } catch ( Throwable $t ) {
            $template = '';
        }
        if ( '' === trim( $template ) ) {
            return new WP_Error( 'missing_template', 'The trending summary prompt (social_trending_summary.txt) is missing.' );
        }
        $site = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
        $user = strtr( $template, [
            '{site_name}' => '' !== $site ? $site : 'PressHub.Cy',
            '{hours}'     => (string) PressHub_AI_Settings_Storage::get_social_trending_max_age_hours(),
            '{posts}'     => self::summary_posts_text( $items ),
        ] );
        $sys = 'You are a careful Greek-language news editor. You reply with a single valid JSON object and nothing else.';
        self::$last_summary_notes = [];
        $refs = [];

        if ( is_callable( self::$summary_caller ) ) {
            // Tests: add the fact checks here, as call_provider() does.
            if ( class_exists( 'PressHub_AI_Fact_Checks' ) ) {
                $sys  = PressHub_AI_Fact_Checks::apply( $sys, $user . "\n" . $sys, 'social_trending_summary' );
                $refs = (array) ( PressHub_AI_Fact_Checks::last_applied()['refs'] ?? [] );
                PressHub_AI_Fact_Checks::clear_last_applied();
            }
            $raw = call_user_func( self::$summary_caller, $sys, $user );
        } else {
            if ( ! class_exists( 'PressHub_AI_API_Client' ) || ! class_exists( 'PressHub_Social_AI_Copywriter' ) ) {
                self::forget_summary_cuts();
                return new WP_Error( 'missing_api_client', 'The AI client is not loaded.' );
            }
            $config = PressHub_Social_AI_Copywriter::resolve_ai_provider_config();
            if ( is_wp_error( $config ) ) {
                self::forget_summary_cuts();
                return $config;
            }
            $client = null;
            $t0     = PressHub_AI_API_Client::begin_generation_record();
            try {
                $client = new PressHub_AI_API_Client( $config );
                $client->set_action( 'social_trending_summary' );
                $client->suppress_provider_prompt_log = true;
                $raw = $client->call_provider( $sys, $user, true, [], 0.3, [ 'purpose' => 'social_trending_summary' ] );
                // The fact checks call_provider() added, before the record consumes them.
                $refs = class_exists( 'PressHub_AI_Fact_Checks' ) ? (array) ( PressHub_AI_Fact_Checks::last_applied()['refs'] ?? [] ) : [];
            } catch ( Throwable $t ) {
                $raw = new WP_Error( 'ai_dispatch_exception', $t->getMessage() );
            }
            if ( null !== $client ) {
                $client->suppress_provider_prompt_log = false;
                PressHub_AI_API_Client::finish_generation_record( $t0, 'generic', $client, $sys, $user, $raw, [
                    'post_id' => $post_id,
                    'config'  => [ 'action' => 'social_trending_summary' ],
                ] );
            }
        }
        self::forget_summary_cuts();
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }
        $text = self::parse_summary( (string) $raw );
        if ( ! is_wp_error( $text ) ) {
            self::$last_summary_notes = self::parse_fact_check_notes( (string) $raw, $refs, $items );
            if ( ! empty( self::$last_summary_notes ) ) {
                self::log( 'info', sprintf( 'Social trending: %d post(s) noted with a fact check from the editorial memory.', count( self::$last_summary_notes ) ), [ 'posts' => array_keys( self::$last_summary_notes ) ] );
            }
        }
        return $text;
    }

    /** Notes of the last generate_summary() call: item key => {text, url}. */
    public static array $last_summary_notes = [];

    /**
     * The posts the summary reply says repeat a fact check
     * ({"fact_checks":[{"post":1,"check":"FC1"}]}), as notes keyed by the
     * post's item key. Only fact checks that were sent count (an unknown
     * reference or post number is dropped), and the note is written from
     * the stored fact check, never from the model's words.
     *
     * @param array<string,array> $refs FC reference => fact check, as sent.
     * @return array<string,array{text:string,url:string}>
     */
    public static function parse_fact_check_notes( string $raw, array $refs, array $items ): array {
        if ( empty( $refs ) || ! class_exists( 'PressHub_AI_Fact_Checks' ) ) {
            return [];
        }
        $data  = class_exists( 'PressHub_AI_SEO' ) ? PressHub_AI_SEO::decode_json_object( $raw ) : json_decode( trim( $raw ), true );
        $list  = is_array( $data ) && is_array( $data['fact_checks'] ?? null ) ? $data['fact_checks'] : [];
        $items = array_values( $items );
        $notes = [];
        foreach ( $list as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $n   = (int) ( $row['post'] ?? 0 );
            $ref = strtoupper( trim( (string) ( $row['check'] ?? '' ) ) );
            if ( $n < 1 || $n > count( $items ) || ! isset( $refs[ $ref ] ) ) {
                continue;
            }
            $key = (string) ( $items[ $n - 1 ]['key'] ?? '' );
            if ( '' === $key || isset( $notes[ $key ] ) ) {
                continue;
            }
            $fc            = $refs[ $ref ];
            $notes[ $key ] = [
                'text' => PressHub_AI_Fact_Checks::note_text( $fc ),
                'url'  => (string) ( $fc['source_url'] ?? '' ),
            ];
        }
        return $notes;
    }

    /**
     * The summary text from the model's reply: JSON {"summary": "..."}, tags
     * and links stripped, whitespace collapsed, capped.
     *
     * @return string|WP_Error
     */
    public static function parse_summary( string $raw ) {
        $data = class_exists( 'PressHub_AI_SEO' ) ? PressHub_AI_SEO::decode_json_object( $raw ) : json_decode( trim( $raw ), true );
        $text = is_array( $data ) && is_string( $data['summary'] ?? null ) ? $data['summary'] : '';
        $text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text );
        $text = (string) preg_replace( '#https?://\S+#u', '', $text );
        $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
        if ( '' === $text ) {
            return new WP_Error( 'summary_empty', 'The AI reply had no summary.' );
        }
        return self::excerpt( $text, self::SUMMARY_MAX_CHARS );
    }

    private static function fmt_count( int $n ): string {
        if ( $n >= 1000000 ) {
            return rtrim( rtrim( number_format( $n / 1000000, 1, ',', '' ), '0' ), ',' ) . 'M';
        }
        if ( $n >= 10000 ) {
            return rtrim( rtrim( number_format( $n / 1000, 1, ',', '' ), '0' ), ',' ) . 'K';
        }
        return number_format( $n, 0, ',', '.' );
    }

    private static function ago( int $ts, int $now ): string {
        $min = max( 1, (int) floor( ( $now - $ts ) / 60 ) );
        if ( $min < 60 ) {
            return sprintf( 'πριν από %d λεπτά', $min );
        }
        $h = (int) floor( $min / 60 );
        return 1 === $h ? 'πριν από 1 ώρα' : sprintf( 'πριν από %d ώρες', $h );
    }

    /** @var callable|null fn( string $url ): bool — oEmbed availability override for tests. */
    public static $oembed_check = null;

    /** @var callable|null fn( string $image_url, int $post_id, string $desc ): int|WP_Error — sideload override for tests. */
    public static $sideload = null;

    /** @var callable|null fn( string $content, string $title, array $template, int $post_id ): int|WP_Error — AI featured image override for tests. */
    public static $ai_image = null;

    /** TikTok photo image URLs whose copy failed in this request, so one roundup build downloads each at most once. */
    private static $photo_failed = [];

    /** @var array<string, bool> oEmbed results within one request. */
    private static $oembed_cache = [];

    /**
     * Can WordPress embed this X / TikTok link right now? Checked when the post
     * is written, so a post whose oEmbed fails gets a readable card instead of
     * a bare link. Without wp_oembed_get (tests) the embed is assumed to work.
     */
    private static function oembed_ok( string $url ): bool {
        if ( is_callable( self::$oembed_check ) ) {
            return (bool) call_user_func( self::$oembed_check, $url );
        }
        if ( isset( self::$oembed_cache[ $url ] ) ) {
            return self::$oembed_cache[ $url ];
        }
        if ( function_exists( 'wp_oembed_get' ) ) {
            $ok = false !== wp_oembed_get( $url );
        } else {
            $ok = true;
        }
        return self::$oembed_cache[ $url ] = $ok;
    }

    /** The summary call has run (or will not): its post cuts are dropped. */
    private static function forget_summary_cuts(): void {
        if ( class_exists( 'PressHub_AI_Knowledge_Cuts' ) ) {
            PressHub_AI_Knowledge_Cuts::forget_material( 'social_trending_summary' );
        }
    }

    private static function excerpt( string $text, int $max = 400 ): string {
        $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
        if ( self::strlen( $text ) > $max ) {
            $text = rtrim( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max ) ) . '…';
        }
        return $text;
    }

    /** Readable card (quote + link) used for Instagram/Facebook fallbacks and failed oEmbeds. */
    private static function quote_block( array $it ): string {
        $label = self::PLATFORM_LABELS[ $it['platform'] ];
        $text  = self::excerpt( (string) $it['text'] );
        $quote = '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '';
        $cite  = '<cite><a href="' . esc_url( $it['url'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( sprintf( 'Δείτε την ανάρτηση στο %s', $label ) ) . '</a></cite>';
        return "<!-- wp:quote {\"className\":\"presshub-trending-fallback\"} -->\n<blockquote class=\"wp-block-quote presshub-trending-fallback\">" . $quote . $cite . "</blockquote>\n<!-- /wp:quote -->";
    }

    /** facebook.com/<digits> with nothing else: no post or video the plugins can embed. */
    public static function is_bare_facebook_url( string $url ): bool {
        return 1 === preg_match( '#^https://(www\.|m\.|web\.)?facebook\.com/\d+/?$#', $url );
    }

    /** A link Facebook's embedded video plugin can play. */
    public static function is_facebook_video_url( string $url ): bool {
        return 1 === preg_match( '#^https://(www\.|m\.|web\.)?facebook\.com/(?:[^?\#]+/)?(?:videos|reel)/\d|^https://(www\.|m\.|web\.)?facebook\.com/watch/?\?(?:.*&)?v=\d#', $url );
    }

    /**
     * The embed for one post.
     *
     * X / TikTok: core embed block (WordPress oEmbed), or the quote card if
     * oEmbed fails. Instagram: Instagram's own embed markup, turned into the
     * post card by instagram.com/embed.js. Facebook: the fb-post / fb-video
     * markup of Facebook's embedded posts plugin (the JS SDK renders it). Both
     * need no Meta app token, keep the caption and link inside as the fallback
     * when the script is blocked, and survive post kses (div/blockquote/a with
     * class and data-* attributes only).
     */
    public static function embed_block( array $it ): string {
        $label = self::PLATFORM_LABELS[ $it['platform'] ];
        $url   = esc_url( $it['url'] );
        $text  = self::excerpt( (string) $it['text'], 300 );
        $link  = '<a href="' . $url . '" target="_blank" rel="noopener nofollow">' . esc_html( sprintf( 'Δείτε την ανάρτηση στο %s', $label ) ) . '</a>';

        // TikTok photo posts: TikTok's player fails for most visitors
        // ("Player authentication failed"), so show the first image instead.
        if ( 'tiktok' === $it['platform'] && PressHub_AI_Embeds::is_tiktok_photo_url( (string) $it['url'] ) ) {
            $img = self::photo_image( $it );
            if ( null === $img ) {
                return self::quote_block( $it );
            }
            $alt = '' !== $text ? $text : sprintf( 'Ανάρτηση στο %s', $label );
            return PressHub_AI_Embeds::image_card_block( (string) $it['url'], $img['id'], $img['src'], self::excerpt( $alt, 150 ) );
        }
        if ( in_array( $it['platform'], [ 'x', 'tiktok' ], true ) ) {
            $provider = 'x' === $it['platform'] ? 'twitter' : 'tiktok';
            $type     = 'x' === $it['platform'] ? 'rich' : 'video';
            // twitter.com links are what every WordPress version's oEmbed provider list knows.
            $embed = 'x' === $it['platform'] ? (string) preg_replace( '#^https://x\.com/#', 'https://twitter.com/', $it['url'] ) : $it['url'];
            if ( ! self::oembed_ok( $embed ) ) {
                return self::quote_block( $it );
            }
            $attrs = json_encode( [ 'url' => $embed, 'type' => $type, 'providerNameSlug' => $provider, 'responsive' => true ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
            return "<!-- wp:embed " . $attrs . " -->\n<figure class=\"wp-block-embed is-type-{$type} is-provider-{$provider} wp-block-embed-{$provider}\"><div class=\"wp-block-embed__wrapper\">\n" . esc_url( $embed ) . "\n</div></figure>\n<!-- /wp:embed -->";
        }
        if ( 'instagram' === $it['platform'] ) {
            $inner = ( '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '' ) . '<p>' . $link . '</p>';
            return "<!-- wp:html -->\n<div class=\"presshub-trending-embed presshub-trending-embed-instagram\"><blockquote class=\"instagram-media\" data-instgrm-permalink=\"" . $url . "\" data-instgrm-version=\"14\" data-instgrm-captioned>" . $inner . "</blockquote></div>\n<!-- /wp:html -->";
        }
        // Facebook. facebook.com/<post id> is what the worker falls back to
        // when a story had no usable link; neither plugin can show it (a
        // reel shown that way is "Video unavailable" and can't be clicked),
        // so it gets the quote card with a working link instead.
        if ( self::is_bare_facebook_url( (string) $it['url'] ) ) {
            return self::quote_block( $it );
        }
        // The video plugin plays only video links (/reel/, /videos/,
        // /watch/?v=); a video inside a /posts/ link plays in the post plugin.
        $class = self::is_facebook_video_url( (string) $it['url'] ) ? 'fb-video' : 'fb-post';
        $inner = '<blockquote cite="' . $url . '" class="fb-xfbml-parse-ignore">' . ( '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '' ) . '<p>' . $link . '</p></blockquote>';
        return "<!-- wp:html -->\n<div class=\"presshub-trending-embed presshub-trending-embed-facebook\"><div class=\"" . $class . "\" data-href=\"" . $url . "\" data-width=\"500\" data-show-text=\"true\" data-lazy=\"true\">" . $inner . "</div></div>\n<!-- /wp:html -->";
    }

    /**
     * Block-editor content: intro, then one card (group block) per post with a
     * heading (rank, author, platform badge), the engagement line and the embed.
     */
    public static function build_content( array $items, int $now, string $summary = '', array $notes = [] ): string {
        $platforms = [];
        foreach ( $items as $it ) {
            $platforms[ $it['platform'] ] = self::PLATFORM_LABELS[ $it['platform'] ];
        }
        $updated = function_exists( 'wp_date' ) ? wp_date( 'j/n/Y H:i', $now ) : gmdate( 'j/n/Y H:i', $now );
        $intro   = sprintf(
            'Οι αναρτήσεις για την Κύπρο που συγκεντρώνουν τη μεγαλύτερη αλληλεπίδραση στα %s τις τελευταίες %d ώρες. Τελευταία ενημέρωση: %s.',
            implode( ', ', array_values( $platforms ) ),
            PressHub_AI_Settings_Storage::get_social_trending_max_age_hours(),
            $updated
        );
        $out = [];
        if ( '' !== trim( $summary ) ) {
            $out[] = "<!-- wp:paragraph {\"className\":\"presshub-trending-summary\"} -->\n<p class=\"presshub-trending-summary\">" . esc_html( trim( $summary ) ) . "</p>\n<!-- /wp:paragraph -->";
        }
        $out[] = "<!-- wp:paragraph {\"className\":\"presshub-trending-intro\"} -->\n<p class=\"presshub-trending-intro\">" . esc_html( $intro ) . "</p>\n<!-- /wp:paragraph -->";

        foreach ( array_values( $items ) as $i => $it ) {
            $p      = $it['platform'];
            $label  = self::PLATFORM_LABELS[ $p ];
            $author = '' !== $it['author_name'] ? $it['author_name'] : ( '' !== $it['handle'] ? '@' . $it['handle'] : $label );
            $handle = '' !== $it['handle'] && 0 !== strpos( $it['handle'], 'id-' ) && '@' . $it['handle'] !== $author ? ' <span class="presshub-trending-handle">@' . esc_html( $it['handle'] ) . '</span>' : '';
            $head   = '<span class="presshub-trending-rank">' . ( $i + 1 ) . '.</span> ' . esc_html( $author ) . $handle . ' <span class="presshub-trending-badge presshub-trending-badge-' . $p . '">' . esc_html( $label ) . '</span>';

            $stats = [];
            if ( $it['likes'] > 0 ) {
                $stats[] = self::fmt_count( (int) $it['likes'] ) . ( 'facebook' === $p ? ' αντιδράσεις' : ' likes' );
            }
            if ( $it['comments'] > 0 ) {
                $stats[] = self::fmt_count( (int) $it['comments'] ) . ' σχόλια';
            }
            if ( $it['shares'] > 0 ) {
                $stats[] = self::fmt_count( (int) $it['shares'] ) . ( 'x' === $p ? ' αναδημοσιεύσεις' : ' κοινοποιήσεις' );
            }
            if ( $it['views'] > 0 ) {
                $stats[] = self::fmt_count( (int) $it['views'] ) . ' προβολές';
            }
            $stats[] = self::ago( (int) $it['created_ts'], $now );

            $card   = "presshub-trending-card presshub-trending-{$p}";
            $out[]  = "<!-- wp:group {\"className\":\"{$card}\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group {$card}\">"
                . "<!-- wp:heading {\"level\":3,\"className\":\"presshub-trending-title\"} -->\n<h3 class=\"wp-block-heading presshub-trending-title\">" . $head . "</h3>\n<!-- /wp:heading -->\n\n"
                . "<!-- wp:paragraph {\"className\":\"presshub-trending-stats\"} -->\n<p class=\"presshub-trending-stats\">" . esc_html( implode( ' · ', $stats ) ) . "</p>\n<!-- /wp:paragraph -->\n\n"
                . self::embed_block( $it )
                . ( isset( $notes[ (string) ( $it['key'] ?? '' ) ] ) && class_exists( 'PressHub_AI_Fact_Checks' ) ? "\n\n" . PressHub_AI_Fact_Checks::note_block( (array) $notes[ (string) $it['key'] ] ) : '' )
                . "</div>\n<!-- /wp:group -->";
        }
        return implode( "\n\n", $out );
    }

    /* ------------------------------------------------------------------
     * Front end: embed scripts and card styling on roundup posts
     * ---------------------------------------------------------------- */

    /**
     * Roundups saved before 2.40.0 embedded Facebook reels as
     * fb-video / fb-post with a bare facebook.com/<post id> link, which
     * Facebook shows as "Video unavailable" with nothing to click. On display,
     * such a block loses its plugin class, so the SDK leaves it alone and the
     * quoted text with its "see the post on Facebook" link shows instead.
     * Roundups written since then never contain a bare link.
     */
    public static function filter_legacy_facebook_embeds( $content ) {
        $content = (string) $content;
        if ( false === strpos( $content, 'presshub-trending-embed-facebook' ) ) {
            return $content;
        }
        return self::unembed_bare_facebook_links( $content );
    }

    public static function unembed_bare_facebook_links( string $content ): string {
        return (string) preg_replace(
            '#<div class="fb-(?:video|post)" (data-href="https://(?:www\.|m\.|web\.)?facebook\.com/\d+/?")#',
            '<div class="presshub-trending-fb-link" $1',
            $content
        );
    }

    private static function is_roundup_view(): bool {
        if ( ! function_exists( 'is_singular' ) || ! is_singular( 'post' ) ) {
            return false;
        }
        $id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
        return $id > 0 && '' !== (string) get_post_meta( $id, self::META_KEY, true );
    }

    public static function enqueue_front_assets(): void {
        if ( ! self::is_roundup_view() ) {
            return;
        }
        $post    = get_post( (int) get_queried_object_id() );
        $content = $post ? (string) $post->post_content : '';
        $args    = [ 'in_footer' => true, 'strategy' => 'async' ];
        if ( false !== strpos( $content, 'instagram-media' ) ) {
            wp_enqueue_script( 'presshub-instagram-embed', 'https://www.instagram.com/embed.js', [], null, $args );
        }
        if ( false !== strpos( $content, 'fb-post' ) || false !== strpos( $content, 'fb-video' ) ) {
            $locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'el_GR';
            $locale = preg_match( '/^[a-z]{2}_[A-Z]{2}$/', $locale ) ? $locale : 'el_GR';
            wp_enqueue_script( 'presshub-facebook-sdk', 'https://connect.facebook.net/' . $locale . '/sdk.js#xfbml=1&version=' . PressHub_Social_Meta_API_Client::GRAPH_API_VERSION, [], null, $args );
        }
        wp_register_style( 'presshub-social-trending', false, [], PRESSHUB_AI_VERSION );
        wp_enqueue_style( 'presshub-social-trending' );
        wp_add_inline_style( 'presshub-social-trending', self::front_css() );
    }

    /** The Facebook SDK expects a #fb-root element in the page. */
    public static function print_fb_root(): void {
        if ( self::is_roundup_view() ) {
            echo '<div id="fb-root"></div>';
        }
    }

    public static function front_css(): string {
        return '.presshub-trending-card{border:1px solid rgba(0,0,0,.1);border-radius:12px;padding:1rem 1.25rem;margin:1.5rem 0;}'
            . '.presshub-trending-card .presshub-trending-title{margin:0 0 .25rem;font-size:1.15em;display:flex;flex-wrap:wrap;align-items:center;gap:.4em;}'
            . '.presshub-trending-rank{opacity:.55;}'
            . '.presshub-trending-handle{font-weight:400;opacity:.65;font-size:.85em;}'
            . '.presshub-trending-badge{font-size:.7em;font-weight:600;letter-spacing:.02em;text-transform:uppercase;padding:.15em .55em;border-radius:999px;color:#fff;background:#555;}'
            . '.presshub-trending-badge-x{background:#000;}'
            . '.presshub-trending-badge-tiktok{background:#fe2c55;}'
            . '.presshub-trending-badge-instagram{background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af);}'
            . '.presshub-trending-badge-facebook{background:#1877f2;}'
            . '.presshub-trending-stats{margin:0 0 .75rem;font-size:.9em;opacity:.75;}'
            . '.presshub-trending-card .wp-block-embed,.presshub-trending-embed{margin:0;max-width:100%;overflow:hidden;}'
            . '.presshub-trending-embed .instagram-media,.presshub-trending-embed .fb-post,.presshub-trending-embed .fb-video{max-width:540px;margin:0 auto;}'
            . '.presshub-trending-fallback{margin:0;}'
            . '.presshub-trending-card .presshub-tiktok-photo-card{margin:0 auto;}'
            . '.presshub-tiktok-photo-card img{max-width:min(100%,420px);height:auto;}'
            . '.presshub-trending-summary{font-size:1.1em;line-height:1.6;border-left:4px solid currentColor;padding:.25rem 0 .25rem 1rem;margin:0 0 1.5rem;}'
            . '.presshub-trending-factcheck{margin:.75rem 0 0;padding:.6rem .8rem;border-left:4px solid #b32d2e;background:rgba(179,45,46,.08);font-size:.95em;}'
            . '@media (prefers-color-scheme:dark){.presshub-trending-card{border-color:rgba(255,255,255,.15);}.presshub-trending-factcheck{background:rgba(255,120,120,.12);border-left-color:#ff8a8a;}}';
    }

    /* ------------------------------------------------------------------
     * Featured image
     * ---------------------------------------------------------------- */

    /**
     * Copy a platform image into the Media Library. Platform image links are
     * signed and expire, so they can't be shown directly.
     *
     * @return int|WP_Error attachment id
     */
    private static function sideload( string $image_url, int $post_id, string $desc ) {
        if ( is_callable( self::$sideload ) ) {
            return call_user_func( self::$sideload, $image_url, $post_id, $desc );
        }
        if ( ! function_exists( 'media_sideload_image' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/media.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if ( ! function_exists( 'media_sideload_image' ) ) {
            return new WP_Error( 'presshub_no_sideload', 'media_sideload_image is not available' );
        }
        return media_sideload_image( $image_url, $post_id, $desc, 'id' );
    }

    /**
     * The Media Library copy of a TikTok photo post's image, sideloaded once
     * per post and reused by later roundup updates.
     *
     * @return array{id:int,src:string}|null null when the post has no image or the copy failed
     */
    public static function photo_image( array $it ): ?array {
        $key = (string) ( $it['key'] ?? ( $it['platform'] . '_' . $it['id'] ) );
        $map = get_option( self::OPTION_IMAGES, [] );
        $map = is_array( $map ) ? $map : [];
        $id  = (int) ( $map[ $key ] ?? 0 );
        if ( $id > 0 ) {
            $src = self::attachment_src( $id );
            if ( '' !== $src ) {
                return [ 'id' => $id, 'src' => $src ];
            }
        }
        $thumb = (string) ( $it['thumbnail'] ?? '' );
        if ( '' === $thumb || isset( self::$photo_failed[ $thumb ] ) ) {
            return null;
        }
        $desc = sprintf( 'TikTok · %s', '' !== (string) $it['author_name'] ? $it['author_name'] : $it['handle'] );
        $att  = self::sideload( $thumb, 0, $desc );
        if ( is_wp_error( $att ) || (int) $att <= 0 ) {
            self::$photo_failed[ $thumb ] = true;
            self::log( 'warning', 'Social trending: TikTok photo image not copied, showing a text card: ' . ( is_wp_error( $att ) ? $att->get_error_message() : 'no attachment' ), [ 'url' => (string) $it['url'] ] );
            return null;
        }
        $src = self::attachment_src( (int) $att );
        if ( '' === $src ) {
            return null;
        }
        unset( $map[ $key ] );
        $map[ $key ] = (int) $att;
        if ( count( $map ) > self::IMAGES_KEPT ) {
            $map = array_slice( $map, -self::IMAGES_KEPT, null, true );
        }
        update_option( self::OPTION_IMAGES, $map, false );
        return [ 'id' => (int) $att, 'src' => $src ];
    }

    private static function attachment_src( int $id ): string {
        $src = function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( $id, 'large' ) : false;
        if ( ! $src && function_exists( 'wp_get_attachment_url' ) ) {
            $src = wp_get_attachment_url( $id );
        }
        return is_string( $src ) ? $src : '';
    }

    /**
     * Give a roundup post without a featured image its picture, once per post.
     * With "Roundup featured image" set to an AI image (and Generate featured
     * images on), the image is drawn from the roundup with the Trending in
     * Cyprus prompt template; otherwise, or if that fails, it is the picture
     * of the top post, copied into the Media Library (platform image links
     * are signed and expire), so a daily post adds one image a day.
     */
    public static function maybe_set_featured_image( int $post_id, array $items ): int {
        if ( $post_id <= 0 || ( function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post_id ) ) ) {
            return 0;
        }
        if ( 'auto_generate' === PressHub_AI_Settings_Storage::get_social_trending_image_source() ) {
            if ( PressHub_AI_Settings_Storage::get_image_feature_enabled() ) {
                // Two AI calls can take a minute: draw it in a job of its own, not in
                // the worker's request. The top post's picture below stands in until then.
                self::queue_ai_featured_image( $post_id, $items );
            } else {
                self::log( 'info', 'Social trending: AI roundup image chosen, but Generate featured images is off; using the top post picture.', [ 'post_id' => $post_id ] );
            }
        }
        $pick = null;
        foreach ( $items as $it ) {
            if ( '' !== (string) ( $it['thumbnail'] ?? '' ) ) {
                $pick = $it;
                break;
            }
        }
        if ( null === $pick ) {
            return 0;
        }
        $desc = sprintf( '%s · %s', self::PLATFORM_LABELS[ $pick['platform'] ], '' !== $pick['author_name'] ? $pick['author_name'] : $pick['handle'] );
        if ( 'tiktok' === $pick['platform'] && PressHub_AI_Embeds::is_tiktok_photo_url( (string) $pick['url'] ) ) {
            // Same image as the roundup card: reuse its copy; a failed copy is not downloaded again.
            $photo = self::photo_image( $pick );
            $att   = null !== $photo ? $photo['id'] : 0;
        } else {
            $att = self::sideload( $pick['thumbnail'], $post_id, $desc );
        }
        if ( is_wp_error( $att ) || (int) $att <= 0 ) {
            self::log( 'info', 'Social trending: featured image not set: ' . ( is_wp_error( $att ) ? $att->get_error_message() : 'no attachment' ) );
            return 0;
        }
        set_post_thumbnail( $post_id, (int) $att );
        return (int) $att;
    }

    /**
     * Queue the AI image job for a roundup, once per post. Only the post texts
     * travel with the job (at most 10 × 300 characters).
     */
    private static function queue_ai_featured_image( int $post_id, array $items ): void {
        if ( function_exists( 'get_post_meta' ) && '' !== (string) get_post_meta( $post_id, self::AI_IMAGE_META, true ) ) {
            return;
        }
        $cuts  = [];
        $texts = self::ai_image_texts( $items, $cuts );
        if ( empty( $texts ) ) {
            self::log( 'info', 'Social trending: no post text for an AI image; using the top post picture.', [ 'post_id' => $post_id ] );
            return;
        }
        if ( ! function_exists( 'wp_schedule_single_event' ) || false === wp_schedule_single_event( time(), self::AI_IMAGE_HOOK, [ $post_id, $texts ] ) ) {
            self::log( 'warning', 'Social trending: could not queue the AI roundup image; using the top post picture.', [ 'post_id' => $post_id ] );
            return;
        }
        update_post_meta( $post_id, self::AI_IMAGE_META, (string) time() );
        // The job runs in another request: keep the cuts with the post so the
        // image prompt call records them (agents.md rule 13).
        if ( ! empty( $cuts ) ) {
            update_post_meta( $post_id, self::AI_IMAGE_CUTS_META, $cuts );
        } elseif ( function_exists( 'delete_post_meta' ) ) {
            delete_post_meta( $post_id, self::AI_IMAGE_CUTS_META );
        }
        // Release (and so the social shares, which copy the featured image) waits for the AI image.
        update_post_meta( $post_id, self::AI_RELEASE_META, '1' );
    }

    /**
     * Up to 10 post texts, each cut to 300 characters. $cuts gets what the
     * two limits left out: [ 'what', 'reason', 'limit', 'needed', 'kept' ].
     */
    private static function ai_image_texts( array $items, ?array &$cuts = null ): array {
        $texts = [];
        $cuts  = [];
        foreach ( array_slice( $items, 0, 10 ) as $i => $it ) {
            $text = trim( (string) ( $it['text'] ?? '' ) );
            if ( '' === $text ) {
                continue;
            }
            $cut     = self::excerpt( $text, 300 );
            $texts[] = $cut;
            $full    = self::strlen( (string) preg_replace( '/\s+/u', ' ', $text ) );
            if ( $full > 300 ) {
                $cuts[] = [ 'what' => sprintf( 'Trending post %d', $i + 1 ), 'reason' => 'clipped', 'limit' => 300, 'needed' => $full, 'kept' => self::strlen( $cut ) - 1 ];
            }
        }
        $with_text_after = count( array_filter( array_slice( $items, 10 ), static function ( $it ) {
            return '' !== trim( (string) ( is_array( $it ) ? ( $it['text'] ?? '' ) : '' ) );
        } ) );
        if ( $with_text_after > 0 ) {
            $cuts[] = [ 'what' => 'Trending posts', 'reason' => 'max_items', 'limit' => 10, 'needed' => count( $items ), 'kept' => min( 10, count( $items ) ) ];
        }
        return $texts;
    }

    /** Note the cuts queue_ai_featured_image() kept with the post, for the image prompt call. */
    private static function note_ai_image_cuts( int $post_id ): void {
        if ( ! class_exists( 'PressHub_AI_Knowledge_Cuts' ) || ! function_exists( 'get_post_meta' ) ) {
            return;
        }
        $cuts = get_post_meta( $post_id, self::AI_IMAGE_CUTS_META, true );
        foreach ( is_array( $cuts ) ? array_slice( $cuts, 0, 20 ) : [] as $c ) {
            if ( is_array( $c ) && isset( $c['what'], $c['reason'] ) ) {
                PressHub_AI_Knowledge_Cuts::note_material( 'generate_image_prompt', (string) $c['what'], (string) $c['reason'], (int) ( $c['limit'] ?? 0 ), (int) ( $c['needed'] ?? 0 ), (int) ( $c['kept'] ?? -1 ) );
            }
        }
    }

    /**
     * The AI image job: draw the image and make it the roundup's featured
     * image, replacing the top post's picture. On failure the picture stays.
     *
     * @param int   $post_id Roundup post.
     * @param array $texts   Post texts from queue_ai_featured_image().
     */
    public static function run_ai_featured_image( $post_id, $texts = [] ): int {
        $post_id = (int) $post_id;
        $post    = ( $post_id > 0 && function_exists( 'get_post' ) ) ? get_post( $post_id ) : null;
        if ( ! $post || 'trash' === (string) ( $post->post_status ?? '' ) ) {
            if ( $post_id > 0 && function_exists( 'delete_post_meta' ) ) {
                delete_post_meta( $post_id, self::AI_RELEASE_META );
            }
            return 0;
        }
        $att = 0;
        try {
            // The settings may have changed since the job was queued.
            if ( self::ai_image_planned() ) {
                if ( function_exists( 'set_time_limit' ) ) {
                    @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
                }
                $items = [];
                foreach ( (array) $texts as $text ) {
                    $items[] = [ 'text' => (string) $text ];
                }
                $att = self::ai_featured_image( $post_id, $items );
                // A job that ran past the watchdog finds the roundup already released
                // with the top post picture; its shares carry that one, so it stays.
                if ( $att > 0 && self::released_by_watchdog( $post_id ) ) {
                    self::log( 'info', 'Social trending: AI roundup image arrived after the release; keeping the top post picture.', [ 'post_id' => $post_id ] );
                    $att = 0;
                } elseif ( $att > 0 ) {
                    set_post_thumbnail( $post_id, $att );
                }
            } else {
                self::log( 'info', 'Social trending: AI roundup image no longer chosen; keeping the top post picture.', [ 'post_id' => $post_id ] );
            }
        } finally {
            // Released now, with the AI image or the top post picture, so its shares carry it.
            // Claimed by deleting the meta, so the watchdog and the job never both release.
            if ( self::release_waits_for_ai_image( $post_id ) && false !== delete_post_meta( $post_id, self::AI_RELEASE_META ) ) {
                self::release_through_gate( $post_id );
            }
        }
        return $att;
    }

    /**
     * Draw an AI featured image for a roundup from its title and the text of
     * its top posts, with the Trending in Cyprus prompt template.
     *
     * @return int Attachment id, or 0 when it failed (logged).
     */
    private static function ai_featured_image( int $post_id, array $items ): int {
        $lines = array_map( static function ( $text ) { return '- ' . $text; }, self::ai_image_texts( $items ) );
        if ( empty( $lines ) ) {
            self::log( 'info', 'Social trending: no post text for an AI image; using the top post picture.' );
            return 0;
        }
        $title   = function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '';
        $content = "What people in Cyprus are posting about on social media:\n" . implode( "\n", $lines );
        try {
            require_once __DIR__ . '/class-image-prompt-templates.php';
            $tpl = PressHub_AI_Image_Prompt_Templates::resolve( PressHub_AI_Settings_Storage::get_auto_image_template( 'social_trending' ), 'social_trending', PressHub_AI_Settings_Storage::default_social_trending_image_template() );
            if ( is_callable( self::$ai_image ) ) {
                $att = call_user_func( self::$ai_image, $content, $title, $tpl, $post_id );
                return is_wp_error( $att ) ? 0 : max( 0, (int) $att );
            }
            require_once __DIR__ . '/class-api-client.php';
            $client = new PressHub_AI_API_Client();
            // The texts were cut when the job was queued: record those cuts with this call.
            self::note_ai_image_cuts( $post_id );
            try {
                $prompt = $client->analyze_article_for_image_prompt( $content, $title, $tpl['template'], $tpl['id'] );
            } finally {
                if ( class_exists( 'PressHub_AI_Knowledge_Cuts' ) ) {
                    PressHub_AI_Knowledge_Cuts::forget_material( 'generate_image_prompt' );
                }
                if ( function_exists( 'delete_post_meta' ) ) {
                    delete_post_meta( $post_id, self::AI_IMAGE_CUTS_META );
                }
            }
            if ( is_wp_error( $prompt ) || '' === trim( (string) $prompt ) ) {
                self::log( 'warning', 'Social trending: AI image prompt failed; using the top post picture.', [ 'error' => is_wp_error( $prompt ) ? $prompt->get_error_message() : 'empty prompt' ] );
                return 0;
            }
            $img = $client->generate_featured_image( (string) $prompt, '', $post_id );
            if ( is_wp_error( $img ) || empty( $img['id'] ) ) {
                self::log( 'warning', 'Social trending: AI image failed; using the top post picture.', [ 'error' => is_wp_error( $img ) ? $img->get_error_message() : 'no attachment' ] );
                return 0;
            }
            self::log( 'info', 'Social trending: AI featured image set.', [ 'post_id' => $post_id, 'template_id' => $tpl['id'] ] );
            return (int) $img['id'];
        } catch ( Throwable $t ) {
            self::log( 'warning', 'Social trending: AI image failed; using the top post picture.', [ 'error' => $t->getMessage() ] );
            return 0;
        }
    }

    /* ------------------------------------------------------------------
     * REST (worker, Pull Mode)
     * ---------------------------------------------------------------- */

    public static function register_routes(): void {
        $ns   = PressHub_Social_REST_Controller::REST_NAMESPACE;
        $auth = [ 'PressHub_Social_REST_Controller', 'verify_worker_secret' ];
        register_rest_route( $ns, '/trending/plan', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_plan' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, '/trending/posts', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_posts' ],
            'permission_callback' => $auth,
        ] );
    }

    private static function param( $request, string $key ) {
        if ( is_array( $request ) ) {
            return $request[ $key ] ?? null;
        }
        $val = method_exists( $request, 'get_param' ) ? $request->get_param( $key ) : null;
        if ( null === $val && method_exists( $request, 'get_json_params' ) ) {
            $json = $request->get_json_params();
            $val  = is_array( $json ) ? ( $json[ $key ] ?? null ) : null;
        }
        return $val;
    }

    public static function rest_plan( $request ) {
        $force = in_array( self::param( $request, 'force' ), [ 1, '1', true, 'true' ], true );
        return self::response( self::get_worker_plan( $force ) );
    }

    public static function rest_posts( $request ) {
        $posts = self::param( $request, 'posts' );
        if ( ! is_array( $posts ) ) {
            return new WP_Error( 'invalid_posts', 'posts must be an array.', [ 'status' => 400 ] );
        }
        $errors = self::param( $request, 'errors' );
        $res    = self::ingest_worker_posts( (string) self::param( $request, 'run_id' ), $posts, is_array( $errors ) ? $errors : [] );
        return is_wp_error( $res ) ? $res : self::response( $res );
    }

    private static function response( array $data ) {
        return class_exists( 'WP_REST_Response' ) ? new WP_REST_Response( $data, 200 ) : $data;
    }

    private static function log( string $level, string $message, array $context = [] ): void {
        if ( class_exists( 'PressHub_AI_Logger' ) && method_exists( 'PressHub_AI_Logger', $level ) ) {
            PressHub_AI_Logger::$level( $message, $context );
        }
    }
}
