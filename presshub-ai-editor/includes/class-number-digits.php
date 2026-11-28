<?php
/**
 * Greek numbers spelled out in words, written as digits (2.91.0).
 *
 * The podcast script spells numbers out for the voice ("είκοσι οκτώ Νοεμβρίου"), and the
 * AI copies that into the post text it writes from it. Social post text is read, not
 * heard, so apply() turns those numbers into digits: "είκοσι οκτώ Νοεμβρίου" →
 * "28 Νοεμβρίου", "είκοσι έξι τοις εκατό" → "26%", "δύο χιλιάδες πεντακόσια ευρώ" →
 * "2.500 ευρώ", "τρία εκατομμύρια" → "3 εκατομμύρια". Only post text goes through it
 * (AI captions, video titles, AI social copy); a short's script stays as written, so the
 * voice is unchanged, and copy an editor typed is left alone.
 *
 * Rules, chosen so that ordinary words are never changed:
 * - A run of number words becomes digits only when it reads as ONE number. "δύο τρεις
 *   φορές" (two or three times) stays in words.
 * - Numbers below 10 stay in words ("δύο υπουργοί", "ένας στους τρεις"), unless a month
 *   ("3 Μαΐου"), "τοις εκατό" ("5%"), a decimal ("2,5") or a scale word
 *   ("3 εκατομμύρια") follows.
 * - "χιλιάδες" or "εκατομμύρια" on their own ("χιλιάδες πολίτες") stay in words;
 *   millions and billions keep the word ("29 εκατομμυρίων").
 * - 1900 to 2099 are written as years (2026); other numbers from 1.000 get a dot.
 * - Ordinals ("τρίτη θέση"), words inside hashtags or links and other languages are
 *   not touched.
 */

class PressHub_AI_Number_Digits {

    /** Units: word (no accents, lower case) => value. */
    private const UNITS = [
        'ενα' => 1, 'ενας' => 1, 'μια' => 1, 'μιας' => 1, 'ενος' => 1,
        'δυο' => 2,
        'τρια' => 3, 'τρεις' => 3, 'τριων' => 3,
        'τεσσερα' => 4, 'τεσσερις' => 4, 'τεσσαρων' => 4,
        'πεντε' => 5, 'εξι' => 6,
        'επτα' => 7, 'εφτα' => 7,
        'οκτω' => 8, 'οχτω' => 8,
        'εννεα' => 9, 'εννια' => 9,
    ];

    private const TEENS = [
        'δεκα' => 10, 'εντεκα' => 11, 'δωδεκα' => 12,
        'δεκατρια' => 13, 'δεκατρεις' => 13, 'δεκατριων' => 13,
        'δεκατεσσερα' => 14, 'δεκατεσσερις' => 14, 'δεκατεσσαρων' => 14,
        'δεκαπεντε' => 15, 'δεκαεξι' => 16, 'δεκαεπτα' => 17, 'δεκαεφτα' => 17,
        'δεκαοκτω' => 18, 'δεκαοχτω' => 18, 'δεκαεννεα' => 19, 'δεκαεννια' => 19,
    ];

    private const TENS = [
        'εικοσι' => 20, 'τριαντα' => 30, 'σαραντα' => 40, 'πενηντα' => 50,
        'εξηντα' => 60, 'εβδομηντα' => 70, 'ογδοντα' => 80, 'ενενηντα' => 90,
    ];

    /** Stems of 200..900; endings in HUNDRED_ENDINGS. "εκατό(ν)" is 100. */
    private const HUNDRED_STEMS = [
        'διακοσ' => 200, 'τριακοσ' => 300, 'τετρακοσ' => 400, 'πεντακοσ' => 500,
        'εξακοσ' => 600, 'επτακοσ' => 700, 'εφτακοσ' => 700, 'οκτακοσ' => 800,
        'οχτακοσ' => 800, 'εννιακοσ' => 900, 'εννεακοσ' => 900,
    ];
    private const HUNDRED_ENDINGS = [ 'ια', 'ιοι', 'ιες', 'ιων', 'ιους' ];

    private const THOUSAND = [ 'χιλια', 'χιλιοι', 'χιλιες', 'χιλιων', 'χιλιους' ];
    private const THOUSANDS = [ 'χιλιαδες', 'χιλιαδων' ];

    private const MONTHS = [
        'ιανουαριου', 'φεβρουαριου', 'μαρτιου', 'απριλιου', 'μαιου', 'ιουνιου', 'ιουλιου',
        'αυγουστου', 'σεπτεμβριου', 'οκτωβριου', 'νοεμβριου', 'δεκεμβριου',
    ];

    /** @var array<string,array{0:string,1:int}>|null word => [ kind, value ] */
    private static $words = null;

    /** @var string|null */
    private static $pattern = null;

    /**
     * Write the Greek numbers of a text as digits. Text without Greek number words comes
     * back unchanged.
     */
    public static function apply( string $text ): string {
        if ( '' === $text || ! preg_match( '/\p{Greek}/u', $text ) ) {
            return $text;
        }
        $out = preg_replace_callback( self::pattern(), [ __CLASS__, 'replace' ], $text );
        return is_string( $out ) ? $out : $text;
    }

    /** @param array<int|string,string> $m */
    private static function replace( array $m ): string {
        $whole   = $m[0];
        $run     = $m['run'];
        $percent = isset( $m['pct'] ) && '' !== $m['pct'];
        $next    = isset( $m['next'] ) ? self::key( $m['next'] ) : '';

        $tokens = preg_split( '/\s+/u', trim( $run ) );
        $parsed = self::parse( array_map( [ __CLASS__, 'key' ], $tokens ) );
        if ( null === $parsed ) {
            return $whole;
        }
        [ $value, $decimal ] = $parsed;

        $is_month = in_array( $next, self::MONTHS, true );
        $is_scale = (bool) preg_match( '/^(?:δισ)?εκατομμυρι/u', $next );
        if ( $value < 10 && ! $percent && null === $decimal && ! $is_month && ! $is_scale ) {
            return $whole;
        }

        $digits = self::format( $value, $is_month );
        if ( null !== $decimal ) {
            $digits .= ',' . $decimal;
        }
        if ( $percent ) {
            return $digits . '%';
        }
        // Keep what followed the run (the space before the next word is in the lookahead).
        return $digits;
    }

    /**
     * Parse number words (accent-free, lower case) into one value, optionally with
     * "κόμμα" and decimal digits. Null when they are not exactly one number.
     *
     * @param string[] $t
     * @return array{0:int,1:?string}|null
     */
    private static function parse( array $t ): ?array {
        $i     = 0;
        $value = self::parse_thousands( $t, $i );
        if ( null === $value ) {
            return null;
        }
        $decimal = null;
        if ( $i < count( $t ) && 'κομμα' === $t[ $i ] ) {
            $i++;
            $frac = self::parse_group( $t, $i );
            if ( null === $frac ) {
                return null;
            }
            $decimal = (string) $frac;
        }
        return $i === count( $t ) ? [ $value, $decimal ] : null;
    }

    /** [group χιλιάδες | χίλια] [group] — below a million. */
    private static function parse_thousands( array $t, int &$i ): ?int {
        $n = count( $t );
        if ( $i < $n && in_array( $t[ $i ], self::THOUSAND, true ) ) {
            $i++;
            $rest = self::parse_group( $t, $i );
            return 1000 + (int) $rest;
        }
        $start = $i;
        $group = self::parse_group( $t, $i );
        if ( null === $group ) {
            return null;
        }
        if ( $i < $n && in_array( $t[ $i ], self::THOUSANDS, true ) ) {
            if ( $group < 2 ) {
                $i = $start;
                return null;
            }
            $i++;
            $rest = self::parse_group( $t, $i );
            return $group * 1000 + (int) $rest;
        }
        return $group;
    }

    /** [hundreds] [tens [units] | teens | units] — 1..999. */
    private static function parse_group( array $t, int &$i ): ?int {
        $n     = count( $t );
        $value = 0;
        $any   = false;
        $kind  = static function ( int $k ) use ( $t, $n ): ?array {
            return $k < $n ? ( self::words()[ $t[ $k ] ] ?? null ) : null;
        };

        $w = $kind( $i );
        if ( $w && 'hundred' === $w[0] ) {
            $value += $w[1];
            $any    = true;
            $i++;
            $w = $kind( $i );
        }
        if ( $w && 'ten' === $w[0] ) {
            $value += $w[1];
            $any    = true;
            $i++;
            $w = $kind( $i );
            if ( $w && 'unit' === $w[0] ) {
                $value += $w[1];
                $i++;
            }
        } elseif ( $w && ( 'teen' === $w[0] || 'unit' === $w[0] ) ) {
            $value += $w[1];
            $any    = true;
            $i++;
        }
        return $any ? $value : null;
    }

    private static function format( int $value, bool $is_date ): string {
        if ( $is_date || $value < 1000 || ( $value >= 1900 && $value <= 2099 ) ) {
            return (string) $value;
        }
        return number_format( $value, 0, ',', '.' );
    }

    /** Lower case without accents, final sigma as "ς". */
    private static function key( string $word ): string {
        $w = mb_strtolower( $word, 'UTF-8' );
        $w = strtr( $w, [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ϊ' => 'ι', 'ΐ' => 'ι', 'ϋ' => 'υ', 'ΰ' => 'υ',
        ] );
        return (string) preg_replace( '/σ$/u', 'ς', $w );
    }

    /** @return array<string,array{0:string,1:int}> */
    private static function words(): array {
        if ( null !== self::$words ) {
            return self::$words;
        }
        $w = [];
        foreach ( self::UNITS as $k => $v ) {
            $w[ $k ] = [ 'unit', $v ];
        }
        foreach ( self::TEENS as $k => $v ) {
            $w[ $k ] = [ 'teen', $v ];
        }
        foreach ( self::TENS as $k => $v ) {
            $w[ $k ] = [ 'ten', $v ];
        }
        $w['εκατο']  = [ 'hundred', 100 ];
        $w['εκατον'] = [ 'hundred', 100 ];
        foreach ( self::HUNDRED_STEMS as $stem => $v ) {
            foreach ( self::HUNDRED_ENDINGS as $end ) {
                $w[ $stem . $end ] = [ 'hundred', $v ];
            }
        }
        foreach ( self::THOUSAND as $k ) {
            $w[ $k ] = [ 'thousand', 1000 ];
        }
        foreach ( self::THOUSANDS as $k ) {
            $w[ $k ] = [ 'thousands', 1000 ];
        }
        self::$words = $w;
        return $w;
    }

    /**
     * A run of Greek words (each checked in replace() / parse()), not inside a hashtag,
     * mention or link, plus an optional "τοις εκατό" and a look at the next word.
     */
    private static function pattern(): string {
        if ( null !== self::$pattern ) {
            return self::$pattern;
        }
        // Any spelling of a number word: match loosely (Greek letters), then look it up.
        $alts = [];
        foreach ( array_keys( self::words() ) as $k ) {
            $alts[] = self::loose( $k );
        }
        $alts[] = self::loose( 'κομμα' );
        usort( $alts, static function ( $a, $b ) {
            return strlen( $b ) <=> strlen( $a );
        } );
        $word = '(?:' . implode( '|', $alts ) . ')';
        $b    = '(?![\p{L}\p{M}\p{N}])';
        // Not right after a letter, digit, # @ / _ - or "τοις " (a lone "εκατό" of "τοις εκατό").
        $pre = '(?<![\p{L}\p{M}\p{N}#@\/_\-])(?<!' . self::loose( 'τοις' ) . ' )';
        self::$pattern = '/' . $pre . '(?<run>' . $word . $b . '(?:[ \t]+' . $word . $b . ')*)'
            . '(?<pct>[ \t]+' . self::loose( 'τοις' ) . '[ \t]+' . self::loose( 'εκατο' ) . $b . ')?'
            . '(?=[ \t]+(?<next>\p{L}+)|)/iu';
        return self::$pattern;
    }

    /** A regex for a word that matches it with or without accents and in any case. */
    private static function loose( string $key ): string {
        $map = [
            'α' => '[αά]', 'ε' => '[εέ]', 'η' => '[ηή]', 'ι' => '[ιίϊΐ]', 'ο' => '[οό]',
            'υ' => '[υύϋΰ]', 'ω' => '[ωώ]', 'ς' => '[ςσ]',
        ];
        $out = '';
        foreach ( preg_split( '//u', $key, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
            $out .= $map[ $ch ] ?? preg_quote( $ch, '/' );
        }
        return $out;
    }
}
