<?php
/**
 * Resolves which preset instruction text applies for a given request.
 *
 * Composition semantics (design doc §3 + §9 Q3 / Antigravity A-6):
 *   - The built-in prompt is NOT this class's concern; it returns only the
 *     instruction text to append (single preset per request, or null).
 *   - Precedence: per-request slug > taxonomy default > role default >
 *     author default > plugin default.
 *   - The author's default slug (user meta 'presshub_ai_default_preset_id')
 *     may name an author preset or a plugin-default preset; an author
 *     preset shadows a plugin default with the same slug.
 *   - Org layers (2026-08-15 design §9 Q3 / Antigravity A-6): admin-curated
 *     maps in the Preset_Store (taxonomy term slug => preset slug, role =>
 *     preset slug) resolve through the same lookup as the author default,
 *     so they respect the author's disabled-defaults list and the preset's
 *     enabled flag. The taxonomy layer matches the FIRST term in
 *     $context['taxonomies'] that has an entry; the role layer matches the
 *     FIRST role of the user (wp_get_current_user roles when $user_id is
 *     the current user, else $context['roles'], else the layer is skipped).
 *     A stored ORG_NONE ('__none__') at a matched org layer disables the
 *     rest of the chain for that request.
 *   - Backward compatibility: the $context parameter is optional. Calls
 *     with 3 args (or fewer) skip BOTH org layers entirely and behave
 *     exactly as before this feature shipped.
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
    const PRESET_ENDPOINTS = [ 'draft', 'chat', 'research', 'curation' ];

    /**
     * Resolve which instruction text applies for a user + optional
     * per-request preset slug, in the context of an endpoint type.
     *
     * @param int         $user_id     Author id (0 = not logged in).
     * @param string      $endpoint    'draft' | 'chat' | 'research' (others gated).
     * @param string|null $preset_slug Per-request preset slug, or null.
     * @param array       $context     Optional org context (4th arg; the
     *                                 org layers are skipped entirely when
     *                                 omitted for backward compatibility):
     *                                 'post_id' (informational, reserved
     *                                 for the metabox wiring), 'taxonomies'
     *                                 (array of term slugs, first match
     *                                 wins), 'roles' (array of role names,
     *                                 used only when $user_id is not the
     *                                 current user).
     * @return string|null The instruction text to append, or null.
     */
    public static function resolve_for_user( int $user_id, string $endpoint, ?string $preset_slug, array $context = [] ): ?string {
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

        // Backward compatibility: 3-arg calls (pre-org-defaults) skip the
        // taxonomy + role layers so their behaviour is bit-for-bit the
        // same as before this feature existed.
        $org_layers = ( func_num_args() >= 4 );

        // Layer 4: per-request selection (explicit override).
        if ( $preset_slug !== '' ) {
            $text = self::lookup_preset_text( $user_id, $preset_slug );
            if ( $text !== null ) {
                return $text;
            }
            // Unknown per-request slug: fall back down the chain.
        }

        if ( $org_layers ) {
            // Layer 3: taxonomy default — first matching term wins.
            if ( isset( $context['taxonomies'] ) && is_array( $context['taxonomies'] ) ) {
                $taxonomy_presets = PressHub_AI_Preset_Store::get_taxonomy_presets();
                foreach ( $context['taxonomies'] as $term_slug ) {
                    $term_slug = trim( (string) $term_slug );
                    if ( $term_slug === '' || ! isset( $taxonomy_presets[ $term_slug ] ) ) {
                        continue;
                    }
                    $matched = $taxonomy_presets[ $term_slug ];
                    if ( $matched === self::SENTINEL_NONE ) {
                        return null;
                    }
                    $text = self::lookup_preset_text( $user_id, $matched );
                    if ( $text !== null ) {
                        return $text;
                    }
                }
            }

            // Layer 3: role default — first matching role of the user wins.
            $roles = self::resolve_user_roles( $user_id, $context );
            if ( $roles !== null ) {
                $role_presets = PressHub_AI_Preset_Store::get_role_presets();
                foreach ( $roles as $role ) {
                    $role = trim( (string) $role );
                    if ( $role === '' || ! isset( $role_presets[ $role ] ) ) {
                        continue;
                    }
                    $matched = $role_presets[ $role ];
                    if ( $matched === self::SENTINEL_NONE ) {
                        return null;
                    }
                    $text = self::lookup_preset_text( $user_id, $matched );
                    if ( $text !== null ) {
                        return $text;
                    }
                }
            }
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
     * Determine the role list to consult for the role-default layer.
     *
     * Priority: the CURRENT user's roles when $user_id is the current
     * user (wp_get_current_user), else $context['roles'], else null
     * (layer skipped — no role context available).
     *
     * @param int   $user_id
     * @param array $context
     * @return array|null
     */
    private static function resolve_user_roles( int $user_id, array $context ): ?array {
        if ( $user_id === (int) get_current_user_id() ) {
            $current = wp_get_current_user();
            if ( is_object( $current ) && isset( $current->roles ) && is_array( $current->roles ) ) {
                return $current->roles;
            }
            return [];
        }
        if ( isset( $context['roles'] ) && is_array( $context['roles'] ) ) {
            return $context['roles'];
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