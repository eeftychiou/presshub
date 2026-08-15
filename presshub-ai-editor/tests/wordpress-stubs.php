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

if ( ! function_exists( 'wp_remote_post' ) ) {
    /**
     * Stubbed wp_remote_post. Records every call and consults an optional
     * capture filter set by tests; otherwise returns a fake success that
     * the API client will treat as 'no predictions' and fall back to mock.
     */
    function wp_remote_post( $url, $args = [] ) {
        $GLOBALS['CAPTURED_REQUESTS'][] = [ $url, $args ];
        if ( isset( $GLOBALS['CAPTURE_FILTER'] ) ) {
            $captured = call_user_func( $GLOBALS['CAPTURE_FILTER'], null, [ $url, $args ] );
            if ( $captured !== null ) return $captured;
        }
        return [
            'response' => [ 'code' => 200 ],
            'body'     => '{"predictions":[]}',
        ];
    }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = [] ) {
        $GLOBALS['CAPTURED_GETS'][] = [ $url, $args ];
        return new WP_Error( 'no_network', 'stub: network disabled in tests' );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        if ( is_array( $response ) ) return $response['body'] ?? '';
        return '';
    }
}

if ( ! function_exists( 'register_setting' ) ) {
    function register_setting( $option_group, $option_name, $args = [] ) {
        $GLOBALS['REGISTERED_SETTINGS'][] = $option_name;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'get_temp_dir' ) ) {
    function get_temp_dir() {
        return sys_get_temp_dir() . '/';
    }
}