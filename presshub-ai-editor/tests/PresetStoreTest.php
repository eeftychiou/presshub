<?php
/**
 * TDD tests for PressHub_AI_Preset_Store.
 *
 * Covers:
 *   - Plugin defaults: option presshub_ai_default_presets read/write, reads
 *     sanitized, empty default.
 *   - Author presets: user meta presshub_ai_author_presets read/write,
 *     reads sanitized, empty default.
 *   - Author default slug: user meta presshub_ai_default_preset_id.
 *   - Disabled plugin-default slugs: user meta presshub_ai_disabled_default_presets.
 *   - seed_plugin_defaults(): seeds exactly the 3 curated presets when the
 *     option is empty; idempotent (second call no-op); never overwrites an
 *     existing non-empty option.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-preset-sanitizer.php';
require_once __DIR__ . '/../includes/class-preset-store.php';

class PresetStoreTest
{
    public static function run(): void {
        $failures = [];

        // --- Plugin defaults: empty default ---
        self::reset();
        $out = PressHub_AI_Preset_Store::get_plugin_defaults();
        if ( $out !== [] ) {
            $failures[] = "get_plugin_defaults() should return [] when unset. Got: " . var_export( $out, true );
        }

        // --- Plugin defaults: save + read round-trip ---
        self::reset();
        $presets = [
            [ 'slug' => 'wire-style', 'name' => 'Wire service concise', 'instruction_text' => 'Lead with the news.', 'enabled' => true ],
            [ 'slug' => 'interview-focus', 'name' => 'Interview-driven', 'instruction_text' => 'Anchor every section in a direct quotation.', 'enabled' => true ],
        ];
        $saved = PressHub_AI_Preset_Store::save_plugin_defaults( $presets );
        if ( count( $saved ) !== 2 ) {
            $failures[] = "save_plugin_defaults() should return the sanitized stored list (got " . count( $saved ) . ").";
        }
        // P-1: the plugin-defaults option must never be autoloaded (it can
        // hold up to ~200KB of serialized preset data).
        $update_calls = $GLOBALS['UPDATE_OPTION_CALLS'] ?? [];
        $last_update  = end( $update_calls );
        if ( ! is_array( $last_update ) || $last_update[0] !== 'presshub_ai_default_presets' ) {
            $failures[] = "save_plugin_defaults() should write via update_option('presshub_ai_default_presets', ...). Got: " . var_export( $last_update, true );
        } elseif ( $last_update[2] !== false ) {
            $failures[] = "save_plugin_defaults() must pass autoload=false (P-1). Got: " . var_export( $last_update[2], true );
        }
        $read = PressHub_AI_Preset_Store::get_plugin_defaults();
        if ( count( $read ) !== 2 || $read[0]['slug'] !== 'wire-style' || $read[1]['slug'] !== 'interview-focus' ) {
            $failures[] = "Plugin defaults round-trip failed. Got: " . var_export( $read, true );
        }

        // --- Plugin defaults: writes are sanitized before storing ---
        self::reset();
        $dirty = [
            [ 'slug' => 'Bad_Slug', 'instruction_text' => 'dropped' ],
            [ 'slug' => 'ok-slug', 'name' => str_repeat( 'n', 200 ), 'instruction_text' => 'kept', 'enabled' => 0 ],
        ];
        PressHub_AI_Preset_Store::save_plugin_defaults( $dirty );
        $stored = $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] ?? null;
        if ( ! is_array( $stored ) || count( $stored ) !== 1 ) {
            $failures[] = "Dirty plugin defaults should be sanitized before storing. Got: " . var_export( $stored, true );
        } elseif ( strlen( $stored[0]['name'] ) !== 80 ) {
            $failures[] = "Stored plugin default name should be truncated to 80.";
        } elseif ( $stored[0]['enabled'] !== false ) {
            $failures[] = "Stored plugin default enabled should be coerced to bool false.";
        }

        // --- Plugin defaults: reads are sanitized (defense in depth) ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'illegal_slug', 'instruction_text' => 'nope' ],
            [ 'slug' => 'valid', 'instruction_text' => 'yes', 'name' => str_repeat( 'x', 100 ) ],
        ];
        $read = PressHub_AI_Preset_Store::get_plugin_defaults();
        if ( count( $read ) !== 1 || $read[0]['slug'] !== 'valid' ) {
            $failures[] = "Plugin defaults read should sanitize bad rows. Got: " . var_export( $read, true );
        } elseif ( strlen( $read[0]['name'] ) !== 80 ) {
            $failures[] = "Plugin defaults read should truncate name.";
        }

        // --- Author presets: empty default ---
        self::reset();
        $out = PressHub_AI_Preset_Store::get_author_presets( 7 );
        if ( $out !== [] ) {
            $failures[] = "get_author_presets() should return [] when unset. Got: " . var_export( $out, true );
        }

        // --- Author presets: save + read round-trip ---
        self::reset();
        $mine = [
            [ 'slug' => 'my-style', 'name' => 'My style', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
        ];
        $saved = PressHub_AI_Preset_Store::save_author_presets( 7, $mine );
        if ( count( $saved ) !== 1 ) {
            $failures[] = "save_author_presets() should return the sanitized stored list.";
        }
        $read = PressHub_AI_Preset_Store::get_author_presets( 7 );
        if ( count( $read ) !== 1 || $read[0]['slug'] !== 'my-style' ) {
            $failures[] = "Author presets round-trip failed. Got: " . var_export( $read, true );
        }
        // Different user must not see them.
        if ( PressHub_AI_Preset_Store::get_author_presets( 8 ) !== [] ) {
            $failures[] = "Author presets should be scoped per user.";
        }

        // --- Author presets: reads are sanitized ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'Bad Slug', 'instruction_text' => 'x' ],
            [ 'slug' => 'good', 'instruction_text' => str_repeat( 'y', 5000 ) ],
        ];
        $read = PressHub_AI_Preset_Store::get_author_presets( 7 );
        if ( count( $read ) !== 1 || $read[0]['slug'] !== 'good' ) {
            $failures[] = "Author presets read should sanitize bad rows. Got: " . var_export( $read, true );
        } elseif ( strlen( $read[0]['instruction_text'] ) !== 4000 ) {
            $failures[] = "Author presets read should truncate instruction_text to 4000.";
        }

        // --- Author default slug: empty default ---
        self::reset();
        $slug = PressHub_AI_Preset_Store::get_author_default_slug( 7 );
        if ( $slug !== '' ) {
            $failures[] = "get_author_default_slug() should return '' when unset. Got: {$slug}";
        }

        // --- Author default slug: set + read round-trip ---
        self::reset();
        $ret = PressHub_AI_Preset_Store::set_author_default_slug( 7, 'wire-style' );
        if ( $ret !== 'wire-style' ) {
            $failures[] = "set_author_default_slug() should return the stored slug.";
        }
        $slug = PressHub_AI_Preset_Store::get_author_default_slug( 7 );
        if ( $slug !== 'wire-style' ) {
            $failures[] = "Author default slug round-trip failed. Got: {$slug}";
        }
        if ( PressHub_AI_Preset_Store::get_author_default_slug( 8 ) !== '' ) {
            $failures[] = "Author default slug should be scoped per user.";
        }

        // --- Disabled defaults: empty default ---
        self::reset();
        $out = PressHub_AI_Preset_Store::get_disabled_defaults( 7 );
        if ( $out !== [] ) {
            $failures[] = "get_disabled_defaults() should return [] when unset. Got: " . var_export( $out, true );
        }

        // --- Disabled defaults: set + read round-trip ---
        self::reset();
        $ret = PressHub_AI_Preset_Store::set_disabled_defaults( 7, [ 'wire-style', 'fact-check' ] );
        if ( $ret !== [ 'wire-style', 'fact-check' ] ) {
            $failures[] = "set_disabled_defaults() should return the stored list. Got: " . var_export( $ret, true );
        }
        $out = PressHub_AI_Preset_Store::get_disabled_defaults( 7 );
        if ( $out !== [ 'wire-style', 'fact-check' ] ) {
            $failures[] = "Disabled defaults round-trip failed. Got: " . var_export( $out, true );
        }

        // --- Disabled defaults: invalid slugs filtered ---
        self::reset();
        PressHub_AI_Preset_Store::set_disabled_defaults( 7, [ 'ok-slug', 'Bad Slug', '', 'dup', 'dup' ] );
        $out = PressHub_AI_Preset_Store::get_disabled_defaults( 7 );
        if ( $out !== [ 'ok-slug', 'dup' ] ) {
            $failures[] = "Disabled defaults should keep only valid unique slugs. Got: " . var_export( $out, true );
        }

        // --- seed_plugin_defaults(): seeds exactly 3 curated presets when empty ---
        self::reset();
        PressHub_AI_Preset_Store::seed_plugin_defaults();
        // P-1: the seed write must also pass autoload=false.
        $seed_calls = $GLOBALS['UPDATE_OPTION_CALLS'] ?? [];
        $seed_last  = end( $seed_calls );
        if ( ! is_array( $seed_last ) || $seed_last[0] !== 'presshub_ai_default_presets' ) {
            $failures[] = "seed_plugin_defaults() should write via update_option('presshub_ai_default_presets', ...). Got: " . var_export( $seed_last, true );
        } elseif ( $seed_last[2] !== false ) {
            $failures[] = "seed_plugin_defaults() must pass autoload=false (P-1). Got: " . var_export( $seed_last[2], true );
        }
        $seeded = PressHub_AI_Preset_Store::get_plugin_defaults();
        if ( count( $seeded ) !== 3 ) {
            $failures[] = "seed_plugin_defaults() should seed exactly 3 presets. Got " . count( $seeded ) . ": " . var_export( $seeded, true );
        } else {
            $expected = [
                'wire-style'      => [ 'name' => 'Wire service concise',    'instruction_text' => 'Use the inverted pyramid. Lead with the news; compress context into subsequent paragraphs.' ],
                'interview-focus' => [ 'name' => 'Interview-driven',        'instruction_text' => 'Anchor every section in a direct quotation from the source notes.' ],
                'fact-check'      => [ 'name' => 'Fact-check everything',   'instruction_text' => 'For every factual claim, add a parenthetical citing the source paragraph. Flag any unsourced claim.' ],
            ];
            foreach ( $seeded as $row ) {
                if ( ! isset( $expected[ $row['slug'] ] ) ) {
                    $failures[] = "Unexpected seeded slug: " . $row['slug'];
                    continue;
                }
                if ( $row['name'] !== $expected[ $row['slug'] ]['name'] ) {
                    $failures[] = "Seeded {$row['slug']} name mismatch. Got: {$row['name']}";
                }
                if ( $row['instruction_text'] !== $expected[ $row['slug'] ]['instruction_text'] ) {
                    $failures[] = "Seeded {$row['slug']} instruction_text mismatch. Got: {$row['instruction_text']}";
                }
                if ( $row['enabled'] !== true ) {
                    $failures[] = "Seeded {$row['slug']} should be enabled.";
                }
            }
        }

        // --- seed_plugin_defaults(): second call is a no-op ---
        PressHub_AI_Preset_Store::seed_plugin_defaults();
        $again = PressHub_AI_Preset_Store::get_plugin_defaults();
        if ( count( $again ) !== 3 ) {
            $failures[] = "seed_plugin_defaults() must be idempotent (got " . count( $again ) . " after second call).";
        }

        // --- seed_plugin_defaults(): never overwrites a non-empty option ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'custom', 'name' => 'Custom', 'instruction_text' => 'Keep mine.', 'enabled' => true ],
        ];
        PressHub_AI_Preset_Store::seed_plugin_defaults();
        $kept = PressHub_AI_Preset_Store::get_plugin_defaults();
        if ( count( $kept ) !== 1 || $kept[0]['slug'] !== 'custom' ) {
            $failures[] = "seed_plugin_defaults() must not overwrite an existing non-empty option. Got: " . var_export( $kept, true );
        }

        // --- seed_plugin_defaults(): stored empty array also seeds ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [];
        PressHub_AI_Preset_Store::seed_plugin_defaults();
        $seeded = PressHub_AI_Preset_Store::get_plugin_defaults();
        if ( count( $seeded ) !== 3 ) {
            $failures[] = "seed_plugin_defaults() should seed when the option is an empty array (got " . count( $seeded ) . ").";
        }

        // ==================================================================
        // upsert() — sanitize + quota + upsert-by-slug (architect A-2/A-3).
        // ==================================================================

        // --- upsert: append a new row (author scope) ---
        self::reset();
        $saved = PressHub_AI_Preset_Store::upsert( 'author', 7, [
            'slug'             => 'punchy',
            'name'             => 'Punchy',
            'instruction_text' => 'Be punchy.',
            'enabled'          => true,
        ], 25 );
        if ( is_wp_error( $saved ) || ( $saved['slug'] ?? '' ) !== 'punchy' || ( $saved['enabled'] ?? null ) !== true ) {
            $failures[] = "upsert() should return the sanitized new row. Got: " . var_export( $saved, true );
        }
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ?? null;
        if ( ! is_array( $stored ) || count( $stored ) !== 1 || $stored[0]['slug'] !== 'punchy' ) {
            $failures[] = "upsert() should append to the author library. Got: " . var_export( $stored, true );
        }

        // --- upsert: enabled omitted on a new row defaults to true ---
        self::reset();
        $saved = PressHub_AI_Preset_Store::upsert( 'author', 7, [
            'slug'             => 'quiet',
            'name'             => 'Quiet',
            'instruction_text' => 'Terse.',
        ], 25 );
        if ( is_wp_error( $saved ) || ( $saved['enabled'] ?? null ) !== true ) {
            $failures[] = "upsert() should default enabled=true for new rows. Got: " . var_export( $saved, true );
        }

        // --- upsert: update in place (no duplicate rows), enabled preserved ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
            [ 'slug' => 'other', 'name' => 'Other', 'instruction_text' => 'Keep.', 'enabled' => true ],
        ];
        $saved = PressHub_AI_Preset_Store::upsert( 'author', 7, [
            'slug'             => 'punchy',
            'name'             => 'Punchy v2',
            'instruction_text' => 'New text.',
        ], 25 );
        if ( is_wp_error( $saved ) ) {
            $failures[] = "upsert() update should not error. Got: " . $saved->get_error_message();
        }
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'];
        if ( count( $stored ) !== 2 ) {
            $failures[] = "upsert() update must keep one row per slug. Got: " . var_export( $stored, true );
        } elseif ( $stored[0]['name'] !== 'Punchy v2' || $stored[0]['enabled'] !== true ) {
            $failures[] = "upsert() should replace in place and preserve the existing enabled flag. Got: " . var_export( $stored[0], true );
        }

        // --- upsert: explicit enabled=false on update is honored ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
        ];
        $saved = PressHub_AI_Preset_Store::upsert( 'author', 7, [
            'slug'             => 'punchy',
            'name'             => 'Punchy',
            'instruction_text' => 'Be punchy.',
            'enabled'          => false,
        ], 25 );
        if ( is_wp_error( $saved ) || ( $saved['enabled'] ?? null ) !== false ) {
            $failures[] = "upsert() should honor an explicit enabled=false. Got: " . var_export( $saved, true );
        }

        // --- upsert: author quota -> WP_Error 'preset_limit' ---
        self::reset();
        $rows = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $rows[] = [ 'slug' => "slug-{$i}", 'name' => "N{$i}", 'instruction_text' => 'T', 'enabled' => true ];
        }
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = $rows;
        $saved = PressHub_AI_Preset_Store::upsert( 'author', 7, [
            'slug' => 'overflow', 'name' => 'Over', 'instruction_text' => 'T',
        ], 25 );
        if ( ! is_wp_error( $saved ) || $saved->get_error_code() !== 'preset_limit' ) {
            $failures[] = "upsert() should return WP_Error preset_limit when full. Got: " . var_export( $saved, true );
        } elseif ( false === strpos( $saved->get_error_message(), '25' ) ) {
            $failures[] = "upsert() quota message should mention the max. Got: " . $saved->get_error_message();
        }
        if ( count( $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ) !== 25 ) {
            $failures[] = "upsert() quota rejection must not mutate the library.";
        }

        // --- upsert: quota does NOT block updates of an existing row ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = $rows;
        $saved = PressHub_AI_Preset_Store::upsert( 'author', 7, [
            'slug' => 'slug-0', 'name' => 'N0 v2', 'instruction_text' => 'T',
        ], 25 );
        if ( is_wp_error( $saved ) ) {
            $failures[] = "upsert() must allow updating an existing row past the quota. Got: " . $saved->get_error_message();
        }

        // --- upsert: plugin scope writes the option, user meta untouched ---
        self::reset();
        $saved = PressHub_AI_Preset_Store::upsert( 'plugin', 0, [
            'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.',
        ], 50 );
        if ( is_wp_error( $saved ) ) {
            $failures[] = "upsert() plugin scope failed. Got: " . $saved->get_error_message();
        }
        $opt = $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] ?? null;
        if ( ! is_array( $opt ) || count( $opt ) !== 1 || $opt[0]['slug'] !== 'wire-style' ) {
            $failures[] = "upsert() plugin scope should update the option. Got: " . var_export( $opt, true );
        }
        if ( isset( $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ) ) {
            $failures[] = "upsert() plugin scope must not touch user meta.";
        }

        // --- upsert: plugin quota -> WP_Error 'preset_limit' ---
        self::reset();
        $rows50 = [];
        for ( $i = 0; $i < 50; $i++ ) {
            $rows50[] = [ 'slug' => "slug-{$i}", 'name' => "N{$i}", 'instruction_text' => 'T', 'enabled' => true ];
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = $rows50;
        $saved = PressHub_AI_Preset_Store::upsert( 'plugin', 0, [
            'slug' => 'overflow', 'name' => 'Over', 'instruction_text' => 'T',
        ], 50 );
        if ( ! is_wp_error( $saved ) || $saved->get_error_code() !== 'preset_limit' ) {
            $failures[] = "upsert() plugin quota should return preset_limit. Got: " . var_export( $saved, true );
        }

        // --- upsert: dirty input is sanitized before storing ---
        self::reset();
        $saved = PressHub_AI_Preset_Store::upsert( 'author', 7, [
            'slug'             => 'ok-slug',
            'name'             => str_repeat( 'n', 200 ),
            'instruction_text' => 'kept',
        ], 25 );
        if ( is_wp_error( $saved ) || strlen( $saved['name'] ) !== 80 ) {
            $failures[] = "upsert() should truncate over-length names to 80. Got: " . var_export( $saved, true );
        }

        // --- upsert: unsalvageable row -> WP_Error 'invalid_preset' ---
        self::reset();
        $saved = PressHub_AI_Preset_Store::upsert( 'author', 7, [
            'slug' => 'Bad_Slug', 'instruction_text' => 'x',
        ], 25 );
        if ( ! is_wp_error( $saved ) || $saved->get_error_code() !== 'invalid_preset' ) {
            $failures[] = "upsert() should reject an illegal slug with invalid_preset. Got: " . var_export( $saved, true );
        }
        if ( isset( $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ) ) {
            $failures[] = "upsert() invalid row must not be stored.";
        }

        // ==================================================================
        // remove() — soft vs hard delete (architect A-2).
        // ==================================================================

        // --- remove: soft delete keeps the row, enabled=false ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
        ];
        $ok = PressHub_AI_Preset_Store::remove( 'author', 7, 'punchy', false );
        if ( $ok !== true ) {
            $failures[] = "remove() soft delete should return true. Got: " . var_export( $ok, true );
        }
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'];
        if ( count( $stored ) !== 1 || $stored[0]['slug'] !== 'punchy' || $stored[0]['enabled'] !== false ) {
            $failures[] = "remove() soft delete should keep the row with enabled=false. Got: " . var_export( $stored, true );
        }

        // --- remove: hard delete removes the row (author scope) ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
            [ 'slug' => 'other', 'name' => 'Other', 'instruction_text' => 'Keep.', 'enabled' => true ],
        ];
        $ok = PressHub_AI_Preset_Store::remove( 'author', 7, 'punchy', true );
        if ( $ok !== true ) {
            $failures[] = "remove() hard delete should return true. Got: " . var_export( $ok, true );
        }
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'];
        if ( count( $stored ) !== 1 || $stored[0]['slug'] !== 'other' ) {
            $failures[] = "remove() hard delete should drop the row. Got: " . var_export( $stored, true );
        }

        // --- remove: plugin scope hard deletes from the option ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
            [ 'slug' => 'fact-check', 'name' => 'Fact check', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $ok = PressHub_AI_Preset_Store::remove( 'plugin', 0, 'wire-style', true );
        if ( $ok !== true ) {
            $failures[] = "remove() plugin scope should return true. Got: " . var_export( $ok, true );
        }
        $opt = $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'];
        if ( count( $opt ) !== 1 || $opt[0]['slug'] !== 'fact-check' ) {
            $failures[] = "remove() plugin scope should hard-delete from the option. Got: " . var_export( $opt, true );
        }

        // --- remove: unknown slug -> false, no mutation ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
        ];
        $ok = PressHub_AI_Preset_Store::remove( 'author', 7, 'no-such', false );
        if ( $ok !== false ) {
            $failures[] = "remove() unknown slug should return false. Got: " . var_export( $ok, true );
        }
        if ( count( $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ) !== 1 ) {
            $failures[] = "remove() not-found must not mutate the library.";
        }

        // ==================================================================
        // copy_default_to_author() — collision suffix + quota (architect A-2).
        // ==================================================================

        // --- copy: happy path into an empty author library ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
        ];
        $copy = PressHub_AI_Preset_Store::copy_default_to_author( 7, 'wire-style' );
        if ( is_wp_error( $copy ) || ( $copy['slug'] ?? '' ) !== 'wire-style' || ( $copy['enabled'] ?? null ) !== true ) {
            $failures[] = "copy_default_to_author() should return the new row. Got: " . var_export( $copy, true );
        }
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ?? null;
        if ( ! is_array( $stored ) || count( $stored ) !== 1 || $stored[0]['instruction_text'] !== 'Inverted pyramid.' ) {
            $failures[] = "copy_default_to_author() should copy the default into the author library. Got: " . var_export( $stored, true );
        }

        // --- copy: slug collision -> '-copy-1' suffix ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Mine', 'instruction_text' => 'My own copy.', 'enabled' => true ],
        ];
        $copy = PressHub_AI_Preset_Store::copy_default_to_author( 7, 'wire-style' );
        if ( is_wp_error( $copy ) || ( $copy['slug'] ?? '' ) !== 'wire-style-copy-1' ) {
            $failures[] = "copy_default_to_author() collision should suffix -copy-1. Got: " . var_export( $copy, true );
        }
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'];
        if ( count( $stored ) !== 2 ) {
            $failures[] = "copy_default_to_author() collision should append, not replace. Got: " . var_export( $stored, true );
        }

        // --- copy: collision chain advances to -copy-2 ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Mine', 'instruction_text' => 'A', 'enabled' => true ],
            [ 'slug' => 'wire-style-copy-1', 'name' => 'Mine', 'instruction_text' => 'B', 'enabled' => true ],
        ];
        $copy = PressHub_AI_Preset_Store::copy_default_to_author( 7, 'wire-style' );
        if ( is_wp_error( $copy ) || ( $copy['slug'] ?? '' ) !== 'wire-style-copy-2' ) {
            $failures[] = "copy_default_to_author() collision chain should advance to -copy-2. Got: " . var_export( $copy, true );
        }

        // --- copy: long slug suffix stays within the 40-char slug limit ---
        self::reset();
        $long = str_repeat( 'a', 40 );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => $long, 'name' => 'Long', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => $long, 'name' => 'Mine', 'instruction_text' => 'A', 'enabled' => true ],
        ];
        $copy = PressHub_AI_Preset_Store::copy_default_to_author( 7, $long );
        if ( is_wp_error( $copy ) || ( $copy['slug'] ?? '' ) !== substr( $long, 0, 32 ) . '-copy-1' ) {
            $failures[] = "copy_default_to_author() must truncate the base so the suffix fits 40 chars. Got: " . var_export( $copy, true );
        }

        // --- copy: author quota -> WP_Error 'preset_limit' ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $rows = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $rows[] = [ 'slug' => "slug-{$i}", 'name' => "N{$i}", 'instruction_text' => 'T', 'enabled' => true ];
        }
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = $rows;
        $copy = PressHub_AI_Preset_Store::copy_default_to_author( 7, 'wire-style' );
        if ( ! is_wp_error( $copy ) || $copy->get_error_code() !== 'preset_limit' ) {
            $failures[] = "copy_default_to_author() should return preset_limit when the author library is full. Got: " . var_export( $copy, true );
        }

        // --- copy: unknown plugin slug -> WP_Error 'preset_not_found' ---
        self::reset();
        $copy = PressHub_AI_Preset_Store::copy_default_to_author( 7, 'no-such-preset' );
        if ( ! is_wp_error( $copy ) || $copy->get_error_code() !== 'preset_not_found' ) {
            $failures[] = "copy_default_to_author() unknown slug should return preset_not_found. Got: " . var_export( $copy, true );
        }

        // --- copy: disabled plugin default -> WP_Error 'preset_not_found' ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'ghost', 'name' => 'Ghost', 'instruction_text' => 'T', 'enabled' => false ],
        ];
        $copy = PressHub_AI_Preset_Store::copy_default_to_author( 7, 'ghost' );
        if ( ! is_wp_error( $copy ) || $copy->get_error_code() !== 'preset_not_found' ) {
            $failures[] = "copy_default_to_author() disabled source should return preset_not_found. Got: " . var_export( $copy, true );
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    private static function reset(): void {
        unset( $GLOBALS['OPTIONS_STORE'] );
        unset( $GLOBALS['USER_META_STORE'] );
        unset( $GLOBALS['UPDATE_OPTION_CALLS'] );
    }
}

PresetStoreTest::run();