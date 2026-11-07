<?php
/**
 * PressHub_AI_Memory_Editor - editor screen for reviewing and correcting editorial memory.
 *
 * Implements Issue #137 (Part of epic #130, Wave C).
 *
 * Surfaces:
 *   - "Memory" submenu under Daily Briefing Hub: timeline of the last
 *     `background_days` (daily digests per date/edition and weekly rollups) with
 *     status badges, story counts, source-post links and highlighted gaps, plus a
 *     preview of what the next morning briefing would receive (context builder, #135).
 *   - Inline entry editor (loaded through AJAX): editable stories, rollup narrative,
 *     diff against the generated version, reset / exclude / include / regenerate /
 *     rebuild actions.
 *
 * Every mutation goes through PressHub_AI_Memory_Store (validation, origin=edited,
 * stale propagation, audit log). This class never writes to the tables itself.
 *
 * Security: every endpoint checks the nonce and the `edit_others_posts` capability.
 * Story text is model/editor content, so the page escapes every value and the AJAX
 * payloads are JSON that the client inserts with .text()/.val(), never as HTML.
 *
 * @package PressHub_AI_Editor
 * @since 2.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Memory_Editor {

    /** Capability required for the screen and every endpoint. */
    const CAPABILITY = 'edit_others_posts';

    /** Nonce action and request field shared with the other admin AJAX endpoints. */
    const NONCE_ACTION = 'presshub_ai_nonce';

    const PAGE_SLUG     = 'presshub-ai-memory';
    const AJAX_GET      = 'presshub_ai_memory_get';
    const AJAX_SAVE     = 'presshub_ai_memory_save';
    const AJAX_ACTION   = 'presshub_ai_memory_action';
    const SCRIPT_HANDLE = 'presshub-ai-memory-editor';
    const STYLE_HANDLE  = 'presshub-ai-memory-editor-css';

    /** Whole-entry actions accepted by the action endpoint. */
    const OPERATIONS = [ 'reset', 'exclude', 'include', 'regenerate', 'rebuild' ];

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_' . self::AJAX_GET, [ $this, 'ajax_get' ] );
        add_action( 'wp_ajax_' . self::AJAX_SAVE, [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_action' ] );
        add_filter( 'presshub_ai_memory_editor_url', [ $this, 'filter_editor_url' ], 10, 2 );
    }

    // ---------------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------------

    public static function can_edit(): bool {
        return current_user_can( self::CAPABILITY );
    }

    /**
     * Nonce first (dies on failure), then capability (JSON 403).
     */
    private function guard_ajax(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! self::can_edit() ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to edit editorial memory.', 'presshub-ai-editor' ) ], 403 );
        }
    }

    /** Rollup actions exist only when the weekly rollup builder (#136) is loaded. */
    public static function rollup_actions_available(): bool {
        return class_exists( 'PressHub_AI_Memory_Rollup_Builder' ) && method_exists( 'PressHub_AI_Memory_Rollup_Builder', 'rebuild' );
    }

    // ---------------------------------------------------------------------
    // URLs and assets
    // ---------------------------------------------------------------------

    public static function editor_url( int $entry_id ): string {
        return add_query_arg(
            [
                'page'     => self::PAGE_SLUG,
                'entry_id' => $entry_id,
            ],
            admin_url( 'admin.php' )
        );
    }

    /** Filter callback for `presshub_ai_memory_editor_url` (used by the generation inspector). */
    public function filter_editor_url( $url, $entry_id = 0 ) {
        $entry_id = is_scalar( $entry_id ) ? (int) $entry_id : 0;
        if ( $entry_id <= 0 || ! self::can_edit() ) {
            return $url;
        }
        return self::editor_url( $entry_id );
    }

    private static function record_url( int $record_id ): string {
        if ( $record_id <= 0 || ! class_exists( 'PressHub_AI_Generation_Inspector' ) ) {
            return '';
        }
        return add_query_arg(
            [
                'page'      => PressHub_AI_Generation_Inspector::PAGE_SLUG,
                'record_id' => $record_id,
            ],
            admin_url( 'admin.php' )
        );
    }

    public function enqueue_assets( $hook ): void {
        if ( false === strpos( (string) $hook, self::PAGE_SLUG ) || ! self::can_edit() ) {
            return;
        }
        wp_enqueue_style( self::STYLE_HANDLE, PRESSHUB_AI_URL . 'assets/memory-editor.css', [], PRESSHUB_AI_VERSION );
        wp_enqueue_script( self::SCRIPT_HANDLE, PRESSHUB_AI_URL . 'assets/memory-editor.js', [ 'jquery' ], PRESSHUB_AI_VERSION, true );
        $open = isset( $_GET['entry_id'] ) ? max( 0, (int) $_GET['entry_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only deep link.
        wp_localize_script( self::SCRIPT_HANDLE, 'presshubMemory', [
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( self::NONCE_ACTION ),
            'open_entry'       => $open,
            'rollup_available' => self::rollup_actions_available(),
            'story_statuses'   => PressHub_AI_Memory_Store::STORY_STATUSES,
            'actions'          => [
                'get'    => self::AJAX_GET,
                'save'   => self::AJAX_SAVE,
                'action' => self::AJAX_ACTION,
            ],
            'i18n'             => [
                'loading'            => __( 'Loading...', 'presshub-ai-editor' ),
                'load_error'         => __( 'Could not load this entry.', 'presshub-ai-editor' ),
                'request_error'      => __( 'Request failed. Check your connection and try again.', 'presshub-ai-editor' ),
                'close'              => __( 'Close', 'presshub-ai-editor' ),
                'review'             => __( 'Review / edit', 'presshub-ai-editor' ),
                'title'              => __( 'Title', 'presshub-ai-editor' ),
                'development'        => __( 'Development', 'presshub-ai-editor' ),
                'status'             => __( 'Status', 'presshub-ai-editor' ),
                'entities'           => __( 'Entities (comma separated)', 'presshub-ai-editor' ),
                'include'            => __( 'Include in memory', 'presshub-ai-editor' ),
                'excluded_story'     => __( 'excluded', 'presshub-ai-editor' ),
                'delete_story'       => __( 'Delete story', 'presshub-ai-editor' ),
                'add_story'          => __( 'Add story', 'presshub-ai-editor' ),
                'new_story'          => __( '(new story)', 'presshub-ai-editor' ),
                'no_stories'         => __( 'This entry has no stories.', 'presshub-ai-editor' ),
                'narrative'          => __( 'Rollup narrative', 'presshub-ai-editor' ),
                'save'               => __( 'Save changes', 'presshub-ai-editor' ),
                'saving'             => __( 'Saving...', 'presshub-ai-editor' ),
                'saved'              => __( 'Saved. This entry is now marked as edited.', 'presshub-ai-editor' ),
                'reset'              => __( 'Reset to generated', 'presshub-ai-editor' ),
                'reset_confirm'      => __( 'Discard all edits and restore the generated version?', 'presshub-ai-editor' ),
                'reset_done'         => __( 'Entry reset to the generated version.', 'presshub-ai-editor' ),
                'exclude'            => __( 'Exclude entry', 'presshub-ai-editor' ),
                'include_entry'      => __( 'Include entry', 'presshub-ai-editor' ),
                'excluded_done'      => __( 'Entry excluded from future memory.', 'presshub-ai-editor' ),
                'included_done'      => __( 'Entry included in future memory.', 'presshub-ai-editor' ),
                'regenerate'         => __( 'Regenerate digest', 'presshub-ai-editor' ),
                'regenerate_confirm' => __( 'Regenerate this digest with the AI provider?', 'presshub-ai-editor' ),
                'regenerate_edited'  => __( 'This entry has been edited. Regeneration refreshes the generated version only; your edited content is kept unless you also reset it. Continue?', 'presshub-ai-editor' ),
                'regenerate_done'    => __( 'Digest regeneration finished.', 'presshub-ai-editor' ),
                'rebuild'            => __( 'Rebuild rollup', 'presshub-ai-editor' ),
                'rebuild_confirm'    => __( 'Rebuild this rollup from its daily digests? Edits to the rollup narrative may be replaced.', 'presshub-ai-editor' ),
                'rebuild_done'       => __( 'Rollup rebuilt.', 'presshub-ai-editor' ),
                'stale_notice'       => __( 'Dependent weekly rollup is now stale and still feeds the next briefings until rebuilt:', 'presshub-ai-editor' ),
                'stale_no_rebuild'   => __( 'Rebuilding rollups is not available yet.', 'presshub-ai-editor' ),
                'view_record'        => __( 'View generation record', 'presshub-ai-editor' ),
                'diff_title'         => __( 'Changes compared with the generated version', 'presshub-ai-editor' ),
                'diff_none'          => __( 'Identical to the generated version.', 'presshub-ai-editor' ),
                'diff_added'         => __( 'Added story', 'presshub-ai-editor' ),
                'diff_removed'       => __( 'Removed story', 'presshub-ai-editor' ),
                'diff_changed'       => __( 'Changed story', 'presshub-ai-editor' ),
                'diff_before'        => __( 'Generated', 'presshub-ai-editor' ),
                'diff_after'         => __( 'Current', 'presshub-ai-editor' ),
                'diff_narrative'     => __( 'Rollup narrative', 'presshub-ai-editor' ),
                'yes'                => __( 'yes', 'presshub-ai-editor' ),
                'no'                 => __( 'no', 'presshub-ai-editor' ),
                'stories_count'      => __( '%s stories', 'presshub-ai-editor' ),
                'error_prefix'       => __( 'Error:', 'presshub-ai-editor' ),
            ],
        ] );
    }

    // ---------------------------------------------------------------------
    // Entry payload helpers
    // ---------------------------------------------------------------------

    /**
     * Normalised story list for display/editing (never executed as HTML).
     */
    private static function content_view( string $json ): array {
        $content = PressHub_AI_Memory_Store::decode_content( $json );
        $stories = [];
        foreach ( (array) ( $content['stories'] ?? [] ) as $story ) {
            if ( ! is_array( $story ) ) {
                continue;
            }
            $stories[] = [
                'key'         => (string) ( $story['key'] ?? '' ),
                'title'       => (string) ( $story['title'] ?? '' ),
                'development' => (string) ( $story['development'] ?? '' ),
                'status'      => (string) ( $story['status'] ?? '' ),
                'entities'    => array_values( array_map( 'strval', array_filter( (array) ( $story['entities'] ?? [] ), 'is_scalar' ) ) ),
                'include'     => ! array_key_exists( 'include', $story ) || (bool) $story['include'],
            ];
        }
        return [
            'stories'   => $stories,
            'narrative' => (string) ( $content['narrative'] ?? '' ),
        ];
    }

    /**
     * Diff of the generated version against the current content, keyed by story key.
     *
     * @return array List of { type: added|removed|changed|narrative, key, title, changes: [{field, before, after}] }.
     */
    public static function compute_diff( array $generated, array $current ): array {
        $gen = [];
        foreach ( $generated['stories'] as $s ) {
            $gen[ $s['key'] ] = $s;
        }
        $cur = [];
        foreach ( $current['stories'] as $s ) {
            $cur[ $s['key'] ] = $s;
        }

        $fmt = static function ( $field, $value ) {
            if ( 'entities' === $field ) {
                return implode( ', ', (array) $value );
            }
            if ( 'include' === $field ) {
                return $value ? 'yes' : 'no';
            }
            return (string) $value;
        };

        $diff = [];
        foreach ( $cur as $key => $story ) {
            if ( ! isset( $gen[ $key ] ) ) {
                $diff[] = [ 'type' => 'added', 'key' => $key, 'title' => $story['title'], 'changes' => [] ];
                continue;
            }
            $changes = [];
            foreach ( [ 'title', 'development', 'status', 'entities', 'include' ] as $field ) {
                $before = $fmt( $field, $gen[ $key ][ $field ] );
                $after  = $fmt( $field, $story[ $field ] );
                if ( $before !== $after ) {
                    $changes[] = [ 'field' => $field, 'before' => $before, 'after' => $after ];
                }
            }
            if ( ! empty( $changes ) ) {
                $diff[] = [ 'type' => 'changed', 'key' => $key, 'title' => $story['title'], 'changes' => $changes ];
            }
        }
        foreach ( $gen as $key => $story ) {
            if ( ! isset( $cur[ $key ] ) ) {
                $diff[] = [ 'type' => 'removed', 'key' => $key, 'title' => $story['title'], 'changes' => [] ];
            }
        }
        if ( $generated['narrative'] !== $current['narrative'] ) {
            $diff[] = [
                'type'    => 'narrative',
                'key'     => '',
                'title'   => '',
                'changes' => [ [ 'field' => 'narrative', 'before' => $generated['narrative'], 'after' => $current['narrative'] ] ],
            ];
        }
        return $diff;
    }

    /** Status badges for an entry: its status plus `edited` when origin is edited. */
    public static function badges_for( array $entry ): array {
        $badges = [ (string) ( $entry['status'] ?? '' ) ];
        if ( 'edited' === (string) ( $entry['origin'] ?? '' ) ) {
            $badges[] = 'edited';
        }
        return array_values( array_filter( $badges, 'strlen' ) );
    }

    private static function story_count( array $entry ): array {
        $view     = self::content_view( (string) ( $entry['content_json'] ?? '' ) );
        $included = 0;
        foreach ( $view['stories'] as $s ) {
            if ( $s['include'] ) {
                $included++;
            }
        }
        return [ count( $view['stories'] ), $included ];
    }

    private static function entry_label( array $entry ): string {
        if ( 'weekly' === (string) $entry['scope'] ) {
            /* translators: 1: week start, 2: week end */
            return sprintf( __( 'Weekly rollup %1$s - %2$s', 'presshub-ai-editor' ), $entry['period_start'], $entry['period_end'] );
        }
        $edition = (string) $entry['edition'];
        return $entry['period_start'] . ( '' !== $edition ? ' (' . $edition . ')' : '' );
    }

    /** Summary used by the timeline row and returned to the client after every mutation. */
    public static function entry_summary( array $entry ): array {
        list( $count, $included ) = self::story_count( $entry );
        $post_id                  = (int) ( $entry['source_post_id'] ?? 0 );
        return [
            'id'             => (int) $entry['id'],
            'scope'          => (string) $entry['scope'],
            'period_start'   => (string) $entry['period_start'],
            'period_end'     => (string) $entry['period_end'],
            'edition'        => (string) $entry['edition'],
            'status'         => (string) $entry['status'],
            'origin'         => (string) $entry['origin'],
            'badges'         => self::badges_for( $entry ),
            'story_count'    => $count,
            'included_count' => $included,
            'source_post_id' => $post_id,
            'source_url'     => $post_id > 0 ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
            'label'          => self::entry_label( $entry ),
            'error_message'  => (string) ( $entry['error_message'] ?? '' ),
        ];
    }

    /**
     * Stale weekly rollups whose week covers the digest's date.
     */
    private static function stale_rollups_for( array $entry ): array {
        if ( 'daily' !== (string) $entry['scope'] ) {
            return [];
        }
        $date = (string) $entry['period_start'];
        $out  = [];
        foreach ( PressHub_AI_Memory_Store::get_entries_for_range( $date, $date, 'weekly', 'stale' ) as $rollup ) {
            $out[] = [ 'id' => (int) $rollup['id'], 'label' => self::entry_label( $rollup ) ];
        }
        return $out;
    }

    private static function payload_for( array $entry ): array {
        $current   = self::content_view( (string) $entry['content_json'] );
        $generated = self::content_view( (string) $entry['generated_json'] );
        $status    = (string) $entry['status'];
        $scope     = (string) $entry['scope'];
        $post_id   = (int) ( $entry['source_post_id'] ?? 0 );

        return [
            'entry'         => self::entry_summary( $entry ),
            'content'       => $current,
            'diff'          => self::compute_diff( $generated, $current ),
            'can'           => [
                'reset'      => 'edited' === (string) $entry['origin'],
                'exclude'    => in_array( $status, [ 'active', 'stale' ], true ),
                'include'    => 'excluded' === $status,
                'regenerate' => 'daily' === $scope && $post_id > 0 && class_exists( 'PressHub_AI_Memory_Digest_Builder' ),
                'rebuild'    => 'weekly' === $scope && 'stale' === $status && self::rollup_actions_available(),
                'narrative'  => 'weekly' === $scope,
            ],
            'record_url'    => self::record_url( (int) ( $entry['generation_record_id'] ?? 0 ) ),
            'stale_rollups' => self::stale_rollups_for( $entry ),
        ];
    }

    private function requested_entry(): array {
        $entry_id = isset( $_POST['entry_id'] ) ? max( 0, (int) $_POST['entry_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard_ajax().
        $entry    = $entry_id > 0 ? PressHub_AI_Memory_Store::get_entry( $entry_id ) : null;
        if ( null === $entry ) {
            wp_send_json_error( [ 'message' => __( 'Memory entry not found.', 'presshub-ai-editor' ) ], 404 );
        }
        return $entry;
    }

    private static function log( string $message, array $context = [] ): void {
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( $message, $context );
        }
    }

    // ---------------------------------------------------------------------
    // AJAX endpoints
    // ---------------------------------------------------------------------

    public function ajax_get(): void {
        $this->guard_ajax();
        $entry = $this->requested_entry();
        wp_send_json_success( self::payload_for( $entry ) );
    }

    public function ajax_save(): void {
        $this->guard_ajax();
        $entry = $this->requested_entry();

        $raw     = isset( $_POST['stories'] ) ? wp_unslash( $_POST['stories'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- decoded and validated by the store.
        $posted  = is_string( $raw ) ? json_decode( $raw, true ) : null;
        if ( ! is_array( $posted ) ) {
            wp_send_json_error( [ 'message' => __( 'The submitted stories are not valid JSON.', 'presshub-ai-editor' ) ], 400 );
        }

        $existing = PressHub_AI_Memory_Store::decode_content( (string) $entry['content_json'] );
        $by_key   = [];
        foreach ( (array) ( $existing['stories'] ?? [] ) as $story ) {
            if ( is_array( $story ) && isset( $story['key'] ) ) {
                $by_key[ (string) $story['key'] ] = $story;
            }
        }

        $stories = [];
        $used    = [];
        foreach ( $posted as $item ) {
            if ( ! is_array( $item ) ) {
                wp_send_json_error( [ 'message' => __( 'A submitted story is malformed.', 'presshub-ai-editor' ) ], 400 );
            }
            $title = isset( $item['title'] ) && is_scalar( $item['title'] ) ? (string) $item['title'] : '';
            $key   = isset( $item['key'] ) && is_scalar( $item['key'] ) ? sanitize_title( (string) $item['key'] ) : '';
            if ( '' === $key || ! isset( $by_key[ $key ] ) ) {
                $key = self::new_story_key( $title, $used );
            }
            $used[ $key ] = true;

            $entities = [];
            if ( isset( $item['entities'] ) && is_array( $item['entities'] ) ) {
                foreach ( $item['entities'] as $entity ) {
                    if ( is_scalar( $entity ) ) {
                        $entities[] = (string) $entity;
                    }
                }
            }

            // status is passed through untouched so the store reports invalid values.
            $stories[] = [
                'key'         => $key,
                'title'       => $title,
                'development' => isset( $item['development'] ) && is_scalar( $item['development'] ) ? (string) $item['development'] : '',
                'status'      => isset( $item['status'] ) && is_scalar( $item['status'] ) ? (string) $item['status'] : '',
                'entities'    => $entities,
                'source_urls' => isset( $by_key[ $key ]['source_urls'] ) && is_array( $by_key[ $key ]['source_urls'] ) ? $by_key[ $key ]['source_urls'] : [],
                'include'     => ! empty( $item['include'] ),
            ];
        }

        // Start from the stored content so fields this editor does not expose (e.g. a weekly
        // rollup's missing_dates from #136) survive a save; then overwrite what was edited.
        $content = array_merge(
            is_array( $existing ) ? $existing : [],
            [
                'stories'   => $stories,
                'podcast'   => is_array( $existing['podcast'] ?? null ) ? $existing['podcast'] : [ 'opening_line' => '', 'closing_line' => '' ],
                'narrative' => (string) ( $existing['narrative'] ?? '' ),
            ]
        );
        if ( 'weekly' === (string) $entry['scope'] && isset( $_POST['narrative'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard_ajax().
            $content['narrative'] = (string) wp_unslash( $_POST['narrative'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- sanitised by the store.
        }

        $result = PressHub_AI_Memory_Store::update_content( (int) $entry['id'], $content );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ], 422 );
        }
        self::log( 'Memory editor: entry saved', [ 'entry_id' => (int) $entry['id'], 'dependents_stale' => (int) ( $result['marked_dependents_stale'] ?? 0 ) ] );

        $fresh   = PressHub_AI_Memory_Store::get_entry( (int) $entry['id'] );
        $payload = self::payload_for( $fresh ?: $entry );
        $payload['dependents_stale'] = (int) ( $result['marked_dependents_stale'] ?? 0 );
        wp_send_json_success( $payload );
    }

    public function ajax_action(): void {
        $this->guard_ajax();
        $op = isset( $_POST['op'] ) ? sanitize_key( (string) wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard_ajax().
        if ( ! in_array( $op, self::OPERATIONS, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown memory action.', 'presshub-ai-editor' ) ], 400 );
        }
        $entry    = $this->requested_entry();
        $entry_id = (int) $entry['id'];
        $status   = (string) $entry['status'];
        $message  = '';
        $result   = null;

        switch ( $op ) {
            case 'reset':
                $result = PressHub_AI_Memory_Store::reset_to_generated( $entry_id );
                break;

            case 'exclude':
                if ( ! in_array( $status, [ 'active', 'stale' ], true ) ) {
                    wp_send_json_error( [ 'message' => __( 'Only active or stale entries can be excluded.', 'presshub-ai-editor' ) ], 409 );
                }
                $result = PressHub_AI_Memory_Store::set_status( $entry_id, 'excluded' );
                break;

            case 'include':
                if ( 'excluded' !== $status ) {
                    wp_send_json_error( [ 'message' => __( 'Only excluded entries can be included again.', 'presshub-ai-editor' ) ], 409 );
                }
                $result = PressHub_AI_Memory_Store::set_status( $entry_id, 'active' );
                break;

            case 'regenerate':
                $post_id = (int) ( $entry['source_post_id'] ?? 0 );
                if ( 'daily' !== (string) $entry['scope'] || $post_id <= 0 || ! class_exists( 'PressHub_AI_Memory_Digest_Builder' ) ) {
                    wp_send_json_error( [ 'message' => __( 'This entry has no source briefing post to regenerate from.', 'presshub-ai-editor' ) ], 409 );
                }
                $result = PressHub_AI_Memory_Digest_Builder::build_for_post( $post_id, null, true );
                if ( is_array( $result ) && isset( $result['status'], $result['reason'] ) && 'skipped' === $result['status'] ) {
                    wp_send_json_error( [ 'message' => sprintf( /* translators: %s: reason code */ __( 'Regeneration was skipped: %s', 'presshub-ai-editor' ), (string) $result['reason'] ) ], 409 );
                }
                break;

            case 'rebuild':
                if ( ! self::rollup_actions_available() ) {
                    wp_send_json_error( [ 'message' => __( 'Weekly rollup rebuilding is not available.', 'presshub-ai-editor' ) ], 409 );
                }
                if ( 'weekly' !== (string) $entry['scope'] || 'stale' !== $status ) {
                    wp_send_json_error( [ 'message' => __( 'Only stale weekly rollups can be rebuilt.', 'presshub-ai-editor' ) ], 409 );
                }
                $result = PressHub_AI_Memory_Rollup_Builder::rebuild( $entry_id );
                break;
        }

        if ( is_wp_error( $result ) ) {
            self::log( 'Memory editor: action failed', [ 'entry_id' => $entry_id, 'op' => $op, 'code' => $result->get_error_code() ] );
            wp_send_json_error( [ 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ], 422 );
        }
        self::log( 'Memory editor: action completed', [ 'entry_id' => $entry_id, 'op' => $op ] );

        $fresh   = PressHub_AI_Memory_Store::get_entry( $entry_id );
        $payload = self::payload_for( $fresh ?: $entry );
        $payload['dependents_stale'] = is_array( $result ) ? (int) ( $result['marked_dependents_stale'] ?? 0 ) : 0;
        $payload['op']               = $op;
        wp_send_json_success( $payload );
    }

    /** Unique key slug for a story added in the editor. */
    private static function new_story_key( string $title, array $used ): string {
        $base = sanitize_title( $title );
        if ( '' === $base ) {
            $base = 'story-' . substr( md5( $title . microtime( true ) ), 0, 8 );
        }
        $key = $base;
        $n   = 2;
        while ( isset( $used[ $key ] ) ) {
            $key = $base . '-' . $n++;
        }
        return $key;
    }

    // ---------------------------------------------------------------------
    // Page
    // ---------------------------------------------------------------------

    public static function render_page(): void {
        if ( ! self::can_edit() ) {
            wp_die( esc_html__( 'You do not have permission to edit editorial memory.', 'presshub-ai-editor' ) );
        }
        echo self::build_page( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput -- read-only; escaped in build_page().
    }

    public static function build_page( array $query = [] ): string {
        $open  = isset( $query['entry_id'] ) ? max( 0, (int) $query['entry_id'] ) : 0;
        $today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        $days  = max( 1, PressHub_AI_Settings_Storage::get_memory_background_days() );
        $from  = gmdate( 'Y-m-d', strtotime( $today . " -{$days} days" ) );

        $html  = '<div class="wrap presshub-memory-editor-page" data-open-entry="' . esc_attr( (string) $open ) . '">';
        $html .= '<h1>' . esc_html__( 'Editorial memory', 'presshub-ai-editor' ) . '</h1>';
        $html .= '<p class="description">' . esc_html( sprintf( /* translators: %d: days */ __( 'Review and correct what the briefings remember. Showing the last %d days. Edits are marked as edited, survive regeneration and are written to the audit log.', 'presshub-ai-editor' ), $days ) ) . '</p>';
        $html .= '<div id="presshub-memory-notices" role="status" aria-live="polite"></div>';

        if ( ! PressHub_AI_Settings_Storage::get_memory_enabled() ) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__( 'Editorial memory is disabled in the settings, so nothing below is injected into new generations.', 'presshub-ai-editor' ) . '</p></div>';
        }

        $html .= self::build_preview( $today );
        $html .= self::build_timeline( $from, $today );
        return $html . '</div>';
    }

    /** Preview of the memory block the next morning briefing would receive (#135). */
    private static function build_preview( string $today ): string {
        if ( ! class_exists( 'PressHub_AI_Memory_Context_Builder' ) ) {
            return '';
        }
        $built    = PressHub_AI_Memory_Context_Builder::build( $today, 'morning', 'briefing' );
        $manifest = is_array( $built['manifest'] ?? null ) ? $built['manifest'] : [];
        $text     = (string) ( $built['text'] ?? '' );

        $rows = [
            __( 'Memory injected', 'presshub-ai-editor' )  => ! empty( $manifest['enabled'] ) ? __( 'yes', 'presshub-ai-editor' ) : __( 'no (disabled)', 'presshub-ai-editor' ),
            __( 'Characters', 'presshub-ai-editor' )       => (int) ( $manifest['used_chars'] ?? 0 ) . ' / ' . (int) ( $manifest['budget_chars'] ?? 0 ),
            __( 'Tokens (estimate)', 'presshub-ai-editor' ) => (int) ( $manifest['tokens_estimate'] ?? 0 ),
            __( 'Entries used', 'presshub-ai-editor' )     => count( (array) ( $manifest['entries'] ?? [] ) ),
            __( 'Trimmed for budget', 'presshub-ai-editor' ) => count( (array) ( $manifest['trimmed'] ?? [] ) ),
            __( 'Days without digest', 'presshub-ai-editor' ) => count( (array) ( $manifest['missing_digests'] ?? [] ) ),
            __( 'Includes stale entries', 'presshub-ai-editor' ) => ! empty( $manifest['has_stale'] ) ? __( 'yes', 'presshub-ai-editor' ) : __( 'no', 'presshub-ai-editor' ),
        ];

        $html  = '<div class="presshub-memory-preview"><h2>' . esc_html__( 'Next morning briefing preview', 'presshub-ai-editor' ) . '</h2>';
        $html .= '<table class="widefat striped presshub-memory-manifest"><tbody>';
        foreach ( $rows as $label => $value ) {
            $html .= '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<details class="presshub-memory-preview-details"><summary>' . esc_html__( 'Show the exact memory text', 'presshub-ai-editor' ) . '</summary>';
        $html .= '' === $text
            ? '<p class="presshub-memory-empty">' . esc_html__( 'Nothing would be injected.', 'presshub-ai-editor' ) . '</p>'
            : '<pre class="presshub-memory-preview-text">' . esc_html( $text ) . '</pre>';
        return $html . '</details></div>';
    }

    private static function badge_html( string $badge ): string {
        return '<span class="presshub-memory-badge presshub-memory-badge-' . esc_attr( sanitize_html_class( $badge ) ) . '">' . esc_html( $badge ) . '</span>';
    }

    private static function build_timeline( string $from, string $today ): string {
        $entries = PressHub_AI_Memory_Store::get_entries_for_range( $from, $today );

        // Dates with at least one daily entry; today is excluded from the gap check
        // because its digest is only built after the briefing is published.
        $covered = [];
        $items   = [];
        foreach ( $entries as $entry ) {
            if ( 'daily' === (string) $entry['scope'] ) {
                $covered[ (string) $entry['period_start'] ] = true;
                $items[] = [ 'sort' => $entry['period_start'] . '|1|' . $entry['edition'], 'html' => self::row_html( $entry ) ];
            } else {
                $items[] = [ 'sort' => $entry['period_end'] . '|2|', 'html' => self::row_html( $entry ) ];
            }
        }
        for ( $ts = strtotime( $from ); $ts < strtotime( $today ); $ts += 86400 ) {
            $date = gmdate( 'Y-m-d', $ts );
            if ( ! isset( $covered[ $date ] ) ) {
                $items[] = [ 'sort' => $date . '|0|', 'html' => self::missing_row_html( $date ) ];
            }
        }
        usort( $items, static function ( $a, $b ) {
            return strcmp( $b['sort'], $a['sort'] );
        } );

        $html  = '<h2>' . esc_html__( 'Timeline', 'presshub-ai-editor' ) . '</h2>';
        $html .= '<table class="widefat presshub-memory-timeline"><thead><tr><th>' . esc_html__( 'Period', 'presshub-ai-editor' ) . '</th><th>'
            . esc_html__( 'Type', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Status', 'presshub-ai-editor' ) . '</th><th>'
            . esc_html__( 'Stories', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Source', 'presshub-ai-editor' ) . '</th><th>'
            . esc_html__( 'Actions', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
        foreach ( $items as $item ) {
            $html .= $item['html'];
        }
        return $html . '</tbody></table>';
    }

    private static function missing_row_html( string $date ): string {
        return '<tr class="presshub-memory-row presshub-memory-missing"><td>' . esc_html( $date ) . '</td><td colspan="5">'
            . '<strong>' . esc_html__( 'No digest for this day', 'presshub-ai-editor' ) . '</strong> - '
            . esc_html__( 'the memory has a gap here. Generate the briefing or run the backfill.', 'presshub-ai-editor' ) . '</td></tr>';
    }

    private static function row_html( array $entry ): string {
        $s      = self::entry_summary( $entry );
        $id     = $s['id'];
        $period = 'weekly' === $s['scope'] ? $s['period_start'] . ' - ' . $s['period_end'] : $s['period_start'];
        $type   = 'weekly' === $s['scope']
            ? __( 'Weekly rollup', 'presshub-ai-editor' )
            : sprintf( /* translators: %s: edition */ __( 'Daily digest (%s)', 'presshub-ai-editor' ), '' !== $s['edition'] ? $s['edition'] : '-' );

        $badges = '';
        foreach ( $s['badges'] as $badge ) {
            $badges .= self::badge_html( $badge ) . ' ';
        }
        $source = $s['source_post_id'] > 0 && '' !== $s['source_url']
            ? '<a href="' . esc_url( $s['source_url'] ) . '">' . esc_html( sprintf( /* translators: %d: post ID */ __( 'Post #%d', 'presshub-ai-editor' ), $s['source_post_id'] ) ) . '</a>'
            : '&mdash;';

        $actions = '<button type="button" class="button presshub-memory-edit" data-entry-id="' . esc_attr( (string) $id ) . '">' . esc_html__( 'Review / edit', 'presshub-ai-editor' ) . '</button>';
        $record  = self::record_url( (int) ( $entry['generation_record_id'] ?? 0 ) );
        if ( '' !== $record ) {
            $actions .= ' <a href="' . esc_url( $record ) . '">' . esc_html__( 'View generation record', 'presshub-ai-editor' ) . '</a>';
        }

        $error = '' !== $s['error_message'] ? '<div class="presshub-memory-error-detail">' . esc_html( $s['error_message'] ) . '</div>' : '';

        return '<tr class="presshub-memory-row" id="presshub-memory-row-' . esc_attr( (string) $id ) . '" data-entry-id="' . esc_attr( (string) $id ) . '" data-scope="' . esc_attr( $s['scope'] ) . '">'
            . '<td>' . esc_html( $period ) . '</td><td>' . esc_html( $type ) . '</td>'
            . '<td class="presshub-memory-badges">' . $badges . $error . '</td>'
            . '<td class="presshub-memory-count">' . esc_html( sprintf( /* translators: 1: included, 2: total */ __( '%1$d / %2$d', 'presshub-ai-editor' ), $s['included_count'], $s['story_count'] ) ) . '</td>'
            . '<td>' . $source . '</td><td>' . $actions . '</td></tr>'
            . '<tr class="presshub-memory-editor-row" data-entry-id="' . esc_attr( (string) $id ) . '" hidden><td colspan="6"><div class="presshub-memory-editor" data-entry-id="' . esc_attr( (string) $id ) . '"></div></td></tr>';
    }
}
