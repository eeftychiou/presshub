<?php
/**
 * PressHub_AI_Generation_Inspector - read-only review of generation records.
 *
 * Lets editors review everything that went into a briefing / podcast generation:
 * settings, system and user prompt, input articles (sent and dropped), memory,
 * response and TTS configuration. Records come from PressHub_AI_Generation_Recorder
 * (Issue #131); this class never writes to them.
 *
 * Surfaces:
 *   - "Generation Inspector" metabox on Daily Briefing (text) and podcast posts.
 *   - "Generation records" submenu under Daily Briefing Hub (filterable list).
 *
 * Security: every endpoint checks the nonce and the `edit_others_posts`
 * capability. Records contain scraped third-party text, so every value is
 * escaped on output, and the large text fields (prompts, response) are sent as
 * JSON data that the client inserts with textContent, never as HTML.
 *
 * Performance: the edit screen only carries the record selector; each tab is
 * fetched through AJAX when it is opened.
 *
 * @package PressHub_AI_Editor
 * @since 2.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Generation_Inspector {

    /** Capability required for every inspector surface and endpoint. */
    const CAPABILITY = 'edit_others_posts';

    /** Nonce action and request field shared by the AJAX endpoints. */
    const NONCE_ACTION = 'presshub_ai_nonce';

    const AJAX_TAB   = 'presshub_ai_inspector_tab';
    const AJAX_FIELD = 'presshub_ai_inspector_field';
    const AJAX_RAW   = 'presshub_ai_inspector_raw';

    const METABOX_ID  = 'presshub_ai_generation_inspector';
    const PAGE_SLUG   = 'presshub-ai-generation-records';
    const SCRIPT_HANDLE = 'presshub-ai-generation-inspector';
    const STYLE_HANDLE  = 'presshub-ai-generation-inspector-css';

    /** Large text columns served one at a time through the field loader. */
    const TEXT_FIELDS = [ 'system_prompt', 'user_prompt', 'response_text' ];

    /** Tabs rendered server-side as escaped HTML. */
    const HTML_TABS = [ 'overview', 'articles', 'memory', 'tts' ];

    /** Post meta `_presshub_briefing_type` values that get the metabox. */
    const POST_TYPES = [ 'text', 'podcast' ];

    /** Maximum rows in the admin list. */
    const LIST_LIMIT = 200;

    /** Light columns (no prompts/response) used by the selector and the list. */
    const LIGHT_COLUMNS = 'id, created_at, trace_id, kind, briefing_date, edition, post_id, status, error_message, duration_ms';

    /** JSON columns decoded for the structured tabs. */
    const JSON_COLUMNS = 'config_json, inputs_json, memory_json, tts_json, usage_json';

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_metabox' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_' . self::AJAX_TAB, [ $this, 'ajax_tab' ] );
        add_action( 'wp_ajax_' . self::AJAX_FIELD, [ $this, 'ajax_field' ] );
        add_action( 'wp_ajax_' . self::AJAX_RAW, [ $this, 'ajax_raw' ] );
    }

    // ---------------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------------

    public static function can_view(): bool {
        return current_user_can( self::CAPABILITY );
    }

    /**
     * Verify nonce then capability for an AJAX request. A bad nonce dies
     * inside check_ajax_referer(); a missing capability returns a JSON 403.
     */
    private function guard_ajax(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! self::can_view() ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to view generation records.', 'presshub-ai-editor' ) ], 403 );
        }
    }

    // ---------------------------------------------------------------------
    // Metabox registration and assets
    // ---------------------------------------------------------------------

    public static function is_inspectable_post( int $post_id ): bool {
        if ( $post_id <= 0 ) {
            return false;
        }
        return in_array( (string) get_post_meta( $post_id, '_presshub_briefing_type', true ), self::POST_TYPES, true );
    }

    public function add_metabox( $post_type, $post = null ): void {
        if ( 'post' !== $post_type || ! is_object( $post ) || ! self::can_view() ) {
            return;
        }
        if ( ! self::is_inspectable_post( (int) $post->ID ) ) {
            return;
        }
        add_meta_box(
            self::METABOX_ID,
            __( 'Generation Inspector', 'presshub-ai-editor' ),
            [ $this, 'render_metabox' ],
            'post',
            'normal',
            'default'
        );
    }

    public function enqueue_assets( $hook ): void {
        $hook = (string) $hook;
        $load = false;
        if ( 'post.php' === $hook ) {
            $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
            $load    = self::is_inspectable_post( $post_id );
        } elseif ( false !== strpos( $hook, self::PAGE_SLUG ) ) {
            $load = true;
        }
        if ( ! $load || ! self::can_view() ) {
            return;
        }

        wp_enqueue_style( self::STYLE_HANDLE, PRESSHUB_AI_URL . 'assets/generation-inspector.css', [], PRESSHUB_AI_VERSION );
        wp_enqueue_script( self::SCRIPT_HANDLE, PRESSHUB_AI_URL . 'assets/generation-inspector.js', [ 'jquery' ], PRESSHUB_AI_VERSION, true );
        wp_localize_script( self::SCRIPT_HANDLE, 'presshubInspector', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
            'actions'  => [
                'tab'   => self::AJAX_TAB,
                'field' => self::AJAX_FIELD,
            ],
            'i18n'     => [
                'loading'      => __( 'Loading...', 'presshub-ai-editor' ),
                'error'        => __( 'Could not load this section.', 'presshub-ai-editor' ),
                'copy'         => __( 'Copy', 'presshub-ai-editor' ),
                'copied'       => __( 'Copied', 'presshub-ai-editor' ),
                'copy_failed'  => __( 'Copy failed', 'presshub-ai-editor' ),
                'characters'   => __( '%s characters', 'presshub-ai-editor' ),
                'empty'        => __( '(empty)', 'presshub-ai-editor' ),
                'raw_intro'    => __( 'Download the complete record (settings, inputs, memory, prompts, response, usage, TTS) as JSON.', 'presshub-ai-editor' ),
                'raw_download' => __( 'Download raw JSON', 'presshub-ai-editor' ),
            ],
        ] );
    }

    // ---------------------------------------------------------------------
    // Data access (read-only)
    // ---------------------------------------------------------------------

    /**
     * Light record row (no prompts/response/JSON blobs) or null.
     */
    public static function get_light_row( int $id ): ?array {
        global $wpdb;
        if ( $id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return null;
        }
        $table = PressHub_AI_Generation_Recorder::get_table_name();
        $row   = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::LIGHT_COLUMNS . " FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        if ( ! is_array( $row ) || empty( $row ) ) {
            return null;
        }
        return self::normalize_light_row( $row );
    }

    private static function normalize_light_row( array $row ): array {
        $row['id']          = (int) ( $row['id'] ?? 0 );
        $row['post_id']     = (int) ( $row['post_id'] ?? 0 );
        $row['duration_ms'] = (int) ( $row['duration_ms'] ?? 0 );
        foreach ( [ 'created_at', 'trace_id', 'kind', 'briefing_date', 'edition', 'status' ] as $key ) {
            $row[ $key ] = (string) ( $row[ $key ] ?? '' );
        }
        $row['error_message'] = (string) ( $row['error_message'] ?? '' );
        return $row;
    }

    /**
     * Record with decoded JSON fields but WITHOUT the large text columns.
     */
    public static function get_structured_record( int $id ): ?array {
        global $wpdb;
        $light = self::get_light_row( $id );
        if ( null === $light ) {
            return null;
        }
        $table = PressHub_AI_Generation_Recorder::get_table_name();
        $row   = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::JSON_COLUMNS . " FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        $row   = is_array( $row ) ? $row : [];
        foreach ( PressHub_AI_Generation_Recorder::JSON_COLUMNS as $column => $key ) {
            $decoded = null;
            if ( isset( $row[ $column ] ) && '' !== (string) $row[ $column ] ) {
                $decoded = json_decode( (string) $row[ $column ], true );
            }
            $light[ $key ] = is_array( $decoded ) ? $decoded : ( 'tts' === $key ? null : [] );
        }
        return $light;
    }

    /**
     * One large text column. $field must be one of TEXT_FIELDS (callers validate).
     */
    public static function get_text_field( int $id, string $field ): ?string {
        global $wpdb;
        if ( $id <= 0 || ! in_array( $field, self::TEXT_FIELDS, true ) || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return null;
        }
        $table = PressHub_AI_Generation_Recorder::get_table_name();
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT {$field} FROM {$table} WHERE id = %d", $id ) );
        return null === $value ? '' : (string) $value;
    }

    /**
     * Ids attached to a post (clean int list, no duplicates).
     *
     * @return int[]
     */
    public static function get_post_record_ids( int $post_id ): array {
        $raw = get_post_meta( $post_id, PressHub_AI_Generation_Recorder::POST_META_KEY, true );
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
     * Light rows for a post, newest first.
     */
    public static function get_post_records( int $post_id ): array {
        $rows = [];
        foreach ( self::get_post_record_ids( $post_id ) as $id ) {
            $row = self::get_light_row( $id );
            if ( null !== $row ) {
                $rows[] = $row;
            }
        }
        usort( $rows, static function ( $a, $b ) {
            $cmp = strcmp( $b['created_at'], $a['created_at'] );
            return 0 !== $cmp ? $cmp : $b['id'] <=> $a['id'];
        } );
        return $rows;
    }

    /**
     * Sanitize list filters: date_from/date_to (YYYY-MM-DD), kind, status.
     * Invalid values are dropped (never passed to SQL).
     */
    public static function sanitize_filters( array $input ): array {
        $filters = [];
        foreach ( [ 'date_from', 'date_to' ] as $key ) {
            $value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
                $filters[ $key ] = $value;
            }
        }
        $kind = isset( $input['kind'] ) ? (string) $input['kind'] : '';
        if ( in_array( $kind, PressHub_AI_Generation_Recorder::KINDS, true ) ) {
            $filters['kind'] = $kind;
        }
        $status = isset( $input['status'] ) ? (string) $input['status'] : '';
        if ( in_array( $status, [ 'success', 'error' ], true ) ) {
            $filters['status'] = $status;
        }
        return $filters;
    }

    /**
     * Filtered list of light rows (plus provider/model from config_json), newest first.
     *
     * @param array $filters See sanitize_filters().
     */
    public static function query_records( array $filters = [], int $limit = self::LIST_LIMIT ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return [];
        }
        $filters = self::sanitize_filters( $filters );
        $table   = PressHub_AI_Generation_Recorder::get_table_name();

        $where = [];
        if ( isset( $filters['date_from'] ) ) {
            $where[] = $wpdb->prepare( 'briefing_date >= %s', $filters['date_from'] );
        }
        if ( isset( $filters['date_to'] ) ) {
            $where[] = $wpdb->prepare( 'briefing_date <= %s', $filters['date_to'] );
        }
        if ( isset( $filters['kind'] ) ) {
            $where[] = $wpdb->prepare( 'kind = %s', $filters['kind'] );
        }
        if ( isset( $filters['status'] ) ) {
            $where[] = $wpdb->prepare( 'status = %s', $filters['status'] );
        }

        $sql  = 'SELECT ' . self::LIGHT_COLUMNS . ', config_json FROM ' . $table;
        $sql .= $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max( 1, (int) $limit );

        $results = $wpdb->get_results( $sql, ARRAY_A );
        $rows    = [];
        foreach ( is_array( $results ) ? $results : [] as $row ) {
            $config = ( isset( $row['config_json'] ) && '' !== (string) $row['config_json'] ) ? json_decode( (string) $row['config_json'], true ) : [];
            $config = is_array( $config ) ? $config : [];
            unset( $row['config_json'] );
            $row          = self::normalize_light_row( $row );
            $row['model'] = (string) ( $config['model'] ?? '' );
            $rows[]       = $row;
        }
        return $rows;
    }

    // ---------------------------------------------------------------------
    // AJAX endpoints
    // ---------------------------------------------------------------------

    /** Structured tab (escaped HTML fragment). */
    public function ajax_tab(): void {
        $this->guard_ajax();
        $tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! in_array( $tab, self::HTML_TABS, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown tab.', 'presshub-ai-editor' ) ], 400 );
        }
        $record = self::get_structured_record( $this->requested_record_id() );
        if ( null === $record ) {
            wp_send_json_error( [ 'message' => __( 'Generation record not found.', 'presshub-ai-editor' ) ], 404 );
        }
        wp_send_json_success( [ 'tab' => $tab, 'html' => self::render_tab( $record, $tab ) ] );
    }

    /** One large text field as JSON data (client inserts with textContent). */
    public function ajax_field(): void {
        $this->guard_ajax();
        $field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! in_array( $field, self::TEXT_FIELDS, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown field.', 'presshub-ai-editor' ) ], 400 );
        }
        $id = $this->requested_record_id();
        if ( null === self::get_light_row( $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Generation record not found.', 'presshub-ai-editor' ) ], 404 );
        }
        $value = (string) self::get_text_field( $id, $field );
        wp_send_json_success( [
            'field' => $field,
            'value' => $value,
            'chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value ),
        ] );
    }

    /** Raw JSON download of the complete record. */
    public function ajax_raw(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! self::can_view() ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'presshub-ai-editor' ), 403 );
        }
        $json = self::build_raw_json( $this->requested_record_id( $_GET ) );
        if ( null === $json ) {
            wp_die( esc_html__( 'Generation record not found.', 'presshub-ai-editor' ), 404 );
        }
        $id = $this->requested_record_id( $_GET );
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="presshub-generation-record-' . $id . '.json"' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download, served as attachment.
        exit;
    }

    /** Full record as pretty JSON, or null when missing. */
    public static function build_raw_json( int $id ): ?string {
        $record = PressHub_AI_Generation_Recorder::get( $id );
        if ( null === $record ) {
            return null;
        }
        $json = wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
        return false === $json ? null : $json;
    }

    private function requested_record_id( ?array $source = null ): int {
        $source = $source ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification
        return isset( $source['record_id'] ) ? max( 0, (int) $source['record_id'] ) : 0;
    }

    // ---------------------------------------------------------------------
    // Rendering helpers (every value escaped)
    // ---------------------------------------------------------------------

    public static function kind_label( string $kind ): string {
        $labels = [
            'briefing_text'  => __( 'Briefing text', 'presshub-ai-editor' ),
            'podcast_script' => __( 'Podcast script', 'presshub-ai-editor' ),
            'memory_digest'  => __( 'Memory digest', 'presshub-ai-editor' ),
            'memory_rollup'  => __( 'Memory rollup', 'presshub-ai-editor' ),
        ];
        return $labels[ $kind ] ?? $kind;
    }

    private static function fmt( $value ): string {
        if ( null === $value || '' === $value ) {
            return "\u{2014}";
        }
        if ( is_bool( $value ) ) {
            return $value ? __( 'yes', 'presshub-ai-editor' ) : __( 'no', 'presshub-ai-editor' );
        }
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }
        $json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return false === $json ? '' : $json;
    }

    /** Escaped value for a table cell. */
    private static function cell( $value ): string {
        return esc_html( self::fmt( $value ) );
    }

    /** Key/value table; $rows = [ label => value ]; a value of [ 'html' => ... ] is pre-escaped. */
    private static function kv_table( array $rows ): string {
        $html = '<table class="widefat striped presshub-inspector-kv"><tbody>';
        foreach ( $rows as $label => $value ) {
            $cell  = ( is_array( $value ) && isset( $value['html'] ) ) ? (string) $value['html'] : self::cell( $value );
            $html .= '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . $cell . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function section_heading( string $text ): string {
        return '<h4>' . esc_html( $text ) . '</h4>';
    }

    private static function notice( string $text ): string {
        return '<p class="presshub-inspector-notice">' . esc_html( $text ) . '</p>';
    }

    /** Link to a post edit screen, or plain escaped text when there is no link. */
    private static function post_link_html( int $post_id ): string {
        if ( $post_id <= 0 ) {
            return esc_html( "\u{2014}" );
        }
        $label = '#' . $post_id;
        if ( function_exists( 'get_the_title' ) ) {
            $title = trim( (string) get_the_title( $post_id ) );
            if ( '' !== $title ) {
                $label = $title . ' (#' . $post_id . ')';
            }
        }
        $url = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '';
        if ( '' === $url ) {
            return esc_html( $label );
        }
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }

    /** Link to an external article URL only when it is http(s); otherwise plain text. */
    private static function article_title_html( string $title, string $url ): string {
        $title = '' !== trim( $title ) ? $title : "\u{2014}";
        if ( preg_match( '#^https?://#i', $url ) ) {
            return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( $title ) . '</a>';
        }
        return esc_html( $title );
    }

    public static function render_tab( array $record, string $tab ): string {
        switch ( $tab ) {
            case 'overview':
                return self::render_overview( $record );
            case 'articles':
                return self::render_articles( $record );
            case 'memory':
                return self::render_memory( $record );
            case 'tts':
                return self::render_tts( $record );
        }
        return '';
    }

    private static function render_overview( array $record ): string {
        $config = is_array( $record['config'] ?? null ) ? $record['config'] : [];
        $inputs = is_array( $record['inputs'] ?? null ) ? $record['inputs'] : [];
        $usage  = is_array( $record['usage'] ?? null ) ? $record['usage'] : [];
        $totals = is_array( $inputs['totals'] ?? null ) ? $inputs['totals'] : [];

        $provider = trim( self::fmt( $config['provider_id'] ?? ( $config['provider_type'] ?? '' ) ) );
        if ( isset( $config['provider_type'], $config['provider_id'] ) && '' !== (string) $config['provider_type'] ) {
            $provider .= ' (' . $config['provider_type'] . ')';
        }
        $status = $record['status'];
        if ( '' !== $record['error_message'] ) {
            $status .= ': ' . $record['error_message'];
        }

        $general = [
            __( 'Kind', 'presshub-ai-editor' )       => self::kind_label( $record['kind'] ),
            __( 'Briefing date', 'presshub-ai-editor' ) => $record['briefing_date'],
            __( 'Edition', 'presshub-ai-editor' )    => $record['edition'],
            __( 'Generated at', 'presshub-ai-editor' ) => $record['created_at'],
            __( 'Status', 'presshub-ai-editor' )     => $status,
            __( 'Duration', 'presshub-ai-editor' )   => $record['duration_ms'] . ' ms',
            __( 'Trace ID', 'presshub-ai-editor' )   => $record['trace_id'],
            __( 'Post', 'presshub-ai-editor' )       => [ 'html' => self::post_link_html( (int) $record['post_id'] ) ],
        ];

        $settings = [
            __( 'Provider', 'presshub-ai-editor' )        => $provider,
            __( 'Provider source', 'presshub-ai-editor' ) => $config['provider_source'] ?? '',
            __( 'Model', 'presshub-ai-editor' )           => $config['model'] ?? '',
            __( 'Model source', 'presshub-ai-editor' )    => $config['model_source'] ?? '',
            __( 'Temperature', 'presshub-ai-editor' )     => $config['temperature'] ?? '',
            __( 'Max tokens', 'presshub-ai-editor' )      => $config['max_tokens'] ?? '',
            __( 'Timeout (s)', 'presshub-ai-editor' )     => $config['timeout'] ?? '',
            __( 'Preset', 'presshub-ai-editor' )          => $config['preset_id'] ?? '',
        ];
        foreach ( [ 'module', 'action', 'context_mode', 'articles_placement', 'duration', 'style' ] as $extra ) {
            if ( isset( $config[ $extra ] ) && '' !== (string) self::fmt( $config[ $extra ] ) ) {
                $settings[ ucfirst( str_replace( '_', ' ', $extra ) ) ] = $config[ $extra ];
            }
        }

        $token_estimate = $inputs['token_estimate'] ?? ( $totals['capped_tokens_estimate'] ?? '' );
        $usage_rows     = [
            __( 'Prompt tokens', 'presshub-ai-editor' )     => $usage['prompt_tokens'] ?? '',
            __( 'Completion tokens', 'presshub-ai-editor' ) => $usage['completion_tokens'] ?? '',
            __( 'Total tokens', 'presshub-ai-editor' )      => $usage['total_tokens'] ?? '',
            __( 'Input token estimate', 'presshub-ai-editor' ) => $token_estimate,
        ];
        if ( isset( $totals['pool_tokens_estimate'] ) ) {
            $usage_rows[ __( 'Pool token estimate (before caps)', 'presshub-ai-editor' ) ] = $totals['pool_tokens_estimate'];
        }
        foreach ( [ 'finish_reason', 'truncated', 'response_truncated' ] as $flag ) {
            if ( array_key_exists( $flag, $usage ) ) {
                $usage_rows[ ucfirst( str_replace( '_', ' ', $flag ) ) ] = $usage[ $flag ];
            }
        }
        $usage_rows[ __( 'Articles truncated (count or per-article cap)', 'presshub-ai-editor' ) ] = $totals['articles_truncated'] ?? '';

        return self::section_heading( __( 'Generation', 'presshub-ai-editor' ) ) . self::kv_table( $general )
            . self::section_heading( __( 'Request settings (as resolved)', 'presshub-ai-editor' ) ) . self::kv_table( $settings )
            . self::section_heading( __( 'Usage and truncation', 'presshub-ai-editor' ) ) . self::kv_table( $usage_rows );
    }

    /**
     * Normalise the two stored article shapes:
     * briefing: inputs.articles = { sent: [], dropped: [] }
     * podcast:  inputs.articles = [ ... ] and inputs.not_selected = [ ... ]
     *
     * @return array{0:array,1:array}
     */
    public static function split_articles( array $inputs ): array {
        $articles = $inputs['articles'] ?? [];
        if ( ! is_array( $articles ) ) {
            return [ [], [] ];
        }
        if ( array_key_exists( 'sent', $articles ) || array_key_exists( 'dropped', $articles ) ) {
            $sent    = is_array( $articles['sent'] ?? null ) ? $articles['sent'] : [];
            $dropped = is_array( $articles['dropped'] ?? null ) ? $articles['dropped'] : [];
        } else {
            $sent    = $articles;
            $dropped = [];
            if ( is_array( $inputs['not_selected'] ?? null ) ) {
                foreach ( $inputs['not_selected'] as $entry ) {
                    if ( is_array( $entry ) ) {
                        $entry['reason'] = $entry['reason'] ?? 'not_selected';
                        $dropped[]       = $entry;
                    }
                }
            }
        }
        return [ array_values( array_filter( $sent, 'is_array' ) ), array_values( array_filter( $dropped, 'is_array' ) ) ];
    }

    private static function render_articles( array $record ): string {
        $inputs = is_array( $record['inputs'] ?? null ) ? $record['inputs'] : [];
        $totals = is_array( $inputs['totals'] ?? null ) ? $inputs['totals'] : [];
        list( $sent, $dropped ) = self::split_articles( $inputs );

        $html = '';
        if ( ! empty( $totals ) ) {
            $html .= self::section_heading( __( 'Caps and totals', 'presshub-ai-editor' ) ) . self::kv_table( [
                __( 'Max articles (cap)', 'presshub-ai-editor' )         => $totals['cap_articles'] ?? '',
                __( 'Max characters per article (cap)', 'presshub-ai-editor' ) => $totals['cap_chars_per_article'] ?? '',
                __( 'Articles in pool', 'presshub-ai-editor' )           => $totals['articles_count_original'] ?? '',
                __( 'Articles sent', 'presshub-ai-editor' )              => $totals['articles_count'] ?? count( $sent ),
            ] );
        }
        if ( isset( $inputs['context_mode'] ) || isset( $inputs['context_used'] ) ) {
            $html .= self::kv_table( [
                __( 'Context mode', 'presshub-ai-editor' ) => $inputs['context_mode'] ?? '',
                __( 'Context used', 'presshub-ai-editor' ) => $inputs['context_used'] ?? '',
                __( 'Articles in system prompt', 'presshub-ai-editor' ) => $inputs['articles_in_system_prompt'] ?? '',
            ] );
        }

        $html .= self::section_heading( sprintf( /* translators: %d: number of articles */ __( 'Articles sent (%d)', 'presshub-ai-editor' ), count( $sent ) ) );
        if ( empty( $sent ) ) {
            $html .= self::notice( __( 'No article inputs were recorded for this generation.', 'presshub-ai-editor' ) );
        } else {
            $html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'presshub-ai-editor' ) . '</th><th>'
                . esc_html__( 'Source', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Characters sent', 'presshub-ai-editor' ) . '</th><th>'
                . esc_html__( 'Truncated', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
            foreach ( $sent as $article ) {
                $html .= '<tr><td>' . self::article_title_html( self::fmt_plain( $article['title'] ?? '' ), self::fmt_plain( $article['url'] ?? '' ) ) . '</td><td>'
                    . self::cell( $article['source'] ?? '' ) . '</td><td>' . self::cell( $article['chars_sent'] ?? '' ) . '</td><td>'
                    . self::cell( $article['truncated'] ?? '' ) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= self::section_heading( sprintf( __( 'Articles dropped (%d)', 'presshub-ai-editor' ), count( $dropped ) ) );
        if ( empty( $dropped ) ) {
            $html .= self::notice( __( 'No articles were dropped.', 'presshub-ai-editor' ) );
        } else {
            $reasons = [
                'cap_articles' => __( 'Over the maximum article count (cap)', 'presshub-ai-editor' ),
                'not_selected' => __( 'Not selected by the editor', 'presshub-ai-editor' ),
                'duplicate'    => __( 'Duplicate', 'presshub-ai-editor' ),
            ];
            $html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'presshub-ai-editor' ) . '</th><th>'
                . esc_html__( 'Source', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Reason', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
            foreach ( $dropped as $article ) {
                $reason = self::fmt_plain( $article['reason'] ?? '' );
                $html  .= '<tr><td>' . self::article_title_html( self::fmt_plain( $article['title'] ?? '' ), self::fmt_plain( $article['url'] ?? '' ) ) . '</td><td>'
                    . self::cell( $article['source'] ?? '' ) . '</td><td>' . esc_html( $reasons[ $reason ] ?? $reason ) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html;
    }

    /** Scalar-as-string for values that feed a helper expecting a string. */
    private static function fmt_plain( $value ): string {
        return is_scalar( $value ) ? (string) $value : '';
    }

    /** Memory editor URL for an entry; empty unless the memory editor (#137) supplies one via the filter. */
    private static function memory_editor_url( $entry_id ): string {
        $url = apply_filters( 'presshub_ai_memory_editor_url', '', $entry_id );
        return is_string( $url ) ? $url : '';
    }

    private static function render_memory_entries( array $entries ): string {
        $html = '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Entry', 'presshub-ai-editor' ) . '</th><th>'
            . esc_html__( 'Tier', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Period', 'presshub-ai-editor' ) . '</th><th>'
            . esc_html__( 'Edition', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Characters', 'presshub-ai-editor' ) . '</th><th>'
            . esc_html__( 'Origin', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Status', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                $entry = [ 'id' => $entry ];
            }
            $id    = $entry['id'] ?? ( $entry['entry_id'] ?? '' );
            $label = self::fmt( $id );
            $url   = '' !== self::fmt_plain( $id ) ? self::memory_editor_url( $id ) : '';
            $id_html = '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( '#' . $label ) . '</a>' : esc_html( '#' . $label );
            if ( isset( $entry['reason'] ) ) {
                $id_html .= ' <em>(' . esc_html( self::fmt( $entry['reason'] ) ) . ')</em>';
            }
            $period = $entry['period'] ?? '';
            if ( '' === $period && isset( $entry['period_start'] ) ) {
                $period = $entry['period_start'] . ( isset( $entry['period_end'] ) && $entry['period_end'] !== $entry['period_start'] ? ' / ' . $entry['period_end'] : '' );
            }
            $html .= '<tr><td>' . $id_html . '</td><td>' . self::cell( $entry['tier'] ?? ( $entry['scope'] ?? '' ) ) . '</td><td>'
                . self::cell( $period ) . '</td><td>' . self::cell( $entry['edition'] ?? '' ) . '</td><td>'
                . self::cell( $entry['chars'] ?? ( $entry['characters'] ?? '' ) ) . '</td><td>' . self::cell( $entry['origin'] ?? '' ) . '</td><td>'
                . self::cell( $entry['status'] ?? '' ) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function render_memory( array $record ): string {
        $memory = is_array( $record['memory'] ?? null ) ? $record['memory'] : [];
        if ( empty( $memory['enabled'] ) ) {
            return self::notice( __( 'Memory was disabled for this generation.', 'presshub-ai-editor' ) );
        }

        $entries = is_array( $memory['entries'] ?? null ) ? $memory['entries'] : [];
        $trimmed = is_array( $memory['trimmed'] ?? null ) ? $memory['trimmed'] : [];
        $html    = '';

        if ( empty( $entries ) ) {
            $html .= self::notice( __( 'Memory was enabled but no entries were included in this generation (memory was empty).', 'presshub-ai-editor' ) );
        } else {
            $html .= self::section_heading( sprintf( __( 'Entries included (%d)', 'presshub-ai-editor' ), count( $entries ) ) )
                . self::render_memory_entries( $entries );
        }
        if ( ! empty( $trimmed ) ) {
            $html .= self::section_heading( sprintf( __( 'Entries trimmed (%d)', 'presshub-ai-editor' ), count( $trimmed ) ) )
                . self::render_memory_entries( $trimmed );
        }

        $budget_rows = [];
        if ( is_array( $memory['budgets'] ?? null ) ) {
            foreach ( $memory['budgets'] as $key => $value ) {
                $budget_rows[ ucfirst( str_replace( '_', ' ', (string) $key ) ) ] = $value;
            }
        }
        foreach ( [ 'budget_chars', 'used_chars', 'total_chars' ] as $key ) {
            if ( isset( $memory[ $key ] ) && is_scalar( $memory[ $key ] ) ) {
                $budget_rows[ ucfirst( str_replace( '_', ' ', $key ) ) ] = $memory[ $key ];
            }
        }
        if ( ! empty( $budget_rows ) ) {
            $html .= self::section_heading( __( 'Budgets', 'presshub-ai-editor' ) ) . self::kv_table( $budget_rows );
        }
        return $html;
    }

    private static function render_tts( array $record ): string {
        if ( 'podcast_script' !== $record['kind'] ) {
            return self::notice( __( 'TTS applies to podcast script records only.', 'presshub-ai-editor' ) );
        }
        $tts = $record['tts'] ?? null;
        if ( ! is_array( $tts ) || empty( $tts ) ) {
            return self::notice( __( 'No TTS configuration recorded: audio has not been synthesized from this script yet.', 'presshub-ai-editor' ) );
        }
        $voices = '';
        if ( is_array( $tts['voices'] ?? null ) ) {
            $parts = [];
            foreach ( $tts['voices'] as $role => $voice ) {
                if ( '' !== self::fmt_plain( $voice ) ) {
                    $parts[] = $role . ': ' . $voice;
                }
            }
            $voices = implode( ', ', $parts );
        }
        return self::kv_table( [
            __( 'Engine', 'presshub-ai-editor' )        => $tts['engine'] ?? '',
            __( 'Model', 'presshub-ai-editor' )         => $tts['model'] ?? '',
            __( 'Payload mode', 'presshub-ai-editor' )  => $tts['payload_mode'] ?? '',
            __( 'Voices', 'presshub-ai-editor' )        => $voices,
            __( 'Hosts', 'presshub-ai-editor' )         => $tts['host_count'] ?? '',
            __( 'Style', 'presshub-ai-editor' )         => $tts['style'] ?? '',
            __( 'Speed', 'presshub-ai-editor' )         => $tts['speed'] ?? '',
            __( 'Pitch', 'presshub-ai-editor' )         => $tts['pitch'] ?? '',
            __( 'Turn pause (ms)', 'presshub-ai-editor' )  => $tts['turn_pause_ms'] ?? '',
            __( 'Topic pause (ms)', 'presshub-ai-editor' ) => $tts['topic_pause_ms'] ?? '',
            __( 'Split by topic', 'presshub-ai-editor' )   => $tts['split_by_topic'] ?? '',
            __( 'Topics', 'presshub-ai-editor' )        => $tts['topic_count'] ?? '',
            __( 'Turns', 'presshub-ai-editor' )         => $tts['turns_count'] ?? '',
            __( 'Script source', 'presshub-ai-editor' ) => $tts['script_source'] ?? '',
            __( 'Synthesized at', 'presshub-ai-editor' ) => $tts['synthesized_at'] ?? '',
        ] );
    }

    // ---------------------------------------------------------------------
    // Inspector component (metabox + records page)
    // ---------------------------------------------------------------------

    private static function raw_url( int $record_id ): string {
        return add_query_arg(
            [
                'action'    => self::AJAX_RAW,
                'record_id' => $record_id,
                'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
            ],
            admin_url( 'admin-ajax.php' )
        );
    }

    /**
     * Render the inspector shell for a list of light rows (newest first).
     * Tab content and large fields are loaded through AJAX.
     */
    public static function render_inspector( array $rows, int $selected_id = 0 ): string {
        if ( empty( $rows ) ) {
            return '<p class="presshub-inspector-empty">' . esc_html__( 'No generation record (generated before records were introduced)', 'presshub-ai-editor' ) . '</p>';
        }
        $selected = $rows[0];
        foreach ( $rows as $row ) {
            if ( (int) $row['id'] === $selected_id ) {
                $selected = $row;
                break;
            }
        }

        $html  = '<div class="presshub-inspector" data-record-id="' . esc_attr( (string) $selected['id'] ) . '">';
        $html .= '<p class="presshub-inspector-bar"><label>' . esc_html__( 'Record', 'presshub-ai-editor' ) . ' <select class="presshub-inspector-select">';
        foreach ( $rows as $row ) {
            $label = sprintf( '%s - %s - %s', $row['created_at'], self::kind_label( $row['kind'] ), $row['status'] );
            $html .= '<option value="' . esc_attr( (string) $row['id'] ) . '" data-kind="' . esc_attr( $row['kind'] ) . '" data-raw-url="'
                . esc_url( self::raw_url( (int) $row['id'] ) ) . '"' . ( (int) $row['id'] === (int) $selected['id'] ? ' selected="selected"' : '' )
                . '>' . esc_html( $label ) . '</option>';
        }
        $html .= '</select></label></p>';

        $tabs = [
            'overview'      => __( 'Overview', 'presshub-ai-editor' ),
            'system_prompt' => __( 'System prompt', 'presshub-ai-editor' ),
            'user_prompt'   => __( 'User prompt', 'presshub-ai-editor' ),
            'articles'      => __( 'Articles', 'presshub-ai-editor' ),
            'memory'        => __( 'Memory', 'presshub-ai-editor' ),
            'response_text' => __( 'Response', 'presshub-ai-editor' ),
            'tts'           => __( 'TTS', 'presshub-ai-editor' ),
            'raw'           => __( 'Raw JSON', 'presshub-ai-editor' ),
        ];
        $html .= '<div class="presshub-inspector-tabs" role="tablist">';
        foreach ( $tabs as $key => $label ) {
            $hidden = ( 'tts' === $key && 'podcast_script' !== $selected['kind'] ) ? ' hidden="hidden"' : '';
            $html  .= '<button type="button" class="button presshub-inspector-tab" role="tab" data-tab="' . esc_attr( $key ) . '"' . $hidden . '>' . esc_html( $label ) . '</button>';
        }
        $html .= '</div><div class="presshub-inspector-panel" role="tabpanel" aria-live="polite"></div></div>';
        return $html;
    }

    public function render_metabox( $post ): void {
        if ( ! self::can_view() ) {
            return;
        }
        echo self::render_inspector( self::get_post_records( (int) $post->ID ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_inspector().
    }

    // ---------------------------------------------------------------------
    // Briefing Admin: "Generation records" page
    // ---------------------------------------------------------------------

    public static function render_records_page(): void {
        if ( ! self::can_view() ) {
            wp_die( esc_html__( 'You do not have permission to view generation records.', 'presshub-ai-editor' ) );
        }
        echo self::build_records_page( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput -- read-only filters, escaped in build_records_page().
    }

    public static function build_records_page( array $query ): string {
        $filters   = self::sanitize_filters( $query );
        $record_id = isset( $query['record_id'] ) ? max( 0, (int) $query['record_id'] ) : 0;
        $rows      = self::query_records( $filters );
        $base      = admin_url( 'admin.php' );

        $html  = '<div class="wrap presshub-generation-records"><h1>' . esc_html__( 'Generation records', 'presshub-ai-editor' ) . '</h1>';
        $html .= '<form method="get" action="' . esc_url( $base ) . '" class="presshub-records-filters">';
        $html .= '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
        $html .= '<label>' . esc_html__( 'From', 'presshub-ai-editor' ) . ' <input type="date" name="date_from" value="' . esc_attr( $filters['date_from'] ?? '' ) . '" /></label> ';
        $html .= '<label>' . esc_html__( 'To', 'presshub-ai-editor' ) . ' <input type="date" name="date_to" value="' . esc_attr( $filters['date_to'] ?? '' ) . '" /></label> ';
        $html .= '<label>' . esc_html__( 'Kind', 'presshub-ai-editor' ) . ' <select name="kind"><option value="">' . esc_html__( 'All', 'presshub-ai-editor' ) . '</option>';
        foreach ( PressHub_AI_Generation_Recorder::KINDS as $kind ) {
            $html .= '<option value="' . esc_attr( $kind ) . '"' . ( ( $filters['kind'] ?? '' ) === $kind ? ' selected="selected"' : '' ) . '>' . esc_html( self::kind_label( $kind ) ) . '</option>';
        }
        $html .= '</select></label> <label>' . esc_html__( 'Status', 'presshub-ai-editor' ) . ' <select name="status"><option value="">' . esc_html__( 'All', 'presshub-ai-editor' ) . '</option>';
        foreach ( [ 'success', 'error' ] as $status ) {
            $html .= '<option value="' . esc_attr( $status ) . '"' . ( ( $filters['status'] ?? '' ) === $status ? ' selected="selected"' : '' ) . '>' . esc_html( $status ) . '</option>';
        }
        $html .= '</select></label> <button type="submit" class="button">' . esc_html__( 'Filter', 'presshub-ai-editor' ) . '</button></form>';

        if ( $record_id > 0 ) {
            $selected = self::get_light_row( $record_id );
            $html    .= '<div class="presshub-records-detail"><h2>' . esc_html( sprintf( /* translators: %d: record ID */ __( 'Record #%d', 'presshub-ai-editor' ), $record_id ) ) . '</h2>';
            $html    .= null === $selected
                ? '<p>' . esc_html__( 'Generation record not found.', 'presshub-ai-editor' ) . '</p>'
                : self::render_inspector( [ $selected ], $record_id );
            $html    .= '</div>';
        }

        if ( empty( $rows ) ) {
            $html .= '<p class="presshub-inspector-empty">' . esc_html__( 'No generation records match these filters.', 'presshub-ai-editor' ) . '</p>';
        } else {
            $html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'presshub-ai-editor' ) . '</th><th>'
                . esc_html__( 'Kind', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Edition', 'presshub-ai-editor' ) . '</th><th>'
                . esc_html__( 'Status', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Post', 'presshub-ai-editor' ) . '</th><th>'
                . esc_html__( 'Model', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Inspect', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $open_url = add_query_arg( array_merge( $filters, [ 'page' => self::PAGE_SLUG, 'record_id' => $row['id'] ] ), $base );
                $html    .= '<tr><td>' . esc_html( '' !== $row['briefing_date'] ? $row['briefing_date'] : $row['created_at'] ) . '</td><td>'
                    . esc_html( self::kind_label( $row['kind'] ) ) . '</td><td>' . self::cell( $row['edition'] ) . '</td><td>'
                    . esc_html( $row['status'] ) . '</td><td>' . self::post_link_html( (int) $row['post_id'] ) . '</td><td>'
                    . self::cell( $row['model'] ) . '</td><td><a href="' . esc_url( $open_url ) . '">'
                    . esc_html( sprintf( __( 'Open #%d', 'presshub-ai-editor' ), $row['id'] ) ) . '</a></td></tr>';
            }
            $html .= '</tbody></table>';
            if ( count( $rows ) >= self::LIST_LIMIT ) {
                $html .= '<p class="description">' . esc_html( sprintf( __( 'Showing the newest %d records. Narrow the filters to see others.', 'presshub-ai-editor' ), self::LIST_LIMIT ) ) . '</p>';
            }
        }
        return $html . '</div>';
    }
}
