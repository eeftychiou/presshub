<?php
/**
 * TDD regression test for Issue #59:
 *   "bug(editor): AI Co-Pilot Proposed Revisions not transferred to draft
 *    post + sidebar width is fixed & too narrow"
 *
 * Background
 * ----------
 * The PressHub AI Gutenberg sidebar (assets/sidebar.js) ships an
 * `applyReplacementInEditor()` function that mutates the editor's
 * `editedPost.content` to transfer LLM-proposed revisions into the draft.
 *
 * The original implementation had three concrete defects:
 *
 *   1. String.prototype.replace() with a string needle replaces ONLY the
 *      first match. If the same `originalText` substring appears more
 *      than once in the article (a heading repeated in two places, a
 *      common phrase), only the first occurrence was replaced.
 *
 *   2. String.prototype.replace() interprets `$&`, `$1`, `$$` etc. in
 *      the replacement string as special tokens. If the revised text
 *      (or a user's manual edit) contained a literal `$`, the output
 *      was corrupted (e.g. "100$" -> "1000").
 *
 *   3. When the serialized Gutenberg content did not contain the
 *      `originalText` substring verbatim, the fallback path used
 *      `wp.blocks.serialize([b])` and `wp.blocks.parse(...)`, which
 *      round-trips every block through serialization and breaks nested
 *      block markup (image captions, list-item nesting, inner blocks).
 *
 * The fix replaces the `replace()` call with `split().join()` (which
 * has no special-token semantics and replaces all matches), and
 * replaces the serialize/parse fallback with a `block.replaceHTML()`
 * style helper that touches only the matching block's inner
 * `attributes.content` (or the plain-text representation of the block
 * that actually contains the substring), preserving all other blocks
 * untouched.
 *
 * Sidebar width (part B of the issue): the sidebar container was given
 * a default minimum width of 480px so the editor textarea doesn't
 * wrap aggressively on a 1280-px laptop viewport.
 *
 * What this test asserts
 * ----------------------
 *
 * 1. The `applyReplacementInEditor` function in assets/sidebar.js no
 *    longer contains the buggy `currentContent.replace(originalText,`
 *    line.
 *
 * 2. The replacement uses `split(originalText).join(safeRevised)` (the
 *    documented fix path) so every occurrence is replaced and `$`-tokens
 *    are no longer interpreted.
 *
 * 3. The block-level fallback does NOT call `wp.blocks.serialize([b])`
 *    followed by `wp.blocks.parse(updatedHtml)` — that's the line that
 *    corrupts nested block markup. Instead, it uses a dedicated helper
 *    (`applyBlockLevelReplacement`) that only mutates the matching
 *    block's inner content.
 *
 * 4. The CSS rule for `.presshub-sidebar-container` has a `width` (or
 *    `min-width`) so the sidebar is no longer pinned at Gutenberg's
 *    ~280px default.
 *
 * 5. `node --check assets/sidebar.js` exits 0 (catches any syntax
 *    regression in the fix). Skipped when node is not on PATH.
 */

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

class SidebarRevisionTransferTest
{
    public static function run(): void {
        $failures = array();

        $sidebar_js = dirname( __DIR__ ) . '/assets/sidebar.js';
        $admin_css  = dirname( __DIR__ ) . '/assets/admin.css';

        if ( ! is_file( $sidebar_js ) ) {
            fwrite( STDERR, "FAIL\n" );
            fwrite( STDERR, "  - assets/sidebar.js not found at expected path: {$sidebar_js}\n" );
            exit( 1 );
        }
        if ( ! is_file( $admin_css ) ) {
            fwrite( STDERR, "FAIL\n" );
            fwrite( STDERR, "  - assets/admin.css not found at expected path: {$admin_css}\n" );
            exit( 1 );
        }

        $js  = (string) file_get_contents( $sidebar_js );
        $css = (string) file_get_contents( $admin_css );

        // -----------------------------------------------------------------
        // Check 1: the buggy `replace(originalText, ...)` line is gone.
        // -----------------------------------------------------------------
        // We look for the exact buggy pattern that the issue calls out:
        //     currentContent.replace(originalText, revisedText)
        // Any remaining string-needle `.replace()` against the article
        // content is the regression — the fix must use `split().join()`.
        // We strip JSDoc / line comments first so explanatory comments
        // (which mention the old patterns) don't false-positive.
        $code_only = self::strip_comments($js);
        $buggy_patterns = array(
            'currentContent.replace(originalText,',
            'rawHtml.replace(originalText,',
        );
        foreach ( $buggy_patterns as $pat ) {
            if ( false !== strpos( $code_only, $pat ) ) {
                $failures[] = "sidebar.js still contains buggy replace pattern: `{$pat}` — Issue #59 (revision transfer) regression.";
            }
        }

        // -----------------------------------------------------------------
        // Check 2: the fix uses split().join() (or an equivalent helper)
        // for $-safe + replace-all.
        // -----------------------------------------------------------------
        // We accept EITHER a literal `split(needle).join(...)` call OR a
        // call to a named helper (`replaceAllSafe(...)`) that internally
        // uses split().join(). The simplest, most readable form is
        // `currentContent.split(originalText).join(safe)` where `safe`
        // is a `$`-escaped revision. To be tolerant of minor refactors
        // we look for the call signature.
        $split_patterns = array(
            '.split(originalText).join(',
            '.split( originalText ).join(',
            '.split(rev.original).join(',
            '.split( rev.original ).join(',
            'replaceAllSafe(',
        );
        $found_split = false;
        foreach ( $split_patterns as $pat ) {
            if ( false !== strpos( $code_only, $pat ) ) {
                $found_split = true;
                break;
            }
        }
        if ( ! $found_split ) {
            $failures[] = 'sidebar.js does not use a `.split(needle).join()` replacement (or a `replaceAllSafe(...)` helper). The fix must replace ALL matches and survive literal `$` characters in the revised text.';
        }

        // -----------------------------------------------------------------
        // Check 3: the `$`-escape helper is present.
        // -----------------------------------------------------------------
        // The simplest safe form: `replace(/\$/g, '$$$$')`. Anything that
        // doubles a literal `$` in the revision is acceptable.
        if ( false === strpos( $code_only, "'$$$$'" )
             && false === strpos( $code_only, '"$$$$"' )
             && false === strpos( $code_only, "\\$\\$" ) ) {
            // No $-escape helper at all. We flag this as a regression
            // risk because the next person to touch the function will
            // reintroduce the bug. Not a hard failure (some refactors
            // might do the escaping inline), so we only warn by default.
            $failures[] = 'sidebar.js has no `$$`-escape helper for `$`-characters in revised text. Without escaping, a revision containing `100$` becomes `1000` after `.replace()` — Issue #59 (revision transfer).';
        }

        // -----------------------------------------------------------------
        // Check 4: the block-level fallback no longer round-trips through
        // serialize/parse, which corrupts nested block markup.
        // -----------------------------------------------------------------
        // Comments mention the old patterns; ignore comment lines.
        $serialize_parse_patterns = array(
            'wp.blocks.serialize([b])',
            'wp.blocks.parse(updatedHtml)',
        );
        foreach ( $serialize_parse_patterns as $pat ) {
            if ( false !== strpos( $code_only, $pat ) ) {
                $failures[] = "sidebar.js still contains the block-level fallback `{$pat}` — Issue #59. Re-serializing every block corrupts nested markup (image captions, inner blocks).";
            }
        }

        // -----------------------------------------------------------------
        // Check 5: a dedicated block-level replacement helper exists.
        // -----------------------------------------------------------------
        // We expect a function called `applyBlockLevelReplacement` (the
        // helper extracted from the inline fallback). Anything that
        // touches only the matching block is acceptable; the named
        // helper is the simplest contract.
        if ( false === strpos( $js, 'applyBlockLevelReplacement' ) ) {
            $failures[] = 'sidebar.js does not define an `applyBlockLevelReplacement` helper. The block-level fallback must be extracted into a named helper that only mutates the matching block — Issue #59.';
        }

        // -----------------------------------------------------------------
        // Check 6: sidebar container CSS has a sane default width.
        // -----------------------------------------------------------------
        // We accept `width: <px>` or `min-width: <px>` for any px value
        // >= 320. Anything narrower than that re-creates the bug; any
        // declaration at all is acceptable evidence the issue is fixed.
        if ( ! preg_match(
            '/\.presshub-sidebar-container\s*\{[^}]*(?:min-)?width\s*:\s*(\d+)px/s',
            $css,
            $m
        ) ) {
            $failures[] = 'admin.css has no `width` / `min-width` rule for `.presshub-sidebar-container`. The sidebar is pinned at Gutenberg ~280px and the Proposed Revision textarea wraps aggressively — Issue #59 (sidebar width).';
        } else {
            $declared_width = (int) $m[1];
            if ( $declared_width < 320 ) {
                $failures[] = "admin.css sidebar width is only {$declared_width}px — too narrow (min 320px). Issue #59.";
            }
        }

        // -----------------------------------------------------------------
        // Check 7: node --check on sidebar.js (skipped when node missing).
        // -----------------------------------------------------------------
        self::node_check_or_skip( $sidebar_js, $failures );

        self::print_failures_and_exit( $failures );
        echo "OK\n";
    }

    /**
     * Strip JS line comments (`// ...`) and block comments (`/* ... *\/`)
     * so that pattern assertions look at CODE only, not documentation.
     * Preserves string literals intact by doing a simple two-pass strip
     * — adequate for this test, which only checks the absence of well-
     * known buggy patterns.
     */
    private static function strip_comments(string $src): string {
        // Strip /* ... */ block comments (non-greedy).
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        // Strip // line comments.
        $src = preg_replace('#(?<![:"\'])//[^\n]*#', '', $src);
        return $src;
    }

    /**
     * Best-effort `node --check <file>` for assets/sidebar.js.
     * Skips with a notice when node is not on PATH so unrelated CI
     * environments don't break; source-level checks above remain
     * authoritative.
     *
     * @param array<string> $failures
     */
    private static function node_check_or_skip( string $file, array &$failures ): void {
        $node_path = '';
        if ( DIRECTORY_SEPARATOR === '\\' ) {
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
            echo "NOTE: node not on PATH; skipping runtime parse check (source-level checks are authoritative)\n";
            return;
        }

        $output    = array();
        $exit_code = 0;
        if ( DIRECTORY_SEPARATOR === '\\' ) {
            $cmd = sprintf(
                'cmd.exe /C ""%s" --check "%s" 2>&1"',
                $node_path,
                $file
            );
            exec( $cmd, $output, $exit_code );
        } else {
            $cmd = escapeshellcmd( $node_path ) . ' --check ' . escapeshellarg( $file ) . ' 2>&1';
            exec( $cmd, $output, $exit_code );
        }

        if ( 0 !== $exit_code ) {
            $stderr = implode( "\n", $output );
            $failures[] = sprintf(
                'sidebar.js failed `node --check` (exit %d). Syntax error in the fix would re-introduce Issue #59. node output:%s%s',
                $exit_code,
                PHP_EOL,
                $stderr
            );
        }
    }

    /**
     * Print any failures to STDERR and exit 1.
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

SidebarRevisionTransferTest::run();
