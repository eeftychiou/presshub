<?php
/**
 * #233 step 4: the six settings that changed behaviour without a field on
 * screen are now shown in their Daily Briefing block, show their stored value
 * on first load, and save through the per-tab save.
 *
 * Also checks the registry's "rendered" flag against the real AI settings
 * page: every AI option the registry says is rendered has a field there.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once __DIR__ . '/../presshub-ai-editor.php';
require_once __DIR__ . '/settings-sections-helper.php';

class SettingsHiddenSurfacedTest {

    const SURFACED = [
        'presshub_ai_briefing_tts_engine',
        'presshub_ai_briefing_text_temperature',
        'presshub_ai_briefing_text_timeout',
        'presshub_ai_briefing_podcast_temperature',
        'presshub_ai_briefing_podcast_timeout',
        'presshub_ai_briefing_podcast_preset',
    ];

    private static function render_page(): string {
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_others_posts' ];
        return presshub_test_render_all_sections();
    }

    /** HTML of one tab pane. */
    private static function pane( string $html, string $tab ): string {
        return presshub_test_tab_pane( $html, $tab );
    }

    public static function run(): void {
        $failures = [];
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();

        // 1. Stored values (not defaults) show on first load, inside the Briefing pane.
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_briefing_tts_engine'          => 'google_cloud',
            'presshub_ai_briefing_text_temperature'    => 0.35,
            'presshub_ai_briefing_text_timeout'        => 240,
            'presshub_ai_briefing_podcast_temperature' => 1.1,
            'presshub_ai_briefing_podcast_timeout'     => 420,
        ];
        $briefing = self::pane( self::render_page(), 'briefing' );
        if ( '' === $briefing ) {
            $failures[] = 'Briefing pane not found.';
        }
        foreach ( self::SURFACED as $key ) {
            if ( false === strpos( $briefing, 'name="' . $key . '"' ) ) {
                $failures[] = "{$key} has no field on the Daily Briefing tab.";
            }
            $e = PressHub_Settings_Registry::get( $key );
            if ( empty( $e['rendered'] ) || 'briefing' !== $e['tab'] ) {
                $failures[] = "{$key} should be declared rendered on the briefing tab.";
            }
        }
        $expect_values = [
            'presshub_ai_briefing_text_temperature'    => '0.35',
            'presshub_ai_briefing_text_timeout'        => '240',
            'presshub_ai_briefing_podcast_temperature' => '1.1',
            'presshub_ai_briefing_podcast_timeout'     => '420',
        ];
        foreach ( $expect_values as $key => $v ) {
            if ( ! preg_match( '/name="' . preg_quote( $key, '/' ) . '"[^>]*value="' . preg_quote( $v, '/' ) . '"/', $briefing ) ) {
                $failures[] = "{$key} should show its stored value {$v}.";
            }
        }
        if ( ! preg_match( '/<option value="google_cloud"\s+selected/', $briefing ) ) {
            $failures[] = 'Speech engine should show the stored engine (google_cloud) selected.';
        }
        // Order: curator tuning after curator tokens, speech engine before the speech provider.
        $pos = static function ( $needle ) use ( $briefing ) {
            $p = strpos( $briefing, $needle );
            return false === $p ? -1 : $p;
        };
        if ( ! ( $pos( 'name="presshub_ai_briefing_text_max_tokens"' ) < $pos( 'name="presshub_ai_briefing_text_temperature"' )
            && $pos( 'name="presshub_ai_briefing_text_temperature"' ) < $pos( 'name="presshub_ai_briefing_podcast_provider"' ) ) ) {
            $failures[] = 'Curator temperature/timeout should sit in the curator model block.';
        }
        if ( ! ( $pos( 'name="presshub_ai_briefing_podcast_max_tokens"' ) < $pos( 'name="presshub_ai_briefing_podcast_temperature"' )
            && $pos( 'name="presshub_ai_briefing_tts_engine"' ) < $pos( 'name="presshub_ai_briefing_podcast_tts_provider"' ) ) ) {
            $failures[] = 'Scriptwriter tuning and the speech engine should sit in the podcast block, the engine before the speech provider.';
        }

        // 2. Empty never-saved fields render empty (provider value applies), not a made-up default.
        $GLOBALS['OPTIONS_STORE'] = [];
        $briefing = self::pane( self::render_page(), 'briefing' );
        if ( ! preg_match( '/name="presshub_ai_briefing_text_temperature"[^>]*value=""/', $briefing ) ) {
            $failures[] = 'An unset curator temperature should render empty.';
        }

        // 3. Save round trip through the per-tab save.
        $GLOBALS['OPTIONS_STORE'] = [];
        PressHub_Settings_Registry::save_posted( 'ai', 'briefing', [
            'presshub_ai_briefing_tts_engine'          => 'google_cloud',
            'presshub_ai_briefing_text_temperature'    => '0.4',
            'presshub_ai_briefing_text_timeout'        => '180',
            'presshub_ai_briefing_podcast_temperature' => '',
            'presshub_ai_briefing_podcast_timeout'     => '',
            'presshub_ai_briefing_podcast_preset'      => '',
        ] );
        $s = $GLOBALS['OPTIONS_STORE'];
        if ( 'google_cloud' !== ( $s['presshub_ai_briefing_tts_engine'] ?? null ) ) {
            $failures[] = 'Speech engine did not save.';
        }
        if ( 0.4 !== ( $s['presshub_ai_briefing_text_temperature'] ?? null ) || 180 !== ( $s['presshub_ai_briefing_text_timeout'] ?? null ) ) {
            $failures[] = 'Curator temperature/timeout did not save as numbers: ' . var_export( [ $s['presshub_ai_briefing_text_temperature'] ?? null, $s['presshub_ai_briefing_text_timeout'] ?? null ], true );
        }
        if ( '' !== ( $s['presshub_ai_briefing_podcast_temperature'] ?? null ) || '' !== ( $s['presshub_ai_briefing_podcast_timeout'] ?? null ) ) {
            $failures[] = 'Empty scriptwriter temperature/timeout must store "" so the provider value keeps applying.';
        }
        // Out-of-range values clamp to what the API client accepts.
        if ( 2.0 !== PressHub_AI_Settings_Storage::sanitize_optional_temperature( '5' ) || 10 !== PressHub_AI_Settings_Storage::sanitize_optional_timeout( '3' ) || 600 !== PressHub_AI_Settings_Storage::sanitize_optional_timeout( '9000' ) ) {
            $failures[] = 'Optional temperature/timeout should clamp to 0–2 and 10–600 s.';
        }

        // 4. The registry's "rendered" flag matches the page for every AI option.
        $GLOBALS['OPTIONS_STORE'] = [
            // Make conditionally shown fields appear (remove-key boxes need a stored key).
            'presshub_ai_api_key' => 'AIzaSyEXAMPLEKEY000000000000000003kQ',
        ];
        $html = self::render_page();
        foreach ( PressHub_Settings_Registry::for_page( 'ai' ) as $key => $e ) {
            if ( empty( $e['rendered'] ) || ! empty( $e['removes'] ) ) {
                continue;
            }
            if ( false === strpos( $html, 'name="' . $key . '"' ) && false === strpos( $html, 'name="' . $key . '[' ) ) {
                $failures[] = "{$key} is declared rendered but has no field on the AI settings page.";
            }
        }

        // 5. The registry's "checkbox" flag matches the page, so unticking a box
        //    and saving its tab stores 0 (found two that did not, #233).
        preg_match_all( '/<input\b[^>]*>/', $html, $inputs );
        $boxes = [];
        foreach ( $inputs[0] as $tag ) {
            if ( false !== strpos( $tag, 'type="checkbox"' ) && preg_match( '/name="([^"\[]+)"/', $tag, $n ) ) {
                $boxes[ $n[1] ] = true;
            }
        }
        foreach ( PressHub_Settings_Registry::for_page( 'ai' ) as $key => $e ) {
            if ( ! empty( $e['removes'] ) ) {
                continue;
            }
            if ( isset( $boxes[ $key ] ) !== ! empty( $e['checkbox'] ) ) {
                $failures[] = "{$key}: the page renders " . ( isset( $boxes[ $key ] ) ? 'a checkbox' : 'no checkbox' ) . ' but the registry says checkbox=' . ( empty( $e['checkbox'] ) ? 'false' : 'true' ) . '.';
            }
        }
        $GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_image_feature_enabled' => 1, 'presshub_ai_briefing_podcast_include_text' => 1 ];
        PressHub_Settings_Registry::save_posted( 'ai', 'images', [] );
        PressHub_Settings_Registry::save_posted( 'ai', 'briefing', [] );
        if ( 0 !== $GLOBALS['OPTIONS_STORE']['presshub_ai_image_feature_enabled'] || 0 !== $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_include_text'] ) {
            $failures[] = 'Unticking "Generate featured images" or "Include transcript" and saving the tab should store 0.';
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo 'SettingsHiddenSurfacedTest: ' . count( self::SURFACED ) . " settings surfaced, rendered flags match the page.\nOK\n";
    }
}

SettingsHiddenSurfacedTest::run();
