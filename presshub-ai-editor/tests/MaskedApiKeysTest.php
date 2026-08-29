<?php
/**
 * TDD Tests for Issue #4: Masked API Keys beginning and ending characters.
 *
 * Covers:
 *   - Provider store & Settings mask_key helper functions
 *   - Known vendor prefixes (sk-proj-, sk-admin-, sk-ant-, gsk_, AQ.Ab, AIzaSy, etc.)
 *   - General strings with length >= 10, 6-9, < 6, empty
 *   - Array provider record representation (ollama_local, empty key, set key)
 *   - Mask preservation during provider save / update
 *   - Credentials display in provider cards and modals
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';

class MaskedApiKeysTest {

    public static function run(): void {
        $failures = [];

        // -------------------------------------------------------------
        // Case 1: Known Vendor Prefixes & Length >= 10
        // -------------------------------------------------------------
        $test_cases_ge10 = [
            'sk-proj-12345678909aBc'       => 'sk-proj-••••••••9aBc',
            'sk-admin-secretkey9999xyz'    => 'sk-admin-••••••••9xyz',
            'sk-ant-api03-abcdefg9876mnop' => 'sk-ant-api03-••••••••mnop',
            'sk-ant-1234567890wxyz'        => 'sk-ant-••••••••wxyz',
            'gsk_1234567890xK2L'           => 'gsk_••••••••xK2L',
            'ghp_1234567890abcdef'         => 'ghp_••••••••cdef',
            'github_pat_11AAAAAA_xxxx9999' => 'github_pat_••••••••9999',
            'AQ.Ab12345678FmAQ'            => 'AQ.Ab••••••••FmAQ',
            'AIzaSy1234567890abcdef'       => 'AIzaSy••••••••cdef',
            'AIza1234567890abcdef'         => 'AIza••••••••cdef',
            'nvapi-1234567890abcd'         => 'nvapi-••••••••abcd',
            'xai-1234567890abcd'           => 'xai-••••••••abcd',
            'ms-1234567890abcd'            => 'ms-••••••••abcd',
            'sk-1234567890'                => 'sk-••••••••7890',
            'custom_prefix_1234567890abcd' => 'custom_prefix_••••••••abcd',
            'generic1234567890'            => 'gene••••••••7890',
        ];

        foreach ( $test_cases_ge10 as $raw => $expected ) {
            $masked_store  = PressHub_AI_Provider_Store::mask_key( $raw );
            $masked_render = PressHub_AI_Settings_Render::mask_key( $raw );
            $masked_facade = PressHub_AI_Settings::mask_key( $raw );

            if ( $masked_store !== $expected ) {
                $failures[] = "Provider_Store::mask_key('{$raw}') should be '{$expected}'; got: '{$masked_store}'";
            }
            if ( $masked_render !== $expected ) {
                $failures[] = "Settings_Render::mask_key('{$raw}') should be '{$expected}'; got: '{$masked_render}'";
            }
            if ( $masked_facade !== $expected ) {
                $failures[] = "Settings::mask_key('{$raw}') should be '{$expected}'; got: '{$masked_facade}'";
            }
        }

        // -------------------------------------------------------------
        // Case 2: Length 6 to 9 Characters
        // -------------------------------------------------------------
        $test_cases_6_9 = [
            '123456789' => '12••••89',
            'abcdefgh'  => 'ab••••gh',
            '1234567'   => '12••••67',
            '123456'    => '12••••56',
        ];

        foreach ( $test_cases_6_9 as $raw => $expected ) {
            $masked = PressHub_AI_Provider_Store::mask_key( $raw );
            if ( $masked !== $expected ) {
                $failures[] = "mask_key('{$raw}') should be '{$expected}'; got: '{$masked}'";
            }
        }

        // -------------------------------------------------------------
        // Case 3: Length < 6 & Empty
        // -------------------------------------------------------------
        if ( PressHub_AI_Provider_Store::mask_key( '12345' ) !== '••••' ) {
            $failures[] = "mask_key('12345') should be '••••'; got: " . PressHub_AI_Provider_Store::mask_key( '12345' );
        }
        if ( PressHub_AI_Provider_Store::mask_key( 'a' ) !== '••••' ) {
            $failures[] = "mask_key('a') should be '••••'; got: " . PressHub_AI_Provider_Store::mask_key( 'a' );
        }
        if ( PressHub_AI_Provider_Store::mask_key( '' ) !== '' ) {
            $failures[] = "mask_key('') should be ''; got: " . PressHub_AI_Provider_Store::mask_key( '' );
        }
        if ( PressHub_AI_Provider_Store::mask_key( '   ' ) !== '' ) {
            $failures[] = "mask_key('   ') should be ''; got: " . PressHub_AI_Provider_Store::mask_key( '   ' );
        }

        // -------------------------------------------------------------
        // Case 4: Array Provider Record Support
        // -------------------------------------------------------------
        $ollama_prov = [
            'type'    => 'ollama_local',
            'api_key' => '',
        ];
        if ( PressHub_AI_Provider_Store::mask_key( $ollama_prov ) !== 'Not required' ) {
            $failures[] = "mask_key(ollama_local) should be 'Not required'; got: " . PressHub_AI_Provider_Store::mask_key( $ollama_prov );
        }

        $nokey_prov = [
            'type'    => 'openai',
            'api_key' => '',
        ];
        if ( PressHub_AI_Provider_Store::mask_key( $nokey_prov ) !== 'No API Key set' ) {
            $failures[] = "mask_key(openai with no key) should be 'No API Key set'; got: " . PressHub_AI_Provider_Store::mask_key( $nokey_prov );
        }

        $set_prov = [
            'type'    => 'openai',
            'api_key' => 'sk-proj-12345678909aBc',
        ];
        if ( PressHub_AI_Provider_Store::mask_key( $set_prov ) !== 'sk-proj-••••••••9aBc' ) {
            $failures[] = "mask_key(openai with key) should be 'sk-proj-••••••••9aBc'; got: " . PressHub_AI_Provider_Store::mask_key( $set_prov );
        }

        // -------------------------------------------------------------
        // Case 5: Provider Store Save / Update with Mask Preserves Secret
        // -------------------------------------------------------------
        self::reset();
        $id = PressHub_AI_Provider_Store::save_provider( [
            'id'       => 'test-mask-preservation',
            'type'     => 'openai',
            'name'     => 'Mask Preservation Test',
            'api_key'  => 'sk-proj-RealSecretKey12345678909aBc',
            'base_url' => 'https://api.openai.com/v1',
        ] );

        $saved = PressHub_AI_Provider_Store::get( $id );
        if ( ! $saved || $saved['api_key'] !== 'sk-proj-RealSecretKey12345678909aBc' ) {
            $failures[] = "Initial provider save failed to store raw key.";
        }

        // Update with masked placeholder submitted
        PressHub_AI_Provider_Store::save_provider( [
            'id'      => $id,
            'name'    => 'Mask Preservation Test Updated',
            'api_key' => 'sk-proj-••••••••9aBc',
        ] );

        $updated = PressHub_AI_Provider_Store::get( $id );
        if ( ! $updated || $updated['api_key'] !== 'sk-proj-RealSecretKey12345678909aBc' ) {
            $failures[] = "Saving provider with masked key string must preserve existing secret key; got: " . var_export( $updated['api_key'] ?? null, true );
        }

        // -------------------------------------------------------------
        // Case 6: render_providers_grid() renders masked keys on cards
        // -------------------------------------------------------------
        self::reset();
        PressHub_AI_Provider_Store::save_provider( [
            'id'       => 'openai-test-card',
            'type'     => 'openai',
            'name'     => 'OpenAI Card',
            'api_key'  => 'sk-proj-12345678909aBc',
            'enabled'  => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'       => 'ollama-test-card',
            'type'     => 'ollama_local',
            'name'     => 'Ollama Card',
            'api_key'  => '',
            'enabled'  => true,
        ] );

        $renderer = new PressHub_AI_Settings_Render();
        ob_start();
        $renderer->render_providers_grid();
        $html = ob_get_clean();

        if ( false === strpos( $html, 'sk-proj-••••••••9aBc' ) ) {
            $failures[] = "render_providers_grid should display 'sk-proj-••••••••9aBc' for OpenAI card; got: " . $html;
        }
        if ( false === strpos( $html, 'data-provider-masked-key="sk-proj-••••••••9aBc"' ) ) {
            $failures[] = "render_providers_grid should set data-provider-masked-key attribute on card; got: " . $html;
        }
        if ( false === strpos( $html, 'Not required' ) ) {
            $failures[] = "render_providers_grid should display 'Not required' for ollama_local card; got: " . $html;
        }

        // Output Results
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
        $GLOBALS['OPTIONS_STORE']       = [];
        $GLOBALS['UPDATE_OPTION_CALLS'] = [];
    }
}

MaskedApiKeysTest::run();
