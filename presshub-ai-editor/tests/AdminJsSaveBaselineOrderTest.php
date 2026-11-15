<?php
/**
 * AdminJsSaveBaselineOrderTest — Issue #206.
 *
 * After a successful save the response can carry the server's normalized
 * sources list, which is written back into the hidden sources input. The
 * clean-state baseline must be taken after that, or the Daily Briefing tab
 * looks unsaved and the browser asks "Leave site?". Checks both save handlers
 * (section save and full save), and that the source modal is not tracked.
 */

$js = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
$failures = [];

$handlers = [
    'section save' => [ 'presshub_ai_save_settings_section', 'tabBaselines[tabKey] = getTabFormSnapshot(tabKey);' ],
    'full save'    => [ "action: 'presshub_ai_save_settings'", 'initTabBaselines();' ],
];
foreach ( $handlers as $label => [ $marker, $baseline ] ) {
    $start = strpos( $js, $marker );
    if ( false === $start ) {
        $failures[] = "{$label}: handler not found";
        continue;
    }
    $sync = strpos( $js, 'syncSourcesInput();', $start );
    $base = strpos( $js, $baseline, $start );
    $done = strpos( $js, 'presshub-settings-notice', $start );
    if ( false === $sync || false === $base || false === $done ) {
        $failures[] = "{$label}: expected calls not found";
        continue;
    }
    if ( ! ( $sync < $base && $base < $done ) ) {
        $failures[] = "{$label}: the baseline is taken before the sources input is re-synced";
    }
}

if ( false === strpos( $js, "\$el.closest('#presshub-source-modal').length" ) ) {
    $failures[] = 'getTabFormSnapshot does not skip the source modal fields';
}

if ( $failures ) {
    foreach ( $failures as $f ) {
        fwrite( STDERR, "FAIL: {$f}\n" );
    }
    exit( 1 );
}
echo "AdminJsSaveBaselineOrderTest: OK\n";
