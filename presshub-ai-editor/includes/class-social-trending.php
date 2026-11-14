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

class PressHub_AI_Social_Trending {

    const OPTION_STATE      = 'presshub_ai_social_trending_state';
    const OPTION_CANDIDATES = 'presshub_ai_social_trending_candidates';
    const OPTION_POSTS      = 'presshub_ai_social_trending_posts';
    const OPTION_IG_TAG_IDS = 'presshub_ai_social_trending_ig_tag_ids';

    const CRON_HOOK  = 'presshub_ai_social_trending_tick';
    const RUN_ACTION = 'presshub_ai_social_trending_run_now';

    /** Post meta on roundup posts: the run key and the items shown. */
    const META_KEY   = '_presshub_social_trending_key';
    const META_ITEMS = '_presshub_social_trending_items';

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
        add_action( 'admin_post_' . self::RUN_ACTION, [ __CLASS__, 'handle_run_now' ] );
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'presshub-ai-editor' ) );
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
        $interval = PressHub_AI_Settings_Storage::get_social_trending_interval_hours() * 3600;
        if ( ! $force && ( $now - (int) $state['last_run'] ) < $interval ) {
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
        $plan['targets'] = self::build_targets();
        $state['plan_handed'] = $now;
        self::save_state( $state );
        return $plan;
    }

    /**
     * Pages the worker visits.
     *
     * @return array<int, array{platform:string, url:string, label:string}>
     */
    public static function build_targets(): array {
        $worker   = self::worker_platforms();
        $keywords = PressHub_AI_Settings_Storage::get_social_trending_keywords();
        $min      = PressHub_AI_Settings_Storage::get_social_trending_min_engagement();
        $targets  = [];

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
                [ $greek, ' lang:el', max( 1, (int) floor( $min / 2 ) ), 'X search (Greek)' ],
                [ $latin, '', max( 1, $min * 2 ), 'X search (English / other)' ],
            ];
            foreach ( $groups as [ $terms, $lang, $faves, $label ] ) {
                if ( empty( $terms ) ) {
                    continue;
                }
                $q = '(' . implode( ' OR ', $terms ) . ')' . $lang . ' -filter:replies -filter:retweets';
                if ( $min > 0 ) {
                    $q .= ' min_faves:' . $faves;
                }
                $targets[] = [
                    'platform' => 'x',
                    'url'      => 'https://x.com/search?q=' . rawurlencode( $q ) . '&src=typed_query&f=top',
                    'label'    => $label,
                ];
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
            foreach ( array_slice( $keywords, 0, self::MAX_TIKTOK_QUERIES ) as $k ) {
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

    /**
     * Posts sent by the worker for a run.
     *
     * @return array|WP_Error {accepted:int, rejected:int, published:int}
     */
    public static function ingest_worker_posts( string $run_id, array $posts, array $errors = [], ?int $now = null ) {
        $now   = $now ?? time();
        $state = self::get_state();
        if ( empty( $state['open'] ) || '' === $run_id || $run_id !== (string) $state['run_id'] ) {
            return new WP_Error( 'stale_run', 'This run is no longer open.', [ 'status' => 409 ] );
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
        $by       = [];
        $per_auth = [];
        foreach ( $candidates as $c ) {
            if ( ! is_array( $c ) || $now - (int) $c['created_ts'] > $max_age ) {
                continue;
            }
            if ( self::engagement( $c ) < $min || self::is_excluded( $c, $excluded ) ) {
                continue;
            }
            $c['score'] = self::score( $c, $now );
            $by[ $c['platform'] ][] = $c;
        }
        foreach ( $by as $p => $list ) {
            usort( $list, static function ( $a, $b ) {
                return $b['score'] <=> $a['score'];
            } );
            // At most two posts by the same account per platform, so one busy account cannot fill it.
            $kept = [];
            foreach ( $list as $c ) {
                $ak = $p . ':' . self::account_key( $c );
                $per_auth[ $ak ] = ( $per_auth[ $ak ] ?? 0 ) + 1;
                if ( $per_auth[ $ak ] > 2 ) {
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
        $out = [];
        for ( $i = 0; count( $out ) < $max; $i++ ) {
            $added = false;
            foreach ( $order as $p ) {
                if ( isset( $by[ $p ][ $i ] ) && count( $out ) < $max ) {
                    $out[] = $by[ $p ][ $i ];
                    $added = true;
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
    public static function upsert_post( array $items, int $now, string $run_id ) {
        $key     = self::post_key( $now, $run_id );
        $map     = get_option( self::OPTION_POSTS, [] );
        $map     = is_array( $map ) ? $map : [];
        $title   = self::build_title( $now );
        $content = self::build_content( $items, $now );
        $status  = PressHub_AI_Settings_Storage::get_social_trending_post_status();
        $items_meta = function_exists( 'wp_json_encode' ) ? wp_json_encode( $items ) : json_encode( $items );

        $existing = (int) ( $map[ $key ] ?? 0 );
        if ( $existing > 0 ) {
            $post = get_post( $existing );
            if ( $post && 'trash' !== ( $post->post_status ?? '' ) ) {
                $res = wp_update_post( [
                    'ID'           => $existing,
                    'post_title'   => $title,
                    'post_content' => $content,
                ], true );
                if ( is_wp_error( $res ) ) {
                    return $res;
                }
                update_post_meta( $existing, self::META_ITEMS, $items_meta );
                return $existing;
            }
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
        return (int) $id;
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

    /**
     * Block-editor content: intro, then one section per post. X and TikTok use
     * the core embed block (WordPress oEmbed); Instagram and Facebook embeds
     * need a Meta app token in WordPress, so they are shown as a quote with a link.
     */
    public static function build_content( array $items, int $now ): string {
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
        $out[] = "<!-- wp:paragraph -->\n<p>" . esc_html( $intro ) . "</p>\n<!-- /wp:paragraph -->";

        foreach ( array_values( $items ) as $i => $it ) {
            $label  = self::PLATFORM_LABELS[ $it['platform'] ];
            $author = '' !== $it['author_name'] ? $it['author_name'] : ( '' !== $it['handle'] ? '@' . $it['handle'] : $label );
            $head   = sprintf( '%d. %s στο %s', $i + 1, $author, $label );
            $out[]  = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . esc_html( $head ) . "</h3>\n<!-- /wp:heading -->";

            $stats = [];
            if ( $it['likes'] > 0 ) {
                $stats[] = self::fmt_count( (int) $it['likes'] ) . ( 'facebook' === $it['platform'] ? ' αντιδράσεις' : ' likes' );
            }
            if ( $it['comments'] > 0 ) {
                $stats[] = self::fmt_count( (int) $it['comments'] ) . ' σχόλια';
            }
            if ( $it['shares'] > 0 ) {
                $stats[] = self::fmt_count( (int) $it['shares'] ) . ( 'x' === $it['platform'] ? ' αναδημοσιεύσεις' : ' κοινοποιήσεις' );
            }
            if ( $it['views'] > 0 ) {
                $stats[] = self::fmt_count( (int) $it['views'] ) . ' προβολές';
            }
            $stats[] = self::ago( (int) $it['created_ts'], $now );
            $out[]   = "<!-- wp:paragraph {\"className\":\"presshub-trending-stats\"} -->\n<p class=\"presshub-trending-stats\"><em>" . esc_html( implode( ' · ', $stats ) ) . "</em></p>\n<!-- /wp:paragraph -->";

            $url = esc_url( $it['url'] );
            if ( in_array( $it['platform'], [ 'x', 'tiktok' ], true ) ) {
                $provider = 'x' === $it['platform'] ? 'twitter' : 'tiktok';
                $type     = 'x' === $it['platform'] ? 'rich' : 'video';
                // twitter.com links are what every WordPress version's oEmbed provider list knows.
                $embed    = 'x' === $it['platform'] ? (string) preg_replace( '#^https://x\.com/#', 'https://twitter.com/', $it['url'] ) : $it['url'];
                $attrs    = json_encode( [ 'url' => $embed, 'type' => $type, 'providerNameSlug' => $provider, 'responsive' => true ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
                $out[] = "<!-- wp:embed " . $attrs . " -->\n<figure class=\"wp-block-embed is-type-{$type} is-provider-{$provider} wp-block-embed-{$provider}\"><div class=\"wp-block-embed__wrapper\">\n" . esc_url( $embed ) . "\n</div></figure>\n<!-- /wp:embed -->";
            } else {
                $text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $it['text'] ) );
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 400 ) {
                    $text = rtrim( mb_substr( $text, 0, 400 ) ) . '…';
                }
                $quote = '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '';
                $cite  = '<cite><a href="' . $url . '" target="_blank" rel="noopener nofollow">' . esc_html( sprintf( 'Δείτε την ανάρτηση στο %s', $label ) ) . '</a></cite>';
                $out[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $quote . $cite . "</blockquote>\n<!-- /wp:quote -->";
            }
        }
        return implode( "\n\n", $out );
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
