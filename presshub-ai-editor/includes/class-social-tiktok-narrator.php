<?php
/**
 * TikTok narration: turns the TikTok copy of a text-only article into a
 * Gemini TTS voice-over so the worker renders a spoken video instead of a
 * silent card.
 *
 * Applied when the local worker claims a TikTok job (Pull Mode). Articles that
 * already carry podcast audio (`_presshub_audio_url` or an inline `<audio>`)
 * keep that audio; narration only fills the gap for articles with no audio.
 *
 * Spoken text = TikTok copy without hashtags, emojis, URLs or any sentence that
 * mentions presshub.cy (the copy's own call to action), followed by the
 * configurable outro (default: "Για το πλήρες άρθρο, επισκεφθείτε το presshub.cy").
 *
 * The generated audio is sideloaded into the Media Library and remembered in
 * the job's `metadata.tiktok_narration` together with a hash of the spoken
 * text, so retries reuse it and an edited copy triggers a fresh synthesis.
 *
 * Zero-Silent-Fallback: if narration is enabled and synthesis fails, the job
 * fails loudly instead of being posted as a silent video.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_TikTok_Narrator {

    /** Default closing line appended to every narration. */
    public const DEFAULT_OUTRO = 'Για το πλήρες άρθρο, επισκεφθείτε το presshub.cy';

    /** Metadata key holding the cached narration for a job. */
    public const META_KEY = 'tiktok_narration';

    /**
     * Optional synthesizer override for tests.
     * Signature: fn( string $text, array $job ): array{url:string,id:int,voice?:string}|WP_Error
     *
     * @var callable|null
     */
    private static $synthesizer = null;

    /**
     * Override the synthesizer (tests). Pass null to restore the Gemini TTS pipeline.
     *
     * @param callable|null $synthesizer
     */
    public static function set_synthesizer( ?callable $synthesizer ): void {
        self::$synthesizer = $synthesizer;
    }

    /**
     * Build the text that will be spoken for a TikTok copy.
     *
     * @param string $copy  TikTok post copy.
     * @param string $outro Closing sentence.
     * @return string Spoken text, or '' when the copy has nothing speakable.
     */
    public static function build_narration_text( string $copy, string $outro ): string {
        $lines  = preg_split( '/\R/u', $copy ) ?: [];
        $kept   = [];

        foreach ( $lines as $line ) {
            $line = (string) preg_replace( '/\b(?:https?:\/\/|www\.)\S+/iu', '', $line );
            // The copy's own CTA ("... στο Presshub.cy") is replaced by the outro: drop
            // only the sentence that mentions it, keeping any content sharing the line.
            if ( preg_match( '/presshub\s*\.?\s*cy\b/iu', $line ) ) {
                $sentences = preg_split( '/(?<=[.!?;…])\s+/u', $line ) ?: [];
                $line      = implode( ' ', array_filter( $sentences, static fn( $s ) => ! preg_match( '/presshub\s*\.?\s*cy\b/iu', $s ) ) );
            }
            $line = preg_replace( '/(?<![\p{L}\p{N}_])#[\p{L}\p{N}_]+/u', '', $line );
            $line = self::strip_emoji( (string) $line );
            $line = trim( (string) preg_replace( '/\s+/u', ' ', (string) $line ) );
            if ( '' !== $line ) {
                $kept[] = $line;
            }
        }

        $body = implode( "\n", $kept );
        if ( '' === $body ) {
            return '';
        }

        // Make sure the body ends a sentence so the outro is read as a new one.
        if ( ! preg_match( '/[.!?;…][»"”\']?$/u', $body ) ) {
            $body .= '.';
        }

        $outro = trim( $outro );
        return '' === $outro ? $body : $body . "\n\n" . $outro;
    }

    /**
     * Remove emoji, flags, pictographs and their joiners/variation selectors.
     */
    public static function strip_emoji( string $text ): string {
        return (string) preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u',
            '',
            $text
        );
    }

    /**
     * Attach narration audio to a claimed TikTok job when it has no audio of its own.
     *
     * @param array $job Claimed job (already enriched with any podcast audio_url).
     * @return array|WP_Error The job, possibly with `audio_url` and `narration` set.
     */
    public static function attach_narration( array $job ) {
        if ( 'tiktok' !== ( $job['platform'] ?? '' ) ) {
            return $job;
        }
        if ( ! empty( $job['audio_url'] ) ) {
            return $job; // Podcast audio takes precedence.
        }
        if ( ! PressHub_Social_Settings_Storage::get_tiktok_narration_enabled() ) {
            return $job;
        }

        $job_id = (int) ( $job['id'] ?? 0 );
        $text   = self::build_narration_text(
            (string) ( $job['post_copy'] ?? '' ),
            PressHub_Social_Settings_Storage::get_tiktok_narration_outro()
        );

        if ( '' === $text ) {
            return new WP_Error(
                'tiktok_narration_empty',
                sprintf( 'TikTok narration failed for job #%d: the copy has no speakable text after removing hashtags, emojis, URLs and the presshub.cy sentence.', $job_id )
            );
        }

        $hash     = hash( 'sha256', PressHub_Social_Settings_Storage::get_tiktok_narration_voice() . '|' . $text );
        $metadata = self::decode_metadata( $job['metadata'] ?? null );
        $cached   = $metadata[ self::META_KEY ] ?? null;

        if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash && ! empty( $cached['audio_url'] ) ) {
            PressHub_Social_Logger::info( sprintf( 'TikTok narration reused for job #%d (attachment #%d).', $job_id, (int) ( $cached['attachment_id'] ?? 0 ) ) );
            $job['audio_url'] = (string) $cached['audio_url'];
            $job['narration'] = true;
            return $job;
        }

        PressHub_Social_Logger::info( sprintf( 'Synthesizing TikTok narration for job #%d (%d chars).', $job_id, mb_strlen( $text ) ) );

        $result = null !== self::$synthesizer
            ? call_user_func( self::$synthesizer, $text, $job )
            : self::synthesize_with_gemini( $text, $job, $hash );

        if ( is_wp_error( $result ) ) {
            $err = new WP_Error(
                'tiktok_narration_failed',
                sprintf( 'TikTok narration failed for job #%d: %s', $job_id, $result->get_error_message() )
            );
            PressHub_Social_Logger::error( $err->get_error_message(), [ 'job_id' => $job_id, 'error_code' => $result->get_error_code() ] );
            return $err;
        }

        if ( empty( $result['url'] ) ) {
            $err = new WP_Error( 'tiktok_narration_failed', sprintf( 'TikTok narration failed for job #%d: synthesized audio has no URL.', $job_id ) );
            PressHub_Social_Logger::error( $err->get_error_message(), [ 'job_id' => $job_id ] );
            return $err;
        }

        $metadata[ self::META_KEY ] = [
            'hash'          => $hash,
            'audio_url'     => (string) $result['url'],
            'attachment_id' => (int) ( $result['id'] ?? 0 ),
            'voice'         => (string) ( $result['voice'] ?? '' ),
            'text'          => $text,
            'generated_at'  => gmdate( 'c' ),
        ];
        if ( $job_id > 0 ) {
            PressHub_Social_Queue::update_job( $job_id, [
                'metadata' => function_exists( 'wp_json_encode' ) ? wp_json_encode( $metadata ) : json_encode( $metadata ),
            ] );
        }

        PressHub_Social_Logger::info( sprintf( 'TikTok narration ready for job #%d: %s', $job_id, $result['url'] ) );

        $job['audio_url'] = (string) $result['url'];
        $job['narration'] = true;
        return $job;
    }

    /**
     * Synthesize with the plugin's Gemini TTS pipeline and sideload the WAV.
     *
     * @return array|WP_Error
     */
    private static function synthesize_with_gemini( string $text, array $job, string $hash ) {
        if ( ! class_exists( 'PressHub_AI_Audio_Synthesizer' ) ) {
            return new WP_Error( 'tts_unavailable', 'PressHub_AI_Audio_Synthesizer is not loaded.' );
        }

        $synth = new PressHub_AI_Audio_Synthesizer();
        $voice = PressHub_Social_Settings_Storage::get_tiktok_narration_voice();
        if ( '' === $voice ) {
            $voice = $synth->get_voice_for_speaker( 'speaker_1' );
            PressHub_Social_Logger::info( sprintf( 'TikTok narration voice not set; using Daily Briefing Presenter 1 voice "%s".', $voice ) );
        }

        $speed = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_speed() : 1.0;
        $pitch = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_pitch() : 0.0;

        $audio = $synth->synthesize_turn( $text, $voice, $speed, $pitch, null, '', '' );
        if ( is_wp_error( $audio ) ) {
            return $audio;
        }
        if ( ! is_string( $audio ) || '' === $audio ) {
            return new WP_Error( 'tts_empty_audio', 'Gemini TTS returned empty audio.' );
        }

        $job_id   = (int) ( $job['id'] ?? 0 );
        $post_id  = (int) ( $job['post_id'] ?? 0 );
        $filename = sprintf( 'tiktok-narration-job-%d-%s.wav', $job_id, substr( $hash, 0, 8 ) );
        $upload   = $synth->sideload_audio_file( $filename, $audio, $post_id, sprintf( 'TikTok narration (job #%d)', $job_id ) );
        if ( is_wp_error( $upload ) ) {
            return $upload;
        }

        $upload['voice'] = $voice;
        return $upload;
    }

    /**
     * @param mixed $raw
     */
    private static function decode_metadata( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return [];
    }
}
