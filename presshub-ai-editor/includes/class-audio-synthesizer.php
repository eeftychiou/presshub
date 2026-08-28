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

        $is_female = (
            'female' === $clean
            || 'host1' === $clean
            || 'host 1' === $clean
            || ( function_exists( 'mb_stripos' ) && false !== mb_stripos( $clean, 'μαρία' ) )
            || false !== stripos( $clean, 'maria' )
            || false !== stripos( $clean, 'female' )
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
     * @param array $wav_or_pcm_buffers List of WAV or raw PCM binary buffers.
     * @param int   $pause_ms           Pause duration in milliseconds between turns (default 400).
     * @param int   $sample_rate        Sample rate in Hz (default 24000).
     * @return string Valid RIFF/WAV binary data.
     */
    public function stitch_wav_chunks( array $wav_or_pcm_buffers, int $pause_ms = 400, int $sample_rate = 24000 ): string {
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

        $combined_pcm = implode( $silence_bytes, $raw_pcm_chunks );
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
            $api_client = new PressHub_AI_API_Client();
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
     * Format raw dialogue transcript into structured HTML paragraphs.
     *
     * @param string $transcript Raw dialogue script.
     * @return string Formatted HTML.
     */
    public function format_transcript_html( string $transcript ): string {
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
            $date = gmdate( 'Y-m-d' );
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
        $formatted_transcript = $this->format_transcript_html( $transcript );

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
            $date = gmdate( 'Y-m-d' );
        }

        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client();
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
        $producer = new PressHub_AI_Podcast_Producer();
        $turns = $producer->parse_script_turns( $script );

        if ( empty( $turns ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( 'Could not parse turns for audio synthesis on date ' . $date );
            }
            return new WP_Error(
                'invalid_script',
                __( 'Could not parse any speaker turns from the podcast script.', 'presshub-ai-editor' )
            );
        }

        $engine = (string) get_option( self::OPTION_ENGINE, 'gemini' );
        $stitched_audio = '';

        $voice_model  = $this->get_voice_for_speaker( 'female' );
        $style_key    = (string) get_option( self::OPTION_STYLE, 'formal' );
        $custom_style = (string) get_option( self::OPTION_CUSTOM_STYLE, '' );
        $used_style   = ( 'custom' === $style_key && ! empty( $custom_style ) ) ? $custom_style : $style_key;

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( '[LogosAI Synthesizer] Synthesizing podcast audio for %s (%d turns, voice: %s, style: %s)', $date, count( $turns ), $voice_model, $used_style ) );
        }

        $start_time = microtime( true );
        $gen_result = $api_client->synthesize_speech_via_gemini( $script, $voice_model, true, $used_style );
        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
        $char_count  = mb_strlen( $script );

        if ( is_wp_error( $gen_result ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', $voice_model, $char_count, $duration_ms, 'error', $gen_result->get_error_message() );
            }
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( '[LogosAI Synthesizer] Single-pass podcast synthesis error: ' . $gen_result->get_error_message() );
            }
            return $gen_result;
        }

        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', $voice_model, $char_count, $duration_ms, 'success', null );
        }

        $stitched_audio = $gen_result;

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

        return [
            'success'       => true,
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
            'audio_url'     => $audio_url,
            'date'          => $date,
            'turns_count'   => count( $turns ),
            'audio_size'    => strlen( $stitched_audio ),
        ];
    }
}
