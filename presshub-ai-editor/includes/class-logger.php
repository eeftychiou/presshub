<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PressHub AI Logger
 *
 * Provides structured multi-level logging (DEBUG, INFO, WARNING, ERROR)
 * with timestamp, level tag, optional context metadata, file rotation,
 * and integration with error_log and admin viewer.
 */
class PressHub_AI_Logger {

    const LEVEL_DEBUG   = 100;
    const LEVEL_INFO    = 200;
    const LEVEL_WARNING = 300;
    const LEVEL_ERROR   = 400;
    const LEVEL_OFF     = 999;

    const LEVELS = [
        'DEBUG'   => self::LEVEL_DEBUG,
        'INFO'    => self::LEVEL_INFO,
        'WARNING' => self::LEVEL_WARNING,
        'ERROR'   => self::LEVEL_ERROR,
        'OFF'     => self::LEVEL_OFF,
    ];

    /** @var string|null Custom file path for tests/overrides */
    private static ?string $custom_log_file = null;

    /**
     * Set custom log file path (mainly for unit tests).
     */
    public static function set_log_file_path( ?string $path ): void {
        self::$custom_log_file = $path;
    }

    /**
     * Get absolute path to the log file.
     */
    public static function get_log_file_path(): string {
        if ( null !== self::$custom_log_file ) {
            return self::$custom_log_file;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'presshub-ai';
        if ( ! is_dir( $base_dir ) ) {
            wp_mkdir_p( $base_dir );
        }
        return $base_dir . '/presshub-debug.log';
    }

    /**
     * Get current configured log level threshold.
     */
    public static function get_configured_level(): string {
        $level = strtoupper( (string) get_option( 'presshub_ai_log_level', '' ) );
        if ( ! isset( self::LEVELS[ $level ] ) ) {
            // Default: if WP_DEBUG is on or debug prompts is on, use DEBUG, else INFO.
            if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || '1' === (string) get_option( 'presshub_ai_debug_prompts', '0' ) ) {
                return 'DEBUG';
            }
            return 'INFO';
        }
        return $level;
    }

    /**
     * Log a message at DEBUG level.
     */
    public static function debug( string $message, array $context = [] ): void {
        self::log( 'DEBUG', $message, $context );
    }

    /**
     * Log a message at INFO level.
     */
    public static function info( string $message, array $context = [] ): void {
        self::log( 'INFO', $message, $context );
    }

    /**
     * Log a message at WARNING level.
     */
    public static function warning( string $message, array $context = [] ): void {
        self::log( 'WARNING', $message, $context );
    }

    /**
     * Log a message at ERROR level.
     */
    public static function error( string $message, array $context = [] ): void {
        self::log( 'ERROR', $message, $context );
    }

    /**
     * Core log handler.
     */
    public static function log( string $level, string $message, array $context = [] ): void {
        $level          = strtoupper( $level );
        $level_int      = self::LEVELS[ $level ] ?? self::LEVEL_INFO;
        $configured_int = self::LEVELS[ self::get_configured_level() ] ?? self::LEVEL_INFO;

        if ( $level_int < $configured_int ) {
            return;
        }

        $time      = gmdate( 'Y-m-d H:i:s' );
        $ctx_str   = ! empty( $context ) ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
        $formatted = sprintf( "[%s UTC] [%s] %s%s\n", $time, $level, $message, $ctx_str );

        // Write to log file
        $file = self::get_log_file_path();
        $dir  = dirname( $file );
        if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
            wp_mkdir_p( $dir );
        }

        // Auto-rotate if > 5MB
        if ( file_exists( $file ) && filesize( $file ) > 5 * 1024 * 1024 ) {
            $rotated = $file . '.' . gmdate( 'Ymd_His' ) . '.old';
            @rename( $file, $rotated );
        }

        @file_put_contents( $file, $formatted, FILE_APPEND | LOCK_EX );

        // Also output to PHP error_log if level is WARNING or ERROR
        if ( $level_int >= self::LEVEL_WARNING ) {
            error_log( sprintf( 'PressHub AI [%s]: %s%s', $level, $message, $ctx_str ) );
        }
    }

    /**
     * Retrieve recent log lines for dashboard viewer.
     */
    public static function get_recent_logs( int $max_lines = 100 ): string {
        $file = self::get_log_file_path();
        if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
            return '';
        }

        $lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( false === $lines || empty( $lines ) ) {
            return '';
        }

        $slice = array_slice( $lines, -$max_lines );
        return implode( "\n", $slice );
    }

    /**
     * Clear log file.
     */
    public static function clear_log(): bool {
        $file = self::get_log_file_path();
        if ( file_exists( $file ) ) {
            return @file_put_contents( $file, '' ) !== false;
        }
        return true;
    }

    /**
     * Log a source harvesting diagnostic event.
     */
    public static function log_harvest_source( string $source_url, string $method, int $code, int $latency_ms, int $links_count, ?string $failure = null ): void {
        $status_label = ( $code >= 200 && $code < 300 && empty( $failure ) ) ? 'OK' : 'FAIL';
        $msg = sprintf(
            '[HARVEST_SOURCE] %s | [%s] Code: %d | Latency: %dms | Articles/Links: %d | Status: %s%s',
            $source_url,
            $method,
            $code,
            $latency_ms,
            $links_count,
            $status_label,
            $failure ? ' (' . $failure . ')' : ''
        );
        $context = [
            'source'     => $source_url,
            'method'     => $method,
            'code'       => $code,
            'latency_ms' => $latency_ms,
            'links'      => $links_count,
            'failure'    => $failure,
        ];
        if ( ! empty( $failure ) || $code >= 400 ) {
            self::warning( $msg, $context );
        } else {
            self::info( $msg, $context );
        }
    }

    /**
     * Log an individual article extraction diagnostic event.
     */
    public static function log_harvest_article( string $url, string $title, string $tier, int $char_count, bool $success, ?string $failure = null ): void {
        $msg = sprintf(
            '[HARVEST_ARTICLE] %s | Tier: %s | Chars: %d | Status: %s | Title: %s%s',
            $url,
            $tier,
            $char_count,
            $success ? 'SUCCESS' : 'FAIL',
            mb_substr( $title, 0, 60 ),
            $failure ? ' (' . $failure . ')' : ''
        );
        $context = [
            'url'        => $url,
            'tier'       => $tier,
            'char_count' => $char_count,
            'success'    => $success,
            'failure'    => $failure,
        ];
        if ( $success ) {
            self::debug( $msg, $context );
        } else {
            self::warning( $msg, $context );
        }
    }
}
