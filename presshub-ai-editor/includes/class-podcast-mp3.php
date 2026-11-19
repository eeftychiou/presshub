<?php
/**
 * MP3 copies of the podcast episodes.
 *
 * Gemini TTS returns 24 kHz 16-bit mono PCM, so a morning or noon episode is
 * saved as WAV: about 32 MB for 11 minutes. The site's host has no MP3 encoder,
 * so the social worker (Pull Mode, on the editor's PC, with its bundled ffmpeg)
 * converts it:
 *
 *   1. After synthesis, queue() records the episode (post, WAV attachment, URL).
 *   2. The worker asks POST /worker/audio/claim, downloads the WAV and encodes it.
 *   3. It sends the MP3 to POST /worker/audio/complete (raw audio/mpeg body).
 *      complete() sideloads it, points the post's player, `_presshub_audio_url`
 *      and `_presshub_audio_attachment_id` at it, and deletes the WAV attachment
 *      a day later (social jobs claimed before the swap may still be reading it).
 *   4. A failed conversion is reported to POST /worker/audio/fail and handed out
 *      again, up to MAX_ATTEMPTS times.
 *
 * Settings: `presshub_ai_podcast_mp3_enabled` (Daily Briefing → Voices & audio,
 * default on) and `presshub_ai_podcast_mp3_bitrate` (kbps, default 64).
 * The queue is runtime state in the option QUEUE_OPTION.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Podcast_Mp3 {

    /** Runtime state: post ID => queue entry. */
    public const QUEUE_OPTION = 'presshub_ai_podcast_mp3_queue';

    /** WP-Cron hook that deletes a replaced WAV attachment: args [ wav_id, post_id ]. */
    public const DELETE_WAV_HOOK = 'presshub_ai_podcast_mp3_delete_wav';

    /** Protocol constant: a claimed conversion not reported within this time is handed out again (s). */
    public const CLAIM_TIMEOUT = 1800;

    /** Protocol constant: conversions tried per episode before it is left as WAV. */
    public const MAX_ATTEMPTS = 3;

    /** The replaced WAV is deleted this long after the swap (s). */
    public const WAV_DELETE_DELAY = 86400;

    /** Largest MP3 accepted from the worker (bytes); 60 minutes at 192 kbps is about 86 MB. */
    public const MAX_MP3_BYTES = 100 * 1024 * 1024;

    /** Entries kept in the queue (oldest dropped first). */
    private const MAX_ENTRIES = 50;

    public static function register_hooks(): void {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
            add_action( self::DELETE_WAV_HOOK, [ __CLASS__, 'delete_wav' ], 10, 2 );
        }
    }

    /* ------------------------------------------------------------------
     * Queue
     * ---------------------------------------------------------------- */

    /** @return array<int, array> */
    public static function get_queue(): array {
        $queue = get_option( self::QUEUE_OPTION, [] );
        return is_array( $queue ) ? $queue : [];
    }

    private static function save_queue( array $queue ): void {
        if ( count( $queue ) > self::MAX_ENTRIES ) {
            uasort( $queue, function ( $a, $b ) {
                return ( (int) ( $a['queued_at'] ?? 0 ) ) <=> ( (int) ( $b['queued_at'] ?? 0 ) );
            } );
            $queue = array_slice( $queue, -self::MAX_ENTRIES, null, true );
        }
        update_option( self::QUEUE_OPTION, $queue, false );
    }

    /** True for a WAV file name or URL (query string ignored). */
    public static function is_wav_url( string $url ): bool {
        $path = (string) parse_url( $url, PHP_URL_PATH );
        return '' !== $path && 1 === preg_match( '/\.wav$/i', $path );
    }

    /**
     * Queue an episode's WAV for conversion. Does nothing when the switch is
     * off or the audio is not WAV (Google Cloud TTS already gives MP3).
     *
     * @return bool True when queued.
     */
    public static function queue( int $post_id, int $attachment_id, string $source_url ): bool {
        if ( $post_id <= 0 || $attachment_id <= 0 || ! self::is_wav_url( $source_url ) ) {
            return false;
        }
        if ( ! PressHub_AI_Settings_Storage::get_podcast_mp3_enabled() ) {
            return false;
        }
        $queue             = self::get_queue();
        $queue[ $post_id ] = [
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
            'source_url'    => $source_url,
            'status'        => 'pending',
            'attempts'      => 0,
            'queued_at'     => time(),
            'claimed_at'    => 0,
            'error'         => '',
        ];
        self::save_queue( $queue );
        PressHub_AI_Logger::info( 'Podcast MP3: episode queued for conversion by the worker.', [
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
        ] );
        return true;
    }

    /** The post's current audio attachment. */
    private static function current_attachment( int $post_id ): int {
        return (int) get_post_meta( $post_id, '_presshub_audio_attachment_id', true );
    }

    /**
     * Hand the worker the next conversion, or null. A pending entry, or a
     * claimed one not reported within CLAIM_TIMEOUT, is claimed (attempts + 1).
     * Entries whose post now plays another file are dropped.
     */
    public static function claim_next(): ?array {
        if ( ! PressHub_AI_Settings_Storage::get_podcast_mp3_enabled() ) {
            return null;
        }
        $queue   = self::get_queue();
        $now     = time();
        $changed = false;
        $job     = null;

        uasort( $queue, function ( $a, $b ) {
            return ( (int) ( $a['queued_at'] ?? 0 ) ) <=> ( (int) ( $b['queued_at'] ?? 0 ) );
        } );

        foreach ( $queue as $post_id => $entry ) {
            $status = (string) ( $entry['status'] ?? '' );
            if ( 'failed' === $status ) {
                continue;
            }
            if ( self::current_attachment( (int) $post_id ) !== (int) ( $entry['attachment_id'] ?? 0 ) ) {
                unset( $queue[ $post_id ] ); // Regenerated or edited since: that audio is queued on its own.
                $changed = true;
                continue;
            }
            $stale = 'processing' === $status && ( $now - (int) ( $entry['claimed_at'] ?? 0 ) ) >= self::CLAIM_TIMEOUT;
            if ( 'pending' !== $status && ! $stale ) {
                continue;
            }
            if ( (int) ( $entry['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
                $entry['status']   = 'failed';
                $entry['error']    = '' !== (string) ( $entry['error'] ?? '' ) ? $entry['error'] : 'The worker did not report back.';
                $queue[ $post_id ] = $entry;
                $changed           = true;
                PressHub_AI_Logger::error( 'Podcast MP3: conversion given up, the episode stays WAV.', [
                    'post_id'  => (int) $post_id,
                    'attempts' => (int) $entry['attempts'],
                    'error'    => (string) $entry['error'],
                ] );
                continue;
            }
            $entry['status']     = 'processing';
            $entry['claimed_at'] = $now;
            $entry['attempts']   = (int) ( $entry['attempts'] ?? 0 ) + 1;
            $queue[ $post_id ]   = $entry;
            $changed             = true;
            $job                 = [
                'post_id'       => (int) $post_id,
                'attachment_id' => (int) $entry['attachment_id'],
                'source_url'    => (string) $entry['source_url'],
                'bitrate_kbps'  => PressHub_AI_Settings_Storage::get_podcast_mp3_bitrate(),
                'attempt'       => (int) $entry['attempts'],
            ];
            break;
        }

        if ( $changed ) {
            self::save_queue( $queue );
        }
        return $job;
    }

    /** True when the bytes start like an MP3 file (ID3 tag or an MPEG audio frame). */
    public static function looks_like_mp3( string $bytes ): bool {
        if ( strlen( $bytes ) < 1024 ) {
            return false;
        }
        if ( 'ID3' === substr( $bytes, 0, 3 ) ) {
            return true;
        }
        return 0xFF === ord( $bytes[0] ) && 0xE0 === ( ord( $bytes[1] ) & 0xE0 );
    }

    /**
     * Store the worker's MP3 and point the episode at it.
     *
     * @return array|WP_Error [ 'post_id', 'attachment_id', 'url', 'bytes' ]
     */
    public static function complete( int $post_id, int $attachment_id, string $mp3 ) {
        $queue = self::get_queue();
        $entry = $queue[ $post_id ] ?? null;
        if ( ! is_array( $entry ) || (int) ( $entry['attachment_id'] ?? 0 ) !== $attachment_id || 'processing' !== ( $entry['status'] ?? '' ) ) {
            return new WP_Error( 'mp3_not_claimed', __( 'This conversion is not waiting for a result.', 'presshub-ai-editor' ), [ 'status' => 409 ] );
        }
        if ( self::current_attachment( $post_id ) !== $attachment_id ) {
            unset( $queue[ $post_id ] );
            self::save_queue( $queue );
            return new WP_Error( 'mp3_superseded', __( 'The episode plays another file now; the MP3 was not used.', 'presshub-ai-editor' ), [ 'status' => 409 ] );
        }
        if ( strlen( $mp3 ) > self::MAX_MP3_BYTES || ! self::looks_like_mp3( $mp3 ) ) {
            self::record_failure( $post_id, $attachment_id, 'The worker sent a file that is not an MP3.' );
            return new WP_Error( 'mp3_invalid', __( 'The uploaded file is not an MP3.', 'presshub-ai-editor' ), [ 'status' => 400 ] );
        }

        $old_url  = (string) get_post_meta( $post_id, '_presshub_audio_url', true );
        $old_url  = '' !== $old_url ? $old_url : (string) $entry['source_url'];
        $base     = (string) pathinfo( (string) parse_url( $old_url, PHP_URL_PATH ), PATHINFO_FILENAME );
        $base     = '' !== $base ? sanitize_file_name( $base ) : 'podcast-' . $post_id;
        $wav_post = get_post( $attachment_id );
        $title    = is_object( $wav_post ) && ! empty( $wav_post->post_title ) ? (string) $wav_post->post_title : ( function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '' );

        $synth = new PressHub_AI_Audio_Synthesizer();
        $media = $synth->sideload_audio_file( $base . '.mp3', $mp3, 0, (string) $title );
        if ( is_wp_error( $media ) ) {
            self::record_failure( $post_id, $attachment_id, 'Saving the MP3 failed: ' . $media->get_error_message() );
            return $media;
        }
        $new_id  = (int) $media['id'];
        $new_url = (string) $media['url'];

        $post = get_post( $post_id );
        if ( is_object( $post ) ) {
            $content = self::swap_audio_in_content( (string) ( $post->post_content ?? '' ), $old_url, $attachment_id, $new_url, $new_id );
            if ( $content !== (string) ( $post->post_content ?? '' ) ) {
                $updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ], true );
                if ( is_wp_error( $updated ) ) {
                    if ( function_exists( 'wp_delete_attachment' ) ) {
                        wp_delete_attachment( $new_id, true );
                    }
                    self::record_failure( $post_id, $attachment_id, 'Updating the post failed: ' . $updated->get_error_message() );
                    return $updated;
                }
            }
        }

        update_post_meta( $post_id, '_presshub_audio_attachment_id', $new_id );
        update_post_meta( $post_id, '_presshub_audio_url', $new_url );
        update_post_meta( $post_id, '_presshub_audio_format', 'mp3' );
        update_post_meta( $post_id, '_presshub_audio_filesize', strlen( $mp3 ) );
        update_post_meta( $post_id, '_presshub_audio_wav_attachment_id', $attachment_id );

        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + self::WAV_DELETE_DELAY, self::DELETE_WAV_HOOK, [ $attachment_id, $post_id ] );
        }

        unset( $queue[ $post_id ] );
        self::save_queue( $queue );

        PressHub_AI_Logger::info( 'Podcast MP3: episode now plays the MP3.', [
            'post_id'           => $post_id,
            'wav_attachment_id' => $attachment_id,
            'mp3_attachment_id' => $new_id,
            'mp3_bytes'         => strlen( $mp3 ),
        ] );

        return [ 'post_id' => $post_id, 'attachment_id' => $new_id, 'url' => $new_url, 'bytes' => strlen( $mp3 ) ];
    }

    /**
     * Point the audio block at the MP3: the old URL (and its escaped form) and
     * the block's attachment id. Nothing else in the post changes.
     */
    public static function swap_audio_in_content( string $content, string $old_url, int $old_id, string $new_url, int $new_id ): string {
        if ( '' !== $old_url ) {
            // The URL as stored, and as escaped in HTML (&#038; from esc_url(), &amp;).
            foreach ( [ '&', '&#038;', '&amp;' ] as $amp ) {
                $content = str_replace(
                    str_replace( '&', $amp, $old_url ),
                    str_replace( '&', $amp, $new_url ),
                    $content
                );
            }
        }
        if ( $old_id > 0 && $new_id > 0 ) {
            $content = (string) preg_replace(
                '/(<!--\s*wp:audio\s*\{[^}]*"id"\s*:\s*)' . $old_id . '(?!\d)/',
                '${1}' . $new_id,
                $content
            );
        }
        return $content;
    }

    /**
     * A failed conversion: handed out again on the next claim, or given up
     * after MAX_ATTEMPTS (the episode keeps its WAV).
     */
    public static function record_failure( int $post_id, int $attachment_id, string $error ): bool {
        $queue = self::get_queue();
        $entry = $queue[ $post_id ] ?? null;
        if ( ! is_array( $entry ) || (int) ( $entry['attachment_id'] ?? 0 ) !== $attachment_id ) {
            return false;
        }
        $error          = substr( sanitize_text_field( $error ), 0, 500 );
        $entry['error'] = '' !== $error ? $error : 'Conversion failed.';
        if ( (int) ( $entry['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
            $entry['status'] = 'failed';
            PressHub_AI_Logger::error( 'Podcast MP3: conversion given up, the episode stays WAV.', [
                'post_id'  => $post_id,
                'attempts' => (int) $entry['attempts'],
                'error'    => $entry['error'],
            ] );
        } else {
            $entry['status'] = 'pending';
            PressHub_AI_Logger::warning( 'Podcast MP3: conversion failed, it will be tried again.', [
                'post_id'  => $post_id,
                'attempts' => (int) ( $entry['attempts'] ?? 0 ),
                'error'    => $entry['error'],
            ] );
        }
        $queue[ $post_id ] = $entry;
        self::save_queue( $queue );
        return true;
    }

    /**
     * WP-Cron: delete the replaced WAV, unless the post plays it again
     * (regenerated audio uses a new attachment, so this is a safety check).
     */
    public static function delete_wav( $wav_id, $post_id ): void {
        $wav_id  = (int) $wav_id;
        $post_id = (int) $post_id;
        if ( $wav_id <= 0 || self::current_attachment( $post_id ) === $wav_id ) {
            return;
        }
        $file = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $wav_id ) : '';
        if ( '' !== $file && ! self::is_wav_url( $file ) ) {
            PressHub_AI_Logger::warning( 'Podcast MP3: the attachment to delete is not a WAV file; kept.', [ 'attachment_id' => $wav_id, 'post_id' => $post_id ] );
            return;
        }
        if ( function_exists( 'wp_delete_attachment' ) ) {
            wp_delete_attachment( $wav_id, true );
            PressHub_AI_Logger::info( 'Podcast MP3: replaced WAV deleted.', [ 'attachment_id' => $wav_id, 'post_id' => $post_id ] );
        }
    }

    /* ------------------------------------------------------------------
     * REST (worker, Pull Mode)
     * ---------------------------------------------------------------- */

    public static function register_routes(): void {
        $ns   = PressHub_Social_REST_Controller::REST_NAMESPACE;
        $auth = [ 'PressHub_Social_REST_Controller', 'verify_worker_secret' ];
        register_rest_route( $ns, '/worker/audio/claim', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_claim' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, '/worker/audio/complete', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_complete' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, '/worker/audio/fail', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_fail' ],
            'permission_callback' => $auth,
        ] );
    }

    private static function param( $request, string $key ) {
        if ( is_array( $request ) ) {
            return $request[ $key ] ?? null;
        }
        $val = method_exists( $request, 'get_param' ) ? $request->get_param( $key ) : null;
        if ( null === $val && method_exists( $request, 'get_json_params' ) ) {
            $json = $request->get_json_params();
            $val  = is_array( $json ) ? ( $json[ $key ] ?? null ) : null;
        }
        return $val;
    }

    /** POST /worker/audio/claim → { job: null | { post_id, attachment_id, source_url, bitrate_kbps, attempt } } */
    public static function rest_claim( $request ): array {
        return [ 'job' => self::claim_next() ];
    }

    /** POST /worker/audio/complete?post_id=&attachment_id= with the MP3 as the raw body. */
    public static function rest_complete( $request ) {
        $post_id       = (int) self::param( $request, 'post_id' );
        $attachment_id = (int) self::param( $request, 'attachment_id' );
        if ( is_array( $request ) ) {
            $body = (string) ( $request['body'] ?? '' );
        } else {
            $body = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
        }
        $result = self::complete( $post_id, $attachment_id, $body );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return [ 'ok' => true ] + $result;
    }

    /** POST /worker/audio/fail { post_id, attachment_id, error } */
    public static function rest_fail( $request ): array {
        $ok = self::record_failure(
            (int) self::param( $request, 'post_id' ),
            (int) self::param( $request, 'attachment_id' ),
            (string) self::param( $request, 'error' )
        );
        return [ 'ok' => $ok ];
    }
}
