<?php
/**
 * TDD Unit Tests for Issue #83 — Briefing Hub Timezone Mismatch.
 *
 * The bug: on the Daily Briefing Hub admin page, the four
 * `.presshub-stage-status-box` containers (Harvest / Curation / Script /
 * Audio) leaked yesterday's UTC-date pipeline status into the early
 * morning of a new local day, because the writer used
 * `current_time('mysql')` (WP-local timezone) while reader defaults used
 * `gmdate('Y-m-d')` (UTC).
 *
 * @group issue-83
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once __DIR__ . '/../presshub-ai-editor.php';

class BriefingTimezoneFixTest {

    public static function run(): void {
        $failures = [];

        // Case 1: wp_date() returns WP-local today, not UTC.
        self::reset_world();
        $GLOBALS['CURRENT_TEST_TIME'] = '2026-09-02 22:00:00';
        $GLOBALS['WP_GMT_OFFSET']     = 3.0;
        $wp_local_today = wp_date( 'Y-m-d' );
        if ( '2026-09-03' !== $wp_local_today ) {
            $failures[] = "wp_date('Y-m-d') must return WP-local '2026-09-03' for Cyprus at 22:00 UTC; got '{$wp_local_today}'.";
        }

        $GLOBALS['CURRENT_TEST_TIME'] = '2026-09-03 03:00:00';
        $GLOBALS['WP_GMT_OFFSET']     = -5.0;
        $wp_local_today = wp_date( 'Y-m-d' );
        if ( '2026-09-02' !== $wp_local_today ) {
            $failures[] = "wp_date('Y-m-d') must return WP-local '2026-09-02' for US East at 03:00 UTC; got '{$wp_local_today}'.";
        }

        $GLOBALS['CURRENT_TEST_TIME'] = '2026-09-03 12:34:56';
        $GLOBALS['WP_GMT_OFFSET']     = 0.0;
        $wp_local_today = wp_date( 'Y-m-d' );
        if ( '2026-09-03' !== $wp_local_today ) {
            $failures[] = "wp_date('Y-m-d') must return '2026-09-03' for UTC site at noon; got '{$wp_local_today}'.";
        }

        // Case 2: normalize_date_boundary() returns timezone-correct UTC boundaries.
        self::reset_world();
        $start = PressHub_AI_Token_Logger::normalize_date_boundary( '2026-09-03', false, 3.0 );
        $end   = PressHub_AI_Token_Logger::normalize_date_boundary( '2026-09-03', true,  3.0 );
        if ( '2026-09-02 21:00:00' !== $start ) {
            $failures[] = "normalize_date_boundary('2026-09-03', false, 3.0) should be '2026-09-02 21:00:00'; got '{$start}'.";
        }
        if ( '2026-09-03 20:59:59' !== $end ) {
            $failures[] = "normalize_date_boundary('2026-09-03', true, 3.0) should be '2026-09-03 20:59:59'; got '{$end}'.";
        }

        $start_utc = PressHub_AI_Token_Logger::normalize_date_boundary( '2026-09-03', false, 0.0 );
        $end_utc   = PressHub_AI_Token_Logger::normalize_date_boundary( '2026-09-03', true,  0.0 );
        if ( '2026-09-03 00:00:00' !== $start_utc ) {
            $failures[] = "normalize_date_boundary('2026-09-03', false, 0.0) should be '2026-09-03 00:00:00'; got '{$start_utc}'.";
        }
        if ( '2026-09-03 23:59:59' !== $end_utc ) {
            $failures[] = "normalize_date_boundary('2026-09-03', true, 0.0) should be '2026-09-03 23:59:59'; got '{$end_utc}'.";
        }

        $start_east = PressHub_AI_Token_Logger::normalize_date_boundary( '2026-09-03', false, -5.0 );
        if ( '2026-09-03 05:00:00' !== $start_east ) {
            $failures[] = "normalize_date_boundary('2026-09-03', false, -5.0) should be '2026-09-03 05:00:00'; got '{$start_east}'.";
        }

        if ( '' !== PressHub_AI_Token_Logger::normalize_date_boundary( '', false, 3.0 ) ) {
            $failures[] = "normalize_date_boundary('', ...) must return empty string.";
        }

        $passthrough = PressHub_AI_Token_Logger::normalize_date_boundary( '2026-09-03 04:15:00', false, 3.0 );
        if ( '2026-09-03 04:15:00' !== $passthrough ) {
            $failures[] = "normalize_date_boundary('Y-m-d H:i:s', ...) must pass through; got '{$passthrough}'.";
        }

        $start_in = PressHub_AI_Token_Logger::normalize_date_boundary( '2026-09-03', false, 5.5 );
        if ( '2026-09-02 18:30:00' !== $start_in ) {
            $failures[] = "normalize_date_boundary('2026-09-03', false, 5.5) should be '2026-09-02 18:30:00'; got '{$start_in}'.";
        }

        // Case 3: get_logs() filters out rows outside the local day.
        self::reset_world();
        $GLOBALS['WP_GMT_OFFSET'] = 3.0;
        global $wpdb;
        $wpdb->tables['wp_presshub_ai_token_logs'] = [];
        $wpdb->auto_increments['wp_presshub_ai_token_logs'] = 0;

        $wpdb->insert( 'wp_presshub_ai_token_logs', [
            'created_at'        => '2026-09-02 22:00:00',
            'action_trigger'    => 'scrape_harvest',
            'provider'          => 'scraper',
            'model'             => 'wp_remote_get',
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'total_tokens'      => 0,
            'metric_units'      => 5,
            'duration_ms'       => 3000,
            'status'            => 'success',
            'user_id'           => 1,
            'error_message'     => null,
            'metadata'          => null,
        ] );
        $wpdb->insert( 'wp_presshub_ai_token_logs', [
            'created_at'        => '2026-09-03 04:00:00',
            'action_trigger'    => 'scrape_harvest',
            'provider'          => 'scraper',
            'model'             => 'wp_remote_get',
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'total_tokens'      => 0,
            'metric_units'      => 7,
            'duration_ms'       => 4000,
            'status'            => 'success',
            'user_id'           => 1,
            'error_message'     => null,
            'metadata'          => null,
        ] );
        $wpdb->insert( 'wp_presshub_ai_token_logs', [
            'created_at'        => '2026-09-02 20:00:00',
            'action_trigger'    => 'scrape_harvest',
            'provider'          => 'scraper',
            'model'             => 'wp_remote_get',
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'total_tokens'      => 0,
            'metric_units'      => 99,
            'duration_ms'       => 999,
            'status'            => 'success',
            'user_id'           => 1,
            'error_message'     => null,
            'metadata'          => null,
        ] );

        $logs = PressHub_AI_Token_Logger::get_logs( [
            'action'     => 'scrape_harvest',
            'start_date' => '2026-09-03',
            'end_date'   => '2026-09-03',
            'per_page'   => 25,
        ] );
        $items = is_array( $logs['items'] ?? null ) ? $logs['items'] : [];
        if ( 2 !== count( $items ) ) {
            $failures[] = "get_logs() with Cyprus gmt_offset=3 must return 2 in-window rows for local '2026-09-03'; got " . count( $items ) . ".";
        }
        foreach ( $items as $row ) {
            $ca = (string) ( $row['created_at'] ?? '' );
            if ( '2026-09-02 20:00:00' === $ca ) {
                $failures[] = "get_logs() must NOT return the 2026-09-02 20:00:00 UTC row (= 23:00 local Sept 2 Cyprus) when querying local Sept 3.";
            }
            $mu = (int) ( $row['metric_units'] ?? 0 );
            if ( 99 === $mu ) {
                $failures[] = 'get_logs() must NOT return the metric_units=99 row (Sept 2 local) when querying local Sept 3.';
            }
        }

        // Case 4: collect_stage_execution_events() timezone-correct end-to-end.
        self::reset_world();
        $GLOBALS['WP_GMT_OFFSET'] = 3.0;
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_briefing_text_title_prefix'      => 'Πρωινή Ενημέρωση:',
            'presshub_ai_briefing_text_title_date_format' => 'd/m/Y',
            'gmt_offset'                                  => 3.0,
        ];
        $wpdb->tables['wp_presshub_ai_token_logs'] = [];
        $wpdb->auto_increments['wp_presshub_ai_token_logs'] = 0;
        $wpdb->insert( 'wp_presshub_ai_token_logs', [
            'created_at'        => '2026-09-02 23:30:00',
            'action_trigger'    => 'scrape_harvest',
            'provider'          => 'scraper',
            'model'             => 'wp_remote_get',
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'total_tokens'      => 0,
            'metric_units'      => 12,
            'duration_ms'       => 8000,
            'status'            => 'success',
            'user_id'           => 1,
            'error_message'     => null,
            'metadata'          => wp_json_encode( [ 'started_at' => '2026-09-02 23:25:00', 'sources_count' => 3 ] ),
        ] );

        $events = PressHub_AI_Briefing_Admin::collect_stage_execution_events( '2026-09-03' );
        if ( empty( $events['harvest']['attempted_at'] ) ) {
            $failures[] = "harvest.attempted_at should surface the metadata.started_at for Cyprus local '2026-09-03' query; got empty.";
        }
        if ( 12 !== (int) ( $events['harvest']['metric_units'] ?? 0 ) ) {
            $failures[] = 'harvest.metric_units should be 12 for Cyprus local Sept 3 query; got ' . ( $events['harvest']['metric_units'] ?? 'null' );
        }
        if ( 3 !== (int) ( $events['harvest']['sources_count'] ?? 0 ) ) {
            $failures[] = 'harvest.sources_count should be 3 for Cyprus local Sept 3 query; got ' . ( $events['harvest']['sources_count'] ?? 'null' );
        }

        $events_yest = PressHub_AI_Briefing_Admin::collect_stage_execution_events( '2026-09-02' );
        if ( 0 !== (int) ( $events_yest['harvest']['duration_ms'] ?? -1 ) ) {
            $failures[] = "harvest.duration_ms must be 0 for Cyprus local '2026-09-02' query (row is on local Sept 3); got " . ( $events_yest['harvest']['duration_ms'] ?? 'null' ) . '.';
        }

        // Case 5: PHP source has no bare gmdate('Y-m-d') outside wp_date fallback.
        self::reset_world();
        $admin_src = file_get_contents( __DIR__ . '/../includes/class-briefing-admin.php' );
        $stripped = preg_replace(
            "/function_exists\\s*\\(\\s*'wp_date'\\s*\\)\\s*\\?\\s*wp_date\\([^)]*\\)\\s*:\\s*gmdate\\(\\s*'Y-m-d'\\s*\\)/s",
            '__WP_DATE_FALLBACK__',
            $admin_src
        );
        $remaining = substr_count( $stripped, "gmdate( 'Y-m-d' )" );
        if ( $remaining > 0 ) {
            $failures[] = "class-briefing-admin.php still contains bare gmdate('Y-m-d') outside the wp_date() fallback: {$remaining} occurrence(s).";
        }

        // Case 6: empty-state placeholder present in PHP source.
        if ( false === strpos( $admin_src, 'Awaiting first run for this date.' ) ) {
            $failures[] = "class-briefing-admin.php must include the 'Awaiting first run for this date.' empty-state placeholder string.";
        }

        // Case 7: JS source drops UTC ISO fallback and references the placeholder.
        $js_src = file_get_contents( __DIR__ . '/../assets/briefing-admin.js' );
        // Strip /* ... */ block comments AND // line comments before
        // checking so any explanatory note about why the UTC fallback
        // was removed (or the new placeholder text) does not produce a
        // false positive.
        $js_src_stripped = preg_replace( '#/\*.*?\*/#s', '', $js_src );
        $js_src_stripped = preg_replace( '#//[^\n]*#', '', $js_src_stripped );
        if ( false !== strpos( $js_src_stripped, "new Date().toISOString().split('T')[0]" ) ) {
            $failures[] = "briefing-admin.js must NOT use new Date().toISOString() for the current date fallback.";
        }
        if ( false === strpos( $js_src_stripped, 'Awaiting first run for this date.' ) ) {
            $failures[] = "briefing-admin.js must include the empty-state placeholder string.";
        }

        if ( empty( $failures ) ) {
            echo 'BriefingTimezoneFixTest: ALL TESTS PASSED! OK' . PHP_EOL;
            return;
        }
        echo 'BriefingTimezoneFixTest: FAIL' . PHP_EOL;
        foreach ( $failures as $f ) {
            echo '  - ' . $f . PHP_EOL;
        }
        exit( 1 );
    }

    private static function reset_world(): void {
        $_POST                               = [];
        $_GET                                = [];
        $_FILES                              = [];
        $GLOBALS['OPTIONS_STORE']            = [];
        $GLOBALS['CURRENT_USER_CAPS']        = [ 'edit_posts', 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID']          = 1;
        $GLOBALS['NONCE_VALID']              = true;
        $GLOBALS['SUBMENU_PAGES']            = [];
        $GLOBALS['ENQUEUED_SCRIPTS']         = [];
        $GLOBALS['ENQUEUED_STYLES']          = [];
        $GLOBALS['LOCALIZED_SCRIPTS']        = [];
        $GLOBALS['JSON_RESPONSES']           = [];
        $GLOBALS['POSTS_STORE']              = [];
        $GLOBALS['POST_META_STORE']          = [];
        $GLOBALS['GET_RESPONSE_FILTER']      = null;
        $GLOBALS['CAPTURE_FILTER']           = null;
        $GLOBALS['CURRENT_TEST_TIME']        = null;
        $GLOBALS['WP_GMT_OFFSET']            = 0.0;
        global $wpdb;
        $wpdb->tables = [];
        $wpdb->queries = [];
        $wpdb->auto_increments = [];
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_briefing_text_title_prefix'      => 'Πρωινή Ενημέρωση:',
            'presshub_ai_briefing_text_title_date_format' => 'd/m/Y',
        ];
    }
}

BriefingTimezoneFixTest::run();
