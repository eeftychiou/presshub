<?php
/**
 * #233 step 2: help-text standard, checked over the whole registry.
 *
 *  - every setting has help text (one sentence on what it does);
 *  - every numeric setting declares its unit;
 *  - no two settings on one page share a label.
 *
 * Remove-key flags ("Remove stored key" boxes) are part of their key's field
 * and are not checked on their own.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        return $value;
    }
}
require_once __DIR__ . '/../includes/class-settings-registry.php';

class SettingsHelpTextLintTest {

    public static function run(): void {
        $failures = [];
        $labels   = [];
        $checked  = 0;
        foreach ( PressHub_Settings_Registry::all() as $key => $e ) {
            if ( ! empty( $e['removes'] ) ) {
                continue;
            }
            ++$checked;
            if ( '' === trim( (string) ( $e['help'] ?? '' ) ) ) {
                $failures[] = "{$key} has no help text.";
            }
            $numeric = in_array( $e['type'] ?? '', [ 'integer', 'number' ], true ) && empty( $e['checkbox'] );
            if ( $numeric && '' === trim( (string) ( $e['unit'] ?? '' ) ) ) {
                $failures[] = "{$key} is numeric but declares no unit.";
            }
            $label = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $e['label'] ) ) : strtolower( trim( (string) $e['label'] ) );
            $labels[ $e['page'] ][ $label ][] = $key;
        }
        foreach ( $labels as $page => $by_label ) {
            foreach ( $by_label as $label => $keys ) {
                if ( count( $keys ) > 1 ) {
                    $failures[] = "Page {$page}: label \"{$label}\" is shared by " . implode( ', ', $keys ) . '.';
                }
            }
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo "SettingsHelpTextLintTest: {$checked} settings have help text, units and unique labels.\nOK\n";
    }
}

SettingsHelpTextLintTest::run();
