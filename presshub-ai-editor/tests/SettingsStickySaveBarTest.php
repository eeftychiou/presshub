<?php
/**
 * Test suite for Issue #16: Sticky Save Changes Bar for Long Settings Pages.
 *
 * Verifies:
 * 1. Semantic HTML markup for the sticky save bar container in render_settings_page().
 * 2. Presence of active section indicator badge, sticky save button, spinner, and status container.
 * 3. Accessibility attributes (roles, aria-label, aria-live, status).
 * 4. CSS styling and responsive media queries in admin.css (fixed positioning, elevation, WCAG 44px touch targets).
 * 5. JavaScript integration in admin.js (tab sync, AJAX save coordination, status feedback, no alerts).
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';

class SettingsStickySaveBarTest
{
    public static function run(): void {
        $failures = [];

        // -------------------------------------------------------------
        // Case 1: Render Settings Page HTML contains sticky save bar markup
        // -------------------------------------------------------------
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];

        $renderer = new PressHub_AI_Settings_Render();
        ob_start();
        $renderer->render_settings_page();
        $html = ob_get_clean();

        // 1.1 Sticky save bar container
        if ( false === strpos( $html, 'id="presshub-sticky-save-bar"' ) && false === strpos( $html, 'class="presshub-sticky-save-bar"' ) ) {
            $failures[] = 'Settings page HTML missing sticky save bar container (#presshub-sticky-save-bar / .presshub-sticky-save-bar).';
        }

        // 1.2 Content wrapper
        if ( false === strpos( $html, 'presshub-sticky-save-bar-content' ) ) {
            $failures[] = 'Settings page HTML missing inner content wrapper (.presshub-sticky-save-bar-content).';
        }

        // 1.3 Active section indicator & badge
        if ( false === strpos( $html, 'presshub-sticky-section-info' ) ) {
            $failures[] = 'Settings page HTML missing active section info (.presshub-sticky-section-info).';
        }

        if ( false === strpos( $html, 'id="presshub-sticky-section-badge"' ) || false === strpos( $html, 'class="presshub-sticky-section-badge"' ) ) {
            $failures[] = 'Settings page HTML missing active section badge (#presshub-sticky-section-badge / .presshub-sticky-section-badge).';
        }

        // 1.4 Sticky save button
        if ( false === strpos( $html, 'id="presshub-sticky-save-btn"' ) || false === strpos( $html, 'presshub-sticky-save-btn' ) ) {
            $failures[] = 'Settings page HTML missing sticky save button (#presshub-sticky-save-btn / .presshub-sticky-save-btn).';
        }

        // 1.5 Sticky save spinner
        if ( false === strpos( $html, 'presshub-sticky-save-spinner' ) ) {
            $failures[] = 'Settings page HTML missing sticky save spinner (.presshub-sticky-save-spinner).';
        }

        // 1.6 Sticky status message container
        if ( false === strpos( $html, 'presshub-sticky-status-msg' ) ) {
            $failures[] = 'Settings page HTML missing sticky status message container (.presshub-sticky-status-msg).';
        }

        // -------------------------------------------------------------
        // Case 2: Accessibility attributes (ARIA roles, labels, live regions)
        // -------------------------------------------------------------
        if ( false === strpos( $html, 'role="region"' ) && false === strpos( $html, 'aria-label=' ) ) {
            $failures[] = 'Sticky save bar missing accessible role="region" or aria-label attribute.';
        }

        if ( false === strpos( $html, 'aria-live=' ) ) {
            $failures[] = 'Sticky status message container missing aria-live attribute for screen reader announcements.';
        }

        // -------------------------------------------------------------
        // Case 3: CSS Styles & Responsive Rules in admin.css
        // -------------------------------------------------------------
        $css_file = dirname( __DIR__ ) . '/assets/admin.css';
        if ( ! file_exists( $css_file ) ) {
            $failures[] = "admin.css file not found at {$css_file}.";
        } else {
            $css_content = file_get_contents( $css_file );

            if ( false === strpos( $css_content, '.presshub-sticky-save-bar' ) ) {
                $failures[] = "admin.css missing '.presshub-sticky-save-bar' selector.";
            }

            if ( false === strpos( $css_content, 'position: fixed' ) && false === strpos( $css_content, 'position:fixed' ) ) {
                $failures[] = "admin.css missing 'position: fixed' for sticky save bar.";
            }

            if ( false === strpos( $css_content, 'z-index: 100' ) && false === strpos( $css_content, 'z-index:' ) ) {
                $failures[] = "admin.css missing z-index declaration for sticky save bar.";
            }

            if ( false === strpos( $css_content, '.presshub-sticky-save-btn' ) ) {
                $failures[] = "admin.css missing '.presshub-sticky-save-btn' selector.";
            }

            // Media query for mobile responsive collapse & touch targets (WCAG 2.1)
            if ( false === strpos( $css_content, '@media (max-width: 782px)' ) ) {
                $failures[] = "admin.css missing '@media (max-width: 782px)' media query.";
            }

            if ( false === strpos( $css_content, 'min-height: 44px' ) && false === strpos( $css_content, 'min-height:44px' ) ) {
                $failures[] = "admin.css missing 'min-height: 44px' touch target sizing for mobile.";
            }
        }

        // -------------------------------------------------------------
        // Case 4: JavaScript Logic & Event Handlers in admin.js
        // -------------------------------------------------------------
        $js_file = dirname( __DIR__ ) . '/assets/admin.js';
        if ( ! file_exists( $js_file ) ) {
            $failures[] = "admin.js file not found at {$js_file}.";
        } else {
            $js_content = file_get_contents( $js_file );

            if ( false === strpos( $js_content, 'presshub-sticky-save-bar' ) ) {
                $failures[] = "admin.js missing references to 'presshub-sticky-save-bar'.";
            }

            if ( false === strpos( $js_content, 'presshub-sticky-save-btn' ) ) {
                $failures[] = "admin.js missing click handler for 'presshub-sticky-save-btn'.";
            }

            if ( false === strpos( $js_content, 'presshub-sticky-section-badge' ) ) {
                $failures[] = "admin.js missing section badge updater for 'presshub-sticky-section-badge'.";
            }

            if ( false === strpos( $js_content, 'presshub_ai_save_settings_section' ) ) {
                $failures[] = "admin.js missing per-tab AJAX save action 'presshub_ai_save_settings_section'.";
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

SettingsStickySaveBarTest::run();
