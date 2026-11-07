<?php
/**
 * LogPathsTest - Issue #138 log storage.
 *
 * Covers PressHub_AI_Log_Paths and its wiring:
 *   1. All channels resolve through the helper with the random suffix in the name.
 *   2. The suffix is generated once, stored with autoload off, and reused.
 *   3. Protection files are created, recreated after deletion, and an existing
 *      .htaccess is never overwritten.
 *   4. get_rotated_files() finds only the channel's own rotated copies.
 *   5. The daily prune deletes rotated files older than the retention (by the
 *      Ymd_His in the name) and keeps newer ones.
 *   6. clear_logs() removes rotated files and reports the count.
 *   7. Token and audit prune callbacks read their retention settings.
 *   8. The upgrade step deletes the old fixed-name files once and keeps .htaccess.
 *   9. Retention settings: defaults and clamping.
 *
 * The unwritable PRESSHUB_AI_LOG_DIR case lives in LogDirMisconfiguredTest.php
 * because a constant cannot be redefined within one process.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) { $GLOBALS['DEACTIVATION_HOOKS'][] = $callback; }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

$failures = 0;
$checks   = 0;
function lpt_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

function lpt_rrmdir( string $dir ): void {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    foreach ( scandir( $dir ) as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir( $path ) ? lpt_rrmdir( $path ) : @unlink( $path );
    }
    @rmdir( $dir );
}

$uploads_root = sys_get_temp_dir() . '/presshub-logpaths-' . getmypid();
lpt_rrmdir( $uploads_root );
@mkdir( $uploads_root, 0777, true );
$GLOBALS['UPLOAD_DIR']    = $uploads_root;
$GLOBALS['OPTIONS_STORE'] = [];
$GLOBALS['UPDATE_OPTION_CALLS'] = [];

// --- 1. Channels resolve with the suffix ---------------------------------
$dir = PressHub_AI_Log_Paths::get_dir();
lpt_check( 'get_dir returns a string', is_string( $dir ) );
lpt_check( 'get_dir is uploads/presshub-ai', $dir === $uploads_root . '/presshub-ai' );
lpt_check( 'dir exists', is_dir( $dir ) );

PressHub_AI_Log_Paths::get_file( 'app' ); // first use generates the suffix
$suffix = (string) get_option( PressHub_AI_Log_Paths::SUFFIX_OPTION, '' );
lpt_check( 'suffix is 20 lowercase alphanumeric chars', 1 === preg_match( '/^[a-z0-9]{20}$/', $suffix ) );

$expected = [
    'app'     => 'presshub-debug-' . $suffix . '.log',
    'prompts' => 'presshub-ai-prompts-' . $suffix . '.jsonl',
    'tts'     => 'presshub-ai-tts-' . $suffix . '.jsonl',
];
foreach ( PressHub_AI_Log_Paths::CHANNELS as $channel ) {
    $file = PressHub_AI_Log_Paths::get_file( $channel );
    lpt_check( "channel {$channel} resolves", is_string( $file ) && basename( $file ) === $expected[ $channel ] );
    lpt_check( "channel {$channel} is inside the log dir", is_string( $file ) && dirname( $file ) === $dir );
}
lpt_check( 'unknown channel returns WP_Error', is_wp_error( PressHub_AI_Log_Paths::get_file( 'nope' ) ) );
lpt_check( 'logger default path uses the helper', PressHub_AI_Logger::get_log_file_path() === PressHub_AI_Log_Paths::get_file( 'app' ) );
lpt_check( 'logger set_log_file_path override still wins', ( function () {
    PressHub_AI_Logger::set_log_file_path( '/tmp/custom.log' );
    $r = PressHub_AI_Logger::get_log_file_path();
    PressHub_AI_Logger::set_log_file_path( null );
    return '/tmp/custom.log' === $r;
} )() );

// --- 2. Suffix generated once and reused -----------------------------------
$suffix_writes = array_filter( $GLOBALS['UPDATE_OPTION_CALLS'], fn ( $c ) => PressHub_AI_Log_Paths::SUFFIX_OPTION === $c[0] );
lpt_check( 'suffix written exactly once', 1 === count( $suffix_writes ) );
$first = reset( $suffix_writes );
lpt_check( 'suffix option is not autoloaded', false === $first[2] );
PressHub_AI_Log_Paths::get_file( 'app' );
PressHub_AI_Log_Paths::get_file( 'tts' );
lpt_check( 'suffix unchanged after more calls', $suffix === get_option( PressHub_AI_Log_Paths::SUFFIX_OPTION ) );
$suffix_writes = array_filter( $GLOBALS['UPDATE_OPTION_CALLS'], fn ( $c ) => PressHub_AI_Log_Paths::SUFFIX_OPTION === $c[0] );
lpt_check( 'suffix not rewritten on later calls', 1 === count( $suffix_writes ) );

// --- 3. Protection files -----------------------------------------------------
foreach ( [ '.htaccess', 'index.php', 'web.config' ] as $name ) {
    lpt_check( "{$name} created", file_exists( $dir . '/' . $name ) );
}
$htaccess = (string) file_get_contents( $dir . '/.htaccess' );
lpt_check( '.htaccess has Require all denied', false !== strpos( $htaccess, 'Require all denied' ) );
lpt_check( '.htaccess has Deny from all', false !== strpos( $htaccess, 'Deny from all' ) && false !== strpos( $htaccess, 'Order deny,allow' ) );
lpt_check( '.htaccess has both IfModule forms', false !== strpos( $htaccess, '<IfModule mod_authz_core.c>' ) && false !== strpos( $htaccess, '<IfModule !mod_authz_core.c>' ) );
lpt_check( 'index.php content', trim( (string) file_get_contents( $dir . '/index.php' ) ) === '<?php // Silence is golden.' );
lpt_check( 'web.config denies all', false !== strpos( (string) file_get_contents( $dir . '/web.config' ), '<deny users="*" />' ) );

unlink( $dir . '/.htaccess' );
unlink( $dir . '/index.php' );
unlink( $dir . '/web.config' );
PressHub_AI_Log_Paths::get_dir();
foreach ( [ '.htaccess', 'index.php', 'web.config' ] as $name ) {
    lpt_check( "{$name} recreated after deletion", file_exists( $dir . '/' . $name ) );
}

file_put_contents( $dir . '/.htaccess', "# hand written\nRequire all denied\n" );
PressHub_AI_Log_Paths::get_dir();
lpt_check( 'existing .htaccess is not overwritten', "# hand written\nRequire all denied\n" === file_get_contents( $dir . '/.htaccess' ) );

lpt_check( 'is_web_accessible_dir for uploads dir', PressHub_AI_Log_Paths::is_web_accessible_dir( $dir ) );
lpt_check( 'is_web_accessible_dir false outside web root', ! PressHub_AI_Log_Paths::is_web_accessible_dir( sys_get_temp_dir() . '/elsewhere-' . getmypid() ) );
lpt_check( 'get_url_for maps uploads file to URL', 'http://example.test/wp-content/uploads/presshub-ai/' . $expected['app'] === PressHub_AI_Log_Paths::get_url_for( $dir . '/' . $expected['app'] ) );
lpt_check( 'get_url_for returns empty outside uploads', '' === PressHub_AI_Log_Paths::get_url_for( sys_get_temp_dir() . '/x/file.log' ) );

// --- 4. Rotated files ----------------------------------------------------------
$app_file     = PressHub_AI_Log_Paths::get_file( 'app' );
$prompts_file = PressHub_AI_Log_Paths::get_file( 'prompts' );
$tts_file     = PressHub_AI_Log_Paths::get_file( 'tts' );
$old_stamp    = gmdate( 'Ymd_His', time() - 40 * 86400 );
$mid_stamp    = gmdate( 'Ymd_His', time() - 20 * 86400 );
$new_stamp    = gmdate( 'Ymd_His', time() - 2 * 86400 );

file_put_contents( $app_file, "current\n" );
foreach ( [ $old_stamp, $mid_stamp, $new_stamp ] as $stamp ) {
    file_put_contents( $app_file . '.' . $stamp . '.old', 'x' );
}
file_put_contents( $prompts_file . '.' . $old_stamp . '.old', 'x' );
file_put_contents( $prompts_file . '.' . $new_stamp . '.old', 'x' );
file_put_contents( $tts_file . '.' . $old_stamp . '.old', 'x' );
file_put_contents( $dir . '/unrelated.log.' . $old_stamp . '.old', 'x' );

$rotated = PressHub_AI_Log_Paths::get_rotated_files( 'app' );
lpt_check( 'app has 3 rotated files', 3 === count( $rotated ) );
lpt_check( 'rotated files are newest first', $rotated === [ $app_file . '.' . $new_stamp . '.old', $app_file . '.' . $mid_stamp . '.old', $app_file . '.' . $old_stamp . '.old' ] );
lpt_check( 'prompts has 2 rotated files', 2 === count( PressHub_AI_Log_Paths::get_rotated_files( 'prompts' ) ) );
lpt_check( 'current file is not listed as rotated', ! in_array( $app_file, $rotated, true ) );
lpt_check( 'unknown channel has no rotated files', [] === PressHub_AI_Log_Paths::get_rotated_files( 'nope' ) );

// --- 5. Prune ----------------------------------------------------------------------
lpt_check( 'retention default is 14', 14 === PressHub_AI_Settings_Storage::get_log_file_retention_days() );
update_option( 'presshub_ai_log_file_retention_days', 30 );
do_action( 'presshub_ai_prune_log_files' );
lpt_check( 'prune (30d) deleted the 40-day-old app file', ! file_exists( $app_file . '.' . $old_stamp . '.old' ) );
lpt_check( 'prune (30d) deleted the 40-day-old prompts file', ! file_exists( $prompts_file . '.' . $old_stamp . '.old' ) );
lpt_check( 'prune (30d) deleted the 40-day-old tts file', ! file_exists( $tts_file . '.' . $old_stamp . '.old' ) );
lpt_check( 'prune (30d) kept the 20-day-old file', file_exists( $app_file . '.' . $mid_stamp . '.old' ) );
lpt_check( 'prune (30d) kept the 2-day-old files', file_exists( $app_file . '.' . $new_stamp . '.old' ) && file_exists( $prompts_file . '.' . $new_stamp . '.old' ) );
lpt_check( 'prune kept the current file', file_exists( $app_file ) );
lpt_check( 'prune ignores files of other names', file_exists( $dir . '/unrelated.log.' . $old_stamp . '.old' ) );
lpt_check( 'prune kept protection files', file_exists( $dir . '/.htaccess' ) && file_exists( $dir . '/index.php' ) );
lpt_check( 'prune logged an INFO line with the count', false !== strpos( (string) file_get_contents( $app_file ), 'Rotated log files pruned' ) && false !== strpos( (string) file_get_contents( $app_file ), '"files_deleted":3' ) );

update_option( 'presshub_ai_log_file_retention_days', 10 );
lpt_check( 'prune_rotated_files(10) deletes the 20-day-old file only', 1 === PressHub_AI_Log_Paths::prune_rotated_files( 10 ) );
lpt_check( '2-day-old files survive a 10 day retention', file_exists( $app_file . '.' . $new_stamp . '.old' ) );

// --- 6. clear_logs removes rotated files ---------------------------------------
$handlers = new PressHub_AI_Ajax_Handlers();
$GLOBALS['JSON_RESPONSES']    = [];
$GLOBALS['NONCE_VALID']       = true;
$GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
file_put_contents( $tts_file, "tts line\n" );
file_put_contents( $tts_file . '.' . $mid_stamp . '.old', 'x' );
$_POST = [ 'nonce' => 'n', 'target' => 'all' ];
try {
    $handlers->clear_logs();
} catch ( RuntimeException $e ) {
    // wp_send_json_success terminates the handler.
}
$resp = end( $GLOBALS['JSON_RESPONSES'] );
lpt_check( 'clear_logs(all) succeeded', is_array( $resp ) && true === $resp['success'] );
lpt_check( 'clear_logs(all) removed every rotated file', [] === PressHub_AI_Log_Paths::get_rotated_files( 'app' ) && [] === PressHub_AI_Log_Paths::get_rotated_files( 'prompts' ) && [] === PressHub_AI_Log_Paths::get_rotated_files( 'tts' ) );
lpt_check( 'clear_logs reports removed file count', 3 === ( $resp['data']['removed_files'] ?? null ) && false !== strpos( (string) ( $resp['data']['message'] ?? '' ), '3' ) );
lpt_check( 'clear_logs emptied the tts file', '' === trim( (string) file_get_contents( $tts_file ) ) );

file_put_contents( $prompts_file . '.' . $new_stamp . '.old', 'x' );
file_put_contents( $tts_file . '.' . $new_stamp . '.old', 'x' );
$_POST = [ 'nonce' => 'n', 'target' => 'prompts' ];
try {
    $handlers->clear_logs();
} catch ( RuntimeException $e ) {
}
lpt_check( 'clear_logs(prompts) removes only prompts rotated files', [] === PressHub_AI_Log_Paths::get_rotated_files( 'prompts' ) && 1 === count( PressHub_AI_Log_Paths::get_rotated_files( 'tts' ) ) );

// --- 7. Token and audit prune callbacks use the settings -------------------------
class LPT_Fake_Wpdb {
    public $prefix = 'wp_';
    public $cutoffs = [];
    public function prepare( $sql, ...$args ) {
        $this->cutoffs[] = $args[0] ?? '';
        return $sql;
    }
    public function query( $sql ) { return 4; }
}
$fake = new LPT_Fake_Wpdb();
$GLOBALS['wpdb'] = $fake;

function lpt_days_from_cutoff( string $cutoff ): float {
    return ( time() - strtotime( $cutoff . ' UTC' ) ) / 86400;
}

update_option( 'presshub_ai_token_log_retention_days', 45 );
do_action( 'presshub_ai_prune_token_logs' );
lpt_check( 'token prune used the configured 45 days', 1 === count( $fake->cutoffs ) && abs( lpt_days_from_cutoff( $fake->cutoffs[0] ) - 45 ) < 0.01 );

$fake->cutoffs = [];
update_option( 'presshub_ai_audit_log_retention_days', 200 );
do_action( 'presshub_ai_prune_audit_logs' );
lpt_check( 'audit prune used the configured 200 days', 1 === count( $fake->cutoffs ) && abs( lpt_days_from_cutoff( $fake->cutoffs[0] ) - 200 ) < 0.01 );

unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_token_log_retention_days'], $GLOBALS['OPTIONS_STORE']['presshub_ai_audit_log_retention_days'] );
$fake->cutoffs = [];
do_action( 'presshub_ai_prune_token_logs' );
lpt_check( 'token prune default is 60 days', abs( lpt_days_from_cutoff( $fake->cutoffs[0] ?? gmdate( 'Y-m-d H:i:s' ) ) - 60 ) < 0.01 );
$fake->cutoffs = [];
do_action( 'presshub_ai_prune_audit_logs' );
lpt_check( 'audit prune default is 90 days', abs( lpt_days_from_cutoff( $fake->cutoffs[0] ?? gmdate( 'Y-m-d H:i:s' ) ) - 90 ) < 0.01 );
unset( $GLOBALS['wpdb'] );

// --- 8. Upgrade step ----------------------------------------------------------------
lpt_rrmdir( $uploads_root );
@mkdir( $uploads_root . '/presshub-ai', 0777, true );
unset( $GLOBALS['OPTIONS_STORE'][ PressHub_AI_Log_Paths::VERSION_OPTION ] );
$legacy_dir = $uploads_root . '/presshub-ai';
$legacy     = [
    'presshub-debug.log',
    'presshub-debug.log.20260101_000000.old',
    'presshub-ai-debug.log',
    'presshub-ai-debug.log.20260101_000000.old',
    'presshub-ai-tts-debug.log',
    'presshub-ai-tts-debug.log.20260101_000000.old',
];
foreach ( $legacy as $name ) {
    file_put_contents( $legacy_dir . '/' . $name, 'secret' );
}
file_put_contents( $legacy_dir . '/.htaccess', "# keep me\n" );
file_put_contents( $legacy_dir . '/index.php', "<?php // keep\n" );
$count = PressHub_AI_Log_Paths::run_upgrade();
lpt_check( 'upgrade deleted 6 legacy files', 6 === $count );
foreach ( $legacy as $name ) {
    lpt_check( "upgrade removed {$name}", ! file_exists( $legacy_dir . '/' . $name ) );
}
lpt_check( 'upgrade kept .htaccess', "# keep me\n" === file_get_contents( $legacy_dir . '/.htaccess' ) );
lpt_check( 'upgrade kept index.php', file_exists( $legacy_dir . '/index.php' ) );
lpt_check( 'upgrade stored its version', PressHub_AI_Log_Paths::VERSION === get_option( PressHub_AI_Log_Paths::VERSION_OPTION ) );
file_put_contents( $legacy_dir . '/presshub-debug.log', 'again' );
lpt_check( 'upgrade runs only once', 0 === PressHub_AI_Log_Paths::run_upgrade() && file_exists( $legacy_dir . '/presshub-debug.log' ) );

// --- 9. Retention settings -------------------------------------------------------------
lpt_check( 'file retention clamps 0 to 1', 1 === PressHub_AI_Settings_Storage::sanitize_log_file_retention_days( 0 ) );
lpt_check( 'file retention clamps 500 to 90', 90 === PressHub_AI_Settings_Storage::sanitize_log_file_retention_days( '500' ) );
lpt_check( 'file retention garbage falls back to 14', 14 === PressHub_AI_Settings_Storage::sanitize_log_file_retention_days( 'abc' ) );
lpt_check( 'token retention clamps 1 to 7', 7 === PressHub_AI_Settings_Storage::sanitize_token_log_retention_days( 1 ) );
lpt_check( 'token retention clamps 9999 to 365', 365 === PressHub_AI_Settings_Storage::sanitize_token_log_retention_days( 9999 ) );
lpt_check( 'token retention garbage falls back to 60', 60 === PressHub_AI_Settings_Storage::sanitize_token_log_retention_days( null ) );
lpt_check( 'audit retention clamps 5 to 30', 30 === PressHub_AI_Settings_Storage::sanitize_audit_log_retention_days( 5 ) );
lpt_check( 'audit retention clamps 9999 to 730', 730 === PressHub_AI_Settings_Storage::sanitize_audit_log_retention_days( 9999 ) );
lpt_check( 'audit retention garbage falls back to 90', 90 === PressHub_AI_Settings_Storage::sanitize_audit_log_retention_days( '' ) );
update_option( 'presshub_ai_audit_log_retention_days', 3 );
lpt_check( 'getter clamps stored option', 30 === PressHub_AI_Settings_Storage::get_audit_log_retention_days() );

lpt_rrmdir( $uploads_root );
unset( $GLOBALS['UPLOAD_DIR'] );

if ( $failures > 0 ) {
    fwrite( STDERR, "LogPathsTest: {$failures} failure(s) of {$checks} checks\n" );
    exit( 1 );
}
echo "LogPathsTest: OK ({$checks} checks)\n";
exit( 0 );
