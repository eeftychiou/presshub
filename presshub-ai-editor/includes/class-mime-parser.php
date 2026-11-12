<?php
/**
 * Small RFC 822 / MIME parser for press release emails.
 *
 * Extracts decoded headers, the best plain-text body (text/plain preferred,
 * text/html converted to text otherwise) and attachments. Handles
 * multipart nesting, base64 / quoted-printable, RFC 2047 encoded words,
 * RFC 2231 file names and legacy Greek charsets (ISO-8859-7, Windows-1253).
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Mime_Parser {

    /** Maximum multipart nesting depth followed. */
    const MAX_DEPTH = 8;

    /**
     * Parse a raw message.
     *
     * @param string $raw Raw RFC 822 message.
     * @return array{
     *   subject:string, from_name:string, from_email:string, date:string, message_id:string,
     *   text:string, html:string,
     *   attachments: array<int, array{filename:string, mime:string, data:string, size:int, inline:bool}>
     * }
     */
    public static function parse( string $raw ): array {
        $result = [
            'subject'     => '',
            'from_name'   => '',
            'from_email'  => '',
            'date'        => '',
            'message_id'  => '',
            'text'        => '',
            'html'        => '',
            'attachments' => [],
        ];

        [ $headers, $body ] = self::split_headers_body( $raw );

        $result['subject']    = self::decode_header_value( $headers['subject'] ?? '' );
        $result['date']       = trim( $headers['date'] ?? '' );
        $result['message_id'] = trim( $headers['message-id'] ?? '', " \t<>" );
        [ $result['from_name'], $result['from_email'] ] = self::parse_address( $headers['from'] ?? '' );

        $texts = [];
        $htmls = [];
        self::walk_part( $headers, $body, $texts, $htmls, $result['attachments'], 0 );

        $result['text'] = trim( implode( "\n\n", $texts ) );
        $result['html'] = trim( implode( "\n", $htmls ) );
        if ( '' === $result['text'] && '' !== $result['html'] ) {
            $result['text'] = self::html_to_text( $result['html'] );
        }
        return $result;
    }

    /**
     * Split a part into lower-cased, unfolded headers and its body.
     *
     * @return array{0: array<string,string>, 1: string}
     */
    public static function split_headers_body( string $raw ): array {
        $raw = ltrim( $raw, "\r\n" );
        $pos = strpos( $raw, "\r\n\r\n" );
        $sep = 4;
        $lf  = strpos( $raw, "\n\n" );
        if ( false === $pos || ( false !== $lf && $lf < $pos ) ) {
            $pos = $lf;
            $sep = 2;
        }
        if ( false === $pos ) {
            return [ self::parse_headers( $raw ), '' ];
        }
        return [ self::parse_headers( substr( $raw, 0, $pos ) ), (string) substr( $raw, $pos + $sep ) ];
    }

    /**
     * @return array<string,string>
     */
    private static function parse_headers( string $block ): array {
        $block   = preg_replace( "/\r?\n[ \t]+/", ' ', $block );
        $headers = [];
        foreach ( preg_split( "/\r?\n/", (string) $block ) as $line ) {
            $c = strpos( $line, ':' );
            if ( false === $c ) {
                continue;
            }
            $name = strtolower( trim( substr( $line, 0, $c ) ) );
            if ( ! isset( $headers[ $name ] ) ) {
                $headers[ $name ] = trim( substr( $line, $c + 1 ) );
            }
        }
        return $headers;
    }

    /**
     * Parse "type/subtype; a=b; c="d"" into [value, params].
     *
     * @return array{0:string, 1:array<string,string>}
     */
    public static function parse_header_params( string $value ): array {
        $parts  = self::split_unquoted( $value, ';' );
        $main   = strtolower( trim( (string) array_shift( $parts ) ) );
        $params = [];
        $cont   = [];
        foreach ( $parts as $p ) {
            $eq = strpos( $p, '=' );
            if ( false === $eq ) {
                continue;
            }
            $k = strtolower( trim( substr( $p, 0, $eq ) ) );
            $v = trim( substr( $p, $eq + 1 ) );
            if ( strlen( $v ) >= 2 && '"' === $v[0] && '"' === substr( $v, -1 ) ) {
                $v = stripcslashes( substr( $v, 1, -1 ) );
            }
            // RFC 2231 continuations: name*0*=..., name*1*=...
            if ( preg_match( '/^([^*]+)\*(\d+)\*?$/', $k, $m ) ) {
                $cont[ $m[1] ][ (int) $m[2] ] = [ $v, '*' === substr( $k, -1 ) ];
                continue;
            }
            if ( '*' === substr( $k, -1 ) ) {
                $params[ substr( $k, 0, -1 ) ] = self::decode_rfc2231( $v );
                continue;
            }
            $params[ $k ] = $v;
        }
        foreach ( $cont as $name => $pieces ) {
            ksort( $pieces );
            $joined  = '';
            $encoded = false;
            foreach ( $pieces as $piece ) {
                $joined .= $piece[0];
                $encoded = $encoded || $piece[1];
            }
            $params[ $name ] = $encoded ? self::decode_rfc2231( $joined ) : $joined;
        }
        return [ $main, $params ];
    }

    private static function split_unquoted( string $s, string $delim ): array {
        $out   = [];
        $buf   = '';
        $quote = false;
        $len   = strlen( $s );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $s[ $i ];
            if ( '\\' === $ch && $quote && $i + 1 < $len ) {
                $buf .= $ch . $s[ ++$i ];
                continue;
            }
            if ( '"' === $ch ) {
                $quote = ! $quote;
            }
            if ( $ch === $delim && ! $quote ) {
                $out[] = $buf;
                $buf   = '';
                continue;
            }
            $buf .= $ch;
        }
        $out[] = $buf;
        return $out;
    }

    private static function decode_rfc2231( string $v ): string {
        // charset'lang'percent-encoded
        if ( preg_match( "/^([^']*)'[^']*'(.*)$/s", $v, $m ) ) {
            return self::to_utf8( rawurldecode( $m[2] ), $m[1] ?: 'UTF-8' );
        }
        return rawurldecode( $v );
    }

    /**
     * Decode RFC 2047 encoded words to UTF-8.
     */
    public static function decode_header_value( string $value ): string {
        if ( false === strpos( $value, '=?' ) ) {
            return trim( self::to_utf8( $value, 'UTF-8' ) );
        }
        // Whitespace between adjacent encoded words is not significant.
        $value = preg_replace( '/(\?=)\s+(=\?)/', '$1$2', $value );
        // Decode each run of adjacent encoded words together: some mailers
        // split one multi-byte character across two words, so the bytes of
        // words in the same charset are joined before conversion.
        $out = preg_replace_callback(
            '/(?:=\?[^?]+\?[BbQq]\?[^?]*\?=)+/',
            static function ( $run ) {
                preg_match_all( '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', $run[0], $words, PREG_SET_ORDER );
                $text    = '';
                $charset = null;
                $bytes   = '';
                foreach ( $words as $m ) {
                    $cs = strtoupper( (string) preg_replace( '/\*.*$/', '', $m[1] ) );
                    $decoded = 'B' === strtoupper( $m[2] )
                        ? (string) base64_decode( $m[3] )
                        : quoted_printable_decode( str_replace( '_', ' ', $m[3] ) );
                    if ( null !== $charset && $cs !== $charset ) {
                        $text .= self::to_utf8( $bytes, $charset );
                        $bytes = '';
                    }
                    $charset = $cs;
                    $bytes  .= $decoded;
                }
                if ( null !== $charset ) {
                    $text .= self::to_utf8( $bytes, $charset );
                }
                return $text;
            },
            $value
        );
        return trim( (string) $out );
    }

    /**
     * Parse an address header into [display name, email].
     *
     * @return array{0:string, 1:string}
     */
    public static function parse_address( string $value ): array {
        $value = self::decode_header_value( $value );
        if ( preg_match( '/^(.*)<([^>]+)>/s', $value, $m ) ) {
            $name = trim( $m[1], " \t\"'" );
            return [ $name, strtolower( trim( $m[2] ) ) ];
        }
        $email = trim( $value );
        if ( preg_match( '/([^\s<>"]+@[^\s<>"]+)/', $email, $m ) ) {
            return [ '', strtolower( $m[1] ) ];
        }
        return [ '', '' ];
    }

    /**
     * Convert bytes in $charset to UTF-8.
     */
    /**
     * True when most bytes above 0x7F form valid UTF-8 sequences, i.e. the
     * text is UTF-8 with some damage rather than a single-byte charset.
     */
    private static function mostly_utf8( string $bytes ): bool {
        $high = preg_match_all( '/[\x80-\xFF]/', $bytes );
        if ( ! $high ) {
            return true;
        }
        $valid = 0;
        if ( preg_match_all( '/[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3}/', $bytes, $m ) ) {
            foreach ( $m[0] as $seq ) {
                $valid += strlen( $seq );
            }
        }
        return $valid * 2 > $high;
    }

    public static function to_utf8( string $bytes, string $charset ): string {
        $charset = strtoupper( trim( $charset, " \t\"'" ) );
        if ( '' === $charset || 'UTF-8' === $charset || 'UTF8' === $charset || 'US-ASCII' === $charset ) {
            if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $bytes, 'UTF-8' ) ) {
                if ( self::mostly_utf8( $bytes ) ) {
                    // Real UTF-8 with a few stray bytes: drop those bytes only.
                    $clean = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'UTF-8//IGNORE', $bytes ) : false;
                    if ( ! is_string( $clean ) || ! mb_check_encoding( $clean, 'UTF-8' ) ) {
                        $clean = function_exists( 'mb_scrub' ) ? str_replace( '?', '', mb_scrub( str_replace( '?', "\x1A", $bytes ), 'UTF-8' ) ) : '';
                        $clean = str_replace( "\x1A", '?', $clean );
                    }
                    return $clean;
                }
                // Mislabelled legacy text: Greek mail is most often Windows-1253.
                return self::to_utf8( $bytes, 'WINDOWS-1253' );
            }
            return $bytes;
        }
        $aliases = [
            'WINDOWS-1253' => 'CP1253',
            'WIN-1253'     => 'CP1253',
            'WINDOWS-1252' => 'CP1252',
            'ISO_8859-7'   => 'ISO-8859-7',
            'ELOT_928'     => 'ISO-8859-7',
        ];
        $iconv_cs = $aliases[ $charset ] ?? $charset;
        if ( function_exists( 'iconv' ) ) {
            $out = @iconv( $iconv_cs, 'UTF-8//IGNORE', $bytes );
            if ( false !== $out && '' !== $out ) {
                return $out;
            }
        }
        if ( function_exists( 'mb_convert_encoding' ) ) {
            $mb_cs = ( 'CP1253' === $iconv_cs ) ? 'ISO-8859-7' : $iconv_cs;
            try {
                $out = @mb_convert_encoding( $bytes, 'UTF-8', $mb_cs );
                if ( is_string( $out ) ) {
                    return $out;
                }
            } catch ( \Throwable $e ) {
                // Unknown charset: fall through.
            }
        }
        return $bytes;
    }

    /**
     * Recursively walk a MIME part.
     */
    private static function walk_part( array $headers, string $body, array &$texts, array &$htmls, array &$attachments, int $depth ): void {
        [ $ctype, $cparams ] = self::parse_header_params( $headers['content-type'] ?? 'text/plain; charset=us-ascii' );
        [ $disp, $dparams ]  = self::parse_header_params( $headers['content-disposition'] ?? '' );
        $encoding            = strtolower( trim( $headers['content-transfer-encoding'] ?? '7bit' ) );

        if ( 0 === strpos( $ctype, 'multipart/' ) && ! empty( $cparams['boundary'] ) && $depth < self::MAX_DEPTH ) {
            $is_alternative = ( 'multipart/alternative' === $ctype );
            $alt_text       = [];
            $alt_html       = [];
            foreach ( self::split_multipart( $body, $cparams['boundary'] ) as $part_raw ) {
                [ $ph, $pb ] = self::split_headers_body( $part_raw );
                if ( $is_alternative ) {
                    self::walk_part( $ph, $pb, $alt_text, $alt_html, $attachments, $depth + 1 );
                } else {
                    self::walk_part( $ph, $pb, $texts, $htmls, $attachments, $depth + 1 );
                }
            }
            // For alternatives keep only the best representation.
            if ( ! empty( $alt_text ) ) {
                $texts = array_merge( $texts, $alt_text );
            } elseif ( ! empty( $alt_html ) ) {
                $htmls = array_merge( $htmls, $alt_html );
            }
            return;
        }

        if ( 'message/rfc822' === $ctype && $depth < self::MAX_DEPTH ) {
            // Forwarded message: read its body as part of this release.
            $inner = self::parse( self::decode_body( $body, $encoding ) );
            if ( '' !== $inner['text'] ) {
                $texts[] = $inner['text'];
            }
            foreach ( $inner['attachments'] as $a ) {
                $attachments[] = $a;
            }
            return;
        }

        $filename = $dparams['filename'] ?? ( $cparams['name'] ?? '' );
        $filename = self::decode_header_value( (string) $filename );
        $decoded  = self::decode_body( $body, $encoding );

        $is_attachment = ( 'attachment' === $disp ) || ( '' !== $filename && 0 !== strpos( $ctype, 'text/' ) ) || ( '' !== $filename && 'inline' !== $disp );
        if ( $is_attachment || ( 0 !== strpos( $ctype, 'text/' ) ) ) {
            if ( '' === $decoded ) {
                return;
            }
            $attachments[] = [
                'filename' => '' !== $filename ? $filename : 'attachment',
                'mime'     => $ctype,
                'data'     => $decoded,
                'size'     => strlen( $decoded ),
                'inline'   => ( 'inline' === $disp ) || ! empty( $headers['content-id'] ),
            ];
            return;
        }

        $text = self::to_utf8( $decoded, $cparams['charset'] ?? 'UTF-8' );
        if ( 'text/html' === $ctype ) {
            $htmls[] = $text;
        } else {
            $texts[] = trim( str_replace( "\r\n", "\n", $text ) );
        }
    }

    /**
     * @return string[] Raw sub-parts.
     */
    private static function split_multipart( string $body, string $boundary ): array {
        $delim = '--' . $boundary;
        // Drop the epilogue after the closing delimiter.
        if ( preg_match( '/^' . preg_quote( $delim, '/' ) . '--[ \t]*\r?$/m', $body, $m, PREG_OFFSET_CAPTURE ) ) {
            $body = substr( $body, 0, $m[0][1] );
        }
        $chunks = preg_split( '/^' . preg_quote( $delim, '/' ) . '[ \t]*\r?$/m', $body );
        if ( ! is_array( $chunks ) || count( $chunks ) < 2 ) {
            return [];
        }
        array_shift( $chunks ); // Preamble.
        $parts = [];
        foreach ( $chunks as $chunk ) {
            $chunk = preg_replace( '/^\r?\n/', '', $chunk );
            $chunk = preg_replace( '/\r?\n$/', '', (string) $chunk );
            if ( '' !== trim( (string) $chunk ) ) {
                $parts[] = (string) $chunk;
            }
        }
        return $parts;
    }

    private static function decode_body( string $body, string $encoding ): string {
        if ( 'base64' === $encoding ) {
            return (string) base64_decode( preg_replace( '/[^A-Za-z0-9+\/=]/', '', $body ) );
        }
        if ( 'quoted-printable' === $encoding ) {
            return quoted_printable_decode( str_replace( "\r\n", "\n", $body ) );
        }
        return $body;
    }

    /**
     * Convert email HTML to readable plain text.
     */
    public static function html_to_text( string $html ): string {
        $html = preg_replace( '#<(script|style|head)[^>]*>.*?</\1>#is', '', $html );
        $html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
        $html = preg_replace( '#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n\n", (string) $html );
        $html = preg_replace( '#<li[^>]*>#i', '- ', (string) $html );
        $text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = str_replace( "\xc2\xa0", ' ', $text );
        $text = preg_replace( "/[ \t]+/", ' ', $text );
        $text = preg_replace( "/ *\n */", "\n", (string) $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
        return trim( (string) $text );
    }
}
