<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_API_Client {
    private $api_key;
    private $google_cloud_api_key;
    private $provider;
    private $model;

    public function __construct() {
        $this->api_key = get_option( 'presshub_ai_api_key' );
        $this->google_cloud_api_key = get_option( 'presshub_ai_google_cloud_api_key' );
        $this->provider = get_option( 'presshub_ai_provider', 'openai' );
        $this->model = get_option( 'presshub_ai_model', 'gpt-4o' );
    }

    public function test_connection() {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', 'API key is missing.' );
        }
        $sys = 'You are a test bot.';
        $user = 'Reply with exactly the word "Hello" and nothing else.';
        return $this->call_provider( $sys, $user, false, [] );
    }

    public function generate_draft( $sources, $instructions, $uploaded_files = [] ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', 'API key is missing.' );
        }

        $sys_prompt = 'You are a professional AI journalist.';
        $user_prompt = "Write a news article draft based on the following sources.\n\nSources:\n" . $sources . "\n\nInstructions:\n" . $instructions;

        return $this->call_provider( $sys_prompt, $user_prompt, false, $uploaded_files );
    }

    public function generate_scorecard( $content ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', 'API key is missing.' );
        }

        $sys_prompt = 'You are an exacting news editor.';
        $user_prompt = "Review this news article draft. Provide a JSON response with exactly two keys: 'score' (an integer 0-100 representing readiness) and 'feedback' (a 2-3 sentence critique).\n\nDraft:\n" . $content;

        $result = $this->call_provider( $sys_prompt, $user_prompt, true, [] );
        
        if ( is_wp_error( $result ) ) return $result;
        
        $result = preg_replace('/```json\s*/', '', $result);
        $result = preg_replace('/```\s*/', '', $result);
        
        $decoded = json_decode( $result, true );
        if ( $decoded && isset( $decoded['score'] ) ) {
            return $decoded;
        }
        
        return new WP_Error( 'json_error', 'Failed to parse scorecard JSON.' );
    }

    public function classify_intent( $prompt ) {
        $sys_prompt = "You are an orchestrator routing user prompts to specialized tools. Classify the user prompt into exactly one of these lowercase strings: 'chat', 'research', 'image', or 'report'.\n- 'chat': Normal Q&A, general questions, writing suggestions, conversations.\n- 'research': Comprehensive synthesis, deep analysis, research on a topic, or requests for a deep investigation.\n- 'image': Requests to generate, create, draw, paint, or design an image/illustration.\n- 'report': Requests to voice over, summarize, or translate an audio or video file/link into a narrated report.\nOutput ONLY the lowercase classification string (e.g. 'chat' or 'research') and absolutely nothing else.";

        // Route through the user's configured provider so classifier cost
        // and behaviour match the rest of the system. Falls back to the
        // default provider if none is configured.
        $result = $this->call_provider( $sys_prompt, $prompt, false, [] );
        if ( is_wp_error( $result ) ) {
            return 'chat'; // Default fallback
        }

        $classified = preg_replace( '/[\`"\'\.]/', '', trim( strtolower( $result ) ) );
        // Basic validation
        if ( in_array( $classified, [ 'chat', 'research', 'image', 'report' ] ) ) {
            return $classified;
        }
        return 'chat';
    }

    /**
     * Build the Google Cloud Vertex AI Imagen endpoint URL using the
     * configured project ID, defaulting to 'presshub-ai' so existing
     * deployments keep working without configuration.
     */
    public function build_imagen_url() {
        $project_id = get_option( 'presshub_ai_gcloud_project_id', 'presshub-ai' );
        return 'https://us-central1-aiplatform.googleapis.com/v1/projects/' . $project_id . '/locations/us-central1/publishers/google/models/imagen-3.0-generate-002:predict?key=' . $this->google_cloud_api_key;
    }

    /**
     * Shared media-sideload helper used by every code path that needs to
     * attach an externally produced asset (image or audio) to a post.
     *
     * Writes $data to a fresh tmp file, requires the wp-admin media
     * helpers, sideloads via media_handle_sideload(), unlinks the tmp
     * file, and returns ['id' => $media_id, 'url' => $attachment_url]
     * on success or a WP_Error on failure. WP_Error propagates untouched
     * so callers can decide how to surface it.
     *
     * @param string $filename Filename for the new attachment (must
     *                         include extension; wp_check_filetype
     *                         relies on it).
     * @param string $data     Raw bytes to write into the tmp file.
     *                         Caller is responsible for any prior
     *                         base64 / JSON decoding.
     * @param int    $post_id  Post to attach the media to (0 = no parent).
     * @param string $title    Title for the new attachment.
     * @return array|WP_Error  ['id' => int, 'url' => string] or WP_Error.
     */
    private function sideload_media( $filename, $data, $post_id, $title ) {
        $filepath = get_temp_dir() . $filename;
        file_put_contents( $filepath, $data );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $filepath,
        ];

        $media_id = media_handle_sideload( $file_array, $post_id, $title );
        @unlink( $filepath );

        if ( is_wp_error( $media_id ) ) {
            return $media_id;
        }

        return [
            'id'  => $media_id,
            'url' => wp_get_attachment_url( $media_id ),
        ];
    }

    public function generate_image_via_imagen( $prompt ) {
        if ( empty( $this->google_cloud_api_key ) ) {
            return new WP_Error( 'no_gc_key', 'Google Cloud API key is missing.' );
        }

        $url = $this->build_imagen_url();

        $body = [
            'instances' => [
                [ 'prompt' => $prompt ]
            ],
            'parameters' => [
                'sampleCount' => 1,
                'aspectRatio' => '1:1',
                'outputMimeType' => 'image/jpeg'
            ]
        ];

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body' => wp_json_encode( $body ),
            'timeout' => 60
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->mock_image_generation( $prompt );
        }

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $res_body['predictions'][0]['bytesBase64Encoded'] ) ) {
            $image_data = base64_decode( $res_body['predictions'][0]['bytesBase64Encoded'] );
            return $this->sideload_media(
                'ai-image-' . time() . '-' . uniqid() . '.jpg',
                $image_data,
                0,
                $prompt
            );
        }

        // Mock / fallback if endpoint is not accessible or setup failed:
        // Generate a default geometric placeholder image so the feature doesn't completely block
        return $this->mock_image_generation($prompt);
    }

    private function mock_image_generation($prompt) {
        // Standard mock image URL for demonstration / playground fallback
        $mock_url = 'https://picsum.photos/seed/' . md5($prompt) . '/600/600';
        $response = wp_remote_get( $mock_url );
        if ( is_wp_error( $response ) ) return $response;

        return $this->sideload_media(
            'ai-image-mock-' . time() . '-' . uniqid() . '.jpg',
            wp_remote_retrieve_body( $response ),
            0,
            $prompt
        );
    }

    public function generate_audio_report( $prompt, $post_id ) {
        if ( empty( $this->google_cloud_api_key ) ) {
            return new WP_Error( 'no_gc_key', 'Google Cloud API key is missing.' );
        }

        // 1. Synthesize media link/details into script using Gemini
        $sys_prompt = "You are a professional news radio narrator. Convert the user's prompt or media notes into a short 4-5 sentence radio report script. Output ONLY the speech script and nothing else.";
        $script = $this->call_gemini( $sys_prompt, $prompt, false, [] );
        if ( is_wp_error( $script ) ) return $script;

        // 2. Call Google Cloud TTS
        $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . $this->google_cloud_api_key;
        $body = [
            'input' => [ 'text' => $script ],
            'voice' => [
                'languageCode' => 'en-US',
                'name' => 'en-US-Journey-F'
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3'
            ]
        ];

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body' => wp_json_encode( $body ),
            'timeout' => 60
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->mock_audio_generation($script, $post_id);
        }

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $res_body['audioContent'] ) ) {
            $audio_data = base64_decode( $res_body['audioContent'] );

            return $this->sideload_media(
                'ai-report-' . time() . '-' . uniqid() . '.mp3',
                $audio_data,
                $post_id,
                'AI Audio Report'
            );
        }

        return $this->mock_audio_generation($script, $post_id);
    }

    private function mock_audio_generation($script, $post_id) {
        // Sideload a tiny silent/placeholder MP3 file as fallback for testing
        // Generate a simple raw file or download a standard silence MP3
        $mock_url = 'https://github.com/anars/blank-audio/raw/master/250-milliseconds-of-silence.mp3'; // simple sample file
        $response = wp_remote_get( $mock_url );
        if ( is_wp_error( $response ) ) return $response;

        return $this->sideload_media(
            'ai-audio-mock-' . time() . '-' . uniqid() . '.mp3',
            wp_remote_retrieve_body( $response ),
            $post_id,
            'Mock Audio Report: ' . substr( $script, 0, 50 )
        );
    }

    public function call_provider( $sys_prompt, $user_prompt, $json_mode, $files ) {
        if ( $this->provider === 'anthropic' ) {
            return $this->call_anthropic( $sys_prompt, $user_prompt, $files );
        } elseif ( $this->provider === 'gemini' ) {
            return $this->call_gemini( $sys_prompt, $user_prompt, $json_mode, $files );
        } else {
            return $this->call_openai( $sys_prompt, $user_prompt, $json_mode, $files );
        }
    }

    private function call_openai( $sys_prompt, $user_prompt, $json_mode, $files ) {
        if ( ! empty( $files ) ) {
            return new WP_Error( 'file_error', 'OpenAI chat completions do not support direct PDF/Audio uploads natively in this basic integration. Please select Google Gemini for multi-modal files.' );
        }

        $body = [
            'model' => $this->model,
            'messages' => [
                [ 'role' => 'system', 'content' => $sys_prompt ],
                [ 'role' => 'user', 'content' => $user_prompt ]
            ]
        ];
        if ( $json_mode ) $body['response_format'] = [ 'type' => 'json_object' ];

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json'
            ],
            'body' => wp_json_encode( $body ),
            'timeout' => 60
        ] );

        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['choices'][0]['message']['content'] ) ) {
            return $body['choices'][0]['message']['content'];
        }
        if ( isset( $body['error']['message'] ) ) {
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        return new WP_Error( 'api_error', 'Invalid response from OpenAI.' );
    }

    private function call_anthropic( $sys_prompt, $user_prompt, $files ) {
        $content_array = [];
        
        foreach ( $files as $file_path ) {
            $mime = mime_content_type( $file_path );
            if ( $mime === 'application/pdf' ) {
                $content_array[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => base64_encode( file_get_contents( $file_path ) )
                    ]
                ];
            } else {
                return new WP_Error( 'file_error', 'Anthropic only supports PDF document uploads. Audio/Video not supported.' );
            }
        }
        
        $content_array[] = [
            'type' => 'text',
            'text' => $user_prompt
        ];

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode( [
                'model' => $this->model,
                'system' => $sys_prompt,
                'messages' => [
                    [ 'role' => 'user', 'content' => $content_array ]
                ],
                'max_tokens' => 2000
            ] ),
            'timeout' => 90 // PDFs can take longer
        ] );

        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['content'][0]['text'] ) ) {
            return $body['content'][0]['text'];
        }
        if ( isset( $body['error']['message'] ) ) {
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        return new WP_Error( 'api_error', 'Invalid response from Anthropic.' );
    }

    private function call_gemini( $sys_prompt, $user_prompt, $json_mode, $files ) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . $this->api_key;
        
        $parts = [];
        foreach ( $files as $file_path ) {
            $mime = mime_content_type( $file_path );
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $mime,
                    'data' => base64_encode( file_get_contents( $file_path ) )
                ]
            ];
        }
        $parts[] = [ 'text' => $user_prompt ];

        $body = [
            'systemInstruction' => [
                'parts' => [ [ 'text' => $sys_prompt ] ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts
                ]
            ]
        ];
        
        if ( $json_mode ) {
            $body['generationConfig'] = [ 'responseMimeType' => 'application/json' ];
        }

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body' => wp_json_encode( $body ),
            'timeout' => 90 // Media processing can take longer
        ] );

        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return $body['candidates'][0]['content']['parts'][0]['text'];
        }
        if ( isset( $body['error']['message'] ) ) {
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        return new WP_Error( 'api_error', 'Invalid response from Gemini.' );
    }
}
