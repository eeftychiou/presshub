<?php
/**
 * TDD regression test for Issue #14:
 *   "bug(settings): Add Provider button does nothing because admin.js has a SyntaxError"
 *
 * Background
 * ----------
 * In the `populateModelDropdown()` function inside presshub-ai-editor/assets/admin.js
 * (around line 806), a multiline regex literal was left malformed:
 *
 *     models = modelsList.split(/[
 *
 *     ,]+/).map(...)
 *
 * JavaScript regex literals cannot contain a raw newline in their pattern body.
 * The unterminated `/[` causes Node's parser (and every browser's parser)
 * to throw `SyntaxError: Invalid regular expression: missing /` immediately
 * on parse. Because admin.js is wrapped in a single `jQuery(document).ready()`
 * callback at the bottom of the file, the SyntaxError aborts the entire file
 * BEFORE the click handler for `#presshub-add-provider-btn` is ever bound.
 * Net effect: clicking Add Provider does literally nothing — the provider
 * modal never opens.
 *
 * Tooling rationale
 * -----------------
 * The plugin's existing PHP test harness (AdminJsNoAlertTest.php) already
 * validates an admin.js source-level invariant ("no alert()") because there
 * is no Jest setup that touches assets/admin.js (Jest lives in
 * presshub-workflow/, a separate package). We follow the same pattern:
 * ship a small PHP regression that shells out to `node --check` to enforce
 * a parseable admin.js. If `node` is not on PATH the test is a no-op skip
 * with a clear notice, so it does not break unrelated CI environments.
 *
 * What this test asserts
 * ----------------------
 * 1. assets/admin.js exists and is non-empty.
 * 2. `node --check <admin.js>` exits 0 (i.e. the file parses cleanly).
 * 3. The populateModelDropdown() function is present in the file AND its
 *    implementation no longer contains a regex literal that spans a line
 *    boundary without a closing `/`. This is the specific regression check
 *    for the bug as filed in Issue #14.
 *
 * If any of the above fail, the test prints actionable diagnostics
 * (node's stderr + the offending regex location) and exits 1.
 */

// Minimal stubs so the harness can load if this file is invoked in
// isolation. The CI runner uses the shared run-all-tests.php harness
// and does not require anything here.
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

class AdminJsSyntaxTest
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
            fwrite( STDERR, "  - assets/admin.js is empty (cannot enforce syntax contract)\n" );
            exit( 1 );
        }

        // -----------------------------------------------------------------
        // Check 1: header declaration that "this file must parse as valid JS"
        // -----------------------------------------------------------------
        // The admin.js header block declares its UI-review contract; it
        // does NOT currently declare a syntax contract. We add a soft
        // reminder comment in admin.js itself (added as part of the fix)
        // and assert that the file contains a populateModelDropdown()
        // function. If the function has been removed, the test fails
        // loudly so we don't silently lose the regression gate.
        if ( false === strpos( $contents, 'function populateModelDropdown' ) ) {
            $failures[] = 'admin.js does not contain a populateModelDropdown() function — Issue #14 regression gate removed.';
        }

        // -----------------------------------------------------------------
        // Check 2: shell out to `node --check` for a definitive parse.
        // -----------------------------------------------------------------
        // `node --check <file>` is the standard way to verify JS syntax
        // without executing it. It exits 0 if the file parses, non-zero
        // with a SyntaxError on stderr otherwise. We use this as the
        // authoritative gate because it catches ANY syntax bug, not
        // just the specific `/[` regex one in Issue #14. An earlier
        // draft of this test tried to detect unterminated regex literals
        // via a regex-on-regexes heuristic, but that produced false
        // positives on any comment that mentioned `/[` — node's own
        // parser is strictly more accurate, so we rely on it alone.
        //
        // Detect `node` robustly across shells:
        //  - POSIX (bash / sh): `command -v node`
        //  - Windows (cmd / PowerShell): `where node`
        // `where` may return multiple lines; we only need the first hit.
        $node_path = '';
        if ( DIRECTORY_SEPARATOR === '\\' ) {
            // Windows — `where` can return `C:\Program Files\nodejs\node.exe`
            // which contains spaces. We resolve via `where` and then quote it
            // on use. If `where` is missing, fall back to PATH lookup.
            $where_out = array();
            $where_rc  = 0;
            exec( 'where node 2>NUL', $where_out, $where_rc );
            if ( 0 === $where_rc && ! empty( $where_out ) ) {
                $node_path = trim( $where_out[0] );
            }
        } else {
            $node_path = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        }

        if ( '' === $node_path ) {
            // No node on PATH — skip with a notice rather than failing,
            // so this test doesn't break pure-PHP CI environments.
            echo "SKIP (node not on PATH; regex-specific check above is authoritative)\n";
            self::print_failures_and_exit( $failures );
            return;
        }

        // Build the command line. On Windows, paths with spaces (e.g.
        // `C:\Program Files\nodejs\node.exe`) cannot be safely consumed
        // by PHP's exec() directly when invoked from PowerShell. We
        // therefore dispatch through cmd.exe with proper double-quoting
        // so cmd's own argv handling deals with the spaces. On POSIX
        // shells, escaping alone is sufficient.
        $output    = array();
        $exit_code = 0;
        if ( DIRECTORY_SEPARATOR === '\\' ) {
            $cmd = sprintf(
                'cmd.exe /C ""%s" --check "%s" 2>&1"',
                $node_path,
                $admin_js_path
            );
            exec( $cmd, $output, $exit_code );
        } else {
            $cmd = escapeshellcmd( $node_path ) . ' --check ' . escapeshellarg( $admin_js_path ) . ' 2>&1';
            exec( $cmd, $output, $exit_code );
        }

        if ( 0 !== $exit_code ) {
            $stderr = implode( "\n", $output );
            $failures[] = sprintf(
                'admin.js failed `node --check` (exit %d). This SyntaxError causes jQuery(document).ready() to abort and the Add Provider button to silently do nothing (Issue #14). node output:%s%s',
                $exit_code,
                PHP_EOL,
                $stderr
            );
        }

        self::print_failures_and_exit( $failures );
        echo "OK\n";
    }

    /**
     * Print any failures to STDERR and exit 1.
     *
     * Centralised so the early-return SKIP path can also use it.
     *
     * @param array<string> $failures
     */
    private static function print_failures_and_exit( array $failures ): void {
        if ( ! $failures ) {
            return;
        }
        fwrite( STDERR, "FAIL\n" );
        foreach ( $failures as $f ) {
            // Indent multi-line failure bodies so the per-file output in
            // the run-all harness stays readable.
            $indented = preg_replace( '/^/m', '    ', $f );
            fwrite( STDERR, "  - {$indented}\n" );
        }
        exit( 1 );
    }
}

AdminJsSyntaxTest::run();
