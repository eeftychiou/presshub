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

$GLOBALS['TEST_DOING_AJAX'] = false;
if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax() {
        return ! empty( $GLOBALS['TEST_DOING_AJAX'] );
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
            // The clean-up itself and the one-time provider migration read them on purpose.
            if ( false === strpos( $f, 'class-settings-cleanup.php' ) && false === strpos( $f, 'class-provider-store.php' ) ) {
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
        $json   = (string) get_option( PressHub_Settings_Cleanup::export_option( PressHub_Settings_Cleanup::VERSION ), '' );
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
        if ( 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6' !== get_option( 'presshub_social_worker_secret' ) || 'Ελένη' !== get_option( 'presshub_ai_briefing_host_female' ) ) {
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
        if ( false === strpos( $first, '8 unused settings were removed' ) || false === strpos( $first, 'presshub-cleanup-report' ) ) {
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

        // 10. Never deletes without the copy: a value JSON cannot encode.
        self::seed();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_female'] = INF;
        PressHub_Settings_Cleanup::maybe_run();
        if ( ! array_key_exists( 'presshub_social_twitter_api_key', $GLOBALS['OPTIONS_STORE'] ) || array_key_exists( PressHub_Settings_Cleanup::export_option( PressHub_Settings_Cleanup::VERSION ), $GLOBALS['OPTIONS_STORE'] ) || PressHub_Settings_Cleanup::report() || array_key_exists( PressHub_Settings_Cleanup::VERSION_OPTION, $GLOBALS['OPTIONS_STORE'] ) ) {
            $failures[] = 'When the copy cannot be written, nothing may be deleted or reported.';
        }
        // Malformed UTF-8 is substituted, so it does not block the clean-up.
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_female'] = "El\xC3\x28ni";
        PressHub_Settings_Cleanup::maybe_run();
        if ( array_key_exists( 'presshub_social_twitter_api_key', $GLOBALS['OPTIONS_STORE'] ) || '' === (string) get_option( PressHub_Settings_Cleanup::export_option( PressHub_Settings_Cleanup::VERSION ), '' ) ) {
            $failures[] = 'Malformed UTF-8 in one option should not stop the clean-up.';
        }

        // 11. Not on AJAX (Heartbeat), and not while another request holds the lock.
        self::seed();
        $GLOBALS['TEST_DOING_AJAX'] = true;
        PressHub_Settings_Cleanup::maybe_run();
        $GLOBALS['TEST_DOING_AJAX'] = false;
        if ( ! array_key_exists( 'presshub_social_twitter_api_key', $GLOBALS['OPTIONS_STORE'] ) ) {
            $failures[] = 'The clean-up must not run on an AJAX request.';
        }
        $GLOBALS['OPTIONS_STORE'][ PressHub_Settings_Cleanup::LOCK_OPTION ] = time() - 5;
        PressHub_Settings_Cleanup::maybe_run();
        if ( ! array_key_exists( 'presshub_social_twitter_api_key', $GLOBALS['OPTIONS_STORE'] ) ) {
            $failures[] = 'The clean-up must wait while another request holds the lock.';
        }
        $GLOBALS['OPTIONS_STORE'][ PressHub_Settings_Cleanup::LOCK_OPTION ] = time() - PressHub_Settings_Cleanup::LOCK_TTL - 5;
        PressHub_Settings_Cleanup::maybe_run();
        if ( array_key_exists( 'presshub_social_twitter_api_key', $GLOBALS['OPTIONS_STORE'] ) || array_key_exists( PressHub_Settings_Cleanup::LOCK_OPTION, $GLOBALS['OPTIONS_STORE'] ) ) {
            $failures[] = 'A stale lock is taken over, and the lock is released afterwards.';
        }

        // 12. A site with none of the removed options: nothing deleted, no notice.
        $GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_briefing_host_female' => 'Ελένη' ];
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Cleanup::run();
        ob_start();
        PressHub_Settings_Cleanup::render_notice();
        if ( '' !== trim( (string) ob_get_clean() ) || 0 !== PressHub_Settings_Cleanup::removed_count() ) {
            $failures[] = 'A site with nothing to remove gets no notice.';
        }

        // 14. Part B: old API keys go into Connections or, when Connections
        // already holds a different key, stay one release unread.
        $gem = static function ( string $key ) {
            return [ 'id' => 'gemini-main', 'type' => 'gemini', 'name' => 'Google Gemini', 'enabled' => true, 'api_key' => $key, 'default_model' => 'gemini-3.1-flash' ];
        };
        // a) Same keys as Connections (presshub.cy): deleted.
        self::seed();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']              = 'gemini';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']               = 'AIzaSyMAINKEY00000000000000000000001';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_api_key']  = 'AIzaSyMAINKEY00000000000000000000001';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers']  = [ $gem( 'AIzaSyMAINKEY00000000000000000000001' ) ];
        $r = PressHub_Settings_Cleanup::run();
        if ( array_key_exists( 'presshub_ai_api_key', $GLOBALS['OPTIONS_STORE'] ) || array_key_exists( 'presshub_ai_briefing_tts_api_key', $GLOBALS['OPTIONS_STORE'] ) || ! empty( $r['kept'] ) || isset( $r['copied']['presshub_ai_api_key'] ) || isset( $r['copied']['presshub_ai_briefing_tts_api_key'] ) ) {
            $failures[] = 'Old keys that Connections already holds are deleted, not copied or kept.';
        }
        // b) Gemini provider without a key: the old Gemini key is copied into it.
        self::seed();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_gemini_api_key']       = 'AIzaSyOLDGEMINI000000000000000000002';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [ $gem( '' ) ];
        $r = PressHub_Settings_Cleanup::run();
        if ( 'AIzaSyOLDGEMINI000000000000000000002' !== PressHub_AI_API_Client::lookup_provider_key_by_type( 'gemini' ) || array_key_exists( 'presshub_ai_gemini_api_key', $GLOBALS['OPTIONS_STORE'] ) || empty( $r['copied']['presshub_ai_gemini_api_key'] ) ) {
            $failures[] = 'A site with only an old Gemini key keeps working: the key moves into its Gemini provider.';
        }
        // c) No provider of the type: one is added with the old key.
        self::seed();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']             = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']              = 'sk-OLDSINGLEKEY0000000000000000000003';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [ $gem( 'AIzaSyMAINKEY00000000000000000000001' ) ];
        PressHub_Settings_Cleanup::run();
        if ( 'sk-OLDSINGLEKEY0000000000000000000003' !== PressHub_AI_API_Client::lookup_provider_key_by_type( 'openai' ) || array_key_exists( 'presshub_ai_api_key', $GLOBALS['OPTIONS_STORE'] ) ) {
            $failures[] = 'An old single key for a provider type with no record becomes a provider in Connections.';
        }
        // d) A different speech key: kept, not read, named in the report and the notice.
        self::seed();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_api_key'] = 'AIzaSyOTHERSPEECH0000000000000000004';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [ $gem( 'AIzaSyMAINKEY00000000000000000000001' ) ];
        $r = PressHub_Settings_Cleanup::run();
        if ( 'AIzaSyOTHERSPEECH0000000000000000004' !== ( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_api_key'] ?? null ) || [ 'presshub_ai_briefing_tts_api_key' ] !== $r['kept'] ) {
            $failures[] = 'A speech key that differs from Connections stays for one release.';
        }
        $client = new PressHub_AI_API_Client();
        if ( 'AIzaSyMAINKEY00000000000000000000001' !== $client->resolve_gemini_api_key() ) {
            $failures[] = 'Speech uses the Connections key, not the old speech key.';
        }
        ob_start();
        PressHub_Settings_Cleanup::render_notice();
        $notice = (string) ob_get_clean();
        ob_start();
        PressHub_Settings_Cleanup::render_report();
        $rep = (string) ob_get_clean();
        if ( false === strpos( $notice, 'differs from the key in Connections' ) || false === strpos( $rep, 'Kept for one more release' ) || false === strpos( $rep, 'Separate speech key' ) ) {
            $failures[] = 'A kept key is named in the notice and the report.';
        }
        // f) Only a differing key, nothing deleted: the notice does not say "0 removed".
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_provider'             => 'gemini',
            'presshub_ai_briefing_tts_api_key' => 'AIzaSyOTHERSPEECH0000000000000000004',
            'presshub_ai_configured_providers' => [ $gem( 'AIzaSyMAINKEY00000000000000000000001' ) ],
        ];
        PressHub_Settings_Cleanup::run();
        ob_start();
        PressHub_Settings_Cleanup::render_notice();
        $notice = (string) ob_get_clean();
        if ( false !== strpos( $notice, '0 unused' ) || false === strpos( $notice, 'differs from the key in Connections' ) ) {
            $failures[] = 'With only a kept key, the notice should not count 0 removed settings: ' . $notice;
        }
        // e) The Speech key field is gone from Connections.
        if ( false !== strpos( presshub_test_render_section( 'connections' ), 'presshub_ai_briefing_tts_api_key' ) ) {
            $failures[] = 'Connections should no longer show a separate speech key.';
        }

        // 15. A site that ran clean-up 1 (2.45.0) keeps that report and gets a second one.
        self::seed();
        $GLOBALS['OPTIONS_STORE'][ PressHub_Settings_Cleanup::VERSION_OPTION ] = 1;
        $GLOBALS['OPTIONS_STORE'][ PressHub_Settings_Cleanup::REPORT_OPTION ]  = [ 'time' => 1763467200, 'version' => '2.45.0', 'removed' => [ 'presshub_social_twitter_api_key' => true ], 'copied' => [], 'export_saved' => true ];
        $GLOBALS['OPTIONS_STORE'][ PressHub_Settings_Cleanup::EXPORT_OPTION ]  = '{"format":"presshub-settings"}';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']                       = 'AIzaSyMAINKEY00000000000000000000001';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers']          = [ $gem( 'AIzaSyMAINKEY00000000000000000000001' ) ];
        PressHub_Settings_Cleanup::maybe_run();
        $runs = PressHub_Settings_Cleanup::reports();
        // It runs clean-ups 2 and 3 together, under the newest version.
        if ( [ 3, 1 ] !== array_keys( $runs ) || array_key_exists( 'presshub_ai_api_key', $GLOBALS['OPTIONS_STORE'] ) || ! array_key_exists( 'presshub_social_twitter_api_key', $GLOBALS['OPTIONS_STORE'] ) ) {
            $failures[] = 'A site at clean-up 1 removes only the later options and keeps the 2.45.0 report.';
        }
        if ( array_keys( $runs[3]['removed'] ?? [] ) !== array_merge( PressHub_Settings_Cleanup::removed_keys( 2 ), PressHub_Settings_Cleanup::removed_keys( 3 ) ) ) {
            $failures[] = 'The new report lists only the options removed after clean-up 1.';
        }
        ob_start();
        PressHub_Settings_Cleanup::render_report();
        $both = (string) ob_get_clean();
        if ( false === strpos( $both, 'Clean-up report, 2.71.0' ) || false === strpos( $both, 'Clean-up report, 2.45.0' ) || strpos( $both, '2.71.0' ) > strpos( $both, '2.45.0' ) || false === strpos( $both, 'cleanup=1' ) || false === strpos( $both, 'cleanup=3' ) ) {
            $failures[] = 'Usage & logs shows both reports, newest first, each with its own copy.';
        }

        // 16. #309 (E1): clean-up 3 keeps what the old hidden options still did.
        self::seed();
        $GLOBALS['OPTIONS_STORE'][ PressHub_Settings_Cleanup::VERSION_OPTION ] = 2;
        $GLOBALS['OPTIONS_STORE'] += [
            'presshub_social_enabled_platforms'                 => [ 'twitter', 'facebook' ],
            'presshub_social_enabled_twitter'                   => '0',
            'presshub_social_enabled_facebook'                  => '1',
            'presshub_social_enabled_youtube'                   => true,
            'presshub_ai_briefing_morning_podcast_title_prefix' => 'Πρωινό - ',
            'presshub_ai_briefing_featured_image_ids'           => '5,6',
            'presshub_ai_openai_org'                            => 'org-E1TEST',
            'presshub_ai_anthropic_version'                     => '2024-01-01',
            'presshub_ai_model_openai'                          => 'gpt-old',
            'presshub_ai_configured_providers'                  => [
                [ 'id' => 'openai-a', 'type' => 'openai', 'name' => 'OpenAI A', 'enabled' => true, 'api_key' => 'sk-a' ],
                [ 'id' => 'openai-b', 'type' => 'openai', 'name' => 'OpenAI B', 'enabled' => true, 'api_key' => 'sk-b', 'headers' => [ 'OpenAI-Organization' => 'org-OWN' ] ],
                [ 'id' => 'claude', 'type' => 'anthropic', 'name' => 'Claude', 'enabled' => true, 'api_key' => 'sk-ant', 'headers' => [ 'X-Extra' => '1' ] ],
            ],
        ];
        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_title_prefix'], $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_featured_image_ids_morning'] );
        $report = PressHub_Settings_Cleanup::run();
        $store  = $GLOBALS['OPTIONS_STORE'];
        if ( [ 'facebook', 'youtube' ] !== ( $store['presshub_social_enabled_platforms'] ?? null ) ) {
            $failures[] = 'The social switches should fold into the platforms list (X off, YouTube on); got ' . json_encode( $store['presshub_social_enabled_platforms'] ?? null );
        }
        if ( 'Πρωινό - ' !== ( $store['presshub_ai_briefing_podcast_title_prefix'] ?? null ) ) {
            $failures[] = 'The morning title prefix should be copied into the unset podcast title prefix.';
        }
        if ( '5,6' !== ( $store['presshub_ai_briefing_featured_image_ids_morning'] ?? null ) ) {
            $failures[] = 'The old pool should be copied into the empty Morning pool.';
        }
        $by_id = [];
        foreach ( (array) ( $store['presshub_ai_configured_providers'] ?? [] ) as $rec ) {
            $by_id[ $rec['id'] ] = $rec;
        }
        if ( 'org-E1TEST' !== ( $by_id['openai-a']['headers']['OpenAI-Organization'] ?? null ) || 'org-OWN' !== ( $by_id['openai-b']['headers']['OpenAI-Organization'] ?? null ) ) {
            $failures[] = 'The organization should go onto OpenAI providers that set none, never over their own; got ' . json_encode( [ $by_id['openai-a']['headers'] ?? null, $by_id['openai-b']['headers'] ?? null ] );
        }
        if ( [ 'X-Extra' => '1', 'anthropic-version' => '2024-01-01' ] !== ( $by_id['claude']['headers'] ?? null ) ) {
            $failures[] = 'The Anthropic version should join the provider headers; got ' . json_encode( $by_id['claude']['headers'] ?? null );
        }
        if ( 'sk-a' !== ( $by_id['openai-a']['api_key'] ?? null ) || 'sk-ant' !== ( $by_id['claude']['api_key'] ?? null ) ) {
            $failures[] = 'Copying a header must keep the provider key.';
        }
        foreach ( PressHub_Settings_Cleanup::removed_keys( 3 ) as $key ) {
            if ( array_key_exists( $key, $store ) ) {
                $failures[] = "{$key} should be removed by clean-up 3.";
            }
        }
        foreach ( [ 'presshub_social_enabled_twitter', 'presshub_social_enabled_youtube', 'presshub_ai_briefing_morning_podcast_title_prefix', 'presshub_ai_briefing_featured_image_ids', 'presshub_ai_openai_org', 'presshub_ai_anthropic_version' ] as $key ) {
            if ( empty( $report['copied'][ $key ] ) ) {
                $failures[] = "The report should say where {$key} went.";
            }
        }
        if ( isset( $report['copied']['presshub_social_enabled_facebook'] ) || isset( $report['copied']['presshub_ai_model_openai'] ) ) {
            $failures[] = 'A switch that changed nothing, or a value that was not kept, is not reported as copied.';
        }
        $export = json_decode( (string) ( $store[ PressHub_Settings_Cleanup::export_option( 3 ) ] ?? '' ), true );
        if ( 'gpt-old' !== ( $export['options']['presshub_ai_model_openai'] ?? null ) || 'org-E1TEST' !== ( $export['options']['presshub_ai_openai_org'] ?? null ) ) {
            $failures[] = 'The copy saved first should hold the removed provider options.';
        }
        // The report names every place a group's values went.
        ob_start();
        PressHub_Settings_Cleanup::render_report();
        $r3 = (string) ob_get_clean();
        foreach ( [ 'the OpenAI A provider in Connections', 'the Claude provider in Connections', '(turned off)', '(turned on)' ] as $where ) {
            if ( false === strpos( $r3, $where ) ) {
                $failures[] = "The 2.71.0 report should say the values went to {$where}.";
            }
        }
        // A site at clean-up 1 with no OpenAI provider: the old key creates one,
        // and that provider also gets the old organization (clean-ups 2 and 3 in one run).
        self::seed();
        $GLOBALS['OPTIONS_STORE'][ PressHub_Settings_Cleanup::VERSION_OPTION ] = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider']              = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key']               = 'sk-OLDKEY000000000000000000000001';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_openai_org']            = 'org-WITHKEY';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers']  = [];
        PressHub_Settings_Cleanup::run();
        $made = array_values( array_filter( (array) $GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'], static function ( $r ) {
            return 'openai' === ( $r['type'] ?? '' );
        } ) );
        if ( 1 !== count( $made ) || 'sk-OLDKEY000000000000000000000001' !== ( $made[0]['api_key'] ?? '' ) || 'org-WITHKEY' !== ( $made[0]['headers']['OpenAI-Organization'] ?? null ) ) {
            $failures[] = 'A provider created from the old key should also get the old organization; got ' . json_encode( array_map( static function ( $r ) {
                return [ 'type' => $r['type'] ?? '', 'key' => '' !== (string) ( $r['api_key'] ?? '' ), 'headers' => $r['headers'] ?? null ];
            }, $made ) );
        }
        // A set prefix (even empty) and a filled Morning pool are never overwritten.
        self::seed();
        $GLOBALS['OPTIONS_STORE'][ PressHub_Settings_Cleanup::VERSION_OPTION ] = 2;
        $GLOBALS['OPTIONS_STORE'] += [
            'presshub_ai_briefing_morning_podcast_title_prefix' => 'Old - ',
            'presshub_ai_briefing_podcast_title_prefix'         => '',
            'presshub_ai_briefing_featured_image_ids'           => '5,6',
            'presshub_ai_briefing_featured_image_ids_morning'   => '9',
        ];
        $report = PressHub_Settings_Cleanup::run();
        if ( '' !== ( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_title_prefix'] ?? null ) || '9' !== ( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_featured_image_ids_morning'] ?? null ) ) {
            $failures[] = 'A prefix set on purpose (even empty) and a filled Morning pool are kept.';
        }
        if ( isset( $report['copied']['presshub_ai_briefing_morning_podcast_title_prefix'] ) || isset( $report['copied']['presshub_ai_briefing_featured_image_ids'] ) ) {
            $failures[] = 'Values that were not copied are not reported as copied.';
        }
        // Reading a removed E1 option is flagged with its release.
        $GLOBALS['DOING_IT_WRONG'] = [];
        PressHub_Settings_Cleanup::reset_warnings();
        get_option( 'presshub_ai_provider' );
        if ( ! $GLOBALS['DOING_IT_WRONG'] || false === strpos( (string) $GLOBALS['DOING_IT_WRONG'][0][1] . (string) $GLOBALS['DOING_IT_WRONG'][0][2], '2.71.0' ) ) {
            $failures[] = 'Reading presshub_ai_provider after clean-up 3 should be flagged with 2.71.0.';
        }

        // 13. Uninstall removes the clean-up rows, including the copy.
        $uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
        foreach ( [ PressHub_Settings_Cleanup::VERSION_OPTION, PressHub_Settings_Cleanup::REPORT_OPTION, PressHub_Settings_Cleanup::EXPORT_OPTION, PressHub_Settings_Cleanup::export_option( 2 ), PressHub_Settings_Cleanup::export_option( 3 ), PressHub_Settings_Cleanup::NOTICE_OPTION, PressHub_Settings_Cleanup::LOCK_OPTION ] as $opt ) {
            if ( false === strpos( $uninstall, "'" . $opt . "'" ) ) {
                $failures[] = "uninstall.php should delete {$opt}.";
            }
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
