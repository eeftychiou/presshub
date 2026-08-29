<?php
/**
 * Test suite for Issue #13: Responsive Settings Navigation Tabs.
 *
 * Verifies:
 * 1. Semantic HTML markup for responsive tab container & mobile select dropdown.
 * 2. Accessibility attributes (WCAG 2.1 tap targets, roles, aria-selected, aria-controls).
 * 3. Mobile dropdown selector options and values matching the tab panes.
 * 4. Responsive CSS rules in admin.css (flexbox, horizontal scroll, media queries, 44px touch targets).
 * 5. JavaScript synchronization in admin.js between nav tabs, mobile select, and URL hash.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';

class SettingsResponsiveTabsTest
{
    public static function run(): void {
        $failures = [];

        // -------------------------------------------------------------
        // Case 1: Render Settings Page output contains responsive navigation wrapper
        // -------------------------------------------------------------
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        
        $settings_render = new PressHub_AI_Settings_Render();
        ob_start();
        $settings_render->render_settings_page();
        $html = ob_get_clean();

        if ( false === strpos( $html, 'presshub-settings-nav-container' ) ) {
            $failures[] = "Settings page HTML missing responsive nav container (.presshub-settings-nav-container).";
        }

        if ( false === strpos( $html, 'presshub-mobile-tab-select-wrap' ) ) {
            $failures[] = "Settings page HTML missing mobile tab select wrap (.presshub-mobile-tab-select-wrap).";
        }

        if ( false === strpos( $html, 'id="presshub-mobile-tab-select"' ) || false === strpos( $html, 'class="presshub-mobile-tab-select"' ) ) {
            $failures[] = "Settings page HTML missing mobile select dropdown (#presshub-mobile-tab-select / .presshub-mobile-tab-select).";
        }

        // -------------------------------------------------------------
        // Case 2: Mobile Select contains all 6 navigation tabs
        // -------------------------------------------------------------
        $expected_tabs = [
            'providers',
            'coauthor',
            'briefing',
            'copilot',
            'token_logs',
            'advanced',
        ];

        foreach ( $expected_tabs as $tab_key ) {
            $pattern = '/<option[^>]*value=["\']' . preg_quote( $tab_key, '/' ) . '["\']/i';
            if ( ! preg_match( $pattern, $html ) ) {
                $failures[] = "Mobile tab select dropdown is missing option for tab '{$tab_key}'.";
            }
        }

        // -------------------------------------------------------------
        // Case 3: Accessibility Attributes (ARIA roles, labels, controls)
        // -------------------------------------------------------------
        if ( false === strpos( $html, 'aria-label=' ) && false === strpos( $html, 'for="presshub-mobile-tab-select"' ) ) {
            $failures[] = "Mobile select dropdown missing accessible label or aria-label attribute.";
        }

        if ( false === strpos( $html, 'role="tab"' ) ) {
            $failures[] = "Desktop/tablet nav-tab elements missing role=\"tab\" attribute.";
        }

        if ( false === strpos( $html, 'aria-selected=' ) ) {
            $failures[] = "Desktop/tablet nav-tab elements missing aria-selected attribute.";
        }

        if ( false === strpos( $html, 'aria-controls=' ) ) {
            $failures[] = "Desktop/tablet nav-tab elements missing aria-controls attribute.";
        }

        // -------------------------------------------------------------
        // Case 4: Responsive CSS in admin.css
        // -------------------------------------------------------------
        $css_file = dirname( __DIR__ ) . '/assets/admin.css';
        if ( ! file_exists( $css_file ) ) {
            $failures[] = "admin.css file not found at {$css_file}.";
        } else {
            $css_content = file_get_contents( $css_file );

            // Check for flex container and overflow scroll
            if ( false === strpos( $css_content, 'overflow-x' ) ) {
                $failures[] = "admin.css missing 'overflow-x' property for responsive horizontal scroll.";
            }

            // Check for media queries
            if ( false === strpos( $css_content, '@media' ) || false === strpos( $css_content, '782px' ) ) {
                $failures[] = "admin.css missing mobile media query breakpoint (max-width: 782px).";
            }

            // Check for mobile tab selector styles
            if ( false === strpos( $css_content, '.presshub-mobile-tab-select' ) ) {
                $failures[] = "admin.css missing styles for .presshub-mobile-tab-select.";
            }

            // Check for WCAG 2.1 minimum 44px tap target height
            if ( false === strpos( $css_content, '44px' ) ) {
                $failures[] = "admin.css missing 44px min-height or height rule for WCAG 2.1 touch targets.";
            }
        }

        // -------------------------------------------------------------
        // Case 5: JS Synchronization in admin.js
        // -------------------------------------------------------------
        $js_file = dirname( __DIR__ ) . '/assets/admin.js';
        if ( ! file_exists( $js_file ) ) {
            $failures[] = "admin.js file not found at {$js_file}.";
        } else {
            $js_content = file_get_contents( $js_file );

            if ( false === strpos( $js_content, 'presshub-mobile-tab-select' ) ) {
                $failures[] = "admin.js missing synchronization logic for #presshub-mobile-tab-select.";
            }

            if ( false === strpos( $js_content, 'aria-selected' ) ) {
                $failures[] = "admin.js missing aria-selected attribute update during tab switching.";
            }
        }

        // -------------------------------------------------------------
        // Report results
        // -------------------------------------------------------------
        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }

        echo "OK\n";
    }
}

SettingsResponsiveTabsTest::run();
