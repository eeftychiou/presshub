<?php
/**
 * #233 steps 3 and 6: settings export / import and the "Scheduled for
 * removal" list.
 *
 *  - Export holds every stored non-secret option and no secret value.
 *  - Importing it on a fresh site reproduces the same stored values.
 *  - Import never writes an unregistered key or a secret; preview writes nothing.
 *  - Unused options leave their Social sections and are listed under
 *    Advanced & System → Diagnostics with a masked saved value.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) {
        return rtrim( (string) $value, '/\\' );
    }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once __DIR__ . '/../presshub-ai-editor.php';
require_once __DIR__ . '/../includes/class-social-settings.php';
require_once __DIR__ . '/../includes/class-social-settings-render.php';
require_once __DIR__ . '/settings-sections-helper.php';

class SettingsTransferTest {

    public static function run(): void {
        $failures = [];
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        $all = PressHub_Settings_Registry::all();

        // A realistic stored site: each option's default as its sanitizer
        // stores it (with every Social field "present", as the form posts),
        // plus hand-picked custom values and secrets.
        $_POST = [];
        foreach ( array_keys( $all ) as $key ) {
            $_POST[ $key . '_present' ] = '1';
        }
        $GLOBALS['OPTIONS_STORE'] = [];
        $site = [];
        foreach ( $all as $key => $e ) {
            if ( ! empty( $e['removes'] ) || ! empty( $e['secret'] ) ) {
                continue;
            }
            $cb      = PressHub_Settings_Registry::sanitizer( $key );
            $default = PressHub_Settings_Registry::default_value( $key );
            $raw     = is_bool( $default ) ? ( $default ? '1' : null ) : $default;
            // Twice: a stored value has been through the sanitizer at least once
            // (the legacy source list gains its priority/alignment on the second pass).
            $site[ $key ] = $cb ? call_user_func( $cb, call_user_func( $cb, $raw ) ) : $raw;
            $GLOBALS['OPTIONS_STORE'][ $key ] = $site[ $key ];
        }
        $custom = [
            'presshub_ai_briefing_harvest_time'   => '04:15',
            'presshub_ai_briefing_host_female'    => 'Ελένη',
            'presshub_ai_pool_max_age_hours'      => 36,
            'presshub_ai_policy_fiches_budgets'   => PressHub_AI_Settings_Storage::sanitize_policy_fiches_budgets( [ 'podcast_script' => 900 ] ),
            'presshub_social_copy_mode'           => 'template',
            'presshub_social_tiktok_shorts_count' => 3,
        ];
        foreach ( $custom as $k => $v ) {
            $site[ $k ] = $v;
            $GLOBALS['OPTIONS_STORE'][ $k ] = $v;
        }
        $secret_values = [
            'presshub_ai_api_key'                 => 'AIzaSyEXAMPLEKEY000000000000000003kQ',
            'presshub_ai_github_token'            => 'ghp_EXAMPLETOKEN00000000000000000091',
            'presshub_social_meta_access_token'   => 'EAAGexampleMetaToken000000000000',
            'presshub_social_twitter_bearer_token' => 'AAAAAAAAAAAAAAAAAAAAAexampleBearer0042',
        ];
        foreach ( $secret_values as $k => $v ) {
            $GLOBALS['OPTIONS_STORE'][ $k ] = $v;
        }
        $_POST = [];

        // 1. Export.
        $export = PressHub_Settings_Transfer::export_data();
        $json   = (string) json_encode( $export );
        foreach ( $secret_values as $k => $v ) {
            if ( false !== strpos( $json, $v ) ) {
                $failures[] = "Export contains the value of secret {$k}.";
            }
            if ( array_key_exists( $k, $export['options'] ) || ! in_array( $k, $export['secrets_left_out'], true ) ) {
                $failures[] = "Secret {$k} should be listed by name only.";
            }
        }
        foreach ( $site as $k => $v ) {
            if ( ! array_key_exists( $k, $export['options'] ) ) {
                $failures[] = "Export is missing {$k}.";
            }
        }
        foreach ( array_keys( $export['options'] ) as $k ) {
            if ( ! empty( $all[ $k ]['secret'] ) ) {
                $failures[] = "Export holds secret option {$k}.";
            }
        }

        // 2. Import on a fresh site reproduces the values.
        $GLOBALS['OPTIONS_STORE'] = [];
        $file    = json_decode( $json, true );
        $parsed  = PressHub_Settings_Transfer::parse( $json );
        if ( isset( $parsed['error'] ) ) {
            $failures[] = 'A fresh export should parse: ' . $parsed['error'];
        }
        PressHub_Settings_Transfer::apply( $parsed['options'] ?? [] );
        foreach ( $site as $k => $v ) {
            $now = $GLOBALS['OPTIONS_STORE'][ $k ] ?? '(missing)';
            if ( serialize( $now ) !== serialize( $v ) ) {
                $failures[] = "{$k} after import: " . var_export( $now, true ) . ', expected ' . var_export( $v, true );
            }
        }
        foreach ( $secret_values as $k => $v ) {
            if ( array_key_exists( $k, $GLOBALS['OPTIONS_STORE'] ) ) {
                $failures[] = "Import wrote secret {$k} on a fresh site.";
            }
        }
        if ( $_POST !== [] ) {
            $failures[] = 'Import should restore $_POST after running the sanitizers.';
        }

        // 3. Unregistered keys and secrets are skipped, and preview writes nothing.
        $GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_api_key' => 'keep-me', 'presshub_ai_pool_max_age_hours' => 48 ];
        $before  = $GLOBALS['OPTIONS_STORE'];
        $hostile = [
            'presshub_ai_api_key'            => 'stolen',
            'presshub_ai_remove_api_key'     => '1',
            'siteurl'                        => 'https://evil.example',
            'presshub_ai_pool_max_age_hours' => '12',
        ];
        $preview = PressHub_Settings_Transfer::preview( $hostile );
        if ( $GLOBALS['OPTIONS_STORE'] !== $before ) {
            $failures[] = 'Preview must not write anything.';
        }
        if ( array_keys( $preview['changes'] ) !== [ 'presshub_ai_pool_max_age_hours' ] || count( $preview['skipped'] ) !== 3 ) {
            $failures[] = 'Preview should change only the registered non-secret option and skip the other three: ' . var_export( $preview, true );
        }
        PressHub_Settings_Transfer::apply( $hostile );
        if ( 'keep-me' !== $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] || array_key_exists( 'siteurl', $GLOBALS['OPTIONS_STORE'] ) || 12 !== $GLOBALS['OPTIONS_STORE']['presshub_ai_pool_max_age_hours'] ) {
            $failures[] = 'Import must write only registered, non-secret options (through their sanitizers).';
        }
        foreach ( [ '{"format":"other","options":{}}', 'not json', '{"format":"presshub-settings","format_version":99,"options":{}}' ] as $bad ) {
            if ( ! isset( PressHub_Settings_Transfer::parse( $bad )['error'] ) ) {
                $failures[] = "parse() should reject {$bad}";
            }
        }

        // 4. Scheduled for removal: listed on Diagnostics with a masked value…
        $GLOBALS['OPTIONS_STORE']     = $secret_values;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_others_posts' ];
        $html = presshub_test_render_all_sections();
        $adv  = substr( $html, (int) strpos( $html, 'id="presshub-tab-pane-site-system"' ) );
        foreach ( array_keys( PressHub_Settings_Registry::unused() ) as $k ) {
            if ( false === strpos( $adv, '<code>' . $k . '</code>' ) ) {
                $failures[] = "{$k} should be listed under Scheduled for removal.";
            }
        }
        if ( false !== strpos( $html, $secret_values['presshub_social_twitter_bearer_token'] ) ) {
            $failures[] = 'Settings must mask a saved token.';
        }
        // #237 removed everything that was on the list.
        $removal = substr( $adv, (int) strpos( $adv, 'id="presshub-scheduled-for-removal"' ), 1200 );
        if ( false === strpos( $removal, 'None.' ) ) {
            $failures[] = 'Scheduled for removal should say None. after the clean-up release.';
        }
        if ( false === strpos( $adv, 'action=' . PressHub_Settings_Transfer::EXPORT_ACTION ) || false === strpos( $html, 'id="presshub-settings-import-form"' ) ) {
            $failures[] = 'Diagnostics should offer Export and Import.';
        }
        // …and no longer rendered on the Social settings page.
        $GLOBALS['FIELDS'] = [];
        ( new PressHub_Social_Settings() )->register_settings();
        $social_fields = [];
        foreach ( $GLOBALS['FIELDS'] as $page => $sections ) {
            foreach ( $sections as $fields ) {
                foreach ( $fields as $f ) {
                    $social_fields[] = $f['id'];
                }
            }
        }
        foreach ( PressHub_Settings_Cleanup::removed_keys() as $k ) {
            if ( in_array( $k, $social_fields, true ) ) {
                $failures[] = "{$k} was removed and should no longer be a field on its Social section.";
            }
        }
        if ( ! in_array( 'presshub_social_twitter_access_token', $social_fields, true ) ) {
            $failures[] = 'The X access token (still read) must stay on the Social page.';
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo 'SettingsTransferTest: ' . count( $site ) . " options round-trip, secrets stay out, removal list shown.\nOK\n";
    }
}

SettingsTransferTest::run();
