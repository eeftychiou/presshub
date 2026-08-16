<?php
/**
 * PressHub AI — minimal .pot generator (i18n sweep, 2026-08-16).
 *
 * Scans the plugin source for translatable strings and writes a GNU
 * gettext-style .pot catalogue to languages/presshub-ai-editor.pot.
 *
 * Recognised source patterns (plugin textdomain = 'presshub-ai-editor'):
 *
 *   PHP:   __( 'foo', 'presshub-ai-editor' )
 *          esc_html__( 'foo', 'presshub-ai-editor' )
 *          esc_attr__( 'foo', 'presshub-ai-editor' )
 *          esc_html_x( 'foo', 'context', 'presshub-ai-editor' )
 *          _x( 'foo', 'context', 'presshub-ai-editor' )
 *          _n( 'one', 'many', $count, 'presshub-ai-editor' )
 *          sprintf( __( 'foo %s', 'presshub-ai-editor' ), $x )
 *          __( "foo", 'presshub-ai-editor' )
 *
 *   JS:    __( 'foo', 'presshub-ai-editor' )
 *          wp.i18n.__( 'foo', 'presshub-ai-editor' )
 *          wp.i18n.sprintf( __( 'foo %d', 'presshub-ai-editor' ), $n )
 *
 * Usage (from plugin root):
 *
 *   php scripts/make-pot.php
 *
 * Output:
 *   languages/presshub-ai-editor.pot
 *
 * No external dependencies. The output matches the format produced by
 * WP's official `wp i18n make-pot` command well enough that translators
 * can open it in Poedit / GlotPress without surprises.
 */

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

const DOMAIN       = 'presshub-ai-editor';
const PLUGIN_ROOT  = __DIR__ . '/..';
const OUT_DIR      = PLUGIN_ROOT . '/languages';
const OUT_FILE     = OUT_DIR . '/' . DOMAIN . '.pot';

// ---------------------------------------------------------------------------
// 1. Collect translatable strings from PHP + JS source.
// ---------------------------------------------------------------------------

$php_sources = [];
$php_iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        PLUGIN_ROOT . '/includes',
        FilesystemIterator::SKIP_DOTS
    )
);
foreach ( $php_iter as $file ) {
    if ( $file->getExtension() !== 'php' ) {
        continue;
    }
    if ( strpos( $file->getPathname(), '/plugin-update-checker/' ) !== false ) {
        continue; // vendored library, not plugin text.
    }
    $php_sources[] = $file->getPathname();
}
// The plugin bootstrap file lives at the root, not under includes/.
$root_php = PLUGIN_ROOT . '/presshub-ai-editor.php';
if ( is_file( $root_php ) ) {
    $php_sources[] = $root_php;
}

$js_files = [
    PLUGIN_ROOT . '/assets/admin.js',
    PLUGIN_ROOT . '/assets/sidebar.js',
    PLUGIN_ROOT . '/assets/presets.js',
];

$strings = []; // msgid => ['context' => '', 'locations' => []]

/**
 * Add a translation entry, deduplicating by msgid (and context where
 * provided). Records every source location so the .pot header has useful
 * #: references.
 */
$add = function ( string $msgid, string $context, string $location ) use ( &$strings ) {
    $msgid = trim( $msgid );
    if ( '' === $msgid ) {
        return;
    }
    $key = $context . "\0" . $msgid;
    if ( ! isset( $strings[ $key ] ) ) {
        $strings[ $key ] = [
            'msgid'      => $msgid,
            'context'    => $context,
            'locations'  => [],
            'plural'     => null, // future _n() support
        ];
    }
    $strings[ $key ]['locations'][] = $location;
};

foreach ( $php_sources as $file ) {
    $contents = file_get_contents( $file );
    $rel      = str_replace( PLUGIN_ROOT . '/', '', $file );
    // Strip line comments (// ...) and block comments (/* ... */) so a
    // developer comment in source never lands in the catalogue.
    $stripped = preg_replace( '#//[^\n]*#', '', $contents );
    $stripped = preg_replace( '#/\*.*?\*/#s', '', $stripped );
    // Match the four WP i18n call shapes we actually use:
    //   __( 'foo', 'presshub-ai-editor' )
    //   esc_html__( 'foo', 'presshub-ai-editor' )
    //   esc_attr__( 'foo', 'presshub-ai-editor' )
    //   _x( 'foo', 'context', 'presshub-ai-editor' )
    //   esc_html_x( 'foo', 'context', 'presshub-ai-editor' )
    //   _n( 'one', 'many', $n, 'presshub-ai-editor' )
    // Domain is anchored as 'presshub-ai-editor' so generic __( 'foo' )
    // calls (no domain) are not picked up by mistake.
    $pattern = '/(?P<fn>(?:\besc_html_|\besc_attr_)?\b_?[_xn]?)\s*\(\s*(?P<args>(?:\'(?:\\\\\'|[^\'])*\'|"(?:\\\\"|[^"])*"|[^()]*|\([^()]*\))*)\s*,\s*[\'"]' . preg_quote( DOMAIN, '/' ) . '[\'"]\s*\)/u';
    // The regex above is too permissive for nested parens; do a per-line
    // scan to keep it simple and correct.
    $lines = preg_split( '/\r?\n/', $stripped );
    foreach ( $lines as $line_no => $line ) {
        $line_no += 1;
        // _n( 'one', 'many', $n, 'presshub-ai-editor' ) — capture plural.
        if ( preg_match( "/_n\s*\(\s*((?:'[^']*'|\"[^\"]*\"))\s*,\s*((?:'[^']*'|\"[^\"]*\"))\s*,\s*[^,]+,\s*['\"]" . preg_quote( DOMAIN, '/' ) . "['\"]\s*\)/u", $line, $m ) ) {
            $singular = trim( $m[1], "'\"" );
            $plural   = trim( $m[2], "'\"" );
            $add( $singular, '', $rel . ':' . $line_no );
            // Record plural form on the entry just added.
            $key = "\0" . $singular;
            $strings[ $key ]['plural'] = $plural;
            continue;
        }
        // _x( 'foo', 'context', 'presshub-ai-editor' ) — with context.
        if ( preg_match( "/(?:_x|esc_html_x)\s*\(\s*((?:'[^']*'|\"[^\"]*\"))\s*,\s*((?:'[^']*'|\"[^\"]*\"))\s*,\s*['\"]" . preg_quote( DOMAIN, '/' ) . "['\"]\s*\)/u", $line, $m ) ) {
            $msgid   = stripcslashes( trim( $m[1], "'\"" ) );
            $context = stripcslashes( trim( $m[2], "'\"" ) );
            $add( $msgid, $context, $rel . ':' . $line_no );
            continue;
        }
        // __( 'foo', 'presshub-ai-editor' ) and esc_*__ variants — no context.
        if ( preg_match( "/(?:\besc_html_|\besc_attr_)?\b__\s*\(\s*((?:'[^']*'|\"[^\"]*\"))\s*,\s*['\"]" . preg_quote( DOMAIN, '/' ) . "['\"]\s*\)/u", $line, $m ) ) {
            $msgid = stripcslashes( trim( $m[1], "'\"" ) );
            if ( '' === $msgid ) {
                continue;
            }
            $add( $msgid, '', $rel . ':' . $line_no );
        }
    }
}

foreach ( $js_files as $file ) {
    if ( ! is_file( $file ) ) {
        continue;
    }
    $contents = file_get_contents( $file );
    $rel      = str_replace( PLUGIN_ROOT . '/', '', $file );
    // Strip block + line comments so developer notes don't end up in POT.
    $stripped = preg_replace( '#//[^\n]*#', '', $contents );
    $stripped = preg_replace( '#/\*[\s\S]*?\*/#', '', $stripped );
    $lines    = preg_split( '/\r?\n/', $stripped );
    foreach ( $lines as $line_no => $line ) {
        $line_no += 1;
        // wp.i18n.__( 'foo', 'presshub-ai-editor' ) / __( 'foo', 'presshub-ai-editor' )
        if ( preg_match( "/(?:wp\.i18n\.)?__\s*\(\s*((?:'[^']*'|\"[^\"]*\"))\s*,\s*['\"]" . preg_quote( DOMAIN, '/' ) . "['\"]\s*\)/u", $line, $m ) ) {
            $msgid = stripcslashes( trim( $m[1], "'\"" ) );
            $add( $msgid, '', $rel . ':' . $line_no );
        }
    }
}

if ( empty( $strings ) ) {
    fwrite( STDERR, "No translatable strings found — aborting.\n" );
    exit( 1 );
}

// ---------------------------------------------------------------------------
// 2. Emit a GNU gettext POT file.
// ---------------------------------------------------------------------------

if ( ! is_dir( OUT_DIR ) && ! mkdir( OUT_DIR, 0755, true ) && ! is_dir( OUT_DIR ) ) {
    fwrite( STDERR, "Failed to create " . OUT_DIR . "\n" );
    exit( 1 );
}

$now = gmdate( 'Y-m-d H:i' ) . '+0000';
$version = '1.1.0';

$out  = "msgid \"\"\n";
$out .= "msgstr \"\"\n";
$out .= "\"Project-Id-Version: PressHub AI Co-Pilot " . $version . "\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://github.com/eeftychiou/presshub/issues\\n\"\n";
$out .= "\"POT-Creation-Date: " . $now . "\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$out .= "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n";
$out .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out .= "\"Language: \\n\"\n";
$out .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
$out .= "\"X-Domain: " . DOMAIN . "\\n\"\n";

// Sort entries: msgctxt (when present) is the first grouping key,
// msgid is the second. This gives a stable diff across runs.
uasort(
    $strings,
    function ( $a, $b ) {
        if ( $a['context'] !== $b['context'] ) {
            return strcmp( $a['context'], $b['context'] );
        }
        return strcmp( $a['msgid'], $b['msgid'] );
    }
);

foreach ( $strings as $entry ) {
    $out .= "\n";
    if ( '' !== $entry['context'] ) {
        $out .= "msgctxt " . format_pot_string( $entry['context'] ) . "\n";
    }
    foreach ( array_unique( $entry['locations'] ) as $loc ) {
        $out .= "#: " . $loc . "\n";
    }
    $out .= "msgid " . format_pot_string( $entry['msgid'] ) . "\n";
    if ( null !== $entry['plural'] ) {
        $out .= "msgid_plural " . format_pot_string( $entry['plural'] ) . "\n";
        $out .= "msgstr[0] \"\"\n";
        $out .= "msgstr[1] \"\"\n";
    } else {
        $out .= "msgstr \"\"\n";
    }
}

if ( file_put_contents( OUT_FILE, $out ) === false ) {
    fwrite( STDERR, "Failed to write " . OUT_FILE . "\n" );
    exit( 1 );
}

$count = count( $strings );
echo "Wrote " . OUT_FILE . " (" . $count . " entries)\n";
exit( 0 );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Format a PHP/JS string for a gettext catalogue:
 *   - escape \ and "
 *   - emit the "..." with newlines as separate "\n" inside the string
 *   - keep the result readable on a single source line
 */
function format_pot_string( string $s ): string {
    // Decode any JSON/JS escape sequences so the .pot is clean source text.
    $s = stripcslashes( $s );
    // Replace literal newlines + tabs with gettext \n / \t continuations.
    $s = str_replace( [ "\\", "\n", "\t", '"' ], [ "\\\\", "\\n", "\\t", '\\"' ], $s );
    return '"' . $s . '"';
}
