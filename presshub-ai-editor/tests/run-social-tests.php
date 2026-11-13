<?php
/**
 * Master test runner for PressHub Social Auto-Post plugin.
 *
 * Runs standalone tests with isolated mocks and assertions covering:
 * - Activator
 * - Queue Manager (validation, state transitions, exponential backoff, alerts, stats)
 * - Meta API Client (Zero-Silent-Fallback, payload formatting)
 * - Worker Client (Zero-Silent-Fallback, HTTP client, methods)
 * - Dispatcher (post transition hook gating, error-preserved enqueuing, engine routing)
 * - Metabox (security gates, nonces, save verification)
 * - AJAX Handlers (security gates, nonces, routing)
 *
 * @package PressHub_Social_Autopost
 */

// 1. Initialize WordPress environment / stubs
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/presshub-social-test/' );
}

$wp_stubs_path = dirname( __DIR__, 2 ) . '/presshub-ai-editor/tests/wordpress-stubs.php';
if ( file_exists( $wp_stubs_path ) ) {
    require_once $wp_stubs_path;
}

// Ensure cron globals and unschedule stub exist
if ( ! isset( $GLOBALS['RECURRING_EVENTS'] ) ) {
    $GLOBALS['RECURRING_EVENTS'] = [];
}
if ( ! isset( $GLOBALS['NEXT_SCHEDULED'] ) ) {
    $GLOBALS['NEXT_SCHEDULED'] = [];
}
if ( ! isset( $GLOBALS['SENT_EMAILS'] ) ) {
    $GLOBALS['SENT_EMAILS'] = [];
}
if ( ! isset( $GLOBALS['CURRENT_USER_CAPS'] ) ) {
    $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options', 'edit_post' ];
} else {
    $GLOBALS['CURRENT_USER_CAPS'][] = 'edit_posts';
    $GLOBALS['CURRENT_USER_CAPS'][] = 'manage_options';
    $GLOBALS['CURRENT_USER_CAPS'][] = 'edit_post';
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
    function wp_unschedule_event( $timestamp, $hook, $args = [] ): bool {
        unset( $GLOBALS['NEXT_SCHEDULED'][ $hook ] );
        if ( isset( $GLOBALS['RECURRING_EVENTS'] ) ) {
            $GLOBALS['RECURRING_EVENTS'] = array_values( array_filter(
                $GLOBALS['RECURRING_EVENTS'],
                fn( $ev ) => ( $ev['hook'] ?? '' ) !== $hook
            ) );
        }
        return true;
    }
}

// REST API stubs
if ( ! isset( $GLOBALS['WP_REST_ROUTES'] ) ) {
    $GLOBALS['WP_REST_ROUTES'] = [];
}

if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = [] ): bool {
        $GLOBALS['WP_REST_ROUTES'][ $namespace ][ $route ] = $args;
        return true;
    }
}

if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $path = '' ): string {
        return 'https://example.com/wp-json/' . ltrim( $path, '/' );
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private string $method;
        private string $route;
        private array $headers = [];
        private array $params = [];

        public function __construct( string $method = 'GET', string $route = '' ) {
            $this->method = strtoupper( $method );
            $this->route  = $route;
        }

        public function set_header( string $key, string $value ): void {
            $this->headers[ strtolower( $key ) ] = $value;
        }

        public function get_header( string $key ): ?string {
            return $this->headers[ strtolower( $key ) ] ?? null;
        }

        public function set_param( string $key, $value ): void {
            $this->params[ $key ] = $value;
        }

        public function get_param( string $key ) {
            return $this->params[ $key ] ?? null;
        }

        public function get_params(): array {
            return $this->params;
        }

        public function get_json_params(): array {
            return $this->params;
        }
    }
}

// Fallback stubs for standalone environments
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private array $errors = [];
        private array $error_data = [];

        public function __construct( string $code = '', string $message = '', $data = '' ) {
            if ( ! empty( $code ) ) {
                $this->errors[ $code ][] = $message;
                if ( ! empty( $data ) ) {
                    $this->error_data[ $code ] = $data;
                }
            }
        }

        public function get_error_code(): string {
            $codes = array_keys( $this->errors );
            return $codes[0] ?? '';
        }

        public function get_error_message( string $code = '' ): string {
            if ( empty( $code ) ) {
                $code = $this->get_error_code();
            }
            return $this->errors[ $code ][0] ?? '';
        }

        public function get_error_data( string $code = '' ) {
            if ( empty( $code ) ) {
                $code = $this->get_error_code();
            }
            return $this->error_data[ $code ] ?? null;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ): string {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ): string {
        return trim( strip_tags( (string) $str ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ): string {
        return trim( strip_tags( (string) $str ) );
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ): string {
        return filter_var( (string) $url, FILTER_SANITIZE_URL ) ?: '';
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $str ): string {
        return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $str ): string {
        return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $str ): string {
        return filter_var( (string) $str, FILTER_SANITIZE_URL ) ?: '';
    }
}

if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $str ): string {
        return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ): string {
        return (string) $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ): string {
        return esc_html( (string) $text );
    }
}

if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $text, $domain = 'default' ): string {
        return esc_attr( (string) $text );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = false ): string {
        return gmdate( 'Y-m-d H:i:s' );
    }
}

if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $string ): string {
        return rtrim( (string) $string, '/\\' );
    }
}

if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ): string {
        return untrailingslashit( $string ) . '/';
    }
}

if ( ! function_exists( 'has_excerpt' ) ) {
    function has_excerpt( $post = null ): bool {
        $p = is_object( $post ) ? $post : get_post( $post );
        return ! empty( $p->post_excerpt );
    }
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        $tmp = sys_get_temp_dir() . '/presshub_social_test_uploads';
        if ( ! is_dir( $tmp ) ) {
            @mkdir( $tmp, 0777, true );
        }
        return [
            'basedir' => $tmp,
            'baseurl' => 'http://example.com/wp-content/uploads',
        ];
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string ): string {
        return strip_tags( (string) $string );
    }
}

if ( ! function_exists( 'wp_trim_words' ) ) {
    function wp_trim_words( $text, $num_words = 55, $more = null ): string {
        $words = preg_split( '/\s+/', trim( (string) $text ) );
        if ( count( $words ) <= $num_words ) {
            return implode( ' ', $words );
        }
        return implode( ' ', array_slice( $words, 0, $num_words ) ) . ( $more ?? '...' );
    }
}

if ( ! function_exists( 'number_format_i18n' ) ) {
    function number_format_i18n( $number, $decimals = 0 ): string {
        return number_format( (float) $number, $decimals );
    }
}

if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $echo = true ) {
        $res = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
        if ( $echo ) echo $res;
        return $res;
    }
}

if ( ! function_exists( 'selected' ) ) {
    function selected( $selected, $current = true, $echo = true ) {
        $res = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
        if ( $echo ) echo $res;
        return $res;
    }
}

// In-memory options, postmeta, posts, cron, and mails store for tests
$GLOBALS['test_options']  = [];
$GLOBALS['test_postmeta'] = [];
$GLOBALS['test_posts']    = [];
$GLOBALS['test_cron']     = [];
$GLOBALS['test_mails']    = [];
$GLOBALS['test_user_caps'] = [ 'edit_posts' => true, 'manage_options' => true ];

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        return $GLOBALS['test_options'][ $key ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value, $autoload = null ): bool {
        $GLOBALS['test_options'][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
        if ( ! isset( $GLOBALS['test_options'][ $key ] ) ) {
            $GLOBALS['test_options'][ $key ] = $value;
            return true;
        }
        return false;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $key ): bool {
        unset( $GLOBALS['test_options'][ $key ] );
        return true;
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key = '', $single = false ) {
        if ( empty( $key ) ) {
            return $GLOBALS['test_postmeta'][ $post_id ] ?? [];
        }
        $val = $GLOBALS['test_postmeta'][ $post_id ][ $key ] ?? null;
        return $single ? $val : ( null !== $val ? [ $val ] : [] );
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $value, $prev_value = '' ): bool {
        $GLOBALS['test_postmeta'][ $post_id ][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $key, $value = '' ): bool {
        unset( $GLOBALS['test_postmeta'][ $post_id ][ $key ] );
        unset( $GLOBALS['POST_META_STORE'][ $post_id ][ $key ] );
        return true;
    }
}

if ( ! function_exists( 'get_post' ) ) {
    function get_post( $post_id ) {
        return $GLOBALS['test_posts'][ $post_id ] ?? null;
    }
}

if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_title = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_date = '';
        public string $post_status = 'publish';

        public function __construct( array $data = [] ) {
            foreach ( $data as $k => $v ) {
                $this->$k = $v;
            }
        }
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook, $args = [] ) {
        return $GLOBALS['test_cron'][ $hook ] ?? false;
    }
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [] ): bool {
        $GLOBALS['test_cron'][ $hook ] = $timestamp;
        return true;
    }
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
    function wp_unschedule_event( $timestamp, $hook, $args = [] ): bool {
        unset( $GLOBALS['test_cron'][ $hook ] );
        return true;
    }
}

if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ): bool {
        $GLOBALS['test_mails'][] = compact( 'to', 'subject', 'message' );
        return true;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap, ...$args ): bool {
        return ! empty( $GLOBALS['test_user_caps'][ $cap ] );
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ): string {
        return 'nonce_' . $action;
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ): bool {
        return $nonce === 'nonce_' . $action || 'valid_nonce' === $nonce;
    }
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $die = true ): bool {
        $nonce = $_REQUEST[ $query_arg ?: '_ajax_nonce' ] ?? '';
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            if ( $die ) {
                throw new RuntimeException( 'Nonce verification failed' );
            }
            return false;
        }
        return true;
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ): string {
        $field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
        if ( $echo ) echo $field;
        return $field;
    }
}

if ( ! function_exists( 'has_post_thumbnail' ) ) {
    function has_post_thumbnail( $post = null ): bool {
        return false;
    }
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
    function get_edit_post_link( $id = 0 ): string {
        return "http://example.com/wp-admin/post.php?post={$id}&action=edit";
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ): string {
        return 'http://example.com/wp-admin/' . $path;
    }
}

// Load plugin classes under test
$base_dir = dirname( __DIR__ );
if ( file_exists( $base_dir . '/includes/class-social-logger.php' ) ) {
    require_once $base_dir . '/includes/class-social-logger.php';
}
if ( file_exists( $base_dir . '/includes/class-social-prompt-loader.php' ) ) {
    require_once $base_dir . '/includes/class-social-prompt-loader.php';
}
if ( file_exists( $base_dir . '/includes/class-social-settings-storage.php' ) ) {
    require_once $base_dir . '/includes/class-social-settings-storage.php';
}
if ( file_exists( $base_dir . '/includes/class-social-settings-render.php' ) ) {
    require_once $base_dir . '/includes/class-social-settings-render.php';
}
if ( file_exists( $base_dir . '/includes/class-social-ai-copywriter.php' ) ) {
    require_once $base_dir . '/includes/class-social-ai-copywriter.php';
}
require_once $base_dir . '/includes/class-social-activator.php';
require_once $base_dir . '/includes/class-social-queue.php';
require_once $base_dir . '/includes/class-social-tiktok-narrator.php';
require_once $base_dir . '/includes/class-social-tiktok-shorts.php';
require_once $base_dir . '/includes/class-social-meta-api-client.php';
require_once $base_dir . '/includes/class-social-worker-client.php';
require_once $base_dir . '/includes/class-social-dispatcher.php';
require_once $base_dir . '/includes/class-social-metabox.php';
require_once $base_dir . '/includes/class-social-queue-admin.php';
require_once $base_dir . '/includes/class-social-ajax-handlers.php';
require_once $base_dir . '/includes/class-social-rest-controller.php';

// Force queue into in-memory mode for clean unit testing
PressHub_Social_Queue::$force_memory = true;
PressHub_Social_Queue::reset_memory_store();

// Test Runner Infrastructure
$tests_run    = 0;
$tests_passed = 0;
$failures     = [];

function assert_true( bool $condition, string $message ): void {
    global $tests_run, $tests_passed, $failures;
    $tests_run++;
    if ( $condition ) {
        $tests_passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failures[] = $message;
        echo "  [FAIL] {$message}\n";
    }
}

function assert_false( bool $condition, string $message ): void {
    assert_true( ! $condition, $message );
}

function assert_equals( $expected, $actual, string $message ): void {
    $matched = ( $expected === $actual );
    if ( ! $matched ) {
        $detail = sprintf( ' Expected: %s, Got: %s', json_encode( $expected ), json_encode( $actual ) );
        assert_true( false, $message . $detail );
    } else {
        assert_true( true, $message );
    }
}

echo "=================================================================\n";
echo "Running PressHub Social Auto-Post Unit Tests\n";
echo "=================================================================\n\n";

// ----------------------------------------------------------------------
// Test Suite 1: Activator
// ----------------------------------------------------------------------
echo "--- Suite 1: PressHub_Social_Activator ---\n";

PressHub_Social_Activator::activate();
foreach ( $GLOBALS['RECURRING_EVENTS'] ?? [] as $ev ) {
    if ( ! empty( $ev['hook'] ) ) {
        $GLOBALS['NEXT_SCHEDULED'][ $ev['hook'] ] = $ev['time'] ?? time();
    }
}
$is_scheduled = ( false !== wp_next_scheduled( 'presshub_social_cron_dispatch' ) )
    || ! empty( array_filter( $GLOBALS['RECURRING_EVENTS'] ?? [], fn( $e ) => 'presshub_social_cron_dispatch' === ( $e['hook'] ?? '' ) ) );

assert_true(
    $is_scheduled,
    'Activator schedules presshub_social_cron_dispatch event'
);

PressHub_Social_Activator::deactivate();
$still_scheduled = ( false !== wp_next_scheduled( 'presshub_social_cron_dispatch' ) )
    || ! empty( array_filter( $GLOBALS['RECURRING_EVENTS'] ?? [], fn( $e ) => 'presshub_social_cron_dispatch' === ( $e['hook'] ?? '' ) ) );

assert_true(
    ! $still_scheduled,
    'Deactivator removes scheduled presshub_social_cron_dispatch event'
);

// ----------------------------------------------------------------------
// Test Suite 2: Queue Validation
// ----------------------------------------------------------------------
echo "\n--- Suite 2: Queue Validation ---\n";
PressHub_Social_Queue::reset_memory_store();

$err1 = PressHub_Social_Queue::enqueue( [
    'post_id'   => 0,
    'platform'  => 'twitter',
    'engine'    => 'api',
    'post_copy' => 'Sample post',
] );
assert_true( is_wp_error( $err1 ) && 'invalid_post_id' === $err1->get_error_code(), 'Queue rejects invalid post_id <= 0' );

$err2 = PressHub_Social_Queue::enqueue( [
    'post_id'   => 42,
    'platform'  => 'myspace',
    'engine'    => 'api',
    'post_copy' => 'Sample post',
] );
assert_true( is_wp_error( $err2 ) && 'invalid_platform' === $err2->get_error_code(), 'Queue rejects unsupported platform' );

$err3 = PressHub_Social_Queue::enqueue( [
    'post_id'   => 42,
    'platform'  => 'twitter',
    'engine'    => 'carrier_pigeon',
    'post_copy' => 'Sample post',
] );
assert_true( is_wp_error( $err3 ) && 'invalid_engine' === $err3->get_error_code(), 'Queue rejects unsupported engine' );

$err4 = PressHub_Social_Queue::enqueue( [
    'post_id'   => 42,
    'platform'  => 'twitter',
    'engine'    => 'api',
    'post_copy' => '   ',
] );
assert_true( is_wp_error( $err4 ) && 'empty_post_copy' === $err4->get_error_code(), 'Queue rejects empty post copy' );

// ----------------------------------------------------------------------
// Test Suite 3: Queue Enqueue & Retrieval
// ----------------------------------------------------------------------
echo "\n--- Suite 3: Queue Enqueue & Retrieval ---\n";

$job_id1 = PressHub_Social_Queue::enqueue( [
    'post_id'   => 101,
    'platform'  => 'twitter',
    'engine'    => 'automation',
    'post_copy' => 'Breaking News: Test Story',
] );
assert_true( is_int( $job_id1 ) && $job_id1 > 0, 'Enqueue returns positive job ID' );

$job1 = PressHub_Social_Queue::get_job( $job_id1 );
assert_true( null !== $job1, 'get_job returns enqueued job record' );
assert_equals( 'pending', $job1['status'] ?? '', 'Initial job status is pending' );
assert_equals( 0, (int) ( $job1['attempts'] ?? -1 ), 'Initial attempts is 0' );
assert_equals( 'twitter', $job1['platform'] ?? '', 'Platform is properly recorded' );
assert_equals( 'automation', $job1['engine'] ?? '', 'Engine is properly recorded' );

$post_jobs = PressHub_Social_Queue::get_jobs_for_post( 101 );
assert_equals( 1, count( $post_jobs ), 'get_jobs_for_post returns enqueued job' );

// ----------------------------------------------------------------------
// Test Suite 4: Due Pending Jobs Query & Sorting
// ----------------------------------------------------------------------
echo "\n--- Suite 4: Due Pending Jobs Query & Sorting ---\n";
PressHub_Social_Queue::reset_memory_store();

$past_time = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$future_time = gmdate( 'Y-m-d H:i:s', time() + 3600 );

$id_past1 = PressHub_Social_Queue::enqueue( [
    'post_id'      => 1,
    'platform'     => 'facebook',
    'engine'       => 'api',
    'post_copy'    => 'Past 1',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 100 ),
] );

$id_future = PressHub_Social_Queue::enqueue( [
    'post_id'      => 2,
    'platform'     => 'facebook',
    'engine'       => 'api',
    'post_copy'    => 'Future',
    'scheduled_at' => $future_time,
] );

$id_past2 = PressHub_Social_Queue::enqueue( [
    'post_id'      => 3,
    'platform'     => 'facebook',
    'engine'       => 'api',
    'post_copy'    => 'Past 2',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 200 ),
] );

$due = PressHub_Social_Queue::get_due_pending_jobs( 10 );
assert_equals( 2, count( $due ), 'Only past/due jobs are returned by get_due_pending_jobs' );
assert_equals( $id_past2, (int) $due[0]['id'], 'Due jobs are sorted chronologically ASC' );

// Test engine and platform filtering
$id_past_auto = PressHub_Social_Queue::enqueue( [
    'post_id'      => 4,
    'platform'     => 'twitter',
    'engine'       => 'automation',
    'post_copy'    => 'Twitter auto',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 50 ),
] );

$due_auto = PressHub_Social_Queue::get_due_pending_jobs( 10, 'automation' );
assert_equals( 1, count( $due_auto ), 'Filtering by engine=automation returns only automation jobs' );
assert_equals( $id_past_auto, (int) $due_auto[0]['id'], 'Matched automation job ID' );

$due_tiktok = PressHub_Social_Queue::get_due_pending_jobs( 10, 'automation', [ 'tiktok' ] );
assert_equals( 0, count( $due_tiktok ), 'Filtering by active platforms excludes non-matching platforms' );

$due_twitter = PressHub_Social_Queue::get_due_pending_jobs( 10, 'automation', [ 'twitter' ] );
assert_equals( 1, count( $due_twitter ), 'Filtering by matching active platform returns Twitter job' );

// ----------------------------------------------------------------------
// Test Suite 5: State Transitions, Backoff & Permanent Failure Alert
// ----------------------------------------------------------------------
echo "\n--- Suite 5: State Transitions, Backoff & Alerts ---\n";
PressHub_Social_Queue::reset_memory_store();
update_option( 'presshub_social_alert_email_enabled', 1 );
update_option( 'presshub_social_alert_email_address', 'admin@example.com' );
update_option( 'presshub_social_retry_base_delay', 60 );
$GLOBALS['SENT_EMAILS'] = [];
$GLOBALS['test_mails'] = [];

$t_job_id = PressHub_Social_Queue::enqueue( [
    'post_id'   => 55,
    'platform'  => 'instagram',
    'engine'    => 'api',
    'post_copy' => 'Photo caption',
] );

// 1. Mark processing
PressHub_Social_Queue::mark_processing( $t_job_id );
$j_proc = PressHub_Social_Queue::get_job( $t_job_id );
assert_equals( 'processing', $j_proc['status'] ?? '', 'Status transitions to processing' );
assert_equals( 1, (int) $j_proc['attempts'], 'Attempts incremented to 1' );

// 2. Mark failed (attempt 1/3) -> should schedule retry
PressHub_Social_Queue::mark_failed( $t_job_id, 'Temporary connection timeout' );
$j_retry = PressHub_Social_Queue::get_job( $t_job_id );
assert_equals( 'pending', $j_retry['status'] ?? '', 'Job status reset to pending for retry' );
assert_equals( 'Temporary connection timeout', $j_retry['last_error'] ?? '', 'Error message recorded' );
assert_true( $j_retry['scheduled_at'] > gmdate( 'Y-m-d H:i:s' ), 'Next scheduled_at set in the future' );
assert_equals( 0, count( $GLOBALS['test_mails'] ), 'No failure email sent on transient retry attempt' );

// 3. Increment to max_attempts (3/3) and mark failed permanently
PressHub_Social_Queue::mark_processing( $t_job_id ); // attempt 2
PressHub_Social_Queue::mark_processing( $t_job_id ); // attempt 3
PressHub_Social_Queue::mark_failed( $t_job_id, 'Fatal authentication failure' );
$j_failed = PressHub_Social_Queue::get_job( $t_job_id );
assert_equals( 'failed', $j_failed['status'] ?? '', 'Job marked as permanently failed when attempts reach max' );

$sent_emails = ! empty( $GLOBALS['SENT_EMAILS'] ) ? $GLOBALS['SENT_EMAILS'] : ( $GLOBALS['test_mails'] ?? [] );
assert_equals( 1, count( $sent_emails ), 'Alert email sent on permanent failure' );
assert_equals( 'admin@example.com', $sent_emails[0]['to'] ?? '', 'Alert email dispatched to configured recipient' );

// 4. Retry Job
PressHub_Social_Queue::retry_job( $t_job_id );
$j_restarted = PressHub_Social_Queue::get_job( $t_job_id );
assert_equals( 'pending', $j_restarted['status'], 'retry_job resets status to pending' );
assert_equals( 0, (int) $j_restarted['attempts'], 'retry_job resets attempts to 0' );

// 5. Cancel Job
PressHub_Social_Queue::cancel_job( $t_job_id );
$j_cancelled = PressHub_Social_Queue::get_job( $t_job_id );
assert_equals( 'cancelled', $j_cancelled['status'], 'cancel_job sets status to cancelled' );

// 6. Mark published
$pub_id = PressHub_Social_Queue::enqueue( [
    'post_id'   => 77,
    'platform'  => 'tiktok',
    'engine'    => 'automation',
    'post_copy' => 'Video viral hook',
] );
PressHub_Social_Queue::mark_published( $pub_id, 'ext-12345', 'https://tiktok.com/@video/12345' );
$j_pub = PressHub_Social_Queue::get_job( $pub_id );
assert_equals( 'published', $j_pub['status'], 'mark_published transitions status to published' );
assert_equals( 'ext-12345', $j_pub['external_post_id'], 'External post ID recorded' );
assert_equals( 'https://tiktok.com/@video/12345', $j_pub['external_post_url'], 'External post URL recorded' );

// ----------------------------------------------------------------------
// Test Suite 6: Queue Stats Computation
// ----------------------------------------------------------------------
echo "\n--- Suite 6: Queue Stats Computation ---\n";
$stats = PressHub_Social_Queue::get_stats();
assert_equals( 2, (int) $stats['total'], 'Stats total correctly computed' );
assert_equals( 1, (int) $stats['cancelled'], 'Stats cancelled counted' );
assert_equals( 1, (int) $stats['published'], 'Stats published counted' );

// ----------------------------------------------------------------------
// Test Suite 7: Meta API Client Zero-Silent-Fallback
// ----------------------------------------------------------------------
echo "\n--- Suite 7: Meta API Client Zero-Silent-Fallback ---\n";

// Clear Meta options
delete_option( 'presshub_social_meta_page_id' );
delete_option( 'presshub_social_meta_access_token' );
delete_option( 'presshub_social_instagram_account_id' );
delete_option( 'presshub_social_twitter_bearer_token' );
delete_option( 'presshub_social_tiktok_access_token' );

$fb_res = PressHub_Social_Meta_API_Client::publish_facebook( [ 'post_copy' => 'Test' ] );
assert_true( is_wp_error( $fb_res ) && 'missing_facebook_credentials' === $fb_res->get_error_code(), 'Facebook fails loudly when credentials missing' );

$ig_res = PressHub_Social_Meta_API_Client::publish_instagram( [ 'post_copy' => 'Test', 'media_url' => 'https://example.com/img.jpg' ] );
assert_true( is_wp_error( $ig_res ) && 'missing_instagram_credentials' === $ig_res->get_error_code(), 'Instagram fails loudly when credentials missing' );

$tw_res = PressHub_Social_Meta_API_Client::publish_twitter( [ 'post_copy' => 'Test' ] );
assert_true( is_wp_error( $tw_res ) && 'missing_twitter_credentials' === $tw_res->get_error_code(), 'Twitter fails loudly when credentials missing' );

$tk_res = PressHub_Social_Meta_API_Client::publish_tiktok( [ 'post_copy' => 'Test', 'media_url' => 'https://example.com/vid.mp4' ] );
assert_true( is_wp_error( $tk_res ) && 'missing_tiktok_credentials' === $tk_res->get_error_code(), 'TikTok fails loudly when credentials missing' );

// ----------------------------------------------------------------------
// Test Suite 8: Worker Client Zero-Silent-Fallback
// ----------------------------------------------------------------------
echo "\n--- Suite 8: Worker Client Zero-Silent-Fallback ---\n";

delete_option( 'presshub_social_worker_url' );
delete_option( 'presshub_social_worker_secret' );

$w_health = PressHub_Social_Worker_Client::check_health();
assert_true( is_wp_error( $w_health ) && 'missing_worker_config' === $w_health->get_error_code(), 'Worker client fails loudly when worker config missing' );

$w_disp = PressHub_Social_Worker_Client::dispatch( [ 'id' => 1, 'platform' => 'twitter', 'post_copy' => 'test' ] );
assert_true( is_wp_error( $w_disp ) && 'missing_worker_config' === $w_disp->get_error_code(), 'Worker dispatch fails loudly when worker secret missing' );

// ----------------------------------------------------------------------
// Test Suite 9: Dispatcher Post Status Transition Gating
// ----------------------------------------------------------------------
echo "\n--- Suite 9: Dispatcher Hook Gating ---\n";
PressHub_Social_Queue::reset_memory_store();

// Setup valid credentials and worker sessions for dispatcher and metabox tests
update_option( 'presshub_social_meta_page_id', '12345' );
update_option( 'presshub_social_meta_access_token', 'valid_token' );
update_option( 'presshub_social_instagram_account_id', '67890' );
update_option( 'presshub_social_twitter_bearer_token', 'valid_bearer_token' );
update_option( 'presshub_social_tiktok_access_token', 'valid_tiktok_token' );
update_option( 'presshub_social_tiktok_open_id', 'valid_tiktok_openid' );
update_option( 'presshub_social_worker_url', 'http://127.0.0.1:3456' );
update_option( 'presshub_social_worker_secret', 'test-secret' );
update_option( 'presshub_social_worker_sessions', [
    'twitter'   => true,
    'facebook'  => true,
    'instagram' => true,
    'tiktok'    => true,
] );
update_option( 'presshub_social_enabled_platforms', [ 'twitter', 'facebook', 'instagram', 'tiktok' ] );

// Transition from publish -> publish should be ignored
$published_post = new WP_Post( [ 'ID' => 201, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Article One' ] );
$GLOBALS['test_posts'][201] = $published_post;
PressHub_Social_Dispatcher::handle_post_status_transition( 'publish', 'publish', $published_post );
assert_equals( 0, count( PressHub_Social_Queue::get_jobs_for_post( 201 ) ), 'Transition publish -> publish does not re-enqueue' );

// Transition draft -> publish should trigger enqueue
$draft_post = new WP_Post( [ 'ID' => 202, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'New News Story' ] );
$GLOBALS['test_posts'][202] = $draft_post;
PressHub_Social_Dispatcher::handle_post_status_transition( 'publish', 'draft', $draft_post );
$enqueued_jobs = PressHub_Social_Queue::get_jobs_for_post( 202 );
assert_true( count( $enqueued_jobs ) > 0, 'Transition draft -> publish successfully enqueues social jobs' );

// ----------------------------------------------------------------------
// Test Suite 10: Metabox Save Verification
// ----------------------------------------------------------------------
echo "\n--- Suite 10: Metabox Security & Save Verification ---\n";

$test_post_id = 301;
$_POST = [];
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options', 'edit_post' ];

// 1. Missing nonce -> should abort save
$_POST['presshub_social_platforms'] = [ 'twitter', 'facebook' ];
PressHub_Social_Metabox::save_metabox( $test_post_id, new WP_Post( [ 'ID' => $test_post_id ] ) );
$res_meta = get_post_meta( $test_post_id, '_presshub_social_platforms', true );
assert_true( empty( $res_meta ), 'Metabox save rejected when nonce is missing' );

// 2. Valid nonce & capability -> should save
$_POST['presshub_social_metabox_nonce'] = 'nonce_presshub_social_save_metabox';
$_POST['presshub_social_platforms']     = [ 'twitter', 'instagram' ];
$_POST['presshub_social_copy_twitter']  = 'Custom tweet copy for launch';

PressHub_Social_Metabox::save_metabox( $test_post_id, new WP_Post( [ 'ID' => $test_post_id ] ) );
$saved_plat = get_post_meta( $test_post_id, '_presshub_social_platforms', true );
$saved_copy = get_post_meta( $test_post_id, '_presshub_social_copy_twitter', true );

assert_equals( [ 'twitter', 'instagram' ], $saved_plat, 'Metabox successfully persists selected platforms' );
assert_equals( 'Custom tweet copy for launch', $saved_copy, 'Metabox successfully persists custom platform copy' );

// 3. Saving an existing published post with 0 queue jobs auto-enqueues
$test_pub_post_id = 302;
$GLOBALS['wp_test_posts'][ $test_pub_post_id ] = new WP_Post( [
    'ID'          => $test_pub_post_id,
    'post_status' => 'publish',
    'post_type'   => 'post',
    'post_title'  => 'Published Article Title',
] );
$_POST['presshub_social_metabox_nonce'] = 'nonce_presshub_social_save_metabox';
$_POST['presshub_social_platforms']     = [ 'twitter' ];
$_POST['presshub_social_copy_twitter']  = 'Published tweet copy';
unset( $_POST['presshub_social_requeue_on_save'] );

PressHub_Social_Metabox::save_metabox( $test_pub_post_id, $GLOBALS['wp_test_posts'][ $test_pub_post_id ] );
$jobs_after_save = PressHub_Social_Queue::get_jobs_for_post( $test_pub_post_id );
assert_true( count( $jobs_after_save ) === 1, 'Saving published post with zero jobs auto-enqueues into queue' );

// 4. Updating an existing published post that already has jobs does NOT re-enqueue duplicates by default
PressHub_Social_Metabox::save_metabox( $test_pub_post_id, $GLOBALS['wp_test_posts'][ $test_pub_post_id ] );
$jobs_after_second_save = PressHub_Social_Queue::get_jobs_for_post( $test_pub_post_id );
assert_true( count( $jobs_after_second_save ) === 1, 'Routine edit of published post with existing jobs does not duplicate queue' );

// 5. Updating an existing published post with presshub_social_requeue_on_save=1 explicitly re-enqueues
$_POST['presshub_social_requeue_on_save'] = '1';
PressHub_Social_Metabox::save_metabox( $test_pub_post_id, $GLOBALS['wp_test_posts'][ $test_pub_post_id ] );
$jobs_after_requeue = PressHub_Social_Queue::get_jobs_for_post( $test_pub_post_id );
assert_true( count( $jobs_after_requeue ) === 2, 'Explicit presshub_social_requeue_on_save re-enqueues new job' );
unset( $_POST['presshub_social_requeue_on_save'] );

// ----------------------------------------------------------------------
// Test Suite 11: Logger & Secret Redaction
// ----------------------------------------------------------------------
echo "\n--- Suite 11: Logger & Secret Redaction ---\n";
assert_equals( '', PressHub_Social_Logger::mask_secret( '' ), 'Empty secret masks to empty string' );
assert_equals( '••••••••', PressHub_Social_Logger::mask_secret( 'short' ), 'Short secret <= 8 chars masks to bullets' );
assert_equals( 'sk-p••••••••7890', PressHub_Social_Logger::mask_secret( 'sk-proj-1234567890' ), 'Long secret preserves only 4-char prefix and suffix' );

PressHub_Social_Logger::clear_recent_logs();
PressHub_Social_Logger::info( 'Test social log entry', [ 'platform' => 'twitter' ] );
$recent_logs = PressHub_Social_Logger::get_recent_logs();
assert_equals( 1, count( $recent_logs ), 'Logger records entry in ring buffer' );
assert_equals( 'INFO', $recent_logs[0]['level'] ?? '', 'Logger records correct severity level' );

// ----------------------------------------------------------------------
// Test Suite 12: Prompt Asset Loader & Zero-Silent-Fallback
// ----------------------------------------------------------------------
echo "\n--- Suite 12: Prompt Asset Loader & Zero-Silent-Fallback ---\n";
PressHub_Social_Prompt_Loader::clear_cache();
$sys_prompt_asset = PressHub_Social_Prompt_Loader::load( 'social_system.txt' );
assert_true( strlen( $sys_prompt_asset ) > 20, 'Prompt loader loads social_system.txt from assets/prompts/' );

$traversal_caught = false;
try {
    PressHub_Social_Prompt_Loader::load( '../uninstall.php' );
} catch ( InvalidArgumentException $e ) {
    $traversal_caught = true;
}
assert_true( $traversal_caught, 'Prompt loader rejects directory traversal with InvalidArgumentException' );

$missing_caught = false;
try {
    PressHub_Social_Prompt_Loader::load( 'non_existent_prompt_file.txt' );
} catch ( RuntimeException $e ) {
    $missing_caught = true;
}
assert_true( $missing_caught, 'Prompt loader throws RuntimeException on missing file (Zero-Silent-Fallback)' );

// ----------------------------------------------------------------------
// Test Suite 13: Settings-First Storage & Clamping Sanitizers
// ----------------------------------------------------------------------
echo "\n--- Suite 13: Settings-First Storage & Clamping Sanitizers ---\n";
assert_equals( 10, PressHub_Social_Settings_Storage::sanitize_max_attempts( 999 ), 'max_attempts clamps upper bound to 10' );
assert_equals( 1, PressHub_Social_Settings_Storage::sanitize_max_attempts( -5 ), 'max_attempts clamps lower bound to 1' );
assert_equals( 10, PressHub_Social_Settings_Storage::sanitize_dispatch_interval( 2 ), 'dispatch_interval clamps lower bound to 10s' );
assert_equals( 3600, PressHub_Social_Settings_Storage::sanitize_dispatch_interval( 99999 ), 'dispatch_interval clamps upper bound to 3600s' );
assert_equals( 0, PressHub_Social_Settings_Storage::sanitize_ai_max_tokens( 10 ), 'Sub-threshold ai_max_tokens (<64) sanitizes to 0 (provider default)' );
assert_equals( 2048, PressHub_Social_Settings_Storage::sanitize_ai_max_tokens( 2048 ), 'Valid ai_max_tokens preserved' );
assert_equals( 'automation', PressHub_Social_Settings_Storage::sanitize_engine( 'invalid_engine', 'automation' ), 'Invalid engine falls back to specified default' );

// ----------------------------------------------------------------------
// Test Suite 14: AI Copywriter (AI Mode, Template Mode & Zero-Silent-Fallback)
// ----------------------------------------------------------------------
echo "\n--- Suite 14: AI Copywriter & Zero-Silent-Fallback ---\n";
PressHub_Social_Settings_Storage::seed_defaults();

// 1. Template Mode
update_option( 'presshub_social_copy_mode', 'template' );
$tpl_copy = PressHub_Social_AI_Copywriter::generate_from_data( [
    'title'     => 'PressHub Launches Social Auto-Post',
    'excerpt'   => 'Automated publishing across X, Facebook, Instagram, and TikTok.',
    'url'       => 'https://presshub.ai/social-launch',
    'hashtags'  => '#PressHub #WordPress',
    'site_name' => 'PressHub News',
], 'twitter' );
assert_true( is_string( $tpl_copy ) && str_contains( $tpl_copy, 'PressHub Launches Social Auto-Post' ), 'Template mode renders post title and URL' );

// 2. Zero-Silent-Fallback when template is blank
update_option( 'presshub_social_template_twitter', '   ' );
$empty_tpl_res = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Test' ], 'twitter' );
assert_true( is_wp_error( $empty_tpl_res ) && 'missing_social_template' === $empty_tpl_res->get_error_code(), 'Template mode fails loudly when template option is empty' );

// Restore defaults
PressHub_Social_Settings_Storage::reset_prompts_to_defaults();

// 3. AI Mode with Zero-Silent-Fallback when prompt is blank
update_option( 'presshub_social_copy_mode', 'ai' );
update_option( 'presshub_social_prompt_twitter', '' );
$empty_prompt_res = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Test' ], 'twitter' );
assert_true( is_wp_error( $empty_prompt_res ) && 'missing_social_prompt' === $empty_prompt_res->get_error_code(), 'AI mode fails loudly when prompt option is empty (never falls back to template)' );

// Restore defaults and verify Zero-Silent-Fallback when no AI provider is configured
PressHub_Social_Settings_Storage::reset_prompts_to_defaults();
if ( ! class_exists( 'PressHub_AI_Provider_Store' ) ) {
    $provider_store_file = dirname( __DIR__, 2 ) . '/presshub-ai-editor/includes/class-provider-store.php';
    if ( file_exists( $provider_store_file ) ) {
        require_once $provider_store_file;
    }
}
update_option( 'presshub_ai_configured_providers', [] );
$no_prov_res = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Test' ], 'twitter' );
assert_true( is_wp_error( $no_prov_res ) && 'missing_ai_provider' === $no_prov_res->get_error_code(), 'AI mode fails loudly when no provider is configured in Provider Store' );

// Seed an enabled provider in PressHub_AI_Provider_Store and test custom AI caller + URL-preserving truncation
update_option( 'presshub_ai_configured_providers', [
    [
        'id'            => 'openai_main',
        'type'          => 'openai',
        'name'          => 'OpenAI Main',
        'api_key'       => 'sk-test-1234567890abcdef',
        'default_model' => 'gpt-4o',
        'enabled'       => true,
    ],
] );
update_option( 'presshub_social_twitter_max_chars', 280 );
PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $config ) {
    return str_repeat( 'Breaking news update ', 25 ) . 'https://presshub.ai/story #News';
} );
$ai_tweet = PressHub_Social_AI_Copywriter::generate_from_data( [
    'title' => 'Big Story',
    'url'   => 'https://presshub.ai/story',
], 'twitter' );
assert_true( is_string( $ai_tweet ) && PressHub_Social_AI_Copywriter::get_twitter_effective_length( $ai_tweet ) <= 280, 'AI copywriter truncates tweet to configured max_chars' );
assert_true( is_string( $ai_tweet ) && str_contains( $ai_tweet, 'https://presshub.ai/story' ), 'AI copywriter preserves article URL during truncation' );

// Twitter t.co character counting & Greek URL truncation test
$greek_url = 'https://presshub.cy/%ce%b1%cf%80%ce%ac%ce%bd%cf%84%ce%b7%cf%83%ce%b7-%cf%83%cf%84%ce%b7-%cf%81%ce%b7%cf%84%ce%bf%cf%81%ce%b9%ce%ba%ce%ae-%cf%84%ce%bf%cf%85-%cf%86%cf%8c%ce%b2%ce%bf%cf%85-%ce%b7-%ce%ba%cf%8d%cf%80%cf%81/';
$tweet_with_greek_url = 'Πώς μπορεί η Κύπρος να μετατραπεί σε «Ελβετία της Αν. Μεσογείου»; Αντί για ρητορική φόβου, η περιφερειακή συνεργασία σε ενέργεια και υποδομές ανοίγει νέες προοπτικές. Διαβάστε την ανάλυση: ' . $greek_url . ' #Cyprus #Analysis';
assert_true( mb_strlen( $greek_url, 'UTF-8' ) > 200, 'Greek URL is over 200 characters long' );
$eff_len = PressHub_Social_AI_Copywriter::get_twitter_effective_length( $tweet_with_greek_url );
assert_true( $eff_len < 280, 'Twitter effective length counts URL as 23 chars (under 280)' );

PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $cfg ) use ( $tweet_with_greek_url ) {
    return $tweet_with_greek_url;
} );
update_option( 'presshub_social_twitter_max_chars', 280 );
$preserved_tweet = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Cyprus Story', 'url' => $greek_url ], 'twitter' );
assert_true( str_contains( $preserved_tweet, 'περιφερειακή συνεργασία' ), 'Greek tweet text is preserved without premature URL length truncation' );

// force_ai parameter test
update_option( 'presshub_social_copy_mode', 'template' );
PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $cfg ) {
    return 'Forced AI Generated Tweet Content';
} );
$tpl_res = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Cyprus Story', 'url' => 'https://example.com' ], 'twitter', false );
assert_true( ! str_contains( $tpl_res, 'Forced AI' ), 'Default template mode returns static template when force_ai is false' );
$forced_res = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Cyprus Story', 'url' => 'https://example.com' ], 'twitter', true );
assert_true( str_contains( $forced_res, 'Forced AI' ), 'force_ai=true invokes AI copywriter even when copy_mode is template' );

// Twitter Single Link Rule and Brand Sanitization tests
$two_url_tweet = "Όλες οι εξελίξεις στο podcast του PressHub.Cy: https://presshub.cy/ και https://presshub.cy/mesimvrino-podcast-enimerosis-05-11-2026/ #Cyprus";
$sanitized_tweet = PressHub_Social_AI_Copywriter::sanitize_twitter_copy( $two_url_tweet, 'https://presshub.cy/mesimvrino-podcast-enimerosis-05-11-2026/' );
assert_true( ! str_contains( $sanitized_tweet, 'PressHub.Cy' ), 'sanitize_twitter_copy strips PressHub.Cy domain to plain PressHub' );
assert_true( str_contains( $sanitized_tweet, 'podcast του PressHub' ), 'Brand name rendered as plain PressHub' );
assert_true( str_contains( $sanitized_tweet, 'https://presshub.cy/mesimvrino-podcast-enimerosis-05-11-2026/' ), 'Article URL is preserved' );
assert_true( ! str_contains( $sanitized_tweet, 'https://presshub.cy/ ' ) && ! str_contains( $sanitized_tweet, 'https://presshub.cy/ και' ), 'Secondary homepage URL is removed' );
preg_match_all( '#https?://\S+#i', $sanitized_tweet, $final_urls );
assert_equals( 1, count( $final_urls[0] ), 'Strictly exactly one URL remains in sanitized tweet' );

// Reset copy mode back to ai
update_option( 'presshub_social_copy_mode', 'ai' );
PressHub_Social_AI_Copywriter::set_ai_caller( null );

// 4. Podcast Script Ingestion & Cleanup Tests
$raw_podcast_script = "[TOPIC_START: Εισαγωγή]\n[SPEAKER_1]: Καλημέρα σας από το PressHub.\n[Presenter 2]: Σήμερα αναλύουμε τις εξελίξεις.\n**[SPEAKER_1]:** Ακριβώς.\n[TOPIC_END]\n[TOPIC_START: Οικονομία]\nSPEAKER_0: Τα επιτόκια παραμένουν σταθερά.\n[TOPIC_END]";
$cleaned_script = PressHub_Social_AI_Copywriter::clean_podcast_script( $raw_podcast_script );
assert_true(
    ! str_contains( $cleaned_script, 'TOPIC' ) &&
    ! str_contains( $cleaned_script, 'SPEAKER' ) &&
    ! str_contains( $cleaned_script, 'Presenter' ) &&
    str_contains( $cleaned_script, 'Καλημέρα σας από το PressHub.' ) &&
    str_contains( $cleaned_script, 'Σήμερα αναλύουμε τις εξελίξεις.' ) &&
    str_contains( $cleaned_script, 'Ακριβώς.' ) &&
    str_contains( $cleaned_script, 'Τα επιτόκια παραμένουν σταθερά.' ),
    'clean_podcast_script strips topic markers and speaker tags'
);

// 5. Podcast post with empty content and _presshub_podcast_script meta populates content & excerpt
$podcast_post_id = 901;
$podcast_post_obj = new WP_Post( [
    'ID'           => $podcast_post_id,
    'post_title'   => 'PressHub Podcast: Daily Briefing',
    'post_content' => '[audio src="https://presshub.ai/audio/briefing.mp3"]',
    'post_excerpt' => '',
] );
$GLOBALS['test_posts'][ $podcast_post_id ]  = $podcast_post_obj;
$GLOBALS['POSTS_STORE'][ $podcast_post_id ] = [
    'ID'           => $podcast_post_id,
    'post_title'   => 'PressHub Podcast: Daily Briefing',
    'post_content' => '[audio src="https://presshub.ai/audio/briefing.mp3"]',
    'post_excerpt' => '',
    'post_status'  => 'publish',
];
update_post_meta( $podcast_post_id, '_presshub_briefing_type', 'podcast' );
update_post_meta( $podcast_post_id, '_presshub_podcast_script', "[TOPIC_START: Εισαγωγή]\n[SPEAKER_1]: Καλωσήρθατε στο ημερήσιο podcast του PressHub.\n[SPEAKER_2]: Έχουμε πολλές ενδιαφέρουσες ειδήσεις για σήμερα.\n[TOPIC_END]" );

$podcast_article_data = PressHub_Social_AI_Copywriter::build_article_data( $podcast_post_id );
assert_true(
    is_array( $podcast_article_data ) &&
    str_contains( $podcast_article_data['content'], 'Καλωσήρθατε στο ημερήσιο podcast του PressHub. Έχουμε πολλές ενδιαφέρουσες ειδήσεις για σήμερα.' ) &&
    ! str_contains( $podcast_article_data['content'], 'TOPIC' ) &&
    ! str_contains( $podcast_article_data['content'], 'SPEAKER' ),
    'build_article_data populates content with cleaned podcast script when post_content is audio shortcode'
);
assert_true(
    ! empty( $podcast_article_data['excerpt'] ) &&
    str_contains( $podcast_article_data['excerpt'], 'Καλωσήρθατε στο ημερήσιο podcast' ) &&
    ! str_contains( $podcast_article_data['excerpt'], 'TOPIC' ) &&
    ! str_contains( $podcast_article_data['excerpt'], 'SPEAKER' ),
    'build_article_data populates excerpt with first 50 words of cleaned podcast script'
);

// 6. Passing cleaned podcast script to AI copywriter user prompt
$captured_user_prompt = '';
PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $config ) use ( &$captured_user_prompt ) {
    $captured_user_prompt = $user;
    return 'Podcast Tweet: ' . mb_substr( $user, 0, 80 );
} );
$podcast_generated_copy = PressHub_Social_AI_Copywriter::generate_for_post( $podcast_post_id, 'twitter' );
assert_true(
    str_contains( $captured_user_prompt, 'Καλωσήρθατε στο ημερήσιο podcast του PressHub.' ),
    'generate_for_post passes cleaned podcast script into AI copywriter user prompt'
);
assert_true(
    is_string( $podcast_generated_copy ) && str_starts_with( $podcast_generated_copy, 'Podcast Tweet:' ),
    'generate_for_post returns generated copy for podcast post'
);
PressHub_Social_AI_Copywriter::set_ai_caller( null );

// 7. Fallback to snapshot file on disk when postmeta is empty
$snapshot_post_id = 902;
$upload_dir = wp_upload_dir();
$briefing_date = '2026-11-04';
$briefing_dir = $upload_dir['basedir'] . '/presshub-briefings/' . $briefing_date;
if ( ! is_dir( $briefing_dir ) ) {
    @mkdir( $briefing_dir, 0777, true );
}
file_put_contents( $briefing_dir . '/podcast-script.txt', "[TOPIC_START: Snapshot]\n[SPEAKER_1]: Ηχογράφηση από αρχείο snapshot δίσκου.\n[TOPIC_END]" );

$snapshot_post_obj = new WP_Post( [
    'ID'           => $snapshot_post_id,
    'post_title'   => 'Snapshot Podcast',
    'post_content' => '',
    'post_excerpt' => '',
    'post_date'    => '2026-11-04 10:00:00',
] );
$GLOBALS['test_posts'][ $snapshot_post_id ]  = $snapshot_post_obj;
$GLOBALS['POSTS_STORE'][ $snapshot_post_id ] = [
    'ID'           => $snapshot_post_id,
    'post_title'   => 'Snapshot Podcast',
    'post_content' => '',
    'post_excerpt' => '',
    'post_date'    => '2026-11-04 10:00:00',
    'post_status'  => 'publish',
];
update_post_meta( $snapshot_post_id, '_presshub_briefing_type', 'podcast' );
update_post_meta( $snapshot_post_id, '_presshub_briefing_date', $briefing_date );
update_post_meta( $snapshot_post_id, '_presshub_briefing_edition', 'morning' );

$snapshot_article_data = PressHub_Social_AI_Copywriter::build_article_data( $snapshot_post_id );
assert_true(
    str_contains( $snapshot_article_data['content'], 'Ηχογράφηση από αρχείο snapshot δίσκου.' ) &&
    ! str_contains( $snapshot_article_data['content'], 'TOPIC' ),
    'build_article_data falls back to Harvester/Producer snapshot file when postmeta is empty'
);

// 8. Site Name Brand Sanitization (.cy domain stripped)
$GLOBALS['test_bloginfo_name'] = 'PressHub.Cy';
$brand_article_data = PressHub_Social_AI_Copywriter::build_article_data( $snapshot_post_id );
assert_equals( 'PressHub', $brand_article_data['site_name'], 'build_article_data strips .Cy domain extension from site_name' );

// 9. Twitter Single Link & Domain Auto-link Neutralization
PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $config ) {
    return "Έρχεται καμπή στο Κυπριακό; Ο Πρόεδρος Χριστοδουλίδης... Διαβάστε στο PressHub.Cy:\n\nhttps://presshub.cy/article-slug/ https://presshub.cy/extra #Cyprus";
} );
$clean_tweet = PressHub_Social_AI_Copywriter::generate_from_data( [
    'title'     => 'Article Title',
    'url'       => 'https://presshub.cy/article-slug/',
    'site_name' => 'PressHub',
], 'twitter', true );

assert_true( ! str_contains( $clean_tweet, 'PressHub.Cy' ), 'Twitter copy replaces standalone PressHub.Cy with plain PressHub' );
assert_true( str_contains( $clean_tweet, 'στο PressHub:' ), 'Twitter copy contains plain brand "στο PressHub:"' );
assert_true( str_contains( $clean_tweet, 'https://presshub.cy/article-slug/' ), 'Twitter copy preserves the single article URL' );
assert_true( ! str_contains( $clean_tweet, 'https://presshub.cy/extra' ), 'Twitter copy strips conflicting secondary URLs' );
PressHub_Social_AI_Copywriter::set_ai_caller( null );

// 10. maybe_upgrade_prompts_v284 upgrades prompt
delete_option( 'presshub_social_upgraded_prompts_v284' );
update_option( 'presshub_social_prompt_twitter', 'Write a post: explore the full story on {site_name}.' );
PressHub_Social_Settings_Storage::maybe_upgrade_prompts_v284();
$upgraded_prompt = PressHub_Social_Settings_Storage::get_platform_prompt( 'twitter' );
assert_true( str_contains( $upgraded_prompt, 'Single Link Rule' ), 'maybe_upgrade_prompts_v284 installs Single Link Rule' );
assert_true( str_contains( $upgraded_prompt, 'Refer to the publisher strictly as "PressHub"' ), 'maybe_upgrade_prompts_v284 installs plain PressHub brand instruction' );

// ----------------------------------------------------------------------
// Test Suite 15: Embedded GitHub Auto-Update Checker & Token Fallback
// ----------------------------------------------------------------------
echo "\n--- Suite 15: GitHub Auto-Update Checker & Token Resolution ---\n";
$puc_entry = $base_dir . '/includes/plugin-update-checker/plugin-update-checker.php';
assert_true( file_exists( $puc_entry ), 'Embedded plugin-update-checker library exists in presshub-social-autopost/includes/plugin-update-checker/' );

delete_option( 'presshub_social_github_token' );
update_option( 'presshub_ai_github_token', 'ghp_shared_core_token_12345' );
assert_equals( 'ghp_shared_core_token_12345', PressHub_Social_Settings_Storage::get_github_token(), 'get_github_token inherits presshub_ai_github_token when social token is unset' );

update_option( 'presshub_social_github_token', 'ghp_dedicated_social_token_999' );
assert_equals( 'ghp_dedicated_social_token_999', PressHub_Social_Settings_Storage::get_github_token(), 'get_github_token prioritizes dedicated presshub_social_github_token when set' );
assert_equals( 'ghp_dedicated_social_token_999', PressHub_Social_Settings_Storage::sanitize_github_token( 'ghp_••••••••_999' ), 'sanitize_github_token preserves existing secret when masked' );

// ----------------------------------------------------------------------
// Test Suite 16: Settings Render (GitHub Token Field & AI Provider Dropdown Filtering)
// ----------------------------------------------------------------------
echo "\n--- Suite 16: Settings Render & Configured AI Provider Filtering ---\n";
$renderer = new PressHub_Social_Settings_Render();

ob_start();
$renderer->render_github_token_field();
$gh_field_html = ob_get_clean();
assert_true( str_contains( $gh_field_html, 'name="presshub_social_github_token"' ), 'render_github_token_field renders password input without TypeError' );

update_option( 'presshub_ai_configured_providers', [
    [
        'id'            => 'openai_active',
        'type'          => 'openai',
        'name'          => 'OpenAI Configured',
        'api_key'       => 'sk-valid-key-1234567890',
        'default_model' => 'gpt-4o',
        'enabled'       => true,
    ],
    [
        'id'            => 'anthropic_disabled',
        'type'          => 'anthropic',
        'name'          => 'Anthropic Disabled',
        'api_key'       => 'sk-ant-valid-1234567890',
        'default_model' => 'claude-3-5-sonnet-20240620',
        'enabled'       => false,
    ],
    [
        'id'            => 'gemini_unconfigured',
        'type'          => 'gemini',
        'name'          => 'Gemini Missing Key',
        'api_key'       => '',
        'default_model' => 'gemini-2.5-flash',
        'enabled'       => true,
    ],
] );

ob_start();
$renderer->render_ai_provider_field();
$prov_html = ob_get_clean();
assert_true( str_contains( $prov_html, 'OpenAI Configured (gpt-4o)' ), 'AI provider dropdown lists enabled and configured provider' );
assert_true( ! str_contains( $prov_html, 'Anthropic Disabled' ), 'AI provider dropdown excludes disabled providers' );
assert_true( ! str_contains( $prov_html, 'Gemini Missing Key' ), 'AI provider dropdown excludes unconfigured providers without API keys' );

// ----------------------------------------------------------------------
// Test Suite 16b: Number fields never render a value the browser rejects
// ----------------------------------------------------------------------
// All settings tabs share one form and inactive tabs are hidden. A number input
// in a hidden tab whose value is outside its min/max makes the browser cancel
// the whole submit without a visible message, so nothing on the page saves.
echo "\n--- Suite 16b: Number field values stay inside min/max ---\n";

$settings_src = (string) file_get_contents( $base_dir . '/includes/class-social-settings.php' );
preg_match_all(
    "/'render_number_field'\s*\],.*?\[\s*(.*?)\]\s*\);/s",
    $settings_src,
    $number_field_blocks
);
$number_fields = [];
foreach ( $number_field_blocks[1] as $block ) {
    if ( preg_match( "/'option'\s*=>\s*'([a-z0-9_]+)'/", $block, $m_opt )
        && preg_match( "/'min'\s*=>\s*(-?\d+)/", $block, $m_min )
        && preg_match( "/'max'\s*=>\s*(-?\d+)/", $block, $m_max ) ) {
        $number_fields[ $m_opt[1] ] = [ 'option' => $m_opt[1], 'min' => (int) $m_min[1], 'max' => (int) $m_max[1] ];
        if ( preg_match( "/'default'\s*=>\s*(-?\d+)/", $block, $m_def ) ) {
            $number_fields[ $m_opt[1] ]['default'] = (int) $m_def[1];
        }
    }
}
assert_true( count( $number_fields ) >= 10, 'found the number fields registered on the settings page' );

$render_number_value = function ( array $args ) use ( $renderer ): ?int {
    ob_start();
    $renderer->render_number_field( $args );
    $html = ob_get_clean();
    return preg_match( '/value="(-?\d+)"/', $html, $m ) ? (int) $m[1] : null;
};

foreach ( $number_fields as $opt => $field ) {
    $saved = get_option( $opt, null );

    delete_option( $opt );
    $v = $render_number_value( $field );
    assert_true( null !== $v && $v >= $field['min'] && $v <= $field['max'], "{$opt}: never-saved option renders inside {$field['min']}-{$field['max']} (got " . var_export( $v, true ) . ')' );

    update_option( $opt, $field['max'] + 1000 );
    $v = $render_number_value( $field );
    assert_equals( $field['max'], $v, "{$opt}: stored value above max renders as max" );

    update_option( $opt, $field['min'] - 1000 );
    $v = $render_number_value( $field );
    assert_equals( $field['min'], $v, "{$opt}: stored value below min renders as min" );

    // The field must accept every value the sanitizer can store, and the sanitizer
    // must not change a value the field accepts.
    $sanitizer = 'sanitize_' . substr( $opt, strlen( 'presshub_social_' ) );
    if ( method_exists( 'PressHub_Social_Settings_Storage', $sanitizer ) ) {
        assert_equals( $field['min'], PressHub_Social_Settings_Storage::$sanitizer( $field['min'] - 1000 ), "{$opt}: sanitizer lower bound matches field min" );
        assert_equals( $field['max'], PressHub_Social_Settings_Storage::$sanitizer( $field['max'] + 1000 ), "{$opt}: sanitizer upper bound matches field max" );
    }

    if ( null !== $saved ) {
        update_option( $opt, $saved );
    } else {
        delete_option( $opt );
    }
}

ob_start();
$renderer->render_ai_temperature_field();
$temp_html = ob_get_clean();
assert_true( str_contains( $temp_html, 'step="0.01"' ), 'temperature field step matches the 2-decimal sanitizer' );

// ----------------------------------------------------------------------
// Test Suite 17: Multi-Tab Settings Persistence, v2.6.3 Auto-Repair & Batch Copy Generation
// ----------------------------------------------------------------------
echo "\n--- Suite 17: Multi-Tab Settings Persistence, v2.6.3 Auto-Repair & Batch Copy Generation ---\n";

// 1. Verify v2.6.3 auto-repair heals options wiped by v2.6.2 single-tab form saves
delete_option( 'presshub_social_repaired_v263' );
update_option( 'presshub_social_auto_publish_enabled', false );
update_option( 'presshub_social_enabled_platforms', [] );
update_option( 'presshub_social_prompt_system', '' );
update_option( 'presshub_social_prompt_twitter', '' );

PressHub_Social_Settings_Storage::maybe_repair_v263_options();
assert_true( PressHub_Social_Settings_Storage::get_auto_publish_enabled(), 'v2.6.3 repair restores auto_publish_enabled to true' );
assert_equals( [ 'twitter', 'facebook', 'instagram', 'tiktok' ], PressHub_Social_Settings_Storage::get_enabled_platforms(), 'v2.6.3 repair restores enabled_platforms' );
assert_true( strlen( PressHub_Social_Settings_Storage::get_system_prompt() ) > 20, 'v2.6.3 repair restores wiped system prompt from external asset' );
assert_true( strlen( PressHub_Social_Settings_Storage::get_platform_prompt( 'twitter' ) ) > 20, 'v2.6.3 repair restores wiped twitter prompt from external asset' );

// 2. Verify partial $_POST submission does not wipe auto_publish_enabled, enabled_platforms, or prompts
$_POST = [ 'presshub_social_ai_provider' => 'openai_active' ];
assert_true( PressHub_Social_Settings_Storage::sanitize_auto_publish_enabled( null ), 'sanitize_auto_publish_enabled preserves true when field omitted from partial POST' );
assert_equals( [ 'twitter', 'facebook', 'instagram', 'tiktok' ], PressHub_Social_Settings_Storage::sanitize_enabled_platforms( null ), 'sanitize_enabled_platforms preserves platforms when field omitted from partial POST' );
assert_true( strlen( PressHub_Social_Settings_Storage::sanitize_prompt( null, 'presshub_social_prompt_system' ) ) > 20, 'sanitize_prompt preserves existing prompt when field omitted from partial POST' );

// Verify explicit uncheck when _present marker IS in $_POST works as expected
$_POST = [
    'presshub_social_auto_publish_enabled_present' => '1',
    'presshub_social_enabled_platforms_present'    => '1',
];
assert_true( ! PressHub_Social_Settings_Storage::sanitize_auto_publish_enabled( null ), 'sanitize_auto_publish_enabled allows explicit uncheck when _present marker is submitted' );
assert_equals( [], PressHub_Social_Settings_Storage::sanitize_enabled_platforms( null ), 'sanitize_enabled_platforms allows explicit empty selection when _present marker is submitted' );
$_POST = [];

// 3. Verify generate_all_for_post generates copy across all enabled platforms
update_option( 'presshub_social_enabled_platforms', [ 'twitter', 'facebook' ] );
update_option( 'presshub_social_copy_mode', 'template' );
PressHub_Social_Settings_Storage::reset_prompts_to_defaults();
$post_401 = new WP_Post( [
    'ID'          => 401,
    'post_type'   => 'post',
    'post_status' => 'publish',
    'post_title'  => 'Multi-Platform Story',
] );
$GLOBALS['test_posts'][401]  = $post_401;
$GLOBALS['POSTS_STORE'][401] = [
    'ID'           => 401,
    'post_type'    => 'post',
    'post_status'  => 'publish',
    'post_title'   => 'Multi-Platform Story',
    'post_content' => 'Full story body content.',
    'post_excerpt' => 'Story excerpt.',
];
$all_copies = PressHub_Social_AI_Copywriter::generate_all_for_post( 401 );
assert_true( is_array( $all_copies ) && isset( $all_copies['twitter'], $all_copies['facebook'] ), 'generate_all_for_post generates copy for all enabled platforms' );

// 4. Verify handle_post_status_transition persists metabox $_POST before enqueuing (Classic Editor hook order)
PressHub_Social_Queue::reset_memory_store();
update_option( 'presshub_social_auto_publish_enabled', true );
$post_402 = new WP_Post( [
    'ID'          => 402,
    'post_type'   => 'post',
    'post_status' => 'publish',
    'post_title'  => 'Classic Editor Publish',
] );
$GLOBALS['test_posts'][402]  = $post_402;
$GLOBALS['POSTS_STORE'][402] = [
    'ID'           => 402,
    'post_type'    => 'post',
    'post_status'  => 'publish',
    'post_title'   => 'Classic Editor Publish',
    'post_content' => 'Body content.',
];
$_POST = [
    'presshub_social_metabox_nonce' => 'nonce_presshub_social_save_metabox',
    'presshub_social_platforms'     => [ 'twitter' ],
    'presshub_social_copy_twitter'  => 'Custom metabox copy saved during publish transition',
];
PressHub_Social_Dispatcher::handle_post_status_transition( 'publish', 'draft', $post_402 );
$_POST = [];
$jobs_402 = PressHub_Social_Queue::get_jobs_for_post( 402 );
assert_equals( 1, count( $jobs_402 ), 'Transition draft -> publish respects metabox platform selection submitted in $_POST' );
assert_equals( 'Custom metabox copy saved during publish transition', $jobs_402[0]['post_copy'] ?? '', 'Transition draft -> publish uses custom metabox copy submitted in $_POST' );

// ----------------------------------------------------------------------
// Test Suite 18: REST API Controller & Pull Mode Worker Integration
// ----------------------------------------------------------------------
echo "\n--- Suite 18: REST API Controller & Pull Mode Worker Integration ---\n";

// 1. Verify route registration
PressHub_Social_REST_Controller::register_routes();
assert_true( isset( $GLOBALS['WP_REST_ROUTES']['presshub-social/v1']['/worker/heartbeat'] ), 'REST route /worker/heartbeat registered' );
assert_true( isset( $GLOBALS['WP_REST_ROUTES']['presshub-social/v1']['/worker/claim'] ), 'REST route /worker/claim registered' );
assert_true( isset( $GLOBALS['WP_REST_ROUTES']['presshub-social/v1']['/worker/complete'] ), 'REST route /worker/complete registered' );

// 2. Secret verification
delete_option( 'presshub_social_worker_secret' );
$req_test_sec = new WP_REST_Request( 'POST', '/worker/heartbeat' );
$req_test_sec->set_header( 'x-presshub-secret', 'any_secret' );
$sec_err = PressHub_Social_REST_Controller::verify_worker_secret( $req_test_sec );
assert_true( is_wp_error( $sec_err ) && 'missing_worker_secret' === $sec_err->get_error_code(), 'verify_worker_secret fails 401 when worker secret unset' );

update_option( 'presshub_social_worker_secret', 'correct_worker_secret_9988' );
$req_test_sec->set_header( 'x-presshub-secret', 'wrong_secret' );
$sec_err2 = PressHub_Social_REST_Controller::verify_worker_secret( $req_test_sec );
assert_true( is_wp_error( $sec_err2 ) && 'invalid_worker_secret' === $sec_err2->get_error_code(), 'verify_worker_secret fails 403 on secret mismatch' );

$req_test_sec->set_header( 'x-presshub-secret', 'correct_worker_secret_9988' );
assert_true( true === PressHub_Social_REST_Controller::verify_worker_secret( $req_test_sec ), 'verify_worker_secret succeeds with X-PressHub-Secret' );

$req_worker_hdr = new WP_REST_Request( 'POST', '/worker/heartbeat' );
$req_worker_hdr->set_header( 'x-worker-secret', 'correct_worker_secret_9988' );
assert_true( true === PressHub_Social_REST_Controller::verify_worker_secret( $req_worker_hdr ), 'verify_worker_secret succeeds with X-Worker-Secret' );

$req_bearer_hdr = new WP_REST_Request( 'POST', '/worker/heartbeat' );
$req_bearer_hdr->set_header( 'authorization', 'Bearer correct_worker_secret_9988' );
assert_true( true === PressHub_Social_REST_Controller::verify_worker_secret( $req_bearer_hdr ), 'verify_worker_secret succeeds with Authorization: Bearer' );

// 3. Heartbeat handler
update_option( 'presshub_social_worker_mode', 'pull' );
$req_hb = new WP_REST_Request( 'POST', '/worker/heartbeat' );
$req_hb->set_param( 'sessions', [ 'twitter' => true, 'tiktok' => false ] );
$res_hb = PressHub_Social_REST_Controller::handle_heartbeat( $req_hb );
assert_true( ! empty( $res_hb['ok'] ) && 'pull' === $res_hb['mode'], 'Heartbeat returns ok and mode' );
assert_true( (int) get_option( 'presshub_social_worker_last_seen' ) > 0, 'Heartbeat updates presshub_social_worker_last_seen' );
$hb_sessions = get_option( 'presshub_social_worker_sessions' );
assert_true( is_array( $hb_sessions ) && true === $hb_sessions['twitter'] && false === $hb_sessions['tiktok'], 'Heartbeat persists platform sessions' );

// 4. Claim handler
PressHub_Social_Queue::reset_memory_store();
$post_501 = new WP_Post( [
    'ID'          => 501,
    'post_type'   => 'post',
    'post_status' => 'publish',
    'post_title'  => 'Article for Worker Pull',
] );
$GLOBALS['test_posts'][501]  = $post_501;
$GLOBALS['POSTS_STORE'][501] = [
    'ID'           => 501,
    'post_type'    => 'post',
    'post_status'  => 'publish',
    'post_title'   => 'Article for Worker Pull',
    'post_content' => 'Content...',
];

$past_time = gmdate( 'Y-m-d H:i:s', time() - 60 );
$job_auto = PressHub_Social_Queue::enqueue( [
    'post_id'      => 501,
    'platform'     => 'twitter',
    'engine'       => 'automation',
    'post_copy'    => 'Automated Tweet from Pull Worker',
    'scheduled_at' => $past_time,
] );
$job_api = PressHub_Social_Queue::enqueue( [
    'post_id'      => 501,
    'platform'     => 'facebook',
    'engine'       => 'api',
    'post_copy'    => 'Graph API Post',
    'scheduled_at' => $past_time,
] );

$req_claim = new WP_REST_Request( 'POST', '/worker/claim' );
$res_claim = PressHub_Social_REST_Controller::handle_claim( $req_claim );
assert_true( ! empty( $res_claim['ok'] ), 'Claim response returns ok' );
assert_equals( 1, count( $res_claim['jobs'] ), 'Claim only claims automation engine jobs' );
assert_equals( 'twitter', $res_claim['jobs'][0]['platform'], 'Claimed job platform is twitter' );
assert_equals( 'Article for Worker Pull', $res_claim['jobs'][0]['post_title'], 'Claimed job has post_title attached' );
assert_true( isset( $res_claim['jobs'][0]['specs']['instagram_card_width'], $res_claim['jobs'][0]['specs']['tiktok_video_duration'] ), 'Claimed job has media specs attached' );
assert_equals( 'processing', PressHub_Social_Queue::get_job( $job_auto )['status'], 'Claimed job transitioned to processing status' );
assert_equals( 'pending', PressHub_Social_Queue::get_job( $job_api )['status'], 'API engine job remains untouched in pending status' );

// 5. Complete handler
$req_comp = new WP_REST_Request( 'POST', '/worker/complete' );
$req_comp->set_param( 'job_id', $job_auto );
$req_comp->set_param( 'status', 'published' );
$req_comp->set_param( 'external_post_id', 'tweet_889977' );
$req_comp->set_param( 'external_post_url', 'https://x.com/presshub/status/889977' );
$res_comp = PressHub_Social_REST_Controller::handle_complete( $req_comp );
assert_true( ! empty( $res_comp['ok'] ) && 'published' === $res_comp['status'], 'Complete handler returns ok and published' );
$published_auto_job = PressHub_Social_Queue::get_job( $job_auto );
assert_equals( 'published', $published_auto_job['status'], 'Job transitioned to published status' );
assert_equals( 'tweet_889977', $published_auto_job['external_post_id'], 'External post ID recorded' );
assert_equals( 'https://x.com/presshub/status/889977', $published_auto_job['external_post_url'], 'External post URL recorded' );

$job_fail = PressHub_Social_Queue::enqueue( [
    'post_id'      => 501,
    'platform'     => 'tiktok',
    'engine'       => 'automation',
    'post_copy'    => 'TikTok Video to Fail',
    'scheduled_at' => $past_time,
] );
PressHub_Social_Queue::mark_processing( $job_fail );
$req_comp_fail = new WP_REST_Request( 'POST', '/worker/complete' );
$req_comp_fail->set_param( 'job_id', $job_fail );
$req_comp_fail->set_param( 'status', 'failed' );
$req_comp_fail->set_param( 'error', 'Playwright selector timeout' );
$res_comp_fail = PressHub_Social_REST_Controller::handle_complete( $req_comp_fail );
assert_true( ! empty( $res_comp_fail['ok'] ) && 'failed' === $res_comp_fail['status'], 'Complete handler records failed status' );
$failed_job = PressHub_Social_Queue::get_job( $job_fail );
assert_equals( 'pending', $failed_job['status'], 'Transient failure resets job to pending for backoff retry' );
assert_equals( 'Playwright selector timeout', $failed_job['last_error'], 'Job records reported failure message' );

// 6. Pull Mode health & session verification
update_option( 'presshub_social_worker_mode', 'pull' );
update_option( 'presshub_social_worker_secret', 'correct_worker_secret_9988' );

// Health check with stale heartbeat (>180s)
update_option( 'presshub_social_worker_last_seen', time() - 300 );
$health_stale = PressHub_Social_Worker_Client::check_health();
assert_true( is_wp_error( $health_stale ) && 'worker_offline' === $health_stale->get_error_code(), 'check_health returns worker_offline when heartbeat is stale' );

// Health check with fresh heartbeat (<=180s)
update_option( 'presshub_social_worker_last_seen', time() - 15 );
$health_fresh = PressHub_Social_Worker_Client::check_health();
assert_true( ! is_wp_error( $health_fresh ) && 'healthy' === $health_fresh['status'], 'check_health succeeds when worker heartbeat is fresh' );

// Session check missing
update_option( 'presshub_social_worker_sessions', [ 'twitter' => false ] );
$sess_missing = PressHub_Social_Worker_Client::verify_session( 'twitter' );
assert_true( is_wp_error( $sess_missing ) && 'session_missing' === $sess_missing->get_error_code(), 'verify_session returns session_missing when platform session is inactive' );

// Session check valid
update_option( 'presshub_social_worker_sessions', [ 'twitter' => true ] );
$sess_valid = PressHub_Social_Worker_Client::verify_session( 'twitter' );
assert_true( ! is_wp_error( $sess_valid ) && ( $sess_valid['valid'] ?? false ), 'verify_session succeeds when platform session is active' );

// 7. Dispatcher Pull Mode gating
PressHub_Social_Queue::reset_memory_store();
$job_pull_gate = PressHub_Social_Queue::enqueue( [
    'post_id'      => 501,
    'platform'     => 'twitter',
    'engine'       => 'automation',
    'post_copy'    => 'Queued for Pull Worker',
    'scheduled_at' => $past_time,
] );
$batch_summary = PressHub_Social_Dispatcher::process_due_queue();
assert_equals( 1, $batch_summary['skipped'], 'process_due_queue skips automation jobs during cron in pull mode' );
$gate_job_after_cron = PressHub_Social_Queue::get_job( $job_pull_gate );
assert_equals( 'pending', $gate_job_after_cron['status'], 'Job remains pending after server-side cron' );

$disp_res = PressHub_Social_Dispatcher::dispatch_job( $job_pull_gate );
assert_true( is_wp_error( $disp_res ) && 'pull_mode_queued' === $disp_res->get_error_code(), 'dispatch_job returns pull_mode_queued in pull mode' );
$gate_job_after_disp = PressHub_Social_Queue::get_job( $job_pull_gate );
assert_equals( 'pending', $gate_job_after_disp['status'], 'Job remains pending for local worker after dispatch_job' );

// ----------------------------------------------------------------------
// Test Suite 19: Copy Regeneration, Published Job Retry & Re-post Operations
// ----------------------------------------------------------------------
echo "\n--- Suite 19: Copy Regeneration, Published Job Retry & Re-post Operations ---\n";
PressHub_Social_Queue::reset_memory_store();

// 1. Retry on a Published job
$pub_job_id = PressHub_Social_Queue::enqueue( [
    'post_id'      => 601,
    'platform'     => 'twitter',
    'engine'       => 'automation',
    'post_copy'    => 'Initial published tweet',
] );
PressHub_Social_Queue::mark_published( $pub_job_id, 'tw-999', 'https://x.com/post/999' );
$job_before_retry = PressHub_Social_Queue::get_job( $pub_job_id );
assert_equals( 'published', $job_before_retry['status'], 'Job initially published' );
assert_equals( 'tw-999', $job_before_retry['external_post_id'], 'External post ID present' );

$retry_success = PressHub_Social_Queue::retry_job( $pub_job_id );
assert_true( $retry_success, 'retry_job succeeds on published job' );
$job_after_retry = PressHub_Social_Queue::get_job( $pub_job_id );
assert_equals( 'pending', $job_after_retry['status'], 'retry_job resets published job to pending' );
assert_equals( 0, (int) $job_after_retry['attempts'], 'retry_job resets attempts to 0' );
assert_true( empty( $job_after_retry['dispatched_at'] ), 'retry_job clears dispatched_at on published job' );
assert_true( empty( $job_after_retry['external_post_id'] ), 'retry_job clears external_post_id on published job' );
assert_true( empty( $job_after_retry['external_post_url'] ), 'retry_job clears external_post_url on published job' );

// 2. update_job_copy
$update_copy_res = PressHub_Social_Queue::update_job_copy( $pub_job_id, 'Updated tweet content after retry' );
assert_true( $update_copy_res, 'update_job_copy successfully updates copy' );
$job_after_update = PressHub_Social_Queue::get_job( $pub_job_id );
assert_equals( 'Updated tweet content after retry', $job_after_update['post_copy'], 'get_job returns updated post copy' );

assert_true( false === PressHub_Social_Queue::update_job_copy( 999999, 'Non-existent' ), 'update_job_copy returns false on non-existent job' );
assert_true( false === PressHub_Social_Queue::update_job_copy( $pub_job_id, '   ' ), 'update_job_copy returns false on empty copy' );

// 3. repost_as_new_job
$reposted_job_id = PressHub_Social_Queue::repost_as_new_job( $pub_job_id, 'Brand new modified copy for separate post' );
assert_true( is_int( $reposted_job_id ) && $reposted_job_id > $pub_job_id, 'repost_as_new_job returns a new distinct job ID' );

$new_job = PressHub_Social_Queue::get_job( $reposted_job_id );
assert_equals( 'Brand new modified copy for separate post', $new_job['post_copy'], 'New job contains the modified copy' );
assert_equals( 'pending', $new_job['status'], 'New job is initialized to pending' );
assert_equals( 601, (int) $new_job['post_id'], 'New job shares the same post_id' );
assert_equals( 'twitter', $new_job['platform'], 'New job shares the same platform' );
assert_equals( 'automation', $new_job['engine'], 'New job shares the same engine' );

// Original job is intact and unmodified
$orig_job_check = PressHub_Social_Queue::get_job( $pub_job_id );
assert_equals( 'Updated tweet content after retry', $orig_job_check['post_copy'], 'Original job copy remains intact' );
assert_equals( $pub_job_id, (int) $orig_job_check['id'], 'Original job ID unchanged' );

// repost_as_new_job with invalid source
$invalid_repost = PressHub_Social_Queue::repost_as_new_job( 888888, 'Fail copy' );
assert_true( is_wp_error( $invalid_repost ), 'repost_as_new_job returns WP_Error for non-existent source job' );

// 4. AI Copywriter Regeneration Integration
update_option( 'presshub_social_copy_mode', 'ai' );
update_option( 'presshub_social_prompt_twitter', 'Write tweet for: {title} {content}' );
PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $config ) {
    return 'Regenerated fresh AI tweet hook: ' . mb_substr( $user, 0, 50 );
} );
$regen_post_id = 602;
$regen_post_obj = new WP_Post( [
    'ID'           => $regen_post_id,
    'post_title'   => 'Breaking News on Climate Summit',
    'post_content' => 'Leaders reached a historic agreement today.',
    'post_excerpt' => 'Historic agreement reached today.',
] );
$GLOBALS['test_posts'][ $regen_post_id ]  = $regen_post_obj;
$GLOBALS['POSTS_STORE'][ $regen_post_id ] = [
    'ID'           => $regen_post_id,
    'post_title'   => 'Breaking News on Climate Summit',
    'post_content' => 'Leaders reached a historic agreement today.',
    'post_excerpt' => 'Historic agreement reached today.',
    'post_status'  => 'publish',
];
$regen_job_id = PressHub_Social_Queue::enqueue( [
    'post_id'   => $regen_post_id,
    'platform'  => 'twitter',
    'engine'    => 'automation',
    'post_copy' => 'Old stale copy',
] );

// Call copywriter directly to regenerate and update
$new_ai_copy = PressHub_Social_AI_Copywriter::generate_for_post( $regen_post_id, 'twitter' );
assert_true( is_string( $new_ai_copy ) && str_starts_with( $new_ai_copy, 'Regenerated fresh AI tweet hook:' ), 'AI copywriter generates new text for queue job' );
PressHub_Social_Queue::update_job_copy( $regen_job_id, $new_ai_copy );
$regen_job_after = PressHub_Social_Queue::get_job( $regen_job_id );
assert_equals( $new_ai_copy, $regen_job_after['post_copy'], 'Queue job copy updated with newly regenerated AI copy' );
PressHub_Social_AI_Copywriter::set_ai_caller( null );

// ----------------------------------------------------------------------
// Test Suite 20: Per-Platform Enable/Disable & Configuration Gating
// ----------------------------------------------------------------------
echo "\n--- Suite 20: Per-Platform Enable/Disable & Configuration Gating ---\n";

// 1. Wipe credentials and sessions to test unconfigured baseline
delete_option( 'presshub_social_meta_page_id' );
delete_option( 'presshub_social_meta_access_token' );
delete_option( 'presshub_social_instagram_account_id' );
delete_option( 'presshub_social_twitter_bearer_token' );
delete_option( 'presshub_social_twitter_api_key' );
delete_option( 'presshub_social_twitter_api_secret' );
delete_option( 'presshub_social_twitter_access_token' );
delete_option( 'presshub_social_twitter_access_secret' );
delete_option( 'presshub_social_tiktok_access_token' );
delete_option( 'presshub_social_tiktok_open_id' );
delete_option( 'presshub_social_worker_sessions' );
update_option( 'presshub_social_engine_facebook', 'api' );
update_option( 'presshub_social_engine_instagram', 'api' );
update_option( 'presshub_social_engine_twitter', 'automation' );
update_option( 'presshub_social_engine_tiktok', 'automation' );
update_option( 'presshub_social_worker_mode', 'pull' );

assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'facebook' ), 'Facebook API engine unconfigured when credentials missing' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'instagram' ), 'Instagram API engine unconfigured when credentials missing' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Twitter automation engine unconfigured when pull sessions empty' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'tiktok' ), 'TikTok automation engine unconfigured when pull sessions empty' );

// 2. Configure worker sessions for twitter and tiktok
update_option( 'presshub_social_worker_sessions', [ 'twitter' => true, 'tiktok' => true ] );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Twitter automation engine configured when pull session active' );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'tiktok' ), 'TikTok automation engine configured when pull session active' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'facebook' ), 'Facebook still unconfigured in API engine' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'instagram' ), 'Instagram still unconfigured in API engine' );

// 3. Configure API engine for Facebook and Instagram
update_option( 'presshub_social_meta_page_id', '1000123' );
update_option( 'presshub_social_meta_access_token', 'EAABtest_token' );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'facebook' ), 'Facebook API engine configured with Page ID and token' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'instagram' ), 'Instagram API engine unconfigured without Instagram Account ID' );
update_option( 'presshub_social_instagram_account_id', 'inst_9988' );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'instagram' ), 'Instagram API engine configured with Account ID and token' );

// 4. Test Push mode automation configuration
update_option( 'presshub_social_engine_twitter', 'automation' );
update_option( 'presshub_social_worker_mode', 'push' );
delete_option( 'presshub_social_worker_secret' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Push mode automation unconfigured when worker secret missing' );
update_option( 'presshub_social_worker_url', 'http://127.0.0.1:3456' );
update_option( 'presshub_social_worker_secret', 'sec123' );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Push mode automation configured when URL and secret present' );
update_option( 'presshub_social_worker_sessions', [ 'twitter' => false ] );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Push mode stays configured when the session is momentarily inactive (jobs wait)' );
assert_false( PressHub_Social_Settings_Storage::is_platform_session_active( 'twitter' ), 'is_platform_session_active reports the inactive session for the badge' );
update_option( 'presshub_social_worker_sessions', [ 'twitter' => true ] );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Push mode automation configured when session active' );
assert_true( PressHub_Social_Settings_Storage::is_platform_session_active( 'twitter' ), 'is_platform_session_active true when session active' );

// 4b. Pull mode: a momentarily inactive session must NOT drop posts (review of #128).
update_option( 'presshub_social_worker_mode', 'pull' );
update_option( 'presshub_social_worker_sessions', [ 'twitter' => false ] );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Pull mode: platform reported by the worker stays configured while its session is inactive' );
delete_option( 'presshub_social_worker_sessions' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Pull mode: platform never reported by the worker is not configured' );
update_option( 'presshub_social_engine_twitter', 'api' );
update_option( 'presshub_social_twitter_access_token', 'tw-access-only' );
delete_option( 'presshub_social_twitter_bearer_token' );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Twitter API engine configured with an Access token alone (matches the poster)' );
delete_option( 'presshub_social_twitter_access_token' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'twitter' ), 'Twitter API engine unconfigured without Bearer or Access token' );
update_option( 'presshub_social_engine_twitter', 'automation' );

// 5. Per-platform enable/disable settings
update_option( 'presshub_social_enabled_platforms', [ 'twitter', 'facebook', 'instagram', 'tiktok' ] );
delete_option( 'presshub_social_enabled_facebook' );
delete_option( 'presshub_social_enabled_twitter' );
delete_option( 'presshub_social_enabled_instagram' );
delete_option( 'presshub_social_enabled_tiktok' );
update_option( 'presshub_social_engine_twitter', 'automation' );
update_option( 'presshub_social_engine_tiktok', 'automation' );
update_option( 'presshub_social_engine_facebook', 'api' );
update_option( 'presshub_social_engine_instagram', 'api' );
update_option( 'presshub_social_worker_mode', 'pull' );
update_option( 'presshub_social_worker_sessions', [ 'twitter' => true, 'tiktok' => true ] );

PressHub_Social_Settings_Storage::set_platform_enabled( 'twitter', false );
assert_false( PressHub_Social_Settings_Storage::is_platform_enabled( 'twitter' ), 'set_platform_enabled(twitter, false) disables twitter' );
assert_false( in_array( 'twitter', PressHub_Social_Settings_Storage::get_enabled_platforms(), true ), 'get_enabled_platforms excludes disabled twitter' );

PressHub_Social_Settings_Storage::set_platform_enabled( 'twitter', true );
assert_true( PressHub_Social_Settings_Storage::is_platform_enabled( 'twitter' ), 'set_platform_enabled(twitter, true) re-enables twitter' );
assert_true( in_array( 'twitter', PressHub_Social_Settings_Storage::get_enabled_platforms(), true ), 'get_enabled_platforms includes re-enabled twitter' );

update_option( 'presshub_social_enabled_facebook', false );
assert_false( PressHub_Social_Settings_Storage::is_platform_enabled( 'facebook' ), 'Option presshub_social_enabled_facebook=false disables facebook' );
assert_false( in_array( 'facebook', PressHub_Social_Settings_Storage::get_enabled_platforms(), true ), 'get_enabled_platforms reflects individual facebook option' );

// 6. Platform readiness checks
// Twitter: enabled=true, configured=true
// TikTok: enabled=true, configured=true
// Facebook: enabled=false, configured=true
// Instagram: enabled=true, configured=false
delete_option( 'presshub_social_instagram_account_id' );
delete_option( 'presshub_social_meta_access_token' );
update_option( 'presshub_social_enabled_instagram', true );

assert_true( PressHub_Social_Settings_Storage::is_platform_ready( 'twitter' ), 'Twitter is ready (enabled and configured)' );
assert_true( PressHub_Social_Settings_Storage::is_platform_ready( 'tiktok' ), 'TikTok is ready (enabled and configured)' );
assert_false( PressHub_Social_Settings_Storage::is_platform_ready( 'facebook' ), 'Facebook is not ready because it is disabled' );
assert_false( PressHub_Social_Settings_Storage::is_platform_ready( 'instagram' ), 'Instagram is not ready because it is unconfigured' );
assert_equals( [ 'twitter', 'tiktok' ], PressHub_Social_Settings_Storage::get_ready_platforms(), 'get_ready_platforms returns only ready platforms' );

// 7. Dispatcher queuing gating
PressHub_Social_Queue::reset_memory_store();
$GLOBALS['POSTS_STORE'][501] = [
    'ID'           => 501,
    'post_title'   => 'Article for Gating Test',
    'post_content' => 'Content for testing gating.',
    'post_excerpt' => 'Excerpt for testing gating.',
    'post_status'  => 'publish',
];
$GLOBALS['test_posts'][501] = new WP_Post( $GLOBALS['POSTS_STORE'][501] );

$enqueued_501 = PressHub_Social_Dispatcher::enqueue_for_post( 501, [ 'twitter', 'facebook', 'instagram', 'tiktok' ] );
assert_equals( 2, count( $enqueued_501 ), 'Dispatcher enqueues jobs only for ready platforms (twitter, tiktok)' );

$jobs_501 = PressHub_Social_Queue::get_jobs_for_post( 501 );
$platforms_enqueued = array_column( $jobs_501, 'platform' );
assert_true( in_array( 'twitter', $platforms_enqueued, true ) && in_array( 'tiktok', $platforms_enqueued, true ), 'Enqueued platforms match twitter and tiktok' );
assert_false( in_array( 'facebook', $platforms_enqueued, true ), 'Disabled platform facebook was skipped' );
assert_false( in_array( 'instagram', $platforms_enqueued, true ), 'Unconfigured platform instagram was skipped' );

// 7b. Pull mode: an article published while the worker reports Twitter's session as inactive is still queued.
PressHub_Social_Queue::reset_memory_store();
update_option( 'presshub_social_worker_sessions', [ 'twitter' => false, 'tiktok' => true ] );
$enqueued_inactive = PressHub_Social_Dispatcher::enqueue_for_post( 501, [ 'twitter', 'tiktok' ] );
$platforms_inactive = array_column( PressHub_Social_Queue::get_jobs_for_post( 501 ), 'platform' );
assert_true( in_array( 'twitter', $platforms_inactive, true ), 'Twitter job is queued even though its session is momentarily inactive' );
assert_equals( 2, count( $enqueued_inactive ), 'Both automation platforms queued while one session is inactive' );
update_option( 'presshub_social_worker_sessions', [ 'twitter' => true, 'tiktok' => true ] );

// 8. Dispatcher returns empty array when only unconfigured or disabled platforms are requested
$enqueued_empty = PressHub_Social_Dispatcher::enqueue_for_post( 501, [ 'facebook', 'instagram' ] );
assert_equals( [], $enqueued_empty, 'enqueue_for_post returns empty array when only unconfigured or disabled platforms are requested' );


// ----------------------------------------------------------------------
// Test Suite: TikTok Narration (Gemini TTS voice-over for text-only articles)
// ----------------------------------------------------------------------
echo "\n--- TikTok Narration ---\n";

$narr_copy = "Τέλος στην «αποικιοκρατική κληρονομιά» των Βρετανικών Βάσεων; 🇬🇧🇨🇾\n\n"
    . "Ο Πρόεδρος Χριστοδουλίδης ανοίγει ένα από τα πιο ακανθώδη θέματα της εξωτερικής πολιτικής. Οι εξελίξεις κορυφώνονται.\n\n"
    . "Δείτε όλες τις λεπτομέρειες για τις διπλωματικές ανατροπές στο  Presshub.cy\n\n"
    . "#Cyprus #News #Politics #BreakingNews #CyprusNews #Christodoulides";
$narr_outro = PressHub_Social_TikTok_Narrator::DEFAULT_OUTRO;

assert_equals(
    "Τέλος στην «αποικιοκρατική κληρονομιά» των Βρετανικών Βάσεων;\n"
    . "Ο Πρόεδρος Χριστοδουλίδης ανοίγει ένα από τα πιο ακανθώδη θέματα της εξωτερικής πολιτικής. Οι εξελίξεις κορυφώνονται.\n\n"
    . 'Για το πλήρες άρθρο, επισκεφθείτε το presshub.cy',
    PressHub_Social_TikTok_Narrator::build_narration_text( $narr_copy, $narr_outro ),
    'Narration drops emojis, hashtags and the presshub.cy CTA line, then appends the outro'
);
assert_equals(
    "Δείτε το βίντεο.\n\n" . $narr_outro,
    PressHub_Social_TikTok_Narrator::build_narration_text( "Δείτε το βίντεο https://example.com/a?b=1 🔥", $narr_outro ),
    'Narration strips URLs and adds a full stop before the outro'
);
assert_equals(
    "Οι εξελίξεις κορυφώνονται.\n\n" . $narr_outro,
    PressHub_Social_TikTok_Narrator::build_narration_text( 'Οι εξελίξεις κορυφώνονται. Δείτε περισσότερα στο Presshub.cy', $narr_outro ),
    'Narration drops only the presshub.cy sentence when it shares a line with content'
);
assert_equals( '', PressHub_Social_TikTok_Narrator::build_narration_text( "🔥🔥\n#Cyprus #News\nhttps://x.com", $narr_outro ), 'Copy with nothing speakable yields empty narration text' );
assert_equals( $narr_outro, PressHub_Social_Settings_Storage::get_tiktok_narration_outro(), 'Narration outro defaults to the Greek presshub.cy sentence' );
assert_true( PressHub_Social_Settings_Storage::get_tiktok_narration_enabled(), 'TikTok narration is enabled by default' );

PressHub_Social_Queue::reset_memory_store();
$narr_calls = [];
PressHub_Social_TikTok_Narrator::set_synthesizer( function ( string $text, array $job ) use ( &$narr_calls ) {
    $narr_calls[] = $text;
    return [ 'url' => 'https://example.com/uploads/narration-' . count( $narr_calls ) . '.wav', 'id' => 900 + count( $narr_calls ), 'voice' => 'Kore' ];
} );

$narr_job_id = PressHub_Social_Queue::enqueue( [ 'post_id' => 36, 'platform' => 'tiktok', 'engine' => 'automation', 'post_copy' => $narr_copy ] );
$narr_job    = PressHub_Social_Queue::get_job( $narr_job_id );
$narrated    = PressHub_Social_TikTok_Narrator::attach_narration( $narr_job );
assert_true( ! is_wp_error( $narrated ) && 'https://example.com/uploads/narration-1.wav' === $narrated['audio_url'], 'Text-only TikTok job gets narration audio_url' );
assert_true( ! empty( $narrated['narration'] ), 'Narrated job is flagged with narration=true' );
assert_equals( 1, count( $narr_calls ), 'Synthesizer called once for a new narration' );
assert_true( str_ends_with( $narr_calls[0], $narr_outro ), 'Synthesized text ends with the outro' );
$narr_meta = json_decode( (string) PressHub_Social_Queue::get_job( $narr_job_id )['metadata'], true );
assert_equals( 901, $narr_meta['tiktok_narration']['attachment_id'] ?? null, 'Narration attachment recorded in job metadata' );

$narrated_again = PressHub_Social_TikTok_Narrator::attach_narration( PressHub_Social_Queue::get_job( $narr_job_id ) );
assert_equals( 1, count( $narr_calls ), 'Retry with unchanged copy reuses cached narration (no new TTS call)' );
assert_equals( 'https://example.com/uploads/narration-1.wav', $narrated_again['audio_url'], 'Reused narration keeps the original audio URL' );

PressHub_Social_Queue::update_job_copy( $narr_job_id, 'Νέο κείμενο για το TikTok.' );
$narrated_edit = PressHub_Social_TikTok_Narrator::attach_narration( PressHub_Social_Queue::get_job( $narr_job_id ) );
assert_equals( 2, count( $narr_calls ), 'Edited copy triggers a fresh narration' );
assert_equals( 'https://example.com/uploads/narration-2.wav', $narrated_edit['audio_url'], 'Fresh narration audio URL attached after copy edit' );

PressHub_Social_Queue::mark_published( $narr_job_id, 'tt_1', 'https://www.tiktok.com/@presshub/video/1', [ 'worker' => 'local' ] );
$narr_pub_meta = json_decode( (string) PressHub_Social_Queue::get_job( $narr_job_id )['metadata'], true );
assert_true( isset( $narr_pub_meta['tiktok_narration'], $narr_pub_meta['worker'] ), 'mark_published merges worker metadata with the recorded narration' );

$podcast_job = PressHub_Social_TikTok_Narrator::attach_narration( [ 'id' => 0, 'platform' => 'tiktok', 'post_copy' => 'Κείμενο', 'audio_url' => 'https://example.com/podcast.mp3' ] );
assert_equals( 'https://example.com/podcast.mp3', $podcast_job['audio_url'], 'Podcast audio takes precedence over narration' );
assert_equals( 2, count( $narr_calls ), 'No TTS call when the article already has audio' );

$twitter_job = PressHub_Social_TikTok_Narrator::attach_narration( [ 'id' => 0, 'platform' => 'twitter', 'post_copy' => 'Κείμενο' ] );
assert_true( empty( $twitter_job['audio_url'] ), 'Non-TikTok jobs are never narrated' );

update_option( 'presshub_social_tiktok_narration_enabled', false );
$disabled_job = PressHub_Social_TikTok_Narrator::attach_narration( [ 'id' => 0, 'platform' => 'tiktok', 'post_copy' => 'Κείμενο' ] );
assert_true( empty( $disabled_job['audio_url'] ) && 2 === count( $narr_calls ), 'Disabled narration leaves the job silent and skips TTS' );
update_option( 'presshub_social_tiktok_narration_enabled', true );

$empty_err = PressHub_Social_TikTok_Narrator::attach_narration( [ 'id' => 0, 'platform' => 'tiktok', 'post_copy' => '#Cyprus #News' ] );
assert_true( is_wp_error( $empty_err ) && 'tiktok_narration_empty' === $empty_err->get_error_code(), 'Copy with no speakable text fails loudly' );

// Claim integration: success attaches audio; TTS failure keeps the job out of the batch and records the error.
PressHub_Social_Queue::reset_memory_store();
$claim_past = gmdate( 'Y-m-d H:i:s', time() - 60 );
$claim_tt   = PressHub_Social_Queue::enqueue( [ 'post_id' => 501, 'platform' => 'tiktok', 'engine' => 'automation', 'post_copy' => 'Καθημερινή ενημέρωση από την Κύπρο.', 'scheduled_at' => $claim_past ] );
$req_tt     = new WP_REST_Request( 'POST', '/worker/claim' );
$req_tt->set_param( 'sessions', [ 'tiktok' => true ] );
$res_tt = PressHub_Social_REST_Controller::handle_claim( $req_tt );
assert_equals( 1, count( $res_tt['jobs'] ), 'Narrated TikTok job is claimed' );
assert_equals( 'https://example.com/uploads/narration-3.wav', $res_tt['jobs'][0]['audio_url'] ?? null, 'Claimed TikTok job carries the narration audio_url to the worker' );

PressHub_Social_TikTok_Narrator::set_synthesizer( fn( string $text, array $job ) => new WP_Error( 'tts_http_500', 'Gemini TTS HTTP 500' ) );
PressHub_Social_Queue::reset_memory_store();
$claim_fail = PressHub_Social_Queue::enqueue( [ 'post_id' => 501, 'platform' => 'tiktok', 'engine' => 'automation', 'post_copy' => 'Άλλη ενημέρωση.', 'scheduled_at' => $claim_past ] );
$res_fail   = PressHub_Social_REST_Controller::handle_claim( $req_tt );
assert_equals( 0, count( $res_fail['jobs'] ), 'TikTok job is not handed to the worker when narration fails' );
$claim_fail_job = PressHub_Social_Queue::get_job( $claim_fail );
assert_true( str_contains( (string) $claim_fail_job['last_error'], 'Gemini TTS HTTP 500' ), 'Narration failure is recorded as the job error' );
assert_true( 'processing' !== $claim_fail_job['status'], 'Failed narration does not leave the job stuck in processing' );


// Generation record for the narration (#140 follow-up): kind tiktok_narration, linked to the
// article post, with the TTS calls made during synthesis logged on it (not on a tts_test record).
require_once $base_dir . '/includes/class-generation-recorder.php';
require_once $base_dir . '/includes/class-tts-call-recorder.php';
global $wpdb;
$wpdb->tables[ PressHub_AI_Generation_Recorder::get_table_name() ] = [];
PressHub_AI_Generation_Recorder::create_table();
PressHub_Social_TikTok_Narrator::set_synthesizer( function ( string $text, array $job ) {
    // Same call the real Gemini path makes for each TTS request.
    PressHub_AI_TTS_Call_Recorder::log_call( [
        'engine'      => 'gemini',
        'model'       => 'gemini-2.5-flash-preview-tts',
        'voice'       => 'Kore',
        'text'        => $text,
        'request'     => [ 'contents' => [ [ 'parts' => [ [ 'text' => $text ] ] ] ] ],
        'http_status' => 200,
        'error'       => '',
        'duration_ms' => 1200,
        'audio_bytes' => 48000,
    ] );
    return [ 'url' => 'https://example.com/uploads/narration-rec.wav', 'id' => 990, 'voice' => 'Kore' ];
} );
PressHub_Social_Queue::reset_memory_store();
$rec_job_id = PressHub_Social_Queue::enqueue( [ 'post_id' => 777, 'platform' => 'tiktok', 'engine' => 'automation', 'post_copy' => 'Νέα ενημέρωση για το TikTok.' ] );
$rec_job    = PressHub_Social_TikTok_Narrator::attach_narration( PressHub_Social_Queue::get_job( $rec_job_id ) );
$rec_rows   = array_values( $wpdb->tables[ PressHub_AI_Generation_Recorder::get_table_name() ] ?? [] );
$rec_kinds  = array_column( $rec_rows, 'kind' );
assert_true( ! is_wp_error( $rec_job ), 'Narration with recording succeeds' );
assert_equals( [ 'tiktok_narration' ], $rec_kinds, 'Exactly one tiktok_narration record is created (no standalone tts_test record)' );
$rec_meta = json_decode( (string) PressHub_Social_Queue::get_job( $rec_job_id )['metadata'], true );
$rec_id   = (int) ( $rec_meta['tiktok_narration']['generation_record_id'] ?? 0 );
assert_true( $rec_id > 0, 'Job metadata stores the narration generation_record_id' );
$rec = PressHub_AI_Generation_Recorder::get( $rec_id );
assert_equals( 777, (int) ( $rec['post_id'] ?? 0 ), 'Narration record is linked to the article post' );
assert_true( in_array( $rec_id, (array) get_post_meta( 777, PressHub_AI_Generation_Recorder::POST_META_KEY, true ), true ), 'Narration record is attached to the post for the Generation Inspector' );
assert_true( str_ends_with( (string) ( $rec['user_prompt'] ?? '' ), PressHub_Social_TikTok_Narrator::DEFAULT_OUTRO ), 'Narration record keeps the spoken text verbatim as its prompt' );
$rec_calls = $rec['tts_calls'] ?? [];
assert_equals( 1, count( $rec_calls['entries'] ?? [] ), 'The TTS call is logged on the narration record' );
assert_equals( 'https://example.com/uploads/narration-rec.wav', $rec['tts']['audio_url'] ?? '', 'Narration record stores the audio URL' );
assert_equals( 'Kore', $rec['tts']['voices']['narrator'] ?? '', 'Narration record stores the voice used' );
assert_true( ! PressHub_AI_TTS_Call_Recorder::is_active(), 'The TTS session is closed after narration' );

PressHub_Social_TikTok_Narrator::set_synthesizer( fn( string $text, array $job ) => new WP_Error( 'tts_http_500', 'Gemini TTS HTTP 500' ) );
PressHub_Social_Queue::reset_memory_store();
$rec_fail_job = PressHub_Social_Queue::enqueue( [ 'post_id' => 778, 'platform' => 'tiktok', 'engine' => 'automation', 'post_copy' => 'Άλλη ενημέρωση για αποτυχία.' ] );
PressHub_Social_TikTok_Narrator::attach_narration( PressHub_Social_Queue::get_job( $rec_fail_job ) );
$rec_fail_rows = array_values( array_filter( $wpdb->tables[ PressHub_AI_Generation_Recorder::get_table_name() ] ?? [], fn( $r ) => 778 === (int) ( $r['post_id'] ?? 0 ) ) );
assert_equals( 'error', $rec_fail_rows[0]['status'] ?? '', 'A failed narration is recorded with status error' );
assert_true( str_contains( (string) ( $rec_fail_rows[0]['error_message'] ?? '' ), 'Gemini TTS HTTP 500' ), 'A failed narration record keeps the error message' );

PressHub_Social_TikTok_Narrator::set_synthesizer( null );

// ----------------------------------------------------------------------
// Test Suite: TikTok Shorts (one short video per top story of a podcast post)
// ----------------------------------------------------------------------
echo "\n--- TikTok Shorts ---\n";

assert_false( PressHub_Social_Settings_Storage::get_tiktok_shorts_enabled(), 'TikTok shorts are off by default' );
assert_equals( 3, PressHub_Social_Settings_Storage::get_tiktok_shorts_count(), 'Shorts per podcast defaults to 3' );
assert_equals( 180, PressHub_Social_Settings_Storage::get_tiktok_shorts_spacing_minutes(), 'Shorts spacing defaults to 180 minutes' );
assert_equals( 130, PressHub_Social_Settings_Storage::get_tiktok_shorts_max_words(), 'Short script word cap defaults to 130' );
assert_equals( 5, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_count( 12 ), 'Shorts count clamps to 5' );
assert_equals( 1, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_count( 0 ), 'Shorts count clamps to 1' );
assert_equals( 720, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_spacing_minutes( 5000 ), 'Shorts spacing clamps to 720 minutes' );
assert_equals( 60, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_max_words( 10 ), 'Short word cap clamps to 60' );
assert_equals( 3, PressHub_Social_Settings_Storage::get_tiktok_shorts_images(), 'Story images per short default to 3' );
assert_equals( 4, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_images( 9 ), 'Story images per short clamp to 4' );
assert_equals( 0, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_images( -1 ), 'Story images per short clamp to 0' );
$shorts_prompt = PressHub_Social_Settings_Storage::get_tiktok_shorts_prompt();
assert_true( str_contains( $shorts_prompt, '{script}' ) && str_contains( $shorts_prompt, '"shorts"' ), 'Shorts prompt is seeded from social_tiktok_shorts.txt' );
assert_true( str_contains( $shorts_prompt, '"visuals"' ) && str_contains( $shorts_prompt, '{image_count}' ), 'Shorts prompt asks for story image descriptions' );

// An unedited older default prompt (no story images) is upgraded; an edited one is kept.
$legacy_prompt = "Γράψε {count} σύντομα βίντεο TikTok (shorts) από το παρακάτω podcast ενημέρωσης του {site_name} ({title}). Κάθε short καλύπτει ΜΙΑ είδηση και διαρκεί 30 έως 60 δευτερόλεπτα.";
assert_false( PressHub_Social_Settings_Storage::is_legacy_tiktok_shorts_prompt( $legacy_prompt ), 'A prompt that differs from the old default is not treated as legacy' );
$legacy_hash_ok = 'e5b721e8c94a252df788a967504c5061b975581411b3f4d20fc6f3097657832b';
assert_true( in_array( $legacy_hash_ok, ( new ReflectionClassConstant( 'PressHub_Social_Settings_Storage', 'LEGACY_TIKTOK_SHORTS_PROMPT_HASHES' ) )->getValue(), true ), 'The v2.18 default prompt hash is listed as legacy' );
$legacy_file = (string) shell_exec( 'git -C ' . escapeshellarg( $base_dir ) . ' show 63b77c4:presshub-ai-editor/assets/prompts/social_tiktok_shorts.txt 2>/dev/null' );
if ( '' !== trim( $legacy_file ) ) {
    assert_true( PressHub_Social_Settings_Storage::is_legacy_tiktok_shorts_prompt( str_replace( "\n", "\r\n", $legacy_file ) ), 'The v2.18 default prompt is recognised (also with CRLF line endings)' );
    update_option( 'presshub_social_prompt_tiktok_shorts', $legacy_file );
    assert_true( str_contains( PressHub_Social_Settings_Storage::get_tiktok_shorts_prompt(), '"visuals"' ), 'An unedited v2.18 prompt is upgraded to the current default' );
    update_option( 'presshub_social_prompt_tiktok_shorts', $legacy_file . "\nΔική μου οδηγία." );
    assert_true( str_contains( PressHub_Social_Settings_Storage::get_tiktok_shorts_prompt(), 'Δική μου οδηγία.' ), 'An edited prompt is kept as it is' );
    delete_option( 'presshub_social_prompt_tiktok_shorts' );
}

// The image prompt for story images is a setting with a {description} placeholder.
$image_style = PressHub_Social_Settings_Storage::get_tiktok_shorts_image_style();
assert_true( str_contains( $image_style, '{description}' ) && str_contains( $image_style, '9:16' ), 'The shorts image prompt is seeded from social_tiktok_shorts_image_style.txt' );
assert_equals( 'A harbour. Vertical photo.', PressHub_Social_TikTok_Shorts::image_prompt( '{description} Vertical photo.', 'A harbour.' ), 'image_prompt fills {description}' );
assert_equals( 'A harbour. Vertical photo.', PressHub_Social_TikTok_Shorts::image_prompt( 'Vertical photo.', 'A harbour.' ), 'image_prompt puts the description first when the placeholder is missing' );
assert_equals( 'A harbour.', PressHub_Social_TikTok_Shorts::image_prompt( '', 'A harbour.' ), 'An empty image prompt sends the description alone' );

// Turn timings for the synced captions.
$pcm_1s  = str_repeat( "\x00", 48000 );
$wav_1s5 = 'RIFF' . pack( 'V', 36 + 72000 ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16 ) . 'data' . pack( 'V', 72000 ) . str_repeat( "\x00", 72000 );
assert_equals( 1.0, PressHub_Social_TikTok_Shorts::pcm_seconds( $pcm_1s ), 'pcm_seconds measures raw 24 kHz PCM' );
assert_equals( 1.5, PressHub_Social_TikTok_Shorts::pcm_seconds( $wav_1s5 ), 'pcm_seconds measures a WAV from its data chunk' );
$timings = PressHub_Social_TikTok_Shorts::turn_timings(
    [ [ 'speaker' => 'female', 'text' => 'Πρώτη ατάκα.' ], [ 'speaker' => 'male', 'text' => 'Δεύτερη ατάκα.' ] ],
    [ $pcm_1s, $wav_1s5 ],
    400,
    false
);
assert_equals( [ 1, 0.0, 1.0 ], [ $timings[0]['speaker'], $timings[0]['start'], $timings[0]['end'] ], 'The first turn starts at 0 and lasts its audio length' );
assert_equals( [ 2, 1.4, 2.9 ], [ $timings[1]['speaker'], $timings[1]['start'], $timings[1]['end'] ], 'The next turn starts after the stitched pause' );
$mp3_timings = PressHub_Social_TikTok_Shorts::turn_timings( [ [ 'speaker' => 'female', 'text' => str_repeat( 'α', 28 ) ] ], [ 'mp3' ], 400, true );
assert_true( ! empty( $mp3_timings[0]['estimated'] ) && 2.0 === $mp3_timings[0]['end'], 'MP3 turn timings are estimates marked as such' );

$short_json = function ( int $n ): string {
    $shorts = [];
    for ( $i = 1; $i <= $n; $i++ ) {
        $shorts[] = [
            'topic'   => "Θέμα {$i}",
            'hook'    => "Hook {$i} σε λίγες λέξεις",
            'script'  => "[SPEAKER_1]: Γεγονός {$i}, γιατί μας αφορά;\n[SPEAKER_2]: Γιατί αλλάζει το πορτοφόλι μας.\n[SPEAKER_1]: Ακολούθησε το PressHub.",
            'visuals' => [ "Limassol harbour at dawn {$i}", "  Close-up of a payslip {$i}  ", '' ],
            'caption' => "Λεζάντα {$i}\nΟλόκληρη η ενημέρωση: λινκ στο bio #κύπρος #cyprus #ειδήσεις",
        ];
    }
    return wp_json_encode( [ 'shorts' => $shorts ] );
};

$parsed = PressHub_Social_TikTok_Shorts::parse_plan( "```json\n" . $short_json( 4 ) . "\n```", 3 );
assert_true( is_array( $parsed ) && 3 === count( $parsed ), 'parse_plan strips code fences and keeps at most the configured count' );
assert_equals( 'Hook 1 σε λίγες λέξεις', $parsed[0]['hook'] ?? '', 'parse_plan keeps the hook' );
assert_equals( [ 'Limassol harbour at dawn 1', 'Close-up of a payslip 1' ], $parsed[0]['visuals'] ?? null, 'parse_plan keeps the image descriptions, trimmed, without empty ones' );
$one_visual = PressHub_Social_TikTok_Shorts::parse_plan( $short_json( 1 ), 3, 0, 1 );
assert_equals( 1, count( $one_visual[0]['visuals'] ?? [] ), 'parse_plan keeps at most the configured number of image descriptions' );
$no_visuals = PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"topic":"a","hook":"b","script":"[SPEAKER_1]: Α","caption":"c"}]}', 3 );
assert_equals( [], $no_visuals[0]['visuals'] ?? null, 'A plan without image descriptions is still valid' );
$bad_speakers = PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"topic":"a","hook":"b","script":"Χωρίς ετικέτες","caption":"c"}]}', 3 );
assert_true( is_wp_error( $bad_speakers ), 'parse_plan rejects a script without [SPEAKER_N]: lines' );
assert_true( is_wp_error( PressHub_Social_TikTok_Shorts::parse_plan( 'Δεν είναι JSON', 3 ) ), 'parse_plan rejects a non-JSON response' );

// A podcast post and the settings shorts need.
$GLOBALS['POSTS_STORE'][880] = [
    'ID'           => 880,
    'post_title'   => 'Πρωινό Podcast Ενημέρωσης - 12/11/2026',
    'post_content' => '<audio controls src="https://example.com/uploads/podcast-880.mp3"></audio>',
    'post_excerpt' => '',
    'post_status'  => 'publish',
];
$GLOBALS['test_posts'][880] = new WP_Post( $GLOBALS['POSTS_STORE'][880] );
update_post_meta( 880, '_presshub_briefing_type', 'podcast' );
update_post_meta( 880, '_presshub_briefing_date', '2026-11-12' );
update_post_meta( 880, '_presshub_audio_url', 'https://example.com/uploads/podcast-880.mp3' );
update_post_meta( 880, '_presshub_podcast_script', "[TOPIC_START: Οικονομία]\n[SPEAKER_1]: Ο κατώτατος μισθός αυξάνεται.\n[SPEAKER_2]: Από τον Ιανουάριο.\n[TOPIC_END]" );
update_option( 'presshub_social_engine_tiktok', 'automation' );
update_option( 'presshub_social_worker_mode', 'pull' );

$shorts_ai_calls = [];
PressHub_Social_TikTok_Shorts::set_ai_caller( function ( string $sys, string $user ) use ( &$shorts_ai_calls, $short_json ) {
    $shorts_ai_calls[] = $user;
    return $short_json( 3 );
} );
$shorts_tts = [];
PressHub_Social_TikTok_Shorts::set_synthesizer( function ( array $short, array $job ) use ( &$shorts_tts ) {
    $shorts_tts[] = $short['script'];
    return [
        'url'      => 'https://example.com/uploads/short-' . count( $shorts_tts ) . '.wav',
        'id'       => 950 + count( $shorts_tts ),
        'segments' => [ [ 'speaker' => 1, 'text' => 'Γεγονός.', 'start' => 0.0, 'end' => 1.2 ], [ 'speaker' => 2, 'text' => 'Γιατί.', 'start' => 1.6, 'end' => 2.4 ] ],
    ];
} );
$shorts_images = [];
PressHub_Social_TikTok_Shorts::set_image_generator( function ( string $prompt, int $post_id, array $short ) use ( &$shorts_images ) {
    $shorts_images[] = $prompt;
    if ( str_contains( $prompt, 'payslip 3' ) ) {
        return new WP_Error( 'image_http_429', 'Image API rate limit' );
    }
    return [ 'url' => 'https://example.com/uploads/short-img-' . count( $shorts_images ) . '.png', 'id' => 1200 + count( $shorts_images ) ];
} );
PressHub_Social_AI_Copywriter::set_ai_caller( fn() => 'Γενική λεζάντα TikTok #news' );

// Shorts off: the podcast still goes to TikTok as one job.
PressHub_Social_Queue::reset_memory_store();
PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
assert_equals( 1, count( PressHub_Social_Queue::get_jobs_for_post( 880 ) ), 'With shorts off a podcast post gets one TikTok job' );

// Shorts on: three spaced jobs with placeholder copy and their index in metadata.
update_option( 'presshub_social_tiktok_shorts_enabled', true );
PressHub_Social_Queue::reset_memory_store();
$short_job_ids = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
assert_equals( 3, count( $short_job_ids ), 'With shorts on a podcast post gets one TikTok job per short' );
$short_jobs = array_map( fn( $id ) => PressHub_Social_Queue::get_job( $id ), $short_job_ids );
assert_true( str_starts_with( (string) $short_jobs[0]['post_copy'], PressHub_Social_TikTok_Shorts::PLACEHOLDER_PREFIX ), 'Short jobs start with placeholder copy' );
assert_equals( 180 * 60, strtotime( $short_jobs[1]['scheduled_at'] ) - strtotime( $short_jobs[0]['scheduled_at'] ), 'Shorts are scheduled 180 minutes apart' );
assert_equals( 2, json_decode( (string) $short_jobs[2]['metadata'], true )['tiktok_short']['index'] ?? null, 'Third short job carries index 2' );
assert_equals( 0, count( $shorts_ai_calls ), 'No shorts LLM call at enqueue time' );

// An article that is not a podcast is never split.
PressHub_Social_Queue::reset_memory_store();
PressHub_Social_Dispatcher::enqueue_for_post( 501, [ 'tiktok' ] );
assert_equals( 1, count( PressHub_Social_Queue::get_jobs_for_post( 501 ) ), 'A text article still gets one TikTok job with shorts on' );

// Shorts need Pull Mode: in push mode the full podcast is posted.
update_option( 'presshub_social_worker_mode', 'push' );
assert_false( PressHub_Social_TikTok_Shorts::should_split( 880, 'automation' ), 'Shorts are not made outside Pull Mode' );
update_option( 'presshub_social_worker_mode', 'pull' );
assert_false( PressHub_Social_TikTok_Shorts::should_split( 880, 'api' ), 'Shorts are not made for the TikTok API engine' );

// Enqueue schedules the background preparation; the claim never prepares a short itself.
$GLOBALS['SCHEDULED_EVENTS'] = [];
PressHub_Social_Queue::reset_memory_store();
$claim_ids = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
$prep_events = array_values( array_filter( $GLOBALS['SCHEDULED_EVENTS'], fn( $e ) => PressHub_Social_TikTok_Shorts::PREPARE_HOOK === $e['hook'] ) );
assert_equals( [ 880 ], $prep_events[0]['args'] ?? null, 'Enqueuing shorts schedules their background preparation' );
foreach ( $claim_ids as $cid ) {
    PressHub_Social_Queue::update_job( $cid, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
}
$req_short = new WP_REST_Request( 'POST', '/worker/claim' );
$req_short->set_param( 'sessions', [ 'tiktok' => true ] );
$req_short->set_param( 'limit', 5 );
$res_unready = PressHub_Social_REST_Controller::handle_claim( $req_short );
assert_equals( 0, count( $res_unready['jobs'] ), 'Unprepared short jobs are not claimed' );
assert_equals( 'pending', PressHub_Social_Queue::get_job( $claim_ids[0] )['status'], 'An unprepared short job stays pending (never stuck in processing)' );
assert_equals( 0, count( $shorts_ai_calls ) + count( $shorts_tts ) + count( $shorts_images ), 'The claim request makes no LLM, TTS or image call' );

// The new live count must not change how many stories the queued jobs get.
update_option( 'presshub_social_tiktok_shorts_count', 1 );
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
update_option( 'presshub_social_tiktok_shorts_count', 3 );
assert_equals( 1, count( $shorts_ai_calls ), 'Preparation generates the plan with one LLM call' );
assert_true( str_contains( $shorts_ai_calls[0], 'Ο κατώτατος μισθός αυξάνεται.' ), 'The shorts prompt carries the podcast script' );
assert_true( ! str_contains( $shorts_ai_calls[0], '{max_words}' ) && str_contains( $shorts_ai_calls[0], '130' ), 'The shorts prompt placeholders are filled' );
assert_equals( 3, count( $shorts_tts ), 'Preparation synthesizes every queued short, using the count the jobs were queued with' );
assert_true( PressHub_Social_TikTok_Shorts::is_ready( PressHub_Social_Queue::get_job( $claim_ids[2] ) ), 'Prepared short jobs are ready' );
assert_equals( 6, count( $shorts_images ), 'Preparation asks for each short\'s image descriptions (2 per short here)' );
assert_true( 1 === count( array_filter( $shorts_images, fn( $p ) => str_starts_with( $p, 'Limassol harbour at dawn 1 ' ) && str_contains( $p, 'Vertical 9:16' ) ) ), 'Image prompts start from the plan\'s description and ask for a vertical frame' );
assert_true( str_contains( $shorts_ai_calls[0], '3 περιγραφές εικόνων' ), 'The shorts prompt asks for the configured number of images' );
$third_meta = json_decode( (string) PressHub_Social_Queue::get_job( $claim_ids[2] )['metadata'], true )['tiktok_short'];
assert_equals( 1, count( $third_meta['images'] ), 'A failed story image is skipped; the short keeps the images that worked' );
assert_equals( 2, count( $third_meta['audio']['segments'] ?? [] ), 'The short stores its turn timings with its audio' );
assert_true( is_array( get_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, true ) ), 'The plan is stored on the podcast post' );

$res_short = PressHub_Social_REST_Controller::handle_claim( $req_short );
assert_equals( 3, count( $res_short['jobs'] ), 'All three prepared short jobs are claimed' );
assert_equals( 1, count( $shorts_ai_calls ), 'Claiming prepared shorts makes no LLM call' );
assert_equals( 3, count( $shorts_tts ), 'Claiming prepared shorts makes no TTS call' );
assert_equals( 6, count( $shorts_images ), 'Claiming prepared shorts makes no image call' );
$claimed_by_index = [];
foreach ( $res_short['jobs'] as $cj ) {
    $claimed_by_index[ (int) $cj['tiktok_short']['index'] ] = $cj;
}
assert_equals( 'https://example.com/uploads/short-2.wav', $claimed_by_index[1]['audio_url'] ?? '', 'A short job carries its own audio, not the full podcast' );
assert_equals( 'Hook 2 σε λίγες λέξεις', $claimed_by_index[1]['video_title'] ?? '', 'The second short job shows the second hook' );
assert_equals( [ 'https://example.com/uploads/short-img-3.png', 'https://example.com/uploads/short-img-4.png' ], $claimed_by_index[1]['tiktok_short']['images'] ?? null, 'A claimed short carries its own story images' );
assert_equals( 1.6, $claimed_by_index[1]['tiktok_short']['segments'][1]['start'] ?? null, 'A claimed short carries its turn timings for the captions' );
assert_true( str_starts_with( (string) $claimed_by_index[1]['post_copy'], 'Λεζάντα 2' ), 'The placeholder copy is replaced by the short caption' );
assert_true( str_starts_with( (string) PressHub_Social_Queue::get_job( $claimed_by_index[1]['id'] )['post_copy'], 'Λεζάντα 2' ), 'The short caption is saved on the job' );
assert_equals( 0, count( PressHub_Social_REST_Controller::handle_claim( $req_short )['jobs'] ), 'A second overlapping claim gets none of the already-claimed jobs' );
assert_false( PressHub_Social_Queue::claim_for_processing( (int) $claimed_by_index[0]['id'] ), 'claim_for_processing refuses a job that is no longer pending' );

// A short prepared before turn timings and images existed is prepared again.
$old_style = PressHub_Social_Queue::get_job( $claimed_by_index[2]['id'] );
$old_meta  = json_decode( (string) $old_style['metadata'], true );
unset( $old_meta['tiktok_short']['audio']['segments'], $old_meta['tiktok_short']['images'] );
$old_style['metadata'] = wp_json_encode( $old_meta );
assert_false( PressHub_Social_TikTok_Shorts::is_ready( $old_style ), 'A short prepared without timings or images is not ready' );

// An editor's copy is kept.
PressHub_Social_Queue::update_job_copy( $claimed_by_index[2]['id'], 'Δική μου λεζάντα' );
$edited_short = PressHub_Social_TikTok_Shorts::attach_short( PressHub_Social_Queue::get_job( $claimed_by_index[2]['id'] ) );
assert_equals( 'Δική μου λεζάντα', $edited_short['post_copy'] ?? '', 'An edited short copy is not overwritten by the generated caption' );

// Reposting a short that still shows its placeholder uses its generated caption.
PressHub_Social_Queue::update_job_copy( $claimed_by_index[0]['id'], PressHub_Social_TikTok_Shorts::placeholder_copy( 0, 3 ) );
$repost_id = PressHub_Social_Queue::repost_as_new_job( (int) $claimed_by_index[0]['id'] );
assert_true( str_starts_with( (string) PressHub_Social_Queue::get_job( $repost_id )['post_copy'], 'Λεζάντα 1' ), 'Reposting a short uses its generated caption, not the placeholder' );

// A short job is never dispatched outside Pull Mode.
update_option( 'presshub_social_worker_mode', 'push' );
$push_short = PressHub_Social_Dispatcher::dispatch_job( $repost_id );
assert_true( is_wp_error( $push_short ) && 'tiktok_short_needs_pull' === $push_short->get_error_code(), 'Dispatching a short outside Pull Mode fails loudly' );
update_option( 'presshub_social_worker_mode', 'pull' );

// The plan has fewer stories than jobs: the surplus job is cancelled, not failed.
update_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
PressHub_Social_TikTok_Shorts::set_ai_caller( fn() => $short_json( 2 ) );
PressHub_Social_Queue::reset_memory_store();
$surplus_ids = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
assert_equals( 'cancelled', PressHub_Social_Queue::get_job( $surplus_ids[2] )['status'], 'A short missing from the plan is cancelled' );
assert_true( PressHub_Social_TikTok_Shorts::is_ready( PressHub_Social_Queue::get_job( $surplus_ids[1] ) ), 'The shorts the plan covers are prepared' );
PressHub_Social_Queue::update_job( $surplus_ids[1], [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
$surplus_claim = PressHub_Social_REST_Controller::handle_claim( $req_short );
assert_equals( 2, (int) ( $surplus_claim['jobs'][0]['tiktok_short']['count'] ?? 0 ), 'The badge total is the number of stories the plan has, not the jobs queued' );

// A failed preparation is retried in the background without moving any posting time.
update_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
PressHub_Social_TikTok_Shorts::set_ai_caller( fn() => 'Συγγνώμη, δεν μπορώ.' );
PressHub_Social_Queue::reset_memory_store();
$fail_ids       = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
$fail_slot      = PressHub_Social_Queue::get_job( $fail_ids[2] )['scheduled_at'];
$GLOBALS['SCHEDULED_EVENTS'] = [];
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
$fail_job = PressHub_Social_Queue::get_job( $fail_ids[2] );
assert_true( str_contains( (string) $fail_job['last_error'], 'TikTok short failed' ), 'The invalid plan is recorded as the job error' );
assert_equals( 'pending', $fail_job['status'], 'A job whose preparation failed stays pending' );
assert_equals( $fail_slot, $fail_job['scheduled_at'], 'A failed preparation does not move the posting time of a later short' );
assert_equals( 0, (int) $fail_job['attempts'], 'A failed preparation does not use the posting retry budget' );
$retry_events = array_values( array_filter( $GLOBALS['SCHEDULED_EVENTS'], fn( $e ) => PressHub_Social_TikTok_Shorts::PREPARE_HOOK === $e['hook'] ) );
assert_true( ! empty( $retry_events ) && $retry_events[0]['time'] >= time() + PressHub_Social_Settings_Storage::get_retry_base_delay() - 5, 'The preparation is rescheduled with the queue backoff' );
for ( $run = 2; $run <= PressHub_Social_Settings_Storage::get_max_attempts(); $run++ ) {
    PressHub_Social_TikTok_Shorts::prepare_post( 880 );
}
assert_equals( 'failed', PressHub_Social_Queue::get_job( $fail_ids[0] )['status'], 'After the max attempts the unprepared shorts fail permanently' );

// Unprepared shorts never starve ready jobs queued behind them in the claim.
update_option( 'presshub_social_tiktok_shorts_count', 5 );
update_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
PressHub_Social_Queue::reset_memory_store();
foreach ( PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] ) as $sid ) {
    PressHub_Social_Queue::update_job( $sid, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ] );
}
$ready_tw = PressHub_Social_Queue::enqueue( [ 'post_id' => 501, 'platform' => 'twitter', 'engine' => 'automation', 'post_copy' => 'Έτοιμο tweet.', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ] );
$req_mixed = new WP_REST_Request( 'POST', '/worker/claim' );
$req_mixed->set_param( 'sessions', [ 'tiktok' => true, 'twitter' => true ] );
$req_mixed->set_param( 'limit', 5 );
$res_mixed = PressHub_Social_REST_Controller::handle_claim( $req_mixed );
assert_equals( [ $ready_tw ], array_map( fn( $j ) => (int) $j['id'], $res_mixed['jobs'] ), 'A ready job behind five unprepared shorts is still claimed' );
update_option( 'presshub_social_tiktok_shorts_count', 3 );

// Validation of the plan.
assert_true( is_wp_error( PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"topic":"a","hook":"b","script":"[SPEAKER_1]: Α\n[SPEAKER_3]: Β","caption":"c"}]}', 3 ) ), 'parse_plan rejects a [SPEAKER_3] line' );
assert_true( is_wp_error( PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"topic":"a","hook":"b","script":"[SPEAKER_1]: ' . str_repeat( 'λέξη ', 200 ) . '","caption":"c"}]}', 3, 100 ) ), 'parse_plan rejects a script far over the word cap' );
assert_true( is_wp_error( PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":["not an object"]}', 3 ) ), 'parse_plan rejects a short that is not an object' );
$url_plan = PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"topic":"a","hook":"b","script":"[SPEAKER_1]: Α","caption":"Δες https://presshub.cy/x #κύπρος"}]}', 3 );
assert_equals( 'Δες #κύπρος', $url_plan[0]['caption'] ?? '', 'parse_plan strips URLs from the caption' );
$GLOBALS['POSTS_STORE'][880]['post_title'] = 'Τίτλος με {script}';
$no_reexpand = PressHub_Social_TikTok_Shorts::build_prompt( 880, 'T={title} S={script}', 'ΣΕΝΑΡΙΟ', 3, 130 );
assert_equals( 'T=Τίτλος με {script} S=ΣΕΝΑΡΙΟ', $no_reexpand, 'build_prompt never re-expands a placeholder inside a value' );

// An empty prompt fails loudly instead of using hidden text.
update_option( 'presshub_social_prompt_tiktok_shorts', '' );
$no_prompt = PressHub_Social_TikTok_Shorts::get_plan( 880, 3 );
assert_true( is_wp_error( $no_prompt ) && 'missing_tiktok_shorts_prompt' === $no_prompt->get_error_code(), 'An empty shorts prompt fails loudly' );
delete_option( 'presshub_social_prompt_tiktok_shorts' );

update_option( 'presshub_social_tiktok_shorts_enabled', false );
PressHub_Social_TikTok_Shorts::set_ai_caller( null );
PressHub_Social_TikTok_Shorts::set_synthesizer( null );
PressHub_Social_TikTok_Shorts::set_image_generator( null );
PressHub_Social_AI_Copywriter::set_ai_caller( null );

// ----------------------------------------------------------------------
// Social Logger audit(): regression for TypeError (issue #139)
// ----------------------------------------------------------------------
require_once $base_dir . '/includes/class-secret-masker.php';
require_once $base_dir . '/includes/class-audit-logger.php';
global $wpdb;
$wpdb->tables['wp_presshub_ai_audit_logs'] = [];
$audit_error = '';
try {
    PressHub_Social_Logger::audit( 'social_job_enqueued', 'social_job', 77, 'Job enqueued', [ 'platform' => 'twitter', 'api_key' => 'sk-proj-abcdefghijklmnop123456' ] );
} catch ( \Throwable $e ) {
    $audit_error = get_class( $e ) . ': ' . $e->getMessage();
}
assert_equals( '', $audit_error, 'PressHub_Social_Logger::audit() does not throw' );
$audit_rows = $wpdb->tables['wp_presshub_ai_audit_logs'] ?? [];
assert_equals( 1, count( $audit_rows ), 'audit() writes exactly one audit row' );
if ( 1 === count( $audit_rows ) ) {
    assert_equals( 'social_job_enqueued', $audit_rows[0]['event_type'], 'audit row event_type' );
    assert_equals( '77', $audit_rows[0]['entity_id'], 'audit row entity_id cast to string' );
    $audit_details = json_decode( (string) $audit_rows[0]['details'], true );
    assert_equals( 'Job enqueued', $audit_details['summary'] ?? null, 'audit row carries summary' );
    assert_true( false === strpos( (string) $audit_rows[0]['details'], 'abcdefghijklmnop123456' ), 'audit row masks secrets in changes' );
}

// ----------------------------------------------------------------------
// Test Summary
// ----------------------------------------------------------------------
echo "\n=================================================================\n";
echo "Summary: {$tests_passed}/{$tests_run} passed (" . round( ( $tests_passed / max( 1, $tests_run ) ) * 100, 1 ) . "% pass rate)\n";
echo "=================================================================\n";

if ( ! empty( $failures ) ) {
    echo "\nFailures:\n";
    foreach ( $failures as $f ) {
        echo " - {$f}\n";
    }
    exit( 1 );
}

echo "\nALL TESTS PASSED SUCCESSFULLY (100%)\n";
exit( 0 );
