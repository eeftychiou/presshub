<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PressHub_AI_URL_Fetcher — server-side URL sourcing for AI prompts.
 *
 * Design decision (2026-08-16): chat-completion models cannot browse URLs
 * (none of OpenAI/Anthropic/Gemini fetch arbitrary links in the plain
 * completions API). Passing a bare URL string makes the model guess from
 * the slug. This class fetches each source URL server-side, extracts the
 * readable article text, and substitutes it into the sources block so the
 * model actually reads the article.
 *
 * Enabled by default (presshub_ai_fetch_urls option, '1'); disable via the
 * option or the presshub_ai_fetch_source_urls filter. Failures are
 * non-fatal: an explanatory note keeps the URL for the model's reference.
 *
 * @since 1.2.4
 */
class PressHub_AI_URL_Fetcher {

    /** Hard cap on URLs fetched per request. */
    const MAX_URLS = 5;

    /** Per-URL character budget (roughly 10k tokens). */
    const MAX_CHARS_PER_URL = 40000;

    /** Request timeout in seconds. */
    const REQUEST_TIMEOUT = 15;

    /** UA so sites don't block the fetch as a generic bot. */
    const USER_AGENT = 'PressHub-AI-Co-Pilot/1.2.4 (+https://github.com/eeftychiou/presshub)';

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
            'timeout'   => self::REQUEST_TIMEOUT,
            'user-agent' => self::USER_AGENT,
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
     * Extract readable text from article HTML:
     * prefer <article>/<main>, drop nav/script/style/footer, convert
     * block elements to newlines, strip tags, collapse whitespace,
     * truncate to the per-URL budget.
     */
    public static function extract_text( $html ): string {
        $html = (string) $html;

        if ( preg_match( '#<article[^>]*>(.*?)</article>#is', $html, $m ) ) {
            $html = $m[1];
        } elseif ( preg_match( '#<main[^>]*>(.*?)</main>#is', $html, $m ) ) {
            $html = $m[1];
        }

        $html = preg_replace( '#<(script|style|noscript|header|footer|nav|form)[^>]*>.*?</\1>#is', ' ', $html );

        // Block elements become paragraph breaks.
        $html = preg_replace( '#<(p|br|h[1-6]|li|div|blockquote)[^>]*>#i', "\n", $html );

        $text = wp_strip_all_tags( $html );
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/[ \t]*\n[ \t]*/', "\n", $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );
        $text = trim( $text );

        $text = self::strip_php_dump_noise( $text );
        $text = self::cut_boilerplate( $text );

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
     * Remove PHP var_dump / print_r debug output that some sites leak
     * into their HTML (e.g. "array(1) {", "[0]=>", "int(453)" lines).
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
     * Cut the article text at the first common boilerplate marker
     * (newsletter signup, comments, related stories, ads) that sites
     * often nest inside their <article> container. English + Greek.
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
     * Process a sources block: keep the notes, fetch every URL, replace
     * each with its extracted article text (or an explanatory failure
     * note when the fetch fails).
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
