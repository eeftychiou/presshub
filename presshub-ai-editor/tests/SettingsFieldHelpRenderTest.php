<?php
/**
 * #260: fields that rendered without a description line now print the
 * registry's help text; #257: the tab save checks browser validity first.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-settings-registry.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-render.php';

class SettingsFieldHelpRenderTest {

    public static function run(): void {
        $failures = [];
        $keys     = [
            'presshub_ai_briefing_text_model',
            'presshub_ai_briefing_podcast_model',
            'presshub_ai_press_inbox_username',
        ];

        $src = file_get_contents( __DIR__ . '/../includes/class-settings-render.php' );
        foreach ( $keys as $key ) {
            $entry = PressHub_Settings_Registry::get( $key );
            $help  = is_array( $entry ) ? (string) ( $entry['help'] ?? '' ) : '';
            if ( '' === $help ) {
                $failures[] = "{$key} has no registry help text.";
                continue;
            }
            ob_start();
            PressHub_AI_Settings_Render::registry_help( $key );
            $html = ob_get_clean();
            if ( false === strpos( $html, 'class="description"' ) || false === strpos( $html, esc_html( $help ) ) ) {
                $failures[] = "registry_help( {$key} ) should print the help as a description.";
            }
            if ( false === strpos( $src, "self::registry_help( '{$key}' )" ) ) {
                $failures[] = "{$key} field should print its registry help.";
            }
        }

        ob_start();
        PressHub_AI_Settings_Render::registry_help( 'presshub_ai_no_such_option' );
        if ( '' !== ob_get_clean() ) {
            $failures[] = 'An unknown key must print nothing.';
        }

        $js = file_get_contents( __DIR__ . '/../assets/admin.js' );
        if ( ! preg_match( '/function saveSettingsTab\(tabKey\)[\s\S]*?presshubCheckFieldsValid\(\$tabPane/', $js ) ) {
            $failures[] = 'saveSettingsTab must check field validity before sending (#257).';
        }
        if ( false === strpos( $js, 'checkValidity()' ) ) {
            $failures[] = 'The validity check must use checkValidity() (#257).';
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

SettingsFieldHelpRenderTest::run();
