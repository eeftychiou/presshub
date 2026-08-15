<?php
/**
 * TDD tests for PressHub_AI_Preset_Resolver.
 *
 * Covers composition semantics (design doc §3):
 *   - Precedence: per-request slug > author default > plugin default.
 *   - Author default slug names an author preset OR a plugin-default preset.
 *   - Author preset shadows a plugin default with the same slug.
 *   - Unknown slug falls back down the chain.
 *   - Endpoint gating: 'scorecard', 'classify', 'audio' always return null.
 *   - Disabled presets skipped; disabled-defaults list respected.
 *   - Empty instruction_text skipped.
 *   - One preset per request: returns a single instruction_text or null.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-preset-sanitizer.php';
require_once __DIR__ . '/../includes/class-preset-store.php';
require_once __DIR__ . '/../includes/class-preset-resolver.php';

class PresetResolverTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: nothing configured anywhere -> null ---
        self::reset();
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== null ) {
            $failures[] = "No presets anywhere should resolve to null. Got: " . var_export( $r, true );
        }

        // --- Case 2: plugin default applies via author's default slug ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'wire-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== 'PLUGIN_TEXT' ) {
            $failures[] = "Plugin default should apply via author default slug. Got: " . var_export( $r, true );
        }

        // --- Case 3: author preset applies via author's default slug ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== 'AUTHOR_TEXT' ) {
            $failures[] = "Author preset should apply via author default slug. Got: " . var_export( $r, true );
        }

        // --- Case 4: same slug in both scopes -> author preset wins ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'shared', 'name' => 'Plugin', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'shared', 'name' => 'Author', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'shared';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== 'AUTHOR_TEXT' ) {
            $failures[] = "Author preset should shadow plugin default with same slug. Got: " . var_export( $r, true );
        }

        // --- Case 5: per-request slug beats author default ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', 'wire-style' );
        if ( $r !== 'PLUGIN_TEXT' ) {
            $failures[] = "Per-request slug should beat author default. Got: " . var_export( $r, true );
        }

        // --- Case 6: per-request slug naming an author preset ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'PUNCHY_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', 'punchy' );
        if ( $r !== 'PUNCHY_TEXT' ) {
            $failures[] = "Per-request slug naming an author preset should apply. Got: " . var_export( $r, true );
        }

        // --- Case 7: unknown per-request slug falls back to author default ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', 'no-such-preset' );
        if ( $r !== 'AUTHOR_TEXT' ) {
            $failures[] = "Unknown per-request slug should fall back to author default. Got: " . var_export( $r, true );
        }

        // --- Case 8: unknown author default slug resolves to null ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'ghost-slug';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== null ) {
            $failures[] = "Unknown author default slug should resolve to null. Got: " . var_export( $r, true );
        }

        // --- Case 9: endpoint gating — scorecard/classify/audio always null ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        foreach ( [ 'scorecard', 'classify', 'audio' ] as $endpoint ) {
            $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, $endpoint, 'wire-style' );
            if ( $r !== null ) {
                $failures[] = "Endpoint '{$endpoint}' must return null regardless of presets. Got: " . var_export( $r, true );
            }
            $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, $endpoint, null );
            if ( $r !== null ) {
                $failures[] = "Endpoint '{$endpoint}' must return null without per-request slug. Got: " . var_export( $r, true );
            }
        }

        // --- Case 10: allowed endpoints draft/chat/research resolve ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        foreach ( [ 'draft', 'chat', 'research' ] as $endpoint ) {
            $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, $endpoint, null );
            if ( $r !== 'AUTHOR_TEXT' ) {
                $failures[] = "Endpoint '{$endpoint}' should resolve presets. Got: " . var_export( $r, true );
            }
        }

        // --- Case 11: unknown endpoint fails closed ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'unknown-endpoint', null );
        if ( $r !== null ) {
            $failures[] = "Unknown endpoint should fail closed with null. Got: " . var_export( $r, true );
        }

        // --- Case 12: disabled author preset (enabled=false) is skipped ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => false ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== null ) {
            $failures[] = "Disabled author preset should be skipped even as default. Got: " . var_export( $r, true );
        }
        // Disabled author preset shadows same-slug plugin default.
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Plugin', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== null ) {
            $failures[] = "Disabled author copy should shadow the same-slug plugin default. Got: " . var_export( $r, true );
        }

        // --- Case 13: disabled plugin-default preset is skipped ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => false ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'wire-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== null ) {
            $failures[] = "Disabled plugin-default preset should be skipped. Got: " . var_export( $r, true );
        }

        // --- Case 14: author's disabled-defaults list blocks plugin defaults ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'wire-style';
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_disabled_default_presets'] = [ 'wire-style' ];
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== null ) {
            $failures[] = "Disabled-defaults list should block the plugin default. Got: " . var_export( $r, true );
        }
        // But an explicit per-request pick of a NON-disabled plugin default still works.
        unset( $GLOBALS['USER_META_STORE'][7]['presshub_ai_disabled_default_presets'] );
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', 'wire-style' );
        if ( $r !== 'PLUGIN_TEXT' ) {
            $failures[] = "Per-request plugin-default pick should still resolve. Got: " . var_export( $r, true );
        }

        // --- Case 15: empty (whitespace-only) instruction_text is skipped ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'blank', 'name' => 'Blank', 'instruction_text' => '   ', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'blank';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', null );
        if ( $r !== null ) {
            $failures[] = "Whitespace-only instruction_text should be skipped. Got: " . var_export( $r, true );
        }

        // --- Case 16: '__none__' sentinel disables presets entirely ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', '__none__' );
        if ( $r !== null ) {
            $failures[] = "'__none__' sentinel should disable presets entirely. Got: " . var_export( $r, true );
        }

        // --- Case 17: '__plugin_default__' sentinel means "use author default" ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', '__plugin_default__' );
        if ( $r !== 'AUTHOR_TEXT' ) {
            $failures[] = "'__plugin_default__' sentinel should fall through to author default. Got: " . var_export( $r, true );
        }

        // --- Case 18: missing user (id 0) short-circuits to null ---
        self::reset();
        $GLOBALS['USER_META_STORE'][0]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][0]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 0, 'draft', 'my-style' );
        if ( $r !== null ) {
            $failures[] = "User id 0 should short-circuit to null. Got: " . var_export( $r, true );
        }

        // --- Case 19: empty per-request slug behaves like null ---
        self::reset();
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $r = PressHub_AI_Preset_Resolver::resolve_for_user( 7, 'draft', '' );
        if ( $r !== 'AUTHOR_TEXT' ) {
            $failures[] = "Empty per-request slug should fall through to author default. Got: " . var_export( $r, true );
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

PresetResolverTest::run();