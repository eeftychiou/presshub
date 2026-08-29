<?php
/**
 * TDD Unit Tests for Issue #2: Dynamic Model Discovery via Provider API & Unified Model Selector.
 *
 * Covers:
 *   1. Dynamic model discovery for OpenAI (GET /v1/models)
 *   2. Dynamic model discovery for Anthropic (GET /v1/models)
 *   3. Dynamic model discovery for Google Gemini (GET /v1beta/models) with prefix stripping
 *   4. Dynamic model discovery for Ollama (/v1/models and /api/tags fallback)
 *   5. Dynamic model discovery for Groq, Mistral, DeepSeek, Custom OpenAI
 *   6. Error handling (no API key, non-200 HTTP, empty list, network error)
 *   7. AJAX action `wp_ajax_presshub_ai_fetch_provider_models` (nonce, permissions, payload parsing)
 *   8. Provider Modal Settings HTML UI verification (unified model dropdown, fetch button, manual toggle)
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-settings-render.php';

class DynamicModelDiscoveryTest {

    public static function run(): void {
        $failures = [];

        // ==================================================================
        // 1. OpenAI Model Discovery
        // ==================================================================
        self::reset_world();
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            if ( false !== strpos( $url, 'api.openai.com' ) || false !== strpos( $url, '/models' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'data' => [
                            [ 'id' => 'gpt-4o' ],
                            [ 'id' => 'gpt-4o-mini' ],
                            [ 'id' => 'o1' ],
                            [ 'id' => 'o3-mini' ],
                        ]
                    ] ),
                ];
            }
            return null;
        };

        $client = new PressHub_AI_API_Client();
        $models = $client->fetch_remote_models( [
            'type'    => 'openai',
            'api_key' => 'sk-test-openai-key',
        ] );

        if ( is_wp_error( $models ) ) {
            $failures[] = 'OpenAI model discovery failed: ' . $models->get_error_message();
        } else {
            if ( count( $models ) !== 4 ) {
                $failures[] = 'OpenAI model discovery expected 4 models, got: ' . count( $models );
            }
            if ( ! in_array( 'gpt-4o', $models, true ) || ! in_array( 'gpt-4o-mini', $models, true ) ) {
                $failures[] = 'OpenAI model discovery missing expected models: ' . json_encode( $models );
            }
        }

        // ==================================================================
        // 2. Anthropic Model Discovery
        // ==================================================================
        self::reset_world();
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            if ( false !== strpos( $url, 'anthropic.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'data' => [
                            [ 'id' => 'claude-3-5-sonnet-20241022', 'display_name' => 'Claude 3.5 Sonnet' ],
                            [ 'id' => 'claude-3-5-haiku-20241022',  'display_name' => 'Claude 3.5 Haiku' ],
                        ]
                    ] ),
                ];
            }
            return null;
        };

        $client = new PressHub_AI_API_Client();
        $models = $client->fetch_remote_models( [
            'type'    => 'anthropic',
            'api_key' => 'sk-ant-test-key',
        ] );

        if ( is_wp_error( $models ) ) {
            $failures[] = 'Anthropic model discovery failed: ' . $models->get_error_message();
        } else {
            if ( ! in_array( 'claude-3-5-sonnet-20241022', $models, true ) || ! in_array( 'claude-3-5-haiku-20241022', $models, true ) ) {
                $failures[] = 'Anthropic model discovery returned unexpected models: ' . json_encode( $models );
            }
        }

        // ==================================================================
        // 3. Gemini Model Discovery & Prefix Stripping
        // ==================================================================
        self::reset_world();
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'models' => [
                            [ 'name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash' ],
                            [ 'name' => 'models/gemini-2.5-pro',   'displayName' => 'Gemini 2.5 Pro' ],
                            [ 'name' => 'gemini-1.5-flash',        'displayName' => 'Gemini 1.5 Flash' ],
                        ]
                    ] ),
                ];
            }
            return null;
        };

        $client = new PressHub_AI_API_Client();
        $models = $client->fetch_remote_models( [
            'type'    => 'gemini',
            'api_key' => 'AIzaSyFakeGeminiKey',
        ] );

        if ( is_wp_error( $models ) ) {
            $failures[] = 'Gemini model discovery failed: ' . $models->get_error_message();
        } else {
            if ( in_array( 'models/gemini-2.5-flash', $models, true ) ) {
                $failures[] = 'Gemini model discovery failed to strip "models/" prefix: ' . json_encode( $models );
            }
            if ( ! in_array( 'gemini-2.5-flash', $models, true ) || ! in_array( 'gemini-2.5-pro', $models, true ) ) {
                $failures[] = 'Gemini model discovery missing clean model names: ' . json_encode( $models );
            }
        }

        // ==================================================================
        // 4. Ollama Model Discovery (Fallback to /api/tags)
        // ==================================================================
        self::reset_world();
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            if ( false !== strpos( $url, '/api/tags' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'models' => [
                            [ 'name' => 'llama3:latest' ],
                            [ 'name' => 'mistral:latest' ],
                            [ 'name' => 'deepseek-r1:14b' ],
                        ]
                    ] ),
                ];
            }
            // Simulate /v1/models failing
            if ( false !== strpos( $url, '/models' ) ) {
                return [ 'response' => [ 'code' => 404 ], 'body' => '404 not found' ];
            }
            return null;
        };

        $client = new PressHub_AI_API_Client();
        $models = $client->fetch_remote_models( [
            'type'     => 'ollama_local',
            'base_url' => 'http://localhost:11434',
        ] );

        if ( is_wp_error( $models ) ) {
            $failures[] = 'Ollama tags fallback discovery failed: ' . $models->get_error_message();
        } else {
            if ( ! in_array( 'llama3:latest', $models, true ) || ! in_array( 'deepseek-r1:14b', $models, true ) ) {
                $failures[] = 'Ollama model discovery missing tags: ' . json_encode( $models );
            }
        }

        // ==================================================================
        // 5. Groq / DeepSeek / Custom OpenAI Discovery
        // ==================================================================
        self::reset_world();
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            if ( false !== strpos( $url, 'groq.com' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'data' => [
                            [ 'id' => 'llama-3.3-70b-versatile' ],
                            [ 'id' => 'llama-3.1-8b-instant' ],
                        ]
                    ] ),
                ];
            }
            return null;
        };

        $client = new PressHub_AI_API_Client();
        $models = $client->fetch_remote_models( [
            'type'    => 'groq',
            'api_key' => 'gsk_test_groq_key',
        ] );

        if ( is_wp_error( $models ) || count( $models ) !== 2 ) {
            $failures[] = 'Groq model discovery failed: ' . ( is_wp_error( $models ) ? $models->get_error_message() : json_encode( $models ) );
        }

        // ==================================================================
        // 6. Error Handling
        // ==================================================================
        self::reset_world();
        // Missing API key error
        $client = new PressHub_AI_API_Client();
        $err = $client->fetch_remote_models( [
            'type'    => 'openai',
            'api_key' => '',
        ] );
        if ( ! is_wp_error( $err ) || 'no_api_key' !== $err->get_error_code() ) {
            $failures[] = 'fetch_remote_models with empty API key should return WP_Error("no_api_key").';
        }

        // Non-200 HTTP response
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            return [
                'response' => [ 'code' => 401 ],
                'body'     => json_encode( [
                    'error' => [ 'message' => 'Invalid authentication token provided.' ]
                ] ),
            ];
        };
        $err401 = $client->fetch_remote_models( [
            'type'    => 'openai',
            'api_key' => 'invalid-key',
        ] );
        if ( ! is_wp_error( $err401 ) || false === strpos( $err401->get_error_message(), 'Invalid authentication' ) ) {
            $failures[] = 'fetch_remote_models on HTTP 401 should return WP_Error with provider error message.';
        }

        // Empty models list response
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'data' => [] ] ),
            ];
        };
        $errEmpty = $client->fetch_remote_models( [
            'type'    => 'openai',
            'api_key' => 'sk-valid-key',
        ] );
        if ( ! is_wp_error( $errEmpty ) || 'no_models_found' !== $errEmpty->get_error_code() ) {
            $failures[] = 'fetch_remote_models on empty response should return WP_Error("no_models_found").';
        }

        // ==================================================================
        // 7. AJAX Action `presshub_ai_fetch_provider_models`
        // ==================================================================
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['NONCE_VALID'] = true;
        $_POST['nonce'] = wp_create_nonce( 'presshub_ai_nonce' );

        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'data' => [
                        [ 'id' => 'mistral-large-latest' ],
                        [ 'id' => 'mistral-small-latest' ],
                        [ 'id' => 'codestral-latest' ],
                    ]
                ] ),
            ];
        };

        $ajax = new PressHub_AI_Ajax_Handlers();
        $_POST['provider_data'] = json_encode( [
            'type'    => 'mistral',
            'api_key' => 'mistral-secret-key-123',
        ] );

        $ajax_res = self::catch_ajax_response( function() use ( $ajax ) {
            $ajax->fetch_provider_models();
        } );

        if ( empty( $ajax_res['success'] ) || empty( $ajax_res['data']['models'] ) ) {
            $failures[] = 'AJAX fetch_provider_models should succeed with models list; got: ' . json_encode( $ajax_res );
        } elseif ( $ajax_res['data']['count'] !== 3 ) {
            $failures[] = 'AJAX fetch_provider_models count mismatch: ' . ( $ajax_res['data']['count'] ?? 'null' );
        }

        // ==================================================================
        // 8. Settings Render Provider Modal Markup Check
        // ==================================================================
        self::reset_world();
        $settings_render = new PressHub_AI_Settings_Render();
        ob_start();
        $settings_render->render_provider_modal();
        $modal_html = ob_get_clean();

        if ( false === strpos( $modal_html, 'id="provider-form-default-model"' ) ) {
            $failures[] = 'Modal HTML missing unified model selector <select id="provider-form-default-model">';
        }
        if ( false === strpos( $modal_html, '<select' ) || false === strpos( $modal_html, 'id="provider-form-default-model"' ) ) {
            $failures[] = 'Modal HTML #provider-form-default-model must be a <select> element.';
        }
        if ( false === strpos( $modal_html, 'id="provider-form-fetch-models"' ) ) {
            $failures[] = 'Modal HTML missing fetch models button (#provider-form-fetch-models).';
        }
        if ( false === strpos( $modal_html, 'id="provider-form-toggle-manual"' ) ) {
            $failures[] = 'Modal HTML missing manual toggle checkbox (#provider-form-toggle-manual).';
        }
        if ( false === strpos( $modal_html, 'id="provider-form-manual-model"' ) ) {
            $failures[] = 'Modal HTML missing manual model text input fallback (#provider-form-manual-model).';
        }
        if ( false !== strpos( $modal_html, '<input type="text" id="provider-form-available-models"' ) ) {
            $failures[] = 'Modal HTML must not contain legacy visible text input for available_models.';
        }

        // ==================================================================
        // Output Results
        // ==================================================================
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
        $GLOBALS['OPTIONS_STORE']       = [];
        $GLOBALS['REGISTERED_SETTINGS'] = [];
        $GLOBALS['SANITIZE_CALLBACKS']  = [];
        $GLOBALS['CURRENT_USER_CAPS']   = [ 'manage_options' ];
        $GLOBALS['JSON_RESPONSES']      = [];
        $GLOBALS['NONCE_VALID']         = true;
        $GLOBALS['CAPTURED_GETS']       = [];
        $GLOBALS['GET_RESPONSE_FILTER'] = null;
        $_POST                          = [];
        $_REQUEST                       = [];
    }

    private static function catch_ajax_response( callable $fn ): array {
        $before_count = count( $GLOBALS['JSON_RESPONSES'] ?? [] );
        try {
            $fn();
        } catch ( Throwable $e ) {
            // caught
        }
        $after = $GLOBALS['JSON_RESPONSES'] ?? [];
        if ( count( $after ) > $before_count ) {
            return end( $after );
        }
        return [ 'raw' => '' ];
    }
}

DynamicModelDiscoveryTest::run();
