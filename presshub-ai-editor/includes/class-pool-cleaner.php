<?php
/**
 * PressHub_AI_Pool_Cleaner — Issue #190: a cleaner article pool.
 *
 * Runs once on the harvested pool, before the snapshot is saved:
 *
 *  1. Clean content: drop <style>/<script>/<noscript> blocks, HTML comments,
 *     data: URIs, leftover tags and repeated whitespace, then recount
 *     char_count. A very long item is cut at STORED_MAX_CHARS (curation only
 *     sends the first few thousand characters anyway), so pool token
 *     estimates are not inflated by junk.
 *  2. Stale items: a title or URL that carries a date older than the
 *     briefing date (daily shows, yesterday's weather bulletin), or a
 *     published_at older than MAX_AGE_HOURS. A page that states no date
 *     is aged from the first harvest that saw its URL (option
 *     FIRST_SEEN_OPTION), so an undated piece that stays on a homepage
 *     for weeks is not new news every day.
 *  3. Promos and filler: titles that match the promo patterns, and scraped
 *     results stubs (a score-only title with little text). A general
 *     minimum length can be set with presshub_ai_pool_min_scraped_words.
 *  4. Near-duplicates: same story from several sources or platforms (word
 *     shingle overlap of title + text). The best copy is kept: highest
 *     source priority, then a transcript, then the longest text.
 *
 * Manual uploads and press releases are never dropped; they are cleaned
 * only. Every dropped item is listed in $payload['pool_cleanup'] with its
 * reason, so the inspector can show what was removed.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Pool_Cleaner {

    const STORED_MAX_CHARS   = 20000;
    /** URL hash => first harvest time, for pages that state no date. */
    const FIRST_SEEN_OPTION  = 'presshub_ai_pool_first_seen';
    /** First-seen entries are kept this long past the age limit. */
    const FIRST_SEEN_KEEP_HOURS = 72;
    const MAX_AGE_HOURS      = 48;
    /** Extra minimum for scraped pages; 0 = only the harvester's own 30-word rule applies. */
    const MIN_SCRAPED_WORDS  = 0;
    /** A score-only title ("Πορτογαλία – Δανία 1") with less text than this is a results stub. */
    const SCORE_STUB_WORDS   = 150;
    const DUPLICATE_SIMILAR  = 0.6;
    const SHINGLE_SIZE       = 3;
    const DUPLICATE_TEXT_LEN = 1500;

    /** Tiers that come from scraping a web page (short pages there are filler). */
    const SCRAPED_TIERS = [ 'dom', 'json_ld', 'opengraph', 'raw_strip', 'llm' ];

    /** Case- and accent-insensitive title patterns for promos and house ads. */
    const PROMO_PATTERNS = [
        '/^διαβαστε (αυτη την|αυριο|σημερα|την|στην)/u',
        '/^(αυτη την κυριακη|αυτο το σαββατο) (στην|στο|με την|με το)/u',
        '/^(κερδιστε|εγγραφειτε|συνδρομη|γινετε συνδρομητ)/u',
        '/^(read|subscribe|sign up|this sunday in)\b/u',
        '/\b(διαγωνισμος|giveaway)\b.*\b(κερδιστε|win)\b/u',
    ];

    private static ?PressHub_AI_News_Harvester $harvester = null;

    // ---------------------------------------------------------------------
    // Entry point
    // ---------------------------------------------------------------------

    /**
     * Clean the pool in place.
     *
     * @param array  $payload Harvest payload with 'articles'.
     * @param string $date    Briefing date (Y-m-d).
     * @param int    $now     Unix time (tests).
     * @return array Summary (also stored in $payload['pool_cleanup']).
     */
    public static function clean( array &$payload, string $date, int $now = 0 ): array {
        $now      = $now > 0 ? $now : time();
        $articles = isset( $payload['articles'] ) && is_array( $payload['articles'] ) ? array_values( $payload['articles'] ) : [];
        $enabled  = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_pool_cleanup_enabled() : true;
        $enabled  = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'presshub_ai_pool_cleanup_enabled', $enabled ) : $enabled;

        $summary = [
            'enabled'      => $enabled,
            'before'       => count( $articles ),
            'after'        => count( $articles ),
            'chars_before' => 0,
            'chars_after'  => 0,
            'trimmed'      => 0,
            'dropped'      => [],
            'counts'       => [ 'stale' => 0, 'promo' => 0, 'too_short' => 0, 'duplicate' => 0 ],
        ];
        if ( ! $enabled || empty( $articles ) ) {
            $payload['pool_cleanup'] = $summary;
            return $summary;
        }

        // 1. Clean content.
        foreach ( $articles as &$art ) {
            if ( ! is_array( $art ) ) {
                continue;
            }
            $before = (string) ( $art['content'] ?? '' );
            $summary['chars_before'] += self::len( $before );
            $clean = self::clean_text( $before );
            if ( self::len( $clean ) > self::STORED_MAX_CHARS ) {
                $clean = rtrim( self::sub( $clean, self::STORED_MAX_CHARS ) ) . ' …';
                $art['content_trimmed'] = true;
                $summary['trimmed']++;
            }
            $art['content']    = $clean;
            $art['char_count'] = self::len( $clean );
            if ( isset( $art['title'] ) ) {
                $art['title'] = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $art['title'] ) ) );
            }
            $summary['chars_after'] += $art['char_count'];
        }
        unset( $art );

        // Undated pages: age them from the first harvest that saw the URL.
        self::apply_first_seen( $articles, $now );

        // 2–3. Per-item filters.
        $kept = [];
        foreach ( $articles as $art ) {
            if ( ! is_array( $art ) ) {
                continue;
            }
            $reason = self::is_protected( $art ) ? '' : self::drop_reason( $art, $date, $now );
            if ( '' !== $reason ) {
                self::record_drop( $summary, $art, $reason );
                continue;
            }
            $kept[] = $art;
        }

        // 4. Near-duplicates.
        $kept = self::dedupe( $kept, $summary );

        $summary['after']        = count( $kept );
        $payload['articles']     = $kept;
        $payload['pool_cleanup'] = $summary;

        if ( class_exists( 'PressHub_AI_Logger' ) && ( $summary['before'] !== $summary['after'] || $summary['trimmed'] > 0 ) ) {
            PressHub_AI_Logger::info( sprintf(
                'Article pool cleaned for %s: %d -> %d articles (stale %d, promo %d, short %d, duplicate %d, trimmed %d)',
                $date,
                $summary['before'],
                $summary['after'],
                $summary['counts']['stale'],
                $summary['counts']['promo'],
                $summary['counts']['too_short'],
                $summary['counts']['duplicate'],
                $summary['trimmed']
            ) );
        }
        return $summary;
    }

    private static function record_drop( array &$summary, array $art, string $reason, string $kept_url = '' ): void {
        $summary['counts'][ $reason ] = ( $summary['counts'][ $reason ] ?? 0 ) + 1;
        $row = [
            'reason' => $reason,
            'title'  => self::sub( (string) ( $art['title'] ?? '' ), 160 ),
            'url'    => (string) ( $art['url'] ?? '' ),
            'source' => (string) ( $art['source'] ?? '' ),
        ];
        if ( '' !== $kept_url ) {
            $row['kept'] = $kept_url;
        }
        $summary['dropped'][] = $row;
    }

    // ---------------------------------------------------------------------
    // Content cleaning
    // ---------------------------------------------------------------------

    public static function clean_text( string $text ): string {
        if ( '' === $text ) {
            return '';
        }
        $text = preg_replace( '#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1\s*>#isu', ' ', $text ) ?? $text;
        $text = preg_replace( '#<!--.*?-->#su', ' ', $text ) ?? $text;
        $text = preg_replace( '#data:[a-z0-9.+-]+/[a-z0-9.+-]+(;[a-z0-9=._-]+)*,[A-Za-z0-9+/=%._-]{16,}#iu', ' ', $text ) ?? $text;
        // Stray CSS/JS blobs left after tag stripping (e.g. ".a{color:red}" or "window.x=...;").
        $text = preg_replace( '/(?:^|\s)[.#@]?[\w-]+(?:\s*[,>]\s*[.#]?[\w-]+)*\s*\{[^{}]{0,400}\}/u', ' ', $text ) ?? $text;
        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = str_replace( [ "\r\n", "\r", "\u{00A0}", "\u{200B}", "\u{FEFF}" ], [ "\n", "\n", ' ', '', '' ], $text );
        $text = preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text;
        $text = preg_replace( '/ *\n */u', "\n", $text ) ?? $text;
        $text = preg_replace( '/\n{3,}/u', "\n\n", $text ) ?? $text;
        return trim( $text );
    }

    // ---------------------------------------------------------------------
    // Per-item filters
    // ---------------------------------------------------------------------

    /** Manual uploads and press releases are kept whatever their shape. */
    public static function is_protected( array $art ): bool {
        return ! empty( $art['is_manual'] ) || ! empty( $art['is_press_release'] )
            || in_array( (string) ( $art['tier'] ?? '' ), [ 'manual', 'press_release' ], true )
            || 'press_release' === (string) ( $art['source_type'] ?? '' );
    }

    /**
     * Give each undated, unprotected article its URL's first harvest time
     * ('first_seen_at'), record new URLs, and drop entries older than the
     * age limit plus FIRST_SEEN_KEEP_HOURS.
     *
     * @param array $articles Pool (changed in place).
     * @param int   $now      Unix time.
     */
    public static function apply_first_seen( array &$articles, int $now ): void {
        if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
            return;
        }
        $seen = get_option( self::FIRST_SEEN_OPTION, [] );
        $seen = is_array( $seen ) ? $seen : [];
        $changed = false;
        foreach ( $articles as &$art ) {
            if ( ! is_array( $art ) || self::is_protected( $art ) || self::has_date( $art ) ) {
                continue;
            }
            $key = self::url_key( (string) ( $art['url'] ?? '' ) );
            if ( '' === $key ) {
                continue;
            }
            if ( ! isset( $seen[ $key ] ) || (int) $seen[ $key ] > $now ) {
                $seen[ $key ] = $now;
                $changed      = true;
            }
            $art['first_seen_at'] = gmdate( 'c', (int) $seen[ $key ] );
        }
        unset( $art );

        $cutoff  = $now - ( max( 1, self::max_age_hours() ) + self::FIRST_SEEN_KEEP_HOURS ) * 3600;
        foreach ( $seen as $k => $ts ) {
            if ( (int) $ts < $cutoff ) {
                unset( $seen[ $k ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( self::FIRST_SEEN_OPTION, $seen, false );
        }
    }

    /** The age limit in hours: "Oldest article kept (h)", then the presshub_ai_pool_max_age_hours filter. */
    public static function max_age_hours(): int {
        $max_age = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_pool_max_age_hours() : self::MAX_AGE_HOURS;
        return (int) ( function_exists( 'apply_filters' ) ? apply_filters( 'presshub_ai_pool_max_age_hours', $max_age ) : $max_age );
    }

    /** True when the article has a published_at that strtotime() can read. */
    private static function has_date( array $art ): bool {
        $p = trim( (string) ( $art['published_at'] ?? '' ) );
        return '' !== $p && false !== strtotime( $p );
    }

    /** Short stable key for a URL: scheme, "www." and a trailing slash ignored. */
    public static function url_key( string $url ): string {
        $url = strtolower( trim( $url ) );
        if ( '' === $url ) {
            return '';
        }
        $url = preg_replace( '#^https?://(www\.)?#', '', $url );
        $url = preg_replace( '/#.*$/', '', $url );
        $url = rtrim( $url, '/' );
        return substr( md5( $url ), 0, 16 );
    }

    /** '' to keep, otherwise 'stale' | 'promo' | 'too_short'. */
    public static function drop_reason( array $art, string $date, int $now ): string {
        $title = (string) ( $art['title'] ?? '' );
        $url   = (string) ( $art['url'] ?? '' );

        $title_date = self::find_date( $title, $date );
        if ( '' === $title_date ) {
            $title_date = self::find_url_date( $url );
        }
        // Only a recent past date marks a dated item (daily show, bulletin); an older
        // date in a title is usually a reference to an event ("since 1/3/2024").
        if ( '' !== $title_date && $title_date < $date && $title_date >= gmdate( 'Y-m-d', strtotime( $date . ' -31 days' ) ) ) {
            return 'stale';
        }

        $published = self::has_date( $art ) ? (string) $art['published_at'] : (string) ( $art['first_seen_at'] ?? '' );
        if ( '' !== $published ) {
            $ts      = strtotime( $published );
            $max_age = self::max_age_hours();
            if ( false !== $ts && $max_age > 0 && $ts < $now - $max_age * 3600 ) {
                return 'stale';
            }
        }

        if ( self::is_promo_title( $title ) ) {
            return 'promo';
        }

        if ( in_array( (string) ( $art['tier'] ?? '' ), self::SCRAPED_TIERS, true ) ) {
            $min = (int) ( function_exists( 'apply_filters' ) ? apply_filters( 'presshub_ai_pool_min_scraped_words', self::MIN_SCRAPED_WORDS ) : self::MIN_SCRAPED_WORDS );
            $words = self::word_count( (string) ( $art['content'] ?? '' ) );
            if ( $min > 0 && $words < $min ) {
                return 'too_short';
            }
            if ( $words < self::SCORE_STUB_WORDS && self::is_score_title( $title ) ) {
                return 'too_short';
            }
        }
        return '';
    }

    /** "Team A – Team B 1" or "Team A - Team B 2-1": a bare results line, not a story. */
    public static function is_score_title( string $title ): bool {
        return 1 === preg_match( '/^[\p{L}.\' ]{2,40}\s+[–—-]\s+[\p{L}.\' ]{2,40}\s+\d{1,2}(\s*[-:–]\s*\d{1,2})?$/u', trim( $title ) );
    }

    public static function is_promo_title( string $title ): bool {
        $t = self::fold( $title );
        if ( '' === $t ) {
            return false;
        }
        $patterns = function_exists( 'apply_filters' ) ? apply_filters( 'presshub_ai_pool_promo_patterns', self::PROMO_PATTERNS ) : self::PROMO_PATTERNS;
        foreach ( (array) $patterns as $re ) {
            if ( is_string( $re ) && @preg_match( $re, $t ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A date written in a title: dd/mm/yy(yy), dd-mm-yyyy, dd.mm.yyyy or
     * yyyy-mm-dd. A two-digit year is 20yy; a date without a year is not
     * used. Returns Y-m-d or ''.
     */
    public static function find_date( string $text, string $ref_date = '' ): string {
        if ( preg_match( '/(?<!\d)(20\d{2})-(\d{1,2})-(\d{1,2})(?!\d)/', $text, $m ) ) {
            return self::ymd( (int) $m[1], (int) $m[2], (int) $m[3] );
        }
        if ( preg_match( '/(?<!\d)(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})(?!\d)/', $text, $m ) ) {
            $year = (int) $m[3];
            $year = $year < 100 ? 2000 + $year : $year;
            return self::ymd( $year, (int) $m[2], (int) $m[1] );
        }
        return '';
    }

    /** A date in the URL path: /2026/11/08/, -08-11-2026, -081126- style slugs are ignored. */
    public static function find_url_date( string $url ): string {
        $path = (string) parse_url( $url, PHP_URL_PATH );
        if ( '' === $path ) {
            return '';
        }
        if ( preg_match( '#/(20\d{2})/(\d{1,2})/(\d{1,2})(?:/|$)#', $path, $m ) ) {
            return self::ymd( (int) $m[1], (int) $m[2], (int) $m[3] );
        }
        if ( preg_match( '/(?<!\d)(\d{1,2})-(\d{1,2})-(20\d{2})(?!\d)/', $path, $m ) ) {
            return self::ymd( (int) $m[3], (int) $m[2], (int) $m[1] );
        }
        if ( preg_match( '/(?<!\d)(20\d{2})-(\d{1,2})-(\d{1,2})(?!\d)/', $path, $m ) ) {
            return self::ymd( (int) $m[1], (int) $m[2], (int) $m[3] );
        }
        return '';
    }

    private static function ymd( int $y, int $m, int $d ): string {
        return ( $y >= 2000 && $y <= 2100 && checkdate( $m, $d, $y ) ) ? sprintf( '%04d-%02d-%02d', $y, $m, $d ) : '';
    }

    // ---------------------------------------------------------------------
    // Near-duplicates
    // ---------------------------------------------------------------------

    /** Word shingles of title + start of the text, accent- and case-folded. */
    public static function shingles( array $art ): array {
        $text  = (string) ( $art['title'] ?? '' ) . ' ' . self::sub( (string) ( $art['content'] ?? '' ), self::DUPLICATE_TEXT_LEN );
        $text  = preg_replace( '#https?://\S+#u', ' ', self::fold( $text ) ) ?? '';
        $words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
        $words = array_values( array_filter( $words, static function ( $w ) {
            return self::len( $w ) > 2;
        } ) );
        $n   = self::SHINGLE_SIZE;
        $out = [];
        if ( count( $words ) < $n ) {
            if ( ! empty( $words ) ) {
                $out[ implode( ' ', $words ) ] = true;
            }
            return $out;
        }
        for ( $i = 0, $c = count( $words ) - $n; $i <= $c; $i++ ) {
            $out[ implode( ' ', array_slice( $words, $i, $n ) ) ] = true;
        }
        return $out;
    }

    /**
     * Overlap of two shingle sets: shared / smaller set, so a short social
     * caption that is fully contained in a longer post still matches.
     */
    public static function similarity( array $a, array $b ): float {
        if ( empty( $a ) || empty( $b ) ) {
            return 0.0;
        }
        $min = min( count( $a ), count( $b ) );
        if ( $min < 4 ) {
            return 0.0; // Too little text to call it the same story.
        }
        return count( array_intersect_key( $a, $b ) ) / $min;
    }

    /** Higher is better: priority, then a transcript, then longer text, then earlier in the pool. */
    private static function rank( array $art ): array {
        $priority = 3;
        if ( class_exists( 'PressHub_AI_News_Curator' ) && method_exists( 'PressHub_AI_News_Curator', 'article_priority' ) ) {
            $priority = (int) PressHub_AI_News_Curator::article_priority( $art );
        }
        return [
            self::is_protected( $art ) ? 1 : 0,
            $priority,
            ! empty( $art['has_transcript'] ) ? 1 : 0,
            (int) ( $art['char_count'] ?? self::len( (string) ( $art['content'] ?? '' ) ) ),
        ];
    }

    public static function dedupe( array $articles, array &$summary ): array {
        $threshold = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_pool_duplicate_similarity() : self::DUPLICATE_SIMILAR;
        $threshold = (float) ( function_exists( 'apply_filters' ) ? apply_filters( 'presshub_ai_pool_duplicate_similarity', $threshold ) : $threshold );
        if ( $threshold <= 0 || $threshold > 1 || count( $articles ) < 2 ) {
            return $articles;
        }
        $sh    = array_map( [ __CLASS__, 'shingles' ], $articles );
        $ranks = array_map( [ __CLASS__, 'rank' ], $articles );
        $drop  = [];
        $n     = count( $articles );
        for ( $i = 0; $i < $n; $i++ ) {
            if ( isset( $drop[ $i ] ) ) {
                continue;
            }
            for ( $j = $i + 1; $j < $n; $j++ ) {
                if ( isset( $drop[ $j ] ) || self::similarity( $sh[ $i ], $sh[ $j ] ) < $threshold ) {
                    continue;
                }
                // Keep the better one; ties keep the earlier item.
                $loser  = ( $ranks[ $j ] <=> $ranks[ $i ] ) > 0 ? $i : $j;
                $winner = $loser === $i ? $j : $i;
                if ( self::is_protected( $articles[ $loser ] ) ) {
                    continue; // Never drop a manual upload or press release.
                }
                $drop[ $loser ] = $winner;
                if ( $loser === $i ) {
                    break;
                }
            }
        }
        $kept = [];
        foreach ( $articles as $k => $art ) {
            if ( isset( $drop[ $k ] ) ) {
                self::record_drop( $summary, $art, 'duplicate', (string) ( $articles[ $drop[ $k ] ]['url'] ?? '' ) );
                continue;
            }
            $kept[] = $art;
        }
        return $kept;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private static function fold( string $s ): string {
        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
        if ( null === self::$harvester && class_exists( 'PressHub_AI_News_Harvester' ) ) {
            self::$harvester = new PressHub_AI_News_Harvester();
        }
        if ( null !== self::$harvester ) {
            $s = self::$harvester->remove_accents_utf8( $s );
        }
        $s = str_replace( 'ς', 'σ', $s );
        return trim( preg_replace( '/\s+/u', ' ', $s ) ?? $s );
    }

    private static function word_count( string $text ): int {
        $w = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $w ) ? count( $w ) : 0;
    }

    private static function len( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
    }

    private static function sub( string $s, int $n ): string {
        return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
    }
}
