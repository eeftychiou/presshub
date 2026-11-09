<?php
/**
 * Gemini TTS rate limiting and 429 retry hints (Issue #153).
 *
 * Gemini rate limits TTS per model (Tier 1: 10 requests per minute for
 * gemini-3.8-flash-tts). Podcast synthesis sends one request per dialogue
 * turn, so an unpaced run sits right on the limit. This class:
 *
 *  - paces requests with a per-model sliding 60 s window
 *    (`presshub_ai_briefing_tts_requests_per_minute`, 0 = unlimited). The
 *    window lives in a transient so the TikTok narrator, the Settings test
 *    button and a podcast run in another request share it;
 *  - reads the server's retry hint from a 429 body (google.rpc.RetryInfo
 *    `retryDelay`, then the Retry-After header, then "Please retry in Ns."
 *    in the message);
 *  - tells per-day quota errors (never worth waiting for) apart from
 *    per-minute ones.
 *
 * Sleeping and the clock are injectable so tests never sleep.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_TTS_Rate_Limiter {

    /** Transient prefix for the per-model request window. */
    const TRANSIENT_PREFIX = 'presshub_ai_tts_rpm_';

    /** Window length in seconds. */
    const WINDOW_SECONDS = 60;

    /** Safety margin added to computed waits (ms). */
    const MARGIN_MS = 250;

    /** @var array<string, float[]> In-process copy of each model's window. */
    private static $windows = [];

    /** @var callable|null fn( int $ms ): void */
    private static $sleeper = null;

    /** @var callable|null fn(): float (seconds) */
    private static $clock = null;

    // ---------------------------------------------------------------------
    // Test seams
    // ---------------------------------------------------------------------

    /** @param callable|null $sleeper fn( int $ms ): void; null restores the default. */
    public static function set_sleeper( ?callable $sleeper ): void {
        self::$sleeper = $sleeper;
    }

    /** @param callable|null $clock fn(): float seconds; null restores microtime(). */
    public static function set_clock( ?callable $clock ): void {
        self::$clock = $clock;
    }

    /** Forget every window (in-process and transient). */
    public static function reset( string $model = '' ): void {
        $models = '' !== $model ? [ $model ] : array_keys( self::$windows );
        foreach ( $models as $m ) {
            unset( self::$windows[ $m ] );
            if ( function_exists( 'delete_transient' ) ) {
                delete_transient( self::transient_key( $m ) );
            }
        }
    }

    // ---------------------------------------------------------------------
    // Pacing
    // ---------------------------------------------------------------------

    /**
     * Wait until another request to $model fits in the per-minute budget,
     * then count it. Returns the milliseconds spent waiting.
     */
    public static function acquire( string $model ): int {
        $rpm = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_requests_per_minute() : 0;
        if ( $rpm <= 0 || '' === $model ) {
            return 0;
        }

        $waited = 0;
        $window = self::load( $model );
        for ( $i = 0; $i < 3; $i++ ) {
            $now    = self::now();
            $window = self::prune( $window, $now );
            if ( count( $window ) < $rpm ) {
                break;
            }
            $oldest  = (float) $window[ count( $window ) - $rpm ];
            $wait_ms = (int) ceil( ( $oldest + self::WINDOW_SECONDS - $now ) * 1000 ) + self::MARGIN_MS;
            if ( $wait_ms <= 0 ) {
                break;
            }
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::debug( 'TTS pacing: waiting before next request', [
                    'model'               => $model,
                    'requests_per_minute' => $rpm,
                    'in_window'           => count( $window ),
                    'wait_ms'             => $wait_ms,
                ] );
            }
            self::sleep_ms( $wait_ms );
            $waited += $wait_ms;
            // Another request may have used the budget meanwhile.
            $window = self::load( $model );
        }

        $window[] = self::now();
        self::save( $model, self::prune( $window, self::now() ) );
        return $waited;
    }

    // ---------------------------------------------------------------------
    // 429 parsing
    // ---------------------------------------------------------------------

    /**
     * Retry delay the server asked for, or null when it gave none.
     *
     * @param array  $body        Decoded error body.
     * @param string $retry_after Retry-After header value ('' when absent).
     * @return array{ms:int,source:string}|null
     */
    public static function parse_retry_delay( array $body, string $retry_after = '' ): ?array {
        $details = $body['error']['details'] ?? [];
        if ( is_array( $details ) ) {
            foreach ( $details as $detail ) {
                if ( ! is_array( $detail ) ) {
                    continue;
                }
                $type = (string) ( $detail['@type'] ?? '' );
                if ( false !== strpos( $type, 'RetryInfo' ) && isset( $detail['retryDelay'] ) ) {
                    $ms = self::duration_to_ms( $detail['retryDelay'] );
                    if ( null !== $ms ) {
                        return [ 'ms' => $ms, 'source' => 'retry_info' ];
                    }
                }
            }
        }

        $retry_after = trim( $retry_after );
        if ( '' !== $retry_after && is_numeric( $retry_after ) ) {
            return [ 'ms' => (int) round( (float) $retry_after * 1000 ), 'source' => 'retry_after' ];
        }

        $message = (string) ( $body['error']['message'] ?? '' );
        if ( preg_match( '/retry in\s+([0-9]+(?:\.[0-9]+)?)\s*s/i', $message, $m ) ) {
            return [ 'ms' => (int) round( (float) $m[1] * 1000 ), 'source' => 'message' ];
        }

        return null;
    }

    /**
     * True when a 429 is for a per-day quota, which no short wait will fix.
     */
    public static function is_daily_quota( array $body ): bool {
        $details = $body['error']['details'] ?? [];
        if ( is_array( $details ) ) {
            foreach ( $details as $detail ) {
                foreach ( (array) ( $detail['violations'] ?? [] ) as $violation ) {
                    $id     = (string) ( $violation['quotaId'] ?? '' );
                    $metric = (string) ( $violation['quotaMetric'] ?? '' );
                    if ( self::names_daily( $id ) || self::names_daily( $metric ) ) {
                        return true;
                    }
                }
            }
        }
        $message = (string) ( $body['error']['message'] ?? '' );
        return (bool) preg_match( '/per[\s_-]?day|PerDay/i', $message );
    }

    // ---------------------------------------------------------------------
    // Sleeping
    // ---------------------------------------------------------------------

    /**
     * Sleep $ms milliseconds. Uses the injected sleeper when set; never sleeps
     * under the unit-test HTTP mocks.
     */
    public static function sleep_ms( int $ms ): void {
        if ( $ms <= 0 ) {
            return;
        }
        if ( null !== self::$sleeper ) {
            call_user_func( self::$sleeper, $ms );
            return;
        }
        if ( self::is_mocked_env() ) {
            return;
        }
        usleep( $ms * 1000 );
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private static function names_daily( string $name ): bool {
        return '' !== $name && (bool) preg_match( '/per[\s_-]?day|PerDay/i', $name );
    }

    /** Parse a google.protobuf.Duration string ("43s", "43.49s") to ms. */
    private static function duration_to_ms( $value ): ?int {
        if ( is_numeric( $value ) ) {
            return (int) round( (float) $value * 1000 );
        }
        if ( is_string( $value ) && preg_match( '/^\s*([0-9]+(?:\.[0-9]+)?)\s*s\s*$/i', $value, $m ) ) {
            return (int) round( (float) $m[1] * 1000 );
        }
        return null;
    }

    private static function now(): float {
        return null !== self::$clock ? (float) call_user_func( self::$clock ) : microtime( true );
    }

    /** @param float[] $window */
    private static function prune( array $window, float $now ): array {
        $cutoff = $now - self::WINDOW_SECONDS;
        $kept   = array_values( array_filter( $window, static function ( $t ) use ( $cutoff ) {
            return is_numeric( $t ) && (float) $t > $cutoff;
        } ) );
        sort( $kept );
        return $kept;
    }

    /** @return float[] */
    private static function load( string $model ): array {
        if ( function_exists( 'get_transient' ) ) {
            $stored = get_transient( self::transient_key( $model ) );
            if ( is_array( $stored ) ) {
                self::$windows[ $model ] = $stored;
            }
        }
        return self::$windows[ $model ] ?? [];
    }

    /** @param float[] $window */
    private static function save( string $model, array $window ): void {
        self::$windows[ $model ] = $window;
        if ( function_exists( 'set_transient' ) ) {
            set_transient( self::transient_key( $model ), $window, 2 * self::WINDOW_SECONDS );
        }
    }

    private static function transient_key( string $model ): string {
        return self::TRANSIENT_PREFIX . md5( $model );
    }

    private static function is_mocked_env(): bool {
        return ! empty( $GLOBALS['HTTP_MOCK'] )
            || ! empty( $GLOBALS['WP_REMOTE_POST_CALLBACK'] )
            || ! empty( $GLOBALS['MOCK_HTTP_RESPONSES'] )
            || ! empty( $GLOBALS['CAPTURE_FILTER'] )
            || defined( 'PRESSHUB_AI_UNIT_TEST' );
    }
}
