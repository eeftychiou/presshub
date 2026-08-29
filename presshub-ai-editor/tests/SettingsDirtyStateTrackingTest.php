<?php
/**
 * Test suite for Issue #17: Dirty-State Tracking and Unsaved-Changes Warning.
 *
 * Verifies:
 * 1. Semantic HTML markup for dirty state indicators and provider modal discard notices in class-settings-render.php.
 * 2. CSS styles for tab dirty indicators (.nav-tab.is-dirty), mobile select, sticky save bar badges, and discard notices in admin.css.
 * 3. JavaScript implementation in admin.js (baseline snapshots, change tracking, sticky bar sync, beforeunload handler, modal discard protection, no alerts).
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';

class SettingsDirtyStateTrackingTest
{
    public static function run(): void {
        $failures = [];

        // -------------------------------------------------------------
        // Case 1: Render Settings Page HTML contains dirty state elements
        // -------------------------------------------------------------
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];

        $renderer = new PressHub_AI_Settings_Render();
        ob_start();
        $renderer->render_settings_page();
        $html = ob_get_clean();

        // 1.1 Sticky save bar unsaved badge element
        if ( false === strpos( $html, 'id="presshub-sticky-unsaved-badge"' ) && false === strpos( $html, 'class="presshub-sticky-unsaved-badge"' ) ) {
            $failures[] = 'Settings page HTML missing sticky save bar unsaved badge element (#presshub-sticky-unsaved-badge / .presshub-sticky-unsaved-badge).';
        }

        // 1.2 Provider modal discard confirmation notice
        if ( false === strpos( $html, 'id="presshub-provider-discard-notice"' ) && false === strpos( $html, 'class="presshub-provider-discard-notice"' ) ) {
            $failures[] = 'Provider modal HTML missing discard confirmation notice (#presshub-provider-discard-notice / .presshub-provider-discard-notice).';
        }

        // 1.3 Provider modal discard action buttons
        if ( false === strpos( $html, 'presshub-provider-discard-confirm-btn' ) ) {
            $failures[] = 'Provider modal HTML missing discard confirm button (.presshub-provider-discard-confirm-btn).';
        }

        if ( false === strpos( $html, 'presshub-provider-discard-cancel-btn' ) ) {
            $failures[] = 'Provider modal HTML missing discard cancel / keep editing button (.presshub-provider-discard-cancel-btn).';
        }

        // -------------------------------------------------------------
        // Case 2: CSS Styles & Dirty Indicator Rules in admin.css
        // -------------------------------------------------------------
        $css_file = dirname( __DIR__ ) . '/assets/admin.css';
        if ( ! file_exists( $css_file ) ) {
            $failures[] = "admin.css file not found at {$css_file}.";
        } else {
            $css_content = file_get_contents( $css_file );

            // 2.1 Desktop tab dirty state selector & dot indicator
            if ( false === strpos( $css_content, '.nav-tab.is-dirty' ) ) {
                $failures[] = "admin.css missing '.nav-tab.is-dirty' selector.";
            }

            if ( false === strpos( $css_content, '.nav-tab.is-dirty::after' ) ) {
                $failures[] = "admin.css missing '.nav-tab.is-dirty::after' pseudo-element indicator.";
            }

            // 2.2 Mobile select dirty state
            if ( false === strpos( $css_content, '.presshub-mobile-tab-select.is-dirty' ) ) {
                $failures[] = "admin.css missing '.presshub-mobile-tab-select.is-dirty' selector.";
            }

            // 2.3 Sticky save bar dirty badge and state
            if ( false === strpos( $css_content, '.presshub-sticky-unsaved-badge' ) ) {
                $failures[] = "admin.css missing '.presshub-sticky-unsaved-badge' selector.";
            }

            if ( false === strpos( $css_content, '.presshub-sticky-save-bar.has-unsaved' ) ) {
                $failures[] = "admin.css missing '.presshub-sticky-save-bar.has-unsaved' selector.";
            }

            // 2.4 Provider discard notice styling
            if ( false === strpos( $css_content, '.presshub-provider-discard-notice' ) ) {
                $failures[] = "admin.css missing '.presshub-provider-discard-notice' selector.";
            }
        }

        // -------------------------------------------------------------
        // Case 3: JavaScript Implementation in admin.js
        // -------------------------------------------------------------
        $js_file = dirname( __DIR__ ) . '/assets/admin.js';
        if ( ! file_exists( $js_file ) ) {
            $failures[] = "admin.js file not found at {$js_file}.";
        } else {
            $js_content = file_get_contents( $js_file );

            // 3.1 Tab form snapshot & baseline functions
            if ( false === strpos( $js_content, 'function getTabFormSnapshot' ) ) {
                $failures[] = "admin.js missing 'getTabFormSnapshot' function.";
            }

            if ( false === strpos( $js_content, 'function initTabBaselines' ) ) {
                $failures[] = "admin.js missing 'initTabBaselines' function.";
            }

            if ( false === strpos( $js_content, 'function isTabDirty' ) ) {
                $failures[] = "admin.js missing 'isTabDirty' function.";
            }

            if ( false === strpos( $js_content, 'function isAnyTabDirty' ) ) {
                $failures[] = "admin.js missing 'isAnyTabDirty' function.";
            }

            if ( false === strpos( $js_content, 'function updateTabDirtyState' ) ) {
                $failures[] = "admin.js missing 'updateTabDirtyState' function.";
            }

            if ( false === strpos( $js_content, 'function updateStickySaveBarDirtyState' ) ) {
                $failures[] = "admin.js missing 'updateStickySaveBarDirtyState' function.";
            }

            // 3.2 Provider modal snapshot & discard protection
            if ( false === strpos( $js_content, 'function getProviderModalSnapshot' ) ) {
                $failures[] = "admin.js missing 'getProviderModalSnapshot' function.";
            }

            if ( false === strpos( $js_content, 'function isProviderModalDirty' ) ) {
                $failures[] = "admin.js missing 'isProviderModalDirty' function.";
            }

            if ( false === strpos( $js_content, 'function tryCloseProviderModal' ) ) {
                $failures[] = "admin.js missing 'tryCloseProviderModal' function.";
            }

            // 3.3 beforeunload event registration
            if ( false === strpos( $js_content, 'beforeunload' ) ) {
                $failures[] = "admin.js missing 'beforeunload' event handler registration.";
            }

            // 3.4 Live input, change, and keyup event binding
            if ( false === strpos( $js_content, 'input change keyup' ) ) {
                $failures[] = "admin.js missing 'input change keyup' form event listener.";
            }

            // 3.5 Discard confirm and cancel event bindings
            if ( false === strpos( $js_content, 'presshub-provider-discard-confirm-btn' ) ) {
                $failures[] = "admin.js missing event handler for 'presshub-provider-discard-confirm-btn'.";
            }

            if ( false === strpos( $js_content, 'presshub-provider-discard-cancel-btn' ) ) {
                $failures[] = "admin.js missing event handler for 'presshub-provider-discard-cancel-btn'.";
            }
        }

        // -------------------------------------------------------------
        // Report results
        // -------------------------------------------------------------
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

SettingsDirtyStateTrackingTest::run();
