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
        $_POST = [ 'prompt' => str_repeat( 'd', 100001 ), 'post_id' => 0 ];
        $msg = self::expect_json_error( function () {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->handle_chat_routing();
        } );
        if ( false === strpos( $msg, '100,000' ) ) {
            $failures[] = "Oversized chat prompt not rejected with 100,000 limit. Got: {$msg}";
        }

        // --- Case 5: research response includes scheduled_at AND the
        //             research post is inserted as 'pending' (Low-16:
        //             no publish-then-revert dance) ---
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
        $inserted = $GLOBALS['WP_INSERTED_POSTS'] ?? [];
        $last     = end( $inserted );
        if ( ! is_array( $last ) || ( $last['post_status'] ?? '' ) !== 'pending' ) {
            $failures[] = 'Research posts should be inserted with post_status=pending; got: ' . json_encode( $last );
        }

        // --- Case 6: slashed $_POST values reach the API client unslashed ---
        // Regression for Medium-4: the five $_POST reads in the AJAX
        // handlers must wp_unslash() before sanitizing, otherwise
        // apostrophes arrive backslash-corrupted in prompts.
        self::reset();
        $_POST = [
            'sources'      => "It\\'s a source list",
            'instructions' => "Write in the paper\\'s voice",
            'nonce'        => 'valid',
        ];
        $GLOBALS['CAPTURE_FILTER'] = function ( $prev, $ctx ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'draft' ] ] ] ] ),
            ];
        };
        $h = new PressHub_AI_Ajax_Handlers();
        try {
            $h->generate_draft();
        } catch ( RuntimeException $e ) {
            // terminal wp_send_json_success — expected
        }
        $reqs = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        $body = json_decode( end( $reqs )[1]['body'] ?? '{}', true );
        $user = $body['messages'][1]['content'] ?? '';
        if ( false === strpos( $user, "It's a source list" ) ) {
            $failures[] = "sources should reach the API unslashed (It's a source list); got: {$user}";
        }
        if ( false !== strpos( $user, "It\\'s" ) ) {
            $failures[] = "sources must not retain the backslash; got: {$user}";
        }
        if ( false === strpos( $user, "paper's voice" ) ) {
            $failures[] = "instructions should reach the API unslashed (paper's voice); got: {$user}";
        }

        // --- Case 7: run_review content is unslashed before the API call ---
        self::reset();
        $_POST = [ 'content' => "It\\'s a draft with an apostrophe", 'post_id' => 0, 'nonce' => 'valid' ];
        $GLOBALS['CAPTURE_FILTER'] = function ( $prev, $ctx ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => '{"score":50,"feedback":"ok"}' ] ] ] ] ),
            ];
        };
        $h = new PressHub_AI_Ajax_Handlers();
        try {
            $h->run_review();
        } catch ( RuntimeException $e ) {
            // terminal wp_send_json_success — expected
        }
        $reqs = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        $body = json_decode( end( $reqs )[1]['body'] ?? '{}', true );
        $user = $body['messages'][1]['content'] ?? '';
        if ( false === strpos( $user, "It's a draft with an apostrophe" ) ) {
            $failures[] = "review content should reach the API unslashed; got: {$user}";
        }

        // --- Case 8: chat prompt is unslashed before the classifier call ---
        self::reset();
        $_POST = [ 'prompt' => "What\\'s the plan", 'intent' => 'chat', 'post_id' => 0, 'nonce' => 'valid' ];
        $GLOBALS['CAPTURE_FILTER'] = function ( $prev, $ctx ) {
            [ $url ] = $ctx;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'chat' ] ] ] ] ),
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };
        $h = new PressHub_AI_Ajax_Handlers();
        try {
            $h->handle_chat_routing();
        } catch ( RuntimeException $e ) {
            // terminal wp_send_json_success — expected
        }
        $reqs = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        $body = json_decode( $reqs[0][1]['body'] ?? '{}', true );
        $user = $body['contents'][0]['parts'][0]['text'] ?? $body['messages'][1]['content'] ?? '';
        if ( false === strpos( $user, "What's the plan" ) ) {
            $failures[] = "chat prompt should reach the classifier unslashed; got: {$user}";
        }

        // --- Case 9: the intent read is unslashed before the admin gate ---
        // A slashed intent ('ima\ge') must not bypass the admin-only image
        // gate. After wp_unslash() it resolves to 'image' and a non-admin
        // author is rejected with Permission denied.
        self::reset();
        $_POST = [ 'prompt' => 'make an image', 'intent' => "ima\\ge", 'post_id' => 0, 'nonce' => 'valid' ];
        $msg = self::expect_json_error( function () {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->handle_chat_routing();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "slashed intent should be unslashed before the admin gate; got: {$msg}";
        }

        // --- Case 10: test_api_connection honours the POST 'provider' param ---
        // P6 regression: the per-provider Test Connection buttons send
        // 'provider'; the handler must pass it through to test_connection().
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini'] = 'gemini-2.0-flash';
        $_POST = [ 'provider' => 'gemini', 'nonce' => 'valid' ];
        $GLOBALS['CAPTURE_FILTER'] = function ( $prev, $ctx ) {
            [ $url ] = $ctx;
            if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'ok' ] ] ] ] ] ] ),
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };
        try {
            ( new PressHub_AI_Ajax_Handlers() )->test_api_connection();
        } catch ( RuntimeException $e ) {
            // terminal wp_send_json_success — expected
        }
        $reqs = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        $last = end( $reqs );
        if ( ! $last || false === strpos( $last[0], 'generativelanguage.googleapis.com' ) ) {
            $failures[] = "test_api_connection should test the requested provider (gemini); got: " . var_export( $last[0] ?? null, true );
        } else if ( false === strpos( $last[0], '/models/gemini-2.0-flash:generateContent' ) ) {
            $failures[] = "test_api_connection should use the gemini model; got: {$last[0]}";
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
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['WP_INSERTED_POSTS'] = [];
        $GLOBALS['WP_INSERT_POST_COUNTER'] = 100;
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_api_key'  => 'k',
            'presshub_ai_provider' => 'openai',
        ]; // rate limiter disabled by default
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
