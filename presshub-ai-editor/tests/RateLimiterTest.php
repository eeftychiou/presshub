<?php
/**
 * TDD test for PressHub_AI_Rate_Limiter (Group 4 — cost controls).
 *
 * RED phase (before implementation): the require_once below will fatal
 * because class-rate-limiter.php does not exist. That is the red.
 *
 * GREEN phase expectation:
 *   - PressHub_AI_Rate_Limiter::check( $key, $limit, $window_seconds )
 *     returns true when the feature is disabled (no rate limit configured)
 *     or when the user is under the limit, and a WP_Error with a human
 *     message including the remaining wait time when over the limit.
 *   - PressHub_AI_Rate_Limiter::record( $key ) increments the counter
 *     and resets the window expiry.
 *   - The counter survives across calls within the window but is cleared
 *     by the underlying transient once the window expires (controllable
 *     via TIME_NOW).
 *   - When the global 'presshub_ai_rate_limit_enabled' option is not set
 *     or falsy, check() is a pass-through (returns true).
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';

class RateLimiterTest
{
    public static function run(): void {
        $failures = [];

        // Case 1: class exists with the expected public API.
        self::reset_world();
        if ( ! class_exists( 'PressHub_AI_Rate_Limiter' ) ) {
            $failures[] = "PressHub_AI_Rate_Limiter class should exist.";
        } else {
            foreach ( [ 'check', 'record' ] as $method ) {
                if ( ! method_exists( 'PressHub_AI_Rate_Limiter', $method ) ) {
                    $failures[] = "PressHub_AI_Rate_Limiter should expose a {$method}() method.";
                }
            }
        }

        // Case 2: disabled-by-default — check() always returns true and
        // record() is a no-op. This is the safety property that keeps the
        // existing behaviour and existing tests unaffected.
        self::reset_world();
        // Deliberately DO NOT set presshub_ai_rate_limit_enabled.
        $rl = new PressHub_AI_Rate_Limiter();
        for ( $i = 0; $i < 100; $i++ ) {
            $result = $rl->check( 'presshub_ai_rl_42', 5, 3600 );
            if ( true !== $result ) {
                $failures[] = "Disabled rate limiter should return true on every check(), got " . var_export( $result, true );
                break;
            }
        }
        // record() must not throw and must not create transient state when disabled.
        try {
            $rl->record( 'presshub_ai_rl_42' );
        } catch ( Throwable $e ) {
            $failures[] = "Disabled rate limiter should swallow record() without throwing: " . $e->getMessage();
        }
        if ( ! empty( $GLOBALS['TRANSIENT_STORE'] ) ) {
            $failures[] = "Disabled rate limiter must not write to the transient store.";
        }

        // Case 3: enabled + explicitly disabled option value (0 / '0' / '')
        // — both must count as disabled.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 0;
        $rl = new PressHub_AI_Rate_Limiter();
        if ( true !== $rl->check( 'presshub_ai_rl_1', 3, 60 ) ) {
            $failures[] = "Enabled=0 must disable the limiter.";
        }
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = '0';
        $rl = new PressHub_AI_Rate_Limiter();
        if ( true !== $rl->check( 'presshub_ai_rl_1', 3, 60 ) ) {
            $failures[] = "Enabled='0' must disable the limiter.";
        }

        // Case 4: enabled + under the limit — check() returns true and
        // does NOT increment. record() bumps the counter.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_per_hour'] = 3;
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['TIME_NOW'] = 1_000_000;

        $rl = new PressHub_AI_Rate_Limiter();
        $key = 'presshub_ai_rl_7';
        if ( true !== $rl->check( $key, 3, 3600 ) ) {
            $failures[] = "First check should pass (0/3 used).";
        }
        $rl->record( $key );
        if ( true !== $rl->check( $key, 3, 3600 ) ) {
            $failures[] = "Second check should pass (1/3 used).";
        }
        $rl->record( $key );
        if ( true !== $rl->check( $key, 3, 3600 ) ) {
            $failures[] = "Third check should pass (2/3 used).";
        }
        $rl->record( $key );
        // Now at 3/3 — next check should be blocked.
        $blocked = $rl->check( $key, 3, 3600 );
        if ( ! ( $blocked instanceof WP_Error ) ) {
            $failures[] = "Check over limit must return WP_Error, got " . var_export( $blocked, true );
        } else {
            if ( ! str_contains( $blocked->get_error_message(), 'limit' ) && ! str_contains( strtolower( $blocked->get_error_message() ), 'rate' ) ) {
                $failures[] = "WP_Error message should mention the rate limit, got: " . $blocked->get_error_message();
            }
        }

        // Case 5: over the limit AND record() afterwards must NOT further
        // inflate the counter (so the user is not punished extra by retries).
        // Counter should remain at 3 and window must not be re-extended.
        $stored = $GLOBALS['TRANSIENT_STORE'][ $key ] ?? null;
        if ( ! is_array( $stored ) || ( $stored['value']['count'] ?? null ) !== 3 ) {
            $failures[] = "Counter should stay at 3 after a blocked check, got " . var_export( $stored, true );
        }
        $expires_before = $stored['value']['expires_at'] ?? 0;
        $rl->record( $key ); // Should be a no-op when already at limit.
        $stored_after = $GLOBALS['TRANSIENT_STORE'][ $key ] ?? null;
        if ( $stored_after['value']['count'] !== 3 || $stored_after['value']['expires_at'] !== $expires_before ) {
            $failures[] = "record() over the limit must not mutate state; before=" . var_export( $stored, true ) . " after=" . var_export( $stored_after, true );
        }

        // Case 6: window expiry — once TIME_NOW passes expires_at, the
        // counter resets and check() passes again.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_per_hour'] = 2;
        // Sync the limiter's internal window default with what we pass
        // into check() below — the limiter records the window it used on
        // first record() and reuses it on subsequent records, so both
        // sides must agree on the window length.
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_window_seconds'] = 60;
        $GLOBALS['CURRENT_USER_ID'] = 9;
        $GLOBALS['TIME_NOW'] = 2_000_000;

        $rl = new PressHub_AI_Rate_Limiter();
        $key = 'presshub_ai_rl_9';
        $rl->record( $key );
        $rl->record( $key );
        $blocked = $rl->check( $key, 2, 60 );
        if ( ! ( $blocked instanceof WP_Error ) ) {
            $failures[] = "At 2/2 should be blocked before window expiry.";
        }
        // Advance the clock past the window.
        $GLOBALS['TIME_NOW'] = 2_000_000 + 61;
        if ( true !== $rl->check( $key, 2, 60 ) ) {
            $failures[] = "After window expiry check() should pass again (counter resets).";
        }
        $fresh = $GLOBALS['TRANSIENT_STORE'][ $key ] ?? null;
        if ( is_array( $fresh ) ) {
            $fresh_count = $fresh['value']['count'] ?? null;
            if ( 0 !== $fresh_count ) {
                $failures[] = "Counter should be reset to 0 after window expiry, got " . var_export( $fresh, true );
            }
        }
        // Whether the limiter deleted the expired transient on read or
        // kept a count=0 entry is an implementation detail — both are
        // correct as long as the post-expiry behaviour is "no counter".

        // Case 7: blocked WP_Error includes a human-readable remaining time.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_per_hour'] = 1;
        $GLOBALS['CURRENT_USER_ID'] = 11;
        $GLOBALS['TIME_NOW'] = 3_000_000;
        $rl = new PressHub_AI_Rate_Limiter();
        $key = 'presshub_ai_rl_11';
        $rl->record( $key ); // 1/1 used
        $blocked = $rl->check( $key, 1, 60 );
        if ( ! ( $blocked instanceof WP_Error ) ) {
            $failures[] = "Should be blocked at 1/1.";
        } else {
            $msg = $blocked->get_error_message();
            if ( ! preg_match( '/\d+/', $msg ) ) {
                $failures[] = "Blocked message should include a remaining-time number, got: {$msg}";
            }
        }

        // Case 8: constructor arg forces the limiter ON even when the
        // global option is unset (the preset-CRUD throttle path).
        self::reset_world();
        $rl = new PressHub_AI_Rate_Limiter( true );
        $key = 'presshub_ai_preset_7';
        if ( true !== $rl->check( $key, 2, 60 ) ) {
            $failures[] = "Forced-on limiter should enforce limits without the option being set.";
        }
        $rl->record( $key, 2, 60 );
        $rl->record( $key, 2, 60 );
        $blocked = $rl->check( $key, 2, 60 );
        if ( ! ( $blocked instanceof WP_Error ) ) {
            $failures[] = "Forced-on limiter should block at the explicit limit (2/2).";
        }

        // Case 9: constructor arg forces the limiter OFF even when the
        // option is enabled.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
        $rl = new PressHub_AI_Rate_Limiter( false );
        for ( $i = 0; $i < 5; $i++ ) {
            if ( true !== $rl->check( 'presshub_ai_rl_1', 1, 60 ) ) {
                $failures[] = "Forced-off limiter must pass every check() despite the option being enabled.";
                break;
            }
        }

        // Case 10: record() honours explicit limit/window args; without
        // args it falls back to the configured options.
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_per_hour'] = 3;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_window_seconds'] = 3600;
        $GLOBALS['TIME_NOW'] = 4_000_000;
        $rl = new PressHub_AI_Rate_Limiter();
        $key = 'presshub_ai_preset_5';
        $rl->record( $key, 60, 60 );
        $stored = $GLOBALS['TRANSIENT_STORE'][ $key ]['value'] ?? null;
        if ( ( $stored['count'] ?? null ) !== 1 ) {
            $failures[] = "record() with explicit args should record count 1; got " . var_export( $stored, true );
        }
        if ( ( $stored['expires_at'] ?? null ) !== 4_000_060 ) {
            $failures[] = "record() with explicit window should anchor expiry at now+60; got " . var_export( $stored, true );
        }
        // Defaults: 3 records against the configured 3/hour, then blocked.
        $key2 = 'presshub_ai_rl_5';
        $rl->record( $key2 );
        $rl->record( $key2 );
        $rl->record( $key2 );
        if ( ! ( $rl->check( $key2, 3, 3600 ) instanceof WP_Error ) ) {
            $failures[] = "record() without args should use the configured limit (3).";
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    private static function reset_world(): void {
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['TRANSIENT_STORE'] = [];
        $GLOBALS['CURRENT_USER_ID'] = 0;
        $GLOBALS['TIME_NOW'] = null;
    }
}

RateLimiterTest::run();