<?php
/**
 * TDD test for classify_intent() honoring the configured provider.
 *
 * RED phase: classify_intent() currently hardcodes a call to
 * call_gemini(), regardless of what provider the user selected in
 * settings. So if the configured provider is 'openai' or 'anthropic',
 * the classifier still hits generativelanguage.googleapis.com.
 *
 * GREEN phase expectation: classify_intent() routes through
 * call_provider() like every other public method, so the captured
 * wp_remote_post URL reflects the configured provider.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class ClassifyIntentProviderTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: provider=openai -> must hit api.openai.com, not gemini
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'openai-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'choices' => [
                            [ 'message' => [ 'content' => 'research' ] ],
                        ],
                    ] ),
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '{"unexpected":true}',
            ];
        };
        $client = new PressHub_AI_API_Client();
        $result = $client->classify_intent( 'Investigate X in depth' );

        $urls = array_map( fn( $r ) => $r[0], $GLOBALS['CAPTURED_REQUESTS'] );
        $openai_hit = false;
        $gemini_hit = false;
        foreach ( $urls as $u ) {
            if ( str_contains( $u, 'api.openai.com' ) ) $openai_hit = true;
            if ( str_contains( $u, 'generativelanguage.googleapis.com' ) ) $gemini_hit = true;
        }

        if ( ! $openai_hit ) {
            $failures[] = "provider=openai: classify_intent() should hit api.openai.com; captured URLs: " . implode( ' | ', $urls );
        }
        if ( $gemini_hit ) {
            $failures[] = "provider=openai: classify_intent() must NOT hit generativelanguage.googleapis.com; captured URLs: " . implode( ' | ', $urls );
        }
        if ( 'research' !== $result ) {
            $failures[] = "provider=openai: classify_intent() should return 'research' from the parsed OpenAI response, got: " . var_export( $result, true );
        }

        // --- Case 2: provider=anthropic -> must hit api.anthropic.com
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'anthropic-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'anthropic';
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.anthropic.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'content' => [
                            [ 'type' => 'text', 'text' => 'image' ],
                        ],
                    ] ),
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '{"unexpected":true}',
            ];
        };
        $client = new PressHub_AI_API_Client();
        $result = $client->classify_intent( 'Draw a cat' );

        $urls = array_map( fn( $r ) => $r[0], $GLOBALS['CAPTURED_REQUESTS'] );
        $anthropic_hit = false;
        $gemini_hit = false;
        foreach ( $urls as $u ) {
            if ( str_contains( $u, 'api.anthropic.com' ) ) $anthropic_hit = true;
            if ( str_contains( $u, 'generativelanguage.googleapis.com' ) ) $gemini_hit = true;
        }
        if ( ! $anthropic_hit ) {
            $failures[] = "provider=anthropic: classify_intent() should hit api.anthropic.com; captured URLs: " . implode( ' | ', $urls );
        }
        if ( $gemini_hit ) {
            $failures[] = "provider=anthropic: classify_intent() must NOT hit generativelanguage.googleapis.com; captured URLs: " . implode( ' | ', $urls );
        }
        if ( 'image' !== $result ) {
            $failures[] = "provider=anthropic: classify_intent() should return 'image' from the parsed Anthropic response, got: " . var_export( $result, true );
        }

        // --- Case 3: provider=gemini -> still works (regression guard)
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'gemini-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'gemini';
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'candidates' => [
                            [ 'content' => [ 'parts' => [ [ 'text' => 'report' ] ] ] ],
                        ],
                    ] ),
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '{"unexpected":true}',
            ];
        };
        $client = new PressHub_AI_API_Client();
        $result = $client->classify_intent( 'Translate this audio' );
        if ( 'report' !== $result ) {
            $failures[] = "provider=gemini: classify_intent() should still return 'report' from a Gemini response, got: " . var_export( $result, true );
        }

        // --- Case 4: provider=openai with API error -> falls back to 'chat'
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'openai-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                return [
                    'response' => [ 'code' => 500 ],
                    'body'     => json_encode( [ 'error' => [ 'message' => 'rate limited' ] ] ),
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '{}',
            ];
        };
        $client = new PressHub_AI_API_Client();
        $result = $client->classify_intent( 'Anything' );
        // call_openai returns WP_Error on error.body present, so classify_intent
        // should fall back to 'chat'.
        if ( 'chat' !== $result ) {
            $failures[] = "provider=openai on error: classify_intent() should fall back to 'chat', got: " . var_export( $result, true );
        }

        // --- Case 5: provider unset defaults to openai (regression for existing users)
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'openai-key';
        // provider deliberately not set
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'choices' => [
                            [ 'message' => [ 'content' => 'chat' ] ],
                        ],
                    ] ),
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };
        $client = new PressHub_AI_API_Client();
        $result = $client->classify_intent( 'hi' );
        if ( 'chat' !== $result ) {
            $failures[] = "provider unset (default openai): classify_intent() should return 'chat' from OpenAI, got: " . var_export( $result, true );
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
    }
}

ClassifyIntentProviderTest::run();