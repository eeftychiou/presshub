<?php
/**
 * PressHub AI Trace Correlation.
 *
 * Provides execution-scoped trace IDs to correlate structured logs,
 * prompt logs, TTS logs, and token usage across pipeline stages.
 *
 * @package PressHub_AI_Editor
 * @since 2.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Trace {

    /** @var string|null Active trace identifier */
    private static ?string $current_trace_id = null;

    /**
     * Generate a new unique trace ID.
     *
     * @return string Unique trace ID formatted as tr_<hex>.
     */
    public static function generate_id(): string {
        try {
            return 'tr_' . bin2hex( random_bytes( 8 ) );
        } catch ( \Throwable $e ) {
            return 'tr_' . substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 16 );
        }
    }

    /**
     * Get the active trace ID, if one has been set.
     *
     * @return string|null Current trace ID or null if none active.
     */
    public static function get_current_trace_id(): ?string {
        return self::$current_trace_id;
    }

    /**
     * Set or override the active trace ID.
     *
     * @param string|null $trace_id Trace ID to set, or null to clear.
     */
    public static function set_current_trace_id( ?string $trace_id ): void {
        self::$current_trace_id = ! empty( $trace_id ) ? sanitize_text_field( $trace_id ) : null;
    }

    /**
     * Get the active trace ID, or create and set a new one if none exists.
     *
     * @return string Active or newly generated trace ID.
     */
    public static function get_or_create_trace_id(): string {
        if ( empty( self::$current_trace_id ) ) {
            self::$current_trace_id = self::generate_id();
        }
        return self::$current_trace_id;
    }

    /**
     * Clear the current trace ID.
     */
    public static function clear_current_trace_id(): void {
        self::$current_trace_id = null;
    }
}
