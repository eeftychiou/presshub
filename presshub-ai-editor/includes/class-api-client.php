<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-provider-defaults.php';
require_once __DIR__ . '/class-preset-sanitizer.php';
require_once __DIR__ . '/class-preset-store.php';
require_once __DIR__ . '/class-preset-resolver.php';
require_once __DIR__ . '/class-url-fetcher.php';

/**
 * Optional prompt inspection: when the presshub_ai_debug_prompts option
 * (Settings → General → "Log AI prompts") or the presshub_ai_debug_prompts
 * filter (returns true) is active, the exact composed SYSTEM and USER
 * prompts are appended to wp-content/uploads/presshub-ai-debug.log before
 * every provider call, and mirrored via the presshub_ai_prompt_log action
 * so code can hook it (e.g. audit storage).
 *
 * The uploads directory is used so the log is trivially findable via the
 * host file manager and never blocked by wp-content permissions.
 *
 * @since 1.2.3 (filter), 1.2.5 (settings toggle + uploads file)
 */
if ( ! function_exists( 'presshub_ai_log_prompts' ) ) {
    /**
     * Append the composed prompts (and, when $response is passed, the
     * provider's response) to wp-content/uploads/presshub-ai-debug.log.
     *
     * @param string       $endpoint   draft|scorecard|chat|research
     * @param string       $sys_prompt Composed SYSTEM prompt.
     * @param string       $user_prompt Composed USER prompt.
     * @param string|null  $response   Provider text response, or an
     *                                 error string ('ERROR: ...') to log.
     * @param string       $meta       Optional request config line
     *                                 (e.g. "provider=openai model=gpt-4o
     *                                 max_tokens=2000").
     */
    function presshub_ai_log_prompts( $endpoint, $sys_prompt, $user_prompt, $response = null, $meta = '' ) {
        $debug_enabled = apply_filters( 'presshub_ai_debug_prompts', get_option( 'presshub_ai_debug_prompts', '0' ) === '1' );
        if ( ! $debug_enabled ) {
            return;
        }
        $uploads  = wp_upload_dir();
        $log_file = trailingslashit( $uploads['basedir'] ) . 'presshub-ai-debug.log';
        $stamp    = gmdate( 'Y-m-d H:i:s' );
        $entry    = "[$stamp] [$endpoint]";
        if ( '' !== $meta ) {
            $entry .= ' CONFIG: ' . $meta;
        }
        $entry .= " SYSTEM prompt:\n" . $sys_prompt
            . "\n\n[$stamp] [$endpoint] USER prompt:\n" . $user_prompt;
        if ( null !== $response ) {
            $entry .= "\n\n[$stamp] [$endpoint] RESPONSE (" . strlen( (string) $response ) . " chars):\n" . $response;
        }
        $entry .= "\n\n---\n";
        // message_type 3 appends to a file directly (no WP filesystem API
        // needed); @-silenced so a read-only uploads dir can't break the
        // draft request.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors
        @error_log( $entry, 3, $log_file );
        do_action( 'presshub_ai_prompt_log', $endpoint, $sys_prompt, $user_prompt, $response, $meta );
    }
}

class PressHub_AI_API_Client {
    private $api_key;

    /**
     * Request-config line for the debug log: mirrors the constructor's
     * option resolution (provider, model, max_tokens) so truncation is
     * diagnosable at a glance (a saved max_tokens option overrides the
     * provider default).
     */
    public static function current_request_meta(): string {
        $provider   = (string) get_option( 'presshub_ai_provider', 'openai' );
        $model      = (string) get_option( 'presshub_ai_model_' . $provider, '' );
        $max_tokens = (int) get_option( 'presshub_ai_max_tokens_' . $provider, PressHub_AI_Provider_Defaults::default_max_tokens() );
        return 'provider=' . $provider
            . ' model=' . ( '' !== $model ? $model : 'default' )
            . ' max_tokens=' . $max_tokens;
    }
    private $google_cloud_api_key;
    private $provider;
    private $model;
    private $temperature;
    private $max_tokens;
    private $timeout;

    public function __construct() {
        $this->api_key = get_option( 'presshub_ai_api_key' );
        $this->google_cloud_api_key = get_option( 'presshub_ai_google_cloud_api_key' );
        $this->provider = get_option( 'presshub_ai_provider', 'openai' );
        // Per-provider config (P2): the model/tuning are read from the
        // ACTIVE provider's options, with the legacy global model as a
        // fallback for sites that have not run the migration yet.
        $this->model = $this->resolve_model( $this->provider );
        $this->temperature = (float) get_option( 'presshub_ai_temperature_' . $this->provider, PressHub_AI_Provider_Defaults::default_temperature() );
        $this->max_tokens = (int) get_option( 'presshub_ai_max_tokens_' . $this->provider, PressHub_AI_Provider_Defaults::default_max_tokens() );
        $this->timeout = (int) get_option( 'presshub_ai_timeout_' . $this->provider, PressHub_AI_Provider_Defaults::default_timeout( $this->provider ) );
    }

    /**
     * Resolve the model for a provider: per-provider option first, then the
     * legacy global option, then the provider default.
     *
     * A leading 'models/' path fragment is stripped (Low-17) so users can
     * paste full model paths like 'models/gemini-2.0-flash' without the
     * request URL ending up as '/models/models/gemini-2.0-flash:...'.
     */
    private function resolve_model( $provider ): string {
        $model = (string) get_option( 'presshub_ai_model_' . $provider, '' );
        if ( '' === $model ) {
            $model = (string) get_option( 'presshub_ai_model', '' );
        }
        if ( '' === $model ) {
            $model = PressHub_AI_Provider_Defaults::default_model( $provider );
        }
        return preg_replace( '#^models/#', '', $model );
    }

    /**
     * Test the connection for a specific provider (defaults to the active
     * one). Re-snapshots the target provider's config so testing a
     * non-active provider uses its own model/tuning.
     *
     * @param string|null $provider openai|anthropic|gemini
     */
    public function test_connection( $provider = null ) {
        $provider = $provider ? $provider : $this->provider;
        if ( ! in_array( $provider, [ 'openai', 'anthropic', 'gemini' ], true ) ) {
            $provider = 'openai';
        }

        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'API key is missing.', 'presshub-ai-editor' ) );
        }

        $this->provider    = $provider;
        $this->model       = $this->resolve_model( $provider );
        $this->temperature = (float) get_option( 'presshub_ai_temperature_' . $provider, PressHub_AI_Provider_Defaults::default_temperature() );
        $this->max_tokens  = (int) get_option( 'presshub_ai_max_tokens_' . $provider, PressHub_AI_Provider_Defaults::default_max_tokens() );
        $this->timeout     = (int) get_option( 'presshub_ai_timeout_' . $provider, PressHub_AI_Provider_Defaults::default_timeout( $provider ) );

        $sys = 'You are a test bot.';
        $user = 'Reply with exactly the word "Hello" and nothing else.';
        return $this->call_provider( $sys, $user, false, [] );
    }

    public function generate_draft( $sources, $instructions, $uploaded_files = [], $preset_slug = '' ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'API key is missing.', 'presshub-ai-editor' ) );
        }

        $sys_prompt = apply_filters( 'presshub_ai_draft_system_prompt', __( 'You are a professional AI journalist.', 'presshub-ai-editor' ) );

        // Per-author instruction presets (2026-08-15 design §3): the
        // resolver returns at most ONE instruction text to append, or null
        // when no preset applies (unknown/disabled/empty preset, '__none__'
        // sentinel, endpoint gating, or no presets configured). The default
        // '' falls back to the author's own default preset — backward
        // compatible with the pre-preset 3-arg call.
        //
        // C-3 composition order: the filter above runs on the BASE prompt
        // FIRST (so hooks that fully replace the string no longer drop the
        // author's preset), the resolved preset is appended AFTER it, and
        // the presshub_ai_composed_system_prompt filter below sees the
        // final composed string. Legacy contract for
        // presshub_ai_draft_system_prompt: hooks receive the base prompt;
        // use string concatenation (or the composed filter) to affect the
        // preset-augmented prompt.
        $preset = PressHub_AI_Preset_Resolver::resolve_for_user(
            get_current_user_id(),
            'draft',
            $preset_slug
        );
        if ( $preset !== null ) {
            $sys_prompt .= "\n\n" . $preset;
        }

        $sys_prompt = apply_filters( 'presshub_ai_composed_system_prompt', $sys_prompt );

        // URL sourcing (2026-08-16): models cannot browse URLs — fetch and
        // extract each source URL server-side so the article text actually
        // reaches the model (presshub_ai_fetch_urls option / filter).
        $sources = PressHub_AI_URL_Fetcher::process_sources( $sources );

        $user_prompt = "Write a news article draft based on the following sources.\n\nSources:\n" . $sources . "\n\nInstructions:\n" . $instructions;
        $user_prompt = apply_filters( 'presshub_ai_draft_user_prompt', $user_prompt, $sources, $instructions );

        $result = $this->call_provider( $sys_prompt, $user_prompt, false, $uploaded_files );
        presshub_ai_log_prompts( 'draft', $sys_prompt, $user_prompt, is_wp_error( $result ) ? 'ERROR: ' . $result->get_error_message() : $result, self::current_request_meta() );

        return $result;
    }

    public function generate_scorecard( $content ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'API key is missing.', 'presshub-ai-editor' ) );
        }

        $sys_prompt = __( 'You are an exacting news editor.', 'presshub-ai-editor' );
        $sys_prompt = apply_filters( 'presshub_ai_scorecard_system_prompt', $sys_prompt );
        $user_prompt = "Review this news article draft. Provide a JSON response with exactly two keys: 'score' (an integer 0-100 representing readiness) and 'feedback' (a 2-3 sentence critique).\n\nDraft:\n" . $content;

        $result = $this->call_provider( $sys_prompt, $user_prompt, true, [] );
        presshub_ai_log_prompts( 'scorecard', $sys_prompt, $user_prompt, is_wp_error( $result ) ? 'ERROR: ' . $result->get_error_message() : $result, self::current_request_meta() );
        
        if ( is_wp_error( $result ) ) return $result;
        
        $result = preg_replace('/```json\s*/', '', $result);
        $result = preg_replace('/```\s*/', '', $result);
        
        $decoded = json_decode( $result, true );
        if ( $decoded && isset( $decoded['score'] ) ) {
            return $decoded;
        }
        
        return new WP_Error( 'json_error', __( 'Failed to parse scorecard JSON.', 'presshub-ai-editor' ) );
    }

    public function classify_intent( $prompt ) {
        $sys_prompt = __( "You are an orchestrator routing user prompts to specialized tools. Classify the user prompt into exactly one of these lowercase strings: 'chat', 'research', 'image', or 'report'.\n- 'chat': Normal Q&A, general questions, writing suggestions, conversations.\n- 'research': Comprehensive synthesis, deep analysis, research on a topic, or requests for a deep investigation.\n- 'image': Requests to generate, create, draw, paint, or design an image/illustration.\n- 'report': Requests to voice over, summarize, or translate an audio or video file/link into a narrated report.\nOutput ONLY the lowercase classification string (e.g. 'chat' or 'research') and absolutely nothing else.", 'presshub-ai-editor' );
        $sys_prompt = apply_filters( 'presshub_ai_classify_intent_prompt', $sys_prompt );

        // Route through the user's configured provider so classifier cost
        // and behaviour match the rest of the system. Falls back to the
        // default provider if none is configured. Temperature is locked to
        // 0.0 for deterministic classification.
        $result = $this->call_provider( $sys_prompt, $prompt, false, [], 0.0 );
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
     *
     * The API key is deliberately NOT part of the URL (Low-20); it is
     * sent in the x-goog-api-key header by generate_image_via_imagen().
     */
    public function build_imagen_url() {
        $project_id = get_option( 'presshub_ai_gcloud_project_id', 'presshub-ai' );
        $region     = get_option( 'presshub_ai_imagen_region', 'us-central1' );
        return 'https://' . $region . '-aiplatform.googleapis.com/v1/projects/' . $project_id . '/locations/' . $region . '/publishers/google/models/imagen-3.0-generate-002:predict';
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
            return new WP_Error( 'no_gc_key', __( 'Google Cloud API key is missing.', 'presshub-ai-editor' ) );
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
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->google_cloud_api_key,
            ],
            'body' => wp_json_encode( $body ),
            'timeout' => 60
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'PressHub AI [imagen] API error: ' . $response->get_error_message() );
            return $this->mock_image_generation( $prompt );
        }

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $res_body['error']['message'] ) ) {
            // D-3: log the provider's error before falling back to mock.
            error_log( 'PressHub AI [imagen] API error: ' . $res_body['error']['message'] );
        }

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
            return new WP_Error( 'no_gc_key', __( 'Google Cloud API key is missing.', 'presshub-ai-editor' ) );
        }

        // 1. Synthesize media link/details into script using Gemini.
        // Temperature locked to 0.0 so the narration script is deterministic.
        $sys_prompt = __( "You are a professional news radio narrator. Convert the user's prompt or media notes into a short 4-5 sentence radio report script. Output ONLY the speech script and nothing else.", 'presshub-ai-editor' );
        $sys_prompt = apply_filters( 'presshub_ai_audio_script_prompt', $sys_prompt );
        $script = $this->call_gemini( $sys_prompt, $prompt, false, [], 0.0 );
        if ( is_wp_error( $script ) ) return $script;

        // 2. Call Google Cloud TTS. The key travels in the x-goog-api-key
        // header (Low-20), never in the URL query string.
        $url = 'https://texttospeech.googleapis.com/v1/text:synthesize';
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
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->google_cloud_api_key,
            ],
            'body' => wp_json_encode( $body ),
            'timeout' => 60
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'PressHub AI [tts] API error: ' . $response->get_error_message() );
            return $this->mock_audio_generation($script, $post_id);
        }

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $res_body['error']['message'] ) ) {
            // D-3: log the provider's error before falling back to mock.
            error_log( 'PressHub AI [tts] API error: ' . $res_body['error']['message'] );
        }
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

    /**
     * @param float|null $temperature Explicit temperature override (used to
     *                                lock classify_intent / audio scripts to
     *                                0.0); null uses the provider's configured
     *                                temperature.
     */
    public function call_provider( $sys_prompt, $user_prompt, $json_mode, $files, $temperature = null ) {
        if ( $this->provider === 'anthropic' ) {
            return $this->call_anthropic( $sys_prompt, $user_prompt, $files, $temperature );
        } elseif ( $this->provider === 'gemini' ) {
            return $this->call_gemini( $sys_prompt, $user_prompt, $json_mode, $files, $temperature );
        } else {
            return $this->call_openai( $sys_prompt, $user_prompt, $json_mode, $files, $temperature );
        }
    }

    private function call_openai( $sys_prompt, $user_prompt, $json_mode, $files, $temperature = null ) {
        if ( ! empty( $files ) ) {
            return new WP_Error( 'file_error', __( 'OpenAI chat completions do not support direct PDF/Audio uploads natively in this basic integration. Please select Google Gemini for multi-modal files.', 'presshub-ai-editor' ) );
        }

        $body = [
            'model' => $this->model,
            'messages' => [
                [ 'role' => 'system', 'content' => $sys_prompt ],
                [ 'role' => 'user', 'content' => $user_prompt ]
            ],
            'max_tokens' => $this->max_tokens,
            'temperature' => null === $temperature ? $this->temperature : (float) $temperature
        ];
        if ( $json_mode ) $body['response_format'] = [ 'type' => 'json_object' ];

        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json'
        ];
        // P6: optional OpenAI-Organization header for multi-org accounts.
        $org = get_option( 'presshub_ai_openai_org', '' );
        if ( ! empty( $org ) ) {
            $headers['OpenAI-Organization'] = $org;
        }

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => $headers,
            'body' => wp_json_encode( $body ),
            'timeout' => $this->timeout
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'PressHub AI [openai] API error: ' . $response->get_error_message() );
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['choices'][0]['message']['content'] ) ) {
            return $body['choices'][0]['message']['content'];
        }
        if ( isset( $body['error']['message'] ) ) {
            error_log( 'PressHub AI [openai] API error: ' . $body['error']['message'] );
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        error_log( 'PressHub AI [openai] API error: Invalid response from OpenAI.' );
        return new WP_Error( 'api_error', __( 'Invalid response from OpenAI.', 'presshub-ai-editor' ) );
    }

    private function call_anthropic( $sys_prompt, $user_prompt, $files, $temperature = null ) {
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
                return new WP_Error( 'file_error', __( 'Anthropic only supports PDF document uploads. Audio/Video not supported.', 'presshub-ai-editor' ) );
            }
        }
        
        $content_array[] = [
            'type' => 'text',
            'text' => $user_prompt
        ];

        // P6: pin the anthropic-version header via settings (default 2023-06-01).
        $version = (string) get_option( 'presshub_ai_anthropic_version', '2023-06-01' );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $this->api_key,
                'anthropic-version' => $version,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode( [
                'model' => $this->model,
                'system' => $sys_prompt,
                'messages' => [
                    [ 'role' => 'user', 'content' => $content_array ]
                ],
                'max_tokens' => $this->max_tokens,
                'temperature' => null === $temperature ? $this->temperature : (float) $temperature
            ] ),
            'timeout' => $this->timeout
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'PressHub AI [anthropic] API error: ' . $response->get_error_message() );
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['content'][0]['text'] ) ) {
            return $body['content'][0]['text'];
        }
        if ( isset( $body['error']['message'] ) ) {
            error_log( 'PressHub AI [anthropic] API error: ' . $body['error']['message'] );
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        error_log( 'PressHub AI [anthropic] API error: Invalid response from Anthropic.' );
        return new WP_Error( 'api_error', __( 'Invalid response from Anthropic.', 'presshub-ai-editor' ) );
    }

    private function call_gemini( $sys_prompt, $user_prompt, $json_mode, $files, $temperature = null ) {
        // S-1: the API key travels in the x-goog-api-key header (matching
        // Imagen/TTS), NEVER in the URL query string — URLs end up in
        // server access logs, proxies, CDNs and referer headers.
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent';
        
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
        
        $body['generationConfig'] = [
            'temperature'     => null === $temperature ? $this->temperature : (float) $temperature,
            'maxOutputTokens' => $this->max_tokens,
        ];
        if ( $json_mode ) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }

        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->api_key,
            ],
            'body' => wp_json_encode( $body ),
            'timeout' => $this->timeout
        ] );

        if ( is_wp_error( $response ) ) {
            // D-3: surface provider failures in the server log; the error
            // itself still propagates to the caller unchanged.
            error_log( 'PressHub AI [gemini] API error: ' . $response->get_error_message() );
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return $body['candidates'][0]['content']['parts'][0]['text'];
        }
        if ( isset( $body['error']['message'] ) ) {
            error_log( 'PressHub AI [gemini] API error: ' . $body['error']['message'] );
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        error_log( 'PressHub AI [gemini] API error: Invalid response from Gemini.' );
        return new WP_Error( 'api_error', __( 'Invalid response from Gemini.', 'presshub-ai-editor' ) );
    }
}
