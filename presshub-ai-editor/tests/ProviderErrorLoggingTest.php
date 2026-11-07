<?php
/**
 * ProviderErrorLoggingTest (#139) — provider failures (WP_Error transport
 * error, API error body, invalid response) must be written to the plugin
 * log via PressHub_AI_Logger::error() with provider/model/action context
 * and the active trace ID. Also checks presshub_ai_log_prompts() masks keys
 * in the config line while keeping prompts verbatim.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-trace.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class ProviderErrorLoggingTest
{
    private static array $failures = [];

    private static function check( string $label, bool $ok ): void {
        if ( ! $ok ) {
            self::$failures[] = $label;
        }
    }

    public static function run(): void {
        // Keep the PHP-level mirror of WARNING+ out of the test output.
        $php_log = tempnam( sys_get_temp_dir(), 'phpelog' );
        ini_set( 'error_log', $php_log );

        $log_file = tempnam( sys_get_temp_dir(), 'phlog' );
        PressHub_AI_Logger::set_log_file_path( $log_file );
        $trace_id = 'trace-139-test';
        PressHub_AI_Trace::set_current_trace_id( $trace_id );

        $cases = [
            'openai'        => [ 'model' => 'gpt-test-1' ],
            'anthropic'     => [ 'model' => 'claude-test-1' ],
            'gemini'        => [ 'model' => 'gemini-test-1' ],
            'custom_openai' => [ 'model' => 'custom-test-1' ],
        ];
        $modes = [ 'wp_error', 'api_error', 'invalid' ];

        foreach ( $cases as $provider => $cfg ) {
            foreach ( $modes as $mode ) {
                $GLOBALS['OPTIONS_STORE'] = [
                    'presshub_ai_api_key'            => 'k',
                    'presshub_ai_provider'           => $provider,
                    'presshub_ai_log_level'          => 'DEBUG',
                    'presshub_ai_model_' . $provider => $cfg['model'],
                ];
                $GLOBALS['CAPTURED_REQUESTS'] = [];
                $GLOBALS['CAPTURE_FILTER']    = function ( $existing, $req ) use ( $mode ) {
                    if ( 'wp_error' === $mode ) {
                        return new WP_Error( 'http_request_failed', 'transport boom' );
                    }
                    if ( 'api_error' === $mode ) {
                        return [ 'response' => [ 'code' => 429 ], 'body' => json_encode( [ 'error' => [ 'message' => 'quota boom' ] ] ) ];
                    }
                    return [ 'response' => [ 'code' => 200 ], 'body' => '{"unexpected":true}' ];
                };
                file_put_contents( $log_file, '' );

                $client = new PressHub_AI_API_Client();
                $client->set_action( 'provider_error_test' );
                if ( 'custom_openai' === $provider ) {
                    $result = $client->call_custom_openai( [
                        'type'     => 'custom_openai',
                        'base_url' => 'https://llm.example.test/v1',
                        'api_key'  => 'k',
                        'model'    => $cfg['model'],
                    ], 'sys', 'user' );
                } else {
                    $method = new ReflectionMethod( $client, 'call_' . $provider );
                    $method->setAccessible( true );
                    $args   = 'anthropic' === $provider ? [ 'sys', 'user', [] ] : [ 'sys', 'user', false, [] ];
                    $result = $method->invokeArgs( $client, $args );
                }

                $label = "{$provider}/{$mode}";
                self::check( "$label: returns WP_Error", is_wp_error( $result ) );

                $lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $log_file ) ), function ( $l ) {
                    return false !== strpos( $l, '[ERROR]' );
                } ) );
                self::check( "$label: exactly one ERROR line written to plugin log (got " . count( $lines ) . ')', 1 === count( $lines ) );
                $line = $lines[0] ?? '';
                $json = false !== strpos( $line, ' {' ) ? substr( $line, strpos( $line, ' {' ) + 1 ) : '';
                $ctx  = json_decode( $json, true );
                self::check( "$label: context JSON parses", is_array( $ctx ) );
                $ctx = is_array( $ctx ) ? $ctx : [];
                self::check( "$label: provider in context", ( $ctx['provider'] ?? null ) === $provider );
                self::check( "$label: model in context", ( $ctx['model'] ?? null ) === $cfg['model'] );
                self::check( "$label: action in context", ( $ctx['action'] ?? null ) === 'provider_error_test' );
                self::check( "$label: trace_id in context", ( $ctx['trace_id'] ?? null ) === $trace_id );
                self::check( "$label: duration_ms in context", isset( $ctx['duration_ms'] ) );
                self::check( "$label: error in context", ! empty( $ctx['error'] ) );
                if ( 'wp_error' !== $mode ) {
                    self::check( "$label: http_code in context", isset( $ctx['http_code'] ) && (int) $ctx['http_code'] === ( 'api_error' === $mode ? 429 : 200 ) );
                } else {
                    self::check( "$label: no http_code for transport error", ! isset( $ctx['http_code'] ) );
                }
            }
        }

        // presshub_ai_log_prompts(): masks the config line, keeps prompts verbatim.
        if ( file_exists( __DIR__ . '/../includes/class-secret-masker.php' ) ) {
            require_once __DIR__ . '/../includes/class-secret-masker.php';
            $GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_debug_prompts' => '1' ];
            $uploads  = wp_upload_dir();
            $dbg_file = PressHub_AI_Log_Paths::get_file( 'prompts' );
            @unlink( $dbg_file );
            $secret = 'sk-proj-ABCDEFGHIJKLMNOP1234';
            $sys    = 'SYSTEM keep sk-proj-VERBATIMKEY1234 here';
            $usr    = 'USER prompt Bearer verbatimtoken1234567890';
            presshub_ai_log_prompts( 'draft', $sys, $usr, 'RESPONSE text', 'provider=openai key=' . $secret . ' model=x' );
            $entry = json_decode( trim( (string) @file_get_contents( $dbg_file ) ), true );
            self::check( 'prompt log: entry written', is_array( $entry ) );
            $entry = is_array( $entry ) ? $entry : [];
            self::check( 'prompt log: config key masked', false === strpos( (string) ( $entry['config'] ?? '' ), $secret ) && false !== strpos( (string) ( $entry['config'] ?? '' ), 'provider=openai' ) );
            self::check( 'prompt log: system prompt verbatim', ( $entry['system_prompt'] ?? null ) === $sys );
            self::check( 'prompt log: user prompt verbatim', ( $entry['user_prompt'] ?? null ) === $usr );
            self::check( 'prompt log: response verbatim', ( $entry['response'] ?? null ) === 'RESPONSE text' );
        } else {
            echo "NOTE: class-secret-masker.php missing; skipped prompt-log masking check\n";
        }

        PressHub_AI_Trace::clear_current_trace_id();
        @unlink( $log_file );
        @unlink( $php_log );

        if ( self::$failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( self::$failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }
}

ProviderErrorLoggingTest::run();
