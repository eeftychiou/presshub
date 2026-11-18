<?php
/**
 * UpdateBackupTest — the backup before each PressHub update (#296).
 *
 *  1. Only PressHub updates are backed up; other plugins and earlier errors pass through.
 *  2. Upload → "Replace current with uploaded" is backed up only when it overwrites PressHub.
 *  3. Backup ids, the "Backups to keep" setting and the labels.
 *  4. The plugin zip has the presshub-ai-editor/ root and skips .git.
 *  5. Listing, incomplete backups and pruning keep the newest N and the protected one.
 *  6. A linked plugin folder (dev-env junction) is detected, and restoring files is refused there.
 *  7. delete_dir() never leaves the backup folder.
 *  8. The page: menu row, editor view without actions, confirm screen with the files box.
 *
 * Taking and restoring a backup against a real database and Plugin_Upgrader is
 * covered end to end on a WordPress + SQLite site (see the PR).
 */

$GLOBALS['UB_TMP'] = sys_get_temp_dir() . '/presshub-ub-' . getmypid() . '-' . mt_rand();
@mkdir( $GLOBALS['UB_TMP'], 0777, true );
define( 'PRESSHUB_AI_BACKUP_DIR', $GLOBALS['UB_TMP'] . '/backups' );

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
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
}
if ( ! function_exists( 'submit_button' ) ) {
    function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true ) {
        echo '<input type="submit" name="' . $name . '" class="button button-' . $type . '" value="' . htmlspecialchars( (string) $text ) . '" />';
    }
}
if ( ! function_exists( 'size_format' ) ) {
    function size_format( $bytes ) { return (int) $bytes . ' B'; }
}
if ( ! function_exists( 'get_site_transient' ) ) {
    function get_site_transient( $key ) { return $GLOBALS['SITE_TRANSIENTS'][ $key ] ?? false; }
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url() { return 'http://example.test'; }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function ub_check( string $label, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}

/** A fake complete backup folder. */
function ub_make_backup( string $id, int $ts, array $extra = [] ): void {
    $dir = PressHub_AI_Update_Backup::backup_path( $id );
    @mkdir( $dir . '/tables', 0777, true );
    file_put_contents( $dir . '/manifest.json', json_encode( array_merge( [
        'format'         => PressHub_AI_Update_Backup::FORMAT,
        'format_version' => 1,
        'id'             => $id,
        'created_ts'     => $ts,
        'reason'         => 'update',
        'plugin_version' => '2.52.0',
        'target_version' => '2.53.0',
        'options'        => 12,
        'tables'         => [ 'presshub_ai_audit_logs' => [ 'rows' => 3, 'create' => true ] ],
        'plugin_zip'     => true,
        'bytes'          => 2048,
    ], $extra ) ) );
}

$B = 'PressHub_AI_Update_Backup';

// ---------------------------------------------------------------------
// 1. Which updates are backed up.
// ---------------------------------------------------------------------
ub_check( 'update: PressHub by its main file', $B::is_presshub_update( [ 'plugin' => 'presshub-ai-editor/presshub-ai-editor.php' ] ) );
ub_check( 'update: PressHub in a renamed folder', $B::is_presshub_update( [ 'plugin' => 'pressshub-ai-editor/presshub-ai-editor.php' ] ) );
ub_check( 'update: another plugin is not', ! $B::is_presshub_update( [ 'plugin' => 'akismet/akismet.php' ] ) );
ub_check( 'update: themes and core are not', ! $B::is_presshub_update( [ 'theme' => 'twentytwentyfive' ] ) && ! $B::is_presshub_update( null ) );
ub_check( 'update: another plugin passes through untouched', true === $B::before_update( true, [ 'plugin' => 'akismet/akismet.php' ] ) );
$earlier = new WP_Error( 'x', 'earlier' );
ub_check( 'update: an earlier error is kept', $earlier === $B::before_update( $earlier, [ 'plugin' => 'presshub-ai-editor/presshub-ai-editor.php' ] ) );

$src = $GLOBALS['UB_TMP'] . '/src/presshub-ai-editor';
@mkdir( $src, 0777, true );
file_put_contents( $src . '/presshub-ai-editor.php', '<?php' );
$no_overwrite = (object) [ 'skin' => (object) [ 'overwrite' => '' ] ];
ub_check( 'upload: a first upload (no overwrite) is not backed up', $src === $B::before_replace_upload( $src, '', $no_overwrite, [ 'type' => 'plugin', 'action' => 'install' ] ) );
$other = $GLOBALS['UB_TMP'] . '/src/other-plugin';
@mkdir( $other, 0777, true );
$overwrite = (object) [ 'skin' => (object) [ 'overwrite' => 'update-plugin' ] ];
ub_check( 'upload: replacing another plugin is not backed up', $other === $B::before_replace_upload( $other, '', $overwrite, [ 'type' => 'plugin', 'action' => 'install' ] ) );
ub_check( 'upload: a theme install is not backed up', $src === $B::before_replace_upload( $src, '', $overwrite, [ 'type' => 'theme', 'action' => 'install' ] ) );

// ---------------------------------------------------------------------
// 2. Ids, setting, labels.
// ---------------------------------------------------------------------
$id = $B::new_id( '2.53.0' );
ub_check( 'id: new ids are valid', $B::is_valid_id( $id ) );
ub_check( 'id: starts with the date and the version', 1 === preg_match( '/^\d{8}-\d{6}-v2\.53\.0-[a-z0-9]{8}$/', $id ) );
ub_check( 'id: path tricks are not valid', ! $B::is_valid_id( '../etc' ) && ! $B::is_valid_id( '20261118-120000-v2.53.0-abc/../x' ) && ! $B::is_valid_id( '' ) );
ub_check( 'id: a strange version is cleaned', $B::is_valid_id( $B::new_id( '2.53.0 beta/<x>' ) ) );

ub_check( 'keep: default 5', 5 === PressHub_AI_Settings_Storage::sanitize_backup_keep( 'abc' ) );
ub_check( 'keep: clamped to 1-20', 1 === PressHub_AI_Settings_Storage::sanitize_backup_keep( 0 ) && 20 === PressHub_AI_Settings_Storage::sanitize_backup_keep( 99 ) && 7 === PressHub_AI_Settings_Storage::sanitize_backup_keep( '7' ) );
ub_check( 'keep: registry entry', null !== PressHub_Settings_Registry::get( 'presshub_ai_backup_keep' ) );

ub_check( 'label: update with target', 'Before the update to 2.53.0' === $B::reason_label( [ 'reason' => 'update', 'target_version' => '2.53.0' ] ) );
ub_check( 'label: update without target', 'Before an update' === $B::reason_label( [ 'reason' => 'update' ] ) );
ub_check( 'label: before a restore', 'Before restoring 2.51.0' === $B::reason_label( [ 'reason' => 'before_restore', 'target_version' => '2.51.0' ] ) );
ub_check( 'label: by hand', 'Taken by hand' === $B::reason_label( [ 'reason' => 'manual' ] ) );
ub_check( 'contents: one row', '1 option, 1 table (1 row)' === $B::contents_label( [ 'options' => 1, 'tables' => [ 'a' => [ 'rows' => 1 ] ] ] ) );
ub_check( 'contents: without files', '12 options, 1 table (3 rows)' === $B::contents_label( [ 'options' => 12, 'tables' => [ 'a' => [ 'rows' => 3 ] ], 'plugin_zip' => true ], false ) );
ub_check( 'contents: counts and files', '12 options, 1 table (3 rows), plugin files' === $B::contents_label( [ 'options' => 12, 'tables' => [ 'a' => [ 'rows' => 3 ] ], 'plugin_zip' => true ] ) );

$GLOBALS['SITE_TRANSIENTS']['update_plugins'] = (object) [ 'response' => [
    'akismet/akismet.php'                      => (object) [ 'new_version' => '9.9' ],
    'presshub-ai-editor/presshub-ai-editor.php' => (object) [ 'new_version' => '2.54.0' ],
] ];
ub_check( 'pending version: read from the update data', '2.54.0' === $B::pending_update_version() );

// ---------------------------------------------------------------------
// 3. Plugin zip.
// ---------------------------------------------------------------------
$plug = $GLOBALS['UB_TMP'] . '/plugin-src';
@mkdir( $plug . '/includes', 0777, true );
@mkdir( $plug . '/.git', 0777, true );
file_put_contents( $plug . '/presshub-ai-editor.php', '<?php // main' );
file_put_contents( $plug . '/includes/a.php', '<?php // a' );
file_put_contents( $plug . '/.git/HEAD', 'ref' );
$zip_file = $GLOBALS['UB_TMP'] . '/p.zip';
ub_check( 'zip: written', true === $B::write_plugin_zip( $plug, $zip_file ) );
$zip   = new ZipArchive();
$zip->open( $zip_file );
$names = [];
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
    $names[] = $zip->getNameIndex( $i );
}
$zip->close();
ub_check( 'zip: main file under presshub-ai-editor/', in_array( 'presshub-ai-editor/presshub-ai-editor.php', $names, true ) );
ub_check( 'zip: subfolders kept', in_array( 'presshub-ai-editor/includes/a.php', $names, true ) );
ub_check( 'zip: .git skipped', ! preg_grep( '#/\.git#', $names ) );
ub_check( 'zip: a folder without the main file is refused', is_wp_error( $B::write_plugin_zip( $plug . '/includes', $zip_file ) ) );

// ---------------------------------------------------------------------
// 4. Listing and pruning.
// ---------------------------------------------------------------------
ub_check( 'base dir: guards written', true === $B::ensure_base_dir() && is_file( PRESSHUB_AI_BACKUP_DIR . '/.htaccess' ) && is_file( PRESSHUB_AI_BACKUP_DIR . '/index.php' ) && is_file( PRESSHUB_AI_BACKUP_DIR . '/web.config' ) );
ub_check( 'base dir: .htaccess denies everything', false !== strpos( file_get_contents( PRESSHUB_AI_BACKUP_DIR . '/.htaccess' ), 'Require all denied' ) );

$ids = [];
for ( $i = 1; $i <= 7; $i++ ) {
    $ids[ $i ] = sprintf( '2026111%d-120000-v2.5%d.0-abcdefg%d', $i, $i, $i );
    ub_make_backup( $ids[ $i ], 1000 + $i );
}
$crashed = '20261101-000000-v2.40.0-crashed1';
@mkdir( $B::backup_path( $crashed ), 0777, true );
touch( $B::backup_path( $crashed ), time() - 7200 );
$fresh = '20261101-000000-v2.40.0-running1';
@mkdir( $B::backup_path( $fresh ), 0777, true );
@mkdir( PRESSHUB_AI_BACKUP_DIR . '/not-a-backup', 0777, true );

$all = $B::all();
ub_check( 'list: only backup folders', 9 === count( $all ) );
$complete_ids = array_column( array_filter( $all, function ( $b ) { return $b['complete']; } ), 'id' );
ub_check( 'list: newest first', $ids[7] === reset( $complete_ids ) && $ids[1] === end( $complete_ids ) );
ub_check( 'list: incomplete marked', 2 === count( array_filter( $all, function ( $b ) { return ! $b['complete']; } ) ) );
ub_check( 'manifest: read', '2.52.0' === ( $B::read_manifest( $ids[3] )['plugin_version'] ?? '' ) );
ub_check( 'manifest: a bad id reads nothing', null === $B::read_manifest( '../x' ) );

$removed = $B::prune( 3, $ids[1] );
$left    = array_column( $B::all(), 'id' );
ub_check( 'prune: keeps the newest 3', in_array( $ids[7], $left, true ) && in_array( $ids[6], $left, true ) && in_array( $ids[5], $left, true ) );
ub_check( 'prune: older ones removed', ! in_array( $ids[4], $left, true ) && ! in_array( $ids[2], $left, true ) );
ub_check( 'prune: the protected one is kept', in_array( $ids[1], $left, true ) );
ub_check( 'prune: a crashed run is removed', ! in_array( $crashed, $left, true ) );
ub_check( 'prune: a run in progress is kept', in_array( $fresh, $left, true ) );
ub_check( 'prune: count', 4 === $removed );

// ---------------------------------------------------------------------
// 4b. verify(): a damaged backup is refused before anything is changed.
// ---------------------------------------------------------------------
function ub_gz( string $file, string $body ): void {
    $gz = gzopen( $file, 'wb' );
    gzwrite( $gz, $body );
    gzclose( $gz );
}
$vid = '20261118-130000-v2.53.0-verify01';
ub_make_backup( $vid, 2000, [ 'options' => 2, 'tables' => [ 'presshub_ai_audit_logs' => [ 'rows' => 2, 'create' => true ] ] ] );
$vdir = $B::backup_path( $vid );
ub_gz( $vdir . '/options.jsonl.gz', "{\"n\":\"presshub_a\",\"v\":\"1\",\"a\":\"yes\"}\n{\"n\":\"presshub_b\",\"v\":\"2\",\"a\":\"no\"}\n" );
file_put_contents( $vdir . '/tables/presshub_ai_audit_logs.sql', 'CREATE TABLE `wp_presshub_ai_audit_logs` (id int)' );
ub_gz( $vdir . '/tables/presshub_ai_audit_logs.jsonl.gz', "{\"id\":\"1\"}\n{\"id\":\"2\"}\n" );
$m = $B::read_manifest( $vid );
ub_check( 'verify: a whole backup passes', true === $B::verify( $m ) );
ub_gz( $vdir . '/tables/presshub_ai_audit_logs.jsonl.gz', "{\"id\":\"1\"}\n" );
$v = $B::verify( $m );
ub_check( 'verify: a short table file is refused', is_wp_error( $v ) && false !== strpos( $v->get_error_message(), '1 rows instead of 2' ) );
ub_gz( $vdir . '/tables/presshub_ai_audit_logs.jsonl.gz', "{\"id\":\"1\"}\n{\"id\":" );
ub_check( 'verify: a line cut short is refused', is_wp_error( $B::verify( $m ) ) );
ub_gz( $vdir . '/tables/presshub_ai_audit_logs.jsonl.gz', "{\"id\":\"1\"}\nnot json\n" );
ub_check( 'verify: a bad line is refused', is_wp_error( $B::verify( $m ) ) );
file_put_contents( $vdir . '/tables/presshub_ai_audit_logs.jsonl.gz', 'not gzip at all' );
ub_check( 'verify: a file that is not gzip is refused', is_wp_error( $B::verify( $m ) ) );
ub_gz( $vdir . '/tables/presshub_ai_audit_logs.jsonl.gz', "{\"id\":\"1\"}\n{\"id\":\"2\"}\n" );
unlink( $vdir . '/tables/presshub_ai_audit_logs.sql' );
ub_check( 'verify: a missing table definition is refused', is_wp_error( $B::verify( $m ) ) );
file_put_contents( $vdir . '/tables/presshub_ai_audit_logs.sql', 'CREATE TABLE `wp_presshub_ai_audit_logs` (id int)' );
file_put_contents( $vdir . '/tables/presshub_ai_audit_logs.sql', '' );
ub_check( 'verify: an empty table definition is refused', is_wp_error( $B::verify( $m ) ) );
file_put_contents( $vdir . '/tables/presshub_ai_audit_logs.sql', 'CREATE TABLE `wp_presshub_ai_audit_logs` (id int)' );
ub_check( 'verify: restoring files needs a plugin zip', is_wp_error( $B::verify( $m, true ) ) );
$z = new ZipArchive();
$z->open( $vdir . '/plugin.zip', ZipArchive::CREATE );
$z->addFromString( 'other/readme.txt', 'x' );
$z->close();
ub_check( 'verify: a zip without PressHub is refused', is_wp_error( $B::verify( $m, true ) ) );
$z = new ZipArchive();
$z->open( $vdir . '/plugin.zip' );
$z->addFromString( 'presshub-ai-editor/presshub-ai-editor.php', '<?php' );
$z->close();
ub_check( 'verify: a PressHub zip passes', true === $B::verify( $m, true ) );
$bad = $m;
$bad['tables'] = [ 'users' => [ 'rows' => 0, 'create' => false ] ];
ub_check( 'verify: a table PressHub does not own is refused', is_wp_error( $B::verify( $bad ) ) );
unlink( $vdir . '/options.jsonl.gz' );
ub_check( 'verify: a missing options file is refused', is_wp_error( $B::verify( $m ) ) );
$B::delete_dir( $vdir );

// ---------------------------------------------------------------------
// 5. Linked plugin folder.
// ---------------------------------------------------------------------
ub_check( 'linked: a real folder is not', ! $B::plugin_dir_is_linked( $plug ) );
$link = $GLOBALS['UB_TMP'] . '/linked-plugin';
if ( function_exists( 'symlink' ) && @symlink( $plug, $link ) ) {
    ub_check( 'linked: a symlink is', $B::plugin_dir_is_linked( $link ) );
} else {
    echo "  (symlink not available, skipped)\n";
}

// ---------------------------------------------------------------------
// 6. delete_dir() stays inside the backup folder.
// ---------------------------------------------------------------------
$outside = $GLOBALS['UB_TMP'] . '/outside';
@mkdir( $outside, 0777, true );
ub_check( 'delete: refuses outside the backup folder', false === $B::delete_dir( $outside ) && is_dir( $outside ) );
ub_check( 'delete: refuses the backup folder itself', false === $B::delete_dir( PRESSHUB_AI_BACKUP_DIR ) && is_dir( PRESSHUB_AI_BACKUP_DIR ) );
ub_check( 'delete: removes a backup', true === $B::delete_dir( $B::backup_path( $ids[5] ) ) && ! is_dir( $B::backup_path( $ids[5] ) ) );

// ---------------------------------------------------------------------
// 7. Page.
// ---------------------------------------------------------------------
$GLOBALS['SUBMENU_PAGES'] = [];
$page = new PressHub_AI_Update_Backup();
$page->add_admin_menu();
$row = $GLOBALS['SUBMENU_PAGES'][ PressHub_Admin_Menu::PARENT ][0] ?? [];
ub_check( 'menu: Backups under PressHub', 'presshub-ai-backups' === ( $row['menu_slug'] ?? '' ) && 'PressHub — Backups' === ( $row['page_title'] ?? '' ) );
ub_check( 'menu: PressHub capability', PressHub_Capabilities::manage() === ( $row['capability'] ?? '' ) );
ub_check( 'menu: in ORDER before Help', array_search( 'presshub-ai-backups', PressHub_Admin_Menu::ORDER, true ) === array_search( 'presshub-ai-help', PressHub_Admin_Menu::ORDER, true ) - 1 );

$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts' ];
ob_start();
$B::render_page();
$html = ob_get_clean();
ub_check( 'page: editor sees the list', false !== strpos( $html, 'presshub-backups-table' ) && false !== strpos( $html, 'Before the update to 2.53.0' ) );
ub_check( 'page: editor gets no actions', false === strpos( $html, 'Restore…' ) && false === strpos( $html, 'Take a backup now' ) && false !== strpos( $html, 'needs an administrator' ) );

$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts', 'update_plugins' ];
ob_start();
$B::render_page();
$html = ob_get_clean();
ub_check( 'page: admin gets Take, Restore and Delete', false !== strpos( $html, 'Take a backup now' ) && false !== strpos( $html, 'Restore…' ) && false !== strpos( $html, 'Delete…' ) );
ub_check( 'page: incomplete row explained', false !== strpos( $html, 'Incomplete' ) );

$_GET['confirm'] = $ids[7];
ob_start();
$B::render_page();
$html = ob_get_clean();
ub_check( 'confirm: explains the safety backup', false !== strpos( $html, 'saved as a new backup' ) );
ub_check( 'confirm: posts the restore action with a nonce', false !== strpos( $html, $B::RESTORE_ACTION ) && false !== strpos( $html, '_wpnonce' ) );
ub_check( 'confirm: names the backup', false !== strpos( $html, 'value="' . $ids[7] . '"' ) );
$_GET['do'] = 'delete';
ob_start();
$B::render_page();
$html = ob_get_clean();
ub_check( 'confirm delete: says it cannot be undone', false !== strpos( $html, 'cannot be undone' ) && false !== strpos( $html, $B::DELETE_ACTION ) );
unset( $_GET['confirm'], $_GET['do'] );

// Clean up.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['UB_TMP'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) {
    ( $f->isDir() && ! $f->isLink() ) ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
}
@rmdir( $GLOBALS['UB_TMP'] );

echo "UpdateBackupTest: {$checks} checks, {$failures} failed\n";
if ( $failures > 0 ) {
    echo "FAIL\n";
    exit( 1 );
}
echo "OK\n";
