<?php
/**
 * #233: the settings registry is the single source of truth for every option.
 *
 * Checks, in both directions, that every option registered by the plugin is
 * declared in the registry and every declared option is registered, that
 * nothing calls register_setting() directly, that each entry carries the
 * metadata later phases rely on, and that the save paths read the registry.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-audit-logger.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';
foreach ( [ 'class-social-settings-storage.php', 'class-social-tiktok-narrator.php', 'class-social-tiktok-music.php', 'class-social-tiktok-shorts.php', 'class-social-post-images.php', 'class-social-image-library.php', 'class-social-ai-copywriter.php' ] as $presshub_f ) {
    require_once __DIR__ . '/../includes/' . $presshub_f;
}

class SettingsRegistryTest {

    public static function run(): void {
        $failures = [];

        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        $registered = PressHub_Settings_Registry::registered();
        $declared   = PressHub_Settings_Registry::all();

        // 1. Both directions.
        foreach ( $registered as $key => $reg ) {
            if ( ! isset( $declared[ $key ] ) ) {
                $failures[] = "Registered option {$key} has no registry entry.";
            }
        }
        foreach ( $declared as $key => $e ) {
            if ( ! isset( $registered[ $key ] ) ) {
                $failures[] = "Registry entry {$key} is never registered.";
            }
        }
        if ( count( $declared ) < 285 ) {
            $failures[] = 'Expected at least 285 options in the registry, got ' . count( $declared ) . '.';
        }

        // 2. Group matches the page, required keys present, values valid.
        $allowed_status = [ PressHub_Settings_Registry::STATUS_ACTIVE, PressHub_Settings_Registry::STATUS_UNUSED ];
        foreach ( $declared as $key => $e ) {
            foreach ( [ 'page', 'tab', 'label', 'type', 'status' ] as $field ) {
                if ( ! isset( $e[ $field ] ) || '' === $e[ $field ] ) {
                    $failures[] = "Registry entry {$key} has no '{$field}'.";
                }
            }
            $page = $e['page'] ?? '';
            if ( ! isset( PressHub_Settings_Registry::GROUPS[ $page ] ) ) {
                $failures[] = "Registry entry {$key} has unknown page '{$page}'.";
                continue;
            }
            if ( isset( $registered[ $key ] ) && $registered[ $key ]['group'] !== PressHub_Settings_Registry::GROUPS[ $page ] ) {
                $failures[] = "Option {$key} is registered in group {$registered[ $key ]['group']} but its page is {$page}.";
            }
            if ( ! in_array( $e['status'] ?? '', $allowed_status, true ) ) {
                $failures[] = "Registry entry {$key} has unknown status.";
            }
            if ( PressHub_Settings_Registry::STATUS_UNUSED === ( $e['status'] ?? '' ) && empty( $e['unused_reason'] ) ) {
                $failures[] = "Unused option {$key} must say why it is unused.";
            }
            if ( ! empty( $e['removes'] ) && ! isset( $declared[ $e['removes'] ] ) ) {
                $failures[] = "{$key} removes {$e['removes']}, which is not in the registry.";
            }
            if ( 'ai' === $page && isset( $registered[ $key ] ) && ! PressHub_Settings_Registry::sanitizer( $key ) ) {
                $failures[] = "AI option {$key} has no sanitize callback.";
            }
        }

        // 3. Nothing registers options behind the registry's back.
        $includes = glob( __DIR__ . '/../includes/*.php' );
        foreach ( $includes as $file ) {
            if ( basename( $file ) === 'class-settings-registry.php' ) {
                continue;
            }
            $src = file_get_contents( $file );
            // Strip comments before looking for calls.
            $code = '';
            foreach ( token_get_all( $src ) as $tok ) {
                if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    continue;
                }
                $code .= is_array( $tok ) ? $tok[1] : $tok;
            }
            if ( preg_match( '/(?<![:\w])register_setting\s*\(/', $code ) ) {
                $failures[] = basename( $file ) . ' calls register_setting() directly; use PressHub_Settings_Registry::register().';
            }
        }

        // 4. Every AI tab map comes from the registry, and each option is on exactly one tab.
        $seen = [];
        foreach ( [ 'providers', 'coauthor', 'briefing', 'images', 'copilot', 'knowledge', 'advanced' ] as $tab ) {
            foreach ( array_keys( PressHub_AI_Settings_Storage::get_section_options_map( $tab ) ) as $key ) {
                if ( isset( $seen[ $key ] ) ) {
                    $failures[] = "Option {$key} is in both the {$seen[ $key ]} and {$tab} tab maps.";
                }
                $seen[ $key ] = $tab;
            }
        }
        $ai_all = PressHub_Settings_Registry::for_page( 'ai' );
        foreach ( $ai_all as $key => $e ) {
            if ( ! isset( $seen[ $key ] ) ) {
                $failures[] = "AI option {$key} is on no tab's save map (tab '{$e['tab']}').";
            }
        }
        if ( count( PressHub_AI_Settings_Storage::get_section_options_map( '' ) ) !== count( $ai_all ) ) {
            $failures[] = 'The "save all" map must cover every AI option.';
        }

        // 5. QA alias still returns the QA options on the Co-Author tab.
        $qa = PressHub_AI_Settings_Storage::get_section_options_map( 'qa' );
        if ( ! isset( $qa['presshub_ai_qa_article_prompt'] ) || isset( $qa['presshub_ai_coauthor_model'] ) ) {
            $failures[] = 'The qa tab alias should return only the presshub_ai_qa_* options.';
        }

        // 6. Unused list matches the audit's confirmed items (podcast prompts 1–3 are still
        //    read as a legacy fallback by resolve_podcast_prompt(), so they are not on it).
        $unused = array_keys( PressHub_Settings_Registry::unused() );
        sort( $unused );
        $expected = [ 'presshub_social_github_token', 'presshub_social_twitter_access_secret', 'presshub_social_twitter_api_key', 'presshub_social_twitter_api_secret' ];
        if ( $unused !== $expected ) {
            $failures[] = 'Unused options: expected ' . implode( ', ', $expected ) . '; got ' . implode( ', ', $unused );
        }

        // 7. Defaults resolve.
        if ( 'ai' !== PressHub_Settings_Registry::default_value( 'presshub_social_copy_mode' ) ) {
            $failures[] = 'Literal default for presshub_social_copy_mode should be "ai".';
        }
        if ( PressHub_Settings_Registry::default_value( 'presshub_ai_briefing_harvest_time' ) !== PressHub_AI_Settings_Storage::default_briefing_harvest_time() ) {
            $failures[] = 'Callback default for presshub_ai_briefing_harvest_time should come from default_briefing_harvest_time().';
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo 'SettingsRegistryTest: ' . count( $declared ) . " options checked.\nOK\n";
    }
}

SettingsRegistryTest::run();
