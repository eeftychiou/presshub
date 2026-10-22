<?php
/**
 * TDD Unit Tests for Gemini Speech API Payload Structure & Live Tester.
 *
 * Covers:
 *   1. Settings-First Configuration:
 *      - presshub_ai_briefing_tts_payload_mode (default 'structured', valid: 'structured', 'system_instruction', 'speech_metadata', 'legacy')
 *      - presshub_ai_briefing_tts_system_instruction (external asset fallback, customizable)
 *      - resolve_briefing_tts_style_instruction (preset styles + custom prompt)
 *      - get_voice_speed (0.85 to 1.25) and get_voice_pitch (-4.0 to 4.0)
 *   2. Gemini Speech API Request Body Construction:
 *      - Modern structured mode: clean transcript in text (never narrates prompt prefix), systemInstruction, speech_metadata on turns
 *      - Multi-speaker speakerVoiceConfigs mapping Presenter 1 & 2
 *      - Single-speaker prebuiltVoiceConfig
 *      - System Instruction only mode
 *      - Speech metadata only mode
 *      - Legacy mode (backward compatibility test)
 *   3. Live Test Speech Synthesis Client Endpoint:
 *      - test_synthesize_speech returns audio_data_url and full json_payload
 *   4. AJAX Endpoints & Capability Gates:
 *      - presshub_ai_preview_tts_payload
 *      - presshub_ai_test_tts_synthesis
 *      - Authorization gates (requires edit_others_posts)
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once __DIR__ . '/../presshub-ai-editor.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class GeminiTTSPayloadTest
{
    public static function run(): void {
        $failures = [];

        // =========================================================================
        // Case 1: Settings-First Payload Mode & System Instruction Configuration
        // =========================================================================
        self::reset_world();

        // 1a: Default payload mode
        if ( 'structured' !== PressHub_AI_Settings_Storage::get_briefing_tts_payload_mode() ) {
            $failures[] = 'get_briefing_tts_payload_mode() should default to "structured".';
        }

        // 1b: Sanitization of valid and invalid payload modes
        if ( 'structured' !== PressHub_AI_Settings_Storage::sanitize_briefing_tts_payload_mode( 'structured' ) ) {
            $failures[] = 'sanitize_briefing_tts_payload_mode("structured") failed.';
        }
        if ( 'hybrid_instruction' !== PressHub_AI_Settings_Storage::sanitize_briefing_tts_payload_mode( 'hybrid_instruction' ) ) {
            $failures[] = 'sanitize_briefing_tts_payload_mode("hybrid_instruction") failed.';
        }
        if ( 'legacy' !== PressHub_AI_Settings_Storage::sanitize_briefing_tts_payload_mode( 'legacy' ) ) {
            $failures[] = 'sanitize_briefing_tts_payload_mode("legacy") failed.';
        }
        if ( 'structured' !== PressHub_AI_Settings_Storage::sanitize_briefing_tts_payload_mode( 'invalid_unknown_mode' ) ) {
            $failures[] = 'sanitize_briefing_tts_payload_mode("invalid_unknown_mode") should sanitize to "structured".';
        }

        // 1c: Style String Composer
        $composed = PressHub_AI_API_Client::compose_tts_style_string( 'Professional news broadcast tone', 1.15, 1.0 );
        if ( false === strpos( $composed, 'Speaking rate: 1.15x' ) ) {
            $failures[] = 'compose_tts_style_string() must include speaking rate.';
        }
        if ( false === strpos( $composed, 'Voice pitch: +1.0' ) ) {
            $failures[] = 'compose_tts_style_string() must include pitch.';
        }

        // 1d: System instruction external asset loading
        $default_instruction = PressHub_AI_Settings_Storage::default_briefing_tts_system_instruction();
        if ( empty( $default_instruction ) || false === strpos( $default_instruction, '{style_instruction}' ) ) {
            $failures[] = 'default_briefing_tts_system_instruction() must load template with {style_instruction} placeholder.';
        }

        // 1e: When option is empty, get_briefing_tts_system_instruction() returns default asset
        delete_option( 'presshub_ai_briefing_tts_system_instruction' );
        if ( PressHub_AI_Settings_Storage::get_briefing_tts_system_instruction() !== $default_instruction ) {
            $failures[] = 'get_briefing_tts_system_instruction() should fall back to default template when empty.';
        }

        // 1f: Custom system instruction override
        update_option( 'presshub_ai_briefing_tts_system_instruction', 'Custom instruction: {style_instruction}' );
        if ( 'Custom instruction: {style_instruction}' !== PressHub_AI_Settings_Storage::get_briefing_tts_system_instruction() ) {
            $failures[] = 'get_briefing_tts_system_instruction() did not return custom override.';
        }

        // 1g: Style instruction resolver
        $formal_style = PressHub_AI_Settings_Storage::resolve_briefing_tts_style_instruction( 'formal' );
        if ( empty( $formal_style ) || false === strpos( $formal_style, 'professional' ) ) {
            $failures[] = 'resolve_briefing_tts_style_instruction("formal") must return formal style directive.';
        }

        $custom_style_inst = PressHub_AI_Settings_Storage::resolve_briefing_tts_style_instruction( 'custom', 'Custom tone in Greek:' );
        if ( 'Custom tone in Greek:' !== $custom_style_inst ) {
            $failures[] = 'resolve_briefing_tts_style_instruction("custom") must return custom style string.';
        }

        // 1h: Speed and Pitch tuning getters
        update_option( 'presshub_ai_briefing_voice_speed', 1.15 );
        if ( 1.15 !== PressHub_AI_Settings_Storage::get_voice_speed() ) {
            $failures[] = 'get_voice_speed() failed to return 1.15.';
        }
        update_option( 'presshub_ai_briefing_voice_speed', 2.5 ); // out of bounds
        if ( 1.25 !== PressHub_AI_Settings_Storage::get_voice_speed() ) {
            $failures[] = 'get_voice_speed() must clamp values > 1.25 to 1.25.';
        }

        update_option( 'presshub_ai_briefing_voice_pitch', -2.0 );
        if ( -2.0 !== PressHub_AI_Settings_Storage::get_voice_pitch() ) {
            $failures[] = 'get_voice_pitch() failed to return -2.0.';
        }

        // =========================================================================
        // Case 2: Gemini TTS Request Body Construction (Zero Prompt Narration)
        // =========================================================================
        self::reset_world();
        $client = new PressHub_AI_API_Client();

        $dialogue_text = "Presenter 1: Καλημέρα σας από το στούντιο.\nPresenter 2: Καλημέρα σας. Ξεκινάμε με τις οικονομικές εξελίξεις.";

        // 2a: Official Gemini 3.8 TTS REST Standard (structured mode)
        $multi_speakers = [
            [
                'speaker'     => 'Presenter 1',
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [ 'voiceName' => 'Charon' ],
                ],
            ],
            [
                'speaker'     => 'Presenter 2',
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [ 'voiceName' => 'Kore' ],
                ],
            ],
        ];

        $body_structured = $client->build_gemini_tts_request_body(
            $dialogue_text,
            'Charon',
            true,   // is_multi_speaker
            $multi_speakers,
            'formal',
            1.05,   // speed
            0.5,    // pitch
            'structured'
        );

        // In official Gemini 3.8 TTS REST standard, systemInstruction is omitted
        if ( isset( $body_structured['systemInstruction'] ) ) {
            $failures[] = 'Official Gemini 3.8 TTS REST standard (structured mode) must omit top-level systemInstruction.';
        }

        // CRITICAL INVARIANT: Script text MUST NOT contain the prompt instruction!
        if ( empty( $body_structured['contents'][0]['parts'] ) ) {
            $failures[] = 'contents[0].parts is empty in structured mode.';
        } else {
            $parts = $body_structured['contents'][0]['parts'];
            if ( count( $parts ) < 2 ) {
                $failures[] = 'Expected at least 2 turns in structured dialogue mode.';
            }

            foreach ( $parts as $idx => $part ) {
                // Must NOT contain the narrated prompt prefix
                if ( false !== strpos( $part['text'], 'Say in a professional' ) || false !== strpos( $part['text'], 'broadcast tone' ) ) {
                    $failures[] = "Turn {$idx} contains prompt instruction in text! Prompt would be narrated aloud!";
                }

                // Check speech_metadata
                if ( empty( $part['speech_metadata'] ) ) {
                    $failures[] = "Turn {$idx} is missing speech_metadata in structured mode.";
                } elseif ( empty( $part['speech_metadata']['speaker'] ) || empty( $part['speech_metadata']['style'] ) ) {
                    $failures[] = "Turn {$idx} speech_metadata missing speaker or style.";
                }
            }

            // Check speaker assignment in speech_metadata
            if ( ( $parts[0]['speech_metadata']['speaker'] ?? '' ) !== 'Presenter 1' ) {
                $failures[] = 'Turn 0 speaker should be Presenter 1.';
            }
            if ( ( $parts[1]['speech_metadata']['speaker'] ?? '' ) !== 'Presenter 2' ) {
                $failures[] = 'Turn 1 speaker should be Presenter 2.';
            }

            // CRITICAL: Gemini multiSpeakerVoiceConfig requires inline speaker label in text part!
            if ( 0 !== strpos( $parts[0]['text'], 'Presenter 1: ' ) ) {
                $failures[] = 'Turn 0 text must retain canonical prefix "Presenter 1: "; got: ' . $parts[0]['text'];
            }
            if ( 0 !== strpos( $parts[1]['text'], 'Presenter 2: ' ) ) {
                $failures[] = 'Turn 1 text must retain canonical prefix "Presenter 2: "; got: ' . $parts[1]['text'];
            }

            // Check speed and pitch composed into style
            $turn_style = $parts[0]['speech_metadata']['style'] ?? '';
            if ( false === strpos( $turn_style, '1.05' ) ) {
                $failures[] = 'Turn 0 style must include composed speaking rate 1.05.';
            }
            if ( false === strpos( $turn_style, '+0.5' ) ) {
                $failures[] = 'Turn 0 style must include composed pitch +0.5.';
            }
        }

        // Check multi-speaker voice configuration
        $voice_configs = $body_structured['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'] ?? [];
        if ( count( $voice_configs ) !== 2 ) {
            $failures[] = 'Expected 2 speakerVoiceConfigs for 2-host podcast.';
        } else {
            if ( ( $voice_configs[0]['speaker'] ?? '' ) !== 'Presenter 1' || ( $voice_configs[0]['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? '' ) !== 'Charon' ) {
                $failures[] = 'Presenter 1 voice config must map to Charon.';
            }
            if ( ( $voice_configs[1]['speaker'] ?? '' ) !== 'Presenter 2' || ( $voice_configs[1]['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? '' ) !== 'Kore' ) {
                $failures[] = 'Presenter 2 voice config must map to Kore.';
            }
        }

        // 2a-2: Greek and bracketed speaker tag normalization to canonical presenter labels
        $greek_dialogue = "[Ομιλητής 1]: Πρώτη είδηση.\n[Παρουσιαστής 2]: Δεύτερη είδηση.";
        $body_greek = $client->build_gemini_tts_request_body(
            $greek_dialogue,
            'Charon',
            true,
            $multi_speakers,
            'formal',
            1.0,
            0.0,
            'structured'
        );
        $greek_parts = $body_greek['contents'][0]['parts'] ?? [];
        if ( count( $greek_parts ) !== 2 ) {
            $failures[] = 'Greek dialogue turns count mismatch; expected 2, got: ' . count( $greek_parts );
        } else {
            if ( 0 !== strpos( $greek_parts[0]['text'], 'Presenter 1: ' ) ) {
                $failures[] = 'Greek turn 0 text should normalize to "Presenter 1: "; got: ' . ( $greek_parts[0]['text'] ?? '' );
            }
            if ( 0 !== strpos( $greek_parts[1]['text'], 'Presenter 2: ' ) ) {
                $failures[] = 'Greek turn 1 text should normalize to "Presenter 2: "; got: ' . ( $greek_parts[1]['text'] ?? '' );
            }
            if ( ( $greek_parts[0]['speech_metadata']['speaker'] ?? '' ) !== 'Presenter 1' ) {
                $failures[] = 'Greek turn 0 speech_metadata.speaker mismatch; got: ' . ( $greek_parts[0]['speech_metadata']['speaker'] ?? '' );
            }
            if ( ( $greek_parts[1]['speech_metadata']['speaker'] ?? '' ) !== 'Presenter 2' ) {
                $failures[] = 'Greek turn 1 speech_metadata.speaker mismatch; got: ' . ( $greek_parts[1]['speech_metadata']['speaker'] ?? '' );
            }
        }

        // 2b: Hybrid Instruction Mode Verification
        $body_hybrid = $client->build_gemini_tts_request_body(
            $dialogue_text,
            'Charon',
            true,
            $multi_speakers,
            'formal',
            1.05,
            0.5,
            'hybrid_instruction'
        );
        if ( empty( $body_hybrid['systemInstruction']['parts'][0]['text'] ) ) {
            $failures[] = 'Hybrid instruction mode must include systemInstruction.';
        } else {
            $sys_text = $body_hybrid['systemInstruction']['parts'][0]['text'];
            if ( false === strpos( $sys_text, 'Charon' ) || false === strpos( $sys_text, 'Kore' ) ) {
                $failures[] = 'Hybrid systemInstruction must include speaker personas.';
            }
        }
        // 2c: Single Presenter Structured Mode
        $single_text = 'Καλημέρα σας. Αυτή είναι η πρωινή ενημέρωση.';
        $body_single = $client->build_gemini_tts_request_body(
            $single_text,
            'Charon',
            false,  // single host
            [],
            'formal',
            1.0,
            0.0,
            'structured'
        );

        if ( ! isset( $body_single['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ) ) {
            $failures[] = 'Single host mode should use prebuiltVoiceConfig instead of multiSpeakerVoiceConfig.';
        } elseif ( $body_single['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] !== 'Charon' ) {
            $failures[] = 'Single host prebuiltVoiceConfig should be Charon.';
        }

        // 2c: Legacy Mode Verification (verifies backward compatibility when explicitly chosen)
        $body_legacy = $client->build_gemini_tts_request_body(
            $single_text,
            'Charon',
            false,
            [],
            'formal',
            1.0,
            0.0,
            'legacy'
        );

        if ( isset( $body_legacy['systemInstruction'] ) ) {
            $failures[] = 'Legacy mode should not include systemInstruction.';
        }
        $legacy_text = $body_legacy['contents'][0]['parts'][0]['text'] ?? '';
        if ( false === strpos( $legacy_text, 'Say in a professional' ) ) {
            $failures[] = 'Legacy mode should prepend instruction string.';
        }

        // =========================================================================
        // Case 3: Live Synthesis Test Method (test_synthesize_speech)
        // =========================================================================
        self::reset_world();
        update_option( 'presshub_ai_briefing_tts_api_key', 'test-gemini-key-12345' );

        // Mock wp_remote_post to return a fake base64 audio response via CAPTURE_FILTER
        $GLOBALS['CAPTURE_FILTER'] = function() {
            return [
                'response' => [ 'code' => 200, 'message' => 'OK' ],
                'body'     => json_encode( [
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'inlineData' => [
                                            'mimeType' => 'audio/wav',
                                            'data'     => base64_encode( 'RIFF....WAVEfmt test audio data' ),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ] ),
            ];
        };

        $test_result = $client->test_synthesize_speech( [
            'text'         => 'Δοκιμή ήχου.',
            'speaker1'     => 'Charon',
            'speaker2'     => 'Kore',
            'two_hosts'    => false,
            'speed'        => 1.0,
            'pitch'        => 0.0,
            'style'        => 'formal',
            'payload_mode' => 'structured',
        ] );

        if ( is_wp_error( $test_result ) ) {
            $failures[] = 'test_synthesize_speech() returned unexpected WP_Error: ' . $test_result->get_error_message();
        } else {
            if ( empty( $test_result['audio_data_url'] ) || 0 !== strpos( $test_result['audio_data_url'], 'data:audio/wav;base64,' ) ) {
                $failures[] = 'test_synthesize_speech() must return audio_data_url starting with data:audio/wav;base64,.';
            }
            if ( empty( $test_result['json_payload'] ) || ! is_array( $test_result['json_payload'] ) ) {
                $failures[] = 'test_synthesize_speech() must return json_payload array.';
            }
            if ( empty( $test_result['duration_ms'] ) ) {
                $failures[] = 'test_synthesize_speech() must return duration_ms.';
            }
        }

        // =========================================================================
        // Case 4: AJAX Handlers & Capability Gates
        // =========================================================================
        self::reset_world();
        $ajax = new PressHub_AI_Ajax_Handlers();

        // 4a: Preview TTS Payload AJAX handler
        $_POST = [
            'nonce'        => 'presshub_ai_nonce',
            'text'         => "Ομιλητής 1: Δοκιμή.\nΟμιλητής 2: Απάντηση.",
            'speaker1'     => 'Charon',
            'speaker2'     => 'Kore',
            'two_hosts'    => '1',
            'speed'        => '1.10',
            'pitch'        => '-1.0',
            'style'        => 'energetic',
            'payload_mode' => 'structured',
        ];

        $GLOBALS['JSON_RESPONSES'] = [];
        try {
            $ajax->preview_tts_payload();
        } catch ( Throwable $e ) {}

        $resp = ! empty( $GLOBALS['JSON_RESPONSES'] ) ? end( $GLOBALS['JSON_RESPONSES'] ) : [];
        if ( empty( $resp['success'] ) || empty( $resp['data']['payload'] ) || empty( $resp['data']['json_formatted'] ) ) {
            $failures[] = 'preview_tts_payload() failed to return valid JSON payload: ' . var_export( $resp, true );
        } else {
            // In structured mode: systemInstruction is omitted and speed/pitch are in speech_metadata.style
            if ( isset( $resp['data']['payload']['systemInstruction'] ) ) {
                $failures[] = 'Structured preview should not include systemInstruction.';
            }
            $preview_style = $resp['data']['payload']['contents'][0]['parts'][0]['speech_metadata']['style'] ?? '';
            if ( false === strpos( $preview_style, '1.10' ) ) {
                $failures[] = 'preview_tts_payload() did not reflect requested speed in speech_metadata.style.';
            }
            if ( false === strpos( $preview_style, '-1.0' ) ) {
                $failures[] = 'preview_tts_payload() did not reflect requested pitch in speech_metadata.style.';
            }
        }

        // 4b: Permission Gate — Authors denied, Editors allowed
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ]; // Simple author lacking edit_others_posts
        $GLOBALS['JSON_RESPONSES']    = [];
        try {
            $ajax->preview_tts_payload();
        } catch ( Throwable $e ) {}

        $denied_resp = ! empty( $GLOBALS['JSON_RESPONSES'] ) ? end( $GLOBALS['JSON_RESPONSES'] ) : [];
        if ( ! empty( $denied_resp['success'] ) ) {
            $failures[] = 'preview_tts_payload() should reject simple author without edit_others_posts.';
        }

        // =========================================================================
        // Result Summary
        // =========================================================================
        if ( ! empty( $failures ) ) {
            echo "FAIL: " . count( $failures ) . " failures:\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }

        echo "GeminiTTSPayloadTest: OK\n";
    }

    private static function reset_world(): void {
        $GLOBALS['OPTIONS_STORE']            = [];
        $GLOBALS['POSTS_STORE']              = [];
        $GLOBALS['POST_META_STORE']          = [];
        $GLOBALS['CURRENT_USER_CAPS']        = [ 'manage_options', 'edit_others_posts' ];
        $GLOBALS['CURRENT_USER_ID']          = 1;
        $GLOBALS['NONCE_VALID']              = true;
        $GLOBALS['CAPTURE_FILTER']           = null;
        $GLOBALS['JSON_RESPONSES']           = [];
        $GLOBALS['CAPTURED_REQUESTS']        = [];
    }
}

GeminiTTSPayloadTest::run();
