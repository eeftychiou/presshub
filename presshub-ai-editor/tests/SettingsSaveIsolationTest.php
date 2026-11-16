<?php
/**
 * #233 save isolation: saving one tab changes only that tab's options.
 *
 * AI page (AJAX per-tab save): every option is seeded with a sentinel value,
 * then each tab is saved twice, once with every one of its fields posted and
 * once with nothing posted (all checkboxes unticked). Every option that
 * belongs to another tab must be byte-for-byte unchanged afterwards.
 *
 * Social page (options.php): WordPress runs every option of the group through
 * its sanitize callback on each save, with null for fields of other tabs. Each
 * tab is saved the same way, and other tabs' options must keep their values.
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
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';
foreach ( [ 'class-social-settings-storage.php', 'class-social-tiktok-narrator.php', 'class-social-tiktok-music.php', 'class-social-tiktok-shorts.php', 'class-social-post-images.php', 'class-social-image-library.php', 'class-social-ai-copywriter.php' ] as $presshub_f ) {
    require_once __DIR__ . '/../includes/' . $presshub_f;
}

class SettingsSaveIsolationTest {

    /** Sentinel stored in every option before a save. */
    private static function sentinel( string $key ) {
        $e = PressHub_Settings_Registry::get( $key );
        if ( ! empty( $e['checkbox'] ) || in_array( $e['type'] ?? '', [ 'boolean', 'integer' ], true ) ) {
            return 1;
        }
        if ( 'array' === ( $e['type'] ?? '' ) ) {
            return [ 'sentinel' ];
        }
        return 'sentinel-' . $key;
    }

    /** A value to post for a field of the tab being saved. */
    private static function posted( string $key ) {
        $e = PressHub_Settings_Registry::get( $key );
        if ( ! empty( $e['removes'] ) ) {
            return null; // never tick "remove key" in this test
        }
        if ( ! empty( $e['checkbox'] ) || 'boolean' === ( $e['type'] ?? '' ) ) {
            return '1';
        }
        if ( in_array( $e['type'] ?? '', [ 'integer', 'number' ], true ) ) {
            return '7';
        }
        return 'changed';
    }

    private static function seed( array $keys ): void {
        $GLOBALS['OPTIONS_STORE'] = [];
        foreach ( $keys as $key ) {
            $GLOBALS['OPTIONS_STORE'][ $key ] = self::sentinel( $key );
        }
    }

    private static function changed_outside( array $before, array $tab_keys ): array {
        $changed = [];
        foreach ( $before as $key => $value ) {
            if ( in_array( $key, $tab_keys, true ) ) {
                continue;
            }
            $now = array_key_exists( $key, $GLOBALS['OPTIONS_STORE'] ) ? $GLOBALS['OPTIONS_STORE'][ $key ] : '(deleted)';
            if ( serialize( $now ) !== serialize( $value ) ) {
                $changed[] = $key . ' (' . var_export( $value, true ) . ' → ' . var_export( $now, true ) . ')';
            }
        }
        return $changed;
    }

    public static function run(): void {
        $failures = [];
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        $handlers = new PressHub_AI_Ajax_Handlers();

        // ---------------- AI page, AJAX per-tab save ----------------
        $ai_keys = array_keys( PressHub_Settings_Registry::for_page( 'ai' ) );
        $tabs    = [];
        foreach ( PressHub_Settings_Registry::for_page( 'ai' ) as $e ) {
            $tabs[ $e['tab'] ] = true;
        }
        foreach ( array_keys( $tabs ) as $tab ) {
            $tab_keys = array_keys( PressHub_Settings_Registry::for_tab( 'ai', $tab ) );
            foreach ( [ 'all fields posted', 'nothing posted' ] as $mode ) {
                self::seed( $ai_keys );
                $before  = $GLOBALS['OPTIONS_STORE'];
                $payload = [];
                if ( 'all fields posted' === $mode ) {
                    foreach ( $tab_keys as $key ) {
                        $v = self::posted( $key );
                        if ( null !== $v ) {
                            $payload[ $key ] = $v;
                        }
                    }
                }
                $_POST = [
                    'tab'         => $tab,
                    'nonce'       => 'valid_nonce',
                    'payload_b64' => base64_encode( json_encode( $payload ) ),
                ];
                $GLOBALS['NONCE_VALID']       = true;
                $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
                $GLOBALS['JSON_RESPONSES']    = [];
                try {
                    $handlers->save_settings_section();
                } catch ( Throwable $t ) {
                    // wp_send_json_* stubs throw to end the request.
                }
                $resp = end( $GLOBALS['JSON_RESPONSES'] );
                if ( empty( $resp['success'] ) ) {
                    $failures[] = "AI tab {$tab} ({$mode}): save did not succeed.";
                }
                foreach ( self::changed_outside( $before, $tab_keys ) as $c ) {
                    $failures[] = "AI tab {$tab} ({$mode}) changed another tab's option: {$c}";
                }
            }
        }

        // The "save all" path writes every tab's posted fields and nothing else.
        self::seed( $ai_keys );
        $_POST = [ 'nonce' => 'valid_nonce', 'payload_b64' => base64_encode( json_encode( [ 'presshub_ai_seo_max_tags' => '9' ] ) ) ];
        try {
            $handlers->save_settings();
        } catch ( Throwable $t ) {
        }
        if ( 9 !== (int) get_option( 'presshub_ai_seo_max_tags' ) ) {
            $failures[] = 'Save all should now save presshub_ai_seo_max_tags (it was missing from the old hand-written map).';
        }

        // ---------------- Social page, options.php ----------------
        // The Social settings page renders every tab in one form (other tabs are
        // hidden with CSS), so each Save posts all fields: the current value of
        // every other tab's fields, plus the "_present" markers for checkboxes.
        // Re-saving those unchanged values must leave them exactly as stored.
        $social   = PressHub_Settings_Registry::for_page( 'social' );
        $soc_keys = array_keys( $social );
        $soc_tabs = [];
        foreach ( $social as $e ) {
            $soc_tabs[ $e['tab'] ] = true;
        }
        $registered = PressHub_Settings_Registry::registered();
        $sanitize   = static function ( string $key, $value ) use ( $registered ) {
            $cb = $registered[ $key ]['args']['sanitize_callback'] ?? null;
            return is_callable( $cb ) ? call_user_func( $cb, $value ) : $value;
        };
        $markers = [];
        foreach ( $soc_keys as $key ) {
            $markers[ $key . '_present' ] = '1';
        }
        // Stable stored values: each option's default, as its sanitizer stores it.
        $GLOBALS['OPTIONS_STORE'] = [];
        $_POST                    = $markers;
        $stable                   = [];
        foreach ( $soc_keys as $key ) {
            $default        = PressHub_Settings_Registry::default_value( $key );
            $stable[ $key ] = $sanitize( $key, is_bool( $default ) ? ( $default ? '1' : null ) : $default );
            $GLOBALS['OPTIONS_STORE'][ $key ] = $stable[ $key ];
        }
        foreach ( array_keys( $soc_tabs ) as $tab ) {
            $tab_keys                 = array_keys( PressHub_Settings_Registry::for_tab( 'social', $tab ) );
            $GLOBALS['OPTIONS_STORE'] = $stable;
            $before                   = $stable;
            $_POST                    = $markers + [ 'option_page' => 'presshub_social_options' ];
            foreach ( $soc_keys as $key ) {
                if ( in_array( $key, $tab_keys, true ) ) {
                    $v = self::posted( $key );
                } else {
                    $v = $stable[ $key ];
                    if ( is_bool( $v ) || ( ! empty( $social[ $key ]['checkbox'] ) && ! is_array( $v ) ) ) {
                        $v = $v ? '1' : null;
                    }
                }
                if ( null !== $v ) {
                    $_POST[ $key ] = $v;
                }
            }
            foreach ( $soc_keys as $key ) {
                $value = array_key_exists( $key, $_POST ) ? $_POST[ $key ] : null;
                try {
                    update_option( $key, $sanitize( $key, $value ) );
                } catch ( Throwable $t ) {
                    $failures[] = "Social tab {$tab}: sanitizer for {$key} threw " . $t->getMessage();
                }
            }
            foreach ( self::changed_outside( $before, $tab_keys ) as $c ) {
                $failures[] = "Social tab {$tab} changed another tab's option: {$c}";
            }
        }
        $_POST = [];

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo 'SettingsSaveIsolationTest: ' . count( $tabs ) . ' AI tabs and ' . count( $soc_tabs ) . " Social tabs isolated.\nOK\n";
    }
}

SettingsSaveIsolationTest::run();
