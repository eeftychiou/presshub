<?php
/**
 * Sanitizes preset arrays before they are stored or returned to a caller.
 *
 * Two public methods:
 *   - sanitize_presets( array $rows ): array  — list-level sanitization.
 *   - sanitize_preset(  mixed  $row ): ?array — single-row sanitization.
 *
 * Sanitization pipeline (per the 2026-08-15 design doc §1.4):
 *   1. Cast entry to array (non-arrays dropped at list level, return null
 *      at single-row level).
 *   2. Drop entries missing 'slug' or 'instruction_text'.
 *   3. Regex-validate slug against [a-z0-9-]{1,40}; illegal slugs dropped.
 *   4. Truncate 'name' to 80 chars, 'instruction_text' to 4000 chars.
 *   5. Coerce 'enabled' to bool; default true when missing.
 *   6. Dedupe by slug (first wins).
 *
 * No state, no side effects — pure functions, easy to unit test.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Preset_Sanitizer {

    const SLUG_REGEX          = '/^[a-z0-9-]{1,40}$/';
    const MAX_NAME_LENGTH     = 80;
    const MAX_INSTRUCTION_LEN = 4000;

    /**
     * Sanitize a list of preset rows. Returns an indexed array of rows.
     *
     * @param mixed $rows
     * @return array
     */
    public static function sanitize_presets( $rows ): array {
        if ( ! is_array( $rows ) ) {
            return [];
        }

        $out      = [];
        $seen     = [];

        foreach ( $rows as $row ) {
            $clean = self::sanitize_preset( $row );
            if ( $clean === null ) {
                continue;
            }
            // Dedupe by slug — first occurrence wins.
            if ( isset( $seen[ $clean['slug'] ] ) ) {
                continue;
            }
            $seen[ $clean['slug'] ] = true;
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * Sanitize a single preset row. Returns the cleaned row or null when
     * the row cannot be salvaged (non-array, missing required fields,
     * illegal slug).
     *
     * @param mixed $row
     * @return array|null
     */
    public static function sanitize_preset( $row ): ?array {
        if ( ! is_array( $row ) ) {
            return null;
        }

        $slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';
        $text = isset( $row['instruction_text'] ) ? (string) $row['instruction_text'] : '';

        if ( $slug === '' || $text === '' ) {
            return null;
        }
        if ( ! preg_match( self::SLUG_REGEX, $slug ) ) {
            return null;
        }

        $name = isset( $row['name'] ) ? (string) $row['name'] : '';
        if ( strlen( $name ) > self::MAX_NAME_LENGTH ) {
            $name = substr( $name, 0, self::MAX_NAME_LENGTH );
        }

        if ( strlen( $text ) > self::MAX_INSTRUCTION_LEN ) {
            $text = substr( $text, 0, self::MAX_INSTRUCTION_LEN );
        }

        $enabled = isset( $row['enabled'] ) ? (bool) $row['enabled'] : true;

        return [
            'slug'             => $slug,
            'name'             => $name,
            'instruction_text' => $text,
            'enabled'          => $enabled,
        ];
    }
}