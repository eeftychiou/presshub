<?php
/**
 * #237 settings clean-up: the removed options are gone from the registry and
 * every page, a copy without secrets is saved before anything is deleted, the
 * report and the one-time notice say what happened, the second GitHub token is
 * kept for plugin updates, and reading a removed option is flagged once.
 */

$GLOBALS['DOING_IT_WRONG'] = [];
if ( ! function_exists( '_doing_it_wrong' ) ) {
    function _doing_it_wrong( $function_name, $message, $version ) {
        $GLOBALS['DOING_IT_WRONG'][] = [ $function_name, $message, $version ];
    }
}

$dir = __DIR__;
require_once $dir . '/wordpress-stubs.php';
require_once $dir . '/wp-action-wrapper.php';
if ( ! function_exists( 'plugin_dir_path' ) ) { function plugin_dir_path( $file ) { return dirname( $file ) . '/'; } }
if ( ! function_exists( 'plugin_dir_url' ) ) { function plugin_dir_url( $file ) { return 'http://example.test/'; } }
if ( ! function_exists( 'register_deactivation_hook' ) ) { function register_deactivation_hook( $f, $c ) {} }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); } }
if ( ! function_exists( '_n' ) ) { function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; } }
define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( $dir ) . '/presshub-ai-editor.php';
require_once $dir . '/settings-sections-helper.php';
PressHub_Settings_Cleanup::register_hooks();

class SettingsCleanupTest {

    const SECRET_VALUES = [
        'presshub_social_github_token'          => 'ghp_SECONDTOKEN000000000000000000000001',
        'presshub_social_twitter_api_key'       => 'XCONSUMERKEY0000000000001',
        'presshub_social_twitter_api_secret'    => 'XCONSUMERSECRET00000000000000000000001',
        'presshub_social_twitter_access_secret' => 'XACCESSSECRET000000000000000000000001',
    ];

    private static function seed(): void {
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_provider'                  => 'gemini',
            'presshub_ai_briefing_host_female'      => 'Ελένη',
            'presshub_social_worker_secret'         => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
            'presshub_social_meta_access_token'     => 'EAAMETATOKEN0000000000000000001',
            'presshub_ai_briefing_podcast_prompt_1' => 'Old prompt for one presenter',
            'presshub_ai_briefing_podcast_prompt_2' => 'Old prompt for two presenters',
            'presshub_ai_briefing_podcast_prompt_3' => 'Old prompt for three presenters',
        ] + self::SECRET_VALUES;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files', 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID']   = 1;
        $GLOBALS['DOING_IT_WRONG']    = [];
        PressHub_Settings_Cleanup::reset_warnings();
        PressHub_Settings_Registry::reset();
    }

    public static function run(): void {
        $failures = [];
        self::seed();

        // 1. No longer registered, rendered or read.
        PressHub_Settings_Registry::ensure_registered();
        $html = presshub_test_render_all_sections();
        foreach ( PressHub_Settings_Cleanup::removed_keys() as $key ) {
            if ( PressHub_Settings_Registry::has( $key ) || isset( PressHub_Settings_Registry::registered()[ $key ] ) ) {
                $failures[] = "{$key} is still registered.";
            }
            if ( false !== strpos( $html, 'name="' . $key . '"' ) || false !== strpos( $html, 'id="' . $key . '"' ) ) {
                $failures[] = "{$key} is still rendered on a Settings section.";
            }
        }
        if ( PressHub_Settings_Registry::unused() ) {
            $failures[] = 'Nothing should be left in Scheduled for removal; got ' . implode( ', ', array_keys( PressHub_Settings_Registry::unused() ) );
        }
        $src = '';
        foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $f ) {
            if ( false === strpos( $f, 'class-settings-cleanup.php' ) ) {
                $src .= file_get_contents( $f );
            }
        }
        foreach ( PressHub_Settings_Cleanup::removed_keys() as $key ) {
            if ( preg_match( "/get_option\\(\\s*'" . preg_quote( $key, '/' ) . "'/", $src ) ) {
                $failures[] = "{$key} is still read with get_option().";
            }
        }
        // No settings field is registered on a section that is never printed field by field.
        foreach ( (array) ( $GLOBALS['FIELDS']['presshub-ai'] ?? [] ) as $section => $fields ) {
            if ( ! in_array( $section, [ 'presshub_ai_media', 'presshub_ai_github', 'presshub_ai_social' ], true ) ) {
                $failures[] = "{$section} registers " . count( $fields ) . ' settings fields that are never rendered.';
            }
        }

        // 2. Rendering the page read nothing removed; the clean-up itself is quiet.
        if ( $GLOBALS['DOING_IT_WRONG'] ) {
            $failures[] = 'Rendering Settings read a removed option: ' . $GLOBALS['DOING_IT_WRONG'][0][1];
        }
        PressHub_Settings_Cleanup::maybe_run();
        if ( $GLOBALS['DOING_IT_WRONG'] ) {
            $failures[] = 'The clean-up should not flag its own reads.';
        }

        // 3. The copy was saved first, without secrets.
        $json   = (string) get_option( PressHub_Settings_Cleanup::EXPORT_OPTION, '' );
        $export = json_decode( $json, true );
        if ( ! is_array( $export ) || PressHub_Settings_Transfer::FORMAT !== ( $export['format'] ?? '' ) ) {
            $failures[] = 'The pre-clean-up copy should be a settings export file.';
        } else {
            foreach ( [ 1, 2, 3 ] as $n ) {
                $k = 'presshub_ai_briefing_podcast_prompt_' . $n;
                if ( ( $export['options'][ $k ] ?? null ) !== self::seed_value( $k ) ) {
                    $failures[] = "The copy should keep {$k}.";
                }
            }
            if ( 'Ελένη' !== ( $export['options']['presshub_ai_briefing_host_female'] ?? null ) ) {
                $failures[] = 'The copy should keep the remaining settings.';
            }
            foreach ( array_merge( self::SECRET_VALUES, [ 'presshub_social_worker_secret' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 'presshub_social_meta_access_token' => 'EAAMETATOKEN0000000000000000001' ] ) as $k => $v ) {
                if ( false !== strpos( $json, $v ) ) {
                    $failures[] = "The copy must not contain the value of {$k}.";
                }
                if ( ! in_array( $k, (array) ( $export['secrets_left_out'] ?? [] ), true ) ) {
                    $failures[] = "The copy should list {$k} by name only.";
                }
            }
        }

        // 4. Rows deleted, the second GitHub token kept for plugin updates.
        foreach ( PressHub_Settings_Cleanup::removed_keys() as $key ) {
            if ( array_key_exists( $key, $GLOBALS['OPTIONS_STORE'] ) ) {
                $failures[] = "{$key} should be deleted.";
            }
        }
        if ( self::SECRET_VALUES['presshub_social_github_token'] !== get_option( 'presshub_ai_github_token' ) ) {
            $failures[] = 'An empty AI GitHub token should receive the second token.';
        }
        if ( 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6' !== get_option( 'presshub_social_worker_secret' ) || 'gemini' !== get_option( 'presshub_ai_provider' ) ) {
            $failures[] = 'Settings that stay must keep their values.';
        }

        // 5. Report: labels first, option names under Details, download link.
        ob_start();
        PressHub_Admin_Menu::render_usage_page();
        $usage = (string) ob_get_clean();
        foreach ( [ 'id="presshub-cleanup-report"', 'Second GitHub token', 'X OAuth1 keys (3)', 'Podcast prompts 1–3', 'Copied to the GitHub token in Connections, then removed', '<details', '<code>presshub_social_twitter_api_key</code>', 'Download the pre-clean-up copy (JSON, no keys)', 'action=' . PressHub_Settings_Cleanup::DOWNLOAD_ACTION ] as $needle ) {
            if ( false === strpos( $usage, $needle ) ) {
                $failures[] = "Usage & logs should show the clean-up report with: {$needle}";
            }
        }
        $label_at = strpos( $usage, 'X OAuth1 keys (3)' );
        $code_at  = strpos( $usage, '<code>presshub_social_twitter_api_key</code>' );
        if ( false !== $label_at && false !== $code_at && $code_at < $label_at ) {
            $failures[] = 'The report should show the label before the option names.';
        }

        // 6. Notice shown once.
        ob_start();
        PressHub_Settings_Cleanup::render_notice();
        $first = (string) ob_get_clean();
        ob_start();
        PressHub_Settings_Cleanup::render_notice();
        $second = (string) ob_get_clean();
        if ( false === strpos( $first, '7 unused settings were removed' ) || false === strpos( $first, 'presshub-cleanup-report' ) ) {
            $failures[] = 'The first admin page should show the clean-up notice with a link to the report; got ' . strip_tags( $first );
        }
        if ( '' !== trim( $second ) ) {
            $failures[] = 'The notice should be shown only once.';
        }

        // 7. Runs once.
        $report = PressHub_Settings_Cleanup::report();
        update_option( 'presshub_ai_briefing_podcast_prompt_1', 'saved again by old code' );
        PressHub_Settings_Cleanup::maybe_run();
        if ( PressHub_Settings_Cleanup::report() !== $report ) {
            $failures[] = 'The clean-up should run only once.';
        }
        delete_option( 'presshub_ai_briefing_podcast_prompt_1' );

        // 8. Reading a removed option is flagged once per request.
        $GLOBALS['DOING_IT_WRONG'] = [];
        PressHub_Settings_Cleanup::reset_warnings();
        get_option( 'presshub_social_twitter_api_key', '' );
        get_option( 'presshub_social_twitter_api_key', '' );
        if ( 1 !== count( $GLOBALS['DOING_IT_WRONG'] ) || false === strpos( (string) ( $GLOBALS['DOING_IT_WRONG'][0][1] ?? '' ), 'presshub_social_twitter_api_key' ) || '2.45.0' !== ( $GLOBALS['DOING_IT_WRONG'][0][2] ?? '' ) ) {
            $failures[] = 'Reading a removed option should call _doing_it_wrong() once.';
        }

        // 9. A token that updates already use is never replaced.
        self::seed();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_github_token'] = 'ghp_MAINTOKEN0000000000000000000000001';
        PressHub_Settings_Cleanup::run();
        if ( 'ghp_MAINTOKEN0000000000000000000000001' !== get_option( 'presshub_ai_github_token' ) || array_key_exists( 'presshub_social_github_token', $GLOBALS['OPTIONS_STORE'] ) ) {
            $failures[] = 'With both tokens set, the AI GitHub token stays and the second one is deleted.';
        }
        ob_start();
        PressHub_Settings_Cleanup::render_report();
        if ( false !== strpos( (string) ob_get_clean(), 'Copied to the GitHub token' ) ) {
            $failures[] = 'The report should not claim a copy that did not happen.';
        }

        // 10. A site with none of the removed options: nothing deleted, no notice.
        $GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_provider' => 'openai' ];
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Cleanup::run();
        ob_start();
        PressHub_Settings_Cleanup::render_notice();
        if ( '' !== trim( (string) ob_get_clean() ) || 0 !== PressHub_Settings_Cleanup::removed_count() ) {
            $failures[] = 'A site with nothing to remove gets no notice.';
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo "SettingsCleanupTest: OK\n";
    }

    private static function seed_value( string $key ) {
        $map = [
            'presshub_ai_briefing_podcast_prompt_1' => 'Old prompt for one presenter',
            'presshub_ai_briefing_podcast_prompt_2' => 'Old prompt for two presenters',
            'presshub_ai_briefing_podcast_prompt_3' => 'Old prompt for three presenters',
        ];
        return $map[ $key ] ?? null;
    }
}

SettingsCleanupTest::run();
