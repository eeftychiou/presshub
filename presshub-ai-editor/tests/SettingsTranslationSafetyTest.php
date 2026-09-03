<?php
/**
 * TDD tests for Issue #92 — settings-render translatable description strings
 * must not contain raw HTML opening tags that the browser would parse as real
 * elements.
 *
 * The bug: a translatable string in class-settings-render.php contained a
 * literal `<h1>` token, which the browser parsed as a real heading and which
 * then consumed the rest of the surrounding `<p>` paragraph (rendering the
 * trailing "already contains it." fragment as an oversized bold heading).
 *
 * The fix: replace the literal `<h1>` with the safe inline-code form
 * `<code>&lt;h1&gt;</code>` so it renders as documentation, not as a parsed
 * element.
 *
 * This test scans every translatable string in class-settings-render.php for
 * raw HTML opening tags that are NOT in the documented whitelist (`<code>`,
 * `<strong>`, `<em>`, `<br>`, `<a>`). Any future regression of Issue #92 (or
 * analogous issues in any translatable description string) will fail this
 * test.
 */

require_once __DIR__ . '/wordpress-stubs.php';

class SettingsTranslationSafetyTest
{
    /** Tags permitted inside translatable strings. Any other raw HTML opening
     *  tag is a regression of Issue #92. */
    private const ALLOWED_TAGS = [ 'code', 'strong', 'em', 'br', 'a' ];

    public static function run(): void {
        $failures = [];

        $render_file = __DIR__ . '/../includes/class-settings-render.php';
        if ( ! is_readable( $render_file ) ) {
            echo "SettingsTranslationSafetyTest: FAIL — class-settings-render.php not readable.\n";
            exit( 1 );
        }

        $source   = file_get_contents( $render_file );
        $bad_tags = self::find_raw_html_tags_in_translations( $source );

        if ( ! empty( $bad_tags ) ) {
            foreach ( $bad_tags as $hit ) {
                $failures[] = sprintf(
                    'Raw <%s> tag found in translatable string at %s:%d — content: "%s"',
                    $hit['tag'],
                    basename( $render_file ),
                    $hit['line'],
                    $hit['context']
                );
            }
        }

        if ( ! empty( $failures ) ) {
            echo "SettingsTranslationSafetyTest: FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }

        echo "SettingsTranslationSafetyTest: OK\n";
        exit( 0 );
    }

    /**
     * Walk every __() / _e() / esc_html__() / esc_html_e() / esc_attr__() /
     * esc_attr_e() call in the source. For each, extract the first string
     * argument and assert it contains no raw HTML opening tag other than the
     * ALLOWED_TAGS whitelist.
     *
     * @return array<int,array{tag:string,line:int,context:string}>
     */
    private static function find_raw_html_tags_in_translations( string $source ): array {
        $hits      = [];
        $whitelist = self::ALLOWED_TAGS;
        $tokens    = token_get_all( $source );
        $count     = count( $tokens );

        $i = 0;
        while ( $i < $count ) {
            $tok = $tokens[ $i ];

            // Detect a translation function name token.
            if ( is_array( $tok ) && T_STRING === $tok[0] ) {
                $name = strtolower( $tok[1] );
                if ( in_array( $name, [ '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', 'translate' ], true ) ) {
                    // Confirm next non-whitespace token is '('.
                    $j = $i + 1;
                    while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
                        $j++;
                    }
                    if ( $j < $count && '(' === $tokens[ $j ] ) {
                        // Find the first argument (must be a T_CONSTANT_ENCAPSED_STRING).
                        $k = $j + 1;
                        while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
                            $k++;
                        }
                        if ( $k < $count && is_array( $tokens[ $k ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $k ][0] ) {
                            $string_tok = $tokens[ $k ];
                            $val        = $string_tok[1];
                            // Strip the surrounding quotes.
                            $val = substr( $val, 1, -1 );
                            // Decode PHP escape sequences.
                            $val = stripcslashes( $val );
                            $string_line = $string_tok[2] ?? 0;

                            // Find any raw HTML opening tag not in the whitelist.
                            if ( preg_match_all( '/<([a-z][a-z0-9]*)>/i', $val, $matches ) ) {
                                foreach ( $matches[1] as $tag ) {
                                    $tag_lc = strtolower( $tag );
                                    if ( ! in_array( $tag_lc, $whitelist, true ) ) {
                                        $hits[] = [
                                            'tag'     => $tag_lc,
                                            'line'    => $string_line,
                                            'context' => substr( $val, 0, 120 ) . ( strlen( $val ) > 120 ? '…' : '' ),
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $i++;
        }

        return $hits;
    }
}

SettingsTranslationSafetyTest::run();
