<?php
/**
 * PressHub_AI_Token_Logger — Unified Token & Activity Logging System.
 *
 * Tracks LLM completions (prompt, completion, total tokens), TTS synthesis (characters),
 * and News Scraping (sources, articles) with execution latency, model, provider, status,
 * user attribution, and JSON metadata in a dedicated WordPress DB table.
 *
 * @package PressHub_AI_Editor
 * @since 1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Token_Logger {

    /** DB table version for schema migration checks. */
    const DB_VERSION = '1.0.0';
    const DB_VERSION_OPTION = 'presshub_ai_token_logs_db_version';

    /**
     * Reserved action identifier used exclusively by automated test runners
     * (e.g. {@see dev-env/scripts/run-integration-tests.php}) and unit tests.
     * Rows with this action_trigger are synthetic placeholders used to verify
     * the database insertion path; they are excluded by default from
     * user-facing queries (admin logs panel, summary stats, CSV exports) and
     * should be deleted from the table once the test that inserted them
     * completes.
     */
    const INTEGRATION_TEST_ACTION = 'integration_test';

    /**
     * Default list of action_trigger values that the user-facing log query
     * APIs (get_logs(), get_summary_stats(), export_csv()) must filter out.
     * Filterable via {@see 'presshub_ai_token_logs_excluded_actions'} so that
     * site maintainers can extend or shorten the exclusion list as needed.
     *
     * @return string[]
     */
    public static function get_excluded_actions(): array {
        $default = [ self::INTEGRATION_TEST_ACTION ];
        $filtered = apply_filters( 'presshub_ai_token_logs_excluded_actions', $default );
        if ( ! is_array( $filtered ) ) {
            return $default;
        }
        $clean = [];
        foreach ( $filtered as $action ) {
            $action = (string) $action;
            if ( '' === $action ) {
                continue;
            }
            $clean[] = $action;
        }
        return $clean;
    }

    /**
     * Get table name with WordPress prefix.
     *
     * @return string Table name.
     */
    public static function get_table_name(): string {
        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
        return $prefix . 'presshub_ai_token_logs';
    }

    /**
     * Create or update the token logs database table using dbDelta.
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
            action_trigger varchar(64) NOT NULL,
            provider varchar(32) NOT NULL,
            model varchar(64) NOT NULL,
            prompt_tokens int(10) unsigned NOT NULL DEFAULT 0,
            completion_tokens int(10) unsigned NOT NULL DEFAULT 0,
            total_tokens int(10) unsigned NOT NULL DEFAULT 0,
            metric_units int(10) unsigned NOT NULL DEFAULT 0,
            duration_ms int(10) unsigned NOT NULL DEFAULT 0,
            status varchar(16) NOT NULL DEFAULT 'success',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            error_message text NULL,
            metadata longtext NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY action_trigger (action_trigger),
            KEY provider (provider),
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
     * Log an LLM completion request.
     *
     * @param string      $action            Action identifier (e.g. 'coauthor_draft', 'copilot_chat', 'briefing_curation').
     * @param string      $provider          Provider name (e.g. 'openai', 'anthropic', 'gemini', 'groq').
     * @param string      $model             Model identifier (e.g. 'gpt-4o', 'claude-3-5-sonnet-20241022').
     * @param int         $prompt_tokens     Input/prompt token count.
     * @param int         $completion_tokens Output/candidate token count.
     * @param int         $duration_ms       Execution time in milliseconds.
     * @param string      $status            'success' or 'error'.
     * @param string|null $error             Optional error message if status is error.
     * @param array       $metadata          Optional extra details (e.g. prompt length, settings, params).
     * @param int         $user_id           WordPress user ID (0 defaults to current user).
     * @return int|null Inserted log ID, or null on failure.
     */
    public static function log_llm_request(
        string $action,
        string $provider,
        string $model,
        int $prompt_tokens,
        int $completion_tokens,
        int $duration_ms,
        string $status = 'success',
        ?string $error = null,
        array $metadata = [],
        int $user_id = 0
    ): ?int {
        if ( 0 === $user_id && function_exists( 'get_current_user_id' ) ) {
            $user_id = (int) get_current_user_id();
        }

        $total_tokens = max( 0, $prompt_tokens + $completion_tokens );

        return self::insert_row( [
            'action_trigger'    => sanitize_key( $action ) ?: sanitize_text_field( $action ),
            'provider'          => sanitize_text_field( $provider ),
            'model'             => sanitize_text_field( $model ),
            'prompt_tokens'     => max( 0, $prompt_tokens ),
            'completion_tokens' => max( 0, $completion_tokens ),
            'total_tokens'      => $total_tokens,
            'metric_units'      => 0,
            'duration_ms'       => max( 0, $duration_ms ),
            'status'            => 'error' === strtolower( $status ) ? 'error' : 'success',
            'user_id'           => max( 0, $user_id ),
            'error_message'     => $error ? sanitize_textarea_field( $error ) : null,
            'metadata'          => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
        ] );
    }

    /**
     * Log a Text-to-Speech (TTS) audio synthesis request.
     *
     * @param string      $action      Action identifier (e.g. 'podcast_audio', 'briefing_audio').
     * @param string      $engine      TTS Engine (e.g. 'google_cloud', 'gemini').
     * @param string      $voice       Voice model identifier.
     * @param int         $char_count  Number of characters synthesized.
     * @param int         $duration_ms Execution time in milliseconds.
     * @param string      $status      'success' or 'error'.
     * @param string|null $error       Optional error message.
     * @param array       $metadata    Optional extra metadata.
     * @param int         $user_id     WordPress user ID.
     * @return int|null Inserted log ID, or null on failure.
     */
    public static function log_tts_request(
        string $action,
        string $engine,
        string $voice,
        int $char_count,
        int $duration_ms,
        string $status = 'success',
        ?string $error = null,
        array $metadata = [],
        int $user_id = 0
    ): ?int {
        if ( 0 === $user_id && function_exists( 'get_current_user_id' ) ) {
            $user_id = (int) get_current_user_id();
        }

        return self::insert_row( [
            'action_trigger'    => sanitize_key( $action ) ?: sanitize_text_field( $action ),
            'provider'          => sanitize_text_field( $engine ),
            'model'             => sanitize_text_field( $voice ),
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'total_tokens'      => 0,
            'metric_units'      => max( 0, $char_count ),
            'duration_ms'       => max( 0, $duration_ms ),
            'status'            => 'error' === strtolower( $status ) ? 'error' : 'success',
            'user_id'           => max( 0, $user_id ),
            'error_message'     => $error ? sanitize_textarea_field( $error ) : null,
            'metadata'          => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
        ] );
    }

    /**
     * Log a News Harvester / Web Scraping request.
     *
     * @param string      $action          Action identifier (e.g. 'scrape_harvest').
     * @param int         $sources_count   Count of news source homepages crawled.
     * @param int         $articles_count  Count of articles successfully parsed and stored.
     * @param int         $duration_ms     Execution duration in milliseconds.
     * @param string      $status          'success' or 'error'.
     * @param string|null $error           Optional error message.
     * @param array       $metadata        Optional extra metadata.
     * @param int         $user_id         WordPress user ID.
     * @return int|null Inserted log ID, or null on failure.
     */
    public static function log_scrape_request(
        string $action,
        int $sources_count,
        int $articles_count,
        int $duration_ms,
        string $status = 'success',
        ?string $error = null,
        array $metadata = [],
        int $user_id = 0
    ): ?int {
        if ( 0 === $user_id && function_exists( 'get_current_user_id' ) ) {
            $user_id = (int) get_current_user_id();
        }

        $meta = array_merge( [ 'sources_count' => max( 0, $sources_count ) ], $metadata );

        return self::insert_row( [
            'action_trigger'    => sanitize_key( $action ) ?: sanitize_text_field( $action ),
            'provider'          => 'scraper',
            'model'             => 'wp_remote_get',
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'total_tokens'      => 0,
            'metric_units'      => max( 0, $articles_count ),
            'duration_ms'       => max( 0, $duration_ms ),
            'status'            => 'error' === strtolower( $status ) ? 'error' : 'success',
            'user_id'           => max( 0, $user_id ),
            'error_message'     => $error ? sanitize_textarea_field( $error ) : null,
            'metadata'          => wp_json_encode( $meta ),
        ] );
    }

    /**
     * Internal row insertion helper.
     *
     * @param array $data Row data to insert.
     * @return int|null Inserted ID or null.
     */
    private static function insert_row( array $data ): ?int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return null;
        }

        $table_name = self::get_table_name();

        $row = [
            'created_at'        => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
            'action_trigger'    => (string) ( $data['action_trigger'] ?? '' ),
            'provider'          => (string) ( $data['provider'] ?? '' ),
            'model'             => (string) ( $data['model'] ?? '' ),
            'prompt_tokens'     => (int) ( $data['prompt_tokens'] ?? 0 ),
            'completion_tokens' => (int) ( $data['completion_tokens'] ?? 0 ),
            'total_tokens'      => (int) ( $data['total_tokens'] ?? 0 ),
            'metric_units'      => (int) ( $data['metric_units'] ?? 0 ),
            'duration_ms'       => (int) ( $data['duration_ms'] ?? 0 ),
            'status'            => 'error' === strtolower( (string) ( $data['status'] ?? 'success' ) ) ? 'error' : 'success',
            'user_id'           => (int) ( $data['user_id'] ?? 0 ),
            'error_message'     => $data['error_message'] ?? null,
            'metadata'          => $data['metadata'] ?? null,
        ];

        $format = [
            '%s', // created_at
            '%s', // action_trigger
            '%s', // provider
            '%s', // model
            '%d', // prompt_tokens
            '%d', // completion_tokens
            '%d', // total_tokens
            '%d', // metric_units
            '%d', // duration_ms
            '%s', // status
            '%d', // user_id
            '%s', // error_message
            '%s', // metadata
        ];

        $result = $wpdb->insert( $table_name, $row, $format );
        if ( false === $result || empty( $wpdb->insert_id ) ) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Query token logs with pagination and filters.
     *
     * @param array $args Query arguments:
     *                    - page (int): 1-indexed page number (default 1).
     *                    - per_page (int): Items per page (default 20).
     *                    - action (string): Filter by action_trigger.
     *                    - provider (string): Filter by provider.
     *                    - status (string): Filter by status ('success' or 'error').
     *                    - start_date (string): Filter created_at >= start_date.
     *                    - end_date (string): Filter created_at <= end_date.
     *                    - search (string): Search term in model, action, provider, error, metadata.
     *                    - orderby (string): Column to sort by (id, created_at, duration_ms, total_tokens, metric_units).
     *                    - order (string): ASC or DESC.
     * @return array Array with keys: items (array), total (int), pages (int), page (int), per_page (int).
     */
    public static function get_logs( array $args = [] ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return [
                'items'    => [],
                'total'    => 0,
                'pages'    => 0,
                'page'     => 1,
                'per_page' => 20,
            ];
        }

        $table_name  = self::get_table_name();
        $page        = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page    = max( 1, (int) ( $args['per_page'] ?? 20 ) );
        $offset      = ( $page - 1 ) * $per_page;
        $action      = sanitize_text_field( $args['action'] ?? ( $args['action_trigger'] ?? '' ) );
        $provider    = sanitize_text_field( $args['provider'] ?? '' );
        $status      = sanitize_text_field( $args['status'] ?? '' );
        $start_date  = sanitize_text_field( $args['start_date'] ?? '' );
        $end_date    = sanitize_text_field( $args['end_date'] ?? '' );
        $search      = sanitize_text_field( $args['search'] ?? '' );
        $orderby_raw = sanitize_key( $args['orderby'] ?? 'id' );
        $orderby     = in_array( $orderby_raw, [ 'id', 'created_at', 'duration_ms', 'total_tokens', 'metric_units' ], true ) ? $orderby_raw : 'id';
        $order       = ( strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ) ? 'ASC' : 'DESC';

        $where_clauses = [ '1=1' ];
        $params = [];

        // Synthetic test rows (action_trigger = integration_test) and any
        // other caller-defined excluded actions must NEVER surface in
        // editorial / admin log views. We always exclude them server-side,
        // even when the caller doesn't pass an `action_exclude` arg, to keep
        // production logs free of test pollution from past runs.
        $excluded_actions = self::get_excluded_actions();
        if ( ! empty( $excluded_actions ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $excluded_actions ), '%s' ) );
            $where_clauses[] = 'action_trigger NOT IN (' . $placeholders . ')';
            foreach ( $excluded_actions as $ex_action ) {
                $params[] = $ex_action;
            }
        }

        if ( '' !== $action ) {
            $where_clauses[] = 'action_trigger = %s';
            $params[] = $action;
        }
        if ( '' !== $provider ) {
            $where_clauses[] = 'provider = %s';
            $params[] = $provider;
        }
        if ( '' !== $status ) {
            $where_clauses[] = 'status = %s';
            $params[] = $status;
        }
        if ( '' !== $start_date ) {
            $where_clauses[] = 'created_at >= %s';
            $params[] = ( false === strpos( $start_date, ' ' ) ) ? $start_date . ' 00:00:00' : $start_date;
        }
        if ( '' !== $end_date ) {
            $where_clauses[] = 'created_at <= %s';
            $params[] = ( false === strpos( $end_date, ' ' ) ) ? $end_date . ' 23:59:59' : $end_date;
        }
        if ( '' !== $search ) {
            $esc_search = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $search ) : addcslashes( $search, '_%\\' );
            $like = '%' . $esc_search . '%';
            $where_clauses[] = '(model LIKE %s OR action_trigger LIKE %s OR provider LIKE %s OR error_message LIKE %s OR metadata LIKE %s)';
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

        // Cast integer fields for clean consumption
        foreach ( $items as &$item ) {
            $item['id']                = (int) ( $item['id'] ?? 0 );
            $item['prompt_tokens']     = (int) ( $item['prompt_tokens'] ?? 0 );
            $item['completion_tokens'] = (int) ( $item['completion_tokens'] ?? 0 );
            $item['total_tokens']      = (int) ( $item['total_tokens'] ?? 0 );
            $item['metric_units']      = (int) ( $item['metric_units'] ?? 0 );
            $item['duration_ms']       = (int) ( $item['duration_ms'] ?? 0 );
            $item['user_id']           = (int) ( $item['user_id'] ?? 0 );
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
     * Compute aggregated summary statistics for token & activity usage.
     *
     * @param string $range Date range: 'today', '7d', '30d', '90d', or 'all'.
     * @return array Aggregated stats array.
     */
    public static function get_summary_stats( string $range = '30d' ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return [
                'total_tokens'         => 0,
                'total_requests'       => 0,
                'prompt_tokens'        => 0,
                'completion_tokens'    => 0,
                'tts_chars'            => 0,
                'scraped_articles'     => 0,
                'success_rate'         => 100.0,
                'tokens_by_model'      => [],
                'tokens_by_action'     => [],
                'requests_by_provider' => [],
            ];
        }

        $table_name = self::get_table_name();
        $where_parts = [ '1=1' ];
        $params     = [];

        if ( 'all' !== $range ) {
            if ( 'today' === $range ) {
                $cutoff = gmdate( 'Y-m-d 00:00:00' );
            } elseif ( '7d' === $range ) {
                $cutoff = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
            } elseif ( '90d' === $range ) {
                $cutoff = gmdate( 'Y-m-d 00:00:00', strtotime( '-90 days' ) );
            } else {
                $cutoff = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) );
            }
            $where_parts[] = 'created_at >= %s';
            $params[]      = $cutoff;
        }

        // Mirror the get_logs() exclusion so summary statistics are never
        // polluted by integration_test or any other synthetic action_trigger.
        $excluded_actions = self::get_excluded_actions();
        if ( ! empty( $excluded_actions ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $excluded_actions ), '%s' ) );
            $where_parts[] = 'action_trigger NOT IN (' . $placeholders . ')';
            foreach ( $excluded_actions as $ex_action ) {
                $params[] = $ex_action;
            }
        }

        $where_sql = implode( ' AND ', $where_parts );

        $query = "SELECT * FROM {$table_name} WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $query = $wpdb->prepare( $query, ...$params );
        }

        $rows = $wpdb->get_results( $query, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            $rows = [];
        }

        $total_requests       = count( $rows );
        $total_tokens         = 0;
        $prompt_tokens        = 0;
        $completion_tokens    = 0;
        $tts_chars            = 0;
        $scraped_articles     = 0;
        $success_count        = 0;
        $tokens_by_model      = [];
        $tokens_by_action     = [];
        $requests_by_provider = [];

        foreach ( $rows as $row ) {
            $p_tokens = (int) ( $row['prompt_tokens'] ?? 0 );
            $c_tokens = (int) ( $row['completion_tokens'] ?? 0 );
            $t_tokens = (int) ( $row['total_tokens'] ?? ( $p_tokens + $c_tokens ) );
            $m_units  = (int) ( $row['metric_units'] ?? 0 );
            $action   = (string) ( $row['action_trigger'] ?? '' );
            $provider = (string) ( $row['provider'] ?? '' );
            $model    = (string) ( $row['model'] ?? '' );
            $status   = (string) ( $row['status'] ?? 'success' );

            $total_tokens      += $t_tokens;
            $prompt_tokens     += $p_tokens;
            $completion_tokens += $c_tokens;

            if ( 'success' === $status ) {
                $success_count++;
            }

            // TTS metrics (characters)
            if ( false !== stripos( $action, 'audio' ) || false !== stripos( $action, 'tts' ) || in_array( $provider, [ 'google_cloud', 'gemini_tts', 'google_cloud_tts' ], true ) ) {
                $tts_chars += $m_units;
            }

            // Scrape metrics (articles)
            if ( false !== stripos( $action, 'scrape' ) || false !== stripos( $action, 'harvest' ) || 'scraper' === $provider ) {
                $scraped_articles += $m_units;
            }

            // Group tokens by model
            if ( $t_tokens > 0 && '' !== $model ) {
                $tokens_by_model[ $model ] = ( $tokens_by_model[ $model ] ?? 0 ) + $t_tokens;
            }

            // Group tokens by action
            if ( $t_tokens > 0 && '' !== $action ) {
                $tokens_by_action[ $action ] = ( $tokens_by_action[ $action ] ?? 0 ) + $t_tokens;
            }

            // Requests by provider
            if ( '' !== $provider ) {
                $requests_by_provider[ $provider ] = ( $requests_by_provider[ $provider ] ?? 0 ) + 1;
            }
        }

        $success_rate = $total_requests > 0 ? round( ( $success_count / $total_requests ) * 100, 2 ) : 100.0;

        arsort( $tokens_by_model );
        arsort( $tokens_by_action );
        arsort( $requests_by_provider );

        return [
            'total_tokens'         => $total_tokens,
            'total_requests'       => $total_requests,
            'prompt_tokens'        => $prompt_tokens,
            'completion_tokens'    => $completion_tokens,
            'tts_chars'            => $tts_chars,
            'scraped_articles'     => $scraped_articles,
            'success_rate'         => $success_rate,
            'tokens_by_model'      => $tokens_by_model,
            'tokens_by_action'     => $tokens_by_action,
            'requests_by_provider' => $requests_by_provider,
        ];
    }

    /**
     * Export matching token logs as RFC 4180 CSV formatted string.
     *
     * @param array $args Filter arguments matching get_logs().
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
            'action_trigger',
            'provider',
            'model',
            'prompt_tokens',
            'completion_tokens',
            'total_tokens',
            'metric_units',
            'duration_ms',
            'status',
            'user_id',
            'error_message',
        ] );

        foreach ( $rows as $row ) {
            fputcsv( $fp, [
                $row['id'] ?? '',
                $row['created_at'] ?? '',
                $row['action_trigger'] ?? '',
                $row['provider'] ?? '',
                $row['model'] ?? '',
                $row['prompt_tokens'] ?? 0,
                $row['completion_tokens'] ?? 0,
                $row['total_tokens'] ?? 0,
                $row['metric_units'] ?? 0,
                $row['duration_ms'] ?? 0,
                $row['status'] ?? '',
                $row['user_id'] ?? 0,
                $row['error_message'] ?? '',
            ] );
        }

        rewind( $fp );
        $csv = stream_get_contents( $fp );
        fclose( $fp );

        return (string) $csv;
    }

    /**
     * Prune logs older than $days (default 60).
     *
     * @param int $days Number of retention days.
     * @return int Number of deleted log rows.
     */
    public static function prune_old_logs( int $days = 60 ): int {
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
     * Clear / truncate all rows from the token logs table.
     *
     * @return bool True on success, false on failure.
     */
    public static function clear_all_logs(): bool {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return false;
        }

        $table_name = self::get_table_name();
        $result = $wpdb->query( "DELETE FROM {$table_name}" );

        return false !== $result;
    }

    /**
     * Delete every token-log row whose action_trigger matches $action.
     *
     * Used by automated test runners to clean up synthetic placeholders
     * (see {@see INTEGRATION_TEST_ACTION}) immediately after insertion, and
     * by the plugin activation hook to one-time purge pre-existing rows
     * left behind by older integration test versions.
     *
     * @param string $action Action identifier to match (exact equality).
     * @return int Number of deleted rows (0 if no rows matched).
     */
    public static function delete_logs_by_action( string $action ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }

        $action = trim( $action );
        if ( '' === $action ) {
            return 0;
        }

        $table_name = self::get_table_name();
        $sql        = $wpdb->prepare( "DELETE FROM {$table_name} WHERE action_trigger = %s", $action );
        $deleted    = $wpdb->query( $sql );

        return is_numeric( $deleted ) ? (int) $deleted : 0;
    }

    /**
     * Convenience wrapper that purges all synthetic integration test rows.
     *
     * @return int Number of deleted rows.
     */
    public static function delete_integration_test_logs(): int {
        return self::delete_logs_by_action( self::INTEGRATION_TEST_ACTION );
    }
}
