<?php
/**
 * Per-user rate limiter for AI-costly PressHub AJAX actions.
 *
 * Opt-in by design: when the global 'presshub_ai_rate_limit_enabled' option
 * is falsy, check() is a pass-through so existing behaviour is unaffected.
 *
 * State is stored in a single transient whose value is the running counter
 * and whose expiry is the rolling window. The expiry is consulted on every
 * check; once elapsed the counter resets naturally.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Rate_Limiter {

    /**
     * Whether the rate limiter is currently enabled.
     *
     * Truthy values ('1', 1, true) enable it; anything else (including
     * the option being absent entirely) leaves the limiter as a no-op.
     */
    public function is_enabled(): bool {
        return ! empty( get_option( 'presshub_ai_rate_limit_enabled' ) );
    }

    /**
     * Read the configured per-hour limit, clamped to a sane minimum.
     */
    public function configured_limit(): int {
        $limit = (int) get_option( 'presshub_ai_rate_limit_per_hour', 30 );
        return max( 1, $limit );
    }

    /**
     * Check whether a request under $key is allowed right now.
     *
     * @param string $key            The rate-limit bucket key (caller
     *                               usually passes 'presshub_ai_rl_' . user id).
     * @param int    $limit          Max requests allowed in the window.
     * @param int    $window_seconds Sliding window length, in seconds.
     *
     * @return bool|WP_Error  true when allowed, WP_Error with a human
     *                        message (including remaining wait time)
     *                        when blocked. When the feature is disabled
     *                        this method always returns true.
     */
    public function check( string $key, int $limit, int $window_seconds ) {
        if ( ! $this->is_enabled() ) {
            return true;
        }

        $entry  = $this->read_state( $key );
        $count  = (int) ( $entry['count'] ?? 0 );
        $expiry = (int) ( $entry['expires_at'] ?? 0 );

        // Expired window — treat as fresh.
        if ( $expiry > 0 && $expiry <= $this->now() ) {
            $count  = 0;
            $expiry = 0;
        }

        if ( $count >= $limit ) {
            $remaining = max( 1, $expiry - $this->now() );
            return new WP_Error(
                'presshub_ai_rate_limited',
                sprintf(
                    /* translators: 1: max requests, 2: seconds until reset */
                    __( 'Rate limit reached (%1$d requests). Try again in %2$d seconds.', 'presshub-ai-editor' ),
                    $limit,
                    $remaining
                )
            );
        }

        return true;
    }

    /**
     * Record that a request under $key actually happened.
     *
     * If the bucket is already at the configured limit we do NOT inflate
     * the counter further (so retries against a blocked state do not
     * push the user further from being able to make requests). The window
     * expiry is also left untouched in that case so the timer is honest.
     *
     * @param string $key The rate-limit bucket key.
     */
    public function record( string $key ): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $limit = $this->configured_limit();

        $entry  = $this->read_state( $key );
        $count  = (int) ( $entry['count'] ?? 0 );
        $expiry = (int) ( $entry['expires_at'] ?? 0 );

        // Expired window — start a fresh one.
        if ( $expiry > 0 && $expiry <= $this->now() ) {
            $count  = 0;
            $expiry = 0;
        }

        // Already at the cap — leave state alone so the user isn't
        // punished extra by retries and the timer keeps counting down.
        if ( $count >= $limit ) {
            return;
        }

        $count++;

        // First request in a fresh window — anchor the expiry AND
        // remember the window length so subsequent records within the
        // same window and matching check() calls stay in sync.
        $window = (int) ( $entry['window'] ?? 0 );
        if ( 0 === $expiry ) {
            $window = $this->window_seconds_for_key( $key );
            $expiry = $this->now() + $window;
        }

        $this->write_state( $key, [
            'count'      => $count,
            'expires_at' => $expiry,
            'window'     => $window,
        ] );
    }

    /**
     * Read the raw stored state. Returns a normalised array so callers
     * never have to deal with the transient-missing shape.
     */
    private function read_state( string $key ): array {
        $stored = get_transient( $key );
        if ( ! is_array( $stored ) ) {
            return [ 'count' => 0, 'expires_at' => 0, 'window' => 0 ];
        }
        return [
            'count'      => (int) ( $stored['count'] ?? 0 ),
            'expires_at' => (int) ( $stored['expires_at'] ?? 0 ),
            'window'     => (int) ( $stored['window'] ?? 0 ),
        ];
    }

    /**
     * Write the state. We only persist when we actually have a non-zero
     * expiry to anchor, otherwise the entry is pointless and gets cleaned
     * up by delete_transient.
     */
    private function write_state( string $key, array $state ): void {
        if ( $state['expires_at'] <= 0 ) {
            delete_transient( $key );
            return;
        }
        set_transient( $key, $state, max( 1, $state['expires_at'] - $this->now() ) );
    }

    /**
     * The window length to use when a fresh window is opened.
     *
     * The task spec is record(string $key) — no window argument — so we
     * persist the window length alongside the counter and reuse it on
     * every subsequent record within the same window. The AJAX handlers
     * pass the same window into check(); both stay in sync because they
     * read from the same stored value.
     */
    private function window_seconds_for_key( string $key ): int {
        $existing = (int) ( $this->read_state( $key )['window'] ?? 0 );
        if ( $existing > 0 ) {
            return $existing;
        }
        $stored_window = (int) get_option( 'presshub_ai_rate_limit_window_seconds', 3600 );
        // Safety: the override must be at least 1s and at most 1 day.
        return max( 1, min( 86400, $stored_window ) );
    }

    /**
     * Allow tests to inject a deterministic clock via $GLOBALS['TIME_NOW'].
     */
    protected function now(): int {
        return $GLOBALS['TIME_NOW'] ?? time();
    }
}