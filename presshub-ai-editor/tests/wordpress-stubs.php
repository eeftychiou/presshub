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

// Materialise empty stubs at ABSPATH/wp-admin/includes/ so the plugin's
// require_once calls don't fatal. This must happen here (not just once on
// a dev machine) because CI runners start with an empty temp dir.
if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
    mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}
foreach ( [ 'image.php', 'file.php', 'media.php' ] as $stub_file ) {
    $stub_path = ABSPATH . 'wp-admin/includes/' . $stub_file;
    if ( ! file_exists( $stub_path ) ) {
        file_put_contents( $stub_path, '' );
    }
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
    /**
     * Stubbed update_option. Stores the value and records every call
     * (including the autoload argument) in UPDATE_OPTION_CALLS so tests
     * can assert autoload flags (P-1: preset options must not autoload).
     */
    function update_option( $key, $value, $autoload = null ) {
        $GLOBALS['OPTIONS_STORE'][ $key ] = $value;
        $GLOBALS['UPDATE_OPTION_CALLS'][] = [ $key, $value, $autoload ];
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
        // the recursion hazard. The re-fired post carries the same
        // post_type as the original (from POST_TYPES, defaulting to 'post')
        // — real WP always passes a full WP_Post here, and the workflow's
        // post_type guard (Antigravity C-5) must not mask recursion bugs.
        $re_fired_post = (object) [
            'ID'        => $post_id,
            'post_type' => $GLOBALS['POST_TYPES'][ $post_id ] ?? 'post',
        ];
        if ( ! empty( $GLOBALS['RECURSION_TEST_MODE'] ) ) {
            do_action( 'transition_post_status', 'publish', $new_status, $re_fired_post );
        } else {
            do_action( 'transition_post_status', $new_status, $old_status, $re_fired_post );
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
        if ( isset( $GLOBALS['GET_RESPONSE_FILTER'] ) ) {
            $r = call_user_func( $GLOBALS['GET_RESPONSE_FILTER'], $url, $args );
            if ( $r !== null ) {
                return $r;
            }
        }
        return new WP_Error( 'no_network', 'stub: network disabled in tests' );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        if ( is_array( $response ) ) {
            return (int) ( $response['response']['code'] ?? 0 );
        }
        return 0;
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
        if ( is_array( $args ) && ! empty( $args['sanitize_callback'] ) ) {
            $GLOBALS['SANITIZE_CALLBACKS'][ $option_name ] = $args['sanitize_callback'];
        }
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

if ( ! function_exists( 'media_handle_sideload' ) ) {
    /**
     * Stubbed media_handle_sideload(). Returns a configurable fake media id,
     * or a WP_Error when SIDELOAD_FAIL is set. Captures the file_array so
     * tests can assert filename propagation through the sideload_media helper.
     */
    function media_handle_sideload( $file_array, $post_id = 0, $title = null, $post_data = [] ) {
        if ( isset( $GLOBALS['SIDELOAD_CAPTURE'] ) ) {
            $GLOBALS['SIDELOAD_CAPTURE'][] = $file_array;
        }
        if ( ! empty( $GLOBALS['SIDELOAD_LAST_POST_ID_OVERRIDE'] ) ) {
            $GLOBALS['SIDELOAD_LAST_POST_ID'] = $post_id;
        }
        if ( ! empty( $GLOBALS['SIDELOAD_LAST_TITLE_OVERRIDE'] ) ) {
            $GLOBALS['SIDELOAD_LAST_TITLE'] = $title;
        }
        if ( ! empty( $GLOBALS['SIDELOAD_FAIL'] ) ) {
            return new WP_Error( $GLOBALS['SIDELOAD_FAIL']['code'], $GLOBALS['SIDELOAD_FAIL']['msg'] );
        }
        $id = $GLOBALS['SIDELOAD_RETURN_ID'] ?? null;
        if ( $id ) {
            return $id;
        }
        return $GLOBALS['SIDELOAD_FIXED_ID'] ?? 1;
    }
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $id ) {
        return 'http://example.test/?attachment_id=' . $id;
    }
}

if ( ! function_exists( 'wp_handle_upload' ) ) {
    /**
     * Stubbed wp_handle_upload. Counts every invocation (HANDLE_UPLOAD_CALLS)
     * so tests can assert that uploads never happen after a rate-limit
     * rejection or validation failure. Tests may install a canned success
     * via HANDLE_UPLOAD_RESULT.
     */
    function wp_handle_upload( &$file, $overrides = false, $time = null ) {
        $GLOBALS['HANDLE_UPLOAD_CALLS'] = ( $GLOBALS['HANDLE_UPLOAD_CALLS'] ?? 0 ) + 1;
        if ( isset( $GLOBALS['HANDLE_UPLOAD_RESULT'] ) ) {
            return $GLOBALS['HANDLE_UPLOAD_RESULT'];
        }
        return [ 'error' => 'wp_handle_upload not stubbed' ];
    }
}

if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
    /**
     * Stubbed wp_check_filetype_and_ext. Derives the extension from the
     * filename — the real function consults the WP mime whitelist, which
     * is exactly the behaviour the upload-validation tests need.
     */
    function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        return [
            'ext'             => $ext,
            'type'            => 'application/octet-stream',
            'proper_filename' => false,
        ];
    }
}

if ( ! function_exists( 'get_post' ) ) {
    /**
     * Stubbed get_post. Backed by a simple map ($GLOBALS['POSTS_STORE'],
     * keyed by post ID, values are arrays) so tests can plant posts with
     * a given status and observe status-guard behaviour.
     */
    function get_post( $post = null, $output = null, $filter = 'raw' ) {
        $store = $GLOBALS['POSTS_STORE'] ?? [];
        $id    = is_object( $post ) ? (int) $post->ID : (int) $post;
        if ( ! isset( $store[ $id ] ) ) {
            return null;
        }
        return (object) $store[ $id ];
    }
}

// In the stubbed environment, real wp-admin includes don't exist. We
// materialise empty stubs at ABSPATH/wp-admin/includes/ so the plugin's
// require_once calls don't fatal. See TestBootstrap.

if ( ! function_exists( 'check_ajax_referer' ) ) {
    /**
     * Stubbed check_ajax_referer. By default the nonce is valid; tests can
     * flip NONCE_VALID to false to exercise the permission failure path.
     */
    function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
        if ( empty( $GLOBALS['NONCE_VALID'] ) ) {
            if ( $die ) {
                throw new RuntimeException( 'check_ajax_referer failed' );
            }
            return false;
        }
        return true;
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null, $options = 0 ) {
        $GLOBALS['JSON_RESPONSES'][] = [ 'success' => false, 'data' => $data ];
        // Mirror WP's behavior: wp_send_json_error calls wp_die() internally.
        throw new RuntimeException( 'wp_send_json_error: ' . ( is_string( $data ) ? $data : wp_json_encode( $data ) ) );
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null, $options = 0 ) {
        $GLOBALS['JSON_RESPONSES'][] = [ 'success' => true, 'data' => $data ];
        throw new RuntimeException( 'wp_send_json_success' );
    }
}

if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $postarr, $wp_error = false ) {
        $id = $GLOBALS['WP_INSERT_POST_COUNTER'] = ( $GLOBALS['WP_INSERT_POST_COUNTER'] ?? 100 ) + 1;
        $GLOBALS['WP_INSERTED_POSTS'][] = $postarr;
        return $id;
    }
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    function wp_schedule_single_event( $timestamp, $hook, $args = [] ) {
        $GLOBALS['SCHEDULED_EVENTS'][] = [ 'time' => $timestamp, 'hook' => $hook, 'args' => $args ];
        return true;
    }
}

if ( ! function_exists( 'wp_html_excerpt' ) ) {
    function wp_html_excerpt( $str, $length, $more = '&hellip;' ) {
        $str = wp_strip_all_tags( $str );
        if ( strlen( $str ) > $length ) {
            $str = substr( $str, 0, $length ) . $more;
        }
        return $str;
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        return strip_tags( $string );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        return trim( $str );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return (int) ( $GLOBALS['CURRENT_USER_ID'] ?? 0 );
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    /**
     * Stubbed get_transient. Reads from a process-local store. Returns the
     * stored value, or false when missing/expired. Supports a controllable
     * clock via TIME_NOW for deterministic window-expiry tests.
     */
    function get_transient( $key ) {
        $store = $GLOBALS['TRANSIENT_STORE'] ?? [];
        if ( ! array_key_exists( $key, $store ) ) {
            return false;
        }
        $entry = $store[ $key ];
        $expires_at = $entry['expires_at'] ?? 0;
        $now = $GLOBALS['TIME_NOW'] ?? time();
        if ( $expires_at > 0 && $expires_at <= $now ) {
            unset( $GLOBALS['TRANSIENT_STORE'][ $key ] );
            return false;
        }
        return $entry['value'];
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    /**
     * Stubbed set_transient. Stores value + computed expiry in the same
     * process-local store. Expiry is computed from TIME_NOW when supplied
     * (tests), otherwise real time() (production).
     */
    function set_transient( $key, $value, $expiration = 0 ) {
        $now = $GLOBALS['TIME_NOW'] ?? time();
        $GLOBALS['TRANSIENT_STORE'][ $key ] = [
            'value'      => $value,
            'expires_at' => $expiration > 0 ? $now + $expiration : 0,
        ];
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ) {
        unset( $GLOBALS['TRANSIENT_STORE'][ $key ] );
        return true;
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    /**
     * Stubbed wp_kses_post. Without a real WP install we just strip tags —
     * the existing tests don't actually validate the HTML round-trip.
     */
    function wp_kses_post( $data ) {
        return is_string( $data ) ? trim( $data ) : $data;
    }
}

if ( ! function_exists( '__' ) ) {
    /**
     * Stubbed translation function. Without WP's l10n stack we just pass
     * the string through; the rate limiter's error messages are still
     * meaningful English for end users.
     */
    function __( $text, $domain = null ) {
        return $text;
    }
}

// i18n sweep (2026-08-16): esc_*__ mirror __() in the stub environment
// so tests can render translated output without a full WP l10n stack.
// In real WP these are the standard esc_html__ / esc_attr__ helpers.
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = null ) {
        return htmlspecialchars( (string) __( $text, $domain ), ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $text, $domain = null ) {
        return htmlspecialchars( (string) __( $text, $domain ), ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_html_x' ) ) {
    function esc_html_x( $text, $context, $domain = null ) {
        return htmlspecialchars( (string) __( $text, $domain ), ENT_QUOTES, 'UTF-8' );
    }
}

// --- Stubs for the admin presets page (org defaults, 2026-08-15 §9 Q3) ---

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'get_terms' ) ) {
    /**
     * Stubbed get_terms. Returns the process-local TERMS_STORE array of
     * term objects (term_id / name / slug), or [] by default so admin
     * page renders degrade gracefully in tests. Real WP returns
     * WP_Term[] or a WP_Error on failure — callers must handle both.
     */
    function get_terms( $args = [], $deprecated = '' ) {
        $store = $GLOBALS['TERMS_STORE'] ?? [];
        if ( ! is_array( $store ) ) {
            return [];
        }
        return $store;
    }
}

// --- Stubs for research cleanup + prompt filters ---

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['FILTERS'][ $tag ][] = [ 'callback' => $callback, 'priority' => $priority, 'accepted_args' => $accepted_args ];
        return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value, ...$args ) {
        foreach ( ( $GLOBALS['FILTERS'][ $tag ] ?? [] ) as $entry ) {
            $value = call_user_func_array( $entry['callback'], array_merge( [ $value ], $args ) );
        }
        return $value;
    }
}

if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        return $GLOBALS['GET_POSTS_RESULT'] ?? [];
    }
}

if ( ! function_exists( 'wp_delete_post' ) ) {
    function wp_delete_post( $post_id, $force = false ) {
        $GLOBALS['DELETED_POSTS'][] = [ 'id' => $post_id, 'force' => $force ];
        return true;
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook, $args = [] ) {
        return $GLOBALS['NEXT_SCHEDULED'][ $hook ] ?? false;
    }
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [] ) {
        $GLOBALS['RECURRING_EVENTS'][] = [ 'time' => $timestamp, 'recurrence' => $recurrence, 'hook' => $hook, 'args' => $args ];
        return true;
    }
}

// --- Stubs for presets feature (user meta) + settings overhaul (Settings API) ---

if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $user_id, $key, $single = false ) {
        $store = $GLOBALS['USER_META_STORE'] ?? [];
        $val = $store[ $user_id ][ $key ] ?? ( $single ? '' : [] );
        return $val;
    }
}

if ( ! function_exists( 'update_user_meta' ) ) {
    function update_user_meta( $user_id, $key, $value ) {
        $GLOBALS['USER_META_STORE'][ $user_id ][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_user_meta' ) ) {
    function delete_user_meta( $user_id, $key, $value = '' ) {
        unset( $GLOBALS['USER_META_STORE'][ $user_id ][ $key ] );
        return true;
    }
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user() {
        $id = (int) ( $GLOBALS['CURRENT_USER_ID'] ?? 0 );
        return (object) [ 'ID' => $id, 'roles' => $GLOBALS['CURRENT_USER_ROLES'] ?? [] ];
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

if ( ! function_exists( 'add_settings_section' ) ) {
    function add_settings_section( $id, $title, $callback, $page ) {
        $GLOBALS['SECTIONS'][ $page ][] = [ 'id' => $id, 'title' => $title, 'callback' => $callback ];
        return true;
    }
}

if ( ! function_exists( 'add_settings_field' ) ) {
    function add_settings_field( $id, $title, $callback, $page, $section, $args = [] ) {
        $GLOBALS['FIELDS'][ $page ][ $section ][] = [ 'id' => $id, 'title' => $title, 'callback' => $callback, 'args' => $args ];
        return true;
    }
}

if ( ! function_exists( 'do_settings_sections' ) ) {
    function do_settings_sections( $page ) {
        $GLOBALS['RENDERED_SECTIONS'][ $page ] = true;
    }
}

if ( ! function_exists( 'settings_fields' ) ) {
    function settings_fields( $option_group ) {
        $GLOBALS['RENDERED_SETTINGS_FIELDS'][ $option_group ] = true;
    }
}

if ( ! function_exists( 'add_help_tab' ) ) {
    function add_help_tab( $args ) {
        $GLOBALS['HELP_TABS'][] = $args;
        return true;
    }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '', $title = '', $args = [] ) {
        throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : json_encode( $message ) ) );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( (string) $str );
    }
}

// D-3: the API client logs provider errors via error_log(). error_log is
// a PHP built-in (cannot be stubbed), so redirect the log target to a
// temp file instead — keeps test output clean and leaves the messages
// inspectable at PRESSHUB_TEST_ERROR_LOG for any future logging tests.
if ( ! defined( 'PRESSHUB_TEST_ERROR_LOG' ) ) {
    define( 'PRESSHUB_TEST_ERROR_LOG', sys_get_temp_dir() . '/presshub-test-error.log' );
    ini_set( 'error_log', PRESSHUB_TEST_ERROR_LOG );
}