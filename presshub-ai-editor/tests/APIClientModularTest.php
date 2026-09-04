<?php
/**
 * TDD tests for Modular Service-to-Provider Routing & Custom Endpoints in PressHub_AI_API_Client.
 *
 * Covers:
 *   - resolve_module_config() for coauthor, briefing_text, briefing_podcast, copilot, tts
 *   - Fallback to first enabled provider from Provider Store
 *   - Fallback to legacy global options when provider store has no match
 *   - Instantiation of PressHub_AI_API_Client with module name, provider config array, or provider ID
 *   - set_module() and set_provider_config() runtime switching
 *   - call_custom_openai() endpoint URL construction, Bearer auth, custom headers, timeout, and response parsing
 *   - Error handling in call_custom_openai() (API errors, missing base_url, files rejection)
 *   - call_provider() routing for custom_openai and OpenAI-compatible types (groq, mistral, deepseek, ollama)
 *   - Backward compatibility for legacy no-argument calls
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-token-logger.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class APIClientModularTest
{
    public static function run(): void {
        $failures = [];

        // ==================================================================
        // 1. resolve_module_config() with explicit module settings
        // ==================================================================
        self::reset_world();
        PressHub_AI_Provider_Store::save_provider( [
            'id'               => 'custom-vllm',
            'type'             => 'custom_openai',
            'name'             => 'Local vLLM',
            'base_url'         => 'http://127.0.0.1:8000/v1',
            'api_key'          => 'sk-vllm-secret',
            'default_model'    => 'meta-llama/Meta-Llama-3-70B',
            'available_models' => [ 'meta-llama/Meta-Llama-3-70B' ],
            'timeout'          => 120,
            'temperature'      => 0.7,
            'max_tokens'       => 4096,
            'headers'          => [ 'X-Custom-Header' => 'CustomValue' ],
            'enabled'          => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_coauthor_provider']    = 'custom-vllm';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_coauthor_model']       = 'meta-llama/Meta-Llama-3-8B';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_coauthor_max_tokens']  = 2048;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_coauthor_temperature'] = 0.4;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_coauthor_timeout']     = 90;

        $coauthor_cfg = PressHub_AI_API_Client::resolve_module_config( 'coauthor' );

        if ( $coauthor_cfg['module'] !== 'coauthor' ) {
            $failures[] = "resolve_module_config('coauthor') module mismatch: " . var_export( $coauthor_cfg['module'], true );
        }
        if ( $coauthor_cfg['provider'] !== 'custom-vllm' ) {
            $failures[] = "resolve_module_config('coauthor') provider mismatch: " . var_export( $coauthor_cfg['provider'], true );
        }
        if ( $coauthor_cfg['type'] !== 'custom_openai' ) {
            $failures[] = "resolve_module_config('coauthor') type mismatch: " . var_export( $coauthor_cfg['type'], true );
        }
        if ( $coauthor_cfg['model'] !== 'meta-llama/Meta-Llama-3-8B' ) {
            $failures[] = "resolve_module_config('coauthor') model override mismatch: " . var_export( $coauthor_cfg['model'], true );
        }
        if ( $coauthor_cfg['max_tokens'] !== 2048 ) {
            $failures[] = "resolve_module_config('coauthor') max_tokens mismatch: " . var_export( $coauthor_cfg['max_tokens'], true );
        }
        if ( $coauthor_cfg['temperature'] !== 0.4 ) {
            $failures[] = "resolve_module_config('coauthor') temperature mismatch: " . var_export( $coauthor_cfg['temperature'], true );
        }
        if ( $coauthor_cfg['timeout'] !== 90 ) {
            $failures[] = "resolve_module_config('coauthor') timeout mismatch: " . var_export( $coauthor_cfg['timeout'], true );
        }
        if ( ( $coauthor_cfg['headers']['X-Custom-Header'] ?? '' ) !== 'CustomValue' ) {
            $failures[] = "resolve_module_config('coauthor') headers mismatch: " . var_export( $coauthor_cfg['headers'], true );
        }

        // ==================================================================
        // 2. resolve_module_config() for briefing_text, briefing_podcast, copilot, tts
        // ==================================================================
        self::reset_world();
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'groq-main',
            'type'          => 'groq',
            'name'          => 'Groq Cloud',
            'base_url'      => 'https://api.groq.com/openai/v1',
            'api_key'       => 'gsk-test-123',
            'default_model' => 'llama-3.3-70b-versatile',
            'enabled'       => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'tts-gemini',
            'type'          => 'gemini',
            'name'          => 'Google Gemini Speech',
            'base_url'      => 'https://generativelanguage.googleapis.com/v1beta',
            'api_key'       => 'gemini-tts-key-999',
            'default_model' => 'gemini-3.1-flash-tts-preview',
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_provider'] = 'groq-main';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_provider'] = 'groq-main';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_copilot_provider'] = 'groq-main';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_tts_provider'] = 'tts-gemini';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model'] = 'gemini-3.1-flash-tts-preview';

        $text_cfg = PressHub_AI_API_Client::resolve_module_config( 'briefing_text' );
        if ( $text_cfg['provider'] !== 'groq-main' || $text_cfg['type'] !== 'groq' ) {
            $failures[] = "briefing_text resolution failed. Got: " . var_export( $text_cfg, true );
        }

        $podcast_cfg = PressHub_AI_API_Client::resolve_module_config( 'briefing_podcast' );
        if ( $podcast_cfg['provider'] !== 'groq-main' || $podcast_cfg['model'] !== 'llama-3.3-70b-versatile' ) {
            $failures[] = "briefing_podcast resolution failed. Got: " . var_export( $podcast_cfg, true );
        }

        $copilot_cfg = PressHub_AI_API_Client::resolve_module_config( 'copilot' );
        if ( $copilot_cfg['provider'] !== 'groq-main' ) {
            $failures[] = "copilot resolution failed. Got: " . var_export( $copilot_cfg, true );
        }

        $tts_cfg = PressHub_AI_API_Client::resolve_module_config( 'tts' );
        if ( $tts_cfg['provider'] !== 'tts-gemini' || $tts_cfg['type'] !== 'gemini' || $tts_cfg['model'] !== 'gemini-3.1-flash-tts-preview' ) {
            $failures[] = "tts resolution failed. Got: " . var_export( $tts_cfg, true );
        }

        // ==================================================================
        // 3. Fallback when module options are empty: first enabled provider in store
        // ==================================================================
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [];
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'prov-disabled',
            'type'          => 'anthropic',
            'name'          => 'Disabled Anthropic',
            'api_key'       => 'sk-ant-disabled',
            'default_model' => 'claude-3-5-sonnet-20240620',
            'enabled'       => false,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'prov-first-enabled',
            'type'          => 'gemini',
            'name'          => 'First Enabled Gemini',
            'api_key'       => 'gem-key-active',
            'default_model' => 'gemini-2.0-flash',
            'enabled'       => true,
        ] );

        $fallback_cfg = PressHub_AI_API_Client::resolve_module_config( 'coauthor' );
        if ( $fallback_cfg['provider'] !== 'prov-first-enabled' || $fallback_cfg['type'] !== 'gemini' ) {
            $failures[] = "Unconfigured module should fallback to first enabled provider. Got: " . var_export( $fallback_cfg, true );
        }

        // ==================================================================
        // 4. Fallback to legacy global options when store has no enabled providers
        // ==================================================================
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']             = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']              = 'sk-legacy-openai-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_openai']         = 'gpt-4o-mini';

        $legacy_fallback = PressHub_AI_API_Client::resolve_module_config( 'coauthor' );
        if ( $legacy_fallback['type'] !== 'openai' || $legacy_fallback['api_key'] !== 'sk-legacy-openai-key' || $legacy_fallback['model'] !== 'gpt-4o-mini' ) {
            $failures[] = "Legacy fallback resolution failed. Got: " . var_export( $legacy_fallback, true );
        }

        // ==================================================================
        // 5. Instantiating client with module identifier & switching modules
        // ==================================================================
        self::reset_world();
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'custom-ollama',
            'type'          => 'ollama_local',
            'name'          => 'Ollama Local',
            'base_url'      => 'http://localhost:11434/v1',
            'default_model' => 'llama3:latest',
            'timeout'       => 300,
            'enabled'       => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'deepseek-api',
            'type'          => 'deepseek',
            'name'          => 'DeepSeek API',
            'base_url'      => 'https://api.deepseek.com/v1',
            'api_key'       => 'sk-deepseek-key',
            'default_model' => 'deepseek-chat',
            'timeout'       => 150,
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_provider'] = 'custom-ollama';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_provider']    = 'deepseek-api';

        // Constructor with module string
        $client = new PressHub_AI_API_Client( 'briefing_podcast' );
        if ( $client->get_module() !== 'briefing_podcast' ) {
            $failures[] = "Client get_module() mismatch. Got: " . var_export( $client->get_module(), true );
        }
        $cfg = $client->get_provider_config();
        if ( ( $cfg['provider'] ?? '' ) !== 'custom-ollama' || ( $cfg['type'] ?? '' ) !== 'ollama_local' ) {
            $failures[] = "Client constructor with module failed to load provider config. Got: " . var_export( $cfg, true );
        }

        // Switch module at runtime via set_module()
        $client->set_module( 'briefing_text' );
        if ( $client->get_module() !== 'briefing_text' ) {
            $failures[] = "set_module('briefing_text') failed to update module. Got: " . var_export( $client->get_module(), true );
        }
        $cfg2 = $client->get_provider_config();
        if ( ( $cfg2['provider'] ?? '' ) !== 'deepseek-api' || ( $cfg2['timeout'] ?? null ) !== 150 ) {
            $failures[] = "set_module('briefing_text') failed to switch provider config. Got: " . var_export( $cfg2, true );
        }

        // ==================================================================
        // 6. Direct provider configuration array & provider ID instantiation
        // ==================================================================
        self::reset_world();
        $direct_config = [
            'type'        => 'custom_openai',
            'name'        => 'Direct Test Provider',
            'base_url'    => 'http://direct.api/v1',
            'api_key'     => 'sk-direct-key',
            'model'       => 'direct-model-1',
            'temperature' => 0.6,
            'max_tokens'  => 8000,
            'timeout'     => 100,
            'headers'     => [ 'X-Direct' => '1' ],
        ];
        $client_direct = new PressHub_AI_API_Client( $direct_config );
        $loaded_cfg    = $client_direct->get_provider_config();
        if ( ( $loaded_cfg['model'] ?? '' ) !== 'direct-model-1' || ( $loaded_cfg['base_url'] ?? '' ) !== 'http://direct.api/v1' ) {
            $failures[] = "Client instantiation with config array failed. Got: " . var_export( $loaded_cfg, true );
        }

        // ==================================================================
        // 7. call_custom_openai() execution against mock endpoint
        // ==================================================================
        self::reset_world();
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url, $args ] = $req;
            if ( str_contains( $url, 'localhost:8000/v1/chat/completions' ) ) {
                $body = [
                    'id'      => 'chatcmpl-test',
                    'choices' => [
                        [
                            'index'   => 0,
                            'message' => [
                                'role'    => 'assistant',
                                'content' => 'Hello from Custom OpenAI Endpoint!',
                            ],
                        ],
                    ],
                ];
                return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
            }
            return [ 'response' => [ 'code' => 404 ], 'body' => '{"error":{"message":"Not Found"}}' ];
        };

        $client_custom = new PressHub_AI_API_Client();
        $provider_cfg = [
            'type'        => 'custom_openai',
            'base_url'    => 'http://localhost:8000/v1',
            'api_key'     => 'sk-test-secret',
            'model'       => 'llama-3-8b-instruct',
            'temperature' => 0.5,
            'max_tokens'  => 3000,
            'timeout'     => 65,
            'headers'     => [ 'X-Custom-Auth' => 'custom-val-123' ],
        ];

        $response = $client_custom->call_custom_openai( $provider_cfg, 'System Prompt', 'User Prompt' );

        if ( is_wp_error( $response ) ) {
            $failures[] = "call_custom_openai failed with WP_Error: " . $response->get_error_message();
        } elseif ( $response !== 'Hello from Custom OpenAI Endpoint!' ) {
            $failures[] = "call_custom_openai returned unexpected response: " . var_export( $response, true );
        }

        $last_req = self::last_request();
        if ( $last_req['url'] !== 'http://localhost:8000/v1/chat/completions' ) {
            $failures[] = "call_custom_openai URL mismatch: " . $last_req['url'];
        }
        if ( ( $last_req['args']['headers']['Authorization'] ?? '' ) !== 'Bearer sk-test-secret' ) {
            $failures[] = "call_custom_openai Authorization header missing or mismatch: " . var_export( $last_req['args']['headers'] ?? null, true );
        }
        if ( ( $last_req['args']['headers']['X-Custom-Auth'] ?? '' ) !== 'custom-val-123' ) {
            $failures[] = "call_custom_openai custom header missing: " . var_export( $last_req['args']['headers'] ?? null, true );
        }
        if ( ( $last_req['args']['timeout'] ?? null ) !== 65 ) {
            $failures[] = "call_custom_openai timeout mismatch: " . var_export( $last_req['args']['timeout'] ?? null, true );
        }
        $req_body = json_decode( $last_req['args']['body'], true );
        if ( ( $req_body['model'] ?? '' ) !== 'llama-3-8b-instruct' ) {
            $failures[] = "call_custom_openai body model mismatch: " . var_export( $req_body['model'] ?? null, true );
        }
        if ( ( $req_body['temperature'] ?? null ) !== 0.5 ) {
            $failures[] = "call_custom_openai body temperature mismatch: " . var_export( $req_body['temperature'] ?? null, true );
        }
        if ( ( $req_body['max_tokens'] ?? null ) !== 3000 ) {
            $failures[] = "call_custom_openai body max_tokens mismatch: " . var_export( $req_body['max_tokens'] ?? null, true );
        }

        // ==================================================================
        // 8. call_provider() routing for custom_openai and groq
        // ==================================================================
        self::reset_world();
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.groq.com/openai/v1/chat/completions' ) ) {
                $body = [ 'choices' => [ [ 'message' => [ 'content' => 'Response from Groq' ] ] ] ];
                return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };

        $groq_client = new PressHub_AI_API_Client( [
            'type'        => 'groq',
            'base_url'    => 'https://api.groq.com/openai/v1',
            'api_key'     => 'gsk-xyz',
            'model'       => 'llama-3.3-70b-versatile',
            'temperature' => 0.7,
            'max_tokens'  => 4000,
            'timeout'     => 300,
        ] );

        $groq_res = $groq_client->call_provider( 'Sys', 'User', false, [] );
        if ( $groq_res !== 'Response from Groq' ) {
            $failures[] = "call_provider for Groq failed. Got: " . var_export( $groq_res, true );
        }

        // ==================================================================
        // 9. Error cases for custom endpoints (missing base_url, file uploads)
        // ==================================================================
        self::reset_world();
        $empty_url_client = new PressHub_AI_API_Client();
        $err_res = $empty_url_client->call_custom_openai( [ 'base_url' => '' ], 'Sys', 'User' );
        if ( ! is_wp_error( $err_res ) || $err_res->get_error_code() !== 'invalid_base_url' ) {
            $failures[] = "call_custom_openai with empty base_url should return invalid_base_url WP_Error.";
        }

        $file_err_res = $empty_url_client->call_custom_openai( [ 'base_url' => 'http://example.com' ], 'Sys', 'User', false, [ '/tmp/test.pdf' ] );
        if ( ! is_wp_error( $file_err_res ) || $file_err_res->get_error_code() !== 'file_error' ) {
            $failures[] = "call_custom_openai with files should return file_error WP_Error.";
        }

        // ==================================================================
        // 10. Backward compatibility for legacy calls
        // ==================================================================
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']      = 'legacy-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']     = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_openai'] = 'gpt-4o';
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com/v1/chat/completions' ) ) {
                return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'Legacy OK' ] ] ] ] ) ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };

        $legacy_client = new PressHub_AI_API_Client();
        $legacy_result = $legacy_client->call_provider( 'Sys', 'User', false, [] );
        if ( $legacy_result !== 'Legacy OK' ) {
            $failures[] = "Legacy call_provider() failed. Got: " . var_export( $legacy_result, true );
        }

        // ==================================================================
        // 11. TTS and Podcast TTS model resolution cascade
        // ==================================================================
        self::reset_world();
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'tts-gemini-custom',
            'type'          => 'gemini',
            'name'          => 'Custom TTS Gemini',
            'base_url'      => 'https://generativelanguage.googleapis.com/v1beta',
            'api_key'       => 'gem-tts-key-777',
            'default_model' => 'gemini-2.5-flash-preview-tts',
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_tts_provider'] = 'tts-gemini-custom';
        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model'] );

        $tts_res = PressHub_AI_API_Client::resolve_module_config( 'tts' );
        if ( $tts_res['model'] !== 'gemini-2.5-flash-preview-tts' ) {
            $failures[] = "resolve_module_config('tts') should use provider default_model when briefing_tts_model is empty; got: " . var_export( $tts_res['model'], true );
        }

        $pod_tts_res = PressHub_AI_API_Client::resolve_module_config( 'podcast_tts' );
        if ( $pod_tts_res['model'] !== 'gemini-2.5-flash-preview-tts' ) {
            $failures[] = "resolve_module_config('podcast_tts') should use provider default_model when briefing_tts_model is empty; got: " . var_export( $pod_tts_res['model'], true );
        }

        // Issue #113: No silent model fallback for TTS in resolve_module_config
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'][0]['default_model'] = '';
        $tts_empty_res = PressHub_AI_API_Client::resolve_module_config( 'tts' );
        if ( $tts_empty_res['model'] !== '' ) {
            $failures[] = "resolve_module_config('tts') should return empty model when no model is configured; got: " . var_export( $tts_empty_res['model'], true );
        }

        // ==================================================================
        // 12. call_gemini() omits systemInstruction for TTS models & prepends prompt
        // ==================================================================
        self::reset_world();
        $captured_body = null;
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) use ( &$captured_body ) {
            [ $url, $args ] = $req;
            if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                $captured_body = json_decode( $args['body'] ?? '{}', true );
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'candidates' => [
                            [ 'content' => [ 'parts' => [ [ 'text' => 'TTS Generated Audio Text' ] ] ] ]
                        ]
                    ] )
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };

        $gemini_tts_config = [
            'type'          => 'gemini',
            'name'          => 'Gemini TTS',
            'api_key'       => 'test-gemini-tts-key',
            'model'         => 'gemini-3.1-flash-tts-preview',
            'default_model' => 'gemini-3.1-flash-tts-preview',
            'timeout'       => 60,
        ];
        $tts_client = new PressHub_AI_API_Client( $gemini_tts_config );
        $tts_out = $tts_client->call_provider( 'System Instruction Text', 'User Voice Prompt', false, [] );

        if ( $tts_out !== 'TTS Generated Audio Text' ) {
            $failures[] = "call_gemini() with TTS model failed to return text; got: " . var_export( $tts_out, true );
        }
        if ( isset( $captured_body['systemInstruction'] ) ) {
            $failures[] = "call_gemini() with TTS model must NOT include systemInstruction in request body; got: " . json_encode( $captured_body );
        }
        $user_prompt_sent = $captured_body['contents'][0]['parts'][0]['text'] ?? '';
        if ( false === strpos( $user_prompt_sent, 'System Instruction Text' ) || false === strpos( $user_prompt_sent, 'User Voice Prompt' ) ) {
            $failures[] = "call_gemini() with TTS model must prepend system instruction into user prompt; got: " . var_export( $user_prompt_sent, true );
        }

        // ==================================================================
        // 13. test_connection() for TTS provider synthesizes speech via Gemini
        // ==================================================================
        $captured_test_body = null;
        $fake_pcm = str_repeat( "\x12\x34", 1200 );
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) use ( &$captured_test_body, $fake_pcm ) {
            [ $url, $args ] = $req;
            if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                $captured_test_body = json_decode( $args['body'] ?? '{}', true );
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [
                        'candidates' => [
                            [
                                'content' => [
                                    'parts' => [
                                        [
                                            'inlineData' => [
                                                'mimeType' => 'audio/pcm;rate=24000',
                                                'data'     => base64_encode( $fake_pcm ),
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ] )
                ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };

        $test_conn_res = $tts_client->test_connection();
        if ( is_wp_error( $test_conn_res ) || false === strpos( (string) $test_conn_res, 'Speech API Connection Successful!' ) ) {
            $failures[] = "test_connection() for TTS provider failed; got: " . var_export( $test_conn_res, true );
        }
        if ( isset( $captured_test_body['systemInstruction'] ) ) {
            $failures[] = "test_connection() for TTS provider must NOT include systemInstruction; got: " . json_encode( $captured_test_body );
        }
        $modalities = $captured_test_body['generationConfig']['responseModalities'] ?? [];
        if ( ! in_array( 'AUDIO', $modalities, true ) ) {
            $failures[] = "test_connection() for TTS provider must request AUDIO modality; got: " . json_encode( $captured_test_body );
        }
        $voice_name_sent = $captured_test_body['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? '';
        if ( 'Kore' !== $voice_name_sent ) {
            $failures[] = "test_connection() for TTS provider must configure Kore voice; got: " . var_export( $voice_name_sent, true );
        }

        // ==================================================================
        // 17. Gemini TTS Token Logging (Success & Error paths)
        // ==================================================================
        global $wpdb;
        $wpdb->tables['wp_presshub_ai_token_logs'] = [];

        // 17a: Success logging
        $sample_text = 'Hello, this is a test of speech synthesis token logging.';
        $success_pcm = str_repeat( "\x00\x01", 1200 );
        $GLOBALS['CAPTURE_FILTER'] = function( $url, $args ) use ( $success_pcm ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'inlineData' => [
                                            'mimeType' => 'audio/pcm;rate=24000',
                                            'data'     => base64_encode( $success_pcm ),
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ] )
            ];
        };

        $tts_client->set_action( 'podcast_audio' );
        $synth_res = $tts_client->synthesize_speech_via_gemini( $sample_text, 'Kore', 'gemini-3.1-flash-tts-preview', 'news', false );
        if ( is_wp_error( $synth_res ) ) {
            $failures[] = "synthesize_speech_via_gemini() failed unexpectedly: " . $synth_res->get_error_message();
        }

        $logs = $wpdb->tables['wp_presshub_ai_token_logs'] ?? [];
        $last_log = end( $logs );
        if ( ! $last_log || $last_log['action_trigger'] !== 'podcast_audio' || $last_log['status'] !== 'success' || (int) $last_log['metric_units'] !== mb_strlen( $sample_text ) || $last_log['provider'] !== 'gemini' ) {
            $failures[] = "synthesize_speech_via_gemini() success was not properly logged in token logs table; got: " . json_encode( $last_log );
        }

        // 17b: Failure logging
        $GLOBALS['CAPTURE_FILTER'] = function( $url, $args ) {
            return [
                'response' => [ 'code' => 400 ],
                'body'     => json_encode( [
                    'error' => [ 'message' => 'API Key expired or invalid' ]
                ] )
            ];
        };

        $tts_client->set_action( 'custom_test' );
        $err_synth_res = $tts_client->synthesize_speech_via_gemini( $sample_text, 'Kore', 'gemini-3.1-flash-tts-preview', 'news', false );
        if ( ! is_wp_error( $err_synth_res ) ) {
            $failures[] = "synthesize_speech_via_gemini() should return WP_Error on 400 failure.";
        }

        $logs = $wpdb->tables['wp_presshub_ai_token_logs'] ?? [];
        $last_err_log = end( $logs );
        if ( ! $last_err_log || $last_err_log['action_trigger'] !== 'custom_test' || $last_err_log['status'] !== 'error' || false === strpos( (string) $last_err_log['error_message'], 'API Key expired' ) || (int) $last_err_log['metric_units'] !== mb_strlen( $sample_text ) ) {
            $failures[] = "synthesize_speech_via_gemini() failure was not properly logged in token logs table; got: " . json_encode( $last_err_log );
        }

        // ==================================================================
        // 18. Model resolution precedence & resolution source tracking
        // ==================================================================
        self::reset_world();
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'gemini-custom',
            'type'          => 'gemini',
            'name'          => 'Custom Gemini Provider',
            'base_url'      => 'https://generativelanguage.googleapis.com/v1beta',
            'api_key'       => 'gem-key-cust-999',
            'default_model' => 'gemini-2.5-pro',
            'enabled'       => true,
        ] );

        // Legacy option for type 'gemini' is set, but Provider Store record has default_model 'gemini-2.5-pro'
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini']               = 'gemini-1.5-flash';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_provider']     = 'gemini-custom';
        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_model'] );

        $resolved = PressHub_AI_API_Client::resolve_module_config( 'briefing_text' );

        // 18a: default_model from store must take precedence over legacy presshub_ai_model_{type}
        if ( ( $resolved['model'] ?? '' ) !== 'gemini-2.5-pro' ) {
            $failures[] = "Provider record default_model must take precedence over legacy presshub_ai_model_{type}; got: " . var_export( $resolved['model'] ?? null, true );
        }
        if ( ( $resolved['provider_source'] ?? '' ) !== 'module_option:presshub_ai_briefing_text_provider' ) {
            $failures[] = "provider_source mismatch; got: " . var_export( $resolved['provider_source'] ?? null, true );
        }
        if ( ( $resolved['model_source'] ?? '' ) !== 'provider_store_default:gemini-custom' ) {
            $failures[] = "model_source mismatch; got: " . var_export( $resolved['model_source'] ?? null, true );
        }

        // 18b: Instance getters (get_provider, get_provider_id, get_model, get_provider_source, get_model_source, get_request_meta)
        $client_res = new PressHub_AI_API_Client( 'briefing_text' );
        if ( $client_res->get_provider() !== 'gemini' ) {
            $failures[] = "get_provider() mismatch; got: " . var_export( $client_res->get_provider(), true );
        }
        if ( $client_res->get_provider_id() !== 'gemini-custom' ) {
            $failures[] = "get_provider_id() mismatch; got: " . var_export( $client_res->get_provider_id(), true );
        }
        if ( $client_res->get_model() !== 'gemini-2.5-pro' ) {
            $failures[] = "get_model() mismatch; got: " . var_export( $client_res->get_model(), true );
        }
        if ( $client_res->get_provider_source() !== 'module_option:presshub_ai_briefing_text_provider' ) {
            $failures[] = "get_provider_source() mismatch; got: " . var_export( $client_res->get_provider_source(), true );
        }
        if ( $client_res->get_model_source() !== 'provider_store_default:gemini-custom' ) {
            $failures[] = "get_model_source() mismatch; got: " . var_export( $client_res->get_model_source(), true );
        }

        $meta = $client_res->get_request_meta();
        if ( false === strpos( $meta, 'provider=gemini' ) || false === strpos( $meta, 'provider_id=gemini-custom' ) || false === strpos( $meta, 'model=gemini-2.5-pro' ) || false === strpos( $meta, '[source: provider_store_default:gemini-custom]' ) ) {
            $failures[] = "get_request_meta() format mismatch; got: " . var_export( $meta, true );
        }

        // 18c: Module override takes precedence over store default_model
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_model'] = 'gemini-2.5-flash';
        $client_res->set_module( 'briefing_text' );
        if ( $client_res->get_model() !== 'gemini-2.5-flash' ) {
            $failures[] = "Module model override failed to take precedence; got: " . var_export( $client_res->get_model(), true );
        }
        if ( $client_res->get_model_source() !== 'module_override:presshub_ai_briefing_text_model' ) {
            $failures[] = "Module override model_source mismatch; got: " . var_export( $client_res->get_model_source(), true );
        }
        if ( false === strpos( $client_res->get_request_meta(), '[source: module_override:presshub_ai_briefing_text_model]' ) ) {
            $failures[] = "get_request_meta() missing module_override source tag; got: " . var_export( $client_res->get_request_meta(), true );
        }

        // 18d: Specific provider ID override takes precedence over store default_model
        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_model'] );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini-custom'] = 'gemini-custom-override-model';
        $client_res->set_module( 'briefing_text' );
        if ( $client_res->get_model() !== 'gemini-custom-override-model' ) {
            $failures[] = "Specific provider ID override failed; got: " . var_export( $client_res->get_model(), true );
        }
        if ( $client_res->get_model_source() !== 'provider_id_override:presshub_ai_model_gemini-custom' ) {
            $failures[] = "Specific provider ID override model_source mismatch; got: " . var_export( $client_res->get_model_source(), true );
        }

        // 18e: Legacy fallback client source tracking
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']     = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']      = 'sk-legacy-test';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_openai'] = 'gpt-4o-mini';
        $legacy_client_2 = new PressHub_AI_API_Client();
        if ( $legacy_client_2->get_provider_source() !== 'legacy_fallback:presshub_ai_provider' ) {
            $failures[] = "Legacy client provider_source mismatch; got: " . var_export( $legacy_client_2->get_provider_source(), true );
        }
        if ( $legacy_client_2->get_model_source() !== 'legacy_type_option:presshub_ai_model_openai' ) {
            $failures[] = "Legacy client model_source mismatch; got: " . var_export( $legacy_client_2->get_model_source(), true );
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

    private static function last_request(): array {
        $requests = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        $last     = end( $requests );
        return [ 'url' => $last[0] ?? '', 'args' => $last[1] ?? [] ];
    }

    private static function reset_world(): void {
        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['CAPTURE_FILTER']    = null;
    }
}

APIClientModularTest::run();
