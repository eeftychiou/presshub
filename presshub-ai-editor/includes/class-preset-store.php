<?php
/**
 * Thin storage wrapper for instruction presets.
 *
 * Backs three storage layers:
 *   - Plugin defaults:  option  'presshub_ai_default_presets'.
 *   - Per-author presets: user meta 'presshub_ai_author_presets'.
 *   - Per-author default slug: user meta 'presshub_ai_default_preset_id'.
 *   - Per-author disabled plugin-default slugs:
 *     user meta 'presshub_ai_disabled_default_presets'.
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
     * @param array $presets
     * @return array The sanitized list that was stored.
     */
    public static function save_plugin_defaults( array $presets ): array {
        $clean = PressHub_AI_Preset_Sanitizer::sanitize_presets( $presets );
        update_option( self::OPTION_DEFAULT_PRESETS, $clean );
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
        update_option( self::OPTION_DEFAULT_PRESETS, self::SEEDED_PRESETS );
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
}