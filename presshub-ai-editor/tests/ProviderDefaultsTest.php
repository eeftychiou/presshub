<?php
/**
 * TDD tests for the single source of provider defaults
 * (includes/class-provider-defaults.php, architect D-1).
 *
 * Covers:
 *   - per-provider default models (openai gpt-4o, anthropic
 *     claude-3-5-sonnet-20240620, gemini gemini-1.5-pro-latest,
 *     unknown provider falls back to gpt-4o);
 *   - shared defaults (temperature 0.7, max_tokens 16384);
 *   - per-provider timeouts (openai 60, everyone else 90);
 *   - consistency: PressHub_AI_Settings and PressHub_AI_API_Client
 *     resolve the exact same defaults as PressHub_AI_Provider_Defaults
 *     (Settings via its public static delegators, the API client via
 *     observable request behaviour with no options configured).
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class ProviderDefaultsTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: default model per provider ---
        $expected_models = [
            'openai'    => 'gpt-4o',
            'anthropic' => 'claude-3-5-sonnet-20240620',
            'gemini'    => 'gemini-1.5-pro-latest',
        ];
        foreach ( $expected_models as $provider => $model ) {
            $got = PressHub_AI_Provider_Defaults::default_model( $provider );
            if ( $got !== $model ) {
                $failures[] = "default_model({$provider}) should be {$model}; got: {$got}";
            }
        }
        // Unknown providers fall back to the OpenAI default.
        $got = PressHub_AI_Provider_Defaults::default_model( 'unknown-vendor' );
        if ( $got !== 'gpt-4o' ) {
            $failures[] = 'default_model(unknown) should fall back to gpt-4o; got: ' . $got;
        }

        // --- Case 2: shared temperature / max_tokens ---
        if ( PressHub_AI_Provider_Defaults::default_temperature() !== 0.7 ) {
            $failures[] = 'default_temperature() should be 0.7; got: ' . var_export( PressHub_AI_Provider_Defaults::default_temperature(), true );
        }
        if ( PressHub_AI_Provider_Defaults::default_max_tokens() !== 16384 ) {
            $failures[] = 'default_max_tokens() should be 16384; got: ' . var_export( PressHub_AI_Provider_Defaults::default_max_tokens(), true );
        }

        // --- Case 3: timeout per provider (default 300s) ---
        foreach ( [ 'openai', 'anthropic', 'gemini', 'groq', 'some-other' ] as $provider ) {
            $got = PressHub_AI_Provider_Defaults::default_timeout( $provider );
            if ( $got !== 300 ) {
                $failures[] = "default_timeout({$provider}) should be 300; got: {$got}";
            }
        }

        // --- Case 4: Settings resolves identical defaults ---
        foreach ( [ 'openai', 'anthropic', 'gemini' ] as $provider ) {
            if ( PressHub_AI_Settings::default_model( $provider ) !== PressHub_AI_Provider_Defaults::default_model( $provider ) ) {
                $failures[] = "Settings::default_model({$provider}) should match Provider_Defaults";
            }
            if ( PressHub_AI_Settings::default_timeout( $provider ) !== PressHub_AI_Provider_Defaults::default_timeout( $provider ) ) {
                $failures[] = "Settings::default_timeout({$provider}) should match Provider_Defaults";
            }
        }
        if ( PressHub_AI_Settings::default_temperature() !== PressHub_AI_Provider_Defaults::default_temperature() ) {
            $failures[] = 'Settings::default_temperature() should match Provider_Defaults';
        }
        if ( PressHub_AI_Settings::default_max_tokens() !== PressHub_AI_Provider_Defaults::default_max_tokens() ) {
            $failures[] = 'Settings::default_max_tokens() should match Provider_Defaults';
        }

        // --- Case 5: the API client resolves identical defaults at
        //             runtime (empty options store -> provider defaults) ---
        foreach ( [ 'openai', 'anthropic', 'gemini' ] as $provider ) {
            self::reset_world();
            $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']  = 'k';
            $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = $provider;
            $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
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
                return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
            };
            $client = new PressHub_AI_API_Client();
            $client->generate_draft( 'sources', 'instructions' );
            $req  = self::last_request();
            $body = json_decode( $req['args']['body'], true );

            $model   = $body['model'] ?? ( $body['generationConfig']['model'] ?? null );
            $model   = $model ?? ( preg_match( '#/models/([^:]+):#', $req['url'], $m ) ? $m[1] : null );
            $timeout = $req['args']['timeout'] ?? null;
            if ( $model !== PressHub_AI_Provider_Defaults::default_model( $provider ) ) {
                $failures[] = "API client should resolve default model {$provider} = " . PressHub_AI_Provider_Defaults::default_model( $provider ) . "; got: " . var_export( $model, true );
            }
            if ( $timeout !== PressHub_AI_Provider_Defaults::default_timeout( $provider ) ) {
                $failures[] = "API client should resolve default timeout {$provider} = " . PressHub_AI_Provider_Defaults::default_timeout( $provider ) . "; got: " . var_export( $timeout, true );
            }
        }

        // --- Case 6: Standard provider templates ---
        $expected_types = [ 'openai', 'anthropic', 'gemini', 'groq', 'mistral', 'deepseek', 'ollama_local', 'custom_openai' ];
        $templates = PressHub_AI_Provider_Defaults::get_templates();
        foreach ( $expected_types as $type ) {
            if ( ! isset( $templates[ $type ] ) ) {
                $failures[] = "Template for type '{$type}' is missing.";
            } else {
                $tmpl = $templates[ $type ];
                if ( ( $tmpl['type'] ?? '' ) !== $type ) {
                    $failures[] = "Template '{$type}' type property mismatch.";
                }
                if ( empty( $tmpl['name'] ) ) {
                    $failures[] = "Template '{$type}' is missing a name.";
                }
                if ( ( $tmpl['timeout'] ?? 0 ) !== 300 ) {
                    $failures[] = "Template '{$type}' timeout should be 300; got " . ( $tmpl['timeout'] ?? 0 );
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

ProviderDefaultsTest::run();
