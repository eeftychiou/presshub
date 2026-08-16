<?php
/**
 * TDD tests for the org-default preset layers (2026-08-15 design doc
 * §9 Q3 / Antigravity A-6):
 *
 *   presshub_ai_save_org_default  (admin-only, manage_options)
 *   presshub_ai_list_presets      (now carries the org maps)
 *   admin presets page render     ('Defaults by category & role' section)
 *
 * The handler is a thin wrapper: nonce, capability gate, scope/key/value
 * validation (slug regex; values must name an ENABLED plugin-default
 * preset, or be '' to clear, or '__none__' to disable), the coarse preset
 * throttle (60 mutations/min/user), then delegation to the Store maps.
 *
 * wp_send_json_* throw RuntimeException in the stub environment; responses
 * are inspected via $GLOBALS['JSON_RESPONSES'].
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-preset-sanitizer.php';
require_once __DIR__ . '/../includes/class-preset-store.php';
require_once __DIR__ . '/../includes/class-preset-resolver.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';
require_once __DIR__ . '/../includes/class-preset-ui.php';
require_once __DIR__ . '/../includes/class-admin-presets.php';

class PresetOrgDefaultsTest
{
    public static function run(): void {
        $failures = [];

        // ==================================================================
        // presshub_ai_save_org_default — auth + validation
        // ==================================================================

        // --- nonce failure ---
        self::reset();
        $GLOBALS['NONCE_VALID'] = false;
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( false === strpos( $msg, 'check_ajax_referer' ) ) {
            $failures[] = "save_org_default nonce failure: got {$msg}";
        }

        // --- capability failure (no manage_options) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST = [ 'scope' => 'taxonomy', 'key' => 'sports', 'value' => 'wire-style' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "save_org_default cap failure: got {$msg}";
        }

        // --- invalid scope ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $_POST = [ 'scope' => 'author', 'key' => 'sports', 'value' => 'wire-style' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( false === strpos( $msg, 'scope' ) ) {
            $failures[] = "save_org_default invalid scope: got {$msg}";
        }

        // --- invalid key (bad slug) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $_POST = [ 'scope' => 'taxonomy', 'key' => 'Bad Key!', 'value' => 'wire-style' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( false === strpos( $msg, 'key' ) ) {
            $failures[] = "save_org_default invalid key: got {$msg}";
        }

        // --- invalid value (bad slug) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $_POST = [ 'scope' => 'role', 'key' => 'editor', 'value' => 'Bad_Slug' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( false === strpos( $msg, 'slug' ) ) {
            $failures[] = "save_org_default invalid value slug: got {$msg}";
        }

        // --- value must name an ENABLED plugin-default preset ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
            [ 'slug' => 'ghost', 'name' => 'Ghost', 'instruction_text' => 'T', 'enabled' => false ],
        ];
        $_POST = [ 'scope' => 'taxonomy', 'key' => 'sports', 'value' => 'no-such-preset' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( false === strpos( $msg, 'not found' ) ) {
            $failures[] = "save_org_default unknown value: got {$msg}";
        }
        $_POST = [ 'scope' => 'taxonomy', 'key' => 'sports', 'value' => 'ghost' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( false === strpos( $msg, 'not found' ) ) {
            $failures[] = "save_org_default disabled value: got {$msg}";
        }
        if ( isset( $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'] ) ) {
            $failures[] = 'save_org_default rejected value must not mutate the map.';
        }

        // ==================================================================
        // presshub_ai_save_org_default — happy paths
        // ==================================================================

        // --- save a taxonomy default ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $_POST = [ 'scope' => 'taxonomy', 'key' => 'sports', 'value' => 'wire-style' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( ! is_array( $data ) || ( $data['scope'] ?? '' ) !== 'taxonomy' || ( $data['key'] ?? '' ) !== 'sports' || ( $data['value'] ?? '' ) !== 'wire-style' ) {
            $failures[] = 'save_org_default taxonomy happy path: response mismatch. Got: ' . json_encode( $data );
        }
        $stored = $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'] ?? null;
        if ( $stored !== [ 'sports' => 'wire-style' ] ) {
            $failures[] = 'save_org_default taxonomy happy path: option not updated. Got: ' . json_encode( $stored );
        }

        // --- save a role default ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'fact-check', 'name' => 'Fact check', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $_POST = [ 'scope' => 'role', 'key' => 'editor', 'value' => 'fact-check' ];
        self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        $stored = $GLOBALS['OPTIONS_STORE']['presshub_ai_role_presets'] ?? null;
        if ( $stored !== [ 'editor' => 'fact-check' ] ) {
            $failures[] = 'save_org_default role happy path: option not updated. Got: ' . json_encode( $stored );
        }

        // --- update replaces the existing assignment in place ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
            [ 'slug' => 'fact-check', 'name' => 'Fact check', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'] = [ 'sports' => 'wire-style', 'politics' => 'fact-check' ];
        $_POST = [ 'scope' => 'taxonomy', 'key' => 'sports', 'value' => 'fact-check' ];
        self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        $stored = $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'];
        if ( $stored !== [ 'sports' => 'fact-check', 'politics' => 'fact-check' ] ) {
            $failures[] = 'save_org_default should update in place. Got: ' . json_encode( $stored );
        }

        // --- '__none__' disables presets at that layer ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'scope' => 'role', 'key' => 'author', 'value' => '__none__' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( ! is_array( $data ) || ( $data['value'] ?? '' ) !== '__none__' ) {
            $failures[] = "save_org_default '__none__' response mismatch. Got: " . json_encode( $data );
        }
        $stored = $GLOBALS['OPTIONS_STORE']['presshub_ai_role_presets'] ?? null;
        if ( $stored !== [ 'author' => '__none__' ] ) {
            $failures[] = "save_org_default should store '__none__'. Got: " . json_encode( $stored );
        }

        // --- '' clears the assignment ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'] = [ 'sports' => 'wire-style', 'politics' => 'fact-check' ];
        $_POST = [ 'scope' => 'taxonomy', 'key' => 'sports', 'value' => '' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( ! is_array( $data ) || ( $data['value'] ?? 'x' ) !== '' ) {
            $failures[] = 'save_org_default clear response mismatch. Got: ' . json_encode( $data );
        }
        $stored = $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'] ?? null;
        if ( $stored !== [ 'politics' => 'fact-check' ] ) {
            $failures[] = 'save_org_default should remove the cleared key. Got: ' . json_encode( $stored );
        }

        // --- throttle: saturated preset bucket blocks the mutation ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $GLOBALS['TRANSIENT_STORE']['presshub_ai_preset_7'] = [
            'value'      => [ 'count' => 60, 'expires_at' => time() + 60, 'window' => 60 ],
            'expires_at' => time() + 60,
        ];
        $_POST = [ 'scope' => 'taxonomy', 'key' => 'sports', 'value' => 'wire-style' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_org_default();
        } );
        if ( false === strpos( $msg, 'Rate limit' ) ) {
            $failures[] = "save_org_default throttle: got {$msg}";
        }
        if ( isset( $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'] ) ) {
            $failures[] = 'save_org_default throttle: mutation must not happen when blocked.';
        }

        // ==================================================================
        // presshub_ai_list_presets — org maps in the response
        // ==================================================================

        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'] = [ 'sports' => 'wire-style' ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_role_presets']     = [ 'editor' => '__none__' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->list_presets();
        } );
        if ( ! is_array( $data ) || ( $data['org']['taxonomy'] ?? null ) !== [ 'sports' => 'wire-style' ] ) {
            $failures[] = 'list_presets should carry the taxonomy org map. Got: ' . json_encode( $data );
        }
        if ( ! is_array( $data ) || ( $data['org']['role'] ?? null ) !== [ 'editor' => '__none__' ] ) {
            $failures[] = 'list_presets should carry the role org map. Got: ' . json_encode( $data );
        }

        // ==================================================================
        // Admin page render — 'Defaults by category & role' section
        // ==================================================================

        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
            [ 'slug' => 'ghost', 'name' => 'Ghost', 'instruction_text' => 'T', 'enabled' => false ],
        ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_taxonomy_presets'] = [ 'sports' => 'wire-style' ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_role_presets']     = [ 'editor' => 'wire-style' ];
        $GLOBALS['TERMS_STORE'] = [
            (object) [ 'term_id' => 3, 'name' => 'Sports', 'slug' => 'sports' ],
            (object) [ 'term_id' => 4, 'name' => 'Politics', 'slug' => 'politics' ],
        ];
        $admin = new PressHub_AI_Admin_Presets();
        ob_start();
        $admin->render_page();
        $html = ob_get_clean();
        if ( false === strpos( $html, 'Defaults by category &amp; role' ) && false === strpos( $html, 'Defaults by category & role' ) ) {
            $failures[] = 'Admin page should render the org defaults section heading.';
        }
        if ( false === strpos( $html, 'id="presshub-ai-org-taxonomy-table"' ) ) {
            $failures[] = 'Admin page should render the category org table.';
        }
        if ( false === strpos( $html, 'id="presshub-ai-org-role-table"' ) ) {
            $failures[] = 'Admin page should render the role org table.';
        }
        if ( false === strpos( $html, 'data-scope="taxonomy" data-key="sports"' ) ) {
            $failures[] = 'Category select should carry data-scope + data-key.';
        }
        if ( false === strpos( $html, 'data-scope="role" data-key="editor"' ) ) {
            $failures[] = 'Role select should carry data-scope + data-key.';
        }
        if ( ! preg_match( '/<option value="wire-style"[^>]*selected="selected"/', $html, $m ) ) {
            $failures[] = 'The current assignment should be marked selected.';
        }
        // Disabled plugin defaults must not appear as options.
        if ( preg_match( '/<option value="ghost"/', $html ) ) {
            $failures[] = 'Disabled plugin defaults must not appear in org selects.';
        }
        if ( false !== strpos( $html, '<script' ) ) {
            $failures[] = 'Admin presets page must not contain inline <script> blocks.';
        }

        // --- render with no terms: graceful empty state ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $admin = new PressHub_AI_Admin_Presets();
        ob_start();
        $admin->render_page();
        $html = ob_get_clean();
        if ( false === strpos( $html, 'No categories found' ) ) {
            $failures[] = 'Admin page should show an empty state when no categories exist.';
        }
        if ( false === strpos( $html, 'id="presshub-ai-org-role-table"' ) ) {
            $failures[] = 'Role table should render even with no categories.';
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

    /**
     * Run a handler expecting wp_send_json_success; returns its data.
     */
    private static function drive_success( callable $fn ): ?array {
        try {
            $fn();
        } catch ( RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
            $last      = end( $responses );
            if ( $last && true === ( $last['success'] ?? null ) ) {
                return $last['data'];
            }
            throw new RuntimeException( 'expected wp_send_json_success, got: ' . $e->getMessage() );
        }
        throw new RuntimeException( 'handler completed without sending a JSON response' );
    }

    /**
     * Run a handler expecting wp_send_json_error; returns its message.
     */
    private static function expect_json_error( callable $fn ): string {
        try {
            $fn();
        } catch ( RuntimeException $e ) {
            return $e->getMessage();
        }
        return '(no error thrown)';
    }

    private static function reset(): void {
        unset(
            $GLOBALS['OPTIONS_STORE'],
            $GLOBALS['USER_META_STORE'],
            $GLOBALS['TRANSIENT_STORE'],
            $GLOBALS['CURRENT_USER_CAPS'],
            $GLOBALS['CURRENT_USER_ID'],
            $GLOBALS['JSON_RESPONSES'],
            $GLOBALS['TERMS_STORE']
        );
        $GLOBALS['NONCE_VALID'] = true;
        $_POST = [];
    }
}

PresetOrgDefaultsTest::run();
