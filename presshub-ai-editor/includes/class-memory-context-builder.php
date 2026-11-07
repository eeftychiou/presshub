<?php
/**
 * PressHub_AI_Memory_Context_Builder — Tiered Editorial Memory Prompt Injection Builder.
 *
 * Implements Issue #135:
 *   - Tiered editorial memory retrieval:
 *       Tier 1: Same-Day Morning Digest (noon edition only)
 *       Tier 2: Recent Daily Digests (last N daily days, newest first)
 *       Tier 3: Background Memory (weekly rollups + gap-filling daily digests)
 *       Podcast: Recent opening/closing lines to prevent repetition (target=podcast)
 *   - Status filtering: only 'active' and 'stale' entries (never 'excluded', 'failed', 'pending').
 *   - Budget enforcement & deterministic trimming order:
 *       (1) Oldest background entries first
 *       (2) Oldest recent days next
 *       (3) 'resolved' stories before 'developing' stories
 *   - Zero-silent-fallback logging of trims and missing noon digests.
 *   - Complete manifest creation for persistence in wp_presshub_ai_generation_records.memory_json.
 *   - Clean gating: returns empty text and disabled manifest when memory is off or 0-day window.
 *
 * @package PressHub_AI_Editor
 * @since 2.12.0
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRESSHUB_AI_TESTSUITE' ) ) {
    exit;
}

require_once __DIR__ . '/class-settings-storage.php';
require_once __DIR__ . '/class-memory-store.php';
require_once __DIR__ . '/class-prompt-loader.php';
require_once __DIR__ . '/class-context-estimator.php';

class PressHub_AI_Memory_Context_Builder {

    /**
     * Strip the {memory_context} placeholder cleanly from a prompt template,
     * restoring the exact original trailing newline structure of stock templates.
     *
     * @param string $prompt Prompt string containing {memory_context}.
     * @return string Stripped prompt.
     */
    public static function strip_placeholder( string $prompt ): string {
        if ( false === strpos( $prompt, '{memory_context}' ) ) {
            return $prompt;
        }

        // 1. Curation template on main ended with \r, so \n\r\n{memory_context} was appended.
        if ( false !== strpos( $prompt, "\n\r\n{memory_context}" ) ) {
            return str_replace( "\n\r\n{memory_context}", '', $prompt );
        }

        // 2. Podcast templates on main ended with \n, so \n{memory_context}\n was appended.
        if ( false !== strpos( $prompt, "\n{memory_context}\n" ) ) {
            return str_replace( "\n{memory_context}\n", '', $prompt );
        }

        // 3. Fallbacks for other variations
        return str_replace(
            [
                "\r\n\r\n{memory_context}\r\n",
                "\r\n\r\n{memory_context}",
                "\r\n{memory_context}\r\n",
                "\r\n{memory_context}",
                "\n\n{memory_context}\n",
                "\n\n{memory_context}",
                "\n{memory_context}\n",
                "\n{memory_context}",
                "{memory_context}\n",
                "{memory_context}\r\n",
                "{memory_context}",
            ],
            '',
            $prompt
        );
    }

    /**
     * Sanitize a text field (story title, development, narrative, podcast lines):
     * Collapses CRLF/CR/LF newlines into single spaces, removes '#' headings,
     * and trims redundant whitespace.
     *
     * @param string $text Raw text.
     * @return string Sanitized single-line text.
     */
    public static function sanitize_field( string $text ): string {
        $clean = preg_replace( '/\r\n|\r|\n/', ' ', $text );
        $clean = str_replace( '#', '', (string) $clean );
        $clean = preg_replace( '/\s+/u', ' ', (string) $clean );
        return trim( (string) $clean );
    }

    /**
     * Build the rendered memory context block and audit manifest for prompt injection.
     *
     * @param string   $date            Briefing date ('YYYY-MM-DD').
     * @param string   $edition         'morning' or 'noon'.
     * @param string   $target          'briefing' or 'podcast'.
     * @param int|null $budget_override Optional character budget override.
     * @return array{text:string,used_morning_digest:bool,manifest:array}
     */
    public static function build( string $date = '', string $edition = 'morning', string $target = 'briefing', ?int $budget_override = null ): array {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        $edition = ( 'noon' === $edition ) ? 'noon' : 'morning';
        $target  = ( 'podcast' === $target ) ? 'podcast' : 'briefing';

        $enabled           = PressHub_AI_Settings_Storage::get_memory_enabled();
        $daily_days        = PressHub_AI_Settings_Storage::get_memory_daily_days();
        $background_days   = PressHub_AI_Settings_Storage::get_memory_background_days();
        $configured_budget = ( 'podcast' === $target )
            ? PressHub_AI_Settings_Storage::get_memory_podcast_budget_chars()
            : PressHub_AI_Settings_Storage::get_memory_briefing_budget_chars();
        $budget_chars      = ( null !== $budget_override ) ? max( 0, $budget_override ) : $configured_budget;

        $budgets_summary = [
            'configured' => $configured_budget,
            'effective'  => $budget_chars,
            'shrunk'     => ( $budget_chars < $configured_budget ),
        ];

        // Compute missing daily digests within lookback window (recent + background)
        $missing_digests = [];
        $lookback_days   = max( $daily_days, $background_days );
        if ( $enabled && $lookback_days > 0 ) {
            $yesterday      = gmdate( 'Y-m-d', strtotime( $date . ' -1 day' ) );
            $window_start   = gmdate( 'Y-m-d', strtotime( $date . " -{$lookback_days} days" ) );
            $window_dailies = PressHub_AI_Memory_Store::get_entries_for_range( $window_start, $yesterday, 'daily' );
            $active_dates   = [];
            foreach ( $window_dailies as $wd ) {
                if ( in_array( (string) $wd['status'], [ 'active', 'stale' ], true ) ) {
                    $active_dates[ (string) $wd['period_start'] ] = true;
                }
            }
            $cur_ts = strtotime( $window_start );
            $end_ts = strtotime( $yesterday );
            while ( $cur_ts <= $end_ts ) {
                $cur_ymd = gmdate( 'Y-m-d', $cur_ts );
                if ( ! isset( $active_dates[ $cur_ymd ] ) ) {
                    $missing_digests[] = $cur_ymd;
                }
                $cur_ts = strtotime( '+1 day', $cur_ts );
            }
        }

        // Clean gating: disabled or 0-day window yields empty block and disabled manifest
        if ( ! $enabled || ( 0 === $daily_days && 0 === $background_days ) ) {
            return [
                'text'                => '',
                'used_morning_digest' => false,
                'manifest'            => [
                    'enabled'         => false,
                    'target'          => $target,
                    'date'            => $date,
                    'edition'         => $edition,
                    'daily_days'      => $daily_days,
                    'background_days' => $background_days,
                    'budgets'         => $budgets_summary,
                    'budget_chars'    => $budget_chars,
                    'used_chars'      => 0,
                    'tokens_estimate' => 0,
                    'entries'         => [],
                    'missing_digests' => $missing_digests,
                    'trimmed'         => [],
                    'has_stale'       => false,
                ],
            ];
        }

        // -------------------------------------------------------------
        // Tier 1: Same-Day Morning Digest (noon edition only)
        // -------------------------------------------------------------
        $same_day_entry      = null;
        $used_morning_digest = false;

        if ( 'noon' === $edition ) {
            $same_day_entries    = PressHub_AI_Memory_Store::get_entries_for_range( $date, $date, 'daily' );
            $morning_entry_found = null;
            foreach ( $same_day_entries as $entry ) {
                if ( 'morning' === (string) $entry['edition'] && $date === (string) $entry['period_start'] ) {
                    $morning_entry_found = $entry;
                    break;
                }
            }

            if ( null !== $morning_entry_found ) {
                if ( in_array( (string) $morning_entry_found['status'], [ 'active', 'stale' ], true ) ) {
                    $content     = PressHub_AI_Memory_Store::decode_content( (string) $morning_entry_found['content_json'] );
                    $inc_stories = [];
                    foreach ( ( $content['stories'] ?? [] ) as $st ) {
                        if ( ! empty( $st['include'] ) ) {
                            $inc_stories[] = $st;
                        }
                    }
                    if ( ! empty( $inc_stories ) ) {
                        $same_day_entry      = [
                            'entry'   => $morning_entry_found,
                            'stories' => $inc_stories,
                            'content' => $content,
                        ];
                        $used_morning_digest = true;
                    } else {
                        if ( class_exists( 'PressHub_AI_Logger' ) ) {
                            PressHub_AI_Logger::warning(
                                'Noon edition memory fallback: morning digest has no included stories, falling back to raw morning briefing text',
                                [ 'date' => $date, 'target' => $target ]
                            );
                        }
                    }
                } else {
                    if ( class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::warning(
                            'Noon edition memory fallback: morning digest missing or not active, falling back to raw morning briefing text',
                            [ 'date' => $date, 'target' => $target, 'status' => (string) $morning_entry_found['status'] ]
                        );
                    }
                }
            } else {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning(
                        'Noon edition memory fallback: morning digest missing or not active, falling back to raw morning briefing text',
                        [ 'date' => $date, 'target' => $target ]
                    );
                }
            }
        }

        // -------------------------------------------------------------
        // Tier 2: Recent Daily Digests (newest first)
        // -------------------------------------------------------------
        $recent_entries = [];
        $recent_from    = '';
        $yesterday      = gmdate( 'Y-m-d', strtotime( $date . ' -1 day' ) );

        if ( $daily_days > 0 ) {
            $recent_from = gmdate( 'Y-m-d', strtotime( $date . " -{$daily_days} days" ) );
            $raw_recent  = PressHub_AI_Memory_Store::get_entries_for_range( $recent_from, $yesterday, 'daily' );

            foreach ( $raw_recent as $entry ) {
                if ( ! in_array( (string) $entry['status'], [ 'active', 'stale' ], true ) ) {
                    continue;
                }
                $content     = PressHub_AI_Memory_Store::decode_content( (string) $entry['content_json'] );
                $inc_stories = [];
                foreach ( ( $content['stories'] ?? [] ) as $st ) {
                    if ( ! empty( $st['include'] ) ) {
                        $inc_stories[] = $st;
                    }
                }
                if ( ! empty( $inc_stories ) || ! empty( $content['podcast']['opening_line'] ) || ! empty( $content['podcast']['closing_line'] ) ) {
                    $recent_entries[] = [
                        'entry'   => $entry,
                        'stories' => $inc_stories,
                        'content' => $content,
                    ];
                }
            }

            // Order newest first: period_start DESC, then noon before morning on the same day
            usort( $recent_entries, function( $a, $b ) {
                if ( $a['entry']['period_start'] !== $b['entry']['period_start'] ) {
                    return strcmp( $b['entry']['period_start'], $a['entry']['period_start'] );
                }
                $ed_order = [ 'noon' => 2, 'morning' => 1, '' => 0 ];
                $a_ed     = $ed_order[ $a['entry']['edition'] ?? '' ] ?? 0;
                $b_ed     = $ed_order[ $b['entry']['edition'] ?? '' ] ?? 0;
                return $b_ed <=> $a_ed;
            } );
        }

        // -------------------------------------------------------------
        // Tier 3: Background Memory (weekly rollups + gap daily digests)
        // -------------------------------------------------------------
        $background_entries = [];

        if ( $background_days > $daily_days ) {
            $bg_from = gmdate( 'Y-m-d', strtotime( $date . " -{$background_days} days" ) );
            $bg_to   = ( $daily_days > 0 && '' !== $recent_from )
                ? gmdate( 'Y-m-d', strtotime( $recent_from . ' -1 day' ) )
                : $yesterday;

            $raw_rollups      = PressHub_AI_Memory_Store::get_entries_for_range( $bg_from, $bg_to, 'weekly' );
            $selected_rollups = [];
            $covered_dates    = [];

            foreach ( $raw_rollups as $r_entry ) {
                if ( ! in_array( (string) $r_entry['status'], [ 'active', 'stale' ], true ) ) {
                    continue;
                }
                // Ensure rollup does not bleed into recent window
                if ( $r_entry['period_end'] > $bg_to ) {
                    continue;
                }
                $content     = PressHub_AI_Memory_Store::decode_content( (string) $r_entry['content_json'] );
                $inc_stories = [];
                foreach ( ( $content['stories'] ?? [] ) as $st ) {
                    if ( ! empty( $st['include'] ) ) {
                        $inc_stories[] = $st;
                    }
                }
                if ( ! empty( $content['narrative'] ) || ! empty( $inc_stories ) ) {
                    $selected_rollups[] = [
                        'entry'     => $r_entry,
                        'stories'   => $inc_stories,
                        'narrative' => (string) ( $content['narrative'] ?? '' ),
                        'content'   => $content,
                    ];
                    $curr = $r_entry['period_start'];
                    while ( $curr <= $r_entry['period_end'] ) {
                        $covered_dates[ $curr ] = true;
                        $curr = gmdate( 'Y-m-d', strtotime( $curr . ' +1 day' ) );
                    }
                }
            }

            // Gap-filling daily digests in [$bg_from, $bg_to] not covered by any weekly rollup
            $raw_bg_dailies = PressHub_AI_Memory_Store::get_entries_for_range( $bg_from, $bg_to, 'daily' );
            $gap_dailies    = [];
            foreach ( $raw_bg_dailies as $d_entry ) {
                if ( ! in_array( (string) $d_entry['status'], [ 'active', 'stale' ], true ) ) {
                    continue;
                }
                if ( isset( $covered_dates[ $d_entry['period_start'] ] ) ) {
                    continue;
                }
                $content     = PressHub_AI_Memory_Store::decode_content( (string) $d_entry['content_json'] );
                $inc_stories = [];
                foreach ( ( $content['stories'] ?? [] ) as $st ) {
                    if ( ! empty( $st['include'] ) ) {
                        $inc_stories[] = $st;
                    }
                }
                if ( ! empty( $inc_stories ) ) {
                    $gap_dailies[] = [
                        'entry'   => $d_entry,
                        'stories' => $inc_stories,
                        'content' => $content,
                    ];
                }
            }

            $all_bg = array_merge( $selected_rollups, $gap_dailies );
            usort( $all_bg, function( $a, $b ) {
                $end_a = $a['entry']['period_end'];
                $end_b = $b['entry']['period_end'];
                if ( $end_a !== $end_b ) {
                    return strcmp( $end_b, $end_a ); // DESC
                }
                return strcmp( $b['entry']['period_start'], $a['entry']['period_start'] ); // DESC
            } );
            $background_entries = $all_bg;
        }

        // -------------------------------------------------------------
        // Podcast Opening/Closing Lines (target=podcast only)
        // -------------------------------------------------------------
        $podcast_lines = [];
        if ( 'podcast' === $target ) {
            $candidate_dailies = [];
            if ( null !== $same_day_entry ) {
                $candidate_dailies[] = $same_day_entry;
            }
            foreach ( $recent_entries as $re ) {
                $candidate_dailies[] = $re;
            }
            foreach ( $background_entries as $be ) {
                if ( 'daily' === ( $be['entry']['scope'] ?? '' ) ) {
                    $candidate_dailies[] = $be;
                }
            }
            foreach ( $candidate_dailies as $cd ) {
                $p     = $cd['content']['podcast'] ?? [];
                $open  = self::sanitize_field( (string) ( $p['opening_line'] ?? '' ) );
                $close = self::sanitize_field( (string) ( $p['closing_line'] ?? '' ) );
                if ( '' !== $open || '' !== $close ) {
                    $podcast_lines[] = [
                        'entry_id'     => (int) $cd['entry']['id'],
                        'date'         => (string) $cd['entry']['period_start'],
                        'edition'      => (string) ( $cd['entry']['edition'] ?? '' ),
                        'opening_line' => $open,
                        'closing_line' => $close,
                    ];
                    if ( count( $podcast_lines ) >= 3 ) {
                        break;
                    }
                }
            }
        }

        // -------------------------------------------------------------
        // Render & Trimming Loop
        // -------------------------------------------------------------
        $trimmed  = [];
        $rendered = self::render_text( $same_day_entry, $recent_entries, $background_entries, $podcast_lines, $date, $daily_days, $target );

        if ( '' !== $rendered && ( 0 === $budget_chars || mb_strlen( $rendered, 'UTF-8' ) > $budget_chars ) ) {
            // Trim (1): Oldest background entries first (at end of $background_entries array)
            while ( ! empty( $background_entries ) && ( 0 === $budget_chars || mb_strlen( $rendered, 'UTF-8' ) > $budget_chars ) ) {
                $dropped_bg = array_pop( $background_entries );
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning(
                        'Memory context builder trimmed background entry to fit budget',
                        [
                            'reason'       => 'budget_oldest_background',
                            'entry_id'     => (int) $dropped_bg['entry']['id'],
                            'scope'        => (string) $dropped_bg['entry']['scope'],
                            'period_start' => (string) $dropped_bg['entry']['period_start'],
                            'period_end'   => (string) $dropped_bg['entry']['period_end'],
                        ]
                    );
                }
                $trimmed[] = [
                    'tier'         => 'background',
                    'reason'       => 'budget_oldest_background',
                    'entry_id'     => (int) $dropped_bg['entry']['id'],
                    'scope'        => (string) $dropped_bg['entry']['scope'],
                    'period_start' => (string) $dropped_bg['entry']['period_start'],
                    'period_end'   => (string) $dropped_bg['entry']['period_end'],
                    'edition'      => (string) ( $dropped_bg['entry']['edition'] ?? '' ),
                ];
                $rendered = self::render_text( $same_day_entry, $recent_entries, $background_entries, $podcast_lines, $date, $daily_days, $target );
            }

            // Trim (2) & (3): Oldest recent days next, with resolved stories before developing ones
            while ( ! empty( $recent_entries ) && ( 0 === $budget_chars || mb_strlen( $rendered, 'UTF-8' ) > $budget_chars ) ) {
                $last_idx     = count( $recent_entries ) - 1;
                $target_entry = &$recent_entries[ $last_idx ];

                // If entry has no stories (e.g. podcast lines only), pop it from recent entries without recording a phantom story trim
                if ( empty( $target_entry['stories'] ) ) {
                    array_pop( $recent_entries );
                    $rendered = self::render_text( $same_day_entry, $recent_entries, $background_entries, $podcast_lines, $date, $daily_days, $target );
                    continue;
                }

                // Find resolved story to drop first
                $resolved_idx = null;
                foreach ( $target_entry['stories'] as $s_idx => $s ) {
                    if ( 'resolved' === ( $s['status'] ?? '' ) ) {
                        $resolved_idx = $s_idx;
                        break;
                    }
                }

                if ( null !== $resolved_idx ) {
                    $dropped_story = $target_entry['stories'][ $resolved_idx ];
                    array_splice( $target_entry['stories'], $resolved_idx, 1 );
                    $reason = 'budget_resolved_story';
                } else {
                    $dropped_story = array_shift( $target_entry['stories'] );
                    $reason = 'budget_oldest_recent_story';
                }

                if ( is_array( $dropped_story ) && ! empty( $dropped_story['key'] ) ) {
                    if ( class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::warning(
                            'Memory context builder trimmed story from oldest recent entry to fit budget',
                            [
                                'reason'       => $reason,
                                'entry_id'     => (int) $target_entry['entry']['id'],
                                'period_start' => (string) $target_entry['entry']['period_start'],
                                'story_key'    => (string) ( $dropped_story['key'] ?? '' ),
                                'story_status' => (string) ( $dropped_story['status'] ?? '' ),
                            ]
                        );
                    }

                    $trimmed[] = [
                        'tier'         => 'recent',
                        'reason'       => $reason,
                        'entry_id'     => (int) $target_entry['entry']['id'],
                        'scope'        => (string) $target_entry['entry']['scope'],
                        'period_start' => (string) $target_entry['entry']['period_start'],
                        'period_end'   => (string) $target_entry['entry']['period_end'],
                        'edition'      => (string) ( $target_entry['entry']['edition'] ?? '' ),
                        'story_key'    => (string) ( $dropped_story['key'] ?? '' ),
                        'story_status' => (string) ( $dropped_story['status'] ?? '' ),
                    ];
                }

                if ( empty( $target_entry['stories'] ) ) {
                    array_pop( $recent_entries );
                }

                $rendered = self::render_text( $same_day_entry, $recent_entries, $background_entries, $podcast_lines, $date, $daily_days, $target );
            }

            // If still over budget, trim same_day_entry (if noon)
            if ( null !== $same_day_entry && ( 0 === $budget_chars || mb_strlen( $rendered, 'UTF-8' ) > $budget_chars ) ) {
                while ( ! empty( $same_day_entry['stories'] ) && ( 0 === $budget_chars || mb_strlen( $rendered, 'UTF-8' ) > $budget_chars ) ) {
                    $resolved_idx = null;
                    foreach ( $same_day_entry['stories'] as $s_idx => $s ) {
                        if ( 'resolved' === ( $s['status'] ?? '' ) ) {
                            $resolved_idx = $s_idx;
                            break;
                        }
                    }
                    if ( null !== $resolved_idx ) {
                        $dropped_story = $same_day_entry['stories'][ $resolved_idx ];
                        array_splice( $same_day_entry['stories'], $resolved_idx, 1 );
                        $reason = 'budget_resolved_story';
                    } else {
                        $dropped_story = array_shift( $same_day_entry['stories'] );
                        $reason = 'budget_story';
                    }
                    if ( is_array( $dropped_story ) && ! empty( $dropped_story['key'] ) ) {
                        if ( class_exists( 'PressHub_AI_Logger' ) ) {
                            PressHub_AI_Logger::warning(
                                'Memory context builder trimmed story from same-day entry to fit budget',
                                [
                                    'reason'    => $reason,
                                    'entry_id'  => (int) $same_day_entry['entry']['id'],
                                    'story_key' => (string) ( $dropped_story['key'] ?? '' ),
                                ]
                            );
                        }
                        $trimmed[] = [
                            'tier'         => 'same_day',
                            'reason'       => $reason,
                            'entry_id'     => (int) $same_day_entry['entry']['id'],
                            'scope'        => (string) $same_day_entry['entry']['scope'],
                            'period_start' => (string) $same_day_entry['entry']['period_start'],
                            'period_end'   => (string) $same_day_entry['entry']['period_end'],
                            'edition'      => (string) ( $same_day_entry['entry']['edition'] ?? '' ),
                            'story_key'    => (string) ( $dropped_story['key'] ?? '' ),
                            'story_status' => (string) ( $dropped_story['status'] ?? '' ),
                        ];
                    }
                    $rendered = self::render_text( $same_day_entry, $recent_entries, $background_entries, $podcast_lines, $date, $daily_days, $target );
                }
                if ( empty( $same_day_entry['stories'] ) ) {
                    $same_day_entry = null;
                    $rendered = self::render_text( $same_day_entry, $recent_entries, $background_entries, $podcast_lines, $date, $daily_days, $target );
                }
            }

            // If still over budget, trim podcast lines
            while ( ! empty( $podcast_lines ) && ( 0 === $budget_chars || mb_strlen( $rendered, 'UTF-8' ) > $budget_chars ) ) {
                $dropped_pl = array_pop( $podcast_lines );
                $trimmed[]  = [
                    'tier'     => 'podcast_lines',
                    'reason'   => 'budget_podcast_line',
                    'entry_id' => (int) $dropped_pl['entry_id'],
                    'date'     => (string) $dropped_pl['date'],
                    'edition'  => (string) $dropped_pl['edition'],
                ];
                $rendered = self::render_text( $same_day_entry, $recent_entries, $background_entries, $podcast_lines, $date, $daily_days, $target );
            }

            if ( 0 === $budget_chars ) {
                $rendered = '';
            }
        }

        // -------------------------------------------------------------
        // Manifest Construction
        // -------------------------------------------------------------
        $manifest_entries = [];
        $has_stale        = false;

        if ( null !== $same_day_entry && ! empty( $same_day_entry['stories'] ) ) {
            $stale = ( 'stale' === (string) $same_day_entry['entry']['status'] );
            if ( $stale ) {
                $has_stale = true;
            }
            $entry_block = self::render_same_day_block( $same_day_entry, $date );
            $manifest_entries[] = [
                'id'            => (int) $same_day_entry['entry']['id'],
                'scope'         => (string) $same_day_entry['entry']['scope'],
                'tier'          => 'same_day',
                'period_start'  => (string) $same_day_entry['entry']['period_start'],
                'period_end'    => (string) $same_day_entry['entry']['period_end'],
                'edition'       => (string) $same_day_entry['entry']['edition'],
                'origin'        => (string) $same_day_entry['entry']['origin'],
                'status'        => (string) $same_day_entry['entry']['status'],
                'stale'         => $stale,
                'chars'         => mb_strlen( $entry_block, 'UTF-8' ),
                'version_hash'  => (string) $same_day_entry['entry']['version_hash'],
                'stories_count' => count( $same_day_entry['stories'] ),
                'story_keys'    => array_values( array_column( $same_day_entry['stories'], 'key' ) ),
            ];
        }

        foreach ( $recent_entries as $re ) {
            if ( empty( $re['stories'] ) ) {
                continue;
            }
            $stale = ( 'stale' === (string) $re['entry']['status'] );
            if ( $stale ) {
                $has_stale = true;
            }
            $entry_block = self::render_recent_entry_block( $re );
            $manifest_entries[] = [
                'id'            => (int) $re['entry']['id'],
                'scope'         => (string) $re['entry']['scope'],
                'tier'          => 'recent',
                'period_start'  => (string) $re['entry']['period_start'],
                'period_end'    => (string) $re['entry']['period_end'],
                'edition'       => (string) $re['entry']['edition'],
                'origin'        => (string) $re['entry']['origin'],
                'status'        => (string) $re['entry']['status'],
                'stale'         => $stale,
                'chars'         => mb_strlen( $entry_block, 'UTF-8' ),
                'version_hash'  => (string) $re['entry']['version_hash'],
                'stories_count' => count( $re['stories'] ),
                'story_keys'    => array_values( array_column( $re['stories'], 'key' ) ),
            ];
        }

        foreach ( $background_entries as $be ) {
            $stale = ( 'stale' === (string) $be['entry']['status'] );
            if ( $stale ) {
                $has_stale = true;
            }
            $entry_block = self::render_background_entry_block( $be );
            $manifest_entries[] = [
                'id'            => (int) $be['entry']['id'],
                'scope'         => (string) $be['entry']['scope'],
                'tier'          => 'background',
                'period_start'  => (string) $be['entry']['period_start'],
                'period_end'    => (string) $be['entry']['period_end'],
                'edition'       => (string) ( $be['entry']['edition'] ?? '' ),
                'origin'        => (string) $be['entry']['origin'],
                'status'        => (string) $be['entry']['status'],
                'stale'         => $stale,
                'chars'         => mb_strlen( $entry_block, 'UTF-8' ),
                'version_hash'  => (string) $be['entry']['version_hash'],
                'stories_count' => count( $be['stories'] ),
                'story_keys'    => array_values( array_column( $be['stories'], 'key' ) ),
            ];
        }

        $used_chars = mb_strlen( $rendered, 'UTF-8' );
        $tokens_est = ( class_exists( 'PressHub_AI_Context_Estimator' ) && $used_chars > 0 )
            ? PressHub_AI_Context_Estimator::estimate_tokens( $rendered )
            : 0;

        $manifest = [
            'enabled'             => true,
            'target'              => $target,
            'date'                => $date,
            'edition'             => $edition,
            'daily_days'          => $daily_days,
            'background_days'     => $background_days,
            'budgets'             => $budgets_summary,
            'budget_chars'        => $budget_chars,
            'used_chars'          => $used_chars,
            'tokens_estimate'     => $tokens_est,
            'used_morning_digest' => $used_morning_digest && ( null !== $same_day_entry ),
            'has_stale'           => $has_stale,
            'entries'             => $manifest_entries,
            'missing_digests'     => $missing_digests,
            'trimmed'             => $trimmed,
            'podcast_lines_count' => count( $podcast_lines ),
        ];

        return [
            'text'                => $rendered,
            'used_morning_digest' => $manifest['used_morning_digest'],
            'manifest'            => $manifest,
        ];
    }

    /**
     * Render the block text for a same-day morning digest.
     *
     * @param array  $same_day_entry Same day entry array.
     * @param string $date           Briefing date.
     * @return string
     */
    private static function render_same_day_block( array $same_day_entry, string $date ): string {
        $sec = "### Σημερινή Πρωινή Ενημέρωση ({$date})\n";
        $story_lines = [];
        foreach ( ( $same_day_entry['stories'] ?? [] ) as $story ) {
            $story_lines[] = self::format_story_line( $story );
        }
        return $sec . implode( "\n", $story_lines );
    }

    /**
     * Render the block text for a recent daily digest entry.
     *
     * @param array $re Recent entry array.
     * @return string
     */
    private static function render_recent_entry_block( array $re ): string {
        if ( empty( $re['stories'] ) ) {
            return '';
        }
        $d      = (string) $re['entry']['period_start'];
        $ed     = (string) ( $re['entry']['edition'] ?? '' );
        $ed_str = ( 'noon' === $ed ) ? ' (Μεσημβρινή)' : ( ( 'morning' === $ed ) ? ' (Πρωινή)' : '' );
        $block  = "#### {$d}{$ed_str}\n";
        $story_lines = [];
        foreach ( $re['stories'] as $story ) {
            $story_lines[] = self::format_story_line( $story );
        }
        return $block . implode( "\n", $story_lines );
    }

    /**
     * Render the block text for a background entry (weekly rollup or gap daily).
     *
     * @param array $be Background entry array.
     * @return string
     */
    private static function render_background_entry_block( array $be ): string {
        $scope = (string) ( $be['entry']['scope'] ?? 'daily' );
        if ( 'weekly' === $scope ) {
            $p_start = (string) $be['entry']['period_start'];
            $p_end   = (string) $be['entry']['period_end'];
            $block   = "#### Εβδομαδιαία Ανασκόπηση ({$p_start} έως {$p_end})\n";
            if ( ! empty( $be['narrative'] ) ) {
                $block .= self::sanitize_field( (string) $be['narrative'] ) . "\n";
            }
            if ( ! empty( $be['stories'] ) ) {
                $story_lines = [];
                foreach ( $be['stories'] as $story ) {
                    $story_lines[] = self::format_story_line( $story );
                }
                $block .= implode( "\n", $story_lines );
            }
            return rtrim( $block );
        }

        $d      = (string) $be['entry']['period_start'];
        $ed     = (string) ( $be['entry']['edition'] ?? '' );
        $ed_str = ( 'noon' === $ed ) ? ' (Μεσημβρινή)' : ( ( 'morning' === $ed ) ? ' (Πρωινή)' : '' );
        $block  = "#### {$d}{$ed_str}\n";
        $story_lines = [];
        foreach ( ( $be['stories'] ?? [] ) as $story ) {
            $story_lines[] = self::format_story_line( $story );
        }
        return $block . implode( "\n", $story_lines );
    }

    /**
     * Render memory sections into formatted markdown text with explicit boundary markers.
     */
    private static function render_text( ?array $same_day, array $recent, array $background, array $podcast_lines, string $date, int $daily_days, string $target ): string {
        $has_same_day   = ( null !== $same_day && ! empty( $same_day['stories'] ) );
        $has_recent     = false;
        foreach ( $recent as $re ) {
            if ( ! empty( $re['stories'] ) ) {
                $has_recent = true;
                break;
            }
        }
        $has_background = false;
        foreach ( $background as $be ) {
            if ( ! empty( $be['stories'] ) || ! empty( $be['narrative'] ) ) {
                $has_background = true;
                break;
            }
        }
        $has_podcast_lines = ( 'podcast' === $target && ! empty( $podcast_lines ) );

        if ( ! $has_same_day && ! $has_recent && ! $has_background && ! $has_podcast_lines ) {
            return '';
        }

        $sections = [];

        // Instructions header
        $sections[] = PressHub_AI_Prompt_Loader::get_memory_context_instructions();

        // 1. Same-Day Morning Section
        if ( $has_same_day ) {
            $sections[] = self::render_same_day_block( $same_day, $date );
        }

        // 2. Recent Daily Digests Section
        if ( $has_recent ) {
            $sec = "### Πρόσφατη Επικαιρότητα (Τελευταίες {$daily_days} ημέρες)\n";
            $entry_blocks = [];
            foreach ( $recent as $re ) {
                $b = self::render_recent_entry_block( $re );
                if ( '' !== $b ) {
                    $entry_blocks[] = $b;
                }
            }
            $sec .= implode( "\n\n", $entry_blocks );
            $sections[] = $sec;
        }

        // 3. Background Memory Section
        if ( $has_background ) {
            $sec = "### Ιστορικό Πλαίσιο & Εβδομαδιαίες Ανασκοπήσεις\n";
            $bg_blocks = [];
            foreach ( $background as $be ) {
                $b = self::render_background_entry_block( $be );
                if ( '' !== $b ) {
                    $bg_blocks[] = $b;
                }
            }
            $sec .= implode( "\n\n", $bg_blocks );
            $sections[] = $sec;
        }

        // 4. Podcast Lines Section
        if ( $has_podcast_lines ) {
            $sec  = "### Προηγούμενες Ατάκες Έναρξης & Κλεισίματος Podcast (ΜΗΝ τις επαναλάβετε)\n";
            $sec .= "Οδηγία για τους παρουσιαστές: Μην επαναλάβετε και μην μιμηθείτε τις παρακάτω ατάκες έναρξης και κλεισίματος προηγούμενων επεισοδίων. Δημιουργήστε εντελώς νέες, αυθόρμητες ατάκες για το σημερινό επεισόδιο:\n";
            $lines_out = [];
            foreach ( $podcast_lines as $pl ) {
                $ed_str   = ( 'noon' === $pl['edition'] ) ? ' [Μεσημέρι]' : ( ( 'morning' === $pl['edition'] ) ? ' [Πρωί]' : '' );
                $pl_block = "- Επεισόδιο {$pl['date']}{$ed_str}:";
                if ( '' !== $pl['opening_line'] ) {
                    $pl_block .= "\n  * Έναρξη: \"{$pl['opening_line']}\"";
                }
                if ( '' !== $pl['closing_line'] ) {
                    $pl_block .= "\n  * Κλείσιμο: \"{$pl['closing_line']}\"";
                }
                $lines_out[] = $pl_block;
            }
            $sec .= implode( "\n", $lines_out );
            $sections[] = $sec;
        }

        if ( empty( $sections ) ) {
            return '';
        }

        $body = implode( "\n\n", $sections );
        return "=== ΑΡΧΗ ΣΥΝΤΑΚΤΙΚΗΣ ΜΝΗΜΗΣ ===\n\n" . $body . "\n\n=== ΤΕΛΟΣ ΣΥΝΤΑΚΤΙΚΗΣ ΜΝΗΜΗΣ ===";
    }

    /**
     * Format a single story item into concise Greek line with status label.
     */
    private static function format_story_line( array $story ): string {
        $key    = isset( $story['key'] ) ? self::sanitize_field( (string) $story['key'] ) : '';
        $title  = isset( $story['title'] ) ? self::sanitize_field( (string) $story['title'] ) : '';
        $status = isset( $story['status'] ) ? trim( (string) $story['status'] ) : '';
        $dev    = isset( $story['development'] ) ? self::sanitize_field( (string) $story['development'] ) : '';

        $status_labels = [
            'new'        => 'Νέο θέμα',
            'developing' => 'Σε εξέλιξη',
            'resolved'   => 'Ολοκληρώθηκε',
        ];
        $status_label = $status_labels[ $status ] ?? $status;

        $line = "- [{$key}] {$title} ({$status_label}): {$dev}";
        if ( ! empty( $story['entities'] ) && is_array( $story['entities'] ) ) {
            $sanitized_entities = array_map( [ __CLASS__, 'sanitize_field' ], $story['entities'] );
            $line .= ' (Πρόσωπα/Φορείς: ' . implode( ', ', $sanitized_entities ) . ')';
        }

        return $line;
    }
}
