<?php
/**
 * PressHub Social Logger
 *
 * Provides structured logging and secret masking for the social auto-post plugin.
 * Delegates to PressHub_AI_Logger if available, while maintaining an in-memory
 * ring buffer for testing, assertions, and live diagnostics.
 *
 * @package PressHub_Social_AutoPost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Logger {

    /**
     * Maximum number of log records retained in memory.
     */
    const MAX_RECENT_LOGS = 100;

    /**
     * In-memory ring buffer for recent logs.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $recent_logs = [];

    /**
     * Mask an API key or sensitive secret for display and logging.
     *
     * If empty, returns ''.
     * If length <= 8, returns '••••••••'.
     * Otherwise returns the first 4 characters, '••••••••', and the last 4 characters.
     *
     * @param string $secret Secret string to mask.
     * @return string Masked string.
     */
    public static function mask_secret( string $secret ): string {
        $secret = trim( $secret );
        if ( '' === $secret ) {
            return '';
        }

        $length = mb_strlen( $secret, 'UTF-8' );
        if ( $length <= 8 ) {
            return '••••••••';
        }

        $prefix = mb_substr( $secret, 0, 4, 'UTF-8' );
        $suffix = mb_substr( $secret, -4, null, 'UTF-8' );

        return $prefix . '••••••••' . $suffix;
    }

    /**
     * Log a message at the specified level.
     *
     * @param string $level Log level ('DEBUG', 'INFO', 'WARNING', 'ERROR').
     * @param string $message Log message.
     * @param array  $context Optional structured context.
     * @return void
     */
    public static function log( string $level, string $message, array $context = [] ): void {
        $normalized_level = strtoupper( trim( $level ) );

        // Maintain in-memory ring buffer.
        self::$recent_logs[] = [
            'timestamp' => time(),
            'level'     => $normalized_level,
            'message'   => $message,
            'context'   => $context,
        ];

        if ( count( self::$recent_logs ) > self::MAX_RECENT_LOGS ) {
            array_shift( self::$recent_logs );
        }

        // Delegate to PressHub_AI_Logger if loaded.
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            $prefixed_message = '[Social AutoPost] ' . $message;
            switch ( $normalized_level ) {
                case 'DEBUG':
                    PressHub_AI_Logger::debug( $prefixed_message, $context );
                    break;
                case 'INFO':
                    PressHub_AI_Logger::info( $prefixed_message, $context );
                    break;
                case 'WARNING':
                    PressHub_AI_Logger::warning( $prefixed_message, $context );
                    break;
                case 'ERROR':
                    PressHub_AI_Logger::error( $prefixed_message, $context );
                    break;
                default:
                    PressHub_AI_Logger::info( $prefixed_message, $context );
                    break;
            }
        } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $context_str = ! empty( $context ) ? ' ' . ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $context ) : json_encode( $context ) ) : '';
            error_log( sprintf( '[Social AutoPost] [%s] %s%s', $normalized_level, $message, $context_str ) );
        }
    }

    /**
     * Log a message at DEBUG level.
     *
     * @param string $message Log message.
     * @param array  $context Optional structured context.
     * @return void
     */
    public static function debug( string $message, array $context = [] ): void {
        self::log( 'DEBUG', $message, $context );
    }

    /**
     * Log a message at INFO level.
     *
     * @param string $message Log message.
     * @param array  $context Optional structured context.
     * @return void
     */
    public static function info( string $message, array $context = [] ): void {
        self::log( 'INFO', $message, $context );
    }

    /**
     * Log a message at WARNING level.
     *
     * @param string $message Log message.
     * @param array  $context Optional structured context.
     * @return void
     */
    public static function warning( string $message, array $context = [] ): void {
        self::log( 'WARNING', $message, $context );
    }

    /**
     * Log a message at ERROR level.
     *
     * @param string $message Log message.
     * @param array  $context Optional structured context.
     * @return void
     */
    public static function error( string $message, array $context = [] ): void {
        self::log( 'ERROR', $message, $context );
    }

    /**
     * Record an audit event into PressHub_AI_Audit_Logger if available.
     *
     * @param string $event_type Event identifier (e.g. 'social_job_enqueued', 'social_job_published').
     * @param string $entity_type Entity type (e.g. 'social_job', 'social_settings').
     * @param int|string $entity_id Entity identifier.
     * @param string $summary Human-readable summary of the event.
     * @param array $changes Masked changes or details payload.
     * @return void
     */
    public static function audit( string $event_type, string $entity_type, $entity_id, string $summary, array $changes = [] ): void {
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
            PressHub_AI_Audit_Logger::log(
                $event_type,
                $entity_type,
                (string) $entity_id,
                $summary,
                $changes,
                $user_id
            );
        }
    }

    /**
     * Retrieve recent logs from in-memory ring buffer.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_logs(): array {
        return self::$recent_logs;
    }

    /**
     * Clear in-memory ring buffer.
     *
     * @return void
     */
    public static function clear_recent_logs(): void {
        self::$recent_logs = [];
    }
}
