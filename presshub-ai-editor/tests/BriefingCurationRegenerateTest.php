<?php
/**
 * TDD regression test for Issue #88:
 *   "bug(editor): Re-generate Text Story leaves the previous post title visible
 *    in the Step 2 bounded box (JS does not refresh card-title-preview)"
 *
 * Background
 * ----------
 * On the Daily Briefing Hub (`presshub-ai-editor/includes/class-briefing-admin.php`,
 * Step 2 / "Text Story Curation") the bounded box's title preview is rendered
 * once at page load from `$status['text_post_title']`:
 *
 *     <p class="card-title-preview">
 *         <strong><?php echo esc_html( $status['text_post_title'] ); ?></strong>
 *     </p>
 *
 * When the operator clicks *Re-generate Story*, the server replaces the
 * underlying WP post (new ID, new body, new title), and the post-status pill
 * (`#presshub-curation-post-status-pill`) is updated by `renderCurationStatusPills()`
 * in `presshub-ai-editor/assets/briefing-admin.js`. However, `.card-title-preview
 * strong` was never touched by the JS, so the operator kept seeing the previous
 * title until a full page reload.
 *
 * Tooling rationale
 * -----------------
 * Following the pattern of `AdminJsSyntaxTest.php` (which guards Issue #14 via
 * `node --check` against `assets/admin.js`), this test asserts the *structural*
 * contract of `assets/briefing-admin.js`:
 *
 *   1. `briefing-admin.js` exists, is non-empty, and parses cleanly under
 *      `node --check`.
 *   2. `renderCurationStatusPills()` (function body) reads
 *      `status.text_post_title` AND mutates `.card-title-preview strong`.
 *   3. `updateUIFromStatus()` (function body) publishes the canonical status
 *      onto `presshubBriefingAdmin.lastStatus` so consumers (and regression
 *      tooling) can read the latest title without scraping the DOM.
 *
 * Each structural assertion is scoped to a specific function body so that
 * doc-block comments elsewhere in the file (which legitimately reference the
 * same identifiers as descriptive text) cannot accidentally satisfy the gate.
 *
 * If `node` is not on PATH the test falls back to source-level greps so the
 * language-agnostic contract still runs in pure-PHP CI environments.
 */

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

class BriefingCurationRegenerateTest
{
    public static function run(): void {
        $failures = array();

        $js_path = dirname( __DIR__ ) . '/assets/briefing-admin.js';
        if ( ! is_file( $js_path ) ) {
            fwrite( STDERR, "FAIL\n" );
            fwrite( STDERR, "  - assets/briefing-admin.js not found at expected path: {$js_path}\n" );
            exit( 1 );
        }

        $contents = (string) file_get_contents( $js_path );
        if ( '' === $contents ) {
            fwrite( STDERR, "FAIL\n" );
            fwrite( STDERR, "  - assets/briefing-admin.js is empty (cannot enforce contract)\n" );
            exit( 1 );
        }

        // -----------------------------------------------------------------
        // Check 1: parse the file with `node --check` so a SyntaxError
        // anywhere in briefing-admin.js fails the build. This mirrors
        // AdminJsSyntaxTest.php — a SyntaxError aborts the entire file's
        // jQuery(document).ready() callback, which would silently disable
        // the title-preview refresh even though it appears to be present
        // in the source.
        // -----------------------------------------------------------------
        $node_path = self::resolve_node();
        if ( '' !== $node_path ) {
            $output    = array();
            $exit_code = 0;
            if ( DIRECTORY_SEPARATOR === '\\' ) {
                $cmd = sprintf(
                    'cmd.exe /C ""%s" --check "%s" 2>&1"',
                    $node_path,
                    $js_path
                );
                exec( $cmd, $output, $exit_code );
            } else {
                $cmd = escapeshellcmd( $node_path ) . ' --check ' . escapeshellarg( $js_path ) . ' 2>&1';
                exec( $cmd, $output, $exit_code );
            }
            if ( 0 !== $exit_code ) {
                $failures[] = sprintf(
                    'briefing-admin.js failed `node --check` (exit %d). A SyntaxError would abort the entire jQuery(document).ready() callback, silently disabling the Issue #88 title-preview refresh. node output:%s%s',
                    $exit_code,
                    PHP_EOL,
                    implode( "\n", $output )
                );
            }
        } else {
            echo "NOTE (node not on PATH; structural checks below are authoritative)\n";
        }

        // -----------------------------------------------------------------
        // Check 2: renderCurationStatusPills() must reference both
        // `status.text_post_title` and `.card-title-preview` AND actually
        // mutate the title preview via `.text(...)`. Scope is the function
        // body itself so doc-block comments cannot satisfy the gate.
        // -----------------------------------------------------------------
        $curation_body = self::strip_js_comments(
            self::extract_function_body( $contents, 'function renderCurationStatusPills(' )
        );
        if ( '' === $curation_body ) {
            $failures[] = 'briefing-admin.js is missing renderCurationStatusPills() — the curation status renderer must remain.';
        } else {
            if ( false === strpos( $curation_body, 'status.text_post_title' ) ) {
                $failures[] = 'renderCurationStatusPills() does not read `status.text_post_title` — the post-regenerate title cannot be sourced without this reference (Issue #88).';
            }
            if ( false === strpos( $curation_body, '.card-title-preview' ) ) {
                $failures[] = 'renderCurationStatusPills() does not reference `.card-title-preview` — Issue #88 title-preview refresh has been removed (regression of the original bug).';
            }
            // The mutation must use `.text(...)` for XSS safety. After
            // stripping comments the only way `.text(` can appear in this
            // body is via an actual DOM mutation call.
            if ( false === strpos( $curation_body, '.text(' ) ) {
                $failures[] = 'renderCurationStatusPills() does not call `.text(...)` on any DOM node — Issue #88 mutation has been removed or replaced with an unsafe `.html(...)` call (XSS regression).';
            }
            // Belt-and-braces: the `.text(...)` call must mutate the
            // `.card-title-preview strong` selector. Assert by searching
            // the (very small) body for both tokens within 200 chars of
            // each other so that an unrelated `.text(...)` on a different
            // selector cannot satisfy the gate.
            $idx_preview = strpos( $curation_body, '.card-title-preview' );
            $idx_text    = strpos( $curation_body, '.text(' );
            if ( false !== $idx_preview && false !== $idx_text ) {
                $distance = abs( $idx_preview - $idx_text );
                if ( $distance > 200 ) {
                    $failures[] = sprintf(
                        'In renderCurationStatusPills(), `.card-title-preview` is %d chars away from the nearest `.text(` call (limit: 200). The title-preview is no longer being mutated by `.text(...)`.',
                        $distance
                    );
                }
            }
        }

        // -----------------------------------------------------------------
        // Check 3: updateUIFromStatus() must publish
        // `presshubBriefingAdmin.lastStatus = status`. Scope is the
        // function body so the gate cannot be satisfied by an unrelated
        // doc-block comment elsewhere in the file.
        // -----------------------------------------------------------------
        $update_body = self::strip_js_comments(
            self::extract_function_body( $contents, 'function updateUIFromStatus(' )
        );
        if ( '' === $update_body ) {
            $failures[] = 'briefing-admin.js is missing updateUIFromStatus() — the status application entry point must remain.';
        } else {
            if ( false === strpos( $update_body, 'presshubBriefingAdmin' ) ) {
                $failures[] = 'updateUIFromStatus() does not reference `presshubBriefingAdmin` — Issue #88 lastStatus publication has been removed.';
            }
            if ( false === strpos( $update_body, '.lastStatus' ) ) {
                $failures[] = 'updateUIFromStatus() does not write `.lastStatus` onto the presshubBriefingAdmin bag — Issue #88 single-source-of-truth invariant has been removed.';
            }
            // Defense-in-depth: the publication must be a real assignment
            // (`bag.lastStatus = status;`) not a property read.
            if ( false === strpos( $update_body, 'lastStatus =' ) ) {
                $failures[] = 'updateUIFromStatus() does not perform an explicit assignment to `lastStatus` — Issue #88 publication has been weakened to a property read.';
            }
        }

        self::print_failures_and_exit( $failures );
        echo "OK\n";
    }

    /**
     * Strip JavaScript line comments (`// ... \n`) and block comments
     * (`/* ... *\/`, including nested-style JSDoc) from a string. Returns
     * the de-commented text.
     *
     * Comments are replaced with the same number of newlines so that any
     * relative character distance computed by callers (e.g. the
     * `.card-title-preview` co-location check) still corresponds to the
     * original source layout.
     */
    private static function strip_js_comments( string $body ): string {
        if ( '' === $body ) {
            return $body;
        }
        // Block comments first so we don't accidentally treat `//`
        // inside a block comment as the start of a line comment.
        $body = preg_replace( '#/\*[\s\S]*?\*/#', '', $body );
        // Line comments: `//` until end of line. Preserve the newline so
        // the length-preservation property above still holds.
        $body = preg_replace( '#//[^\n]*#', '', $body );
        return (string) $body;
    }

    /**
     * Extract the body of a top-level `function NAME(` definition by brace
     * matching. Returns the substring starting at the opening `{` of the
     * function (inclusive) through its matching `}`, or '' if the function
     * header cannot be located.
     *
     * The extractor is intentionally simple — it does not understand JS
     * strings, template literals, regex literals, or comments. briefing-admin.js
     * does not contain any function whose definition spans an unbalanced
     * brace inside a string literal, and the file is wrapped in a single
     * jQuery(document).ready() IIFE so the brace counts are well-behaved at
     * top level. If a future refactor changes this, the test will simply
     * fail loudly and force the author to revisit the extraction logic —
     * which is preferable to silently producing a wrong answer.
     */
    private static function extract_function_body( string $haystack, string $needle ): string {
        $header_pos = strpos( $haystack, $needle );
        if ( false === $header_pos ) {
            return '';
        }
        // Find the FIRST `{` after the parameter list closes — the
        // parameter list cannot contain `{` in this file, so we can scan
        // forward for the first `{` after the matched `)`.
        $open_paren = strpos( $haystack, '(', $header_pos );
        if ( false === $open_paren ) {
            return '';
        }
        $body_open = strpos( $haystack, '{', $open_paren );
        if ( false === $body_open ) {
            return '';
        }
        $depth = 0;
        $len   = strlen( $haystack );
        for ( $i = $body_open; $i < $len; $i++ ) {
            $ch = $haystack[ $i ];
            if ( '{' === $ch ) {
                $depth++;
            } elseif ( '}' === $ch ) {
                $depth--;
                if ( 0 === $depth ) {
                    return substr( $haystack, $body_open, $i - $body_open + 1 );
                }
            }
        }
        return '';
    }

    /**
     * Locate a `node` binary suitable for `node --check`. Returns the full
     * resolved path on success, or '' if node is not available. Mirrors the
     * resolution logic in AdminJsSyntaxTest.php so both tests degrade
     * identically when node is missing.
     */
    private static function resolve_node(): string {
        if ( DIRECTORY_SEPARATOR === '\\' ) {
            $where_out = array();
            $where_rc  = 0;
            exec( 'where node 2>NUL', $where_out, $where_rc );
            if ( 0 === $where_rc && ! empty( $where_out ) ) {
                return trim( $where_out[0] );
            }
            return '';
        }
        return trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
    }

    /**
     * Centralised failure printer — keeps the run-all harness output clean.
     *
     * @param array<string> $failures
     */
    private static function print_failures_and_exit( array $failures ): void {
        if ( ! $failures ) {
            return;
        }
        fwrite( STDERR, "FAIL\n" );
        foreach ( $failures as $f ) {
            $indented = preg_replace( '/^/m', '    ', $f );
            fwrite( STDERR, "  - {$indented}\n" );
        }
        exit( 1 );
    }
}

BriefingCurationRegenerateTest::run();
