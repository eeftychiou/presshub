<?php
/**
 * Settings section list on every screen size (#13, reworked for #246).
 *
 * Since #246 the Settings page is one section per page load, so the section
 * list is plain links (aria-current on the open one, no role="tab"), with a
 * <select> of the same sections on narrow screens.
 *
 * Verifies:
 * 1. The section list: one link per visible section, aria-current="page" on
 *    the open one only, no tab roles.
 * 2. The narrow-screen <select>: labelled, one option per section, the open
 *    one selected, values are the section URLs.
 * 3. Responsive CSS in settings.css: the select replaces the list below
 *    960px and touch targets are 44px high below 782px.
 * 4. settings-page.js navigates on change and asks first when there are
 *    unsaved changes.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/settings-sections-helper.php';

class SettingsResponsiveTabsTest
{
    public static function run(): void {
        $failures = [];

        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_others_posts' ];

        $slugs = PressHub_Settings_Sections::slugs();
        foreach ( $slugs as $current ) {
            $html = presshub_test_render_section( $current );

            // Case 1: the section list.
            if ( false === strpos( $html, '<nav class="presshub-settings-nav"' ) || false === strpos( $html, 'aria-label="Settings sections"' ) ) {
                $failures[] = "{$current}: the section list should be a labelled <nav>.";
            }
            if ( false !== strpos( $html, 'role="tab"' ) || false !== strpos( $html, 'role="tablist"' ) || false !== strpos( $html, 'role="tabpanel"' ) ) {
                $failures[] = "{$current}: the section list must not use tab roles.";
            }
            if ( 1 !== substr_count( $html, 'aria-current="page"' ) ) {
                $failures[] = "{$current}: exactly one link should carry aria-current=\"page\"; got " . substr_count( $html, 'aria-current="page"' );
            }
            if ( ! preg_match( '/<a href="[^"]*section=' . preg_quote( $current, '/' ) . '"[^>]*data-section="' . preg_quote( $current, '/' ) . '" aria-current="page"/', $html ) ) {
                $failures[] = "{$current}: aria-current should sit on the link to the open section.";
            }
            foreach ( $slugs as $slug ) {
                if ( ! preg_match( '/<a href="[^"]*section=' . preg_quote( $slug, '/' ) . '"[^>]*class="presshub-settings-nav-link/', $html ) ) {
                    $failures[] = "{$current}: the section list is missing a link to {$slug}.";
                }
            }

            // Case 2: the narrow-screen select.
            if ( false === strpos( $html, 'for="presshub-settings-section-select"' ) || false === strpos( $html, 'id="presshub-settings-section-select"' ) ) {
                $failures[] = "{$current}: the section <select> should render with a label.";
            }
            foreach ( $slugs as $slug ) {
                if ( ! preg_match( '/<option value="[^"]*section=' . preg_quote( $slug, '/' ) . '"/', $html ) ) {
                    $failures[] = "{$current}: the section <select> is missing an option for {$slug}.";
                }
            }
            if ( ! preg_match( '/<option value="[^"]*section=' . preg_quote( $current, '/' ) . '" selected="selected"/', $html ) ) {
                $failures[] = "{$current}: the open section should be selected in the <select>.";
            }
        }

        // Case 3: responsive CSS.
        $css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/settings.css' );
        $narrow = (string) strstr( (string) strstr( $css, '@media (max-width: 960px)' ), '@media (max-width: 782px)', true );
        if ( false === strpos( $narrow, 'presshub-settings-nav-list { display: none; }' ) || false === strpos( $narrow, 'presshub-settings-nav-select-wrap { display: block;' ) ) {
            $failures[] = 'settings.css should swap the section list for the <select> below 960px.';
        }
        if ( ! preg_match( '/@media \(max-width: 782px\)[^@]*min-height: 44px/s', $css ) ) {
            $failures[] = 'settings.css should give touch targets a 44px min-height below 782px.';
        }

        // Case 4: the select navigates, behind the unsaved-changes guard.
        $js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/settings-page.js' );
        if ( false === strpos( $js, "'#presshub-settings-section-select'" ) || false === strpos( $js, 'confirmLeave()' ) ) {
            $failures[] = 'settings-page.js should navigate on section <select> change, after the unsaved-changes guard.';
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( array_unique( $failures ) as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }

        echo "OK\n";
    }
}

SettingsResponsiveTabsTest::run();
