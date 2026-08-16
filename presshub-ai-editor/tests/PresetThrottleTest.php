<?php
/**
 * Regression test (Medium-5 from the 2026-08-16 review synthesis):
 * the preset-CRUD throttle must be live even when the global
 * 'presshub_ai_rate_limit_enabled' option is NOT set.
 *
 * The preset handlers force-enable their dedicated limiter
 * (PressHub_AI_Rate_Limiter( true )) and use a dedicated bucket with a
 * 60/min window, so preset mutations never consume the AI-call budget
 * and are throttled regardless of the global AI-limit opt-in.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class PresetThrottleTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: option unset; a saturated preset bucket still blocks ---
        self::reset();
        // Deliberately do NOT set presshub_ai_rate_limit_enabled.
        $GLOBALS['TIME_NOW'] = 5_000_000;
        $key = 'presshub_ai_preset_7';
        $limiter = new PressHub_AI_Rate_Limiter( true );
        for ( $i = 0; $i < 60; $i++ ) {
            $limiter->record( $key, 60, 60 );
        }
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, 'Rate limit' ) ) {
            $failures[] = "save_preset should be throttled with the global option unset; got: {$msg}";
        }
        if ( isset( $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ) ) {
            $failures[] = 'save_preset throttle: mutation must not happen when blocked.';
        }

        // --- Case 2: a successful mutation records against the bucket ---
        self::reset();
        $GLOBALS['TIME_NOW'] = 5_100_000;
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy', 'name' => 'P', 'instruction_text' => 'T', 'nonce' => 'valid' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( ! is_array( $data ) || ( $data['slug'] ?? '' ) !== 'punchy' ) {
            $failures[] = 'save_preset should succeed when under the throttle; got: ' . var_export( $data, true );
        }
        $stored = $GLOBALS['TRANSIENT_STORE']['presshub_ai_preset_7']['value'] ?? null;
        if ( ( $stored['count'] ?? null ) !== 1 ) {
            $failures[] = 'Successful save_preset should record 1 against the throttle bucket; got: ' . var_export( $stored, true );
        }
        if ( ( $stored['expires_at'] ?? null ) !== 5_100_060 ) {
            $failures[] = 'Preset throttle window should be 60s (expires_at = now+60); got: ' . var_export( $stored, true );
        }

        // --- Case 3: the 61st mutation in a window is blocked end-to-end ---
        self::reset();
        $GLOBALS['TIME_NOW'] = 5_200_000;
        $key = 'presshub_ai_preset_7';
        $limiter = new PressHub_AI_Rate_Limiter( true );
        for ( $i = 0; $i < 60; $i++ ) {
            $limiter->record( $key, 60, 60 );
        }
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy', 'name' => 'P', 'instruction_text' => 'T', 'nonce' => 'valid' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, 'Rate limit' ) ) {
            $failures[] = "61st preset mutation in the window should be blocked; got: {$msg}";
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

    private static function expect_json_error( callable $fn ): string {
        try {
            $fn();
        } catch ( RuntimeException $e ) {
            return $e->getMessage();
        }
        return '(no error thrown)';
    }

    private static function drive_success( callable $fn ) {
        try {
            $fn();
        } catch ( RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
            $last      = end( $responses );
            if ( is_array( $last ) && ( $last['success'] ?? null ) === true ) {
                return $last['data'];
            }
            throw $e;
        }
        return null;
    }

    private static function reset(): void {
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['JSON_RESPONSES'] = [];
        $GLOBALS['OPTIONS_STORE'] = []; // global AI limit option unset
        $GLOBALS['TRANSIENT_STORE'] = [];
        $GLOBALS['USER_META_STORE'] = [];
        $GLOBALS['NONCE_VALID'] = true;
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy', 'name' => 'P', 'instruction_text' => 'T', 'nonce' => 'valid' ];
    }
}

PresetThrottleTest::run();
