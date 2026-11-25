<?php
/**
 * PressHub_AI_Fact_Checks - claims, photos and videos a fact-checker found
 * false, kept in the editorial memory and noted in every kind of post.
 *
 *  - Capture: each daily memory digest may carry a `fact_checks` list, taken
 *    from the published briefing ("FactCheck Cyprus found that the photo
 *    ... was digitally altered"). An editor can correct or remove them in
 *    the Memory editor like any other part of a digest.
 *  - Register: the fact checks of the active daily digests of the last
 *    `presshub_ai_fact_check_days` days, newest first, one per claim.
 *  - Use: call_provider() adds the fact checks whose match phrases appear in
 *    a call's material to its system prompt, within a budget per action
 *    (briefing, podcast, co-author, co-pilot, QA gate, social copy, Shorts,
 *    the Trending in Cyprus summary). The trending summary also says which
 *    post repeats which fact check, and the roundup shows a note under it.
 *
 * Storage: none of its own (the memory entries hold them).
 *
 * @package PressHub_AI_Editor
 * @since 2.80.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Fact_Checks {

    /**
     * Character budget per action. Exact keys first, then prefixes ending in
     * "_". Stored in presshub_ai_fact_checks_budgets; filter on top:
     * presshub_ai_fact_checks_budgets.
     */
    const BUDGETS = [
        'briefing_curation'       => 1500,
        'podcast_script'          => 1000,
        'coauthor_draft'          => 1200,
        'copilot_chat'            => 1200,
        'coauthor_scorecard'      => 1200,
        'social_copy_'            => 700,
        'social_tiktok_shorts'    => 700,
        'social_trending_summary' => 1500,
    ];

    /** Verdicts and how the prompt and the roundup name them. */
    const VERDICTS = [
        'false'       => 'ψευδές',
        'manipulated' => 'παραποιημένο',
        'misleading'  => 'παραπλανητικό',
        'unproven'    => 'αστήρικτο',
    ];

    const MAX_PER_DIGEST = 8;
    const MAX_MATCH      = 6;
    const MAX_INJECTED   = 6;
    const CLAIM_MAX      = 300;
    const FINDING_MAX    = 300;
    const BY_MAX         = 80;
    const PHRASE_MAX     = 60;

    const POST_REMOVE = 'presshub_ai_fact_check_remove';
    const NONCE       = 'presshub_ai_fact_check';

    private static ?array $last_applied = null;

    /** Register cache for this request: [ key => list ]. */
    private static array $cache = [];

    public static function register_hooks(): void {
        add_action( 'admin_post_' . self::POST_REMOVE, [ __CLASS__, 'handle_remove' ] );
    }

    public static function is_enabled(): bool {
        $on = class_exists( 'PressHub_AI_Settings_Storage' )
            ? ( PressHub_AI_Settings_Storage::get_memory_enabled() && PressHub_AI_Settings_Storage::get_fact_checks_enabled() )
            : true;
        return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'presshub_ai_fact_checks_enabled', $on ) : $on;
    }

    // ---------------------------------------------------------------------
    // Text helpers
    // ---------------------------------------------------------------------

    public static function fold( string $s ): string {
        if ( class_exists( 'PressHub_AI_Stakeholders' ) ) {
            return PressHub_AI_Stakeholders::fold( $s );
        }
        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
        $s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s ) ?? $s;
        return trim( preg_replace( '/\s+/u', ' ', $s ) ?? $s );
    }

    private static function len( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
    }

    private static function clip( string $s, int $max ): string {
        $s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
        return self::len( $s ) > $max ? rtrim( function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max ) ) : $s;
    }

    private static function text( $v, int $max ): string {
        $v = is_scalar( $v ) ? (string) $v : '';
        $v = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $v ) : trim( strip_tags( $v ) );
        return self::clip( $v, $max );
    }

    // ---------------------------------------------------------------------
    // Shape (used by the memory store's validator)
    // ---------------------------------------------------------------------

    /**
     * One fact check, cleaned: null when it has no claim, no known verdict
     * or no usable match phrase. A phrase is usable when it is two words or
     * more (so "Κύπρος" alone never matches).
     */
    public static function normalize( $fc ): ?array {
        if ( ! is_array( $fc ) ) {
            return null;
        }
        $claim   = self::text( $fc['claim'] ?? '', self::CLAIM_MAX );
        $verdict = function_exists( 'sanitize_key' ) ? sanitize_key( (string) ( $fc['verdict'] ?? '' ) ) : strtolower( (string) ( $fc['verdict'] ?? '' ) );
        if ( '' === $claim || ! isset( self::VERDICTS[ $verdict ] ) ) {
            return null;
        }
        $match = [];
        foreach ( (array) ( $fc['match'] ?? [] ) as $phrase ) {
            $phrase = self::text( $phrase, self::PHRASE_MAX );
            if ( '' === self::phrase_pattern( $phrase ) ) {
                continue;
            }
            $key = self::fold( $phrase );
            if ( ! isset( $match[ $key ] ) ) {
                $match[ $key ] = $phrase;
            }
            if ( count( $match ) >= self::MAX_MATCH ) {
                break;
            }
        }
        if ( empty( $match ) ) {
            return null;
        }
        $url = is_string( $fc['source_url'] ?? null ) ? trim( $fc['source_url'] ) : '';
        $url = '' !== $url && function_exists( 'esc_url_raw' ) ? esc_url_raw( $url ) : ( preg_match( '#^https?://#i', $url ) ? $url : '' );
        return [
            'claim'      => $claim,
            'verdict'    => $verdict,
            'checked_by' => self::text( $fc['checked_by'] ?? '', self::BY_MAX ),
            'finding'    => self::text( $fc['finding'] ?? '', self::FINDING_MAX ),
            'match'      => array_values( $match ),
            'source_url' => $url,
        ];
    }

    /** A list of fact checks, cleaned, one per claim, at most MAX_PER_DIGEST. */
    public static function normalize_list( $list ): array {
        $out  = [];
        $seen = [];
        foreach ( is_array( $list ) ? $list : [] as $fc ) {
            $clean = self::normalize( $fc );
            if ( null === $clean ) {
                continue;
            }
            $key = self::fold( $clean['claim'] );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[]        = $clean;
            if ( count( $out ) >= self::MAX_PER_DIGEST ) {
                break;
            }
        }
        return $out;
    }

    /** Regex for a match phrase in folded text, '' when the phrase is too vague. */
    public static function phrase_pattern( string $phrase ): string {
        $folded = self::fold( $phrase );
        if ( '' === $folded ) {
            return '';
        }
        // One word alone ("Κύπρος", "Κυπριακό") would match far too much.
        if ( count( explode( ' ', $folded ) ) < 2 ) {
            return '';
        }
        return '/(?<![\p{L}\p{N}])' . preg_quote( $folded, '/' ) . '(?![\p{L}\p{N}])/u';
    }

    // ---------------------------------------------------------------------
    // Register
    // ---------------------------------------------------------------------

    /** Today in site time (Y-m-d). */
    private static function today(): string {
        return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
    }

    public static function flush_cache(): void {
        self::$cache = [];
    }

    /**
     * The fact checks in memory: those of the active daily digests of the
     * last N days, newest first, one per claim. Each item gets `date` (the
     * digest's date) and `entry_id`.
     */
    public static function all( ?string $today = null, ?int $days = null ): array {
        $today = $today ?? self::today();
        $days  = $days ?? ( class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_fact_check_days() : 30 );
        $key   = $today . '|' . $days;
        if ( isset( self::$cache[ $key ] ) ) {
            return self::$cache[ $key ];
        }
        $out = [];
        if ( class_exists( 'PressHub_AI_Memory_Store' ) ) {
            $from    = gmdate( 'Y-m-d', strtotime( $today . ' -' . max( 0, $days - 1 ) . ' days' ) );
            $entries = PressHub_AI_Memory_Store::get_entries_for_range( $from, $today, 'daily' );
            usort( $entries, static function ( $a, $b ) {
                return [ (string) ( $b['period_start'] ?? '' ), (string) ( $b['edition'] ?? '' ) ] <=> [ (string) ( $a['period_start'] ?? '' ), (string) ( $a['edition'] ?? '' ) ];
            } );
            $seen = [];
            foreach ( $entries as $e ) {
                if ( ! in_array( (string) ( $e['status'] ?? '' ), [ 'active', 'stale' ], true ) ) {
                    continue;
                }
                $content = PressHub_AI_Memory_Store::decode_content( (string) ( $e['content_json'] ?? '' ) );
                foreach ( self::normalize_list( $content['fact_checks'] ?? [] ) as $fc ) {
                    $k = self::fold( $fc['claim'] );
                    if ( isset( $seen[ $k ] ) ) {
                        continue;
                    }
                    $seen[ $k ]     = true;
                    $fc['date']     = (string) ( $e['period_start'] ?? '' );
                    $fc['entry_id'] = (int) ( $e['id'] ?? 0 );
                    $out[]          = $fc;
                }
            }
        }
        self::$cache[ $key ] = $out;
        return $out;
    }

    /**
     * Fact checks whose match phrases appear in $text, most matched first,
     * then newest.
     */
    public static function find_in_text( string $text, ?array $checks = null ): array {
        $folded = self::fold( $text );
        if ( '' === $folded ) {
            return [];
        }
        $hits = [];
        foreach ( $checks ?? self::all() as $i => $fc ) {
            $count = 0;
            foreach ( (array) $fc['match'] as $phrase ) {
                $re = self::phrase_pattern( (string) $phrase );
                if ( '' !== $re ) {
                    $count += (int) preg_match_all( $re, $folded );
                }
            }
            if ( $count > 0 ) {
                $hits[] = [ 'count' => $count, 'order' => $i, 'check' => $fc ];
            }
        }
        usort( $hits, static function ( $a, $b ) {
            return [ $b['count'], $a['order'] ] <=> [ $a['count'], $b['order'] ];
        } );
        return array_map( static function ( $h ) { return $h['check']; }, $hits );
    }

    // ---------------------------------------------------------------------
    // Use in AI calls
    // ---------------------------------------------------------------------

    public static function budget_for( string $action ): int {
        $budgets = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_context_budgets( 'presshub_ai_fact_checks_budgets', self::BUDGETS ) : self::BUDGETS;
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'presshub_ai_fact_checks_budgets', $budgets );
            $budgets  = is_array( $filtered ) ? $filtered : $budgets;
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

    /** Reference used in the prompt and in the trending summary's reply. */
    public static function ref( int $n ): string {
        return 'FC' . $n;
    }

    /** One fact check as a prompt line. */
    public static function render_line( array $fc, int $n ): string {
        $line = '- [' . self::ref( $n ) . '] «' . rtrim( $fc['claim'], ' .' ) . '» — ' . ( self::VERDICTS[ $fc['verdict'] ] ?? $fc['verdict'] );
        $meta = array_filter( [ $fc['checked_by'], (string) ( $fc['date'] ?? '' ) ], 'strlen' );
        if ( $meta ) {
            $line .= ' (' . implode( ', ', $meta ) . ')';
        }
        if ( '' !== $fc['finding'] ) {
            $line .= '. Εύρημα: ' . rtrim( $fc['finding'], ' .' ) . '.';
        }
        return $line . "\n";
    }

    private static function header_for( string $action ): string {
        $base = "=== ΕΛΕΓΧΟΙ ΓΕΓΟΝΟΤΩΝ (από τη συντακτική μνήμη) ===\n"
            . "Ισχυρισμοί, φωτογραφίες ή βίντεο που ελεγκτές γεγονότων ή αρμόδιοι έχουν ήδη κρίνει ψευδή, παραποιημένα ή παραπλανητικά, όπως αναφέρθηκαν σε προηγούμενες ενημερώσεις μας. Ισχύουν μόνο όταν το υλικό αναφέρεται στον ίδιο ισχυρισμό ή στο ίδιο υλικό· μια απλή κοινή λέξη δεν αρκεί. Τότε μην τον παρουσιάζεις ως αληθινό και ανέφερε τον έλεγχο με όνομα (π.χ. «το FactCheck Cyprus έχει διαπιστώσει ότι…»). Ένας έλεγχος που ήδη αναφέραμε δεν είναι σημερινή είδηση: μην τον ξαναπείς ως θέμα της ημέρας, ούτε όταν το σημερινό υλικό είναι ένα νέο άρθρο για τον ίδιο ισχυρισμό, εκτός αν φέρνει νέο εύρημα (π.χ. νέο αποτέλεσμα ελέγχου ή επίσημη αντίδραση).\n";
        if ( 'coauthor_scorecard' === $action ) {
            return $base . "Αν το κείμενο αναπαράγει κάποιον από αυτούς ως αληθινό ή χωρίς να αναφέρει τον έλεγχο, μείωσε τη βαθμολογία και γράψε στο feedback ποιον αφορά.\n";
        }
        if ( 'social_trending_summary' === $action ) {
            return $base . "Αν μια ανάρτηση της λίστας επαναλαμβάνει ή μοιράζεται κάποιον από αυτούς, σημείωσέ το σύντομα στη σύνοψη, με απόδοση στον ελεγκτή, και βάλε την ανάρτηση στο πεδίο \"fact_checks\" της απάντησης με τον κωδικό του ελέγχου (π.χ. {\"post\":1,\"check\":\"FC1\"}).\n";
        }
        return $base;
    }

    /**
     * The block for an action, within its budget: fact checks in order, each
     * whole or left out (every cut reported).
     *
     * @return array{text:string,refs:array<string,array>,left_out:array}
     */
    public static function render( array $checks, string $action, int $budget ): array {
        if ( $budget <= 0 || empty( $checks ) ) {
            return [ 'text' => '', 'refs' => [], 'left_out' => [] ];
        }
        $body     = '';
        $refs     = [];
        $left_out = [];
        $n        = 0;
        foreach ( array_values( $checks ) as $i => $fc ) {
            $item = self::clip( $fc['claim'], 60 );
            if ( $i >= self::MAX_INJECTED ) {
                $left_out[] = [ 'item' => $item, 'reason' => 'max_items', 'limit' => self::MAX_INJECTED ];
                continue;
            }
            $line = self::render_line( $fc, $n + 1 );
            if ( self::len( $body . $line ) > $budget ) {
                $left_out[] = [ 'item' => $item, 'reason' => 'no_room', 'limit' => $budget, 'room' => max( 0, $budget - self::len( $body ) ), 'needed' => self::len( $line ) ];
                continue;
            }
            $n++;
            $body                 .= $line;
            $refs[ self::ref( $n ) ] = $fc;
        }
        $text = '' === $body ? '' : self::header_for( $action ) . $body . '=== ΤΕΛΟΣ ΕΛΕΓΧΩΝ ΓΕΓΟΝΟΤΩΝ ===';
        return [ 'text' => $text, 'refs' => $refs, 'left_out' => $left_out ];
    }

    /**
     * Add the fact checks found in $material to $sys_prompt. Never throws.
     */
    public static function apply( $sys_prompt, $material, string $action ) {
        self::$last_applied = null;
        if ( ! is_string( $sys_prompt ) || ! is_string( $material ) ) {
            return $sys_prompt;
        }
        try {
            $budget = self::budget_for( $action );
            if ( $budget <= 0 || ! self::is_enabled() ) {
                return $sys_prompt;
            }
            $hits = self::find_in_text( $material );
            if ( empty( $hits ) ) {
                return $sys_prompt;
            }
            $out    = self::render( $hits, $action, $budget );
            $prompt = '' === $out['text'] ? $sys_prompt : rtrim( $sys_prompt ) . "\n\n" . $out['text'];
            self::$last_applied = [
                'action'        => $action,
                'claims'        => array_values( array_map( static function ( $fc ) { return self::clip( $fc['claim'], 80 ); }, $out['refs'] ) ),
                'refs'          => $out['refs'],
                'chars'         => self::len( $out['text'] ),
                'left_out'      => $out['left_out'],
                'original_hash' => md5( $sys_prompt ),
                'prompt'        => $prompt,
            ];
            class_exists( 'PressHub_AI_Knowledge_Cuts' ) && PressHub_AI_Knowledge_Cuts::log( 'fact_checks', $action, $out['left_out'] );
            return $prompt;
        } catch ( \Throwable $e ) {
            return $sys_prompt;
        }
    }

    public static function last_applied(): ?array {
        return self::$last_applied;
    }

    public static function clear_last_applied(): void {
        self::$last_applied = null;
    }

    // ---------------------------------------------------------------------
    // Notes on the Trending in Cyprus roundup
    // ---------------------------------------------------------------------

    /**
     * The note shown under a roundup post that repeats a fact check. Built
     * from the stored fact check, never from the model's own words.
     */
    public static function note_text( array $fc ): string {
        $verdict = self::VERDICTS[ $fc['verdict'] ] ?? $fc['verdict'];
        $text    = sprintf( '«%s» — %s.', rtrim( $fc['claim'], ' .' ), $verdict );
        if ( '' !== $fc['checked_by'] ) {
            $text = $fc['checked_by'] . ': ' . $text;
        }
        if ( '' !== $fc['finding'] ) {
            $text .= ' ' . rtrim( $fc['finding'], ' .' ) . '.';
        }
        return $text;
    }

    /** The note as a block, with a link to the check when there is one. */
    public static function note_block( array $note ): string {
        $text = esc_html( (string) ( $note['text'] ?? '' ) );
        $url  = (string) ( $note['url'] ?? '' );
        if ( '' !== $url ) {
            $text .= ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow">Ο έλεγχος</a>';
        }
        return "<!-- wp:paragraph {\"className\":\"presshub-trending-factcheck\"} -->\n<p class=\"presshub-trending-factcheck\"><strong>Έλεγχος γεγονότων</strong> " . $text . "</p>\n<!-- /wp:paragraph -->";
    }

    // ---------------------------------------------------------------------
    // Memory page: the list, and removing a wrong one
    // ---------------------------------------------------------------------

    /** Stable id of a fact check inside its digest. */
    public static function id_for( array $fc ): string {
        return substr( md5( self::fold( (string) $fc['claim'] ) ), 0, 12 );
    }

    /** The "Fact checks in memory" section of the Memory page. */
    public static function build_section( ?string $today = null ): string {
        $days  = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_fact_check_days() : 30;
        $all   = self::all( $today, $days );
        $html  = '<div class="presshub-memory-fact-checks"><h2>' . esc_html__( 'Fact checks in memory', 'presshub-ai-editor' ) . '</h2>';
        $html .= '<p class="description">' . esc_html( sprintf(
            /* translators: %d: days */
            __( 'Claims, photos and videos that a fact-checker found false, as the briefings of the last %d days reported them. Every AI task whose material repeats one is told about it, and the Trending roundup adds a note under the post. Regenerate a digest to read its briefing again; remove one that was read wrongly.', 'presshub-ai-editor' ),
            $days
        ) ) . '</p>';
        if ( ! self::is_enabled() ) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__( 'Fact checks in AI calls are off (Settings, Editorial knowledge), so these are not used.', 'presshub-ai-editor' ) . '</p></div>';
        }
        if ( empty( $all ) ) {
            return $html . '<p class="presshub-memory-empty">' . esc_html__( 'No fact checks in memory yet.', 'presshub-ai-editor' ) . '</p></div>';
        }
        $html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Claim', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Verdict', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Matched by', 'presshub-ai-editor' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'presshub-ai-editor' ) . '</span></th></tr></thead><tbody>';
        $post_url = function_exists( 'admin_url' ) ? admin_url( 'admin-post.php' ) : 'admin-post.php';
        foreach ( $all as $fc ) {
            $claim = esc_html( $fc['claim'] );
            if ( '' !== $fc['finding'] ) {
                $claim .= '<br /><span class="description">' . esc_html( $fc['finding'] ) . '</span>';
            }
            if ( '' !== $fc['source_url'] ) {
                $claim .= ' <a href="' . esc_url( $fc['source_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Source', 'presshub-ai-editor' ) . '</a>';
            }
            $verdict = esc_html( self::VERDICTS[ $fc['verdict'] ] ?? $fc['verdict'] ) . ( '' !== $fc['checked_by'] ? '<br />' . esc_html( $fc['checked_by'] ) : '' );
            $form    = '<form method="post" action="' . esc_url( $post_url ) . '">'
                . '<input type="hidden" name="action" value="' . esc_attr( self::POST_REMOVE ) . '" />'
                . '<input type="hidden" name="entry_id" value="' . esc_attr( (string) $fc['entry_id'] ) . '" />'
                . '<input type="hidden" name="fact_check" value="' . esc_attr( self::id_for( $fc ) ) . '" />'
                . ( function_exists( 'wp_nonce_field' ) ? wp_nonce_field( self::NONCE, '_wpnonce', true, false ) : '' )
                . '<button type="submit" class="button button-small">' . esc_html__( 'Remove', 'presshub-ai-editor' ) . '</button></form>';
            $html .= '<tr><td>' . esc_html( (string) $fc['date'] ) . '</td><td>' . $claim . '</td><td>' . $verdict . '</td><td>' . esc_html( implode( ', ', (array) $fc['match'] ) ) . '</td><td>' . $form . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /**
     * Remove one fact check from its digest (an editor edit, so it survives
     * regeneration and is in the audit log like any memory edit).
     *
     * @return true|WP_Error
     */
    public static function remove( int $entry_id, string $id ) {
        $entry = PressHub_AI_Memory_Store::get_entry( $entry_id );
        if ( ! $entry ) {
            return new WP_Error( 'not_found', __( 'Memory entry not found.', 'presshub-ai-editor' ) );
        }
        $content = PressHub_AI_Memory_Store::decode_content( (string) $entry['content_json'] );
        $list    = self::normalize_list( $content['fact_checks'] ?? [] );
        $kept    = array_values( array_filter( $list, static function ( $fc ) use ( $id ) {
            return self::id_for( $fc ) !== $id;
        } ) );
        if ( count( $kept ) === count( $list ) ) {
            return new WP_Error( 'not_found', __( 'That fact check is no longer in this digest.', 'presshub-ai-editor' ) );
        }
        $content['fact_checks'] = $kept;
        if ( empty( $kept ) ) {
            unset( $content['fact_checks'] );
        }
        $res = PressHub_AI_Memory_Store::update_content( $entry_id, $content );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        self::flush_cache();
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            PressHub_AI_Audit_Logger::log( 'fact_check_removed', 'memory_entry', $entry_id, [ 'fact_check' => $id ] );
        }
        return true;
    }

    public static function handle_remove(): void {
        check_admin_referer( self::NONCE );
        if ( ! class_exists( 'PressHub_AI_Memory_Editor' ) || ! PressHub_AI_Memory_Editor::can_edit() ) {
            wp_die( esc_html__( 'You do not have permission to edit editorial memory.', 'presshub-ai-editor' ) );
        }
        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;
        $id       = isset( $_POST['fact_check'] ) ? sanitize_key( (string) wp_unslash( $_POST['fact_check'] ) ) : '';
        $res      = self::remove( $entry_id, $id );
        $url      = class_exists( 'PressHub_Admin_Menu' ) ? PressHub_Admin_Menu::url( 'presshub-ai-memory' ) : admin_url( 'admin.php?page=presshub-ai-memory' );
        wp_safe_redirect( add_query_arg( 'fact_check_removed', is_wp_error( $res ) ? '0' : '1', $url ) );
        exit;
    }
}
