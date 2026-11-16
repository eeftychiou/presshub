<?php
/**
 * Settings export and import (#233 step 6).
 *
 * Export writes every registered PressHub option (both settings pages) to a
 * JSON file. Secrets (keys, tokens, passwords) are never written: they are
 * listed by name only. Import reads such a file, shows what would change, and
 * writes only registered, non-secret options, each through its registered
 * sanitizer. This is the rollback path for every later settings phase.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-settings-registry.php';

class PressHub_Settings_Transfer {

    const FORMAT         = 'presshub-settings';
    const FORMAT_VERSION = 1;

    const EXPORT_ACTION  = 'presshub_settings_export';
    const PREVIEW_ACTION = 'presshub_settings_import_preview';
    const APPLY_ACTION   = 'presshub_settings_import_apply';
    const NONCE          = 'presshub_settings_transfer';
    const TRANSIENT      = 'presshub_settings_import_';
    const MAX_BYTES      = 2097152;

    public static function register_hooks(): void {
        add_action( 'admin_post_' . self::EXPORT_ACTION, [ __CLASS__, 'handle_export' ] );
        add_action( 'admin_post_' . self::PREVIEW_ACTION, [ __CLASS__, 'handle_preview' ] );
        add_action( 'admin_post_' . self::APPLY_ACTION, [ __CLASS__, 'handle_apply' ] );
    }

    // ------------------------------------------------------------------
    // Pure logic (tested directly).
    // ------------------------------------------------------------------

    /**
     * Whether an option may travel in an export file and be written by import.
     * Secrets and "remove key" flags never are.
     */
    public static function is_transferable( string $key ): bool {
        $e = PressHub_Settings_Registry::get( $key );
        return null !== $e && empty( $e['secret'] ) && empty( $e['removes'] );
    }

    /**
     * Export payload: every stored, transferable option of both pages.
     *
     * @return array{format:string,format_version:int,plugin_version:string,exported_at:string,site:string,options:array<string,mixed>,secrets_left_out:string[]}
     */
    public static function export_data(): array {
        PressHub_Settings_Registry::ensure_registered();
        $options = [];
        $secrets = [];
        foreach ( PressHub_Settings_Registry::all() as $key => $e ) {
            if ( ! empty( $e['removes'] ) ) {
                continue;
            }
            if ( ! empty( $e['secret'] ) ) {
                $secrets[] = $key;
                continue;
            }
            $missing = new stdClass();
            $value   = get_option( $key, $missing );
            if ( $value !== $missing ) {
                $options[ $key ] = $value;
            }
        }
        return [
            'format'           => self::FORMAT,
            'format_version'   => self::FORMAT_VERSION,
            'plugin_version'   => defined( 'PRESSHUB_AI_VERSION' ) ? (string) PRESSHUB_AI_VERSION : '',
            'exported_at'      => gmdate( 'c' ),
            'site'             => function_exists( 'home_url' ) ? (string) home_url() : '',
            'options'          => $options,
            // The AI provider records (models, endpoints and their API keys)
            // are stored outside the registry; they are not exported either.
            'secrets_left_out' => array_merge( $secrets, [ 'presshub_ai_configured_providers' ] ),
        ];
    }

    /**
     * Parses an export file. Returns the options array or a WP_Error-like
     * array [ 'error' => message ].
     *
     * @return array{options?:array,error?:string}
     */
    public static function parse( string $json ): array {
        if ( strlen( $json ) > self::MAX_BYTES ) {
            return [ 'error' => __( 'The file is larger than 2 MB, so it is not a PressHub settings export.', 'presshub-ai-editor' ) ];
        }
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) || ! isset( $data['options'] ) || ! is_array( $data['options'] ) ) {
            return [ 'error' => __( 'This file is not a PressHub settings export.', 'presshub-ai-editor' ) ];
        }
        if ( (int) ( $data['format_version'] ?? 0 ) > self::FORMAT_VERSION ) {
            return [ 'error' => __( 'This export comes from a newer PressHub. Update the plugin first.', 'presshub-ai-editor' ) ];
        }
        return [ 'options' => $data['options'] ];
    }

    /**
     * What an import would do. Values are shown as they are in the file;
     * apply() stores each through the option's sanitizer.
     *
     * @param array<string,mixed> $incoming Options from the file.
     * @return array{changes:array<string,array{from:mixed,to:mixed}>,unchanged:int,skipped:array<string,string>}
     */
    public static function preview( array $incoming ): array {
        PressHub_Settings_Registry::ensure_registered();
        $changes   = [];
        $unchanged = 0;
        $skipped   = [];
        foreach ( $incoming as $key => $value ) {
            $key = (string) $key;
            if ( ! PressHub_Settings_Registry::has( $key ) ) {
                $skipped[ $key ] = __( 'not a PressHub setting', 'presshub-ai-editor' );
                continue;
            }
            if ( ! self::is_transferable( $key ) ) {
                $skipped[ $key ] = __( 'secrets are never imported', 'presshub-ai-editor' );
                continue;
            }
            // Preview never runs sanitizers: some Social sanitizers write
            // related options as a side effect. Values are compared as stored.
            $missing = new stdClass();
            $current = get_option( $key, $missing );
            $new     = $value;
            if ( $current !== $missing && serialize( $current ) === serialize( $new ) ) {
                ++$unchanged;
                continue;
            }
            $changes[ $key ] = [ 'from' => $current === $missing ? null : $current, 'to' => $new ];
        }
        return [ 'changes' => $changes, 'unchanged' => $unchanged, 'skipped' => $skipped ];
    }

    /**
     * Writes the incoming options that preview() lists as changes.
     *
     * @return string[] Keys written.
     */
    public static function apply( array $incoming ): array {
        $written = [];
        $changes = self::preview( $incoming )['changes'];
        // Run the sanitizers as a full Social form save would: every field is
        // "present", so checkbox sanitizers use the value given and never
        // reset related options.
        $saved_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification -- restored below.
        $_POST      = [];
        foreach ( array_keys( PressHub_Settings_Registry::all() ) as $key ) {
            $_POST[ $key . '_present' ] = '1';
        }
        foreach ( $changes as $key => $change ) {
            $_POST[ $key ] = $change['to'];
        }
        try {
            foreach ( $changes as $key => $change ) {
                update_option( $key, self::sanitized( $key, $change['to'] ) );
                $written[] = $key;
            }
        } finally {
            $_POST = $saved_post;
        }
        if ( $written && function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'alloptions', 'options' );
        }
        if ( $written && class_exists( 'PressHub_AI_Audit_Logger' ) && method_exists( 'PressHub_AI_Audit_Logger', 'log' ) ) {
            try {
                PressHub_AI_Audit_Logger::log( 'settings_imported', 'settings', 'import', [ 'keys' => $written ] );
            } catch ( \Throwable $e ) {
                // Auditing never blocks an import.
            }
        }
        return $written;
    }

    /** The value an option would store: passed through its registered sanitizer. */
    private static function sanitized( string $key, $value ) {
        $cb = PressHub_Settings_Registry::sanitizer( $key );
        return $cb ? call_user_func( $cb, $value ) : $value;
    }

    /** Short, safe display of a stored value for the preview and removal tables. */
    public static function display_value( $value, bool $secret = false ): string {
        if ( null === $value ) {
            return '—';
        }
        if ( $secret ) {
            $s = is_scalar( $value ) ? (string) $value : '';
            if ( '' === $s ) {
                return __( 'not set', 'presshub-ai-editor' );
            }
            return class_exists( 'PressHub_AI_Provider_Store' ) ? PressHub_AI_Provider_Store::mask_key( $s ) : '••••' . substr( $s, -2 );
        }
        $s = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
        if ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > 80 ) {
            $s = mb_substr( $s, 0, 77 ) . '…';
        }
        return '' === $s ? '""' : $s;
    }

    // ------------------------------------------------------------------
    // admin-post handlers.
    // ------------------------------------------------------------------

    private static function guard(): void {
        $cap = function_exists( 'apply_filters' ) ? (string) apply_filters( 'presshub_ai_settings_cap', 'manage_options' ) : 'manage_options';
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You are not allowed to export or import PressHub settings.', 'presshub-ai-editor' ), 403 );
        }
        check_admin_referer( self::NONCE );
    }

    private static function transient_key(): string {
        return self::TRANSIENT . (int) get_current_user_id();
    }

    public static function settings_url( array $args = [] ): string {
        $url = admin_url( 'options-general.php?page=presshub-ai' );
        if ( $args && function_exists( 'add_query_arg' ) ) {
            $url = add_query_arg( $args, $url );
        }
        return $url . '#advanced';
    }

    public static function export_url(): string {
        $url = admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION );
        return function_exists( 'wp_nonce_url' ) ? wp_nonce_url( $url, self::NONCE ) : $url;
    }

    public static function handle_export(): void {
        self::guard();
        $json = wp_json_encode( self::export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $name = 'presshub-settings-' . gmdate( 'Y-m-d-His' ) . '.json';
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
        exit;
    }

    public static function handle_preview(): void {
        self::guard();
        $file = $_FILES['presshub_settings_file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
        $json = ( is_array( $file ) && ! empty( $file['tmp_name'] ) && is_uploaded_file( $file['tmp_name'] ) ) ? (string) file_get_contents( $file['tmp_name'] ) : '';
        $parsed = '' === $json ? [ 'error' => __( 'Choose an export file first.', 'presshub-ai-editor' ) ] : self::parse( $json );
        if ( isset( $parsed['error'] ) ) {
            set_transient( self::transient_key(), [ 'error' => $parsed['error'] ], 15 * MINUTE_IN_SECONDS );
        } else {
            set_transient( self::transient_key(), [ 'options' => $parsed['options'], 'name' => sanitize_file_name( (string) ( $file['name'] ?? '' ) ) ], 15 * MINUTE_IN_SECONDS );
        }
        wp_safe_redirect( self::settings_url( [ 'presshub_import' => 'preview' ] ) );
        exit;
    }

    public static function handle_apply(): void {
        self::guard();
        $pending = get_transient( self::transient_key() );
        delete_transient( self::transient_key() );
        $written = is_array( $pending ) && isset( $pending['options'] ) && is_array( $pending['options'] ) ? self::apply( $pending['options'] ) : null;
        wp_safe_redirect( self::settings_url( [ 'presshub_import' => null === $written ? 'expired' : 'done', 'presshub_import_count' => null === $written ? 0 : count( $written ) ] ) );
        exit;
    }

    /** The pending import of the current user, if any. */
    public static function pending(): ?array {
        $p = function_exists( 'get_transient' ) ? get_transient( self::transient_key() ) : false;
        return is_array( $p ) ? $p : null;
    }
}
