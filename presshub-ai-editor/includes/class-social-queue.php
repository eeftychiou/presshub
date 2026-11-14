<?php
/**
 * Social post queue manager for PressHub Social Auto-Post.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Queue {

    /**
     * Allowed social platforms.
     */
    public const VALID_PLATFORMS = [ 'twitter', 'facebook', 'instagram', 'tiktok' ];

    /**
     * Allowed dispatch engines.
     */
    public const VALID_ENGINES = [ 'api', 'automation' ];

    /**
     * Allowed job statuses.
     */
    public const VALID_STATUSES = [ 'pending', 'processing', 'published', 'failed', 'cancelled' ];

    /**
     * In-memory store fallback for isolated unit testing or when $wpdb is unavailable.
     *
     * @var array<int, array>
     */
    private static array $memory_store = [];

    /**
     * In-memory auto-increment ID tracker.
     *
     * @var int
     */
    private static int $auto_increment_id = 1;

    /**
     * Flag to force in-memory storage (e.g. during test runs).
     *
     * @var bool
     */
    public static bool $force_memory = false;

    /**
     * Determine whether the WordPress database is available.
     *
     * @return bool
     */
    public static function is_db_ready(): bool {
        global $wpdb;

        if ( self::$force_memory ) {
            return false;
        }

        return isset( $wpdb )
            && ! empty( $wpdb->prefix )
            && method_exists( $wpdb, 'get_var' )
            && method_exists( $wpdb, 'insert' )
            && method_exists( $wpdb, 'prepare' );
    }

    /**
     * Reset the in-memory store (for testing).
     *
     * @return void
     */
    public static function reset_memory_store(): void {
        self::$memory_store     = [];
        self::$auto_increment_id = 1;
    }

    /**
     * Enqueue a new social posting job.
     *
     * @param array $job_data Job payload.
     * @return int|WP_Error Job ID on success, WP_Error on validation error.
     */
    public static function enqueue( array $job_data ): int|WP_Error {
        $post_id   = isset( $job_data['post_id'] ) ? (int) $job_data['post_id'] : 0;
        $platform  = isset( $job_data['platform'] ) ? sanitize_key( (string) $job_data['platform'] ) : '';
        $engine    = isset( $job_data['engine'] ) ? sanitize_key( (string) $job_data['engine'] ) : 'api';
        $post_copy = isset( $job_data['post_copy'] ) ? trim( (string) $job_data['post_copy'] ) : '';

        if ( $post_id <= 0 ) {
            return new WP_Error( 'invalid_post_id', __( 'A valid positive post_id is required.', 'presshub-social-autopost' ) );
        }

        if ( ! in_array( $platform, self::VALID_PLATFORMS, true ) ) {
            return new WP_Error( 'invalid_platform', sprintf( __( 'Platform must be one of: %s', 'presshub-social-autopost' ), implode( ', ', self::VALID_PLATFORMS ) ) );
        }

        if ( ! in_array( $engine, self::VALID_ENGINES, true ) ) {
            return new WP_Error( 'invalid_engine', sprintf( __( 'Engine must be one of: %s', 'presshub-social-autopost' ), implode( ', ', self::VALID_ENGINES ) ) );
        }

        if ( '' === $post_copy ) {
            return new WP_Error( 'empty_post_copy', __( 'Post copy content cannot be empty.', 'presshub-social-autopost' ) );
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        $max_attempts = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_max_attempts' )
            ? PressHub_Social_Settings_Storage::get_max_attempts()
            : 3;

        $status = isset( $job_data['status'] ) && in_array( $job_data['status'], self::VALID_STATUSES, true )
            ? $job_data['status']
            : 'pending';

        $scheduled_at = ! empty( $job_data['scheduled_at'] ) ? (string) $job_data['scheduled_at'] : $now;
        $media_url    = ! empty( $job_data['media_url'] ) ? esc_url_raw( (string) $job_data['media_url'] ) : null;
        $media_type   = ! empty( $job_data['media_type'] ) ? sanitize_key( (string) $job_data['media_type'] ) : null;

        $metadata_raw = $job_data['metadata'] ?? null;
        if ( is_array( $metadata_raw ) ) {
            $metadata = function_exists( 'wp_json_encode' ) ? wp_json_encode( $metadata_raw ) : json_encode( $metadata_raw );
        } elseif ( is_string( $metadata_raw ) ) {
            $metadata = $metadata_raw;
        } else {
            $metadata = null;
        }

        $record = [
            'post_id'           => $post_id,
            'platform'          => $platform,
            'engine'            => $engine,
            'status'            => $status,
            'post_copy'         => $post_copy,
            'media_url'         => $media_url,
            'media_type'        => $media_type,
            'attempts'          => isset( $job_data['attempts'] ) ? (int) $job_data['attempts'] : 0,
            'max_attempts'      => $max_attempts,
            'scheduled_at'      => $scheduled_at,
            'dispatched_at'     => $job_data['dispatched_at'] ?? null,
            'external_post_id'  => $job_data['external_post_id'] ?? null,
            'external_post_url' => $job_data['external_post_url'] ?? null,
            'last_error'        => $job_data['last_error'] ?? null,
            'metadata'          => $metadata,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $inserted = $wpdb->insert( $table, $record );
            if ( false === $inserted && class_exists( 'PressHub_Social_Activator' ) ) {
                // Ensure the queue table exists and retry insert once.
                PressHub_Social_Activator::activate();
                $inserted = $wpdb->insert( $table, $record );
            }
            if ( false === $inserted ) {
                $db_err = $wpdb->last_error ?: __( 'Could not insert queue record into database.', 'presshub-social-autopost' );
                if ( class_exists( 'PressHub_Social_Logger' ) ) {
                    PressHub_Social_Logger::error(
                        'Database insert failed in PressHub_Social_Queue::enqueue: ' . $db_err,
                        [ 'table' => $table, 'post_id' => $post_id, 'platform' => $platform ]
                    );
                }
                return new WP_Error( 'db_insert_error', $db_err );
            }
            $job_id = (int) $wpdb->insert_id;
            return $job_id;
        }

        // In-memory fallback
        $job_id = self::$auto_increment_id++;
        $record['id'] = $job_id;
        self::$memory_store[ $job_id ] = $record;
        return $job_id;
    }

    /**
     * Retrieve a job by its ID.
     *
     * @param int $job_id
     * @return array|null
     */
    public static function get_job( int $job_id ): ?array {
        if ( $job_id <= 0 ) {
            return null;
        }

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $job_id ), ARRAY_A );
            return $row ? (array) $row : null;
        }

        return self::$memory_store[ $job_id ] ?? null;
    }

    /**
     * Retrieve all jobs for a specific post.
     *
     * @param int $post_id
     * @return array<int, array>
     */
    public static function get_jobs_for_post( int $post_id ): array {
        if ( $post_id <= 0 ) {
            return [];
        }

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC", $post_id ), ARRAY_A );
            return is_array( $rows ) ? $rows : [];
        }

        $results = [];
        foreach ( self::$memory_store as $job ) {
            if ( (int) $job['post_id'] === $post_id ) {
                $results[] = $job;
            }
        }
        usort( $results, fn( $a, $b ) => $b['id'] <=> $a['id'] );
        return $results;
    }

    /**
     * Retrieve due pending jobs ready for dispatch.
     *
     * @param int         $limit     Maximum number of jobs to fetch.
     * @param string|null $engine    Optional engine filter (e.g. 'automation' or 'api').
     * @param array       $platforms Optional list of platforms to filter by.
     * @return array<int, array>
     */
    public static function get_due_pending_jobs( int $limit = 0, ?string $engine = null, array $platforms = [], array $exclude_ids = [] ): array {
        $exclude_ids = array_values( array_filter( array_map( 'intval', $exclude_ids ), fn( $id ) => $id > 0 ) );
        if ( $limit <= 0 ) {
            $limit = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_batch_size' )
                ? PressHub_Social_Settings_Storage::get_batch_size()
                : 5;
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        $clean_platforms = [];
        foreach ( $platforms as $p ) {
            $pk = sanitize_key( (string) $p );
            if ( '' !== $pk ) {
                $clean_platforms[] = $pk;
            }
        }

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table  = PressHub_Social_Activator::get_table_name();
            $where  = "status = 'pending' AND scheduled_at <= %s";
            $params = [ $now ];

            if ( ! empty( $engine ) ) {
                $where    .= " AND engine = %s";
                $params[] = sanitize_key( $engine );
            }

            if ( ! empty( $clean_platforms ) ) {
                $placeholders = implode( ', ', array_fill( 0, count( $clean_platforms ), '%s' ) );
                $where       .= " AND platform IN ({$placeholders})";
                foreach ( $clean_platforms as $cp ) {
                    $params[] = $cp;
                }
            }

            if ( ! empty( $exclude_ids ) ) {
                $where .= ' AND id NOT IN (' . implode( ', ', array_fill( 0, count( $exclude_ids ), '%d' ) ) . ')';
                foreach ( $exclude_ids as $xid ) {
                    $params[] = $xid;
                }
            }

            $params[] = $limit;
            $sql      = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY scheduled_at ASC, id ASC LIMIT %d",
                ...$params
            );
            $rows     = $wpdb->get_results( $sql, ARRAY_A );
            return is_array( $rows ) ? $rows : [];
        }

        $due = [];
        foreach ( self::$memory_store as $job ) {
            if ( 'pending' !== $job['status'] || $job['scheduled_at'] > $now ) {
                continue;
            }
            if ( ! empty( $engine ) && ( $job['engine'] ?? 'api' ) !== $engine ) {
                continue;
            }
            if ( ! empty( $clean_platforms ) && ! in_array( $job['platform'] ?? '', $clean_platforms, true ) ) {
                continue;
            }
            if ( in_array( (int) $job['id'], $exclude_ids, true ) ) {
                continue;
            }
            $due[] = $job;
        }

        usort( $due, function( $a, $b ) {
            $cmp = strcmp( $a['scheduled_at'], $b['scheduled_at'] );
            return 0 !== $cmp ? $cmp : ( $a['id'] <=> $b['id'] );
        } );

        return array_slice( $due, 0, $limit );
    }

    /**
     * Query jobs with flexible filters.
     *
     * @param array $filters Supported: status, platform, post_id, limit, offset.
     * @return array<int, array>
     */
    public static function query_jobs( array $filters = [] ): array {
        $limit  = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 25;
        $offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $where = [ '1=1' ];
            $args  = [];

            if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::VALID_STATUSES, true ) ) {
                $where[] = 'status = %s';
                $args[]  = $filters['status'];
            }

            if ( ! empty( $filters['platform'] ) && in_array( $filters['platform'], self::VALID_PLATFORMS, true ) ) {
                $where[] = 'platform = %s';
                $args[]  = $filters['platform'];
            }

            if ( ! empty( $filters['post_id'] ) ) {
                $where[] = 'post_id = %d';
                $args[]  = (int) $filters['post_id'];
            }

            $where_sql = implode( ' AND ', $where );
            $args[]    = $limit;
            $args[]    = $offset;

            $sql  = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...$args );
            $rows = $wpdb->get_results( $sql, ARRAY_A );
            return is_array( $rows ) ? $rows : [];
        }

        $items = self::$memory_store;
        if ( ! empty( $filters['status'] ) ) {
            $items = array_filter( $items, fn( $j ) => $j['status'] === $filters['status'] );
        }
        if ( ! empty( $filters['platform'] ) ) {
            $items = array_filter( $items, fn( $j ) => $j['platform'] === $filters['platform'] );
        }
        if ( ! empty( $filters['post_id'] ) ) {
            $items = array_filter( $items, fn( $j ) => (int) $j['post_id'] === (int) $filters['post_id'] );
        }

        usort( $items, fn( $a, $b ) => $b['id'] <=> $a['id'] );
        return array_slice( array_values( $items ), $offset, $limit );
    }

    /**
     * Mark a job as processing. Increments attempts counter.
     *
     * @param int $job_id
     * @return bool
     */
    /**
     * Atomically move a pending job to processing (attempts + 1). Returns false when the
     * job is no longer pending, e.g. another worker claim took it first.
     */
    public static function claim_for_processing( int $job_id ): bool {
        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table  = PressHub_Social_Activator::get_table_name();
            $result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending'",
                $now,
                $job_id
            ) );
            return 1 === (int) $result;
        }

        if ( isset( self::$memory_store[ $job_id ] ) && 'pending' === ( self::$memory_store[ $job_id ]['status'] ?? '' ) ) {
            self::$memory_store[ $job_id ]['status']     = 'processing';
            self::$memory_store[ $job_id ]['attempts']   = (int) ( self::$memory_store[ $job_id ]['attempts'] ?? 0 ) + 1;
            self::$memory_store[ $job_id ]['updated_at'] = $now;
            return true;
        }

        return false;
    }

    public static function mark_processing( int $job_id ): bool {
        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d",
                $now,
                $job_id
            ) );
            return false !== $result;
        }

        if ( isset( self::$memory_store[ $job_id ] ) ) {
            self::$memory_store[ $job_id ]['status'] = 'processing';
            self::$memory_store[ $job_id ]['attempts'] = (int) ( self::$memory_store[ $job_id ]['attempts'] ?? 0 ) + 1;
            self::$memory_store[ $job_id ]['updated_at'] = $now;
            return true;
        }

        return false;
    }

    /**
     * Mark a job as published with external metadata.
     *
     * @param int $job_id
     * @param string $external_post_id
     * @param string $external_post_url
     * @param array $meta
     * @return bool
     */
    public static function mark_published( int $job_id, string $external_post_id = '', string $external_post_url = '', array $meta = [] ): bool {
        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        // Merge with metadata recorded before publishing (e.g. tiktok_narration) instead of discarding it.
        $existing = self::get_job( $job_id );
        $merged   = [];
        if ( $existing && ! empty( $existing['metadata'] ) ) {
            $decoded = is_array( $existing['metadata'] ) ? $existing['metadata'] : json_decode( (string) $existing['metadata'], true );
            if ( is_array( $decoded ) ) {
                $merged = $decoded;
            }
        }
        $merged   = array_merge( $merged, $meta );
        $metadata = function_exists( 'wp_json_encode' ) ? wp_json_encode( $merged ) : json_encode( $merged );

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $result = $wpdb->update(
                $table,
                [
                    'status'            => 'published',
                    'dispatched_at'     => $now,
                    'external_post_id'  => $external_post_id,
                    'external_post_url' => $external_post_url,
                    'last_error'        => null,
                    'metadata'          => $metadata,
                    'updated_at'        => $now,
                ],
                [ 'id' => $job_id ]
            );
            return false !== $result;
        }

        if ( isset( self::$memory_store[ $job_id ] ) ) {
            self::$memory_store[ $job_id ]['status']            = 'published';
            self::$memory_store[ $job_id ]['dispatched_at']     = $now;
            self::$memory_store[ $job_id ]['external_post_id']  = $external_post_id;
            self::$memory_store[ $job_id ]['external_post_url'] = $external_post_url;
            self::$memory_store[ $job_id ]['last_error']        = null;
            self::$memory_store[ $job_id ]['metadata']          = $metadata;
            self::$memory_store[ $job_id ]['updated_at']        = $now;
            return true;
        }

        return false;
    }

    /**
     * Mark a job as failed, scheduling a retry if attempts < max_attempts or marking permanently failed.
     *
     * @param int $job_id
     * @param string $error_message
     * @param array $meta
     * @return bool
     */
    public static function mark_failed( int $job_id, string $error_message, array $meta = [] ): bool {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return false;
        }

        $now          = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
        $attempts     = (int) ( $job['attempts'] ?? 1 );
        $max_attempts = (int) ( $job['max_attempts'] ?? 3 );

        $metadata_merged = [];
        if ( ! empty( $job['metadata'] ) ) {
            $existing_meta = json_decode( (string) $job['metadata'], true );
            if ( is_array( $existing_meta ) ) {
                $metadata_merged = $existing_meta;
            }
        }
        if ( ! empty( $meta ) ) {
            $metadata_merged = array_merge( $metadata_merged, $meta );
        }
        $metadata_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $metadata_merged ) : json_encode( $metadata_merged );

        if ( $attempts < $max_attempts ) {
            // Schedule exponential backoff retry: base_delay * (2 ^ (attempts - 1))
            $base_delay = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_retry_base_delay' )
                ? PressHub_Social_Settings_Storage::get_retry_base_delay()
                : 60;

            $delay_exponent = max( 0, $attempts - 1 );
            $backoff_delay  = $base_delay * ( 2 ** $delay_exponent );
            $next_run_time  = time() + $backoff_delay;
            $scheduled_at   = gmdate( 'Y-m-d H:i:s', $next_run_time );

            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'warning' ) ) {
                PressHub_Social_Logger::warning(
                    sprintf( 'Social job #%d failed attempt %d/%d. Retrying in %ds at %s.', $job_id, $attempts, $max_attempts, $backoff_delay, $scheduled_at ),
                    [
                        'job_id'       => $job_id,
                        'platform'     => $job['platform'] ?? '',
                        'error'        => $error_message,
                        'next_attempt' => $scheduled_at,
                    ]
                );
            }

            if ( self::is_db_ready() ) {
                global $wpdb;
                $table = PressHub_Social_Activator::get_table_name();
                $wpdb->update(
                    $table,
                    [
                        'status'       => 'pending',
                        'scheduled_at' => $scheduled_at,
                        'last_error'   => $error_message,
                        'metadata'     => $metadata_json,
                        'updated_at'   => $now,
                    ],
                    [ 'id' => $job_id ]
                );
                return true;
            }

            self::$memory_store[ $job_id ]['status']       = 'pending';
            self::$memory_store[ $job_id ]['scheduled_at'] = $scheduled_at;
            self::$memory_store[ $job_id ]['last_error']   = $error_message;
            self::$memory_store[ $job_id ]['metadata']     = $metadata_json;
            self::$memory_store[ $job_id ]['updated_at']   = $now;
            return true;
        }

        // Permanently failed
        if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
            PressHub_Social_Logger::error(
                sprintf( 'Social job #%d permanently failed after %d attempts: %s', $job_id, $attempts, $error_message ),
                [
                    'job_id'   => $job_id,
                    'post_id'  => $job['post_id'] ?? 0,
                    'platform' => $job['platform'] ?? '',
                    'error'    => $error_message,
                ]
            );
        }

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $wpdb->update(
                $table,
                [
                    'status'     => 'failed',
                    'last_error' => $error_message,
                    'metadata'   => $metadata_json,
                    'updated_at' => $now,
                ],
                [ 'id' => $job_id ]
            );
        } else {
            self::$memory_store[ $job_id ]['status']     = 'failed';
            self::$memory_store[ $job_id ]['last_error'] = $error_message;
            self::$memory_store[ $job_id ]['metadata']   = $metadata_json;
            self::$memory_store[ $job_id ]['updated_at'] = $now;
        }

        // Email alert if enabled
        self::maybe_send_failure_alert( $job, $error_message );

        return true;
    }

    /**
     * Send email notification on permanent job failure if configured.
     *
     * @param array $job
     * @param string $error_message
     * @return void
     */
    private static function maybe_send_failure_alert( array $job, string $error_message ): void {
        if ( ! class_exists( 'PressHub_Social_Settings_Storage' ) ) {
            return;
        }

        $email_enabled = method_exists( 'PressHub_Social_Settings_Storage', 'get_alert_email_enabled' )
            ? PressHub_Social_Settings_Storage::get_alert_email_enabled()
            : false;

        $email_address = method_exists( 'PressHub_Social_Settings_Storage', 'get_alert_email_address' )
            ? PressHub_Social_Settings_Storage::get_alert_email_address()
            : '';

        if ( ! $email_enabled || empty( $email_address ) || ! function_exists( 'wp_mail' ) ) {
            return;
        }

        $job_id   = $job['id'] ?? 0;
        $platform = ucfirst( (string) ( $job['platform'] ?? 'Unknown' ) );
        $post_id  = $job['post_id'] ?? 0;
        $attempts = $job['attempts'] ?? 0;

        $subject = sprintf( '[PressHub Social] Job #%d failed permanently (%s)', $job_id, $platform );
        $body    = sprintf(
            "PressHub Social Auto-Post Alert\n\nJob ID: %d\nPost ID: %d\nPlatform: %s\nAttempts: %d\nError: %s\nTime: %s\n",
            $job_id,
            $post_id,
            $platform,
            $attempts,
            $error_message,
            gmdate( 'Y-m-d H:i:s' )
        );

        wp_mail( $email_address, $subject, $body );
    }

    /**
     * Retry a job: resets attempts and schedules for immediate dispatch.
     *
     * @param int $job_id
     * @return bool
     */
    public static function retry_job( int $job_id ): bool {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return false;
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $result = $wpdb->update(
                $table,
                [
                    'status'            => 'pending',
                    'attempts'          => 0,
                    'scheduled_at'      => $now,
                    'last_error'        => null,
                    'dispatched_at'     => null,
                    'external_post_id'  => null,
                    'external_post_url' => null,
                    'updated_at'        => $now,
                ],
                [ 'id' => $job_id ]
            );
            return false !== $result;
        }

        if ( isset( self::$memory_store[ $job_id ] ) ) {
            self::$memory_store[ $job_id ]['status']            = 'pending';
            self::$memory_store[ $job_id ]['attempts']          = 0;
            self::$memory_store[ $job_id ]['scheduled_at']      = $now;
            self::$memory_store[ $job_id ]['last_error']        = null;
            self::$memory_store[ $job_id ]['dispatched_at']     = null;
            self::$memory_store[ $job_id ]['external_post_id']  = null;
            self::$memory_store[ $job_id ]['external_post_url'] = null;
            self::$memory_store[ $job_id ]['updated_at']        = $now;
            return true;
        }

        return false;
    }

    /**
     * Re-post an existing job as a brand new queue item with optional modified copy.
     * Leaves the original job record completely intact.
     *
     * @param int    $source_job_id Original job ID.
     * @param string $custom_copy   Optional modified copy; uses source job's copy if blank.
     * @return int|WP_Error New job ID on success or WP_Error on failure.
     */
    public static function repost_as_new_job( int $source_job_id, string $custom_copy = '' ): int|WP_Error {
        $source = self::get_job( $source_job_id );
        if ( ! $source ) {
            return new WP_Error( 'job_not_found', sprintf( __( 'Source job #%d not found.', 'presshub-social-autopost' ), $source_job_id ) );
        }

        $post_copy = trim( $custom_copy );
        if ( '' === $post_copy ) {
            $post_copy = (string) ( $source['post_copy'] ?? '' );
        }
        // A TikTok short still showing its placeholder is reposted with its generated
        // caption when it has one; otherwise the new job is prepared like the original.
        if ( class_exists( 'PressHub_Social_TikTok_Shorts' ) && PressHub_Social_TikTok_Shorts::is_short_job( $source )
            && PressHub_Social_TikTok_Shorts::is_placeholder_copy( $post_copy ) && '' !== PressHub_Social_TikTok_Shorts::generated_caption( $source ) ) {
            $post_copy = PressHub_Social_TikTok_Shorts::generated_caption( $source );
        }

        if ( '' === $post_copy ) {
            return new WP_Error( 'empty_copy', __( 'Post copy content cannot be empty.', 'presshub-social-autopost' ) );
        }

        if ( str_starts_with( ltrim( $post_copy ), '[AI Copy Generation Failed:' ) ) {
            $source_post_id  = (int) ( $source['post_id'] ?? 0 );
            $source_platform = sanitize_key( (string) ( $source['platform'] ?? '' ) );
            if ( $source_post_id > 0 && class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'generate_for_post' ) ) {
                $regenerated = PressHub_Social_AI_Copywriter::generate_for_post( $source_post_id, $source_platform );
                if ( is_wp_error( $regenerated ) ) {
                    return $regenerated;
                }
                $regenerated_str = trim( (string) $regenerated );
                if ( '' === $regenerated_str || str_starts_with( ltrim( $regenerated_str ), '[AI Copy Generation Failed:' ) ) {
                    return new WP_Error( 'failed_copy_placeholder', __( 'Cannot repost a job whose AI copy generation failed.', 'presshub-social-autopost' ) );
                }
                $post_copy = $regenerated_str;
            } else {
                return new WP_Error( 'failed_copy_placeholder', __( 'Cannot repost a job whose AI copy generation failed.', 'presshub-social-autopost' ) );
            }
        }

        if ( 'twitter' === ( $source['platform'] ?? '' ) && class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'sanitize_twitter_copy' ) ) {
            $post_url  = function_exists( 'get_permalink' ) ? ( get_permalink( (int) $source['post_id'] ) ?: '' ) : '';
            $post_copy = PressHub_Social_AI_Copywriter::sanitize_twitter_copy( $post_copy, $post_url );
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        $new_job_data = [
            'post_id'      => (int) $source['post_id'],
            'platform'     => (string) $source['platform'],
            'engine'       => (string) ( $source['engine'] ?? 'api' ),
            'post_copy'    => $post_copy,
            'media_url'    => $source['media_url'] ?? null,
            'media_type'   => $source['media_type'] ?? null,
            'status'       => 'pending',
            'scheduled_at' => $now,
            'metadata'     => ! empty( $source['metadata'] ) ? ( is_string( $source['metadata'] ) ? json_decode( $source['metadata'], true ) : $source['metadata'] ) : null,
        ];

        return self::enqueue( $new_job_data );
    }

    /**
     * Cancel a job in the queue.
     *
     * @param int $job_id
     * @return bool
     */
    /**
     * Close a TikTok short test render: the worker saved the video instead of posting it.
     * The job ends as cancelled (it was never meant to be posted), with the video's path
     * on the worker in its note and in metadata.test_render.
     */
    public static function mark_test_rendered( int $job_id, string $file ): bool {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return false;
        }
        $meta = is_array( $job['metadata'] ?? null ) ? $job['metadata'] : json_decode( (string) ( $job['metadata'] ?? '' ), true );
        $meta = is_array( $meta ) ? $meta : [];
        $now  = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
        $meta['test_render'] = [ 'file' => $file, 'rendered_at' => $now ];
        $note = sprintf( 'Test render only, not posted. Video saved on the worker: %s', '' !== $file ? $file : '(path not reported)' );
        $data = [
            'status'     => 'cancelled',
            'last_error' => $note,
            'metadata'   => function_exists( 'wp_json_encode' ) ? wp_json_encode( $meta ) : json_encode( $meta ),
            'updated_at' => $now,
        ];

        if ( self::is_db_ready() ) {
            global $wpdb;
            return false !== $wpdb->update( PressHub_Social_Activator::get_table_name(), $data, [ 'id' => $job_id ] );
        }
        if ( isset( self::$memory_store[ $job_id ] ) ) {
            self::$memory_store[ $job_id ] = array_merge( self::$memory_store[ $job_id ], $data );
            return true;
        }
        return false;
    }

    public static function cancel_job( int $job_id ): bool {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return false;
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $result = $wpdb->update(
                $table,
                [
                    'status'     => 'cancelled',
                    'updated_at' => $now,
                ],
                [ 'id' => $job_id ]
            );
            return false !== $result;
        }

        if ( isset( self::$memory_store[ $job_id ] ) ) {
            self::$memory_store[ $job_id ]['status']     = 'cancelled';
            self::$memory_store[ $job_id ]['updated_at'] = $now;
            return true;
        }

        return false;
    }

    /**
     * Update arbitrary fields of an existing job.
     *
     * @param int   $job_id
     * @param array $data
     * @return bool
     */
    public static function update_job( int $job_id, array $data ): bool {
        if ( empty( $data ) || $job_id <= 0 ) {
            return false;
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
        $data['updated_at'] = $now;

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table  = PressHub_Social_Activator::get_table_name();
            $result = $wpdb->update(
                $table,
                $data,
                [ 'id' => $job_id ]
            );
            return false !== $result;
        }

        if ( isset( self::$memory_store[ $job_id ] ) ) {
            foreach ( $data as $k => $v ) {
                self::$memory_store[ $job_id ][ $k ] = $v;
            }
            return true;
        }

        return false;
    }

    /**
     * Update the post copy of an existing queue job (e.g. after regenerating failed AI copy on retry).
     *
     * @param int    $job_id
     * @param string $post_copy
     * @return bool
     */
    public static function update_job_copy( int $job_id, string $post_copy ): bool {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return false;
        }

        $post_copy = trim( $post_copy );
        if ( '' === $post_copy ) {
            return false;
        }

        if ( 'twitter' === ( $job['platform'] ?? '' ) && class_exists( 'PressHub_Social_AI_Copywriter' ) && method_exists( 'PressHub_Social_AI_Copywriter', 'sanitize_twitter_copy' ) ) {
            $post_url  = function_exists( 'get_permalink' ) ? ( get_permalink( (int) ( $job['post_id'] ?? 0 ) ) ?: '' ) : '';
            $post_copy = PressHub_Social_AI_Copywriter::sanitize_twitter_copy( $post_copy, $post_url );
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table  = PressHub_Social_Activator::get_table_name();
            $result = $wpdb->update(
                $table,
                [
                    'post_copy'  => $post_copy,
                    'updated_at' => $now,
                ],
                [ 'id' => $job_id ]
            );
            return false !== $result;
        }

        if ( isset( self::$memory_store[ $job_id ] ) ) {
            self::$memory_store[ $job_id ]['post_copy']  = $post_copy;
            self::$memory_store[ $job_id ]['updated_at'] = $now;
            return true;
        }

        return false;
    }

    /**
     * Get aggregate statistics of jobs across statuses.
     *
     * @return array<string, int>
     */
    public static function get_stats(): array {
        $stats = [
            'total'      => 0,
            'pending'    => 0,
            'processing' => 0,
            'published'  => 0,
            'failed'     => 0,
            'cancelled'  => 0,
        ];

        if ( self::is_db_ready() ) {
            global $wpdb;
            $table = PressHub_Social_Activator::get_table_name();
            $rows  = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status", ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $status = (string) $row['status'];
                    $count  = (int) $row['cnt'];
                    if ( isset( $stats[ $status ] ) ) {
                        $stats[ $status ] = $count;
                    }
                    $stats['total'] += $count;
                }
            }
            return $stats;
        }

        foreach ( self::$memory_store as $job ) {
            $status = $job['status'] ?? 'pending';
            if ( isset( $stats[ $status ] ) ) {
                $stats[ $status ]++;
            }
            $stats['total']++;
        }

        return $stats;
    }
}
