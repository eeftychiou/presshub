<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-provider-defaults.php';
require_once __DIR__ . '/class-provider-store.php';
require_once __DIR__ . '/class-preset-sanitizer.php';
require_once __DIR__ . '/class-preset-store.php';
require_once __DIR__ . '/class-preset-resolver.php';
require_once __DIR__ . '/class-url-fetcher.php';
require_once __DIR__ . '/class-markdown.php';
require_once __DIR__ . '/class-token-logger.php';

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
    private $google_cloud_api_key;
    private $gemini_api_key;
    private $briefing_tts_api_key;
    private $provider;
    private $provider_id;
    private $model;
    private $temperature;
    private $max_tokens;
    private $timeout;
    private $base_url = '';
    private $headers = [];
    private $module = null;
    private $provider_config = null;
    private $current_action = 'coauthor_draft';

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

    /**
     * Resolve provider configuration for a specific functional module.
     *
     * Supported modules:
     *   - 'coauthor'
     *   - 'briefing_text'
     *   - 'briefing_podcast'
     *   - 'copilot'
     *   - 'tts'
     *
     * Falls back to the first enabled provider in PressHub_AI_Provider_Store
     * or legacy global options if no module-specific provider is configured.
     *
     * @param string $module Module identifier.
     * @return array Resolved provider configuration array.
     */
    public static function resolve_module_config( string $module ): array {
        $module = sanitize_key( $module );

        // 1. Get module-configured provider ID or engine
        $provider_id = '';
        if ( 'tts' === $module || 'podcast_tts' === $module ) {
            $provider_id = (string) get_option( 'presshub_ai_briefing_podcast_tts_provider', '' );
            if ( '' === $provider_id ) {
                $provider_id = (string) get_option( 'presshub_ai_briefing_tts_engine', '' );
            }
            if ( '' === $provider_id ) {
                $provider_id = (string) get_option( 'presshub_ai_briefing_tts_provider', '' );
            }
        } else {
            $provider_id = (string) get_option( "presshub_ai_{$module}_provider", '' );
        }
        $provider_id = trim( $provider_id );

        // 2. Fetch provider record from Provider Store
        $provider_record = null;
        if ( '' !== $provider_id ) {
            $provider_record = PressHub_AI_Provider_Store::get( $provider_id );
            if ( null === $provider_record ) {
                $all_providers = PressHub_AI_Provider_Store::get_all( false );
                foreach ( $all_providers as $p ) {
                    if ( ( $p['type'] ?? '' ) === $provider_id || ( $p['id'] ?? '' ) === $provider_id ) {
                        $provider_record = $p;
                        break;
                    }
                }
            }
        }

        // 3. Fallback to first enabled provider if none configured or not found
        if ( null === $provider_record ) {
            $enabled_providers = PressHub_AI_Provider_Store::get_all( true );
            if ( ! empty( $enabled_providers ) ) {
                if ( 'tts' === $module ) {
                    foreach ( $enabled_providers as $ep ) {
                        if ( in_array( $ep['type'] ?? '', [ 'google_cloud_tts', 'gemini' ], true ) ) {
                            $provider_record = $ep;
                            break;
                        }
                    }
                }
                if ( null === $provider_record ) {
                    $provider_record = $enabled_providers[0];
                }
            }
        }

        // 4. Fallback to legacy global provider options
        if ( null === $provider_record ) {
            $legacy_type = (string) get_option( 'presshub_ai_provider', 'openai' );
            $provider_record = PressHub_AI_Provider_Store::get_default_provider_record( $legacy_type );
            $provider_record['id'] = $legacy_type;
            $provider_record['api_key'] = (string) get_option( 'presshub_ai_api_key', '' );
        }

        $type        = $provider_record['type'] ?? 'openai';
        $provider_id = $provider_record['id'] ?? $type;
        $name        = $provider_record['name'] ?? ucwords( str_replace( [ '_', '-' ], ' ', $type ) );
        $base_url    = $provider_record['base_url'] ?? '';
        $headers     = $provider_record['headers'] ?? [];
        $api_key     = $provider_record['api_key'] ?? '';

        // API Key fallback from legacy options if empty
        if ( empty( $api_key ) ) {
            if ( 'gemini' === $type ) {
                $api_key = (string) get_option( 'presshub_ai_gemini_api_key', get_option( 'presshub_ai_api_key', '' ) );
            } elseif ( 'google_cloud_tts' === $type ) {
                $api_key = (string) get_option( 'presshub_ai_google_cloud_api_key', get_option( 'presshub_ai_briefing_tts_api_key', '' ) );
            } else {
                $api_key = (string) get_option( 'presshub_ai_api_key', '' );
            }
        }

        // 5. Model resolution:
        // Module option -> Per-provider option -> Provider record default -> Legacy global -> Provider Defaults
        $model = '';
        if ( 'tts' === $module || 'podcast_tts' === $module ) {
            $model = (string) get_option( 'presshub_ai_briefing_tts_model', '' );
            if ( '' === $model && ! empty( $provider_record['default_model'] ) ) {
                $model = $provider_record['default_model'];
            }
            if ( '' === $model ) {
                $model = 'gemini-3.1-flash-tts-preview';
            }
        } else {
            $model = (string) get_option( "presshub_ai_{$module}_model", '' );
        }

        if ( '' === $model ) {
            $model = (string) get_option( 'presshub_ai_model_' . $provider_id, '' );
            if ( '' === $model && $provider_id !== $type ) {
                $model = (string) get_option( 'presshub_ai_model_' . $type, '' );
            }
        }
        if ( '' === $model && ! empty( $provider_record['default_model'] ) ) {
            $model = $provider_record['default_model'];
        }
        if ( '' === $model ) {
            $model = (string) get_option( 'presshub_ai_model', '' );
        }
        if ( '' === $model ) {
            $model = PressHub_AI_Provider_Defaults::default_model( $type );
        }
        $model = preg_replace( '#^models/#', '', $model );

        // 6. Temperature resolution
        $opt_temp = ( 'tts' === $module )
            ? get_option( 'presshub_ai_briefing_tts_temperature', null )
            : get_option( "presshub_ai_{$module}_temperature", null );

        if ( null === $opt_temp || '' === $opt_temp ) {
            $opt_temp = get_option( 'presshub_ai_temperature_' . $provider_id, null );
            if ( ( null === $opt_temp || '' === $opt_temp ) && $provider_id !== $type ) {
                $opt_temp = get_option( 'presshub_ai_temperature_' . $type, null );
            }
        }

        if ( null !== $opt_temp && '' !== $opt_temp ) {
            $temperature = max( 0.0, min( 2.0, (float) $opt_temp ) );
        } elseif ( isset( $provider_record['temperature'] ) ) {
            $temperature = (float) $provider_record['temperature'];
        } else {
            $temperature = PressHub_AI_Provider_Defaults::default_temperature();
        }

        // 7. Max Tokens resolution
        $opt_tokens = ( 'tts' === $module )
            ? get_option( 'presshub_ai_briefing_tts_max_tokens', null )
            : get_option( "presshub_ai_{$module}_max_tokens", null );

        if ( null === $opt_tokens || '' === $opt_tokens ) {
            $opt_tokens = get_option( 'presshub_ai_max_tokens_' . $provider_id, null );
            if ( ( null === $opt_tokens || '' === $opt_tokens ) && $provider_id !== $type ) {
                $opt_tokens = get_option( 'presshub_ai_max_tokens_' . $type, null );
            }
        }

        if ( null !== $opt_tokens && '' !== $opt_tokens ) {
            $max_tokens = max( 1, (int) $opt_tokens );
        } elseif ( isset( $provider_record['max_tokens'] ) ) {
            $max_tokens = (int) $provider_record['max_tokens'];
        } else {
            $max_tokens = PressHub_AI_Provider_Defaults::default_max_tokens();
        }

        // 8. Timeout resolution
        $opt_timeout = ( 'tts' === $module )
            ? get_option( 'presshub_ai_briefing_tts_timeout', null )
            : get_option( "presshub_ai_{$module}_timeout", null );

        if ( null === $opt_timeout || '' === $opt_timeout ) {
            $opt_timeout = get_option( 'presshub_ai_timeout_' . $provider_id, null );
            if ( ( null === $opt_timeout || '' === $opt_timeout ) && $provider_id !== $type ) {
                $opt_timeout = get_option( 'presshub_ai_timeout_' . $type, null );
            }
        }

        if ( null !== $opt_timeout && '' !== $opt_timeout ) {
            $timeout = max( 1, (int) $opt_timeout );
        } elseif ( isset( $provider_record['timeout'] ) ) {
            $timeout = (int) $provider_record['timeout'];
        } else {
            $timeout = PressHub_AI_Provider_Defaults::default_timeout( $type );
        }

        // 9. Standard header additions
        if ( 'openai' === $type && empty( $headers['OpenAI-Organization'] ) ) {
            $org = (string) get_option( 'presshub_ai_openai_org', '' );
            if ( '' !== $org ) {
                $headers['OpenAI-Organization'] = $org;
            }
        }
        if ( 'anthropic' === $type && empty( $headers['anthropic-version'] ) ) {
            $version = (string) get_option( 'presshub_ai_anthropic_version', '2023-06-01' );
            if ( '' !== $version ) {
                $headers['anthropic-version'] = $version;
            }
        }

        return [
            'module'           => $module,
            'id'               => $provider_id,
            'provider'         => $provider_id,
            'type'             => $type,
            'name'             => $name,
            'api_key'          => $api_key,
            'base_url'         => $base_url,
            'model'            => $model,
            'default_model'    => $model,
            'available_models' => $provider_record['available_models'] ?? [ $model ],
            'temperature'      => $temperature,
            'max_tokens'       => $max_tokens,
            'timeout'          => $timeout,
            'headers'          => $headers,
            'enabled'          => $provider_record['enabled'] ?? true,
            'is_system'        => $provider_record['is_system'] ?? false,
        ];
    }

    /**
     * Constructor.
     *
     * @param string|array|null $module_or_provider Optional module name ('coauthor', 'briefing_text', etc.),
     *                                              provider config array, provider ID string, or null.
     */
    public function __construct( $module_or_provider = null ) {
        if ( is_array( $module_or_provider ) ) {
            $this->set_provider_config( $module_or_provider );
            return;
        }

        if ( is_string( $module_or_provider ) && in_array( $module_or_provider, [ 'coauthor', 'briefing_text', 'briefing_podcast', 'copilot', 'tts' ], true ) ) {
            $this->set_module( $module_or_provider );
            return;
        }

        // Check if string is a provider ID configured in Provider Store
        if ( is_string( $module_or_provider ) && '' !== trim( $module_or_provider ) ) {
            $store_prov = PressHub_AI_Provider_Store::get( trim( $module_or_provider ) );
            if ( $store_prov ) {
                $this->set_provider_config( $store_prov );
                return;
            }
        }

        // Legacy / default single-provider options fallback
        $this->provider = ( is_string( $module_or_provider ) && ! empty( $module_or_provider ) )
            ? $module_or_provider
            : get_option( 'presshub_ai_provider', 'openai' );

        $this->api_key = get_option( 'presshub_ai_api_key' );
        $this->google_cloud_api_key = get_option( 'presshub_ai_google_cloud_api_key' );

        $gemini_key = (string) get_option( 'presshub_ai_gemini_api_key', '' );
        if ( empty( $gemini_key ) ) {
            $gemini_key = (string) get_option( 'presshub_ai_api_key', '' );
        }
        $this->gemini_api_key = $gemini_key;

        $briefing_tts_key = (string) get_option( 'presshub_ai_briefing_tts_api_key', '' );
        if ( empty( $briefing_tts_key ) ) {
            $briefing_tts_key = $this->gemini_api_key;
        }
        $this->briefing_tts_api_key = $briefing_tts_key;

        $this->model       = $this->resolve_model( $this->provider );
        $this->temperature = (float) get_option( 'presshub_ai_temperature_' . $this->provider, PressHub_AI_Provider_Defaults::default_temperature() );
        $this->max_tokens  = (int) get_option( 'presshub_ai_max_tokens_' . $this->provider, PressHub_AI_Provider_Defaults::default_max_tokens() );
        $this->timeout     = (int) get_option( 'presshub_ai_timeout_' . $this->provider, PressHub_AI_Provider_Defaults::default_timeout( $this->provider ) );

        $configured = PressHub_AI_Provider_Store::get( $this->provider );
        if ( $configured ) {
            $this->base_url = $configured['base_url'] ?? '';
            $this->headers  = $configured['headers'] ?? [];
            if ( ! empty( $configured['api_key'] ) ) {
                $this->api_key = $configured['api_key'];
            }
        }
    }

    /**
     * Set the current functional module context on this API client.
     *
     * @param string $module 'coauthor'|'briefing_text'|'briefing_podcast'|'copilot'|'tts'
     * @return self
     */
    public function set_module( string $module ): self {
        $this->module = $module;
        $config = self::resolve_module_config( $module );
        $this->set_provider_config( $config );
        return $this;
    }

    /**
     * Get the active module context, if any.
     *
     * @return string|null
     */
    public function get_module(): ?string {
        return $this->module;
    }

    /**
     * Set provider configuration directly on this API client.
     *
     * @param array $config Provider configuration record.
     * @return self
     */
    public function set_provider_config( array $config ): self {
        $this->provider_config = $config;
        $this->provider        = $config['type'] ?? ( $config['provider'] ?? 'openai' );
        $this->provider_id     = $config['id'] ?? ( $config['provider'] ?? $this->provider );
        $this->api_key         = $config['api_key'] ?? '';

        // If API key is empty or masked, attempt to lookup saved record.
        if ( ( empty( $this->api_key ) || false !== strpos( $this->api_key, '•' ) ) && ! empty( $config['id'] ) ) {
            if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
                $saved = PressHub_AI_Provider_Store::get( (string) $config['id'] );
                if ( ! empty( $saved['api_key'] ) ) {
                    $this->api_key = $saved['api_key'];
                }
            }
        }

        // If still empty, fall back to global options.
        if ( empty( $this->api_key ) || false !== strpos( $this->api_key, '•' ) ) {
            if ( 'gemini' === $this->provider ) {
                $this->api_key = (string) get_option( 'presshub_ai_gemini_api_key', '' );
                if ( empty( $this->api_key ) ) {
                    $this->api_key = (string) get_option( 'presshub_ai_briefing_tts_api_key', '' );
                }
            }
            if ( empty( $this->api_key ) ) {
                $this->api_key = (string) get_option( 'presshub_ai_api_key', '' );
            }
        }

        $this->model           = ! empty( $config['model'] ) ? $config['model'] : ( $config['default_model'] ?? '' );
        $this->model           = preg_replace( '#^models/#', '', $this->model );
        $this->temperature     = isset( $config['temperature'] ) ? (float) $config['temperature'] : PressHub_AI_Provider_Defaults::default_temperature();
        $this->max_tokens      = isset( $config['max_tokens'] ) ? (int) $config['max_tokens'] : PressHub_AI_Provider_Defaults::default_max_tokens();
        $this->timeout         = isset( $config['timeout'] ) ? (int) $config['timeout'] : PressHub_AI_Provider_Defaults::default_timeout( $this->provider );
        $this->base_url        = $config['base_url'] ?? '';
        $this->headers         = $config['headers'] ?? [];

        if ( 'gemini' === $this->provider ) {
            $this->gemini_api_key = $this->api_key;
        }
        if ( 'google_cloud_tts' === $this->provider ) {
            $this->google_cloud_api_key = $this->api_key;
        }
        return $this;
    }

    /**
     * Get the active provider configuration array.
     *
     * @return array|null
     */
    public function get_provider_config(): ?array {
        return $this->provider_config;
    }

    /**
     * Set action trigger context for token and activity logging.
     *
     * @param string $action Action identifier (e.g. 'coauthor_draft', 'copilot_chat', 'briefing_curation').
     * @return self
     */
    public function set_action( string $action ): self {
        $this->current_action = sanitize_key( $action ) ?: sanitize_text_field( $action );
        return $this;
    }

    /**
     * Get active action trigger context.
     *
     * @return string
     */
    public function get_action(): string {
        return $this->current_action ?: 'coauthor_draft';
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
     * @param string|null $provider openai|anthropic|gemini|custom provider ID
     */
    public function test_connection( $provider = null ) {
        $this->current_action = 'custom_test';
        if ( ! empty( $provider ) ) {
            $prov_record = PressHub_AI_Provider_Store::get( $provider );
            if ( $prov_record ) {
                $this->set_provider_config( $prov_record );
            } else {
                if ( ! in_array( $provider, [ 'openai', 'anthropic', 'gemini' ], true ) ) {
                    $provider = 'openai';
                }
                $this->provider    = $provider;
                $this->model       = $this->resolve_model( $provider );
                $this->temperature = (float) get_option( 'presshub_ai_temperature_' . $provider, PressHub_AI_Provider_Defaults::default_temperature() );
                $this->max_tokens  = (int) get_option( 'presshub_ai_max_tokens_' . $provider, PressHub_AI_Provider_Defaults::default_max_tokens() );
                $this->timeout     = (int) get_option( 'presshub_ai_timeout_' . $provider, PressHub_AI_Provider_Defaults::default_timeout( $provider ) );
            }
        }

        if ( empty( $this->api_key ) && 'ollama_local' !== $this->provider ) {
            return new WP_Error( 'no_api_key', __( 'API key is missing.', 'presshub-ai-editor' ) );
        }

        $is_tts = ( false !== stripos( (string) $this->model, 'tts' ) || false !== stripos( (string) $this->model, 'audio' ) || 'google_cloud_tts' === $this->provider );
        if ( $is_tts ) {
            $speech_res = $this->synthesize_speech_via_gemini( 'Hello', 'Kore', true, 'natural' );
            if ( is_wp_error( $speech_res ) ) {
                return $speech_res;
            }
            return __( 'Speech API Connection Successful! (Audio generated)', 'presshub-ai-editor' );
        }

        $sys  = 'You are a test bot.';
        $user = 'Reply with exactly the word "Hello" and nothing else.';
        return $this->call_provider( $sys, $user, false, [] );
    }

    public function generate_draft( $sources, $instructions, $uploaded_files = [], $preset_slug = '' ) {
        $this->current_action = 'coauthor_draft';
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

        $user_prompt = "Write a news article draft based on the following sources.\n\nSources:\n" . $sources . "\n\nInstructions:\n" . $instructions
            . "\n\nFormat the draft as clean HTML for a WordPress post: use <h2> for section headings, <p> for paragraphs and <strong> for emphasis. Do NOT use Markdown syntax (no **, ## or *), and do NOT wrap the output in code fences.";
        $user_prompt = apply_filters( 'presshub_ai_draft_user_prompt', $user_prompt, $sources, $instructions );

        $result = $this->call_provider( $sys_prompt, $user_prompt, false, $uploaded_files );

        // 1.2.9: models often return Markdown — convert to clean HTML so
        // the inserted draft renders properly in the WordPress editor.
        if ( ! is_wp_error( $result ) ) {
            $result = PressHub_AI_Markdown::to_html( $result );
        }

        presshub_ai_log_prompts( 'draft', $sys_prompt, $user_prompt, is_wp_error( $result ) ? 'ERROR: ' . $result->get_error_message() : $result, self::current_request_meta() );

        return $result;
    }

    public function generate_scorecard( $content ) {
        $this->current_action = 'coauthor_scorecard';
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
        $this->current_action = 'copilot_chat';
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

        $referer = function_exists( 'home_url' ) ? trailingslashit( home_url() ) : 'https://presshub.cy/';
        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->google_cloud_api_key,
                'Referer'        => $referer,
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

        $referer = function_exists( 'home_url' ) ? trailingslashit( home_url() ) : 'https://presshub.cy/';
        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->google_cloud_api_key,
                'Referer'        => $referer,
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
     * Synthesize speech using Google Cloud Text-to-Speech API with explicit voice model, rate, and pitch.
     *
     * @param string $text        Text to synthesize.
     * @param string $voice_model Google Cloud TTS voice name (e.g. 'el-GR-Neural2-A', 'el-GR-Neural2-B').
     * @param float  $speed       Speaking rate / speed (default 1.0, range 0.25 to 4.0).
     * @param float  $pitch       Voice pitch adjustment in semitones (default 0.0, range -20.0 to 20.0).
     * @return string|WP_Error   Raw binary MP3 data string or WP_Error on failure.
     */
    public function synthesize_speech_with_options( $text, $voice_model = 'el-GR-Wavenet-A', $speed = 1.0, $pitch = 0.0 ) {
        if ( empty( $this->google_cloud_api_key ) ) {
            return new WP_Error( 'no_gc_key', __( 'Google Cloud API key is missing.', 'presshub-ai-editor' ) );
        }

        if ( empty( $voice_model ) || false !== strpos( $voice_model, 'Neural2' ) ) {
            $voice_model = 'el-GR-Wavenet-A';
        }

        $lang_code = 'el-GR';
        if ( preg_match( '/^([a-z]{2,3}-[A-Z]{2})/i', $voice_model, $m ) ) {
            $lang_code = $m[1];
        }

        $url = 'https://texttospeech.googleapis.com/v1/text:synthesize';
        $is_chirp = ( false !== stripos( $voice_model, 'Chirp' ) );
        $audio_config = [
            'audioEncoding' => 'MP3',
        ];
        if ( ! $is_chirp ) {
            $audio_config['speakingRate'] = (float) $speed;
            $audio_config['pitch']        = (float) $pitch;
        }

        $body = [
            'input'       => [ 'text' => $text ],
            'voice'       => [
                'languageCode' => $lang_code,
                'name'         => $voice_model,
            ],
            'audioConfig' => $audio_config,
        ];

        $referer = function_exists( 'home_url' ) ? trailingslashit( home_url() ) : 'https://presshub.cy/';
        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->google_cloud_api_key,
                'Referer'        => $referer,
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'PressHub AI [tts] API error: ' . $response->get_error_message() );
            return $response;
        }

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $res_body['error']['message'] ) ) {
            error_log( 'PressHub AI [tts] API error: ' . $res_body['error']['message'] );
            return new WP_Error( 'tts_api_error', $res_body['error']['message'] );
        }

        if ( isset( $res_body['audioContent'] ) ) {
            return base64_decode( $res_body['audioContent'] );
        }

        return new WP_Error( 'tts_empty_response', __( 'Empty or invalid audio content returned from Google Cloud TTS.', 'presshub-ai-editor' ) );
    }

    /**
     * Wrap raw PCM audio data into a valid 16-bit mono RIFF/WAV container.
     *
     * @param string $pcm_data        Raw binary PCM buffer.
     * @param int    $sample_rate     Sample rate in Hz (default 24000).
     * @param int    $channels        Number of channels (default 1).
     * @param int    $bits_per_sample Bits per sample (default 16).
     * @return string Binary WAV data.
     */
    public static function pcm_to_wav( string $pcm_data, int $sample_rate = 24000, int $channels = 1, int $bits_per_sample = 16 ): string {
        $data_len = strlen( $pcm_data );
        $byte_rate = (int) ( $sample_rate * $channels * ( $bits_per_sample / 8 ) );
        $block_align = (int) ( $channels * ( $bits_per_sample / 8 ) );

        $header = 'RIFF'
            . pack( 'V', 36 + $data_len )
            . 'WAVE'
            . 'fmt '
            . pack( 'V', 16 )               // Subchunk1Size (16 for PCM)
            . pack( 'v', 1 )                // AudioFormat (1 = PCM)
            . pack( 'v', $channels )        // NumChannels
            . pack( 'V', $sample_rate )     // SampleRate
            . pack( 'V', $byte_rate )       // ByteRate
            . pack( 'v', $block_align )     // BlockAlign
            . pack( 'v', $bits_per_sample ) // BitsPerSample
            . 'data'
            . pack( 'V', $data_len );

        return $header . $pcm_data;
    }

    /**
     * Synthesize natural Greek speech using Google AI Studio Gemini Flash Audio (logosAI replication).
     *
     * @param string $text        Spoken dialogue or turn text.
     * @param string $voice_name  Gemini prebuilt voice name ('Kore', 'Fenrir', 'Puck', 'Charon', 'Zephyr', 'Aoede', etc.).
     * @param bool   $as_wav      Whether to return complete WAV container (default true) or raw PCM.
     * @param string $style       Delivery style ('formal', 'natural', 'cheerful', 'storyteller', 'calm', etc.) or custom instruction.
     * @return string|WP_Error Binary audio data or WP_Error on failure.
     */
    public function synthesize_speech_via_gemini( string $text, string $voice_name = 'Kore', bool $as_wav = true, string $style = 'formal' ) {
        // 1. Resolve Provider and API Key from Provider Store or dedicated option
        $tts_provider_id = (string) get_option( 'presshub_ai_briefing_podcast_tts_provider', '' );
        $provider_record = null;

        if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
            if ( ! empty( $tts_provider_id ) ) {
                $provider_record = PressHub_AI_Provider_Store::get( $tts_provider_id );
            }
            if ( null === $provider_record ) {
                $all_providers = PressHub_AI_Provider_Store::get_all( true );
                foreach ( $all_providers as $p ) {
                    if ( ( $p['type'] ?? '' ) === 'gemini' ) {
                        $provider_record = $p;
                        break;
                    }
                }
            }
        }

        $tts_api_key = '';
        if ( ! empty( $this->api_key ) && 'gemini' === $this->provider ) {
            $tts_api_key = $this->api_key;
        } elseif ( ! empty( $provider_record['api_key'] ) ) {
            $tts_api_key = $provider_record['api_key'];
        }
        if ( empty( $tts_api_key ) ) {
            $tts_api_key = (string) get_option( 'presshub_ai_briefing_tts_api_key', '' );
        }
        if ( empty( $tts_api_key ) ) {
            $tts_api_key = ! empty( $this->briefing_tts_api_key ) ? $this->briefing_tts_api_key : $this->gemini_api_key;
        }
        if ( empty( $tts_api_key ) ) {
            $tts_api_key = (string) get_option( 'presshub_ai_gemini_api_key', '' );
        }

        if ( empty( $tts_api_key ) ) {
            $err_msg = __( 'Google AI Studio Gemini API key is missing. Please configure your Gemini Provider in the AI Providers tab or enter a Speech API Key in Briefing Settings.', 'presshub-ai-editor' );
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( '[LogosAI Speech] ' . $err_msg );
            }
            return new WP_Error( 'no_gemini_key', $err_msg );
        }

        if ( empty( trim( $voice_name ) ) ) {
            $voice_name = 'Kore';
        }

        // 2. Build logosAI prompt instruction
        $style_instructions = [
            'natural'     => 'Say naturally and clearly in Greek with warm human cadence:',
            'formal'      => 'Say in a professional, authoritative, articulate Greek news broadcast tone:',
            'cheerful'    => 'Say cheerfully, enthusiastically, and with a bright uplifting smile in Greek:',
            'storyteller' => 'Say like an engaging, captivating storyteller with theatrical pacing and expressive pauses in Greek:',
            'calm'        => 'Say in a peaceful, gentle, soothing, and relaxing tone in Greek:',
            'dramatic'    => 'Say with intense dramatic emotion, resonant weight, and vivid inflection in Greek:',
            'poetic'      => 'Say with deep lyrical emotion, soft melodic rhythm, and poetic sensitivity in Greek:',
            'epic'        => 'Say in a grand, legendary, classical ancient oratorical style in Greek:',
            'whisper'     => 'Say in a soft, intimate, gentle quiet whisper in Greek:',
            'energetic'   => 'Say with high energy, vibrant excitement, and dynamic rhythm in Greek:',
        ];

        $instruction = $style_instructions[ $style ] ?? ( ! empty( $style ) && strlen( $style ) > 15 ? $style : $style_instructions['formal'] );
        $clean_text  = trim( $text );
        $prompt_text = $instruction . "\n\"" . $clean_text . "\"";

        // 3. Resolve Model cascade
        $configured_model = ! empty( $this->model ) && 'gemini' === $this->provider ? $this->model : (string) get_option( 'presshub_ai_briefing_tts_model', '' );
        if ( empty( $configured_model ) && ! empty( $provider_record['default_model'] ) ) {
            $configured_model = $provider_record['default_model'];
        }
        if ( empty( $configured_model ) || 'journey' === $configured_model ) {
            $configured_model = 'gemini-3.1-flash-tts-preview';
        }

        $tts_models = array_values( array_unique( array_filter( [
            $configured_model,
            'gemini-3.1-flash-tts-preview',
            'gemini-2.5-flash-preview-tts',
        ] ) ) );

        $audio_base64  = null;
        $mime_type     = 'audio/pcm;rate=24000';
        $last_error    = '';
        $used_model    = '';
        $start_time    = microtime( true );

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( '[LogosAI Speech] Initiating TTS: target_model="%s", voice="%s", style="%s", chars=%d', $configured_model, $voice_name, $style, mb_strlen( $clean_text ) ) );
        }

        foreach ( $tts_models as $model ) {
            $gen_url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . urlencode( $tts_api_key );
            $gen_body = [
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [
                            [ 'text' => $prompt_text ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => [ 'AUDIO' ],
                    'speechConfig'       => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => [
                                'voiceName' => $voice_name,
                            ],
                        ],
                    ],
                ],
            ];

            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::debug( sprintf( '[LogosAI Speech] POST %s with model "%s"', 'generateContent', $model ) );
            }

            $gen_res = wp_remote_post( $gen_url, [
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'x-goog-api-key' => $tts_api_key,
                    'User-Agent'     => 'aistudio-build',
                ],
                'body'    => wp_json_encode( $gen_body ),
                'timeout' => 90,
            ] );

            if ( ! is_wp_error( $gen_res ) ) {
                $code     = wp_remote_retrieve_response_code( $gen_res );
                $res_body = json_decode( wp_remote_retrieve_body( $gen_res ), true );

                if ( 200 === $code && isset( $res_body['candidates'][0]['content']['parts'] ) ) {
                    foreach ( $res_body['candidates'][0]['content']['parts'] as $part ) {
                        if ( ! empty( $part['inlineData']['data'] ) ) {
                            $audio_base64 = $part['inlineData']['data'];
                            $mime_type    = $part['inlineData']['mimeType'] ?? $mime_type;
                            $used_model   = $model;
                            break 2;
                        } elseif ( ! empty( $part['inline_data']['data'] ) ) {
                            $audio_base64 = $part['inline_data']['data'];
                            $mime_type    = $part['inline_data']['mime_type'] ?? $mime_type;
                            $used_model   = $model;
                            break 2;
                        }
                    }
                }

                if ( isset( $res_body['error']['message'] ) ) {
                    $last_error = $res_body['error']['message'];
                    if ( class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::warning( sprintf( '[LogosAI Speech] Model "%s" HTTP %d error: %s', $model, $code, $last_error ) );
                    }
                }
            } else {
                $last_error = $gen_res->get_error_message();
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( sprintf( '[LogosAI Speech] Model "%s" connection error: %s', $model, $last_error ) );
                }
            }
        }

        if ( empty( $audio_base64 ) ) {
            $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
            $err_msg = $last_error ?: __( 'No audio payload received from Gemini Speech model.', 'presshub-ai-editor' );
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( sprintf( '[LogosAI Speech] Synthesis failed for all models: %s', $err_msg ) );
            }
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_tts_request( $this->get_action() ?: 'briefing_podcast', 'gemini', $configured_model, mb_strlen( $clean_text ), $duration_ms, 'error', $err_msg );
            }
            return new WP_Error( 'gemini_audio_error', $err_msg );
        }

        $raw_audio = base64_decode( $audio_base64 );
        if ( false === $raw_audio || '' === $raw_audio ) {
            return new WP_Error( 'decode_failed', __( 'Failed to decode base64 audio from Gemini.', 'presshub-ai-editor' ) );
        }

        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( '[LogosAI Speech] Success with model "%s" in %d ms (raw audio: %d bytes)', $used_model, $duration_ms, strlen( $raw_audio ) ) );
        }
        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_tts_request( $this->get_action() ?: 'briefing_podcast', 'gemini', $used_model ?: $configured_model, mb_strlen( $clean_text ), $duration_ms, 'success', null );
        }

        // If it is PCM data, extract sample rate or default to 24000
        $sample_rate = 24000;
        if ( preg_match( '/rate=(\d+)/i', $mime_type, $m ) ) {
            $sample_rate = (int) $m[1];
        }

        return $as_wav ? self::pcm_to_wav( $raw_audio, $sample_rate ) : $raw_audio;
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
        } elseif ( $this->provider === 'custom_openai' || in_array( $this->provider, [ 'groq', 'mistral', 'deepseek', 'ollama_local' ], true ) || ( ! empty( $this->base_url ) && false === strpos( $this->base_url, 'api.openai.com' ) ) ) {
            $config = is_array( $this->provider_config ) ? $this->provider_config : [
                'type'        => $this->provider,
                'base_url'    => $this->base_url,
                'api_key'     => $this->api_key,
                'model'       => $this->model,
                'temperature' => null === $temperature ? $this->temperature : (float) $temperature,
                'max_tokens'  => $this->max_tokens,
                'timeout'     => $this->timeout,
                'headers'     => $this->headers,
            ];
            return $this->call_custom_openai( $config, $sys_prompt, $user_prompt, $json_mode, $files, $temperature );
        } else {
            return $this->call_openai( $sys_prompt, $user_prompt, $json_mode, $files, $temperature );
        }
    }

    /**
     * Call a custom OpenAI-compatible chat completions endpoint.
     *
     * @param array|null  $provider_config Provider configuration record.
     * @param string      $sys_prompt      System prompt.
     * @param string      $user_prompt     User prompt.
     * @param bool        $json_mode       Whether to request JSON object response format.
     * @param array       $files           Uploaded files (not supported in basic OpenAI format).
     * @param float|null  $temperature     Explicit temperature override.
     * @return string|WP_Error Response text or WP_Error on failure.
     */
    public function call_custom_openai( $provider_config, $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null ) {
        if ( ! empty( $files ) ) {
            return new WP_Error( 'file_error', __( 'Custom OpenAI endpoints do not support direct PDF/Audio uploads natively in this integration. Please select Google Gemini for multi-modal files.', 'presshub-ai-editor' ) );
        }

        if ( ! is_array( $provider_config ) ) {
            $provider_config = is_array( $this->provider_config ) ? $this->provider_config : [
                'base_url'    => $this->base_url,
                'api_key'     => $this->api_key,
                'model'       => $this->model,
                'temperature' => $this->temperature,
                'max_tokens'  => $this->max_tokens,
                'timeout'     => $this->timeout,
                'headers'     => $this->headers,
            ];
        }

        $base_url = trim( (string) ( $provider_config['base_url'] ?? '' ) );
        if ( empty( $base_url ) ) {
            return new WP_Error( 'invalid_base_url', __( 'Custom endpoint base URL is missing.', 'presshub-ai-editor' ) );
        }

        // Endpoint URL: append /chat/completions if not present
        if ( preg_match( '#/chat/completions/?$#i', $base_url ) ) {
            $endpoint_url = rtrim( $base_url, '/' );
        } else {
            $endpoint_url = rtrim( $base_url, '/' ) . '/chat/completions';
        }

        $model = ! empty( $provider_config['model'] ) ? $provider_config['model'] : ( $provider_config['default_model'] ?? 'default' );
        $model = preg_replace( '#^models/#', '', $model );

        $max_tokens = isset( $provider_config['max_tokens'] ) ? max( 1, (int) $provider_config['max_tokens'] ) : PressHub_AI_Provider_Defaults::default_max_tokens();
        $temp       = null !== $temperature ? (float) $temperature : ( isset( $provider_config['temperature'] ) ? (float) $provider_config['temperature'] : PressHub_AI_Provider_Defaults::default_temperature() );
        $timeout    = isset( $provider_config['timeout'] ) ? max( 1, (int) $provider_config['timeout'] ) : PressHub_AI_Provider_Defaults::DEFAULT_TIMEOUT;

        $body = [
            'model'       => $model,
            'messages'    => [
                [ 'role' => 'system', 'content' => $sys_prompt ],
                [ 'role' => 'user', 'content' => $user_prompt ],
            ],
            'max_tokens'  => $max_tokens,
            'temperature' => $temp,
        ];
        if ( $json_mode ) {
            $body['response_format'] = [ 'type' => 'json_object' ];
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        $api_key = trim( (string) ( $provider_config['api_key'] ?? '' ) );
        if ( '' !== $api_key ) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        if ( ! empty( $provider_config['headers'] ) && is_array( $provider_config['headers'] ) ) {
            foreach ( $provider_config['headers'] as $hk => $hv ) {
                if ( '' !== trim( (string) $hk ) ) {
                    $headers[ trim( (string) $hk ) ] = (string) $hv;
                }
            }
        }

        $start_time    = microtime( true );
        $action        = $this->get_action();
        $provider_name = ! empty( $provider_config['type'] ) ? $provider_config['type'] : 'custom_openai';

        $response = wp_remote_post( $endpoint_url, [
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
            'timeout' => $timeout,
        ] );

        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, $provider_name, $model, 0, 0, $duration_ms, 'error', $response->get_error_message() );
            }
            error_log( 'PressHub AI [custom_openai] API error: ' . $response->get_error_message() );
            return $response;
        }

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $res_body['choices'][0]['message']['content'] ) ) {
            $prompt_tokens     = (int) ( $res_body['usage']['prompt_tokens'] ?? 0 );
            $completion_tokens = (int) ( $res_body['usage']['completion_tokens'] ?? 0 );
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, $provider_name, $model, $prompt_tokens, $completion_tokens, $duration_ms, 'success', null );
            }
            return $res_body['choices'][0]['message']['content'];
        }
        if ( isset( $res_body['error']['message'] ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, $provider_name, $model, 0, 0, $duration_ms, 'error', $res_body['error']['message'] );
            }
            error_log( 'PressHub AI [custom_openai] API error: ' . $res_body['error']['message'] );
            return new WP_Error( 'api_error', $res_body['error']['message'] );
        }

        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_llm_request( $action, $provider_name, $model, 0, 0, $duration_ms, 'error', 'Invalid response from custom endpoint.' );
        }
        error_log( 'PressHub AI [custom_openai] API error: Invalid response from custom endpoint.' );
        return new WP_Error( 'api_error', __( 'Invalid response from custom endpoint.', 'presshub-ai-editor' ) );
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

        $start_time = microtime( true );
        $action     = $this->get_action();

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => $headers,
            'body' => wp_json_encode( $body ),
            'timeout' => $this->timeout
        ] );

        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'openai', $this->model, 0, 0, $duration_ms, 'error', $response->get_error_message() );
            }
            error_log( 'PressHub AI [openai] API error: ' . $response->get_error_message() );
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['choices'][0]['message']['content'] ) ) {
            $prompt_tokens     = (int) ( $body['usage']['prompt_tokens'] ?? 0 );
            $completion_tokens = (int) ( $body['usage']['completion_tokens'] ?? 0 );
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'openai', $this->model, $prompt_tokens, $completion_tokens, $duration_ms, 'success', null );
            }
            return $body['choices'][0]['message']['content'];
        }
        if ( isset( $body['error']['message'] ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'openai', $this->model, 0, 0, $duration_ms, 'error', $body['error']['message'] );
            }
            error_log( 'PressHub AI [openai] API error: ' . $body['error']['message'] );
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_llm_request( $action, 'openai', $this->model, 0, 0, $duration_ms, 'error', 'Invalid response from OpenAI.' );
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

        $start_time = microtime( true );
        $action     = $this->get_action();

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

        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'anthropic', $this->model, 0, 0, $duration_ms, 'error', $response->get_error_message() );
            }
            error_log( 'PressHub AI [anthropic] API error: ' . $response->get_error_message() );
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['content'][0]['text'] ) ) {
            $prompt_tokens     = (int) ( $body['usage']['input_tokens'] ?? 0 );
            $completion_tokens = (int) ( $body['usage']['output_tokens'] ?? 0 );
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'anthropic', $this->model, $prompt_tokens, $completion_tokens, $duration_ms, 'success', null );
            }
            return $body['content'][0]['text'];
        }
        if ( isset( $body['error']['message'] ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'anthropic', $this->model, 0, 0, $duration_ms, 'error', $body['error']['message'] );
            }
            error_log( 'PressHub AI [anthropic] API error: ' . $body['error']['message'] );
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_llm_request( $action, 'anthropic', $this->model, 0, 0, $duration_ms, 'error', 'Invalid response from Anthropic.' );
        }
        error_log( 'PressHub AI [anthropic] API error: Invalid response from Anthropic.' );
        return new WP_Error( 'api_error', __( 'Invalid response from Anthropic.', 'presshub-ai-editor' ) );
    }

    private function call_gemini( $sys_prompt, $user_prompt, $json_mode, $files, $temperature = null ) {
        // S-1: the API key travels in the x-goog-api-key header (matching
        // Imagen/TTS), NEVER in the URL query string — URLs end up in
        // server access logs, proxies, CDNs and referer headers.
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent';
        
        $is_tts_model = ( false !== stripos( (string) $this->model, 'tts' ) || false !== stripos( (string) $this->model, 'audio' ) );
        if ( $is_tts_model && ! empty( $sys_prompt ) ) {
            $user_prompt = trim( $sys_prompt . "\n\n" . $user_prompt );
            $sys_prompt  = '';
        }

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
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts
                ]
            ]
        ];

        if ( ! empty( $sys_prompt ) && ! $is_tts_model ) {
            $body['systemInstruction'] = [
                'parts' => [ [ 'text' => $sys_prompt ] ]
            ];
        }
        
        $body['generationConfig'] = [
            'temperature'     => null === $temperature ? $this->temperature : (float) $temperature,
            'maxOutputTokens' => $this->max_tokens,
        ];
        if ( $json_mode ) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }

        $start_time = microtime( true );
        $action     = $this->get_action();

        $referer = function_exists( 'home_url' ) ? trailingslashit( home_url() ) : 'https://presshub.cy/';
        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->api_key,
                'Referer'        => $referer,
            ],
            'body' => wp_json_encode( $body ),
            'timeout' => $this->timeout
        ] );

        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'gemini', $this->model, 0, 0, $duration_ms, 'error', $response->get_error_message() );
            }
            // D-3: surface provider failures in the server log; the error
            // itself still propagates to the caller unchanged.
            error_log( 'PressHub AI [gemini] API error: ' . $response->get_error_message() );
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $prompt_tokens     = (int) ( $body['usageMetadata']['promptTokenCount'] ?? 0 );
            $completion_tokens = (int) ( $body['usageMetadata']['candidatesTokenCount'] ?? 0 );
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'gemini', $this->model, $prompt_tokens, $completion_tokens, $duration_ms, 'success', null );
            }
            return $body['candidates'][0]['content']['parts'][0]['text'];
        }
        if ( isset( $body['error']['message'] ) ) {
            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                PressHub_AI_Token_Logger::log_llm_request( $action, 'gemini', $this->model, 0, 0, $duration_ms, 'error', $body['error']['message'] );
            }
            error_log( 'PressHub AI [gemini] API error: ' . $body['error']['message'] );
            return new WP_Error( 'api_error', $body['error']['message'] );
        }
        if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
            PressHub_AI_Token_Logger::log_llm_request( $action, 'gemini', $this->model, 0, 0, $duration_ms, 'error', 'Invalid response from Gemini.' );
        }
        error_log( 'PressHub AI [gemini] API error: Invalid response from Gemini.' );
        return new WP_Error( 'api_error', __( 'Invalid response from Gemini.', 'presshub-ai-editor' ) );
    }
}
