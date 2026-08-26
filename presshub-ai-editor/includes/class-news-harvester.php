<?php
/**
 * PressHub_AI_News_Harvester — Morning Greek News Harvester & Cloudflare Fallback Engine.
 *
 * Automates scraping of configured Greek news homepages, article link extraction,
 * anti-bot / Cloudflare challenge detection, manual document/text upload merging,
 * deduplication, and daily JSON snapshot persistence.
 *
 * @package PressHub_AI_Editor
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_News_Harvester {

    /** Maximum links to crawl per homepage to respect time budgets. */
    const MAX_LINKS_PER_SOURCE = 15;

    /** Request timeout in seconds. */
    const REQUEST_TIMEOUT = 15;

    /** User Agent for harvesting. */
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 PressHub-AI-Harvester/1.3.0';

    /**
     * Fetch homepage HTML and extract unique article URLs.
     *
     * @param string $url Target homepage URL.
     * @return string[] List of absolute article URLs.
     */
    public function fetch_homepage_links( string $url ): array {
        $url = trim( $url );
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return [];
        }

        $response = wp_remote_get( $url, [
            'timeout'     => self::REQUEST_TIMEOUT,
            'user-agent'  => self::USER_AGENT,
            'redirection' => 5,
            'headers'     => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'el,el-GR;q=0.9,en;q=0.8',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $this->is_cloudflare_or_blocked( $response, $body ) ) {
            return [];
        }

        if ( 200 !== $code || empty( $body ) ) {
            return [];
        }

        return $this->extract_article_links_from_html( $body, $url );
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

        // Check known Cloudflare & anti-bot challenge signatures in body
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
     * Parse HTML and extract valid article links, stripping nav/footer/privacy/tags/social.
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

        // Strip navigation, header, footer, script, style, form, aside to avoid utility links
        $clean_html = preg_replace( '#<(script|style|noscript|header|footer|nav|form|aside)[^>]*>.*?</(script|style|noscript|header|footer|nav|form|aside)>#is', ' ', $html );

        // Extract all <a href="..."> links
        preg_match_all( '/<a\s+[^>]*?href=[\'"]([^\'"]+)[\'"][^>]*>/i', $clean_html, $matches );
        $raw_hrefs = $matches[1] ?? [];

        $article_links = [];

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

            // Exclude javascript / mailto / tel
            if ( preg_match( '/^(javascript|mailto|tel):/i', $href ) ) {
                continue;
            }

            // Check exclusion substrings
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

            // Resolve relative URLs to absolute
            $absolute_url = '';
            if ( 0 === strpos( $href, '//' ) ) {
                $absolute_url = $base_scheme . ':' . $href;
            } elseif ( 0 === strpos( $href, '/' ) ) {
                $absolute_url = $base_scheme . '://' . $base_host . $href;
            } elseif ( 0 === strpos( $href, 'http://' ) || 0 === strpos( $href, 'https://' ) ) {
                $absolute_url = $href;
            } else {
                $absolute_url = $base_scheme . '://' . $base_host . '/' . ltrim( $href, '/' );
            }

            // Strip fragments and trailing tracking params (utm_*, etc.)
            $parts = parse_url( $absolute_url );
            if ( empty( $parts['host'] ) ) {
                continue;
            }

            // Normalize path
            $path = $parts['path'] ?? '/';
            if ( '/' === $path || '' === $path ) {
                continue; // Skip homepage itself
            }

            // Only allow same domain or news domain
            $target_host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
            $source_host = strtolower( preg_replace( '/^www\./', '', $base_host ) );
            
            // Allow same host or valid news subdomains
            if ( $target_host !== $source_host && ! preg_match( '/\.' . preg_quote( $source_host, '/' ) . '$/', $target_host ) ) {
                // If it is an external link, verify if it is a news agency (e.g. amna.gr) or skip
                if ( false === strpos( $target_host, 'amna.gr' ) && false === strpos( $target_host, 'ape-mpe.gr' ) ) {
                    continue;
                }
            }

            // Remove query params like tracking tags
            $clean_url = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $path;

            $article_links[] = $clean_url;
        }

        return array_values( array_unique( $article_links ) );
    }

    /**
     * Harvest all configured sources, scrape articles, detect blocks, and persist daily snapshot.
     *
     * @param string[] $source_urls List of news website URLs.
     * @param string   $date        Target date in YYYY-MM-DD format (defaults to current date).
     * @return array Harvest payload.
     */
    public function harvest_all( array $source_urls, string $date = '' ): array {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $source_urls = array_values( array_unique( array_filter( array_map( 'trim', $source_urls ) ) ) );

        $payload = [
            'date'            => $date,
            'harvested_at'    => gmdate( 'c' ),
            'sources'         => $source_urls,
            'blocked_sources' => [],
            'articles'        => [],
        ];

        $seen_urls = [];
        $seen_titles = [];

        foreach ( $source_urls as $source_url ) {
            // Check homepage accessibility & Cloudflare / 403 status
            $response = wp_remote_get( $source_url, [
                'timeout'     => self::REQUEST_TIMEOUT,
                'user-agent'  => self::USER_AGENT,
                'redirection' => 5,
            ] );

            if ( is_wp_error( $response ) || $this->is_cloudflare_or_blocked( $response ) ) {
                $payload['blocked_sources'][] = $source_url;
                continue;
            }

            $body = wp_remote_retrieve_body( $response );
            if ( empty( $body ) ) {
                $payload['blocked_sources'][] = $source_url;
                continue;
            }

            $extracted_links = $this->extract_article_links_from_html( $body, $source_url );
            if ( empty( $extracted_links ) ) {
                // If the source URL itself is directly an article or single post
                if ( preg_match( '#<article[^>]*>#i', $body ) ) {
                    $extracted_links = [ $source_url ];
                }
            }

            $source_host = parse_url( $source_url, PHP_URL_HOST ) ?: $source_url;
            $links_to_crawl = array_slice( $extracted_links, 0, self::MAX_LINKS_PER_SOURCE );

            foreach ( $links_to_crawl as $article_url ) {
                if ( in_array( $article_url, $seen_urls, true ) ) {
                    continue;
                }

                $article_data = $this->fetch_and_parse_article( $article_url, $source_host );
                if ( empty( $article_data ) || empty( $article_data['content'] ) ) {
                    continue;
                }

                // Cross-outlet deduplication by title similarity / URL
                $norm_title = mb_strtolower( trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $article_data['title'] ) ) );
                if ( '' !== $norm_title && in_array( $norm_title, $seen_titles, true ) ) {
                    continue;
                }

                $seen_urls[] = $article_url;
                if ( '' !== $norm_title ) {
                    $seen_titles[] = $norm_title;
                }

                $payload['articles'][] = $article_data;
            }
        }

        $this->save_snapshot( $date, $payload );
        return $payload;
    }

    /**
     * Fetch a single article URL, extract its title and body text.
     *
     * @param string $url         Article URL.
     * @param string $source_host Source host identifier.
     * @return array|null Article data array or null on failure.
     */
    protected function fetch_and_parse_article( string $url, string $source_host = '' ): ?array {
        $response = wp_remote_get( $url, [
            'timeout'     => self::REQUEST_TIMEOUT,
            'user-agent'  => self::USER_AGENT,
            'redirection' => 3,
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 || $this->is_cloudflare_or_blocked( $response ) ) {
            return null;
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            return null;
        }

        // Extract title: prefer <h1 class="..."> or <title>
        $title = '';
        if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
            $title = wp_strip_all_tags( $m[1] );
        } elseif ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
            $title = wp_strip_all_tags( $m[1] );
            // Clean out common site brand suffixes from title e.g. " - Kathimerini"
            $title = preg_replace( '/\s*[-|–]\s*.*$/u', '', $title );
        }

        $content = class_exists( 'PressHub_AI_URL_Fetcher' ) 
            ? PressHub_AI_URL_Fetcher::extract_text( $html )
            : wp_strip_all_tags( $html );

        if ( empty( trim( $content ) ) ) {
            return null;
        }

        if ( empty( $title ) ) {
            $lines = explode( "\n", trim( $content ) );
            $title = ! empty( $lines[0] ) ? wp_html_excerpt( $lines[0], 120 ) : __( 'Είδηση', 'presshub-ai-editor' );
        }

        return [
            'url'          => $url,
            'title'        => trim( $title ),
            'content'      => trim( $content ),
            'source'       => $source_host ?: ( parse_url( $url, PHP_URL_HOST ) ?: 'Web' ),
            'is_manual'    => false,
            'harvested_at' => gmdate( 'c' ),
        ];
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
                'articles'        => [],
            ];
        }

        $existing_articles = $payload['articles'] ?? [];
        $existing_keys = [];

        foreach ( $existing_articles as $art ) {
            $url = $art['url'] ?? '';
            $title = mb_strtolower( trim( $art['title'] ?? '' ) );
            $content_hash = md5( trim( $art['content'] ?? '' ) );
            if ( ! empty( $url ) ) {
                $existing_keys[ $url ] = true;
            }
            $existing_keys[ $title . '|' . $content_hash ] = true;
        }

        foreach ( $uploaded_articles as $item ) {
            $title   = sanitize_text_field( $item['title'] ?? __( 'Χειροκίνητη Καταχώρηση', 'presshub-ai-editor' ) );
            $content = sanitize_textarea_field( $item['content'] ?? '' );
            $source  = sanitize_text_field( $item['source'] ?? __( 'Manual Upload', 'presshub-ai-editor' ) );
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