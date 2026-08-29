<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PressHub_AI_URL_Fetcher — server-side URL sourcing & Multi-Fallback Extractor for AI prompts.
 *
 * Design decision (2026-08-16 / 2026-08-29 Issue #5):
 * Chat-completion models cannot browse arbitrary web URLs natively. Passing a bare
 * URL makes the model hallucinate from the slug. This class fetches each source URL
 * server-side, extracts clean, readable article text using a 4-tier fallback pipeline
 * (Tier 1: JSON-LD, Tier 2: Semantic DOM Containers, Tier 3: OpenGraph/Meta, Tier 4: LLM Smart Extractor),
 * and substitutes it into prompt sources so the model reads the true article.
 *
 * @since 1.2.4
 * @updated 1.3.1 (Issue #5: Multi-Fallback Semantic Content Extractor)
 */
class PressHub_AI_URL_Fetcher {

    /** Hard cap on URLs fetched per request. */
    const MAX_URLS = 5;

    /** Per-URL character budget (roughly 10k tokens). */
    const MAX_CHARS_PER_URL = 40000;

    /** Request timeout in seconds. */
    const REQUEST_TIMEOUT = 15;

    /** UA so sites don't block the fetch as a generic bot. */
    const USER_AGENT = 'PressHub-AI-Co-Pilot/1.3.1 (+https://github.com/eeftychiou/presshub)';

    /**
     * Whether URL fetching is active: option on AND not filtered off.
     */
    public static function is_enabled(): bool {
        $option = get_option( 'presshub_ai_fetch_urls', '1' );
        return apply_filters( 'presshub_ai_fetch_source_urls', '1' === $option );
    }

    /**
     * Pull http(s) URLs out of a free-text block.
     *
     * @param string $text
     * @return string[] unique absolute URLs
     */
    public static function extract_urls( $text ): array {
        preg_match_all( '#https?://[^\s<>"\'\)\]]+#i', (string) $text, $m );
        return array_values( array_unique( $m[0] ?? [] ) );
    }

    /**
     * Remove URLs from a notes block, leaving plain prose.
     */
    public static function strip_urls_from_notes( $text ): string {
        return trim( preg_replace( '#https?://[^\s<>"\'\)\]]+#i', '', (string) $text ) );
    }

    /**
     * Fetch one URL and return extracted article text ('' on failure).
     */
    public static function fetch_article( $url ): string {
        $response = wp_remote_get( $url, [
            'timeout'     => self::REQUEST_TIMEOUT,
            'user-agent'  => self::USER_AGENT,
            'redirection' => 3,
        ] );
        if ( is_wp_error( $response ) ) {
            return '';
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return '';
        }
        $html = wp_remote_retrieve_body( $response );
        if ( '' === trim( (string) $html ) ) {
            return '';
        }
        return self::extract_text( $html );
    }

    /**
     * Fetch one URL and return structured extraction result.
     *
     * @param string $url Target article URL.
     * @return array Structured article data.
     */
    public static function fetch_article_data( string $url ): array {
        $url = trim( $url );
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return [
                'success'      => false,
                'status_code'  => 0,
                'error'        => 'Invalid URL',
                'url'          => $url,
                'title'        => '',
                'content'      => '',
                'tier'         => 'none',
                'char_count'   => 0,
                'published_at' => '',
            ];
        }

        $response = wp_remote_get( $url, [
            'timeout'     => self::REQUEST_TIMEOUT,
            'user-agent'  => self::USER_AGENT,
            'redirection' => 3,
            'headers'     => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'el,el-GR;q=0.9,en;q=0.8',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success'      => false,
                'status_code'  => 0,
                'error'        => $response->get_error_message(),
                'url'          => $url,
                'title'        => '',
                'content'      => '',
                'tier'         => 'none',
                'char_count'   => 0,
                'published_at' => '',
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return [
                'success'      => false,
                'status_code'  => $code,
                'error'        => 'HTTP ' . $code,
                'url'          => $url,
                'title'        => '',
                'content'      => '',
                'tier'         => 'none',
                'char_count'   => 0,
                'published_at' => '',
            ];
        }

        $html = wp_remote_retrieve_body( $response );
        if ( '' === trim( (string) $html ) ) {
            return [
                'success'      => false,
                'status_code'  => $code,
                'error'        => 'Empty response body',
                'url'          => $url,
                'title'        => '',
                'content'      => '',
                'tier'         => 'none',
                'char_count'   => 0,
                'published_at' => '',
            ];
        }

        $extracted = self::extract_article_semantic( (string) $html, $url );
        $extracted['success']     = ( '' !== trim( $extracted['content'] ?? '' ) );
        $extracted['status_code'] = $code;
        $extracted['error']       = $extracted['success'] ? null : 'Failed to extract article content';
        $extracted['url']         = $url;

        return $extracted;
    }

    /**
     * Multi-Fallback Semantic Content Extractor (4 Tiers):
     * - Tier 1: JSON-LD Structured Metadata (articleBody, headline, datePublished)
     * - Tier 2: Semantic DOM Containers (<article>, .entry-content, .article__body, .story-body, <main>)
     * - Tier 3: OpenGraph / Meta Tags Fallback (og:title, og:description, meta description)
     * - Tier 4: LLM Smart Extractor Fallback (lightweight LLM parser for sparse/paywalled HTML)
     *
     * @param string $html Raw HTML content.
     * @param string $url  Optional source article URL for context.
     * @return array Extracted article metadata & content array.
     */
    public static function extract_article_semantic( string $html, string $url = '' ): array {
        $html = (string) $html;

        // Default extracted title from HTML <title> or <h1>
        $page_title = '';
        if ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', $html, $m ) ) {
            $page_title = trim( wp_strip_all_tags( $m[1] ) );
        } elseif ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
            $page_title = trim( wp_strip_all_tags( $m[1] ) );
            $page_title = preg_replace( '/\s*[-|–]\s*.*$/u', '', $page_title );
        }

        // =========================================================================
        // Tier 1: Structured Metadata Extraction (JSON-LD)
        // =========================================================================
        $json_ld_article = self::extract_from_json_ld( $html );
        if ( ! empty( $json_ld_article['content'] ) ) {
            $cleaned = self::clean_prose( $json_ld_article['content'] );
            if ( mb_strlen( $cleaned ) >= 10 ) {
                return [
                    'title'        => ! empty( $json_ld_article['title'] ) ? $json_ld_article['title'] : $page_title,
                    'content'      => $cleaned,
                    'tier'         => 'json_ld',
                    'published_at' => $json_ld_article['published_at'] ?? '',
                    'char_count'   => mb_strlen( $cleaned ),
                ];
            }
        }

        // =========================================================================
        // Tier 2: Semantic DOM News Containers
        // =========================================================================
        $dom_text = self::extract_from_dom_containers( $html );
        if ( '' !== $dom_text ) {
            $cleaned = self::clean_prose( $dom_text );
            if ( mb_strlen( $cleaned ) >= 10 ) {
                return [
                    'title'        => $page_title,
                    'content'      => $cleaned,
                    'tier'         => 'dom',
                    'published_at' => '',
                    'char_count'   => mb_strlen( $cleaned ),
                ];
            }
        }

        // =========================================================================
        // Tier 3: OpenGraph & Meta Tags Fallback
        // =========================================================================
        $og_data = self::extract_from_opengraph( $html );
        if ( ! empty( $og_data['content'] ) ) {
            $cleaned = self::clean_prose( $og_data['content'] );
            if ( mb_strlen( $cleaned ) >= 10 ) {
                return [
                    'title'        => ! empty( $og_data['title'] ) ? $og_data['title'] : $page_title,
                    'content'      => $cleaned,
                    'tier'         => 'opengraph',
                    'published_at' => $og_data['published_at'] ?? '',
                    'char_count'   => mb_strlen( $cleaned ),
                ];
            }
        }

        // =========================================================================
        // Tier 4: LLM Smart Extractor Fallback (for sparse or paywalled HTML)
        // =========================================================================
        $llm_article = self::extract_via_llm_fallback( $html, $url );
        if ( ! empty( $llm_article['content'] ) ) {
            $cleaned = self::clean_prose( $llm_article['content'] );
            if ( mb_strlen( $cleaned ) >= 10 ) {
                return [
                    'title'        => ! empty( $llm_article['title'] ) ? $llm_article['title'] : $page_title,
                    'content'      => $cleaned,
                    'tier'         => 'llm',
                    'published_at' => '',
                    'char_count'   => mb_strlen( $cleaned ),
                ];
            }
        }

        // =========================================================================
        // Fallback: Raw HTML tag stripping
        // =========================================================================
        $raw_clean = self::extract_raw_stripped( $html );
        $final_title = $page_title;
        if ( empty( $final_title ) && ! empty( $raw_clean ) ) {
            $lines = explode( "\n", $raw_clean );
            $final_title = ! empty( $lines[0] ) ? wp_html_excerpt( $lines[0], 120 ) : 'Είδηση';
        }

        return [
            'title'        => $final_title,
            'content'      => $raw_clean,
            'tier'         => 'raw_strip',
            'published_at' => '',
            'char_count'   => mb_strlen( $raw_clean ),
        ];
    }

    /**
     * Backward-compatible plain text extractor.
     */
    public static function extract_text( $html ): string {
        $extracted = self::extract_article_semantic( (string) $html );
        $text = (string) ( $extracted['content'] ?? '' );

        // Truncate (mb-safe when available).
        if ( function_exists( 'mb_substr' ) ) {
            $text = mb_substr( $text, 0, self::MAX_CHARS_PER_URL );
        } else {
            $text = substr( $text, 0, self::MAX_CHARS_PER_URL );
        }

        // Scrub anything json_encode would reject later (invalid UTF-8).
        $clean = preg_replace( '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $text );
        return null === $clean ? preg_replace( '/[^\x20-\x7E]/', ' ', $text ) : trim( $clean );
    }

    /**
     * Tier 1: Extract article content from JSON-LD schema scripts.
     */
    protected static function extract_from_json_ld( string $html ): array {
        preg_match_all( '#<script\s+[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches );
        if ( empty( $matches[1] ) ) {
            return [];
        }

        $news_types = [ 'newsarticle', 'article', 'blogposting', 'report', 'webpage', 'techarticle' ];

        foreach ( $matches[1] as $json_str ) {
            $json_str = trim( $json_str );
            if ( '' === $json_str ) {
                continue;
            }

            $decoded = json_decode( $json_str, true );
            if ( ! is_array( $decoded ) ) {
                continue;
            }

            // Collect all candidate objects (handle single object or @graph list)
            $candidates = [];
            if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
                $candidates = $decoded['@graph'];
            } elseif ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
                $candidates = $decoded;
            } else {
                $candidates = [ $decoded ];
            }

            foreach ( $candidates as $obj ) {
                if ( ! is_array( $obj ) ) {
                    continue;
                }

                $type = $obj['@type'] ?? '';
                $type_str = is_array( $type ) ? implode( ' ', $type ) : (string) $type;
                $type_lower = strtolower( $type_str );

                $is_article_type = false;
                foreach ( $news_types as $nt ) {
                    if ( false !== strpos( $type_lower, $nt ) ) {
                        $is_article_type = true;
                        break;
                    }
                }

                if ( ! $is_article_type && ! isset( $obj['articleBody'] ) ) {
                    continue;
                }

                $body = $obj['articleBody'] ?? ( $obj['text'] ?? '' );
                if ( ! empty( $body ) && is_string( $body ) && mb_strlen( trim( $body ) ) >= 10 ) {
                    $title = $obj['headline'] ?? ( $obj['name'] ?? '' );
                    $published = $obj['datePublished'] ?? ( $obj['dateCreated'] ?? ( $obj['dateModified'] ?? '' ) );
                    return [
                        'title'        => is_string( $title ) ? trim( $title ) : '',
                        'content'      => trim( $body ),
                        'published_at' => is_string( $published ) ? trim( $published ) : '',
                    ];
                }
            }
        }

        return [];
    }

    /**
     * Tier 2: Extract text from standard semantic DOM containers using DOMXPath.
     */
    protected static function extract_from_dom_containers( string $html ): string {
        if ( empty( trim( $html ) ) ) {
            return '';
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            return '';
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        $xpath = new DOMXPath( $doc );

        // Strip noise and boilerplate sub-nodes
        $noise_query = '//script | //style | //noscript | //header | //footer | //nav | //form | //aside | //svg | //iframe | //figure | //figcaption | //*[contains(@class, "social")] | //*[contains(@class, "share")] | //*[contains(@class, "related")] | //*[contains(@class, "advert")] | //*[contains(@class, "sidebar")] | //*[contains(@class, "banner")] | //*[contains(@class, "comment")] | //*[contains(@class, "author-bio")] | //*[contains(@class, "newsletter")] | //*[contains(@class, "cookie")]';
        $noise_nodes = $xpath->query( $noise_query );
        if ( $noise_nodes ) {
            foreach ( $noise_nodes as $noise ) {
                if ( $noise->parentNode ) {
                    $noise->parentNode->removeChild( $noise );
                }
            }
        }

        // Priority list of containers
        $queries = [
            '//article',
            '//div[contains(@class, "entry-content") or contains(@class, "article__body") or contains(@class, "article-body") or contains(@class, "story-body") or contains(@class, "post-content") or contains(@class, "main-content") or contains(@class, "article-content") or contains(@class, "story-content")]',
            '//section[contains(@class, "story-body") or contains(@class, "article__content") or contains(@class, "article-body") or contains(@class, "story-content")]',
            '//main',
        ];

        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( $nodes && $nodes->length > 0 ) {
                $node_html = $doc->saveHTML( $nodes->item( 0 ) );
                if ( ! empty( $node_html ) ) {
                    $node_html = preg_replace( '#<(p|br|h[1-6]|li|div|blockquote)[^>]*>#i', "\n\n", $node_html );
                    $text = wp_strip_all_tags( $node_html );
                    $text = preg_replace( '/[ \t]+/', ' ', $text );
                    $text = preg_replace( '/\n{3,}/', "\n\n", $text );
                    if ( mb_strlen( trim( $text ) ) >= 10 ) {
                        return trim( $text );
                    }
                }
            }
        }

        return '';
    }

    /**
     * Tier 3: Extract from OpenGraph and Meta tags.
     */
    protected static function extract_from_opengraph( string $html ): array {
        $title = '';
        $description = '';
        $published_at = '';

        // og:title or twitter:title
        if ( preg_match( '/<meta\s+[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $title = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
        }

        // og:description or meta description
        if ( preg_match( '/<meta\s+[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:description["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']description["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*name=["\']twitter:description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $description = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
        }

        // article:published_time
        if ( preg_match( '/<meta\s+[^>]*property=["\']article:published_time["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']article:published_time["\']/i', $html, $m ) ) {
            $published_at = trim( $m[1] );
        }

        return [
            'title'        => $title,
            'content'      => $description,
            'published_at' => $published_at,
        ];
    }

    /**
     * Tier 4: LLM Smart Extractor Fallback for sparse or paywalled HTML.
     */
    protected static function extract_via_llm_fallback( string $html, string $url = '' ): array {
        if ( ! apply_filters( 'presshub_ai_enable_llm_extractor', true ) ) {
            return [];
        }

        if ( ! class_exists( 'PressHub_AI_API_Client' ) ) {
            return [];
        }

        try {
            $api = new PressHub_AI_API_Client();
            $cfg = $api->get_provider_config();
            if ( empty( $cfg ) || ( empty( $cfg['api_key'] ) && 'ollama_local' !== ( $cfg['type'] ?? '' ) ) ) {
                return [];
            }

            // Strip heavyweight elements and truncate HTML to avoid prompt bloat
            $stripped_html = preg_replace( '#<(script|style|svg|iframe|noscript)[^>]*>.*?</\1>#is', ' ', $html );
            $truncated_html = mb_substr( $stripped_html, 0, 25000 );

            $sys_prompt = 'You are an accurate news article extraction engine. Given raw HTML from a news webpage, extract the main news article headline and full body text in plain text. Exclude navigation menus, footer text, advertisements, cookie notices, and related article lists. Format your response strictly as:\nTITLE: [article headline]\nBODY:\n[full article body text]';

            $user_prompt = "Source URL: {$url}\n\nHTML:\n{$truncated_html}";

            $result = $api->call_provider( $sys_prompt, $user_prompt, false, [] );
            if ( is_wp_error( $result ) || empty( $result ) || ! is_string( $result ) ) {
                return [];
            }

            $title = '';
            $body  = '';

            if ( preg_match( '/TITLE:\s*(.*?)\nBODY:\s*(.*)$/is', $result, $m ) ) {
                $title = trim( $m[1] );
                $body  = trim( $m[2] );
            } else {
                $body = trim( $result );
            }

            return [
                'title'   => $title,
                'content' => $body,
            ];
        } catch ( Throwable $e ) {
            return [];
        }
    }

    /**
     * Strip noise, PHP dumps, boilerplate, and collapse whitespace.
     */
    protected static function clean_prose( string $text ): string {
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/[ \t]*\n[ \t]*/', "\n", $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );
        $text = trim( $text );
        $text = self::strip_php_dump_noise( $text );
        $text = self::cut_boilerplate( $text );
        return trim( $text );
    }

    /**
     * Fallback raw strip method.
     */
    protected static function extract_raw_stripped( string $html ): string {
        $clean = preg_replace( '#<(script|style|noscript|header|footer|nav|form)[^>]*>.*?</\1>#is', ' ', $html );
        $clean = preg_replace( '#<(p|br|h[1-6]|li|div|blockquote)[^>]*>#i', "\n", $clean );
        $text  = wp_strip_all_tags( $clean );
        return self::clean_prose( $text );
    }

    /**
     * Remove PHP var_dump / print_r debug output that some sites leak.
     */
    public static function strip_php_dump_noise( $text ): string {
        $lines = preg_split( '/\r?\n/', (string) $text );
        $out   = [];
        $in_dump = false;
        foreach ( $lines as $line ) {
            $line = rtrim( $line );
            if ( preg_match( '/^array\(\d+\)\s*\{$/', $line ) || preg_match( '/^(object|stdClass)\(/', $line ) ) {
                $in_dump = true;
                continue;
            }
            if ( $in_dump ) {
                if ( preg_match( '/^\[\d+\]=>$/', $line ) || preg_match( '/^(int|string|float|bool|NULL|array)\(/', $line ) || '' === $line || '}' === $line ) {
                    continue;
                }
                $in_dump = false;
            }
            $out[] = $line;
        }
        return implode( "\n", $out );
    }

    /**
     * Cut the article text at the first common boilerplate marker.
     */
    public static function cut_boilerplate( $text ): string {
        $markers = [
            'Εγγραφή στο Newsletter', 'ΣΧΟΛΙΑ', 'Προβολή σχολίων', 'ΣΧΕΤΙΚΑ ΝΕΑ',
            'TOP STORIES', 'ΤΑ ΑΚΙΝΗΤΑ ΤΗΣ ΕΒΔΟΜΑΔΑΣ',
            'Leave a comment', 'Comments', 'Related Articles', 'Related Stories',
            'Subscribe to our newsletter', 'Newsletter', 'Recommended for you',
        ];
        foreach ( $markers as $marker ) {
            $pos = function_exists( 'mb_strpos' ) ? mb_strpos( (string) $text, $marker ) : strpos( (string) $text, $marker );
            if ( false !== $pos ) {
                $cut = function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $pos ) : substr( (string) $text, 0, $pos );
                return trim( $cut );
            }
        }
        return (string) $text;
    }

    /**
     * Process a sources block: keep notes, fetch URLs, replace with article text.
     */
    public static function process_sources( $sources ): string {
        $sources = (string) $sources;
        if ( ! self::is_enabled() ) {
            return $sources;
        }
        $urls = self::extract_urls( $sources );
        if ( ! $urls ) {
            return $sources;
        }

        $notes = self::strip_urls_from_notes( $sources );
        $out   = [];
        if ( '' !== $notes ) {
            $out[] = $notes;
        }

        foreach ( array_slice( $urls, 0, self::MAX_URLS ) as $url ) {
            $article = self::fetch_article( $url );
            if ( '' !== $article ) {
                $out[] = 'Source article (fetched from ' . $url . '):' . "\n" . $article;
            } else {
                $out[] = 'NOTE: could not fetch ' . $url
                    . ' (blocked, paywalled or JavaScript-rendered). The URL is listed for reference — '
                    . 'paste the article text manually if the rebuttal needs its content: ' . $url;
            }
        }

        return implode( "\n\n", $out );
    }
}
