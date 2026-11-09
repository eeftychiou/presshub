<?php
/**
 * PressHub_AI_TTS_Call_Recorder - per-call TTS details for generation records (Issue #140).
 *
 * Every TTS API call made while a podcast is synthesized is appended to the
 * parent record's `tts_calls_json` column (the podcast_script record). Entries
 * carry the phase, model, fallback model, voice and speaker mapping, the length
 * and SHA-256 of the spoken text (never the text itself: the script is already in
 * the record), the request body with base64 audio stripped and keys masked, the
 * HTTP status, error, duration and audio length. Stitched and topic metrics are
 * stored as summary entries.
 *
 * Stored shape (decoded as `tts_calls` by PressHub_AI_Generation_Recorder::get()):
 *   { "truncated": bool, "dropped": int, "total": int, "entries": [ ... ] }
 *
 * Parent record: synthesize_podcast() opens a session with begin_session() around
 * the synthesis and closes it in a finally block. Without a parent record, or for a
 * TTS call made outside any session (settings test button, connection test), the
 * calls land in a record of kind `tts_test`, created on the first call.
 *
 * Recording never breaks synthesis: every public method swallows errors after
 * logging them through PressHub_AI_Logger.
 *
 * @package PressHub_AI_Editor
 * @since 2.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_TTS_Call_Recorder {

    /**
     * Maximum stored entries per record (storage safety limit set by Issue #140,
     * not a user-tunable operational knob). Past it, entries are dropped, the
     * envelope is marked truncated and one WARNING is logged.
     */
    const MAX_ENTRIES = 500;

    /** Replacement for base64 audio payloads. */
    const BASE64_PLACEHOLDER = '[base64 omitted: %d chars]';

    /** @var bool Whether a session is open. */
    private static $active = false;

    /** @var int Parent record ID of the open session (0 = create a tts_test record on first call). */
    private static $record_id = 0;

    /** @var array Config stored on a lazily created tts_test record. */
    private static $context = [];

    /** @var array Envelope mirrored to the DB for the open session. */
    private static $envelope = [];

    /** @var bool Whether the truncation WARNING was already logged for this session. */
    private static $warned = false;

    /** @var bool Whether the current record is a tts_test record created here (its status follows the last call). */
    private static $owned = false;

    // ---------------------------------------------------------------------
    // Session (current TTS record)
    // ---------------------------------------------------------------------

    /**
     * Open a session. Calls made until end_session() are appended to $record_id.
     * A fresh synthesis replaces the previous run's calls on that record.
     *
     * @param int   $record_id Parent record ID (podcast_script), or 0 for none.
     * @param array $context   Stored as config on a tts_test record when no parent exists.
     */
    public static function begin_session( int $record_id, array $context = [] ): void {
        self::$active    = true;
        self::$record_id = max( 0, $record_id );
        self::$context   = $context;
        self::$envelope  = self::empty_envelope();
        self::$warned    = false;
        self::$owned     = false;
    }

    /** Close the session (call from a finally block). */
    public static function end_session(): void {
        self::$active    = false;
        self::$record_id = 0;
        self::$context   = [];
        self::$envelope  = [];
        self::$warned    = false;
        self::$owned     = false;
    }

    /** Whether a session is open. */
    public static function is_active(): bool {
        return self::$active;
    }

    /** Parent record ID of the open session (0 when none, or tts_test not created yet). */
    public static function current_record_id(): int {
        return self::$active ? self::$record_id : 0;
    }

    // ---------------------------------------------------------------------
    // Recording
    // ---------------------------------------------------------------------

    /**
     * Record one TTS API call.
     *
     * @param array $call {
     *     @type string      $phase          'request' (default).
     *     @type string      $engine         gemini | google_cloud.
     *     @type string      $model          Model (or voice model for Google Cloud) used.
     *     @type string      $fallback_model Substituted model/voice, empty when none.
     *     @type string      $voice          Voice persona (single speaker).
     *     @type array|null  $speakers       Speaker to voice mapping (multi speaker).
     *     @type string      $text           Spoken text; only its length and SHA-256 are stored.
     *     @type array       $request        Request body (audio stripped, keys masked here).
     *     @type int         $http_status    HTTP status, 0 for transport errors.
     *     @type string      $error          Error message, empty on success.
     *     @type int         $duration_ms    Request duration.
     *     @type int         $audio_bytes    Decoded audio length in bytes.
     *     @type int         $audio_seconds  Audio length in seconds when known.
     *     @type int         $retry_attempt  0 for the first try, 1+ for retries.
     *     @type int         $wait_ms        Milliseconds waited before this request (pacing + retry delay).
     *     @type string      $wait_source    Retry delay source: retry_info | retry_after | message | backoff | ''.
     *     @type string      $endpoint       Endpoint URL (key masked here).
     *     @type array       $settings       Style, speed, pitch, payload mode.
     * }
     */
    public static function log_call( array $call ): void {
        try {
            self::append_entry( self::build_entry( $call ) );
        } catch ( \Throwable $t ) {
            self::log_warning( 'TTS call recording failed: ' . $t->getMessage() );
        }
    }

    /**
     * Record a cache hit (turn served from disk). Only stored inside a session.
     *
     * @param array $call Same keys as log_call(); phase is forced to cache_hit.
     */
    public static function log_cache_hit( array $call ): void {
        if ( ! self::$active ) {
            return;
        }
        $call['phase'] = 'cache_hit';
        self::log_call( $call );
    }

    /**
     * Record a topic or stitched summary entry. Only stored inside a session.
     *
     * @param string $phase   topic | stitched.
     * @param array  $summary Metrics (voices, counts, durations, bytes).
     */
    public static function log_summary( string $phase, array $summary ): void {
        if ( ! self::$active ) {
            return;
        }
        try {
            $entry = [
                'phase'   => $phase,
                'at'      => gmdate( 'Y-m-d H:i:s' ),
                'summary' => self::mask( $summary ),
            ];
            self::append_entry( $entry );
        } catch ( \Throwable $t ) {
            self::log_warning( 'TTS summary recording failed: ' . $t->getMessage() );
        }
    }

    /**
     * Build one stored call entry (pure function, also used by tests).
     *
     * @param array $call See log_call().
     * @return array
     */
    public static function build_entry( array $call ): array {
        $text = isset( $call['text'] ) ? (string) $call['text'] : '';
        $entry = [
            'phase'          => (string) ( $call['phase'] ?? 'request' ),
            'at'             => gmdate( 'Y-m-d H:i:s' ),
            'engine'         => (string) ( $call['engine'] ?? '' ),
            'model'          => (string) ( $call['model'] ?? '' ),
            'fallback_model' => (string) ( $call['fallback_model'] ?? '' ),
            'voice'          => (string) ( $call['voice'] ?? '' ),
            'speakers'       => isset( $call['speakers'] ) && is_array( $call['speakers'] ) ? $call['speakers'] : null,
            'text_chars'     => function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ),
            'text_sha256'    => '' !== $text ? hash( 'sha256', $text ) : '',
            'retry_attempt'  => (int) ( $call['retry_attempt'] ?? 0 ),
            'http_status'    => (int) ( $call['http_status'] ?? 0 ),
            'error'          => self::mask_text( (string) ( $call['error'] ?? '' ) ),
            'duration_ms'    => max( 0, (int) ( $call['duration_ms'] ?? 0 ) ),
            'audio_bytes'    => max( 0, (int) ( $call['audio_bytes'] ?? 0 ) ),
            'audio_seconds'  => isset( $call['audio_seconds'] ) ? round( (float) $call['audio_seconds'], 2 ) : null,
            // Issue #153 — time waited before this request (pacing + retry delay) and why.
            'wait_ms'        => max( 0, (int) ( $call['wait_ms'] ?? 0 ) ),
            'wait_source'    => (string) ( $call['wait_source'] ?? '' ),
        ];
        if ( isset( $call['endpoint'] ) && '' !== (string) $call['endpoint'] ) {
            $entry['endpoint'] = self::mask_text( PressHub_AI_API_Client::mask_api_key_in_url( (string) $call['endpoint'] ) );
        }
        if ( isset( $call['settings'] ) && is_array( $call['settings'] ) ) {
            $entry['settings'] = self::mask( $call['settings'] );
        }
        if ( isset( $call['request'] ) && is_array( $call['request'] ) ) {
            $entry['request'] = self::sanitize_request( $call['request'] );
        }
        return $entry;
    }

    /**
     * Strip base64 audio and spoken text from a request/response body and mask
     * keys. Spoken text is replaced by its length because the script is already
     * stored on the record; style, voice and speaker configuration stay visible.
     *
     * @param array $body Request body.
     * @return array
     */
    public static function sanitize_request( array $body ): array {
        return self::mask( self::strip_payload( $body ) );
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private static function empty_envelope(): array {
        return [ 'truncated' => false, 'dropped' => 0, 'total' => 0, 'entries' => [] ];
    }

    /**
     * Add an entry to the session envelope and persist it. Without a session the
     * entry goes to a one-off tts_test record.
     */
    private static function append_entry( array $entry ): void {
        $standalone = ! self::$active;
        if ( $standalone ) {
            self::$envelope = self::empty_envelope();
            self::$warned   = false;
            self::$owned    = false;
        }

        self::$envelope['total'] = (int) self::$envelope['total'] + 1;
        $entry['seq']            = self::$envelope['total'];

        if ( count( self::$envelope['entries'] ) >= self::MAX_ENTRIES ) {
            self::$envelope['truncated'] = true;
            self::$envelope['dropped']   = (int) self::$envelope['dropped'] + 1;
            if ( ! self::$warned ) {
                self::$warned = true;
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning(
                        sprintf( 'TTS calls for generation record #%d exceeded %d entries; further entries are dropped and the record is marked truncated', self::$record_id, self::MAX_ENTRIES ),
                        [ 'record_id' => self::$record_id, 'max_entries' => self::MAX_ENTRIES ]
                    );
                }
            }
        } else {
            self::$envelope['entries'][] = $entry;
        }

        if ( self::$record_id <= 0 ) {
            self::$record_id = self::create_test_record( $entry );
            self::$owned     = self::$record_id > 0;
        }
        if ( self::$record_id > 0 ) {
            $fields = [ 'tts_calls' => self::$envelope ];
            if ( self::$owned && isset( $entry['error'] ) ) {
                // A tts_test record is as healthy as its latest call.
                $fields['status'] = '' === (string) $entry['error'] ? 'success' : 'error';
            }
            PressHub_AI_Generation_Recorder::update_record( self::$record_id, $fields );
        }

        if ( $standalone ) {
            self::$record_id = 0;
            self::$envelope  = [];
            self::$owned     = false;
        }
    }

    /** Create the tts_test record that holds calls without a parent record. */
    private static function create_test_record( array $first_entry ): int {
        $config = array_merge(
            [
                'module'   => 'tts',
                'action'   => 'tts_test',
                'engine'   => (string) ( $first_entry['engine'] ?? '' ),
                'model'    => (string) ( $first_entry['model'] ?? '' ),
                'user_id'  => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            ],
            self::$context
        );
        $id = PressHub_AI_Generation_Recorder::record( [
            'kind'   => 'tts_test',
            'status' => ( '' === (string) ( $first_entry['error'] ?? '' ) ) ? 'success' : 'error',
            'config' => $config,
        ] );
        return (int) $id;
    }

    /** Recursively replace base64 audio and spoken text. */
    private static function strip_payload( $value, string $key = '', bool $in_spoken = false ) {
        if ( is_array( $value ) ) {
            $out = [];
            foreach ( $value as $k => $v ) {
                $k_str     = (string) $k;
                $out[ $k ] = self::strip_payload( $v, $k_str, $in_spoken || 'contents' === $k_str || 'input' === $k_str );
            }
            return $out;
        }
        if ( ! is_string( $value ) ) {
            return $value;
        }
        // Spoken text (Gemini contents[].parts[].text, Google Cloud input.text): length only.
        // systemInstruction and style text are configuration and stay visible.
        if ( 'text' === $key && $in_spoken ) {
            return sprintf( '[text omitted: %d chars]', function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value ) );
        }
        // Known audio fields and any long base64-looking blob.
        if ( in_array( $key, [ 'data', 'audioContent', 'bytesBase64Encoded', 'b64_json' ], true )
            || ( strlen( $value ) >= 256 && 1 === preg_match( '#^[A-Za-z0-9+/=\r\n]+$#', $value ) ) ) {
            return sprintf( self::BASE64_PLACEHOLDER, strlen( $value ) );
        }
        return $value;
    }

    private static function mask( array $data ): array {
        if ( class_exists( 'PressHub_AI_Secret_Masker' ) ) {
            $masked = PressHub_AI_Secret_Masker::mask_array( $data );
            return is_array( $masked ) ? $masked : $data;
        }
        return $data;
    }

    private static function mask_text( string $text ): string {
        return class_exists( 'PressHub_AI_Secret_Masker' ) ? PressHub_AI_Secret_Masker::mask_text( $text ) : $text;
    }

    private static function log_warning( string $message ): void {
        try {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( $message, [ 'record_id' => self::$record_id ] );
            }
        } catch ( \Throwable $t ) {
            // Logging must never break synthesis.
        }
    }

    /**
     * Decoded byte length of a base64 string without decoding it.
     *
     * @param string $b64 Base64 text.
     * @return int
     */
    public static function base64_decoded_length( string $b64 ): int {
        $len = strlen( $b64 );
        if ( 0 === $len ) {
            return 0;
        }
        $padding = 0;
        if ( '=' === substr( $b64, -1 ) ) {
            $padding++;
            if ( $len > 1 && '=' === substr( $b64, -2, 1 ) ) {
                $padding++;
            }
        }
        return max( 0, (int) floor( $len * 3 / 4 ) - $padding );
    }
}
