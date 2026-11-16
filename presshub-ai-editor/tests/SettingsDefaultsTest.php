<?php
/**
 * #233 phase 1 finish: one default per setting.
 *
 *  - Every literal default passed to get_option() for a registered option
 *    equals the registry default, or is an "unset" sentinel ('', null, false,
 *    []) that the caller replaces itself, or sits on a class_exists() fallback
 *    line that only runs without the settings class.
 *  - The migration defaults return the same values as the storage defaults.
 *  - Every depends_on names a registered on/off switch on the same page, and
 *    every alias is a non-empty string.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) {
        return rtrim( (string) $value, '/\\' );
    }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once __DIR__ . '/../presshub-ai-editor.php';

class SettingsDefaultsTest {

    public static function run(): void {
        $failures = [];
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        $all = PressHub_Settings_Registry::all();

        // 1. Call-site literal defaults.
        $checked = 0;
        $pattern = "/get_option\(\s*'(presshub_[a-z0-9_]+)'\s*,\s*('(?:[^'\\\\]|\\\\.)*'|-?[0-9.]+|true|false|null|\[\s*\])\s*\)/";
        foreach ( glob( __DIR__ . '/../includes/*.php' ) as $file ) {
            $src   = (string) file_get_contents( $file );
            $lines = explode( "\n", $src );
            if ( ! preg_match_all( $pattern, $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
                continue;
            }
            foreach ( $m as $x ) {
                $key = $x[1][0];
                if ( ! isset( $all[ $key ] ) ) {
                    continue;
                }
                $default = PressHub_Settings_Registry::default_value( $key );
                if ( null === $default ) {
                    continue;
                }
                $literal = self::literal( $x[2][0] );
                ++$checked;
                if ( in_array( $literal, [ '', null, false, [] ], true ) ) {
                    continue;
                }
                $line_no = substr_count( substr( $src, 0, $x[0][1] ), "\n" ) + 1;
                if ( false !== strpos( $lines[ $line_no - 1 ] ?? '', 'class_exists(' ) ) {
                    continue;
                }
                $same = is_numeric( $literal ) && is_numeric( $default )
                    ? (float) $literal === (float) $default
                    : $literal === $default;
                if ( ! $same ) {
                    $failures[] = basename( $file ) . ":{$line_no} {$key} defaults to " . var_export( $literal, true ) . ' but the registry says ' . var_export( $default, true ) . '.';
                }
            }
        }
        if ( $checked < 50 ) {
            $failures[] = "Only {$checked} call-site defaults found; the scan pattern looks broken.";
        }

        // 2. Migration defaults delegate to storage.
        foreach ( get_class_methods( 'PressHub_AI_Settings_Migration' ) as $method ) {
            if ( 0 !== strpos( $method, 'default_' ) || ! method_exists( 'PressHub_AI_Settings_Storage', $method ) ) {
                continue;
            }
            $r = new ReflectionMethod( 'PressHub_AI_Settings_Migration', $method );
            if ( $r->getNumberOfRequiredParameters() > 0 ) {
                continue;
            }
            $a = PressHub_AI_Settings_Migration::$method();
            $b = PressHub_AI_Settings_Storage::$method();
            if ( $a !== $b ) {
                $failures[] = "Migration::{$method}() returns " . var_export( $a, true ) . ' but Storage returns ' . var_export( $b, true ) . '.';
            }
        }

        // 3. depends_on and aliases.
        $deps    = 0;
        $aliases = 0;
        foreach ( $all as $key => $e ) {
            if ( isset( $e['depends_on'] ) ) {
                ++$deps;
                $target = $e['depends_on'];
                $t      = is_string( $target ) ? ( $all[ $target ] ?? null ) : null;
                if ( null === $t ) {
                    $failures[] = "{$key} depends on " . var_export( $target, true ) . ', which is not a registered setting.';
                } elseif ( empty( $t['checkbox'] ) ) {
                    $failures[] = "{$key} depends on {$target}, which is not an on/off switch.";
                } elseif ( ( $t['page'] ?? '' ) !== ( $e['page'] ?? '' ) ) {
                    $failures[] = "{$key} depends on {$target}, which is on another settings page.";
                } elseif ( $target === $key ) {
                    $failures[] = "{$key} depends on itself.";
                }
            }
            if ( isset( $e['aliases'] ) ) {
                if ( ! is_array( $e['aliases'] ) ) {
                    $failures[] = "{$key} aliases must be a list.";
                    continue;
                }
                foreach ( $e['aliases'] as $alias ) {
                    ++$aliases;
                    if ( ! is_string( $alias ) || '' === trim( $alias ) ) {
                        $failures[] = "{$key} has an empty or non-string alias.";
                    }
                }
            }
        }
        if ( $deps < 50 ) {
            $failures[] = "Only {$deps} settings declare depends_on; expected the 70+ added in phase 1.";
        }
        if ( $aliases < 50 ) {
            $failures[] = "Only {$aliases} aliases found; expected the earlier labels to be kept.";
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo "SettingsDefaultsTest: {$checked} call-site defaults, {$deps} dependencies, {$aliases} aliases consistent.\nOK\n";
    }

    /** Evaluates a matched PHP literal without eval(). */
    private static function literal( string $code ) {
        $code = trim( $code );
        if ( 'true' === $code ) {
            return true;
        }
        if ( 'false' === $code ) {
            return false;
        }
        if ( 'null' === $code ) {
            return null;
        }
        if ( '[' === $code[0] ) {
            return [];
        }
        if ( "'" === $code[0] ) {
            return strtr( substr( $code, 1, -1 ), [ "\\'" => "'", '\\\\' => '\\' ] );
        }
        return false !== strpos( $code, '.' ) ? (float) $code : (int) $code;
    }
}

SettingsDefaultsTest::run();
