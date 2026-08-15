<?php
/**
 * TDD tests for PressHub_AI_Preset_Sanitizer.
 *
 * Covers:
 *   - Shape coercion: non-array rows dropped, missing slug/instruction_text dropped.
 *   - Slug regex: only [a-z0-9-]{1,40} passes; illegal slugs rejected.
 *   - Length limits: name <= 80 chars, instruction_text <= 4000 chars.
 *   - Enabled coerced to bool.
 *   - Dedupe by slug (first wins).
 *   - Single-preset sanitization (sanitize_preset) shares the same rules.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-preset-sanitizer.php';

class PresetSanitizerTest
{
    public static function run(): void {
        $failures = [];

        // Case 1: happy path — clean row passes through unchanged.
        $clean = [
            'slug'             => 'wire-style',
            'name'             => 'Wire service concise',
            'instruction_text' => 'Lead with the news.',
            'enabled'          => true,
        ];
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [ $clean ] );
        if ( count( $out ) !== 1 ) {
            $failures[] = "Clean row should pass through (got " . count( $out ) . " rows).";
        } elseif ( $out[0]['slug'] !== 'wire-style' ) {
            $failures[] = "Clean row slug lost. Got: " . var_export( $out[0], true );
        } elseif ( $out[0]['name'] !== 'Wire service concise' ) {
            $failures[] = "Clean row name lost. Got: " . var_export( $out[0], true );
        } elseif ( $out[0]['instruction_text'] !== 'Lead with the news.' ) {
            $failures[] = "Clean row instruction_text lost.";
        } elseif ( $out[0]['enabled'] !== true ) {
            $failures[] = "Clean row enabled should be bool true.";
        }

        // Case 2: non-array rows are dropped (scalar rows).
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [
            'just a string',
            42,
            null,
            $clean,
        ] );
        if ( count( $out ) !== 1 ) {
            $failures[] = "Non-array rows should be dropped (got " . count( $out ) . ").";
        }

        // Case 3: row missing slug is dropped.
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [
            [ 'instruction_text' => 'no slug here', 'name' => 'No slug' ],
            $clean,
        ] );
        if ( count( $out ) !== 1 || $out[0]['slug'] !== 'wire-style' ) {
            $failures[] = "Rows missing slug should be dropped. Got: " . var_export( $out, true );
        }

        // Case 4: row missing instruction_text is dropped.
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [
            [ 'slug' => 'has-slug', 'name' => 'No text' ],
            $clean,
        ] );
        if ( count( $out ) !== 1 || $out[0]['slug'] !== 'wire-style' ) {
            $failures[] = "Rows missing instruction_text should be dropped. Got: " . var_export( $out, true );
        }

        // Case 5: illegal slug (uppercase, spaces, too long, special chars) is rejected.
        $bad_slugs = [
            'Has-Spaces',
            'has_underscore',
            'has.dot',
            '',
            str_repeat( 'a', 41 ), // 41 chars
            'has/slash',
            'sümlaut',
        ];
        $rows = [];
        foreach ( $bad_slugs as $s ) {
            $rows[] = [ 'slug' => $s, 'instruction_text' => 'x', 'name' => 'n' ];
        }
        $rows[] = $clean;
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( $rows );
        if ( count( $out ) !== 1 ) {
            $failures[] = "Illegal slugs should be rejected (got " . count( $out ) . " surviving). Got: " . var_export( array_column( $out, 'slug' ), true );
        }

        // Case 6: slug at the boundaries (1 char and exactly 40 chars) passes.
        $short = [ 'slug' => 'a', 'instruction_text' => 'one', 'name' => 'Short' ];
        $long  = [ 'slug' => str_repeat( 'a', 40 ), 'instruction_text' => 'two', 'name' => 'Long' ];
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [ $short, $long ] );
        if ( count( $out ) !== 2 ) {
            $failures[] = "Slugs of 1 and 40 chars should pass (got " . count( $out ) . ").";
        }

        // Case 7: name > 80 chars gets truncated to 80.
        $long_name = str_repeat( 'n', 200 );
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [
            [ 'slug' => 'a', 'name' => $long_name, 'instruction_text' => 'x' ],
        ] );
        if ( count( $out ) !== 1 ) {
            $failures[] = "Long-name row should not be dropped (got " . count( $out ) . ").";
        } elseif ( strlen( $out[0]['name'] ) !== 80 ) {
            $failures[] = "Name should be truncated to 80 chars (got " . strlen( $out[0]['name'] ) . ").";
        }

        // Case 8: instruction_text > 4000 chars gets truncated to 4000.
        $long_text = str_repeat( 'x', 5000 );
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [
            [ 'slug' => 'a', 'name' => 'n', 'instruction_text' => $long_text ],
        ] );
        if ( count( $out ) !== 1 ) {
            $failures[] = "Long-text row should not be dropped (got " . count( $out ) . ").";
        } elseif ( strlen( $out[0]['instruction_text'] ) !== 4000 ) {
            $failures[] = "instruction_text should be truncated to 4000 chars (got " . strlen( $out[0]['instruction_text'] ) . ").";
        }

        // Case 9: enabled is coerced to bool.
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [
            [ 'slug' => 'a', 'instruction_text' => 'x', 'enabled' => 1 ],
            [ 'slug' => 'b', 'instruction_text' => 'x', 'enabled' => 'false' ], // any truthy string -> true
            [ 'slug' => 'c', 'instruction_text' => 'x', 'enabled' => 0 ],
        ] );
        if ( $out[0]['enabled'] !== true ) {
            $failures[] = "enabled=1 should coerce to bool true.";
        }
        if ( $out[1]['enabled'] !== true ) {
            $failures[] = "enabled='false' (non-empty string) should coerce to bool true.";
        }
        if ( $out[2]['enabled'] !== false ) {
            $failures[] = "enabled=0 should coerce to bool false.";
        }

        // Case 10: missing enabled defaults to true.
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [
            [ 'slug' => 'a', 'instruction_text' => 'x' ],
        ] );
        if ( $out[0]['enabled'] !== true ) {
            $failures[] = "Missing enabled should default to true (got " . var_export( $out[0]['enabled'], true ) . ").";
        }

        // Case 11: dedupe by slug — first wins.
        $first  = [ 'slug' => 'dup', 'instruction_text' => 'first',  'name' => 'First'  ];
        $second = [ 'slug' => 'dup', 'instruction_text' => 'second', 'name' => 'Second' ];
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [ $first, $second ] );
        if ( count( $out ) !== 1 ) {
            $failures[] = "Duplicate slugs should collapse to one row (got " . count( $out ) . ").";
        } elseif ( $out[0]['instruction_text'] !== 'first' ) {
            $failures[] = "First duplicate should win (got instruction_text='" . $out[0]['instruction_text'] . "').";
        } elseif ( $out[0]['name'] !== 'First' ) {
            $failures[] = "First duplicate should win (got name='" . $out[0]['name'] . "').";
        }

        // Case 12: empty input returns empty array.
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( [] );
        if ( $out !== [] ) {
            $failures[] = "Empty input should return empty array.";
        }

        // Case 13: non-array input returns empty array.
        $out = PressHub_AI_Preset_Sanitizer::sanitize_presets( null );
        if ( $out !== [] ) {
            $failures[] = "Null input should return empty array.";
        }

        // Case 14: sanitize_preset() single-row helper applies the same rules.
        $row = PressHub_AI_Preset_Sanitizer::sanitize_preset( $clean );
        if ( ! is_array( $row ) || $row['slug'] !== 'wire-style' ) {
            $failures[] = "sanitize_preset() should return the sanitized row.";
        }
        $bad = PressHub_AI_Preset_Sanitizer::sanitize_preset( [ 'slug' => 'Bad_Slug', 'instruction_text' => 'x' ] );
        if ( $bad !== null ) {
            $failures[] = "sanitize_preset() should return null for an illegal slug.";
        }
        $bad2 = PressHub_AI_Preset_Sanitizer::sanitize_preset( 'not an array' );
        if ( $bad2 !== null ) {
            $failures[] = "sanitize_preset() should return null for non-array input.";
        }
        $bad3 = PressHub_AI_Preset_Sanitizer::sanitize_preset( [ 'slug' => 'ok', 'instruction_text' => 'x', 'name' => str_repeat( 'z', 500 ) ] );
        if ( $bad3 === null || strlen( $bad3['name'] ) !== 80 ) {
            $failures[] = "sanitize_preset() should truncate name to 80 chars.";
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }
}

PresetSanitizerTest::run();