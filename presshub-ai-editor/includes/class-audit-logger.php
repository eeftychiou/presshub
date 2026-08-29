<?php
/**
 * PressHub_AI_Audit_Logger — Structured Settings & Provider Audit Log.
 *
 * Records every mutation to PressHub AI configuration (options, providers,
 * news sources) with actor, timestamp, event type, entity summary, and masked changes.
 * Ensures API keys and credentials are never stored in plaintext in the audit trail.
 *
 * @package PressHub_AI_Editor
 * @since 1.9.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Audit_Logger {

    /** DB table version for schema migration checks. */
    const DB_VERSION = '1.0.0';
    const DB_VERSION_OPTION = 'presshub_ai_audit_logs_db_version';

    /**
     * Get table name with WordPress prefix.
     *
     * @return string Table name.
     */
    public static function get_table_name(): string {
        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
        return $prefix . 'presshub_ai_audit_logs';
    }

    /**
     * Create or update the audit logs database table using dbDelta.
     *
     * @return void
     */
    public static function create_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = ( isset( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) ? $wpdb->get_charset_collate() : '';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_login varchar(60) NOT NULL DEFAULT '',
            event_type varchar(64) NOT NULL,
            entity_type varchar(64) NOT NULL,
            entity_id varchar(128) NOT NULL DEFAULT '',
            details longtext NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event_type (event_type),
            KEY entity_type (entity_type),
            KEY user_id (user_id)
        ) {$charset_collate};";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if ( function_exists( 'dbDelta' ) ) {
            dbDelta( $sql );
        } elseif ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
            $wpdb->query( $sql );
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Recursively mask sensitive fields and credentials in details array.
     * Ensures API keys, tokens, and passwords never appear in plaintext in the log.
     *
     * @param mixed $data Input array or scalar.
     * @return mixed Sanitized data with masked secrets.
     */
    public static function mask_details( $data ) {
        if ( is_array( $data ) ) {
            $masked = [];
            foreach ( $data as $key => $val ) {
                $k_str = (string) $key;
                if ( is_array( $val ) ) {
                    $masked[ $key ] = self::mask_details( $val );
                } elseif ( is_string( $val ) ) {
                    // Check if key implies sensitive credential
                    if ( preg_match( '/(key|secret|token|password|pass|auth|pwd|authorization)/i', $k_str ) ) {
                        $masked[ $key ] = self::mask_secret_value( $val );
                    } else {
                        // Check if the value itself looks like a secret API key or Bearer token
                        $masked[ $key ] = self::mask_if_secret_value( $val );
                    }
                } else {
                    $masked[ $key ] = $val;
                }
            }
            return $masked;
        } elseif ( is_string( $data ) ) {
            return self::mask_if_secret_value( $data );
        }

        return $data;
    }

    /**
     * Mask an explicit secret value.
     *
     * @param string $val Raw secret string.
     * @return string Masked string.
     */
    public static function mask_secret_value( string $val ): string {
        $val = trim( $val );
        if ( '' === $val ) {
            return '';
        }
        if ( false !== strpos( $val, '••••' ) ) {
            return $val;
        }

        if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
            $masked = PressHub_AI_Provider_Store::mask_key( $val );
            if ( '' !== $masked && 'No API Key set' !== $masked ) {
                return $masked;
            }
        }

        $len = strlen( $val );
        if ( $len >= 10 ) {
            return substr( $val, 0, 4 ) . '••••••••' . substr( $val, -4 );
        } elseif ( $len >= 6 ) {
            return substr( $val, 0, 2 ) . '••••' . substr( $val, -2 );
        }
        return '••••••••';
    }

    /**
     * Mask value only if it matches known API key formats or Bearer tokens.
     *
     * @param string $val Input string.
     * @return string Original or masked string.
     */
    public static function mask_if_secret_value( string $val ): string {
        $trimmed = trim( $val );
        if ( '' === $trimmed ) {
            return $val;
        }

        $known_prefixes = [
            'sk-proj-',
            'sk-admin-',
            'sk-ant-api03-',
            'sk-ant-',
            'github_pat_',
            'ghp_',
            'gsk_',
            'AIzaSy',
            'AIza',
            'nvapi-',
            'xai-',
            'ms-',
            'sk-',
        ];

        foreach ( $known_prefixes as $pfx ) {
            if ( 0 === strpos( $trimmed, $pfx ) && strlen( $trimmed ) > strlen( $pfx ) + 4 ) {
                return self::mask_secret_value( $trimmed );
            }
        }

        if ( 0 === stripos( $trimmed, 'Bearer ' ) && strlen( $trimmed ) > 12 ) {
            return 'Bearer ' . self::mask_secret_value( substr( $trimmed, 7 ) );
        }

        return $val;
    }

    /**
     * Log a configuration audit event.
     *
     * @param string $event_type  Event slug (e.g. 'provider_added', 'provider_updated', 'provider_deleted', 'provider_toggled', 'settings_saved', 'news_source_added', 'news_source_updated', 'news_source_deleted', 'news_source_toggled').
     * @param string $entity_type Entity category (e.g. 'provider', 'settings', 'news_source', 'preset').
     * @param string $entity_id   Unique identifier of the entity (e.g. provider ID, option name, source ID).
     * @param array  $details     Contextual changes and metadata (automatically masked).
     * @return int|null Inserted log ID or null on failure.
     */
    public static function log(
        string $event_type,
        string $entity_type,
        string $entity_id,
        array $details = []
    ): ?int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return null;
        }

        $user_id = 0;
        if ( function_exists( 'get_current_user_id' ) ) {
            $user_id = (int) get_current_user_id();
        }

        $user_login = '';
        if ( function_exists( 'wp_get_current_user' ) ) {
            $current_user = wp_get_current_user();
            if ( $current_user && ! empty( $current_user->user_login ) ) {
                $user_login = (string) $current_user->user_login;
            }
        }
        if ( '' === $user_login ) {
            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                $user_login = 'wp_cli';
            } elseif ( 0 === $user_id ) {
                $user_login = 'system';
            } else {
                $user_login = 'user_' . $user_id;
            }
        }

        $ip_address = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip_address = trim( $ips[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip_address = trim( (string) $_SERVER['REMOTE_ADDR'] );
        }
        if ( ! filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            $ip_address = '127.0.0.1';
        }

        $masked_details = self::mask_details( $details );

        $table_name = self::get_table_name();
        $row = [
            'created_at'  => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
            'user_id'     => max( 0, $user_id ),
            'user_login'  => function_exists( 'sanitize_user' ) ? ( sanitize_user( $user_login, true ) ?: sanitize_text_field( $user_login ) ) : sanitize_text_field( $user_login ),
            'event_type'  => sanitize_key( $event_type ) ?: sanitize_text_field( $event_type ),
            'entity_type' => sanitize_key( $entity_type ) ?: sanitize_text_field( $entity_type ),
            'entity_id'   => sanitize_text_field( $entity_id ),
            'details'     => ! empty( $masked_details ) ? wp_json_encode( $masked_details ) : null,
            'ip_address'  => sanitize_text_field( $ip_address ),
        ];

        $format = [
            '%s', // created_at
            '%d', // user_id
            '%s', // user_login
            '%s', // event_type
            '%s', // entity_type
            '%s', // entity_id
            '%s', // details
            '%s', // ip_address
        ];

        $result = $wpdb->insert( $table_name, $row, $format );
        if ( false === $result || empty( $wpdb->insert_id ) ) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Query audit logs with pagination and filters.
     *
     * Supports both array arguments or positional parameters:
     * `get_logs( array $args = [] )` or `get_logs( int $limit = 50, int $offset = 0, string $event_type = '' )`
     *
     * @param array|int $args Query argument array or integer limit.
     * @param int       $offset Offset when using positional arguments.
     * @param string    $event_type Event type filter when using positional arguments.
     * @return array Array with keys: items (array), total (int), pages (int), page (int), per_page (int).
     */
    public static function get_logs( $args = [], int $offset = 0, string $event_type = '' ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return [
                'items'    => [],
                'total'    => 0,
                'pages'    => 0,
                'page'     => 1,
                'per_page' => 50,
            ];
        }

        if ( is_numeric( $args ) ) {
            $limit       = max( 1, (int) $args );
            $page        = (int) floor( $offset / $limit ) + 1;
            $per_page    = $limit;
            $entity_type = '';
            $search      = '';
            $start_date  = '';
            $end_date    = '';
            $orderby     = 'id';
            $order       = 'DESC';
        } else {
            $args_arr    = is_array( $args ) ? $args : [];
            $page        = max( 1, (int) ( $args_arr['page'] ?? 1 ) );
            $per_page    = max( 1, (int) ( $args_arr['per_page'] ?? ( $args_arr['limit'] ?? 50 ) ) );
            $offset      = isset( $args_arr['offset'] ) ? (int) $args_arr['offset'] : ( $page - 1 ) * $per_page;
            $event_type  = sanitize_text_field( $args_arr['event_type'] ?? '' );
            $entity_type = sanitize_text_field( $args_arr['entity_type'] ?? '' );
            $search      = sanitize_text_field( $args_arr['search'] ?? '' );
            $start_date  = sanitize_text_field( $args_arr['start_date'] ?? '' );
            $end_date    = sanitize_text_field( $args_arr['end_date'] ?? '' );
            $orderby_raw = sanitize_key( $args_arr['orderby'] ?? 'id' );
            $orderby     = in_array( $orderby_raw, [ 'id', 'created_at', 'event_type', 'entity_type', 'user_id' ], true ) ? $orderby_raw : 'id';
            $order       = ( strtoupper( (string) ( $args_arr['order'] ?? 'DESC' ) ) === 'ASC' ) ? 'ASC' : 'DESC';
        }

        $table_name    = self::get_table_name();
        $where_clauses = [ '1=1' ];
        $params        = [];

        if ( '' !== $event_type ) {
            $where_clauses[] = 'event_type = %s';
            $params[]        = $event_type;
        }
        if ( '' !== $entity_type ) {
            $where_clauses[] = 'entity_type = %s';
            $params[]        = $entity_type;
        }
        if ( '' !== $start_date ) {
            $where_clauses[] = 'created_at >= %s';
            $params[]        = ( false === strpos( $start_date, ' ' ) ) ? $start_date . ' 00:00:00' : $start_date;
        }
        if ( '' !== $end_date ) {
            $where_clauses[] = 'created_at <= %s';
            $params[]        = ( false === strpos( $end_date, ' ' ) ) ? $end_date . ' 23:59:59' : $end_date;
        }
        if ( '' !== $search ) {
            $esc_search = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $search ) : addcslashes( $search, '_%\\' );
            $like = '%' . $esc_search . '%';
            $where_clauses[] = '(event_type LIKE %s OR entity_type LIKE %s OR entity_id LIKE %s OR user_login LIKE %s OR details LIKE %s OR ip_address LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where_clauses );

        $count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $count_sql = $wpdb->prepare( $count_sql, ...$params );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        $query_sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $query_params = array_merge( $params, [ $per_page, $offset ] );
        $query_sql = $wpdb->prepare( $query_sql, ...$query_params );
        $items = $wpdb->get_results( $query_sql, ARRAY_A );
        if ( ! is_array( $items ) ) {
            $items = [];
        }

        foreach ( $items as &$item ) {
            $item['id']      = (int) ( $item['id'] ?? 0 );
            $item['user_id'] = (int) ( $item['user_id'] ?? 0 );
        }
        unset( $item );

        return [
            'items'    => $items,
            'total'    => $total,
            'pages'    => (int) ceil( $total / max( 1, $per_page ) ),
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Count matching audit logs.
     *
     * @param string $event_type  Optional event type filter.
     * @param string $entity_type Optional entity type filter.
     * @return int Total matching records.
     */
    public static function count_logs( string $event_type = '', string $entity_type = '' ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }

        $table_name    = self::get_table_name();
        $where_clauses = [ '1=1' ];
        $params        = [];

        if ( '' !== $event_type ) {
            $where_clauses[] = 'event_type = %s';
            $params[]        = $event_type;
        }
        if ( '' !== $entity_type ) {
            $where_clauses[] = 'entity_type = %s';
            $params[]        = $entity_type;
        }

        $where_sql = implode( ' AND ', $where_clauses );
        $sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, ...$params );
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Export audit logs as RFC 4180 CSV formatted string.
     *
     * @param array $args Query filter arguments.
     * @return string CSV text.
     */
    public static function export_csv( array $args = [] ): string {
        $args['page']     = 1;
        $args['per_page'] = 50000;
        $result = self::get_logs( $args );
        $rows   = $result['items'] ?? [];

        $fp = fopen( 'php://temp', 'r+' );
        fputcsv( $fp, [
            'id',
            'created_at',
            'user_id',
            'user_login',
            'event_type',
            'entity_type',
            'entity_id',
            'ip_address',
            'details',
        ] );

        foreach ( $rows as $row ) {
            fputcsv( $fp, [
                $row['id'] ?? '',
                $row['created_at'] ?? '',
                $row['user_id'] ?? 0,
                $row['user_login'] ?? '',
                $row['event_type'] ?? '',
                $row['entity_type'] ?? '',
                $row['entity_id'] ?? '',
                $row['ip_address'] ?? '',
                $row['details'] ?? '',
            ] );
        }

        rewind( $fp );
        $csv = stream_get_contents( $fp );
        fclose( $fp );

        return (string) $csv;
    }

    /**
     * Prune logs older than $days (default 90).
     *
     * @param int $days Number of retention days.
     * @return int Number of deleted log rows.
     */
    public static function prune_logs( int $days = 90 ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }

        $table_name = self::get_table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ) ) );
        $sql = $wpdb->prepare( "DELETE FROM {$table_name} WHERE created_at < %s", $cutoff );
        $deleted = $wpdb->query( $sql );

        return is_numeric( $deleted ) ? (int) $deleted : 0;
    }

    /**
     * Alias for prune_logs().
     *
     * @param int $days Retention days.
     * @return int Deleted count.
     */
    public static function prune_old_logs( int $days = 90 ): int {
        return self::prune_logs( $days );
    }

    /**
     * Clear / truncate all rows from the audit logs table.
     *
     * @return bool True on success, false on failure.
     */
    public static function clear_logs(): bool {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return false;
        }

        $table_name = self::get_table_name();
        $result = $wpdb->query( "DELETE FROM {$table_name}" );

        return false !== $result;
    }

    /**
     * Alias for clear_logs().
     *
     * @return bool True on success, false on failure.
     */
    public static function clear_all_logs(): bool {
        return self::clear_logs();
    }
}
