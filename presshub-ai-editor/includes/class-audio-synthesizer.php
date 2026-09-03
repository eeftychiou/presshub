<?php
/**
 * PressHub_AI_Audio_Synthesizer — Multi-Voice Google Cloud TTS Podcast Synthesizer.
 *
 * Handles voice model directory for Greek TTS (Neural2, Wavenet, Standard),
 * speaker turn synthesis, silent MPEG frame generation, binary MP3 stitching,
 * Media Library sideloading, and WordPress Podcast post creation with Audio blocks.
 *
 * @package PressHub_AI_Editor
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-api-client.php';
require_once __DIR__ . '/class-news-harvester.php';
require_once __DIR__ . '/class-podcast-producer.php';
require_once __DIR__ . '/class-markdown.php';
require_once __DIR__ . '/class-token-logger.php';

class PressHub_AI_Audio_Synthesizer {

    /** Option key for audio synthesis engine ('gemini' or 'google_cloud'). */
    const OPTION_ENGINE = 'presshub_ai_briefing_tts_engine';

    /** Option key for female voice model. */
    const OPTION_VOICE_FEMALE = 'presshub_ai_briefing_voice_female';

    /** Option key for male voice model. */
    const OPTION_VOICE_MALE = 'presshub_ai_briefing_voice_male';

    /** Option key for speaking style / delivery. */
    const OPTION_STYLE = 'presshub_ai_briefing_tts_style';

    /** Option key for custom speaking style instruction. */
    const OPTION_CUSTOM_STYLE = 'presshub_ai_briefing_tts_custom_style';

    /** Option key for speaking rate/speed. */
    const OPTION_VOICE_SPEED = 'presshub_ai_briefing_voice_speed';

    /** Option key for voice pitch. */
    const OPTION_VOICE_PITCH = 'presshub_ai_briefing_voice_pitch';

    /** Option key for podcast post category. */
    const OPTION_CATEGORY = 'presshub_ai_briefing_podcast_category';

    /** Option key for podcast post status. */
    const OPTION_STATUS = 'presshub_ai_briefing_podcast_status';

    /**
     * Get list of delivery styles from logosAI.
     *
     * @return array Style dictionary.
     */
    public function get_available_styles(): array {
        return [
            'formal'      => [
                'id'          => 'formal',
                'label'       => __( 'Formal & Broadcast (Επίσημο & Επαγγελματικό)', 'presshub-ai-editor' ),
                'instruction' => 'Say in a professional, authoritative, articulate Greek news broadcast tone:',
            ],
            'natural'     => [
                'id'          => 'natural',
                'label'       => __( 'Natural & Warm (Φυσικό & Φιλικό)', 'presshub-ai-editor' ),
                'instruction' => 'Say naturally and clearly in Greek with warm human cadence:',
            ],
            'cheerful'    => [
                'id'          => 'cheerful',
                'label'       => __( 'Cheerful & Bright (Χαρούμενο & Φωτεινό)', 'presshub-ai-editor' ),
                'instruction' => 'Say cheerfully, enthusiastically, and with a bright uplifting smile in Greek:',
            ],
            'storyteller' => [
                'id'          => 'storyteller',
                'label'       => __( 'Storyteller & Narrative (Αφήγηση & Παραμύθι)', 'presshub-ai-editor' ),
                'instruction' => 'Say like an engaging, captivating storyteller with theatrical pacing and expressive pauses in Greek:',
            ],
            'calm'        => [
                'id'          => 'calm',
                'label'       => __( 'Calm & Soothing (Ήρεμο & Γαλήνιο)', 'presshub-ai-editor' ),
                'instruction' => 'Say in a peaceful, gentle, soothing, and relaxing tone in Greek:',
            ],
            'dramatic'    => [
                'id'          => 'dramatic',
                'label'       => __( 'Dramatic & Intense (Δραματικό & Έντονο)', 'presshub-ai-editor' ),
                'instruction' => 'Say with intense dramatic emotion, resonant weight, and vivid inflection in Greek:',
            ],
            'poetic'      => [
                'id'          => 'poetic',
                'label'       => __( 'Poetic & Lyrical (Ποιητικό & Λυρικό)', 'presshub-ai-editor' ),
                'instruction' => 'Say with deep lyrical emotion, soft melodic rhythm, and poetic sensitivity in Greek:',
            ],
            'epic'        => [
                'id'          => 'epic',
                'label'       => __( 'Epic & Classical (Επικό & Αρχαιοπρεπές)', 'presshub-ai-editor' ),
                'instruction' => 'Say in a grand, legendary, classical ancient oratorical style in Greek:',
            ],
            'whisper'     => [
                'id'          => 'whisper',
                'label'       => __( 'Gentle Whisper (Ψίθυρος)', 'presshub-ai-editor' ),
                'instruction' => 'Say in a soft, intimate, gentle quiet whisper in Greek:',
            ],
            'energetic'   => [
                'id'          => 'energetic',
                'label'       => __( 'Energetic & Dynamic (Δυναμικό & Ενθουσιώδες)', 'presshub-ai-editor' ),
                'instruction' => 'Say with high energy, vibrant excitement, and dynamic rhythm in Greek:',
            ],
            'custom'      => [
                'id'          => 'custom',
                'label'       => __( 'Custom Prompt Instruction (Προσαρμοσμένη Οδηγία)', 'presshub-ai-editor' ),
                'instruction' => '',
            ],
        ];
    }

    /**
     * Get available Greek voice models for audio synthesis (logosAI personas).
     *
     * @param string $engine Optional engine parameter (kept for backwards compatibility).
     * @return array Grouped voice models directory with metadata.
     */
    public function get_available_voices( string $engine = '' ): array {
        return [
            'female' => [
                'Kore'       => [
                    'name'   => 'Kore',
                    'label'  => __( 'Kore / Κόρη (Warm, Crystal Clear & Articulate - Default)', 'presshub-ai-editor' ),
                    'gender' => 'FEMALE',
                    'type'   => 'Gemini-Neural',
                ],
                'Aoede'      => [
                    'name'   => 'Aoede',
                    'label'  => __( 'Aoede / Αοιδή (Expressive & Melodic)', 'presshub-ai-editor' ),
                    'gender' => 'FEMALE',
                    'type'   => 'Gemini-Neural',
                ],
                'Leda'       => [
                    'name'   => 'Leda',
                    'label'  => __( 'Leda / Λήδα (Warm & Professional)', 'presshub-ai-editor' ),
                    'gender' => 'FEMALE',
                    'type'   => 'Gemini-Neural',
                ],
                'Callirrhoe' => [
                    'name'   => 'Callirrhoe',
                    'label'  => __( 'Callirrhoe / Καλλιρρόη (Dynamic & Engaging)', 'presshub-ai-editor' ),
                    'gender' => 'FEMALE',
                    'type'   => 'Gemini-Neural',
                ],
                'Autonoe'    => [
                    'name'   => 'Autonoe',
                    'label'  => __( 'Autonoe / Αυτονόη (Conversational)', 'presshub-ai-editor' ),
                    'gender' => 'FEMALE',
                    'type'   => 'Gemini-Neural',
                ],
            ],
            'male' => [
                'Fenrir'     => [
                    'name'   => 'Fenrir',
                    'label'  => __( 'Fenrir / Φένριρ (Bold, Strong & Authoritative - Default)', 'presshub-ai-editor' ),
                    'gender' => 'MALE',
                    'type'   => 'Gemini-Neural',
                ],
                'Puck'       => [
                    'name'   => 'Puck',
                    'label'  => __( 'Puck / Πουκ (Lively, Youthful & Expressive)', 'presshub-ai-editor' ),
                    'gender' => 'MALE',
                    'type'   => 'Gemini-Neural',
                ],
                'Charon'     => [
                    'name'   => 'Charon',
                    'label'  => __( 'Charon / Χάρων (Deep Baritone & Solemn Gravitas)', 'presshub-ai-editor' ),
                    'gender' => 'MALE',
                    'type'   => 'Gemini-Neural',
                ],
                'Zephyr'     => [
                    'name'   => 'Zephyr',
                    'label'  => __( 'Zephyr / Ζέφυρος (Calm, Gentle & Melodious)', 'presshub-ai-editor' ),
                    'gender' => 'NEUTRAL',
                    'type'   => 'Gemini-Neural',
                ],
                'Orus'       => [
                    'name'   => 'Orus',
                    'label'  => __( 'Orus / Ώρος (Confident & Articulate)', 'presshub-ai-editor' ),
                    'gender' => 'MALE',
                    'type'   => 'Gemini-Neural',
                ],
            ],
        ];
    }

    /**
     * Get the configured voice model for a given speaker identifier.
     *
     * @param string $speaker Speaker identifier ('female', 'male', 'host1', 'host2', or speaker name).
     * @return string Voice model name (e.g. 'Kore', 'Fenrir', 'Puck', 'el-GR-Wavenet-A').
     */
    public function get_voice_for_speaker( string $speaker ): string {
        $clean = trim( $speaker );
        $engine = (string) get_option( self::OPTION_ENGINE, 'gemini' );

        $female_host   = (string) get_option( 'presshub_ai_briefing_host_female', 'Μαρία' );
        $male_host     = (string) get_option( 'presshub_ai_briefing_host_male', 'Νίκος' );
        $tertiary_host = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_tertiary() : (string) get_option( 'presshub_ai_briefing_host_tertiary', 'Κώστας' );

        $lower = strtolower( $clean );

        // 1. Check tertiary host
        $is_tertiary = (
            'tertiary' === $lower
            || 'host3' === $lower
            || 'host 3' === $lower
            || ( ! empty( $tertiary_host ) && function_exists( 'mb_stripos' ) && false !== mb_stripos( $clean, $tertiary_host ) )
        );

        if ( $is_tertiary ) {
            $default_tertiary = ( 'google_cloud' === $engine ) ? 'el-GR-Wavenet-C' : 'Puck';
            $voice = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_tertiary() : (string) get_option( 'presshub_ai_briefing_voice_tertiary', $default_tertiary );
            return ! empty( $voice ) ? trim( $voice ) : $default_tertiary;
        }

        $is_female = (
            'female' === $lower
            || 'host1' === $lower
            || 'host 1' === $lower
            || false !== strpos( $lower, 'female' )
            || false !== strpos( $lower, 'maria' )
            || ( function_exists( 'mb_stripos' ) && false !== mb_stripos( $clean, 'μαρία' ) )
            || ( ! empty( $female_host ) && function_exists( 'mb_stripos' ) && false !== mb_stripos( $clean, $female_host ) )
        );

        if ( $is_female ) {
            $default_female = ( 'google_cloud' === $engine ) ? 'el-GR-Wavenet-A' : 'Kore';
            $voice = (string) get_option( self::OPTION_VOICE_FEMALE, $default_female );
            if ( empty( $voice ) || false !== strpos( $voice, 'Neural2' ) ) {
                $voice = $default_female;
            }
            return trim( $voice );
        }

        $default_male = ( 'google_cloud' === $engine ) ? 'el-GR-Chirp3-HD-Achird' : 'Fenrir';
        $voice = (string) get_option( self::OPTION_VOICE_MALE, $default_male );
        if ( empty( $voice ) || false !== strpos( $voice, 'Neural2' ) || 'el-GR-Wavenet-B' === $voice || 'el-GR-Standard-B' === $voice ) {
            $voice = $default_male;
        }
        return trim( $voice );
    }

    /**
     * Generate valid MPEG-1 Layer 3 silent audio frames.
     *
     * Standard MPEG-1 Layer 3 frame at 128kbps / 44.1kHz is 417 bytes and represents ~26.1224 ms.
     *
     * @param int $duration_ms Silence duration in milliseconds (default 400).
     * @return string Binary silent MP3 data buffer.
     */
    public function generate_silent_mp3_frame( int $duration_ms = 400 ): string {
        if ( $duration_ms <= 0 ) {
            return '';
        }

        // 417-byte canonical MPEG-1 Layer 3 silent frame (128kbps, 44.1kHz, Joint Stereo)
        $single_frame = "\xFF\xFB\x90\xC4"
            . "\x00\x03\xC0\x00\x01\xA4\x00\x00\x00\x20\x00\x00\x34\x80\x00\x00\x04"
            . str_repeat( "\x55", 396 );

        $num_frames = max( 1, (int) round( $duration_ms / 26.1224 ) );

        return str_repeat( $single_frame, $num_frames );
    }

    /**
     * Strip ID3v2 header and ID3v1 footer from raw MP3 buffer.
     *
     * @param string $mp3_buffer Raw MP3 binary buffer.
     * @return string Cleaned MP3 audio data without ID3 tags.
     */
    public function strip_id3_tags( string $mp3_buffer ): string {
        $len = strlen( $mp3_buffer );
        if ( $len === 0 ) {
            return '';
        }

        // 1. Strip ID3v2 header if present at start
        if ( $len >= 10 && 'ID3' === substr( $mp3_buffer, 0, 3 ) ) {
            $flags = ord( $mp3_buffer[5] );
            $has_footer = ( $flags & 0x10 ) !== 0; // Bit 4
            // Syncsafe integer: 4 bytes (7 bits each)
            $tag_size = ( ( ord( $mp3_buffer[6] ) & 0x7F ) << 21 )
                      | ( ( ord( $mp3_buffer[7] ) & 0x7F ) << 14 )
                      | ( ( ord( $mp3_buffer[8] ) & 0x7F ) << 7 )
                      | ( ord( $mp3_buffer[9] ) & 0x7F );
            $header_total = 10 + $tag_size + ( $has_footer ? 10 : 0 );
            if ( $header_total < $len ) {
                $mp3_buffer = substr( $mp3_buffer, $header_total );
                $len = strlen( $mp3_buffer );
            }
        }

        // 2. Strip ID3v1 tag if present at end (128 bytes starting with "TAG")
        if ( $len >= 128 && 'TAG' === substr( $mp3_buffer, -128, 3 ) ) {
            $mp3_buffer = substr( $mp3_buffer, 0, $len - 128 );
        }

        return $mp3_buffer;
    }

    /**
     * Stitch multiple binary MP3 chunks together with silent pause intervals.
     *
     * @param array $mp3_buffers List of binary MP3 buffers.
     * @param int   $pause_ms    Silence pause duration in milliseconds between turns (default 400).
     * @return string Concatenated binary MP3 data.
     */
    public function stitch_audio_chunks( array $mp3_buffers, int $pause_ms = 400 ): string {
        if ( empty( $mp3_buffers ) ) {
            return '';
        }

        $cleaned_buffers = [];
        foreach ( $mp3_buffers as $buffer ) {
            if ( ! is_string( $buffer ) || '' === $buffer ) {
                continue;
            }
            $cleaned = $this->strip_id3_tags( $buffer );
            if ( '' !== $cleaned ) {
                $cleaned_buffers[] = $cleaned;
            }
        }

        if ( empty( $cleaned_buffers ) ) {
            return '';
        }

        if ( 1 === count( $cleaned_buffers ) ) {
            return $cleaned_buffers[0];
        }

        $pause_frame = $this->generate_silent_mp3_frame( $pause_ms );
        return implode( $pause_frame, $cleaned_buffers );
    }

    /**
     * Stitch multiple WAV/PCM audio buffers together with silent intervals.
     *
     * @param array  $wav_or_pcm_buffers List of WAV or raw PCM binary buffers.
     * @param int    $pause_ms           Pause duration in milliseconds between turns (default 400).
     * @param int    $sample_rate        Sample rate in Hz (default 24000).
     * @param string $interstitial_pcm   Optional raw PCM binary to inject between chunks (e.g. SFX).
     * @return string Valid RIFF/WAV binary data.
     */
    public function stitch_wav_chunks( array $wav_or_pcm_buffers, int $pause_ms = 400, int $sample_rate = 24000, string $interstitial_pcm = '' ): string {
        if ( empty( $wav_or_pcm_buffers ) ) {
            return '';
        }

        $raw_pcm_chunks = [];
        foreach ( $wav_or_pcm_buffers as $buffer ) {
            if ( ! is_string( $buffer ) || '' === $buffer ) {
                continue;
            }
            // If it is a WAV container (starts with RIFF), extract PCM payload (strip header)
            if ( strlen( $buffer ) >= 44 && 'RIFF' === substr( $buffer, 0, 4 ) && 'WAVE' === substr( $buffer, 8, 4 ) ) {
                $pos = strpos( $buffer, 'data' );
                if ( false !== $pos && strlen( $buffer ) >= $pos + 8 ) {
                    $data_size = unpack( 'V', substr( $buffer, $pos + 4, 4 ) )[1] ?? 0;
                    $pcm = substr( $buffer, $pos + 8, $data_size > 0 ? $data_size : null );
                    $raw_pcm_chunks[] = $pcm;
                } else {
                    $raw_pcm_chunks[] = substr( $buffer, 44 );
                }
            } else {
                $raw_pcm_chunks[] = $buffer;
            }
        }

        if ( empty( $raw_pcm_chunks ) ) {
            return '';
        }

        // Generate silence PCM frames (zero bytes: 48 bytes per ms at 24kHz 16-bit mono)
        $bytes_per_ms = (int) ( $sample_rate * 2 / 1000 );
        $silence_bytes = str_repeat( "\x00", max( 0, $pause_ms * $bytes_per_ms ) );
        
        $separator = $silence_bytes;
        if ( '' !== $interstitial_pcm ) {
            // Pad the SFX with a small buffer of silence on both sides (e.g., 150ms)
            $short_silence = str_repeat( "\x00", 150 * $bytes_per_ms );
            $separator = $short_silence . $interstitial_pcm . $short_silence;
        }

        $combined_pcm = implode( $separator, $raw_pcm_chunks );
        return PressHub_AI_API_Client::pcm_to_wav( $combined_pcm, $sample_rate );
    }

    /**
     * Synthesize a single speaker turn using the configured engine (Gemini or Google Cloud TTS).
     *
     * @param string                       $text        Turn text.
     * @param string                       $voice_model Voice model name.
     * @param float                        $speed       Speaking rate (default 1.0).
     * @param float                        $pitch       Voice pitch (default 0.0).
     * @param PressHub_AI_API_Client|null $api_client  Optional API client.
     * @param string                       $style       Delivery style instruction or preset.
     * @return string|WP_Error Binary audio string or WP_Error on failure.
     */
    public function synthesize_turn( string $text, string $voice_model = '', float $speed = 1.0, float $pitch = 0.0, ?PressHub_AI_API_Client $api_client = null, string $style = 'formal' ) {
        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'tts' );
        }

        if ( empty( $voice_model ) ) {
            $voice_model = 'Kore';
        }
        if ( empty( $style ) ) {
            $style = (string) get_option( self::OPTION_STYLE, 'formal' );
        }

        $start_time = microtime( true );
        $result     = $api_client->synthesize_speech_via_gemini( $text, $voice_model, true, $style );
        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
        $char_count  = mb_strlen( $text );

        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            if ( is_wp_error( $result ) ) {
                PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', $voice_model, $char_count, $duration_ms, 'error', $result->get_error_message() );
            } else {
                PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', $voice_model, $char_count, $duration_ms, 'success', null );
            }
        }

        return $result;
    }

    /**
     * Sideload binary audio data into WordPress Media Library.
     *
     * @param string $filename    Target filename (e.g. 'podcast-2026-08-26.mp3' or 'podcast-2026-08-26.wav').
     * @param string $binary_data Binary audio bytes.
     * @param int    $post_id     Optional parent post ID.
     * @param string $title       Attachment title.
     * @return array|WP_Error Array with ['id' => int, 'url' => string] or WP_Error.
     */
    public function sideload_audio_file( string $filename, string $binary_data, int $post_id = 0, string $title = '' ) {
        // Auto-detect extension from binary header
        $is_wav = ( strlen( $binary_data ) >= 4 && 'RIFF' === substr( $binary_data, 0, 4 ) );
        if ( $is_wav ) {
            if ( preg_match( '/\.mp3$/i', $filename ) ) {
                $filename = preg_replace( '/\.mp3$/i', '.wav', $filename );
            } elseif ( ! preg_match( '/\.wav$/i', $filename ) ) {
                $filename .= '.wav';
            }
        } else {
            if ( ! preg_match( '/\.mp3$/i', $filename ) ) {
                $filename .= '.mp3';
            }
        }

        $filepath = trailingslashit( get_temp_dir() ) . $filename;
        file_put_contents( $filepath, $binary_data );

        if ( defined( 'ABSPATH' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $filepath,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id, $title );
        @unlink( $filepath );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        $attachment_id = (int) $attachment_id;
        $url = wp_get_attachment_url( $attachment_id );

        return [
            'id'  => $attachment_id,
            'url' => $url ? $url : '',
        ];
    }

    /**
     * Issue #91 — Strip audio-synthesis topic scaffolding from a script
     * before it is paragraphized for publication.
     *
     * Topic markers exist solely to instruct the synthesizer to split
     * synthesis per topic; they have no business appearing in the
     * public-facing transcript. Drop entire lines matching any of the
     * four variants enumerated in PressHub_AI_Podcast_Producer::parse_script():
     *   - [TOPIC_START: Title] ... [TOPIC_END]
     *   - <!-- TOPIC_START: Title --> ... <!-- TOPIC_END -->
     *   - === TOPIC: Title === ... === END ===
     *   - ### TOPIC: Title ... ### END
     * Then collapse runs of blank lines so paragraphization only sees
     * dialogue lines.
     *
     * @param string $script Raw script text (multi-line).
     * @return string Script with topic-marker lines dropped.
     */
    public function strip_topic_markers( string $script ): string {
        if ( '' === trim( $script ) ) {
            return $script;
        }

        $lines = preg_split( '/\r?\n/', $script );
        $out   = [];

        // Mirrors PressHub_AI_Podcast_Producer::parse_script() patterns
        // (L570 for TOPIC_START and L592 for TOPIC_END) so the parser
        // and the stripper recognize the same set of variants.
        $topic_start_re = '/^\s*(?:\[|\<\!\-\-|\={2,3}|\#{2,3})?\s*TOPIC(?:_?START)?\s*:[^\]\>\=]*(?:\]|\-\-\>|\={2,3})?\s*$/iu';
        $topic_end_re   = '/^\s*(?:\[|\<\!\-\-)?\s*TOPIC_?END\s*(?:\]|\-\-\>)?\s*$/iu';

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( '' === $trimmed ) {
                $out[] = $line;
                continue;
            }
            if ( preg_match( $topic_start_re, $trimmed ) ) {
                continue;
            }
            if ( preg_match( $topic_end_re, $trimmed ) ) {
                continue;
            }
            $out[] = $line;
        }

        // Collapse runs of 3+ consecutive blank lines into a single blank
        // line so the resulting paragraphization doesn't leave awkward gaps.
        $collapsed = preg_replace( '/\n{3,}/', "\n\n", implode( "\n", $out ) );
        // Trim leading/trailing blank lines.
        return trim( $collapsed );
    }

    /**
     * Format raw dialogue transcript into structured HTML paragraphs.
     *
     * @param string $transcript Raw dialogue script.
     * @return string Formatted HTML.
     */
    public function format_transcript_html( string $transcript ): string {
        // Issue #91 — drop audio-synthesis topic scaffolding before
        // paragraphization so [TOPIC_START] / [TOPIC_END] markers and
        // their HTML/Markdown variants never leak into the published
        // post body.
        $transcript = $this->strip_topic_markers( $transcript );

        $lines = explode( "\n", trim( $transcript ) );
        $paragraphs = [];

        foreach ( $lines as $line ) {
            $t = trim( $line );
            if ( '' === $t ) {
                continue;
            }

            // Match speaker lines like: [Μαρία]: ..., **[Μαρία]:** ..., **Μαρία:** ..., Μαρία: ...
            if ( preg_match( '/^(?:\s*[*_#\s]*)\s*(?:\[|\()?([^\n:\]\)]+)(?:\]|\))?\s*(?:[*_]*)\s*:\s*(?:[*_]*)\s*(.*)$/u', $t, $m ) ) {
                $speaker = trim( preg_replace( '/[^\p{L}\p{N}\s]+/u', '', $m[1] ) );
                $speech  = trim( preg_replace( '/^\*\*|\*\*$/', '', $m[2] ) );
                $paragraphs[] = sprintf( '<p><strong>%s:</strong> %s</p>', esc_html( $speaker ), esc_html( $speech ) );
            } elseif ( 0 !== strpos( $t, '#' ) ) {
                $paragraphs[] = sprintf( '<p>%s</p>', esc_html( $t ) );
            }
        }

        if ( empty( $paragraphs ) ) {
            return PressHub_AI_Markdown::to_html( $transcript );
        }

        return implode( "\n", $paragraphs );
    }

    /**
     * Create WordPress Podcast post with Gutenberg Audio block, transcript, category, and metadata.
     *
     * @param string $audio_url     URL of the audio attachment.
     * @param int    $attachment_id Attachment ID in Media Library.
     * @param string $transcript    Dialogue transcript text.
     * @param string $date          Briefing date (YYYY-MM-DD).
     * @param string $title         Optional post title override.
     * @return int|WP_Error Created post ID or WP_Error on failure.
     */
    public function create_podcast_post( string $audio_url, int $attachment_id, string $transcript, string $date, string $title = '' ) {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        // Format title if empty
        if ( empty( trim( $title ) ) ) {
            $timestamp = strtotime( $date );
            $formatted_date = ( false !== $timestamp )
                ? ( function_exists( 'date_i18n' ) ? date_i18n( 'd/m/Y', $timestamp ) : date( 'd/m/Y', $timestamp ) )
                : $date;
            $title = sprintf( 'Πρωινό Podcast Ενημέρωσης - %s', $formatted_date );
        }

        // Category handling
        $cat_option = get_option( self::OPTION_CATEGORY, 0 );
        $post_category = [];
        if ( ! empty( $cat_option ) && is_numeric( $cat_option ) && (int) $cat_option > 0 ) {
            $post_category = [ (int) $cat_option ];
        } elseif ( ! empty( $cat_option ) && is_array( $cat_option ) ) {
            $post_category = array_map( 'intval', $cat_option );
        }

        // Post status (default pending)
        $status_option = (string) get_option( self::OPTION_STATUS, 'pending' );
        $post_status = ! empty( trim( $status_option ) ) ? trim( $status_option ) : 'pending';

        // Content: WordPress Audio Block + Formatted Transcript
        // Issue #91 — belt-and-braces: strip audio-synthesis topic scaffolding
        // (e.g. [TOPIC_START: ...]) BEFORE format_transcript_html(), so any
        // future entry point that bypasses format_transcript_html() still
        // inherits the defensive stripping and the markers never leak into
        // the published post_content.
        $formatted_transcript = $this->format_transcript_html( $this->strip_topic_markers( $transcript ) );

        $post_content = "<!-- wp:audio {\"id\":{$attachment_id}} -->\n"
            . "<figure class=\"wp-block-audio\"><audio controls src=\"" . esc_url_raw( $audio_url ) . "\"></audio></figure>\n"
            . "<!-- /wp:audio -->\n\n"
            . "<!-- wp:heading {\"level\":3} -->\n"
            . "<h3 class=\"wp-block-heading\">" . __( 'Απομαγνητοφώνηση / Διάλογος', 'presshub-ai-editor' ) . "</h3>\n"
            . "<!-- /wp:heading -->\n\n"
            . $formatted_transcript;

        $postarr = [
            'post_title'   => $title,
            'post_content' => wp_kses_post( $post_content ),
            'post_status'  => $post_status,
            'post_type'    => 'post',
            'meta_input'   => [
                '_presshub_briefing_date'        => $date,
                '_presshub_briefing_type'        => 'podcast',
                '_presshub_audio_attachment_id' => $attachment_id,
                '_presshub_audio_url'           => $audio_url,
            ],
        ];

        if ( ! empty( $post_category ) ) {
            $postarr['post_category'] = $post_category;
        }

        $post_id = wp_insert_post( $postarr, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( empty( $post_id ) || ! is_numeric( $post_id ) ) {
            return new WP_Error( 'post_creation_failed', __( 'Failed to create podcast post.', 'presshub-ai-editor' ) );
        }

        $post_id = (int) $post_id;

        // Explicitly update meta
        update_post_meta( $post_id, '_presshub_briefing_date', $date );
        update_post_meta( $post_id, '_presshub_briefing_type', 'podcast' );
        update_post_meta( $post_id, '_presshub_audio_attachment_id', $attachment_id );
        update_post_meta( $post_id, '_presshub_audio_url', $audio_url );

        return $post_id;
    }

    /**
     * Issue #79 — Read audio-stats meta previously persisted on a podcast
     * post by synthesize_podcast(). Returns a structured array the Briefing
     * Hub aggregator uses to render the Stage 4 status box.
     *
     * Missing/legacy posts gracefully yield empty-string slots so the UI can
     * render "—" or "Unknown" without throwing.
     *
     * @param int $post_id WordPress post ID.
     * @return array{duration_sec:float,filesize:int,sample_rate:int,format:string,engine:string,
     *               female_voice:string,male_voice:string,tertiary_voice:string,
     *               host_count:int,split_by_topic:int,topic_count:int,
     *               attempted_at:string,completed_at:string} Structured audio meta.
     */
    public static function get_audio_meta( int $post_id ): array {
        $defaults = [
            'duration_sec'    => 0.0,
            'filesize'        => 0,
            'sample_rate'     => 0,
            'format'          => '',
            'engine'          => '',
            'female_voice'    => '',
            'male_voice'      => '',
            'tertiary_voice'  => '',
            'host_count'      => 0,
            'split_by_topic'  => 0,
            'topic_count'     => 0,
            'attempted_at'    => '',
            'completed_at'    => '',
        ];

        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return $defaults;
        }

        $engine_value = (string) get_post_meta( $post_id, '_presshub_audio_engine', true );
        $allowed_engines = [ 'gemini', 'google_cloud', 'google_cloud_tts' ];
        if ( ! in_array( $engine_value, $allowed_engines, true ) ) {
            $engine_value = '';
        }

        return [
            'duration_sec'    => round( (float) get_post_meta( $post_id, '_presshub_audio_duration_sec', true ), 2 ),
            'filesize'        => (int) get_post_meta( $post_id, '_presshub_audio_filesize', true ),
            'sample_rate'     => (int) get_post_meta( $post_id, '_presshub_audio_sample_rate', true ),
            'format'          => strtolower( (string) get_post_meta( $post_id, '_presshub_audio_format', true ) ),
            'engine'          => $engine_value,
            'female_voice'    => (string) get_post_meta( $post_id, '_presshub_audio_female_voice', true ),
            'male_voice'      => (string) get_post_meta( $post_id, '_presshub_audio_male_voice', true ),
            'tertiary_voice'  => (string) get_post_meta( $post_id, '_presshub_audio_tertiary_voice', true ),
            'host_count'      => (int) get_post_meta( $post_id, '_presshub_audio_host_count', true ),
            'split_by_topic'  => (int) get_post_meta( $post_id, '_presshub_audio_split_by_topic', true ),
            'topic_count'     => (int) get_post_meta( $post_id, '_presshub_audio_topic_count', true ),
            'attempted_at'    => (string) get_post_meta( $post_id, '_presshub_audio_attempted_at', true ),
            'completed_at'    => (string) get_post_meta( $post_id, '_presshub_audio_completed_at', true ),
        ];
    }

    /**
     * Synthesize Greek daily news briefing podcast end-to-end.
     *
     * In Gemini mode (logosAI replication), performs single-pass natural speech synthesis
     * where the model fluidly acts and alternates speakers in one continuous stream.
     *
     * @param string                       $date          Target briefing date (YYYY-MM-DD).
     * @param string                       $custom_script Optional script text override.
     * @param PressHub_AI_API_Client|null $api_client    Optional API client.
     * @return array|WP_Error Result payload array or WP_Error on failure.
     */
    public function synthesize_podcast( string $date = '', string $custom_script = '', ?PressHub_AI_API_Client $api_client = null ) {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'tts' );
        }

        // 1. Resolve script
        $script = trim( $custom_script );
        if ( empty( $script ) ) {
            $producer = new PressHub_AI_Podcast_Producer();
            $stored = $producer->get_script( $date );
            if ( empty( $stored ) ) {
                return new WP_Error(
                    'no_script',
                    sprintf( __( 'No podcast script found for date: %s. Please generate a script first.', 'presshub-ai-editor' ), $date )
                );
            }
            $script = $stored;
        }

        // 2. Parse turns for metadata & metrics
        $producer      = new PressHub_AI_Podcast_Producer();
        $female_host   = (string) get_option( 'presshub_ai_briefing_host_female', 'Μαρία' );
        $male_host     = (string) get_option( 'presshub_ai_briefing_host_male', 'Νίκος' );
        $tertiary_host = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_tertiary() : (string) get_option( 'presshub_ai_briefing_host_tertiary', 'Κώστας' );
        if ( empty( trim( $female_host ) ) ) {
            $female_host = 'Μαρία';
        }
        if ( empty( trim( $male_host ) ) ) {
            $male_host = 'Νίκος';
        }
        if ( empty( trim( $tertiary_host ) ) ) {
            $tertiary_host = 'Κώστας';
        }

        $turns = $producer->parse_script_turns( $script, $female_host, $male_host, $tertiary_host );

        if ( empty( $turns ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( 'Could not parse turns for audio synthesis on date ' . $date );
            }
            return new WP_Error(
                'invalid_script',
                __( 'Could not parse any speaker turns from the podcast script.', 'presshub-ai-editor' )
            );
        }

        $engine         = (string) get_option( self::OPTION_ENGINE, 'gemini' );
        $split_by_topic = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_audio_split_by_topic() : (bool) (int) get_option( 'presshub_ai_briefing_audio_split_by_topic', 1 );
        $host_count     = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_count() : (int) get_option( 'presshub_ai_briefing_host_count', 2 );

        $female_voice   = $this->get_voice_for_speaker( 'female' );
        $male_voice     = $this->get_voice_for_speaker( 'male' );
        $tertiary_voice = $this->get_voice_for_speaker( 'tertiary' );

        $speed          = (float) get_option( self::OPTION_VOICE_SPEED, 1.0 );
        $pitch          = (float) get_option( self::OPTION_VOICE_PITCH, 0.0 );
        $style_key      = (string) get_option( self::OPTION_STYLE, 'formal' );
        $custom_style   = (string) get_option( self::OPTION_CUSTOM_STYLE, '' );
        $used_style     = ( 'custom' === $style_key && ! empty( $custom_style ) ) ? $custom_style : $style_key;

        // Issue #79 — record the timestamp when audio synthesis was *attempted* so
        // the Briefing Hub stage card can later show "Synthesis Start Attempt"
        // even when the actual synthesis fails partway. completed_at is set
        // once the podcast post is created (i.e. the pipeline finished).
        $podcast_audio_attempted_at = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

        $stitched_audio = '';

        // 1. Topic-Based Audio Synthesis (Splits generation by topic to eliminate neural voice drift)
        if ( $split_by_topic ) {
            $topics = $producer->parse_script_topics( $script, $female_host, $male_host, $tertiary_host );

            if ( ! empty( $topics ) ) {
                $topic_wavs    = [];
                $total_chars   = 0;
                $overall_start = microtime( true );

                foreach ( $topics as $topic_idx => $topic ) {
                    $topic_turns  = $topic['turns'] ?? [];
                    if ( empty( $topic_turns ) ) {
                        continue;
                    }

                    $topic_script = $topic['script'] ?? '';
                    $total_chars += mb_strlen( $topic_script );

                    if ( class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::info( sprintf(
                            '[LogosAI Synthesizer] Synthesizing topic %d/%d ("%s", %d turns, %d chars)...',
                            $topic_idx + 1,
                            count( $topics ),
                            $topic['title'] ?? '',
                            count( $topic_turns ),
                            mb_strlen( $topic_script )
                        ) );
                    }

                    // Issue #80 — Settings-First: emit a structured
                    // "topic" payload log entry for every iteration of the
                    // topic loop when the operator has enabled the TTS
                    // payload debug toggle. This mirrors the per-topic
                    // metadata (index, title, turn-by-turn speaker →
                    // voice mapping, character count, sample rate) so
                    // investigators can correlate voice drift against
                    // which topic / speaker turn was active.
                    if ( class_exists( 'PressHub_AI_Settings_Storage' )
                        && class_exists( 'PressHub_AI_API_Client' )
                        && PressHub_AI_Settings_Storage::get_log_tts_payloads()
                    ) {
                        $turn_assignments = [];
                        foreach ( $topic_turns as $turn_idx => $t ) {
                            $speaker_id = (string) ( $t['speaker'] ?? '' );
                            $speaker_voice = ( 'tertiary' === $speaker_id )
                                ? $tertiary_voice
                                : ( ( 'female' === $speaker_id ) ? $female_voice : $male_voice );
                            $turn_assignments[] = [
                                'turn'   => $turn_idx + 1,
                                'speaker' => $speaker_id,
                                'voice'   => $speaker_voice,
                                'chars'   => mb_strlen( (string) ( $t['text'] ?? '' ) ),
                            ];
                        }
                        $topic_log_entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
                            'topic',
                            [
                                'date'              => $date,
                                'topic_index'       => $topic_idx + 1,
                                'topic_total'       => count( $topics ),
                                'topic_title'       => (string) ( $topic['title'] ?? '' ),
                                'turns_count'       => count( $topic_turns ),
                                'chars'             => mb_strlen( $topic_script ),
                                'host_count'        => $host_count,
                                'female_host'       => $female_host,
                                'male_host'         => $male_host,
                                'tertiary_host'     => $tertiary_host,
                                'female_voice'      => $female_voice,
                                'male_voice'        => $male_voice,
                                'tertiary_voice'    => $tertiary_voice,
                                'style'             => $used_style,
                                'turn_assignments'  => $turn_assignments,
                            ]
                        );
                        PressHub_AI_API_Client::write_tts_payload_log( $topic_log_entry );
                    }

                    if ( 1 === $host_count ) {
                        // Solo anchor
                        $dialogue_lines = [];
                        foreach ( $topic_turns as $t ) {
                            $dialogue_lines[] = $female_host . ': ' . $t['text'];
                        }
                        $formatted_topic_script = implode( "\n\n", $dialogue_lines );

                        $topic_audio = $api_client->synthesize_speech_via_gemini(
                            $formatted_topic_script,
                            $female_voice,
                            true,
                            $used_style,
                            null
                        );
                    } elseif ( 3 === $host_count ) {
                        // 3 Hosts: Gemini multiSpeakerVoiceConfig strictly requires exactly 2 speakers.
                        // Synthesize turns individually and stitch with 250ms natural pause.
                        $chunk_wavs = [];
                        foreach ( $topic_turns as $t ) {
                            $speaker = $t['speaker'];
                            if ( 'tertiary' === $speaker ) {
                                $t_voice = $tertiary_voice;
                            } elseif ( 'female' === $speaker ) {
                                $t_voice = $female_voice;
                            } else {
                                $t_voice = $male_voice;
                            }
                            $turn_res = $this->synthesize_turn( $t['text'], $t_voice, $speed, $pitch, $api_client, $used_style );
                            if ( is_wp_error( $turn_res ) ) {
                                return $turn_res;
                            }
                            $chunk_wavs[] = $turn_res;
                        }
                        $topic_audio = $this->stitch_wav_chunks( $chunk_wavs, 250, 24000 );
                    } else {
                        // 2 Hosts (Default)
                        $has_fem = false;
                        $has_mal = false;
                        foreach ( $topic_turns as $t ) {
                            if ( 'female' === $t['speaker'] ) {
                                $has_fem = true;
                            } elseif ( 'male' === $t['speaker'] ) {
                                $has_mal = true;
                            }
                        }

                        $speaker_configs = null;
                        if ( $has_fem && $has_mal ) {
                            $speaker_configs = [
                                [ 'speaker' => $female_host, 'voice' => $female_voice ],
                                [ 'speaker' => $male_host, 'voice' => $male_voice ],
                            ];
                        }

                        $dialogue_lines = [];
                        foreach ( $topic_turns as $t ) {
                            $label = ( 'female' === $t['speaker'] ) ? $female_host : $male_host;
                            $dialogue_lines[] = '[' . $label . ']: ' . $t['text'];
                        }
                        $formatted_topic_script = implode( "\n\n", $dialogue_lines );

                        $topic_audio = $api_client->synthesize_speech_via_gemini(
                            $formatted_topic_script,
                            $female_voice,
                            true,
                            $used_style,
                            $speaker_configs
                        );

                        // Resilient fallback for this topic if single-pass fails
                        if ( is_wp_error( $topic_audio ) ) {
                            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                                PressHub_AI_Logger::warning( sprintf(
                                    '[LogosAI Synthesizer] Topic %d synthesis failed (%s). Falling back to turn-by-turn.',
                                    $topic_idx + 1,
                                    $topic_audio->get_error_message()
                                ) );
                            }
                            $chunk_wavs = [];
                            foreach ( $topic_turns as $t ) {
                                $t_voice  = ( 'female' === $t['speaker'] ) ? $female_voice : $male_voice;
                                $turn_res = $this->synthesize_turn( $t['text'], $t_voice, $speed, $pitch, $api_client, $used_style );
                                if ( is_wp_error( $turn_res ) ) {
                                    return $turn_res;
                                }
                                $chunk_wavs[] = $turn_res;
                            }
                            $topic_audio = $this->stitch_wav_chunks( $chunk_wavs, 250, 24000 );
                        }
                    }

                    if ( is_wp_error( $topic_audio ) ) {
                        return $topic_audio;
                    }

                    $topic_wavs[] = $topic_audio;
                }

                if ( ! empty( $topic_wavs ) ) {
                    $intro_sfx_setting = PressHub_AI_Settings_Storage::get_briefing_audio_intro_sfx();
                    $trans_sfx_setting = PressHub_AI_Settings_Storage::get_briefing_audio_transition_sfx();
                    $outro_sfx_setting = PressHub_AI_Settings_Storage::get_briefing_audio_outro_sfx();

                    $intro_pcm = $this->get_sfx_pcm( $intro_sfx_setting );
                    $trans_pcm = $this->get_sfx_pcm( $trans_sfx_setting );
                    $outro_pcm = $this->get_sfx_pcm( $outro_sfx_setting );

                    // Prepend intro if present
                    if ( ! empty( $intro_pcm ) && ! empty( $topic_wavs ) ) {
                        // We wrap it in a mock WAV header so it can be stitched as a chunk
                        $topic_wavs = array_merge( [ $this->wrap_pcm_in_wav( $intro_pcm ) ], $topic_wavs );
                    }
                    
                    // Append outro if present
                    if ( ! empty( $outro_pcm ) && ! empty( $topic_wavs ) ) {
                        $topic_wavs[] = $this->wrap_pcm_in_wav( $outro_pcm );
                    }

                    $stitched_audio = $this->stitch_wav_chunks( $topic_wavs, 600, 24000, $trans_pcm );
                    $duration_ms    = (int) round( ( microtime( true ) - $overall_start ) * 1000 );

                    if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                        PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', 'topic_stitched', $total_chars, $duration_ms, 'success', count( $topic_wavs ) . '_topics' );
                    }

                    // Issue #80 — Settings-First: when the TTS payload debug
                    // toggle is enabled, emit a "stitched" entry that captures
                    // the final assembled audio metrics (total bytes,
                    // duration_ms, sample rate, topic count, intro/outro
                    // presence, transition SFX). This makes the issue's
                    // "stitched audio metrics" requirement explicit and
                    // matches the per-topic entries emitted above.
                    if ( class_exists( 'PressHub_AI_Settings_Storage' )
                        && class_exists( 'PressHub_AI_API_Client' )
                        && PressHub_AI_Settings_Storage::get_log_tts_payloads()
                    ) {
                        $stitched_metrics_entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
                            'stitched',
                            [
                                'date'             => $date,
                                'phase'            => 'topic_stitched',
                                'topic_count'      => count( $topics ),
                                'chunks_count'     => count( $topic_wavs ),
                                'total_chars'      => $total_chars,
                                'duration_ms'      => $duration_ms,
                                'audio_bytes'      => strlen( (string) $stitched_audio ),
                                'sample_rate_hz'   => 24000,
                                'pause_ms'         => 600,
                                'has_intro_sfx'    => ! empty( $intro_pcm ),
                                'has_outro_sfx'    => ! empty( $outro_pcm ),
                                'has_transition_sfx' => ! empty( $trans_pcm ),
                                'female_voice'     => $female_voice,
                                'male_voice'       => $male_voice,
                                'tertiary_voice'   => $tertiary_voice,
                                'host_count'       => $host_count,
                            ]
                        );
                        PressHub_AI_API_Client::write_tts_payload_log( $stitched_metrics_entry );
                    }
                }
            }
        }

        // 2. Fallback or Single-Pass Synthesis (when topic splitting is disabled or topic pass produced no audio)
        if ( empty( $stitched_audio ) ) {
            if ( 1 === $host_count ) {
                $dialogue_lines = [];
                foreach ( $turns as $t ) {
                    $dialogue_lines[] = $female_host . ': ' . $t['text'];
                }
                $formatted_script = implode( "\n\n", $dialogue_lines );
                $start_time       = microtime( true );
                $gen_result       = $api_client->synthesize_speech_via_gemini(
                    $formatted_script,
                    $female_voice,
                    true,
                    $used_style,
                    null
                );
                $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
                $char_count  = mb_strlen( $formatted_script );
            } elseif ( 3 === $host_count ) {
                // 3 hosts whole-script turn-by-turn
                $turn_wavs   = [];
                $start_time  = microtime( true );
                $char_count  = 0;
                foreach ( $turns as $turn ) {
                    $speaker    = $turn['speaker'];
                    $turn_voice = ( 'tertiary' === $speaker ) ? $tertiary_voice : ( ( 'female' === $speaker ) ? $female_voice : $male_voice );
                    $char_count += mb_strlen( $turn['text'] );
                    $turn_res   = $this->synthesize_turn( $turn['text'], $turn_voice, $speed, $pitch, $api_client, $used_style );
                    if ( is_wp_error( $turn_res ) ) {
                        return $turn_res;
                    }
                    $turn_wavs[] = $turn_res;
                }
                $duration_ms    = (int) round( ( microtime( true ) - $start_time ) * 1000 );
                $stitched_audio = $this->stitch_wav_chunks( $turn_wavs, 300, 24000 );
                $gen_result     = $stitched_audio;
            } else {
                // 2 hosts (Default)
                $has_female = false;
                $has_male   = false;
                foreach ( $turns as $t ) {
                    if ( 'female' === $t['speaker'] ) {
                        $has_female = true;
                    } elseif ( 'male' === $t['speaker'] ) {
                        $has_male = true;
                    }
                }

                $speaker_configs = null;
                if ( $has_female && $has_male ) {
                    $speaker_configs = [
                        [ 'speaker' => $female_host, 'voice' => $female_voice ],
                        [ 'speaker' => $male_host, 'voice' => $male_voice ],
                    ];
                }

                $dialogue_lines = [];
                foreach ( $turns as $t ) {
                    $speaker_label    = ( 'female' === $t['speaker'] ) ? $female_host : $male_host;
                    $dialogue_lines[] = '[' . $speaker_label . ']: ' . $t['text'];
                }
                $formatted_script = implode( "\n\n", $dialogue_lines );

                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::info( sprintf(
                        '[LogosAI Synthesizer] Synthesizing podcast audio for %s (%d turns, lead: %s [%s], co-host: %s [%s], style: %s)',
                        $date,
                        count( $turns ),
                        $female_host,
                        $female_voice,
                        $male_host,
                        $male_voice,
                        $used_style
                    ) );
                }

                $start_time = microtime( true );
                $gen_result = $api_client->synthesize_speech_via_gemini(
                    $formatted_script,
                    $female_voice,
                    true,
                    $used_style,
                    $speaker_configs
                );
                $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
                $char_count  = mb_strlen( $formatted_script );
            }

            if ( empty( $stitched_audio ) ) {
                if ( is_wp_error( $gen_result ) ) {
                    if ( class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::warning( sprintf(
                            '[LogosAI Synthesizer] Single-pass podcast synthesis failed (%s). Falling back to resilient turn-by-turn synthesis for %d turns.',
                            $gen_result->get_error_message(),
                            count( $turns )
                        ) );
                    }

                    // Resilient Fallback: synthesize each turn individually and stitch WAV chunks
                    $turn_wavs = [];
                    foreach ( $turns as $turn_idx => $turn ) {
                        $turn_voice = ( 'female' === $turn['speaker'] ) ? $female_voice : $male_voice;
                        $turn_res   = $this->synthesize_turn( $turn['text'], $turn_voice, $speed, $pitch, $api_client, $used_style );

                        if ( is_wp_error( $turn_res ) ) {
                            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                                PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', $turn_voice, mb_strlen( $turn['text'] ), 0, 'error', $turn_res->get_error_message() );
                            }
                            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                                PressHub_AI_Logger::error( sprintf(
                                    '[LogosAI Synthesizer] Turn %d synthesis failed during fallback: %s',
                                    $turn_idx + 1,
                                    $turn_res->get_error_message()
                                ) );
                            }
                            return $turn_res;
                        }
                        $turn_wavs[] = $turn_res;
                    }

                    $stitched_audio = $this->stitch_wav_chunks( $turn_wavs, 400, 24000 );
                    if ( empty( $stitched_audio ) ) {
                        return new WP_Error(
                            'stitching_failed',
                            __( 'Failed to stitch synthesized turn audio chunks.', 'presshub-ai-editor' )
                        );
                    }

                    if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                        PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', $female_voice . '+' . $male_voice, $char_count, $duration_ms, 'success', 'fallback_stitched' );
                    }
                } else {
                    if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                        PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', $female_voice . '+' . $male_voice, $char_count, $duration_ms, 'success', null );
                    }
                    $stitched_audio = $gen_result;
                }
            }
        }

        if ( empty( $stitched_audio ) ) {
            return new WP_Error(
                'stitching_failed',
                __( 'Failed to generate synthesized audio stream.', 'presshub-ai-editor' )
            );
        }

        // Save copy to daily briefing storage
        $harvester = new PressHub_AI_News_Harvester();
        $snapshot_dir = $harvester->get_snapshot_dir( $date );
        if ( ! is_dir( $snapshot_dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( $snapshot_dir );
            } else {
                @mkdir( $snapshot_dir, 0755, true );
            }
        }
        $ext = ( strlen( $stitched_audio ) >= 4 && 'RIFF' === substr( $stitched_audio, 0, 4 ) ) ? '.wav' : '.mp3';
        $local_audio_path = trailingslashit( $snapshot_dir ) . 'podcast' . $ext;
        @file_put_contents( $local_audio_path, $stitched_audio );

        // Sideload to Media Library
        $filename = sprintf( 'podcast-briefing-%s-%s%s', $date, uniqid(), $ext );
        $title    = sprintf( __( 'PressHub Daily Briefing Podcast (%s)', 'presshub-ai-editor' ), $date );
        $media    = $this->sideload_audio_file( $filename, $stitched_audio, 0, $title );

        if ( is_wp_error( $media ) ) {
            return $media;
        }

        $attachment_id = (int) $media['id'];
        $audio_url     = (string) $media['url'];

        // Create WordPress Podcast Post
        $post_id = $this->create_podcast_post( $audio_url, $attachment_id, $script, $date );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Issue #79 — persist audio-stats meta on the podcast post so the
        // Briefing Hub stage card can surface playback duration, file size,
        // sample rate, format, voice personas, engine, mode, and topic count.
        // Skipped silently if update_post_meta is unavailable (e.g. unit
        // tests that mock only sideload/create). All values come from
        // Settings-First helpers (PressHub_AI_Settings_Storage::get_*) so
        // operators can re-tune without code changes.
        if ( function_exists( 'update_post_meta' ) && $post_id > 0 ) {
            $audio_bytes    = strlen( (string) $stitched_audio );
            $audio_sample   = 24000; // Gemini TTS default — see synthesize_speech_via_gemini / stitch_wav_chunks
            // PCM = 16-bit signed, mono, so 2 bytes per sample; duration in
            // seconds for raw PCM (24 kHz mono). MP3 fallback uses an
            // estimated 32 kbps ≈ 4000 bytes/sec when bytesize mode heuristic
            // is needed. For WAV filesize, header is 44 bytes.
            $is_wav   = ( strlen( $stitched_audio ) >= 4 && 'RIFF' === substr( $stitched_audio, 0, 4 ) );
            $is_mp3   = ( strlen( $stitched_audio ) >= 3 && 'ID3' === substr( $stitched_audio, 0, 3 ) )
                     || ( strlen( $stitched_audio ) >= 2 && "\xFF\xFB" === substr( $stitched_audio, 0, 2 ) );
            $format   = $is_wav ? 'wav' : ( $is_mp3 ? 'mp3' : ( $ext ? ltrim( $ext, '.' ) : 'wav' ) );
            if ( $format === 'wav' ) {
                $pcm_seconds    = max( 0.0, ( $audio_bytes - 44 ) / ( $audio_sample * 2 ) );
                $duration_sec   = $pcm_seconds;
            } else {
                $duration_sec   = max( 0.0, $audio_bytes / 4000.0 );
            }

            $host_count_value = class_exists( 'PressHub_AI_Settings_Storage' ) ? (int) PressHub_AI_Settings_Storage::get_briefing_host_count() : (int) ( 1 === (int) $host_count ? 1 : 2 );
            $engine_value     = (string) $engine;
            $split_by_topic_v = $split_by_topic ? 1 : 0;
            $topic_count_v    = ! empty( $topics ) ? count( $topics ) : 0;
            $completed_at_v   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

            update_post_meta( (int) $post_id, '_presshub_audio_duration_sec', round( (float) $duration_sec, 2 ) );
            update_post_meta( (int) $post_id, '_presshub_audio_filesize', (int) $audio_bytes );
            update_post_meta( (int) $post_id, '_presshub_audio_sample_rate', (int) $audio_sample );
            update_post_meta( (int) $post_id, '_presshub_audio_format', (string) $format );
            update_post_meta( (int) $post_id, '_presshub_audio_engine', $engine_value );
            update_post_meta( (int) $post_id, '_presshub_audio_female_voice', (string) $female_voice );
            update_post_meta( (int) $post_id, '_presshub_audio_male_voice', (string) $male_voice );
            update_post_meta( (int) $post_id, '_presshub_audio_tertiary_voice', (string) $tertiary_voice );
            update_post_meta( (int) $post_id, '_presshub_audio_host_count', (int) $host_count_value );
            update_post_meta( (int) $post_id, '_presshub_audio_split_by_topic', (int) $split_by_topic_v );
            update_post_meta( (int) $post_id, '_presshub_audio_topic_count', (int) $topic_count_v );
            update_post_meta( (int) $post_id, '_presshub_audio_attempted_at', (string) $podcast_audio_attempted_at );
            update_post_meta( (int) $post_id, '_presshub_audio_completed_at', (string) $completed_at_v );
        }

        $podcast_audio_completed_at = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

        return [
            'success'         => true,
            'post_id'         => $post_id,
            'attachment_id'   => $attachment_id,
            'audio_url'       => $audio_url,
            'date'            => $date,
            'turns_count'     => count( $turns ),
            'audio_size'      => strlen( $stitched_audio ),
            'audio_data'      => $stitched_audio,
            // Issue #79 — surface audio stats in the result payload so the AJAX
            // handler can echo them into the response without re-querying.
            'engine'          => (string) $engine,
            'female_voice'    => (string) $female_voice,
            'male_voice'      => (string) $male_voice,
            'tertiary_voice'  => (string) $tertiary_voice,
            'host_count'      => (int) $host_count,
            'sample_rate'     => 24000,
            'format'          => (string) ( $is_wav ? 'wav' : ( $is_mp3 ? 'mp3' : ltrim( $ext, '.' ) ) ),
            'attempted_at'    => (string) $podcast_audio_attempted_at,
            'completed_at'    => (string) $podcast_audio_completed_at,
        ];
    }

    private function get_sfx_pcm( string $sfx_setting ): string {
        if ( empty( $sfx_setting ) || 'silence' === $sfx_setting ) {
            return '';
        }

        $sfx_file = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/audio/' . basename( $sfx_setting );
        if ( ! file_exists( $sfx_file ) ) {
            return '';
        }

        $is_mp3 = ( strtolower( pathinfo( $sfx_file, PATHINFO_EXTENSION ) ) === 'mp3' );
        $needs_ffmpeg = $is_mp3;

        if ( ! $needs_ffmpeg ) {
            $header = file_get_contents( $sfx_file, false, null, 0, 44 );
            if ( strlen( $header ) >= 44 && 'RIFF' === substr( $header, 0, 4 ) ) {
                $fmt_chunk = substr( $header, 12, 4 );
                if ( 'fmt ' === $fmt_chunk ) {
                    $channels = unpack( 'v', substr( $header, 22, 2 ) )[1] ?? 0;
                    $sample_rate = unpack( 'V', substr( $header, 24, 4 ) )[1] ?? 0;
                    if ( 1 !== $channels || 24000 !== $sample_rate ) {
                        $needs_ffmpeg = true;
                    }
                }
            }
        }

        $sfx_wav = '';

        if ( $needs_ffmpeg ) {
            $tmp_wav = wp_temp_dir() . '/sfx_tmp_' . uniqid() . '.wav';
            $cmd = 'ffmpeg -i ' . escapeshellarg( $sfx_file ) . ' -ar 24000 -ac 1 -c:a pcm_s16le -f wav -y ' . escapeshellarg( $tmp_wav ) . ' 2>&1';
            @shell_exec( $cmd );
            if ( file_exists( $tmp_wav ) ) {
                $sfx_wav = file_get_contents( $tmp_wav );
                @unlink( $tmp_wav );
            } else {
                error_log( 'PressHub AI: Failed to decode/resample SFX using ffmpeg.' );
            }
        } else {
            $sfx_wav = file_get_contents( $sfx_file );
        }

        if ( ! empty( $sfx_wav ) && strlen( $sfx_wav ) >= 44 && 'RIFF' === substr( $sfx_wav, 0, 4 ) ) {
            $offset = 12;
            $len = strlen( $sfx_wav );
            while ( $offset + 8 <= $len ) {
                $chunk_id = substr( $sfx_wav, $offset, 4 );
                $chunk_size = unpack( 'V', substr( $sfx_wav, $offset + 4, 4 ) )[1] ?? 0;
                
                if ( 'data' === $chunk_id ) {
                    return substr( $sfx_wav, $offset + 8, $chunk_size > 0 ? $chunk_size : null );
                }
                
                $offset += 8 + $chunk_size;
            }
            return substr( $sfx_wav, 44 );
        }

        return '';
    }

    private function wrap_pcm_in_wav( string $pcm_data, int $sample_rate = 24000 ): string {
        if ( empty( $pcm_data ) ) {
            return '';
        }

        $data_len = strlen( $pcm_data );
        $channels = 1;
        $bits_per_sample = 16;
        $byte_rate = $sample_rate * $channels * ( $bits_per_sample / 8 );
        $block_align = $channels * ( $bits_per_sample / 8 );
        $chunk_size = 36 + $data_len;

        $header = pack(
            'A4VA4A4VvvVVvvA4V',
            'RIFF',
            $chunk_size,
            'WAVE',
            'fmt ',
            16,
            1,
            $channels,
            $sample_rate,
            $byte_rate,
            $block_align,
            $bits_per_sample,
            'data',
            $data_len
        );

        return $header . $pcm_data;
    }
}
