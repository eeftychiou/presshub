<?php
/**
 * Test helper for the one Settings page (#246): each section is its own page
 * load, so tests that look for a field render the section that holds it, or
 * every section in a row.
 */

if ( ! function_exists( 'presshub_test_render_section' ) ) {
    /** HTML of PressHub → Settings opened at one section. */
    function presshub_test_render_section( string $section ): string {
        if ( class_exists( 'PressHub_Social_Settings' ) && empty( $GLOBALS['wp_settings_sections']['presshub_social_general'] ) ) {
            PressHub_Social_Settings::get_instance()->register_settings();
        }
        $had = array_key_exists( 'section', $_GET );
        $old = $had ? $_GET['section'] : null;
        $_GET['section'] = $section;
        ob_start();
        try {
            ( new PressHub_AI_Settings_Render() )->render_settings_page();
        } finally {
            $html = (string) ob_get_clean();
            if ( $had ) {
                $_GET['section'] = $old;
            } else {
                unset( $_GET['section'] );
            }
        }
        return $html;
    }

    /** HTML of every Settings section, one after another. */
    function presshub_test_render_all_sections(): string {
        $html = '';
        foreach ( PressHub_Settings_Sections::slugs() as $slug ) {
            $html .= presshub_test_render_section( $slug );
        }
        return $html;
    }

    /** The section's content pane (#presshub-tab-pane-<slug>) out of a page. */
    function presshub_test_section_pane( string $html, string $section ): string {
        $start = strpos( $html, 'id="presshub-tab-pane-' . $section . '"' );
        if ( false === $start ) {
            return '';
        }
        $end = strpos( $html, 'id="presshub-sticky-save-bar"', $start );
        return substr( $html, $start, false === $end ? null : $end - $start );
    }
}

if ( ! function_exists( 'presshub_test_tab_pane' ) ) {
    /**
     * Pane of the section that now holds an old AI or Social tab ('briefing',
     * 'social:worker') or of a section slug.
     */
    function presshub_test_tab_pane( string $html, string $tab ): string {
        $page = 'ai';
        if ( 0 === strpos( $tab, 'social:' ) ) {
            $page = 'social';
            $tab  = substr( $tab, 7 );
        }
        $section = PressHub_Settings_Sections::exists( $tab ) ? $tab : ( PressHub_Settings_Sections::TAB_SECTIONS[ $page ][ $tab ] ?? $tab );
        return presshub_test_section_pane( $html, $section );
    }

    /** One group (<section id="…">) out of a page. */
    function presshub_test_group( string $html, string $group ): string {
        $start = strpos( $html, '<section id="' . $group . '"' );
        if ( false === $start ) {
            return '';
        }
        // Groups do not nest, so the next </section> that closes this one is the
        // first whose depth returns to zero.
        $depth = 0;
        $pos   = $start;
        while ( preg_match( '/<section\b|<\/section>/', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
            $depth += '</section>' === $m[0][0] ? -1 : 1;
            $pos    = $m[0][1] + strlen( $m[0][0] );
            if ( 0 === $depth ) {
                return substr( $html, $start, $pos - $start );
            }
        }
        return substr( $html, $start );
    }
}
