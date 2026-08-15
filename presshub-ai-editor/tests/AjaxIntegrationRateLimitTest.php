<?php
/**
 * TDD test: AJAX endpoints (draft, review, chat, research) must respect
 * the per-user rate limiter when it is enabled, and must pass through
 * untouched when it is disabled.
 *
 * RED phase (before integration): generate_draft / run_review /
 * handle_chat_routing do NOT consult the rate limiter, so a user can
 * hammer the API indefinitely. The "enabled+over limit" case below will
 * fail because the handler will not block.
 *
 * GREEN phase expectation: every AI-costly entry point calls
 * PressHub_AI_Rate_Limiter::check() before reaching the API client; on
 * block, wp_send_json_error fires with the limiter's message. When the
 * limiter is disabled (default), existing behaviour is preserved — this
 * is what keeps every prior test green.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class AjaxIntegrationRateLimitTest
{
    public static function run(): void {
        $failures = [];

        // ----- DISABLED: passes through unchanged -----------------------------
        // generate_draft: enabled=0, the handler must reach wp_send_json_success.
        $failures = array_merge( $failures, self::drive_generate_draft(
            enabled: false,
            user_id: 1,
            expect_blocked: false,
            label: 'draft_disabled'
        ) );

        // run_review: enabled=0, handler must reach wp_send_json_success.
        $failures = array_merge( $failures, self::drive_run_review(
            enabled: false,
            user_id: 1,
            expect_blocked: false,
            label: 'review_disabled'
        ) );

        // chat (author, chat intent): enabled=0, handler must reach success.
        $failures = array_merge( $failures, self::drive_chat(
            enabled: false,
            caps: [ 'edit_posts' ],
            intent_hint: 'chat',
            classifier_intent: 'chat',
            user_id: 1,
            expect_blocked: false,
            label: 'chat_disabled'
        ) );

        // research (author): enabled=0, handler must reach success.
        $failures = array_merge( $failures, self::drive_chat(
            enabled: false,
            caps: [ 'edit_posts' ],
            intent_hint: 'research',
            classifier_intent: 'research',
            user_id: 1,
            expect_blocked: false,
            label: 'research_disabled'
        ) );

        // ----- ENABLED + UNDER LIMIT: passes ---------------------------------
        $failures = array_merge( $failures, self::drive_generate_draft(
            enabled: true,
            user_id: 2,
            expect_blocked: false,
            label: 'draft_under_limit'
        ) );

        $failures = array_merge( $failures, self::drive_run_review(
            enabled: true,
            user_id: 2,
            expect_blocked: false,
            label: 'review_under_limit'
        ) );

        $failures = array_merge( $failures, self::drive_chat(
            enabled: true,
            caps: [ 'edit_posts' ],
            intent_hint: 'chat',
            classifier_intent: 'chat',
            user_id: 2,
            expect_blocked: false,
            label: 'chat_under_limit'
        ) );

        $failures = array_merge( $failures, self::drive_chat(
            enabled: true,
            caps: [ 'edit_posts' ],
            intent_hint: 'research',
            classifier_intent: 'research',
            user_id: 2,
            expect_blocked: false,
            label: 'research_under_limit'
        ) );

        // ----- ENABLED + OVER LIMIT: blocked ---------------------------------
        $failures = array_merge( $failures, self::drive_generate_draft(
            enabled: true,
            user_id: 3,
            expect_blocked: true,
            label: 'draft_over_limit'
        ) );

        $failures = array_merge( $failures, self::drive_run_review(
            enabled: true,
            user_id: 3,
            expect_blocked: true,
            label: 'review_over_limit'
        ) );

        $failures = array_merge( $failures, self::drive_chat(
            enabled: true,
            caps: [ 'edit_posts' ],
            intent_hint: 'chat',
            classifier_intent: 'chat',
            user_id: 3,
            expect_blocked: true,
            label: 'chat_over_limit'
        ) );

        $failures = array_merge( $failures, self::drive_chat(
            enabled: true,
            caps: [ 'edit_posts' ],
            intent_hint: 'research',
            classifier_intent: 'research',
            user_id: 3,
            expect_blocked: true,
            label: 'research_over_limit'
        ) );

        // ----- IMAGE/REPORT already admin-gated; rate limiter must NOT fire
        //       for image/report even when over-limit and user lacks admin —
        //       the existing admin gate must still be the first line.
        $failures = array_merge( $failures, self::drive_chat(
            enabled: true,
            caps: [ 'edit_posts' ],
            intent_hint: 'image',
            classifier_intent: 'image',
            user_id: 4,
            expect_blocked: true,
            expect_message_contains: 'Permission denied',
            label: 'image_admin_gate_takes_priority'
        ) );

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
     * Drive generate_draft() and assert whether the rate limiter blocks.
     */
    private static function drive_generate_draft( bool $enabled, int $user_id, bool $expect_blocked, string $label ): array {
        self::reset_world();
        $GLOBALS['CURRENT_USER_ID'] = $user_id;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        if ( $enabled ) {
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_per_hour'] = 3;
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_window_seconds'] = 3600;
            if ( $expect_blocked ) {
                // Saturate the counter so the next check() blocks.
                $key = 'presshub_ai_rl_' . $user_id;
                $GLOBALS['TRANSIENT_STORE'][ $key ] = [
                    'value' => [ 'count' => 3, 'expires_at' => time() + 3600, 'window' => 3600 ],
                    'expires_at' => time() + 3600,
                ];
            }
        }

        // The capture filter makes generate_draft return a fake draft via
        // the api client — but it must NOT be reached when blocked.
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'choices' => [ [ 'message' => [ 'content' => 'draft text' ] ] ],
                    ] ),
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };
        $_POST = [
            'sources'      => '',
            'instructions' => 'write something',
            'nonce'        => 'valid',
        ];

        $handler = new PressHub_AI_Ajax_Handlers();
        $thrown = null;
        try {
            $handler->generate_draft();
        } catch ( Throwable $e ) {
            $thrown = $e;
        }

        return self::assert_outcome(
            $expect_blocked,
            $thrown,
            'Rate limit',
            $label,
            'wp_send_json_success'
        );
    }

    /**
     * Drive run_review() and assert whether the rate limiter blocks.
     */
    private static function drive_run_review( bool $enabled, int $user_id, bool $expect_blocked, string $label ): array {
        self::reset_world();
        $GLOBALS['CURRENT_USER_ID'] = $user_id;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        if ( $enabled ) {
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_per_hour'] = 3;
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_window_seconds'] = 3600;
            if ( $expect_blocked ) {
                $key = 'presshub_ai_rl_' . $user_id;
                $GLOBALS['TRANSIENT_STORE'][ $key ] = [
                    'value' => [ 'count' => 3, 'expires_at' => time() + 3600, 'window' => 3600 ],
                    'expires_at' => time() + 3600,
                ];
            }
        }
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'choices' => [ [ 'message' => [
                            'content' => json_encode( [ 'score' => 90, 'notes' => 'good' ] ),
                        ] ] ],
                    ] ),
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };
        $_POST = [
            'content' => '<p>hello</p>',
            'post_id' => '0',
            'nonce'   => 'valid',
        ];

        $handler = new PressHub_AI_Ajax_Handlers();
        $thrown = null;
        try {
            $handler->run_review();
        } catch ( Throwable $e ) {
            $thrown = $e;
        }
        return self::assert_outcome(
            $expect_blocked,
            $thrown,
            'Rate limit',
            $label,
            'wp_send_json_success'
        );
    }

    /**
     * Drive handle_chat_routing() and assert whether the rate limiter
     * blocks. For image/report intents with a non-admin user we expect
     * the admin gate to fire first (Permission denied).
     */
    private static function drive_chat(
        bool $enabled,
        array $caps,
        string $intent_hint,
        string $classifier_intent,
        int $user_id,
        bool $expect_blocked,
        string $label,
        string $expect_message_contains = ''
    ): array {
        self::reset_world();
        $GLOBALS['CURRENT_USER_ID'] = $user_id;
        $GLOBALS['CURRENT_USER_CAPS'] = $caps;
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'gc';
        if ( $enabled ) {
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_per_hour'] = 3;
            $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_window_seconds'] = 3600;
            if ( $expect_blocked ) {
                $key = 'presshub_ai_rl_' . $user_id;
                $GLOBALS['TRANSIENT_STORE'][ $key ] = [
                    'value' => [ 'count' => 3, 'expires_at' => time() + 3600, 'window' => 3600 ],
                    'expires_at' => time() + 3600,
                ];
            }
        }

        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) use ( $classifier_intent ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'choices' => [ [ 'message' => [ 'content' => $classifier_intent ] ] ],
                    ] ),
                ];
            }
            if ( str_contains( $url, 'aiplatform.googleapis.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'predictions' => [ [ 'bytesBase64Encoded' => base64_encode( 'IMG' ) ] ],
                    ] ),
                ];
            }
            if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'A radio report script.' ] ] ] ] ],
                    ] ),
                ];
            }
            if ( str_contains( $url, 'texttospeech.googleapis.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [ 'audioContent' => base64_encode( 'MP3' ) ] ),
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };

        $_POST = [
            'prompt'  => 'do the thing',
            'post_id' => '0',
            'intent'  => $intent_hint,
            'nonce'   => 'valid',
        ];

        $handler = new PressHub_AI_Ajax_Handlers();
        $thrown = null;
        try {
            $handler->handle_chat_routing();
        } catch ( Throwable $e ) {
            $thrown = $e;
        }

        // Default expectation: either rate-limit message or success.
        return self::assert_outcome(
            $expect_blocked,
            $thrown,
            $expect_message_contains ?: 'Rate limit',
            $label,
            'wp_send_json_success'
        );
    }

    /**
     * Shared assertion helper: compares actual thrown/captured outcome
     * against the expectation.
     */
    private static function assert_outcome(
        bool $expect_blocked,
        ?Throwable $thrown,
        string $expect_msg_contains,
        string $label,
        string $success_marker
    ): array {
        $failures = [];
        if ( $expect_blocked ) {
            if ( null === $thrown ) {
                $failures[] = "{$label}: handler should have thrown (rate-limited), but completed normally";
            } elseif ( ! str_contains( $thrown->getMessage(), $expect_msg_contains ) ) {
                $failures[] = "{$label}: handler should reject with '{$expect_msg_contains}', got: " . $thrown->getMessage();
            } else {
                $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
                $last      = end( $responses );
                if ( ! is_array( $last ) || ( $last['success'] ?? null ) !== false ) {
                    $failures[] = "{$label}: expected wp_send_json_error to be recorded, got: " . var_export( $last, true );
                }
            }
        } else {
            if ( null === $thrown ) {
                $failures[] = "{$label}: handler should reach {$success_marker} when limiter is permissive";
            } elseif ( ! str_contains( $thrown->getMessage(), $success_marker ) ) {
                $failures[] = "{$label}: handler should reach {$success_marker} but threw: " . $thrown->getMessage();
            }
        }
        return $failures;
    }

    private static function reset_world(): void {
        $GLOBALS['POST_META_STORE'] = [];
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['TRANSIENT_STORE'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $GLOBALS['CURRENT_USER_ID'] = 0;
        $GLOBALS['POST_STATUSES'] = [];
        $GLOBALS['HOOK_INVOCATION_COUNT'] = 0;
        $GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
        $GLOBALS['HOOK_INVOCATION_LOG'] = [];
        $GLOBALS['DO_ACTION_LOG'] = [];
        $GLOBALS['TRANSITION_HANDLERS'] = [];
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['CAPTURE_FILTER'] = null;
        $GLOBALS['JSON_RESPONSES'] = [];
        $GLOBALS['WP_INSERTED_POSTS'] = [];
        $GLOBALS['WP_INSERT_POST_COUNTER'] = 100;
        $GLOBALS['SCHEDULED_EVENTS'] = [];
        $GLOBALS['NONCE_VALID'] = false;
        $GLOBALS['TIME_NOW'] = null;
        $_POST = [];
    }
}

AjaxIntegrationRateLimitTest::run();