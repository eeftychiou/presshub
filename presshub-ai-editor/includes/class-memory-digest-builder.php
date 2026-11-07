<?php
/**
 * Builder and workflow coordinator for PressHub Daily Memory Digests.
 *
 * Implements Issue #134:
 *   - Automatic single-event cron scheduling when a briefing text post is created/updated.
 *   - Extracts structured daily memory digests (stories, entities, slugs, source_urls).
 *   - Feeds the previous 7 days' story keys/titles into the prompt for continuous thread tracking.
 *   - Retries once on invalid JSON / schema validation errors with the error message appended.
 *   - Records every LLM attempt as a 'memory_digest' generation record in PressHub_AI_Generation_Recorder.
 *   - Edit-protection invariant: respects human edits during regeneration/backfill without overwriting content_json.
 *   - Resumable, idempotent backfill workflow (Cron + WP-CLI + Admin Hub UI).
 *   - Honors presshub_ai_memory_enabled gate.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-settings-storage.php';
require_once __DIR__ . '/class-prompt-loader.php';
require_once __DIR__ . '/class-memory-store.php';

class PressHub_AI_Memory_Digest_Builder {

    const CRON_HOOK             = 'presshub_ai_memory_build_digest';
    const BACKFILL_CRON_HOOK    = 'presshub_ai_memory_backfill_step';
    const BACKFILL_STATE_OPTION = 'presshub_ai_memory_backfill_state';

    /**
     * Register WordPress action hooks and WP-CLI commands.
     *
     * @return void
     */
    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'handle_cron_build_digest' ], 10, 1 );
        add_action( self::BACKFILL_CRON_HOOK, [ __CLASS__, 'handle_backfill_step' ] );
        add_action( 'save_post_post', [ __CLASS__, 'on_save_briefing_post' ], 20, 3 );

        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
            WP_CLI::add_command( 'presshub memory backfill', [ __CLASS__, 'cli_backfill' ] );
        }
    }

    /**
     * Schedule a background single event cron job to build daily memory digest for a post.
     *
     * @param int $post_id Post ID.
     * @param int $delay   Seconds delay before execution (default 30).
     * @return bool True if scheduled or already scheduled, false if skipped/invalid.
     */
    public static function schedule_for_post( int $post_id, int $delay = 30 ): bool {
        if ( ! class_exists( 'PressHub_AI_Settings_Storage' ) || ! PressHub_AI_Settings_Storage::get_memory_enabled() ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info(
                    'Memory digest scheduling skipped: editorial memory is disabled',
                    [ 'post_id' => $post_id ]
                );
            }
            return false;
        }

        $briefing_type = (string) get_post_meta( $post_id, '_presshub_briefing_type', true );
        if ( 'text' !== $briefing_type ) {
            return false;
        }

        if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
            return true;
        }

        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + max( 0, $delay ), self::CRON_HOOK, [ $post_id ] );
            return true;
        }

        return false;
    }

    /**
     * Handle save_post_post hook for briefing text posts.
     *
     * @param int          $post_id
     * @param WP_Post|null $post
     * @param bool|null    $update
     * @return void
     */
    public static function on_save_briefing_post( $post_id, $post = null, $update = null ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }

        if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( function_exists( 'get_post_status' ) && 'trash' === get_post_status( $post_id ) ) {
            return;
        }

        $type = (string) get_post_meta( $post_id, '_presshub_briefing_type', true );
        if ( 'text' !== $type ) {
            return;
        }

        self::schedule_for_post( $post_id, 30 );
    }

    /**
     * Handle single-event cron execution for building a daily digest.
     *
     * @param int $post_id
     * @return void
     */
    public static function handle_cron_build_digest( int $post_id ): void {
        self::build_for_post( (int) $post_id );
    }

    /**
     * Strip optional markdown code fences from JSON response text.
     *
     * @param string $raw
     * @return string
     */
    public static function clean_json_response( string $raw ): string {
        $cleaned = trim( $raw );
        if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/is', $cleaned, $matches ) ) {
            $cleaned = trim( $matches[1] );
        }
        return $cleaned;
    }

    /**
     * Build daily memory digest for a given briefing post.
     *
     * @param int                         $post_id
     * @param PressHub_AI_API_Client|null $api_client Optional API client.
     * @param bool                        $force      Whether to force rebuild even if already active.
     * @return array|WP_Error Status array on success/skip, or WP_Error on failure.
     */
    public static function build_for_post( int $post_id, ?PressHub_AI_API_Client $api_client = null, bool $force = false ) {
        if ( ! class_exists( 'PressHub_AI_Settings_Storage' ) || ! PressHub_AI_Settings_Storage::get_memory_enabled() ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info(
                    'Memory digest build skipped: editorial memory is disabled',
                    [ 'post_id' => $post_id ]
                );
            }
            return [ 'status' => 'skipped', 'reason' => 'memory_disabled' ];
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'post_not_found', __( 'Post not found.', 'presshub-ai-editor' ) );
        }

        $date = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        if ( empty( $date ) ) {
            $date = ( function_exists( 'get_the_date' ) ? get_the_date( 'Y-m-d', $post_id ) : '' )
                ?: ( ! empty( $post->post_date ) ? substr( (string) $post->post_date, 0, 10 ) : ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ) ) );
        }

        $raw_edition = (string) get_post_meta( $post_id, '_presshub_briefing_edition', true );
        $edition     = ( 'noon' === $raw_edition ) ? 'noon' : 'morning';

        // Check if existing active digest exists
        $existing_entry = PressHub_AI_Memory_Store::get_daily_entry( $date, $edition );
        if ( ! $force && $existing_entry && in_array( (string) $existing_entry['status'], [ 'active', 'excluded' ], true ) ) {
            return [
                'status' => 'skipped',
                'reason' => 'already_built',
                'id'     => (int) $existing_entry['id'],
            ];
        }

        // Load briefing text
        $briefing_text = '';
        if ( class_exists( 'PressHub_AI_News_Harvester' ) ) {
            $harvester    = new PressHub_AI_News_Harvester();
            $snapshot_dir = $harvester->get_snapshot_dir( $date );
            $filename     = ( 'noon' === $edition ) ? 'briefing-text-noon.md' : 'briefing-text.md';
            $snap_file    = trailingslashit( $snapshot_dir ) . $filename;
            if ( file_exists( $snap_file ) && is_readable( $snap_file ) ) {
                $briefing_text = trim( (string) file_get_contents( $snap_file ) );
            }
        }

        if ( '' === $briefing_text ) {
            $briefing_text = trim( (string) ( $post->post_content ?? '' ) );
            if ( ! empty( $post->post_title ) && ! str_contains( $briefing_text, (string) $post->post_title ) ) {
                $briefing_text = '# ' . $post->post_title . "\n\n" . $briefing_text;
            }
        }

        if ( '' === $briefing_text ) {
            return new WP_Error( 'empty_briefing_post', __( 'Briefing post content is empty.', 'presshub-ai-editor' ) );
        }

        // Gather existing story keys & titles from previous 7 days
        $from_7d        = gmdate( 'Y-m-d', strtotime( $date . ' -7 days' ) );
        $recent_entries = PressHub_AI_Memory_Store::get_entries_for_range( $from_7d, $date, 'daily' );
        $existing_keys  = [];
        foreach ( $recent_entries as $rentry ) {
            // Exclude the current ($date, $edition) entry itself
            if ( (string) $rentry['period_start'] === $date && (string) $rentry['edition'] === $edition ) {
                continue;
            }
            if ( ! in_array( (string) $rentry['status'], [ 'active', 'stale' ], true ) ) {
                continue;
            }
            $rcontent = PressHub_AI_Memory_Store::decode_content( (string) $rentry['content_json'] );
            if ( ! empty( $rcontent['stories'] ) && is_array( $rcontent['stories'] ) ) {
                foreach ( $rcontent['stories'] as $story ) {
                    if ( ! empty( $story['key'] ) && ! empty( $story['title'] ) ) {
                        $existing_keys[ (string) $story['key'] ] = (string) $story['title'];
                    }
                }
            }
        }

        $existing_keys_str = '';
        if ( ! empty( $existing_keys ) ) {
            $lines = [];
            foreach ( $existing_keys as $k => $t ) {
                $lines[] = "- {$k}: {$t}";
            }
            $existing_keys_str = implode( "\n", $lines );
        } else {
            $existing_keys_str = 'Κανένα προϋπάρχον θέμα (καμία καταγεγραμμένη είδηση τις προηγούμενες 7 ημέρες).';
        }

        $edition_label = ( 'noon' === $edition ) ? 'Μεσημβρινή Ενημέρωση' : 'Πρωινή Ενημέρωση';
        $sys_prompt    = PressHub_AI_Prompt_Loader::get_daily_digest_prompt();
        $sys_prompt    = str_replace(
            [ '{date}', '{edition}', '{existing_story_keys}' ],
            [ $date, $edition_label, $existing_keys_str ],
            $sys_prompt
        );

        $user_prompt = "Ημερομηνία: {$date}\nΈκδοση: {$edition_label}\n\nΠροϋπάρχοντα θέματα προηγούμενων 7 ημερών:\n{$existing_keys_str}\n\nΚείμενο Ενημέρωσης:\n{$briefing_text}";

        $briefing_chars      = function_exists( 'mb_strlen' ) ? mb_strlen( $briefing_text ) : strlen( $briefing_text );
        $existing_keys_count = count( $existing_keys );

        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'briefing_memory' );
        }

        // Attempt 1
        $res1        = self::call_and_record( $api_client, $sys_prompt, $user_prompt, $date, $edition, $post_id, 1, $briefing_chars, $existing_keys_count );
        $raw1        = $res1['response'];
        $rec_id1     = $res1['record_id'];
        $last_rec_id = $rec_id1;

        $validated = null;
        $val_error = null;

        if ( is_wp_error( $raw1 ) ) {
            $val_error = $raw1;
        } else {
            $cleaned1 = self::clean_json_response( (string) $raw1 );
            $decoded1 = json_decode( $cleaned1, true );
            if ( ! is_array( $decoded1 ) ) {
                $val_error = new WP_Error(
                    'invalid_memory_json',
                    __( 'Content must be valid JSON.', 'presshub-ai-editor' ),
                    [ 'raw' => substr( $cleaned1, 0, 200 ) ]
                );
            } else {
                $decoded1['narrative'] = '';
                $check1 = PressHub_AI_Memory_Store::validate_content( $decoded1 );
                if ( is_wp_error( $check1 ) ) {
                    $val_error = $check1;
                } else {
                    $validated = $check1;
                }
            }
        }

        // Attempt 2 (Retry on validation error)
        if ( null !== $val_error ) {
            if ( $rec_id1 > 0 && class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
                PressHub_AI_Generation_Recorder::update_record( $rec_id1, [
                    'status'        => 'error',
                    'error_message' => $val_error->get_error_message(),
                ] );
            }

            $user_prompt_retry = $user_prompt . "\n\n---\nΠΡΟΗΓΟΥΜΕΝΟ ΣΦΑΛΜΑ ΕΠΙΚΥΡΩΣΗΣ JSON: " . $val_error->get_error_message() . ' (' . $val_error->get_error_code() . '). Παρακαλώ διόρθωσε το JSON ώστε να συμμορφώνεται αυστηρά με το σχήμα και επέστρεψε ΜΟΝΟ έγκυρο JSON.';

            $res2        = self::call_and_record( $api_client, $sys_prompt, $user_prompt_retry, $date, $edition, $post_id, 2, $briefing_chars, $existing_keys_count );
            $raw2        = $res2['response'];
            $rec_id2     = $res2['record_id'];
            $last_rec_id = $rec_id2;

            if ( is_wp_error( $raw2 ) ) {
                $val_error = $raw2;
            } else {
                $cleaned2 = self::clean_json_response( (string) $raw2 );
                $decoded2 = json_decode( $cleaned2, true );
                if ( ! is_array( $decoded2 ) ) {
                    $val_error = new WP_Error(
                        'invalid_memory_json',
                        __( 'Content must be valid JSON.', 'presshub-ai-editor' ),
                        [ 'raw' => substr( $cleaned2, 0, 200 ) ]
                    );
                } else {
                    $decoded2['narrative'] = '';
                    $check2 = PressHub_AI_Memory_Store::validate_content( $decoded2 );
                    if ( is_wp_error( $check2 ) ) {
                        $val_error = $check2;
                    } else {
                        $validated = $check2;
                        $val_error = null;
                    }
                }
            }
        }

        // If still errored after retry
        if ( null !== $val_error ) {
            $err_msg = $val_error->get_error_message();
            if ( $last_rec_id > 0 && class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
                PressHub_AI_Generation_Recorder::update_record( $last_rec_id, [
                    'status'        => 'error',
                    'error_message' => $err_msg,
                ] );
            }

            PressHub_AI_Memory_Store::record_failed_digest( $date, $edition, $err_msg, $post_id, $last_rec_id );

            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error(
                    'Memory digest build failed after validation/retry',
                    [
                        'post_id' => $post_id,
                        'date'    => $date,
                        'edition' => $edition,
                        'error'   => $err_msg,
                    ]
                );
            }

            return new WP_Error( 'memory_digest_failed', $err_msg, [ 'generation_record_id' => $last_rec_id ] );
        }

        // Deterministically populate podcast lines from existing script or existing entry
        $podcast_lines_set = false;
        if ( class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            $producer = new PressHub_AI_Podcast_Producer();
            $script   = $producer->get_script( $date, $edition );
            if ( ! empty( $script ) ) {
                $turns = $producer->parse_script_turns( $script );
                if ( ! empty( $turns ) ) {
                    $first_turn = reset( $turns );
                    $last_turn  = end( $turns );
                    $validated['podcast']['opening_line'] = trim( (string) ( $first_turn['text'] ?? '' ) );
                    $validated['podcast']['closing_line'] = trim( (string) ( $last_turn['text'] ?? '' ) );
                    $podcast_lines_set = true;
                }
            }
        }

        if ( ! $podcast_lines_set && $existing_entry ) {
            $existing_content = PressHub_AI_Memory_Store::decode_content( (string) $existing_entry['content_json'] );
            if ( ! empty( $existing_content['podcast']['opening_line'] ) || ! empty( $existing_content['podcast']['closing_line'] ) ) {
                $validated['podcast']['opening_line'] = (string) ( $existing_content['podcast']['opening_line'] ?? '' );
                $validated['podcast']['closing_line'] = (string) ( $existing_content['podcast']['closing_line'] ?? '' );
            }
        }

        $upsert_res = PressHub_AI_Memory_Store::upsert_generated( [
            'scope'                => 'daily',
            'period_start'         => $date,
            'period_end'           => $date,
            'edition'              => $edition,
            'source_post_id'       => $post_id,
            'generated_content'    => $validated,
            'generation_record_id' => $last_rec_id,
            'status'               => 'active',
            'error_message'        => '',
        ] );

        if ( is_wp_error( $upsert_res ) ) {
            return $upsert_res;
        }

        if ( ! empty( $upsert_res['preserved_edit'] ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info(
                    'Memory digest source post changed for edited entry; updated generated_json and preserved editor content_json',
                    [
                        'entry_id' => $upsert_res['id'],
                        'post_id'  => $post_id,
                        'date'     => $date,
                        'edition'  => $edition,
                    ]
                );
            }
        }

        $upsert_res['generation_record_id'] = $last_rec_id;
        return $upsert_res;
    }

    /**
     * Call LLM and record generation event via PressHub_AI_Generation_Recorder.
     *
     * @param PressHub_AI_API_Client $api_client
     * @param string                 $sys_prompt
     * @param string                 $user_prompt
     * @param string                 $date
     * @param string                 $edition
     * @param int                    $post_id
     * @param int                    $attempt
     * @param int                    $briefing_chars
     * @param int                    $existing_keys_count
     * @return array{response:mixed,record_id:int}
     */
    protected static function call_and_record(
        PressHub_AI_API_Client $api_client,
        string $sys_prompt,
        string $user_prompt,
        string $date,
        string $edition,
        int $post_id,
        int $attempt,
        int $briefing_chars,
        int $existing_keys_count
    ): array {
        if ( class_exists( 'PressHub_AI_Token_Logger' ) && method_exists( 'PressHub_AI_Token_Logger', 'reset_last_llm_request' ) ) {
            PressHub_AI_Token_Logger::reset_last_llm_request();
        }

        $t0 = microtime( true );
        $metadata = [
            'action_trigger' => 'memory_digest',
            'briefing_date'  => $date,
            'edition'        => $edition,
            'attempt'        => $attempt,
        ];

        $raw         = $api_client->call_provider( $sys_prompt, $user_prompt, true, [], null, $metadata );
        $duration_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

        $record_id = 0;
        if ( class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            $gen_usage = ( class_exists( 'PressHub_AI_Token_Logger' ) && method_exists( 'PressHub_AI_Token_Logger', 'get_last_llm_request' ) )
                ? ( PressHub_AI_Token_Logger::get_last_llm_request() ?? [] )
                : [];

            $config = method_exists( 'PressHub_AI_Generation_Recorder', 'build_client_config' )
                ? PressHub_AI_Generation_Recorder::build_client_config( $api_client, [ 'attempt' => $attempt, 'edition' => $edition ] )
                : [];

            $record_id = (int) PressHub_AI_Generation_Recorder::record( [
                'kind'          => 'memory_digest',
                'briefing_date' => $date,
                'edition'       => $edition,
                'status'        => is_wp_error( $raw ) ? 'error' : 'success',
                'error_message' => is_wp_error( $raw ) ? $raw->get_error_message() : '',
                'config'        => $config,
                'inputs'        => [
                    'source_post_id'      => $post_id,
                    'briefing_chars'      => $briefing_chars,
                    'existing_keys_count' => $existing_keys_count,
                    'attempt'             => $attempt,
                ],
                'system_prompt' => $sys_prompt,
                'user_prompt'   => $user_prompt,
                'response_text' => is_wp_error( $raw ) ? '' : (string) $raw,
                'usage'         => $gen_usage,
                'duration_ms'   => $duration_ms,
            ] );

            if ( $post_id > 0 && $record_id > 0 && method_exists( 'PressHub_AI_Generation_Recorder', 'attach_to_post' ) ) {
                PressHub_AI_Generation_Recorder::attach_to_post( $post_id, $record_id );
            }
        }

        return [
            'response'  => $raw,
            'record_id' => $record_id,
        ];
    }

    /**
     * Find briefing editions in a given date range.
     *
     * Compatible with real WordPress MySQL/SQLite and isolated unit test stub environments.
     *
     * @param string $from  'YYYY-MM-DD'
     * @param string $to    'YYYY-MM-DD'
     * @param bool   $force Whether to include editions that already have active daily memory entries.
     * @return array List of arrays [ 'post_id' => int, 'date' => string, 'edition' => string ]
     */
    public static function find_briefing_editions_in_range( string $from, string $to, bool $force = false ): array {
        global $wpdb;

        $candidate_posts = [];

        // 1. Query database if wpdb exists
        if ( isset( $wpdb ) && method_exists( $wpdb, 'get_results' ) ) {
            $posts_table    = isset( $wpdb->posts ) ? $wpdb->posts : 'wp_posts';
            $postmeta_table = isset( $wpdb->postmeta ) ? $wpdb->postmeta : 'wp_postmeta';

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, pm_date.meta_value as briefing_date, pm_edition.meta_value as briefing_edition
                     FROM {$posts_table} p
                     INNER JOIN {$postmeta_table} pm_type ON (p.ID = pm_type.post_id AND pm_type.meta_key = '_presshub_briefing_type' AND pm_type.meta_value = 'text')
                     INNER JOIN {$postmeta_table} pm_date ON (p.ID = pm_date.post_id AND pm_date.meta_key = '_presshub_briefing_date')
                     LEFT JOIN {$postmeta_table} pm_edition ON (p.ID = pm_edition.post_id AND pm_edition.meta_key = '_presshub_briefing_edition')
                     WHERE pm_date.meta_value >= %s AND pm_date.meta_value <= %s
                       AND p.post_status != 'trash'
                     ORDER BY pm_date.meta_value ASC, p.ID ASC",
                    $from,
                    $to
                ),
                ARRAY_A
            );

            if ( is_array( $rows ) ) {
                foreach ( $rows as $r ) {
                    $pid   = (int) $r['ID'];
                    $bdate = (string) $r['briefing_date'];
                    $bed   = ( 'noon' === (string) ( $r['briefing_edition'] ?? '' ) ) ? 'noon' : 'morning';
                    $candidate_posts[ $pid ] = [
                        'post_id' => $pid,
                        'date'    => $bdate,
                        'edition' => $bed,
                    ];
                }
            }
        }

        // 2. Fallback / support for unit-test stubs using $GLOBALS['POST_META_STORE']
        if ( isset( $GLOBALS['POST_META_STORE'] ) && is_array( $GLOBALS['POST_META_STORE'] ) ) {
            foreach ( $GLOBALS['POST_META_STORE'] as $pid => $meta ) {
                if ( ! is_array( $meta ) ) {
                    continue;
                }
                if ( ( $meta['_presshub_briefing_type'] ?? '' ) === 'text' ) {
                    $bdate = (string) ( $meta['_presshub_briefing_date'] ?? '' );
                    if ( $bdate >= $from && $bdate <= $to ) {
                        $bed = ( ( $meta['_presshub_briefing_edition'] ?? '' ) === 'noon' ) ? 'noon' : 'morning';
                        $candidate_posts[ (int) $pid ] = [
                            'post_id' => (int) $pid,
                            'date'    => $bdate,
                            'edition' => $bed,
                        ];
                    }
                }
            }
        }

        // Sort chronologically: date ASC, morning before noon, post_id ASC
        uasort( $candidate_posts, function( $a, $b ) {
            if ( $a['date'] !== $b['date'] ) {
                return strcmp( $a['date'], $b['date'] );
            }
            $rank_a = ( 'morning' === $a['edition'] ) ? 0 : 1;
            $rank_b = ( 'noon' === $b['edition'] ) ? 0 : 1;
            if ( $rank_a !== $rank_b ) {
                return $rank_a - $rank_b;
            }
            return $a['post_id'] - $b['post_id'];
        } );

        $queue = [];
        foreach ( $candidate_posts as $item ) {
            if ( ! $force ) {
                $existing = PressHub_AI_Memory_Store::get_daily_entry( $item['date'], $item['edition'] );
                if ( $existing && in_array( (string) $existing['status'], [ 'active', 'excluded' ], true ) ) {
                    continue;
                }
            }
            $queue[] = $item;
        }

        return $queue;
    }

    /**
     * Start a backfill job over a date range.
     *
     * @param string $from
     * @param string $to
     * @param bool   $force
     * @return array State array
     */
    public static function start_backfill( string $from = '', string $to = '', bool $force = false ): array {
        if ( empty( $to ) ) {
            $to = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }
        if ( empty( $from ) ) {
            $from = gmdate( 'Y-m-d', strtotime( $to . ' -30 days' ) );
        }

        $discovered = self::find_briefing_editions_in_range( $from, $to, $force );
        $post_ids   = array_values( array_map( function( $item ) {
            return is_array( $item ) ? (int) $item['post_id'] : (int) $item;
        }, $discovered ) );

        $now   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $state = [
            'status'     => empty( $post_ids ) ? 'completed' : 'running',
            'from'       => $from,
            'to'         => $to,
            'force'      => (bool) $force,
            'queue'      => $post_ids,
            'total'      => count( $post_ids ),
            'processed'  => 0,
            'succeeded'  => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'started_at' => $now,
            'updated_at' => $now,
        ];

        update_option( self::BACKFILL_STATE_OPTION, $state );

        if ( ! empty( $post_ids ) && function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + 5, self::BACKFILL_CRON_HOOK );
        }

        return $state;
    }

    /**
     * Process one item from the backfill queue.
     *
     * @return array Updated state array
     */
    public static function handle_backfill_step(): array {
        $state = get_option( self::BACKFILL_STATE_OPTION, [] );
        if ( empty( $state ) || ! is_array( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
            return is_array( $state ) ? $state : [];
        }

        $queue = (array) ( $state['queue'] ?? [] );
        if ( empty( $queue ) ) {
            $state['status']     = 'completed';
            $state['updated_at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
            update_option( self::BACKFILL_STATE_OPTION, $state );
            return $state;
        }

        // Pop one post_id
        $post_id            = (int) array_shift( $queue );
        $state['queue']     = array_values( $queue );
        $state['processed'] = (int) ( $state['processed'] ?? 0 ) + 1;

        $res = self::build_for_post( $post_id, null, (bool) ( $state['force'] ?? false ) );

        if ( is_wp_error( $res ) ) {
            $state['failed'] = (int) ( $state['failed'] ?? 0 ) + 1;
        } elseif ( is_array( $res ) && ( $res['status'] ?? '' ) === 'skipped' ) {
            $state['skipped'] = (int) ( $state['skipped'] ?? 0 ) + 1;
        } else {
            $state['succeeded'] = (int) ( $state['succeeded'] ?? 0 ) + 1;
        }

        $state['updated_at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

        if ( empty( $state['queue'] ) ) {
            $state['status'] = 'completed';
        } else {
            if ( function_exists( 'wp_schedule_single_event' ) ) {
                wp_schedule_single_event( time() + 5, self::BACKFILL_CRON_HOOK );
            }
        }

        update_option( self::BACKFILL_STATE_OPTION, $state );
        return $state;
    }

    /**
     * WP-CLI command handler: wp presshub memory backfill [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--force]
     *
     * @param array $args
     * @param array $assoc_args
     * @return void
     */
    public static function cli_backfill( array $args, array $assoc_args ): void {
        $from  = $assoc_args['from'] ?? '';
        $to    = $assoc_args['to'] ?? '';
        $force = isset( $assoc_args['force'] ) ? (bool) $assoc_args['force'] : false;

        if ( empty( $to ) ) {
            $to = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }
        if ( empty( $from ) ) {
            $from = gmdate( 'Y-m-d', strtotime( $to . ' -30 days' ) );
        }

        if ( class_exists( 'WP_CLI' ) ) {
            WP_CLI::log( sprintf( 'Starting daily memory backfill from %s to %s (force=%s)...', $from, $to, $force ? 'yes' : 'no' ) );
        }

        $discovered = self::find_briefing_editions_in_range( $from, $to, $force );
        $total      = count( $discovered );

        if ( 0 === $total ) {
            if ( class_exists( 'WP_CLI' ) ) {
                WP_CLI::success( 'No briefing editions found to backfill in range.' );
            }
            return;
        }

        if ( class_exists( 'WP_CLI' ) ) {
            WP_CLI::log( sprintf( 'Found %d briefing edition(s) to process.', $total ) );
        }

        $succeeded = 0;
        $failed    = 0;
        $skipped   = 0;

        foreach ( $discovered as $item ) {
            $pid = (int) $item['post_id'];
            if ( class_exists( 'WP_CLI' ) ) {
                WP_CLI::log( sprintf( 'Processing post #%d (%s, %s)...', $pid, $item['date'], $item['edition'] ) );
            }

            $res = self::build_for_post( $pid, null, $force );
            if ( is_wp_error( $res ) ) {
                $failed++;
                if ( class_exists( 'WP_CLI' ) ) {
                    WP_CLI::warning( sprintf( 'Post #%d failed: %s', $pid, $res->get_error_message() ) );
                }
            } elseif ( is_array( $res ) && ( $res['status'] ?? '' ) === 'skipped' ) {
                $skipped++;
                if ( class_exists( 'WP_CLI' ) ) {
                    WP_CLI::log( sprintf( 'Post #%d skipped (%s).', $pid, $res['reason'] ?? '' ) );
                }
            } else {
                $succeeded++;
                if ( class_exists( 'WP_CLI' ) ) {
                    WP_CLI::log( sprintf( 'Post #%d completed successfully.', $pid ) );
                }
            }
        }

        if ( class_exists( 'WP_CLI' ) ) {
            WP_CLI::success( sprintf( 'Backfill completed. Total: %d, Succeeded: %d, Failed: %d, Skipped: %d', $total, $succeeded, $failed, $skipped ) );
        }
    }
}
