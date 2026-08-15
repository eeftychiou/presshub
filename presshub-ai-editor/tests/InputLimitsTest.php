<?php
/**
 * TDD tests for AJAX input length limits and the research scheduled_at field.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class InputLimitsTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: oversized sources are rejected ---
        self::reset();
        $_POST = [ 'sources' => str_repeat( 'a', 20001 ), 'instructions' => 'short' ];
        $msg = self::expect_json_error( function () {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->generate_draft();
        } );
        if ( false === strpos( $msg, '20,000' ) ) {
            $failures[] = "Oversized sources not rejected with 20,000 limit. Got: {$msg}";
        }

        // --- Case 2: oversized instructions are rejected ---
        self::reset();
        $_POST = [ 'sources' => 'ok', 'instructions' => str_repeat( 'b', 5001 ) ];
        $msg = self::expect_json_error( function () {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->generate_draft();
        } );
        if ( false === strpos( $msg, '5,000' ) ) {
            $failures[] = "Oversized instructions not rejected with 5,000 limit. Got: {$msg}";
        }

        // --- Case 3: oversized review content is rejected ---
        self::reset();
        $_POST = [ 'content' => str_repeat( 'c', 100001 ), 'post_id' => 0 ];
        $msg = self::expect_json_error( function () {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->run_review();
        } );
        if ( false === strpos( $msg, '100,000' ) ) {
            $failures[] = "Oversized review content not rejected with 100,000 limit. Got: {$msg}";
        }

        // --- Case 4: oversized chat prompt is rejected ---
        self::reset();
        $_POST = [ 'prompt' => str_repeat( 'd', 5001 ), 'post_id' => 0 ];
        $msg = self::expect_json_error( function () {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->handle_chat_routing();
        } );
        if ( false === strpos( $msg, '5,000' ) ) {
            $failures[] = "Oversized chat prompt not rejected with 5,000 limit. Got: {$msg}";
        }

        // --- Case 5: research response includes scheduled_at ---
        self::reset();
        $_POST = [ 'prompt' => 'deep investigation of renewable energy', 'post_id' => 0 ];
        $GLOBALS['CAPTURE_FILTER'] = function ( $prev, $ctx ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'research' ] ] ] ] ),
            ];
        };
        $data = null;
        try {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->handle_chat_routing();
        } catch ( RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'];
            $last = end( $responses );
            $data = $last ? $last['data'] : null;
        }
        if ( ! is_array( $data ) || ! isset( $data['scheduled_at'] ) || ! is_int( $data['scheduled_at'] ) ) {
            $failures[] = 'Research success response missing integer scheduled_at: ' . json_encode( $data );
        }
        if ( ! is_array( $data ) || ( $data['type'] ?? '' ) !== 'research' ) {
            $failures[] = 'Research success response missing type=research: ' . json_encode( $data );
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

    private static function reset(): void {
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['JSON_RESPONSES'] = [];
        $GLOBALS['CAPTURE_FILTER'] = null;
        $GLOBALS['OPTIONS_STORE'] = []; // rate limiter disabled by default
        $GLOBALS['NONCE_VALID'] = true;
        $_POST = [];
    }

    private static function expect_json_error( callable $fn ): string {
        try {
            $fn();
        } catch ( RuntimeException $e ) {
            return $e->getMessage();
        }
        return '(no error thrown)';
    }
}

InputLimitsTest::run();
