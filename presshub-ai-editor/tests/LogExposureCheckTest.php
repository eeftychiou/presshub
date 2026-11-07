<?php
/**
 * Tests for PressHub_AI_Log_Exposure_Check (issue #138, requirement 5).
 *
 * The HTTP layer is stubbed through $GLOBALS['GET_RESPONSE_FILTER'] (see
 * wordpress-stubs.php). PressHub_AI_Log_Paths is the real class when
 * includes/class-log-paths.php exists; otherwise a minimal contract-shaped
 * double is defined below so this test can run on its own.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

$lec_real_paths = file_exists( __DIR__ . '/../includes/class-log-paths.php' );
if ( $lec_real_paths ) {
    require_once __DIR__ . '/../includes/class-log-paths.php';
} else {
    class PressHub_AI_Log_Paths {
        private static $override = null;
        public static function set_base_dir_override( ?string $dir ): void { self::$override = $dir; }
        public static function get_dir() {
            if ( ! empty( $GLOBALS['TEST_LOG_DIR_ERROR'] ) ) {
                return new WP_Error( 'presshub_ai_log_dir_unwritable', 'unwritable' );
            }
            return self::$override;
        }
        public static function is_web_accessible_dir( string $dir ): bool { return empty( $GLOBALS['TEST_LOG_DIR_OUTSIDE'] ); }
        public static function get_url_for( string $file ): string {
            return 'http://example.test/wp-content/uploads/presshub-ai/' . basename( $file );
        }
    }
}
require_once __DIR__ . '/../includes/class-log-exposure-check.php';

$failures = [];
function lec_check( bool $cond, string $msg ): void {
    global $failures;
    if ( ! $cond ) {
        $failures[] = $msg;
    }
}

$tmp = sys_get_temp_dir() . '/presshub-exposure-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp, 0777, true );
$GLOBALS['UPLOAD_DIR'] = $tmp; // wp_upload_dir() basedir, so the log dir counts as web-accessible
PressHub_AI_Log_Paths::set_base_dir_override( $tmp . '/presshub-ai' );

function lec_canaries( string $dir ): array {
    $found = [];
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
        if ( 0 === strpos( $f->getFilename(), 'exposure-check-' ) ) {
            $found[] = $f->getPathname();
        }
    }
    return $found;
}

/** Stub the HTTP layer. $mode: echo | code:<n> | error:<msg>. Records whether the canary existed at request time. */
function lec_stub_http( string $mode ): void {
    global $tmp;
    $GLOBALS['CAPTURED_GETS'] = [];
    $GLOBALS['LEC_CANARY_SEEN'] = null;
    $GLOBALS['GET_RESPONSE_FILTER'] = function ( $url, $args ) use ( $mode, $tmp ) {
        $found = [];
        foreach ( lec_canaries( $tmp ) as $p ) {
            if ( basename( $p ) === basename( $url ) ) {
                $found[] = $p;
            }
        }
        $GLOBALS['LEC_CANARY_SEEN'] = ! empty( $found );
        if ( 'echo' === $mode ) {
            return [ 'response' => [ 'code' => 200 ], 'body' => $found ? file_get_contents( $found[0] ) : '' ];
        }
        if ( 0 === strpos( $mode, 'code:' ) ) {
            return [ 'response' => [ 'code' => (int) substr( $mode, 5 ) ], 'body' => '<html>nope</html>' ];
        }
        return new WP_Error( 'http_request_failed', substr( $mode, 6 ) );
    };
}

function lec_reset(): void {
    $GLOBALS['TRANSIENT_STORE'] = [];
    $GLOBALS['JSON_RESPONSES'] = [];
    $GLOBALS['NONCE_VALID'] = true;
    $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
    unset( $GLOBALS['TEST_LOG_DIR_ERROR'], $GLOBALS['TEST_LOG_DIR_OUTSIDE'] );
}

// --- State per HTTP outcome, canary always removed ---------------------------
$cases = [
    [ 'echo',           'exposed'    ],
    [ 'code:403',       'protected'  ],
    [ 'code:404',       'protected'  ],
    [ 'code:401',       'protected'  ],
    [ 'error:no route', 'unverified' ],
    [ 'code:500',       'unverified' ],
    [ 'code:200',       'unverified' ], // soft-404 page without the canary content
];
foreach ( $cases as $case ) {
    list( $mode, $expected ) = $case;
    lec_reset();
    lec_stub_http( $mode );
    $status = PressHub_AI_Log_Exposure_Check::get_status( true );
    lec_check( $status['state'] === $expected, "[$mode] expected $expected, got {$status['state']}" );
    lec_check( true === $GLOBALS['LEC_CANARY_SEEN'], "[$mode] canary did not exist during the request" );
    lec_check( [] === lec_canaries( $tmp ), "[$mode] canary file was not deleted" );
    lec_check( ! empty( $status['checked_at'] ) && isset( $status['detail'] ), "[$mode] status shape incomplete" );
    $args = $GLOBALS['CAPTURED_GETS'][0][1] ?? [];
    lec_check( ( $args['redirection'] ?? null ) === 0 && ( $args['timeout'] ?? 0 ) === 10 && false === ( $args['sslverify'] ?? true ), "[$mode] request args wrong" );
}

lec_reset();
lec_stub_http( 'error:cURL error 7' );
$status = PressHub_AI_Log_Exposure_Check::get_status( true );
lec_check( false !== strpos( $status['detail'], 'cURL error 7' ), 'unverified detail should include the transport error' );

// --- Caching -----------------------------------------------------------------
lec_reset();
lec_stub_http( 'code:403' );
PressHub_AI_Log_Exposure_Check::get_status();
PressHub_AI_Log_Exposure_Check::get_status();
lec_check( 1 === count( $GLOBALS['CAPTURED_GETS'] ), 'second get_status() should come from the transient' );
$entry = $GLOBALS['TRANSIENT_STORE']['presshub_ai_log_exposure_status'] ?? null;
lec_check( null !== $entry && abs( $entry['expires_at'] - time() - 43200 ) <= 5, 'transient should expire after 12h' );
PressHub_AI_Log_Exposure_Check::get_status( true );
lec_check( 2 === count( $GLOBALS['CAPTURED_GETS'] ), 'force=true must bypass the cache' );
lec_stub_http( 'echo' );
$cached = PressHub_AI_Log_Exposure_Check::get_status();
lec_check( 'protected' === $cached['state'] && 0 === count( $GLOBALS['CAPTURED_GETS'] ), 'cached value should be served without a request' );
$fresh = PressHub_AI_Log_Exposure_Check::get_status( true );
lec_check( 'exposed' === $fresh['state'], 'force should refresh to the new state' );

// --- not_applicable / WP_Error dir (only drivable with the test double) -------
if ( ! $lec_real_paths ) {
    lec_reset();
    lec_stub_http( 'echo' );
    $GLOBALS['TEST_LOG_DIR_OUTSIDE'] = true;
    $status = PressHub_AI_Log_Exposure_Check::get_status( true );
    lec_check( 'not_applicable' === $status['state'], 'outside web root => not_applicable' );
    lec_check( 0 === count( $GLOBALS['CAPTURED_GETS'] ), 'no request when not applicable' );
    lec_reset();
    $GLOBALS['TEST_LOG_DIR_ERROR'] = true;
    $status = PressHub_AI_Log_Exposure_Check::get_status( true );
    lec_check( 'unverified' === $status['state'] && false !== strpos( $status['detail'], 'PRESSHUB_AI_LOG_DIR' ), 'WP_Error dir => unverified naming PRESSHUB_AI_LOG_DIR' );
}
lec_reset();

// --- AJAX handler gates ------------------------------------------------------
lec_stub_http( 'code:404' );
PressHub_AI_Log_Exposure_Check::register();
lec_check( (bool) has_action( 'wp_ajax_presshub_ai_recheck_log_exposure' ), 'AJAX action not registered' );
lec_check( (bool) has_action( 'admin_notices' ), 'admin_notices not registered' );

$GLOBALS['NONCE_VALID'] = false;
$threw = false;
try { PressHub_AI_Log_Exposure_Check::ajax_recheck(); } catch ( RuntimeException $e ) { $threw = true; }
lec_check( $threw && 0 === count( $GLOBALS['CAPTURED_GETS'] ), 'bad nonce must abort before any check' );

$GLOBALS['NONCE_VALID'] = true;
$GLOBALS['CURRENT_USER_CAPS'] = [];
$msg = '';
try { PressHub_AI_Log_Exposure_Check::ajax_recheck(); } catch ( RuntimeException $e ) { $msg = $e->getMessage(); }
lec_check( 0 === strpos( $msg, 'wp_send_json_error' ) && 0 === count( $GLOBALS['CAPTURED_GETS'] ), 'missing capability must be rejected before any check' );

$GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
$GLOBALS['JSON_RESPONSES'] = [];
$msg = '';
try { PressHub_AI_Log_Exposure_Check::ajax_recheck(); } catch ( RuntimeException $e ) { $msg = $e->getMessage(); }
$resp = end( $GLOBALS['JSON_RESPONSES'] );
lec_check( 'wp_send_json_success' === $msg && $resp && true === $resp['success'], 'authorised recheck should succeed' );
lec_check( ( $resp['data']['state'] ?? '' ) === 'protected' && false !== strpos( $resp['data']['html'] ?? '', 'presshub-log-exposure' ), 'response should carry state and panel html' );
lec_check( 1 === count( $GLOBALS['CAPTURED_GETS'] ), 'recheck must force a fresh request' );

// The presshub_ai_settings_cap filter is honoured.
$GLOBALS['FILTERS']['presshub_ai_settings_cap'][] = [ 'callback' => function () { return 'edit_others_posts'; } ];
$msg = '';
try { PressHub_AI_Log_Exposure_Check::ajax_recheck(); } catch ( RuntimeException $e ) { $msg = $e->getMessage(); }
lec_check( 0 === strpos( $msg, 'wp_send_json_error' ), 'presshub_ai_settings_cap filter must be applied' );
unset( $GLOBALS['FILTERS']['presshub_ai_settings_cap'] );

// --- render_panel ------------------------------------------------------------
function lec_panel(): string {
    ob_start();
    PressHub_AI_Log_Exposure_Check::render_panel();
    return (string) ob_get_clean();
}

lec_reset();
lec_stub_http( 'echo' );
PressHub_AI_Log_Exposure_Check::get_status( true );
$html = lec_panel();
lec_check( false !== strpos( $html, 'notice-error' ), 'exposed panel should be red' );
lec_check( false !== strpos( $html, '<pre' ) && false !== strpos( $html, 'location ~* /wp-content/uploads/presshub-ai/ { deny all; }' ), 'exposed panel must contain the nginx example' );
lec_check( false !== strpos( $html, 'PRESSHUB_AI_LOG_DIR' ), 'exposed panel should mention PRESSHUB_AI_LOG_DIR' );
lec_check( false !== strpos( $html, 'Re-check now' ) && false !== strpos( $html, 'admin-ajax.php' ) && false !== strpos( $html, 'presshub_ai_recheck_log_exposure' ) && false !== strpos( $html, 'data-nonce="test-nonce"' ), 'panel needs the re-check button with data attributes' );
lec_check( false !== strpos( $html, 'fetch(' ), 'panel needs the inline fetch script' );

lec_reset();
lec_stub_http( 'code:403' );
PressHub_AI_Log_Exposure_Check::get_status( true );
$html = lec_panel();
lec_check( false !== strpos( $html, 'notice-success' ) && false === strpos( $html, 'location ~*' ), 'protected panel is green without nginx example' );

lec_reset();
lec_stub_http( 'error:<script>alert(1)</script>' );
PressHub_AI_Log_Exposure_Check::get_status( true );
$html = lec_panel();
lec_check( false === strpos( $html, '<script>alert(1)' ), 'detail text must be escaped' );
lec_check( false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'escaped detail should be visible' );
lec_check( false !== strpos( $html, 'Could not verify' ) && false === strpos( $html, 'location ~*' ), 'unverified panel is neutral without nginx example' );

lec_reset();
$GLOBALS['TRANSIENT_STORE']['presshub_ai_log_exposure_status'] = [ 'value' => [ 'state' => 'not_applicable', 'checked_at' => '2026-01-01 00:00:00', 'detail' => '' ], 'expires_at' => 0 ];
$html = lec_panel();
lec_check( false !== strpos( $html, 'outside the web root' ) && false === strpos( $html, 'location ~*' ), 'not_applicable panel message' );

// --- admin notice (defines the constant, so keep last) -----------------------
if ( ! defined( 'PRESSHUB_AI_LOG_DIR' ) ) {
    $blocker = $tmp . '/blocker-file';
    file_put_contents( $blocker, 'x' );
    // A path below a regular file can never be created, so the real helper returns a WP_Error.
    define( 'PRESSHUB_AI_LOG_DIR', $blocker . '/<b>logs</b>' );
    $GLOBALS['TEST_LOG_DIR_ERROR'] = true; // used by the test double only
    PressHub_AI_Log_Paths::set_base_dir_override( null ); // the real helper ignores the constant while an override is set
    $GLOBALS['TEST_SCREEN_ID'] = 'toplevel_page_presshub-ai-settings';
    if ( ! function_exists( 'get_current_screen' ) ) {
        function get_current_screen() { return (object) [ 'id' => $GLOBALS['TEST_SCREEN_ID'] ]; }
    }
    ob_start();
    PressHub_AI_Log_Exposure_Check::maybe_render_notice();
    $out = (string) ob_get_clean();
    lec_check( false !== strpos( $out, 'notice-error' ) && false !== strpos( $out, 'PRESSHUB_AI_LOG_DIR' ), 'notice should name the constant' );
    lec_check( false !== strpos( $out, '&lt;b&gt;logs&lt;/b&gt;' ) && false === strpos( $out, '<b>logs' ), 'notice must escape the path' );

    $GLOBALS['TEST_SCREEN_ID'] = 'dashboard';
    ob_start();
    PressHub_AI_Log_Exposure_Check::maybe_render_notice();
    lec_check( '' === (string) ob_get_clean(), 'no notice outside PressHub screens' );
}

// Cleanup
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
    $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
}
@rmdir( $tmp );
PressHub_AI_Log_Paths::set_base_dir_override( null );

if ( $failures ) {
    echo "FAIL: LogExposureCheckTest\n - " . implode( "\n - ", $failures ) . "\n";
    exit( 1 );
}
echo "OK: LogExposureCheckTest passed\n";
