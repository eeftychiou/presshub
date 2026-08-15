<?php
/**
 * TDD tests for PressHub_AI_Research_Cleanup (stale research-log retention).
 */

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
