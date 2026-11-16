<?php
/**
 * #256 and #262: selects must show what is stored, so an untouched save
 * never stores a value nobody picked.
 *
 *  - #256: a stored voice the current speech engine does not list stays
 *    selected, marked "(not available for this engine)".
 *  - #262: Active Co-Author Provider offers the "use the default provider"
 *    entry with value '' and does not fall back to the legacy option.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-render.php';

class SettingsSelectDefaultsTest {

    public static function run(): void {
        $failures = [];

        $female = [ 'el-GR-Wavenet-A' => [ 'label' => 'Wavenet A' ] ];
        $male   = [ 'el-GR-Wavenet-B' => [ 'label' => 'Wavenet B' ] ];

        // #256: stored Gemini voice under Google Cloud stays selected.
        $html = PressHub_AI_Settings_Render::unavailable_voice_option( 'Kore', $female, $male );
        if ( false === strpos( $html, 'value="Kore"' ) || false === strpos( $html, 'selected' ) ) {
            $failures[] = 'An unlisted stored voice must render as a selected option.';
        }
        if ( false === strpos( $html, 'not available for this engine' ) ) {
            $failures[] = 'An unlisted stored voice must be marked as not available.';
        }
        if ( '' !== PressHub_AI_Settings_Render::unavailable_voice_option( 'el-GR-Wavenet-B', $female, $male ) ) {
            $failures[] = 'A listed voice must not get an extra option.';
        }
        if ( '' !== PressHub_AI_Settings_Render::unavailable_voice_option( '', $female, $male ) ) {
            $failures[] = 'An empty stored voice must not get an extra option.';
        }
        $xss = PressHub_AI_Settings_Render::unavailable_voice_option( '"><script>', $female, $male );
        if ( false !== strpos( $xss, '<script>' ) ) {
            $failures[] = 'The stored voice name must be escaped.';
        }

        // #262: co-author provider select source.
        $src = file_get_contents( __DIR__ . '/../includes/class-settings-render.php' );
        if ( ! preg_match( "/get_option\\( 'presshub_ai_coauthor_provider', '' \\)/", $src ) ) {
            $failures[] = 'Co-Author provider must read the stored value with an empty default.';
        }
        if ( ! preg_match( '/get_active_providers_options\( \$cur_coauthor_prov, __\( \'-- Use Active Provider Default --\'/', $src ) ) {
            $failures[] = 'Co-Author provider select must offer the default-provider entry.';
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo "OK\n";
    }
}

SettingsSelectDefaultsTest::run();
