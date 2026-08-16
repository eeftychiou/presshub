<?php
/**
 * TDD tests for PressHub_AI_Research_Cleanup (stale research-log retention).
 */

// Capturing get_posts: identical to the harness stub, but also records
// the query args so tests can assert the retention window. Defined before
// wordpress-stubs.php so the guarded stub is skipped.
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        $GLOBALS['GET_POSTS_ARGS'][] = $args;
        return $GLOBALS['GET_POSTS_RESULT'] ?? [];
    }
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-research-cleanup.php';

class ResearchCleanupTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: stale completed/failed logs are deleted ---
        unset( $GLOBALS['FILTERS'], $GLOBALS['GET_POSTS_RESULT'], $GLOBALS['DELETED_POSTS'], $GLOBALS['RECURRING_EVENTS'], $GLOBALS['NEXT_SCHEDULED'] );
        $GLOBALS['GET_POSTS_RESULT'] = [ 11, 12, 13 ];
        $deleted = PressHub_AI_Research_Cleanup::run( 30 );
        if ( $deleted !== 3 ) {
            $failures[] = "Expected 3 deletions, got {$deleted}.";
        }
        $ids = array_column( $GLOBALS['DELETED_POSTS'], 'id' );
        if ( $ids !== [ 11, 12, 13 ] ) {
            $failures[] = 'wp_delete_post not called with the stale ids: ' . json_encode( $ids );
        }

        // --- Case 2: no stale logs -> nothing deleted ---
        unset( $GLOBALS['GET_POSTS_RESULT'], $GLOBALS['DELETED_POSTS'] );
        $GLOBALS['GET_POSTS_RESULT'] = [];
        $deleted = PressHub_AI_Research_Cleanup::run( 30 );
        if ( $deleted !== 0 ) {
            $failures[] = "Expected 0 deletions for empty result, got {$deleted}.";
        }
        if ( ! empty( $GLOBALS['DELETED_POSTS'] ) ) {
            $failures[] = 'wp_delete_post should not have been called when nothing is stale.';
        }

        // --- Case 3: register() schedules the daily event (and runs without fatal) ---
        unset( $GLOBALS['RECURRING_EVENTS'], $GLOBALS['FILTERS'] );
        $GLOBALS['NEXT_SCHEDULED'] = []; // nothing scheduled yet
        PressHub_AI_Research_Cleanup::register();
        $events = $GLOBALS['RECURRING_EVENTS'] ?? [];
        if ( count( $events ) !== 1 || $events[0]['recurrence'] !== 'daily' || $events[0]['hook'] !== 'presshub_ai_cleanup_research' ) {
            $failures[] = 'Expected one daily presshub_ai_cleanup_research event, got: ' . json_encode( $events );
        }

        // --- Case 4: register() does NOT double-schedule when already scheduled ---
        unset( $GLOBALS['RECURRING_EVENTS'] );
        $GLOBALS['NEXT_SCHEDULED'] = [ 'presshub_ai_cleanup_research' => 1234567890 ];
        PressHub_AI_Research_Cleanup::register();
        if ( ! empty( $GLOBALS['RECURRING_EVENTS'] ) ) {
            $failures[] = 'register() must not schedule when the event already exists.';
        }

        // --- Case 5: run() with no args reads the retention option ---
        unset( $GLOBALS['GET_POSTS_RESULT'], $GLOBALS['GET_POSTS_ARGS'], $GLOBALS['DELETED_POSTS'], $GLOBALS['OPTIONS_STORE'] );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_research_retention_days'] = 7;
        $GLOBALS['GET_POSTS_RESULT'] = [ 21 ];
        PressHub_AI_Research_Cleanup::run();
        $args   = $GLOBALS['GET_POSTS_ARGS'][0] ?? [];
        $before = $args['date_query']['before'] ?? '';
        $ts     = strtotime( $before );
        if ( ! $ts || $ts > time() - 7 * DAY_IN_SECONDS || $ts <= time() - 8 * DAY_IN_SECONDS ) {
            $failures[] = "run() should use the option's 7-day retention window; before: {$before}";
        }
        // Default fallback when the option is absent: DEFAULT_RETENTION_DAYS.
        unset( $GLOBALS['OPTIONS_STORE'], $GLOBALS['GET_POSTS_ARGS'] );
        PressHub_AI_Research_Cleanup::run();
        $before = $GLOBALS['GET_POSTS_ARGS'][0]['date_query']['before'] ?? '';
        $ts     = strtotime( $before );
        if ( ! $ts || $ts > time() - 30 * DAY_IN_SECONDS || $ts <= time() - 31 * DAY_IN_SECONDS ) {
            $failures[] = "run() should fall back to the 30-day default when the option is absent; before: {$before}";
        }
        // Explicit $days still wins over the option (cron-triggered sweep).
        unset( $GLOBALS['GET_POSTS_ARGS'] );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_research_retention_days'] = 7;
        PressHub_AI_Research_Cleanup::run( 3 );
        $before = $GLOBALS['GET_POSTS_ARGS'][0]['date_query']['before'] ?? '';
        $ts     = strtotime( $before );
        if ( ! $ts || $ts > time() - 3 * DAY_IN_SECONDS || $ts <= time() - 4 * DAY_IN_SECONDS ) {
            $failures[] = "an explicit \$days should override the option; before: {$before}";
        }

        // --- Case 6: run() clamps sub-1 retention to 1 day ---
        unset( $GLOBALS['GET_POSTS_ARGS'] );
        PressHub_AI_Research_Cleanup::run( 0 );
        $before = $GLOBALS['GET_POSTS_ARGS'][0]['date_query']['before'] ?? '';
        $ts     = strtotime( $before );
        if ( ! $ts || $ts > time() - DAY_IN_SECONDS || $ts <= time() - 2 * DAY_IN_SECONDS ) {
            $failures[] = "run(0) should clamp to a 1-day window; before: {$before}";
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

ResearchCleanupTest::run();
