<?php
/**
 * PressHub_AI_Context_Estimator - UTF-8-safe word & token estimation helpers.
 *
 * Provides:
 *   - utf8_word_count( $text )           multibyte-safe word counter for Greek,
 *                                       Cyrillic, CJK (Han, Hiragana, Katakana,
 *                                       Hangul), Arabic, and mixed-script text.
 *                                       Replaces the buggy str_word_count() and
 *                                       split(/\s+/) patterns that miscounted
 *                                       non-Latin articles.
 *   - estimate_tokens( $text )          conservative char-based heuristic
 *                                       (about 1 token per 3 UTF-8 codepoints).
 *   - summarize( $text )                returns ['words' => int, 'tokens' => int].
 *   - clamp_max_context_tokens( $value) validates the briefing max-context-token
 *                                       threshold option against safe bounds.
 *
 * The word-count algorithm:
 *     1. Strip HTML tags, decode HTML entities.
 *     2. Split on Unicode whitespace runs (PREG_SPLIT_NO_EMPTY).
 *     3. For each whitespace token, count CJK codepoints individually and treat
 *        each non-CJK run as one word.
 *
 * For CJK we use explicit codepoint ranges rather than Unicode property classes for
 * portability across regex engines.
 *
 * @package PressHub_AI_Editor
 * @since   1.9.8
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRESSHUB_AI_TESTSUITE' ) ) {
    exit;
}

/**
 * Class PressHub_AI_Context_Estimator
 *
 * All methods are static and side-effect-free. Loaded from presshub-ai-editor.php
 * before any consumer (class-briefing-admin, class-podcast-producer,
 * assets/briefing-admin.js mirror).
 */
class PressHub_AI_Context_Estimator {

    /** Lower bound for the briefing max-context-token threshold (inclusive). */
    const MIN_MAX_CONTEXT_TOKENS = 5000;

    /** Upper bound for the briefing max-context-token threshold (inclusive). */
    const MAX_MAX_CONTEXT_TOKENS = 200000;

    /** Default value for the briefing max-context-token threshold. */
    const DEFAULT_MAX_CONTEXT_TOKENS = 40000;

    /** Approximate codepoints-per-token used by estimate_tokens(). */
    const CHARS_PER_TOKEN = 3;

    /**
     * UTF-8-safe word counter.
     *
     * Counts words for Latin, Greek, Cyrillic, Han, Hiragana, Katakana, Hangul,
     * Arabic, and mixed-script text without relying on str_word_count() (Latin
     * only) or a naive split(/\s+/) (miscounts CJK).
     *
     * @param  string $text  Raw text (may contain HTML).
     * @return int           Word count (>= 0).
     */
    public static function utf8_word_count( $text ): int {
        if ( null === $text || '' === $text ) {
            return 0;
        }
        $text = (string) $text;

        $text = strip_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        $text = trim( $text );
        if ( '' === $text ) {
            return 0;
        }

        $tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $tokens ) || empty( $tokens ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $tokens as $token ) {
            $count += self::count_tokens_in_word( $token );
        }
        return max( 0, $count );
    }

    /**
     * Tokenise a single whitespace-delimited token into word units.
     *
     * Explicit Unicode codepoint ranges used for portability:
     *   - Han:        U+4E00..U+9FFF, U+3400..U+4DBF, U+20000..U+2A6DF,
     *                 U+F900..U+FAFF, U+2F800..U+2FA1F
     *   - Hiragana:   U+3040..U+309F
     *   - Katakana:   U+30A0..U+30FF, U+31F0..U+31FF
     *   - Hangul:     U+AC00..U+D7AF, U+1100..U+11FF, U+3130..U+318F,
     *                 U+A960..U+A97F, U+D7B0..U+D7FF
     *
     * @param  string $token  A single whitespace-delimited token.
     * @return int            Number of words inside this token (>= 1).
     */
    private static function count_tokens_in_word( string $token ): int {
        $pattern = '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{20000}-\x{2A6DF}\x{F900}-\x{FAFF}\x{2F800}-\x{2FA1F}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{31F0}-\x{31FF}\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{A960}-\x{A97F}\x{D7B0}-\x{D7FF}]/u';

        if ( ! preg_match_all( $pattern, $token, $matches, PREG_OFFSET_CAPTURE ) ) {
            return 1;
        }

        $len = strlen( $token );
        $cjk_positions = [];
        foreach ( $matches[0] as $m ) {
            $cjk_positions[] = (int) $m[1];
        }

        if ( empty( $cjk_positions ) ) {
            return 1;
        }

        // Detect "all CJK" - if first CJK byte is at 0 and last CJK byte
        // covers the rest of the token, return the codepoint count.
        $first = $cjk_positions[0];
        $last_pos = end( $cjk_positions );
        $last_idx = count( $cjk_positions ) - 1;
        $last_byte_end = $last_pos + strlen( $matches[0][ $last_idx ][0] );
        if ( 0 === $first && $last_byte_end >= $len ) {
            return mb_strlen( $token, 'UTF-8' );
        }

        // Mixed token: count non-CJK runs as 1 word each, CJK as 1 each.
        $words = 0;
        $cursor = 0;
        foreach ( $cjk_positions as $i => $pos ) {
            if ( $pos > $cursor ) {
                $words++; // non-CJK run before this CJK codepoint
            }
            $words++;   // the CJK codepoint itself
            $cursor = $pos + strlen( $matches[0][ $i ][0] );
        }
        if ( $cursor < $len ) {
            $words++; // trailing non-CJK run
        }
        return max( 1, $words );
    }

    /**
     * Estimate the LLM token cost of the given text using a conservative
     * codepoint/3 heuristic.
     */
    public static function estimate_tokens( $text ): int {
        if ( null === $text || '' === $text ) {
            return 0;
        }
        $text = (string) $text;
        $codepoints = mb_strlen( strip_tags( $text ), 'UTF-8' );
        if ( $codepoints <= 0 ) {
            return 0;
        }
        return max( 1, intdiv( $codepoints, self::CHARS_PER_TOKEN ) );
    }

    /**
     * Return both word and token estimates in a single call.
     *
     * @return array{words:int,tokens:int}
     */
    public static function summarize( $text ): array {
        return array(
            'words'  => self::utf8_word_count( $text ),
            'tokens' => self::estimate_tokens( $text ),
        );
    }

    /**
     * Clamp a user-provided max-context-tokens value into the safe range
     * [MIN_MAX_CONTEXT_TOKENS, MAX_MAX_CONTEXT_TOKENS]. Out-of-range or
     * non-numeric input falls back to DEFAULT_MAX_CONTEXT_TOKENS.
     */
    public static function clamp_max_context_tokens( $value ): int {
        if ( ! is_numeric( $value ) ) {
            return self::DEFAULT_MAX_CONTEXT_TOKENS;
        }
        $value = (int) $value;
        if ( $value < self::MIN_MAX_CONTEXT_TOKENS ) {
            return self::MIN_MAX_CONTEXT_TOKENS;
        }
        if ( $value > self::MAX_MAX_CONTEXT_TOKENS ) {
            return self::MAX_MAX_CONTEXT_TOKENS;
        }
        return $value;
    }
}
