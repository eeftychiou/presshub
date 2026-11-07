<?php
/**
 * PressHub_AI_Generation_Recorder — durable per-generation records.
 *
 * Persists the exact prompts sent to and the response received from the LLM for
 * every LLM, image and TTS call (briefing / podcast generations since #131, every
 * other call since #140), together with the resolved client configuration,
 * per-article inputs, memory context, TTS configuration and per-call TTS details,
 * in a dedicated WordPress DB table. Prompts and responses are stored verbatim.
 * JSON is stored as TEXT (no JSON SQL functions, no FULLTEXT) so the table works
 * under the dev-env SQLite integration.
 *
 * Recording must never break generation: record() never throws and returns null
 * on failure after logging an ERROR through PressHub_AI_Logger.
 *
 * @package PressHub_AI_Editor
 * @since 2.9.0 (#140: kinds for every call, tts_calls_json)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Generation_Recorder {

    /** DB table version for schema migration checks. */
    const DB_VERSION        = '1.1.0';
    const DB_VERSION_OPTION = 'presshub_ai_generation_records_db_version';

    /** Post meta key holding the ordered array of record IDs attached to a post. */
    const POST_META_KEY = '_presshub_generation_record_ids';

    /**
     * Allowed record kinds.
     *
     * #131: briefing_text, podcast_script, memory_digest, memory_rollup.
     * #140: every other LLM, image and TTS call: coauthor_draft, scorecard,
     * copilot_chat, research, image_generation, generic (any other
     * call_provider() call) and tts_test (TTS calls without a parent record).
     */
    const KINDS = [
        'briefing_text',
        'podcast_script',
        'memory_digest',
        'memory_rollup',
        'coauthor_draft',
        'scorecard',
        'copilot_chat',
        'research',
        'image_generation',
        'generic',
        'tts_test',
    ];

    /**
     * Kinds created interactively by a logged-in user that may hold unpublished
     * work. The record's own author (config.user_id) may open these in the
     * Generation Inspector without edit_others_posts.
     */
    const AUTHOR_KINDS = [ 'coauthor_draft', 'scorecard', 'copilot_chat', 'research' ];

    /** Daily prune cron hook. */
    const PRUNE_HOOK = 'presshub_ai_prune_generation_records';

    /** JSON encoding flags used for every JSON column. */
    const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** JSON columns decoded by get(), keyed by column => output key. */
    const JSON_COLUMNS = [
        'config_json' => 'config',
        'inputs_json' => 'inputs',
        'memory_json' => 'memory',
        'tts_json'    => 'tts',
        'tts_calls_json' => 'tts_calls',
        'usage_json'  => 'usage',
    ];

    /**
     * Get table name with WordPress prefix.
     *
     * @return string Table name.
     */
    public static function get_table_name(): string {
        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
        return $prefix . 'presshub_ai_generation_records';
    }

    /**
     * Create or update the generation records table using dbDelta.
     *
     * @return void
     */
    public static function create_table(): void {
        global $wpdb;
        $table_name      = self::get_table_name();
        $charset_collate = ( isset( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) ? $wpdb->get_charset_collate() : '';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            trace_id varchar(64) NOT NULL DEFAULT '',
            kind varchar(32) NOT NULL,
            briefing_date varchar(10) NOT NULL DEFAULT '',
            edition varchar(16) NOT NULL DEFAULT '',
            post_id bigint(20) unsigned NULL,
            status varchar(16) NOT NULL DEFAULT 'success',
            error_message text NULL,
            config_json longtext NULL,
            inputs_json longtext NULL,
            memory_json longtext NULL,
            tts_json longtext NULL,
            tts_calls_json longtext NULL,
            system_prompt longtext NULL,
            user_prompt longtext NULL,
            response_text longtext NULL,
            usage_json text NULL,
            duration_ms int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY kind (kind),
            KEY briefing_date (briefing_date),
            KEY post_id (post_id),
            KEY trace_id (trace_id)
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
     * Insert one generation record.
     *
     * Never throws: a recording failure must never break generation.
     *
     * @param array $data Record data. See class docs; `kind` is required.
     * @return int|null New record ID, or null on failure.
     */
    public static function record( array $data ): ?int {
        $trace_id = '';
        $kind     = '';
        try {
            $kind     = isset( $data['kind'] ) ? (string) $data['kind'] : '';
            $trace_id = isset( $data['trace_id'] ) ? (string) $data['trace_id'] : '';
            if ( '' === $trace_id && class_exists( 'PressHub_AI_Trace' ) ) {
                $trace_id = (string) PressHub_AI_Trace::get_or_create_trace_id();
            }

            if ( ! in_array( $kind, self::KINDS, true ) ) {
                self::log_failure( 'Generation record rejected: unknown kind', $trace_id, $kind );
                return null;
            }

            global $wpdb;
            if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
                self::log_failure( 'Generation record insert failed: $wpdb unavailable', $trace_id, $kind );
                return null;
            }

            $memory = ( isset( $data['memory'] ) && is_array( $data['memory'] ) )
                ? $data['memory']
                : [ 'enabled' => false ];

            $post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;

            $row = [
                'created_at'    => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
                'trace_id'      => $trace_id,
                'kind'          => $kind,
                'briefing_date' => (string) ( $data['briefing_date'] ?? '' ),
                'edition'       => (string) ( $data['edition'] ?? '' ),
                'post_id'       => $post_id > 0 ? $post_id : null,
                'status'        => 'error' === ( $data['status'] ?? 'success' ) ? 'error' : 'success',
                'error_message' => isset( $data['error_message'] ) && '' !== (string) $data['error_message'] ? (string) $data['error_message'] : null,
                'config_json'   => self::encode_json( $data['config'] ?? [] ),
                'inputs_json'   => self::encode_json( $data['inputs'] ?? [] ),
                'memory_json'   => self::encode_json( $memory ),
                'tts_json'      => ( isset( $data['tts'] ) && is_array( $data['tts'] ) ) ? self::encode_json( $data['tts'] ) : null,
                'system_prompt' => isset( $data['system_prompt'] ) ? (string) $data['system_prompt'] : null,
                'user_prompt'   => isset( $data['user_prompt'] ) ? (string) $data['user_prompt'] : null,
                'response_text' => isset( $data['response_text'] ) ? (string) $data['response_text'] : null,
                'usage_json'    => self::encode_json( $data['usage'] ?? [] ),
                'duration_ms'   => max( 0, (int) ( $data['duration_ms'] ?? 0 ) ),
            ];

            $format = [
                '%s', // created_at
                '%s', // trace_id
                '%s', // kind
                '%s', // briefing_date
                '%s', // edition
                '%d', // post_id
                '%s', // status
                '%s', // error_message
                '%s', // config_json
                '%s', // inputs_json
                '%s', // memory_json
                '%s', // tts_json
                '%s', // system_prompt
                '%s', // user_prompt
                '%s', // response_text
                '%s', // usage_json
                '%d', // duration_ms
            ];

            $result = $wpdb->insert( self::get_table_name(), $row, $format );
            if ( false === $result || empty( $wpdb->insert_id ) ) {
                $db_error = ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) ? ' (' . $wpdb->last_error . ')' : '';
                self::log_failure( 'Generation record insert failed' . $db_error, $trace_id, $kind );
                return null;
            }

            return (int) $wpdb->insert_id;
        } catch ( \Throwable $t ) {
            self::log_failure( 'Generation record threw: ' . $t->getMessage(), $trace_id, $kind );
            return null;
        }
    }

    /**
     * Update allowed fields of an existing record: post_id, status, error_message, tts,
     * tts_calls (#140: per-call TTS envelope, see PressHub_AI_TTS_Call_Recorder).
     *
     * @param int   $id     Record ID.
     * @param array $fields Fields to update (others are ignored).
     * @return bool True on success.
     */
    public static function update_record( int $id, array $fields ): bool {
        global $wpdb;
        if ( $id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
            return false;
        }

        $set    = [];
        $format = [];

        if ( array_key_exists( 'post_id', $fields ) ) {
            $post_id  = (int) $fields['post_id'];
            $set['post_id'] = $post_id > 0 ? $post_id : null;
            $format[] = '%d';
        }
        if ( array_key_exists( 'status', $fields ) ) {
            $set['status'] = 'error' === $fields['status'] ? 'error' : 'success';
            $format[]      = '%s';
        }
        if ( array_key_exists( 'error_message', $fields ) ) {
            $msg                  = (string) $fields['error_message'];
            $set['error_message'] = '' !== $msg ? $msg : null;
            $format[]             = '%s';
        }
        if ( array_key_exists( 'tts', $fields ) ) {
            $set['tts_json'] = is_array( $fields['tts'] ) ? self::encode_json( $fields['tts'] ) : null;
            $format[]        = '%s';
        }
        if ( array_key_exists( 'tts_calls', $fields ) ) {
            try {
                $set['tts_calls_json'] = is_array( $fields['tts_calls'] ) ? self::encode_json( $fields['tts_calls'] ) : null;
            } catch ( \Throwable $t ) {
                self::log_failure( 'Generation record tts_calls encoding failed: ' . $t->getMessage(), '', '' );
                return false;
            }
            $format[] = '%s';
        }

        if ( empty( $set ) ) {
            return false;
        }

        $result = $wpdb->update( self::get_table_name(), $set, [ 'id' => $id ], $format, [ '%d' ] );
        return false !== $result;
    }

    /**
     * Fetch one record with JSON fields decoded to arrays.
     *
     * @param int $id Record ID.
     * @return array|null Record, or null if missing.
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        if ( $id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return null;
        }

        $table_name = self::get_table_name();
        $row        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
        if ( ! is_array( $row ) || empty( $row ) ) {
            return null;
        }

        $row['id']          = (int) ( $row['id'] ?? 0 );
        $row['post_id']     = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
        $row['duration_ms'] = (int) ( $row['duration_ms'] ?? 0 );

        foreach ( self::JSON_COLUMNS as $column => $key ) {
            $decoded = null;
            if ( isset( $row[ $column ] ) && '' !== (string) $row[ $column ] ) {
                $decoded = json_decode( (string) $row[ $column ], true );
            }
            $row[ $key ] = is_array( $decoded ) ? $decoded : ( 'tts' === $key ? null : [] );
            unset( $row[ $column ] );
        }

        return $row;
    }

    /**
     * Start capturing one LLM call: clears the Token_Logger's last-request
     * capture so usage reflects only this call, and returns the start time.
     * Pair with finish_call() (#140).
     *
     * @return float Start time (microtime).
     */
    public static function begin_call(): float {
        if ( class_exists( 'PressHub_AI_Token_Logger' ) && method_exists( 'PressHub_AI_Token_Logger', 'reset_last_llm_request' ) ) {
            PressHub_AI_Token_Logger::reset_last_llm_request();
        }
        return microtime( true );
    }

    /**
     * Record one finished LLM call (success or error) with verbatim prompts and
     * response. Never throws; returns 0 when nothing was stored.
     *
     * @param float                 $started_at  Value returned by begin_call().
     * @param string                $kind        One of KINDS.
     * @param object|null           $client      API client the call was made on (provider, model, settings).
     * @param string                $sys_prompt  System prompt exactly as sent.
     * @param string                $user_prompt User prompt exactly as sent.
     * @param string|WP_Error|mixed $result      Raw provider result (text or WP_Error).
     * @param array                 $extra       Optional: post_id, inputs, config (merged over the client config), user_id.
     * @return int Record ID, or 0.
     */
    public static function finish_call( float $started_at, string $kind, $client, $sys_prompt, $user_prompt, $result, array $extra = [] ): int {
        try {
            $duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
            $usage       = ( class_exists( 'PressHub_AI_Token_Logger' ) && method_exists( 'PressHub_AI_Token_Logger', 'get_last_llm_request' ) )
                ? ( PressHub_AI_Token_Logger::get_last_llm_request() ?? [] )
                : [];
            $is_error    = is_wp_error( $result );
            $config      = self::build_client_config( $client, is_array( $extra['config'] ?? null ) ? $extra['config'] : [] );
            $user_id     = isset( $extra['user_id'] ) ? (int) $extra['user_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
            if ( $user_id > 0 ) {
                $config['user_id'] = $user_id;
            }
            $post_id = (int) ( $extra['post_id'] ?? 0 );

            $record_id = (int) self::record( [
                'kind'          => $kind,
                'status'        => $is_error ? 'error' : 'success',
                'error_message' => $is_error ? $result->get_error_message() : '',
                'config'        => $config,
                'inputs'        => is_array( $extra['inputs'] ?? null ) ? $extra['inputs'] : [],
                'system_prompt' => (string) $sys_prompt,
                'user_prompt'   => (string) $user_prompt,
                'response_text' => $is_error ? '' : (string) $result,
                'usage'         => is_array( $usage ) ? $usage : [],
                'duration_ms'   => $duration_ms,
                'post_id'       => $post_id,
            ] );
            if ( $record_id > 0 && $post_id > 0 ) {
                self::attach_to_post( $post_id, $record_id );
            }
            return $record_id;
        } catch ( \Throwable $t ) {
            self::log_failure( 'Generation record threw: ' . $t->getMessage(), '', $kind );
            return 0;
        }
    }

    /**
     * Whether a user may open a record in the Generation Inspector (#140).
     *
     * edit_others_posts always may. The record's own author may open the
     * interactive kinds (AUTHOR_KINDS) that can hold unpublished work, using the
     * user ID stored in config.user_id, provided the user can edit posts.
     *
     * @param string $kind      Record kind.
     * @param int    $author_id config.user_id stored on the record (0 when none).
     * @param int    $user_id   Current user ID.
     * @return bool
     */
    public static function user_can_open( string $kind, int $author_id, int $user_id ): bool {
        if ( current_user_can( 'edit_others_posts' ) ) {
            return true;
        }
        return $user_id > 0
            && $author_id === $user_id
            && in_array( $kind, self::AUTHOR_KINDS, true )
            && current_user_can( 'edit_posts' );
    }

    /**
     * Records attached to a post, in the order of the POST_META_KEY array.
     *
     * @param int $post_id Post ID.
     * @return array List of records (see get()).
     */
    public static function get_for_post( int $post_id ): array {
        if ( $post_id <= 0 ) {
            return [];
        }
        $ids     = self::get_attached_ids( $post_id );
        $records = [];
        foreach ( $ids as $record_id ) {
            $record = self::get( $record_id );
            if ( null !== $record ) {
                $records[] = $record;
            }
        }
        return $records;
    }

    /**
     * Append a record ID to the post's record-id list (no duplicates, order
     * preserved) and set the record's post_id column if it is NULL/0.
     *
     * @param int $post_id   Post ID.
     * @param int $record_id Record ID.
     * @return void
     */
    public static function attach_to_post( int $post_id, int $record_id ): void {
        if ( $post_id <= 0 || $record_id <= 0 ) {
            return;
        }

        $ids = self::get_attached_ids( $post_id );
        if ( ! in_array( $record_id, $ids, true ) ) {
            $ids[] = $record_id;
            update_post_meta( $post_id, self::POST_META_KEY, $ids );
        }

        $record = self::get( $record_id );
        if ( null !== $record && 0 === (int) $record['post_id'] ) {
            self::update_record( $record_id, [ 'post_id' => $post_id ] );
        }
    }

    /**
     * Delete records older than $days.
     *
     * @param int $days Retention in days (minimum 1).
     * @return int Number of deleted rows.
     */
    public static function prune( int $days ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }

        $table_name = self::get_table_name();
        $seconds    = max( 1, $days ) * ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
        // Same WP-local cutoff formatting as PressHub_AI_Token_Logger::prune_old_logs().
        $cutoff = function_exists( 'wp_date' )
            ? wp_date( 'Y-m-d H:i:s', time() - $seconds )
            : gmdate( 'Y-m-d H:i:s', time() - $seconds );
        $sql     = $wpdb->prepare( "DELETE FROM {$table_name} WHERE created_at < %s", $cutoff );
        $deleted = $wpdb->query( $sql );

        return is_numeric( $deleted ) ? (int) $deleted : 0;
    }

    /**
     * Build config_json from an API client instance.
     *
     * @param object|null $client API client (PressHub_AI_API_Client).
     * @param array       $extra  Extra keys merged on top (preset_id, edition, context_mode, ...).
     * @return array
     */
    public static function build_client_config( $client, array $extra = [] ): array {
        $config = [];

        if ( is_object( $client ) ) {
            if ( method_exists( $client, 'get_module' ) ) {
                $config['module'] = (string) $client->get_module();
            }
            if ( method_exists( $client, 'get_action' ) ) {
                $config['action'] = (string) $client->get_action();
            }
            if ( method_exists( $client, 'get_provider' ) ) {
                $config['provider_type'] = (string) $client->get_provider();
            }
            if ( method_exists( $client, 'get_provider_id' ) ) {
                $config['provider_id'] = (string) $client->get_provider_id();
            }
            if ( method_exists( $client, 'get_provider_source' ) ) {
                $config['provider_source'] = (string) $client->get_provider_source();
            }
            if ( method_exists( $client, 'get_model' ) ) {
                $config['model'] = (string) $client->get_model();
            }
            if ( method_exists( $client, 'get_model_source' ) ) {
                $config['model_source'] = (string) $client->get_model_source();
            } elseif ( method_exists( $client, 'get_request_meta' )
                && preg_match( '/\[source: ([^\]]+)\]/', (string) $client->get_request_meta(), $m ) ) {
                $config['model_source'] = $m[1];
            }
            if ( method_exists( $client, 'get_temperature' ) ) {
                $config['temperature'] = $client->get_temperature();
            }
            if ( method_exists( $client, 'get_max_tokens' ) ) {
                $config['max_tokens'] = $client->get_max_tokens();
            }
            if ( method_exists( $client, 'get_timeout' ) ) {
                $config['timeout'] = $client->get_timeout();
            }
        }

        return array_merge( $config, $extra );
    }

    /**
     * Encode a value as JSON for storage (unescaped slashes and unicode).
     *
     * @param mixed $value Value to encode.
     * @return string
     */
    private static function encode_json( $value ): string {
        $json = wp_json_encode( $value, self::JSON_FLAGS );
        if ( false === $json ) {
            throw new \RuntimeException( 'JSON encoding failed: ' . json_last_error_msg() );
        }
        return $json;
    }

    /**
     * Read the attached record ID list from post meta as a clean int array.
     *
     * @param int $post_id Post ID.
     * @return int[]
     */
    private static function get_attached_ids( int $post_id ): array {
        $raw = get_post_meta( $post_id, self::POST_META_KEY, true );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $ids = [];
        foreach ( $raw as $value ) {
            $id = (int) $value;
            if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Log a recording failure through PressHub_AI_Logger (never throws).
     *
     * @param string $message  Message.
     * @param string $trace_id Trace ID.
     * @param string $kind     Record kind.
     * @return void
     */
    private static function log_failure( string $message, string $trace_id, string $kind ): void {
        try {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( $message, [ 'trace_id' => $trace_id, 'kind' => $kind ] );
            }
        } catch ( \Throwable $t ) {
            // Logging must never break generation.
        }
    }
}
