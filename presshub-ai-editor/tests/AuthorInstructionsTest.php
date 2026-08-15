<?php
/**
 * End-to-end AJAX contract tests for the 5 per-author instruction preset
 * handlers (2026-08-15 design doc §6.1 / §7.4):
 *
 *   presshub_ai_list_presets          (read-only, edit_posts)
 *   presshub_ai_save_preset           (edit_posts own / manage_options plugin)
 *   presshub_ai_delete_preset         (soft for author, hard for plugin)
 *   presshub_ai_set_default_preset    (edit_posts)
 *   presshub_ai_copy_default_preset   (edit_posts)
 *
 * Every handler: nonce check, capability gate, slug regex, length limits,
 * per-scope quota (25 author / 50 plugin), and the coarse preset throttle
 * (max 60 mutations/min/user via PressHub_AI_Rate_Limiter, dedicated
 * 'presshub_ai_preset_<uid>' bucket — never the AI-call bucket).
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

class AuthorInstructionsTest
{
    public static function run(): void {
        $failures = [];

        // ==================================================================
        // presshub_ai_list_presets
        // ==================================================================

        // --- nonce failure ---
        self::reset();
        $GLOBALS['NONCE_VALID'] = false;
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->list_presets();
        } );
        if ( false === strpos( $msg, 'check_ajax_referer' ) ) {
            $failures[] = "list_presets nonce failure: got {$msg}";
        }

        // --- capability failure ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->list_presets();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "list_presets cap failure: got {$msg}";
        }

        // --- happy path shape: own + enabled defaults minus disabled + default_slug ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
            [ 'slug' => 'old-one', 'name' => 'Old', 'instruction_text' => 'Retired.', 'enabled' => false ],
        ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
            [ 'slug' => 'fact-check', 'name' => 'Fact check', 'instruction_text' => 'Cite sources.', 'enabled' => true ],
            [ 'slug' => 'ghost', 'name' => 'Ghost', 'instruction_text' => 'Disabled plugin default.', 'enabled' => false ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_disabled_default_presets'] = [ 'fact-check' ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'punchy';
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->list_presets();
        } );
        if ( ! is_array( $data ) || count( $data['own'] ?? [] ) !== 2 ) {
            $failures[] = 'list_presets happy path: own should contain both author presets (incl. disabled). Got: ' . json_encode( $data );
        }
        if ( ! is_array( $data ) || count( $data['defaults'] ?? [] ) !== 1 || ( $data['defaults'][0]['slug'] ?? '' ) !== 'wire-style' ) {
            $failures[] = 'list_presets happy path: defaults should be enabled plugin presets minus the author\'s disabled list. Got: ' . json_encode( $data['defaults'] ?? null );
        }
        if ( ! is_array( $data ) || ( $data['default_slug'] ?? '' ) !== 'punchy' ) {
            $failures[] = 'list_presets happy path: default_slug mismatch. Got: ' . json_encode( $data );
        }

        // ==================================================================
        // presshub_ai_save_preset
        // ==================================================================

        // --- nonce failure ---
        self::reset();
        $GLOBALS['NONCE_VALID'] = false;
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, 'check_ajax_referer' ) ) {
            $failures[] = "save_preset nonce failure: got {$msg}";
        }

        // --- capability failure (no edit_posts) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy', 'name' => 'P', 'instruction_text' => 'T' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "save_preset cap failure: got {$msg}";
        }

        // --- author cannot write plugin scope ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST = [ 'scope' => 'plugin', 'slug' => 'wire-style', 'name' => 'W', 'instruction_text' => 'T' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "save_preset plugin scope by non-admin: got {$msg}";
        }

        // --- slug regex rejection ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'scope' => 'author', 'slug' => 'Bad_Slug', 'name' => 'Bad', 'instruction_text' => 'T' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, 'slug' ) ) {
            $failures[] = "save_preset slug rejection: got {$msg}";
        }

        // --- over-length name rejection ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy', 'name' => str_repeat( 'n', 81 ), 'instruction_text' => 'T' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, '80' ) ) {
            $failures[] = "save_preset name length rejection: got {$msg}";
        }

        // --- over-length instruction_text rejection ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy', 'name' => 'P', 'instruction_text' => str_repeat( 't', 4001 ) ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, '4,000' ) ) {
            $failures[] = "save_preset instruction length rejection: got {$msg}";
        }

        // --- happy path (author scope): sanitized record stored in user meta ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [
            'scope'            => 'author',
            'slug'             => 'punchy',
            'name'             => 'Punchy',
            'instruction_text' => 'Be punchy.',
            'enabled'          => '1',
        ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ?? null;
        if ( ! is_array( $data ) || ( $data['slug'] ?? '' ) !== 'punchy' || ( $data['enabled'] ?? null ) !== true ) {
            $failures[] = 'save_preset happy path: response should be the sanitized record. Got: ' . json_encode( $data );
        }
        if ( ! is_array( $stored ) || count( $stored ) !== 1 || $stored[0]['slug'] !== 'punchy' || $stored[0]['instruction_text'] !== 'Be punchy.' ) {
            $failures[] = 'save_preset happy path: user meta not updated. Got: ' . json_encode( $stored );
        }
        if ( isset( $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] ) ) {
            $failures[] = 'save_preset author scope must not touch plugin defaults.';
        }

        // --- update in place (no duplicate rows) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
            [ 'slug' => 'other', 'name' => 'Other', 'instruction_text' => 'Keep.', 'enabled' => true ],
        ];
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy', 'name' => 'Punchy v2', 'instruction_text' => 'New text.', 'enabled' => '0' ];
        self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'];
        if ( count( $stored ) !== 2 ) {
            $failures[] = 'save_preset update should keep one row per slug. Got: ' . json_encode( $stored );
        }
        if ( $stored[0]['name'] !== 'Punchy v2' || $stored[0]['instruction_text'] !== 'New text.' || $stored[0]['enabled'] !== false ) {
            $failures[] = 'save_preset update should replace the row in place. Got: ' . json_encode( $stored[0] );
        }

        // --- author quota (25 max) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $rows = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $rows[] = [ 'slug' => "slug-{$i}", 'name' => "N{$i}", 'instruction_text' => 'T', 'enabled' => true ];
        }
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = $rows;
        $_POST = [ 'scope' => 'author', 'slug' => 'overflow', 'name' => 'Over', 'instruction_text' => 'T' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, '25' ) ) {
            $failures[] = "save_preset author quota: got {$msg}";
        }

        // --- plugin quota (50 max) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $rows = [];
        for ( $i = 0; $i < 50; $i++ ) {
            $rows[] = [ 'slug' => "slug-{$i}", 'name' => "N{$i}", 'instruction_text' => 'T', 'enabled' => true ];
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = $rows;
        $_POST = [ 'scope' => 'plugin', 'slug' => 'overflow', 'name' => 'Over', 'instruction_text' => 'T' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, '50' ) ) {
            $failures[] = "save_preset plugin quota: got {$msg}";
        }

        // --- happy path (plugin scope, admin): option updated, user meta untouched ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'scope' => 'plugin', 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        $stored = $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] ?? null;
        if ( ! is_array( $stored ) || count( $stored ) !== 1 || $stored[0]['slug'] !== 'wire-style' ) {
            $failures[] = 'save_preset plugin scope: option not updated. Got: ' . json_encode( $stored );
        }
        if ( isset( $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ) ) {
            $failures[] = 'save_preset plugin scope must not touch user meta.';
        }

        // --- throttle: saturated preset bucket blocks the mutation ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_rate_limit_enabled'] = 1;
        $GLOBALS['TRANSIENT_STORE']['presshub_ai_preset_7'] = [
            'value'      => [ 'count' => 60, 'expires_at' => time() + 60, 'window' => 60 ],
            'expires_at' => time() + 60,
        ];
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy', 'name' => 'P', 'instruction_text' => 'T' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->save_preset();
        } );
        if ( false === strpos( $msg, 'Rate limit' ) ) {
            $failures[] = "save_preset throttle: got {$msg}";
        }
        if ( isset( $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ) ) {
            $failures[] = 'save_preset throttle: mutation must not happen when blocked.';
        }

        // ==================================================================
        // presshub_ai_delete_preset
        // ==================================================================

        // --- nonce failure ---
        self::reset();
        $GLOBALS['NONCE_VALID'] = false;
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->delete_preset();
        } );
        if ( false === strpos( $msg, 'check_ajax_referer' ) ) {
            $failures[] = "delete_preset nonce failure: got {$msg}";
        }

        // --- capability failure ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->delete_preset();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "delete_preset cap failure: got {$msg}";
        }

        // --- author soft delete: enabled=false, row kept ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
        ];
        $_POST = [ 'scope' => 'author', 'slug' => 'punchy' ];
        self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->delete_preset();
        } );
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'];
        if ( count( $stored ) !== 1 || $stored[0]['slug'] !== 'punchy' || $stored[0]['enabled'] !== false ) {
            $failures[] = 'delete_preset author scope should soft-delete (enabled=false, row kept). Got: ' . json_encode( $stored );
        }

        // --- plugin scope by non-admin denied ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $_POST = [ 'scope' => 'plugin', 'slug' => 'wire-style' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->delete_preset();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "delete_preset plugin scope by non-admin: got {$msg}";
        }

        // --- plugin hard delete (admin) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
            [ 'slug' => 'fact-check', 'name' => 'Fact check', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $_POST = [ 'scope' => 'plugin', 'slug' => 'wire-style' ];
        self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->delete_preset();
        } );
        $stored = $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'];
        if ( count( $stored ) !== 1 || $stored[0]['slug'] !== 'fact-check' ) {
            $failures[] = 'delete_preset plugin scope should hard-delete the row. Got: ' . json_encode( $stored );
        }

        // --- slug regex rejection ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'scope' => 'author', 'slug' => 'Bad Slug' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->delete_preset();
        } );
        if ( false === strpos( $msg, 'slug' ) ) {
            $failures[] = "delete_preset slug rejection: got {$msg}";
        }

        // ==================================================================
        // presshub_ai_set_default_preset
        // ==================================================================

        // --- nonce failure ---
        self::reset();
        $GLOBALS['NONCE_VALID'] = false;
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->set_default_preset();
        } );
        if ( false === strpos( $msg, 'check_ajax_referer' ) ) {
            $failures[] = "set_default_preset nonce failure: got {$msg}";
        }

        // --- capability failure ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $_POST = [ 'slug' => 'punchy' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->set_default_preset();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "set_default_preset cap failure: got {$msg}";
        }

        // --- happy path: author preset slug ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'punchy', 'name' => 'Punchy', 'instruction_text' => 'Be punchy.', 'enabled' => true ],
        ];
        $_POST = [ 'slug' => 'punchy' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->set_default_preset();
        } );
        if ( ! is_array( $data ) || ( $data['default_slug'] ?? '' ) !== 'punchy' ) {
            $failures[] = 'set_default_preset happy path: response mismatch. Got: ' . json_encode( $data );
        }
        if ( ( $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] ?? '' ) !== 'punchy' ) {
            $failures[] = 'set_default_preset should persist the author default slug.';
        }

        // --- happy path: enabled plugin default (not disabled by author) ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $_POST = [ 'slug' => 'wire-style' ];
        self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->set_default_preset();
        } );
        if ( ( $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] ?? '' ) !== 'wire-style' ) {
            $failures[] = 'set_default_preset should accept an enabled plugin-default slug.';
        }

        // --- unknown slug rejected ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'slug' => 'no-such-preset' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->set_default_preset();
        } );
        if ( false === strpos( $msg, 'not found' ) ) {
            $failures[] = "set_default_preset unknown slug: got {$msg}";
        }

        // --- disabled preset rejected ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'old-one', 'name' => 'Old', 'instruction_text' => 'Retired.', 'enabled' => false ],
        ];
        $_POST = [ 'slug' => 'old-one' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->set_default_preset();
        } );
        if ( false === strpos( $msg, 'not found' ) ) {
            $failures[] = "set_default_preset disabled slug: got {$msg}";
        }

        // --- empty slug clears the default ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'punchy';
        $_POST = [ 'slug' => '' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->set_default_preset();
        } );
        if ( ! is_array( $data ) || ( $data['default_slug'] ?? 'x' ) !== '' ) {
            $failures[] = 'set_default_preset should allow clearing with an empty slug. Got: ' . json_encode( $data );
        }
        if ( ( $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] ?? 'x' ) !== '' ) {
            $failures[] = 'set_default_preset empty slug should clear the stored default.';
        }

        // ==================================================================
        // presshub_ai_copy_default_preset
        // ==================================================================

        // --- nonce failure ---
        self::reset();
        $GLOBALS['NONCE_VALID'] = false;
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->copy_default_preset();
        } );
        if ( false === strpos( $msg, 'check_ajax_referer' ) ) {
            $failures[] = "copy_default_preset nonce failure: got {$msg}";
        }

        // --- capability failure ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $_POST = [ 'slug' => 'wire-style' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->copy_default_preset();
        } );
        if ( false === strpos( $msg, 'Permission denied' ) ) {
            $failures[] = "copy_default_preset cap failure: got {$msg}";
        }

        // --- happy path: plugin default copied into author library ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
        ];
        $_POST = [ 'slug' => 'wire-style' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->copy_default_preset();
        } );
        if ( ! is_array( $data ) || ( $data['slug'] ?? '' ) !== 'wire-style' ) {
            $failures[] = 'copy_default_preset happy path: response slug mismatch. Got: ' . json_encode( $data );
        }
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] ?? null;
        if ( ! is_array( $stored ) || count( $stored ) !== 1 || $stored[0]['slug'] !== 'wire-style' || $stored[0]['instruction_text'] !== 'Inverted pyramid.' || $stored[0]['enabled'] !== true ) {
            $failures[] = 'copy_default_preset should copy the plugin default into the author library. Got: ' . json_encode( $stored );
        }

        // --- collision: author already owns the slug -> '-copy-1' suffix ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'Inverted pyramid.', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Mine', 'instruction_text' => 'My own copy.', 'enabled' => true ],
        ];
        $_POST = [ 'slug' => 'wire-style' ];
        $data = self::drive_success( function () {
            ( new PressHub_AI_Ajax_Handlers() )->copy_default_preset();
        } );
        $stored = $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'];
        $slugs  = array_column( $stored, 'slug' );
        if ( ( $data['slug'] ?? '' ) !== 'wire-style-copy-1' || ! in_array( 'wire-style-copy-1', $slugs, true ) || count( $stored ) !== 2 ) {
            $failures[] = 'copy_default_preset collision should suffix -copy-1. Got: ' . json_encode( $stored ) . ' resp ' . json_encode( $data );
        }

        // --- unknown plugin slug rejected ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'slug' => 'no-such-preset' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->copy_default_preset();
        } );
        if ( false === strpos( $msg, 'not found' ) ) {
            $failures[] = "copy_default_preset unknown slug: got {$msg}";
        }

        // --- disabled plugin default rejected ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'ghost', 'name' => 'Ghost', 'instruction_text' => 'T', 'enabled' => false ],
        ];
        $_POST = [ 'slug' => 'ghost' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->copy_default_preset();
        } );
        if ( false === strpos( $msg, 'not found' ) ) {
            $failures[] = "copy_default_preset disabled plugin default: got {$msg}";
        }

        // --- author quota blocks the copy ---
        self::reset();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'T', 'enabled' => true ],
        ];
        $rows = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $rows[] = [ 'slug' => "slug-{$i}", 'name' => "N{$i}", 'instruction_text' => 'T', 'enabled' => true ];
        }
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = $rows;
        $_POST = [ 'slug' => 'wire-style' ];
        $msg = self::expect_json_error( function () {
            ( new PressHub_AI_Ajax_Handlers() )->copy_default_preset();
        } );
        if ( false === strpos( $msg, '25' ) ) {
            $failures[] = "copy_default_preset author quota: got {$msg}";
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
            // Re-throw as a descriptive failure for the caller to inspect.
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
            $GLOBALS['POST_META_STORE'],
            $GLOBALS['OPTIONS_STORE'],
            $GLOBALS['USER_META_STORE'],
            $GLOBALS['TRANSIENT_STORE'],
            $GLOBALS['CURRENT_USER_CAPS'],
            $GLOBALS['CURRENT_USER_ID'],
            $GLOBALS['CAPTURED_REQUESTS'],
            $GLOBALS['JSON_RESPONSES']
        );
        $GLOBALS['CAPTURE_FILTER'] = null;
        $GLOBALS['NONCE_VALID'] = true;
        $_POST = [];
    }
}

AuthorInstructionsTest::run();
