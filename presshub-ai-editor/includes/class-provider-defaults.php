<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Single source of truth for provider defaults and preset templates.
 *
 * Both PressHub_AI_Settings and PressHub_AI_API_Client read through this
 * class so defaults and provider configurations remain synchronized in
 * exactly one place.
 */
class PressHub_AI_Provider_Defaults {

    /** Per-provider default model names. */
    const DEFAULT_MODELS = [
        'openai'           => 'gpt-4o',
        'anthropic'        => 'claude-3-5-sonnet-20240620',
        'gemini'           => 'gemini-1.5-pro-latest',
        'google_cloud_tts' => 'journey',
        'groq'             => 'llama-3.3-70b-versatile',
        'mistral'          => 'mistral-large-latest',
        'deepseek'         => 'deepseek-chat',
        'ollama_local'     => 'llama3:latest',
        'custom_openai'    => 'default',
    ];

    const DEFAULT_TEMPERATURE = 0.7;
    const DEFAULT_MAX_TOKENS   = 10000;

    /** Timeouts set to 300 seconds (5 minutes) across all providers. */
    const DEFAULT_TIMEOUT        = 300;
    const DEFAULT_TIMEOUT_OPENAI = 300;
    const DEFAULT_TIMEOUT_GEMINI = 300;
    const DEFAULT_TIMEOUT_OTHER  = 300;

    /** Standard provider preset templates. */
    const PROVIDER_TEMPLATES = [
        'openai' => [
            'type'             => 'openai',
            'name'             => 'OpenAI',
            'base_url'         => 'https://api.openai.com/v1',
            'default_model'    => 'gpt-4o',
            'available_models' => [ 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'o1', 'o3-mini' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => true,
        ],
        'anthropic' => [
            'type'             => 'anthropic',
            'name'             => 'Anthropic Claude',
            'base_url'         => 'https://api.anthropic.com/v1',
            'default_model'    => 'claude-3-5-sonnet-20240620',
            'available_models' => [ 'claude-3-5-sonnet-20240620', 'claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022', 'claude-3-opus-20240229' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => true,
        ],
        'gemini' => [
            'type'             => 'gemini',
            'name'             => 'Google Gemini',
            'base_url'         => 'https://generativelanguage.googleapis.com/v1beta',
            'default_model'    => 'gemini-1.5-pro-latest',
            'available_models' => [ 'gemini-1.5-pro-latest', 'gemini-1.5-flash-latest', 'gemini-2.0-flash', 'gemini-2.0-pro-exp-02-05' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => true,
        ],
        'google_cloud_tts' => [
            'type'             => 'google_cloud_tts',
            'name'             => 'Google Cloud TTS',
            'base_url'         => 'https://texttospeech.googleapis.com/v1',
            'default_model'    => 'journey',
            'available_models' => [ 'journey', 'neural2', 'wavenet', 'standard' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => true,
        ],
        'groq' => [
            'type'             => 'groq',
            'name'             => 'Groq',
            'base_url'         => 'https://api.groq.com/openai/v1',
            'default_model'    => 'llama-3.3-70b-versatile',
            'available_models' => [ 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => true,
        ],
        'mistral' => [
            'type'             => 'mistral',
            'name'             => 'Mistral AI',
            'base_url'         => 'https://api.mistral.ai/v1',
            'default_model'    => 'mistral-large-latest',
            'available_models' => [ 'mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest', 'codestral-latest' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => true,
        ],
        'deepseek' => [
            'type'             => 'deepseek',
            'name'             => 'DeepSeek',
            'base_url'         => 'https://api.deepseek.com/v1',
            'default_model'    => 'deepseek-chat',
            'available_models' => [ 'deepseek-chat', 'deepseek-reasoner' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => true,
        ],
        'ollama_local' => [
            'type'             => 'ollama_local',
            'name'             => 'Local Ollama',
            'base_url'         => 'http://localhost:11434/v1',
            'default_model'    => 'llama3:latest',
            'available_models' => [ 'llama3:latest', 'mistral:latest', 'qwen2.5:latest', 'phi3:latest' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => true,
        ],
        'custom_openai' => [
            'type'             => 'custom_openai',
            'name'             => 'Custom OpenAI Endpoint',
            'base_url'         => '',
            'default_model'    => 'default',
            'available_models' => [ 'default' ],
            'timeout'          => 300,
            'temperature'      => 0.7,
            'max_tokens'       => 10000,
            'headers'          => [],
            'enabled'          => true,
            'is_system'        => false,
        ],
    ];

    public static function default_model( $provider ): string {
        return self::DEFAULT_MODELS[ $provider ] ?? self::DEFAULT_MODELS['openai'];
    }

    public static function default_temperature(): float {
        return self::DEFAULT_TEMPERATURE;
    }

    public static function default_max_tokens(): int {
        return self::DEFAULT_MAX_TOKENS;
    }

    public static function default_timeout( $provider = null ): int {
        if ( 'openai' === $provider ) {
            return self::DEFAULT_TIMEOUT_OPENAI;
        }
        if ( 'gemini' === $provider ) {
            return self::DEFAULT_TIMEOUT_GEMINI;
        }
        return self::DEFAULT_TIMEOUT_OTHER;
    }

    public static function get_templates(): array {
        return self::PROVIDER_TEMPLATES;
    }

    public static function get_template( string $type ): ?array {
        return self::PROVIDER_TEMPLATES[ $type ] ?? null;
    }

    public static function get_standard_types(): array {
        return array_keys( self::PROVIDER_TEMPLATES );
    }
}

