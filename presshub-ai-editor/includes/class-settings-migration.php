<?php
/**
 * PressHub AI Editor — Settings migration module (Concern #2 split).
 *
 * Extracted from class-settings.php in T4. Contains:
 *   - The 13 default_* static delegators (model/temperature/max_tokens/timeout
 *     delegate to PressHub_AI_Provider_Defaults; the briefing defaults are
 *     literals that were originally on the facade).
 *   - migrate_legacy_model() — the idempotent P2 migration that copies the
 *     legacy presshub_ai_model option into the active provider's per-provider
 *     key.
 *
 * Status: dead code in T4. The PressHub_AI_Settings facade still owns the
 * entire migration surface; this class is not yet referenced anywhere.
 * T5 will reduce the facade to a thin forwarder shell that delegates
 * default_* and migrate_legacy_model calls into this module.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Settings_Migration {

    const PROVIDERS = [ 'openai', 'anthropic', 'gemini' ];

    public static function default_model( $provider ): string {
        return PressHub_AI_Provider_Defaults::default_model( $provider );
    }

    public static function default_temperature(): float {
        return PressHub_AI_Provider_Defaults::default_temperature();
    }

    public static function default_max_tokens(): int {
        return PressHub_AI_Provider_Defaults::default_max_tokens();
    }

    public static function default_timeout( $provider ): int {
        return PressHub_AI_Provider_Defaults::default_timeout( $provider );
    }

    public static function default_briefing_sources(): array {
        return [
            'https://www.kathimerini.gr',
            'https://www.tovima.gr',
            'https://www.naftemporiki.gr',
            'https://www.in.gr',
            'https://www.news247.gr',
        ];
    }

    public static function default_briefing_harvest_time(): string {
        return '06:30';
    }

    public static function default_briefing_generation_time(): string {
        return '07:15';
    }

    public static function default_briefing_target_duration(): string {
        return '5_min';
    }

    public static function default_briefing_host_female(): string {
        return 'Μαρία';
    }

    public static function default_briefing_host_male(): string {
        return 'Νίκος';
    }

    public static function default_briefing_tts_engine(): string {
        return 'gemini';
    }

    public static function default_briefing_tts_model(): string {
        return 'gemini-3.1-flash-tts-preview';
    }

    public static function default_briefing_voice_female(): string {
        return 'Kore';
    }

    public static function default_briefing_voice_male(): string {
        return 'Fenrir';
    }

    public static function default_briefing_tts_style(): string {
        return 'formal';
    }

    public static function default_briefing_tts_custom_style(): string {
        return '';
    }


    // ------------------------------------------------------------------
    // P2: legacy model migration (idempotent).
    // ------------------------------------------------------------------

    /**
     * Copy the legacy presshub_ai_model option into the ACTIVE provider's
     * per-provider key, but only when that key is empty (so an admin's
     * existing per-provider value is never overwritten). Runs once:
     * guarded by the presshub_ai_migrated_models option.
     */

    public static function migrate_legacy_model(): void {
        if ( get_option( 'presshub_ai_migrated_models' ) ) {
            return;
        }

        $legacy = (string) get_option( 'presshub_ai_model', '' );
        if ( '' !== $legacy ) {
            $provider = (string) get_option( 'presshub_ai_provider', 'openai' );
            if ( in_array( $provider, self::PROVIDERS, true ) ) {
                $key     = 'presshub_ai_model_' . $provider;
                $current = (string) get_option( $key, '' );
                if ( '' === $current ) {
                    update_option( $key, $legacy );
                }
            }
        }

        update_option( 'presshub_ai_migrated_models', 1 );
    }

    // ------------------------------------------------------------------
    // P3: deprecated Gemini model migration (idempotent).
    //
    // Google's Gemini API shut down gemini-2.0-flash and gemini-2.0-flash-exp
    // in 2026; both now return "This model … is no longer available." If a
    // site previously saved one of those values into:
    //   - the per-provider Gemini model key (presshub_ai_model_gemini), or
    //   - the legacy shared model key (presshub_ai_model), or
    //   - the TTS model key (presshub_ai_briefing_tts_model),
    // then every TTS request will cascade-fail on the dead model first and
    // only fall through to the valid model on retry — which surfaces in the
    // token log as the two-line "no longer available" error the user reported.
    //
    // This migration runs once and rewrites any deprecated saved value to
    // the current valid model for that key (gemini-2.5-flash for text gen,
    // gemini-3.1-flash-tts-preview for TTS).
    // ------------------------------------------------------------------

    public static function migrate_deprecated_gemini_models(): void {
        if ( get_option( 'presshub_ai_migrated_deprecated_gemini' ) ) {
            return;
        }

        $deprecated_text = [ 'gemini-2.0-flash' ];
        $valid_text      = 'gemini-2.5-flash';
        $valid_tts       = 'gemini-3.1-flash-tts-preview';

        // Text-gen keys: legacy shared + per-provider Gemini.
        $text_keys = [ 'presshub_ai_model', 'presshub_ai_model_gemini' ];
        foreach ( $text_keys as $key ) {
            $current = (string) get_option( $key, '' );
            if ( '' !== $current && in_array( $current, $deprecated_text, true ) ) {
                update_option( $key, $valid_text );
            }
        }

        // TTS key: rewrite any deprecated saved TTS model to the current
        // primary TTS model. The cascade in synthesize_speech_via_gemini()
        // no longer contains the deprecated models, so this prevents the
        // first retry attempt from hitting a dead endpoint.
        $tts_key = 'presshub_ai_briefing_tts_model';
        $tts_current = (string) get_option( $tts_key, '' );
        if ( '' !== $tts_current && in_array( $tts_current, $deprecated_text, true ) ) {
            update_option( $tts_key, $valid_tts );
        }

        update_option( 'presshub_ai_migrated_deprecated_gemini', 1 );
    }
}
