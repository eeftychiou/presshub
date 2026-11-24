<?php
/**
 * #233 upgrade test: installing this release over a 2.36.x database keeps
 * every option registered and every stored value unchanged.
 *
 * The 2.36.x option list is a fixture (tests/fixtures/settings-options-2.36.txt).
 * A snapshot of stored values is built from each option's default as 2.36.x
 * stores it, plus hand-picked custom values for the options editors change most.
 * The code that runs when the new plugin loads (option registration on both
 * pages, the settings migrations and the #237 clean-up) must not alter any of
 * them, except the options the clean-up removes. Shorts, Reels and the podcast
 * must resolve the same voices, counts, spacing and prompts as before.
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
require_once __DIR__ . '/../includes/class-settings-transfer.php';
require_once __DIR__ . '/../includes/class-settings-cleanup.php';
foreach ( [ 'class-social-settings-storage.php', 'class-social-tiktok-narrator.php', 'class-social-tiktok-music.php', 'class-social-tiktok-shorts.php', 'class-social-post-images.php', 'class-social-image-library.php', 'class-social-ai-copywriter.php' ] as $presshub_f ) {
    require_once __DIR__ . '/../includes/' . $presshub_f;
}

class SettingsUpgradeTest {

    /** Values the Shorts, Reels and podcast code read, keyed by getter. */
    private static function resolved(): array {
        $S   = 'PressHub_Social_Settings_Storage';
        $out = [];
        foreach ( [ 'get_tiktok_shorts_enabled', 'get_instagram_shorts_enabled', 'get_tiktok_shorts_count', 'get_tiktok_shorts_spacing_minutes', 'get_tiktok_shorts_max_words', 'get_tiktok_shorts_images', 'get_tiktok_shorts_narrators', 'get_tiktok_shorts_voice_speed', 'get_tiktok_shorts_music_enabled', 'get_tiktok_shorts_music_volume' ] as $m ) {
            $out[ $m ] = $S::$m();
        }
        foreach ( [ 1, 2, 3 ] as $n ) {
            $out[ 'get_tiktok_shorts_voice_' . $n ] = $S::get_tiktok_shorts_voice( $n );
        }
        $A = 'PressHub_AI_Settings_Storage';
        foreach ( [ 'get_briefing_host_count', 'get_briefing_host_female', 'get_briefing_host_male', 'get_briefing_host_tertiary', 'get_podcast_style' ] as $m ) {
            $out[ $m ] = $A::$m();
        }
        foreach ( [ 'default_greek_chat', 'bbc_broadcasting_standards', 'conversational_news_reporting' ] as $style ) {
            foreach ( [ 1, 2, 3 ] as $n ) {
                $m = 'get_podcast_prompt_' . $n;
                $out[ $m . '_' . $style ] = $A::$m( $style );
            }
        }
        return $out;
    }

    public static function run(): void {
        $failures = [];

        $old = array_values( array_filter( array_map( 'trim', file( __DIR__ . '/fixtures/settings-options-2.36.txt' ) ), static function ( $l ) {
            return '' !== $l && '#' !== $l[0];
        } ) );

        // 1. Nothing registered in 2.36.x disappears, except what #237 removed.
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        $registered = PressHub_Settings_Registry::registered();
        foreach ( $old as $key ) {
            if ( PressHub_Settings_Cleanup::is_removed( $key ) ) {
                if ( isset( $registered[ $key ] ) || PressHub_Settings_Registry::has( $key ) ) {
                    $failures[] = "{$key} was removed in #237 and must no longer be registered.";
                }
                continue;
            }
            if ( ! isset( $registered[ $key ] ) ) {
                $failures[] = "{$key} was registered in 2.36.x and is no longer registered.";
            }
        }
        foreach ( PressHub_Settings_Cleanup::removed_keys() as $key ) {
            // presshub_ai_gemini_api_key was read but never registered; the
            // YouTube switch came after 2.36.x (#309 E1 removes it too).
            if ( ! in_array( $key, $old, true ) && ! in_array( $key, [ 'presshub_ai_gemini_api_key', 'presshub_social_enabled_youtube' ], true ) ) {
                $failures[] = "{$key} is in the clean-up list but was not a 2.36.x option.";
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
            'presshub_ai_briefing_tts_api_key'           => 'AIzaSyEXAMPLESPEECH0000000000000007zQ',
            'presshub_social_copy_mode'                  => 'ai',
            'presshub_social_worker_mode'                => 'pull',
            'presshub_social_tiktok_shorts_count'        => 3,
            'presshub_social_tiktok_shorts_voice_1'      => 'Puck',
            'presshub_social_tiktok_shorts_spacing_minutes' => 90,
            // A 2.36.x site has a worker secret (generated on first load).
            'presshub_social_worker_secret'              => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
            // The per-platform switches were kept in step with the list (#309 E1).
            'presshub_social_enabled_twitter'            => 1,
            'presshub_social_enabled_facebook'           => 1,
            'presshub_social_enabled_instagram'          => 1,
            'presshub_social_enabled_tiktok'             => 0,
            'presshub_social_enabled_platforms'          => [ 'twitter', 'facebook', 'instagram' ],
            // The old single pool, used while the Morning pool was empty.
            'presshub_ai_briefing_featured_image_ids'    => '11,12',
            'presshub_ai_briefing_featured_image_ids_morning' => '',
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
            // No declared default (or no longer registered): store a value of the option's type.
            $type = PressHub_Settings_Registry::get( $key )['type'] ?? 'string';
            $snapshot[ $key ] = 'boolean' === $type ? 1 : ( in_array( $type, [ 'integer', 'number' ], true ) ? 5 : 'kept value for ' . $key );
        }

        // 3. Load the new release over it.
        $GLOBALS['OPTIONS_STORE'] = $snapshot;
        // Its providers, as on presshub.cy: the old keys are copies of these (#237 part B).
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [
            [ 'id' => 'gemini-main', 'type' => 'gemini', 'name' => 'Google Gemini', 'enabled' => true, 'api_key' => 'AIzaSyEXAMPLEKEY000000000000000003kQ', 'default_model' => 'gemini-3.1-flash' ],
            [ 'id' => 'gemini-speech', 'type' => 'gemini', 'name' => 'Google Gemini (3.8 Speech)', 'enabled' => true, 'api_key' => 'AIzaSyEXAMPLESPEECH0000000000000007zQ', 'default_model' => 'gemini-3.1-flash-tts-preview' ],
        ];
        // One-time repair flags a 2.36.x site has already set.
        foreach ( [ 'presshub_social_repaired_v263', 'presshub_social_upgraded_prompts_v269', 'presshub_social_upgraded_prompts_v284' ] as $flag ) {
            $GLOBALS['OPTIONS_STORE'][ $flag ] = '1';
        }
        PressHub_Settings_Registry::reset();
        PressHub_AI_Settings_Storage::register_options();
        PressHub_Social_Settings_Storage::register_options();
        PressHub_AI_Settings_Migration::migrate_legacy_model();
        PressHub_AI_Settings_Migration::migrate_deprecated_gemini_models();

        // What Shorts, Reels and the podcast resolve before the clean-up.
        $before = self::resolved();
        $store_before             = $GLOBALS['OPTIONS_STORE'];

        // 4. The #237 clean-up (first admin request after the upgrade).
        PressHub_Settings_Cleanup::maybe_run();
        $after = self::resolved();

        $github_copied = '' === (string) ( $snapshot['presshub_ai_github_token'] ?? '' ) && '' !== (string) ( $snapshot['presshub_social_github_token'] ?? '' );
        // #309 (E1): the clean-up copies the old single pool into the empty Morning pool.
        $copied_to = [ 'presshub_ai_briefing_featured_image_ids_morning' => '11,12' ];
        foreach ( $snapshot as $key => $value ) {
            $now = array_key_exists( $key, $GLOBALS['OPTIONS_STORE'] ) ? $GLOBALS['OPTIONS_STORE'][ $key ] : '(deleted)';
            if ( PressHub_Settings_Cleanup::is_removed( $key ) ) {
                if ( '(deleted)' !== $now ) {
                    $failures[] = "{$key} should be deleted by the clean-up.";
                }
                continue;
            }
            if ( 'presshub_ai_github_token' === $key && $github_copied ) {
                $value = $snapshot['presshub_social_github_token'];
            }
            if ( array_key_exists( $key, $copied_to ) ) {
                $value = $copied_to[ $key ];
            }
            if ( serialize( $now ) !== serialize( $value ) ) {
                $failures[] = "{$key} changed on upgrade: " . var_export( $value, true ) . ' → ' . var_export( $now, true );
            }
        }
        // Nothing else the clean-up did touched a stored setting (its own bookkeeping aside).
        foreach ( $store_before as $key => $value ) {
            if ( PressHub_Settings_Cleanup::is_removed( $key ) || 'presshub_ai_github_token' === $key || array_key_exists( $key, $copied_to ) ) {
                continue;
            }
            if ( ! array_key_exists( $key, $GLOBALS['OPTIONS_STORE'] ) || serialize( $GLOBALS['OPTIONS_STORE'][ $key ] ) !== serialize( $value ) ) {
                $failures[] = "{$key} was changed by the clean-up.";
            }
        }
        if ( serialize( $before ) !== serialize( $after ) ) {
            foreach ( $before as $k => $v ) {
                if ( serialize( $v ) !== serialize( $after[ $k ] ?? null ) ) {
                    $failures[] = "{$k} resolves differently after the clean-up: " . var_export( $v, true ) . ' → ' . var_export( $after[ $k ] ?? null, true );
                }
            }
        }
        $expected_removed = count( array_intersect( PressHub_Settings_Cleanup::removed_keys(), array_keys( $snapshot ) ) );
        if ( $expected_removed !== PressHub_Settings_Cleanup::removed_count() ) {
            $failures[] = "The report should count {$expected_removed} removed settings; got " . PressHub_Settings_Cleanup::removed_count();
        }
        if ( PressHub_Settings_Cleanup::report()['kept'] ?? [ 'x' ] ) {
            $failures[] = 'Old keys that match a Connections provider are not kept.';
        }
        $export = json_decode( (string) get_option( PressHub_Settings_Cleanup::export_option( PressHub_Settings_Cleanup::VERSION ), '' ), true );
        if ( ! is_array( $export ) || count( $export['options'] ?? [] ) < count( $snapshot ) - 60 ) {
            $failures[] = 'The pre-clean-up copy should hold the stored settings.';
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo 'SettingsUpgradeTest: ' . ( count( $old ) - count( PressHub_Settings_Cleanup::REMOVED ) ) . ' options still registered, ' . count( PressHub_Settings_Cleanup::REMOVED ) . ' removed by the clean-up, the other ' . ( count( $snapshot ) - count( PressHub_Settings_Cleanup::REMOVED ) ) . " stored values unchanged.\nOK\n";
    }
}

SettingsUpgradeTest::run();
