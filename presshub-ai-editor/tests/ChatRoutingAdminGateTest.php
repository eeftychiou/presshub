<?php
/**
 * TDD test for handle_chat_routing() gating image/report intents.
 *
 * RED phase: handle_chat_routing() currently only checks `edit_posts`,
 * so any author (who can edit_posts) can trigger paid Imagen and TTS
 * generation via the chat router. Google Cloud costs can rack up
 * without an admin's involvement.
 *
 * GREEN phase expectation: the handler must reject `image` and `report`
 * intents for non-admin users (anyone without `manage_options`) with a
 * permission-denied response, while leaving `chat` and `research`
 * intents available to authors.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class ChatRoutingAdminGateTest
{
    public static function run(): void {
        $failures = [];

        foreach ( [
            'image_author'  => [ 'edit_posts', 'image',  'permission_denied' ],
            'report_author' => [ 'edit_posts', 'report', 'permission_denied' ],
            'image_admin'   => [ [ 'edit_posts', 'manage_options' ], 'image',  'allowed' ],
            'report_admin'  => [ [ 'edit_posts', 'manage_options' ], 'report', 'allowed' ],
            'chat_author'   => [ 'edit_posts', 'chat',  'allowed' ],
            'research_author' => [ 'edit_posts', 'research', 'allowed' ],
            'chat_admin'    => [ [ 'edit_posts', 'manage_options' ], 'chat',  'allowed' ],
        ] as $key => [ $caps, $intent, $expectation ] ) {
            self::reset_world();
            $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'openai-key';
            $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
            // Stub Google Cloud key so image / report paths don't bail.
            $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'gc-key';
            $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) use ( $intent ) {
                [ $url ] = $req;
                if ( str_contains( $url, 'api.openai.com' ) ) {
                    return [
                        'response' => [ 'code' => 200 ],
                        'body'     => json_encode( [
                            'choices' => [
                                [ 'message' => [ 'content' => $intent ] ],
                            ],
                        ] ),
                    ];
                }
                // Imagen: return a valid predictions payload with bytesBase64Encoded.
                if ( str_contains( $url, 'aiplatform.googleapis.com' ) ) {
                    return [
                        'response' => [ 'code' => 200 ],
                        'body'     => json_encode( [
                            'predictions' => [
                                [ 'bytesBase64Encoded' => base64_encode( 'IMG' ) ],
                            ],
                        ] ),
                    ];
                }
                // Gemini (used by generate_audio_report's script synthesis):
                // return a valid Gemini candidates payload.
                if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                    return [
                        'response' => [ 'code' => 200 ],
                        'body'     => json_encode( [
                            'candidates' => [
                                [ 'content' => [ 'parts' => [ [ 'text' => 'A short radio report script.' ] ] ] ],
                            ],
                        ] ),
                    ];
                }
                // Cloud TTS: return valid audioContent.
                if ( str_contains( $url, 'texttospeech.googleapis.com' ) ) {
                    return [
                        'response' => [ 'code' => 200 ],
                        'body'     => json_encode( [ 'audioContent' => base64_encode( 'MP3BYTES' ) ] ),
                    ];
                }
                return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
            };
            $GLOBALS['NONCE_VALID'] = true;

            $GLOBALS['CURRENT_USER_CAPS'] = is_array( $caps ) ? $caps : [ $caps ];
            $_POST = [
                'prompt'  => 'some test prompt',
                'post_id' => '0',
                'nonce'   => 'valid',
            ];

            $handler = new PressHub_AI_Ajax_Handlers();
            $thrown = null;
            try {
                $handler->handle_chat_routing();
            } catch ( Throwable $e ) {
                $thrown = $e;
            }

            if ( 'permission_denied' === $expectation ) {
                if ( null === $thrown ) {
                    $failures[] = "{$key}: handler should have thrown (permission denied) but completed normally";
                } elseif ( ! str_contains( $thrown->getMessage(), 'Permission denied' ) ) {
                    $failures[] = "{$key}: handler should reject with 'Permission denied', got: " . $thrown->getMessage();
                } else {
                    // Inspect the captured JSON response.
                    $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
                    $last      = end( $responses );
                    if ( ! $last || true !== ( $last['success'] ?? null ) ) {
                        // wp_send_json_error was called — verify it recorded the right message.
                    }
                }
            } else { // 'allowed'
                if ( null === $thrown ) {
                    $failures[] = "{$key}: handler should have completed successfully (wp_send_json_success), but threw nothing and didn't send a response";
                } elseif ( ! str_contains( $thrown->getMessage(), 'wp_send_json_success' ) ) {
                    $failures[] = "{$key}: handler should reach wp_send_json_success for intent={$intent}, but threw: " . $thrown->getMessage();
                }
            }
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
        $GLOBALS['POST_META_STORE'] = [];
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [];
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
        $_POST = [];
    }
}

ChatRoutingAdminGateTest::run();