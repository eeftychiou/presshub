<?php
/**
 * Test PressHub_AI_Trace and Trace Propagation
 *
 * Covers:
 *   - Unique trace ID generation format (tr_<hex>).
 *   - Getting, setting, and clearing current trace ID.
 *   - get_or_create_trace_id() idempotence within an execution scope.
 *   - Propagation into PressHub_AI_Logger formatted output.
 *   - Propagation into PressHub_AI_Token_Logger metadata (LLM & TTS).
 *   - Propagation into presshub_ai_log_prompts JSONL entries.
 *   - Propagation into write_tts_payload_log JSON entries.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-trace.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-token-logger.php';
require_once __DIR__ . '/../includes/class-api-client.php';

$failures = [];

// 1. generate_id()
$id1 = PressHub_AI_Trace::generate_id();
$id2 = PressHub_AI_Trace::generate_id();

if ( empty( $id1 ) || 0 !== strpos( $id1, 'tr_' ) ) {
    $failures[] = 'generate_id() must return a non-empty string starting with "tr_"; got: ' . var_export( $id1, true );
}
if ( $id1 === $id2 ) {
    $failures[] = 'generate_id() must generate unique IDs across calls; got duplicate: ' . $id1;
}

// 2. get_current_trace_id() / set_current_trace_id() / clear_current_trace_id()
PressHub_AI_Trace::clear_current_trace_id();
if ( null !== PressHub_AI_Trace::get_current_trace_id() ) {
    $failures[] = 'get_current_trace_id() must return null when no trace is set; got: ' . var_export( PressHub_AI_Trace::get_current_trace_id(), true );
}

PressHub_AI_Trace::set_current_trace_id( 'tr_custom_12345' );
if ( 'tr_custom_12345' !== PressHub_AI_Trace::get_current_trace_id() ) {
    $failures[] = 'get_current_trace_id() must return set trace ID; got: ' . var_export( PressHub_AI_Trace::get_current_trace_id(), true );
}

PressHub_AI_Trace::clear_current_trace_id();
if ( null !== PressHub_AI_Trace::get_current_trace_id() ) {
    $failures[] = 'clear_current_trace_id() must reset active trace; got: ' . var_export( PressHub_AI_Trace::get_current_trace_id(), true );
}

// 3. get_or_create_trace_id()
$created = PressHub_AI_Trace::get_or_create_trace_id();
if ( empty( $created ) || 0 !== strpos( $created, 'tr_' ) ) {
    $failures[] = 'get_or_create_trace_id() must create a valid trace ID; got: ' . var_export( $created, true );
}
$second_call = PressHub_AI_Trace::get_or_create_trace_id();
if ( $created !== $second_call ) {
    $failures[] = 'get_or_create_trace_id() must return the same active trace ID on subsequent calls; got ' . $second_call;
}

// 4. Trace propagation to PressHub_AI_Logger
$tmp_dir = sys_get_temp_dir() . '/presshub-trace-log-' . uniqid();
@mkdir( $tmp_dir, 0777, true );
$log_file = $tmp_dir . '/test.log';
PressHub_AI_Logger::set_log_file_path( $log_file );
update_option( 'presshub_ai_log_level', 'DEBUG' );
PressHub_AI_Logger::clear_log();

PressHub_AI_Trace::set_current_trace_id( 'tr_logger_prop_test' );
PressHub_AI_Logger::info( 'Message with trace' );

$log_content = PressHub_AI_Logger::get_recent_logs();
if ( false === strpos( $log_content, '[tr_logger_prop_test]' ) ) {
    $failures[] = 'PressHub_AI_Logger must prepend [trace_id] when active trace exists; log content: ' . $log_content;
}

PressHub_AI_Trace::clear_current_trace_id();
PressHub_AI_Logger::info( 'Message without trace' );
$log_content_after = PressHub_AI_Logger::get_recent_logs();
if ( false !== strpos( $log_content_after, 'Message without trace' ) && false !== strpos( $log_content_after, '[tr_logger_prop_test] Message without trace' ) ) {
    $failures[] = 'PressHub_AI_Logger must not attach trace ID when trace is cleared.';
}

@unlink( $log_file );
@rmdir( $tmp_dir );
PressHub_AI_Logger::set_log_file_path( null );

// 5. Trace propagation to PressHub_AI_Token_Logger
global $wpdb;
$wpdb->tables = [];
$wpdb->queries = [];
$wpdb->auto_increments = [];
PressHub_AI_Token_Logger::create_table();

PressHub_AI_Trace::set_current_trace_id( 'tr_token_test_abc' );
$llm_id = PressHub_AI_Token_Logger::log_llm_request(
    'test_action',
    'openai',
    'gpt-4o',
    100,
    50,
    500,
    'success',
    null,
    [ 'extra' => 'data' ]
);

$row = $wpdb->tables['wp_presshub_ai_token_logs'][0] ?? null;
if ( ! $row ) {
    $failures[] = 'Token logger row not inserted.';
} else {
    $meta = json_decode( $row['metadata'], true );
    if ( ! is_array( $meta ) || ( $meta['trace_id'] ?? '' ) !== 'tr_token_test_abc' ) {
        $failures[] = 'log_llm_request must inject current trace_id into metadata; got: ' . var_export( $row['metadata'], true );
    }
}

// 5b. TTS Token log
$tts_id = PressHub_AI_Token_Logger::log_tts_request(
    'test_tts',
    'gemini',
    'Kore',
    200,
    300,
    'success',
    null,
    []
);

$tts_row = $wpdb->tables['wp_presshub_ai_token_logs'][1] ?? null;
if ( ! $tts_row ) {
    $failures[] = 'TTS Token logger row not inserted.';
} else {
    $meta_tts = json_decode( $tts_row['metadata'], true );
    if ( ! is_array( $meta_tts ) || ( $meta_tts['trace_id'] ?? '' ) !== 'tr_token_test_abc' ) {
        $failures[] = 'log_tts_request must inject current trace_id into metadata; got: ' . var_export( $tts_row['metadata'], true );
    }
}

// 6. Trace propagation to presshub_ai_log_prompts and write_tts_payload_log
$upload_dir = sys_get_temp_dir() . '/presshub-trace-upload-' . uniqid();
@mkdir( $upload_dir, 0777, true );
$GLOBALS['UPLOAD_DIR'] = $upload_dir;
$GLOBALS['OPTIONS_STORE']['presshub_ai_debug_prompts'] = '1';

PressHub_AI_Trace::set_current_trace_id( 'tr_prompt_file_test' );
presshub_ai_log_prompts( 'draft', 'SYS-PROMPT', 'USER-PROMPT', 'RESP-PROMPT' );

$prompt_log_file = $upload_dir . '/presshub-ai/presshub-ai-debug.log';
if ( ! file_exists( $prompt_log_file ) ) {
    $failures[] = 'presshub_ai_log_prompts did not create ' . $prompt_log_file;
} else {
    $p_line = trim( file_get_contents( $prompt_log_file ) );
    $decoded_prompt = json_decode( $p_line, true );
    if ( ! is_array( $decoded_prompt ) ) {
        $failures[] = 'Prompt debug log is not valid JSON; got: ' . $p_line;
    } elseif ( ( $decoded_prompt['trace_id'] ?? '' ) !== 'tr_prompt_file_test' ) {
        $failures[] = 'Prompt debug log missing expected trace_id; got: ' . var_export( $decoded_prompt, true );
    }
}

// 6b. write_tts_payload_log
$tts_entry = PressHub_AI_API_Client::build_tts_payload_log_entry( 'request', [ 'text' => 'Hello' ] );
if ( ( $tts_entry['trace_id'] ?? '' ) !== 'tr_prompt_file_test' ) {
    $failures[] = 'build_tts_payload_log_entry did not inject active trace_id; got: ' . var_export( $tts_entry, true );
}

PressHub_AI_API_Client::write_tts_payload_log( $tts_entry );
$tts_log_file = $upload_dir . '/presshub-ai/presshub-ai-tts-debug.log';
if ( ! file_exists( $tts_log_file ) ) {
    $failures[] = 'write_tts_payload_log did not create ' . $tts_log_file;
} else {
    $tts_content = file_get_contents( $tts_log_file );
    if ( false === strpos( $tts_content, 'tr_prompt_file_test' ) ) {
        $failures[] = 'TTS debug log file does not contain trace_id; got: ' . $tts_content;
    }
}

// Cleanup
@unlink( $prompt_log_file );
@unlink( $tts_log_file );
@rmdir( $upload_dir . '/presshub-ai' );
@rmdir( $upload_dir );
unset( $GLOBALS['UPLOAD_DIR'], $GLOBALS['OPTIONS_STORE']['presshub_ai_debug_prompts'] );
PressHub_AI_Trace::clear_current_trace_id();

if ( ! empty( $failures ) ) {
    echo "TraceIdTest FAILED:\n";
    foreach ( $failures as $f ) {
        echo "  - {$f}\n";
    }
    exit( 1 );
}

echo "OK\n";
exit( 0 );
