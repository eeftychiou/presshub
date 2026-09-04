<?php
/**
 * Test LogViewerAjaxTest
 *
 * Verifies:
 * 1. AJAX action registration: wp_ajax_presshub_ai_get_logs and wp_ajax_presshub_ai_clear_logs.
 * 2. Nonce and capability enforcement for both endpoints.
 * 3. get_logs() target handling for 'app', 'prompts', 'tts'.
 * 4. get_logs() search and trace_id filtering and line limiting.
 * 5. get_logs() JSONL parsing for 'prompts' and 'tts'.
 * 6. clear_logs() target handling for 'app', 'prompts', 'tts', and 'all'.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-trace.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

$failures = [];

function lva_reset_env(): void {
    $GLOBALS['JSON_RESPONSES'] = [];
    $GLOBALS['NONCE_VALID'] = true;
    $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
    $_POST = [
        'nonce' => 'valid_nonce',
    ];
}

$handlers = new PressHub_AI_Ajax_Handlers();

// 1. Action registration
if ( ! has_action( 'wp_ajax_presshub_ai_get_logs' ) ) {
    $failures[] = 'Action wp_ajax_presshub_ai_get_logs is not registered.';
}
if ( ! has_action( 'wp_ajax_presshub_ai_clear_logs' ) ) {
    $failures[] = 'Action wp_ajax_presshub_ai_clear_logs is not registered.';
}

// 2. Nonce verification rejection
lva_reset_env();
$GLOBALS['NONCE_VALID'] = false;
try {
    $handlers->get_logs();
    $failures[] = 'get_logs() should reject invalid nonce.';
} catch ( RuntimeException $e ) {
    if ( false === strpos( $e->getMessage(), 'check_ajax_referer failed' ) ) {
        $failures[] = 'get_logs() threw unexpected exception for nonce: ' . $e->getMessage();
    }
}

lva_reset_env();
$GLOBALS['NONCE_VALID'] = false;
try {
    $handlers->clear_logs();
    $failures[] = 'clear_logs() should reject invalid nonce.';
} catch ( RuntimeException $e ) {
    if ( false === strpos( $e->getMessage(), 'check_ajax_referer failed' ) ) {
        $failures[] = 'clear_logs() threw unexpected exception for nonce: ' . $e->getMessage();
    }
}

// 2b. Capability verification
lva_reset_env();
$GLOBALS['CURRENT_USER_CAPS'] = [];
try {
    $handlers->get_logs();
    $failures[] = 'get_logs() should reject user without manage_options.';
} catch ( RuntimeException $e ) {
    $last_resp = end( $GLOBALS['JSON_RESPONSES'] );
    if ( empty( $last_resp ) || true === $last_resp['success'] ) {
        $failures[] = 'get_logs() did not send json error on capability rejection.';
    }
}

// Setup test upload directory
$upload_dir = sys_get_temp_dir() . '/presshub-ajax-log-' . uniqid();
@mkdir( $upload_dir . '/presshub-ai', 0777, true );
$GLOBALS['UPLOAD_DIR'] = $upload_dir;

$app_file     = $upload_dir . '/presshub-ai/presshub-debug.log';
$prompt_file  = $upload_dir . '/presshub-ai/presshub-ai-debug.log';
$tts_file     = $upload_dir . '/presshub-ai/presshub-ai-tts-debug.log';

PressHub_AI_Logger::set_log_file_path( $app_file );

// 3. get_logs target='app'
lva_reset_env();
file_put_contents( $app_file, "[2026-09-04 12:00:00 UTC] [INFO] [tr_app_1] Log entry 1\n[2026-09-04 12:01:00 UTC] [ERROR] [tr_app_2] Critical failure\n" );
$_POST['target'] = 'app';
$_POST['lines'] = 10;
try {
    $handlers->get_logs();
} catch ( RuntimeException $e ) {
    // Expected terminal wp_send_json_success
}

$resp = end( $GLOBALS['JSON_RESPONSES'] );
if ( ! $resp || ! $resp['success'] || ( $resp['data']['target'] ?? '' ) !== 'app' ) {
    $failures[] = 'get_logs(target=app) did not return expected response.';
} elseif ( count( $resp['data']['entries'] ?? [] ) !== 2 ) {
    $failures[] = 'get_logs(target=app) returned incorrect number of entries: ' . count( $resp['data']['entries'] ?? [] );
}

// 3b. get_logs target='app' with trace_id filter
lva_reset_env();
$_POST['target'] = 'app';
$_POST['trace_id'] = 'tr_app_2';
try {
    $handlers->get_logs();
} catch ( RuntimeException $e ) {}
$resp = end( $GLOBALS['JSON_RESPONSES'] );
if ( count( $resp['data']['entries'] ?? [] ) !== 1 || false === strpos( $resp['data']['entries'][0] ?? '', 'Critical failure' ) ) {
    $failures[] = 'get_logs(target=app, trace_id=tr_app_2) filter failed; entries: ' . var_export( $resp['data']['entries'] ?? null, true );
}

// 4. get_logs target='prompts' with JSONL parsing
lva_reset_env();
$p1 = json_encode( [ 'trace_id' => 'tr_p1', 'timestamp' => '2026-09-04 12:00:00', 'endpoint' => 'draft', 'config' => '', 'system_prompt' => 'Sys1', 'user_prompt' => 'User1', 'response' => 'Resp1', 'response_chars' => 5 ] );
$p2 = json_encode( [ 'trace_id' => 'tr_p2', 'timestamp' => '2026-09-04 12:05:00', 'endpoint' => 'chat', 'config' => '', 'system_prompt' => 'Sys2', 'user_prompt' => 'Greek news query', 'response' => 'Resp2', 'response_chars' => 5 ] );
file_put_contents( $prompt_file, $p1 . "\n" . $p2 . "\n" );

$_POST['target'] = 'prompts';
try {
    $handlers->get_logs();
} catch ( RuntimeException $e ) {}
$resp = end( $GLOBALS['JSON_RESPONSES'] );
if ( ! $resp || ! $resp['success'] || ( $resp['data']['target'] ?? '' ) !== 'prompts' ) {
    $failures[] = 'get_logs(target=prompts) failed.';
} else {
    $entries = $resp['data']['entries'] ?? [];
    if ( count( $entries ) !== 2 ) {
        $failures[] = 'get_logs(target=prompts) expected 2 parsed entries; got ' . count( $entries );
    } elseif ( ( $entries[0]['trace_id'] ?? '' ) !== 'tr_p1' || ( $entries[1]['endpoint'] ?? '' ) !== 'chat' ) {
        $failures[] = 'get_logs(target=prompts) parsed entries do not match input data.';
    }
}

// 4b. get_logs target='prompts' with search filter
lva_reset_env();
$_POST['target'] = 'prompts';
$_POST['search'] = 'Greek news';
try {
    $handlers->get_logs();
} catch ( RuntimeException $e ) {}
$resp = end( $GLOBALS['JSON_RESPONSES'] );
if ( count( $resp['data']['entries'] ?? [] ) !== 1 || ( $resp['data']['entries'][0]['trace_id'] ?? '' ) !== 'tr_p2' ) {
    $failures[] = 'get_logs(target=prompts, search=Greek news) filter failed.';
}

// 5. get_logs target='tts' with JSON lines parsing
lva_reset_env();
$tts1 = json_encode( [ 'trace_id' => 'tr_tts1', 'phase' => 'request', 'timestamp' => '2026-09-04 12:10:00', 'data' => [ 'model' => 'Kore', 'prompt_text' => 'Hello voice' ] ] );
$tts2 = json_encode( [ 'trace_id' => 'tr_tts2', 'phase' => 'response', 'timestamp' => '2026-09-04 12:10:01', 'data' => [ 'model' => 'Kore', 'bytes' => 1234 ] ] );
file_put_contents( $tts_file, "[2026-09-04 12:10:00] " . $tts1 . "\n[2026-09-04 12:10:01] " . $tts2 . "\n" );

$_POST['target'] = 'tts';
try {
    $handlers->get_logs();
} catch ( RuntimeException $e ) {}
$resp = end( $GLOBALS['JSON_RESPONSES'] );
if ( ! $resp || ! $resp['success'] || ( $resp['data']['target'] ?? '' ) !== 'tts' ) {
    $failures[] = 'get_logs(target=tts) failed.';
} else {
    $entries = $resp['data']['entries'] ?? [];
    if ( count( $entries ) !== 2 ) {
        $failures[] = 'get_logs(target=tts) expected 2 entries; got ' . count( $entries );
    } elseif ( ( $entries[0]['trace_id'] ?? '' ) !== 'tr_tts1' || ( $entries[1]['phase'] ?? '' ) !== 'response' ) {
        $failures[] = 'get_logs(target=tts) entries did not parse JSON with timestamp prefix correctly.';
    }
}

// 6. clear_logs target='prompts'
lva_reset_env();
$_POST['target'] = 'prompts';
try {
    $handlers->clear_logs();
} catch ( RuntimeException $e ) {}
if ( '' !== trim( (string) file_get_contents( $prompt_file ) ) ) {
    $failures[] = 'clear_logs(target=prompts) failed to clear prompt debug log.';
}
if ( '' === trim( (string) file_get_contents( $tts_file ) ) ) {
    $failures[] = 'clear_logs(target=prompts) inadvertently cleared tts file.';
}

// 6b. clear_logs target='all'
lva_reset_env();
$_POST['target'] = 'all';
try {
    $handlers->clear_logs();
} catch ( RuntimeException $e ) {}
$app_cleared_content = (string) file_get_contents( $app_file );
if ( false !== strpos( $app_cleared_content, 'Log entry 1' ) ) {
    $failures[] = 'clear_logs(target=all) did not wipe prior contents from app log file.';
}
if ( '' !== trim( (string) file_get_contents( $tts_file ) ) ) {
    $failures[] = 'clear_logs(target=all) did not clear tts log file.';
}

// Cleanup
@unlink( $app_file );
@unlink( $prompt_file );
@unlink( $tts_file );
@rmdir( $upload_dir . '/presshub-ai' );
@rmdir( $upload_dir );
PressHub_AI_Logger::set_log_file_path( null );
unset( $GLOBALS['UPLOAD_DIR'] );

if ( ! empty( $failures ) ) {
    echo "LogViewerAjaxTest FAILED:\n";
    foreach ( $failures as $f ) {
        echo "  - {$f}\n";
    }
    exit( 1 );
}

echo "OK\n";
exit( 0 );
