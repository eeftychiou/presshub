<?php
/**
 * PressHub_AI_Knowledge_Cuts — what a context limit cut from an AI call.
 *
 * Design principle (agents.md): when a limit cuts or leaves out content, the
 * cut is recorded. The editorial agenda, people fiches and policy fiches
 * report every item they drop or shorten for lack of room; this class logs
 * one line per call and turns the stored list into text for the generation
 * inspector. Editorial memory has its own list (manifest "trimmed").
 *
 * One cut: [ 'item' => slug, title or 'guidance', 'reason' => one of
 * REASONS, 'limit' => the budget or count, 'needed' => characters the item
 * needed, 'room' => characters left when it was tried, 'parts' => parts
 * left out of a shortened item, 'kept' => characters kept of a clipped one ].
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Knowledge_Cuts {

    const REASONS = [ 'no_room', 'shortened', 'clipped', 'max_items' ];

    /** Config keys of the generation record, per kind. */
    const CONFIG_KEYS = [
        'editorial_agenda' => 'editorial_agenda_left_out',
        'stakeholders'     => 'stakeholders_left_out',
        'policy_fiches'    => 'policy_fiches_left_out',
        'material'         => 'material_left_out',
    ];

    /** Material cuts noted before the next AI call (see clip_material()). */
    private static array $pending = [];

    /** Material cuts of the last call_provider(), for the recorder. */
    private static ?array $last_material = null;

    /** A noted cut older than this is stale (its call never ran) and is dropped. */
    const PENDING_TTL = 300;

    /** One log line for a call whose block lost something. Never throws. */
    public static function log( string $kind, string $action, array $cuts ): void {
        if ( empty( $cuts ) || ! class_exists( 'PressHub_AI_Logger' ) ) {
            return;
        }
        try {
            $by_reason = array_count_values( array_map( static function ( $c ) { return (string) ( $c['reason'] ?? '' ); }, $cuts ) );
            PressHub_AI_Logger::info(
                sprintf( 'Context limit cut %d item(s) of %s from %s.', count( $cuts ), $kind, $action ),
                [ 'kind' => $kind, 'action' => $action, 'by_reason' => $by_reason, 'items' => array_column( $cuts, 'item' ) ]
            );
        } catch ( \Throwable $e ) {
            // Logging must never break the AI call.
        }
    }

    // ------------------------------------------------------------------
    // Material cuts: text and lists a caller shortens before an AI call
    // (article body, podcast script, fetched pages, URL count...).
    // ------------------------------------------------------------------

    /**
     * Cut $text to $limit characters and note the cut for the next AI call.
     * Returns the text unchanged when it fits. $suffix is appended to a cut
     * text, as the caller did before.
     */
    public static function clip_material( string $text, int $limit, string $what, string $suffix = '' ): string {
        $len = self::len( $text );
        if ( $limit <= 0 || $len <= $limit ) {
            return $text;
        }
        self::note_material( $what, 'clipped', $limit, $len, $limit );
        return ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit ) ) . $suffix;
    }

    /**
     * Note a cut the caller made itself. $reason 'clipped' (text cut to
     * $kept of $needed characters, limit $limit) or 'max_items' ($needed
     * items, only $limit sent).
     */
    public static function note_material( string $what, string $reason, int $limit, int $needed, int $kept = -1 ): void {
        if ( count( self::$pending ) >= 50 ) {
            return;
        }
        $row = [ 'item' => $what, 'reason' => $reason, 'limit' => $limit, 'needed' => $needed, 't' => time() ];
        if ( $kept >= 0 ) {
            $row['kept'] = $kept;
        }
        self::$pending[] = $row;
    }

    /**
     * Called by call_provider() before the provider call: the cuts noted for
     * this call become last_material() (for the generation record) and are
     * logged once. Stale notes from a call that never ran are dropped.
     */
    public static function start_call( string $action ): void {
        $fresh = array_values( array_filter( self::$pending, static function ( $c ) {
            return (int) ( $c['t'] ?? 0 ) >= time() - self::PENDING_TTL;
        } ) );
        self::$pending       = [];
        self::$last_material = $fresh ? array_map( static function ( $c ) { unset( $c['t'] ); return $c; }, $fresh ) : null;
        if ( $fresh ) {
            self::log( 'material', $action, self::$last_material );
        }
    }

    public static function last_material(): ?array {
        return self::$last_material;
    }

    public static function clear_last_material(): void {
        self::$last_material = null;
    }

    /** Drop everything noted (tests, or a caller that aborts before its call). */
    public static function clear_pending(): void {
        self::$pending = [];
    }

    private static function len( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
    }

    /** Keep the stored list small and well formed. */
    public static function sanitize( $cuts ): array {
        $out = [];
        foreach ( is_array( $cuts ) ? array_slice( $cuts, 0, 50 ) : [] as $c ) {
            if ( ! is_array( $c ) || ! in_array( $c['reason'] ?? '', self::REASONS, true ) ) {
                continue;
            }
            $row = [ 'item' => mb_substr( (string) ( $c['item'] ?? '' ), 0, 120 ), 'reason' => (string) $c['reason'] ];
            foreach ( [ 'limit', 'needed', 'room', 'kept' ] as $n ) {
                if ( isset( $c[ $n ] ) ) {
                    $row[ $n ] = (int) $c[ $n ];
                }
            }
            if ( ! empty( $c['parts'] ) && is_array( $c['parts'] ) ) {
                $row['parts'] = array_values( array_map( 'strval', $c['parts'] ) );
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * What the article caps of the briefing curation cut, from the stored
     * article breakdown: articles shortened to the per-article character cap
     * and articles left out by the maximum article count. Articles the editor
     * did not select are a choice, not a limit, and are not counted.
     *
     * @param array $sent    Breakdown of sent articles (chars_sent, chars_original, truncated).
     * @param array $dropped Breakdown of dropped articles (reason).
     * @param array $totals  Record totals (cap_articles, cap_chars_per_article).
     * @return array{sent:int,shortened:int,chars_cut:int,cap_chars:int,left_out:int,cap_articles:int,shortened_items:string[],left_out_items:string[]}
     */
    public static function article_cuts( array $sent, array $dropped, array $totals ): array {
        $out = [
            'sent'            => 0,
            'shortened'       => 0,
            'chars_cut'       => 0,
            'cap_chars'       => (int) ( $totals['cap_chars_per_article'] ?? 0 ),
            'left_out'        => 0,
            'cap_articles'    => (int) ( $totals['cap_articles'] ?? 0 ),
            'shortened_items' => [],
            'left_out_items'  => [],
        ];
        foreach ( $sent as $a ) {
            if ( ! is_array( $a ) ) {
                continue;
            }
            $out['sent']++;
            if ( empty( $a['truncated'] ) ) {
                continue;
            }
            $out['shortened']++;
            $original = (int) ( $a['chars_original'] ?? 0 );
            $kept     = (int) ( $a['chars_sent'] ?? 0 );
            if ( $original > $kept ) {
                $out['chars_cut'] += $original - $kept;
            }
            if ( 0 === $out['cap_chars'] && $kept > 0 ) {
                $out['cap_chars'] = $kept;
            }
            $out['shortened_items'][] = self::article_label( $a );
        }
        foreach ( $dropped as $a ) {
            if ( is_array( $a ) && 'cap_articles' === ( $a['reason'] ?? '' ) ) {
                $out['left_out']++;
                $out['left_out_items'][] = self::article_label( $a );
            }
        }
        return $out;
    }

    /** One line per kind of article cut, for the generation inspector. */
    public static function describe_articles( array $summary ): array {
        $lines = [];
        if ( ! empty( $summary['shortened'] ) ) {
            /* translators: 1: articles shortened, 2: articles sent, 3: per-article character cap, 4: characters cut in total */
            $lines[] = sprintf( __( '%1$d of %2$d articles cut to %3$d characters each (the per-article limit); %4$d characters cut in total', 'presshub-ai-editor' ), (int) $summary['shortened'], (int) ( $summary['sent'] ?? 0 ), (int) ( $summary['cap_chars'] ?? 0 ), (int) ( $summary['chars_cut'] ?? 0 ) );
        }
        if ( ! empty( $summary['left_out'] ) ) {
            /* translators: 1: articles left out, 2: maximum number of articles */
            $lines[] = sprintf( __( '%1$d articles left out: only %2$d are sent per call (lowest source priority first)', 'presshub-ai-editor' ), (int) $summary['left_out'], (int) ( $summary['cap_articles'] ?? 0 ) );
        }
        if ( ! empty( $lines ) ) {
            $lines[] = __( 'Each article is listed in the Articles tab.', 'presshub-ai-editor' );
        }
        return $lines;
    }

    /** One log line when the article caps cut something. Never throws. */
    public static function log_articles( string $action, array $summary ): void {
        if ( ( empty( $summary['shortened'] ) && empty( $summary['left_out'] ) ) || ! class_exists( 'PressHub_AI_Logger' ) ) {
            return;
        }
        try {
            PressHub_AI_Logger::info(
                sprintf(
                    'Article limits cut the %s prompt: %d of %d articles shortened to %d characters (%d characters cut), %d left out by the %d-article cap.',
                    $action,
                    (int) $summary['shortened'],
                    (int) $summary['sent'],
                    (int) $summary['cap_chars'],
                    (int) $summary['chars_cut'],
                    (int) $summary['left_out'],
                    (int) $summary['cap_articles']
                ),
                [
                    'action'    => $action,
                    'shortened' => array_slice( $summary['shortened_items'] ?? [], 0, 20 ),
                    'left_out'  => array_slice( $summary['left_out_items'] ?? [], 0, 20 ),
                ]
            );
        } catch ( \Throwable $e ) {
            // Logging must never break the AI call.
        }
    }

    /** Memory entries and stories the memory budget left out (manifest "trimmed"), for the inspector. */
    public static function describe_memory( array $memory ): array {
        $trimmed = is_array( $memory['trimmed'] ?? null ) ? $memory['trimmed'] : [];
        if ( empty( $trimmed ) ) {
            return [];
        }
        $budget = (int) ( $memory['budget_chars'] ?? 0 );
        return [
            $budget > 0
                /* translators: 1: entries or stories left out, 2: memory budget in characters */
                ? sprintf( __( '%1$d memory entries or stories left out to fit the memory budget of %2$d characters; listed in the Memory tab', 'presshub-ai-editor' ), count( $trimmed ), $budget )
                /* translators: %d: entries or stories left out */
                : sprintf( __( '%d memory entries or stories left out to fit the memory budget; listed in the Memory tab', 'presshub-ai-editor' ), count( $trimmed ) ),
        ];
    }

    /** "Title (source)", at most 120 characters. */
    private static function article_label( array $a ): string {
        $title  = trim( (string) ( $a['title'] ?? '' ) );
        $source = trim( (string) ( $a['source'] ?? '' ) );
        $label  = '' !== $title ? $title : (string) ( $a['url'] ?? '?' );
        if ( '' !== $source ) {
            $label .= ' (' . $source . ')';
        }
        return function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 120 ) : substr( $label, 0, 120 );
    }

    /** One line per cut, for the generation inspector. */
    public static function describe( array $cuts ): array {
        $lines = [];
        foreach ( self::sanitize( $cuts ) as $c ) {
            $item = '' !== $c['item'] ? $c['item'] : '?';
            switch ( $c['reason'] ) {
                case 'max_items':
                    /* translators: 1: item, 2: maximum number of items, 3: number of items there were */
                    $lines[] = isset( $c['needed'] ) && $c['needed'] > ( $c['limit'] ?? 0 )
                        ? sprintf( __( '%1$s: only %2$d of %3$d sent, the rest left out', 'presshub-ai-editor' ), $item, $c['limit'] ?? 0, $c['needed'] )
                        : sprintf( __( '%1$s: left out, only %2$d are sent per call', 'presshub-ai-editor' ), $item, $c['limit'] ?? 0 );
                    break;
                case 'shortened':
                    /* translators: 1: item, 2: parts left out, 3: characters needed in full, 4: budget */
                    $lines[] = sprintf( __( '%1$s: sent without %2$s (needed %3$d characters in full, limit %4$d)', 'presshub-ai-editor' ), $item, implode( ', ', $c['parts'] ?? [] ), $c['needed'] ?? 0, $c['limit'] ?? 0 );
                    break;
                case 'clipped':
                    /* translators: 1: item, 2: characters kept, 3: characters in full, 4: budget */
                    $lines[] = sprintf( __( '%1$s: cut to %2$d of %3$d characters (limit %4$d)', 'presshub-ai-editor' ), $item, $c['kept'] ?? 0, $c['needed'] ?? 0, $c['limit'] ?? 0 );
                    break;
                default:
                    /* translators: 1: item, 2: characters needed, 3: characters left, 4: budget */
                    $lines[] = isset( $c['room'] )
                        ? sprintf( __( '%1$s: left out, needed %2$d characters, %3$d left (limit %4$d)', 'presshub-ai-editor' ), $item, $c['needed'] ?? 0, $c['room'], $c['limit'] ?? 0 )
                        : sprintf( __( '%1$s: left out, needed %2$d characters (limit %3$d)', 'presshub-ai-editor' ), $item, $c['needed'] ?? 0, $c['limit'] ?? 0 );
            }
        }
        return $lines;
    }
}
