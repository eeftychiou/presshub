<?php
/**
 * PressHub_AI_News_Harvester — Auto-Detecting Hybrid News Harvester & Fallback Engine.
 *
 * Automates scraping and feed consumption of configured Greek news homepages:
 * - RSS / Atom Feed Auto-Detection & Parsing (Tier 0 Discovery)
 * - Probing common feed endpoints (/feed, /rss, /rss.xml)
 * - Semantic HTML & JSON-LD Scraper Fallback
 * - Anti-bot / Cloudflare Challenge Detection & Diagnostics
 * - Multi-Fallback Article Content Extraction
 * - Granular Diagnostic Logging & Health Status Tracking
 * - Manual Document / Notes Upload Merging & Deduplication
 * - Daily JSON Snapshot Persistence (wp-content/uploads/presshub-briefings/YYYY-MM-DD/raw-articles.json)
 *
 * @package PressHub_AI_Editor
 * @since 1.3.0
 * @updated 1.3.1 (Issue #5: Auto-Detecting Hybrid Engine & Diagnostics)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-token-logger.php';
require_once __DIR__ . '/class-logger.php';
require_once __DIR__ . '/class-url-fetcher.php';

class PressHub_AI_News_Harvester {

    /** Maximum links to crawl per homepage to respect time budgets (default 4 for 10-12 sources sampling). */
    const MAX_LINKS_PER_SOURCE = 4;

    /** Request timeout in seconds. */
    const REQUEST_TIMEOUT = 15;

    /** Default time budget for harvesting in seconds. */
    const DEFAULT_TIME_BUDGET = 60;

    /** User Agent for harvesting. */
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 PressHub-AI-Harvester/1.3.1';

    /**
     * Timestamp tracker for polite per-host crawl delays (host => float microtime).
     *
     * @var array<string, float>
     */
    protected array $last_host_request_time = [];

    /**
     * Polite rate-limiting throttle between successive requests to the same host.
     *
     * @param string $url Target request URL.
     */
    public function throttle_host_request( string $url ): void {
        $host = (string) parse_url( $url, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return;
        }

        $delay_ms = (int) apply_filters( 'presshub_ai_crawl_delay_ms', 500, $host );
        if ( $delay_ms > 0 && isset( $this->last_host_request_time[ $host ] ) ) {
            $elapsed_ms = ( microtime( true ) - $this->last_host_request_time[ $host ] ) * 1000;
            if ( $elapsed_ms < $delay_ms ) {
                usleep( (int) ( ( $delay_ms - $elapsed_ms ) * 1000 ) );
            }
        }
        $this->last_host_request_time[ $host ] = microtime( true );
    }

    /**
     * Reset per-host crawl delay timestamps.
     */
    public function reset_host_throttle(): void {
        $this->last_host_request_time = [];
    }

    /**
     * Get per-host crawl delay timestamps map.
     *
     * @return array<string, float>
     */
    public function get_last_host_request_time(): array {
        return $this->last_host_request_time;
    }

    /**
     * Get the maximum number of links to harvest per source.
     *
     * @param int|null $limit Optional explicit limit override.
     * @return int Effective maximum links per source.
     */
    public function get_max_links_per_source( $limit = null ): int {
        if ( null !== $limit && (int) $limit > 0 ) {
            return (int) $limit;
        }
        $filtered = apply_filters( 'presshub_ai_harvest_max_links_per_source', self::MAX_LINKS_PER_SOURCE );
        if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
            return (int) $filtered;
        }
        return self::MAX_LINKS_PER_SOURCE;
    }

    /**
     * Count words in a text string (Unicode aware).
     *
     * @param string $text Raw text.
     * @return int Number of words.
     */
    public function count_words( string $text ): int {
        $words = preg_split( '/\s+/u', trim( wp_strip_all_tags( $text ) ), -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $words ) ? count( $words ) : 0;
    }

    /**
     * Remove Greek and Latin diacritics/accents from a UTF-8 string for comparison.
     *
     * @param string $str Input string.
     * @return string Accent-free string.
     */
    public function remove_accents_utf8( string $str ): string {
        $accents = [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ΐ' => 'ι', 'ΰ' => 'υ', 'ϊ' => 'ι', 'ϋ' => 'υ',
            'Ά' => 'α', 'Έ' => 'ε', 'Ή' => 'η', 'Ί' => 'ι', 'Ό' => 'ο', 'Ύ' => 'υ', 'Ώ' => 'ω',
            'Ϊ' => 'ι', 'Ϋ' => 'υ',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c',
        ];
        return strtr( $str, $accents );
    }

    /**
     * Determine whether a title matches a category/section or utility page name.
     *
     * @param string $title Page or article title.
     * @return bool True if category title, false otherwise.
     */
    public function is_category_title( string $title ): bool {
        $clean = mb_strtolower( trim( preg_replace( '/[^\p{L}\p{N}\s]/u', '', $title ) ) );
        $clean = $this->remove_accents_utf8( $clean );
        if ( '' === $clean ) {
            return true;
        }
        $category_titles = [
            'πολιτικη', 'οικονομια', 'κοινωνια', 'κοσμος', 'αποψεις', 'πολιτισμος',
            'αθλητισμος', 'καιρος', 'παιχνιδια', 'στηλες', 'αρχικη', 'ειδησεις',
            'επικαιροτητα', 'ελλαδα', 'διεθνη', 'lifestyle', 'υγεια', 'αυτοκινητο',
            'τεχνολογια', 'ταξιδια', 'γυναικα', 'γνωμες', 'αρθρα', 'συνεντευξεις',
            'αφιερωματα', 'βουλη', 'ροη ειδησεων', 'ολες οι ειδησεις', 'δημοφιλη',
            'πρωτοσελιδα', 'ερεπλικα', 'ereplica', 'webtv', 'podcasts', 'podcast',
            'politics', 'economy', 'society', 'world', 'opinion', 'opinions',
            'culture', 'sports', 'weather', 'games', 'home', 'news', 'latest news',
            'top stories', 'all news', 'trending', 'breaking news', 'health',
            'technology', 'travel', 'entertainment', 'contact', 'about', 'terms',
            'privacy', 'privacy policy', 'terms of use', 'about us', 'contact us',
            'πολιτικη απορρητου', 'οροι χρησης', 'επικοινωνια', 'σχετικα με εμας',
        ];
        return in_array( $clean, $category_titles, true );
    }

    /**
     * Determine whether harvested article data meets quality & depth thresholds.
     * Discards items where title is <= 2 words, matches category names, or word count < 30 words (or < 8 words for RSS fallbacks).
     *
     * @param array $article_data Extracted article data array.
     * @return bool True if valid article, false otherwise.
     */
    public function is_valid_harvested_article( array $article_data ): bool {
        $title   = trim( (string) ( $article_data['title'] ?? '' ) );
        $content = trim( (string) ( $article_data['content'] ?? '' ) );

        if ( empty( $title ) || empty( $content ) ) {
            return false;
        }

        if ( $this->is_category_title( $title ) ) {
            return false;
        }

        $title_word_count = $this->count_words( $title );
        $min_title_words  = (int) apply_filters( 'presshub_ai_harvest_min_title_words', 3 );
        if ( $title_word_count < $min_title_words ) {
            return false;
        }

        $tier        = (string) ( $article_data['tier'] ?? '' );
        $is_rss_tier = ( 'rss_description' === $tier || 'rss' === $tier );

        $content_word_count = $this->count_words( $content );
        $min_content_words  = $is_rss_tier
            ? (int) apply_filters( 'presshub_ai_harvest_min_rss_words', 8 )
            : (int) apply_filters( 'presshub_ai_harvest_min_word_count', 30 );

        if ( $content_word_count < $min_content_words ) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether a given URL is a legitimate news article vs category/section/utility landing page.
     *
     * @param string $url      Target URL or path.
     * @param string $base_url Optional source base URL for resolving relative links.
     * @return bool True if valid article URL, false if category/section/utility page.
     */
    public function is_article_url( string $url, string $base_url = '' ): bool {
        $url = trim( $url );
        if ( empty( $url ) || '#' === $url || 0 === strpos( $url, '#' ) ) {
            return false;
        }

        if ( preg_match( '/^(javascript|mailto|tel|data):/i', $url ) ) {
            return false;
        }

        $abs = ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) )
            ? $url
            : $this->resolve_relative_url( $url, $base_url );

        if ( empty( $abs ) || ! filter_var( $abs, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        $parts = parse_url( $abs );
        $path  = trim( (string) ( $parts['path'] ?? '' ), '/' );

        if ( '' === $path || 'index.php' === $path || 'index.html' === $path ) {
            return false;
        }

        // Reject static assets and media files
        if ( preg_match( '/\.(?:jpe?g|png|gif|svg|webp|avif|pdf|docx?|xlsx?|zip|rar|tar|gz|mp3|mp4|avi|mov|wmv|ogg|wav|css|js|xml|json|ico|woff2?|ttf|eot)(?:\?.*)?$/i', $abs ) ) {
            return false;
        }

        // Check excluded substring patterns
        $excluded_patterns = [
            '/category/', '/categories/', '/tag/', '/tags/', '/author/', '/authors/',
            '/topic/', '/topics/', '/section/', '/sections/', '/page/', '/pages/',
            '/archive/', '/archives/', '/terms', '/privacy', '/oroi', '/politiki-aporritou',
            '/contact', '/epikoino', '/about', '/cookie', '/feed', '/rss', '/wp-json/',
            '/search', '/login', '/wp-login', '/newsletter', '/sitemap', '/advertis',
            '/subscription', '/cart', '/checkout', '/account', '/membership',
            'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'youtube.com',
            'linkedin.com', 'tiktok.com', 't.me', 'whatsapp.com', 'pinterest.com',
        ];

        foreach ( $excluded_patterns as $pattern ) {
            if ( false !== stripos( $abs, $pattern ) ) {
                return false;
            }
        }

        $category_slugs = [
            'politiki', 'oikonomia', 'koinonia', 'kosmos', 'apopseis', 'politismos',
            'athlitismos', 'kairos', 'paixnidia', 'ereplica', 'stiles', 'life',
            'media', 'webtv', 'podcasts', 'podcast', 'contact', 'privacy', 'terms',
            'about', 'rss', 'feed', 'category', 'categories', 'tag', 'tags',
            'section', 'sections', 'topic', 'topics', 'author', 'authors',
            'archive', 'archives', 'page', 'pages', 'search', 'login', 'wp-login',
            'newsletter', 'sitemap', 'advertis', 'advertising', 'diafimisi',
            'vouli', 'epikairothta', 'ellada', 'diethni', 'lifestyle', 'ygeia',
            'auto', 'texnologia', 'travel', 'gynaika', 'sports', 'sport',
            'politics', 'economy', 'business', 'society', 'world', 'opinion',
            'opinions', 'culture', 'weather', 'games', 'replica', 'columns',
            'technology', 'science', 'health', 'entertainment', 'video', 'videos',
            'audio', 'galleries', 'photos', 'live', 'blogs', 'blog', 'columnists',
            'epistimi', 'perivallon', 'astynomiko', 'dikastiko', 'diethnh',
            'oikonomika', 'politika', 'koinonika', 'news', 'home', 'main',
            'editorial', 'interviews', 'special-reports', 'focus', 'frontpage',
        ];

        $segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
        $segment_count = count( $segments );

        if ( 0 === $segment_count ) {
            return false;
        }

        // 1-segment URLs: e.g. /politiki/ or /article-1
        if ( 1 === $segment_count ) {
            $slug = strtolower( preg_replace( '/\.(html|htm|php|amp)$/i', '', $segments[0] ) );
            if ( in_array( $slug, $category_slugs, true ) ) {
                return false;
            }
            // Require article indicators: numeric ID (e.g. article-1 or news-12345), date, or substantive slug with at least 2 hyphens
            $has_numeric_id = (bool) preg_match( '/(?:-\d+$|\d{5,})/', $slug );
            $has_date       = (bool) preg_match( '/\d{4}/', $slug );
            $has_hyphens    = ( substr_count( $slug, '-' ) >= 2 );

            if ( ! $has_numeric_id && ! $has_date && ! $has_hyphens ) {
                return false;
            }
            return true;
        }

        // 2-segment URLs: e.g. /category/politiki, /news/101, /politiki/562345, /politiki/ayxiseis-syntaxeis-metra
        if ( 2 === $segment_count ) {
            $seg0 = strtolower( $segments[0] );
            $seg1 = strtolower( preg_replace( '/\.(html|htm|php|amp)$/i', '', $segments[1] ) );

            // Blacklisted taxonomy roots
            if ( in_array( $seg0, [ 'category', 'categories', 'tag', 'tags', 'topic', 'topics', 'section', 'sections', 'author', 'authors', 'archive', 'archives', 'page' ], true ) ) {
                return false;
            }

            // Both segments are category names (e.g. /politiki/apopseis/)
            if ( in_array( $seg0, $category_slugs, true ) && in_array( $seg1, $category_slugs, true ) ) {
                return false;
            }

            // Check article indicators on segment 1
            $is_numeric     = is_numeric( $seg1 );
            $has_numeric_id = (bool) preg_match( '/(?:-\d+$|\d{4,})/', $seg1 );
            $has_date       = (bool) preg_match( '/\d{4}/', $seg1 ) || (bool) preg_match( '/\d{4}/', $seg0 );
            $has_hyphens    = ( substr_count( $seg1, '-' ) >= 2 );
            $is_article_dir = in_array( $seg0, [ 'article', 'articles', 'story', 'stories', 'news', 'post', 'posts' ], true );

            if ( $is_numeric || $has_numeric_id || $has_date || $has_hyphens || ( $is_article_dir && ( is_numeric( $seg1 ) || substr_count( $seg1, '-' ) >= 1 ) ) ) {
                return true;
            }

            // Single word segment 1 under a category is a sub-category landing page (e.g. /politiki/vouli/)
            return false;
        }

        // 3+ segment URLs: e.g. /politiki/562345/synantisi-mitsotaki, /2026/08/26/fotia-eyvoia
        $last_seg = strtolower( preg_replace( '/\.(html|htm|php|amp)$/i', '', end( $segments ) ) );

        // Date component in path (e.g. /2026/08/26/ or /epikairothta/2026/08/26/)
        if ( preg_match( '#/\d{4}/\d{2}(?:/\d{2})?/#', '/' . $path . '/' ) ) {
            return true;
        }

        // Numeric ID segment anywhere (e.g. /politiki/562345/slug or /article/789123/slug)
        foreach ( $segments as $seg ) {
            if ( preg_match( '/^\d{4,}$/', $seg ) ) {
                return true;
            }
        }

        // Last segment article indicators
        $last_has_id      = (bool) preg_match( '/(?:-\d+$|\d{4,})/', $last_seg );
        $last_has_hyphens = ( substr_count( $last_seg, '-' ) >= 1 );

        if ( $last_has_id || $last_has_hyphens ) {
            return true;
        }

        // If all segments are in category slugs, it's a deep taxonomy hierarchy
        $all_categories = true;
        foreach ( $segments as $seg ) {
            $s = strtolower( preg_replace( '/\.(html|htm|php|amp)$/i', '', $seg ) );
            if ( ! in_array( $s, $category_slugs, true ) ) {
                $all_categories = false;
                break;
            }
        }
        if ( $all_categories ) {
            return false;
        }

        return true;
    }

    /**
     * Fetch homepage HTML or Feed and extract unique article URLs.
     *
     * @param string|array $source Target homepage, feed URL, or structured source.
     * @param int|null     $limit  Optional maximum number of articles to return.
     * @return string[] List of absolute article URLs.
     */
    public function fetch_homepage_links( $source, $limit = null ): array {
        $discovery = $this->discover_source_articles( $source, $limit );
        return $discovery['article_urls'] ?? [];
    }

    /**
     * Discover articles from a source using Auto-Detecting Hybrid Engine:
     * 1. Check if source URL returns XML feed directly.
     * 2. If HTML, inspect <link rel="alternate" type="application/rss+xml">.
     * 3. If no link tag feed, probe common feed endpoints (/feed, /rss, etc.).
     * 4. If valid feed found, parse clean headlines, canonical URLs, and summaries.
     * 5. Fallback to Semantic HTML & JSON-LD parser.
     *
     * @param string|array $source Source homepage URL or structured source array.
     * @param int|null     $limit  Optional maximum number of articles to sample.
     * @return array Discovery result details.
     */
    public function discover_source_articles( $source, $limit = null ): array {
        $url             = is_array( $source ) ? trim( (string) ( $source['url'] ?? '' ) ) : trim( (string) $source );
        $effective_limit = ( is_array( $source ) && isset( $source['max_articles'] ) && (int) $source['max_articles'] > 0 )
            ? (int) $source['max_articles']
            : $this->get_max_links_per_source( $limit );

        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return [
                'source_url'       => $url,
                'discovery_method' => 'NONE',
                'feed_url'         => null,
                'raw_links_count'  => 0,
                'article_urls'     => [],
                'feed_items'       => [],
                'status_code'      => 0,
                'latency_ms'       => 0,
                'is_blocked'       => false,
                'failure_reason'   => 'Invalid source URL',
            ];
        }

        $start_time = microtime( true );
        $this->throttle_host_request( $url );
        $response   = wp_remote_get( $url, [
            'timeout'     => self::REQUEST_TIMEOUT,
            'user-agent'  => self::USER_AGENT,
            'redirection' => 5,
            'headers'     => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml,text/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'el,el-GR;q=0.9,en;q=0.8',
            ],
        ] );

        $latency_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $err_msg = $response->get_error_message();
            PressHub_AI_Logger::log_harvest_source( $url, 'HTTP_GET', 0, $latency_ms, 0, $err_msg );
            return [
                'source_url'       => $url,
                'discovery_method' => 'NONE',
                'feed_url'         => null,
                'raw_links_count'  => 0,
                'article_urls'     => [],
                'feed_items'       => [],
                'status_code'      => 0,
                'latency_ms'       => $latency_ms,
                'is_blocked'       => true,
                'failure_reason'   => $err_msg,
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $this->is_cloudflare_or_blocked( $response, $body ) ) {
            $reason = ( 403 === $code ) ? '403 Forbidden' : ( ( 503 === $code ) ? '503 Service Unavailable' : 'Cloudflare Bot Challenge' );
            PressHub_AI_Logger::log_harvest_source( $url, 'SECURITY_BLOCK', $code, $latency_ms, 0, $reason );
            return [
                'source_url'       => $url,
                'discovery_method' => 'BLOCKED',
                'feed_url'         => null,
                'raw_links_count'  => 0,
                'article_urls'     => [],
                'feed_items'       => [],
                'status_code'      => $code,
                'latency_ms'       => $latency_ms,
                'is_blocked'       => true,
                'failure_reason'   => $reason,
            ];
        }

        if ( 200 !== $code || empty( $body ) ) {
            $reason = 'HTTP ' . $code . ( empty( $body ) ? ' (Empty Body)' : '' );
            PressHub_AI_Logger::log_harvest_source( $url, 'HTTP_STATUS', $code, $latency_ms, 0, $reason );
            return [
                'source_url'       => $url,
                'discovery_method' => 'ERROR',
                'feed_url'         => null,
                'raw_links_count'  => 0,
                'article_urls'     => [],
                'feed_items'       => [],
                'status_code'      => $code,
                'latency_ms'       => $latency_ms,
                'is_blocked'       => false,
                'failure_reason'   => $reason,
            ];
        }

        // Case A: The landing page URL is itself directly an XML RSS / Atom feed
        if ( $this->is_xml_feed( $body ) ) {
            $feed_items = $this->parse_feed_items( $body, $url );
            if ( ! empty( $feed_items ) ) {
                $urls = array_values( array_unique( array_filter( array_column( $feed_items, 'url' ) ) ) );
                PressHub_AI_Logger::log_harvest_source( $url, 'FEED_DIRECT', $code, $latency_ms, count( $urls ) );
                return [
                    'source_url'       => $url,
                    'discovery_method' => 'FEED_DIRECT',
                    'feed_url'         => $url,
                    'raw_links_count'  => count( $feed_items ),
                    'article_urls'     => array_slice( $urls, 0, $effective_limit ),
                    'feed_items'       => $feed_items,
                    'status_code'      => $code,
                    'latency_ms'       => $latency_ms,
                    'is_blocked'       => false,
                    'failure_reason'   => null,
                ];
            }
        }

        // Case B: Auto-detect RSS/Atom <link> tags in HTML head
        $discovered_feed_url = $this->discover_feed_url( $body, $url );
        if ( ! empty( $discovered_feed_url ) ) {
            $this->throttle_host_request( $discovered_feed_url );
            $feed_res = wp_remote_get( $discovered_feed_url, [
                'timeout'     => self::REQUEST_TIMEOUT,
                'user-agent'  => self::USER_AGENT,
                'redirection' => 3,
            ] );
            if ( ! is_wp_error( $feed_res ) && 200 === wp_remote_retrieve_response_code( $feed_res ) ) {
                $feed_body  = (string) wp_remote_retrieve_body( $feed_res );
                $feed_items = $this->parse_feed_items( $feed_body, $discovered_feed_url );
                if ( ! empty( $feed_items ) ) {
                    $urls = array_values( array_unique( array_filter( array_column( $feed_items, 'url' ) ) ) );
                    PressHub_AI_Logger::log_harvest_source( $url, 'RSS_FEED', $code, $latency_ms, count( $urls ) );
                    return [
                        'source_url'       => $url,
                        'discovery_method' => 'RSS_FEED',
                        'feed_url'         => $discovered_feed_url,
                        'raw_links_count'  => count( $feed_items ),
                        'article_urls'     => array_slice( $urls, 0, $effective_limit ),
                        'feed_items'       => $feed_items,
                        'status_code'      => $code,
                        'latency_ms'       => $latency_ms,
                        'is_blocked'       => false,
                        'failure_reason'   => null,
                    ];
                }
            }
        }

        // Case C: Probe common feed endpoints (/feed, /rss, /rss.xml, /feed/news)
        $probed_feed = $this->probe_feed_endpoints( $url );
        if ( ! empty( $probed_feed ) && ! empty( $probed_feed['items'] ) ) {
            $feed_items = $probed_feed['items'];
            $urls       = array_values( array_unique( array_filter( array_column( $feed_items, 'url' ) ) ) );
            PressHub_AI_Logger::log_harvest_source( $url, 'RSS_FEED', $code, $latency_ms, count( $urls ) );
            return [
                'source_url'       => $url,
                'discovery_method' => 'RSS_FEED',
                'feed_url'         => $probed_feed['feed_url'],
                'raw_links_count'  => count( $feed_items ),
                'article_urls'     => array_slice( $urls, 0, $effective_limit ),
                'feed_items'       => $feed_items,
                'status_code'      => $code,
                'latency_ms'       => $latency_ms,
                'is_blocked'       => false,
                'failure_reason'   => null,
            ];
        }

        // Case D: Semantic HTML Scraper Fallback
        $html_links = $this->extract_article_links_from_html( $body, $url );
        PressHub_AI_Logger::log_harvest_source( $url, 'HTML_SCRAPER', $code, $latency_ms, count( $html_links ) );

        return [
            'source_url'       => $url,
            'discovery_method' => 'HTML_SCRAPER',
            'feed_url'         => null,
            'raw_links_count'  => count( $html_links ),
            'article_urls'     => array_slice( $html_links, 0, $effective_limit ),
            'feed_items'       => [],
            'status_code'      => $code,
            'latency_ms'       => $latency_ms,
            'is_blocked'       => false,
            'failure_reason'   => empty( $html_links ) ? 'No article links extracted from HTML' : null,
        ];
    }

    /**
     * Determine if content is an XML RSS/Atom feed.
     */
    public function is_xml_feed( string $body ): bool {
        $body_start = substr( ltrim( $body ), 0, 500 );
        if ( preg_match( '/<(?:\\?xml|rss|feed|rdf:RDF)/i', $body_start ) ) {
            if ( false !== stripos( $body_start, '<rss' ) || false !== stripos( $body_start, '<feed' ) || false !== stripos( $body_start, '<channel' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse items from RSS 2.0, RSS 1.0/RDF, or Atom feed XML.
     *
     * @param string $xml_content Raw XML string.
     * @param string $base_url    Base source URL.
     * @return array List of parsed item arrays.
     */
    public function parse_feed_items( string $xml_content, string $base_url = '' ): array {
        if ( empty( trim( $xml_content ) ) ) {
            return [];
        }

        $prev_use_errors = libxml_use_internal_errors( true );
        try {
            $xml = simplexml_load_string( $xml_content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING );
        } catch ( Throwable $e ) {
            $xml = false;
        }
        libxml_clear_errors();
        libxml_use_internal_errors( $prev_use_errors );

        if ( false === $xml ) {
            return [];
        }

        $items = [];

        // RSS 2.0 / RSS 0.92 (<channel><item>)
        if ( isset( $xml->channel->item ) ) {
            foreach ( $xml->channel->item as $entry ) {
                $item_data = $this->parse_rss_entry( $entry, $base_url );
                if ( ! empty( $item_data['url'] ) ) {
                    $items[] = $item_data;
                }
            }
        }
        // RSS 1.0 / RDF (<item>)
        elseif ( isset( $xml->item ) ) {
            foreach ( $xml->item as $entry ) {
                $item_data = $this->parse_rss_entry( $entry, $base_url );
                if ( ! empty( $item_data['url'] ) ) {
                    $items[] = $item_data;
                }
            }
        }
        // Atom (<feed><entry>)
        elseif ( isset( $xml->entry ) ) {
            foreach ( $xml->entry as $entry ) {
                $item_data = $this->parse_atom_entry( $entry, $base_url );
                if ( ! empty( $item_data['url'] ) ) {
                    $items[] = $item_data;
                }
            }
        }

        return $items;
    }

    /**
     * Parse a single RSS XML item element.
     */
    protected function parse_rss_entry( SimpleXMLElement $entry, string $base_url = '' ): array {
        $namespaces = $entry->getNamespaces( true );
        $content_ns = isset( $namespaces['content'] ) ? $entry->children( $namespaces['content'] ) : null;
        $dc_ns      = isset( $namespaces['dc'] ) ? $entry->children( $namespaces['dc'] ) : null;

        $title = (string) $entry->title;
        $link  = trim( (string) $entry->link );
        if ( empty( $link ) && isset( $entry->guid ) && filter_var( (string) $entry->guid, FILTER_VALIDATE_URL ) ) {
            $link = trim( (string) $entry->guid );
        }

        $content = '';
        if ( $content_ns && isset( $content_ns->encoded ) ) {
            $content = (string) $content_ns->encoded;
        }
        if ( empty( $content ) && isset( $entry->description ) ) {
            $content = (string) $entry->description;
        }

        $pub_date = (string) $entry->pubDate;
        if ( empty( $pub_date ) && $dc_ns && isset( $dc_ns->date ) ) {
            $pub_date = (string) $dc_ns->date;
        }

        $clean_url = $this->resolve_relative_url( $link, $base_url );
        $clean_desc = isset( $entry->description ) ? trim( wp_strip_all_tags( (string) $entry->description ) ) : '';

        return [
            'url'          => $clean_url,
            'title'        => trim( wp_strip_all_tags( $title ) ),
            'content'      => trim( wp_strip_all_tags( $content ) ),
            'description'  => $clean_desc,
            'published_at' => ! empty( $pub_date ) ? gmdate( 'c', strtotime( $pub_date ) ?: time() ) : '',
            'guid'         => (string) ( $entry->guid ?? '' ),
        ];
    }

    /**
     * Parse a single Atom XML entry element.
     */
    protected function parse_atom_entry( SimpleXMLElement $entry, string $base_url = '' ): array {
        $title = (string) $entry->title;
        $link  = '';

        if ( isset( $entry->link ) ) {
            foreach ( $entry->link as $l ) {
                $attrs = $l->attributes();
                $rel   = (string) ( $attrs['rel'] ?? 'alternate' );
                $href  = (string) ( $attrs['href'] ?? '' );
                if ( ( 'alternate' === $rel || empty( $rel ) ) && ! empty( $href ) ) {
                    $link = trim( $href );
                    break;
                }
            }
            if ( empty( $link ) && isset( $entry->link['href'] ) ) {
                $link = trim( (string) $entry->link['href'] );
            }
        }
        if ( empty( $link ) && isset( $entry->id ) && filter_var( (string) $entry->id, FILTER_VALIDATE_URL ) ) {
            $link = trim( (string) $entry->id );
        }

        $content = '';
        if ( isset( $entry->content ) ) {
            $content = (string) $entry->content;
        } elseif ( isset( $entry->summary ) ) {
            $content = (string) $entry->summary;
        }

        $published     = (string) ( $entry->published ?? ( $entry->updated ?? '' ) );
        $clean_url     = $this->resolve_relative_url( $link, $base_url );
        $clean_summary = isset( $entry->summary ) ? trim( wp_strip_all_tags( (string) $entry->summary ) ) : '';

        return [
            'url'          => $clean_url,
            'title'        => trim( wp_strip_all_tags( $title ) ),
            'content'      => trim( wp_strip_all_tags( $content ) ),
            'description'  => $clean_summary,
            'published_at' => ! empty( $published ) ? gmdate( 'c', strtotime( $published ) ?: time() ) : '',
            'guid'         => (string) ( $entry->id ?? '' ),
        ];
    }

    /**
     * Inspect HTML head for <link rel="alternate" type="application/rss+xml"> tags.
     */
    public function discover_feed_url( string $html, string $base_url ): ?string {
        if ( preg_match( '#<link\s+[^>]*rel=["\']alternate["\'][^>]*type=["\'](?:application/(?:rss|atom)\+xml|text/xml)["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m )
            || preg_match( '#<link\s+[^>]*type=["\'](?:application/(?:rss|atom)\+xml|text/xml)["\'][^>]*rel=["\']alternate["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m )
            || preg_match( '#<link\s+[^>]*href=["\']([^"\']+)["\'][^>]*type=["\'](?:application/(?:rss|atom)\+xml|text/xml)["\'][^>]*rel=["\']alternate["\']#i', $html, $m ) ) {
            $feed_href = trim( $m[1] );
            return $this->resolve_relative_url( $feed_href, $base_url );
        }
        return null;
    }

    /**
     * Probe common feed endpoints on the source domain.
     */
    public function probe_feed_endpoints( string $base_url ): ?array {
        $parsed = parse_url( $base_url );
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? '';
        if ( empty( $host ) ) {
            return null;
        }

        $common_paths = [ '/feed', '/rss', '/rss.xml', '/feed/news' ];

        foreach ( $common_paths as $path ) {
            $probe_url = $scheme . '://' . $host . $path;
            $this->throttle_host_request( $probe_url );
            $res = wp_remote_get( $probe_url, [
                'timeout'     => 5,
                'user-agent'  => self::USER_AGENT,
                'redirection' => 3,
            ] );

            if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
                $body = (string) wp_remote_retrieve_body( $res );
                if ( $this->is_xml_feed( $body ) ) {
                    $items = $this->parse_feed_items( $body, $probe_url );
                    if ( ! empty( $items ) ) {
                        return [
                            'feed_url' => $probe_url,
                            'items'    => $items,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse HTML and extract valid article links, prioritizing editorial headings, lead/hero containers,
     * card components, and semantic articles over general navigation and footer anchors.
     *
     * @param string $html     Raw HTML content.
     * @param string $base_url Homepage base URL for resolving relative links.
     * @return string[] Array of unique, normalized article URLs.
     */
    public function extract_article_links_from_html( string $html, string $base_url ): array {
        $parsed_base = parse_url( $base_url );
        $base_scheme = $parsed_base['scheme'] ?? 'https';
        $base_host   = $parsed_base['host'] ?? '';

        if ( empty( $base_host ) ) {
            return [];
        }

        // 1. JSON-LD Schema links (ItemList, NewsArticle)
        $json_ld_links = $this->extract_links_from_json_ld( $html, $base_url );

        // 2. Clean HTML: Strip navigation, header, footer, script, style, form, aside to avoid utility links
        $clean_html = preg_replace( '#<(script|style|noscript|header|footer|nav|form|aside)[^>]*>.*?</(script|style|noscript|header|footer|nav|form|aside)>#is', ' ', $html );
        $clean_html = preg_replace( '#<(?:div|section|ul|ol|nav)[^>]*class=["\'][^"\']*(?:main-menu|site-nav|navigation|navbar|main-nav|top-nav|subnav|site-header|site-footer|footer-links|sidebar|social-share|share-buttons)[^"\']*["\'][^>]*>.*?</(?:div|section|ul|ol|nav)>#is', ' ', $clean_html );

        // 3. Priority Buckets
        // Bucket 2: Priority Editorial Containers, Heading Anchors, & <article> Tags
        preg_match_all( '#<(?:div|section|article|li)[^>]*class=["\'][^"\']*(?:lead|hero|top-story|top_story|top-news|top_news|featured|entry-title|story|card)[^"\']*["\'][^>]*>(.*?)</(?:div|section|article|li)>#is', $clean_html, $container_matches );
        $priority_html_blocks = implode( ' ', $container_matches[0] ?? [] );

        preg_match_all( '#<article[^>]*>(.*?)</article>#is', $clean_html, $article_matches );
        $priority_html_blocks .= ' ' . implode( ' ', $article_matches[0] ?? [] );

        // Headings containing links (e.g. <h2><a href="...">...</a></h2>) and links wrapping headings (e.g. <a href="..."><h2>...</h2></a>)
        preg_match_all( '#<h[1-4][^>]*>.*?<a\s+[^>]*?href=["\']([^"\']+)["\'][^>]*>.*?</h[1-4]>#is', $clean_html, $h_matches1 );
        preg_match_all( '#<a\s+[^>]*?href=["\']([^"\']+)["\'][^>]*>\s*<h[1-4][^>]*>.*?</h[1-4]>\s*</a>#is', $clean_html, $h_matches2 );
        $heading_hrefs = array_merge( $h_matches1[1] ?? [], $h_matches2[1] ?? [] );

        preg_match_all( '#<a\s+[^>]*?href=["\']([^"\']+)["\'][^>]*>#i', $priority_html_blocks, $p_matches );
        $priority_hrefs = array_merge( $heading_hrefs, $p_matches[1] ?? [] );

        // Bucket 3: General DOM links across the page body
        preg_match_all( '#<a\s+[^>]*?href=["\']([^"\']+)["\'][^>]*>#i', $clean_html, $gen_matches );
        $general_hrefs = $gen_matches[1] ?? [];

        $candidate_hrefs = array_merge( $json_ld_links, $priority_hrefs, $general_hrefs );
        $discovered_links = [];

        foreach ( $candidate_hrefs as $href ) {
            $href = trim( (string) $href );
            if ( '' === $href || '#' === $href || 0 === strpos( $href, '#' ) ) {
                continue;
            }

            if ( preg_match( '/^(javascript|mailto|tel|data):/i', $href ) ) {
                continue;
            }

            $norm = $this->normalize_article_url( $href, $base_url );
            if ( ! empty( $norm ) && ! in_array( $norm, $discovered_links, true ) ) {
                $discovered_links[] = $norm;
            }
        }

        return $discovered_links;
    }

    /**
     * Extract article links from JSON-LD ItemList / NewsArticle schemas.
     */
    protected function extract_links_from_json_ld( string $html, string $base_url ): array {
        preg_match_all( '#<script\s+[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches );
        if ( empty( $matches[1] ) ) {
            return [];
        }

        $links = [];
        foreach ( $matches[1] as $json_str ) {
            $decoded = json_decode( trim( $json_str ), true );
            if ( ! is_array( $decoded ) ) {
                continue;
            }

            $items = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ? $decoded['@graph'] : ( isset( $decoded[0] ) ? $decoded : [ $decoded ] );
            foreach ( $items as $obj ) {
                if ( ! is_array( $obj ) ) {
                    continue;
                }
                // ItemList / Carousel
                if ( isset( $obj['itemListElement'] ) && is_array( $obj['itemListElement'] ) ) {
                    foreach ( $obj['itemListElement'] as $elem ) {
                        $item_url = $elem['url'] ?? ( $elem['item']['@id'] ?? ( $elem['item']['url'] ?? '' ) );
                        if ( is_string( $item_url ) && ! empty( $item_url ) ) {
                            $norm = $this->normalize_article_url( $item_url, $base_url );
                            if ( ! empty( $norm ) ) {
                                $links[] = $norm;
                            }
                        }
                    }
                }
                // Direct NewsArticle
                if ( isset( $obj['@type'] ) && is_string( $obj['@type'] ) && false !== stripos( $obj['@type'], 'Article' ) ) {
                    $art_url = $obj['url'] ?? ( $obj['mainEntityOfPage'] ?? '' );
                    if ( is_string( $art_url ) && ! empty( $art_url ) ) {
                        $norm = $this->normalize_article_url( $art_url, $base_url );
                        if ( ! empty( $norm ) ) {
                            $links[] = $norm;
                        }
                    }
                }
            }
        }

        return $links;
    }

    /**
     * Normalize and validate candidate article URL.
     */
    protected function normalize_article_url( string $href, string $base_url ): string {
        $abs = $this->resolve_relative_url( $href, $base_url );
        if ( empty( $abs ) ) {
            return '';
        }

        $parts = parse_url( $abs );
        if ( empty( $parts['host'] ) ) {
            return '';
        }

        $path = $parts['path'] ?? '/';
        if ( '/' === $path || '' === $path ) {
            return '';
        }

        $parsed_base = parse_url( $base_url );
        $base_host   = $parsed_base['host'] ?? '';

        $target_host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
        $source_host = strtolower( preg_replace( '/^www\./', '', $base_host ) );

        if ( ! empty( $source_host ) && $target_host !== $source_host && ! preg_match( '/\.' . preg_quote( $source_host, '/' ) . '$/', $target_host ) ) {
            if ( false === strpos( $target_host, 'amna.gr' ) && false === strpos( $target_host, 'ape-mpe.gr' ) ) {
                return '';
            }
        }

        if ( ! $this->is_article_url( $abs, $base_url ) ) {
            return '';
        }

        return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $path;
    }

    /**
     * Resolve relative URL against a base URL.
     */
    protected function resolve_relative_url( string $href, string $base_url ): string {
        $href = trim( $href );
        if ( empty( $href ) ) {
            return '';
        }

        if ( 0 === strpos( $href, 'http://' ) || 0 === strpos( $href, 'https://' ) ) {
            return $href;
        }

        $parsed_base = parse_url( $base_url );
        $scheme      = $parsed_base['scheme'] ?? 'https';
        $host        = $parsed_base['host'] ?? '';

        if ( empty( $host ) ) {
            return $href;
        }

        if ( 0 === strpos( $href, '//' ) ) {
            return $scheme . ':' . $href;
        } elseif ( 0 === strpos( $href, '/' ) ) {
            return $scheme . '://' . $host . $href;
        } else {
            return $scheme . '://' . $host . '/' . ltrim( $href, '/' );
        }
    }

    /**
     * Detect Cloudflare challenge, 403 forbidden, 503 service unavailable, or rate limit blocks.
     *
     * @param array|WP_Error $response HTTP response array or WP_Error.
     * @param string         $body     Response body string.
     * @return bool True if blocked by bot protection or forbidden status.
     */
    public function is_cloudflare_or_blocked( $response, string $body = '' ): bool {
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, [ 403, 503, 429 ], true ) ) {
            return true;
        }

        if ( empty( $body ) && is_array( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
        }

        $challenge_signatures = [
            'Just a moment...',
            'cf-chl-bypass',
            'Attention Required! | Cloudflare',
            'challenge-platform',
            'cf-browser-verification',
            'cf-im-under-attack',
            'Checking if the site connection is secure',
            'Ray ID:',
            'Cloudflare Ray ID:',
            '<title>Access Denied</title>',
            'Security Check | Cloudflare',
        ];

        foreach ( $challenge_signatures as $sig ) {
            if ( false !== stripos( $body, $sig ) ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Get the time budget in seconds for harvesting.
     *
     * @param int|float|null $budget Optional explicit budget override.
     * @return int|float Effective time budget in seconds.
     */
    public function get_time_budget( $budget = null ) {
        if ( null !== $budget && (float) $budget > 0 ) {
            return (float) $budget;
        }
        $configured = (int) get_option( 'presshub_ai_harvest_time_budget', 60 );
        $filtered   = apply_filters( 'presshub_ai_harvest_time_budget', $configured > 0 ? $configured : 60 );
        return is_numeric( $filtered ) && (float) $filtered > 0 ? (float) $filtered : 60;
    }

    /**
     * Harvest a single source with execution time budgeting and error resilience.
     *
     * @param array|string   $source      Source config array, URL string, or source ID.
     * @param string         $date        Target date in YYYY-MM-DD format (defaults to current date).
     * @param int|float|null $time_budget Optional time budget in seconds.
     * @return array Harvest payload snapshot with diagnostics and articles.
     */
    public function harvest_source( $source, string $date = '', $time_budget = null ): array {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }
        $start_time  = microtime( true );
        $time_budget = $this->get_time_budget( $time_budget );

        // Resolve source structure
        $source_struct = null;
        if ( is_string( $source ) && ! preg_match( '/^https?:\/\//i', trim( $source ) ) ) {
            if ( class_exists( 'PressHub_AI_Settings_Storage' ) ) {
                $all_sources = PressHub_AI_Settings_Storage::get_briefing_sources();
                $normalized  = PressHub_AI_Settings_Storage::normalize_sources( $all_sources );
                foreach ( $normalized as $item ) {
                    if ( ( $item['id'] ?? '' ) === $source ) {
                        $source_struct = $item;
                        break;
                    }
                }
            }
        }

        if ( empty( $source_struct ) ) {
            if ( is_string( $source ) ) {
                $url = trim( $source );
                $source_struct = [
                    'id'           => 'src_' . substr( md5( $url ), 0, 8 ),
                    'name'         => (string) ( parse_url( $url, PHP_URL_HOST ) ?: $url ),
                    'url'          => $url,
                    'type'         => 'text_news',
                    'enabled'      => true,
                    'category'     => 'General',
                    'notes'        => '',
                    'max_articles' => 5,
                ];
            } elseif ( is_array( $source ) ) {
                $url = (string) ( $source['url'] ?? '' );
                $source_struct = array_merge( [
                    'id'           => 'src_' . substr( md5( $url ), 0, 8 ),
                    'name'         => (string) ( $source['name'] ?? ( parse_url( $url, PHP_URL_HOST ) ?: $url ) ),
                    'url'          => $url,
                    'type'         => (string) ( $source['type'] ?? 'text_news' ),
                    'enabled'      => isset( $source['enabled'] ) ? (bool) $source['enabled'] : true,
                    'category'     => (string) ( $source['category'] ?? 'General' ),
                    'notes'        => (string) ( $source['notes'] ?? '' ),
                    'max_articles' => isset( $source['max_articles'] ) ? (int) $source['max_articles'] : 5,
                ], $source );
            }
        }

        if ( empty( $source_struct ) || empty( $source_struct['url'] ) ) {
            return [
                'date'               => $date,
                'harvested_at'       => gmdate( 'c' ),
                'sources'            => [],
                'configured_sources' => [],
                'blocked_sources'    => [],
                'source_health'      => [],
                'diagnostics'        => [],
                'articles'           => [],
                'budget_exceeded'    => false,
                'harvested_count'    => 0,
            ];
        }

        $source_url   = $source_struct['url'];
        $source_host  = (string) ( parse_url( $source_url, PHP_URL_HOST ) ?: $source_url );
        $src_enabled  = ! empty( $source_struct['enabled'] );
        $src_type     = $source_struct['type'] ?? 'text_news';
        $source_limit = isset( $source_struct['max_articles'] ) && (int) $source_struct['max_articles'] > 0
            ? (int) $source_struct['max_articles']
            : $this->get_max_links_per_source();

        // Load existing snapshot to preserve existing articles
        $existing = $this->load_snapshot( $date );
        $payload  = ( is_array( $existing ) && ! empty( $existing ) ) ? $existing : [
            'date'               => $date,
            'harvested_at'       => gmdate( 'c' ),
            'sources'            => [],
            'configured_sources' => [],
            'blocked_sources'    => [],
            'source_health'      => [],
            'diagnostics'        => [],
            'articles'           => [],
            'budget_exceeded'    => false,
            'harvested_count'    => 0,
        ];

        if ( ! in_array( $source_url, $payload['sources'] ?? [], true ) ) {
            $payload['sources'][] = $source_url;
        }

        $seen_urls   = [];
        $seen_titles = [];
        foreach ( ( $payload['articles'] ?? [] ) as $art ) {
            if ( ! empty( $art['url'] ) ) {
                $seen_urls[] = $art['url'];
            }
            $t = mb_strtolower( trim( (string) ( $art['title'] ?? '' ) ) );
            if ( '' !== $t ) {
                $seen_titles[] = $t;
            }
        }

        if ( ! $src_enabled || ! in_array( $src_type, [ 'text_news', 'rss_feed' ], true ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::debug( sprintf( "Source '%s' (%s) is disabled or non-text (type: %s); skipping single harvest.", $source_struct['name'], $source_url, $src_type ) );
            }
            return $payload;
        }

        $budget_exceeded       = false;
        $source_articles_count = 0;

        // Check budget before discovery
        if ( ( microtime( true ) - $start_time ) >= $time_budget ) {
            $budget_exceeded = true;
        } else {
            try {
                $discovery = $this->discover_source_articles( $source_struct, $source_limit );
            } catch ( \Throwable $e ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::error( sprintf( 'Discovery failed for source %s: %s', $source_url, $e->getMessage() ), [ 'exception' => $e ] );
                }
                $discovery = [
                    'source_url'       => $source_url,
                    'discovery_method' => 'ERROR',
                    'feed_url'         => null,
                    'raw_links_count'  => 0,
                    'article_urls'     => [],
                    'feed_items'       => [],
                    'status_code'      => 0,
                    'latency_ms'       => 0,
                    'is_blocked'       => false,
                    'failure_reason'   => $e->getMessage(),
                ];
            }

            if ( $discovery['is_blocked'] ) {
                if ( ! in_array( $source_url, $payload['blocked_sources'] ?? [], true ) ) {
                    $payload['blocked_sources'][] = $source_url;
                }
            } else {
                $links_to_crawl = array_slice( $discovery['article_urls'] ?? [], 0, $source_limit );

                foreach ( $links_to_crawl as $article_url ) {
                    // Check budget before each article fetch
                    if ( ( microtime( true ) - $start_time ) >= $time_budget ) {
                        $budget_exceeded = true;
                        if ( class_exists( 'PressHub_AI_Logger' ) ) {
                            PressHub_AI_Logger::warning( sprintf(
                                'Single source harvest time budget (%ds) reached while crawling %s. Preserving %d articles collected.',
                                $time_budget,
                                $source_url,
                                count( $payload['articles'] )
                            ) );
                        }
                        break;
                    }

                    if ( in_array( $article_url, $seen_urls, true ) ) {
                        continue;
                    }

                    try {
                        $article_data = $this->fetch_and_parse_article( $article_url, $source_host );
                    } catch ( \Throwable $e ) {
                        if ( class_exists( 'PressHub_AI_Logger' ) ) {
                            PressHub_AI_Logger::error( sprintf( 'Article fetch failed for %s: %s', $article_url, $e->getMessage() ), [ 'exception' => $e ] );
                        }
                        $article_data = null;
                    }

                    // Fallback to feed item if available
                    if ( ( empty( $article_data ) || empty( $article_data['content'] ) ) && ! empty( $discovery['feed_items'] ) ) {
                        foreach ( $discovery['feed_items'] as $fitem ) {
                            $fitem_url = rtrim( (string) ( $fitem['url'] ?? '' ), '/' );
                            $curr_url  = rtrim( (string) $article_url, '/' );
                            if ( $fitem_url === $curr_url || ( $fitem['url'] ?? '' ) === $article_url ) {
                                $feed_content = ! empty( $fitem['content'] ) ? $fitem['content'] : ( $fitem['description'] ?? '' );
                                $feed_content = trim( (string) $feed_content );
                                if ( ! empty( $feed_content ) ) {
                                    $feed_title = trim( (string) ( $fitem['title'] ?? '' ) );
                                    // Combine title + description if description is concise so the article maintains rich editorial context.
                                    if ( ! empty( $feed_title ) && $this->count_words( $feed_content ) < 30 && false === mb_stripos( $feed_content, $feed_title ) ) {
                                        $feed_content = $feed_title . ' — ' . $feed_content;
                                    }
                                    $candidate = [
                                        'url'             => $article_url,
                                        'title'           => $feed_title,
                                        'content'         => $feed_content,
                                        'source'          => $source_host ?: ( parse_url( $article_url, PHP_URL_HOST ) ?: 'Feed' ),
                                        'tier'            => 'rss_description',
                                        'published_at'    => $fitem['published_at'] ?? '',
                                        'char_count'      => mb_strlen( $feed_content ),
                                        'is_manual'       => false,
                                        'harvested_at'    => gmdate( 'c' ),
                                        'fallback_reason' => 'waf_or_http_error',
                                    ];
                                    if ( $this->is_valid_harvested_article( $candidate ) ) {
                                        $article_data = $candidate;
                                        if ( class_exists( 'PressHub_AI_Logger' ) ) {
                                            PressHub_AI_Logger::info( sprintf( 'Used RSS description fallback for %s due to HTML extraction/WAF block.', $article_url ) );
                                        }
                                    }
                                }
                                break;
                            }
                        }
                    }

                    if ( empty( $article_data ) || empty( $article_data['content'] ) ) {
                        continue;
                    }

                    $norm_title = mb_strtolower( trim( (string) ( $article_data['title'] ?? '' ) ) );
                    if ( '' !== $norm_title && in_array( $norm_title, $seen_titles, true ) ) {
                        continue;
                    }

                    $seen_urls[]           = $article_url;
                    $seen_titles[]         = $norm_title;
                    $payload['articles'][] = $article_data;
                    $source_articles_count++;
                }
            }

            $health_status = 'ok';
            if ( $discovery['is_blocked'] ) {
                $health_status = 'blocked';
            } elseif ( 0 === $source_articles_count ) {
                $health_status = 'warning';
            }

            $diag_item = [
                'url'              => $source_url,
                'host'             => $source_host,
                'status'           => $health_status,
                'http_code'        => $discovery['status_code'] ?? 0,
                'latency_ms'       => $discovery['latency_ms'] ?? 0,
                'discovery_method' => $discovery['discovery_method'] ?? 'UNKNOWN',
                'feed_url'         => $discovery['feed_url'] ?? null,
                'raw_links_count'  => $discovery['raw_links_count'] ?? 0,
                'articles_yielded' => $source_articles_count,
                'failure_reason'   => $discovery['failure_reason'] ?? ( ( 0 === $source_articles_count && ! $discovery['is_blocked'] ) ? '0 articles scraped' : null ),
            ];

            // Update or append source health
            $sh_found = false;
            foreach ( $payload['source_health'] as $idx => $sh ) {
                if ( ( $sh['url'] ?? '' ) === $source_url ) {
                    $payload['source_health'][ $idx ] = $diag_item;
                    $sh_found = true;
                    break;
                }
            }
            if ( ! $sh_found ) {
                $payload['source_health'][] = $diag_item;
            }

            $dg_found = false;
            foreach ( $payload['diagnostics'] as $idx => $dg ) {
                if ( ( $dg['url'] ?? '' ) === $source_url ) {
                    $payload['diagnostics'][ $idx ] = $diag_item;
                    $dg_found = true;
                    break;
                }
            }
            if ( ! $dg_found ) {
                $payload['diagnostics'][] = $diag_item;
            }
        }

        $duration_ms                 = (int) round( ( microtime( true ) - $start_time ) * 1000 );
        $payload['harvested_at']     = gmdate( 'c' );
        $payload['budget_exceeded']  = $budget_exceeded;
        $payload['harvested_count']  = count( $payload['articles'] );
        $payload['duration_ms']      = $duration_ms;
        $payload['time_budget']      = $time_budget;

        if ( $budget_exceeded ) {
            $payload['notice'] = sprintf(
                /* translators: 1: number of articles, 2: elapsed time in seconds, 3: time budget in seconds */
                __( 'Harvest reached time budget (%2$ds / %3$ds). %1$d articles saved.', 'presshub-ai-editor' ),
                count( $payload['articles'] ),
                (int) round( $duration_ms / 1000 ),
                $time_budget
            );
        }

        $this->save_snapshot( $date, $payload );

        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_scrape_request(
                'scrape_harvest_single',
                1,
                $source_articles_count,
                $duration_ms,
                $budget_exceeded ? 'partial' : 'success',
                null,
                [
                    'source_url'      => $source_url,
                    'date'            => $date,
                    'budget_exceeded' => $budget_exceeded,
                    'time_budget_sec' => $time_budget,
                ]
            );
        }

        PressHub_AI_Logger::info( sprintf(
            'Single source harvest completed for %s (%s): %d articles collected, duration %dms%s',
            $source_url,
            $date,
            $source_articles_count,
            $duration_ms,
            $budget_exceeded ? ' (budget exceeded)' : ''
        ) );

        return $payload;
    }

    /**
     * Harvest all configured sources, scrape articles, detect blocks, and persist daily snapshot.
     *
     * @param string[]|array $sources     List of news website URLs or structured source configs.
     * @param string         $date        Target date in YYYY-MM-DD format (defaults to current date).
     * @param int|float|null $time_budget Optional time budget in seconds.
     * @return array Harvest payload with diagnostics and articles.
     */
    public function harvest_all( $sources, string $date = '', $time_budget = null ): array {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }
        $start_time  = microtime( true );
        $time_budget = $this->get_time_budget( $time_budget );

        // Normalize structured sources
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            $structured_sources = PressHub_AI_Settings_Storage::normalize_sources( $sources );
        } else {
            $structured_sources = [];
            foreach ( (array) $sources as $item ) {
                if ( is_string( $item ) && preg_match( '/^https?:\/\//i', trim( $item ) ) ) {
                    $structured_sources[] = [
                        'id'           => 'src_' . substr( md5( trim( $item ) ), 0, 8 ),
                        'name'         => (string) parse_url( trim( $item ), PHP_URL_HOST ),
                        'url'          => trim( $item ),
                        'type'         => 'text_news',
                        'enabled'      => true,
                        'category'     => 'General',
                        'notes'        => '',
                        'max_articles' => 5,
                    ];
                } elseif ( is_array( $item ) && ! empty( $item['url'] ) ) {
                    $structured_sources[] = array_merge( [
                        'id'           => 'src_' . substr( md5( (string) $item['url'] ), 0, 8 ),
                        'name'         => (string) ( $item['name'] ?? parse_url( (string) $item['url'], PHP_URL_HOST ) ),
                        'url'          => (string) $item['url'],
                        'type'         => (string) ( $item['type'] ?? 'text_news' ),
                        'enabled'      => isset( $item['enabled'] ) ? (bool) $item['enabled'] : true,
                        'category'     => (string) ( $item['category'] ?? 'General' ),
                        'notes'        => (string) ( $item['notes'] ?? '' ),
                        'max_articles' => isset( $item['max_articles'] ) ? (int) $item['max_articles'] : 5,
                    ], $item );
                }
            }
        }

        $active_text_sources = [];
        foreach ( $structured_sources as $src ) {
            $src_url     = $src['url'] ?? '';
            $src_name    = $src['name'] ?? ( parse_url( $src_url, PHP_URL_HOST ) ?: $src_url );
            $src_type    = $src['type'] ?? 'text_news';
            $src_enabled = ! empty( $src['enabled'] );

            if ( ! $src_enabled ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::debug( sprintf( "Source '%s' (%s) is disabled; skipping harvest.", $src_name, $src_url ) );
                }
                continue;
            }

            if ( in_array( $src_type, [ 'text_news', 'rss_feed' ], true ) ) {
                $active_text_sources[] = $src;
            } else {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::info( sprintf( "Preserving non-text source '%s' (type: %s, url: %s) for multi-modal ingestion pipeline.", $src_name, $src_type, $src_url ) );
                }
            }
        }

        $source_urls = array_values( array_unique( array_column( $active_text_sources, 'url' ) ) );

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf(
                'Starting news harvest for %s (%d active text/RSS sources out of %d configured, time budget: %ds)',
                $date,
                count( $source_urls ),
                count( $structured_sources ),
                $time_budget
            ), [ 'sources' => $source_urls ] );
        }

        $payload = [
            'date'               => $date,
            'harvested_at'       => gmdate( 'c' ),
            'sources'            => $source_urls,
            'configured_sources' => $structured_sources,
            'blocked_sources'    => [],
            'source_health'      => [],
            'diagnostics'        => [],
            'articles'           => [],
            'budget_exceeded'    => false,
            'harvested_count'    => 0,
        ];

        // Preserve any existing manual uploads in snapshot
        $existing_snapshot = $this->load_snapshot( $date );
        if ( is_array( $existing_snapshot ) && ! empty( $existing_snapshot['articles'] ) ) {
            foreach ( $existing_snapshot['articles'] as $art ) {
                if ( ! empty( $art['is_manual'] ) ) {
                    $payload['articles'][] = $art;
                }
            }
        }

        $seen_urls   = [];
        $seen_titles = [];
        foreach ( $payload['articles'] as $art ) {
            if ( ! empty( $art['url'] ) ) {
                $seen_urls[] = $art['url'];
            }
            $t = mb_strtolower( trim( (string) ( $art['title'] ?? '' ) ) );
            if ( '' !== $t ) {
                $seen_titles[] = $t;
            }
        }

        $budget_exceeded       = false;
        $processed_source_urls = [];

        foreach ( $active_text_sources as $source_item ) {
            $source_url = $source_item['url'] ?? '';
            if ( empty( $source_url ) || in_array( $source_url, $processed_source_urls, true ) ) {
                continue;
            }
            $processed_source_urls[] = $source_url;

            // Check budget before starting next source
            if ( ( microtime( true ) - $start_time ) >= $time_budget ) {
                $budget_exceeded = true;
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( sprintf(
                        'News harvest time budget (%ds) reached before scraping source: %s. Preserving %d articles harvested so far.',
                        $time_budget,
                        $source_url,
                        count( $payload['articles'] )
                    ) );
                }
                break;
            }

            $source_host  = (string) ( parse_url( $source_url, PHP_URL_HOST ) ?: $source_url );
            $source_limit = isset( $source_item['max_articles'] ) && (int) $source_item['max_articles'] > 0
                ? (int) $source_item['max_articles']
                : $this->get_max_links_per_source();

            try {
                $discovery = $this->discover_source_articles( $source_item, $source_limit );
            } catch ( \Throwable $e ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::error( sprintf( 'Discovery failed for source %s: %s', $source_url, $e->getMessage() ), [ 'exception' => $e ] );
                }
                $discovery = [
                    'source_url'       => $source_url,
                    'discovery_method' => 'ERROR',
                    'feed_url'         => null,
                    'raw_links_count'  => 0,
                    'article_urls'     => [],
                    'feed_items'       => [],
                    'status_code'      => 0,
                    'latency_ms'       => 0,
                    'is_blocked'       => false,
                    'failure_reason'   => $e->getMessage(),
                ];
            }

            $source_articles_count = 0;

            if ( $discovery['is_blocked'] ) {
                $payload['blocked_sources'][] = $source_url;
            } else {
                $links_to_crawl = array_slice( $discovery['article_urls'] ?? [], 0, $source_limit );

                // Scrape each discovered article
                foreach ( $links_to_crawl as $article_url ) {
                    // Check budget before each article fetch
                    if ( ( microtime( true ) - $start_time ) >= $time_budget ) {
                        $budget_exceeded = true;
                        if ( class_exists( 'PressHub_AI_Logger' ) ) {
                            PressHub_AI_Logger::warning( sprintf(
                                'News harvest time budget (%ds) reached while crawling articles for %s. Preserving %d articles harvested so far.',
                                $time_budget,
                                $source_url,
                                count( $payload['articles'] )
                            ) );
                        }
                        break 2; // Break both article loop and source loop
                    }

                    if ( in_array( $article_url, $seen_urls, true ) ) {
                        continue;
                    }

                    try {
                        $article_data = $this->fetch_and_parse_article( $article_url, $source_host );
                    } catch ( \Throwable $e ) {
                        if ( class_exists( 'PressHub_AI_Logger' ) ) {
                            PressHub_AI_Logger::error( sprintf( 'Article fetch failed for %s: %s', $article_url, $e->getMessage() ), [ 'exception' => $e ] );
                        }
                        $article_data = null;
                    }

                    // Fallback to feed item if available
                    if ( ( empty( $article_data ) || empty( $article_data['content'] ) ) && ! empty( $discovery['feed_items'] ) ) {
                        foreach ( $discovery['feed_items'] as $fitem ) {
                            $fitem_url = rtrim( (string) ( $fitem['url'] ?? '' ), '/' );
                            $curr_url  = rtrim( (string) $article_url, '/' );
                            if ( $fitem_url === $curr_url || ( $fitem['url'] ?? '' ) === $article_url ) {
                                $feed_content = ! empty( $fitem['content'] ) ? $fitem['content'] : ( $fitem['description'] ?? '' );
                                $feed_content = trim( (string) $feed_content );
                                if ( ! empty( $feed_content ) ) {
                                    $feed_title = trim( (string) ( $fitem['title'] ?? '' ) );
                                    // Combine title + description if description is concise so the article maintains rich editorial context.
                                    if ( ! empty( $feed_title ) && $this->count_words( $feed_content ) < 30 && false === mb_stripos( $feed_content, $feed_title ) ) {
                                        $feed_content = $feed_title . ' — ' . $feed_content;
                                    }
                                    $candidate = [
                                        'url'             => $article_url,
                                        'title'           => $feed_title,
                                        'content'         => $feed_content,
                                        'source'          => $source_host ?: ( parse_url( $article_url, PHP_URL_HOST ) ?: 'Feed' ),
                                        'tier'            => 'rss_description',
                                        'published_at'    => $fitem['published_at'] ?? '',
                                        'char_count'      => mb_strlen( $feed_content ),
                                        'is_manual'       => false,
                                        'harvested_at'    => gmdate( 'c' ),
                                        'fallback_reason' => 'waf_or_http_error',
                                    ];
                                    if ( $this->is_valid_harvested_article( $candidate ) ) {
                                        $article_data = $candidate;
                                        if ( class_exists( 'PressHub_AI_Logger' ) ) {
                                            PressHub_AI_Logger::info( sprintf( 'Used RSS description fallback for %s due to HTML extraction/WAF block.', $article_url ) );
                                        }
                                    }
                                }
                                break;
                            }
                        }
                    }

                    if ( empty( $article_data ) || empty( $article_data['content'] ) ) {
                        continue;
                    }

                    // Title deduplication check
                    $norm_title = mb_strtolower( trim( (string) ( $article_data['title'] ?? '' ) ) );
                    if ( '' !== $norm_title && in_array( $norm_title, $seen_titles, true ) ) {
                        continue;
                    }

                    $seen_urls[] = $article_url;
                    if ( '' !== $norm_title ) {
                        $seen_titles[] = $norm_title;
                    }

                    $payload['articles'][] = $article_data;
                    $source_articles_count++;
                }
            }

            $health_status = 'ok';
            if ( $discovery['is_blocked'] ) {
                $health_status = 'blocked';
            } elseif ( 0 === $source_articles_count ) {
                $health_status = 'warning';
            }

            $diag_item = [
                'url'              => $source_url,
                'host'             => $source_host,
                'status'           => $health_status,
                'http_code'        => $discovery['status_code'] ?? 0,
                'latency_ms'       => $discovery['latency_ms'] ?? 0,
                'discovery_method' => $discovery['discovery_method'] ?? 'UNKNOWN',
                'feed_url'         => $discovery['feed_url'] ?? null,
                'raw_links_count'  => $discovery['raw_links_count'] ?? 0,
                'articles_yielded' => $source_articles_count,
                'failure_reason'   => $discovery['failure_reason'] ?? ( ( 0 === $source_articles_count && ! $discovery['is_blocked'] ) ? '0 articles scraped' : null ),
            ];

            $payload['source_health'][] = $diag_item;
            $payload['diagnostics'][]   = $diag_item;
        }

        $duration_ms                 = (int) round( ( microtime( true ) - $start_time ) * 1000 );
        $payload['budget_exceeded']  = $budget_exceeded;
        $payload['harvested_count']  = count( $payload['articles'] );
        $payload['duration_ms']      = $duration_ms;
        $payload['time_budget']      = $time_budget;

        if ( $budget_exceeded ) {
            $payload['notice'] = sprintf(
                /* translators: 1: number of articles, 2: elapsed time in seconds, 3: time budget in seconds */
                __( 'Harvest reached time budget (%2$ds / %3$ds). %1$d articles collected and saved.', 'presshub-ai-editor' ),
                count( $payload['articles'] ),
                (int) round( $duration_ms / 1000 ),
                $time_budget
            );
        }

        $this->save_snapshot( $date, $payload );

        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_scrape_request(
                'scrape_harvest',
                count( $source_urls ),
                count( $payload['articles'] ),
                $duration_ms,
                $budget_exceeded ? 'partial' : 'success',
                null,
                [
                    'blocked_sources' => count( $payload['blocked_sources'] ),
                    'date'            => $date,
                    'budget_exceeded' => $budget_exceeded,
                    'time_budget_sec' => $time_budget,
                ]
            );
        }

        PressHub_AI_Logger::info( sprintf(
            'News harvest completed for %s: %d articles collected, %d blocked, duration %dms%s',
            $date,
            count( $payload['articles'] ),
            count( $payload['blocked_sources'] ),
            $duration_ms,
            $budget_exceeded ? ' (budget exceeded)' : ''
        ) );

        return $payload;
    }



    /**
     * Fetch a single article URL and extract its title and body text using Multi-Fallback Extractor.
     *
     * @param string $url         Article URL.
     * @param string $source_host Source host identifier.
     * @return array|null Article data array or null on failure.
     */
    protected function fetch_and_parse_article( string $url, string $source_host = '' ): ?array {
        $this->throttle_host_request( $url );
        $extracted = PressHub_AI_URL_Fetcher::fetch_article_data( $url );

        if ( ! $extracted['success'] || empty( $extracted['content'] ) ) {
            PressHub_AI_Logger::log_harvest_article( $url, '', $extracted['tier'] ?? 'none', 0, false, $extracted['error'] ?? 'Extraction failed' );
            return null;
        }

        $title   = trim( (string) ( $extracted['title'] ?? '' ) );
        $content = trim( (string) ( $extracted['content'] ?? '' ) );

        if ( empty( $title ) ) {
            $lines = explode( "\n", $content );
            $title = ! empty( $lines[0] ) ? wp_html_excerpt( $lines[0], 120 ) : 'Είδηση';
        }

        $article_data = [
            'url'          => $url,
            'title'        => $title,
            'content'      => $content,
            'source'       => $source_host ?: ( parse_url( $url, PHP_URL_HOST ) ?: 'Web' ),
            'tier'         => $extracted['tier'] ?? 'dom',
            'published_at' => $extracted['published_at'] ?? '',
            'char_count'   => mb_strlen( $content ),
            'is_manual'    => false,
            'harvested_at' => gmdate( 'c' ),
        ];

        if ( ! $this->is_valid_harvested_article( $article_data ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::debug( sprintf(
                    'Discarding shallow or non-article page: %s (title: "%s", words: %d)',
                    $url,
                    $title,
                    $this->count_words( $content )
                ) );
            }
            return null;
        }

        PressHub_AI_Logger::log_harvest_article( $url, $title, $article_data['tier'], $article_data['char_count'], true );

        return $article_data;
    }

    /**
     * Merge manual uploads (PDF/DOCX extracted text or editor notes) into daily articles pool.
     *
     * @param array  $uploaded_articles Array of upload items with 'title', 'content', 'source', 'url'.
     * @param string $date              Target date in YYYY-MM-DD format.
     * @return array Updated payload array.
     */
    public function handle_manual_upload( array $uploaded_articles, string $date = '' ): array {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $payload = $this->load_snapshot( $date );
        if ( ! is_array( $payload ) || empty( $payload ) ) {
            $payload = [
                'date'            => $date,
                'harvested_at'    => gmdate( 'c' ),
                'sources'         => [],
                'blocked_sources' => [],
                'source_health'   => [],
                'diagnostics'     => [],
                'articles'        => [],
            ];
        }

        $existing_articles = $payload['articles'] ?? [];
        $existing_keys     = [];

        foreach ( $existing_articles as $art ) {
            $url          = $art['url'] ?? '';
            $title        = mb_strtolower( trim( $art['title'] ?? '' ) );
            $content_hash = md5( trim( $art['content'] ?? '' ) );
            if ( ! empty( $url ) ) {
                $existing_keys[ $url ] = true;
            }
            $existing_keys[ $title . '|' . $content_hash ] = true;
        }

        foreach ( $uploaded_articles as $item ) {
            $title   = sanitize_text_field( $item['title'] ?? 'Χειροκίνητη Καταχώρηση' );
            $content = sanitize_textarea_field( $item['content'] ?? '' );
            $source  = sanitize_text_field( $item['source'] ?? 'Manual Upload' );
            $url     = ! empty( $item['url'] ) ? esc_url_raw( $item['url'] ) : '';

            if ( empty( $content ) && empty( $title ) ) {
                continue;
            }

            $dup_key = mb_strtolower( trim( $title ) ) . '|' . md5( trim( $content ) );
            if ( ( ! empty( $url ) && isset( $existing_keys[ $url ] ) ) || isset( $existing_keys[ $dup_key ] ) ) {
                continue;
            }

            if ( ! empty( $url ) ) {
                $existing_keys[ $url ] = true;
            }
            $existing_keys[ $dup_key ] = true;

            $payload['articles'][] = [
                'url'          => $url,
                'title'        => $title,
                'content'      => $content,
                'source'       => $source,
                'tier'         => 'manual',
                'char_count'   => mb_strlen( $content ),
                'published_at' => '',
                'is_manual'    => true,
                'harvested_at' => gmdate( 'c' ),
            ];
        }

        $this->save_snapshot( $date, $payload );
        return $payload;
    }

    /**
     * Get directory path for daily briefing uploads.
     *
     * @param string $date Date string YYYY-MM-DD.
     * @return string Directory path.
     */
    public function get_snapshot_dir( string $date ): string {
        $clean_date = preg_replace( '/[^0-9\-]/', '', $date );
        if ( empty( $clean_date ) ) {
            $clean_date = gmdate( 'Y-m-d' );
        }
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'presshub-briefings/' . $clean_date;
    }

    /**
     * Get full file path for the raw-articles.json snapshot.
     *
     * @param string $date Date string YYYY-MM-DD.
     * @return string Full file path.
     */
    public function get_snapshot_path( string $date ): string {
        return trailingslashit( $this->get_snapshot_dir( $date ) ) . 'raw-articles.json';
    }

    /**
     * Save daily snapshot payload to disk as JSON and in transients.
     *
     * @param string $date Date string YYYY-MM-DD.
     * @param array  $data Snapshot data.
     * @return bool True on success, false on failure.
     */
    public function save_snapshot( string $date, array $data ): bool {
        $clean_date = preg_replace( '/[^0-9\-]/', '', $date );
        if ( empty( $clean_date ) ) {
            $clean_date = gmdate( 'Y-m-d' );
        }

        if ( function_exists( 'set_transient' ) ) {
            set_transient( 'presshub_ai_harvest_snapshot_' . $clean_date, $data, DAY_IN_SECONDS );
        }

        $path = $this->get_snapshot_path( $date );
        $dir  = dirname( $path );

        if ( ! is_dir( $dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( $dir );
            } else {
                @mkdir( $dir, 0755, true );
            }
        }

        $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) {
            return false;
        }

        return false !== @file_put_contents( $path, $json );
    }

    /**
     * Load daily snapshot payload from disk or transient cache.
     *
     * @param string $date Date string YYYY-MM-DD.
     * @return array|null Decoded payload array or null if not found.
     */
    public function load_snapshot( string $date ): ?array {
        $clean_date = preg_replace( '/[^0-9\-]/', '', $date );
        if ( empty( $clean_date ) ) {
            $clean_date = gmdate( 'Y-m-d' );
        }

        $path = $this->get_snapshot_path( $date );
        if ( file_exists( $path ) ) {
            $content = @file_get_contents( $path );
            if ( false !== $content && '' !== trim( $content ) ) {
                $decoded = json_decode( $content, true );
                if ( is_array( $decoded ) ) {
                    return $decoded;
                }
            }
        }

        if ( function_exists( 'get_transient' ) ) {
            $cached = get_transient( 'presshub_ai_harvest_snapshot_' . $clean_date );
            if ( is_array( $cached ) && ! empty( $cached ) ) {
                return $cached;
            }
        }

        return null;
    }
}
