<?php
/**
 * Wrapper that re-implements add_action for our test environment so that
 * PressHub_AI_Workflow's constructor actually registers its
 * transition_post_status callback. Without this, the test would have no
 * handler to fire.
 */

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['ACTIONS'][ $hook ][] = $callback;
        if ( 'transition_post_status' === $hook ) {
            $GLOBALS['TRANSITION_HANDLERS'][] = $callback;
        }
    }
}