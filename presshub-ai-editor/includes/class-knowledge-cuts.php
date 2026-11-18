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
    ];

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

    /** One line per cut, for the generation inspector. */
    public static function describe( array $cuts ): array {
        $lines = [];
        foreach ( self::sanitize( $cuts ) as $c ) {
            $item = '' !== $c['item'] ? $c['item'] : '?';
            switch ( $c['reason'] ) {
                case 'max_items':
                    /* translators: 1: item, 2: maximum number of items */
                    $lines[] = sprintf( __( '%1$s: left out, only %2$d are sent per call', 'presshub-ai-editor' ), $item, $c['limit'] ?? 0 );
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
