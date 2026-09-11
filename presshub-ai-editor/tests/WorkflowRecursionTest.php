<?php
/**
 * TDD test for PressHub_AI_Workflow recursion guard.
 *
 * RED phase: this test should fail because enforce_editorial_workflow()
 * currently calls wp_update_post() inside the transition_post_status
 * action without any guard, so an author publishing an unreviewed post
 * triggers an infinite loop of status transitions.
 *
 * GREEN phase expectation: once we add a static guard, the recursive
 * transition_post_status fire is suppressed and we end up with a single
 * status change to 'pending'.
 */

require_once __DIR__ . '/wordpress-stubs.php';

// We need a way to register the workflow's transition_post_status handler
// without WordPress. Provide a minimal helper the class itself can call.
if ( ! class_exists( 'WP_Hook_Registrar' ) ) {
    class WP_Hook_Registrar {
        public static function register_transition_handler( callable $cb ) {
            $GLOBALS['TRANSITION_HANDLERS'][] = $cb;
        }
    }
}

// Lightly monkey-patch add_action() so it actually registers transition
// handlers in our stubbed environment.
function presshub_test_add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    if ( 'transition_post_status' === $hook ) {
        WP_Hook_Registrar::register_transition_handler( $callback );
    }
}

// Re-define add_action AFTER our helper exists. PHP's function lookup is
// order-sensitive inside the same runtime, so include the plugin file via
// require below and intercept add_action through a wrapper file.
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-workflow.php';

class WorkflowRecursionTest
{
    public static function run(): void {
        $failures = [];

        self::reset_world();
        $count = self::simulate_unreviewed_publish();

        if ( $count !== 1 ) {
            $failures[] = "Expected exactly 1 wp_update_post call (1 enforced revert, no recursion), got {$count}.";
        }

        // Direct recursion probe: simulate a worst-case where wp_update_post's
        // stubbed re-fire uses 'publish' as the new status (which can happen
        // in real WP via interaction with other plugins / hooks). With a
        // static guard the handler bails on re-entry; without it the handler
        // fires again and calls wp_update_post, leading to an unbounded loop
        // that the stub must artificially cap to avoid hanging the suite.
        self::reset_world();
        $maxCalls = 5; // safety cap; buggy code would exceed this
        $GLOBALS['RECURSION_MAX_CALLS'] = $maxCalls;
        try {
            self::probe_reentry_publish_to_publish();
        } catch ( Throwable $e ) {
            // ignore; we'll assert below
        }
        if ( $GLOBALS['WP_UPDATE_POST_CALLS'] >= $maxCalls ) {
            $failures[] = "Re-entry recursion: wp_update_post called {$GLOBALS['WP_UPDATE_POST_CALLS']} times (cap {$maxCalls}) — guard missing.";
        }

        self::reset_world();
        self::grant_editor_caps();
        $count = self::simulate_unreviewed_publish();
        if ( $count !== 0 ) {
            $failures[] = "Editor should bypass enforcement (0 wp_update_post calls), got {$count}.";
        }

        self::reset_world();
        self::grant_author_caps();
        self::seed_post_meta( 42, [ 'score' => 90 ] );
        $count = self::simulate_unreviewed_publish();
        if ( $count !== 0 ) {
            $failures[] = "High-score author should not be reverted (0 wp_update_post calls), got {$count}.";
        }

        // Antigravity C-5: non-'post' post types (research CPT, headless /
        // cron transitions) must never touch the editorial publish gate.
        // An author publishing a presshub_research post with no scorecard
        // must NOT trigger wp_update_post.
        self::reset_world();
        self::grant_author_caps();
        $GLOBALS['POST_TYPES'][42] = 'presshub_research';
        $workflow = new PressHub_AI_Workflow();
        $post = (object) [ 'ID' => 42, 'post_type' => 'presshub_research' ];
        $GLOBALS['POST_STATUSES'][42] = 'pending';
        do_action( 'transition_post_status', 'publish', 'pending', $post );
        if ( ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) !== 0 ) {
            $failures[] = "CPT transitions must bypass the editorial gate (0 wp_update_post calls), got " . ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) . ".";
        }

        // Automated Daily Briefing Hub posts (text briefing stories)
        // must bypass the editorial gate even without a scorecard.
        self::reset_world();
        self::grant_author_caps();
        $GLOBALS['POST_META_STORE'][42]['_presshub_briefing_type'] = 'text';
        $count = self::simulate_unreviewed_publish();
        if ( $count !== 0 ) {
            $failures[] = "Briefing text post must bypass the editorial gate (0 wp_update_post calls), got {$count}.";
        }

        // Automated Daily Briefing Hub posts (podcast posts)
        // must bypass the editorial gate even without a scorecard.
        self::reset_world();
        self::grant_author_caps();
        $GLOBALS['POST_META_STORE'][42]['_presshub_briefing_type'] = 'podcast';
        $count = self::simulate_unreviewed_publish();
        if ( $count !== 0 ) {
            $failures[] = "Briefing podcast post must bypass the editorial gate (0 wp_update_post calls), got {$count}.";
        }

        // Cron executions (wp_doing_cron() === true) must bypass the gate.
        self::reset_world();
        self::grant_author_caps();
        $GLOBALS['WP_DOING_CRON'] = true;
        $count = self::simulate_unreviewed_publish();
        if ( $count !== 0 ) {
            $failures[] = "Cron execution must bypass the editorial gate (0 wp_update_post calls), got {$count}.";
        }
        $GLOBALS['WP_DOING_CRON'] = false;

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    private static function reset_world(): void {
        $GLOBALS['POST_META_STORE'] = [];
        $GLOBALS['OPTIONS_STORE'] = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $GLOBALS['POST_STATUSES'] = [];
        $GLOBALS['POST_TYPES'] = [];
        $GLOBALS['HOOK_INVOCATION_COUNT'] = 0;
        $GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
        $GLOBALS['HOOK_INVOCATION_LOG'] = [];
        $GLOBALS['DO_ACTION_LOG'] = [];
        $GLOBALS['TRANSITION_HANDLERS'] = [];
        $GLOBALS['WP_DOING_CRON'] = false;
    }

    private static function grant_author_caps(): void {
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
    }

    private static function grant_editor_caps(): void {
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts' ];
    }

    private static function seed_post_meta( int $post_id, array $scorecard ): void {
        $GLOBALS['POST_META_STORE'][ $post_id ]['_presshub_ai_scorecard'] = $scorecard;
    }

    /**
     * Simulate an author publishing a post that has no scorecard meta.
     * Returns the number of wp_update_post() calls that occurred.
     */
    private static function simulate_unreviewed_publish(): int {
        $workflow = new PressHub_AI_Workflow();
        $post = (object) [ 'ID' => 42, 'post_type' => 'post' ];
        $GLOBALS['POST_STATUSES'][42] = 'draft';

        // Fire the transition once. With a recursion guard in place, this
        // should produce exactly one wp_update_post call.
        do_action( 'transition_post_status', 'publish', 'draft', $post );

        return $GLOBALS['WP_UPDATE_POST_CALLS'];
    }

    /**
     * Directly probe the recursion guard: invoke the handler twice in a
     * row with publish->publish, which is the worst case for re-entry. With
     * a guard in place the second invocation is short-circuited; without it,
     * it runs (and would call wp_update_post if any logic permits).
     */
    private static function probe_guard_with_publish_to_publish(): int {
        $workflow = new PressHub_AI_Workflow();
        $post = (object) [ 'ID' => 42, 'post_type' => 'post' ];
        $GLOBALS['POST_STATUSES'][42] = 'publish';

        // First call: author + no scorecard. new_status is 'publish'.
        do_action( 'transition_post_status', 'publish', 'publish', $post );

        return $GLOBALS['WP_UPDATE_POST_CALLS'];
    }

    /**
     * Simulate the worst-case recursion: the wp_update_post stub re-fires
     * transition_post_status with publish as the new status (modeling an
     * interaction with another plugin or hook that republishes the post).
     * With a recursion guard the handler bails after the first call; without
     * it the handler calls wp_update_post repeatedly until the safety cap.
     */
    private static function probe_reentry_publish_to_publish(): void {
        $GLOBALS['RECURSION_TEST_MODE'] = true;
        $GLOBALS['RECURSION_PROBE_POST'] = (object) [ 'ID' => 42, 'post_type' => 'post' ];
        $GLOBALS['POST_STATUSES'][42] = 'draft';
        new PressHub_AI_Workflow();
        // Fire publish transition for an author with no scorecard.
        do_action( 'transition_post_status', 'publish', 'draft', $GLOBALS['RECURSION_PROBE_POST'] );
    }
}

WorkflowRecursionTest::run();