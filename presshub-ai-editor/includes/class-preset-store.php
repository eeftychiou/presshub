<?php
/**
 * Thin storage wrapper for instruction presets.
 *
 * Backs five storage layers:
 *   - Plugin defaults:  option  'presshub_ai_default_presets'.
 *   - Per-author presets: user meta 'presshub_ai_author_presets'.
 *   - Per-author default slug: user meta 'presshub_ai_default_preset_id'.
 *   - Per-author disabled plugin-default slugs:
 *     user meta 'presshub_ai_disabled_default_presets'.
 *   - Org defaults (2026-08-15 design §9 Q3 / Antigravity A-6):
 *     option 'presshub_ai_taxonomy_presets' (term slug => preset slug)
 *     and option 'presshub_ai_role_presets' (role => preset slug),
 *     both admin-curated.
 *
 * Every read goes through PressHub_AI_Preset_Sanitizer so bad data in the
 * DB never escapes into prompts (defense in depth, design doc §1.4).
 * Static methods only — no constructor state, easy to unit test.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Preset_Store {

    const OPTION_DEFAULT_PRESETS        = 'presshub_ai_default_presets';
    const META_AUTHOR_PRESETS           = 'presshub_ai_author_presets';
    const META_DEFAULT_SLUG             = 'presshub_ai_default_preset_id';
    const META_DISABLED_DEFAULT_PRESETS = 'presshub_ai_disabled_default_presets';
    const OPTION_TAXONOMY_PRESETS       = 'presshub_ai_taxonomy_presets';
    const OPTION_ROLE_PRESETS           = 'presshub_ai_role_presets';

    /**
     * Sentinel value stored in an org-default map to mean "no preset at
     * this layer" (disables the rest of the chain for the request).
     * Must stay in sync with PressHub_AI_Preset_Resolver::SENTINEL_NONE.
     */
    const ORG_NONE = '__none__';

    const SEEDED_PRESETS = [
        [
            'slug'             => 'wire-style',
            'name'             => 'Wire service concise',
            'instruction_text' => 'Use the inverted pyramid. Lead with the news; compress context into subsequent paragraphs.',
            'enabled'          => true,
        ],
        [
            'slug'             => 'interview-focus',
            'name'             => 'Interview-driven',
            'instruction_text' => 'Anchor every section in a direct quotation from the source notes.',
            'enabled'          => true,
        ],
        [
            'slug'             => 'fact-check',
            'name'             => 'Fact-check everything',
            'instruction_text' => 'For every factual claim, add a parenthetical citing the source paragraph. Flag any unsourced claim.',
            'enabled'          => true,
        ],
    ];

    // Per-scope library quotas (2026-08-15 design §6.1): 25 author
    // presets, 50 plugin defaults. Single source of truth for both the
    // Store methods and the thin AJAX handlers (architect A-2).
    const AUTHOR_MAX_PRESETS = 25;
    const PLUGIN_MAX_PRESETS = 50;

    /**
     * Read the plugin-default presets (sanitized).
     *
     * @return array
     */
    public static function get_plugin_defaults(): array {
        $raw = get_option( self::OPTION_DEFAULT_PRESETS, [] );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        return PressHub_AI_Preset_Sanitizer::sanitize_presets( $raw );
    }

    /**
     * Sanitize and store the plugin-default presets.
     *
     * P-1: the option is written with autoload=false — the serialized
     * preset list (up to ~200KB worst case) must not be loaded on every
     * request.
     *
     * @param array $presets
     * @return array The sanitized list that was stored.
     */
    public static function save_plugin_defaults( array $presets ): array {
        $clean = PressHub_AI_Preset_Sanitizer::sanitize_presets( $presets );
        update_option( self::OPTION_DEFAULT_PRESETS, $clean, false );
        return $clean;
    }

    /**
     * Read one author's presets (sanitized).
     *
     * @param int $user_id
     * @return array
     */
    public static function get_author_presets( int $user_id ): array {
        $raw = get_user_meta( $user_id, self::META_AUTHOR_PRESETS, true );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        return PressHub_AI_Preset_Sanitizer::sanitize_presets( $raw );
    }

    /**
     * Sanitize and store one author's presets.
     *
     * @param int   $user_id
     * @param array $presets
     * @return array The sanitized list that was stored.
     */
    public static function save_author_presets( int $user_id, array $presets ): array {
        $clean = PressHub_AI_Preset_Sanitizer::sanitize_presets( $presets );
        update_user_meta( $user_id, self::META_AUTHOR_PRESETS, $clean );
        return $clean;
    }

    /**
     * Read the author's default preset slug ('' when unset).
     *
     * @param int $user_id
     * @return string
     */
    public static function get_author_default_slug( int $user_id ): string {
        $raw = get_user_meta( $user_id, self::META_DEFAULT_SLUG, true );
        if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
            return '';
        }
        $slug = (string) $raw;
        // Only well-formed slugs are meaningful as a lookup key.
        if ( $slug !== '' && ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
            return '';
        }
        return $slug;
    }

    /**
     * Set the author's default preset slug ('' clears it).
     *
     * @param int    $user_id
     * @param string $slug
     * @return string The stored slug ('' when cleared).
     */
    public static function set_author_default_slug( int $user_id, string $slug ): string {
        $slug = trim( $slug );
        update_user_meta( $user_id, self::META_DEFAULT_SLUG, $slug );
        return $slug;
    }

    /**
     * Read the plugin-default slugs this author has disabled (sanitized).
     *
     * @param int $user_id
     * @return string[]
     */
    public static function get_disabled_defaults( int $user_id ): array {
        $raw = get_user_meta( $user_id, self::META_DISABLED_DEFAULT_PRESETS, true );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        return self::sanitize_slug_list( $raw );
    }

    /**
     * Store the plugin-default slugs this author has disabled.
     *
     * @param int     $user_id
     * @param string[] $slugs
     * @return string[] The sanitized list that was stored.
     */
    public static function set_disabled_defaults( int $user_id, array $slugs ): array {
        $clean = self::sanitize_slug_list( $slugs );
        update_user_meta( $user_id, self::META_DISABLED_DEFAULT_PRESETS, $clean );
        return $clean;
    }

    /**
     * Read the admin-curated per-taxonomy default map (sanitized).
     *
     * Map shape: term slug => preset slug, or term slug =>
     * PressHub_AI_Preset_Store::ORG_NONE ('__none__') to disable presets
     * for that term. Entries with illegal keys/values are dropped so bad
     * data in the DB never escapes into resolution.
     *
     * @return array
     */
    public static function get_taxonomy_presets(): array {
        $raw = get_option( self::OPTION_TAXONOMY_PRESETS, [] );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        return self::sanitize_org_map( $raw );
    }

    /**
     * Sanitize and store the per-taxonomy default map.
     *
     * P-1: written with autoload=false, like the other preset options.
     *
     * @param array $map term slug => preset slug ('' / missing clears).
     * @return array The sanitized map that was stored.
     */
    public static function save_taxonomy_presets( array $map ): array {
        $clean = self::sanitize_org_map( $map );
        update_option( self::OPTION_TAXONOMY_PRESETS, $clean, false );
        return $clean;
    }

    /**
     * Read the admin-curated per-role default map (sanitized).
     *
     * Map shape: role => preset slug, or role => ORG_NONE ('__none__')
     * to disable presets for that role. Entries with illegal keys/values
     * are dropped.
     *
     * @return array
     */
    public static function get_role_presets(): array {
        $raw = get_option( self::OPTION_ROLE_PRESETS, [] );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        return self::sanitize_org_map( $raw );
    }

    /**
     * Sanitize and store the per-role default map.
     *
     * P-1: written with autoload=false, like the other preset options.
     *
     * @param array $map role => preset slug ('' / missing clears).
     * @return array The sanitized map that was stored.
     */
    public static function save_role_presets( array $map ): array {
        $clean = self::sanitize_org_map( $map );
        update_option( self::OPTION_ROLE_PRESETS, $clean, false );
        return $clean;
    }

    /**
     * Create or update one preset row in the given scope's library.
     *
     * Central home for the quota + upsert-by-slug invariants that the AJAX
     * handlers used to re-implement by hand (architect A-2/A-3):
     *   - input is run through PressHub_AI_Preset_Sanitizer before storage;
     *   - a full library (count($list) >= $max) rejects NEW slugs with a
     *     WP_Error 'preset_limit' (updates of existing rows always pass);
     *   - an update replaces the row in place — one row per slug, always;
     *   - when 'enabled' is omitted, an existing row keeps its current
     *     enabled state and a new row defaults to enabled=true.
     *
     * @param string $scope   'author' (user meta) or 'plugin' (option).
     * @param int    $user_id Author id; ignored for plugin scope.
     * @param array  $row     slug + name + instruction_text (+ optional enabled).
     * @param int    $max     Quota for the scope (AUTHOR_MAX_PRESETS / PLUGIN_MAX_PRESETS).
     * @return array|WP_Error The sanitized stored row, or WP_Error
     *                        ('preset_limit' when full, 'invalid_preset' when
     *                        the row cannot be sanitized).
     */
    public static function upsert( string $scope, int $user_id, array $row, int $max ) {
        $list = self::read_list( $scope, $user_id );

        $exists       = false;
        $prev_enabled = true;
        $slug         = isset( $row['slug'] ) ? (string) $row['slug'] : '';
        foreach ( $list as $existing ) {
            if ( $existing['slug'] === $slug ) {
                $exists       = true;
                $prev_enabled = $existing['enabled'];
                break;
            }
        }

        if ( ! $exists && count( $list ) >= $max ) {
            return new WP_Error( 'preset_limit', sprintf( 'Preset limit reached (%d max).', $max ) );
        }

        if ( ! array_key_exists( 'enabled', $row ) ) {
            $row['enabled'] = $exists ? $prev_enabled : true;
        }

        $clean = PressHub_AI_Preset_Sanitizer::sanitize_preset( $row );
        if ( $clean === null ) {
            return new WP_Error( 'invalid_preset', 'Invalid preset data.' );
        }

        $rows    = [];
        $updated = false;
        foreach ( $list as $existing ) {
            if ( $existing['slug'] === $clean['slug'] ) {
                $rows[]  = $clean;
                $updated = true;
            } else {
                $rows[] = $existing;
            }
        }
        if ( ! $updated ) {
            $rows[] = $clean;
        }

        self::write_list( $scope, $user_id, $rows );
        return $clean;
    }

    /**
     * Remove one preset row from the given scope's library.
     *
     * Soft delete (recommended for author scope so existing selections
     * keep resolving) sets enabled=false and keeps the row; hard delete
     * drops the row entirely (plugin scope, manage_options only).
     *
     * @param string $scope   'author' (user meta) or 'plugin' (option).
     * @param int    $user_id Author id; ignored for plugin scope.
     * @param string $slug    The preset slug to remove.
     * @param bool   $hard    true = hard delete, false = soft delete.
     * @return bool True when the slug existed (and the library was
     *              rewritten); false when the slug was not found.
     */
    public static function remove( string $scope, int $user_id, string $slug, bool $hard ): bool {
        $list  = self::read_list( $scope, $user_id );
        $rows  = [];
        $found = false;

        foreach ( $list as $row ) {
            if ( $row['slug'] === $slug ) {
                $found = true;
                if ( ! $hard ) {
                    $row['enabled'] = false;
                    $rows[]         = $row;
                }
                continue;
            }
            $rows[] = $row;
        }

        if ( ! $found ) {
            return false;
        }

        self::write_list( $scope, $user_id, $rows );
        return true;
    }

    /**
     * Copy a plugin-default preset into the author's own library
     * (copy-on-edit model, design §2.2).
     *
     * On a slug collision the copy is stored as '<slug>-copy-<n>' (the
     * base is truncated to 32 chars so the suffix always fits the 40-char
     * slug limit). Enforces the author quota (AUTHOR_MAX_PRESETS).
     *
     * @param int    $user_id
     * @param string $slug Source plugin-default slug.
     * @return array|WP_Error The new row, or WP_Error ('preset_not_found'
     *                        for an unknown/disabled source, 'preset_limit'
     *                        when the author library is full,
     *                        'too_many_copies' past the -copy-999 cap).
     */
    public static function copy_default_to_author( int $user_id, string $slug ) {
        $source = null;
        foreach ( self::get_plugin_defaults() as $preset ) {
            if ( $preset['slug'] === $slug ) {
                $source = $preset;
                break;
            }
        }
        if ( $source === null || ! $source['enabled'] ) {
            return new WP_Error( 'preset_not_found', 'Plugin default preset not found.' );
        }

        $list     = self::get_author_presets( $user_id );
        $new_slug = $slug;
        $n        = 1;
        while ( self::list_has_slug( $list, $new_slug ) ) {
            $new_slug = substr( $slug, 0, 32 ) . '-copy-' . $n;
            $n++;
            if ( $n > 999 ) {
                return new WP_Error( 'too_many_copies', 'Too many preset copies.' );
            }
        }

        if ( count( $list ) >= self::AUTHOR_MAX_PRESETS ) {
            return new WP_Error( 'preset_limit', sprintf( 'Preset limit reached (%d max).', self::AUTHOR_MAX_PRESETS ) );
        }

        $copy = [
            'slug'             => $new_slug,
            'name'             => $source['name'],
            'instruction_text' => $source['instruction_text'],
            'enabled'          => true,
        ];

        $list[] = $copy;
        self::save_author_presets( $user_id, $list );
        return $copy;
    }

    /**
     * Read the whole preset list for a scope (sanitized).
     */
    private static function read_list( string $scope, int $user_id ): array {
        return 'plugin' === $scope
            ? self::get_plugin_defaults()
            : self::get_author_presets( $user_id );
    }

    /**
     * Sanitize and store the whole preset list for a scope.
     */
    private static function write_list( string $scope, int $user_id, array $rows ): array {
        return 'plugin' === $scope
            ? self::save_plugin_defaults( $rows )
            : self::save_author_presets( $user_id, $rows );
    }

    /**
     * Whether a preset list contains the given slug.
     */
    private static function list_has_slug( array $list, string $slug ): bool {
        foreach ( $list as $row ) {
            if ( $row['slug'] === $slug ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Seed the plugin-default presets when the option is empty.
     *
     * Idempotent: only writes when the option is unset or an empty array,
     * so existing installations are never overwritten (design doc §2.1).
     *
     * @return void
     */
    public static function seed_plugin_defaults(): void {
        $raw = get_option( self::OPTION_DEFAULT_PRESETS, [] );
        if ( is_array( $raw ) && count( $raw ) > 0 ) {
            return;
        }
        // P-1: same autoload=false as save_plugin_defaults().
        update_option( self::OPTION_DEFAULT_PRESETS, self::SEEDED_PRESETS, false );
    }

    /**
     * Keep only well-formed, unique slugs.
     *
     * @param array $slugs
     * @return string[]
     */
    private static function sanitize_slug_list( array $slugs ): array {
        $out  = [];
        $seen = [];
        foreach ( $slugs as $slug ) {
            if ( ! is_string( $slug ) && ! is_numeric( $slug ) ) {
                continue;
            }
            $slug = (string) $slug;
            if ( $slug === '' || ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $slug ) ) {
                continue;
            }
            if ( isset( $seen[ $slug ] ) ) {
                continue;
            }
            $seen[ $slug ] = true;
            $out[] = $slug;
        }
        return $out;
    }

    /**
     * Sanitize an org-default map (term slug / role => preset slug).
     *
     * Rules (org defaults are admin-curated, but defense in depth):
     *   - Keys must match the slug regex (term slugs and the standard
     *     WP roles are all lowercase alphanumeric + hyphens).
     *   - Values must be a slug-regex preset slug, ORG_NONE ('__none__',
     *     the disable sentinel — underscores intentionally allowed), or
     *     '' which means "cleared" and drops the entry from the map.
     *   - First occurrence wins for duplicate keys.
     *
     * @param array $map
     * @return array
     */
    private static function sanitize_org_map( array $map ): array {
        $out = [];
        foreach ( $map as $key => $value ) {
            if ( ! is_string( $key ) && ! is_numeric( $key ) ) {
                continue;
            }
            $key = (string) $key;
            if ( $key === '' || ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $key ) ) {
                continue;
            }
            if ( isset( $out[ $key ] ) ) {
                continue;
            }
            if ( $value === null || $value === false ) {
                continue;
            }
            $value = (string) $value;
            if ( $value === '' ) {
                continue; // cleared
            }
            if ( $value !== self::ORG_NONE && ! preg_match( PressHub_AI_Preset_Sanitizer::SLUG_REGEX, $value ) ) {
                continue;
            }
            $out[ $key ] = $value;
        }
        return $out;
    }
}