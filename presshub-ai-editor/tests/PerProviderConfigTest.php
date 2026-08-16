<?php
/**
 * TDD tests for per-provider configuration in PressHub_AI_API_Client (P2/P4/P6).
 *
 * Covers:
 *   - P2: the client picks model / temperature / max_tokens / timeout from
 *     the ACTIVE provider's options (presshub_ai_model_openai etc.) instead
 *     of the legacy global model; timeouts flow into wp_remote_post args.
 *   - classify_intent() and the audio-script generation lock temperature
 *     to 0.0 regardless of the configured per-provider temperature.
 *   - P6: OpenAI-Organization header (when option set), anthropic-version
 *     header (option with '2023-06-01' default), Imagen region option used
 *     by build_imagen_url().
 *   - P4: test_connection( $provider ) tests the given provider (URL + its
 *     own model) even when a different provider is active.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class PerProviderConfigTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: OpenAI uses its own model, temperature and timeout ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']            = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']           = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_openai']       = 'gpt-4o-custom';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_temperature_openai'] = 0.2;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_openai']  = 500;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_timeout_openai']     = 45;
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 'sources', 'instructions' );
        $req  = self::last_request();
        $body = json_decode( $req['args']['body'], true );
        if ( false === strpos( $req['url'], 'api.openai.com' ) ) {
            $failures[] = 'OpenAI request should hit api.openai.com; got: ' . $req['url'];
        }
        if ( ( $body['model'] ?? null ) !== 'gpt-4o-custom' ) {
            $failures[] = 'OpenAI body should use presshub_ai_model_openai (gpt-4o-custom); got: ' . var_export( $body['model'] ?? null, true );
        }
        if ( ( $body['temperature'] ?? null ) !== 0.2 ) {
            $failures[] = 'OpenAI body should carry temperature 0.2; got: ' . var_export( $body['temperature'] ?? null, true );
        }
        if ( ( $body['max_tokens'] ?? null ) !== 500 ) {
            $failures[] = 'OpenAI body should carry max_tokens 500; got: ' . var_export( $body['max_tokens'] ?? null, true );
        }
        if ( ( $req['args']['timeout'] ?? null ) !== 45 ) {
            $failures[] = 'OpenAI wp_remote_post timeout should be 45; got: ' . var_export( $req['args']['timeout'] ?? null, true );
        }

        // --- Case 2: Anthropic uses its own model, max_tokens, temperature,
        //             version header and default timeout ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']             = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']            = 'anthropic';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_anthropic']     = 'claude-3-5-sonnet-20240620';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_temperature_anthropic'] = 0.3;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_anthropic']  = 700;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_anthropic_version']     = '2024-01-01';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 'sources', 'instructions' );
        $req  = self::last_request();
        $body = json_decode( $req['args']['body'], true );
        if ( false === strpos( $req['url'], 'api.anthropic.com' ) ) {
            $failures[] = 'Anthropic request should hit api.anthropic.com; got: ' . $req['url'];
        }
        if ( ( $req['args']['headers']['anthropic-version'] ?? null ) !== '2024-01-01' ) {
            $failures[] = 'anthropic-version header should be 2024-01-01; got: ' . var_export( $req['args']['headers']['anthropic-version'] ?? null, true );
        }
        if ( ( $body['model'] ?? null ) !== 'claude-3-5-sonnet-20240620' ) {
            $failures[] = 'Anthropic body should use presshub_ai_model_anthropic; got: ' . var_export( $body['model'] ?? null, true );
        }
        if ( ( $body['max_tokens'] ?? null ) !== 700 ) {
            $failures[] = 'Anthropic body should carry max_tokens 700; got: ' . var_export( $body['max_tokens'] ?? null, true );
        }
        if ( ( $body['temperature'] ?? null ) !== 0.3 ) {
            $failures[] = 'Anthropic body should carry temperature 0.3; got: ' . var_export( $body['temperature'] ?? null, true );
        }
        if ( ( $req['args']['timeout'] ?? null ) !== 90 ) {
            $failures[] = 'Anthropic default timeout should be 90; got: ' . var_export( $req['args']['timeout'] ?? null, true );
        }

        // --- Case 3: Anthropic version header defaults to 2023-06-01 ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']         = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']        = 'anthropic';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 'sources', 'instructions' );
        $req = self::last_request();
        if ( ( $req['args']['headers']['anthropic-version'] ?? null ) !== '2023-06-01' ) {
            $failures[] = 'anthropic-version should default to 2023-06-01; got: ' . var_export( $req['args']['headers']['anthropic-version'] ?? null, true );
        }

        // --- Case 4: Gemini model in URL, temperature + maxOutputTokens in
        //             generationConfig, timeout ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']          = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']         = 'gemini';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini']     = 'gemini-1.5-pro-latest';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_temperature_gemini'] = 0.3;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_gemini']  = 600;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_timeout_gemini']   = 45;
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 'sources', 'instructions' );
        $req  = self::last_request();
        $body = json_decode( $req['args']['body'], true );
        if ( false === strpos( $req['url'], '/models/gemini-1.5-pro-latest:generateContent' ) ) {
            $failures[] = 'Gemini URL should contain presshub_ai_model_gemini; got: ' . $req['url'];
        }
        if ( str_contains( $req['url'], 'key=' ) ) {
            $failures[] = 'Gemini URL must not embed the API key in the query string (S-1); got: ' . $req['url'];
        }
        if ( ( $req['args']['headers']['x-goog-api-key'] ?? null ) !== 'k' ) {
            $failures[] = 'Gemini request should send x-goog-api-key header (S-1); got: ' . var_export( $req['args']['headers'] ?? null, true );
        }
        if ( ( $body['generationConfig']['temperature'] ?? null ) !== 0.3 ) {
            $failures[] = 'Gemini generationConfig should carry temperature 0.3; got: ' . var_export( $body['generationConfig'] ?? null, true );
        }
        if ( ( $body['generationConfig']['maxOutputTokens'] ?? null ) !== 600 ) {
            $failures[] = 'Gemini generationConfig should carry maxOutputTokens 600; got: ' . var_export( $body['generationConfig'] ?? null, true );
        }
        if ( ( $req['args']['timeout'] ?? null ) !== 45 ) {
            $failures[] = 'Gemini wp_remote_post timeout should be 45; got: ' . var_export( $req['args']['timeout'] ?? null, true );
        }

        // --- Case 5: default timeouts per provider (openai 60, gemini 90) ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']   = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']  = 'openai';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 's', 'i' );
        if ( ( self::last_request()['args']['timeout'] ?? null ) !== 60 ) {
            $failures[] = 'OpenAI default timeout should be 60.';
        }

        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']   = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']  = 'gemini';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 's', 'i' );
        if ( ( self::last_request()['args']['timeout'] ?? null ) !== 90 ) {
            $failures[] = 'Gemini default timeout should be 90.';
        }

        // --- Case 6: classify_intent forces temperature 0.0 (OpenAI body) ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']            = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']           = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_temperature_openai'] = 0.7;
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->classify_intent( 'hi' );
        $body = json_decode( self::last_request()['args']['body'], true );
        if ( (float) ( $body['temperature'] ?? null ) !== 0.0 ) {
            $failures[] = 'classify_intent must force temperature 0.0 in the OpenAI body; got: ' . var_export( $body['temperature'] ?? null, true );
        }

        // --- Case 7: classify_intent forces temperature 0.0 (Gemini generationConfig) ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']           = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']          = 'gemini';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_temperature_gemini'] = 0.9;
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->classify_intent( 'hi' );
        $body = json_decode( self::last_request()['args']['body'], true );
        if ( (float) ( $body['generationConfig']['temperature'] ?? null ) !== 0.0 ) {
            $failures[] = 'classify_intent must force temperature 0.0 in Gemini generationConfig; got: ' . var_export( $body['generationConfig'] ?? null, true );
        }

        // --- Case 8: audio-script generation forces temperature 0.0 (Gemini) ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']            = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']           = 'gemini';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'gc';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_temperature_gemini'] = 0.9;
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_audio_report( 'Write a radio script about X', 1 );
        $first = $GLOBALS['CAPTURED_REQUESTS'][0] ?? null;
        if ( ! $first || false === strpos( $first[0], 'generativelanguage.googleapis.com' ) ) {
            $failures[] = 'generate_audio_report should first call Gemini for the script; got: ' . var_export( $first[0] ?? null, true );
        } else {
            if ( str_contains( $first[0], 'key=' ) ) {
                $failures[] = 'Audio-script Gemini URL must not embed the API key (S-1); got: ' . $first[0];
            }
            if ( ( $first[1]['headers']['x-goog-api-key'] ?? null ) !== 'k' ) {
                $failures[] = 'Audio-script Gemini call should send x-goog-api-key header (S-1); got: ' . var_export( $first[1]['headers'] ?? null, true );
            }
            $body = json_decode( $first[1]['body'], true );
            if ( (float) ( $body['generationConfig']['temperature'] ?? null ) !== 0.0 ) {
                $failures[] = 'Audio-script generation must force temperature 0.0; got: ' . var_export( $body['generationConfig'] ?? null, true );
            }
        }
        // The TTS call must carry the Google Cloud key in the
        // x-goog-api-key header, never in the URL query string.
        $tts = $GLOBALS['CAPTURED_REQUESTS'][1] ?? null;
        if ( ! $tts || false === strpos( $tts[0], 'texttospeech.googleapis.com' ) ) {
            $failures[] = 'generate_audio_report should second-call TTS; got: ' . var_export( $tts[0] ?? null, true );
        } else {
            if ( str_contains( $tts[0], '?key=' ) ) {
                $failures[] = 'TTS URL must not embed the API key in the query string; got: ' . $tts[0];
            }
            if ( ( $tts[1]['headers']['x-goog-api-key'] ?? null ) !== 'gc' ) {
                $failures[] = 'TTS request should send x-goog-api-key header; got: ' . var_export( $tts[1]['headers'] ?? null, true );
            }
        }

        // --- Case 9: OpenAI-Organization header present when option set, absent otherwise ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']   = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']  = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_openai_org'] = 'org-abc';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 's', 'i' );
        if ( ( self::last_request()['args']['headers']['OpenAI-Organization'] ?? null ) !== 'org-abc' ) {
            $failures[] = 'OpenAI-Organization header should be org-abc when presshub_ai_openai_org is set.';
        }

        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']   = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']  = 'openai';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 's', 'i' );
        if ( isset( self::last_request()['args']['headers']['OpenAI-Organization'] ) ) {
            $failures[] = 'OpenAI-Organization header must be absent when the option is unset.';
        }

        // --- Case 10: Imagen region comes from presshub_ai_imagen_region ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'gc';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_imagen_region']        = 'europe-west4';
        $client = new PressHub_AI_API_Client();
        $url = $client->build_imagen_url();
        if ( false === strpos( $url, 'https://europe-west4-aiplatform.googleapis.com/v1/projects/presshub-ai/locations/europe-west4/' ) ) {
            $failures[] = 'build_imagen_url should use the configured region; got: ' . $url;
        }

        // --- Case 11: test_connection( $provider ) tests the GIVEN provider ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']       = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']      = 'openai'; // active provider
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini']  = 'gemini-2.0-flash';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $result = $client->test_connection( 'gemini' );
        $req    = self::last_request();
        if ( is_wp_error( $result ) ) {
            $failures[] = 'test_connection(gemini) should succeed: ' . $result->get_error_message();
        }
        if ( false === strpos( $req['url'], 'generativelanguage.googleapis.com' ) || false === strpos( $req['url'], '/models/gemini-2.0-flash:generateContent' ) ) {
            $failures[] = 'test_connection(gemini) should hit Gemini with the gemini model; got: ' . $req['url'];
        }

        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']  = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->test_connection(); // no provider -> active provider
        if ( false === strpos( self::last_request()['url'], 'api.openai.com' ) ) {
            $failures[] = 'test_connection() without provider should test the active provider (openai).';
        }

        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']  = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->test_connection( 'anthropic' );
        if ( false === strpos( self::last_request()['url'], 'api.anthropic.com' ) ) {
            $failures[] = 'test_connection(anthropic) should hit api.anthropic.com.';
        }

        // --- Case 12: test_connection still reports a missing key ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $result = $client->test_connection( 'gemini' );
        if ( ! is_wp_error( $result ) || 'no_api_key' !== $result->get_error_code() ) {
            $failures[] = 'test_connection should return no_api_key when the key is missing; got: ' . var_export( $result, true );
        }

        // --- Case 13: a model option with a leading 'models/' path is
        //             normalized so the URL never double-prefixes ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']      = 'k';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']     = 'gemini';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_model_gemini'] = 'models/gemini-2.0-flash';
        $GLOBALS['CAPTURE_FILTER'] = self::multi_provider_filter();
        $client = new PressHub_AI_API_Client();
        $client->generate_draft( 's', 'i' );
        $req = self::last_request();
        if ( str_contains( $req['url'], 'models/models/' ) ) {
            $failures[] = 'Gemini URL must not double-prefix models/; got: ' . $req['url'];
        }
        if ( false === strpos( $req['url'], '/models/gemini-2.0-flash:generateContent' ) ) {
            $failures[] = 'Gemini URL should contain the normalized model; got: ' . $req['url'];
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
     * Capture filter that answers each provider's endpoint with a valid
     * success payload.
     */
    private static function multi_provider_filter() {
        return function ( $existing, $req ) {
            [ $url ] = $req;
            if ( str_contains( $url, 'api.openai.com' ) ) {
                $body = [ 'choices' => [ [ 'message' => [ 'content' => 'ok' ] ] ] ];
                return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
            }
            if ( str_contains( $url, 'api.anthropic.com' ) ) {
                $body = [ 'content' => [ [ 'type' => 'text', 'text' => 'ok' ] ] ];
                return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
            }
            if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                $body = [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'ok' ] ] ] ] ] ];
                return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
            }
            if ( str_contains( $url, 'texttospeech.googleapis.com' ) ) {
                $body = [ 'audioContent' => base64_encode( 'MP3' ) ];
                return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ) ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };
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

PerProviderConfigTest::run();
