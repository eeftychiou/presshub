<?php
/**
 * PressHub_AI_Editorial_Agenda — Issue #198.
 *
 * A short editor-maintained "current agenda" fiche (running themes with a
 * priority, framing notes and an optional expiry, plus standing editorial
 * guidance) that is injected into the system prompt of every editorial LLM
 * call through one shared helper, apply().
 *
 *  - Storage: one option holding the current version, plus a capped history
 *    of earlier versions and one pending suggestion.
 *  - Injection: a prompt that contains the {editorial_agenda} placeholder gets
 *    the block in place; otherwise the block is appended for the actions that
 *    have a budget (curation, podcast script, shorts plan, social copy, SEO,
 *    image prompts, co-author draft, co-pilot chat). Memory digests, intent
 *    classification, QA scorecards and file extraction never get it.
 *  - Budget: each action gets its own character budget; the lowest-priority
 *    themes are dropped first when the block does not fit.
 *  - Records: the version that was sent is kept in last_applied() so the
 *    generation recorder can store it (and the prompt exactly as sent).
 *  - Suggestions: when a weekly rollup is built, a suggested agenda is made
 *    from its open stories. It stays pending until the editor accepts it.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Editorial_Agenda {

    const OPTION            = 'presshub_ai_editorial_agenda';
    const HISTORY_OPTION    = 'presshub_ai_editorial_agenda_history';
    const SUGGESTION_OPTION = 'presshub_ai_editorial_agenda_suggestion';
    const PLACEHOLDER       = '{editorial_agenda}';
    const HISTORY_MAX       = 20;
    const MAX_THEMES        = 12;
    const PRIORITY_MIN      = 1;
    const PRIORITY_MAX      = 5;
    const PRIORITY_DEFAULT  = 3;
    const TITLE_MAX         = 160;
    const NOTES_MAX         = 600;
    const GUIDANCE_MAX      = 2000;

    /** Admin-post actions (PressHub → Editorial agenda). */
    const POST_SAVE    = 'presshub_ai_agenda_save';
    const POST_SUGGEST = 'presshub_ai_agenda_suggest';
    const POST_ACCEPT  = 'presshub_ai_agenda_accept';
    const POST_DISMISS = 'presshub_ai_agenda_dismiss';
    const NONCE        = 'presshub_ai_agenda';

    /**
     * Character budget per action. Exact keys first, then prefixes ending in
     * "_". An action that is not listed gets no agenda (0).
     */
    const BUDGETS = [
        'briefing_curation'     => 3000,
        'podcast_script'        => 2000,
        'coauthor_draft'        => 2000,
        'copilot_chat'          => 1500,
        'social_tiktok_shorts'  => 1200,
        'generate_image_prompt' => 600,
        'social_copy_'          => 800,
        'seo_'                  => 600,
    ];

    /** @var array{version:int,action:string,chars:int,original_hash:string,prompt:string}|null */
    private static ?array $last_applied = null;

    // ---------------------------------------------------------------------
    // Hooks
    // ---------------------------------------------------------------------

    public static function register_hooks(): void {
        add_action( 'admin_post_' . self::POST_SAVE, [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_' . self::POST_SUGGEST, [ __CLASS__, 'handle_suggest' ] );
        add_action( 'admin_post_' . self::POST_ACCEPT, [ __CLASS__, 'handle_accept' ] );
        add_action( 'admin_post_' . self::POST_DISMISS, [ __CLASS__, 'handle_dismiss' ] );
        add_action( 'presshub_ai_memory_rollup_built', [ __CLASS__, 'on_rollup_built' ], 10, 1 );
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    /** The empty agenda (version 0 means "never saved"). */
    public static function empty_agenda(): array {
        return [
            'version'    => 0,
            'updated_at' => '',
            'updated_by' => 0,
            'note'       => '',
            'themes'     => [],
            'guidance'   => '',
        ];
    }

    public static function get(): array {
        $raw = get_option( self::OPTION, [] );
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return self::empty_agenda();
        }
        $agenda             = array_merge( self::empty_agenda(), $raw );
        $agenda['version']  = max( 0, (int) $agenda['version'] );
        $agenda['themes']   = self::normalize_themes( is_array( $agenda['themes'] ) ? $agenda['themes'] : [] );
        $agenda['guidance'] = self::clip( (string) $agenda['guidance'], self::GUIDANCE_MAX );
        return $agenda;
    }

    public static function get_history(): array {
        $raw = get_option( self::HISTORY_OPTION, [] );
        return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
    }

    public static function get_suggestion(): ?array {
        $raw = get_option( self::SUGGESTION_OPTION, [] );
        if ( ! is_array( $raw ) || empty( $raw['themes'] ) ) {
            return null;
        }
        $raw['themes'] = self::normalize_themes( (array) $raw['themes'] );
        return $raw;
    }

    public static function sanitize_priority( $value ): int {
        if ( ! is_numeric( $value ) ) {
            return self::PRIORITY_DEFAULT;
        }
        return max( self::PRIORITY_MIN, min( self::PRIORITY_MAX, (int) $value ) );
    }

    private static function clip( string $text, int $max ): string {
        $text = trim( str_replace( [ "\r\n", "\r" ], "\n", $text ) );
        if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > $max ) {
            return rtrim( mb_substr( $text, 0, $max ) );
        }
        return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max ) ) : $text;
    }

    private static function valid_date( string $date ): bool {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return false;
        }
        [ $y, $m, $d ] = array_map( 'intval', explode( '-', $date ) );
        return checkdate( $m, $d, $y );
    }

    /**
     * Clean a list of theme rows: drop rows without a title, clamp fields,
     * keep at most MAX_THEMES.
     */
    public static function normalize_themes( array $themes ): array {
        $out = [];
        foreach ( $themes as $theme ) {
            if ( ! is_array( $theme ) ) {
                continue;
            }
            $title = self::clip( preg_replace( '/\s+/u', ' ', (string) ( $theme['title'] ?? '' ) ), self::TITLE_MAX );
            if ( '' === $title ) {
                continue;
            }
            $expires = trim( (string) ( $theme['expires'] ?? '' ) );
            $out[]   = [
                'title'    => $title,
                'priority' => self::sanitize_priority( $theme['priority'] ?? self::PRIORITY_DEFAULT ),
                'notes'    => self::clip( (string) ( $theme['notes'] ?? '' ), self::NOTES_MAX ),
                'expires'  => self::valid_date( $expires ) ? $expires : '',
            ];
            if ( count( $out ) >= self::MAX_THEMES ) {
                break;
            }
        }
        return $out;
    }

    /**
     * Save a new version. The previous version goes to the history.
     *
     * @param array  $themes   Theme rows.
     * @param string $guidance Standing editorial guidance.
     * @param int    $user_id  Editor.
     * @param string $note     Short change note (e.g. "accepted suggestion").
     * @return array The saved agenda.
     */
    public static function save( array $themes, string $guidance, int $user_id = 0, string $note = '' ): array {
        $current = self::get();
        $agenda  = [
            'version'    => $current['version'] + 1,
            'updated_at' => self::now(),
            'updated_by' => $user_id,
            'note'       => self::clip( $note, 200 ),
            'themes'     => self::normalize_themes( $themes ),
            'guidance'   => self::clip( $guidance, self::GUIDANCE_MAX ),
        ];

        if ( $current['version'] > 0 ) {
            $history = self::get_history();
            array_unshift( $history, $current );
            update_option( self::HISTORY_OPTION, array_slice( $history, 0, self::HISTORY_MAX ), false );
        }
        update_option( self::OPTION, $agenda, false );

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( 'Editorial agenda saved', [
                'version' => $agenda['version'],
                'themes'  => count( $agenda['themes'] ),
                'user_id' => $user_id,
                'note'    => $agenda['note'],
            ] );
        }
        return $agenda;
    }

    private static function now(): string {
        return function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    private static function today(): string {
        return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
    }

    // ---------------------------------------------------------------------
    // Rendering and injection
    // ---------------------------------------------------------------------

    /** Budget in characters for an action; 0 = the action gets no agenda. */
    public static function budget_for( string $action ): int {
        $budgets = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_context_budgets( 'presshub_ai_editorial_agenda_budgets', self::BUDGETS ) : self::BUDGETS;
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'presshub_ai_editorial_agenda_budgets', $budgets );
            if ( is_array( $filtered ) ) {
                $budgets = $filtered;
            }
        }
        if ( isset( $budgets[ $action ] ) ) {
            return max( 0, (int) $budgets[ $action ] );
        }
        foreach ( $budgets as $key => $budget ) {
            $key = (string) $key;
            if ( '_' === substr( $key, -1 ) && 0 === strpos( $action, $key ) ) {
                return max( 0, (int) $budget );
            }
        }
        return 0;
    }

    /** Themes that have not expired, highest priority first (ties keep editor order). */
    public static function active_themes( array $agenda, string $today = '' ): array {
        $today  = '' === $today ? self::today() : $today;
        $themes = [];
        foreach ( (array) ( $agenda['themes'] ?? [] ) as $i => $theme ) {
            if ( '' !== ( $theme['expires'] ?? '' ) && $theme['expires'] < $today ) {
                continue;
            }
            $theme['_order'] = $i;
            $themes[]        = $theme;
        }
        usort( $themes, static function ( $a, $b ) {
            return [ $b['priority'], $a['_order'] ] <=> [ $a['priority'], $b['_order'] ];
        } );
        return array_map( static function ( $t ) {
            unset( $t['_order'] );
            return $t;
        }, $themes );
    }

    private static function strlen_u( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
    }

    /**
     * Build the agenda block within a character budget. Themes are dropped
     * from the lowest priority up; guidance is clipped last.
     *
     * @return string '' when there is nothing to send or no budget.
     */
    public static function render( array $agenda, int $budget, string $today = '' ): string {
        return self::render_report( $agenda, $budget, $today )['text'];
    }

    /**
     * The agenda block plus what the budget cut. The budget counts the themes
     * and guidance; the fixed header and footer are not charged to it.
     *
     * @return array{text:string,left_out:array}
     */
    public static function render_report( array $agenda, int $budget, string $today = '' ): array {
        $none = [ 'text' => '', 'left_out' => [] ];
        if ( $budget <= 0 || $agenda['version'] <= 0 ) {
            return $none;
        }
        $themes   = self::active_themes( $agenda, $today );
        $guidance = (string) $agenda['guidance'];
        if ( empty( $themes ) && '' === $guidance ) {
            return $none;
        }

        $header = "=== ΤΡΕΧΟΥΣΑ ΣΥΝΤΑΚΤΙΚΗ ΑΤΖΕΝΤΑ (έκδοση {$agenda['version']}) ===\n"
            . "Οδηγίες του αρχισυντάκτη για τα θέματα που παρακολουθούμε. Χρησιμοποίησέ τες για την επιλογή, την ιεράρχηση και την πλαισίωση. Μην επινοείς γεγονότα για θέματα της ατζέντας που δεν υπάρχουν στο υλικό.\n";
        $footer = '=== ΤΕΛΟΣ ΑΤΖΕΝΤΑΣ ===';

        $theme_lines = [];
        $titles      = [];
        foreach ( $themes as $theme ) {
            $line = '- [Προτεραιότητα ' . $theme['priority'] . '/' . self::PRIORITY_MAX . '] ' . $theme['title'];
            if ( '' !== $theme['expires'] ) {
                $line .= ' (έως ' . $theme['expires'] . ')';
            }
            if ( '' !== $theme['notes'] ) {
                $line .= "\n  Πλαισίωση: " . str_replace( "\n", "\n  ", $theme['notes'] );
            }
            $theme_lines[] = $line;
            $titles[]      = (string) $theme['title'];
        }

        $compose = static function ( array $lines, string $guide ): string {
            $body = '';
            if ( ! empty( $lines ) ) {
                $body .= "Θέματα:\n" . implode( "\n", $lines ) . "\n";
            }
            if ( '' !== $guide ) {
                $body .= "Μόνιμες συντακτικές οδηγίες:\n" . $guide . "\n";
            }
            return $body;
        };

        $left_out = [];
        $body     = $compose( $theme_lines, $guidance );
        while ( self::strlen_u( $body ) > $budget && ! empty( $theme_lines ) ) {
            $need = self::strlen_u( (string) array_pop( $theme_lines ) );
            array_unshift( $left_out, [ 'item' => (string) array_pop( $titles ), 'reason' => 'no_room', 'limit' => $budget, 'needed' => $need ] );
            $body = $compose( $theme_lines, $guidance );
        }
        if ( self::strlen_u( $body ) > $budget && '' !== $guidance ) {
            $full     = self::strlen_u( $guidance );
            $room     = $budget - self::strlen_u( $compose( [], ' ' ) ) - 2;
            $guidance = $room > 40 ? self::clip( $guidance, $room ) . '…' : '';
            $left_out[] = [ 'item' => 'guidance', 'reason' => '' === $guidance ? 'no_room' : 'clipped', 'limit' => $budget, 'needed' => $full, 'kept' => self::strlen_u( $guidance ) ];
            $body     = $compose( [], $guidance );
        }
        if ( self::strlen_u( $body ) > $budget || ( empty( $theme_lines ) && '' === $guidance ) ) {
            return [ 'text' => '', 'left_out' => $left_out ];
        }
        return [ 'text' => $header . $body . $footer, 'left_out' => $left_out ];
    }

    /**
     * The shared helper: put the agenda into a system prompt for an action.
     * A prompt with {editorial_agenda} gets it in place (or the placeholder
     * removed when there is nothing to send); otherwise it is appended for
     * actions with a budget. Never throws.
     */
    public static function apply( $sys_prompt, string $action ) {
        self::$last_applied = null;
        if ( ! is_string( $sys_prompt ) ) {
            return $sys_prompt;
        }
        try {
            $has_placeholder = false !== strpos( $sys_prompt, self::PLACEHOLDER );
            $budget          = self::budget_for( $action );
            if ( $has_placeholder && $budget <= 0 ) {
                $budget = 1500;
            }
            $agenda = $budget > 0 ? self::get() : self::empty_agenda();
            $report = self::render_report( $agenda, $budget );
            $block  = $report['text'];
            class_exists( 'PressHub_AI_Knowledge_Cuts' ) && PressHub_AI_Knowledge_Cuts::log( 'editorial_agenda', $action, $report['left_out'] );

            if ( $has_placeholder ) {
                $out = str_replace( self::PLACEHOLDER, $block, $sys_prompt );
            } elseif ( '' !== $block ) {
                $out = rtrim( $sys_prompt ) . "\n\n" . $block;
            } else {
                $out = $sys_prompt;
            }

            if ( '' !== $block || ! empty( $report['left_out'] ) ) {
                self::$last_applied = [
                    'version'       => (int) $agenda['version'],
                    'action'        => $action,
                    'chars'         => self::strlen_u( $block ),
                    'left_out'      => $report['left_out'],
                    'original_hash' => md5( $sys_prompt ),
                    'prompt'        => $out,
                ];
            }
            return $out;
        } catch ( \Throwable $e ) {
            return str_replace( self::PLACEHOLDER, '', $sys_prompt );
        }
    }

    /** What the last apply() sent, or null. */
    public static function last_applied(): ?array {
        return self::$last_applied;
    }

    public static function clear_last_applied(): void {
        self::$last_applied = null;
    }

    // ---------------------------------------------------------------------
    // Weekly suggestion
    // ---------------------------------------------------------------------

    /**
     * Build a suggested agenda from a weekly rollup's open stories. The
     * editor's current themes keep their place; new stories are added after
     * them. Stored as pending; never applied on its own.
     *
     * @return array|null The suggestion, or null when the rollup has nothing new.
     */
    public static function suggest_from_rollup( array $entry ): ?array {
        $json    = (string) ( $entry['content_json'] ?? '' );
        $content = '' !== $json ? json_decode( $json, true ) : null;
        if ( ! is_array( $content ) || empty( $content['stories'] ) || ! is_array( $content['stories'] ) ) {
            return null;
        }

        $current = self::get();
        $themes  = self::active_themes( $current );
        $known   = [];
        foreach ( $themes as $t ) {
            $known[ self::fold( $t['title'] ) ] = true;
        }

        $added = 0;
        foreach ( $content['stories'] as $story ) {
            if ( ! is_array( $story ) || 'resolved' === ( $story['status'] ?? '' ) ) {
                continue;
            }
            $title = trim( (string) ( $story['title'] ?? '' ) );
            if ( '' === $title || isset( $known[ self::fold( $title ) ] ) ) {
                continue;
            }
            $known[ self::fold( $title ) ] = true;
            $themes[] = [
                'title'    => $title,
                'priority' => 'developing' === ( $story['status'] ?? '' ) ? 4 : self::PRIORITY_DEFAULT,
                'notes'    => self::clip( (string) ( $story['development'] ?? '' ), 300 ),
                'expires'  => '',
            ];
            $added++;
        }
        if ( 0 === $added ) {
            return null;
        }

        $suggestion = [
            'created_at'   => self::now(),
            'source'       => 'weekly_rollup',
            'entry_id'     => (int) ( $entry['id'] ?? 0 ),
            'period_start' => (string) ( $entry['period_start'] ?? '' ),
            'period_end'   => (string) ( $entry['period_end'] ?? '' ),
            'base_version' => (int) $current['version'],
            'added'        => $added,
            'themes'       => self::normalize_themes( $themes ),
            'guidance'     => (string) $current['guidance'],
        ];
        update_option( self::SUGGESTION_OPTION, $suggestion, false );
        return $suggestion;
    }

    private static function fold( string $s ): string {
        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
        return preg_replace( '/\s+/u', ' ', trim( $s ) );
    }

    /** Hook: a weekly rollup was built. */
    public static function on_rollup_built( $entry_id ): void {
        if ( ! class_exists( 'PressHub_AI_Memory_Store' ) ) {
            return;
        }
        $entry = PressHub_AI_Memory_Store::get_entry( (int) $entry_id );
        if ( is_array( $entry ) && 'weekly' === ( $entry['scope'] ?? '' ) ) {
            self::suggest_from_rollup( $entry );
        }
    }

    /** Suggest from the most recent active weekly rollup (button). */
    public static function suggest_from_latest_rollup(): ?array {
        if ( ! class_exists( 'PressHub_AI_Memory_Store' ) ) {
            return null;
        }
        $today   = self::today();
        $from    = gmdate( 'Y-m-d', strtotime( $today . ' -35 days' ) );
        $entries = PressHub_AI_Memory_Store::get_entries_for_range( $from, $today, 'weekly', 'active' );
        if ( empty( $entries ) ) {
            return null;
        }
        return self::suggest_from_rollup( end( $entries ) );
    }

    public static function accept_suggestion( int $user_id = 0 ): ?array {
        $s = self::get_suggestion();
        if ( null === $s ) {
            return null;
        }
        $label = trim( ( $s['period_start'] ?? '' ) . ' – ' . ( $s['period_end'] ?? '' ), ' –' );
        $saved = self::save( $s['themes'], (string) ( $s['guidance'] ?? '' ), $user_id, 'Accepted suggestion' . ( '' !== $label ? ' from week ' . $label : '' ) );
        delete_option( self::SUGGESTION_OPTION );
        return $saved;
    }

    public static function dismiss_suggestion(): void {
        delete_option( self::SUGGESTION_OPTION );
    }

    // ---------------------------------------------------------------------
    // Admin-post handlers (PressHub → Editorial agenda)
    // ---------------------------------------------------------------------

    private static function guard(): void {
        $cap = class_exists( 'PressHub_AI_Memory_Editor' ) ? PressHub_AI_Memory_Editor::CAPABILITY : 'edit_others_posts';
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have permission to edit the editorial agenda.', 'presshub-ai-editor' ), 403 );
        }
        check_admin_referer( self::NONCE );
    }

    /** Admin page of the agenda (PressHub → Editorial agenda). */
    public static function page_slug(): string {
        return class_exists( 'PressHub_AI_Fiches_Admin' ) ? PressHub_AI_Fiches_Admin::AGENDA_SLUG : 'presshub-ai-agenda';
    }

    private static function back( string $notice ): void {
        wp_safe_redirect( add_query_arg( [ 'page' => self::page_slug(), 'agenda_notice' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Turn the posted form rows into theme arrays.
     *
     * @param array $post $_POST (unslashed).
     */
    public static function themes_from_post( array $post ): array {
        $rows   = isset( $post['agenda_themes'] ) && is_array( $post['agenda_themes'] ) ? $post['agenda_themes'] : [];
        $themes = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
                continue;
            }
            $themes[] = [
                'title'    => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
                'priority' => $row['priority'] ?? self::PRIORITY_DEFAULT,
                'notes'    => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
                'expires'  => sanitize_text_field( (string) ( $row['expires'] ?? '' ) ),
            ];
        }
        return $themes;
    }

    public static function handle_save(): void {
        self::guard();
        $post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
        self::save(
            self::themes_from_post( is_array( $post ) ? $post : [] ),
            sanitize_textarea_field( (string) ( $post['agenda_guidance'] ?? '' ) ),
            (int) get_current_user_id(),
            sanitize_text_field( (string) ( $post['agenda_note'] ?? '' ) )
        );
        self::back( 'saved' );
    }

    public static function handle_suggest(): void {
        self::guard();
        self::back( null === self::suggest_from_latest_rollup() ? 'nothing_new' : 'suggested' );
    }

    public static function handle_accept(): void {
        self::guard();
        self::back( null === self::accept_suggestion( (int) get_current_user_id() ) ? 'no_suggestion' : 'accepted' );
    }

    public static function handle_dismiss(): void {
        self::guard();
        self::dismiss_suggestion();
        self::back( 'dismissed' );
    }

    // ---------------------------------------------------------------------
    // Admin UI
    // ---------------------------------------------------------------------

    private static function hidden( string $action ): string {
        $nonce = function_exists( 'wp_nonce_field' ) ? wp_nonce_field( self::NONCE, '_wpnonce', true, false ) : '';
        return '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">' . $nonce;
    }

    private static function theme_row( int $i, array $theme ): string {
        $name = 'agenda_themes[' . $i . ']';
        $html  = '<tr class="presshub-agenda-theme">';
        $html .= '<td><input type="text" class="regular-text" name="' . esc_attr( $name ) . '[title]" value="' . esc_attr( $theme['title'] ?? '' ) . '" maxlength="' . self::TITLE_MAX . '" aria-label="' . esc_attr__( 'Theme', 'presshub-ai-editor' ) . '"></td>';
        $html .= '<td><select name="' . esc_attr( $name ) . '[priority]" aria-label="' . esc_attr__( 'Priority', 'presshub-ai-editor' ) . '">';
        $current = (int) ( $theme['priority'] ?? self::PRIORITY_DEFAULT );
        for ( $p = self::PRIORITY_MAX; $p >= self::PRIORITY_MIN; $p-- ) {
            $html .= '<option value="' . $p . '"' . ( $p === $current ? ' selected' : '' ) . '>' . $p . '</option>';
        }
        $html .= '</select></td>';
        $html .= '<td><textarea rows="2" class="large-text" name="' . esc_attr( $name ) . '[notes]" maxlength="' . self::NOTES_MAX . '" aria-label="' . esc_attr__( 'Framing notes', 'presshub-ai-editor' ) . '">' . esc_textarea( $theme['notes'] ?? '' ) . '</textarea></td>';
        $html .= '<td><input type="date" name="' . esc_attr( $name ) . '[expires]" value="' . esc_attr( $theme['expires'] ?? '' ) . '" aria-label="' . esc_attr__( 'Expires', 'presshub-ai-editor' ) . '"></td>';
        $html .= '<td><label><input type="checkbox" name="' . esc_attr( $name ) . '[remove]" value="1"> ' . esc_html__( 'Remove', 'presshub-ai-editor' ) . '</label></td>';
        return $html . '</tr>';
    }

    private static function themes_table( array $themes, bool $editable, string $today ): string {
        $html  = '<table class="widefat striped presshub-agenda-themes"><thead><tr>';
        $html .= '<th>' . esc_html__( 'Theme', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Priority', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Framing notes', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Expires', 'presshub-ai-editor' ) . '</th>';
        $html .= $editable ? '<th></th>' : '';
        $html .= '</tr></thead><tbody>';
        if ( $editable ) {
            $i = 0;
            foreach ( $themes as $theme ) {
                $html .= self::theme_row( $i++, $theme );
            }
            for ( $n = 0; $n < 3; $n++ ) {
                $html .= self::theme_row( $i++, [] );
            }
        } else {
            foreach ( $themes as $theme ) {
                $expired = '' !== $theme['expires'] && $theme['expires'] < $today;
                $html   .= '<tr' . ( $expired ? ' class="presshub-agenda-expired"' : '' ) . '><td>' . esc_html( $theme['title'] ) . '</td><td>' . (int) $theme['priority'] . '</td><td>' . esc_html( $theme['notes'] ) . '</td><td>' . esc_html( $theme['expires'] ) . '</td></tr>';
            }
        }
        return $html . '</tbody></table>';
    }

    /** The Editorial agenda page body (Policy Fiches menu). */
    public static function build_tab( array $query = [] ): string {
        $agenda  = self::get();
        $today   = self::today();
        $notices = [
            'saved'         => __( 'Agenda saved. New generations use this version.', 'presshub-ai-editor' ),
            'suggested'     => __( 'A suggested update is ready below.', 'presshub-ai-editor' ),
            'nothing_new'   => __( 'The latest weekly rollup has no new open stories to suggest.', 'presshub-ai-editor' ),
            'accepted'      => __( 'Suggestion accepted and saved as a new version.', 'presshub-ai-editor' ),
            'dismissed'     => __( 'Suggestion dismissed.', 'presshub-ai-editor' ),
            'no_suggestion' => __( 'There is no pending suggestion.', 'presshub-ai-editor' ),
        ];
        $form_url = esc_url( admin_url( 'admin-post.php' ) );
        $html     = '';

        $notice = isset( $query['agenda_notice'] ) ? sanitize_key( (string) $query['agenda_notice'] ) : '';
        if ( isset( $notices[ $notice ] ) ) {
            $html .= '<div class="notice notice-success inline"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
        }

        $html .= '<p class="description">' . esc_html__( 'The running themes and standing guidance below are added to every editorial AI call: curation, podcast script, shorts plan, social copy, SEO, image prompts, co-author drafts and the co-pilot chat. Expired themes are left out. Memory digests and QA checks do not get it.', 'presshub-ai-editor' ) . '</p>';
        $html .= '<p>' . esc_html( $agenda['version'] > 0
            ? sprintf( /* translators: 1: version, 2: date */ __( 'Current version %1$d, saved %2$s.', 'presshub-ai-editor' ), $agenda['version'], $agenda['updated_at'] )
            : __( 'No agenda saved yet, so nothing is added to prompts.', 'presshub-ai-editor' ) ) . '</p>';

        // Pending suggestion.
        $suggestion = self::get_suggestion();
        if ( null !== $suggestion ) {
            $html .= '<div class="presshub-agenda-suggestion notice notice-info inline"><h3>' . esc_html( sprintf(
                /* translators: 1: count, 2: week start, 3: week end */
                __( 'Suggested update: %1$d new theme(s) from the week %2$s – %3$s', 'presshub-ai-editor' ),
                (int) $suggestion['added'], (string) $suggestion['period_start'], (string) $suggestion['period_end']
            ) ) . '</h3>';
            $html .= self::themes_table( $suggestion['themes'], false, $today );
            $html .= '<div class="presshub-agenda-suggestion-actions"><form method="post" action="' . $form_url . '" style="display:inline">' . self::hidden( self::POST_ACCEPT ) . '<button class="button button-primary">' . esc_html__( 'Accept suggestion', 'presshub-ai-editor' ) . '</button></form> ';
            $html .= '<form method="post" action="' . $form_url . '" style="display:inline">' . self::hidden( self::POST_DISMISS ) . '<button class="button">' . esc_html__( 'Dismiss', 'presshub-ai-editor' ) . '</button></form></div></div>';
        }

        // Editor.
        $html .= '<form method="post" action="' . $form_url . '" class="presshub-agenda-form">' . self::hidden( self::POST_SAVE );
        $html .= '<h2>' . esc_html__( 'Running themes', 'presshub-ai-editor' ) . '</h2>';
        $html .= self::themes_table( $agenda['themes'], true, $today );
        $html .= '<p class="description">' . esc_html( sprintf( /* translators: %d: max */ __( 'Priority 5 is the most important. Up to %d themes; leave a row empty to skip it.', 'presshub-ai-editor' ), self::MAX_THEMES ) ) . '</p>';
        $html .= '<h2><label for="presshub-agenda-guidance">' . esc_html__( 'Standing editorial guidance', 'presshub-ai-editor' ) . '</label></h2>';
        $html .= '<textarea id="presshub-agenda-guidance" name="agenda_guidance" rows="6" class="large-text" maxlength="' . self::GUIDANCE_MAX . '">' . esc_textarea( $agenda['guidance'] ) . '</textarea>';
        $html .= '<p class="description">' . esc_html__( 'Terms to use or avoid, balance rules and other standing instructions.', 'presshub-ai-editor' ) . '</p>';
        $html .= '<p><label>' . esc_html__( 'Change note (optional)', 'presshub-ai-editor' ) . ' <input type="text" name="agenda_note" class="regular-text" maxlength="200"></label></p>';
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save agenda', 'presshub-ai-editor' ) . '</button></p></form>';

        $html .= '<form method="post" action="' . $form_url . '">' . self::hidden( self::POST_SUGGEST ) . '<p><button class="button">' . esc_html__( 'Suggest an update from the latest weekly rollup', 'presshub-ai-editor' ) . '</button></p></form>';

        // Preview.
        $preview = self::render( $agenda, self::budget_for( 'briefing_curation' ), $today );
        $html   .= '<details><summary>' . esc_html__( 'Show the exact text the morning curation receives', 'presshub-ai-editor' ) . '</summary>';
        $html   .= '' === $preview ? '<p>' . esc_html__( 'Nothing would be added.', 'presshub-ai-editor' ) . '</p>' : '<pre class="presshub-memory-preview-text">' . esc_html( $preview ) . '</pre>';
        $html   .= '</details>';

        // History.
        $history = self::get_history();
        $html   .= '<h2>' . esc_html__( 'Edit history', 'presshub-ai-editor' ) . '</h2>';
        if ( empty( $history ) ) {
            $html .= '<p>' . esc_html__( 'No earlier versions.', 'presshub-ai-editor' ) . '</p>';
        } else {
            foreach ( $history as $old ) {
                $user  = '';
                if ( ! empty( $old['updated_by'] ) && function_exists( 'get_userdata' ) ) {
                    $u    = get_userdata( (int) $old['updated_by'] );
                    $user = $u ? (string) $u->display_name : '';
                }
                $label = sprintf( 'v%d · %s%s%s', (int) $old['version'], (string) $old['updated_at'], '' !== $user ? ' · ' . $user : '', '' !== (string) ( $old['note'] ?? '' ) ? ' · ' . $old['note'] : '' );
                $html .= '<details class="presshub-agenda-history"><summary>' . esc_html( $label ) . '</summary>';
                $html .= self::themes_table( self::normalize_themes( (array) ( $old['themes'] ?? [] ) ), false, $today );
                if ( '' !== (string) ( $old['guidance'] ?? '' ) ) {
                    $html .= '<pre>' . esc_html( (string) $old['guidance'] ) . '</pre>';
                }
                $html .= '</details>';
            }
        }
        return $html;
    }
}
