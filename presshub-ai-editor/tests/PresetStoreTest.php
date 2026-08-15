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
    }
}

PresetStoreTest::run();