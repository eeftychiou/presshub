<?php
/**
 * TDD tests for the extracted presets asset (Batch B, Medium-10):
 *
 *   - assets/presets.js is enqueued through admin_enqueue_scripts by both
 *     class-admin-presets.php (settings_page_presshub-ai-presets) and
 *     class-author-presets.php (profile.php / user-edit.php) with the
 *     'jquery' dependency, PRESSHUB_AI_VERSION and in_footer=true;
 *   - wp_localize_script provides presshubAI with ajax_url, nonce,
 *     scope ('plugin' vs 'author'), user_id and an i18n translations map;
 *   - the rendered pages no longer contain inline <script> blocks and
 *     still emit their expected markup ids.
 */

// Extra stubs not provided by the shared harness.
if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false ) {
        $GLOBALS['ENQUEUED_SCRIPTS'][ $handle ] = [ 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer ];
    }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( $handle, $object_name, $data ) {
        $GLOBALS['LOCALIZED_SCRIPTS'][ $handle ] = [ 'object_name' => $object_name, 'data' => $data ];
    }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) {
        return 'test-nonce';
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
defined( 'PRESSHUB_AI_VERSION' ) || define( 'PRESSHUB_AI_VERSION', '1.1.0' );
defined( 'PRESSHUB_AI_URL' ) || define( 'PRESSHUB_AI_URL', 'http://example.test/wp-content/plugins/presshub-ai-editor/' );
require_once __DIR__ . '/../includes/class-preset-sanitizer.php';
require_once __DIR__ . '/../includes/class-preset-store.php';
require_once __DIR__ . '/../includes/class-admin-presets.php';
require_once __DIR__ . '/../includes/class-author-presets.php';

class PresetsAssetsTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: admin page enqueues the asset with the right config ---
        self::reset();
        $admin = new PressHub_AI_Admin_Presets();
        $admin->enqueue_scripts( 'settings_page_presshub-ai-presets' );
        $entry = $GLOBALS['ENQUEUED_SCRIPTS'][ PressHub_AI_Admin_Presets::SCRIPT_HANDLE ] ?? null;
        if ( ! $entry ) {
            $failures[] = 'Admin presets page should enqueue presshub-ai-presets-js; got: ' . json_encode( array_keys( $GLOBALS['ENQUEUED_SCRIPTS'] ?? [] ) );
        } else {
            if ( $entry['deps'] !== [ 'jquery' ] || true !== $entry['in_footer'] || PRESSHUB_AI_VERSION !== $entry['ver'] ) {
                $failures[] = 'presets asset should enqueue with jquery dep, in_footer=true and the plugin version; got: ' . json_encode( $entry );
            }
            $localized = $GLOBALS['LOCALIZED_SCRIPTS'][ PressHub_AI_Admin_Presets::SCRIPT_HANDLE ] ?? null;
            if ( ! $localized || 'presshubAI' !== $localized['object_name'] ) {
                $failures[] = 'presets asset should be localized as presshubAI; got: ' . json_encode( $localized );
            } else {
                $data = $localized['data'];
                if ( ( $data['scope'] ?? '' ) !== 'plugin' ) {
                    $failures[] = 'admin localize should carry scope=plugin; got: ' . json_encode( $data );
                }
                if ( empty( $data['ajax_url'] ) || empty( $data['nonce'] ) ) {
                    $failures[] = 'admin localize should carry ajax_url + nonce; got: ' . json_encode( $data );
                }
                foreach ( [ 'slug_required', 'error_prefix', 'unknown_error', 'connection_error', 'delete_confirm' ] as $key ) {
                    if ( empty( $data['i18n'][ $key ] ) ) {
                        $failures[] = "admin localize i18n should include {$key}; got: " . json_encode( $data['i18n'] ?? null );
                    }
                }
            }
        }

        // --- Case 2: wrong hook does not enqueue ---
        self::reset();
        $admin->enqueue_scripts( 'post.php' );
        if ( ! empty( $GLOBALS['ENQUEUED_SCRIPTS'] ) ) {
            $failures[] = 'Admin presets must not enqueue outside its own page.';
        }

        // --- Case 3: author profiles enqueue with scope=author + user_id ---
        self::reset();
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $author = new PressHub_AI_Author_Presets();
        $author->enqueue_scripts( 'profile.php' );
        $data = $GLOBALS['LOCALIZED_SCRIPTS'][ PressHub_AI_Admin_Presets::SCRIPT_HANDLE ]['data'] ?? [];
        if ( ( $data['scope'] ?? '' ) !== 'author' || ( $data['user_id'] ?? null ) !== 7 ) {
            $failures[] = 'profile.php should localize scope=author + current user id; got: ' . json_encode( $data );
        }

        self::reset();
        $_GET['user_id'] = '5';
        $author->enqueue_scripts( 'user-edit.php' );
        $data = $GLOBALS['LOCALIZED_SCRIPTS'][ PressHub_AI_Admin_Presets::SCRIPT_HANDLE ]['data'] ?? [];
        if ( ( $data['scope'] ?? '' ) !== 'author' || ( $data['user_id'] ?? null ) !== 5 ) {
            $failures[] = 'user-edit.php should localize the viewed user id; got: ' . json_encode( $data );
        }
        unset( $_GET['user_id'] );

        self::reset();
        $author->enqueue_scripts( 'dashboard.php' );
        if ( ! empty( $GLOBALS['ENQUEUED_SCRIPTS'] ) ) {
            $failures[] = 'Author presets must not enqueue outside profile screens.';
        }

        // --- Case 4: admin page render — markup intact, no inline script ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['OPTIONS_STORE'][ PressHub_AI_Preset_Store::OPTION_DEFAULT_PRESETS ] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
        ];
        $admin = new PressHub_AI_Admin_Presets();
        ob_start();
        $admin->render_page();
        $html = ob_get_clean();
        if ( false === strpos( $html, 'id="presshub-ai-presets-table"' ) ) {
            $failures[] = 'Admin presets page should render the presets table.';
        }
        if ( false !== strpos( $html, '<script' ) ) {
            $failures[] = 'Admin presets page must not contain inline <script> blocks.';
        }
        if ( false !== strpos( $html, 'presshubAI.ajax_url' ) ) {
            $failures[] = 'Admin presets page must not inline the JS payload.';
        }

        // --- Case 5: author section render — markup intact, no inline script ---
        self::reset();
        $GLOBALS['CURRENT_USER_ID'] = 9;
        $GLOBALS['OPTIONS_STORE'][ PressHub_AI_Preset_Store::OPTION_DEFAULT_PRESETS ] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
        ];
        $author = new PressHub_AI_Author_Presets();
        ob_start();
        $author->render_presets_section( (object) [ 'ID' => 9 ] );
        $html = ob_get_clean();
        if ( false === strpos( $html, 'id="presshub-ai-author-presets"' ) || false === strpos( $html, 'data-can-edit="1"' ) ) {
            $failures[] = 'Author section should render with the editable state; got: ' . substr( $html, 0, 300 );
        }
        if ( false !== strpos( $html, '<script' ) ) {
            $failures[] = 'Author section must not contain inline <script> blocks.';
        }
        // Read-only view of another user's profile still renders the section.
        ob_start();
        $author->render_presets_section( (object) [ 'ID' => 8 ] );
        $html = ob_get_clean();
        if ( false === strpos( $html, 'data-can-edit="0"' ) ) {
            $failures[] = 'Viewing another profile should render the section read-only.';
        }

        // --- Case 6: the shared excerpt helper (A-5 dedup) truncates long
        //             preset text in both rendered pages ---
        $long_text = 'Start with the most important information. Then add context and background, keeping sentences short. Finally, end with next steps and a call to action.';
        if ( strlen( $long_text ) <= 120 ) {
            $failures[] = 'Test fixture should exceed the 120-char excerpt length.';
        }
        $excerpt = PressHub_AI_Preset_UI::excerpt( $long_text, 120 );
        if ( $excerpt !== substr( $long_text, 0, 120 ) . '…' ) {
            $failures[] = 'PressHub_AI_Preset_UI::excerpt() should truncate with an ellipsis; got: ' . var_export( $excerpt, true );
        }
        if ( PressHub_AI_Preset_UI::excerpt( 'Short text' ) !== 'Short text' ) {
            $failures[] = 'PressHub_AI_Preset_UI::excerpt() should return short text untouched.';
        }
        if ( PressHub_AI_Preset_UI::excerpt( '  padded  ' ) !== 'padded' ) {
            $failures[] = 'PressHub_AI_Preset_UI::excerpt() should trim input.';
        }

        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['OPTIONS_STORE'][ PressHub_AI_Preset_Store::OPTION_DEFAULT_PRESETS ] = [
            [ 'slug' => 'long-style', 'name' => 'Long', 'instruction_text' => $long_text, 'enabled' => true ],
        ];
        $admin = new PressHub_AI_Admin_Presets();
        ob_start();
        $admin->render_page();
        $html = ob_get_clean();
        if ( false === strpos( $html, substr( $long_text, 0, 120 ) . '…' ) ) {
            $failures[] = 'Admin presets page should render the truncated excerpt of a long preset.';
        }
        // The full text legitimately lives in data-text + the hidden edit
        // textarea, so scope the tail check to the excerpt cell itself.
        if ( ! preg_match( '/class="presshub-preset-text">([^<]*)</', $html, $m ) || $m[1] !== substr( $long_text, 0, 120 ) . '…' ) {
            $failures[] = 'Admin presets excerpt cell must contain only the truncated excerpt; got: ' . var_export( $m[1] ?? null, true );
        }

        self::reset();
        $GLOBALS['CURRENT_USER_ID'] = 9;
        $GLOBALS['USER_META_STORE'][9]['presshub_ai_author_presets'] = [
            [ 'slug' => 'long-style', 'name' => 'Long', 'instruction_text' => $long_text, 'enabled' => true ],
        ];
        $author = new PressHub_AI_Author_Presets();
        ob_start();
        $author->render_presets_section( (object) [ 'ID' => 9 ] );
        $html = ob_get_clean();
        if ( false === strpos( $html, substr( $long_text, 0, 120 ) . '…' ) ) {
            $failures[] = 'Author section should render the truncated excerpt of a long preset.';
        }
        if ( ! preg_match( '/class="presshub-preset-text">([^<]*)</', $html, $m ) || $m[1] !== substr( $long_text, 0, 120 ) . '…' ) {
            $failures[] = 'Author presets excerpt cell must contain only the truncated excerpt; got: ' . var_export( $m[1] ?? null, true );
        }

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
        unset( $GLOBALS['ENQUEUED_SCRIPTS'], $GLOBALS['LOCALIZED_SCRIPTS'] );
        $GLOBALS['ENQUEUED_SCRIPTS']  = [];
        $GLOBALS['LOCALIZED_SCRIPTS'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $GLOBALS['CURRENT_USER_ID']   = 0;
        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['USER_META_STORE']   = [];
    }
}

PresetsAssetsTest::run();
