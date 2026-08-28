<?php
/**
 * TDD regression test for Concern #4 from the 2026-08-28 holistic
 * assessment: assets/admin.js must contain NO alert() calls.
 *
 * Background
 * ----------
 * The header comment of assets/admin.js (line 9) declares the UI-review
 * contract for the file:
 *
 *     "no alert() dialogs — all feedback is inline WP .notice markup"
 *
 * This was the agreed outcome of the 2026-08-16 Antigravity UI review
 * (ME-1 / F-01). A spot-check at the time of the 2026-08-28 holistic
 * assessment found 4 alert() calls remaining:
 *
 *     - line 960  (provider delete — failure path)
 *     - line 964  (provider delete — AJAX transport error)
 *     - line 1238 (clear-logs   — server returned an error)
 *     - line 1242 (clear-logs   — AJAX transport error)
 *
 * Because there is no JS test infrastructure for assets/admin.js in the
 * plugin root (jest lives in presshub-workflow/, the separate Gutenberg
 * npm package, and does not cover this asset), we enforce the contract
 * from the PHP test harness: read the file and assert no alert() calls
 * remain.
 *
 * Tooling rationale
 * -----------------
 * Setting up Jest + DOM mocks + jQuery globals just to lint one asset
 * is not justified. A ~50-line PHP file_get_contents + regex assertion
 * catches the regression deterministically, runs in the existing harness
 * in < 10 ms, and fails loudly if anyone (including future LLM agents)
 * re-introduces an alert().
 *
 * Match rules
 * -----------
 * - Strip the header comment block before scanning so the literal
 *   declaration "no alert() dialogs" in the file header does not count.
 * - Match the call site: alert( ... ) (opening paren immediately after
 *   the identifier). This avoids false-positives from identifiers like
 *   `dialogAlert` or `myAlert` and ignores string literals that just
 *   mention the word "alert" in copy text.
 * - Report every offending line so the failure message is actionable.
 */

// Minimal stubs so the harness can load if this file is invoked in
// isolation. The CI runner uses the shared run-all-tests.php harness
// and does not require anything here.
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

class AdminJsNoAlertTest
{
    public static function run(): void {
        $failures = array();

        $admin_js_path = dirname( __DIR__ ) . '/assets/admin.js';
        if ( ! is_file( $admin_js_path ) ) {
            fwrite( STDERR, "FAIL\n" );
            fwrite( STDERR, "  - assets/admin.js not found at expected path: {$admin_js_path}\n" );
            exit( 1 );
        }

        $contents = (string) file_get_contents( $admin_js_path );
        if ( '' === $contents ) {
            fwrite( STDERR, "FAIL\n" );
            fwrite( STDERR, "  - assets/admin.js is empty (cannot enforce no-alert contract)\n" );
            exit( 1 );
        }

        // Strip the leading block-comment header so the literal phrase
        // "no alert() dialogs" at line 9 of the header does not itself
        // count as a call site.
        $stripped = preg_replace( '#^/\*.*?\*/#s', '', $contents, 1 );
        if ( null === $stripped ) {
            $stripped = $contents;
        }

        // Compute how many lines of original file the stripped prefix
        // occupied, so a failure message can point the developer at the
        // real source line (line numbers must match what an editor shows).
        $prefix_len  = strlen( $contents ) - strlen( $stripped );
        $line_offset = substr_count( substr( $contents, 0, $prefix_len ), "\n" );

        $lines   = preg_split( '/\r\n|\r|\n/', $stripped );
        $matches = array();
        foreach ( $lines as $i => $line ) {
            // Match an identifier named exactly `alert` followed by an
            // opening paren. Word boundaries on the left keep us safe
            // against identifiers like `myAlert`; the literal '(' on the
            // right ensures we only match call sites, not bare words.
            if ( preg_match( '/(?<![A-Za-z0-9_$])alert\s*\(/', $line ) ) {
                $matches[] = $line_offset + $i + 1; // 1-based, original-file line
            }
        }

        if ( $matches ) {
            $failures[] = sprintf(
                'admin.js header declares "no alert() dialogs" but %d call site(s) remain at line(s): %s',
                count( $matches ),
                implode( ', ', $matches )
            );
        }

        // Second regression check: empty trailing arguments before a closing
        // paren. A 2026-08-28 incident in this same file saw PowerShell's
        // double-quoted-heredoc expansion eat `$(this)` from two showNotice()
        // calls, leaving `showNotice(msg, 'error', );` — syntactically invalid
        // JS that *would throw at runtime* but was invisible to the
        // no-alert() check above. Catch it here.
        //
        // Pattern: optional whitespace + comma + optional whitespace + closing
        // paren, BUT NOT preceded by a string literal or another closing
        // paren (which would be a legitimate one-arg call or a closing of an
        // inner expression). Practically: match `, )` or `,\n)` etc. on lines
        // that look like function calls. To keep false-positives low we
        // specifically look for `, );` at end-of-line in a context where
        // the surrounding function name suggests a showNotice/confirm/etc.
        $empty_arg_lines = array();
        $js_lines = preg_split( '/\r\n|\r|\n/', $contents );
        foreach ( $js_lines as $i => $line ) {
            // `, );` at end of line — empty trailing arg.
            if ( preg_match( '/,\s*\)\s*;?\s*$/', $line ) ) {
                $empty_arg_lines[] = $i + 1; // 1-based
            }
        }
        if ( $empty_arg_lines ) {
            $failures[] = sprintf(
                'admin.js has %d line(s) with an empty trailing argument before closing paren (likely a botched edit that ate $(this) or similar): %s',
                count( $empty_arg_lines ),
                implode( ', ', $empty_arg_lines )
            );
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
}

AdminJsNoAlertTest::run();
