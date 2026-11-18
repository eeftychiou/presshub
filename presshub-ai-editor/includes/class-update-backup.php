<?php
/**
 * PressHub_AI_Update_Backup — a backup before every PressHub update, and a restore (#296).
 *
 * When WordPress is about to replace the PressHub plugin (Plugins → Update,
 * auto-update, or Upload Plugin → "Replace current with uploaded"), this
 * class first saves:
 *
 *   - every presshub_* row of wp_options (value and autoload, as stored),
 *   - every {prefix}presshub_* table (its CREATE TABLE and all its rows),
 *   - a zip of the plugin folder that is about to be replaced,
 *
 * into wp-content/presshub-backups/<id>/ (or PRESSHUB_AI_BACKUP_DIR). The
 * folder denies web access (.htaccess, web.config, index.php) and each
 * backup's folder name ends in a random suffix. The backups hold API keys and
 * tokens, like the database itself. Only the newest
 * PressHub_AI_Settings_Storage::get_backup_keep() backups are kept.
 *
 * If the backup cannot be taken the update is stopped with the reason, so an
 * update never runs without one. A host can skip it with
 * define( 'PRESSHUB_AI_SKIP_UPDATE_BACKUP', true ).
 *
 * PressHub → Backups lists the backups, takes one by hand and restores one.
 * A restore first takes a "before restore" backup of the current state, then
 * puts back the options and tables and, unless the editor unticks it,
 * reinstalls the saved plugin files through WordPress's own Plugin_Upgrader.
 * Putting back the files is refused when the plugin folder is a link (the
 * dev-env junction to the git checkout), so a restore never writes into a
 * repository.
 *
 * Files in a backup:
 *   manifest.json         written last; a backup without it is incomplete
 *   options.jsonl.gz      one {"n":name,"v":value,"a":autoload} per line
 *   tables/<suffix>.sql   CREATE TABLE, when the database could give it
 *   tables/<suffix>.jsonl.gz  one row (column => value) per line
 *   plugin.zip            the plugin folder, under presshub-ai-editor/
 *
 * @package PressHub_AI_Editor
 * @since 2.53.0 (follow-ups 2.53.1)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';
require_once __DIR__ . '/class-admin-menu.php';

class PressHub_AI_Update_Backup {

    const PAGE_SLUG      = 'presshub-ai-backups';
    const DIR_NAME       = 'presshub-backups';
    const PLUGIN_FOLDER  = 'presshub-ai-editor';
    const PLUGIN_MAIN    = 'presshub-ai-editor.php';
    const FORMAT         = 'presshub-update-backup';
    const FORMAT_VERSION = 1;

    const BACKUP_ACTION  = 'presshub_ai_backup_take';
    const RESTORE_ACTION = 'presshub_ai_backup_restore';
    const DELETE_ACTION  = 'presshub_ai_backup_delete';
    const RECHECK_ACTION = 'presshub_ai_backup_recheck';
    const NOTICE_PREFIX  = 'presshub_ai_backup_notice_';

    /** Rows read or written per query. */
    const ROW_BATCH = 200;

    /** Largest INSERT sent in one query (bytes), kept under max_allowed_packet. */
    const INSERT_MAX_BYTES = 1048576;

    /** Cached result of the web-access check of the backup folder. */
    const EXPOSURE_TRANSIENT = 'presshub_ai_backup_exposure';

    /** An incomplete backup older than this (seconds) is a crashed run and is removed. */
    const INCOMPLETE_GRACE = 3600;

    /** Option rows never saved or put back: none today, kept for per-site state. */
    const OPTION_EXCLUDE = [];

    /** Reasons a backup was taken. */
    const REASON_UPDATE  = 'update';
    const REASON_MANUAL  = 'manual';
    const REASON_RESTORE = 'before_restore';

    /** Set while this class drives the upgrader, so its own hooks do not back up again. */
    private static $busy = false;

    /** Whether this request already put plugin files back (a later failure then cannot use the Backups page). */
    private static $files_restored = false;

    /** Backups taken in this request (one per request is enough). */
    private static $taken_this_request = '';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_post_' . self::BACKUP_ACTION, [ __CLASS__, 'handle_take' ] );
        add_action( 'admin_post_' . self::RESTORE_ACTION, [ __CLASS__, 'handle_restore' ] );
        add_action( 'admin_post_' . self::DELETE_ACTION, [ __CLASS__, 'handle_delete' ] );
        add_action( 'admin_post_' . self::RECHECK_ACTION, [ __CLASS__, 'handle_recheck' ] );
        self::register_upgrader_hooks();
    }

    /** Upgrader hooks. Priority 5: before WordPress deactivates and removes the old plugin. */
    public static function register_upgrader_hooks(): void {
        add_filter( 'upgrader_pre_install', [ __CLASS__, 'before_update' ], 5, 2 );
        add_filter( 'upgrader_source_selection', [ __CLASS__, 'before_replace_upload' ], 5, 4 );
    }

    // ------------------------------------------------------------------
    // Upgrader hooks
    // ------------------------------------------------------------------

    /** Whether hook_extra names this plugin (any folder name, the main file decides). */
    public static function is_presshub_update( $hook_extra ): bool {
        if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || ! is_string( $hook_extra['plugin'] ) ) {
            return false;
        }
        return self::PLUGIN_MAIN === basename( str_replace( '\\', '/', $hook_extra['plugin'] ) );
    }

    /**
     * upgrader_pre_install: Plugins → Update, bulk and auto-updates of PressHub.
     *
     * @param bool|WP_Error $response
     * @param array         $hook_extra
     * @return bool|WP_Error
     */
    public static function before_update( $response, $hook_extra = [] ) {
        if ( is_wp_error( $response ) || ! self::is_presshub_update( $hook_extra ) ) {
            return $response;
        }
        return self::backup_or_stop( $response );
    }

    /**
     * upgrader_source_selection: Upload Plugin → "Replace current with uploaded".
     * That path carries no hook_extra['plugin'], so the unpacked package and the
     * installer's overwrite choice decide.
     *
     * @param string|WP_Error $source
     * @return string|WP_Error
     */
    public static function before_replace_upload( $source, $remote_source = '', $upgrader = null, $hook_extra = [] ) {
        if ( is_wp_error( $source ) || self::is_presshub_update( $hook_extra ) ) {
            return $source; // Updates were handled by before_update().
        }
        if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) || 'install' !== ( $hook_extra['action'] ?? '' ) ) {
            return $source;
        }
        $overwrite = is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && ! empty( $upgrader->skin->overwrite );
        if ( ! $overwrite || ! is_string( $source ) || ! file_exists( trailingslashit( $source ) . self::PLUGIN_MAIN ) ) {
            return $source;
        }
        if ( ! file_exists( self::plugin_dir() . '/' . self::PLUGIN_MAIN ) ) {
            return $source; // Nothing installed to back up.
        }
        $result = self::backup_or_stop( true );
        return is_wp_error( $result ) ? $result : $source;
    }

    /** Takes the pre-update backup; a WP_Error stops the update. */
    private static function backup_or_stop( $response ) {
        if ( self::$busy || '' !== self::$taken_this_request ) {
            return $response;
        }
        if ( defined( 'PRESSHUB_AI_SKIP_UPDATE_BACKUP' ) && PRESSHUB_AI_SKIP_UPDATE_BACKUP ) {
            PressHub_AI_Logger::warning( 'Pre-update backup skipped by PRESSHUB_AI_SKIP_UPDATE_BACKUP', [ 'from_version' => self::running_version() ] );
            return $response;
        }
        $result = self::create( self::REASON_UPDATE, self::pending_update_version() );
        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                'presshub_backup_failed',
                sprintf(
                    /* translators: %s: why the backup failed. */
                    __( 'PressHub stopped this update because it could not take its backup first: %s Fix the problem and try again, or take a backup under PressHub → Backups to see the error there.', 'presshub-ai-editor' ),
                    $result->get_error_message()
                )
            );
        }
        return $response;
    }

    // ------------------------------------------------------------------
    // Paths
    // ------------------------------------------------------------------

    /** Folder holding all backups (no trailing slash). */
    public static function base_dir(): string {
        if ( defined( 'PRESSHUB_AI_BACKUP_DIR' ) && is_string( PRESSHUB_AI_BACKUP_DIR ) && '' !== PRESSHUB_AI_BACKUP_DIR ) {
            return untrailingslashit( PRESSHUB_AI_BACKUP_DIR );
        }
        return untrailingslashit( WP_CONTENT_DIR ) . '/' . self::DIR_NAME;
    }

    /** The installed plugin folder (the one WordPress will replace). */
    public static function plugin_dir(): string {
        $dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . self::PLUGIN_FOLDER : '';
        if ( '' === $dir || ! is_dir( $dir ) ) {
            $dir = untrailingslashit( defined( 'PRESSHUB_AI_DIR' ) ? PRESSHUB_AI_DIR : dirname( __DIR__ ) );
        }
        return untrailingslashit( str_replace( '\\', '/', $dir ) );
    }

    /**
     * Whether the plugin folder is a link or junction (dev-env points it at the
     * git checkout). Restoring files is refused then.
     */
    public static function plugin_dir_is_linked( ?string $dir = null ): bool {
        $dir = $dir ?? self::plugin_dir();
        if ( is_link( $dir ) ) {
            return true;
        }
        $real   = realpath( $dir );
        $parent = realpath( dirname( $dir ) );
        if ( false === $real || false === $parent ) {
            return false;
        }
        $expected = rtrim( str_replace( '\\', '/', $parent ), '/' ) . '/' . basename( $dir );
        return strtolower( str_replace( '\\', '/', $real ) ) !== strtolower( $expected );
    }

    /** Valid backup id: date, time, version and a random suffix. */
    public static function is_valid_id( string $id ): bool {
        return (bool) preg_match( '/^\d{8}-\d{6}-[a-z0-9._-]{1,40}-[a-z0-9]{8}$/', $id );
    }

    public static function backup_path( string $id ): string {
        return self::base_dir() . '/' . $id;
    }

    /** Creates the base folder with its deny-all files. */
    public static function ensure_base_dir() {
        $base = self::base_dir();
        if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
            return new WP_Error( 'presshub_backup_dir', sprintf(
                /* translators: %s: folder path. */
                __( 'The backup folder %s could not be created.', 'presshub-ai-editor' ),
                $base
            ) );
        }
        if ( ! is_writable( $base ) ) {
            return new WP_Error( 'presshub_backup_dir', sprintf(
                /* translators: %s: folder path. */
                __( 'The backup folder %s is not writable.', 'presshub-ai-editor' ),
                $base
            ) );
        }
        $guards = [
            '.htaccess'  => "# PressHub backups: no web access.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
        ];
        foreach ( $guards as $file => $body ) {
            if ( file_exists( $base . '/' . $file ) ) {
                continue;
            }
            if ( false === file_put_contents( $base . '/' . $file, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                // Never write API keys into a folder whose web block could not be written.
                return new WP_Error( 'presshub_backup_dir', sprintf(
                    /* translators: 1: file name, 2: folder path. */
                    __( 'The web block %1$s could not be written in %2$s, so no backup was saved there.', 'presshub-ai-editor' ),
                    $file,
                    $base
                ) );
            }
        }
        return true;
    }

    /** index.php in a backup's folders, so a server without .htaccess support lists nothing. */
    private static function guard_subdirs( string $dir ): bool {
        foreach ( [ $dir, $dir . '/tables' ] as $folder ) {
            if ( false === file_put_contents( $folder . '/index.php', "<?php\n// Silence is golden.\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Taking a backup
    // ------------------------------------------------------------------

    /** Version of the code running now (the one an update replaces). */
    public static function running_version(): string {
        return defined( 'PRESSHUB_AI_VERSION' ) ? (string) PRESSHUB_AI_VERSION : '';
    }

    /** The version WordPress is about to install, when the update data says. */
    public static function pending_update_version(): string {
        if ( ! function_exists( 'get_site_transient' ) ) {
            return '';
        }
        $updates = get_site_transient( 'update_plugins' );
        if ( ! is_object( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) ) {
            return '';
        }
        foreach ( $updates->response as $file => $info ) {
            if ( self::PLUGIN_MAIN === basename( (string) $file ) && is_object( $info ) && ! empty( $info->new_version ) ) {
                return (string) $info->new_version;
            }
        }
        return '';
    }

    /** New backup id. */
    public static function new_id( string $version ): string {
        $slug = strtolower( preg_replace( '/[^A-Za-z0-9._-]/', '', $version ) );
        $slug = '' !== $slug ? 'v' . substr( $slug, 0, 39 ) : 'v0';
        $rand = strtolower( wp_generate_password( 8, false, false ) );
        return gmdate( 'Ymd-His' ) . '-' . $slug . '-' . $rand;
    }

    /**
     * Takes a backup. Returns the manifest, or WP_Error (the partial folder is removed).
     *
     * @param string $reason  update | manual | before_restore
     * @param string $target  Version about to be installed or restored, if known.
     * @param string $protect A backup id pruning must keep (the one being restored).
     * @return array|WP_Error
     */
    public static function create( string $reason, string $target = '', string $protect = '' ) {
        $started = microtime( true );
        if ( ! function_exists( 'gzopen' ) ) {
            return new WP_Error( 'presshub_backup_zlib', __( 'PHP has no zlib support, which the backup needs to compress its files.', 'presshub-ai-editor' ) );
        }
        $ok = self::ensure_base_dir();
        if ( is_wp_error( $ok ) ) {
            self::log_failure( $reason, $ok );
            return $ok;
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not allowed on every host.
        }

        $version = self::running_version();
        $id      = self::new_id( $version );
        $dir     = self::backup_path( $id );
        if ( ! wp_mkdir_p( $dir . '/tables' ) ) {
            $err = new WP_Error( 'presshub_backup_dir', sprintf(
                /* translators: %s: folder path. */
                __( 'The folder %s could not be created.', 'presshub-ai-editor' ),
                $dir
            ) );
            self::log_failure( $reason, $err );
            return $err;
        }

        if ( ! self::guard_subdirs( $dir ) ) {
            self::delete_dir( $dir );
            $err = new WP_Error( 'presshub_backup_dir', __( 'The backup folder could not be protected (index.php could not be written).', 'presshub-ai-editor' ) );
            self::log_failure( $reason, $err );
            return $err;
        }
        $result = self::write_contents( $dir );
        if ( is_wp_error( $result ) ) {
            self::delete_dir( $dir );
            self::log_failure( $reason, $result );
            return $result;
        }

        $manifest = [
            'format'         => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'id'             => $id,
            'created_at'     => gmdate( 'c' ),
            'created_ts'     => time(),
            'reason'         => $reason,
            'plugin_version' => $version,
            'target_version' => $target,
            'site'           => function_exists( 'home_url' ) ? (string) home_url() : '',
            'db_prefix'      => self::db_prefix(),
            'user_id'        => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'options'        => $result['options'],
            'tables'         => $result['tables'],
            'plugin_zip'     => $result['plugin_zip'],
            'bytes'          => self::dir_size( $dir ),
            'duration_ms'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
        ];
        $json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( false === $json || false === file_put_contents( $dir . '/manifest.json', $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            self::delete_dir( $dir );
            $err = new WP_Error( 'presshub_backup_write', __( 'The backup list of contents could not be written.', 'presshub-ai-editor' ) );
            self::log_failure( $reason, $err );
            return $err;
        }

        self::$taken_this_request = $id;
        $pruned = self::prune( PressHub_AI_Settings_Storage::get_backup_keep(), $protect );

        $context = [
            'backup_id'      => $id,
            'reason'         => $reason,
            'plugin_version' => $version,
            'target_version' => $target,
            'options'        => $manifest['options'],
            'tables'         => count( $manifest['tables'] ),
            'rows'           => array_sum( array_column( $manifest['tables'], 'rows' ) ),
            'plugin_zip'     => $manifest['plugin_zip'],
            'bytes'          => $manifest['bytes'],
            'duration_ms'    => $manifest['duration_ms'],
            'pruned'         => $pruned,
        ];
        PressHub_AI_Logger::info( 'PressHub backup taken', $context );
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            PressHub_AI_Audit_Logger::log( 'backup_created', 'backup', $id, $context );
        }
        return $manifest;
    }

    private static function log_failure( string $reason, WP_Error $error ): void {
        PressHub_AI_Logger::error( 'PressHub backup failed', [
            'reason'         => $reason,
            'plugin_version' => self::running_version(),
            'error_code'     => $error->get_error_code(),
            'error'          => $error->get_error_message(),
        ] );
    }

    private static function db_prefix(): string {
        global $wpdb;
        return isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
    }

    /**
     * Writes options, tables and the plugin zip into $dir.
     *
     * @return array{options:int,tables:array<string,array{rows:int,create:bool}>,plugin_zip:bool}|WP_Error
     */
    private static function write_contents( string $dir ) {
        $options = self::write_options( $dir . '/options.jsonl.gz' );
        if ( is_wp_error( $options ) ) {
            return $options;
        }
        $tables = [];
        foreach ( self::plugin_tables() as $table ) {
            $suffix = substr( $table, strlen( self::db_prefix() ) );
            $info   = self::write_table( $table, $dir . '/tables/' . $suffix );
            if ( is_wp_error( $info ) ) {
                return $info;
            }
            $tables[ $suffix ] = $info;
        }
        $zip = self::write_plugin_zip( self::plugin_dir(), $dir . '/plugin.zip' );
        if ( is_wp_error( $zip ) ) {
            return $zip;
        }
        return [ 'options' => $options, 'tables' => $tables, 'plugin_zip' => true ];
    }

    /** LIKE pattern for PressHub option names. */
    private static function option_like(): string {
        global $wpdb;
        return $wpdb->esc_like( 'presshub_' ) . '%';
    }

    /** @return int|WP_Error Rows written. */
    private static function write_options( string $file ) {
        global $wpdb;
        $gz = gzopen( $file, 'wb6' );
        if ( ! $gz ) {
            return new WP_Error( 'presshub_backup_write', __( 'The options file could not be written.', 'presshub-ai-editor' ) );
        }
        $count   = 0;
        $last_id = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT %d",
                self::option_like(),
                $last_id,
                self::ROW_BATCH
            ), ARRAY_A );
            if ( ! empty( $wpdb->last_error ) ) {
                gzclose( $gz );
                return new WP_Error( 'presshub_backup_db', sprintf(
                    /* translators: %s: database error. */
                    __( 'Reading the PressHub options failed: %s', 'presshub-ai-editor' ),
                    $wpdb->last_error
                ) );
            }
            foreach ( (array) $rows as $row ) {
                $last_id = max( $last_id, (int) $row['option_id'] );
                if ( in_array( $row['option_name'], self::OPTION_EXCLUDE, true ) ) {
                    continue;
                }
                $line = wp_json_encode( [ 'n' => $row['option_name'], 'v' => $row['option_value'], 'a' => $row['autoload'] ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
                if ( false === $line || false === gzwrite( $gz, $line . "\n" ) ) {
                    gzclose( $gz );
                    return new WP_Error( 'presshub_backup_write', __( 'The options file could not be written (is the disk full?).', 'presshub-ai-editor' ) );
                }
                $count++;
            }
        } while ( is_array( $rows ) && count( $rows ) === self::ROW_BATCH );
        if ( ! gzclose( $gz ) ) {
            return new WP_Error( 'presshub_backup_write', __( 'The options file could not be finished (is the disk full?).', 'presshub-ai-editor' ) );
        }
        return $count;
    }

    /** Every {prefix}presshub_* table. @return string[] */
    public static function plugin_tables(): array {
        global $wpdb;
        $like   = $wpdb->esc_like( self::db_prefix() . 'presshub_' ) . '%';
        $tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
        $tables = array_values( array_filter( array_map( 'strval', $tables ), [ __CLASS__, 'is_plugin_table' ] ) );
        sort( $tables );
        return $tables;
    }

    /** A table name this class may read, drop and create. */
    public static function is_plugin_table( string $table ): bool {
        $prefix = self::db_prefix() . 'presshub_';
        return 0 === strpos( $table, $prefix ) && (bool) preg_match( '/^[A-Za-z0-9_]+$/', $table );
    }

    /**
     * Primary key columns of a table, for a stable row order. $int_key is set
     * to the column when the key is one integer column (keyset paging).
     *
     * @return string[]
     */
    private static function primary_key( string $table, ?string &$int_key = null ): array {
        global $wpdb;
        $cols = (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- validated table name.
        $keys    = [];
        $types   = [];
        $int_key = '';
        foreach ( $cols as $col ) {
            if ( 'PRI' === strtoupper( (string) ( $col['Key'] ?? '' ) ) && preg_match( '/^[A-Za-z0-9_]+$/', (string) $col['Field'] ) ) {
                $keys[]  = (string) $col['Field'];
                $types[] = strtolower( (string) ( $col['Type'] ?? '' ) );
            }
        }
        if ( 1 === count( $keys ) && false !== strpos( $types[0], 'int' ) ) {
            $int_key = $keys[0];
        }
        return $keys;
    }

    /** @return array{rows:int,create:bool}|WP_Error */
    private static function write_table( string $table, string $base ) {
        global $wpdb;
        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- validated table name.
        $has_create = is_array( $create ) && ! empty( $create[1] ) && is_string( $create[1] );
        if ( $has_create && false === file_put_contents( $base . '.sql', $create[1] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            return new WP_Error( 'presshub_backup_write', __( 'A table definition could not be written.', 'presshub-ai-editor' ) );
        }
        $gz = gzopen( $base . '.jsonl.gz', 'wb6' );
        if ( ! $gz ) {
            return new WP_Error( 'presshub_backup_write', __( 'A table file could not be written.', 'presshub-ai-editor' ) );
        }
        $keyset = '';
        $keys   = self::primary_key( $table, $keyset );
        // One integer key (every PressHub table): keyset paging, fast and stable while
        // rows are added. Otherwise LIMIT/OFFSET in key order.
        $order  = $keys ? ' ORDER BY `' . implode( '`, `', $keys ) . '`' : '';
        $count  = 0;
        $offset = 0;
        $last   = null;
        do {
            if ( '' !== $keyset ) {
                $where = null === $last ? '' : $wpdb->prepare( " WHERE `{$keyset}` > %d", $last ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- validated column name.
                $sql   = "SELECT * FROM `{$table}`{$where}{$order} LIMIT " . (int) self::ROW_BATCH;
            } else {
                $sql = "SELECT * FROM `{$table}`{$order} LIMIT " . (int) self::ROW_BATCH . ' OFFSET ' . (int) $offset;
            }
            $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- validated names, integer limits.
            if ( ! empty( $wpdb->last_error ) ) {
                gzclose( $gz );
                return new WP_Error( 'presshub_backup_db', sprintf(
                    /* translators: 1: table, 2: database error. */
                    __( 'Reading %1$s failed: %2$s', 'presshub-ai-editor' ),
                    $table,
                    $wpdb->last_error
                ) );
            }
            foreach ( (array) $rows as $row ) {
                if ( '' !== $keyset ) {
                    if ( ! is_numeric( $row[ $keyset ] ?? null ) ) {
                        gzclose( $gz );
                        return new WP_Error( 'presshub_backup_db', sprintf(
                            /* translators: %s: table. */
                            __( 'The table %s has a key that is not a number.', 'presshub-ai-editor' ),
                            $table
                        ) );
                    }
                    $last = (int) $row[ $keyset ];
                }
                $line = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
                if ( false === $line || false === gzwrite( $gz, $line . "\n" ) ) {
                    gzclose( $gz );
                    return new WP_Error( 'presshub_backup_write', sprintf(
                        /* translators: %s: table. */
                        __( 'The rows of %s could not be written (is the disk full?).', 'presshub-ai-editor' ),
                        $table
                    ) );
                }
                $count++;
            }
            $offset += self::ROW_BATCH;
        } while ( is_array( $rows ) && count( $rows ) === self::ROW_BATCH );
        if ( ! gzclose( $gz ) ) {
            return new WP_Error( 'presshub_backup_write', sprintf(
                /* translators: %s: table. */
                __( 'The rows of %s could not be finished (is the disk full?).', 'presshub-ai-editor' ),
                $table
            ) );
        }
        return [ 'rows' => $count, 'create' => $has_create ];
    }

    /** Zips the plugin folder under presshub-ai-editor/. @return true|WP_Error */
    public static function write_plugin_zip( string $source, string $zip_file ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'presshub_backup_zip', __( 'PHP has no ZipArchive, which the backup needs to save the plugin files.', 'presshub-ai-editor' ) );
        }
        $root = realpath( $source );
        if ( false === $root || ! is_file( $root . '/' . self::PLUGIN_MAIN ) ) {
            return new WP_Error( 'presshub_backup_zip', __( 'The installed plugin folder was not found.', 'presshub-ai-editor' ) );
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            return new WP_Error( 'presshub_backup_zip', __( 'The plugin zip could not be created.', 'presshub-ai-editor' ) );
        }
        $skip  = [ '.git', 'node_modules' ];
        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                static function ( $file ) use ( $skip ) {
                    return ! in_array( $file->getFilename(), $skip, true );
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $root_len = strlen( $root ) + 1;
        foreach ( $files as $file ) {
            $relative = str_replace( '\\', '/', substr( $file->getPathname(), $root_len ) );
            $name     = self::PLUGIN_FOLDER . '/' . $relative;
            if ( $file->isDir() ) {
                $zip->addEmptyDir( $name );
            } elseif ( $file->isFile() && ! $zip->addFile( $file->getPathname(), $name ) ) {
                $zip->close();
                return new WP_Error( 'presshub_backup_zip', sprintf(
                    /* translators: %s: file path. */
                    __( 'The plugin file %s could not be added to the zip.', 'presshub-ai-editor' ),
                    $relative
                ) );
            }
        }
        if ( ! $zip->close() ) {
            return new WP_Error( 'presshub_backup_zip', __( 'The plugin zip could not be written (is the disk full?).', 'presshub-ai-editor' ) );
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Listing and pruning
    // ------------------------------------------------------------------

    /**
     * Backups, newest first. Each is the manifest plus 'complete' and 'path';
     * an incomplete one has only id, path, created_ts and complete=false.
     *
     * @return array<int,array>
     */
    public static function all(): array {
        $base = self::base_dir();
        if ( ! is_dir( $base ) ) {
            return [];
        }
        $list = [];
        foreach ( (array) scandir( $base ) as $entry ) {
            $entry = (string) $entry;
            if ( ! self::is_valid_id( $entry ) || ! is_dir( $base . '/' . $entry ) ) {
                continue;
            }
            $manifest = self::read_manifest( $entry );
            if ( $manifest ) {
                $manifest['complete'] = true;
                $manifest['path']     = $base . '/' . $entry;
                $list[]               = $manifest;
            } else {
                $list[] = [
                    'id'         => $entry,
                    'path'       => $base . '/' . $entry,
                    'created_ts' => (int) filemtime( $base . '/' . $entry ),
                    'complete'   => false,
                ];
            }
        }
        usort( $list, static function ( $a, $b ) {
            return [ $b['created_ts'], $b['id'] ] <=> [ $a['created_ts'], $a['id'] ];
        } );
        return $list;
    }

    /** The manifest of a complete backup, or null. */
    public static function read_manifest( string $id ): ?array {
        if ( ! self::is_valid_id( $id ) ) {
            return null;
        }
        $file = self::backup_path( $id ) . '/manifest.json';
        if ( ! is_file( $file ) ) {
            return null;
        }
        $data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) || ( $data['id'] ?? '' ) !== $id ) {
            return null;
        }
        if ( (int) ( $data['format_version'] ?? 0 ) > self::FORMAT_VERSION ) {
            return null;
        }
        $data['tables']     = is_array( $data['tables'] ?? null ) ? $data['tables'] : [];
        $data['created_ts'] = (int) ( $data['created_ts'] ?? 0 );
        return $data;
    }

    /**
     * Keeps the newest $keep complete backups (and $protect), and removes
     * incomplete ones left by a crashed run.
     *
     * @return int Backups removed.
     */
    public static function prune( int $keep, string $protect = '' ): int {
        $removed  = 0;
        $complete = 0;
        foreach ( self::all() as $backup ) {
            if ( $backup['id'] === $protect || $backup['id'] === self::$taken_this_request ) {
                $complete += $backup['complete'] ? 1 : 0;
                continue;
            }
            if ( ! $backup['complete'] ) {
                if ( time() - (int) $backup['created_ts'] > self::INCOMPLETE_GRACE && self::delete_dir( $backup['path'] ) ) {
                    $removed++;
                }
                continue;
            }
            $complete++;
            if ( $complete > $keep && self::delete_dir( $backup['path'] ) ) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Deletes a folder inside the backup folder. */
    public static function delete_dir( string $dir ): bool {
        $base = realpath( self::base_dir() );
        $real = realpath( $dir );
        if ( false === $base || false === $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_dir( $real ) ) {
            return false;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            if ( $item->isDir() && ! $item->isLink() ) {
                @rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            } else {
                @unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
        return @rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    private static function dir_size( string $dir ): int {
        $size = 0;
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
            $size += $file->isFile() ? (int) $file->getSize() : 0;
        }
        return $size;
    }

    // ------------------------------------------------------------------
    // Restore
    // ------------------------------------------------------------------

    /**
     * Restores a backup: a "before restore" backup first, then the options and
     * tables, then (when $with_files) the plugin files.
     *
     * @return array{safety_id:string,options:int,tables:int,rows:int,files:bool,version:string}|WP_Error
     */
    public static function restore( string $id, bool $with_files ) {
        $manifest = self::read_manifest( $id );
        if ( ! $manifest ) {
            return new WP_Error( 'presshub_restore_missing', __( 'That backup was not found or is incomplete.', 'presshub-ai-editor' ) );
        }
        $dir = self::backup_path( $id );
        if ( $with_files ) {
            if ( empty( $manifest['plugin_zip'] ) || ! is_file( $dir . '/plugin.zip' ) ) {
                return new WP_Error( 'presshub_restore_zip', __( 'This backup has no plugin files.', 'presshub-ai-editor' ) );
            }
            if ( self::plugin_dir_is_linked() ) {
                return new WP_Error( 'presshub_restore_linked', __( 'The plugin folder is a link to a code checkout (a development site), so PressHub will not overwrite its files. Restore the data only, or check out that version in git.', 'presshub-ai-editor' ) );
            }
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        // Read every file of the backup before anything is deleted or dropped.
        $checked = self::verify( $manifest, $with_files );
        if ( is_wp_error( $checked ) ) {
            return new WP_Error( $checked->get_error_code(), sprintf(
                /* translators: %s: what is wrong with the backup. */
                __( 'Nothing was restored, because this backup is damaged: %s', 'presshub-ai-editor' ),
                $checked->get_error_message()
            ) );
        }

        $safety = self::create( self::REASON_RESTORE, (string) ( $manifest['plugin_version'] ?? '' ), $id );
        if ( is_wp_error( $safety ) ) {
            return new WP_Error( 'presshub_restore_safety', sprintf(
                /* translators: %s: why the backup failed. */
                __( 'Nothing was restored, because the backup of the current state failed first: %s', 'presshub-ai-editor' ),
                $safety->get_error_message()
            ) );
        }

        // Files first: if WordPress cannot put them back (for example it needs FTP
        // details), the database has not been touched yet.
        if ( $with_files ) {
            $files = self::restore_plugin_files( $dir . '/plugin.zip' );
            if ( is_wp_error( $files ) ) {
                return self::restore_failed( $id, $safety['id'], $files );
            }
            self::$files_restored = true;
        }

        $options = self::restore_options( $dir . '/options.jsonl.gz' );
        if ( is_wp_error( $options ) ) {
            return self::restore_failed( $id, $safety['id'], $options );
        }
        $rows = 0;
        foreach ( $manifest['tables'] as $suffix => $info ) {
            $count = self::restore_table( (string) $suffix, $dir . '/tables/' . $suffix, ! empty( $info['create'] ) );
            if ( is_wp_error( $count ) ) {
                return self::restore_failed( $id, $safety['id'], $count );
            }
            $rows += $count;
        }
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        $result = [
            'safety_id' => $safety['id'],
            'options'   => $options,
            'tables'    => count( $manifest['tables'] ),
            'rows'      => $rows,
            'files'     => $with_files,
            'version'   => (string) ( $manifest['plugin_version'] ?? '' ),
        ];
        $context = array_merge( [ 'backup_id' => $id ], $result );
        PressHub_AI_Logger::info( 'PressHub backup restored', $context );
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            PressHub_AI_Audit_Logger::log( 'backup_restored', 'backup', $id, $context );
        }
        return $result;
    }

    private static function restore_failed( string $id, string $safety_id, WP_Error $error ): WP_Error {
        PressHub_AI_Logger::error( 'PressHub restore failed', [
            'backup_id'  => $id,
            'safety_id'  => $safety_id,
            'error_code' => $error->get_error_code(),
            'error'      => $error->get_error_message(),
        ] );
        return new WP_Error( $error->get_error_code(), sprintf(
            /* translators: 1: error, 2: backup id. */
            __( 'The restore stopped part way: %1$s The state before the restore is saved as backup %2$s; restore that one to undo.', 'presshub-ai-editor' ),
            $error->get_error_message(),
            $safety_id
        ) );
    }

    /**
     * Checks a backup can be restored in full: every file is there, every line
     * reads as JSON and the counts match the manifest. Nothing is changed.
     *
     * @return true|WP_Error
     */
    public static function verify( array $manifest, bool $with_files = false ) {
        $dir   = self::backup_path( (string) ( $manifest['id'] ?? '' ) );
        $files = [ 'options.jsonl.gz' => (int) ( $manifest['options'] ?? 0 ) ];
        if ( $with_files ) {
            $zip = class_exists( 'ZipArchive' ) ? new ZipArchive() : null;
            if ( ! $zip || ! is_readable( $dir . '/plugin.zip' ) || true !== $zip->open( $dir . '/plugin.zip' ) ) {
                return new WP_Error( 'presshub_restore_file', __( 'The plugin zip is missing or cannot be opened.', 'presshub-ai-editor' ) );
            }
            $has_main = false !== $zip->locateName( self::PLUGIN_FOLDER . '/' . self::PLUGIN_MAIN );
            $zip->close();
            if ( ! $has_main ) {
                return new WP_Error( 'presshub_restore_file', __( 'The plugin zip does not contain PressHub.', 'presshub-ai-editor' ) );
            }
        }
        foreach ( (array) ( $manifest['tables'] ?? [] ) as $suffix => $info ) {
            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $suffix ) || ! self::is_plugin_table( self::db_prefix() . $suffix ) ) {
                return new WP_Error( 'presshub_restore_table', __( 'The backup lists a table with an invalid name.', 'presshub-ai-editor' ) );
            }
            $sql_file = $dir . '/tables/' . $suffix . '.sql';
            if ( ! empty( $info['create'] ) && ( ! is_readable( $sql_file ) || ! preg_match( '/^\s*CREATE\s+TABLE\b/i', (string) file_get_contents( $sql_file, false, null, 0, 64 ) ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                return new WP_Error( 'presshub_restore_file', sprintf(
                    /* translators: %s: file name. */
                    __( 'The file %s is missing.', 'presshub-ai-editor' ),
                    'tables/' . $suffix . '.sql'
                ) );
            }
            $files[ 'tables/' . $suffix . '.jsonl.gz' ] = (int) ( $info['rows'] ?? 0 );
        }
        foreach ( $files as $file => $expected ) {
            if ( ! is_readable( $dir . '/' . $file ) ) {
                return new WP_Error( 'presshub_restore_file', sprintf(
                    /* translators: %s: file name. */
                    __( 'The file %s is missing.', 'presshub-ai-editor' ),
                    $file
                ) );
            }
            $count = 0;
            try {
                foreach ( self::read_lines( $dir . '/' . $file ) as $row ) {
                    unset( $row );
                    $count++;
                }
            } catch ( RuntimeException $e ) {
                return new WP_Error( 'presshub_restore_file', $e->getMessage() );
            }
            if ( $count !== $expected ) {
                return new WP_Error( 'presshub_restore_file', sprintf(
                    /* translators: 1: file name, 2: rows found, 3: rows expected. */
                    __( 'The file %1$s has %2$d rows instead of %3$d.', 'presshub-ai-editor' ),
                    $file,
                    $count,
                    $expected
                ) );
            }
        }
        return true;
    }

    /**
     * Reads a .jsonl.gz file line by line. A line that is not JSON, or a file
     * that cannot be opened or ends early, throws RuntimeException.
     *
     * @return Generator<array>
     */
    private static function read_lines( string $file ) {
        $gz = @gzopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
        if ( ! $gz ) {
            throw new RuntimeException( sprintf(
                /* translators: %s: file name. */
                __( 'The file %s could not be opened.', 'presshub-ai-editor' ),
                basename( $file )
            ) );
        }
        $number = 0;
        try {
            while ( ! gzeof( $gz ) ) {
                $line = gzgets( $gz );
                if ( false === $line ) {
                    if ( gzeof( $gz ) ) {
                        break;
                    }
                    throw new RuntimeException( sprintf(
                        /* translators: %s: file name. */
                        __( 'The file %s could not be read to the end.', 'presshub-ai-editor' ),
                        basename( $file )
                    ) );
                }
                $number++;
                // Every line the backup writes ends with a newline; one without it was cut short.
                if ( "\n" !== substr( $line, -1 ) ) {
                    throw new RuntimeException( sprintf(
                        /* translators: 1: file name, 2: line number. */
                        __( 'The file %1$s ends in the middle of line %2$d.', 'presshub-ai-editor' ),
                        basename( $file ),
                        $number
                    ) );
                }
                $line = trim( $line );
                if ( '' === $line ) {
                    continue;
                }
                $row = json_decode( $line, true );
                if ( ! is_array( $row ) ) {
                    throw new RuntimeException( sprintf(
                        /* translators: 1: line number, 2: file name. */
                        __( 'Line %1$d of %2$s is not readable.', 'presshub-ai-editor' ),
                        $number,
                        basename( $file )
                    ) );
                }
                yield $row;
            }
        } finally {
            gzclose( $gz );
        }
    }

    /** Replaces every presshub_* option with the saved rows. @return int|WP_Error */
    private static function restore_options( string $file ) {
        global $wpdb;
        if ( ! is_file( $file ) ) {
            return new WP_Error( 'presshub_restore_file', __( 'The options file of this backup is missing.', 'presshub-ai-editor' ) );
        }
        // Read the whole (small) file before the current options are cleared.
        try {
            $lines = iterator_to_array( self::read_lines( $file ), false );
        } catch ( RuntimeException $e ) {
            return new WP_Error( 'presshub_restore_file', $e->getMessage() );
        }
        $keep = self::OPTION_EXCLUDE;
        $sql  = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", self::option_like() );
        foreach ( $keep as $name ) {
            $sql .= $wpdb->prepare( ' AND option_name <> %s', $name );
        }
        if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from prepare() parts.
            return new WP_Error( 'presshub_restore_db', sprintf(
                /* translators: %s: database error. */
                __( 'Clearing the current PressHub options failed: %s', 'presshub-ai-editor' ),
                $wpdb->last_error
            ) );
        }
        $count = 0;
        foreach ( $lines as $row ) {
            $name = isset( $row['n'] ) ? (string) $row['n'] : '';
            if ( 0 !== strpos( $name, 'presshub_' ) || in_array( $name, $keep, true ) ) {
                continue;
            }
            $ok = $wpdb->insert( $wpdb->options, [
                'option_name'  => $name,
                'option_value' => (string) ( $row['v'] ?? '' ),
                'autoload'     => (string) ( $row['a'] ?? 'yes' ),
            ], [ '%s', '%s', '%s' ] );
            if ( false === $ok ) {
                return new WP_Error( 'presshub_restore_db', sprintf(
                    /* translators: 1: option name, 2: database error. */
                    __( 'Writing the option %1$s failed: %2$s', 'presshub-ai-editor' ),
                    $name,
                    $wpdb->last_error
                ) );
            }
            $count++;
        }
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }
        return $count;
    }

    /** Recreates one table and loads its saved rows. @return int|WP_Error Rows loaded. */
    private static function restore_table( string $suffix, string $base, bool $has_create ) {
        global $wpdb;
        $table = self::db_prefix() . $suffix;
        if ( ! self::is_plugin_table( $table ) ) {
            return new WP_Error( 'presshub_restore_table', sprintf(
                /* translators: %s: table name. */
                __( 'The backup names a table PressHub does not own: %s', 'presshub-ai-editor' ),
                $table
            ) );
        }
        $create = $has_create && is_file( $base . '.sql' ) ? (string) file_get_contents( $base . '.sql' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( '' !== $create ) {
            // The saved statement names the table with the old prefix; use the current one.
            $create = preg_replace( '/^\s*CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?[A-Za-z0-9_]+`?/i', 'CREATE TABLE `' . $table . '`', $create, 1 );
            if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ) || false === $wpdb->query( $create ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- validated table name; saved DDL.
                return new WP_Error( 'presshub_restore_db', sprintf(
                    /* translators: 1: table, 2: database error. */
                    __( 'Recreating %1$s failed: %2$s', 'presshub-ai-editor' ),
                    $table,
                    $wpdb->last_error
                ) );
            }
        } else {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $exists !== $table ) {
                return new WP_Error( 'presshub_restore_db', sprintf(
                    /* translators: %s: table. */
                    __( 'The table %s is missing and the backup has no definition for it.', 'presshub-ai-editor' ),
                    $table
                ) );
            }
            if ( false === $wpdb->query( "DELETE FROM `{$table}`" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- validated table name.
                return new WP_Error( 'presshub_restore_db', sprintf(
                    /* translators: 1: table, 2: database error. */
                    __( 'Clearing %1$s failed: %2$s', 'presshub-ai-editor' ),
                    $table,
                    $wpdb->last_error
                ) );
            }
        }

        $count     = 0;
        $batch     = [];
        $cols      = null;
        $bytes     = 0;
        $max_bytes = self::insert_max_bytes();
        $flush     = static function () use ( &$batch, &$cols, &$bytes, $table, $wpdb ) {
            if ( ! $batch ) {
                return true;
            }
            $values = [];
            $args   = [];
            foreach ( $batch as $row ) {
                $parts = [];
                foreach ( $cols as $col ) {
                    if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] ) {
                        $parts[] = 'NULL';
                    } else {
                        $parts[] = '%s';
                        $args[]  = (string) $row[ $col ];
                    }
                }
                $values[] = '(' . implode( ', ', $parts ) . ')';
            }
            $sql = 'INSERT INTO `' . $table . '` (`' . implode( '`, `', $cols ) . '`) VALUES ' . implode( ', ', $values );
            if ( $args ) {
                $sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
            }
            $batch = [];
            $bytes = 0;
            return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        };
        try {
            foreach ( self::read_lines( $base . '.jsonl.gz' ) as $row ) {
                $row_cols = array_values( array_filter( array_map( 'strval', array_keys( $row ) ), static function ( $c ) {
                    return (bool) preg_match( '/^[A-Za-z0-9_]+$/', $c );
                } ) );
                // Rough size of this row in the query: values doubled for escaping, plus quotes and commas.
                $size = 16;
                foreach ( $row as $value ) {
                    $size += null === $value ? 5 : 2 * strlen( (string) $value ) + 4;
                }
                // Send what is queued before the columns change or the query would grow past the cap.
                if ( $batch && ( $row_cols !== $cols || $bytes + $size > $max_bytes || count( $batch ) >= self::ROW_BATCH ) && ! $flush() ) {
                    return self::insert_error( $table );
                }
                $cols    = $row_cols;
                $batch[] = $row;
                $bytes  += $size;
                $count++;
            }
        } catch ( RuntimeException $e ) {
            return new WP_Error( 'presshub_restore_file', $e->getMessage() );
        }
        if ( ! $flush() ) {
            return self::insert_error( $table );
        }
        return $count;
    }

    /** INSERT size cap: INSERT_MAX_BYTES, or half the server's max_allowed_packet when that is smaller. */
    private static function insert_max_bytes(): int {
        global $wpdb;
        $quiet  = $wpdb->suppress_errors( true );
        $packet = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
        $wpdb->suppress_errors( $quiet );
        $wpdb->last_error = '';
        if ( $packet > 0 ) {
            return max( 65536, min( self::INSERT_MAX_BYTES, (int) ( $packet / 2 ) ) );
        }
        return self::INSERT_MAX_BYTES;
    }

    private static function insert_error( string $table ): WP_Error {
        global $wpdb;
        return new WP_Error( 'presshub_restore_db', sprintf(
            /* translators: 1: table, 2: database error. */
            __( 'Loading the rows of %1$s failed: %2$s', 'presshub-ai-editor' ),
            $table,
            $wpdb->last_error
        ) );
    }

    /** Reinstalls the saved plugin zip over the plugin folder. @return true|WP_Error */
    private static function restore_plugin_files( string $zip ) {
        if ( ! class_exists( 'Plugin_Upgrader' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if ( ! function_exists( 'request_filesystem_credentials' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        self::$busy = true;
        $result     = $upgrader->install( $zip, [ 'overwrite_package' => true ] );
        self::$busy = false;
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( is_wp_error( $skin->result ) ) {
            return $skin->result;
        }
        if ( true !== $result ) {
            $messages = array_filter( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );
            return new WP_Error( 'presshub_restore_files', sprintf(
                /* translators: %s: upgrader messages. */
                __( 'WordPress could not put back the plugin files. %s', 'presshub-ai-editor' ),
                implode( ' ', $messages )
            ) );
        }
        // The folder and main file are unchanged, so the plugin stays active.
        if ( function_exists( 'wp_clean_plugins_cache' ) ) {
            wp_clean_plugins_cache();
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Web access check
    // ------------------------------------------------------------------

    /**
     * Whether the web server hands out files from the backup folder. Writes a
     * canary file with random content, requests its URL and deletes it.
     * Cached for 12 hours. 'exposed' only when the canary's content came back;
     * 'protected' only on a real 401/403/404; 'outside' when the folder is not
     * under wp-content; otherwise 'unverified'.
     */
    public static function exposure( bool $force = false ): string {
        if ( ! $force ) {
            $cached = get_transient( self::exposure_key() );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }
        $state    = self::check_exposure();
        $previous = get_transient( self::exposure_key() );
        // A check that could not finish never clears an earlier "exposed".
        if ( 'unverified' === $state && 'exposed' === $previous ) {
            $state = 'exposed';
        }
        set_transient( self::exposure_key(), $state, 12 * 3600 );
        if ( 'exposed' === $state ) {
            PressHub_AI_Logger::warning( 'PressHub backup folder is publicly accessible over HTTP', [ 'dir' => self::base_dir() ] );
        }
        return $state;
    }

    /** The cached result belongs to one folder: moving it (PRESSHUB_AI_BACKUP_DIR) checks again. */
    private static function exposure_key(): string {
        return self::EXPOSURE_TRANSIENT . '_' . substr( md5( self::base_dir() ), 0, 12 );
    }

    private static function check_exposure(): string {
        if ( ! defined( 'WP_CONTENT_DIR' ) ) {
            return 'outside';
        }
        $base    = wp_normalize_path( self::base_dir() );
        $content = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
        $windows = '\\' === DIRECTORY_SEPARATOR;
        if ( 0 !== strpos( $windows ? strtolower( $base . '/' ) : $base . '/', $windows ? strtolower( $content . '/' ) : $content . '/' ) || ! function_exists( 'wp_remote_get' ) || ! function_exists( 'content_url' ) ) {
            return 'outside';
        }
        if ( true !== self::ensure_base_dir() ) {
            return 'unverified';
        }
        $token = wp_generate_password( 24, false, false );
        $name  = 'canary-' . strtolower( wp_generate_password( 12, false, false ) ) . '.txt';
        if ( false === file_put_contents( $base . '/' . $name, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            return 'unverified';
        }
        try {
            $url      = content_url( substr( $base, strlen( $content ) ) . '/' . $name );
            $response = wp_remote_get( $url, [ 'timeout' => 5, 'redirection' => 0, 'sslverify' => false ] );
        } finally {
            @unlink( $base . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( is_wp_error( $response ) ) {
            return 'unverified';
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $response ), $token ) ) {
            return 'exposed';
        }
        return in_array( $code, [ 401, 403, 404 ], true ) ? 'protected' : 'unverified';
    }

    // ------------------------------------------------------------------
    // Admin page
    // ------------------------------------------------------------------

    public function add_admin_menu(): void {
        add_submenu_page(
            PressHub_Admin_Menu::PARENT,
            PressHub_Admin_Menu::page_title( __( 'Backups', 'presshub-ai-editor' ) ),
            __( 'Backups', 'presshub-ai-editor' ),
            PressHub_Capabilities::manage(),
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue_assets( $hook ): void {
        if ( ! defined( 'PRESSHUB_AI_URL' ) || ! PressHub_Admin_Menu::is_page_hook( (string) $hook, self::PAGE_SLUG ) ) {
            return;
        }
        wp_enqueue_style( 'presshub-backups', PRESSHUB_AI_URL . 'assets/css/backups.css', [], defined( 'PRESSHUB_AI_VERSION' ) ? PRESSHUB_AI_VERSION : false );
    }

    /**
     * Taking, restoring and deleting need the PressHub capability and the
     * right to update plugins (administrators), since a restore replaces
     * plugin files and settings.
     */
    public static function current_user_can_act(): bool {
        return PressHub_Capabilities::current_user_can_manage() && current_user_can( 'update_plugins' );
    }

    public static function page_url( array $args = [] ): string {
        return PressHub_Admin_Menu::url( self::PAGE_SLUG, $args );
    }

    private static function notice_key(): string {
        return self::NOTICE_PREFIX . ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
    }

    private static function finish( string $type, string $message, string $url = '' ): void {
        set_transient( self::notice_key(), [ 'type' => $type, 'message' => $message ], 300 );
        wp_safe_redirect( '' !== $url ? $url : self::page_url() );
        exit;
    }

    private static function guard( string $action ): void {
        if ( ! self::current_user_can_act() ) {
            wp_die( esc_html__( 'Only an administrator who can update plugins can take, restore or delete PressHub backups.', 'presshub-ai-editor' ), 403 );
        }
        check_admin_referer( $action );
    }

    /** The backup id posted with a form, if valid. */
    private static function posted_id(): string {
        $id = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
        return self::is_valid_id( $id ) ? $id : '';
    }

    public static function handle_take(): void {
        self::guard( self::BACKUP_ACTION );
        $result = self::create( self::REASON_MANUAL );
        if ( is_wp_error( $result ) ) {
            self::finish( 'error', $result->get_error_message() );
        }
        self::finish( 'success', sprintf(
            /* translators: %s: backup size. */
            __( 'Backup taken (%s).', 'presshub-ai-editor' ),
            size_format( (int) $result['bytes'] )
        ) );
    }

    public static function handle_restore(): void {
        self::guard( self::RESTORE_ACTION );
        $id = self::posted_id();
        if ( '' === $id ) {
            self::finish( 'error', __( 'That backup was not found.', 'presshub-ai-editor' ) );
        }
        $with_files = ! empty( $_POST['with_files'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
        $result     = self::restore( $id, $with_files );
        if ( is_wp_error( $result ) ) {
            $manifest = self::read_manifest( $id );
            $old      = $manifest && version_compare( (string) ( $manifest['plugin_version'] ?? '' ), '2.53.0', '<' );
            if ( self::$files_restored && $old ) {
                // The old files may already be in place, and they have no Backups page to show this.
                wp_die(
                    '<p>' . esc_html( $result->get_error_message() ) . '</p><p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Plugins', 'presshub-ai-editor' ) . '</a></p>',
                    esc_html__( 'PressHub restore stopped', 'presshub-ai-editor' ),
                    [ 'response' => 500 ]
                );
            }
            self::finish( 'error', $result->get_error_message() );
        }
        $message = $result['files']
            ? sprintf(
                /* translators: 1: version, 2: options, 3: rows, 4: tables. */
                __( 'Restored PressHub %1$s: its plugin files, %2$d options and %3$d rows in %4$d tables. The state before the restore was saved as a backup, listed below.', 'presshub-ai-editor' ),
                $result['version'],
                $result['options'],
                $result['rows'],
                $result['tables']
            )
            : sprintf(
                /* translators: 1: options, 2: rows, 3: tables, 4: version. */
                __( 'Restored %1$d options and %2$d rows in %3$d tables, saved with PressHub %4$s. The plugin files were not changed. The state before the restore was saved as a backup, listed below.', 'presshub-ai-editor' ),
                $result['options'],
                $result['rows'],
                $result['tables'],
                $result['version']
            );
        // A version older than this feature has no Backups page to come back to.
        $url = $result['files'] && version_compare( $result['version'], '2.53.0', '<' ) ? admin_url( 'plugins.php' ) : '';
        self::finish( 'success', $message, $url );
    }

    public static function handle_recheck(): void {
        self::guard( self::RECHECK_ACTION );
        $state = self::exposure( true );
        if ( 'exposed' === $state ) {
            self::finish( 'error', __( 'Checked again: the web server still hands out files from the backup folder.', 'presshub-ai-editor' ) );
        }
        if ( 'protected' === $state ) {
            self::finish( 'success', __( 'Checked again: the backup folder is blocked from the web.', 'presshub-ai-editor' ) );
        }
        if ( 'outside' === $state ) {
            self::finish( 'success', __( 'Checked again: the backup folder is outside wp-content, so the web server does not serve it.', 'presshub-ai-editor' ) );
        }
        self::finish( 'warning', __( 'PressHub could not check whether the backup folder is blocked: the test request to this site did not answer. Check the folder by hand, or ask your host.', 'presshub-ai-editor' ) );
    }

    public static function handle_delete(): void {
        self::guard( self::DELETE_ACTION );
        $id = self::posted_id();
        if ( '' === $id || ! self::delete_dir( self::backup_path( $id ) ) ) {
            self::finish( 'error', __( 'That backup could not be deleted.', 'presshub-ai-editor' ) );
        }
        PressHub_AI_Logger::info( 'PressHub backup deleted', [ 'backup_id' => $id ] );
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            PressHub_AI_Audit_Logger::log( 'backup_deleted', 'backup', $id, [ 'backup_id' => $id ] );
        }
        self::finish( 'success', __( 'Backup deleted.', 'presshub-ai-editor' ) );
    }

    /** "Before update to 2.53.0", "Taken by hand", "Before a restore". */
    public static function reason_label( array $backup ): string {
        $target = (string) ( $backup['target_version'] ?? '' );
        switch ( $backup['reason'] ?? '' ) {
            case self::REASON_UPDATE:
                return '' !== $target
                    /* translators: %s: version number. */
                    ? sprintf( __( 'Before the update to %s', 'presshub-ai-editor' ), $target )
                    : __( 'Before an update', 'presshub-ai-editor' );
            case self::REASON_RESTORE:
                return '' !== $target
                    /* translators: %s: version number. */
                    ? sprintf( __( 'Before restoring %s', 'presshub-ai-editor' ), $target )
                    : __( 'Before a restore', 'presshub-ai-editor' );
            default:
                return __( 'Taken by hand', 'presshub-ai-editor' );
        }
    }

    private static function format_time( int $ts, bool $seconds = false ): string {
        if ( function_exists( 'wp_date' ) ) {
            return (string) wp_date( get_option( 'date_format' ) . ' ' . ( $seconds ? 'H:i:s' : get_option( 'time_format' ) ), $ts );
        }
        return gmdate( $seconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i', $ts );
    }

    /** "18 options, 7 tables (1,204 rows), plugin files". */
    public static function contents_label( array $backup, bool $with_files = true ): string {
        $rows  = array_sum( array_map( static function ( $t ) {
            return (int) ( $t['rows'] ?? 0 );
        }, (array) ( $backup['tables'] ?? [] ) ) );
        $parts = [
            sprintf(
                /* translators: %s: number of options. */
                _n( '%s option', '%s options', (int) ( $backup['options'] ?? 0 ), 'presshub-ai-editor' ),
                number_format_i18n( (int) ( $backup['options'] ?? 0 ) )
            ),
            sprintf(
                /* translators: 1: number of tables, 2: "5 rows". */
                _n( '%1$s table (%2$s)', '%1$s tables (%2$s)', count( (array) ( $backup['tables'] ?? [] ) ), 'presshub-ai-editor' ),
                number_format_i18n( count( (array) ( $backup['tables'] ?? [] ) ) ),
                sprintf(
                    /* translators: %s: number of rows. */
                    _n( '%s row', '%s rows', $rows, 'presshub-ai-editor' ),
                    number_format_i18n( $rows )
                )
            ),
        ];
        if ( $with_files && ! empty( $backup['plugin_zip'] ) ) {
            $parts[] = __( 'plugin files', 'presshub-ai-editor' );
        }
        return implode( ', ', $parts );
    }

    public static function render_page(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to view PressHub backups.', 'presshub-ai-editor' ) );
        }
        $can_act = self::current_user_can_act();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
        $confirm = isset( $_GET['confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['confirm'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
        $do      = isset( $_GET['do'] ) && 'delete' === $_GET['do'] ? 'delete' : 'restore';

        echo '<div class="wrap presshub-backups-wrap" id="presshub-backups-page">';
        echo '<h1>' . esc_html( PressHub_Admin_Menu::page_title( __( 'Backups', 'presshub-ai-editor' ) ) ) . '</h1>';
        echo '<hr class="wp-header-end" />';

        $notice = get_transient( self::notice_key() );
        if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
            delete_transient( self::notice_key() );
            $type  = (string) ( $notice['type'] ?? '' );
            $error = 'error' === $type;
            $class = $error ? 'notice-error' : ( 'warning' === $type ? 'notice-warning' : 'notice-success' );
            echo '<div class="notice ' . $class . '" role="' . ( $error ? 'alert' : 'status' ) . '"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
        }

        if ( $can_act && '' !== $confirm && self::is_valid_id( $confirm ) ) {
            $backup = self::read_manifest( $confirm );
            if ( $backup ) {
                self::render_confirm( $backup, $do );
                echo '</div>';
                return;
            }
            echo '<div class="notice notice-error" role="alert"><p>' . esc_html__( 'That backup was not found or is incomplete.', 'presshub-ai-editor' ) . '</p></div>';
        }

        self::render_intro( $can_act );
        self::render_list( $can_act );
        echo '</div>';
    }

    private static function render_intro( bool $can_act ): void {
        $keep = PressHub_AI_Settings_Storage::get_backup_keep();
        echo '<p class="presshub-backups-intro">' . esc_html( sprintf(
            /* translators: %d: number of backups kept. */
            _n(
                'Before WordPress updates PressHub, PressHub saves its settings, its database tables and the plugin files it is about to replace. If an update goes wrong, restore the backup taken just before it. The newest %d backup is kept.',
                'Before WordPress updates PressHub, PressHub saves its settings, its database tables and the plugin files it is about to replace. If an update goes wrong, restore the backup taken just before it. The newest %d backups are kept.',
                $keep,
                'presshub-ai-editor'
            ),
            $keep
        ) ) . ' <a href="' . esc_url( PressHub_Admin_Menu::url( 'presshub-ai', [ 'section' => 'site-system' ] ) . '#presshub_ai_backup_keep' ) . '">' . esc_html__( 'Change how many', 'presshub-ai-editor' ) . '</a></p>';
        echo '<p class="description">' . esc_html__( 'Posts, pages, media and other plugins are not included: back those up with your host. The backups contain your API keys and tokens, so keep the folder private.', 'presshub-ai-editor' ) . '</p>';
        echo '<p class="description presshub-backups-folder">' . esc_html__( 'Folder:', 'presshub-ai-editor' ) . ' <code>' . esc_html( wp_normalize_path( self::base_dir() ) ) . '</code></p>';
        if ( $can_act && 'exposed' === self::exposure() ) {
            echo '<div class="notice notice-error inline" role="alert"><p><strong>' . esc_html__( 'The web server hands out files from this folder.', 'presshub-ai-editor' ) . '</strong> ' . wp_kses(
                sprintf(
                    /* translators: 1: .htaccess, 2: PRESSHUB_AI_BACKUP_DIR, 3: wp-config.php */
                    __( 'Anyone who guesses a backup name could download your settings and API keys. Block the folder in the web server (nginx ignores %1$s), or move it outside the web root with %2$s in %3$s.', 'presshub-ai-editor' ),
                    '<code>.htaccess</code>',
                    '<code>PRESSHUB_AI_BACKUP_DIR</code>',
                    '<code>wp-config.php</code>'
                ),
                [ 'code' => [] ]
            ) . '</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::RECHECK_ACTION ) . '" />';
            wp_nonce_field( self::RECHECK_ACTION );
            submit_button( __( 'Check again', 'presshub-ai-editor' ), 'secondary small', 'presshub-backups-recheck', false );
            echo '</p></form></div>';
        }
        if ( $can_act ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="presshub-backups-take">';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::BACKUP_ACTION ) . '" />';
            wp_nonce_field( self::BACKUP_ACTION );
            submit_button( __( 'Take a backup now', 'presshub-ai-editor' ), 'secondary', 'submit', false );
            echo '</form>';
        } else {
            echo '<p class="description">' . esc_html__( 'Taking and restoring backups needs an administrator who can update plugins.', 'presshub-ai-editor' ) . '</p>';
        }
    }

    private static function render_list( bool $can_act ): void {
        $backups = self::all();
        echo '<h2>' . esc_html__( 'Saved backups', 'presshub-ai-editor' ) . '</h2>';
        if ( ! $backups ) {
            echo '<p>' . ( $can_act
                ? esc_html__( 'No backups yet. The first one is taken before the next PressHub update, or now with "Take a backup now".', 'presshub-ai-editor' )
                : esc_html__( 'No backups yet. The first one is taken before the next PressHub update.', 'presshub-ai-editor' ) ) . '</p>';
            return;
        }
        echo '<div class="presshub-backups-table-wrap"><table class="widefat striped presshub-backups-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Taken', 'presshub-ai-editor' ) . '</th>';
        echo '<th scope="col" class="presshub-backups-col-version">' . esc_html__( 'PressHub version', 'presshub-ai-editor' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Why', 'presshub-ai-editor' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Contents', 'presshub-ai-editor' ) . '</th>';
        echo '<th scope="col" class="presshub-backups-col-size">' . esc_html__( 'Size', 'presshub-ai-editor' ) . '</th>';
        if ( $can_act ) {
            echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'presshub-ai-editor' ) . '</span></th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $backups as $backup ) {
            echo '<tr>';
            echo '<td>' . esc_html( self::format_time( (int) $backup['created_ts'] ) ) . '</td>';
            if ( ! $backup['complete'] ) {
                echo '<td class="presshub-backups-col-version"><span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Unknown', 'presshub-ai-editor' ) . '</span></td>';
                echo '<td>' . esc_html__( 'Incomplete', 'presshub-ai-editor' ) . '</td>';
                echo '<td>' . esc_html__( 'The backup did not finish. It is removed automatically.', 'presshub-ai-editor' ) . '</td>';
                echo '<td class="presshub-backups-col-size"><span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Unknown', 'presshub-ai-editor' ) . '</span></td>';
                if ( $can_act ) {
                    echo '<td></td>';
                }
                echo '</tr>';
                continue;
            }
            echo '<td class="presshub-backups-col-version">' . esc_html( (string) $backup['plugin_version'] ) . '</td>';
            echo '<td>' . esc_html( self::reason_label( $backup ) ) . '</td>';
            echo '<td>' . esc_html( self::contents_label( $backup ) ) . '</td>';
            echo '<td class="presshub-backups-col-size">' . esc_html( size_format( (int) ( $backup['bytes'] ?? 0 ) ) ) . '</td>';
            if ( $can_act ) {
                echo '<td class="presshub-backups-actions"><div class="presshub-backups-actions-inner">';
                /* translators: 1: date and time the backup was taken, 2: PressHub version, 3: why. Read after "Restore…" or "Delete…". */
                $which = '<span class="screen-reader-text"> ' . esc_html( sprintf( __( '(%1$s, PressHub %2$s, %3$s)', 'presshub-ai-editor' ), self::format_time( (int) $backup['created_ts'], true ), (string) $backup['plugin_version'], self::reason_label( $backup ) ) ) . '</span>';
                echo '<a class="button button-secondary" href="' . esc_url( self::page_url( [ 'confirm' => $backup['id'] ] ) ) . '">' . esc_html__( 'Restore…', 'presshub-ai-editor' ) . $which . '</a> ';
                echo '<a class="button-link button-link-delete presshub-backups-delete" href="' . esc_url( self::page_url( [ 'confirm' => $backup['id'], 'do' => 'delete' ] ) ) . '">' . esc_html__( 'Delete…', 'presshub-ai-editor' ) . $which . '</a>';
                echo '</div></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_confirm( array $backup, string $do ): void {
        $when    = self::format_time( (int) $backup['created_ts'] );
        $version = (string) $backup['plugin_version'];
        echo '<div class="card presshub-backups-confirm">';
        if ( 'delete' === $do ) {
            echo '<h2>' . esc_html__( 'Delete this backup?', 'presshub-ai-editor' ) . '</h2>';
            echo '<p>' . esc_html( sprintf(
                /* translators: 1: date and time, 2: version, 3: why it was taken. */
                __( 'The backup from %1$s (PressHub %2$s, %3$s) will be deleted. This cannot be undone.', 'presshub-ai-editor' ),
                $when,
                $version,
                self::reason_label( $backup )
            ) ) . '</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::DELETE_ACTION ) . '" />';
            echo '<input type="hidden" name="backup" value="' . esc_attr( $backup['id'] ) . '" />';
            wp_nonce_field( self::DELETE_ACTION );
            echo '<p>';
            submit_button( __( 'Delete backup', 'presshub-ai-editor' ), 'primary presshub-button-danger', 'submit', false );
            echo ' <a class="button button-secondary" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Cancel', 'presshub-ai-editor' ) . '</a></p>';
            echo '</form></div>';
            return;
        }

        $linked    = self::plugin_dir_is_linked();
        $has_files = ! empty( $backup['plugin_zip'] );
        echo '<h2>' . esc_html__( 'Restore this backup?', 'presshub-ai-editor' ) . '</h2>';
        echo '<p>' . esc_html( sprintf(
            /* translators: 1: date and time, 2: version, 3: reason. */
            __( 'Backup from %1$s, PressHub %2$s (%3$s).', 'presshub-ai-editor' ),
            $when,
            $version,
            self::reason_label( $backup )
        ) ) . '</p>';
        echo '<ul class="ul-disc">';
        echo '<li>' . esc_html__( 'First, the current PressHub settings, tables and plugin files are saved as a new backup, so you can undo the restore.', 'presshub-ai-editor' ) . '</li>';
        echo '<li>' . esc_html( sprintf(
            /* translators: %s: contents, e.g. "120 options, 7 tables (900 rows)". */
            __( 'Then every PressHub setting and table is replaced with the saved copy: %s. Anything PressHub saved after the backup (new logs, reviews, memory, queue items, settings changes) is lost.', 'presshub-ai-editor' ),
            self::contents_label( $backup, false )
        ) ) . '</li>';
        echo '<li>' . esc_html__( 'Posts, pages, media and other plugins are not changed.', 'presshub-ai-editor' ) . '</li>';
        echo '</ul>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::RESTORE_ACTION ) . '" />';
        echo '<input type="hidden" name="backup" value="' . esc_attr( $backup['id'] ) . '" />';
        wp_nonce_field( self::RESTORE_ACTION );
        if ( $has_files && ! $linked ) {
            echo '<p><label><input type="checkbox" name="with_files" value="1" checked="checked" /> ' . esc_html( sprintf(
                /* translators: %1$s: saved version, %2$s: running version. */
                __( 'Also put back the plugin files of PressHub %1$s (now %2$s)', 'presshub-ai-editor' ),
                $version,
                self::running_version()
            ) ) . '</label></p>';
        } elseif ( $has_files ) {
            echo '<p class="description">' . esc_html__( 'The plugin files will not be put back: the plugin folder is a link to a code checkout (a development site). Check out the matching version in git instead.', 'presshub-ai-editor' ) . '</p>';
        }
        echo '<p>';
        submit_button( __( 'Restore backup', 'presshub-ai-editor' ), 'primary', 'submit', false );
        echo ' <a class="button button-secondary" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Cancel', 'presshub-ai-editor' ) . '</a></p>';
        echo '<p class="description">' . esc_html__( 'A restore can take a minute on a large site. Keep this page open until it finishes.', 'presshub-ai-editor' ) . '</p>';
        echo '</form></div>';
    }
}
