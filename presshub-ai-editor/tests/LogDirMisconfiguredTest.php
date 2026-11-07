<?php
/**
 * LogDirMisconfiguredTest - Issue #138 (PRESSHUB_AI_LOG_DIR misconfiguration).
 *
 * Runs in its own process because the constant cannot be redefined. The
 * directory is placed beneath a regular file so it can never be created.
 *
 * Verifies: get_dir()/get_file() return WP_Error, nothing is written to uploads
 * (no fallback), exactly one error_log line per request, the logger and the
 * prompt/TTS writers skip the file write without fatals, and the do_action
 * hooks still fire.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

$failures = 0;
$checks   = 0;
function ldm_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$root = sys_get_temp_dir() . '/presshub-logdir-bad-' . getmypid();
@mkdir( $root, 0777, true );
$blocker = $root . '/blocker-file';
file_put_contents( $blocker, 'not a directory' );
$uploads = $root . '/uploads';
@mkdir( $uploads, 0777, true );
$GLOBALS['UPLOAD_DIR'] = $uploads;

define( 'PRESSHUB_AI_LOG_DIR', $blocker . '/logs' );

$php_log = $root . '/php-error.log';
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $php_log );

require_once __DIR__ . '/../includes/class-trace.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-token-logger.php';
require_once __DIR__ . '/../includes/class-api-client.php';

$dir = PressHub_AI_Log_Paths::get_dir();
ldm_check( 'get_dir returns WP_Error', is_wp_error( $dir ) );
ldm_check( 'error code is presshub_ai_log_dir_unwritable', is_wp_error( $dir ) && 'presshub_ai_log_dir_unwritable' === $dir->get_error_code() );
ldm_check( 'error message names the constant and path', is_wp_error( $dir ) && false !== strpos( $dir->get_error_message(), 'PRESSHUB_AI_LOG_DIR' ) && false !== strpos( $dir->get_error_message(), 'blocker-file' ) );
foreach ( PressHub_AI_Log_Paths::CHANNELS as $channel ) {
    ldm_check( "get_file({$channel}) returns WP_Error", is_wp_error( PressHub_AI_Log_Paths::get_file( $channel ) ) );
    ldm_check( "get_rotated_files({$channel}) is empty", [] === PressHub_AI_Log_Paths::get_rotated_files( $channel ) );
}

// Logging paths must not fatal and must not fall back to uploads.
$GLOBALS['OPTIONS_STORE']['presshub_ai_debug_prompts'] = '1';
$GLOBALS['DO_ACTION_LOG'] = [];
PressHub_AI_Logger::error( 'something failed' );
presshub_ai_log_prompts( 'draft', 'SYS', 'USER', 'RESP' );
PressHub_AI_API_Client::write_tts_payload_log( PressHub_AI_API_Client::build_tts_payload_log_entry( 'request', [ 'text' => 'Hello' ] ) );
ldm_check( 'logger clear_log tolerates the error', true === PressHub_AI_Logger::clear_log() );
ldm_check( 'get_recent_logs is empty', '' === PressHub_AI_Logger::get_recent_logs() );

$hooks = array_column( $GLOBALS['DO_ACTION_LOG'], 'hook' );
ldm_check( 'prompt log hook still fires', in_array( 'presshub_ai_prompt_log', $hooks, true ) );
ldm_check( 'tts payload hook still fires', in_array( 'presshub_ai_tts_payload_log', $hooks, true ) );

$written = [];
$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
    $written[] = $f->getPathname();
}
ldm_check( 'nothing was written to uploads', [] === $written );
ldm_check( 'no presshub-ai folder was created in uploads', ! is_dir( $uploads . '/presshub-ai' ) );

// Exactly one raw error_log line from the helper per request. The logger's own
// WARNING+ mirror for 'something failed' is a separate line.
$lines        = file_exists( $php_log ) ? file( $php_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : [];
$helper_lines = array_filter( $lines, fn ( $l ) => false !== strpos( $l, 'PRESSHUB_AI_LOG_DIR' ) );
ldm_check( 'exactly one PRESSHUB_AI_LOG_DIR error_log line per request', 1 === count( $helper_lines ) );
ldm_check( 'logger still mirrors ERROR to error_log', 1 === count( array_filter( $lines, fn ( $l ) => false !== strpos( $l, 'something failed' ) ) ) );

@unlink( $php_log );
@unlink( $blocker );
@rmdir( $uploads );
@rmdir( $root );

if ( $failures > 0 ) {
    fwrite( STDERR, "LogDirMisconfiguredTest: {$failures} failure(s) of {$checks} checks\n" );
    exit( 1 );
}
echo "LogDirMisconfiguredTest: OK ({$checks} checks)\n";
exit( 0 );
