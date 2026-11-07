<?php
/**
 * PressHub_AI_Secret_Masker — single source of truth for masking secrets in
 * logs and the audit trail.
 *
 * UI display formatters (PressHub_AI_Provider_Store::mask_key(),
 * PressHub_Social_Logger::mask_secret()) are separate and unchanged.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Secret_Masker {

    /**
     * Known API key prefixes. Longer, more specific prefixes come first.
     */
    const KNOWN_PREFIXES = [
        'sk-proj-',
        'sk-admin-',
        'sk-ant-api03-',
        'sk-ant-',
        'github_pat_',
        'ghp_',
        'gsk_',
        'AIzaSy',
        'AIza',
        'nvapi-',
        'xai-',
        'ms-',
        'sk-',
    ];

    /**
     * Mask an explicit secret value.
     */
    public static function mask_value( string $val ): string {
        $val = trim( $val );
        if ( '' === $val ) {
            return '';
        }
        if ( false !== strpos( $val, '••••' ) ) {
            return $val;
        }

        if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
            $masked = PressHub_AI_Provider_Store::mask_key( $val );
            if ( '' !== $masked && 'No API Key set' !== $masked ) {
                return $masked;
            }
        }

        $len = strlen( $val );
        if ( $len >= 10 ) {
            return substr( $val, 0, 4 ) . '••••••••' . substr( $val, -4 );
        } elseif ( $len >= 6 ) {
            return substr( $val, 0, 2 ) . '••••' . substr( $val, -2 );
        }
        return '••••••••';
    }

    /**
     * Mask value only if it matches known API key formats or a Bearer token.
     */
    public static function mask_if_secret( string $val ): string {
        $trimmed = trim( $val );
        if ( '' === $trimmed ) {
            return $val;
        }

        foreach ( self::KNOWN_PREFIXES as $pfx ) {
            if ( 0 === strpos( $trimmed, $pfx ) && strlen( $trimmed ) > strlen( $pfx ) + 4 ) {
                return self::mask_value( $trimmed );
            }
        }

        if ( 0 === stripos( $trimmed, 'Bearer ' ) && strlen( $trimmed ) > 12 ) {
            return 'Bearer ' . self::mask_value( substr( $trimmed, 7 ) );
        }

        return $val;
    }

    /**
     * Recursively mask sensitive fields and credentials.
     *
     * @param mixed $data Input array or scalar.
     * @return mixed
     */
    public static function mask_array( $data ) {
        if ( is_array( $data ) ) {
            $masked = [];
            foreach ( $data as $key => $val ) {
                $k_str = (string) $key;
                if ( is_array( $val ) ) {
                    $masked[ $key ] = self::mask_array( $val );
                } elseif ( is_string( $val ) ) {
                    if ( self::is_sensitive_key( $k_str ) ) {
                        $masked[ $key ] = self::mask_value( $val );
                    } else {
                        $masked[ $key ] = self::mask_if_secret( $val );
                    }
                } else {
                    $masked[ $key ] = $val;
                }
            }
            return $masked;
        } elseif ( is_string( $data ) ) {
            return self::mask_if_secret( $data );
        }

        return $data;
    }

    /**
     * Mask every known-prefix key and Bearer token embedded in free text.
     */
    /**
     * Field-name segments that mark a value as a credential. Matched against
     * whole segments of the key name (split on _ - . space and camelCase), so
     * 'api_key', 'X-Goog-Api-Key', 'apiKey', 'auth_token' and 'client_secret'
     * match while 'author', 'bypass', 'keyword' or 'max_tokens' do not.
     */
    const SENSITIVE_SEGMENTS = [
        'key', 'apikey', 'secret', 'token', 'password', 'passwd', 'pass', 'pwd',
        'auth', 'authorization', 'bearer', 'credential', 'credentials', 'cookie', 'signature',
    ];

    /** Full key names that contain a sensitive segment but never hold a credential. */
    const NON_SECRET_KEYS = [ 'cache_key', 'meta_key', 'option_key', 'story_key', 'idempotency_key' ];

    /**
     * Whether a field name denotes a credential (see SENSITIVE_SEGMENTS).
     *
     * @param string $key Field name.
     * @return bool
     */
    public static function is_sensitive_key( string $key ): bool {
        $normalized = strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key ) );
        if ( in_array( $normalized, self::NON_SECRET_KEYS, true ) ) {
            return false;
        }
        $segments = preg_split( '/[_\-.\s]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $segments ) && [] !== array_intersect( $segments, self::SENSITIVE_SEGMENTS );
    }

    public static function mask_text( string $text ): string {
        if ( '' === $text ) {
            return $text;
        }

        $alternation = implode( '|', array_map( static function ( $p ) {
            return preg_quote( $p, '/' );
        }, self::KNOWN_PREFIXES ) );

        $pattern = '/(?<![A-Za-z0-9_\-])(?:(?:' . $alternation . ')[A-Za-z0-9_\-]{5,}+|Bearer\s++([A-Za-z0-9._~+\/=\-]{6,}+)(?!•))/';

        $result = preg_replace_callback(
            $pattern,
            static function ( $m ) {
                if ( isset( $m[1] ) && '' !== $m[1] ) {
                    return 'Bearer ' . self::mask_value( $m[1] );
                }
                return self::mask_value( $m[0] );
            },
            $text
        );

        return is_string( $result ) ? $result : $text;
    }
}
