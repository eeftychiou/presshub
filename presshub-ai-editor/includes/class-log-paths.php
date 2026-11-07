<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PressHub AI Log Paths (issue #138)
 *
 * Single source of truth for where the diagnostic log files live. Every
 * writer and reader (logger, prompt log, TTS payload log, AJAX handlers,
 * cron prune) resolves its path through this class.
 *
 * - Files carry a random per-site suffix so their URLs cannot be guessed.
 * - The directory always contains protection files (.htaccess, index.php,
 *   web.config); existing files are never overwritten.
 * - `PRESSHUB_AI_LOG_DIR` (wp-config.php) moves the folder, for example
 *   outside the web root. When it is set but unusable we return a WP_Error
 *   and never fall back to uploads (Zero-Silent-Fallback).
 */
class PressHub_AI_Log_Paths {

    const CHANNELS      = [ 'app', 'prompts', 'tts' ];
    const SUFFIX_OPTION = 'presshub_ai_log_file_suffix';

    /** Option holding the version of the one-off legacy file cleanup. */
    const VERSION_OPTION = 'presshub_ai_log_paths_version';
    const VERSION        = '1';

    /** Fixed-name files written before #138; removed once by run_upgrade(). */
    const LEGACY_FILES = [
        'presshub-debug.log',
        'presshub-ai-debug.log',
        'presshub-ai-tts-debug.log',
    ];

    /** @var string|null Test seam: overrides the base directory. */
    private static ?string $base_dir_override = null;

    /** @var bool Whether the misconfiguration error was already written this request. */
    private static bool $error_reported = false;

    /**
     * Test seam: override the base directory (null resets).
     */
    public static function set_base_dir_override( ?string $dir ): void {
        self::$base_dir_override = $dir;
        self::$error_reported    = false;
    }

    /**
     * Absolute log directory with protection files ensured, or WP_Error when
     * PRESSHUB_AI_LOG_DIR is set but missing or unwritable.
     *
     * @return string|WP_Error
     */
    public static function get_dir() {
        if ( null !== self::$base_dir_override ) {
            $dir = rtrim( self::$base_dir_override, '/\\' );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            self::ensure_protection( $dir );
            return $dir;
        }

        if ( defined( 'PRESSHUB_AI_LOG_DIR' ) && '' !== trim( (string) PRESSHUB_AI_LOG_DIR ) ) {
            $dir = rtrim( (string) PRESSHUB_AI_LOG_DIR, '/\\' );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
                $error = new WP_Error(
                    'presshub_ai_log_dir_unwritable',
                    sprintf(
                        /* translators: %s: configured directory path */
                        __( 'PRESSHUB_AI_LOG_DIR is set to "%s" but that folder does not exist or is not writable. File logging is disabled until it is fixed.', 'presshub-ai-editor' ),
                        $dir
                    )
                );
                self::report_error_once( $error );
                return $error;
            }
            self::ensure_protection( $dir );
            return $dir;
        }

        $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [ 'basedir' => sys_get_temp_dir() ];
        $dir     = trailingslashit( (string) ( $uploads['basedir'] ?? sys_get_temp_dir() ) ) . 'presshub-ai';
        $dir     = rtrim( $dir, '/\\' );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        self::ensure_protection( $dir );
        return $dir;
    }

    /**
     * Absolute path of a channel's current log file, or WP_Error.
     *
     * @return string|WP_Error
     */
    public static function get_file( string $channel ) {
        $name = self::get_file_name( $channel );
        if ( is_wp_error( $name ) ) {
            return $name;
        }
        $dir = self::get_dir();
        if ( is_wp_error( $dir ) ) {
            return $dir;
        }
        return $dir . '/' . $name;
    }

    /**
     * Rotated copies (`<file>.<Ymd_His>.old`) of a channel, newest first. [] on error.
     *
     * @return string[]
     */
    public static function get_rotated_files( string $channel ): array {
        $file = self::get_file( $channel );
        if ( is_wp_error( $file ) ) {
            return [];
        }
        return self::scan_rotated( dirname( $file ), basename( $file ) );
    }

    /**
     * Write .htaccess, index.php and web.config into $dir when missing.
     * Existing files are never overwritten (operators may hand-edit them).
     */
    public static function ensure_protection( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $dir = rtrim( $dir, '/\\' );
        // Check once per request per directory: get_dir() runs on every log write.
        static $checked = [];
        if ( isset( $checked[ $dir ] ) && file_exists( $dir . '/.htaccess' ) ) {
            return;
        }
        $checked[ $dir ] = true;
        $files = [
            '.htaccess'  => "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n",
            'index.php'  => "<?php // Silence is golden.\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
        ];
        foreach ( $files as $name => $content ) {
            $path = $dir . '/' . $name;
            if ( ! file_exists( $path ) ) {
                @file_put_contents( $path, $content );
            }
        }
    }

    /**
     * True when the directory is inside the web root (ABSPATH or uploads).
     */
    public static function is_web_accessible_dir( string $dir ): bool {
        $real = self::norm( $dir );
        if ( '' === $real ) {
            return false;
        }
        $roots = [];
        if ( defined( 'ABSPATH' ) ) {
            $roots[] = self::norm( ABSPATH );
        }
        if ( function_exists( 'wp_upload_dir' ) ) {
            $uploads = wp_upload_dir();
            if ( ! empty( $uploads['basedir'] ) ) {
                $roots[] = self::norm( $uploads['basedir'] );
            }
        }
        foreach ( $roots as $root ) {
            if ( '' !== $root && ( $real === $root || 0 === strpos( $real . '/', $root . '/' ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Public URL of a file in the log dir when it lives under uploads; '' otherwise.
     */
    public static function get_url_for( string $file ): string {
        if ( ! function_exists( 'wp_upload_dir' ) ) {
            return '';
        }
        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
            return '';
        }
        $base = self::norm( $uploads['basedir'] );
        $real = self::norm( dirname( $file ) );
        if ( '' === $base || '' === $real || 0 !== strpos( $real . '/', $base . '/' ) ) {
            return '';
        }
        $relative = ltrim( substr( $real, strlen( $base ) ), '/' );
        $url      = rtrim( (string) $uploads['baseurl'], '/' );
        if ( '' !== $relative ) {
            $url .= '/' . $relative;
        }
        return $url . '/' . rawurlencode( basename( $file ) );
    }

    /**
     * Delete a channel's rotated copies.
     *
     * @return int Number of files removed.
     */
    public static function delete_rotated_files( string $channel ): int {
        $deleted = 0;
        foreach ( self::get_rotated_files( $channel ) as $path ) {
            if ( @unlink( $path ) ) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Delete rotated files of all channels older than $days, judged by the
     * Ymd_His stamp (UTC) in the file name.
     *
     * @return int Number of files removed.
     */
    public static function prune_rotated_files( int $days ): int {
        $cutoff  = time() - ( max( 1, $days ) * 86400 );
        $deleted = 0;
        foreach ( self::CHANNELS as $channel ) {
            foreach ( self::get_rotated_files( $channel ) as $path ) {
                if ( ! preg_match( '/\.(\d{8})_(\d{6})\.old$/', $path, $m ) ) {
                    continue;
                }
                $stamp = \DateTime::createFromFormat( 'Ymd_His', $m[1] . '_' . $m[2], new \DateTimeZone( 'UTC' ) );
                if ( false === $stamp ) {
                    continue;
                }
                if ( $stamp->getTimestamp() < $cutoff && @unlink( $path ) ) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /**
     * Clean Slate (#138): once per upgrade, delete the old fixed-name log files
     * and their .old copies from uploads/presshub-ai/. Nothing is migrated.
     * Protection files are left alone.
     *
     * @return int Number of files removed (0 when the step already ran).
     */
    public static function run_upgrade(): int {
        if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
            return 0;
        }
        $deleted = self::delete_legacy_files();
        update_option( self::VERSION_OPTION, self::VERSION, false );
        if ( $deleted > 0 && class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( 'Removed legacy fixed-name log files', [ 'files_deleted' => $deleted ] );
        }
        return $deleted;
    }

    /**
     * Delete the legacy fixed-name files (and .old copies) from the uploads folder.
     */
    private static function delete_legacy_files(): int {
        $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [ 'basedir' => sys_get_temp_dir() ];
        $dir     = rtrim( trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . 'presshub-ai', '/\\' );
        if ( ! is_dir( $dir ) ) {
            return 0;
        }
        $deleted = 0;
        foreach ( self::LEGACY_FILES as $legacy ) {
            $path = $dir . '/' . $legacy;
            if ( is_file( $path ) && @unlink( $path ) ) {
                $deleted++;
            }
            foreach ( self::scan_rotated( $dir, $legacy ) as $old ) {
                if ( @unlink( $old ) ) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /**
     * Resolve a channel's file name (without directory), creating the suffix on first use.
     *
     * @return string|WP_Error
     */
    private static function get_file_name( string $channel ) {
        $suffix = self::get_suffix();
        switch ( $channel ) {
            case 'app':
                return 'presshub-debug-' . $suffix . '.log';
            case 'prompts':
                return 'presshub-ai-prompts-' . $suffix . '.jsonl';
            case 'tts':
                return 'presshub-ai-tts-' . $suffix . '.jsonl';
        }
        return new WP_Error( 'presshub_ai_log_unknown_channel', sprintf( 'Unknown log channel "%s".', $channel ) );
    }

    /**
     * Random per-site suffix: lowercase alphanumeric, 20 chars, generated once.
     */
    private static function get_suffix(): string {
        $suffix = get_option( self::SUFFIX_OPTION, '' );
        if ( is_string( $suffix ) && preg_match( '/^[a-z0-9]{20}$/', $suffix ) ) {
            return $suffix;
        }
        $suffix = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) wp_generate_password( 20, false ) ) );
        $suffix = substr( str_pad( $suffix, 20, '0' ), 0, 20 );
        // add_option() only succeeds for the first writer, so two simultaneous first
        // requests cannot end up logging to different files: the loser re-reads.
        if ( function_exists( 'add_option' ) && ! add_option( self::SUFFIX_OPTION, $suffix, '', false ) ) {
            if ( function_exists( 'wp_cache_delete' ) ) {
                wp_cache_delete( self::SUFFIX_OPTION, 'options' );
            }
            $stored = get_option( self::SUFFIX_OPTION, '' );
            if ( is_string( $stored ) && preg_match( '/^[a-z0-9]{20}$/', $stored ) ) {
                return $stored;
            }
            update_option( self::SUFFIX_OPTION, $suffix, false );
        } elseif ( ! function_exists( 'add_option' ) ) {
            update_option( self::SUFFIX_OPTION, $suffix, false );
        }
        return $suffix;
    }

    /**
     * Rotated copies of $name inside $dir, newest first.
     *
     * @return string[]
     */
    private static function scan_rotated( string $dir, string $name ): array {
        $found = [];
        $items = is_dir( $dir ) ? scandir( $dir ) : false;
        if ( false === $items ) {
            return [];
        }
        $pattern = '/^' . preg_quote( $name, '/' ) . '\.\d{8}_\d{6}\.old$/';
        foreach ( $items as $item ) {
            if ( preg_match( $pattern, $item ) ) {
                $found[] = rtrim( $dir, '/\\' ) . '/' . $item;
            }
        }
        rsort( $found, SORT_STRING );
        return $found;
    }

    /**
     * Normalise a path for prefix comparison (forward slashes, no trailing slash).
     */
    private static function norm( string $path ): string {
        $real = realpath( $path );
        $path = false !== $real ? $real : $path;
        return rtrim( str_replace( '\\', '/', $path ), '/' );
    }

    /**
     * One error_log line per request when PRESSHUB_AI_LOG_DIR is unusable.
     * This is a raw error_log() on purpose: the file logger itself is
     * unavailable in this state, so PressHub_AI_Logger cannot record it.
     */
    private static function report_error_once( WP_Error $error ): void {
        if ( self::$error_reported ) {
            return;
        }
        self::$error_reported = true;
        error_log( 'PressHub AI [ERROR]: ' . $error->get_error_message() );
    }
}
