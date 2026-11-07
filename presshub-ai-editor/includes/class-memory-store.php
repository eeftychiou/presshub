<?php
/**
 * Repository and storage manager for PressHub Editorial Memory.
 *
 * Implements Issue #132 (Wave A):
 *   - SQLite-compatible custom tables:
 *       wp_presshub_ai_memory_entries
 *       wp_presshub_ai_memory_entry_links
 *   - Content schema validation (stories, podcast, narrative)
 *   - Strict generated-vs-edited protection (Zero-Silent-Fallback)
 *   - Stale dependent propagation
 *   - Range queries, link helpers, retention pruning
 *   - WordPress post deletion hook for briefing digests
 *   - Audit logging via PressHub_AI_Audit_Logger
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-audit-logger.php';
require_once __DIR__ . '/class-settings-storage.php';

class PressHub_AI_Memory_Store {

    const DB_VERSION        = '1.0.0';
    const DB_VERSION_OPTION = 'presshub_ai_memory_db_version';

    /**
     * Allowed scopes.
     */
    const SCOPES = [ 'daily', 'weekly' ];

    /**
     * Allowed origins.
     */
    const ORIGINS = [ 'generated', 'edited' ];

    /**
     * Allowed statuses.
     */
    const STATUSES = [ 'pending', 'active', 'excluded', 'failed', 'stale' ];

    /**
     * Allowed editions.
     */
    const EDITIONS = [ 'morning', 'noon', '' ];

    /**
     * Allowed story statuses.
     */
    const STORY_STATUSES = [ 'new', 'developing', 'resolved' ];

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'before_delete_post', [ __CLASS__, 'on_before_delete_post' ] );
    }

    /**
     * Get entries table name with WordPress prefix.
     *
     * @return string
     */
    public static function get_entries_table(): string {
        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
        return $prefix . 'presshub_ai_memory_entries';
    }

    /**
     * Get entry links table name with WordPress prefix.
     *
     * @return string
     */
    public static function get_links_table(): string {
        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
        return $prefix . 'presshub_ai_memory_entry_links';
    }

    /**
     * Create or upgrade memory store tables.
     * Compatible with both MySQL/MariaDB and dev-env SQLite integration.
     *
     * @return void
     */
    public static function create_tables(): void {
        global $wpdb;

        $entries_table = self::get_entries_table();
        $links_table   = self::get_links_table();
        $charset_collate = ( isset( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) ? $wpdb->get_charset_collate() : '';

        $entries_sql = "CREATE TABLE {$entries_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scope varchar(16) NOT NULL,
            period_start date NOT NULL,
            period_end date NOT NULL,
            edition varchar(16) NOT NULL DEFAULT '',
            source_post_id bigint(20) unsigned NULL DEFAULT NULL,
            generated_json longtext NOT NULL,
            content_json longtext NOT NULL,
            origin varchar(16) NOT NULL DEFAULT 'generated',
            status varchar(16) NOT NULL DEFAULT 'active',
            error_message text NULL DEFAULT NULL,
            generation_record_id bigint(20) unsigned NULL DEFAULT NULL,
            version_hash varchar(64) NOT NULL DEFAULT '',
            edited_by bigint(20) unsigned NULL DEFAULT NULL,
            edited_at datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scope_period_edition (scope, period_start, edition),
            KEY scope (scope),
            KEY period_range (period_start, period_end),
            KEY source_post_id (source_post_id),
            KEY status (status)
        ) {$charset_collate};";

        $links_sql = "CREATE TABLE {$links_table} (
            rollup_id bigint(20) unsigned NOT NULL,
            digest_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (rollup_id, digest_id),
            KEY digest_id (digest_id)
        ) {$charset_collate};";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        if ( function_exists( 'dbDelta' ) ) {
            dbDelta( $entries_sql );
            dbDelta( $links_sql );
        } elseif ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
            $wpdb->query( $entries_sql );
            $wpdb->query( $links_sql );
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Validate content array or JSON against the memory content schema.
     *
     * Schema shape:
     * {
     *   "stories": [
     *     {
     *       "key": "slug-stable-across-days",
     *       "title": "...",
     *       "development": "one or two sentences",
     *       "status": "new|developing|resolved",
     *       "entities": ["..."],
     *       "source_urls": ["..."],
     *       "include": true
     *     }
     *   ],
     *   "podcast": { "opening_line": "...", "closing_line": "..." },
     *   "narrative": "weekly rollups only: compact background narrative"
     * }
     *
     * @param array|string $content
     * @return array|WP_Error Normalized array on success, WP_Error on failure.
     */
    public static function validate_content( $content ) {
        if ( is_string( $content ) ) {
            $decoded = json_decode( $content, true );
            if ( ! is_array( $decoded ) ) {
                return new WP_Error(
                    'invalid_memory_json',
                    __( 'Content must be valid JSON.', 'presshub-ai-editor' ),
                    [ 'raw' => substr( $content, 0, 200 ) ]
                );
            }
            $content = $decoded;
        }

        if ( ! is_array( $content ) ) {
            return new WP_Error(
                'invalid_memory_content',
                __( 'Memory content must be an associative array or JSON object.', 'presshub-ai-editor' )
            );
        }

        if ( ! isset( $content['stories'] ) || ! is_array( $content['stories'] ) ) {
            return new WP_Error(
                'invalid_memory_stories',
                __( 'Memory content must contain a stories array.', 'presshub-ai-editor' )
            );
        }

        $validated_stories = [];
        foreach ( $content['stories'] as $idx => $story ) {
            if ( ! is_array( $story ) ) {
                return new WP_Error(
                    'invalid_story_item',
                    sprintf( __( 'Story item at index %d is not an array.', 'presshub-ai-editor' ), $idx )
                );
            }

            $key = isset( $story['key'] ) ? sanitize_title( (string) $story['key'] ) : '';
            if ( '' === $key ) {
                return new WP_Error(
                    'missing_story_key',
                    sprintf( __( 'Story item at index %d requires a non-empty key slug.', 'presshub-ai-editor' ), $idx )
                );
            }

            $title = isset( $story['title'] ) ? trim( (string) $story['title'] ) : '';
            if ( '' === $title ) {
                return new WP_Error(
                    'missing_story_title',
                    sprintf( __( 'Story item "%s" requires a non-empty title.', 'presshub-ai-editor' ), $key )
                );
            }

            $development = isset( $story['development'] ) ? trim( (string) $story['development'] ) : '';
            if ( '' === $development ) {
                return new WP_Error(
                    'missing_story_development',
                    sprintf( __( 'Story item "%s" requires a non-empty development summary.', 'presshub-ai-editor' ), $key )
                );
            }

            if ( ! isset( $story['status'] ) ) {
                return new WP_Error(
                    'missing_story_status',
                    sprintf( __( 'Story item "%s" requires a status (%s).', 'presshub-ai-editor' ), $key, implode( ', ', self::STORY_STATUSES ) )
                );
            }
            $status = sanitize_key( (string) $story['status'] );
            if ( ! in_array( $status, self::STORY_STATUSES, true ) ) {
                return new WP_Error(
                    'invalid_story_status',
                    sprintf( __( 'Story item "%s" has invalid status "%s". Allowed: %s.', 'presshub-ai-editor' ), $key, $status, implode( ', ', self::STORY_STATUSES ) )
                );
            }

            if ( ! isset( $story['entities'] ) || ! is_array( $story['entities'] ) ) {
                return new WP_Error(
                    'invalid_story_entities',
                    sprintf( __( 'Story item "%s" requires an entities array.', 'presshub-ai-editor' ), $key )
                );
            }
            $entities = [];
            foreach ( $story['entities'] as $entity ) {
                $clean_entity = trim( sanitize_text_field( (string) $entity ) );
                if ( '' !== $clean_entity ) {
                    $entities[] = $clean_entity;
                }
            }

            if ( ! isset( $story['source_urls'] ) || ! is_array( $story['source_urls'] ) ) {
                return new WP_Error(
                    'invalid_story_source_urls',
                    sprintf( __( 'Story item "%s" requires a source_urls array.', 'presshub-ai-editor' ), $key )
                );
            }
            $source_urls = [];
            foreach ( $story['source_urls'] as $url ) {
                $clean_url = esc_url_raw( trim( (string) $url ) );
                if ( '' !== $clean_url ) {
                    $source_urls[] = $clean_url;
                }
            }

            if ( ! array_key_exists( 'include', $story ) ) {
                return new WP_Error(
                    'missing_story_include',
                    sprintf( __( 'Story item "%s" requires an include boolean.', 'presshub-ai-editor' ), $key )
                );
            }
            $include = (bool) $story['include'];

            $validated_stories[] = [
                'key'         => $key,
                'title'       => sanitize_text_field( $title ),
                'development' => sanitize_textarea_field( $development ),
                'status'      => $status,
                'entities'    => array_values( array_unique( $entities ) ),
                'source_urls' => array_values( array_unique( $source_urls ) ),
                'include'     => $include,
            ];
        }

        $podcast = [
            'opening_line' => '',
            'closing_line' => '',
        ];
        if ( isset( $content['podcast'] ) && is_array( $content['podcast'] ) ) {
            if ( isset( $content['podcast']['opening_line'] ) ) {
                $podcast['opening_line'] = sanitize_text_field( (string) $content['podcast']['opening_line'] );
            }
            if ( isset( $content['podcast']['closing_line'] ) ) {
                $podcast['closing_line'] = sanitize_text_field( (string) $content['podcast']['closing_line'] );
            }
        }

        $narrative = isset( $content['narrative'] ) ? sanitize_textarea_field( (string) $content['narrative'] ) : '';

        return [
            'stories'   => $validated_stories,
            'podcast'   => $podcast,
            'narrative' => $narrative,
        ];
    }

    /**
     * Compute SHA256 version hash for content array or JSON.
     *
     * @param array|string $content
     * @return string
     */
    public static function compute_hash( $content ): string {
        if ( is_array( $content ) ) {
            $json = wp_json_encode( $content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        } else {
            $json = (string) $content;
        }
        return hash( 'sha256', $json );
    }

    /**
     * Format a content array into stable JSON string.
     *
     * @param array $content
     * @return string
     */
    public static function encode_content( array $content ): string {
        return (string) wp_json_encode( $content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Decode content JSON into array.
     *
     * @param string $json
     * @return array
     */
    public static function decode_content( string $json ): array {
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [ 'stories' => [], 'podcast' => [ 'opening_line' => '', 'closing_line' => '' ], 'narrative' => '' ];
    }

    /**
     * Upsert a generated memory entry.
     *
     * Rules:
     *   - Writes generated_json.
     *   - Writes content_json ONLY IF the entry does not exist or is origin='generated'.
     *   - If existing entry is origin='edited', preserves content_json, updates generated_json,
     *     and flags preserved_edit=true in the return array.
     *
     * @param array $args {
     *     @type string     $scope                'daily'|'weekly'. Required.
     *     @type string     $period_start         'YYYY-MM-DD'. Required.
     *     @type string     $period_end           'YYYY-MM-DD'. Required.
     *     @type string     $edition              'morning'|'noon'|''. Default ''.
     *     @type int|null   $source_post_id       Nullable source post ID.
     *     @type array|string $generated_content  Content array or JSON. Required.
     *     @type int|null   $generation_record_id Nullable record ID.
     *     @type string     $status               Default 'active'.
     *     @type string     $error_message        Default ''.
     * }
     * @return array|WP_Error Array with entry ID, origin, preserved_edit flag, or WP_Error.
     */
    public static function upsert_generated( array $args ) {
        global $wpdb;

        $scope = sanitize_key( $args['scope'] ?? '' );
        if ( ! in_array( $scope, self::SCOPES, true ) ) {
            return new WP_Error( 'invalid_scope', __( 'Invalid memory scope.', 'presshub-ai-editor' ) );
        }

        $period_start = sanitize_text_field( $args['period_start'] ?? '' );
        $period_end   = sanitize_text_field( $args['period_end'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period_start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period_end ) ) {
            return new WP_Error( 'invalid_dates', __( 'period_start and period_end must be YYYY-MM-DD format.', 'presshub-ai-editor' ) );
        }
        // Real calendar date validation
        $ps_parts = explode( '-', $period_start );
        $pe_parts = explode( '-', $period_end );
        if ( ! checkdate( (int) $ps_parts[1], (int) $ps_parts[2], (int) $ps_parts[0] ) || ! checkdate( (int) $pe_parts[1], (int) $pe_parts[2], (int) $pe_parts[0] ) ) {
            return new WP_Error( 'invalid_calendar_date', __( 'period_start and period_end must be valid calendar dates.', 'presshub-ai-editor' ) );
        }
        if ( $period_end < $period_start ) {
            return new WP_Error( 'invalid_date_range', __( 'period_end cannot be earlier than period_start.', 'presshub-ai-editor' ) );
        }

        if ( 'weekly' === $scope ) {
            $edition = '';
        } else {
            if ( ! isset( $args['edition'] ) ) {
                return new WP_Error( 'missing_edition', __( 'Daily memory entries require an edition (morning or noon).', 'presshub-ai-editor' ) );
            }
            $edition = sanitize_key( $args['edition'] );
            if ( ! in_array( $edition, [ 'morning', 'noon' ], true ) ) {
                return new WP_Error( 'invalid_edition', sprintf( __( 'Invalid edition "%s". Allowed: morning, noon.', 'presshub-ai-editor' ), $edition ) );
            }
        }

        $val_res = self::validate_content( $args['generated_content'] ?? [] );
        if ( is_wp_error( $val_res ) ) {
            return $val_res;
        }

        $encoded_gen = self::encode_content( $val_res );
        $now         = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $source_pid  = ! empty( $args['source_post_id'] ) ? (int) $args['source_post_id'] : null;
        $gen_rec_id  = ! empty( $args['generation_record_id'] ) ? (int) $args['generation_record_id'] : null;
        if ( isset( $args['status'] ) ) {
            $status = sanitize_key( $args['status'] );
            if ( ! in_array( $status, self::STATUSES, true ) ) {
                return new WP_Error( 'invalid_status', sprintf( __( 'Invalid status "%s".', 'presshub-ai-editor' ), $status ) );
            }
        } else {
            $status = 'active';
        }
        $error_msg   = sanitize_textarea_field( (string) ( $args['error_message'] ?? '' ) );

        $entries_table = self::get_entries_table();

        // Check if an existing entry exists for (scope, period_start, edition)
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$entries_table} WHERE scope = %s AND period_start = %s AND edition = %s LIMIT 1",
                $scope,
                $period_start,
                $edition
            ),
            ARRAY_A
        );

        $preserved_edit = false;

        if ( $existing ) {
            $entry_id       = (int) $existing['id'];
            $existing_origin = (string) $existing['origin'];

            if ( 'edited' === $existing_origin ) {
                // Keep content_json, version_hash, origin, edited_by, edited_at.
                // Update generated_json, updated_at, source_post_id, generation_record_id.
                $preserved_edit = true;
                $update_data = [
                    'generated_json' => $encoded_gen,
                    'period_end'     => $period_end,
                    'updated_at'     => $now,
                ];
                $update_format = [ '%s', '%s', '%s' ];

                if ( null !== $source_pid ) {
                    $update_data['source_post_id'] = $source_pid;
                    $update_format[] = '%d';
                }
                if ( null !== $gen_rec_id ) {
                    $update_data['generation_record_id'] = $gen_rec_id;
                    $update_format[] = '%d';
                }
                if ( ! empty( $error_msg ) ) {
                    $update_data['error_message'] = $error_msg;
                    $update_format[] = '%s';
                }

                $wpdb->update(
                    $entries_table,
                    $update_data,
                    [ 'id' => $entry_id ],
                    $update_format,
                    [ '%d' ]
                );

                return [
                    'id'             => $entry_id,
                    'scope'          => $scope,
                    'period_start'   => $period_start,
                    'edition'        => $edition,
                    'origin'         => 'edited',
                    'preserved_edit' => true,
                    'version_hash'   => (string) $existing['version_hash'],
                ];
            } else {
                // Not edited: overwrite generated_json AND content_json
                // Preserve editor exclusion if existing status was 'excluded'
                $effective_status = ('excluded' === (string) ($existing['status'] ?? '')) ? 'excluded' : $status;
                $version_hash = self::compute_hash( $encoded_gen );
                $update_data = [
                    'period_end'           => $period_end,
                    'source_post_id'       => $source_pid,
                    'generated_json'       => $encoded_gen,
                    'content_json'         => $encoded_gen,
                    'origin'               => 'generated',
                    'status'               => $effective_status,
                    'error_message'        => $error_msg,
                    'generation_record_id' => $gen_rec_id,
                    'version_hash'         => $version_hash,
                    'edited_by'            => null,
                    'edited_at'            => null,
                    'updated_at'           => $now,
                ];
                $wpdb->update(
                    $entries_table,
                    $update_data,
                    [ 'id' => $entry_id ],
                    [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ],
                    [ '%d' ]
                );

                return [
                    'id'             => $entry_id,
                    'scope'          => $scope,
                    'period_start'   => $period_start,
                    'edition'        => $edition,
                    'origin'         => 'generated',
                    'preserved_edit' => false,
                    'version_hash'   => $version_hash,
                ];
            }
        }

        // Insert new entry
        $version_hash = self::compute_hash( $encoded_gen );
        $insert_data = [
            'scope'                => $scope,
            'period_start'         => $period_start,
            'period_end'           => $period_end,
            'edition'              => $edition,
            'source_post_id'       => $source_pid,
            'generated_json'       => $encoded_gen,
            'content_json'         => $encoded_gen,
            'origin'               => 'generated',
            'status'               => $status,
            'error_message'        => $error_msg,
            'generation_record_id' => $gen_rec_id,
            'version_hash'         => $version_hash,
            'edited_by'            => null,
            'edited_at'            => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];

        $inserted = $wpdb->insert(
            $entries_table,
            $insert_data,
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return new WP_Error( 'db_insert_failed', __( 'Could not insert memory entry into database.', 'presshub-ai-editor' ), [ 'last_error' => $wpdb->last_error ] );
        }

        $new_id = (int) $wpdb->insert_id;

        return [
            'id'             => $new_id,
            'scope'          => $scope,
            'period_start'   => $period_start,
            'edition'        => $edition,
            'origin'         => 'generated',
            'preserved_edit' => false,
            'version_hash'   => $version_hash,
        ];
    }

    /**
     * Editor content update: sets origin=edited, recomputes version_hash,
     * updates edited_by/edited_at, marks dependent rollups stale,
     * and logs mutation to PressHub_AI_Audit_Logger.
     *
     * @param int          $entry_id
     * @param array|string $content
     * @param int|null     $user_id Optional user ID. Defaults to current user.
     * @return array|WP_Error
     */
    public static function update_content( int $entry_id, $content, ?int $user_id = null ) {
        global $wpdb;

        $entry = self::get_entry( $entry_id );
        if ( ! $entry ) {
            return new WP_Error( 'not_found', __( 'Memory entry not found.', 'presshub-ai-editor' ) );
        }

        $validated = self::validate_content( $content );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $encoded_content = self::encode_content( $validated );
        $new_hash        = self::compute_hash( $encoded_content );
        $old_hash        = (string) $entry['version_hash'];

        if ( null === $user_id ) {
            $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $entries_table = self::get_entries_table();

        $updated = $wpdb->update(
            $entries_table,
            [
                'content_json' => $encoded_content,
                'origin'       => 'edited',
                'version_hash' => $new_hash,
                'edited_by'    => $user_id > 0 ? $user_id : null,
                'edited_at'    => $now,
                'updated_at'   => $now,
            ],
            [ 'id' => $entry_id ],
            [ '%s', '%s', '%s', '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'db_update_failed', __( 'Failed to update memory content in database.', 'presshub-ai-editor' ) );
        }

        // Mark dependent weekly rollups stale if this was a daily digest
        $stale_count = 0;
        if ( 'daily' === $entry['scope'] ) {
            $stale_count = self::mark_dependents_stale( $entry_id );
        }

        // Audit log
        PressHub_AI_Audit_Logger::log(
            'memory_content_updated',
            'memory_entry',
            (string) $entry_id,
            [
                'scope'               => $entry['scope'],
                'period_start'        => $entry['period_start'],
                'edition'             => $entry['edition'],
                'before_version_hash' => $old_hash,
                'after_version_hash'  => $new_hash,
                'dependents_stale'    => $stale_count,
            ]
        );

        return [
            'id'                  => $entry_id,
            'origin'              => 'edited',
            'version_hash'        => $new_hash,
            'marked_dependents_stale' => $stale_count,
        ];
    }

    /**
     * Reset an entry's content back to the immutable generated_json.
     * Sets origin='generated', recomputes hash from generated_json, clears edited_by/edited_at,
     * marks dependents stale if digest, and audit logs.
     *
     * @param int      $entry_id
     * @param int|null $user_id
     * @return array|WP_Error
     */
    public static function reset_to_generated( int $entry_id, ?int $user_id = null ) {
        global $wpdb;

        $entry = self::get_entry( $entry_id );
        if ( ! $entry ) {
            return new WP_Error( 'not_found', __( 'Memory entry not found.', 'presshub-ai-editor' ) );
        }

        $generated_json = (string) $entry['generated_json'];
        $new_hash       = self::compute_hash( $generated_json );
        $old_hash       = (string) $entry['version_hash'];
        $now            = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $entries_table  = self::get_entries_table();

        $updated = $wpdb->update(
            $entries_table,
            [
                'content_json' => $generated_json,
                'origin'       => 'generated',
                'version_hash' => $new_hash,
                'edited_by'    => null,
                'edited_at'    => null,
                'updated_at'   => $now,
            ],
            [ 'id' => $entry_id ],
            [ '%s', '%s', '%s', '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'db_update_failed', __( 'Failed to reset memory entry.', 'presshub-ai-editor' ) );
        }

        $stale_count = 0;
        if ( 'daily' === $entry['scope'] ) {
            $stale_count = self::mark_dependents_stale( $entry_id );
        }

        if ( null === $user_id ) {
            $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        }

        PressHub_AI_Audit_Logger::log(
            'memory_reset_to_generated',
            'memory_entry',
            (string) $entry_id,
            [
                'scope'               => $entry['scope'],
                'period_start'        => $entry['period_start'],
                'edition'             => $entry['edition'],
                'reset_by'            => $user_id > 0 ? $user_id : null,
                'before_version_hash' => $old_hash,
                'after_version_hash'  => $new_hash,
                'dependents_stale'    => $stale_count,
            ]
        );

        return [
            'id'                  => $entry_id,
            'origin'              => 'generated',
            'version_hash'        => $new_hash,
            'marked_dependents_stale' => $stale_count,
        ];
    }

    /**
     * Change entry status (e.g. active <-> excluded).
     *
     * @param int    $entry_id
     * @param string $status   'active'|'excluded'|'stale'|'failed'|'pending'
     * @return array|WP_Error
     */
    public static function set_status( int $entry_id, string $status ) {
        global $wpdb;

        $entry = self::get_entry( $entry_id );
        if ( ! $entry ) {
            return new WP_Error( 'not_found', __( 'Memory entry not found.', 'presshub-ai-editor' ) );
        }

        $status = sanitize_key( $status );
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            return new WP_Error( 'invalid_status', sprintf( __( 'Invalid status "%s".', 'presshub-ai-editor' ), $status ) );
        }

        $old_status    = (string) $entry['status'];
        $now           = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $entries_table = self::get_entries_table();

        $updated = $wpdb->update(
            $entries_table,
            [
                'status'     => $status,
                'updated_at' => $now,
            ],
            [ 'id' => $entry_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'db_update_failed', __( 'Failed to update memory status in database.', 'presshub-ai-editor' ) );
        }

        $stale_count = 0;
        if ( 'daily' === $entry['scope'] && ( 'excluded' === $status || 'active' === $status ) ) {
            $stale_count = self::mark_dependents_stale( $entry_id );
        }

        PressHub_AI_Audit_Logger::log(
            'memory_status_changed',
            'memory_entry',
            (string) $entry_id,
            [
                'scope'            => $entry['scope'],
                'period_start'     => $entry['period_start'],
                'edition'          => $entry['edition'],
                'old_status'       => $old_status,
                'new_status'       => $status,
                'dependents_stale' => $stale_count,
            ]
        );

        return [
            'id'                  => $entry_id,
            'status'              => $status,
            'marked_dependents_stale' => $stale_count,
        ];
    }

    /**
     * Retrieve a memory entry by ID.
     *
     * @param int $entry_id
     * @return array|null
     */
    public static function get_entry( int $entry_id ): ?array {
        global $wpdb;
        $table = self::get_entries_table();
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $entry_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Retrieve entries for a date range and scope.
     * Range condition:
     *   Entries overlapping or falling within [$from, $to].
     *   For standard single-day digests, period_start = period_end.
     *   Condition: period_start <= $to AND period_end >= $from.
     *
     * @param string      $from  'YYYY-MM-DD'
     * @param string      $to    'YYYY-MM-DD'
     * @param string|null $scope 'daily'|'weekly'|null (both if null)
     * @param string|null $status Optional status filter (e.g. 'active'). Null for all non-excluded or all.
     * @return array List of entry arrays ordered by period_start ASC, edition ASC.
     */
    public static function get_entries_for_range( string $from, string $to, ?string $scope = null, ?string $status = null ): array {
        global $wpdb;
        $table = self::get_entries_table();

        $where  = [ 'period_start <= %s', 'period_end >= %s' ];
        $params = [ $to, $from ];

        if ( null !== $scope && in_array( $scope, self::SCOPES, true ) ) {
            $where[]  = 'scope = %s';
            $params[] = $scope;
        }

        if ( null !== $status && in_array( $status, self::STATUSES, true ) ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY period_start ASC, edition ASC, id ASC';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Retrieve daily digest entry associated with a specific source post ID.
     *
     * @param int $post_id
     * @return array|null
     */
    public static function get_digest_for_post( int $post_id ): ?array {
        global $wpdb;
        $table = self::get_entries_table();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_post_id = %d AND scope = 'daily' LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Link a weekly rollup to the daily digest IDs it was constructed from.
     *
     * @param int   $rollup_id
     * @param array $digest_ids Array of int digest entry IDs.
     * @return int Number of links inserted.
     */
    public static function link_rollup( int $rollup_id, array $digest_ids ): int {
        global $wpdb;
        $links_table = self::get_links_table();
        $inserted    = 0;

        foreach ( $digest_ids as $did ) {
            $did = (int) $did;
            if ( $did <= 0 ) {
                continue;
            }
            $res = $wpdb->replace(
                $links_table,
                [
                    'rollup_id' => $rollup_id,
                    'digest_id' => $did,
                ],
                [ '%d', '%d' ]
            );
            if ( false !== $res ) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Mark weekly rollups that depend on a given daily digest as 'stale'.
     *
     * @param int $digest_id
     * @return int Number of rollups marked stale.
     */
    public static function mark_dependents_stale( int $digest_id ): int {
        global $wpdb;
        $entries_table = self::get_entries_table();
        $links_table   = self::get_links_table();

        // Find linked rollup IDs that are currently active
        $sql = "SELECT r.id FROM {$entries_table} r
                INNER JOIN {$links_table} l ON r.id = l.rollup_id
                WHERE l.digest_id = %d AND r.scope = 'weekly' AND r.status = 'active'";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $digest_id ), ARRAY_A );
        if ( empty( $rows ) ) {
            return 0;
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $count = 0;

        foreach ( $rows as $r ) {
            $rid = (int) $r['id'];
            $wpdb->update(
                $entries_table,
                [ 'status' => 'stale', 'updated_at' => $now ],
                [ 'id' => $rid ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            $count++;

            PressHub_AI_Audit_Logger::log(
                'memory_rollup_marked_stale',
                'memory_entry',
                (string) $rid,
                [
                    'triggered_by_digest_id' => $digest_id,
                ]
            );
        }

        return $count;
    }

    /**
     * Clean Slate: before a post is deleted, if it's a briefing post,
     * delete its daily digest entries and clean up associated links.
     * Hooked to `before_delete_post`.
     *
     * @param int $post_id
     * @return void
     */
    public static function on_before_delete_post( $post_id ): void {
        global $wpdb;
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }

        $entries_table = self::get_entries_table();
        $links_table   = self::get_links_table();

        // Check if there are daily digest entries pointing to this post
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$entries_table} WHERE source_post_id = %d AND scope = 'daily'",
                $post_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $entry_id = (int) $row['id'];
            // Mark dependents stale before deletion
            self::mark_dependents_stale( $entry_id );

            // Delete links
            $wpdb->delete( $links_table, [ 'digest_id' => $entry_id ], [ '%d' ] );

            // Delete entry
            $wpdb->delete( $entries_table, [ 'id' => $entry_id ], [ '%d' ] );

            PressHub_AI_Audit_Logger::log(
                'memory_entry_deleted_on_post_delete',
                'memory_entry',
                (string) $entry_id,
                [
                    'source_post_id' => $post_id,
                ]
            );
        }
    }

    /**
     * Prune memory entries older than the retention threshold.
     * Also deletes associated links in the links table.
     *
     * @param int|null $retention_days If null, uses PressHub_AI_Settings_Storage::get_memory_retention_days().
     * @return int Number of deleted memory entries.
     */
    public static function prune_old_entries( ?int $retention_days = null ): int {
        global $wpdb;

        if ( null === $retention_days || $retention_days <= 0 ) {
            $retention_days = PressHub_AI_Settings_Storage::get_memory_retention_days();
        }

        $cutoff_date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', strtotime( "-{$retention_days} days" ) ) : gmdate( 'Y-m-d', strtotime( "-{$retention_days} days" ) );

        $entries_table = self::get_entries_table();
        $links_table   = self::get_links_table();

        // Find entries where period_end < cutoff_date
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$entries_table} WHERE period_end < %s",
                $cutoff_date
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return 0;
        }

        $deleted_count = 0;
        foreach ( $rows as $row ) {
            $eid = (int) $row['id'];
            // Delete links where this entry is rollup or digest
            $wpdb->delete( $links_table, [ 'rollup_id' => $eid ], [ '%d' ] );
            $wpdb->delete( $links_table, [ 'digest_id' => $eid ], [ '%d' ] );

            // Delete entry
            $del = $wpdb->delete( $entries_table, [ 'id' => $eid ], [ '%d' ] );
            if ( false !== $del && $del > 0 ) {
                $deleted_count++;
            }
        }

        if ( $deleted_count > 0 ) {
            PressHub_AI_Audit_Logger::log(
                'memory_entries_pruned',
                'memory_store',
                'retention_cron',
                [
                    'retention_days' => $retention_days,
                    'cutoff_date'    => $cutoff_date,
                    'deleted_count'  => $deleted_count,
                ]
            );
        }

        return $deleted_count;
    }
}
