<?php
/**
 * Pronunciation list for text-to-speech (2.88.0).
 *
 * Words the voice keeps getting wrong, each with how it should be said. The list
 * is applied to the text sent to the TTS engine only (podcast turns, shorts,
 * TikTok narration, the Settings test button), never to the script that is
 * stored, shown in captions or put on slides.
 *
 * One entry per line in the setting `presshub_ai_tts_pronunciations`:
 *
 *     word = how to say it
 *     tr: word = how to say it      (only in that language: el, en, tr)
 *     # a comment
 *
 * A word matches whole words only, ignoring case. A longer entry wins over a
 * shorter one it contains; each part of the text is replaced at most once.
 *
 * @package presshub-ai-editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_TTS_Pronunciations {

    /** Option holding the list. */
    const OPTION = 'presshub_ai_tts_pronunciations';

    /** Longest list kept, in characters. */
    const MAX_CHARS = 20000;

    /** Most entries used. */
    const MAX_ENTRIES = 500;

    /** English language names used by the podcast languages, as codes. */
    const LANGUAGE_CODES = [
        'greek'   => 'el',
        'english' => 'en',
        'turkish' => 'tr',
    ];

    /** @var array<string, array{pattern:string, map:array<string,string>}> Compiled lists by "list hash|language". */
    private static $compiled = [];

    /** The saved list, as typed. */
    public static function get(): string {
        $value = function_exists( 'get_option' ) ? get_option( self::OPTION, '' ) : '';
        return is_string( $value ) ? $value : '';
    }

    /**
     * Sanitize the list: LF line endings, no tags or control characters, trimmed
     * lines, at most MAX_CHARS characters.
     *
     * @param mixed $value Raw value.
     */
    public static function sanitize( $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = str_replace( [ "\r\n", "\r" ], "\n", $value );
        $value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value );
        $value = (string) preg_replace( '/[^\P{C}\n\t]/u', '', $value );
        $lines = array_map( 'trim', explode( "\n", $value ) );
        $value = trim( implode( "\n", $lines ) );
        if ( mb_strlen( $value ) > self::MAX_CHARS ) {
            $value = mb_substr( $value, 0, self::MAX_CHARS );
        }
        return $value;
    }

    /**
     * Entries of a list, in order. Lines without "=", or with nothing on one side,
     * are skipped.
     *
     * @return array<int, array{lang:string, word:string, say:string}>
     */
    public static function parse( string $list ): array {
        $entries = [];
        foreach ( explode( "\n", str_replace( "\r", '', $list ) ) as $line ) {
            $line = trim( $line );
            if ( '' === $line || '#' === $line[0] ) {
                continue;
            }
            $lang = '';
            if ( preg_match( '/^([a-z]{2})\s*:\s*(.+)$/i', $line, $m ) && false !== strpos( $m[2], '=' ) ) {
                $lang = strtolower( $m[1] );
                $line = $m[2];
            }
            $eq = strpos( $line, '=' );
            if ( false === $eq ) {
                continue;
            }
            $word = trim( substr( $line, 0, $eq ) );
            $say  = trim( substr( $line, $eq + 1 ) );
            if ( '' === $word || '' === $say ) {
                continue;
            }
            $entries[] = [ 'lang' => $lang, 'word' => $word, 'say' => $say ];
            if ( count( $entries ) >= self::MAX_ENTRIES ) {
                break;
            }
        }
        return $entries;
    }

    /**
     * Language code of a language name or code: "Turkish" or "tr" gives "tr".
     * Empty gives "el" (the Greek podcast and shorts).
     */
    public static function language_code( string $language ): string {
        $language = strtolower( trim( $language ) );
        if ( '' === $language ) {
            return 'el';
        }
        if ( isset( self::LANGUAGE_CODES[ $language ] ) ) {
            return self::LANGUAGE_CODES[ $language ];
        }
        return substr( $language, 0, 2 );
    }

    /**
     * The text with every listed word replaced by how to say it.
     *
     * @param string      $text     Text about to be sent to the TTS engine.
     * @param string      $language Language name or code of the text ('' = Greek).
     * @param string|null $list     The list (default: the saved setting).
     */
    public static function apply( string $text, string $language = '', ?string $list = null ): string {
        if ( '' === trim( $text ) ) {
            return $text;
        }
        $list = null === $list ? self::get() : $list;
        if ( '' === trim( $list ) ) {
            return $text;
        }
        $lang = self::language_code( $language );
        $key  = md5( $list ) . '|' . $lang;
        if ( ! isset( self::$compiled[ $key ] ) ) {
            self::$compiled[ $key ] = self::compile( self::parse( $list ), $lang );
        }
        $compiled = self::$compiled[ $key ];
        if ( '' === $compiled['pattern'] ) {
            return $text;
        }
        $map    = $compiled['map'];
        $result = preg_replace_callback(
            $compiled['pattern'],
            static function ( array $m ) use ( $map ): string {
                $k = (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $m[0] ) );
                return $map[ $k ] ?? $m[0];
            },
            $text
        );
        return is_string( $result ) ? $result : $text;
    }

    /**
     * One pattern for every entry that applies in $lang, longest word first. An entry
     * for the language overrides an entry for every language with the same word; a
     * later line overrides an earlier one.
     *
     * @param array<int, array{lang:string, word:string, say:string}> $entries
     * @return array{pattern:string, map:array<string,string>}
     */
    private static function compile( array $entries, string $lang ): array {
        $map = [];
        foreach ( [ '', $lang ] as $pass ) {
            foreach ( $entries as $e ) {
                if ( $pass === $e['lang'] ) {
                    $map[ mb_strtolower( $e['word'] ) ] = $e['say'];
                }
            }
        }
        if ( empty( $map ) ) {
            return [ 'pattern' => '', 'map' => [] ];
        }
        $words = array_keys( $map );
        usort( $words, static function ( $a, $b ) {
            return mb_strlen( (string) $b ) <=> mb_strlen( (string) $a );
        } );
        $alts = array_map( static function ( $w ) {
            // Spaces in a phrase match any run of spaces.
            return (string) preg_replace( '/\s+/u', '\\s+', preg_quote( (string) $w, '/' ) );
        }, $words );
        $pattern = '/(?<![\p{L}\p{N}_])(?:' . implode( '|', $alts ) . ')(?![\p{L}\p{N}_])/iu';
        // A phrase matched across several spaces still finds its entry.
        $norm = [];
        foreach ( $map as $w => $say ) {
            $norm[ (string) preg_replace( '/\s+/u', ' ', (string) $w ) ] = $say;
        }
        return [ 'pattern' => $pattern, 'map' => $norm ];
    }

    /** Clears the compiled lists (tests). */
    public static function reset(): void {
        self::$compiled = [];
    }
}
