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

    /** Maximum links to crawl per homepage to respect time budgets. */
    const MAX_LINKS_PER_SOURCE = 15;

    /** Request timeout in seconds. */
    const REQUEST_TIMEOUT = 15;

    /** User Agent for harvesting. */
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 PressHub-AI-Harvester/1.3.1';

    /**
     * Fetch homepage HTML or Feed and extract unique article URLs.
     *
     * @param string $url Target homepage or feed URL.
     * @return string[] List of absolute article URLs.
     */
    public function fetch_homepage_links( string $url ): array {
        $discovery = $this->discover_source_articles( $url );
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
     * @param string $url Source homepage URL or Feed endpoint.
     * @return array Discovery result details.
     */
    public function discover_source_articles( string $url ): array {
        $url = trim( $url );
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
                    'article_urls'     => array_slice( $urls, 0, self::MAX_LINKS_PER_SOURCE ),
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
                        'article_urls'     => array_slice( $urls, 0, self::MAX_LINKS_PER_SOURCE ),
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
                'article_urls'     => array_slice( $urls, 0, self::MAX_LINKS_PER_SOURCE ),
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
            'article_urls'     => array_slice( $html_links, 0, self::MAX_LINKS_PER_SOURCE ),
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

        return [
            'url'          => $clean_url,
            'title'        => trim( wp_strip_all_tags( $title ) ),
            'content'      => trim( wp_strip_all_tags( $content ) ),
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

        $published = (string) ( $entry->published ?? ( $entry->updated ?? '' ) );
        $clean_url  = $this->resolve_relative_url( $link, $base_url );

        return [
            'url'          => $clean_url,
            'title'        => trim( wp_strip_all_tags( $title ) ),
            'content'      => trim( wp_strip_all_tags( $content ) ),
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
     * Parse HTML and extract valid article links, combining JSON-LD schemas and DOM anchors.
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

        $discovered_links = [];

        // 1. JSON-LD Schema links (ItemList, NewsArticle)
        $json_ld_links = $this->extract_links_from_json_ld( $html, $base_url );
        foreach ( $json_ld_links as $jlink ) {
            $discovered_links[] = $jlink;
        }

        // 2. DOM Anchors
        // Strip navigation, header, footer, script, style, form, aside to avoid utility links
        $clean_html = preg_replace( '#<(script|style|noscript|header|footer|nav|form|aside)[^>]*>.*?</(script|style|noscript|header|footer|nav|form|aside)>#is', ' ', $html );

        // Extract all <a href="..."> links
        preg_match_all( '#<a\s+[^>]*?href=["\']([^"\']+)["\'][^>]*>#i', $clean_html, $matches );
        $raw_hrefs = $matches[1] ?? [];

        // Excluded path and keyword substrings (Greek & English)
        $excluded_patterns = [
            '/tag/', '/tags/', '/category/', '/categories/', '/author/', '/page/',
            '/terms', '/privacy', '/oroi', '/politiki-aporritou', '/contact', '/epikoino',
            '/about', '/cookie', '/feed', '/rss', '/wp-json/', '/search', '/login',
            '/wp-login', '/newsletter', '/sitemap', '/advertis', '/subscription',
            'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'youtube.com',
            'linkedin.com', 'tiktok.com', 't.me', 'whatsapp.com', 'pinterest.com',
            'mailto:', 'tel:', 'javascript:', '#',
        ];

        foreach ( $raw_hrefs as $href ) {
            $href = trim( $href );
            if ( '' === $href || '#' === $href || 0 === strpos( $href, '#' ) ) {
                continue;
            }

            if ( preg_match( '/^(javascript|mailto|tel):/i', $href ) ) {
                continue;
            }

            $skip = false;
            foreach ( $excluded_patterns as $pattern ) {
                if ( false !== stripos( $href, $pattern ) ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }

            $norm = $this->normalize_article_url( $href, $base_url );
            if ( ! empty( $norm ) ) {
                $discovered_links[] = $norm;
            }
        }

        return array_values( array_unique( $discovered_links ) );
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
     * Harvest all configured sources, scrape articles, detect blocks, and persist daily snapshot.
     *
     * @param string[] $source_urls List of news website URLs.
     * @param string   $date        Target date in YYYY-MM-DD format (defaults to current date).
     * @return array Harvest payload with diagnostics and articles.
     */
    public function harvest_all( $sources, string $date = '' ): array {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }
        $start_time = microtime( true );

        // Normalize structured sources
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            $structured_sources = PressHub_AI_Settings_Storage::normalize_sources( $sources );
        } else {
            $structured_sources = [];
            foreach ( (array) $sources as $item ) {
                if ( is_string( $item ) && preg_match( '/^https?:\/\//i', trim( $item ) ) ) {
                    $structured_sources[] = [
                        'id'       => 'src_' . substr( md5( trim( $item ) ), 0, 8 ),
                        'name'     => (string) parse_url( trim( $item ), PHP_URL_HOST ),
                        'url'      => trim( $item ),
                        'type'     => 'text_news',
                        'enabled'  => true,
                        'category' => 'General',
                        'notes'    => '',
                    ];
                } elseif ( is_array( $item ) && ! empty( $item['url'] ) ) {
                    $structured_sources[] = array_merge( [
                        'id'       => 'src_' . substr( md5( (string) $item['url'] ), 0, 8 ),
                        'name'     => (string) ( $item['name'] ?? parse_url( (string) $item['url'], PHP_URL_HOST ) ),
                        'url'      => (string) $item['url'],
                        'type'     => (string) ( $item['type'] ?? 'text_news' ),
                        'enabled'  => isset( $item['enabled'] ) ? (bool) $item['enabled'] : true,
                        'category' => (string) ( $item['category'] ?? 'General' ),
                        'notes'    => (string) ( $item['notes'] ?? '' ),
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
            PressHub_AI_Logger::info( sprintf( 'Starting news harvest for %s (%d active text/RSS sources out of %d configured)', $date, count( $source_urls ), count( $structured_sources ) ), [ 'sources' => $source_urls ] );
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
        ];

        $seen_urls   = [];
        $seen_titles = [];

        foreach ( $source_urls as $source_url ) {
            $source_host = (string) parse_url( $source_url, PHP_URL_HOST ) ?: $source_url;
            $discovery   = $this->discover_source_articles( $source_url );

            $source_articles_count = 0;

            if ( $discovery['is_blocked'] ) {
                $payload['blocked_sources'][] = $source_url;
            } else {
                $links_to_crawl = array_slice( $discovery['article_urls'] ?? [], 0, self::MAX_LINKS_PER_SOURCE );

                // Scrape each discovered article
                foreach ( $links_to_crawl as $article_url ) {
                    if ( in_array( $article_url, $seen_urls, true ) ) {
                        continue;
                    }

                    $article_data = $this->fetch_and_parse_article( $article_url, $source_host );
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

        $this->save_snapshot( $date, $payload );

        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_scrape_request(
                'scrape_harvest',
                count( $source_urls ),
                count( $payload['articles'] ),
                $duration_ms,
                'success',
                null,
                [ 'blocked_sources' => count( $payload['blocked_sources'] ), 'date' => $date ]
            );
        }

        PressHub_AI_Logger::info( sprintf(
            'News harvest completed for %s: %d articles collected, %d blocked, duration %dms',
            $date,
            count( $payload['articles'] ),
            count( $payload['blocked_sources'] ),
            $duration_ms
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
     * Save daily snapshot payload to disk as JSON.
     *
     * @param string $date Date string YYYY-MM-DD.
     * @param array  $data Snapshot data.
     * @return bool True on success, false on failure.
     */
    public function save_snapshot( string $date, array $data ): bool {
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
     * Load daily snapshot payload from disk.
     *
     * @param string $date Date string YYYY-MM-DD.
     * @return array|null Decoded payload array or null if not found.
     */
    public function load_snapshot( string $date ): ?array {
        $path = $this->get_snapshot_path( $date );
        if ( ! file_exists( $path ) ) {
            return null;
        }

        $content = @file_get_contents( $path );
        if ( false === $content || '' === trim( $content ) ) {
            return null;
        }

        $decoded = json_decode( $content, true );
        return is_array( $decoded ) ? $decoded : null;
    }
}
