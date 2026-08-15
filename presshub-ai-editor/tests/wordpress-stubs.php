<?php
/**
 * Minimal WordPress function stubs for PressHub AI Editor unit tests.
 *
 * These shims let us load plugin classes without a full WordPress install.
 * Each function is intentionally simple — only enough surface area for the
 * behaviour under test.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/presshub-test/' );
}

if ( ! defined( 'HOOK_INVOCATION_COUNT' ) ) {
    // Tracks how many times transition_post_status handlers fire.
    $GLOBALS['HOOK_INVOCATION_COUNT'] = 0;
    $GLOBALS['HOOK_INVOCATION_LOG'] = [];
    $GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
    // Storage for the current "active" transition.
    $GLOBALS['CURRENT_TRANSITION'] = null;
}



if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        $store = $GLOBALS['POST_META_STORE'] ?? [];
        return $store[ $post_id ][ $key ] ?? ( $single ? '' : [] );
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $value ) {
        $GLOBALS['POST_META_STORE'][ $post_id ][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        $opts = $GLOBALS['OPTIONS_STORE'] ?? [];
        return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value ) {
        $GLOBALS['OPTIONS_STORE'][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) {
        $caps = $GLOBALS['CURRENT_USER_CAPS'] ?? [];
        return in_array( $capability, $caps, true );
    }
}

if ( ! function_exists( 'wp_update_post' ) ) {
    /**
     * Stubbed wp_update_post. Records every call and triggers
     * transition_post_status again — exactly the recursion the bug describes.
     */
    function wp_update_post( $postarr, $wp_error = false ) {
        // Bail if we've exceeded the recursion safety cap.
        if ( ( $GLOBALS['RECURSION_MAX_CALLS'] ?? 0 ) > 0 &&
             ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) >= $GLOBALS['RECURSION_MAX_CALLS'] ) {
            throw new RuntimeException( 'wp_update_post recursion safety cap reached' );
        }

        $post_id = $postarr['ID'] ?? 0;
        $new_status = $postarr['post_status'] ?? 'publish';
        $old_status = $GLOBALS['POST_STATUSES'][ $post_id ] ?? 'draft';
        $GLOBALS['POST_STATUSES'][ $post_id ] = $new_status;
        $GLOBALS['WP_UPDATE_POST_CALLS']++;
        $GLOBALS['HOOK_INVOCATION_LOG'][] = [
            'post_id' => $post_id,
            'old' => $old_status,
            'new' => $new_status,
        ];

        // Recreate the transition_post_status re-fire so tests can observe
        // the recursion hazard. In recursion-test mode, re-fire with the
        // SAME publish->publish pair so the buggy handler re-enters; in
        // normal mode re-fire with the natural publish->pending pair.
        if ( ! empty( $GLOBALS['RECURSION_TEST_MODE'] ) ) {
            do_action( 'transition_post_status', 'publish', $new_status, (object) [ 'ID' => $post_id ] );
        } else {
            do_action( 'transition_post_status', $new_status, $old_status, (object) [ 'ID' => $post_id ] );
        }
        return $post_id;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) {
        $GLOBALS['DO_ACTION_LOG'][] = [ 'hook' => $hook, 'args' => $args ];
        // transition_post_status handlers are registered via add_action(); the
        // shim above ignores arguments, so we explicitly invoke them here.
        if ( 'transition_post_status' === $hook && isset( $GLOBALS['TRANSITION_HANDLERS'] ) ) {
            foreach ( $GLOBALS['TRANSITION_HANDLERS'] as $cb ) {
                $GLOBALS['HOOK_INVOCATION_COUNT']++;
                call_user_func_array( $cb, $args );
            }
        }
    }
}