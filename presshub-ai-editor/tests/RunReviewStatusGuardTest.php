<?php
/**
 * Regression test (High-1 from the 2026-08-16 review synthesis):
 * run_review() must never demote an already-published post to 'pending'.
 *
 * Expected behaviour after the fix:
 *   - published post + score >= 80 -> scorecard meta stored, NO
 *     wp_update_post call (status untouched).
 *   - draft post + score >= 80 -> wp_update_post called with 'pending'.
 *   - published post + score < 80 -> no wp_update_post call either.
 *   - missing post (get_post returns null) -> no crash, no wp_update_post.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class RunReviewStatusGuardTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: published post + score >= 80 -> meta stored, status untouched ---
        self::reset();
        $GLOBALS['POSTS_STORE'][42]  = [ 'ID' => 42, 'post_status' => 'publish', 'post_type' => 'post' ];
        $GLOBALS['POST_STATUSES'][42] = 'publish';
        $_POST = [ 'content' => 'draft copy', 'post_id' => 42, 'nonce' => 'valid' ];
        self::drive_review( '{"score":95,"feedback":"great"}' );
        if ( ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) !== 0 ) {
            $failures[] = 'run_review must NOT call wp_update_post for a published post; calls=' . ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 );
        }
        $meta = $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard'] ?? null;
        if ( ! is_array( $meta ) || ( $meta['score'] ?? null ) !== 95 ) {
            $failures[] = 'scorecard meta should still be stored for the published post; got: ' . var_export( $meta, true );
        }
        $hash = $GLOBALS['POST_META_STORE'][42]['_presshub_ai_scorecard_hash'] ?? null;
        if ( $hash !== md5( 'draft copy' ) ) {
            $failures[] = 'scorecard hash should be stored for post 42; got: ' . var_export( $hash, true );
        }
        if ( ( $GLOBALS['POST_STATUSES'][42] ?? null ) !== 'publish' ) {
            $failures[] = 'published post status must remain publish; got: ' . var_export( $GLOBALS['POST_STATUSES'][42] ?? null, true );
        }
        // ME-8 / F-29: the response must report the post status after review.
        $last = $GLOBALS['JSON_RESPONSES'][0]['data'] ?? null;
        if ( ( $last['status_after'] ?? 'missing' ) !== 'publish' ) {
            $failures[] = 'status_after should be "publish" for a published post; got: ' . var_export( $last['status_after'] ?? null, true );
        }

        // --- Case 2: draft post + score >= 80 -> wp_update_post('pending') ---
        self::reset();
        $GLOBALS['POSTS_STORE'][43]  = [ 'ID' => 43, 'post_status' => 'draft', 'post_type' => 'post' ];
        $GLOBALS['POST_STATUSES'][43] = 'draft';
        $_POST = [ 'content' => 'draft copy', 'post_id' => 43, 'nonce' => 'valid' ];
        self::drive_review( '{"score":90,"feedback":"good"}' );
        if ( ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) !== 1 ) {
            $failures[] = 'run_review should transition a draft to pending; calls=' . ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 );
        }
        $log = $GLOBALS['HOOK_INVOCATION_LOG'] ?? [];
        if ( empty( $log ) || ( $log[0]['post_id'] ?? 0 ) !== 43 || ( $log[0]['new'] ?? '' ) !== 'pending' ) {
            $failures[] = 'wp_update_post should target post 43 with pending; log=' . json_encode( $log );
        }
        if ( ( $GLOBALS['POST_STATUSES'][43] ?? null ) !== 'pending' ) {
            $failures[] = 'post 43 should now be pending; got: ' . var_export( $GLOBALS['POST_STATUSES'][43] ?? null, true );
        }
        $last = $GLOBALS['JSON_RESPONSES'][0]['data'] ?? null;
        if ( ( $last['status_after'] ?? 'missing' ) !== 'pending' ) {
            $failures[] = 'status_after should be "pending" after a draft -> pending transition; got: ' . var_export( $last['status_after'] ?? null, true );
        }

        // --- Case 3: published post + score < 80 -> no wp_update_post ---
        self::reset();
        $GLOBALS['POSTS_STORE'][44]  = [ 'ID' => 44, 'post_status' => 'publish', 'post_type' => 'post' ];
        $GLOBALS['POST_STATUSES'][44] = 'publish';
        $_POST = [ 'content' => 'draft copy', 'post_id' => 44, 'nonce' => 'valid' ];
        self::drive_review( '{"score":55,"feedback":"needs work"}' );
        if ( ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) !== 0 ) {
            $failures[] = 'run_review must not touch the post when score < 80; calls=' . ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 );
        }
        $last = $GLOBALS['JSON_RESPONSES'][0]['data'] ?? null;
        if ( ( $last['status_after'] ?? 'missing' ) !== 'publish' ) {
            $failures[] = 'status_after should stay "publish" when score < 80; got: ' . var_export( $last['status_after'] ?? null, true );
        }

        // --- Case 4: missing post (get_post null) -> no crash, no update ---
        self::reset();
        $_POST = [ 'content' => 'draft copy', 'post_id' => 999, 'nonce' => 'valid' ];
        self::drive_review( '{"score":88,"feedback":"ok"}' );
        if ( ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) !== 0 ) {
            $failures[] = 'run_review must not wp_update_post for a missing post; calls=' . ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 );
        }
        $last = $GLOBALS['JSON_RESPONSES'][0]['data'] ?? null;
        if ( ! array_key_exists( 'status_after', $last ?? [] ) || $last['status_after'] !== null ) {
            $failures[] = 'status_after should be null when no post exists; got: ' . var_export( $last['status_after'] ?? 'missing key', true );
        }

        // --- Case 5: no post_id -> status_after stays null ---
        self::reset();
        $_POST = [ 'content' => 'draft copy', 'nonce' => 'valid' ];
        self::drive_review( '{"score":81,"feedback":"ok"}' );
        $last = $GLOBALS['JSON_RESPONSES'][0]['data'] ?? null;
        if ( ! is_array( $last ) || ! array_key_exists( 'status_after', $last ) || $last['status_after'] !== null ) {
            $failures[] = 'status_after should be null when no post_id is sent; got: ' . var_export( $last, true );
        }

        // --- Case 6: dynamic threshold (qa_min_score = 90) -> score 85 does not transition draft to pending ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_qa_min_score'] = 90;
        $GLOBALS['POSTS_STORE'][45]  = [ 'ID' => 45, 'post_status' => 'draft', 'post_type' => 'post' ];
        $GLOBALS['POST_STATUSES'][45] = 'draft';
        $_POST = [ 'content' => 'draft copy', 'post_id' => 45, 'nonce' => 'valid' ];
        self::drive_review( '{"score":85,"feedback":"good but under 90"}' );
        if ( ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) !== 0 ) {
            $failures[] = 'run_review must respect dynamic min score (90); score 85 should not transition to pending';
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

    /**
     * Drive run_review() with a scorecard payload and swallow the terminal
     * wp_send_json_success exception. Any other exception (including a
     * wp_send_json_error) propagates and fails the test loudly.
     */
    private static function drive_review( string $scorecard_json ): void {
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) use ( $scorecard_json ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'choices' => [ [ 'message' => [ 'content' => $scorecard_json ] ] ],
                    ] ),
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };
        try {
            ( new PressHub_AI_Ajax_Handlers() )->run_review();
        } catch ( RuntimeException $e ) {
            if ( false === strpos( $e->getMessage(), 'wp_send_json_success' ) ) {
                throw $e;
            }
        }
    }

    private static function reset(): void {
        $GLOBALS['CURRENT_USER_CAPS']  = [ 'edit_posts', 'edit_post' ];
        $GLOBALS['CURRENT_USER_ID']    = 7;
        $GLOBALS['JSON_RESPONSES']     = [];
        $GLOBALS['CAPTURE_FILTER']     = null;
        $GLOBALS['CAPTURED_REQUESTS']  = [];
        $GLOBALS['OPTIONS_STORE']      = [
            'presshub_ai_api_key'    => 'k',
            'presshub_ai_provider'   => 'openai',
        ];
        $GLOBALS['NONCE_VALID']        = true;
        $GLOBALS['POSTS_STORE']        = [];
        $GLOBALS['POST_STATUSES']      = [];
        $GLOBALS['POST_META_STORE']    = [];
        $GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
        $GLOBALS['HOOK_INVOCATION_LOG']  = [];
        $GLOBALS['DO_ACTION_LOG']        = [];
        $GLOBALS['TRANSITION_HANDLERS']  = [];
        $GLOBALS['HOOK_INVOCATION_COUNT'] = 0;
        $_POST = [];
    }
}

RunReviewStatusGuardTest::run();
