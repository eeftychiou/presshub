<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Single source of truth for provider defaults (architect D-1).
 *
 * Both PressHub_AI_Settings (form defaults + sanitize fallbacks) and
 * PressHub_AI_API_Client (runtime request config) used to carry private
 * copies of these values; any edit to one silently drifted from the
 * other. Every consumer now reads through this class so a default
 * changes in exactly one place.
 */
class PressHub_AI_Provider_Defaults {

    /** Per-provider default model names. */
    const DEFAULT_MODELS = [
        'openai'    => 'gpt-4o',
        'anthropic' => 'claude-3-5-sonnet-20240620',
        'gemini'    => 'gemini-1.5-pro-latest',
    ];

    const DEFAULT_TEMPERATURE = 0.7;
    const DEFAULT_MAX_TOKENS   = 3000;

    /** OpenAI's endpoint is faster; everyone else gets 90s. */
    const DEFAULT_TIMEOUT_OPENAI = 60;
    const DEFAULT_TIMEOUT_OTHER  = 90;

    public static function default_model( $provider ): string {
        return self::DEFAULT_MODELS[ $provider ] ?? self::DEFAULT_MODELS['openai'];
    }

    public static function default_temperature(): float {
        return self::DEFAULT_TEMPERATURE;
    }

    public static function default_max_tokens(): int {
        return self::DEFAULT_MAX_TOKENS;
    }

    public static function default_timeout( $provider ): int {
        return 'openai' === $provider ? self::DEFAULT_TIMEOUT_OPENAI : self::DEFAULT_TIMEOUT_OTHER;
    }
}
