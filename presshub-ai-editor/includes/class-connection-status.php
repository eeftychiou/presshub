<?php
/**
 * Cached connection status for PressHub → Settings → Connections (#247).
 *
 * Every connection test (AI providers, social platforms, the press inbox
 * login, the worker) records its last result here when it runs. Loading the
 * Connections section only reads this cache, so it makes no remote request;
 * a status changes only when someone clicks Test / Recheck.
 *
 * Stored in one transient: [ check id => [ ok, text, checked_at, expires_at? ] ].
 * Check ids: "provider:<id>", "social:<platform>", "press_inbox", "worker".
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Connection_Status {

    const TRANSIENT = 'presshub_connection_status';

    /** Kept for a month; each test overwrites only its own entry. */
    const TTL = 2592000;

    /** Token expiry this close (seconds) is a setup-check problem. */
    const EXPIRY_WARNING = 7 * 86400;

    /** @return array<string,array{ok:bool,text:string,checked_at:int,expires_at?:int}> */
    public static function all(): array {
        $data = function_exists( 'get_transient' ) ? get_transient( self::TRANSIENT ) : false;
        return is_array( $data ) ? $data : [];
    }

    /** The last result of one check, or null when it never ran. */
    public static function get( string $id ): ?array {
        $all = self::all();
        return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
    }

    /**
     * Records the result of a test that just ran.
     *
     * @param int $expires_at Unix time the credential expires, 0 when unknown or never.
     */
    public static function record( string $id, bool $ok, string $text, int $expires_at = 0 ): array {
        $entry = [
            'ok'         => $ok,
            'text'       => self::short( $text ),
            'checked_at' => time(),
        ];
        if ( $expires_at > 0 ) {
            $entry['expires_at'] = $expires_at;
        }
        $all        = self::all();
        $all[ $id ] = $entry;
        if ( function_exists( 'set_transient' ) ) {
            set_transient( self::TRANSIENT, $all, self::TTL );
        }
        return $entry;
    }

    /** Forgets a check (its credential was removed or replaced). */
    public static function forget( string $id ): void {
        $all = self::all();
        if ( isset( $all[ $id ] ) ) {
            unset( $all[ $id ] );
            if ( function_exists( 'set_transient' ) ) {
                set_transient( self::TRANSIENT, $all, self::TTL );
            }
        }
    }

    /**
     * Display state of an entry: 'ok', 'warning' (expires soon), 'error' or
     * 'unknown' (never checked), with the words to show next to it. The words
     * always carry the state, so colour is never the only signal.
     *
     * @return array{state:string,label:string,checked:string}
     */
    public static function describe( ?array $entry, int $now = 0 ): array {
        $now = $now ?: time();
        if ( ! $entry ) {
            return [ 'state' => 'unknown', 'label' => __( 'Not checked yet', 'presshub-ai-editor' ), 'checked' => '' ];
        }
        $checked = sprintf(
            /* translators: %s: time of day the status was checked */
            __( 'checked %s', 'presshub-ai-editor' ),
            self::time_label( (int) ( $entry['checked_at'] ?? 0 ), $now )
        );
        if ( empty( $entry['ok'] ) ) {
            return [ 'state' => 'error', 'label' => __( 'Not working', 'presshub-ai-editor' ), 'checked' => $checked ];
        }
        $expires = (int) ( $entry['expires_at'] ?? 0 );
        if ( $expires > 0 ) {
            $days = (int) floor( ( $expires - $now ) / 86400 );
            if ( $expires <= $now ) {
                return [ 'state' => 'error', 'label' => __( 'Expired', 'presshub-ai-editor' ), 'checked' => $checked ];
            }
            /* translators: %d: days until the token expires */
            $label = sprintf( _n( 'Valid, %d day left', 'Valid, %d days left', max( 0, $days ), 'presshub-ai-editor' ), max( 0, $days ) );
            return [ 'state' => ( $expires - $now ) <= self::EXPIRY_WARNING ? 'warning' : 'ok', 'label' => $label, 'checked' => $checked ];
        }
        return [ 'state' => 'ok', 'label' => __( 'Connected', 'presshub-ai-editor' ), 'checked' => $checked ];
    }

    /** "06:10" today, otherwise "16 Nov 06:10", in the site's time zone. */
    public static function time_label( int $ts, int $now = 0 ): string {
        if ( $ts <= 0 ) {
            return '—';
        }
        $now  = $now ?: time();
        $same = function_exists( 'wp_date' ) ? wp_date( 'Ymd', $ts ) === wp_date( 'Ymd', $now ) : gmdate( 'Ymd', $ts ) === gmdate( 'Ymd', $now );
        $fmt  = $same ? 'H:i' : 'j M H:i';
        return function_exists( 'wp_date' ) ? (string) wp_date( $fmt, $ts ) : gmdate( $fmt, $ts );
    }

    /** One status pill with its "checked" time, for a card or a row. */
    public static function render_pill( string $id ): string {
        $d = self::describe( self::get( $id ) );
        $out  = '<span class="presshub-conn-status" data-check="' . esc_attr( $id ) . '">';
        $out .= '<span class="presshub-conn-pill is-' . esc_attr( $d['state'] ) . '">' . esc_html( $d['label'] ) . '</span>';
        if ( '' !== $d['checked'] ) {
            $out .= ' <span class="presshub-conn-checked">' . esc_html( $d['checked'] ) . '</span>';
        }
        $out .= '</span>';
        return $out;
    }

    private static function short( string $text ): string {
        $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 240 ) : substr( $text, 0, 240 );
    }
}
