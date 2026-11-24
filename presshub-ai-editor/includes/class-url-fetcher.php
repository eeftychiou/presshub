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

    /** Length of the last extracted text before the MAX_CHARS_PER_URL cut (process_sources() records the cuts). */
    private static int $last_extract_len = 0;

    /** Request timeout in seconds. */
    const REQUEST_TIMEOUT = 15;

    /** UA so sites don't block the fetch as a generic bot. */
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 PressHub-AI-Co-Pilot/1.3.1';

    /**
     * Default modern browser request headers to minimize false-positive bot blocks.
     *
     * @return array<string, string>
     */
    public static function get_default_headers(): array {
        return (array) apply_filters( 'presshub_ai_url_fetcher_headers', [
            'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language'           => 'el,el-GR;q=0.9,en;q=0.8',
            'Sec-Ch-Ua'                 => '"Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
            'Sec-Ch-Ua-Mobile'          => '?0',
            'Sec-Ch-Ua-Platform'        => '"Windows"',
            'Sec-Fetch-Dest'            => 'document',
            'Sec-Fetch-Mode'            => 'navigate',
            'Sec-Fetch-Site'            => 'none',
            'Sec-Fetch-User'            => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ] );
    }

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
        $response = wp_remote_get( self::encode_iri( trim( (string) $url ) ), [
            'timeout'     => self::REQUEST_TIMEOUT,
            'user-agent'  => self::USER_AGENT,
            'redirection' => 3,
            'headers'     => self::get_default_headers(),
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
     * Count the words of a title (Unicode aware).
     *
     * @param string $title Title text.
     * @return int Number of words.
     */
    private static function count_title_words( string $title ): int {
        $parts = preg_split( '/\s+/u', trim( $title ), -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? count( $parts ) : 0;
    }

    /**
     * Find a headline that a page marks only with a large inline font size.
     *
     * Older sites exported from Word or Dreamweaver have no <h1> or og:title;
     * the headline is a paragraph or span styled with a big font-size, for
     * example `font-size:28.0pt`. This returns the text of the element with
     * the largest font-size of at least 18pt (or 24px) whose text is 3 to 30
     * words long, looking inside the first <article> when there is one.
     *
     * @param string $html Raw HTML.
     * @return string Headline text, or '' when none qualifies.
     */
    public static function extract_styled_headline( string $html ): string {
        $scope = $html;
        if ( preg_match( '#<article\b[^>]*>(.*?)</article>#is', $html, $am ) ) {
            $scope = $am[1];
        } elseif ( preg_match( '#<body\b[^>]*>(.*)$#is', $html, $bm ) ) {
            $scope = $bm[1];
        }
        $stripped = preg_replace(
            [ '#<!--.*?-->#s', '#<(script|style|header|nav|footer|aside|form)\b[^>]*>.*?</\1>#is' ],
            ' ',
            $scope
        );
        if ( is_string( $stripped ) ) {
            $scope = $stripped;
        }
        // Opening tags only, so a styled <span> inside an unstyled <p> is
        // still seen; the text runs to that tag's next closing tag.
        if ( ! is_string( $scope ) || ! preg_match_all(
            '#<(p|span|div|font|strong|b)\b([^>]*)>#i',
            $scope,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        ) ) {
            return '';
        }

        $best      = '';
        $best_size = 0.0;
        foreach ( $matches as $m ) {
            $attrs = $m[2][0];
            // Read font-size from the style attribute, which may itself
            // contain the other quote kind (font-family:'Candara').
            if ( ! preg_match( '#(?<![\w-])style\s*=\s*("|\')(.*?)\1#is', $attrs, $sm )
                || ! preg_match( '#(?:^|[;\s])font-size\s*:\s*([\d.]+)\s*(pt|px)#i', $sm[2], $fm ) ) {
                continue;
            }
            $start = $m[0][1] + strlen( $m[0][0] );
            $close = self::find_matching_close( $scope, strtolower( $m[1][0] ), $start );
            if ( false === $close ) {
                continue;
            }
            $inner = substr( $scope, $start, $close - $start );
            $size  = (float) $fm[1];
            $pt    = ( 'px' === strtolower( $fm[2] ) ) ? $size * 0.75 : $size;
            if ( $pt < 18.0 || $pt <= $best_size ) {
                continue;
            }
            $text  = html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $text  = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
            $words = self::count_title_words( $text );
            if ( $words < 3 || $words > 30 ) {
                continue;
            }
            $best      = $text;
            $best_size = $pt;
        }
        return $best;
    }

    /**
     * Find the closing tag that matches an opening tag, counting nested tags
     * of the same name (a styled <span> holding a red <span> kicker).
     *
     * @param string $html  HTML to search.
     * @param string $tag   Lower-case tag name.
     * @param int    $start Offset just after the opening tag.
     * @return int|false Offset of the matching closing tag, or false.
     */
    private static function find_matching_close( string $html, string $tag, int $start ) {
        $depth = 1;
        if ( ! preg_match_all( '#<(/?)' . preg_quote( $tag, '#' ) . '\b[^>]*>#i', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start ) ) {
            return false;
        }
        foreach ( $tags as $t ) {
            if ( '/' === $t[1][0] ) {
                $depth--;
                if ( 0 === $depth ) {
                    return $t[0][1];
                }
            } elseif ( '/' !== substr( $t[0][0], -2, 1 ) ) {
                $depth++;
            }
        }
        return false;
    }

    /**
     * Percent-encode the non-ASCII characters of a URL (IRI to URI).
     *
     * Feeds such as tothemaonline.com publish links with raw characters like
     * "–", "€" or "ϊ" in the path. PHP's FILTER_VALIDATE_URL rejects those, so
     * the article was dropped as "Invalid URL" although the site serves it.
     * Encoding them as UTF-8 percent escapes gives the same address in a form
     * that validates and that the HTTP client sends correctly. ASCII is left
     * as it is, so existing escapes are never encoded twice, and so is the
     * host.
     *
     * @param string $url URL that may contain non-ASCII characters.
     * @return string URL with every non-ASCII byte sequence percent-encoded.
     */
    public static function encode_iri( string $url ): string {
        if ( '' === $url || ! preg_match( '/[^\x00-\x7F]/', $url ) ) {
            return $url;
        }
        // Only the part after the host: a non-ASCII host (IDN) needs
        // punycode, not percent escapes, so it is left as it is.
        $prefix = '';
        $rest   = $url;
        if ( preg_match( '#^([a-z][a-z0-9+.\-]*://[^/?\#]*)(.*)$#is', $url, $parts ) ) {
            $prefix = $parts[1];
            $rest   = $parts[2];
        }
        $encoded = preg_replace_callback(
            '/[^\x00-\x7F]+/',
            static function ( $m ) {
                return rawurlencode( $m[0] );
            },
            $rest
        );
        return is_string( $encoded ) ? $prefix . $encoded : $url;
    }

    /**
     * Fetch one URL and return structured extraction result.
     *
     * @param string $url Target article URL.
     * @return array Structured article data.
     */
    public static function fetch_article_data( string $url ): array {
        $url = self::encode_iri( trim( $url ) );
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
            'headers'     => self::get_default_headers(),
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
        $result = self::extract_article_tiers( (string) $html, $url );
        // Only the JSON-LD and OpenGraph tiers read a date, so a page whose
        // text comes from the DOM (ciren.cy) had no published_at and passed
        // the pool's age check every day it stayed on the homepage.
        // A date strtotime() cannot read counts as none, so the page is still aged.
        $pub = trim( (string) ( $result['published_at'] ?? '' ) );
        if ( '' === $pub || false === strtotime( $pub ) ) {
            $result['published_at'] = self::extract_published_date( (string) $html );
        }
        return $result;
    }

    /**
     * The page's own publication date, whatever tier gives its text:
     * meta article:published_time (or a few common equivalents), then
     * JSON-LD datePublished anywhere in the page's graph, then
     * itemprop="datePublished". '' when the page states none.
     *
     * @param string $html Raw HTML.
     * @return string Date as written by the page.
     */
    public static function extract_published_date( string $html ): string {
        foreach ( [ 'article:published_time', 'og:published_time', 'datePublished', 'pubdate', 'publish-date', 'publish_date', 'dc.date', 'dc.date.issued' ] as $name ) {
            $q = preg_quote( $name, '/' );
            if ( preg_match( '/<meta\s+[^>]*(?:property|name|itemprop)=["\']' . $q . '["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m )
                || preg_match( '/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*(?:property|name|itemprop)=["\']' . $q . '["\']/i', $html, $m ) ) {
                $v = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
                if ( '' !== $v && false !== strtotime( $v ) ) {
                    return $v;
                }
            }
        }

        if ( preg_match_all( '#<script\s+[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
            foreach ( $matches[1] as $json_str ) {
                $decoded = json_decode( trim( $json_str ), true );
                if ( is_array( $decoded ) ) {
                    $found = self::find_json_ld_date( $decoded );
                    if ( '' !== $found ) {
                        return $found;
                    }
                }
            }
        }

        // itemprop: inside <article> or <main> first, so a sidebar's date is not taken.
        $scopes = [];
        foreach ( [ 'article', 'main' ] as $tag ) {
            if ( preg_match( '#<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>#is', $html, $sm ) ) {
                $scopes[] = $sm[1];
            }
        }
        $scopes[] = $html;
        foreach ( $scopes as $scope ) {
            if ( preg_match( '/<[a-z]+\s+[^>]*itemprop=["\']datePublished["\'][^>]*(?:datetime|content)=["\']([^"\']+)["\']/i', $scope, $m )
                || preg_match( '/<[a-z]+\s+[^>]*(?:datetime|content)=["\']([^"\']+)["\'][^>]*itemprop=["\']datePublished["\']/i', $scope, $m ) ) {
                $v = trim( $m[1] );
                if ( false !== strtotime( $v ) ) {
                    return $v;
                }
            }
        }
        return '';
    }

    /**
     * datePublished of the page's article node in a decoded JSON-LD value
     * (object, list or @graph). Only article-type nodes count (Article,
     * NewsArticle, BlogPosting, Report…), then a plain WebPage; an image,
     * video, site or collection page date is never taken for the article's.
     */
    private static function find_json_ld_date( array $decoded ): string {
        if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
            $nodes = $decoded['@graph'];
        } elseif ( isset( $decoded[0] ) ) {
            $nodes = $decoded;
        } else {
            $nodes = [ $decoded ];
        }
        $page_date = '';
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $date = isset( $node['datePublished'] ) && is_string( $node['datePublished'] ) ? trim( $node['datePublished'] ) : '';
            if ( '' === $date || false === strtotime( $date ) ) {
                continue;
            }
            $types = array_map( 'strtolower', (array) ( $node['@type'] ?? [] ) );
            foreach ( $types as $t ) {
                if ( false !== strpos( (string) $t, 'article' ) || in_array( $t, [ 'blogposting', 'report', 'liveblogposting' ], true ) ) {
                    return $date;
                }
            }
            if ( '' === $page_date && in_array( 'webpage', $types, true ) ) {
                $page_date = $date;
            }
        }
        return $page_date;
    }

    /** The content tiers of extract_article_semantic(). */
    protected static function extract_article_tiers( string $html, string $url = '' ): array {

        // Default extracted title from <h1>, OpenGraph, or HTML <title>
        $page_title = '';
        if ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', $html, $m ) ) {
            $page_title = trim( wp_strip_all_tags( $m[1] ) );
            $page_title = preg_replace( '/\s*[-|–—]\s*[^-\|–—]+$/u', '', $page_title );
        } elseif ( preg_match( '/<meta\s+[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\']/i', $html, $m )
            || preg_match( '/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $page_title = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
            $page_title = preg_replace( '/\s*[-|–—]\s*[^-\|–—]+$/u', '', $page_title );
        } elseif ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
            $page_title = trim( wp_strip_all_tags( $m[1] ) );
            $full_title = $page_title;
            $page_title = preg_replace( '/\s*[-|–—]\s*.*$/u', '', $page_title );
            // A page with no <h1> and no og:title whose <title> is only the
            // site name (socialistikiekfrasi.com: "Sosialistiki Ekfrasi" on
            // every page) would be thrown away as a shallow page. Use the
            // headline the page shows in large type instead.
            // The whole <title> must be short too, so a title such as
            // "COVID-19: Νέα μέτρα" that the separator cut leaves short does
            // not hand the title to large promo text.
            if ( self::count_title_words( (string) $page_title ) < 3 && self::count_title_words( $full_title ) < 3 ) {
                $styled = self::extract_styled_headline( $html );
                if ( '' !== $styled ) {
                    $page_title = $styled;
                }
            }
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
        self::$last_extract_len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

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

        // Strip noise and boilerplate sub-nodes (scripts, styles, nav, aside, footer, ads, etc.)
        $noise_query = '//script | //style | //noscript | //header | //footer | //nav | //form | //aside | //svg | //iframe | //figure | //figcaption | //*[contains(@class, "social")] | //*[contains(@class, "share")] | //*[contains(@class, "related")] | //*[contains(@class, "advert")] | //*[contains(@class, "sidebar")] | //*[contains(@class, "banner")] | //*[contains(@class, "comment")] | //*[contains(@class, "author-bio")] | //*[contains(@class, "newsletter")] | //*[contains(@class, "cookie")]';
        $noise_nodes = $xpath->query( $noise_query );
        if ( $noise_nodes ) {
            foreach ( $noise_nodes as $noise ) {
                if ( ! $noise->parentNode ) {
                    continue;
                }

                $tag_name = strtolower( $noise->nodeName );

                // Never remove root or primary content containers
                if ( in_array( $tag_name, [ 'html', 'body', 'main', 'article' ], true ) ) {
                    continue;
                }

                // Never remove containers with explicit article/content IDs
                if ( $noise->hasAttribute( 'id' ) ) {
                    $id = $noise->getAttribute( 'id' );
                    if ( preg_match( '/(printSection|article|story|content|body)/i', $id ) ) {
                        continue;
                    }
                }

                // For generic class-based noise matches, do not remove if node has substantial paragraph content
                if ( ! in_array( $tag_name, [ 'script', 'style', 'noscript', 'header', 'footer', 'nav', 'aside', 'svg', 'iframe', 'form' ], true ) ) {
                    $p_tags = $noise->getElementsByTagName( 'p' );
                    if ( $p_tags->length >= 3 || mb_strlen( trim( $noise->textContent ) ) > 600 ) {
                        continue;
                    }
                }

                $noise->parentNode->removeChild( $noise );
            }
        }

        // Expanded priority list of DOM containers
        $queries = [
            '//*[@id="printSection" or contains(@id, "printSection")]',
            '//*[contains(@id, "article-content") or contains(@id, "story-text") or contains(@id, "article-body")]',
            '//article',
            '//*[contains(@class, "entry-content") or contains(@class, "article__body") or contains(@class, "article-body") or contains(@class, "story-body") or contains(@class, "post-content") or contains(@class, "main-content") or contains(@class, "article-content") or contains(@class, "story-content") or contains(@class, "news-item-body") or contains(@class, "field--name-body") or contains(@class, "field-name-body") or contains(@class, "text-formatted") or contains(@class, "node__content") or contains(@class, "article-main-content")]',
            '//section[contains(@class, "story-body") or contains(@class, "article__content") or contains(@class, "article-body") or contains(@class, "story-content") or contains(@class, "news-item-body") or contains(@class, "node__content")]',
            '//main',
        ];

        $best_text   = '';
        $best_length = 0;
        $seen_nodes  = [];

        // Highest-Density Node Selection (Longest Text): Iterate through all matching container nodes
        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes || $nodes->length === 0 ) {
                continue;
            }

            foreach ( $nodes as $node ) {
                $node_path = ( method_exists( $node, 'getNodePath' ) && $node->getNodePath() ) ? $node->getNodePath() : spl_object_hash( $node );
                if ( isset( $seen_nodes[ $node_path ] ) ) {
                    continue;
                }
                $seen_nodes[ $node_path ] = true;

                $node_html = $doc->saveHTML( $node );
                if ( empty( $node_html ) ) {
                    continue;
                }

                $node_html = preg_replace( '#<(p|br|h[1-6]|li|div|blockquote)[^>]*>#i', "\n\n", $node_html );
                $text = wp_strip_all_tags( $node_html );
                $text = preg_replace( '/[ \t]+/', ' ', $text );
                $text = preg_replace( '/\n{3,}/', "\n\n", $text );
                $clean_text = trim( $text );
                $char_count = mb_strlen( $clean_text );

                if ( $char_count > $best_length ) {
                    $best_length = $char_count;
                    $best_text   = $clean_text;
                }
            }
        }

        // Paragraph Cluster Fallback:
        // If no specific container matches or all candidates yield < 200 chars,
        // aggregate paragraph clusters (//p) inside the largest common parent element containing substantial text.
        if ( $best_length < 200 ) {
            $p_nodes = $xpath->query( '//p' );
            if ( $p_nodes && $p_nodes->length > 0 ) {
                $parent_clusters = [];

                foreach ( $p_nodes as $p ) {
                    $p_text = trim( wp_strip_all_tags( $doc->saveHTML( $p ) ) );
                    if ( mb_strlen( $p_text ) < 10 ) {
                        continue;
                    }

                    // Collect candidate parent and grandparent nodes
                    $candidates = [];
                    if ( $p->parentNode && ! in_array( strtolower( $p->parentNode->nodeName ), [ 'html', 'body' ], true ) ) {
                        $candidates[] = $p->parentNode;
                    }
                    if ( $p->parentNode && $p->parentNode->parentNode && ! in_array( strtolower( $p->parentNode->parentNode->nodeName ), [ 'html', 'body' ], true ) ) {
                        $candidates[] = $p->parentNode->parentNode;
                    }

                    foreach ( $candidates as $cand ) {
                        $path = ( method_exists( $cand, 'getNodePath' ) && $cand->getNodePath() ) ? $cand->getNodePath() : spl_object_hash( $cand );
                        if ( ! isset( $parent_clusters[ $path ] ) ) {
                            $parent_clusters[ $path ] = $cand;
                        }
                    }
                }

                foreach ( $parent_clusters as $cluster_node ) {
                    $node_html = $doc->saveHTML( $cluster_node );
                    if ( empty( $node_html ) ) {
                        continue;
                    }
                    $node_html = preg_replace( '#<(p|br|h[1-6]|li|div|blockquote)[^>]*>#i', "\n\n", $node_html );
                    $text = wp_strip_all_tags( $node_html );
                    $text = preg_replace( '/[ \t]+/', ' ', $text );
                    $text = preg_replace( '/\n{3,}/', "\n\n", $text );
                    $clean_text = trim( $text );
                    $char_count = mb_strlen( $clean_text );

                    if ( $char_count > $best_length ) {
                        $best_length = $char_count;
                        $best_text   = $clean_text;
                    }
                }
            }
        }

        if ( $best_length >= 10 ) {
            return $best_text;
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
            // Its own action: the default (`coauthor_draft`) would log it as a
            // draft and add the agenda and policy fiches to an HTML extraction.
            $api->set_action( 'url_extract' );
            $cfg = $api->get_provider_config();
            if ( empty( $cfg ) || ( empty( $cfg['api_key'] ) && 'ollama_local' !== ( $cfg['type'] ?? '' ) ) ) {
                return [];
            }

            // Strip heavyweight elements and truncate HTML to avoid prompt bloat
            $stripped_html = preg_replace( '#<(script|style|svg|iframe|noscript)[^>]*>.*?</\1>#is', ' ', $html );
            // Recorded with this url_extract call (design principle: cuts are never silent).
            $truncated_html = class_exists( 'PressHub_AI_Knowledge_Cuts' )
                ? PressHub_AI_Knowledge_Cuts::clip_material( 'url_extract', (string) $stripped_html, 25000, __( 'Page HTML', 'presshub-ai-editor' ) )
                : mb_substr( $stripped_html, 0, 25000 );

            $sys_prompt = 'You are an accurate news article extraction engine. Given raw HTML from a news webpage, extract the main news article headline and full body text in plain text. Exclude navigation menus, footer text, advertisements, cookie notices, and related article lists. Format your response strictly as:\nTITLE: [article headline]\nBODY:\n[full article body text]';

            $user_prompt = "Source URL: {$url}\n\nHTML:\n{$truncated_html}";

            $result = $api->call_provider( $sys_prompt, $user_prompt, false, [] );
            if ( class_exists( 'PressHub_AI_Knowledge_Cuts' ) ) {
                PressHub_AI_Knowledge_Cuts::forget_material( 'url_extract' ); // taken by the call, or the call never ran
            }
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
     * $for is the action of the call the sources go into; the cuts are noted
     * for it (none when it is empty).
     */
    public static function process_sources( $sources, string $for = '' ): string {
        if ( '' !== $for && class_exists( 'PressHub_AI_Knowledge_Cuts' ) ) {
            // A new sources block replaces what an earlier one noted.
            PressHub_AI_Knowledge_Cuts::forget_material( $for );
        }
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

        // The cuts belong to the caller's call ($for), not to a page's own
        // extraction call (url_extract).
        $cuts = [];
        if ( count( $urls ) > self::MAX_URLS ) {
            $cuts[] = [ __( 'Source URLs', 'presshub-ai-editor' ), 'max_items', self::MAX_URLS, count( $urls ), -1 ];
        }
        foreach ( array_slice( $urls, 0, self::MAX_URLS ) as $url ) {
            self::$last_extract_len = 0;
            $article = self::fetch_article( $url );
            if ( '' !== $article && self::$last_extract_len > self::MAX_CHARS_PER_URL ) {
                /* translators: %s: URL */
                $cuts[] = [ sprintf( __( 'Fetched page %s', 'presshub-ai-editor' ), $url ), 'clipped', self::MAX_CHARS_PER_URL, self::$last_extract_len, self::MAX_CHARS_PER_URL ];
            }
            if ( '' !== $article ) {
                $out[] = 'Source article (fetched from ' . $url . '):' . "\n" . $article;
            } else {
                $out[] = 'NOTE: could not fetch ' . $url
                    . ' (blocked, paywalled or JavaScript-rendered). The URL is listed for reference — '
                    . 'paste the article text manually if the rebuttal needs its content: ' . $url;
            }
        }
        if ( $cuts && '' !== $for && class_exists( 'PressHub_AI_Knowledge_Cuts' ) ) {
            foreach ( $cuts as $c ) {
                PressHub_AI_Knowledge_Cuts::note_material( $for, $c[0], $c[1], $c[2], $c[3], $c[4] );
            }
        }

        return implode( "\n\n", $out );
    }
}
