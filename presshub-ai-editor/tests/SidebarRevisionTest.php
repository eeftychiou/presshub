<?php
/**
 * TDD regression test for Issue #59:
 *   "bug(editor): AI Co-Pilot Proposed Revisions not transferred to draft post
 *    + sidebar width is fixed & too narrow"
 *
 * Background
 * ----------
 * The previous implementation of `applyReplacementInEditor()` in
 * `assets/sidebar.js` had three concrete defects:
 *
 *   1. `$`-escape: `String.prototype.replace(needle, replacement)`
 *      interprets `$&`, `$1`, `$$` as back-references. A revised text
 *      containing a literal `$` would be corrupted. Repro: Accept a
 *      revision whose revised text is "Costs $5 billion" — the post
 *      ends up with "Costs  billion" because `$5` is interpreted as
 *      "match #5" (and is empty), producing an `$1`-style collapse.
 *   2. First-match-only: `String.prototype.replace()` with a string
 *      needle only swaps the first occurrence. Repro: Accept a
 *      revision whose original text appears in two blocks.
 *   3. Plain-text vs. serialized HTML: the LLM emits plain text but
 *      the editor stores serialized Gutenberg blocks. The old code
 *      re-serialized every block, silently corrupting nested markup.
 *      Repro: Accept a revision whose original text lives inside a
 *      block with nested markup (image captions, list nesting).
 *
 * Sidebar (part B of the issue): the sidebar width was static
 * ~280px with no resize affordance.
 *
 * What this test asserts
 * ---------------------
 * 1. `assets/sidebar.js` exists and parses cleanly with `node --check`.
 * 2. `assets/admin.css` declares the new rules:
 *      - `.presshub-sidebar-container { min-width: 520px; ... }`
 *      - `.presshub-sidebar-resize-handle { ... }`
 *      - `@media (prefers-reduced-motion: reduce) { ... }`
 * 3. `assets/sidebar.js` no longer uses `String.prototype.replace()` on
 *    a dynamic needle within `applyReplacementInEditor` (the literal
 *    call site `.replace(originalText, revisedText)` is gone) — this
 *    is the regression gate for the `$`-escape / first-match bugs.
 * 4. `assets/sidebar.js` contains the new helper symbols:
 *      - `applyReplacementInEditor` (must exist)
 *      - `SIDEBAR_MIN_WIDTH`, `SIDEBAR_MAX_WIDTH`, `sidebarWidthStorageKey`
 *      - `loadSidebarWidth`
 *      - `presshub-sidebar-resize-handle` (the handle DOM node)
 *      - `aria-orientation`, `aria-valuemin`, `aria-valuemax` (the
 *      keyboard-accessibility contract).
 * 5. **Behavioural gate** (the heart of the test): we shell out to a
 *    small Node.js driver that loads `sidebar.js`, builds a minimal
 *    `wp.data` / `wp.blocks` mock, extracts the
 *    `applyReplacementInEditor` function and exercises the three
 *    fixed paths:
 *      - `$`-escape: a revision containing `$5bn` survives byte-for-byte.
 *      - Multi-match: `originalText` appearing in two blocks is
 *        replaced in both blocks.
 *      - Missing-text: when the original text isn't in the post, the
 *        function returns `{ ok: false, mode: 'no-match' }` (no silent
 *        append).
 *
 * Tooling rationale
 * -----------------
 * `assets/sidebar.js` is the Gutenberg sidebar plugin. It runs inside
 * WordPress's React-rendered `PluginSidebar` and is normally exercised
 * via a real editor + an API key. We don't have that here. Spinning up
 * Jest + DOM mocks + jQuery globals just to exercise one function is
 * overkill, so we follow the same pattern as `AdminJsSyntaxTest.php`:
 * shell out to `node` for an authoritative parse, plus a regex check
 * for the contract (no `.replace(needle, replacement)`), plus a tiny
 * Node helper that imports the function and runs it under a fake
 * `wp.data` to verify behaviour.
 */

// Minimal stubs so the harness can load if this file is invoked in
// isolation. The CI runner uses the shared run-all-tests.php harness
// and does not require anything here.
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

class SidebarRevisionTest {

    /** @var string Absolute path to assets/sidebar.js */
    private static $sidebar_js;

    /** @var string Absolute path to assets/admin.css */
    private static $admin_css;

    public static function run(): void {
        $failures = array();

        self::$sidebar_js = dirname( __DIR__ ) . '/assets/sidebar.js';
        self::$admin_css  = dirname( __DIR__ ) . '/assets/admin.css';

        // -----------------------------------------------------------------
        // Check 1: sidebar.js exists and is non-empty
        // -----------------------------------------------------------------
        if ( ! is_file( self::$sidebar_js ) ) {
            fwrite( STDERR, "FAIL\n  - assets/sidebar.js not found\n" );
            exit( 1 );
        }
        $sidebar_src = (string) file_get_contents( self::$sidebar_js );
        if ( '' === $sidebar_src ) {
            $failures[] = 'assets/sidebar.js is empty (cannot enforce revision-transfer contract)';
        }

        // -----------------------------------------------------------------
        // Check 2: admin.css exists and is non-empty
        // -----------------------------------------------------------------
        if ( ! is_file( self::$admin_css ) ) {
            fwrite( STDERR, "FAIL\n  - assets/admin.css not found\n" );
            exit( 1 );
        }
        $admin_css_src = (string) file_get_contents( self::$admin_css );
        if ( '' === $admin_css_src ) {
            $failures[] = 'assets/admin.css is empty (cannot enforce sidebar-width contract)';
        }

        // -----------------------------------------------------------------
        // Check 3: sidebar.js contains the function we are testing
        // -----------------------------------------------------------------
        if ( false === strpos( $sidebar_src, 'applyReplacementInEditor' ) ) {
            $failures[] = 'sidebar.js no longer defines applyReplacementInEditor — Issue #59 regression gate removed.';
        }

        // -----------------------------------------------------------------
        // Check 4: the old `.replace(originalText, revisedText)` call site
        // is gone. This is the single-line regression check for the
        // `$`-escape + first-match bug: the previous implementation did
        // `currentContent.replace(originalText, revisedText)` directly.
        // If anyone re-introduces that call site the test fails loudly.
        // -----------------------------------------------------------------
        if ( false !== strpos( $sidebar_src, '.replace(originalText, revisedText)' ) ) {
            $failures[] = 'sidebar.js still contains `.replace(originalText, revisedText)` — this corrupts literal `$` characters (Issue #59 part A) and only replaces the first match. Use the split/join pattern in applyReplacementInEditor.';
        }
        // Also forbid `.replace(rev.original, revisedText)` (the
        // call site in applySingleRevision/acceptAllRevisions) for the
        // same reason — that pattern is equally broken.
        if ( false !== strpos( $sidebar_src, '.replace(rev.original' ) ) {
            $failures[] = 'sidebar.js still calls `.replace(rev.original, …)` — same $-escape / first-match bug. Use applyReplacementInEditor.';
        }

        // -----------------------------------------------------------------
        // Check 5: the new helper symbols are present
        // -----------------------------------------------------------------
        $required_symbols = array(
            'SIDEBAR_MIN_WIDTH'      => 'sidebar min-width constant',
            'SIDEBAR_MAX_WIDTH'      => 'sidebar max-width constant',
            'sidebarWidthStorageKey' => 'per-post width persistence key',
            'loadSidebarWidth'       => 'width loader',
            'presshub-sidebar-resize-handle' => 'drag-handle DOM contract',
            'aria-orientation'       => 'handle accessibility (vertical separator)',
            'aria-valuemin'          => 'handle ARIA min',
            'aria-valuemax'          => 'handle ARIA max',
        );
        foreach ( $required_symbols as $needle => $description ) {
            if ( false === strpos( $sidebar_src, $needle ) ) {
                $failures[] = sprintf(
                    'sidebar.js is missing `%s` (%s).',
                    $needle,
                    $description
                );
            }
        }

        // -----------------------------------------------------------------
        // Check 6: admin.css declares the new sidebar rules
        // -----------------------------------------------------------------
        $css_required = array(
            'min-width: 520px'                  => '520px minimum sidebar width on a fresh sidebar',
            '.presshub-sidebar-resize-handle'   => 'drag handle selector',
            'cursor: col-resize'                => 'handle cursor affordance',
            'prefers-reduced-motion'            => 'reduced-motion respect',
        );
        foreach ( $css_required as $needle => $description ) {
            if ( false === strpos( $admin_css_src, $needle ) ) {
                $failures[] = sprintf(
                    'admin.css is missing `%s` (%s).',
                    $needle,
                    $description
                );
            }
        }

        // -----------------------------------------------------------------
        // Check 7: shell out to `node --check` for an authoritative parse
        // -----------------------------------------------------------------
        $node_path = self::find_node();
        if ( '' !== $node_path ) {
            $output    = array();
            $exit_code = 0;
            if ( DIRECTORY_SEPARATOR === '\\' ) {
                $cmd = sprintf(
                    'cmd.exe /C ""%s" --check "%s" 2>&1"',
                    $node_path,
                    self::$sidebar_js
                );
                exec( $cmd, $output, $exit_code );
            } else {
                $cmd = escapeshellcmd( $node_path ) . ' --check ' . escapeshellarg( self::$sidebar_js ) . ' 2>&1';
                exec( $cmd, $output, $exit_code );
            }

            if ( 0 !== $exit_code ) {
                $stderr = implode( "\n", $output );
                $failures[] = sprintf(
                    'sidebar.js failed `node --check` (exit %d). This SyntaxError prevents the Co-Pilot sidebar from registering at all. node output:%s%s',
                    $exit_code,
                    PHP_EOL,
                    $stderr
                );
            }
        }
        // (No node on PATH — the contract checks above remain authoritative.)

        // -----------------------------------------------------------------
        // Check 8: behavioural gate — exercise applyReplacementInEditor
        // via a tiny Node helper that loads sidebar.js, extracts the
        // function under test, and runs three cases:
        //   (a) $-escape: a revision containing `$5bn` survives.
        //   (b) multi-match: originalText in two blocks is replaced in both.
        //   (c) missing-text: returns { ok: false, mode: 'no-match' }.
        // -----------------------------------------------------------------
        if ( '' !== $node_path ) {
            $driver_path = self::write_driver_script();
            $output      = array();
            $exit_code   = 0;
            if ( DIRECTORY_SEPARATOR === '\\' ) {
                $cmd = sprintf(
                    'cmd.exe /C ""%s" "%s" "%s" 2>&1"',
                    $node_path,
                    $driver_path,
                    self::$sidebar_js
                );
                exec( $cmd, $output, $exit_code );
            } else {
                $cmd = escapeshellcmd( $node_path ) . ' ' . escapeshellarg( $driver_path ) . ' ' . escapeshellarg( self::$sidebar_js ) . ' 2>&1';
                exec( $cmd, $output, $exit_code );
            }

            // Clean up the temporary driver script.
            @unlink( $driver_path );

            $stdout = implode( "\n", $output );
            if ( 0 !== $exit_code || false === strpos( $stdout, 'BEHAVIOUR_OK' ) ) {
                $failures[] = sprintf(
                    'applyReplacementInEditor behavioural gate failed (exit %d). Output:%s%s',
                    $exit_code,
                    PHP_EOL,
                    $stdout
                );
            }
        }

        if ( ! empty( $failures ) ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                $indented = preg_replace( '/^/m', '    ', $f );
                fwrite( STDERR, "  - {$indented}\n" );
            }
            exit( 1 );
        }

        echo "OK\n";
    }

    /**
     * Locate `node` on PATH. Returns '' when not found; the test then
     * skips the node-driven gates but keeps the contract gates active.
     */
    private static function find_node(): string {
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
     * Write a temporary Node helper that:
     *  1. reads sidebar.js,
     *  2. builds a fake `wp.data` / `wp.blocks` mock,
     *  3. extracts the IIFE's `applyReplacementInEditor` by
     *     stringifying it (we use Function.prototype.toString to
     *     pull the function body out of the source via a regex
     *     that captures `const applyReplacementInEditor = ( … ) => { … };`
     *     as a balanced-brace block),
     *  4. runs the three cases and prints `BEHAVIOUR_OK` on success.
     */
    private static function write_driver_script(): string {
        $tmp = tempnam( sys_get_temp_dir(), 'presshub-sidebar-driver-' ) . '.js';
        file_put_contents( $tmp, self::driver_source() );
        return $tmp;
    }

    private static function driver_source(): string {
        // Keep the driver self-contained and deterministic. We never
        // require any npm modules so it works on bare `node`.
        return <<<'NODE_DRIVER'
'use strict';
const fs = require('fs');
const path = require('path');

const sidebarPath = process.argv[2];
const src = fs.readFileSync(sidebarPath, 'utf8');

// ---------------------------------------------------------------------
// Extract `applyReplacementInEditor` from sidebar.js. We don't try to
// load the whole IIFE (it touches `wp`, `jQuery`, `presshubAI`,
// React hooks, etc., none of which exist in Node). Instead we lift
// the function source out of the file with a brace-counting scanner
// so we can evaluate just that function in a sandbox where we control
// `wp`.
// ---------------------------------------------------------------------
const marker = 'const applyReplacementInEditor = ';
const startIdx = src.indexOf(marker);
if (startIdx === -1) {
    console.error('FAIL: applyReplacementInEditor not found in sidebar.js');
    process.exit(1);
}

let i = startIdx + marker.length;
while (i < src.length && (src[i] === ' ' || src[i] === '\n' || src[i] === '\t')) i++;
if (src[i] !== '(') { console.error('FAIL: expected ( after marker'); process.exit(1); }
let depth = 1; i++;
while (i < src.length && depth > 0) {
    if (src[i] === '(') depth++;
    else if (src[i] === ')') depth--;
    i++;
}
while (i < src.length && /\s/.test(src[i])) i++;
if (src.substr(i, 2) !== '=>') { console.error('FAIL: expected => after parameter list'); process.exit(1); }
i += 2;
while (i < src.length && /\s/.test(src[i])) i++;
if (src[i] !== '{') { console.error('FAIL: expected { to start body'); process.exit(1); }
const bodyStart = i + 1;

let bodyDepth = 1;
i++;
while (i < src.length && bodyDepth > 0) {
    const ch = src[i];
    if (ch === '{') bodyDepth++;
    else if (ch === '}') bodyDepth--;
    else if (ch === "'" || ch === '"' || ch === '`') {
        const quote = ch;
        i++;
        while (i < src.length && src[i] !== quote) {
            if (src[i] === '\\') i++;
            i++;
        }
    }
    else if (ch === '/' && src[i + 1] === '/') {
        while (i < src.length && src[i] !== '\n') i++;
    }
    else if (ch === '/' && src[i + 1] === '*') {
        i += 2;
        while (i < src.length && !(src[i] === '*' && src[i + 1] === '/')) i++;
        i++;
    }
    i++;
}
const bodyEnd = i - 1;
const bodySrc = src.substring(bodyStart, bodyEnd);

const wrapped = 'return (function applyReplacementInEditor(originalText, revisedText) {\n' +
    bodySrc + '\n' +
    '\n});';

// ---------------------------------------------------------------------
// Sandbox: build a minimal wp.data / wp.blocks mock.
// ---------------------------------------------------------------------
function makeSandbox(blocks, postContent) {
    const blocksCopy = JSON.parse(JSON.stringify(blocks));
    let content = postContent;
    return {
        wp: {
            data: {
                select: (store) => {
                    if (store !== 'core/editor') return null;
                    return {
                        getEditedPostContent: () => content,
                        getBlocks: () => blocksCopy
                    };
                },
                dispatch: (store) => {
                    if (store !== 'core/editor') return null;
                    return {
                        editPost: (patch) => {
                            if (typeof patch.content === 'string') {
                                content = patch.content;
                            }
                        },
                        resetBlocks: (newBlocks) => {
                            blocksCopy.length = 0;
                            for (const b of newBlocks) blocksCopy.push(b);
                        }
                    };
                }
            },
            blocks: {
                serialize: (arr) => {
                    if (!Array.isArray(arr)) return '';
                    return arr.map(b => b && b.html ? b.html : '').join('');
                },
                parse: (html) => {
                    if (!html) return [];
                    return [{ html }];
                }
            }
        }
    };
}

const failures = [];
function assert(cond, msg) {
    if (!cond) failures.push(msg);
}

// ---------------------------------------------------------------------
// Case (a) — $-escape: a revised text containing `$&` must survive
// byte-for-byte. Before the fix, `String.prototype.replace()` would
// interpret `$&` as the entire match (i.e. the needle itself), so
// 'modest' -> 'Tests showed $& effect.' would actually land in the
// post as 'Tests showed modest effect.' — the literal `$&` is
// silently consumed. The fix uses split/join which treats the
// replacement string as opaque bytes.
// ---------------------------------------------------------------------
{
    const original = 'modest';
    const revised  = 'Tests showed $& effect.';
    const blocks = [
        { name: 'core/html', html: original },
        { name: 'core/paragraph', html: '<p>Follow-up paragraph.</p>' }
    ];
    const sb = makeSandbox(blocks, blocks.map(b => b.html).join(''));
    const fn = new Function('wp', wrapped)(sb.wp);
    const result = fn(original, revised);
    assert(result && result.ok === true, '(a) $-escape: result.ok should be true; got ' + JSON.stringify(result));
    const serialized = sb.wp.blocks.serialize(sb.wp.data.select('core/editor').getBlocks());
    assert(serialized.indexOf('$&') !== -1, '(a) $-escape: serialized should contain literal "$&"; got: ' + serialized);
    assert(serialized.indexOf('Tests showed modest') === -1, '(a) $-escape: $& should NOT have been expanded into the needle "modest"; got: ' + serialized);
}

// Case (b) — multi-match
{
    const original = 'common phrase';
    const revised  = 'replacement phrase';
    const blocks = [
        { name: 'core/paragraph', html: '<p>First paragraph with common phrase here.</p>' },
        { name: 'core/paragraph', html: '<p>Second paragraph also contains common phrase.</p>' },
        { name: 'core/paragraph', html: '<p>Third paragraph untouched.</p>' }
    ];
    const sb = makeSandbox(blocks, blocks.map(b => b.html).join(''));
    const fn = new Function('wp', wrapped)(sb.wp);
    const result = fn(original, revised);
    assert(result && result.ok === true, '(b) multi-match: result.ok should be true; got ' + JSON.stringify(result));
    const serialized = sb.wp.blocks.serialize(sb.wp.data.select('core/editor').getBlocks());
    const occurrences = (serialized.match(/replacement phrase/g) || []).length;
    assert(occurrences === 2, '(b) multi-match: should have 2 occurrences of "replacement phrase"; got ' + occurrences + ' in: ' + serialized);
    assert(serialized.indexOf('Third paragraph untouched') !== -1, '(b) multi-match: third paragraph should be untouched; got: ' + serialized);
}

// Case (c) — missing-text
{
    const sb = makeSandbox(
        [{ name: 'core/paragraph', html: '<p>Post has been edited by the user since the AI responded.</p>' }],
        '<p>Post has been edited by the user since the AI responded.</p>'
    );
    const fn = new Function('wp', wrapped)(sb.wp);
    const result = fn('this string is definitely not in the post anywhere', 'replacement text');
    assert(result && result.ok === false, '(c) missing-text: result.ok should be false; got ' + JSON.stringify(result));
    assert(result && result.mode === 'no-match', '(c) missing-text: result.mode should be "no-match"; got ' + JSON.stringify(result && result.mode));
    const serialized = sb.wp.blocks.serialize(sb.wp.data.select('core/editor').getBlocks());
    assert(serialized.indexOf('replacement text') === -1, '(c) missing-text: should NOT have silently appended revised text; got: ' + serialized);
}

if (failures.length > 0) {
    console.error('FAIL');
    for (const f of failures) console.error('  - ' + f);
    process.exit(1);
}
console.log('BEHAVIOUR_OK');
NODE_DRIVER;
    }
}

SidebarRevisionTest::run();
