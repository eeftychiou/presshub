<?php
/**
 * The save bar of PressHub → Settings (#16, reworked for #246).
 *
 * Since #246 each section is its own page with one Save, in a bar that
 * sticks to the bottom of the section (position: sticky, not fixed, so it
 * never covers the footer or the field being edited).
 *
 * Verifies:
 * 1. Each section prints one save bar whose submit button names the section,
 *    with a spinner, an "N unsaved changes" line and a save status line, both
 *    role="status".
 * 2. The bar is a labelled region.
 * 3. settings.css keeps it sticky at the bottom with room to scroll the
 *    focused field above it (scroll-padding-bottom), and 44px touch targets.
 * 4. The scripts: admin.js saves the section over AJAX; settings-page.js
 *    counts unsaved changes and scrolls a focused field clear of the bar.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/settings-sections-helper.php';

class SettingsStickySaveBarTest
{
    public static function run(): void {
        $failures = [];

        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_others_posts' ];

        foreach ( PressHub_Settings_Sections::slugs() as $slug ) {
            $html  = presshub_test_render_section( $slug );
            $start = strpos( $html, 'id="presshub-sticky-save-bar"' );
            if ( false === $start || 1 !== substr_count( $html, 'id="presshub-sticky-save-bar"' ) ) {
                $failures[] = "{$slug}: expected exactly one save bar.";
                continue;
            }
            $bar = substr( $html, $start, (int) strpos( $html, '</form>', $start ) - $start );

            // Case 1: contents.
            if ( false === strpos( $bar, 'data-active-tab="' . $slug . '"' ) ) {
                $failures[] = "{$slug}: the save bar should name its section in data-active-tab.";
            }
            if ( ! preg_match( '/<button type="submit" id="presshub-sticky-save-btn"[^>]*>\s*Save ' . preg_quote( esc_html( PressHub_Settings_Sections::label( $slug ) ), '/' ) . '\s*<\/button>/', $bar ) ) {
                $failures[] = "{$slug}: the Save button should be a submit button that says \"Save <section>\".";
            }
            if ( false === strpos( $bar, 'presshub-sticky-save-spinner' ) ) {
                $failures[] = "{$slug}: the save bar is missing its spinner.";
            }
            if ( ! preg_match( '/id="presshub-sticky-unsaved-badge"[^>]*role="status"[^>]*aria-live="polite"[^>]*hidden/', $bar ) ) {
                $failures[] = "{$slug}: the unsaved-changes line should be a hidden role=\"status\" live region.";
            }
            if ( ! preg_match( '/id="presshub-sticky-status-msg"[^>]*role="status"[^>]*aria-live="polite"/', $bar ) ) {
                $failures[] = "{$slug}: the save status line should be a role=\"status\" live region.";
            }

            // Case 2: a labelled region.
            if ( ! preg_match( '/id="presshub-sticky-save-bar"[^>]*role="region"[^>]*aria-label="[^"]+"/', $html ) ) {
                $failures[] = "{$slug}: the save bar should be a labelled region.";
            }

            // The old per-tab and fallback buttons are gone.
            if ( false !== strpos( $html, 'presshub-tab-save-btn' ) || false !== strpos( $html, 'presshub-settings-submit-wrap' ) ) {
                $failures[] = "{$slug}: the old per-tab Save buttons must not render.";
            }
        }

        // Case 3: CSS.
        $css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/settings.css' );
        if ( ! preg_match( '/\.presshub-settings-savebar \{[^}]*position: sticky;[^}]*bottom: 0;[^}]*z-index:/s', $css ) ) {
            $failures[] = 'settings.css should make the save bar sticky at the bottom with a z-index.';
        }
        if ( false === strpos( $css, 'scroll-padding-bottom' ) ) {
            $failures[] = 'settings.css should keep scroll-padding-bottom so anchors and focus land above the save bar.';
        }
        if ( false === strpos( $css, 'min-height: 44px' ) ) {
            $failures[] = 'settings.css should keep 44px touch targets on narrow screens.';
        }

        // Case 4: scripts.
        $admin = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
        if ( false === strpos( $admin, 'presshub_ai_save_settings_section' ) ) {
            $failures[] = "admin.js should save the section with 'presshub_ai_save_settings_section'.";
        }
        if ( false === strpos( $admin, 'presshub-sticky-save-btn' ) || false === strpos( $admin, 'presshub-sticky-status-msg' ) ) {
            $failures[] = 'admin.js should drive the save button and the status line.';
        }
        $page = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/settings-page.js' );
        if ( false === strpos( $page, '#presshub-sticky-unsaved-badge' ) ) {
            $failures[] = 'settings-page.js should update the unsaved-changes line.';
        }
        if ( false === strpos( $page, "addEventListener('focusin'" ) || false === strpos( $page, 'presshub-sticky-save-bar' ) ) {
            $failures[] = 'settings-page.js should scroll a focused field clear of the save bar.';
        }
        if ( preg_match( '/\balert\(/', $page ) ) {
            $failures[] = 'settings-page.js must not use alert().';
        }

        if ( ! empty( $failures ) ) {
            echo "FAIL\n";
            foreach ( $failures as $failure ) {
                echo "  - {$failure}\n";
            }
            exit( 1 );
        }

        echo "OK\n";
        exit( 0 );
    }
}

SettingsStickySaveBarTest::run();
