<?php
/**
 * Weekly background rollup builder for PressHub Editorial Memory.
 *
 * Implements Issue #136:
 *   - Daily cron `presshub_ai_memory_build_rollups` finds every completed ISO week (Mon-Sun,
 *     site-local dates) inside `presshub_ai_memory_background_days` that has no rollup and
 *     schedules one single event (`presshub_ai_memory_build_rollup_week`) per week.
 *   - Input: the week's `active` daily digests (their content_json, so editor corrections
 *     propagate; stories with include=false are left out) plus the previous week's rollup narrative.
 *   - Output: schema-valid weekly entry (`narrative`, `stories` with existing keys, `missing_dates`).
 *   - Validation: retry once with the error appended, then status=failed + error_message + ERROR log.
 *   - Stale rollups are never rebuilt automatically; rebuild() / build_week() are for the editor and CLI.
 *   - A rebuild never overwrites an edited rollup's content_json (store rule: only generated_json).
 *   - Every LLM call is recorded as a `memory_rollup` generation record.
 *   - WP-CLI: wp presshub memory rollups --from=YYYY-MM-DD --to=YYYY-MM-DD [--force]
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-settings-storage.php';
require_once __DIR__ . '/class-prompt-loader.php';
require_once __DIR__ . '/class-memory-store.php';
require_once __DIR__ . '/class-memory-digest-builder.php';

class PressHub_AI_Memory_Rollup_Builder {

    /** Daily scanner cron (recurring). */
    const CRON_HOOK = 'presshub_ai_memory_build_rollups';

    /** One single event per missing week; args: [ 'YYYY-MM-DD' (Monday) ]. */
    const WEEK_CRON_HOOK = 'presshub_ai_memory_build_rollup_week';

    /** Target narrative length (documented in the prompt) and the hard limit that triggers a retry. */
    const NARRATIVE_TARGET_CHARS = 1500;
    const NARRATIVE_MAX_CHARS    = 1800;

    /**
     * Register hooks and the WP-CLI command.
     *
     * @return void
     */
    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'handle_cron_scan' ] );
        add_action( self::WEEK_CRON_HOOK, [ __CLASS__, 'handle_cron_build_week' ], 10, 1 );

        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
            WP_CLI::add_command( 'presshub memory rollups', [ __CLASS__, 'cli_rollups' ] );
        }
    }

    /**
     * Make sure the daily scanner cron is scheduled (activation + upgrade block).
     *
     * @return void
     */
    public static function ensure_cron_scheduled(): void {
        if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) && function_exists( 'wp_schedule_event' ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Remove the scanner and every pending per-week event (deactivation).
     *
     * @return void
     */
    public static function clear_cron(): void {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }
        if ( function_exists( 'wp_unschedule_hook' ) ) {
            wp_unschedule_hook( self::WEEK_CRON_HOOK );
        }
    }

    // ------------------------------------------------------------------
    // Date helpers (pure calendar arithmetic on YYYY-MM-DD strings)
    // ------------------------------------------------------------------

    /**
     * @param string $date 'YYYY-MM-DD'
     * @return bool
     */
    public static function is_valid_date( string $date ): bool {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
            return false;
        }
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /**
     * @param string $date 'YYYY-MM-DD'
     * @param int    $days Days to add (may be negative).
     * @return string
     */
    public static function add_days( string $date, int $days ): string {
        $dt = new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );
        return $dt->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
    }

    /**
     * ISO week bounds containing $date.
     *
     * @param string $date 'YYYY-MM-DD'
     * @return array{0:string,1:string} [ Monday, Sunday ]
     */
    public static function week_bounds( string $date ): array {
        $dt     = new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );
        $dow    = (int) $dt->format( 'N' ); // 1 (Mon) .. 7 (Sun)
        $monday = $dt->modify( '-' . ( $dow - 1 ) . ' days' )->format( 'Y-m-d' );
        return [ $monday, self::add_days( $monday, 6 ) ];
    }

    /**
     * Site-local "today".
     *
     * @return string
     */
    protected static function today(): string {
        return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
    }

    /**
     * Active daily digests of a week, ordered by date then edition.
     *
     * @param string $week_start Monday.
     * @return array List of entry rows.
     */
    protected static function get_active_digests( string $week_start ): array {
        $week_end = self::add_days( $week_start, 6 );
        $rows     = PressHub_AI_Memory_Store::get_entries_for_range( $week_start, $week_end, 'daily', 'active' );
        $out      = [];
        foreach ( $rows as $row ) {
            if ( (string) $row['period_start'] >= $week_start && (string) $row['period_start'] <= $week_end ) {
                $out[] = $row;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Week discovery & scheduling
    // ------------------------------------------------------------------

    /**
     * Completed weeks inside the background window that have no rollup yet and at least one
     * active digest (a week without any digest cannot be summarised).
     *
     * A week counts as "inside" the window when it overlaps it (its Sunday is on/after the
     * window start); completed means its Sunday is before today (site-local).
     *
     * @param string|null $today           Site-local 'YYYY-MM-DD' (default: today).
     * @param int|null    $background_days Default: presshub_ai_memory_background_days.
     * @return array List of [ 'week_start' => string, 'week_end' => string, 'digest_count' => int ].
     */
    public static function find_missing_weeks( ?string $today = null, ?int $background_days = null ): array {
        $today = $today ?? self::today();
        $days  = $background_days ?? PressHub_AI_Settings_Storage::get_memory_background_days();
        if ( $days <= 0 ) {
            return [];
        }
        $from = self::add_days( $today, -$days );
        return self::collect_weeks( $from, $today, false, $today );
    }

    /**
     * Completed weeks overlapping [$from, $to] that can be built.
     *
     * @param string      $from  'YYYY-MM-DD'
     * @param string      $to    'YYYY-MM-DD'
     * @param bool        $force Include weeks that already have a rollup.
     * @param string|null $today Site-local today (default: today).
     * @return array Same shape as find_missing_weeks().
     */
    public static function find_weeks_in_range( string $from, string $to, bool $force = false, ?string $today = null ): array {
        return self::collect_weeks( $from, $to, $force, $today ?? self::today() );
    }

    /**
     * @param string $from
     * @param string $to
     * @param bool   $include_existing
     * @param string $today
     * @return array
     */
    protected static function collect_weeks( string $from, string $to, bool $include_existing, string $today ): array {
        $weeks = [];
        [ $week_start ] = self::week_bounds( $from );
        $guard = 0;
        while ( $week_start <= $to && $guard++ < 200 ) {
            $week_end = self::add_days( $week_start, 6 );
            if ( $week_end >= $today ) {
                break; // Not completed yet (and every later week is later still).
            }
            if ( $week_end >= $from ) {
                $existing = PressHub_AI_Memory_Store::get_weekly_entry( $week_start );
                if ( ! $existing || $include_existing ) {
                    $digests = self::get_active_digests( $week_start );
                    if ( ! empty( $digests ) ) {
                        $weeks[] = [
                            'week_start'   => $week_start,
                            'week_end'     => $week_end,
                            'digest_count' => count( $digests ),
                        ];
                    }
                }
            }
            $week_start = self::add_days( $week_start, 7 );
        }
        return $weeks;
    }

    /**
     * Daily cron callback: schedule one single event per missing week.
     *
     * @return array List of week_start dates that were scheduled.
     */
    public static function handle_cron_scan(): array {
        if ( ! self::is_enabled() ) {
            self::log_disabled( 'scan' );
            return [];
        }

        $scheduled = [];
        $offset    = 30;
        foreach ( self::find_missing_weeks() as $week ) {
            $args = [ $week['week_start'] ];
            if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::WEEK_CRON_HOOK, $args ) ) {
                continue;
            }
            if ( function_exists( 'wp_schedule_single_event' ) ) {
                wp_schedule_single_event( time() + $offset, self::WEEK_CRON_HOOK, $args );
                $scheduled[] = $week['week_start'];
                $offset     += 60; // Spread the weeks out; each run stays within PHP time limits.
            }
        }

        if ( ! empty( $scheduled ) && class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( 'Memory rollups scheduled', [ 'weeks' => $scheduled ] );
        }
        return $scheduled;
    }

    /**
     * Single-event cron callback.
     *
     * @param string $week_start
     * @return void
     */
    public static function handle_cron_build_week( $week_start ): void {
        self::build_week( (string) $week_start, false );
    }

    // ------------------------------------------------------------------
    // Public build API (used by cron, WP-CLI and the memory editor #137)
    // ------------------------------------------------------------------

    /**
     * Rebuild the rollup entry with the given ID (editor "Rebuild rollup").
     *
     * Rebuilds even when the rollup is active/stale/failed. An edited rollup keeps its
     * content_json (only generated_json is refreshed); an excluded rollup stays excluded.
     *
     * @param int                         $rollup_id
     * @param PressHub_AI_API_Client|null $api_client Optional client (tests).
     * @return array|WP_Error See build_week().
     */
    public static function rebuild( int $rollup_id, ?PressHub_AI_API_Client $api_client = null ) {
        $entry = PressHub_AI_Memory_Store::get_entry( $rollup_id );
        if ( ! $entry || 'weekly' !== (string) $entry['scope'] ) {
            return new WP_Error( 'rollup_not_found', __( 'Weekly rollup not found.', 'presshub-ai-editor' ) );
        }
        return self::build_week( (string) $entry['period_start'], true, $api_client );
    }

    /**
     * Build (or, with $force, rebuild) the rollup for one ISO week.
     *
     * @param string                      $week_start 'YYYY-MM-DD', must be a Monday.
     * @param bool                        $force      Rebuild even when an entry already exists.
     * @param PressHub_AI_API_Client|null $api_client Optional client (tests).
     * @return array|WP_Error
     *   Success: [ 'status' => 'built', 'id' => int, 'origin' => 'generated'|'edited',
     *              'preserved_edit' => bool, 'generation_record_id' => int,
     *              'digest_ids' => int[], 'missing_dates' => string[] ] (+ store upsert keys).
     *   Skipped: [ 'status' => 'skipped', 'reason' => 'memory_disabled'|'already_exists', ('id' => int) ].
     *   Failure (also stored as status failed/stale, with ERROR log): WP_Error with code
     *   'memory_rollup_failed' (data: generation_record_id, id), 'no_digests',
     *   'invalid_week_start', or a store error.
     */
    public static function build_week( string $week_start, bool $force = false, ?PressHub_AI_API_Client $api_client = null ) {
        if ( ! self::is_enabled() ) {
            self::log_disabled( 'build_week', [ 'week_start' => $week_start ] );
            return [ 'status' => 'skipped', 'reason' => 'memory_disabled' ];
        }

        if ( ! self::is_valid_date( $week_start ) || self::week_bounds( $week_start )[0] !== $week_start ) {
            return new WP_Error( 'invalid_week_start', __( 'Week start must be a valid Monday (YYYY-MM-DD).', 'presshub-ai-editor' ) );
        }
        $week_end = self::add_days( $week_start, 6 );

        $existing = PressHub_AI_Memory_Store::get_weekly_entry( $week_start );
        if ( $existing && ! $force ) {
            return [ 'status' => 'skipped', 'reason' => 'already_exists', 'id' => (int) $existing['id'] ];
        }

        // ---- Input: active digests, editors' content_json -------------------------------
        $digests = self::get_active_digests( $week_start );
        if ( empty( $digests ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( 'Memory rollup skipped: no active daily digests in week', [ 'week_start' => $week_start ] );
            }
            return new WP_Error( 'no_digests', __( 'No active daily digests exist for this week.', 'presshub-ai-editor' ) );
        }

        $digest_ids   = [];
        $digest_dates = [];
        $payload      = [];
        $allowed_keys = [];
        foreach ( $digests as $d ) {
            $digest_ids[]                       = (int) $d['id'];
            $digest_dates[ $d['period_start'] ] = true;
            $content                            = PressHub_AI_Memory_Store::decode_content( (string) $d['content_json'] );
            $stories                            = [];
            foreach ( ( $content['stories'] ?? [] ) as $st ) {
                if ( empty( $st['include'] ) ) {
                    continue; // Editor removed this story from memory.
                }
                $stories[]                                  = [
                    'key'         => (string) $st['key'],
                    'title'       => (string) $st['title'],
                    'development' => (string) $st['development'],
                    'status'      => (string) $st['status'],
                    'entities'    => array_values( (array) ( $st['entities'] ?? [] ) ),
                    'source_urls' => array_values( (array) ( $st['source_urls'] ?? [] ) ),
                ];
                $allowed_keys[ (string) $st['key'] ] = true;
            }
            $payload[] = [
                'date'    => (string) $d['period_start'],
                'edition' => (string) $d['edition'],
                'stories' => $stories,
            ];
        }

        $missing_dates = [];
        for ( $i = 0; $i < 7; $i++ ) {
            $day = self::add_days( $week_start, $i );
            if ( ! isset( $digest_dates[ $day ] ) ) {
                $missing_dates[] = $day;
            }
        }
        if ( ! empty( $missing_dates ) && class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::warning(
                'Memory rollup built from a partial week: missing daily digests',
                [ 'week_start' => $week_start, 'missing_dates' => $missing_dates ]
            );
        }

        $prev_narrative = '';
        $prev_entry     = PressHub_AI_Memory_Store::get_weekly_entry( self::add_days( $week_start, -7 ) );
        if ( $prev_entry && in_array( (string) $prev_entry['status'], [ 'active', 'stale' ], true ) ) {
            $prev_content   = PressHub_AI_Memory_Store::decode_content( (string) $prev_entry['content_json'] );
            $prev_narrative = trim( (string) ( $prev_content['narrative'] ?? '' ) );
        }

        $keys_str    = empty( $allowed_keys ) ? '-' : implode( ', ', array_keys( $allowed_keys ) );
        $missing_str = empty( $missing_dates ) ? 'καμία' : implode( ', ', $missing_dates );
        $sys_prompt  = str_replace(
            [ '{week_start}', '{week_end}', '{story_keys}', '{missing_dates}' ],
            [ $week_start, $week_end, $keys_str, $missing_str ],
            PressHub_AI_Prompt_Loader::get_weekly_rollup_prompt()
        );

        $user_prompt = "Εβδομάδα: {$week_start} έως {$week_end}\n"
            . "Ημέρες χωρίς σύνοψη: {$missing_str}\n\n"
            . "Σύνοψη προηγούμενης εβδομάδας (για συνέχεια):\n"
            . ( '' !== $prev_narrative ? $prev_narrative : 'Δεν υπάρχει σύνοψη προηγούμενης εβδομάδας.' )
            . "\n\nΗμερήσιες συνόψεις (JSON):\n"
            . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'briefing_memory' );
        }

        $inputs = [
            'week_start'     => $week_start,
            'week_end'       => $week_end,
            'digest_ids'     => $digest_ids,
            'digest_count'   => count( $digest_ids ),
            'missing_dates'  => $missing_dates,
            'prev_narrative' => '' !== $prev_narrative,
        ];

        // ---- Attempt 1, then attempt 2 with the validation error appended -----------------
        $validated   = null;
        $last_rec_id = 0;
        $val_error   = null;

        for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
            $prompt = $user_prompt;
            if ( 2 === $attempt && null !== $val_error ) {
                $prompt .= "\n\n---\nΠΡΟΗΓΟΥΜΕΝΟ ΣΦΑΛΜΑ ΕΠΙΚΥΡΩΣΗΣ JSON: " . $val_error->get_error_message() . ' (' . $val_error->get_error_code() . '). Παρακαλώ διόρθωσε το JSON ώστε να συμμορφώνεται αυστηρά με το σχήμα και επέστρεψε ΜΟΝΟ έγκυρο JSON.';
            }

            $res         = self::call_and_record( $api_client, $sys_prompt, $prompt, $week_start, $attempt, $inputs );
            $last_rec_id = $res['record_id'];
            $parsed      = self::parse_and_validate( $res['response'], array_keys( $allowed_keys ), $missing_dates );

            if ( is_wp_error( $parsed ) ) {
                $val_error = $parsed;
                if ( $last_rec_id > 0 && class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
                    PressHub_AI_Generation_Recorder::update_record( $last_rec_id, [
                        'status'        => 'error',
                        'error_message' => $parsed->get_error_message(),
                    ] );
                }
                continue;
            }

            $validated = $parsed;
            $val_error = null;
            break;
        }

        if ( null !== $val_error ) {
            $err_msg = $val_error->get_error_message();
            $failed  = PressHub_AI_Memory_Store::record_failed_rollup( $week_start, $week_end, $err_msg, $last_rec_id );

            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error(
                    'Memory rollup build failed after validation/retry',
                    [
                        'week_start' => $week_start,
                        'week_end'   => $week_end,
                        'error'      => $err_msg,
                        'code'       => $val_error->get_error_code(),
                    ]
                );
            }

            return new WP_Error(
                'memory_rollup_failed',
                $err_msg,
                [
                    'generation_record_id' => $last_rec_id,
                    'id'                   => is_array( $failed ) ? (int) ( $failed['id'] ?? 0 ) : 0,
                ]
            );
        }

        // ---- Store (edited content_json is preserved by the store) -------------------------
        $upsert = PressHub_AI_Memory_Store::upsert_generated( [
            'scope'                => 'weekly',
            'period_start'         => $week_start,
            'period_end'           => $week_end,
            'edition'              => '',
            'generated_content'    => $validated,
            'generation_record_id' => $last_rec_id,
            'status'               => 'active',
            'error_message'        => '',
        ] );
        if ( is_wp_error( $upsert ) ) {
            return $upsert;
        }

        $rollup_id = (int) $upsert['id'];
        if ( ! empty( $upsert['preserved_edit'] ) ) {
            // The store left status/error alone for the edited entry: this rebuild succeeded.
            PressHub_AI_Memory_Store::mark_rollup_built( $rollup_id );
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info(
                    'Memory rollup rebuilt for edited entry; updated generated_json and preserved editor content_json',
                    [ 'entry_id' => $rollup_id, 'week_start' => $week_start ]
                );
            }
        }

        PressHub_AI_Memory_Store::replace_rollup_links( $rollup_id, $digest_ids );

        $upsert['status']               = 'built';
        $upsert['generation_record_id'] = $last_rec_id;
        $upsert['digest_ids']           = $digest_ids;
        $upsert['missing_dates']        = $missing_dates;
        return $upsert;
    }

    /**
     * Build every buildable completed week overlapping a date range, one after another
     * (used by WP-CLI; synchronous).
     *
     * @param string                      $from
     * @param string                      $to
     * @param bool                        $force
     * @param PressHub_AI_API_Client|null $api_client Optional client (tests).
     * @param string|null                 $today      Optional site-local today (tests).
     * @return array|WP_Error [ 'total' => int, 'succeeded' => int, 'failed' => int, 'skipped' => int,
     *                          'weeks' => [ week_start => 'built'|'failed'|'skipped' ] ]
     */
    public static function process_range( string $from, string $to, bool $force = false, ?PressHub_AI_API_Client $api_client = null, ?string $today = null ) {
        if ( ! self::is_valid_date( $from ) || ! self::is_valid_date( $to ) || $from > $to ) {
            return new WP_Error( 'invalid_date_range', __( 'Invalid date range provided for rollups.', 'presshub-ai-editor' ) );
        }

        $summary = [ 'total' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'weeks' => [] ];
        foreach ( self::find_weeks_in_range( $from, $to, $force, $today ) as $week ) {
            $summary['total']++;
            $res = self::build_week( $week['week_start'], $force, $api_client );
            if ( is_wp_error( $res ) ) {
                $summary['failed']++;
                $summary['weeks'][ $week['week_start'] ] = 'failed';
            } elseif ( 'skipped' === ( $res['status'] ?? '' ) ) {
                $summary['skipped']++;
                $summary['weeks'][ $week['week_start'] ] = 'skipped';
            } else {
                $summary['succeeded']++;
                $summary['weeks'][ $week['week_start'] ] = 'built';
            }
        }
        return $summary;
    }

    /**
     * WP-CLI: wp presshub memory rollups --from=YYYY-MM-DD --to=YYYY-MM-DD [--force]
     *
     * @param array $args
     * @param array $assoc_args
     * @return void
     */
    public static function cli_rollups( array $args, array $assoc_args ): void {
        $to    = $assoc_args['to'] ?? self::today();
        $from  = $assoc_args['from'] ?? self::add_days( (string) $to, -PressHub_AI_Settings_Storage::get_memory_background_days() );
        $force = ! empty( $assoc_args['force'] );

        if ( ! class_exists( 'WP_CLI' ) ) {
            return;
        }
        if ( ! self::is_enabled() ) {
            WP_CLI::error( 'Editorial memory is disabled in settings.' );
            return;
        }
        if ( ! self::is_valid_date( (string) $from ) || ! self::is_valid_date( (string) $to ) || $from > $to ) {
            WP_CLI::error( 'Invalid date range. Use --from=YYYY-MM-DD --to=YYYY-MM-DD with from <= to.' );
            return;
        }

        WP_CLI::log( sprintf( 'Building weekly memory rollups from %s to %s (force=%s)...', $from, $to, $force ? 'yes' : 'no' ) );
        $summary = self::process_range( (string) $from, (string) $to, $force );
        if ( is_wp_error( $summary ) ) {
            WP_CLI::error( $summary->get_error_message() );
            return;
        }
        foreach ( $summary['weeks'] as $week => $state ) {
            WP_CLI::log( sprintf( 'Week %s: %s', $week, $state ) );
        }
        WP_CLI::success( sprintf(
            'Rollups completed. Total: %d, Succeeded: %d, Failed: %d, Skipped: %d',
            $summary['total'],
            $summary['succeeded'],
            $summary['failed'],
            $summary['skipped']
        ) );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected static function is_enabled(): bool {
        return class_exists( 'PressHub_AI_Settings_Storage' ) && PressHub_AI_Settings_Storage::get_memory_enabled();
    }

    protected static function log_disabled( string $what, array $context = [] ): void {
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( 'Memory rollup ' . $what . ' skipped: editorial memory is disabled', $context );
        }
    }

    /**
     * Turn a raw LLM response into validated rollup content.
     *
     * @param mixed    $raw           String response or WP_Error.
     * @param string[] $allowed_keys  Story keys present in the input digests.
     * @param string[] $missing_dates Deterministic list recorded in the entry.
     * @return array|WP_Error
     */
    protected static function parse_and_validate( $raw, array $allowed_keys, array $missing_dates ) {
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $cleaned = PressHub_AI_Memory_Digest_Builder::clean_json_response( (string) $raw );
        $decoded = json_decode( $cleaned, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error(
                'invalid_memory_json',
                __( 'Content must be valid JSON.', 'presshub-ai-editor' ),
                [ 'raw' => substr( $cleaned, 0, 200 ) ]
            );
        }

        $decoded['podcast']       = [ 'opening_line' => '', 'closing_line' => '' ];
        $decoded['missing_dates'] = $missing_dates; // Deterministic, never model-provided.
        $check                    = PressHub_AI_Memory_Store::validate_content( $decoded );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $narrative = trim( (string) $check['narrative'] );
        if ( '' === $narrative ) {
            return new WP_Error( 'missing_rollup_narrative', __( 'Weekly rollup requires a non-empty narrative.', 'presshub-ai-editor' ) );
        }
        $len = function_exists( 'mb_strlen' ) ? mb_strlen( $narrative ) : strlen( $narrative );
        if ( $len > self::NARRATIVE_MAX_CHARS ) {
            return new WP_Error(
                'rollup_narrative_too_long',
                sprintf(
                    /* translators: 1: actual length, 2: target length */
                    __( 'Narrative is %1$d characters; keep it to about %2$d.', 'presshub-ai-editor' ),
                    $len,
                    self::NARRATIVE_TARGET_CHARS
                )
            );
        }

        $allowed = array_flip( $allowed_keys );
        foreach ( $check['stories'] as $st ) {
            if ( ! isset( $allowed[ $st['key'] ] ) ) {
                return new WP_Error(
                    'unknown_rollup_story_key',
                    sprintf(
                        /* translators: %s: story key */
                        __( 'Story key "%s" does not exist in the daily digests; only existing keys may be used.', 'presshub-ai-editor' ),
                        $st['key']
                    )
                );
            }
        }

        return $check;
    }

    /**
     * Call the LLM and record the attempt as a memory_rollup generation record.
     *
     * @return array{response:mixed,record_id:int}
     */
    protected static function call_and_record(
        PressHub_AI_API_Client $api_client,
        string $sys_prompt,
        string $user_prompt,
        string $week_start,
        int $attempt,
        array $inputs
    ): array {
        if ( class_exists( 'PressHub_AI_Token_Logger' ) && method_exists( 'PressHub_AI_Token_Logger', 'reset_last_llm_request' ) ) {
            PressHub_AI_Token_Logger::reset_last_llm_request();
        }

        $t0       = microtime( true );
        $metadata = [
            'action_trigger' => 'memory_rollup',
            'briefing_date'  => $week_start,
            'edition'        => '',
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
                ? PressHub_AI_Generation_Recorder::build_client_config( $api_client, [ 'attempt' => $attempt ] )
                : [];

            $record_id = (int) PressHub_AI_Generation_Recorder::record( [
                'kind'          => 'memory_rollup',
                'briefing_date' => $week_start,
                'edition'       => '',
                'status'        => is_wp_error( $raw ) ? 'error' : 'success',
                'error_message' => is_wp_error( $raw ) ? $raw->get_error_message() : '',
                'config'        => $config,
                'inputs'        => $inputs + [ 'attempt' => $attempt ],
                'system_prompt' => $sys_prompt,
                'user_prompt'   => $user_prompt,
                'response_text' => is_wp_error( $raw ) ? '' : (string) $raw,
                'usage'         => $gen_usage,
                'duration_ms'   => $duration_ms,
            ] );
        }

        return [
            'response'  => $raw,
            'record_id' => $record_id,
        ];
    }
}
