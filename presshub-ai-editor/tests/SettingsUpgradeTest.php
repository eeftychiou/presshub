<?php
/**
 * #233 upgrade test: installing this release over a 2.36.x database keeps
 * every option registered and every stored value unchanged.
 *
 * The 2.36.x option list is a fixture (tests/fixtures/settings-options-2.36.txt).
 * A snapshot of stored values is built from each option's default as 2.36.x
 * stores it, plus hand-picked custom values for the options editors change most.
 * The code that runs when the new plugin loads (option registration on both
 * pages and the settings migrations) must not alter any of them.
 */

require_once __DIR__ . '/wordpress-stubs.php';
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) {
        return rtrim( (string) $value, '/\\' );
    }
}
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-audit-logger.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings-migration.php';
require_once __DIR__ . '/../includes/class-settings.php';
foreach ( [ 'class-social-settings-storage.php', 'class-social-tiktok-narrator.php', 'class-social-tiktok-music.php', 'class-social-tiktok-shorts.php', 'class-social-post-images.php', 'class-social-image-library.php', 'class-social-ai-copywriter.php' ] as $presshub_f ) {
    require_once __DIR__ . '/../includes/' . $presshub_f;
}

class SettingsUpgradeTest {

    public static function run(): void {
        $failures = [];

        $old = array_values( array_filter( array_map( 'trim', file( __DIR__ . '/fixtures/settings-options-2.36.txt' ) ), static function ( $l ) {
            return '' !== $l && '#' !== $l[0];
        } ) );

        // 1. Nothing registered in 2.36.x disappears.
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        $registered = PressHub_Settings_Registry::registered();
        foreach ( $old as $key ) {
            if ( ! isset( $registered[ $key ] ) ) {
                $failures[] = "{$key} was registered in 2.36.x and is no longer registered.";
            }
        }

        // 2. Build a 2.36.x snapshot of stored values.
        $custom = [
            'presshub_ai_provider'                       => 'gemini',
            'presshub_ai_briefing_harvest_time'          => '04:15',
            'presshub_ai_briefing_generation_time'       => '04:30',
            'presshub_ai_briefing_host_female'           => 'Ελένη',
            'presshub_ai_briefing_voice_female'          => 'Kore',
            'presshub_ai_briefing_tts_engine'            => 'gemini',
            'presshub_ai_briefing_text_temperature'      => 0.4,
            'presshub_ai_briefing_text_timeout'          => 180,
            'presshub_ai_briefing_text_model'            => 'gemini-3.1-pro',
            'presshub_ai_coauthor_model'                 => 'gemini-3.1-flash',
            'presshub_ai_api_key'                        => 'AIzaSyEXAMPLEKEY000000000000000003kQ',
            'presshub_social_copy_mode'                  => 'ai',
            'presshub_social_worker_mode'                => 'pull',
            'presshub_social_tiktok_shorts_count'        => 3,
            'presshub_social_tiktok_shorts_voice_1'      => 'Puck',
            'presshub_social_tiktok_shorts_spacing_minutes' => 90,
            // A 2.36.x site has a worker secret (generated on first load).
            'presshub_social_worker_secret'              => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
        ];
        $snapshot = [];
        foreach ( $old as $key ) {
            if ( array_key_exists( $key, $custom ) ) {
                $snapshot[ $key ] = $custom[ $key ];
                continue;
            }
            $default = PressHub_Settings_Registry::default_value( $key );
            if ( null !== $default ) {
                $snapshot[ $key ] = $default;
                continue;
            }
            // No declared default: store a value of the option's type.
            $type = PressHub_Settings_Registry::get( $key )['type'] ?? 'string';
            $snapshot[ $key ] = 'boolean' === $type ? 1 : ( in_array( $type, [ 'integer', 'number' ], true ) ? 5 : 'kept value for ' . $key );
        }

        // 3. Load the new release over it.
        $GLOBALS['OPTIONS_STORE'] = $snapshot;
        // One-time repair flags a 2.36.x site has already set.
        foreach ( [ 'presshub_social_repaired_v263', 'presshub_social_upgraded_prompts_v269', 'presshub_social_upgraded_prompts_v284' ] as $flag ) {
            $GLOBALS['OPTIONS_STORE'][ $flag ] = '1';
        }
        PressHub_Settings_Registry::reset();
        PressHub_AI_Settings_Storage::register_options();
        PressHub_Social_Settings_Storage::register_options();
        PressHub_AI_Settings_Migration::migrate_legacy_model();
        PressHub_AI_Settings_Migration::migrate_deprecated_gemini_models();

        foreach ( $snapshot as $key => $value ) {
            $now = array_key_exists( $key, $GLOBALS['OPTIONS_STORE'] ) ? $GLOBALS['OPTIONS_STORE'][ $key ] : '(deleted)';
            if ( serialize( $now ) !== serialize( $value ) ) {
                $failures[] = "{$key} changed on upgrade: " . var_export( $value, true ) . ' → ' . var_export( $now, true );
            }
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo 'SettingsUpgradeTest: ' . count( $old ) . ' options still registered, ' . count( $snapshot ) . " stored values unchanged.\nOK\n";
    }
}

SettingsUpgradeTest::run();
