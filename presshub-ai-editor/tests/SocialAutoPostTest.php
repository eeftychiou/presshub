<?php
/**
 * Test Suite for PressHub Social Auto-Post Subsystem Integration.
 *
 * Runs the comprehensive 19-suite test matrix for the integrated social autopost engine.
 */

$cmd = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/run-social-tests.php' );
$output = [];
$exit_code = 0;
exec( $cmd . ' 2>&1', $output, $exit_code );

$output_str = implode( "\n", $output );

if ( 0 !== $exit_code || false === strpos( $output_str, 'ALL TESTS PASSED SUCCESSFULLY (100%)' ) ) {
    echo "FAIL: Social Auto-Post Test Suite failed (exit code {$exit_code}):\n";
    echo $output_str . "\n";
    exit( 1 );
}

echo "OK (151 social unit tests passed)\n";
exit( 0 );
