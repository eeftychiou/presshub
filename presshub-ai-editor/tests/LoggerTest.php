<?php
/**
 * Test PressHub_AI_Logger
 *
 * Covers:
 *   - Log level thresholds (DEBUG, INFO, WARNING, ERROR, OFF).
 *   - Formatting of log entries with timestamp, level, message, and context JSON.
 *   - File creation and appending in upload directory.
 *   - Retrieving recent log lines and clearing log.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-secret-masker.php';
require_once __DIR__ . '/../includes/class-logger.php';

class LoggerTest {
    public static function run_all(): array {
        $failures = [];

        $tmp_dir = sys_get_temp_dir() . '/presshub-log-test-' . uniqid();
        if ( ! is_dir( $tmp_dir ) ) {
            mkdir( $tmp_dir, 0777, true );
        }
        $log_file = $tmp_dir . '/test-presshub.log';
        PressHub_AI_Logger::set_log_file_path( $log_file );

        // --- Case 1: Writing DEBUG when level is DEBUG ---
        update_option( 'presshub_ai_log_level', 'DEBUG' );
        PressHub_AI_Logger::clear_log();

        PressHub_AI_Logger::debug( 'Debug message', [ 'foo' => 'bar' ] );
        PressHub_AI_Logger::info( 'Info message', [ 'count' => 5 ] );
        PressHub_AI_Logger::warning( 'Warning message' );
        PressHub_AI_Logger::error( 'Error message', [ 'code' => 500 ] );

        $content = PressHub_AI_Logger::get_recent_logs();
        if ( false === strpos( $content, '[DEBUG]' ) || false === strpos( $content, 'Debug message' ) ) {
            $failures[] = 'Case 1: DEBUG level should write debug messages to log; got: ' . $content;
        }
        if ( false === strpos( $content, '[INFO]' ) || false === strpos( $content, 'Info message' ) ) {
            $failures[] = 'Case 1: INFO message missing from log; got: ' . $content;
        }
        if ( false === strpos( $content, '[WARNING]' ) || false === strpos( $content, 'Warning message' ) ) {
            $failures[] = 'Case 1: WARNING message missing from log; got: ' . $content;
        }
        if ( false === strpos( $content, '[ERROR]' ) || false === strpos( $content, 'Error message' ) ) {
            $failures[] = 'Case 1: ERROR message missing from log; got: ' . $content;
        }

        // --- Case 2: Level threshold filtering (WARNING level skips DEBUG and INFO) ---
        PressHub_AI_Logger::clear_log();
        update_option( 'presshub_ai_log_level', 'WARNING' );

        PressHub_AI_Logger::debug( 'Ignored debug message' );
        PressHub_AI_Logger::info( 'Ignored info message' );
        PressHub_AI_Logger::warning( 'Captured warning message' );
        PressHub_AI_Logger::error( 'Captured error message' );

        $content2 = PressHub_AI_Logger::get_recent_logs();
        if ( false !== strpos( $content2, 'Ignored debug message' ) || false !== strpos( $content2, 'Ignored info message' ) ) {
            $failures[] = 'Case 2: WARNING level should filter out DEBUG and INFO messages; got: ' . $content2;
        }
        if ( false === strpos( $content2, 'Captured warning message' ) || false === strpos( $content2, 'Captured error message' ) ) {
            $failures[] = 'Case 2: WARNING and ERROR messages should be captured; got: ' . $content2;
        }

        // --- Case 3: OFF level skips all logging ---
        PressHub_AI_Logger::clear_log();
        update_option( 'presshub_ai_log_level', 'OFF' );

        PressHub_AI_Logger::error( 'Should not be logged' );
        $content3 = PressHub_AI_Logger::get_recent_logs();
        if ( '' !== trim( $content3 ) ) {
            $failures[] = 'Case 3: OFF level should disable all logging; got: ' . $content3;
        }

        // --- Case 4: Clear log functionality ---
        update_option( 'presshub_ai_log_level', 'DEBUG' );
        PressHub_AI_Logger::info( 'Some log line' );
        if ( '' === trim( PressHub_AI_Logger::get_recent_logs() ) ) {
            $failures[] = 'Case 4: Failed to write log before clearing.';
        }
        PressHub_AI_Logger::clear_log();
        if ( '' !== trim( PressHub_AI_Logger::get_recent_logs() ) ) {
            $failures[] = 'Case 4: Clear log should empty log contents.';
        }

        // --- Case 5: secrets never reach the log file in plain text ---
        update_option( 'presshub_ai_log_level', 'DEBUG' );
        PressHub_AI_Logger::clear_log();
        $plain_ctx = 'plainctxsecretvalue123456';
        $plain_msg = 'sk-proj-abcdefghijklmnop123456';
        PressHub_AI_Logger::error( 'Call failed with key ' . $plain_msg, [ 'api_key' => $plain_ctx, 'provider' => 'openai' ] );
        $content5 = PressHub_AI_Logger::get_recent_logs();
        if ( false !== strpos( $content5, $plain_ctx ) ) {
            $failures[] = 'Case 5: context api_key leaked in plain text: ' . $content5;
        }
        if ( false !== strpos( $content5, $plain_msg ) ) {
            $failures[] = 'Case 5: key embedded in message leaked in plain text: ' . $content5;
        }
        if ( false === strpos( $content5, 'openai' ) || false === strpos( $content5, 'Call failed with key' ) ) {
            $failures[] = 'Case 5: non-secret content should be preserved: ' . $content5;
        }

        // --- Case 6: prompt debugging no longer lowers the level (WP_DEBUG off) ---
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            update_option( 'presshub_ai_log_level', '' );
            update_option( 'presshub_ai_debug_prompts', '1' );
            if ( 'INFO' !== PressHub_AI_Logger::get_configured_level() ) {
                $failures[] = 'Case 6: prompt debugging with WP_DEBUG off must yield INFO, got ' . PressHub_AI_Logger::get_configured_level();
            }
            update_option( 'presshub_ai_debug_prompts', '0' );
        }

        // Cleanup
        if ( file_exists( $log_file ) ) {
            unlink( $log_file );
        }
        if ( is_dir( $tmp_dir ) ) {
            rmdir( $tmp_dir );
        }
        PressHub_AI_Logger::set_log_file_path( null );

        return $failures;
    }
}

$failures = LoggerTest::run_all();
if ( empty( $failures ) ) {
    echo "LoggerTest: OK (all checks passed)\n";
    exit( 0 );
} else {
    echo "LoggerTest: FAILED with " . count( $failures ) . " failure(s):\n";
    foreach ( $failures as $f ) {
        echo "  - $f\n";
    }
    exit( 1 );
}
