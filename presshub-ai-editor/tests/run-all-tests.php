<?php
/**
 * Master test runner for PressHub AI Editor test suite.
 *
 * Discovers and executes all *Test.php files in the tests/ directory
 * in separate PHP processes to ensure clean test isolation.
 */

$test_dir = __DIR__;
$files = glob( $test_dir . '/*Test.php' );
sort( $files );

$total  = count( $files );
$passed = 0;
$failed = 0;
$failures = [];

echo "=================================================================\n";
echo "Running PressHub AI Editor Unit Tests ({$total} test files)\n";
echo "=================================================================\n\n";

foreach ( $files as $index => $file ) {
    $filename = basename( $file );
    $cmd = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $file );
    
    $output = [];
    $exit_code = 0;
    exec( $cmd . ' 2>&1', $output, $exit_code );

    $status_str = implode( "\n", $output );

    if ( 0 === $exit_code && false !== strpos( $status_str, 'OK' ) ) {
        $passed++;
        printf( "[%02d/%02d] PASS: %s\n", $index + 1, $total, $filename );
    } else {
        $failed++;
        $failures[ $filename ] = $output;
        printf( "[%02d/%02d] FAIL: %s (exit code: %d)\n", $index + 1, $total, $filename, $exit_code );
    }
}

echo "\n=================================================================\n";
echo "Summary: {$passed}/{$total} passed (" . round( ( $passed / max( 1, $total ) ) * 100, 1 ) . "% pass rate)\n";
echo "=================================================================\n";

if ( $failed > 0 ) {
    echo "\nFailed test details:\n";
    foreach ( $failures as $file => $out ) {
        echo "\n--- {$file} ---\n";
        echo implode( "\n", $out ) . "\n";
    }
    exit( 1 );
}

echo "\nALL TESTS PASSED SUCCESSFULLY (100%)\n";
exit( 0 );
