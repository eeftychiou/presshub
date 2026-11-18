<?php
/**
 * PressHub_AI_QA_Review_Store — the saved history of every QA gate review.
 *
 * One row per review (author publish, Daily Briefing, Review button, …) with
 * the verdict and, per check (editorial, legal, SEO), its score, threshold,
 * result and full feedback, so editors and admins can look back at every
 * score, not only the latest one kept in post meta. Shown on
 * PressHub → QA reviews (PressHub_AI_QA_Reviews_Admin).
 *
 * Rows are kept indefinitely: at a handful of reviews a day the table stays
 * small. Recording never throws and never blocks publishing.
 *
 * @package PressHub_AI_Editor
 * @since 2.47.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_QA_Review_Store {

    const DB_VERSION        = '1.0.0';
    const DB_VERSION_OPTION = 'presshub_ai_qa_reviews_db_version';

    /** Verdicts a review can end in. */
    const VERDICTS = [ 'pass', 'alert', 'hold', 'error' ];

    /** Results of one check. */
    const RESULTS = [ 'pass', 'fail', 'skip', 'error' ];

    /** The three checks, as column prefixes. */
    const CHECKS = [ 'editorial', 'legal', 'seo' ];

    const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function get_table_name(): string {
        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
        return $prefix . 'presshub_ai_qa_reviews';
    }

    public static function create_table(): void {
        global $wpdb;
        $table_name      = self::get_table_name();
        $charset_collate = ( isset( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) ? $wpdb->get_charset_collate() : '';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            trigger_type varchar(32) NOT NULL DEFAULT '',
            context varchar(16) NOT NULL DEFAULT 'post',
            verdict varchar(8) NOT NULL DEFAULT 'pass',
            content_hash varchar(32) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            trace_id varchar(64) NOT NULL DEFAULT '',
            editorial_result varchar(8) NOT NULL DEFAULT 'skip',
            editorial_score smallint(5) NULL,
            editorial_threshold smallint(5) NULL,
            legal_result varchar(8) NOT NULL DEFAULT 'skip',
            legal_risk varchar(8) NOT NULL DEFAULT '',
            seo_result varchar(8) NOT NULL DEFAULT 'skip',
            seo_score smallint(5) NULL,
            seo_threshold smallint(5) NULL,
            details_json longtext NULL,
            override_user_id bigint(20) unsigned NULL,
            override_reason text NULL,
            override_at datetime NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY post_id (post_id),
            KEY verdict (verdict)
        ) {$charset_collate};";

        if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if ( function_exists( 'dbDelta' ) ) {
            dbDelta( $sql );
        } elseif ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static DDL.
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /** Create or upgrade the table when its version changed. */
    public static function maybe_upgrade(): void {
        if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    /**
     * Save one gate review.
     *
     * @param array $gate Result of PressHub_AI_Publish_Gate::review_*() plus
     *                    post_id, trigger and content_hash.
     * @return int|null Row id, or null when the insert failed.
     */
    public static function record( array $gate ): ?int {
        try {
            global $wpdb;
            if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
                return null;
            }
            $checks = (array) ( $gate['checks'] ?? [] );
            $ed     = (array) ( $checks['editorial'] ?? [] );
            $lg     = (array) ( $checks['legal'] ?? [] );
            $seo    = (array) ( $checks['seo'] ?? [] );

            $row = [
                'created_at'          => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
                'post_id'             => max( 0, (int) ( $gate['post_id'] ?? 0 ) ),
                'trigger_type'        => substr( sanitize_key( (string) ( $gate['trigger'] ?? '' ) ), 0, 32 ),
                'context'             => 'briefing' === ( $gate['context'] ?? 'post' ) ? 'briefing' : 'post',
                'verdict'             => self::pick( (string) ( $gate['verdict'] ?? '' ), self::VERDICTS, 'error' ),
                'content_hash'        => substr( (string) ( $gate['content_hash'] ?? '' ), 0, 32 ),
                'user_id'             => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
                'trace_id'            => class_exists( 'PressHub_AI_Trace' ) ? (string) PressHub_AI_Trace::get_or_create_trace_id() : '',
                'editorial_result'    => self::pick( (string) ( $ed['result'] ?? '' ), self::RESULTS, 'skip' ),
                'editorial_score'     => isset( $ed['score'] ) ? (int) $ed['score'] : null,
                'editorial_threshold' => isset( $ed['threshold'] ) ? (int) $ed['threshold'] : null,
                'legal_result'        => self::pick( (string) ( $lg['result'] ?? '' ), self::RESULTS, 'skip' ),
                'legal_risk'          => substr( (string) ( $lg['risk'] ?? '' ), 0, 8 ),
                'seo_result'          => self::pick( (string) ( $seo['result'] ?? '' ), self::RESULTS, 'skip' ),
                'seo_score'           => isset( $seo['score'] ) ? (int) $seo['score'] : null,
                'seo_threshold'       => isset( $seo['threshold'] ) ? (int) $seo['threshold'] : null,
                'details_json'        => self::encode( $gate ),
            ];

            $ok = $wpdb->insert( self::get_table_name(), $row );
            if ( false === $ok ) {
                self::log_failure( 'QA review could not be saved', $row['post_id'] );
                return null;
            }
            return (int) $wpdb->insert_id;
        } catch ( Throwable $t ) {
            self::log_failure( 'QA review could not be saved: ' . $t->getMessage(), (int) ( $gate['post_id'] ?? 0 ) );
            return null;
        }
    }

    /** One review with its details decoded, or null. */
    public static function get( int $id ): ?array {
        global $wpdb;
        if ( $id <= 0 || ! isset( $wpdb ) ) {
            return null;
        }
        $table = self::get_table_name();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        return is_array( $row ) ? self::decode_row( $row ) : null;
    }

    /**
     * Reviews, newest first.
     *
     * @param array $filters post_id (int), verdict (string), failed (editorial|legal|seo).
     */
    public static function query( array $filters = [], int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return [];
        }
        $table = self::get_table_name();
        $sql   = "SELECT * FROM {$table}" . self::where( $filters ) . ' ORDER BY id DESC LIMIT ' . max( 1, min( 500, $limit ) );
        if ( $offset > 0 ) {
            $sql .= ' OFFSET ' . (int) $offset;
        }
        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- where() prepares every value.
        return array_map( [ __CLASS__, 'decode_row' ], is_array( $rows ) ? $rows : [] );
    }

    /** Number of reviews matching the filters. */
    public static function count( array $filters = [] ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return 0;
        }
        $table = self::get_table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" . self::where( $filters ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- where() prepares every value.
    }

    /** The reviews of one post, newest first. */
    public static function for_post( int $post_id, int $limit = 20 ): array {
        return $post_id > 0 ? self::query( [ 'post_id' => $post_id ], $limit ) : [];
    }

    /** Clean a filter array from a request. */
    public static function sanitize_filters( array $input ): array {
        $out = [];
        if ( ! empty( $input['post_id'] ) ) {
            $out['post_id'] = max( 0, (int) $input['post_id'] );
        }
        $verdict = sanitize_key( (string) ( $input['verdict'] ?? '' ) );
        if ( in_array( $verdict, self::VERDICTS, true ) ) {
            $out['verdict'] = $verdict;
        }
        $failed = sanitize_key( (string) ( $input['failed'] ?? '' ) );
        if ( in_array( $failed, self::CHECKS, true ) ) {
            $out['failed'] = $failed;
        }
        return $out;
    }

    private static function where( array $filters ): string {
        global $wpdb;
        $filters = self::sanitize_filters( $filters );
        $parts   = [];
        if ( ! empty( $filters['post_id'] ) ) {
            $parts[] = $wpdb->prepare( 'post_id = %d', $filters['post_id'] );
        }
        if ( ! empty( $filters['verdict'] ) ) {
            $parts[] = $wpdb->prepare( 'verdict = %s', $filters['verdict'] );
        }
        if ( ! empty( $filters['failed'] ) ) {
            // Column name from the CHECKS allow-list.
            $parts[] = $filters['failed'] . "_result = 'fail'";
        }
        return $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '';
    }

    private static function decode_row( array $row ): array {
        $details        = json_decode( (string) ( $row['details_json'] ?? '' ), true );
        $row['details'] = is_array( $details ) ? $details : [];
        unset( $row['details_json'] );
        foreach ( [ 'id', 'post_id', 'user_id' ] as $k ) {
            $row[ $k ] = (int) ( $row[ $k ] ?? 0 );
        }
        return $row;
    }

    private static function pick( string $value, array $allowed, string $fallback ): string {
        return in_array( $value, $allowed, true ) ? $value : $fallback;
    }

    private static function encode( $value ): string {
        $json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, self::JSON_FLAGS ) : json_encode( $value, self::JSON_FLAGS );
        return is_string( $json ) ? $json : '{}';
    }

    private static function log_failure( string $message, int $post_id ): void {
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::error( $message, [ 'post_id' => $post_id ] );
        }
    }
}
