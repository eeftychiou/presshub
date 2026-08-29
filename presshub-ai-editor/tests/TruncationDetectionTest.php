<?php
/**
 * Unit tests for LLM output truncation detection across all providers.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-token-logger.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class TruncationDetectionTest
{
    public static function run(): void {
        $failures = [];
        global $wpdb;

        // 1. OpenAI finish_reason = 'length'
        self::reset_world();
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            $body = [
                'choices' => [
                    [
                        'message'       => [ 'content' => 'Partial response that got cut...' ],
                        'finish_reason' => 'length',
                    ],
                ],
                'usage' => [ 'prompt_tokens' => 50, 'completion_tokens' => 16384 ],
            ];
            return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
        };

        $client_openai = new PressHub_AI_API_Client( [
            'type'       => 'openai',
            'api_key'    => 'sk-test',
            'model'      => 'gpt-4o',
            'max_tokens' => 16384,
        ] );

        $result = $client_openai->call_provider( 'Sys', 'User', false, [] );

        if ( ! is_wp_error( $result ) ) {
            $failures[] = "OpenAI truncation should return WP_Error, got: " . var_export( $result, true );
        } elseif ( $result->get_error_code() !== 'output_truncated' ) {
            $failures[] = "OpenAI truncation error code should be 'output_truncated', got: " . $result->get_error_code();
        }

        $logs = $wpdb->tables['wp_presshub_ai_token_logs'] ?? [];
        $last_log = end( $logs );
        if ( ! $last_log || $last_log['status'] !== 'error' ) {
            $failures[] = "OpenAI truncation should be logged as error in token logs.";
        }
        $meta = json_decode( $last_log['metadata'] ?? '{}', true );
        if ( empty( $meta['truncated'] ) || ( $meta['finish_reason'] ?? '' ) !== 'length' ) {
            $failures[] = "OpenAI token log metadata must include truncated=true and finish_reason=length.";
        }

        // 2. Anthropic stop_reason = 'max_tokens'
        self::reset_world();
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            $body = [
                'content'     => [ [ 'type' => 'text', 'text' => 'Anthropic partial text...' ] ],
                'stop_reason' => 'max_tokens',
                'usage'       => [ 'input_tokens' => 40, 'output_tokens' => 16384 ],
            ];
            return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
        };

        $client_anthropic = new PressHub_AI_API_Client( [
            'type'       => 'anthropic',
            'api_key'    => 'sk-ant-test',
            'model'      => 'claude-3-5-sonnet-20240620',
            'max_tokens' => 16384,
        ] );

        $ant_result = $client_anthropic->call_provider( 'Sys', 'User', false, [] );

        if ( ! is_wp_error( $ant_result ) ) {
            $failures[] = "Anthropic truncation should return WP_Error, got: " . var_export( $ant_result, true );
        } elseif ( $ant_result->get_error_code( ) !== 'output_truncated' ) {
            $failures[] = "Anthropic truncation error code should be 'output_truncated', got: " . $ant_result->get_error_code();
        }

        $logs = $wpdb->tables['wp_presshub_ai_token_logs'] ?? [];
        $last_log = end( $logs );
        if ( ! $last_log || $last_log['status'] !== 'error' ) {
            $failures[] = "Anthropic truncation should be logged as error in token logs.";
        }

        // 3. Google Gemini finishReason = 'MAX_TOKENS'
        self::reset_world();
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            $body = [
                'candidates' => [
                    [
                        'content'     => [ 'parts' => [ [ 'text' => 'Gemini truncated text...' ] ] ],
                        'finishReason' => 'MAX_TOKENS',
                    ],
                ],
                'usageMetadata' => [ 'promptTokenCount' => 60, 'candidatesTokenCount' => 16384 ],
            ];
            return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
        };

        $client_gemini = new PressHub_AI_API_Client( [
            'type'       => 'gemini',
            'api_key'    => 'AIzaSyTest',
            'model'      => 'gemini-2.5-flash',
            'max_tokens' => 16384,
        ] );

        $gem_result = $client_gemini->call_provider( 'Sys', 'User', false, [] );

        if ( ! is_wp_error( $gem_result ) ) {
            $failures[] = "Gemini truncation should return WP_Error, got: " . var_export( $gem_result, true );
        } elseif ( $gem_result->get_error_code() !== 'output_truncated' ) {
            $failures[] = "Gemini truncation error code should be 'output_truncated', got: " . $gem_result->get_error_code();
        }

        // 4. Custom OpenAI (Groq/DeepSeek/Local) finish_reason = 'length'
        self::reset_world();
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            $body = [
                'choices' => [
                    [
                        'message'       => [ 'content' => 'DeepSeek truncated...' ],
                        'finish_reason' => 'length',
                    ],
                ],
                'usage' => [ 'prompt_tokens' => 100, 'completion_tokens' => 8192 ],
            ];
            return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
        };

        $client_deepseek = new PressHub_AI_API_Client( [
            'type'       => 'deepseek',
            'base_url'    => 'https://api.deepseek.com/v1',
            'api_key'    => 'sk-ds-test',
            'model'      => 'deepseek-chat',
            'max_tokens' => 8192,
        ] );

        $ds_result = $client_deepseek->call_provider( 'Sys', 'User', false, [] );

        if ( ! is_wp_error( $ds_result ) ) {
            $failures[] = "DeepSeek truncation should return WP_Error, got: " . var_export( $ds_result, true );
        } elseif ( $ds_result->get_error_code() !== 'output_truncated' ) {
            $failures[] = "DeepSeek truncation error code should be 'output_truncated', got: " . $ds_result->get_error_code();
        }

        // 5. Scorecard propagates output_truncated error directly
        $scorecard_res = $client_openai->generate_scorecard( 'Article content' );
        if ( ! is_wp_error( $scorecard_res ) || $scorecard_res->get_error_code() !== 'output_truncated' ) {
            $failures[] = "generate_scorecard should propagate output_truncated WP_Error when truncated, got: " . ( is_wp_error( $scorecard_res ) ? $scorecard_res->get_error_code() : json_encode( $scorecard_res ) );
        }

        // 6. Non-truncated response succeeds normally
        self::reset_world();
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            $body = [
                'choices' => [
                    [
                        'message'       => [ 'content' => 'Full complete response.' ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [ 'prompt_tokens' => 50, 'completion_tokens' => 120 ],
            ];
            return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
        };

        $normal_res = $client_openai->call_provider( 'Sys', 'User', false, [] );
        if ( $normal_res !== 'Full complete response.' ) {
            $failures[] = "Normal non-truncated response should succeed, got: " . var_export( $normal_res, true );
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL
" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}
" );
            }
            exit( 1 );
        }
        echo "OK
";
    }

    private static function reset_world(): void {
        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['CAPTURE_FILTER']    = null;
        global $wpdb;
        $wpdb->tables['wp_presshub_ai_token_logs'] = [];
    }
}

TruncationDetectionTest::run();
