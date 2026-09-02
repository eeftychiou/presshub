<?php
/**
 * TtsPayloadLogTest — Issue #80 Settings-First verification.
 *
 * Asserts the presshub_ai_log_tts_payloads Settings knob:
 *   1. Registers the option via sanitize_boolean (so on/off round-trips).
 *   2. get_log_tts_payloads() defaults to false (production log size).
 *   3. write_tts_payload_log() respects the action hook mirror (so
 *      developer scripts / CLI tools can intercept payloads) and
 *      writes a JSON entry to wp-content/uploads/presshub-ai/presshub-ai-tts-debug.log.
 *   4. The PressHub_AI_API_Client::mask_api_key_in_url() helper masks
 *      `key=` query parameters on Gemini TTS endpoint URLs.
 *   5. When the toggle is OFF, neither the helper nor the request/response
 *      log writer are touched (no entries leak).
 *
 * The test follows the existing convention (PromptDebugLogTest.php) of
 * using wordpress-stubs + wp-action-wrapper for an isolated PHP process.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'http://example.test/wp-content/plugins/presshub-ai-editor/';
    }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) {
        $GLOBALS['DEACTIVATION_HOOKS'][] = $callback;
    }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = [] ) {
        $GLOBALS['WP_REMOTE_POST_CALLS'][] = [ 'url' => $url, 'args' => $args ];
        return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return $response['response']['code'] ?? 0;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
    function wp_remote_retrieve_header( $response, $header ) {
        return $response['headers'][ $header ] ?? '';
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return $response['body'] ?? '';
    }
}
if ( ! function_exists( 'wp_raise_memory_limit' ) ) {
    function wp_raise_memory_limit( $context = 'admin' ) {
        return;
    }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        $base = $GLOBALS['UPLOAD_DIR'] ?? sys_get_temp_dir() . '/presshub-tts-payload-test';
        if ( ! is_dir( $base ) ) {
            mkdir( $base, 0777, true );
        }
        return [ 'basedir' => $base, 'baseurl' => 'http://example.test/wp-content/uploads' ];
    }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir ) {
        if ( is_dir( $dir ) ) {
            return true;
        }
        return mkdir( $dir, 0777, true );
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = '' ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = '' ) { return $text; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $text, $domain = '' ) { return $text; }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $echo = true ) {
        $result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
        if ( $echo ) echo $result;
        return $result;
    }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) { return $value; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $str ) { return rtrim( (string) $str, '/\\' ) . '/'; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
        return json_encode( $data, $flags, $depth );
    }
}

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
function tpl_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

// --- Case 1: get_log_tts_payloads() defaults to false (production safety) ---
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_log_tts_payloads'] );
tpl_check(
    'default: get_log_tts_payloads() returns false',
    false === PressHub_AI_Settings_Storage::get_log_tts_payloads()
);

// --- Case 2: enable toggle, helper returns true ---
$GLOBALS['OPTIONS_STORE']['presshub_ai_log_tts_payloads'] = '1';
tpl_check(
    'enabled: get_log_tts_payloads() returns true',
    true === PressHub_AI_Settings_Storage::get_log_tts_payloads()
);

// --- Case 3: sanitize_boolean accepts the canonical truthy values ---
foreach ( [ '1', 1, true, 'on', 'true', 'yes' ] as $truthy ) {
    tpl_check(
        'sanitize_boolean truthy: ' . var_export( $truthy, true ),
        1 === PressHub_AI_Settings_Storage::sanitize_boolean( $truthy )
    );
}
foreach ( [ '0', 0, false, 'off', 'no', '', 'random' ] as $falsy ) {
    tpl_check(
        'sanitize_boolean falsy: ' . var_export( $falsy, true ),
        0 === PressHub_AI_Settings_Storage::sanitize_boolean( $falsy )
    );
}

// --- Case 4: mask_api_key_in_url masks `key=` only, leaves everything else ---
$plain_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-tts-preview:generateContent?key=AIzaSyD-EXAMPLE-leaked-secret-1234&foo=bar';
$masked = PressHub_AI_API_Client::mask_api_key_in_url( $plain_url );
tpl_check( 'mask_url: removes leaked key', false === strpos( $masked, 'leaked-secret-1234' ) );
tpl_check( 'mask_url: keeps other params', false !== strpos( $masked, 'foo=bar' ) );
tpl_check( 'mask_url: keeps host/path', false !== strpos( $masked, 'generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-tts-preview:generateContent' ) );
tpl_check( 'mask_url: marks masked token', false !== strpos( $masked, '***masked***' ) );

// Idempotent — applying twice still produces a safe URL.
$masked_twice = PressHub_AI_API_Client::mask_api_key_in_url( $masked );
tpl_check( 'mask_url: idempotent', $masked === $masked_twice );

// Empty / no key param handled.
tpl_check(
    'mask_url: no key param left untouched',
    'https://example.test/foo' === PressHub_AI_API_Client::mask_api_key_in_url( 'https://example.test/foo' )
);
tpl_check(
    'mask_url: empty input returns empty',
    '' === PressHub_AI_API_Client::mask_api_key_in_url( '' )
);

// --- Case 5: write_tts_payload_log writes JSON line + fires action ---
$log_root = sys_get_temp_dir() . '/presshub-tts-test-' . getmypid() . '-' . uniqid();
@mkdir( $log_root, 0777, true );
$GLOBALS['UPLOAD_DIR'] = $log_root;
$GLOBALS['DO_ACTION_LOG'] = [];

// The request payload NEVER carries the raw `endpoint` field — only the
// masked variant. This guards against API key leakage even if a developer
// accidentally logs the raw $entry or ships it to a third party.
$entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
    'request',
    [
        'model'           => 'gemini-3.1-flash-tts-preview',
        'endpoint_masked' => PressHub_AI_API_Client::mask_api_key_in_url(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-tts-preview:generateContent?key=AIzaSyLEAKED12345'
        ),
        'speaker_mapping' => [
            [ 'speaker' => 'Μαρία', 'voiceConfig' => [ 'prebuiltVoiceConfig' => [ 'voiceName' => 'Kore' ] ] ],
            [ 'speaker' => 'Νίκος', 'voiceConfig' => [ 'prebuiltVoiceConfig' => [ 'voiceName' => 'Fenrir' ] ] ],
        ],
        'is_multi_speaker' => true,
        'prompt_text'       => '[Μαρία]: Καλημέρα Νίκο.',
    ]
);

PressHub_AI_API_Client::write_tts_payload_log( $entry );

$log_file = $log_root . '/presshub-ai/presshub-ai-tts-debug.log';
tpl_check( 'write_log: file exists', file_exists( $log_file ) );

if ( file_exists( $log_file ) ) {
    $content = file_get_contents( $log_file );
    tpl_check( 'write_log: contains phase', false !== strpos( $content, '"phase":"request"' ) );
    tpl_check( 'write_log: contains timestamp', false !== strpos( $content, '"timestamp"' ) );
    tpl_check( 'write_log: contains model', false !== strpos( $content, 'gemini-3.1-flash-tts-preview' ) );
    tpl_check( 'write_log: masks API key in URL', false === strpos( $content, 'AIzaSyLEAKED12345' ) );
    tpl_check( 'write_log: contains speaker mapping', false !== strpos( $content, 'prebuiltVoiceConfig' ) );
    tpl_check( 'write_log: contains prompt text', false !== strpos( $content, 'Καλημέρα Νίκο' ) );
}

// Action mirror — hooks must receive the raw entry + the json string.
$tts_action_calls = array_values( array_filter(
    $GLOBALS['DO_ACTION_LOG'],
    fn( $e ) => isset( $e['hook'] ) && 'presshub_ai_tts_payload_log' === $e['hook']
) );
tpl_check( 'write_log: action fired exactly once', 1 === count( $tts_action_calls ) );
if ( 1 === count( $tts_action_calls ) ) {
    $args = $tts_action_calls[0]['args'] ?? [];
    tpl_check( 'action arg[0] is the raw entry array', is_array( $args[0] ?? null ) );
    tpl_check( 'action arg[1] is the JSON string', is_string( $args[1] ?? null ) );
}

// --- Case 6: response entry captures http_code + bytes + mime ---
$response_entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
    'response',
    [
        'model'      => 'gemini-3.1-flash-tts-preview',
        'endpoint'   => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-tts-preview:generateContent?key=***masked***',
        'latency_ms' => 421,
        'http_code'  => 200,
        'mime_type'  => 'audio/pcm;rate=24000',
        'bytes'      => 12345,
    ]
);
PressHub_AI_API_Client::write_tts_payload_log( $response_entry );
if ( file_exists( $log_file ) ) {
    $content = file_get_contents( $log_file );
    tpl_check( 'response: phase present', false !== strpos( $content, '"phase":"response"' ) );
    tpl_check( 'response: latency present', false !== strpos( $content, '"latency_ms":421' ) );
    tpl_check( 'response: bytes present', false !== strpos( $content, '"bytes":12345' ) );
    tpl_check( 'response: mime present', false !== strpos( $content, 'audio/pcm;rate=24000' ) );
}

// --- Case 7: topic / stitched entries are well-formed ---
$topic_entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
    'topic',
    [
        'date'         => '2026-09-02',
        'topic_index'  => 2,
        'topic_total'  => 4,
        'topic_title'  => 'Εξωτερική Πολιτική',
        'turns_count'  => 6,
        'chars'        => 1480,
        'host_count'   => 2,
        'female_voice' => 'Kore',
        'male_voice'   => 'Fenrir',
        'turn_assignments' => [
            [ 'turn' => 1, 'speaker' => 'female', 'voice' => 'Kore', 'chars' => 220 ],
            [ 'turn' => 2, 'speaker' => 'male',   'voice' => 'Fenrir', 'chars' => 245 ],
        ],
    ]
);
PressHub_AI_API_Client::write_tts_payload_log( $topic_entry );
if ( file_exists( $log_file ) ) {
    $content = file_get_contents( $log_file );
    tpl_check( 'topic: phase present', false !== strpos( $content, '"phase":"topic"' ) );
    tpl_check( 'topic: index present', false !== strpos( $content, '"topic_index":2' ) );
    tpl_check( 'topic: assignments present', false !== strpos( $content, 'turn_assignments' ) );
}

$stitched_entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
    'stitched',
    [
        'date'           => '2026-09-02',
        'phase'          => 'topic_stitched',
        'topic_count'    => 4,
        'chunks_count'   => 6,
        'total_chars'    => 5800,
        'duration_ms'    => 42100,
        'audio_bytes'    => 1503200,
        'sample_rate_hz' => 24000,
        'pause_ms'       => 600,
        'female_voice'   => 'Kore',
        'male_voice'     => 'Fenrir',
    ]
);
PressHub_AI_API_Client::write_tts_payload_log( $stitched_entry );
if ( file_exists( $log_file ) ) {
    $content = file_get_contents( $log_file );
    tpl_check( 'stitched: phase present', false !== strpos( $content, '"phase":"stitched"' ) );
    tpl_check( 'stitched: topic_count present', false !== strpos( $content, '"topic_count":4' ) );
    tpl_check( 'stitched: audio_bytes present', false !== strpos( $content, '"audio_bytes":1503200' ) );
}

// --- Case 8: provider store key masking integration ---
// Sanity check that PressHub_AI_Provider_Store::mask_key still works so
// the TTS request payload header masking integrates cleanly.
tpl_check(
    'provider_store mask_key masks a long API key',
    false === strpos( PressHub_AI_Provider_Store::mask_key( 'sk-pr-1234567890abcdef-3x9K' ), '1234567890abcdef' )
);

// --- Cleanup ---
@unlink( $log_file );
@rmdir( dirname( $log_file ) );
@rmdir( $log_root );
unset( $GLOBALS['UPLOAD_DIR'] );

if ( $failures > 0 ) {
    fwrite( STDERR, "TtsPayloadLogTest: {$failures} failure(s)\n" );
    exit( 1 );
}

echo "TtsPayloadLogTest: OK\n";
