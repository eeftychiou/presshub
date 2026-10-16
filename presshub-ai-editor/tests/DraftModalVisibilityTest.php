<?php
/**
 * TDD regression tests for AI Co-Author draft modal visibility & dismissibility:
 *
 * Issue: "When I use the AI Co-author functionality metabox to generate a text
 * and then click insert draft the dialog stays open and it cannot be dismissed."
 *
 * Root cause:
 *   1. CSS cascade collision in assets/admin.css: a second .presshub-modal-backdrop
 *      rule later in the file unconditionally set `display: flex;`, overriding
 *      the default `display: none;`. As a result, removing `.is-open` left the modal
 *      computed as `display: flex`, keeping it visible on screen.
 *   2. acceptDraft() in assets/admin.js did not invoke complete modal closure/cleanup
 *      or ensure [hidden] was set, and setting presshubPendingDraft = null before
 *      properly dismissing the modal caused subsequent clicks on "Insert draft",
 *      "Discard draft", "Cancel", and Escape to no-op.
 *   3. Missing CSS `[hidden]` enforcement: author stylesheet `display: flex` rules
 *      override browser default `[hidden] { display: none }` unless explicit
 *      author rules like `.presshub-modal-backdrop[hidden] { display: none !important; }`
 *      are present.
 *   4. Unscoped modal-close click listener in admin.js hijacked .presshub-modal-cancel
 *      and routed it to closeLogDetailsModal().
 *
 * This test suite asserts that:
 *   - assets/admin.css enforces `display: none` by default for .presshub-modal-backdrop.
 *   - assets/admin.css only enables `display: flex` when `.is-open` is present.
 *   - assets/admin.css enforces `display: none !important` when [hidden] is present.
 *   - assets/admin.css does NOT contain an un-scoped `display: flex` override on .presshub-modal-backdrop.
 *   - assets/admin.js implements unified openModal and closeModal helpers.
 *   - assets/admin.js closeModal sets `hidden` attribute & property, removes `is-open`, and hides the element.
 *   - assets/admin.js ensureDraftModal initializes the draft modal element hidden.
 *   - assets/admin.js acceptDraft always closes and dismisses the modal on draft insertion.
 *   - assets/admin.js handles Escape key and delegated button clicks for draft modal dismiss.
 *   - assets/admin.js modal-cancel/close handler is properly scoped to avoid intercepting other modals.
 */

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

class DraftModalVisibilityTest
{
    public static function run(): void {
        $failures = [];

        $admin_css_path = dirname( __DIR__ ) . '/assets/admin.css';
        $admin_js_path  = dirname( __DIR__ ) . '/assets/admin.js';

        if ( ! is_file( $admin_css_path ) ) {
            fwrite( STDERR, "FAIL: assets/admin.css not found at {$admin_css_path}\n" );
            exit( 1 );
        }
        if ( ! is_file( $admin_js_path ) ) {
            fwrite( STDERR, "FAIL: assets/admin.js not found at {$admin_js_path}\n" );
            exit( 1 );
        }

        $css = (string) file_get_contents( $admin_css_path );
        $js  = (string) file_get_contents( $admin_js_path );

        // =================================================================
        // Part 1: CSS Assertions in admin.css
        // =================================================================

        // 1.1: [hidden] rule enforces display: none !important
        if ( ! preg_match( '/\.presshub-modal-backdrop\[hidden\].*?display:\s*none\s*!important/s', $css ) &&
             ! preg_match( '/\[hidden\].*?display:\s*none\s*!important/s', $css ) ) {
            $failures[] = 'admin.css must enforce display: none !important on [hidden] or .presshub-modal-backdrop[hidden].';
        }

        // 1.2: .presshub-modal[hidden] enforces display: none !important
        if ( ! preg_match( '/\.presshub-modal\[hidden\].*?display:\s*none\s*!important/s', $css ) &&
             ! preg_match( '/\[hidden\].*?display:\s*none\s*!important/s', $css ) ) {
            $failures[] = 'admin.css must enforce display: none !important on .presshub-modal[hidden].';
        }

        // 1.3: .presshub-modal-backdrop default display is none
        if ( ! preg_match( '/\.presshub-modal-backdrop\s*\{[^}]*?display:\s*none/s', $css ) ) {
            $failures[] = 'admin.css must declare display: none on .presshub-modal-backdrop by default.';
        }

        // 1.4: .presshub-modal-backdrop.is-open display is flex
        if ( ! preg_match( '/\.presshub-modal-backdrop\.is-open\s*\{[^}]*?display:\s*flex/s', $css ) ) {
            $failures[] = 'admin.css must declare display: flex on .presshub-modal-backdrop.is-open.';
        }

        // 1.5: .presshub-modal-backdrop:not(.is-open) is display: none
        if ( ! preg_match( '/\.presshub-modal-backdrop:not\(\.is-open\)\s*\{[^}]*?display:\s*none/s', $css ) ) {
            $failures[] = 'admin.css must declare display: none on .presshub-modal-backdrop:not(.is-open).';
        }

        // 1.6: NO un-scoped .presshub-modal-backdrop { display: flex } rule
        // Scan all .presshub-modal-backdrop { ... } rule blocks that do NOT have .is-open
        preg_match_all( '/(^|[^\w\.-])(\.presshub-modal-backdrop)\s*\{([^}]+)\}/m', $css, $matches, PREG_SET_ORDER );
        foreach ( $matches as $m ) {
            $block_content = $m[3];
            if ( preg_match( '/display:\s*flex/i', $block_content ) ) {
                $failures[] = 'admin.css has a bare .presshub-modal-backdrop block with display: flex; this overrides default display: none and prevents modals from closing.';
            }
        }

        // =================================================================
        // Part 2: JavaScript Assertions in admin.js
        // =================================================================

        // 2.1: Unified openModal and closeModal functions exist
        if ( false === strpos( $js, 'function openModal(' ) ) {
            $failures[] = "admin.js missing 'openModal' helper function.";
        }
        if ( false === strpos( $js, 'function closeModal(' ) ) {
            $failures[] = "admin.js missing 'closeModal' helper function.";
        }

        // 2.2: closeModal removes is-open and manages hidden attribute/property
        if ( ! preg_match( '/function closeModal\s*\(.*?\)\s*\{.*?\bremoveClass\([\'"]is-open[\'"]\)/s', $js ) ) {
            $failures[] = "closeModal() must remove class 'is-open'.";
        }
        if ( ! preg_match( '/function closeModal\s*\(.*?\)\s*\{.*?\battr\([\'"]hidden[\'"],\s*[\'"]hidden[\'"]\)/s', $js ) ) {
            $failures[] = "closeModal() must set hidden attribute via .attr('hidden', 'hidden').";
        }
        if ( ! preg_match( '/function closeModal\s*\(.*?\)\s*\{.*?\bprop\([\'"]hidden[\'"],\s*true\)/s', $js ) ) {
            $failures[] = "closeModal() must set hidden property via .prop('hidden', true).";
        }
        if ( ! preg_match( '/function closeModal\s*\(.*?\)\s*\{.*?\bremoveClass\([\'"]presshub-modal-open[\'"]\)/s', $js ) ) {
            $failures[] = "closeModal() must remove 'presshub-modal-open' from body.";
        }

        // 2.3: ensureDraftModal initializes modal with hidden attribute and display:none
        if ( false === strpos( $js, 'id="presshub-draft-modal"' ) ) {
            $failures[] = "admin.js ensureDraftModal() must define modal with id='presshub-draft-modal'.";
        }
        if ( ! preg_match( '/<div[^>]*id="presshub-draft-modal"[^>]*hidden/s', $js ) &&
             ! preg_match( '/<div[^>]*class="[^"]*presshub-modal-backdrop[^"]*"[^>]*hidden/s', $js ) ) {
            $failures[] = "admin.js ensureDraftModal() markup must include the 'hidden' attribute.";
        }

        // 2.4: acceptDraft calls closeDraftModal / closeModal to dismiss the dialog
        if ( ! preg_match( '/function acceptDraft\s*\(.*?\)\s*\{.*?closeDraftModal\(/s', $js ) &&
             ! preg_match( '/function acceptDraft\s*\(.*?\)\s*\{.*?closeModal\(/s', $js ) ) {
            $failures[] = "acceptDraft() must call closeDraftModal() or closeModal() to dismiss the modal after insertion.";
        }

        // 2.5: acceptDraft guards null/empty presshubPendingDraft by still closing the modal
        // Ensures double-clicks don't strand the open modal
        if ( ! preg_match( '/function acceptDraft\s*\(.*?\)\s*\{[^}]*?!presshubPendingDraft[^}]*?closeDraftModal/s', $js ) ) {
            $failures[] = "acceptDraft() must dismiss modal even when presshubPendingDraft is falsey/cleared.";
        }

        // 2.6: Escape key handler checks for draft modal visibility and closes it
        if ( ! preg_match( '/Escape.*?presshub-draft-modal/s', $js ) &&
             ! preg_match( '/presshub-draft-modal.*?Escape/s', $js ) ) {
            $failures[] = "admin.js keydown handler must support Escape dismiss for presshub-draft-modal.";
        }

        // 2.7: Delegated click handlers for accept, reject, and cancel on draft modal
        if ( false === strpos( $js, '#presshub-draft-modal #presshub-draft-accept' ) ) {
            $failures[] = "admin.js missing delegated click listener for '#presshub-draft-modal #presshub-draft-accept'.";
        }
        if ( false === strpos( $js, '#presshub-draft-modal #presshub-draft-reject' ) ) {
            $failures[] = "admin.js missing delegated click listener for '#presshub-draft-modal #presshub-draft-reject'.";
        }
        if ( false === strpos( $js, '#presshub-draft-modal .presshub-modal-cancel' ) ) {
            $failures[] = "admin.js missing delegated click listener for '#presshub-draft-modal .presshub-modal-cancel'.";
        }

        // 2.8: Scoped modal-close/cancel handler
        // The provider/log modal handler must NOT blindly catch all .presshub-modal-close or .presshub-modal-cancel
        if ( preg_match( '/\$\(document\)\.on\([\'"]click[\'"],\s*[\'"]\.presshub-modal-close,\s*\.presshub-modal-cancel[\'"]/s', $js ) ) {
            $failures[] = "admin.js has an unscoped $(document).on('click', '.presshub-modal-close, .presshub-modal-cancel') listener that hijacks cancel clicks from other modals.";
        }

        // Output results
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

DraftModalVisibilityTest::run();
