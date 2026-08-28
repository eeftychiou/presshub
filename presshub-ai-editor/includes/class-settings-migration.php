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
}
