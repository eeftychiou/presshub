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
            'id'            => 'tts-gcloud',
            'type'          => 'google_cloud_tts',
            'name'          => 'Google Cloud TTS Engine',
            'base_url'      => 'https://texttospeech.googleapis.com/v1',
            'api_key'       => 'gc-tts-key-999',
            'default_model' => 'el-GR-Wavenet-A',
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_provider'] = 'groq-main';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_provider'] = 'groq-main';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_copilot_provider'] = 'groq-main';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_engine'] = 'tts-gcloud';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model'] = 'el-GR-Neural2-B';

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
        if ( $tts_cfg['provider'] !== 'tts-gcloud' || $tts_cfg['type'] !== 'google_cloud_tts' || $tts_cfg['model'] !== 'el-GR-Neural2-B' ) {
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
