<?php
/**
 * Resolves which preset instruction text applies for a given request.
 *
 * Composition semantics (design doc §3):
 *   - The built-in prompt is NOT this class's concern; it returns only the
 *     instruction text to append (single preset per request, or null).
 *   - Precedence: per-request slug > author default > plugin default.
 *   - The author's default slug (user meta 'presshub_ai_default_preset_id')
 *     may name an author preset or a plugin-default preset; an author
 *     preset shadows a plugin default with the same slug.
 *   - Endpoint gating: 'scorecard', 'classify', 'audio' never receive
 *     presets; unknown endpoints fail closed. Allowed: draft/chat/research.
 *   - Empty instruction text and disabled (enabled=false) presets are
 *     skipped; the author's disabled-defaults list blocks plugin defaults.
 *   - Sentinels: '__none__' disables presets for the request; an empty
 *     slug (or '__plugin_default__') means "use the author's default".
 *
 * Pure function — no state, no side effects.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Preset_Resolver {

    const SENTINEL_NONE      = '__none__';
    const SENTINEL_PLUGIN    = '__plugin_default__';

    // Endpoints that may receive preset composition. Everything else
    // (scorecard, classify, audio, unknown) fails closed to null.
    const PRESET_ENDPOINTS = [ 'draft', 'chat', 'research' ];

    /**
     * Resolve which instruction text applies for a user + optional
     * per-request preset slug, in the context of an endpoint type.
     *
     * @param int         $user_id     Author id (0 = not logged in).
     * @param string      $endpoint    'draft' | 'chat' | 'research' (others gated).
     * @param string|null $preset_slug Per-request preset slug, or null.
     * @return string|null The instruction text to append, or null.
     */
    public static function resolve_for_user( int $user_id, string $endpoint, ?string $preset_slug ): ?string {
        // Missing user: no author context to resolve against.
        if ( $user_id <= 0 ) {
            return null;
        }

        $endpoint = strtolower( trim( $endpoint ) );
        if ( ! in_array( $endpoint, self::PRESET_ENDPOINTS, true ) ) {
            return null;
        }

        // Explicit "no preset" sentinel disables the whole chain.
        if ( $preset_slug === self::SENTINEL_NONE ) {
            return null;
        }

        $preset_slug = trim( (string) $preset_slug );
        if ( $preset_slug === '' || $preset_slug === self::SENTINEL_PLUGIN ) {
            $preset_slug = '';
        }

        // Layer 3: per-request selection (explicit override).
        if ( $preset_slug !== '' ) {
            $text = self::lookup_preset_text( $user_id, $preset_slug );
            if ( $text !== null ) {
                return $text;
            }
            // Unknown per-request slug: fall back down the chain.
        }

        // Layer 1+2: author default slug resolves to an author preset or a
        // plugin-default preset.
        $default_slug = PressHub_AI_Preset_Store::get_author_default_slug( $user_id );
        if ( $default_slug !== '' ) {
            $text = self::lookup_preset_text( $user_id, $default_slug );
            if ( $text !== null ) {
                return $text;
            }
        }

        return null;
    }

    /**
     * Look up a slug in the author's presets first, then the plugin
     * defaults. Returns the preset's instruction_text, or null when the
     * slug is unknown, disabled, blocked, or its text is empty.
     *
     * @param int    $user_id
     * @param string $slug
     * @return string|null
     */
    private static function lookup_preset_text( int $user_id, string $slug ): ?string {
        // Author presets are more specific — an author row with this slug
        // shadows the plugin default, even when the author's copy is
        // disabled (the author deliberately opted out of it).
        foreach ( PressHub_AI_Preset_Store::get_author_presets( $user_id ) as $preset ) {
            if ( $preset['slug'] !== $slug ) {
                continue;
            }
            if ( ! $preset['enabled'] ) {
                return null;
            }
            $text = trim( (string) $preset['instruction_text'] );
            if ( $text === '' ) {
                return null;
            }
            return $text;
        }

        // Plugin defaults apply only when the author hasn't disabled them.
        $disabled = PressHub_AI_Preset_Store::get_disabled_defaults( $user_id );
        foreach ( PressHub_AI_Preset_Store::get_plugin_defaults() as $preset ) {
            if ( $preset['slug'] !== $slug ) {
                continue;
            }
            if ( ! $preset['enabled'] || in_array( $slug, $disabled, true ) ) {
                return null;
            }
            $text = trim( (string) $preset['instruction_text'] );
            if ( $text === '' ) {
                return null;
            }
            return $text;
        }

        return null;
    }
}