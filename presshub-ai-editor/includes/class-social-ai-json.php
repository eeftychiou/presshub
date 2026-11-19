<?php
/**
 * JSON answers of the social planners (TikTok/YouTube shorts script, AI post images):
 * reading a model's JSON, repairing a misplaced bracket, and marking an answer that
 * could not be used as an error on its generation record and its Usage & logs row.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'PressHub_Social_AI_Json' ) ) {
    return;
}

class PressHub_Social_AI_Json {

    /**
     * Decode the JSON object in a model's answer (code fences and text around it are ignored).
     *
     * When it does not decode, the bracket structure is repaired (see repair()).
     *
     * @param string      $raw   The answer.
     * @param array       $fixes Filled with what was repaired (empty when nothing).
     * @param string|null $error Filled with why it could not be read, when null is returned.
     * @return array|null The decoded object or list, or null.
     */
    public static function decode( string $raw, array &$fixes = [], ?string &$error = null ): ?array {
        $fixes = [];
        $error = null;
        $text  = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/iu', '', trim( $raw ) );
        $decoded = json_decode( $text, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( false === $start || false === $end || $end <= $start ) {
            $error = 'the AI response is not JSON.';
            return null;
        }
        $json    = substr( $text, $start, $end - $start + 1 );
        $decoded = json_decode( $json, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }
        $json_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Syntax error';
        $repaired   = [];
        $decoded    = json_decode( self::repair( $json, $repaired ), true );
        if ( ! is_array( $decoded ) ) {
            $error = sprintf( 'the AI response is not valid JSON (%s).', $json_error );
            return null;
        }
        $fixes = $repaired;
        return $decoded;
    }

    /**
     * Repair the bracket structure of almost-valid JSON from a model.
     *
     * Outside strings, a closing bracket that does not match the innermost open one is
     * dropped, and so is a comma right before a closing bracket. Strings and values are
     * never changed. A response with brackets still open at the end (or cut off inside a
     * string) was cut off, so it is returned unchanged: closing it would silently turn
     * it into a shorter plan.
     *
     * @param array $fixes Filled with what was repaired, e.g. "dropped 1 misplaced ]".
     */
    public static function repair( string $json, array &$fixes = [] ): string {
        $fixes     = [];
        $out       = '';
        $stack     = [];
        $in_string = false;
        $escaped   = false;
        $dropped   = [ '}' => 0, ']' => 0, ',' => 0 ];
        $length    = strlen( $json );
        for ( $i = 0; $i < $length; $i++ ) {
            $ch = $json[ $i ];
            if ( $in_string ) {
                $out .= $ch;
                if ( $escaped ) {
                    $escaped = false;
                } elseif ( '\\' === $ch ) {
                    $escaped = true;
                } elseif ( '"' === $ch ) {
                    $in_string = false;
                }
                continue;
            }
            if ( '"' === $ch ) {
                $in_string = true;
                $out      .= $ch;
            } elseif ( '{' === $ch || '[' === $ch ) {
                $stack[] = '{' === $ch ? '}' : ']';
                $out    .= $ch;
            } elseif ( '}' === $ch || ']' === $ch ) {
                if ( empty( $stack ) || end( $stack ) !== $ch ) {
                    $dropped[ $ch ]++; // A stray or misplaced closer.
                    continue;
                }
                array_pop( $stack );
                $out = rtrim( $out );
                if ( str_ends_with( $out, ',' ) ) {
                    $out = substr( $out, 0, -1 );
                    $dropped[',']++;
                }
                $out .= $ch;
            } else {
                $out .= $ch;
            }
        }
        if ( $in_string || ! empty( $stack ) ) {
            return $json; // Cut off: nothing safe to repair.
        }
        foreach ( [ ']' => 'misplaced ]', '}' => 'misplaced }', ',' => 'trailing comma' ] as $key => $label ) {
            if ( $dropped[ $key ] > 0 ) {
                $fixes[] = sprintf( 'dropped %d %s', $dropped[ $key ], $label );
            }
        }
        return $out;
    }

    /**
     * The Usage & logs row of the provider call that just finished (0 when none).
     */
    public static function last_log_id(): int {
        $last = class_exists( 'PressHub_AI_Token_Logger' ) && method_exists( 'PressHub_AI_Token_Logger', 'get_last_llm_request' )
            ? PressHub_AI_Token_Logger::get_last_llm_request()
            : null;
        return (int) ( $last['log_id'] ?? 0 );
    }

    /**
     * The call answered, but its answer could not be used: show that as an error on its
     * generation record and its Usage & logs row, which both said "success" because the
     * provider call itself worked.
     */
    public static function mark_unusable( int $record_id, int $log_id, WP_Error $error ): void {
        $message = 'Response not usable: ' . $error->get_error_message();
        if ( $record_id > 0 && class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            PressHub_AI_Generation_Recorder::update_record( $record_id, [ 'status' => 'error', 'error_message' => $message ] );
        }
        if ( $log_id > 0 && class_exists( 'PressHub_AI_Token_Logger' ) && method_exists( 'PressHub_AI_Token_Logger', 'mark_failed' ) ) {
            PressHub_AI_Token_Logger::mark_failed( $log_id, $message );
        }
    }

    /**
     * Note a repaired answer on its (successful) generation record.
     */
    public static function note_repair( int $record_id, array $fixes ): string {
        $note = 'JSON repaired: ' . implode( ', ', $fixes ) . '.';
        if ( $record_id > 0 && class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            PressHub_AI_Generation_Recorder::update_record( $record_id, [ 'error_message' => $note ] );
        }
        return $note;
    }

    /**
     * " See generation record #1." / " See generation records #1, #2." ('' for none).
     *
     * @param int[] $record_ids
     */
    public static function records_suffix( array $record_ids ): string {
        $record_ids = array_values( array_filter( array_map( 'intval', $record_ids ) ) );
        if ( empty( $record_ids ) ) {
            return '';
        }
        return sprintf(
            ' See generation %s %s.',
            1 === count( $record_ids ) ? 'record' : 'records',
            implode( ', ', array_map( fn( $id ) => '#' . $id, $record_ids ) )
        );
    }

    /**
     * The user prompt of a retry: the same prompt plus why the last answer was unusable.
     */
    public static function retry_prompt( string $user_prompt, WP_Error $error ): string {
        return $user_prompt . "\n\n" . sprintf( 'Your previous answer was unusable: %s Reply with valid JSON only, in the format asked above.', $error->get_error_message() );
    }
}
