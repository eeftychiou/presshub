<?php
/**
 * Background music for TikTok shorts: a small library of instrumental tracks per mood,
 * generated once with Google Lyria and reused under every short.
 *
 * - Moods and their music prompts come from `presshub_social_prompt_tiktok_shorts_music`
 *   (one "mood: prompt" line each, seeded from social_tiktok_shorts_music.txt). The shorts
 *   plan picks one mood per story (`mood`, from {moods}), or `none` for no music.
 * - The operator generates the tracks from the settings page ("Generate Music Tracks"):
 *   each AJAX request makes one track (Lyria, model `presshub_social_tiktok_shorts_music_model`)
 *   until every mood has `presshub_social_tiktok_shorts_music_tracks` tracks. The tracks are
 *   media attachments; the library (option LIBRARY_OPTION) maps each mood to its tracks.
 *   A track whose mood prompt or model has changed no longer counts and is replaced.
 * - At claim time pick_track() chooses one of the mood's tracks for the short (the same
 *   one for the same job), and the worker loops it under the voice at
 *   `presshub_social_tiktok_shorts_music_volume`, ducked while someone speaks.
 *
 * Music is an extra, never a reason to fail a short: with no track for the mood the short
 * is rendered without music and a WARNING names the missing tracks.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_TikTok_Music {

    /** Option holding the generated tracks: mood => list of {id, url, hash, model, created}. */
    public const LIBRARY_OPTION = 'presshub_social_tiktok_shorts_music_library';

    /** The mood that means "no music". */
    public const NO_MUSIC = 'none';

    /** Gemini API Interactions endpoint (Lyria music generation). */
    public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    /**
     * Optional Lyria override for tests.
     * Signature: fn( string $prompt, string $model ): string|WP_Error (MP3/WAV bytes)
     *
     * @var callable|null
     */
    private static $generator = null;

    /**
     * Optional upload override for tests.
     * Signature: fn( string $filename, string $bytes, string $title ): array{id:int,url:string}|WP_Error
     *
     * @var callable|null
     */
    private static $uploader = null;

    /** Lyria calls per track when its copyright filter blocks the prompt. */
    public const MAX_ATTEMPTS = 3;

    /** Variations asked for in turn, so tracks of a mood differ and a blocked prompt is reworded. */
    private const VARIATIONS = [
        'Original composition with its own simple melody.',
        'Original composition, a different melody and key from the first version, with a lighter arrangement.',
        'Original composition, sparse and minimal, with a new melody.',
        'Original composition, warmer instruments and a new chord progression.',
        'Original composition, a fresh motif played softly.',
    ];

    /** The prompt sent for track $n (1-based) on retry $attempt: the mood prompt plus a variation. */
    public static function variation_prompt( string $prompt, int $n, int $attempt = 0 ): string {
        $i = ( max( 1, $n ) - 1 + $attempt * 2 ) % count( self::VARIATIONS );
        return rtrim( $prompt ) . ' ' . self::VARIATIONS[ $i ];
    }

    /** Whether Lyria refused the prompt (copyright/recitation or safety filter) rather than failed. */
    public static function is_blocked( WP_Error $error ): bool {
        return 'tiktok_music_no_audio' === $error->get_error_code()
            || (bool) preg_match( '/recitation|copyright|blocked|safety/i', $error->get_error_message() );
    }

    public static function set_generator( ?callable $generator ): void {
        self::$generator = $generator;
    }

    public static function set_uploader( ?callable $uploader ): void {
        self::$uploader = $uploader;
    }

    /**
     * Parse the music prompts setting: one "mood: prompt" line per mood. Blank lines and
     * lines starting with # are skipped; a mood is lower-case letters, digits, - or _.
     *
     * @return array<string,string> mood => prompt, in the setting's order.
     */
    public static function parse_prompts( string $text, bool $allow_none = false ): array {
        $moods = [];
        foreach ( preg_split( '/\R/u', $text ) ?: [] as $line ) {
            $line = trim( $line );
            if ( '' === $line || '#' === $line[0] ) {
                continue;
            }
            if ( preg_match( '/^([a-z0-9_-]+)\s*:\s*(.+)$/iu', $line, $m ) ) {
                $mood = strtolower( $m[1] );
                if ( ( $allow_none || self::NO_MUSIC !== $mood ) && '' !== trim( $m[2] ) ) {
                    $moods[ $mood ] = trim( $m[2] );
                }
            }
        }
        return $moods;
    }

    /** @return array<string,string> mood => music prompt from the settings. */
    public static function prompts(): array {
        return self::parse_prompts( PressHub_Social_Settings_Storage::get_tiktok_shorts_music_prompts() );
    }

    /** @return string[] Moods the plan may choose, `none` last. */
    public static function moods(): array {
        return array_merge( array_keys( self::prompts() ), [ self::NO_MUSIC ] );
    }

    /** The mood used when the plan gives none or one that is not in the settings. */
    public static function default_mood(): string {
        $moods = array_keys( self::prompts() );
        return in_array( 'neutral', $moods, true ) ? 'neutral' : ( $moods[0] ?? self::NO_MUSIC );
    }

    /** Normalise the plan's mood to one of moods(). */
    public static function normalize_mood( $mood ): string {
        $mood = strtolower( trim( is_string( $mood ) ? $mood : '' ) );
        return in_array( $mood, self::moods(), true ) ? $mood : self::default_mood();
    }

    /** Fingerprint of what a track was made from: its prompt and model. */
    public static function track_hash( string $prompt, string $model ): string {
        return substr( hash( 'sha256', $model . '|' . $prompt ), 0, 16 );
    }

    /** @return array<string, array<int, array>> */
    public static function library(): array {
        $lib = function_exists( 'get_option' ) ? get_option( self::LIBRARY_OPTION, [] ) : [];
        return is_array( $lib ) ? $lib : [];
    }

    /**
     * Tracks of a mood that match its current prompt and model.
     *
     * @return array<int, array{id:int,url:string,hash:string}>
     */
    public static function tracks( string $mood ): array {
        $prompt = self::prompts()[ $mood ] ?? null;
        if ( null === $prompt ) {
            return [];
        }
        $hash = self::track_hash( $prompt, PressHub_Social_Settings_Storage::get_tiktok_shorts_music_model() );
        return array_values( array_filter(
            (array) ( self::library()[ $mood ] ?? [] ),
            fn( $t ) => is_array( $t ) && ( $t['hash'] ?? '' ) === $hash && '' !== (string) ( $t['url'] ?? '' )
        ) );
    }

    /**
     * Library status per mood, for the settings page.
     *
     * @return array<string, array{have:int,need:int,tracks:array}>
     */
    public static function status(): array {
        $need = PressHub_Social_Settings_Storage::get_tiktok_shorts_music_tracks();
        $out  = [];
        foreach ( array_keys( self::prompts() ) as $mood ) {
            $tracks       = self::tracks( $mood );
            $out[ $mood ] = [ 'have' => min( count( $tracks ), $need ), 'need' => $need, 'tracks' => array_slice( $tracks, 0, $need ) ];
        }
        return $out;
    }

    /** Tracks still to generate across all moods. */
    public static function missing_count(): int {
        $n = 0;
        foreach ( self::status() as $s ) {
            $n += max( 0, $s['need'] - $s['have'] );
        }
        return $n;
    }

    /**
     * Generate one missing track (the first mood short of tracks) and add it to the library.
     * Tracks made from an older prompt or model are dropped from that mood at the same time.
     *
     * @return array{mood:string,track:array,remaining:int}|array{mood:string,remaining:int}|WP_Error
     *         `mood` is '' when nothing was missing.
     */
    public static function generate_next() {
        // One generation at a time, so two admins never overwrite each other's new track.
        $lock = 'presshub_social_music_generating';
        if ( function_exists( 'get_transient' ) && get_transient( $lock ) ) {
            return new WP_Error( 'tiktok_music_busy', 'another music track is being generated right now. Try again in a minute.' );
        }
        if ( function_exists( 'set_transient' ) ) {
            set_transient( $lock, 1, 300 );
        }
        try {
            return self::generate_next_unlocked();
        } finally {
            if ( function_exists( 'delete_transient' ) ) {
                delete_transient( $lock );
            }
        }
    }

    /** @return array|WP_Error See generate_next(). */
    private static function generate_next_unlocked() {
        $prompts = self::prompts();
        if ( empty( $prompts ) ) {
            return new WP_Error( 'tiktok_music_no_prompts', 'the Music Prompts setting has no "mood: prompt" lines.' );
        }
        $model = PressHub_Social_Settings_Storage::get_tiktok_shorts_music_model();
        $need  = PressHub_Social_Settings_Storage::get_tiktok_shorts_music_tracks();

        foreach ( $prompts as $mood => $prompt ) {
            $tracks = self::tracks( $mood );
            if ( count( $tracks ) >= $need ) {
                continue;
            }

            $n = count( $tracks ) + 1;

            // Lyria's copyright filter sometimes blocks a harmless prompt, and a repeat of the
            // same prompt is more likely to be blocked. Each track and each retry asks for a
            // different variation of the same mood.
            $audio = null;
            for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
                $audio = self::call_lyria( self::variation_prompt( $prompt, $n, $attempt ), $model );
                if ( ! is_wp_error( $audio ) || ! self::is_blocked( $audio ) ) {
                    break;
                }
                PressHub_Social_Logger::warning( sprintf( 'TikTok shorts music: Lyria blocked the %s track %d (attempt %d/%d): %s', $mood, $n, $attempt + 1, self::MAX_ATTEMPTS, $audio->get_error_message() ) );
            }
            if ( is_wp_error( $audio ) ) {
                PressHub_Social_Logger::error( sprintf( 'TikTok shorts music: the %s track could not be generated: %s', $mood, $audio->get_error_message() ) );
                if ( self::is_blocked( $audio ) ) {
                    return new WP_Error(
                        $audio->get_error_code(),
                        sprintf( 'Lyria blocked the %s track %d times with its copyright filter. Reword the "%s:" line in TikTok Shorts: Music Prompts (fewer genre and production terms), save, and generate again.', $mood, self::MAX_ATTEMPTS, $mood )
                    );
                }
                return $audio;
            }

            $hash     = self::track_hash( $prompt, $model );
            $is_wav   = 'RIFF' === substr( $audio, 0, 4 );
            $filename = sprintf( 'presshub-music-%s-%d-%s.%s', $mood, $n, substr( $hash, 0, 8 ), $is_wav ? 'wav' : 'mp3' );
            $upload   = self::upload( $filename, $audio, sprintf( 'TikTok shorts music: %s %d', $mood, $n ) );
            if ( is_wp_error( $upload ) ) {
                PressHub_Social_Logger::error( sprintf( 'TikTok shorts music: the %s track could not be saved: %s', $mood, $upload->get_error_message() ) );
                return $upload;
            }

            $track = [
                'id'      => (int) ( $upload['id'] ?? 0 ),
                'url'     => (string) ( $upload['url'] ?? '' ),
                'hash'    => $hash,
                'model'   => $model,
                'created' => gmdate( 'c' ),
            ];
            $lib          = self::library();
            $stale        = array_filter( (array) ( $lib[ $mood ] ?? [] ), fn( $t ) => is_array( $t ) && ( $t['hash'] ?? '' ) !== $hash );
            self::delete_attachments( $stale );
            $lib[ $mood ] = array_merge( $tracks, [ $track ] );
            update_option( self::LIBRARY_OPTION, $lib, false );
            PressHub_Social_Logger::info( sprintf( 'TikTok shorts music: generated %s track %d/%d with %s.', $mood, $n, $need, $model ) );

            return [ 'mood' => $mood, 'track' => $track, 'remaining' => self::missing_count() ];
        }
        return [ 'mood' => '', 'remaining' => 0 ];
    }

    /**
     * Drop every track (and its attachment), so Generate Music Tracks makes a new set.
     */
    public static function clear_library(): void {
        foreach ( self::library() as $tracks ) {
            self::delete_attachments( (array) $tracks );
        }
        update_option( self::LIBRARY_OPTION, [], false );
        PressHub_Social_Logger::info( 'TikTok shorts music: the track library was cleared.' );
    }

    /**
     * The track for a short: one of its mood's tracks, the same one for the same seed (the
     * job id), so a retried job keeps its music. '' for `none` or when the mood has no track.
     */
    public static function pick_track( string $mood, int $seed ): string {
        if ( self::NO_MUSIC === $mood || '' === $mood ) {
            return '';
        }
        $tracks = self::tracks( $mood );
        if ( empty( $tracks ) ) {
            return '';
        }
        return (string) $tracks[ abs( $seed ) % count( $tracks ) ]['url'];
    }

    /**
     * Call Lyria through the Gemini API Interactions endpoint.
     *
     * @return string|WP_Error The audio bytes (MP3 by default).
     */
    private static function call_lyria( string $prompt, string $model ) {
        if ( null !== self::$generator ) {
            try {
                $result = call_user_func( self::$generator, $prompt, $model );
            } catch ( Throwable $e ) {
                $result = new WP_Error( 'tiktok_music_exception', $e->getMessage() );
            }
            return self::check_audio( $result );
        }

        $key = self::api_key();
        if ( '' === $key ) {
            return new WP_Error( 'tiktok_music_no_key', 'no Gemini API key is set (Lyria music uses the Gemini key from the AI Editor providers).' );
        }
        $response = wp_remote_post( self::ENDPOINT, [
            'timeout' => 240,
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $key,
            ],
            'body'    => wp_json_encode( [ 'model' => $model, 'input' => $prompt ] ),
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            $decoded = json_decode( $body, true );
            $message = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? '' ) : '';
            return new WP_Error( 'tiktok_music_http_' . $code, sprintf( 'Lyria returned HTTP %d%s', $code, '' !== $message ? ': ' . $message : '.' ) );
        }
        return self::check_audio( self::audio_from_response( $body ) );
    }

    /**
     * The audio bytes from an Interactions response: the first `audio` block of a
     * `model_output` step (base64 in `data`).
     *
     * @return string|WP_Error
     */
    public static function audio_from_response( string $body ) {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'tiktok_music_invalid_response', 'Lyria returned a response that is not JSON.' );
        }
        $steps = is_array( $decoded['steps'] ?? null ) ? $decoded['steps'] : ( is_array( $decoded['outputs'] ?? null ) ? [ [ 'type' => 'model_output', 'content' => $decoded['outputs'] ] ] : [] );
        foreach ( $steps as $step ) {
            if ( ! is_array( $step ) || 'model_output' !== ( $step['type'] ?? '' ) ) {
                continue;
            }
            foreach ( (array) ( $step['content'] ?? [] ) as $block ) {
                if ( is_array( $block ) && 'audio' === ( $block['type'] ?? '' ) && is_string( $block['data'] ?? null ) ) {
                    $bytes = base64_decode( $block['data'], true );
                    if ( false !== $bytes && '' !== $bytes ) {
                        return $bytes;
                    }
                }
            }
        }
        return new WP_Error( 'tiktok_music_no_audio', 'Lyria returned no audio (the prompt may have been blocked by its safety filters).' );
    }

    /** @return string|WP_Error */
    private static function check_audio( $result ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! is_string( $result ) || strlen( $result ) < 1024 ) {
            return new WP_Error( 'tiktok_music_empty', 'Lyria returned empty audio.' );
        }
        return $result;
    }

    /** @return array{id:int,url:string}|WP_Error */
    private static function upload( string $filename, string $bytes, string $title ) {
        if ( null !== self::$uploader ) {
            return call_user_func( self::$uploader, $filename, $bytes, $title );
        }
        if ( ! class_exists( 'PressHub_AI_Audio_Synthesizer' ) ) {
            return new WP_Error( 'tiktok_music_no_uploader', 'PressHub_AI_Audio_Synthesizer is not loaded.' );
        }
        $upload = ( new PressHub_AI_Audio_Synthesizer() )->sideload_audio_file( $filename, $bytes, 0, $title );
        if ( ! is_wp_error( $upload ) && empty( $upload['url'] ) ) {
            return new WP_Error( 'tiktok_music_no_url', 'the saved track has no URL.' );
        }
        return $upload;
    }

    private static function delete_attachments( array $tracks ): void {
        if ( ! function_exists( 'wp_delete_attachment' ) ) {
            return;
        }
        foreach ( $tracks as $t ) {
            if ( is_array( $t ) && (int) ( $t['id'] ?? 0 ) > 0 ) {
                wp_delete_attachment( (int) $t['id'], true );
            }
        }
    }

    /** The Gemini API key: the AI Editor's Gemini provider, then the legacy options. */
    private static function api_key(): string {
        if ( class_exists( 'PressHub_AI_API_Client' ) && method_exists( 'PressHub_AI_API_Client', 'lookup_provider_key_by_type' ) ) {
            $key = PressHub_AI_API_Client::lookup_provider_key_by_type( 'gemini' );
            if ( '' !== $key ) {
                return $key;
            }
        }
        $key = (string) get_option( 'presshub_ai_gemini_api_key', '' );
        return '' !== $key ? $key : (string) get_option( 'presshub_ai_api_key', '' );
    }
}
