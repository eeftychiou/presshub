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

    /** Options whose change makes a check's last result stale => check ids. */
    const WATCHED = [
        'presshub_ai_press_inbox_host'         => [ 'press_inbox' ],
        'presshub_ai_press_inbox_port'         => [ 'press_inbox' ],
        'presshub_ai_press_inbox_encryption'   => [ 'press_inbox' ],
        'presshub_ai_press_inbox_verify_ssl'   => [ 'press_inbox' ],
        'presshub_ai_press_inbox_username'     => [ 'press_inbox' ],
        'presshub_ai_press_inbox_password'     => [ 'press_inbox' ],
        'presshub_social_meta_page_id'         => [ 'social:facebook' ],
        'presshub_social_meta_access_token'    => [ 'social:facebook', 'social:instagram' ],
        'presshub_social_instagram_account_id' => [ 'social:instagram' ],
        'presshub_social_twitter_bearer_token' => [ 'social:twitter' ],
        'presshub_social_twitter_access_token' => [ 'social:twitter' ],
        'presshub_social_tiktok_access_token'  => [ 'social:tiktok' ],
        'presshub_social_tiktok_open_id'       => [ 'social:tiktok' ],
        'presshub_social_worker_secret'        => [ 'worker' ],
        'presshub_social_worker_url'           => [ 'worker' ],
        'presshub_social_worker_mode'          => [ 'worker' ],
        'presshub_social_engine_twitter'       => [ 'social:twitter' ],
        'presshub_social_engine_facebook'      => [ 'social:facebook' ],
        'presshub_social_engine_instagram'     => [ 'social:instagram' ],
        'presshub_social_engine_tiktok'        => [ 'social:tiktok' ],
    ];

    /** Worker settings: platforms on the automation engine are tested through the worker too. */
    const WORKER_OPTIONS = [ 'presshub_social_worker_secret', 'presshub_social_worker_url', 'presshub_social_worker_mode' ];

    /**
     * Forget a check whenever one of its settings changes or is deleted
     * (a removed password, a new host…), so its pill reads "Not checked
     * yet" instead of an old "Connected".
     */
    public static function register_hooks(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        foreach ( array_keys( self::WATCHED ) as $option ) {
            $forget = static function () use ( $option ) {
                self::forget_for_option( $option );
            };
            // WordPress fires update_option_* only on a real change; compare anyway.
            $changed = static function ( $old = null, $new = null ) use ( $option ) {
                if ( $old != $new ) { // phpcs:ignore Universal.Operators.StrictComparisons -- "1" and 1 are the same setting.
                    self::forget_for_option( $option );
                }
            };
            add_action( 'update_option_' . $option, $changed, 10, 2 );
            add_action( 'add_option_' . $option, $forget, 10, 0 );
            add_action( 'delete_option_' . $option, $forget, 10, 0 );
        }
    }

    public static function forget_for_option( string $option ): void {
        foreach ( self::WATCHED[ $option ] ?? [] as $id ) {
            self::forget( $id );
        }
        if ( in_array( $option, self::WORKER_OPTIONS, true ) && class_exists( 'PressHub_Social_Settings_Storage' ) ) {
            foreach ( PressHub_Social_Settings_Storage::PLATFORMS as $p ) {
                if ( 'automation' === PressHub_Social_Settings_Storage::get_platform_engine( $p ) ) {
                    self::forget( 'social:' . $p );
                }
            }
        }
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
