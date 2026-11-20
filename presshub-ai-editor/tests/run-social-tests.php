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
    $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options', 'edit_post', 'edit_others_posts' ];
} else {
    $GLOBALS['CURRENT_USER_CAPS'][] = 'edit_posts';
    $GLOBALS['CURRENT_USER_CAPS'][] = 'manage_options';
$GLOBALS['CURRENT_USER_CAPS'][] = 'edit_others_posts';
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
$GLOBALS['test_user_caps'] = [ 'edit_posts' => true, 'manage_options' => true, 'edit_others_posts' => true ];

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
require_once $base_dir . '/includes/class-social-image-library.php';
require_once $base_dir . '/includes/class-social-post-images.php';
require_once $base_dir . '/includes/class-social-tiktok-shorts.php';
require_once $base_dir . '/includes/class-social-tiktok-music.php';
require_once $base_dir . '/includes/class-podcast-timeline.php';
require_once $base_dir . '/includes/class-podcast-translations.php';
require_once $base_dir . '/includes/class-social-youtube.php';
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
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options', 'edit_post', 'edit_others_posts' ];

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

// Hashtags: every AI copy carries them, even when the model leaves them out.
PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $config ) {
    return "Μεγάλη είδηση για την Κύπρο.\n\nΔιαβάστε: https://presshub.ai/story";
} );
$tagless_fb = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Big Story', 'url' => 'https://presshub.ai/story', 'hashtags' => '#Κύπρος #Οικονομία #Συντάξεις' ], 'facebook' );
assert_true( is_string( $tagless_fb ) && str_ends_with( $tagless_fb, "\n\n#Κύπρος #Οικονομία #Συντάξεις" ), 'AI copy without hashtags gets the post hashtags appended' );
$tagless_x = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Big Story', 'url' => 'https://presshub.ai/story', 'hashtags' => '#Κύπρος #Οικονομία #Συντάξεις' ], 'twitter' );
assert_true( is_string( $tagless_x ) && str_contains( $tagless_x, '#Κύπρος #Οικονομία' ) && ! str_contains( $tagless_x, '#Συντάξεις' ), 'X/Twitter copy without hashtags gets the first two post hashtags' );
PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $config ) {
    return "Είδηση.\n\n#cyprus #news";
} );
$tagged = PressHub_Social_AI_Copywriter::generate_from_data( [ 'title' => 'Big Story', 'url' => 'https://presshub.ai/story', 'hashtags' => '#Κύπρος' ], 'instagram' );
assert_equals( "Είδηση.\n\n#cyprus #news", $tagged, 'AI copy that has hashtags is left as written' );
assert_true( PressHub_Social_AI_Copywriter::has_hashtag( 'Νέα #κύπρος' ), 'has_hashtag finds a Greek hashtag' );
assert_false( PressHub_Social_AI_Copywriter::has_hashtag( 'https://presshub.cy/a#section C# &#8217; #2026' ), 'has_hashtag ignores URL fragments, C#, HTML entities and bare numbers' );
assert_equals( 'Κείμενο', PressHub_Social_AI_Copywriter::ensure_hashtags( 'Κείμενο', '' ), 'ensure_hashtags adds nothing when the post has no hashtags' );
$tagless_short = PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"hook":"a","script":"[SPEAKER_1]: Α","caption":"Λεζάντα χωρίς hashtags"}]}', 1 );
assert_equals( "Λεζάντα χωρίς hashtags\n\n#κύπρος #cyprus #ειδήσεις", $tagless_short[0]['caption'] ?? '', 'A short caption without hashtags gets #κύπρος #cyprus #ειδήσεις' );

// A speech/image model picked as the social copy provider falls back to a text provider
assert_true( ! PressHub_Social_AI_Copywriter::is_text_model( 'gemini-3.8-flash-tts' ), 'is_text_model: TTS model is not a text model' );
assert_true( ! PressHub_Social_AI_Copywriter::is_text_model( 'gemini-3.1-flash-lite-image' ), 'is_text_model: image model is not a text model' );
assert_true( ! PressHub_Social_AI_Copywriter::is_text_model( 'models/imagen-4.0-generate-001' ), 'is_text_model: Imagen is not a text model' );
assert_true( ! PressHub_Social_AI_Copywriter::is_text_model( 'lyria-3-clip-preview' ), 'is_text_model: Lyria is not a text model' );
assert_true( PressHub_Social_AI_Copywriter::is_text_model( 'gemini-3.8-flash' ), 'is_text_model: Gemini Flash is a text model' );
assert_true( PressHub_Social_AI_Copywriter::is_text_model( 'deepseek-v4-flash' ), 'is_text_model: DeepSeek is a text model' );
$saved_providers = get_option( 'presshub_ai_configured_providers', [] );
update_option( 'presshub_ai_configured_providers', [
    [ 'id' => 'gemini-speech', 'type' => 'gemini', 'name' => 'Gemini Speech', 'api_key' => 'k-speech-1234567890', 'default_model' => 'gemini-3.8-flash-tts', 'enabled' => true ],
    [ 'id' => 'gemini-main', 'type' => 'gemini', 'name' => 'Gemini', 'api_key' => 'k-main-1234567890', 'default_model' => 'gemini-3.1-flash-lite', 'enabled' => true ],
] );
update_option( 'presshub_social_ai_provider', 'gemini-speech' );
$tts_cfg = PressHub_Social_AI_Copywriter::resolve_ai_provider_config();
assert_true( is_array( $tts_cfg ) && 'gemini-main' === ( $tts_cfg['id'] ?? '' ), 'TTS provider chosen for social copy falls back to the first text provider' );
assert_true( is_array( $tts_cfg ) && 'gemini-3.1-flash-lite' === ( $tts_cfg['model'] ?? '' ), 'Fallback uses the text provider model, not the TTS model' );
update_option( 'presshub_social_ai_provider', '' );
$auto_cfg = PressHub_Social_AI_Copywriter::resolve_ai_provider_config();
assert_true( is_array( $auto_cfg ) && 'gemini-main' === ( $auto_cfg['id'] ?? '' ), 'Automatic provider skips a TTS provider listed first' );
update_option( 'presshub_social_ai_provider', 'gemini-main' );
$main_cfg = PressHub_Social_AI_Copywriter::resolve_ai_provider_config();
assert_true( is_array( $main_cfg ) && 'gemini-main' === ( $main_cfg['id'] ?? '' ), 'A text provider chosen for social copy is used as is' );
update_option( 'presshub_social_ai_provider', '' );
update_option( 'presshub_ai_configured_providers', $saved_providers );

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

// #237: the second GitHub token (presshub_social_github_token) is gone;
// plugin updates read presshub_ai_github_token only.
assert_true( ! method_exists( 'PressHub_Social_Settings_Storage', 'get_github_token' ), 'Social storage no longer reads a second GitHub token' );

// ----------------------------------------------------------------------
// Test Suite 16: Settings Render (GitHub Token Field & AI Provider Dropdown Filtering)
// ----------------------------------------------------------------------
echo "\n--- Suite 16: Settings Render & Configured AI Provider Filtering ---\n";
$renderer = new PressHub_Social_Settings_Render();


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

// #309 (E1): the platforms list is the only switch; a left-over per-platform row is not read.
update_option( 'presshub_social_enabled_facebook', false );
assert_true( PressHub_Social_Settings_Storage::is_platform_enabled( 'facebook' ), 'A left-over presshub_social_enabled_facebook row does not disable facebook' );
delete_option( 'presshub_social_enabled_facebook' );
PressHub_Social_Settings_Storage::set_platform_enabled( 'facebook', false );
assert_false( PressHub_Social_Settings_Storage::is_platform_enabled( 'facebook' ), 'set_platform_enabled(facebook, false) disables facebook' );
assert_false( in_array( 'facebook', PressHub_Social_Settings_Storage::get_enabled_platforms(), true ), 'get_enabled_platforms excludes disabled facebook' );
assert_false( array_key_exists( 'presshub_social_enabled_facebook', $GLOBALS['OPTIONS_STORE'] ?? [] ), 'set_platform_enabled() writes no per-platform option' );

// 6. Platform readiness checks
// Twitter: enabled=true, configured=true
// TikTok: enabled=true, configured=true
// Facebook: enabled=false, configured=true
// Instagram: enabled=true, configured=false
delete_option( 'presshub_social_instagram_account_id' );
delete_option( 'presshub_social_meta_access_token' );
PressHub_Social_Settings_Storage::set_platform_enabled( 'instagram', true );

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
assert_equals( 90, PressHub_Social_Settings_Storage::get_tiktok_shorts_spacing_minutes(), 'Shorts spacing defaults to 90 minutes (#189)' );
assert_equals( 130, PressHub_Social_Settings_Storage::get_tiktok_shorts_max_words(), 'Short script word cap defaults to 130' );
assert_equals( 5, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_count( 12 ), 'Shorts count clamps to 5' );
assert_equals( 1, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_count( 0 ), 'Shorts count clamps to 1' );
assert_equals( 720, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_spacing_minutes( 5000 ), 'Shorts spacing clamps to 720 minutes' );
assert_equals( 60, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_max_words( 10 ), 'Short word cap clamps to 60' );
assert_equals( 3, PressHub_Social_Settings_Storage::get_tiktok_shorts_images(), 'Story images per short default to 3' );
assert_equals( 4, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_images( 9 ), 'Story images per short clamp to 4' );
assert_equals( 0, PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_images( -1 ), 'Story images per short clamp to 0' );

// #189: a new podcast cancels the earlier edition's unposted shorts.
PressHub_Social_Queue::reset_memory_store();
$sup_ids = [];
foreach ( PressHub_Social_TikTok_Shorts::build_jobs( 722, 'automation', null, null, time() - 60 ) as $sup_job ) {
    $sup_ids[] = PressHub_Social_Queue::enqueue( $sup_job );
}
$sup_test  = PressHub_Social_TikTok_Shorts::build_jobs( 700, 'automation', null, null, time(), true )[0];
$sup_test_id = PressHub_Social_Queue::enqueue( $sup_test );
$sup_plain = PressHub_Social_Queue::enqueue( [ 'post_id' => 722, 'platform' => 'tiktok', 'engine' => 'automation', 'post_copy' => 'Full podcast' ] );
PressHub_Social_Queue::mark_processing( (int) $sup_ids[0] );
$sup_cancelled = PressHub_Social_TikTok_Shorts::cancel_superseded( 730 );
assert_equals( [ (int) $sup_ids[2], (int) $sup_ids[1] ], $sup_cancelled, 'A newer podcast cancels the pending shorts of the earlier one (#189)' );
assert_equals( 'processing', PressHub_Social_Queue::get_job( (int) $sup_ids[0] )['status'], 'A short the worker already claimed is left alone (#189)' );
assert_equals( 'pending', PressHub_Social_Queue::get_job( (int) $sup_test_id )['status'], 'Test-render shorts are never cancelled (#189)' );
assert_equals( 'pending', PressHub_Social_Queue::get_job( (int) $sup_plain )['status'], 'Non-short TikTok jobs are never cancelled (#189)' );
assert_equals( [], PressHub_Social_TikTok_Shorts::cancel_superseded( 722 ), 'A post never cancels its own shorts (#189)' );
add_filter( 'presshub_social_tiktok_shorts_cancel_superseded', '__return_false' );
PressHub_Social_Queue::reset_memory_store();
$sup_off = PressHub_Social_Queue::enqueue( PressHub_Social_TikTok_Shorts::build_jobs( 722, 'automation', null, null, time() )[0] );
assert_equals( [], PressHub_Social_TikTok_Shorts::cancel_superseded( 730 ), 'The filter turns superseded-short cancelling off (#189)' );
remove_all_filters( 'presshub_social_tiktok_shorts_cancel_superseded' );
PressHub_Social_Queue::reset_memory_store();
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
update_option( 'presshub_social_tiktok_shorts_narrators', 2 );
$timings = PressHub_Social_TikTok_Shorts::turn_timings(
    [ [ 'speaker' => 'female', 'text' => 'Πρώτη ατάκα.' ], [ 'speaker' => 'male', 'text' => 'Δεύτερη ατάκα.' ] ],
    [ $pcm_1s, $wav_1s5 ],
    400,
    false
);
assert_equals( [ 1, 0.0, 1.0 ], [ $timings[0]['speaker'], $timings[0]['start'], $timings[0]['end'] ], 'The first turn starts at 0 and lasts its audio length' );
assert_equals( [ 2, 1.4, 2.9 ], [ $timings[1]['speaker'], $timings[1]['start'], $timings[1]['end'] ], 'The next turn starts after the stitched pause' );
delete_option( 'presshub_social_tiktok_shorts_narrators' );

// The voice dropdown lists every Gemini voice, whatever is selected.
ob_start();
( new PressHub_Social_Settings_Render() )->render_tiktok_shorts_voice_field( [ 'option' => 'presshub_social_tiktok_shorts_voice_1', 'narrator' => 1 ] );
$voice_html = ob_get_clean();
assert_equals( count( PressHub_Social_Settings_Storage::gemini_tts_voices() ) + 1, substr_count( $voice_html, '<option ' ), 'The narrator voice dropdown lists all Gemini voices plus the briefing voice' );
assert_true( 30 === count( PressHub_Social_Settings_Storage::gemini_tts_voices() ), 'All 30 Gemini TTS voices are offered' );
assert_true( false !== strpos( $voice_html, '<select' ) && preg_match( '/value="Puck"\s+selected/', $voice_html ) === 1, 'The dropdown selects the saved voice (Puck by default)' );
update_option( 'presshub_social_tiktok_shorts_voice_1', 'NewVoice' );
ob_start();
( new PressHub_Social_Settings_Render() )->render_tiktok_shorts_voice_field( [ 'option' => 'presshub_social_tiktok_shorts_voice_1', 'narrator' => 1 ] );
assert_true( false !== strpos( ob_get_clean(), 'value="NewVoice" selected' ), 'A saved voice missing from the list is kept and selected' );
delete_option( 'presshub_social_tiktok_shorts_voice_1' );

// Narrator settings.
assert_equals( 1, PressHub_Social_Settings_Storage::get_tiktok_shorts_narrators(), 'Shorts default to a single narrator' );
assert_equals( 'Puck', PressHub_Social_Settings_Storage::get_tiktok_shorts_voice( 1 ), 'The single narrator defaults to an upbeat Gemini voice' );
assert_equals( '', PressHub_Social_Settings_Storage::get_tiktok_shorts_voice( 2 ), 'The second voice defaults to the briefing presenter 2 voice' );
assert_equals( 110, PressHub_Social_Settings_Storage::get_tiktok_shorts_voice_speed(), 'Shorts default to a brisk 110% speed' );
assert_equals( [ 1, 2, 2 ], [ PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_narrators( 0 ), PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_narrators( 2 ), PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_narrators( 5 ) ], 'Narrators are clamped to 1-2' );
assert_equals( [ 70, 150 ], [ PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_voice_speed( 10 ), PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_voice_speed( 400 ) ], 'Voice speed is clamped to 70-150%' );
assert_equals( 'el-GR-Chirp3-HD-Achird', PressHub_Social_Settings_Storage::sanitize_tiktok_shorts_voice( ' el-GR-Chirp3-HD-Achird; ' ), 'Voice names keep letters, digits and dashes only' );
assert_true( strlen( PressHub_Social_Settings_Storage::get_tiktok_shorts_tts_style() ) > 15, 'The narration style seeds an energetic instruction long enough to be sent as is' );
assert_true( isset( PressHub_Social_Settings_Storage::gemini_tts_voices()['Puck'] ), 'The Gemini voice list offers Puck' );
assert_true( PressHub_Social_TikTok_Shorts::voice_fits_engine( 'Puck', 'gemini' ) && ! PressHub_Social_TikTok_Shorts::voice_fits_engine( 'Puck', 'google_cloud' ), 'A Gemini voice fits only the Gemini engine' );
assert_true( PressHub_Social_TikTok_Shorts::voice_fits_engine( 'el-GR-Wavenet-A', 'google_cloud' ) && ! PressHub_Social_TikTok_Shorts::voice_fits_engine( 'el-GR-Wavenet-A', 'gemini' ), 'A Cloud voice fits only the Google Cloud engine' );
$two_voice_turns = [ [ 'speaker' => 'female', 'text' => 'Ένα.' ], [ 'speaker' => 'male', 'text' => 'Δύο.' ], [ 'speaker' => 'male', 'text' => 'Τρία.' ], [ 'speaker' => 'female', 'text' => ' ' ] ];
$merged_one = PressHub_Social_TikTok_Shorts::merge_turns( $two_voice_turns, 1 );
assert_equals( [ 1, 'Ένα. Δύο. Τρία.' ], [ count( $merged_one ), $merged_one[0]['text'] ], 'A single narrator reads the whole script in one turn' );
$merged_two = PressHub_Social_TikTok_Shorts::merge_turns( $two_voice_turns, 2 );
assert_equals( [ 'Ένα.', 'Δύο. Τρία.' ], array_column( $merged_two, 'text' ), 'With two narrators only consecutive lines of the same voice are joined' );
$single_timings = PressHub_Social_TikTok_Shorts::turn_timings( [ [ 'speaker' => 'male', 'text' => 'Δύο.' ] ], [ $pcm_1s ], 400, false );
assert_equals( 1, $single_timings[0]['speaker'], 'With one narrator every caption segment is narrator 1' );
$one_prompt = PressHub_Social_TikTok_Shorts::build_prompt( 0, '{speakers}', '', 1, 100 );
assert_true( false !== strpos( $one_prompt, '[SPEAKER_1] μόνο' ) && false === strpos( $one_prompt, '[SPEAKER_2]' ), 'The script prompt asks for a single narrator by default' );
update_option( 'presshub_social_tiktok_shorts_narrators', 2 );
assert_true( false !== strpos( PressHub_Social_TikTok_Shorts::build_prompt( 0, '{speakers}', '', 1, 100 ), '[SPEAKER_2]' ), 'With 2 narrators the script prompt asks for two alternating voices' );
delete_option( 'presshub_social_tiktok_shorts_narrators' );
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
// Job #224: the model closed the caption with a stray `]` (`"caption":"…"]}]}`).
$misplaced = PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"topic":"a","hook":"b","script":"[SPEAKER_1]: Α [σημείωση]","visuals":["x"],"mood":"neutral","caption":"Λεζάντα #κύπρος"]}]}', 1 );
assert_true( is_array( $misplaced ) && 'b' === ( $misplaced[0]['hook'] ?? '' ), 'parse_plan repairs a misplaced closing bracket' );
assert_true( str_contains( $misplaced[0]['script'] ?? '', '[σημείωση]' ), 'The repair leaves brackets inside strings alone' );
assert_equals( '{"a":[1,2]}', PressHub_Social_AI_Json::repair( '{"a":[1,2,]}' ), 'repair_json drops a trailing comma' );
$fixes = [];
assert_equals( '{"a":{"b":"}]"}', PressHub_Social_AI_Json::repair( '{"a":{"b":"}]"}', $fixes ), 'repair_json leaves a cut-off response (brackets still open) unchanged' );
assert_equals( [], $fixes, 'Nothing is reported as repaired in a cut-off response' );
assert_equals( '{"a":"x\\"}"}', PressHub_Social_AI_Json::repair( '{"a":"x\\"}"}' ), 'repair_json handles escaped quotes' );
assert_equals( '{"a":"x\\\\"}', PressHub_Social_AI_Json::repair( '{"a":"x\\\\"]}' ), 'An escaped backslash before the closing quote ends the string' );
assert_equals( '{"a":"cut', PressHub_Social_AI_Json::repair( '{"a":"cut' ), 'repair_json leaves a response cut off inside a string unchanged' );
PressHub_Social_AI_Json::repair( '{"a":[1,2,]]}', $fixes );
assert_equals( [ 'dropped 1 misplaced ]', 'dropped 1 trailing comma' ], $fixes, 'repair_json reports what it dropped' );
// A 3-short answer cut off inside the third short is not turned into a 2-short plan.
$three = json_decode( $short_json( 3 ), true );
$cut   = substr( $short_json( 3 ), 0, (int) strpos( $short_json( 3 ), $three['shorts'][2]['caption'] ) );
assert_true( is_wp_error( PressHub_Social_TikTok_Shorts::parse_plan( $cut, 3 ) ), 'A cut-off plan is rejected, not repaired into fewer shorts' );
$broken = PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"hook":"b" "script":"x"}]}', 1 );
assert_true( is_wp_error( $broken ) && str_contains( $broken->get_error_message(), 'not valid JSON' ), 'Broken JSON is reported as invalid JSON, not as a missing "shorts" list' );

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
assert_equals( 90 * 60, strtotime( $short_jobs[1]['scheduled_at'] ) - strtotime( $short_jobs[0]['scheduled_at'] ), 'Shorts are scheduled 90 minutes apart by default (#189)' );
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
assert_true( 1 === count( array_filter( $shorts_images, fn( $p ) => str_starts_with( $p, 'Limassol harbour at dawn 1 ' ) && str_contains( $p, '9:16' ) ) ), 'Image prompts start from the plan\'s description and ask for a vertical frame' );
assert_true( str_contains( $shorts_images[0], 'editorial illustration' ), 'A neutral story (the default mood) gets the illustrated style' );
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

// Test render: real preparation, claimed with test_render, saved by the worker, never posted.
update_option( 'presshub_social_tiktok_shorts_enabled', false );
PressHub_Social_Queue::reset_memory_store();
$test_ids = PressHub_Social_TikTok_Shorts::enqueue_test_render( 880 );
assert_true( is_array( $test_ids ) && 3 === count( $test_ids ), 'A test render queues one job per short, even with shorts off' );
$test_jobs = array_map( fn( $id ) => PressHub_Social_Queue::get_job( $id ), $test_ids );
assert_equals( $test_jobs[0]['scheduled_at'], $test_jobs[2]['scheduled_at'], 'Test render jobs are all due now, not spaced out' );
assert_true( PressHub_Social_TikTok_Shorts::is_test_job( $test_jobs[1] ), 'Test render jobs are flagged as tests' );
assert_true( is_wp_error( PressHub_Social_TikTok_Shorts::enqueue_test_render( 99999 ) ), 'A test render needs a podcast post or an article with text' );
update_option( 'presshub_social_worker_mode', 'push' );
assert_true( is_wp_error( PressHub_Social_TikTok_Shorts::enqueue_test_render( 880 ) ), 'A test render needs Pull Mode' );
update_option( 'presshub_social_worker_mode', 'pull' );
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
$test_claim = PressHub_Social_REST_Controller::handle_claim( $req_short );
assert_equals( 3, count( $test_claim['jobs'] ), 'Prepared test shorts are claimed' );
assert_true( ! empty( $test_claim['jobs'][0]['test_render'] ) && ! empty( $test_claim['jobs'][0]['tiktok_short']['segments'] ), 'A claimed test short carries test_render and the real timings' );
$req_test_done = new WP_REST_Request( 'POST', '/worker/complete' );
$req_test_done->set_param( 'job_id', $test_ids[0] );
$req_test_done->set_param( 'status', 'test_rendered' );
$req_test_done->set_param( 'metadata', [ 'test_render_file' => 'C:\\presshub\\output\\tiktok-short-tests\\post-880-short-1.mp4' ] );
$res_test_done = PressHub_Social_REST_Controller::handle_complete( $req_test_done );
$test_done_job = PressHub_Social_Queue::get_job( $test_ids[0] );
assert_equals( 'cancelled', $test_done_job['status'], 'A finished test render ends as cancelled (never posted)' );
assert_true( str_contains( (string) $test_done_job['last_error'], 'post-880-short-1.mp4' ), 'The test render note names the saved video' );
$req_test_pub = new WP_REST_Request( 'POST', '/worker/complete' );
$req_test_pub->set_param( 'job_id', $test_ids[1] );
$req_test_pub->set_param( 'status', 'published' );
PressHub_Social_REST_Controller::handle_complete( $req_test_pub );
assert_true( 'published' !== PressHub_Social_Queue::get_job( $test_ids[1] )['status'], 'A test render reported as published is not recorded as published' );
$plain_job = PressHub_Social_Queue::enqueue( [ 'post_id' => 501, 'platform' => 'twitter', 'engine' => 'automation', 'post_copy' => 'Tweet.' ] );
$req_bad_test = new WP_REST_Request( 'POST', '/worker/complete' );
$req_bad_test->set_param( 'job_id', $plain_job );
$req_bad_test->set_param( 'status', 'test_rendered' );
assert_true( is_wp_error( PressHub_Social_REST_Controller::handle_complete( $req_bad_test ) ), 'Only a test short can be completed as test_rendered' );
update_option( 'presshub_social_tiktok_shorts_enabled', true );

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

// An unusable response is asked for once more in the same run before it fails.
update_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
$retry_calls = 0;
$retry_prompts = [];
PressHub_Social_TikTok_Shorts::set_ai_caller( function ( string $sys, string $user ) use ( &$retry_calls, &$retry_prompts, $short_json ) {
    $retry_calls++;
    $retry_prompts[] = $user;
    return 1 === $retry_calls ? '{"shorts":"oops"}' : $short_json( 3 );
} );
PressHub_Social_Queue::reset_memory_store();
$retry_ids = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
assert_equals( 2, $retry_calls, 'An invalid plan response is retried once in the same run' );
assert_true( str_contains( $retry_prompts[1] ?? '', 'Your previous answer was unusable: the AI response has no "shorts" list.' ) && ! str_contains( $retry_prompts[0], 'previous answer' ), 'The retry tells the AI why its first answer was unusable' );

// An empty answer is retried like an unusable one.
update_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
$empty_calls = 0;
PressHub_Social_TikTok_Shorts::set_ai_caller( function () use ( &$empty_calls, $short_json ) {
    $empty_calls++;
    return 1 === $empty_calls ? '  ' : $short_json( 3 );
} );
PressHub_Social_Queue::reset_memory_store();
$empty_ids = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
assert_equals( 2, $empty_calls, 'An empty plan response is retried once in the same run' );
assert_true( PressHub_Social_TikTok_Shorts::is_ready( PressHub_Social_Queue::get_job( $empty_ids[0] ) ), 'The shorts are prepared after an empty first answer' );
assert_true( PressHub_Social_TikTok_Shorts::is_ready( PressHub_Social_Queue::get_job( $retry_ids[0] ) ), 'The shorts are prepared from the second response' );


// End to end: the records and Usage & logs rows of an unusable answer are marked as errors,
// a repaired one is noted, and the job error names every record of the run.
require_once $base_dir . '/includes/class-token-logger.php';
if ( ! function_exists( 'presshub_test_plan_call' ) ) {
    function presshub_test_plan_call( string $kind, string $action, $response ): array {
        $record = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => $kind, 'status' => 'success', 'response_text' => is_string( $response ) ? $response : '' ] );
        $log    = (int) PressHub_AI_Token_Logger::log_llm_request( $action, 'gemini', 'gemini-test', 100, 50, 10, 'success', null, [], 1 );
        return [ 'response' => $response, 'record_id' => $record, 'log_id' => $log ];
    }
    function presshub_test_token_row( int $id ): array {
        foreach ( $GLOBALS['wpdb']->tables[ PressHub_AI_Token_Logger::get_table_name() ] ?? [] as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $id ) {
                return $row;
            }
        }
        return [];
    }
}
$GLOBALS['wpdb']->tables[ PressHub_AI_Generation_Recorder::get_table_name() ] = $GLOBALS['wpdb']->tables[ PressHub_AI_Generation_Recorder::get_table_name() ] ?? [];
PressHub_AI_Generation_Recorder::create_table();
PressHub_AI_Token_Logger::create_table();

update_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
$e2e_calls = [];
PressHub_Social_TikTok_Shorts::set_ai_caller( function () use ( &$e2e_calls, $short_json ) {
    $answer      = 0 === count( $e2e_calls ) ? '{"shorts":"oops"}' : substr( $short_json( 3 ), 0, -1 ) . ']}';
    $call        = presshub_test_plan_call( 'tiktok_short_script', 'social_tiktok_shorts', $answer );
    $e2e_calls[] = $call;
    return $call;
} );
PressHub_Social_Queue::reset_memory_store();
$e2e_ids = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
$e2e_first  = PressHub_AI_Generation_Recorder::get( $e2e_calls[0]['record_id'] ?? 0 );
$e2e_second = PressHub_AI_Generation_Recorder::get( $e2e_calls[1]['record_id'] ?? 0 );
assert_true( 'error' === ( $e2e_first['status'] ?? '' ) && str_starts_with( (string) ( $e2e_first['error_message'] ?? '' ), 'Response not usable: ' ), 'Shorts: the unusable answer\'s record is marked error with the reason' );
assert_true( 'error' === ( presshub_test_token_row( $e2e_calls[0]['log_id'] )['status'] ?? '' ), 'Shorts: the unusable answer\'s Usage & logs row is marked error' );
assert_true( 'success' === ( $e2e_second['status'] ?? '' ) && 'JSON repaired: dropped 1 misplaced ].' === ( $e2e_second['error_message'] ?? '' ), 'Shorts: the repaired answer\'s record stays success with a note' );
assert_equals( 'success', presshub_test_token_row( $e2e_calls[1]['log_id'] )['status'] ?? '', 'Shorts: the repaired answer\'s Usage & logs row stays success' );
assert_true( PressHub_Social_TikTok_Shorts::is_ready( PressHub_Social_Queue::get_job( $e2e_ids[0] ) ), 'Shorts: the jobs are prepared from the repaired answer' );
// Two unusable answers: the job error names both records.
update_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
$e2e_bad = [];
PressHub_Social_TikTok_Shorts::set_ai_caller( function () use ( &$e2e_bad ) {
    $call      = presshub_test_plan_call( 'tiktok_short_script', 'social_tiktok_shorts', '{"shorts":"oops"}' );
    $e2e_bad[] = $call['record_id'];
    return $call;
} );
PressHub_Social_Queue::reset_memory_store();
$e2e_bad_ids = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
assert_true( str_ends_with( (string) PressHub_Social_Queue::get_job( $e2e_bad_ids[0] )['last_error'], sprintf( 'See generation records #%d, #%d.', $e2e_bad[0], $e2e_bad[1] ) ), 'Shorts: the job error names every record of the run' );
delete_post_meta( 880, PressHub_Social_TikTok_Shorts::PREP_ATTEMPTS_META );

// A failed preparation is retried in the background without moving any posting time.
update_post_meta( 880, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
$fail_calls = 0;
PressHub_Social_TikTok_Shorts::set_ai_caller( function () use ( &$fail_calls ) {
    $fail_calls++;
    return 'Συγγνώμη, δεν μπορώ.';
} );
PressHub_Social_Queue::reset_memory_store();
$fail_ids       = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'tiktok' ] );
$fail_slot      = PressHub_Social_Queue::get_job( $fail_ids[2] )['scheduled_at'];
$GLOBALS['SCHEDULED_EVENTS'] = [];
PressHub_Social_TikTok_Shorts::prepare_post( 880 );
$fail_job = PressHub_Social_Queue::get_job( $fail_ids[2] );
assert_true( str_contains( (string) $fail_job['last_error'], 'TikTok short failed' ), 'The invalid plan is recorded as the job error' );
assert_equals( PressHub_Social_TikTok_Shorts::PLAN_ATTEMPTS, $fail_calls, 'A run asks the AI at most PLAN_ATTEMPTS times' );
assert_equals( 'pending', $fail_job['status'], 'A job whose preparation failed stays pending' );
assert_equals( $fail_slot, $fail_job['scheduled_at'], 'A failed preparation does not move the posting time of a later short' );
assert_equals( 0, (int) $fail_job['attempts'], 'A failed preparation does not use the posting retry budget' );
$retry_events = array_values( array_filter( $GLOBALS['SCHEDULED_EVENTS'], fn( $e ) => PressHub_Social_TikTok_Shorts::PREPARE_HOOK === $e['hook'] ) );
assert_true( ! empty( $retry_events ) && $retry_events[0]['time'] >= time() + PressHub_Social_Settings_Storage::get_retry_base_delay() - 5, 'The preparation is rescheduled with the queue backoff' );
for ( $run = 2; $run <= PressHub_Social_Settings_Storage::get_max_attempts(); $run++ ) {
    PressHub_Social_TikTok_Shorts::prepare_post( 880 );
}
assert_equals( 'failed', PressHub_Social_Queue::get_job( $fail_ids[0] )['status'], 'After the max attempts the unprepared shorts fail permanently' );

// Article shorts: every other post goes to TikTok (and Instagram Reels) as one short.
echo "\n--- Article Shorts ---\n";
if ( ! function_exists( 'get_the_category' ) ) {
    function get_the_category( $post_id = 0 ) {
        return $GLOBALS['test_categories'][ (int) $post_id ] ?? [];
    }
}
$GLOBALS['POSTS_STORE'][890] = [
    'ID'           => 890,
    'post_title'   => 'Διασπαστικά βήματα μέσα στον αθλητισμό',
    'post_content' => '<p>Ο αθλητισμός στην Κύπρο περνά κρίση εμπιστοσύνης.</p>[gallery ids="1,2"]<p>Οι ομοσπονδίες &amp; οι σύλλογοι διαφωνούν.</p>',
    'post_excerpt' => 'Ένα άρθρο γνώμης για τον αθλητισμό.',
    'post_status'  => 'publish',
];
$GLOBALS['test_posts'][890]      = new WP_Post( $GLOBALS['POSTS_STORE'][890] );
$GLOBALS['test_categories'][890] = [ (object) [ 'name' => 'Άποψη', 'slug' => 'apopsi' ] ];
assert_true( PressHub_Social_Settings_Storage::get_tiktok_article_shorts_enabled(), 'Article shorts are on by default' );
assert_equals( 'article', PressHub_Social_TikTok_Shorts::source_type( 890 ), 'A post with text that is not a podcast is an article source' );
assert_equals( 'podcast', PressHub_Social_TikTok_Shorts::source_type( 880 ), 'A podcast post with a script is a podcast source' );
$art_text = PressHub_Social_TikTok_Shorts::article_text( 890 );
assert_true( str_contains( $art_text, 'κρίση εμπιστοσύνης.' ) && str_contains( $art_text, 'ομοσπονδίες & οι' ) && ! str_contains( $art_text, '<p>' ), 'The article text has no markup and decoded entities' );
assert_equals( 'ΑΠΟΨΗ', PressHub_Social_TikTok_Shorts::article_badge( 890 ), 'The badge is the category in capitals without accents' );
assert_equals( 'ΑΡΘΡΟ', PressHub_Social_TikTok_Shorts::article_badge( 99999 ), 'A post without a category gets the ΑΡΘΡΟ badge' );
$GLOBALS['test_categories'][891] = [ (object) [ 'name' => 'Uncategorized', 'slug' => 'uncategorized' ], (object) [ 'name' => 'Άποψη', 'slug' => 'apopsi' ] ];
assert_equals( 'Άποψη', PressHub_Social_TikTok_Shorts::primary_category( 891 ), 'The primary category skips Uncategorized' );
$GLOBALS['POSTS_STORE'][891] = [ 'ID' => 891, 'post_title' => 'Τίτλος', 'post_content' => 'Κείμενο', 'post_excerpt' => '', 'post_status' => 'publish' ];
$GLOBALS['test_posts'][891]  = new WP_Post( $GLOBALS['POSTS_STORE'][891] );
assert_true( str_contains( PressHub_Social_TikTok_Shorts::build_prompt( 891, 'Κατηγορία: {category}', '', 1, 100, 3, 'Κείμενο' ), 'Κατηγορία: Άποψη' ), 'The article prompt gets the same primary category as the badge' );
assert_equals( 'ΑΠΟΨΗ', PressHub_Social_TikTok_Shorts::article_badge( 891 ), 'The badge uses the same primary category as the prompt' );
$GLOBALS['test_categories'][892] = [ (object) [ 'name' => 'Uncategorized', 'slug' => 'uncategorized' ] ];
assert_equals( '', PressHub_Social_TikTok_Shorts::primary_category( 892 ), 'A post filed only under Uncategorized has no primary category' );
assert_equals( 'ΑΡΘΡΟ', PressHub_Social_TikTok_Shorts::article_badge( 892 ), 'A post filed only under Uncategorized gets the ΑΡΘΡΟ badge' );
$GLOBALS['test_categories'][892] = [ (object) [ 'name' => 'Χωρίς κατηγορία', 'slug' => 'xoris-katigoria', 'term_id' => 1 ], (object) [ 'name' => 'Πολιτική', 'slug' => 'politiki', 'term_id' => 7 ] ];
assert_equals( 'Πολιτική', PressHub_Social_TikTok_Shorts::primary_category( 892 ), 'The Greek Uncategorized (Χωρίς κατηγορία) is skipped' );
update_option( 'default_category', 55 );
$GLOBALS['test_categories'][892] = [ (object) [ 'name' => 'Γενικά', 'slug' => 'genika', 'term_id' => 55 ], (object) [ 'name' => 'Πολιτική', 'slug' => 'politiki', 'term_id' => 7 ] ];
assert_equals( 'Πολιτική', PressHub_Social_TikTok_Shorts::primary_category( 892 ), 'The site\'s default category, even renamed, is skipped' );
update_option( 'default_category', 0 );
$GLOBALS['test_categories'][892] = [ 'not a term', (object) [ 'name' => '' ], [ 'name' => 'Κοινωνία', 'slug' => 'koinonia' ] ];
assert_equals( 'Κοινωνία', PressHub_Social_TikTok_Shorts::primary_category( 892 ), 'Invalid or nameless terms are skipped' );
$GLOBALS['POSTS_STORE'][893] = [ 'ID' => 893, 'post_title' => 'Κενό', 'post_content' => "<p>&nbsp;</p>\n<p>\u{00A0}&nbsp;</p>", 'post_excerpt' => '', 'post_status' => 'draft' ];
$GLOBALS['test_posts'][893]  = new WP_Post( $GLOBALS['POSTS_STORE'][893] );
assert_equals( '', PressHub_Social_TikTok_Shorts::source_type( 893 ), 'A post of empty &nbsp; paragraphs has no text for a short' );

// A test render of a draft does not stop the post going out when it is published.
PressHub_Social_Queue::reset_memory_store();
PressHub_Social_Queue::enqueue( [ 'post_id' => 891, 'platform' => 'tiktok', 'engine' => 'automation', 'post_copy' => 'Test', 'metadata' => wp_json_encode( [ 'tiktok_short' => [ 'index' => 0, 'count' => 1, 'source' => 'article', 'test' => true ] ] ) ] );
assert_false( PressHub_Social_Dispatcher::has_publish_jobs( 891 ), 'A test render is not a social job of the post' );
PressHub_Social_Queue::enqueue( [ 'post_id' => 891, 'platform' => 'twitter', 'engine' => 'automation', 'post_copy' => 'Tweet' ] );
assert_true( PressHub_Social_Dispatcher::has_publish_jobs( 891 ), 'A real job is a social job of the post' );
PressHub_Social_Queue::reset_memory_store();

$art_ai_calls = [];
PressHub_Social_TikTok_Shorts::set_ai_caller( function ( string $sys, string $user ) use ( &$art_ai_calls, $short_json ) {
    $art_ai_calls[] = $user;
    return $short_json( 3 );
} );
PressHub_Social_AI_Copywriter::set_ai_caller( fn() => 'Λεζάντα άρθρου TikTok #κύπρος #αθλητισμός' );

// Article shorts off: the classic single TikTok post.
update_option( 'presshub_social_tiktok_article_shorts_enabled', false );
PressHub_Social_Queue::reset_memory_store();
$classic_ids = PressHub_Social_Dispatcher::enqueue_for_post( 890, [ 'tiktok' ] );
assert_equals( 1, count( $classic_ids ), 'With article shorts off an article gets one TikTok job' );
assert_false( PressHub_Social_TikTok_Shorts::is_short_job( PressHub_Social_Queue::get_job( $classic_ids[0] ) ), 'With article shorts off the article job is not a short' );
update_option( 'presshub_social_tiktok_article_shorts_enabled', true );

// Article shorts on: one short job, with the usual AI caption as its post text.
PressHub_Social_Queue::reset_memory_store();
$art_ids = PressHub_Social_Dispatcher::enqueue_for_post( 890, [ 'tiktok' ] );
assert_equals( 1, count( $art_ids ), 'An article gets ONE TikTok short job, not Shorts per Podcast' );
$art_job = PressHub_Social_Queue::get_job( $art_ids[0] );
assert_true( PressHub_Social_TikTok_Shorts::is_article_job( $art_job ), 'The article job is an article short' );
assert_equals( 'Λεζάντα άρθρου TikTok #κύπρος #αθλητισμός', $art_job['post_copy'], 'The article short keeps the TikTok caption prompt\'s copy' );
assert_equals( 0, count( $art_ai_calls ), 'No shorts LLM call at enqueue time for an article' );

// Instagram Reels get the article short too, when Instagram Reels are on.
update_option( 'presshub_social_instagram_shorts_enabled', true );
assert_true( PressHub_Social_TikTok_Shorts::should_split( 890, 'automation', 'instagram' ), 'An article becomes an Instagram Reel when Instagram Reels are on' );
$ig_art = PressHub_Social_TikTok_Shorts::build_jobs( 890, 'automation', null, null, time() - 60, false, 'instagram', 'Λεζάντα Instagram' );
assert_equals( 1, count( $ig_art ), 'An article gets one Instagram Reel job' );
assert_equals( 'Λεζάντα Instagram', $ig_art[0]['post_copy'], 'The Instagram Reel of an article keeps its Instagram caption' );
PressHub_Social_Queue::enqueue( $ig_art[0] );
update_option( 'presshub_social_instagram_shorts_enabled', false );
assert_false( PressHub_Social_TikTok_Shorts::should_split( 890, 'automation', 'instagram' ), 'No Instagram Reel for an article when Instagram Reels are off' );
assert_false( PressHub_Social_TikTok_Shorts::should_split( 890, 'api' ), 'Article shorts need the automation engine' );

// A new podcast never cancels an article short.
assert_equals( [], PressHub_Social_TikTok_Shorts::cancel_superseded( 880 ), 'A podcast does not cancel pending article shorts' );

// Preparation: the plan comes from the article text, with one story.
$GLOBALS['SCHEDULED_EVENTS'] = [];
PressHub_Social_TikTok_Shorts::prepare_post( 890 );
assert_equals( 1, count( $art_ai_calls ), 'The article short plan takes one LLM call (shared by TikTok and Instagram)' );
assert_true( str_contains( $art_ai_calls[0], 'κρίση εμπιστοσύνης' ) && str_contains( $art_ai_calls[0], 'Κατηγορία: Άποψη' ) && ! str_contains( $art_ai_calls[0], '{content}' ), 'The article short prompt carries the article text and category' );
$art_ready = PressHub_Social_Queue::get_job( $art_ids[0] );
assert_true( PressHub_Social_TikTok_Shorts::is_ready( $art_ready ), 'The article short is prepared' );
$plan_890 = get_post_meta( 890, PressHub_Social_TikTok_Shorts::PLAN_META, true );
assert_equals( 1, count( $plan_890['shorts'] ?? [] ), 'The article plan keeps one story' );
foreach ( PressHub_Social_Queue::get_jobs_for_post( 890 ) as $aj ) {
    PressHub_Social_Queue::update_job( (int) $aj['id'], [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
}
$req_art = new WP_REST_Request( 'POST', '/worker/claim' );
$req_art->set_param( 'sessions', [ 'tiktok' => true, 'instagram' => true ] );
$req_art->set_param( 'limit', 5 );
$art_claim = PressHub_Social_REST_Controller::handle_claim( $req_art );
$art_claimed = array_values( array_filter( $art_claim['jobs'], fn( $j ) => 'tiktok' === $j['platform'] ) )[0] ?? [];
assert_equals( 'ΑΠΟΨΗ', $art_claimed['tiktok_short']['badge'] ?? '', 'A claimed article short carries its category badge' );
assert_equals( 'article', $art_claimed['tiktok_short']['source'] ?? '', 'A claimed article short says it is an article short' );
assert_equals( 'Λεζάντα άρθρου TikTok #κύπρος #αθλητισμός', $art_claimed['post_copy'] ?? '', 'A claimed article short posts the TikTok caption, not the plan caption' );
assert_true( '' !== (string) ( $art_claimed['audio_url'] ?? '' ) && ! empty( $art_claimed['tiktok_short']['segments'] ), 'A claimed article short carries its narration and timings' );
assert_equals( 2, count( $art_claim['jobs'] ), 'The TikTok short and the Instagram Reel of the article are both claimed' );

// The worker cannot render the article short: it retries, then posts the article the classic way.
$art_tt_id  = (int) $art_claimed['id'];
$art_ig_id  = (int) ( array_values( array_filter( $art_claim['jobs'], fn( $j ) => 'instagram' === $j['platform'] ) )[0]['id'] ?? 0 );
$max_att    = (int) PressHub_Social_Queue::get_job( $art_tt_id )['max_attempts'];
$req_rf     = new WP_REST_Request( 'POST', '/worker/complete' );
$req_rf->set_param( 'job_id', $art_tt_id );
$req_rf->set_param( 'status', 'failed' );
$req_rf->set_param( 'error', 'FFmpeg exited with code 1' );
$req_rf->set_param( 'metadata', [ 'short_render_failed' => true ] );
$rf1 = PressHub_Social_REST_Controller::handle_complete( $req_rf );
assert_equals( 'failed', $rf1['status'], 'A render failure before the last attempt is a normal failure' );
assert_true( PressHub_Social_TikTok_Shorts::is_article_job( PressHub_Social_Queue::get_job( $art_tt_id ) ), 'Before the last attempt the job is still the article short' );
PressHub_Social_Queue::update_job( $art_tt_id, [ 'status' => 'processing', 'attempts' => $max_att ] );
$rf2    = PressHub_Social_REST_Controller::handle_complete( $req_rf );
$rf_job = PressHub_Social_Queue::get_job( $art_tt_id );
$rf_meta = json_decode( (string) $rf_job['metadata'], true );
assert_equals( 'pending', $rf2['status'], 'A render failure on the last attempt falls back' );
assert_equals( 'pending', $rf_job['status'], 'The fallen-back job is pending again' );
assert_equals( 0, (int) $rf_job['attempts'], 'The classic post gets fresh attempts' );
assert_false( PressHub_Social_TikTok_Shorts::is_short_job( $rf_job ), 'The fallen-back job is no longer a short' );
assert_equals( 'tiktok_short_render_failed', $rf_meta['tiktok_short_fallback']['error_code'] ?? '', 'The fallback records the render failure' );
assert_equals( 'Λεζάντα άρθρου TikTok #κύπρος #αθλητισμός', $rf_job['post_copy'], 'The classic post keeps the TikTok caption' );
// A failure after the render (posting) never falls back: the short was made.
PressHub_Social_Queue::update_job( $art_ig_id, [ 'status' => 'processing', 'attempts' => $max_att ] );
$req_pf = new WP_REST_Request( 'POST', '/worker/complete' );
$req_pf->set_param( 'job_id', $art_ig_id );
$req_pf->set_param( 'status', 'failed' );
$req_pf->set_param( 'error', 'Instagram upload timed out' );
PressHub_Social_REST_Controller::handle_complete( $req_pf );
$pf_job = PressHub_Social_Queue::get_job( $art_ig_id );
assert_equals( 'failed', $pf_job['status'], 'A posting failure on the last attempt fails for good' );
assert_true( PressHub_Social_TikTok_Shorts::is_article_job( $pf_job ), 'A posting failure keeps the article short' );
$art_meta = wp_json_encode( [ 'tiktok_short' => [ 'index' => 0, 'count' => 1, 'source' => 'article' ] ] );
assert_false( PressHub_Social_TikTok_Shorts::fallback_after_render_failure( [ 'id' => 9001, 'post_id' => 890, 'platform' => 'youtube', 'attempts' => 3, 'max_attempts' => 3, 'metadata' => $art_meta ], 'x' ), 'A YouTube article short never falls back' );
assert_false( PressHub_Social_TikTok_Shorts::fallback_after_render_failure( [ 'id' => 9002, 'post_id' => 890, 'platform' => 'tiktok', 'attempts' => 3, 'max_attempts' => 3, 'metadata' => wp_json_encode( [ 'tiktok_short' => [ 'index' => 0, 'count' => 1, 'source' => 'article', 'test' => true ] ] ) ], 'x' ), 'A test render never falls back' );
assert_false( PressHub_Social_TikTok_Shorts::fallback_after_render_failure( [ 'id' => 9003, 'post_id' => 880, 'platform' => 'tiktok', 'attempts' => 3, 'max_attempts' => 3, 'metadata' => wp_json_encode( [ 'tiktok_short' => [ 'index' => 0, 'count' => 3 ] ] ) ], 'x' ), 'A podcast short never falls back' );

// Editing the article's title or category without its text makes a new plan.
$calls_before = count( $art_ai_calls );
PressHub_Social_TikTok_Shorts::get_plan( 890, 1 );
assert_equals( $calls_before, count( $art_ai_calls ), 'An unchanged article reuses its plan' );
$GLOBALS['test_categories'][890] = [ (object) [ 'name' => 'Πολιτική', 'slug' => 'politiki' ] ];
PressHub_Social_TikTok_Shorts::get_plan( 890, 1 );
assert_equals( $calls_before + 1, count( $art_ai_calls ), 'A new category makes a new article plan' );
$GLOBALS['test_categories'][890] = [ (object) [ 'name' => 'Άποψη', 'slug' => 'apopsi' ] ];

// ----------------------------------------------------------------------
// YouTube: Shorts and podcast episodes through the YouTube Data API
// ----------------------------------------------------------------------
echo "\n--- YouTube ---\n";
$yt_http       = [];
$yt_no_channel = false;
PressHub_Social_YouTube::set_http( function ( string $method, string $url, array $args ) use ( &$yt_http, &$yt_no_channel ) {
    $yt_http[] = [ $method, $url, $args ];
    if ( str_contains( $url, 'oauth2.googleapis.com/token' ) ) {
        if ( 'refresh_token' === ( $args['body']['grant_type'] ?? '' ) && 'REVOKED' === ( $args['body']['refresh_token'] ?? '' ) ) {
            return [ 'code' => 400, 'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}' ];
        }
        if ( 'authorization_code' === ( $args['body']['grant_type'] ?? '' ) ) {
            return [ 'code' => 200, 'body' => '{"access_token":"ya29.FIRST","expires_in":3599,"refresh_token":"1//REFRESH"}' ];
        }
        return [ 'code' => 200, 'body' => '{"access_token":"ya29.FRESH","expires_in":3599}' ];
    }
    if ( str_contains( $url, '/youtube/v3/channels?part=status' ) ) {
        $GLOBALS['yt_long_calls'] = ( $GLOBALS['yt_long_calls'] ?? 0 ) + 1;
        return [ 'code' => 200, 'body' => wp_json_encode( [ 'items' => [ [ 'status' => [ 'longUploadsStatus' => $GLOBALS['yt_long_status'] ?? 'allowed' ] ] ] ] ) ];
    }
    if ( str_contains( $url, '/youtube/v3/channels' ) ) {
        return $yt_no_channel
            ? [ 'code' => 200, 'body' => '{"items":[]}' ]
            : [ 'code' => 200, 'body' => '{"items":[{"id":"UC123","snippet":{"title":"PressHub Cyprus"}}]}' ];
    }
    if ( str_contains( $url, '/youtube/v3/playlists' ) ) {
        return str_contains( $url, 'PLpodcastplaylist01' )
            ? [ 'code' => 200, 'body' => '{"items":[{"id":"PLpodcastplaylist01","snippet":{"title":"Daily Briefing","channelId":"UC123"}}]}' ]
            : [ 'code' => 200, 'body' => '{"items":[]}' ];
    }
    if ( str_contains( $url, '/revoke' ) ) {
        return [ 'code' => 200, 'body' => '' ];
    }
    return [ 'code' => 404, 'body' => '' ];
} );

// Settings sanitizers.
assert_equals( 'PLabcdefghijklmnop', PressHub_Social_Settings_Storage::sanitize_youtube_podcast_playlist_id( 'https://www.youtube.com/playlist?list=PLabcdefghijklmnop' ), 'A pasted playlist link becomes its ID' );
assert_equals( 'PLabcdefghijklmnop', PressHub_Social_Settings_Storage::sanitize_youtube_podcast_playlist_id( ' PLabcdefghijklmnop ' ), 'A playlist ID is kept' );
assert_equals( '', PressHub_Social_Settings_Storage::sanitize_youtube_podcast_playlist_id( 'not a playlist <script>' ), 'Anything else is dropped' );
assert_equals( 'public', PressHub_Social_Settings_Storage::sanitize_youtube_privacy_status( 'everyone' ), 'An unknown privacy status becomes public' );
assert_equals( 'unlisted', PressHub_Social_Settings_Storage::sanitize_youtube_privacy_status( 'unlisted' ), 'Unlisted is kept' );
assert_equals( 25, PressHub_Social_Settings_Storage::sanitize_youtube_category_id( 999 ), 'An out-of-range category becomes News & Politics' );
assert_equals( 'automation', PressHub_Social_Settings_Storage::get_platform_engine( 'youtube' ), 'YouTube is always claimed by the worker' );
assert_false( in_array( 'youtube', PressHub_Social_Settings_Storage::DEFAULT_ENABLED_PLATFORMS, true ), 'YouTube is opt-in in the platform list' );

// Not connected: not configured, and the claim never hands out YouTube jobs.
update_option( 'presshub_social_worker_sessions', [ 'tiktok' => true, 'instagram' => true, 'youtube' => true ] );
delete_option( PressHub_Social_YouTube::REFRESH_TOKEN_OPTION );
update_option( 'presshub_social_youtube_client_id', '123-abc.apps.googleusercontent.com' );
update_option( 'presshub_social_youtube_client_secret', 'GOCSPX-secret' );
assert_false( PressHub_Social_YouTube::is_connected(), 'Without a sign-in YouTube is not connected' );
assert_false( PressHub_Social_Settings_Storage::is_platform_configured( 'youtube' ), 'Without a sign-in YouTube is not configured' );
assert_true( is_wp_error( PressHub_Social_YouTube::access_token() ), 'No access token without a sign-in' );

// Sign-in: a state started by another user (or none) is refused.
$GLOBALS['CURRENT_USER_ID'] = 7;
set_transient( PressHub_Social_YouTube::STATE_TRANSIENT_PREFIX . 'STATE1', 7, 900 );
$bad = PressHub_Social_YouTube::complete_sign_in( 'STATE1', 'CODE', '', 8 );
assert_equals( 'youtube_bad_state', is_wp_error( $bad ) ? $bad->get_error_code() : '', 'A sign-in returned to another user is refused' );
assert_false( get_transient( PressHub_Social_YouTube::STATE_TRANSIENT_PREFIX . 'STATE1' ), 'A state is used once' );
set_transient( PressHub_Social_YouTube::STATE_TRANSIENT_PREFIX . 'STATE2', 7, 900 );
$denied = PressHub_Social_YouTube::complete_sign_in( 'STATE2', '', 'access_denied', 7 );
assert_true( is_wp_error( $denied ) && '' === PressHub_Social_YouTube::get_refresh_token(), 'A declined consent stores nothing' );
$auth_url = PressHub_Social_YouTube::authorization_url( 'S' );
assert_true( str_contains( $auth_url, 'access_type=offline' ) && str_contains( $auth_url, 'prompt=consent' ) && str_contains( $auth_url, rawurlencode( PressHub_Social_YouTube::redirect_uri() ) ), 'The consent URL asks for offline access with the plugin\'s redirect URI' );

set_transient( PressHub_Social_YouTube::STATE_TRANSIENT_PREFIX . 'STATE3', 7, 900 );
$yt_http = [];
$chan = PressHub_Social_YouTube::complete_sign_in( 'STATE3', 'CODE', '', 7 );
assert_equals( 'PressHub Cyprus', is_array( $chan ) ? $chan['title'] : '', 'A sign-in reads the channel' );
assert_equals( '1//REFRESH', PressHub_Social_YouTube::get_refresh_token(), 'A sign-in stores the refresh token' );
assert_equals( 'UC123', PressHub_Social_YouTube::channel()['id'] ?? '', 'A sign-in stores the channel' );
assert_equals( 'authorization_code', $yt_http[0][2]['body']['grant_type'] ?? '', 'The code is swapped at the token endpoint' );
assert_equals( 'GOCSPX-secret', $yt_http[0][2]['body']['client_secret'] ?? '', 'The token request carries the client secret (server side only)' );
assert_true( ! empty( PressHub_Connection_Status::get( 'social:youtube' )['ok'] ), 'A sign-in records YouTube as connected' );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'youtube' ), 'Connected, in Pull mode, with a worker that reported YouTube: configured' );

// Access tokens: cached, then refreshed near expiry.
$yt_http = [];
$tok = PressHub_Social_YouTube::access_token();
assert_equals( 'ya29.FIRST', $tok['access_token'] ?? '', 'The token from the sign-in is reused' );
assert_equals( 0, count( $yt_http ), 'A cached token makes no request' );
set_transient( PressHub_Social_YouTube::ACCESS_TOKEN_TRANSIENT, [ 'access_token' => 'ya29.OLD', 'expires_at' => time() + 30 ], 30 );
$tok = PressHub_Social_YouTube::access_token();
assert_equals( 'ya29.FRESH', $tok['access_token'] ?? '', 'A token about to expire is refreshed' );
$rest_tok = PressHub_Social_REST_Controller::handle_youtube_token( new WP_REST_Request( 'POST', '/worker/youtube/token' ) );
assert_equals( 'ya29.FRESH', is_array( $rest_tok ) ? ( $rest_tok['access_token'] ?? '' ) : '', 'The worker gets an access token from the token route' );
assert_false( str_contains( wp_json_encode( $rest_tok ), '1//REFRESH' ) || str_contains( wp_json_encode( $rest_tok ), 'GOCSPX' ), 'The token route never returns the refresh token or client secret' );
set_transient( PressHub_Social_YouTube::ACCESS_TOKEN_TRANSIENT, [ 'access_token' => 'ya29.CACHED', 'expires_at' => time() + 3000 ], 3000 );
$rest_cached = PressHub_Social_REST_Controller::handle_youtube_token( new WP_REST_Request( 'POST', '/worker/youtube/token' ) );
assert_equals( 'ya29.CACHED', is_array( $rest_cached ) ? ( $rest_cached['access_token'] ?? '' ) : '', 'The token route hands out the cached token while it has time left' );
$fresh_req = new WP_REST_Request( 'POST', '/worker/youtube/token' );
$fresh_req->set_param( 'refresh', true );
$rest_fresh = PressHub_Social_REST_Controller::handle_youtube_token( $fresh_req );
assert_equals( 'ya29.FRESH', is_array( $rest_fresh ) ? ( $rest_fresh['access_token'] ?? '' ) : '', 'refresh=true (YouTube refused the cached token) gets a new one from Google' );

// Shorts: the same shorts as TikTok, as YouTube jobs.
PressHub_Social_Settings_Storage::set_platform_enabled( 'youtube', true );
update_option( 'presshub_social_youtube_shorts_enabled', true );
PressHub_Social_AI_Copywriter::set_ai_caller( fn() => 'Περιγραφή YouTube για το άρθρο. #κύπρος #αθλητισμός #Shorts' );
PressHub_Social_Queue::reset_memory_store();
$yt_art_ids = PressHub_Social_Dispatcher::enqueue_for_post( 890, [ 'youtube' ] );
assert_equals( 1, count( $yt_art_ids ), 'An article gets one YouTube short' );
$yt_art = PressHub_Social_Queue::get_job( $yt_art_ids[0] );
assert_equals( 'youtube', $yt_art['platform'], 'The short is a YouTube job' );
assert_true( PressHub_Social_TikTok_Shorts::is_article_job( $yt_art ), 'The YouTube short is an article short' );
assert_equals( 'Περιγραφή YouTube για το άρθρο. #κύπρος #αθλητισμός #Shorts', $yt_art['post_copy'], 'The article short keeps the YouTube description prompt\'s copy' );

update_option( 'presshub_social_youtube_shorts_enabled', false );
PressHub_Social_Queue::reset_memory_store();
assert_equals( [], PressHub_Social_Dispatcher::enqueue_for_post( 890, [ 'youtube' ] ), 'With YouTube Shorts off an article is not posted to YouTube' );

// Podcast: its shorts plus the full episode.
update_option( 'presshub_social_youtube_shorts_enabled', true );
update_option( 'presshub_social_youtube_podcast_enabled', true );
update_option( 'presshub_social_youtube_podcast_playlist_id', 'PLpodcastplaylist01' );
PressHub_Social_Queue::reset_memory_store();
$yt_pod_ids = PressHub_Social_Dispatcher::enqueue_for_post( 880, [ 'youtube' ] );
$yt_pod_jobs = array_map( [ 'PressHub_Social_Queue', 'get_job' ], $yt_pod_ids );
$yt_eps = array_values( array_filter( $yt_pod_jobs, [ 'PressHub_Social_YouTube', 'is_episode_job' ] ) );
assert_equals( 1, count( $yt_eps ), 'A podcast gets one YouTube episode job' );
assert_equals( PressHub_Social_Settings_Storage::get_tiktok_shorts_count(), count( $yt_pod_jobs ) - 1, 'A podcast also gets its YouTube shorts' );
assert_false( PressHub_Social_TikTok_Shorts::is_short_job( $yt_eps[0] ), 'The episode is not a short' );

// Claim: the episode gets its upload details.
foreach ( PressHub_Social_Queue::get_jobs_for_post( 880 ) as $pj ) {
    if ( 'youtube' !== $pj['platform'] || ! PressHub_Social_YouTube::is_episode_job( $pj ) ) {
        PressHub_Social_Queue::cancel_job( (int) $pj['id'] );
    }
}
PressHub_Social_Queue::update_job( (int) $yt_eps[0]['id'], [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );

delete_option( PressHub_Social_YouTube::REFRESH_TOKEN_OPTION );
$req_yt = new WP_REST_Request( 'POST', '/worker/claim' );
$req_yt->set_param( 'sessions', [ 'youtube' => true ] );
$req_yt->set_param( 'limit', 5 );
assert_equals( 0, count( PressHub_Social_REST_Controller::handle_claim( $req_yt )['jobs'] ), 'Without a connected channel YouTube jobs wait in the queue' );
assert_equals( 'pending', PressHub_Social_Queue::get_job( (int) $yt_eps[0]['id'] )['status'], 'The waiting episode stays pending' );

update_option( PressHub_Social_YouTube::REFRESH_TOKEN_OPTION, '1//REFRESH' );
$req_old = new WP_REST_Request( 'POST', '/worker/claim' );
$req_old->set_param( 'limit', 5 );
assert_equals( 0, count( array_filter( PressHub_Social_REST_Controller::handle_claim( $req_old )['jobs'], fn( $j ) => 'youtube' === $j['platform'] ) ), 'A worker that does not report YouTube never gets YouTube jobs' );
$yt_claim = PressHub_Social_REST_Controller::handle_claim( $req_yt );
$yt_ep    = $yt_claim['jobs'][0] ?? [];
assert_equals( 'episode', $yt_ep['youtube']['kind'] ?? '', 'The claimed episode is marked as an episode' );
assert_equals( 'PLpodcastplaylist01', $yt_ep['youtube']['playlist_id'] ?? '', 'The episode goes to the podcast playlist' );
assert_equals( 1920, $yt_ep['youtube']['width'] ?? 0, 'The episode is rendered 16:9' );
assert_equals( 'https://example.com/uploads/podcast-880.mp3', $yt_ep['audio_url'] ?? '', 'The episode carries the podcast audio' );
assert_true( true === ( $yt_ep['youtube']['contains_synthetic_media'] ?? null ) && false === ( $yt_ep['youtube']['made_for_kids'] ?? null ), 'The upload declares AI content and not made for kids' );
assert_false( isset( $yt_ep['access_token'] ) || str_contains( wp_json_encode( $yt_ep ), '1//REFRESH' ), 'The claim carries no token' );

// Upload metadata rules.
$long = str_repeat( 'Λέξη <b>με</b> ', 30 );
$t    = PressHub_Social_YouTube::clean_title( $long );
assert_true( mb_strlen( $t, 'UTF-8' ) <= 100 && ! str_contains( $t, '<' ) && ! str_contains( $t, '>' ), 'A title is at most 100 characters, without < or >' );
assert_true( strlen( PressHub_Social_YouTube::clean_description( str_repeat( 'αβγ ', 3000 ) ) ) <= 5000, 'A description is at most 5000 bytes' );
assert_equals( [ 'κύπρος', 'αθλητισμός' ], PressHub_Social_YouTube::tags_from( 'Κείμενο #κύπρος #αθλητισμός #Shorts #Κύπρος' ), 'Tags come from the hashtags, once each, without #Shorts' );
$yt_short = PressHub_Social_YouTube::attach_upload( [
    'id'           => 1,
    'post_id'      => 890,
    'platform'     => 'youtube',
    'post_copy'    => 'Λεζάντα #κύπρος',
    'video_title'  => 'Τι αλλάζει στον αθλητισμό',
    'post_url'     => 'https://example.com/?p=890',
    'tiktok_short' => [ 'index' => 0, 'count' => 1 ],
] );
assert_equals( 'short', $yt_short['youtube']['kind'] ?? '', 'A short is uploaded as a short' );
assert_equals( 'Τι αλλάζει στον αθλητισμό', $yt_short['youtube']['title'] ?? '', 'A short\'s title is its hook' );
assert_true( str_ends_with( $yt_short['youtube']['description'] ?? '', 'https://example.com/?p=890' ), 'The article link is added to the description' );
assert_false( isset( $yt_short['youtube']['playlist_id'] ), 'A short goes to no playlist' );
$no_audio = PressHub_Social_YouTube::attach_upload( [ 'id' => 2, 'post_id' => 880, 'platform' => 'youtube', 'metadata' => [ 'youtube_episode' => [ 'queued_at' => 'x' ] ] ] );
assert_equals( 'youtube_episode_no_audio', is_wp_error( $no_audio ) ? $no_audio->get_error_code() : '', 'An episode without audio fails' );
$plain = PressHub_Social_YouTube::attach_upload( [ 'id' => 3, 'post_id' => 890, 'platform' => 'youtube' ] );
assert_true( is_wp_error( $plain ), 'YouTube only gets videos' );

// Enhanced episodes: caption timeline, scenes from the shorts' images, chapters.
$wav = static function ( float $sec ): string {
    $pcm = str_repeat( "\x00", (int) round( $sec * 48000 ) );
    return 'RIFF' . pack( 'V', 36 + strlen( $pcm ) ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16 ) . 'data' . pack( 'V', strlen( $pcm ) ) . $pcm;
};
assert_equals( 2.5, PressHub_AI_Podcast_Timeline::turn_seconds( $wav( 2.5 ), 'x', false ), 'A WAV turn is measured from its samples' );
assert_equals( 2.0, PressHub_AI_Podcast_Timeline::turn_seconds( '', str_repeat( 'α', 28 ), true ), 'An MP3 turn is estimated from its text' );
$tl = PressHub_AI_Podcast_Timeline::build( [
    [ 'title' => 'Η Βουλή ψηφίζει τον προϋπολογισμό', 'turns' => [ [ 'speaker' => 'female', 'text' => 'Καλημέρα.', 'seconds' => 10.0 ], [ 'speaker' => 'male', 'text' => 'Καλημέρα σας.', 'seconds' => 5.0 ] ] ],
    [ 'title' => 'Καιρός', 'turns' => [ [ 'speaker' => 'male', 'text' => 'Βροχές.', 'seconds' => 12.0 ] ] ],
    [ 'title' => 'Ποδόσφαιρο: νίκη της Ομόνοιας', 'turns' => [ [ 'speaker' => 'female', 'text' => 'Τέλος.', 'seconds' => 20.0 ] ] ],
    [ 'title' => 'Κενό', 'turns' => [] ],
], [ 'turn_pause_ms' => 250, 'topic_pause_ms' => 600, 'transition_seconds' => 1.0, 'intro_seconds' => 3.0, 'outro_seconds' => 2.0, 'host_count' => 2 ] );
assert_equals( 3, count( $tl['topics'] ), 'A topic without turns is left out of the timeline' );
assert_equals( 3.6, $tl['segments'][0]['start'], 'The first turn starts after the intro sound and the topic pause' );
assert_equals( 13.85, $tl['segments'][1]['start'], 'Turns are separated by the turn pause' );
assert_equals( 2, $tl['segments'][1]['speaker'], 'The male host is speaker 2' );
assert_equals( 20.15, $tl['topics'][1]['start'], 'Topics are separated by 150 ms + transition sound + 150 ms' );
assert_equals( 1, $tl['segments'][2]['topic'], 'Each turn knows its topic' );
assert_equals( 56.05, $tl['duration'], 'The timeline ends after the topic pause and the outro sound' );
$tl_mp3 = PressHub_AI_Podcast_Timeline::build( [ [ 'title' => '', 'turns' => [ [ 'speaker' => 'male', 'text' => 'α', 'seconds' => 1 ] ] ] ], [ 'host_count' => 1, 'estimated' => true ] );
assert_true( $tl_mp3['estimated'] && 1 === $tl_mp3['segments'][0]['speaker'], 'With one host every turn is speaker 1; an MP3 timeline is marked estimated' );
assert_equals( "0:00 Η Βουλή ψηφίζει τον προϋπολογισμό\n0:20 Καιρός\n0:33 Ποδόσφαιρο: νίκη της Ομόνοιας", PressHub_AI_Podcast_Timeline::chapters( $tl ), 'Chapters start at 0:00 and follow the topics' );
assert_equals( '', PressHub_AI_Podcast_Timeline::chapters( [ 'duration' => 60, 'topics' => array_slice( $tl['topics'], 0, 2 ) ] ), 'Fewer than three topics give no chapters' );
assert_equals( '', PressHub_AI_Podcast_Timeline::chapters( [ 'duration' => 40, 'topics' => [ [ 'title' => 'A', 'start' => 0 ], [ 'title' => 'B', 'start' => 15 ], [ 'title' => 'C', 'start' => 20 ] ] ] ), 'When merging short chapters leaves fewer than three there are no chapters' );
assert_equals( "0:00 A\n0:15 B\n0:40 D", PressHub_AI_Podcast_Timeline::chapters( [ 'duration' => 60, 'topics' => [ [ 'title' => 'A', 'start' => 0 ], [ 'title' => 'B', 'start' => 15 ], [ 'title' => 'C', 'start' => 35 ], [ 'title' => 'D', 'start' => 40 ] ] ] ), 'A topic under 10 seconds is merged into the chapter before it' );
assert_equals( "0:00 B\n0:30 C\n0:50 D", PressHub_AI_Podcast_Timeline::chapters( [ 'duration' => 70, 'topics' => [ [ 'title' => 'A', 'start' => 0 ], [ 'title' => 'B', 'start' => 5 ], [ 'title' => 'C', 'start' => 30 ], [ 'title' => 'D', 'start' => 50 ] ] ] ), 'A short first topic gives 0:00 to the next' );
assert_equals( '1:02:05', PressHub_AI_Podcast_Timeline::stamp( 3725 ), 'Chapter times past an hour have hours' );

assert_true( PressHub_Social_YouTube::topic_overlap( 'Ποδόσφαιρο: νίκη της Ομόνοιας', 'Η Ομόνοια νίκησε στο ποδόσφαιρο' ) >= 0.5, 'Greek titles match across accents and inflections' );
assert_equals( 0.0, PressHub_Social_YouTube::topic_overlap( 'Καιρός', 'Ποδόσφαιρο' ), 'Unrelated titles do not match' );
$groups = [
    [ 'index' => 0, 'topic' => 'Νίκη της Ομόνοιας στο ποδόσφαιρο', 'urls' => [ 'https://img/a1.jpg', 'https://img/a2.jpg' ] ],
    [ 'index' => 1, 'topic' => 'Ο προϋπολογισμός στη Βουλή', 'urls' => [ 'https://img/b1.jpg' ] ],
    [ 'index' => 2, 'topic' => 'Τουρισμός', 'urls' => [ 'https://img/c1.jpg' ] ],
];
$scenes = PressHub_Social_YouTube::episode_scenes( $tl['topics'], $groups, 56.05 );
assert_equals( [ 0.0, 20.15, 33.45 ], array_column( $scenes, 'start' ), 'Scenes cover the audio from 0, one per topic' );
assert_equals( 56.05, $scenes[2]['end'], 'The last scene lasts to the end' );
assert_equals( [ 'https://img/b1.jpg' ], $scenes[0]['images'], 'A topic gets the images of the short about it' );
assert_equals( [ 'https://img/a1.jpg', 'https://img/a2.jpg' ], $scenes[2]['images'], 'The football topic gets the football short' );
assert_equals( [ 'https://img/c1.jpg' ], $scenes[1]['images'], 'A topic no short matches gets a short left over' );
$one = PressHub_Social_YouTube::episode_scenes( [], $groups, 30.0 );
assert_true( 1 === count( $one ) && 4 === count( $one[0]['images'] ), 'Without topics one scene has every image' );

$desc = PressHub_Social_YouTube::with_chapters( str_repeat( 'α', 3000 ), "0:00 Α\n1:00 Β\n2:00 Γ" );
assert_true( strlen( $desc ) <= PressHub_Social_YouTube::DESCRIPTION_MAX_BYTES && str_ends_with( $desc, "2:00 Γ" ), 'Chapters are kept at the end of a long description' );
$desc2 = PressHub_Social_YouTube::with_chapters( str_repeat( 'α', 3000 ) . "\n\n#κύπρος #ειδήσεις\n\nhttps://example.com/?p=1", "0:00 Α\n1:00 Β\n2:00 Γ" );
assert_true( strlen( $desc2 ) <= PressHub_Social_YouTube::DESCRIPTION_MAX_BYTES && str_ends_with( $desc2, "2:00 Γ\n\n#κύπρος #ειδήσεις\n\nhttps://example.com/?p=1" ), 'The link and hashtags stay whole after the chapters' );

// The episode waits for the shorts' images, then is uploaded with them.
$ep_id  = (int) $yt_eps[0]['id'];
$sh_id  = PressHub_Social_Queue::enqueue( [ 'post_id' => 880, 'platform' => 'tiktok', 'engine' => 'automation', 'post_copy' => 'x', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'metadata' => [ 'tiktok_short' => [ 'index' => 0, 'count' => 2 ] ] ] );
$ep_job = PressHub_Social_Queue::get_job( $ep_id );
$ep_job['metadata'] = [ 'youtube_episode' => [ 'queued_at' => gmdate( 'c' ) ] ];
assert_false( PressHub_Social_YouTube::episode_ready( $ep_job ), 'An episode waits while a short has no images yet' );
assert_true( PressHub_Social_YouTube::episode_ready( $ep_job, time() + 90 * 60 + 1 ), 'After the wait the episode goes without them' );
assert_true( null !== PressHub_Social_YouTube::episode_hold( $ep_job ) && 300 === PressHub_Social_YouTube::episode_hold( $ep_job )['delay'], 'A held episode is checked again in 5 minutes' );
update_option( 'presshub_social_youtube_episode_image_wait_minutes', 0 );
assert_true( PressHub_Social_YouTube::episode_ready( $ep_job ), 'Wait for Story Images 0 does not wait' );
assert_equals( 0, PressHub_Social_Settings_Storage::sanitize_youtube_episode_image_wait_minutes( null ), 'A save from another section keeps Wait for Story Images' );
assert_equals( 240, PressHub_Social_Settings_Storage::sanitize_youtube_episode_image_wait_minutes( 999 ), 'Wait for Story Images is at most 240 minutes' );
delete_option( 'presshub_social_youtube_episode_image_wait_minutes' );
update_option( 'presshub_social_youtube_episode_enhanced', false );
assert_true( PressHub_Social_YouTube::episode_ready( $ep_job ), 'With enhanced episodes off nothing waits for images' );
delete_option( 'presshub_social_youtube_episode_enhanced' );
assert_true( PressHub_Social_YouTube::episode_ready( [ 'platform' => 'youtube', 'tiktok_short' => [] ] ), 'A short is never held for images' );
PressHub_Social_Queue::update_job( (int) $sh_id, [ 'metadata' => wp_json_encode( [ 'tiktok_short' => [ 'index' => 0, 'count' => 2, 'topic' => 'Νίκη της Ομόνοιας στο ποδόσφαιρο', 'images' => [ [ 'url' => 'https://img/a1.jpg' ], [ 'url' => 'https://img/a2.jpg' ] ] ] ] ) ] );
PressHub_Social_Queue::enqueue( [ 'post_id' => 880, 'platform' => 'youtube', 'engine' => 'automation', 'post_copy' => 'x', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'metadata' => [ 'tiktok_short' => [ 'index' => 0, 'count' => 2, 'topic' => 'Νίκη', 'images' => [ [ 'url' => 'https://img/a1.jpg' ] ] ] ] ] );
assert_true( PressHub_Social_YouTube::episode_ready( $ep_job ), 'Once the shorts have their images the episode is ready' );
assert_equals( [ [ 'index' => 0, 'topic' => 'Νίκη της Ομόνοιας στο ποδόσφαιρο', 'urls' => [ 'https://img/a1.jpg', 'https://img/a2.jpg' ] ] ], PressHub_Social_YouTube::short_image_groups( 880 ), 'Each short is taken once across platforms, from the job with the most images' );

update_post_meta( 880, PressHub_AI_Podcast_Timeline::META_KEY, $tl );
$ep_up = PressHub_Social_YouTube::attach_upload( array_merge( $ep_job, [ 'audio_url' => 'https://example.com/uploads/podcast-880.wav', 'post_copy' => 'Η ενημέρωση της ημέρας.' ] ) );
$ev    = $ep_up['youtube']['episode_video'] ?? [];
assert_equals( 56.05, $ev['duration'] ?? 0, 'The episode carries the timeline length' );
assert_equals( count( $tl['segments'] ), count( $ev['segments'] ?? [] ), 'The episode carries every spoken turn for the captions' );
assert_equals( [ 'https://img/a1.jpg', 'https://img/a2.jpg' ], $ev['scenes'][2]['images'] ?? [], 'The football scene shows the football short\'s images' );
assert_true( str_contains( $ep_up['youtube']['description'] ?? '', "0:00 Η Βουλή ψηφίζει τον προϋπολογισμό\n0:20 Καιρός" ), 'The description lists the chapters' );
update_post_meta( 880, PressHub_AI_Podcast_Timeline::META_KEY, array_merge( $tl, [ 'estimated' => true ] ) );
$ep_est = PressHub_Social_YouTube::attach_upload( array_merge( $ep_job, [ 'audio_url' => 'https://example.com/uploads/podcast-880.mp3', 'post_copy' => 'Η ενημέρωση.' ] ) );
assert_false( str_contains( $ep_est['youtube']['description'] ?? '', '0:00' ), 'An estimated timeline gives no chapters' );
assert_true( ! empty( $ep_est['youtube']['episode_video']['estimated'] ), 'The worker is told the timeline is estimated' );
delete_post_meta( 880, PressHub_AI_Podcast_Timeline::META_KEY );
PressHub_Social_Queue::cancel_job( (int) $sh_id );
$ep_plain = PressHub_Social_YouTube::attach_upload( array_merge( $ep_job, [ 'audio_url' => 'https://example.com/uploads/podcast-880.mp3' ] ) );
assert_true( isset( $ep_plain['youtube']['episode_video']['images'] ) && ! isset( $ep_plain['youtube']['episode_video']['segments'] ), 'Without a timeline the episode still gets the story images, without captions' );
assert_equals( '', PressHub_AI_Podcast_Timeline::error( 880 ), 'A podcast without a timeline error is taken for one made before 2.54.0' );
update_post_meta( 880, PressHub_AI_Podcast_Timeline::ERROR_META_KEY, 'disk full' );
assert_equals( 'disk full', PressHub_AI_Podcast_Timeline::error( 880 ), 'A timeline that could not be saved is marked on the podcast' );
delete_post_meta( 880, PressHub_AI_Podcast_Timeline::ERROR_META_KEY );

// An episode over 15 minutes waits while the channel may not upload long videos.
$GLOBALS['yt_long_status'] = 'eligible';
$GLOBALS['yt_long_calls']  = 0;
PressHub_Social_YouTube::forget_access_token();
update_post_meta( 880, '_presshub_audio_duration_sec', 600 );
assert_true( PressHub_Social_YouTube::episode_length_ok( $ep_job ), 'A 10-minute episode may always be uploaded' );
update_post_meta( 880, '_presshub_audio_duration_sec', 899 );
assert_false( PressHub_Social_YouTube::episode_length_ok( $ep_job ), 'An episode just under 15 minutes counts the silence added at the end' );
PressHub_Social_YouTube::forget_access_token();
$GLOBALS['yt_long_calls'] = 0;
assert_equals( 0, $GLOBALS['yt_long_calls'], 'A short episode needs no channel check' );
update_post_meta( 880, '_presshub_audio_duration_sec', 1100 );
assert_false( PressHub_Social_YouTube::episode_length_ok( $ep_job ), 'An 18-minute episode waits while the channel is not verified' );
assert_false( PressHub_Social_YouTube::episode_length_ok( $ep_job ), 'It keeps waiting' );
assert_equals( 1, $GLOBALS['yt_long_calls'], 'The channel status is read once and cached' );
$held_before = PressHub_Social_Queue::get_job( $ep_id );
PressHub_Social_Queue::update_job( $ep_id, [ 'status' => 'pending', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'metadata' => wp_json_encode( $ep_job['metadata'] ) ] );
delete_transient( 'presshub_social_youtube_long_note_' . $ep_id );
$held_claim = PressHub_Social_REST_Controller::handle_claim( $req_yt );
$held_after = PressHub_Social_Queue::get_job( $ep_id );
assert_false( in_array( $ep_id, array_map( fn( $j ) => (int) $j['id'], $held_claim['jobs'] ?? [] ), true ), 'The claim does not hand out the long episode' );
assert_equals( 'pending', $held_after['status'], 'The held episode stays pending' );
assert_equals( (int) ( $held_before['attempts'] ?? 0 ), (int) ( $held_after['attempts'] ?? 0 ), 'Holding the episode uses no attempt' );
assert_true( str_contains( (string) $held_after['last_error'], 'over 15 minutes' ), 'The Queue screen says why the episode waits' );
assert_true( strtotime( $held_after['scheduled_at'] . ' UTC' ) > time() + 3000, 'The held episode is moved an hour later in the queue' );
// An MP3 estimate shorter than the real audio does not let a long episode through.
update_post_meta( 880, PressHub_AI_Podcast_Timeline::META_KEY, array_merge( $tl, [ 'estimated' => true, 'duration' => 800.0 ] ) );
assert_equals( 1100.0, PressHub_Social_YouTube::episode_seconds( 880 ), 'An estimated timeline counts the stored audio length when that is longer' );
update_post_meta( 880, PressHub_AI_Podcast_Timeline::META_KEY, array_merge( $tl, [ 'duration' => 800.0 ] ) );
assert_equals( 800.0, PressHub_Social_YouTube::episode_seconds( 880 ), 'A measured timeline is taken as it is' );
delete_post_meta( 880, PressHub_AI_Podcast_Timeline::META_KEY );
$GLOBALS['yt_long_status'] = 'allowed';
PressHub_Social_YouTube::forget_access_token();
assert_true( PressHub_Social_YouTube::episode_length_ok( $ep_job ), 'Once long uploads are allowed the episode goes' );
PressHub_Social_Queue::update_job( $ep_id, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
$freed_claim = PressHub_Social_REST_Controller::handle_claim( $req_yt );
assert_true( in_array( $ep_id, array_map( fn( $j ) => (int) $j['id'], $freed_claim['jobs'] ?? [] ), true ), 'The episode is handed out once it may go up' );
assert_equals( '', (string) PressHub_Social_Queue::get_job( $ep_id )['last_error'], 'The "Waiting" note is cleared when the episode is handed out' );
assert_true( PressHub_Social_YouTube::episode_length_ok( [ 'platform' => 'youtube', 'post_id' => 880, 'tiktok_short' => [] ] ), 'A short is never held for length' );
delete_post_meta( 880, '_presshub_audio_duration_sec' );
PressHub_Social_YouTube::forget_access_token();

// English and Turkish episodes: queued once voiced and published, uploaded with their own
// audio, title, captions and playlist, the images following the Greek topics.
update_option( 'presshub_ai_briefing_podcast_languages', [ 'en', 'tr' ] );
update_option( 'presshub_social_youtube_podcast_language_playlists', [ 'en' => 'https://www.youtube.com/playlist?list=PLenglishplaylist1', 'tr' => 'not a playlist!' ] );
assert_equals( 'PLenglishplaylist1', PressHub_Social_Settings_Storage::get_youtube_podcast_playlist_id_for( 'en' ), 'A pasted playlist link gives the language its playlist ID' );
assert_equals( '', PressHub_Social_Settings_Storage::get_youtube_podcast_playlist_id_for( 'tr' ), 'A bad playlist ID is dropped' );
update_post_meta( 880, PressHub_AI_Podcast_Timeline::META_KEY, $tl );
$tl_en = PressHub_AI_Podcast_Timeline::build( [
    [ 'title' => 'Parliament passes the budget', 'turns' => [ [ 'speaker' => 'female', 'text' => 'Good morning.', 'seconds' => 8.0 ], [ 'speaker' => 'male', 'text' => 'Good morning.', 'seconds' => 4.0 ] ] ],
    [ 'title' => 'Weather', 'turns' => [ [ 'speaker' => 'male', 'text' => 'Rain.', 'seconds' => 11.0 ] ] ],
    [ 'title' => 'Football: Omonia wins', 'turns' => [ [ 'speaker' => 'female', 'text' => 'The end.', 'seconds' => 18.0 ] ] ],
], [ 'turn_pause_ms' => 250, 'topic_pause_ms' => 600, 'host_count' => 2 ] );
$tr_entry = [ 'status' => 'translated', 'title' => 'PressHub Morning Briefing: the budget', 'description' => 'The House passes the budget. #Cyprus', 'script' => 'x' ];
update_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY, [ 'en' => $tr_entry ] );
assert_equals( 0, PressHub_AI_Podcast_Translations::maybe_enqueue( 880, 'en' ), 'A language is not queued before it is voiced' );
update_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY, [ 'en' => array_merge( $tr_entry, [ 'status' => 'voiced', 'audio_url' => 'https://example.com/uploads/podcast-880-en.wav', 'timeline' => $tl_en, 'duration_sec' => $tl_en['duration'] ] ) ] );
$GLOBALS['POSTS_STORE'][880]['post_status'] = 'pending';
assert_equals( 0, PressHub_AI_Podcast_Translations::maybe_enqueue( 880, 'en' ), 'A language waits while the Greek post is held' );
$GLOBALS['POSTS_STORE'][880]['post_status'] = 'publish';
$yt_was = get_option( 'presshub_social_enabled_platforms', null );
PressHub_Social_Settings_Storage::set_platform_enabled( 'youtube', false );
assert_equals( 0, PressHub_AI_Podcast_Translations::maybe_enqueue( 880, 'en' ), 'A language is never queued while YouTube is off under Where to post' );
null === $yt_was ? delete_option( 'presshub_social_enabled_platforms' ) : update_option( 'presshub_social_enabled_platforms', $yt_was );
update_post_meta( 880, '_presshub_social_platforms', [ 'facebook' ] );
assert_equals( 0, PressHub_AI_Podcast_Translations::maybe_enqueue( 880, 'en' ), 'A language is never queued when YouTube is not ticked on the post' );
update_post_meta( 880, '_presshub_social_platforms', [ 'facebook', 'youtube' ] );
assert_true( PressHub_AI_Podcast_Translations::youtube_allowed( 880 ), 'YouTube ticked on the post lets the languages go' );
delete_post_meta( 880, '_presshub_social_platforms' );
$greek_eps = array_values( array_filter( PressHub_Social_Queue::get_jobs_for_post( 880 ), static fn( $j ) => PressHub_Social_YouTube::is_episode_job( $j ) && 'cancelled' !== ( $j['status'] ?? '' ) ) );
$greek_st  = array_map( static fn( $j ) => [ (int) $j['id'], (string) $j['status'] ], $greek_eps );
foreach ( $greek_st as [ $gid ] ) {
    PressHub_Social_Queue::update_job( $gid, [ 'status' => 'cancelled' ] );
}
assert_equals( 0, PressHub_AI_Podcast_Translations::maybe_enqueue( 880, 'en' ), 'A language is never queued while its Greek episode is not (auto-publish off, for example)' );
foreach ( $greek_st as [ $gid, $gst ] ) {
    PressHub_Social_Queue::update_job( $gid, [ 'status' => $gst ] );
}
assert_true( count( $greek_st ) > 0 && PressHub_AI_Podcast_Translations::greek_episode_queued( 880 ), 'The Greek episode is queued again' );
$en_id = PressHub_AI_Podcast_Translations::maybe_enqueue( 880, 'en' );
assert_true( $en_id > 0, 'A voiced language of a published podcast gets its YouTube episode' );
assert_equals( 0, PressHub_AI_Podcast_Translations::maybe_enqueue( 880, 'en' ), 'Each language is queued once' );
assert_equals( [], PressHub_AI_Podcast_Translations::enqueue_ready( 880 ), 'Queueing the ready languages again adds nothing (Turkish is not voiced)' );
$en_job = PressHub_Social_Queue::get_job( $en_id );
assert_true( PressHub_Social_YouTube::is_episode_job( $en_job ) && 'en' === PressHub_Social_YouTube::episode_language( $en_job ), 'The job is the English episode' );
assert_equals( '', PressHub_Social_YouTube::episode_language( $ep_job ), 'The Greek episode has no language of its own' );
assert_equals( 'Youtube (EN)', PressHub_Social_YouTube::platform_label( $en_job ), 'The Queue shows the English episode as YouTube (EN)' );
assert_equals( 'Youtube', PressHub_Social_YouTube::platform_label( $ep_job ), 'The Greek episode is plain YouTube' );
// A translation made again after queueing: the job waits for the new audio, then takes the new description.
$en_entry = PressHub_AI_Podcast_Translations::get_entry( 880, 'en' );
update_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY, array_merge( PressHub_AI_Podcast_Translations::get_all( 880 ), [ 'en' => array_merge( $en_entry, [ 'status' => 'translated' ] ) ] ) );
$held = PressHub_Social_YouTube::episode_hold( $en_job );
assert_true( is_array( $held ) && str_contains( (string) ( $held['note'] ?? '' ), 'voiced again' ), 'An English episode waits while its new translation is voiced' );
update_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY, array_merge( PressHub_AI_Podcast_Translations::get_all( 880 ), [ 'en' => array_merge( $en_entry, [ 'description' => 'New English description. #Cyprus' ] ) ] ) );
$fresh = PressHub_Social_YouTube::attach_upload( array_merge( $en_job, [ 'audio_url' => 'https://example.com/uploads/podcast-880.mp3' ] ) );
assert_true( str_starts_with( (string) ( $fresh['youtube']['description'] ?? '' ), 'New English description.' ), 'An unedited English job takes the new translation\'s description' );
$edited = PressHub_Social_YouTube::attach_upload( array_merge( $en_job, [ 'post_copy' => 'Hand-written English copy', 'audio_url' => 'https://example.com/uploads/podcast-880.mp3' ] ) );
assert_true( str_starts_with( (string) ( $edited['youtube']['description'] ?? '' ), 'Hand-written English copy' ), 'Copy edited by hand on the Queue is kept' );
update_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY, array_merge( PressHub_AI_Podcast_Translations::get_all( 880 ), [ 'en' => $en_entry ] ) );

PressHub_Social_Queue::update_job_copy( $en_id, 'Edited by hand' );
$_REQUEST['nonce'] = 'valid_nonce';
$nonce_was         = $GLOBALS['NONCE_VALID'] ?? null;
$GLOBALS['NONCE_VALID'] = true;
$_POST['job_id']   = $en_id;
$caps_was          = $GLOBALS['test_user_caps'] ?? [];
$GLOBALS['test_user_caps'][ PressHub_Capabilities::social() ] = true;
$GLOBALS['JSON_RESPONSES'] = [];
try {
    PressHub_Social_Ajax_Handlers::handle_regenerate_job_copy();
} catch ( RuntimeException $e ) {
    // wp_send_json_* stops here.
}
$regen = end( $GLOBALS['JSON_RESPONSES'] ) ?: [];
assert_true( ! empty( $regen['success'] ) && 'The House passes the budget. #Cyprus' === ( $regen['data']['new_copy'] ?? '' ), 'Regen AI on the English episode restores its translated description' );
assert_equals( 'The House passes the budget. #Cyprus', PressHub_Social_Queue::get_job( $en_id )['post_copy'] ?? '', 'The English job has its description back' );
$GLOBALS['test_user_caps'] = $caps_was;
$GLOBALS['NONCE_VALID']    = $nonce_was;
unset( $_REQUEST['nonce'], $_POST['job_id'] );
assert_equals( 'The House passes the budget. #Cyprus', $en_job['post_copy'], 'The English description is the translation\'s' );
assert_true( abs( PressHub_AI_Podcast_Translations::duration( 880, 'en' ) - $tl_en['duration'] ) < 0.001, 'The English episode\'s length is its own audio\'s' );

$budget_short = PressHub_Social_Queue::enqueue( [ 'post_id' => 880, 'platform' => 'youtube', 'engine' => 'automation', 'post_copy' => 'x', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'metadata' => [ 'tiktok_short' => [ 'index' => 1, 'count' => 2, 'topic' => 'Ο προϋπολογισμός στη Βουλή', 'images' => [ [ 'url' => 'https://img/b1.jpg' ] ] ] ] ] );
$en_up = PressHub_Social_YouTube::attach_upload( array_merge( $en_job, [ 'audio_url' => 'https://example.com/uploads/podcast-880.mp3', 'post_url' => 'https://example.com/?p=880' ] ) );
$en_yt = $en_up['youtube'] ?? [];
$en_ev = $en_yt['episode_video'] ?? [];
$el_ev = PressHub_Social_YouTube::episode_video( 880 );
assert_equals( 'https://example.com/uploads/podcast-880-en.wav', $en_up['audio_url'] ?? '', 'The English episode uploads the English audio' );
assert_equals( 'PressHub Morning Briefing: the budget', $en_yt['title'] ?? '', 'The English episode has the English title' );
assert_equals( 'en', ( $en_yt['default_language'] ?? '' ) . '', 'The English episode is marked English' );
assert_equals( 'en', $en_yt['default_audio_language'] ?? '', 'Its audio is marked English' );
assert_equals( 'PLenglishplaylist1', $en_yt['playlist_id'] ?? '', 'The English episode goes to the English playlist' );
assert_equals( 'PressHub Morning Briefing: the budget', $en_ev['title'] ?? '', 'The video shows the English title' );
assert_true( str_starts_with( (string) ( $en_ev['end_card_line'] ?? '' ), 'Full stories and sources at ' ), 'The video ends on an English line' );
assert_equals( count( $tl_en['segments'] ), count( $en_ev['segments'] ?? [] ), 'The English captions are the English turns' );
assert_equals( 'Good morning.', $en_ev['segments'][0]['text'] ?? '', 'The captions are in English' );
assert_equals( array_column( $el_ev['scenes'], 'images' ), array_column( $en_ev['scenes'] ?? [], 'images' ), 'Each English topic shows the images of the same Greek topic' );
assert_equals( $tl_en['topics'][1]['start'], $en_ev['scenes'][1]['start'] ?? null, 'The English scenes follow the English timing' );
assert_equals( [ 'https://img/b1.jpg' ], $en_ev['scenes'][0]['images'] ?? [], 'The English budget topic shows the budget short, matched through the Greek topic' );
assert_true( in_array( 'https://img/a1.jpg', $en_ev['scenes'][2]['images'] ?? [], true ) && ! in_array( 'https://img/b1.jpg', $en_ev['scenes'][2]['images'] ?? [], true ), 'The English football topic shows the football short' );
assert_true( str_contains( $en_yt['description'] ?? '', "0:00 Parliament passes the budget\n0:" ) && ! str_contains( $en_yt['description'] ?? '', 'Καιρός' ), 'The English description lists English chapters' );

$tr_job = PressHub_Social_YouTube::episode_job( 880, 'Açıklama', null, null, gmdate( 'Y-m-d H:i:s' ), null, 'tr' );
update_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY, array_merge( PressHub_AI_Podcast_Translations::get_all( 880 ), [ 'tr' => [ 'status' => 'translated', 'audio_url' => 'https://example.com/uploads/podcast-880-tr-old.wav', 'title' => 'Yeni başlık' ] ] ) );
$tr_stale = PressHub_Social_YouTube::attach_upload( array_merge( $tr_job, [ 'id' => 9, 'audio_url' => 'https://example.com/uploads/podcast-880.mp3' ] ) );
assert_equals( 'youtube_episode_no_audio', is_wp_error( $tr_stale ) ? $tr_stale->get_error_code() : '', 'A language translated again but not voiced yet never uploads its old audio' );
update_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY, array_diff_key( PressHub_AI_Podcast_Translations::get_all( 880 ), [ 'tr' => 1 ] ) );
$tr_up  = PressHub_Social_YouTube::attach_upload( array_merge( $tr_job, [ 'id' => 9, 'audio_url' => 'https://example.com/uploads/podcast-880.mp3' ] ) );
assert_equals( 'youtube_episode_no_audio', is_wp_error( $tr_up ) ? $tr_up->get_error_code() : '', 'A language without its own audio never uploads the Greek audio' );
update_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY, array_merge( PressHub_AI_Podcast_Translations::get_all( 880 ), [ 'tr' => [ 'status' => 'voiced', 'audio_url' => 'https://example.com/uploads/podcast-880-tr.wav', 'title' => '' ] ] ) );
$tr_up2 = PressHub_Social_YouTube::attach_upload( array_merge( $tr_job, [ 'id' => 9, 'audio_url' => 'https://example.com/uploads/podcast-880.mp3' ] ) );
assert_equals( 'youtube_episode_no_title', is_wp_error( $tr_up2 ) ? $tr_up2->get_error_code() : '', 'A language without its own title never takes the Greek title' );

PressHub_Social_Queue::cancel_job( (int) $budget_short );
PressHub_Social_Queue::cancel_job( $en_id );
assert_equals( 1, count( PressHub_AI_Podcast_Translations::enqueue_ready( 880 ) ), 'A cancelled language episode can be queued again' );
update_option( 'presshub_ai_briefing_podcast_languages', [] );
assert_equals( 0, PressHub_AI_Podcast_Translations::maybe_enqueue( 880, 'tr' ), 'A language that is off is never queued' );
foreach ( PressHub_Social_Queue::get_jobs_for_post( 880 ) as $pj ) {
    if ( '' !== PressHub_Social_YouTube::episode_language( $pj ) ) {
        PressHub_Social_Queue::cancel_job( (int) $pj['id'] );
    }
}
delete_post_meta( 880, PressHub_AI_Podcast_Translations::META_KEY );
delete_post_meta( 880, PressHub_AI_Podcast_Timeline::META_KEY );
delete_option( 'presshub_social_youtube_podcast_language_playlists' );

// A revoked sign-in fails loudly and shows on Connections.
update_option( PressHub_Social_YouTube::REFRESH_TOKEN_OPTION, 'REVOKED' );
PressHub_Social_YouTube::forget_access_token();
$revoked = PressHub_Social_YouTube::access_token();
assert_equals( 'youtube_invalid_grant', is_wp_error( $revoked ) ? $revoked->get_error_code() : '', 'A revoked sign-in is an invalid_grant error' );
assert_true( empty( PressHub_Connection_Status::get( 'social:youtube' )['ok'] ), 'A revoked sign-in shows YouTube as not working' );

assert_false( PressHub_Social_YouTube::is_connected(), 'After Google refuses the sign-in YouTube no longer counts as connected' );
assert_true( '' !== PressHub_Social_YouTube::reconnect_reason(), 'The refusal is kept so Connections can say why' );
assert_equals( 'youtube_not_connected', ( fn( $t ) => is_wp_error( $t ) ? $t->get_error_code() : '' )( PressHub_Social_YouTube::access_token() ), 'No new refresh is tried until Connect YouTube again' );
assert_equals( 0, count( PressHub_Social_REST_Controller::handle_claim( $req_yt )['jobs'] ), 'After a refused sign-in no YouTube job is handed out' );
assert_true( PressHub_Social_YouTube::is_set_up(), 'A refused sign-in is still set up (client and refresh token stored)' );
assert_true( PressHub_Social_Settings_Storage::is_platform_configured( 'youtube' ), 'While Google refuses the sign-in, new posts are still queued for YouTube and wait' );
$tok_route = PressHub_Social_REST_Controller::handle_youtube_token( new WP_REST_Request( 'POST', '/worker/youtube/token' ) );
assert_true( is_wp_error( $tok_route ) && 409 === ( $tok_route->get_error_data()['status'] ?? 0 ) && 'youtube_not_connected' === $tok_route->get_error_code(), 'The token route answers 409 with the reason' );
assert_false( str_contains( wp_json_encode( $tok_route->get_error_message() ), 'REVOKED' ), 'The token route error carries no token' );

// A sign-in that cannot read a channel stores nothing and keeps the earlier state.
update_option( PressHub_Social_YouTube::REFRESH_TOKEN_OPTION, '1//OLD' );
delete_option( PressHub_Social_YouTube::NEEDS_RECONNECT_OPTION );
update_option( PressHub_Social_YouTube::CHANNEL_OPTION, [ 'id' => 'UCOLD', 'title' => 'Old channel' ] );
$yt_no_channel = true;
set_transient( PressHub_Social_YouTube::STATE_TRANSIENT_PREFIX . 'STATE4', 7, 900 );
$nochan = PressHub_Social_YouTube::complete_sign_in( 'STATE4', 'CODE', '', 7 );
$yt_no_channel = false;
assert_equals( 'youtube_no_channel', is_wp_error( $nochan ) ? $nochan->get_error_code() : '', 'A Google account without a channel fails the sign-in' );
assert_equals( '1//OLD', PressHub_Social_YouTube::get_refresh_token(), 'A failed sign-in does not replace the stored sign-in' );
assert_equals( 'UCOLD', PressHub_Social_YouTube::channel()['id'] ?? '', 'A failed sign-in keeps the stored channel' );

// A sign-in clears an earlier refusal.
update_option( PressHub_Social_YouTube::NEEDS_RECONNECT_OPTION, 'refused' );
set_transient( PressHub_Social_YouTube::STATE_TRANSIENT_PREFIX . 'STATE5', 7, 900 );
PressHub_Social_YouTube::complete_sign_in( 'STATE5', 'CODE', '', 7 );
assert_true( PressHub_Social_YouTube::is_connected() && '' === PressHub_Social_YouTube::reconnect_reason(), 'Connect YouTube again clears the refusal' );

// The test button checks the podcast playlist.
update_option( 'presshub_social_youtube_podcast_enabled', true );
$yt_test = PressHub_Social_YouTube::test_connection();
assert_true( ! is_wp_error( $yt_test ) && str_contains( $yt_test['message'], 'Daily Briefing' ), 'Test names the podcast playlist' );
update_option( 'presshub_social_youtube_podcast_playlist_id', 'PLmissingplaylist1' );
$yt_test = PressHub_Social_YouTube::test_connection();
assert_equals( 'youtube_playlist_missing', is_wp_error( $yt_test ) ? $yt_test->get_error_code() : '', 'Test fails on a playlist that does not exist' );
update_option( 'presshub_social_youtube_podcast_playlist_id', 'PLpodcastplaylist01' );

// Quota and sign-in refusals reported by the worker hold the job and pause YouTube.
$q_id = (int) $yt_eps[0]['id'];
PressHub_Social_Queue::update_job( $q_id, [ 'status' => 'processing' ] );
$req_c = new WP_REST_Request( 'POST', '/worker/complete' );
$req_c->set_param( 'job_id', $q_id );
$req_c->set_param( 'status', 'failed' );
$req_c->set_param( 'error', 'YouTube upload limit reached for today (quotaExceeded)' );
$req_c->set_param( 'metadata', [ 'youtube_error_reason' => 'quota' ] );
$done = PressHub_Social_REST_Controller::handle_complete( $req_c );
$q_job = PressHub_Social_Queue::get_job( $q_id );
assert_equals( 'deferred', $done['status'] ?? '', 'A quota refusal is not a failure' );
assert_equals( 'pending', $q_job['status'], 'The job waits for the quota reset' );
assert_true( strtotime( $q_job['scheduled_at'] . ' UTC' ) >= PressHub_Social_YouTube::paused_until() - 5, 'The job is due after the quota reset' );
assert_true( PressHub_Social_YouTube::paused_until() > time(), 'YouTube is paused until the reset' );
PressHub_Social_Queue::update_job( $q_id, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
assert_equals( 0, count( PressHub_Social_REST_Controller::handle_claim( $req_yt )['jobs'] ), 'While paused no YouTube job is handed out' );
$reset = PressHub_Social_YouTube::next_quota_reset( strtotime( '2026-11-18 20:00:00 UTC' ) );
assert_equals( '2026-11-19 08:05', gmdate( 'Y-m-d H:i', $reset ), 'The quota resets at midnight Pacific Time' );
delete_option( PressHub_Social_YouTube::PAUSED_UNTIL_OPTION );

PressHub_Social_Queue::update_job( $q_id, [ 'status' => 'processing' ] );
$req_c->set_param( 'metadata', [ 'youtube_error_reason' => 'auth' ] );
$req_c->set_param( 'error', 'Invalid Credentials' );
PressHub_Social_REST_Controller::handle_complete( $req_c );
assert_equals( 'pending', PressHub_Social_Queue::get_job( $q_id )['status'], 'A refused sign-in also holds the job' );
assert_false( PressHub_Social_YouTube::is_connected(), 'A refused sign-in reported by the worker stops YouTube uploads' );
delete_option( PressHub_Social_YouTube::NEEDS_RECONNECT_OPTION );

// A finished upload YouTube refused fails at once: the same video would be refused again.
PressHub_Social_Queue::update_job( $q_id, [ 'status' => 'processing', 'attempts' => 1 ] );
$req_c->set_param( 'metadata', [ 'youtube_error_reason' => 'refused' ] );
$req_c->set_param( 'error', 'YouTube refused the uploaded video V9 (rejected: length).' );
$refused_done = PressHub_Social_REST_Controller::handle_complete( $req_c );
$refused_job  = PressHub_Social_Queue::get_job( $q_id );
assert_equals( 'failed', $refused_job['status'], 'A refused upload is failed, not retried' );
assert_equals( 'failed', $refused_done['status'] ?? '', 'The worker is told it failed' );
assert_true( PressHub_Social_YouTube::is_connected(), 'A refused video does not stop YouTube uploads' );

// A playlist error is kept and shown; Retry never re-uploads a published video.
PressHub_Social_Queue::update_job( $q_id, [ 'status' => 'processing' ] );
$req_p = new WP_REST_Request( 'POST', '/worker/complete' );
$req_p->set_param( 'job_id', $q_id );
$req_p->set_param( 'status', 'published' );
$req_p->set_param( 'external_post_id', 'VID1' );
$req_p->set_param( 'external_post_url', 'https://www.youtube.com/watch?v=VID1' );
$req_p->set_param( 'metadata', [ 'youtube_kind' => 'episode', 'youtube_playlist_error' => 'Playlist not found.', 'youtube_episode_note' => 'story images: none could be downloaded' ] );
PressHub_Social_REST_Controller::handle_complete( $req_p );
$pub = PressHub_Social_Queue::get_job( $q_id );
assert_equals( 'published', $pub['status'], 'An upload with a playlist error is published' );
assert_equals( 'Playlist not found.', PressHub_Social_YouTube::playlist_error( $pub ), 'The playlist error stays on the job for the Queue screen' );
assert_false( PressHub_Social_YouTube::can_retry( $pub ), 'Retry is not offered on a published YouTube video' );
assert_false( PressHub_Social_YouTube::can_retry( [ 'platform' => 'youtube', 'status' => 'processing', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] ), 'A YouTube job that is uploading now cannot be retried' );
assert_true( PressHub_Social_YouTube::can_retry( [ 'platform' => 'youtube', 'status' => 'processing', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * 3600 ) ] ), 'A YouTube job stuck for hours can be retried' );
assert_true( PressHub_Social_YouTube::can_retry( [ 'platform' => 'youtube', 'status' => 'failed' ] ), 'A failed YouTube job can be retried' );
$repost_id = PressHub_Social_Queue::repost_as_new_job( $q_id );
$repost    = is_int( $repost_id ) ? PressHub_Social_Queue::get_job( $repost_id ) : [];
assert_equals( '', PressHub_Social_YouTube::playlist_error( (array) $repost ), 'A re-post does not inherit the earlier playlist error' );
assert_equals( 'story images: none could be downloaded', PressHub_Social_YouTube::episode_note( $pub ), 'What the episode video lacked stays on the job for the Queue screen' );
assert_equals( '', PressHub_Social_YouTube::episode_note( (array) $repost ), 'A re-post does not inherit the earlier episode note' );
$repost_meta = json_decode( (string) ( $repost['metadata'] ?? '' ), true );
$repost_q    = strtotime( (string) ( $repost_meta['youtube_episode']['queued_at'] ?? '' ) );
assert_true( false !== $repost_q && $repost_q >= time() - 5, 'A re-posted episode waits for story images from now' );
if ( is_int( $repost_id ) ) {
    PressHub_Social_Queue::cancel_job( $repost_id );
}
update_option( PressHub_Social_YouTube::PAUSED_UNTIL_OPTION, time() + 3600 );
PressHub_Social_YouTube::client_changed( 'a', 'b' );
assert_equals( 0, PressHub_Social_YouTube::paused_until(), 'A new OAuth client is not held by the old project\'s quota pause' );
update_option( PressHub_Social_YouTube::REFRESH_TOKEN_OPTION, '1//REFRESH' );
update_option( PressHub_Social_YouTube::CHANNEL_OPTION, [ 'id' => 'UC123', 'title' => 'PressHub Cyprus' ] );

// Outside Pull mode a YouTube job fails at once.
PressHub_Social_Queue::update_job( $q_id, [ 'status' => 'pending' ] );
update_option( 'presshub_social_worker_mode', 'push' );
$push = PressHub_Social_Dispatcher::dispatch_job( $q_id );
assert_equals( 'youtube_needs_pull', is_wp_error( $push ) ? $push->get_error_code() : '', 'A YouTube job is never dispatched in Push mode' );
update_option( 'presshub_social_worker_mode', 'pull' );

// Text rules for YouTube.
$yt_host = function_exists( 'home_url' ) && wp_parse_url( home_url(), PHP_URL_HOST ) ? preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) : 'presshub.cy';
assert_equals( 'Ολόκληρο το άρθρο στο ' . $yt_host . '.', PressHub_Social_YouTube::youtube_cta( 'Ολόκληρο το άρθρο στο PressHub, λινκ στο bio.' ), 'TikTok\'s "link in bio" names the site on YouTube' );
assert_equals( 'Ολόκληρη η ενημέρωση στο ' . $yt_host, PressHub_Social_YouTube::youtube_cta( 'Ολόκληρη η ενημέρωση: λινκ στο bio' ), 'The podcast short caption line too' );
assert_equals( 'Ολόκληρο το άρθρο στο ' . $yt_host . '.', PressHub_Social_YouTube::youtube_cta( 'Ολόκληρο το άρθρο στο PressHub.cy, το λινκ στο bio.' ), '"στο PressHub.cy, το λινκ στο bio" names the site once' );
assert_equals( 'Δείτε στο ' . $yt_host . ' τώρα', PressHub_Social_YouTube::youtube_cta( 'Δείτε τον σύνδεσμο στο βιογραφικό τώρα' ), '"σύνδεσμο στο βιογραφικό" too' );
assert_equals( 'Η βιογραφία του', PressHub_Social_YouTube::youtube_cta( 'Η βιογραφία του' ), 'Other text is left alone' );
assert_equals( 'Τιμές 3 5 ευρώ', PressHub_Social_YouTube::clean_title( 'Τιμές 3 < 5 ευρώ' ), 'A bare < does not cut the rest of the title' );
assert_equals( [ 'κύπρος' ], PressHub_Social_YouTube::tags_from( 'Δείτε https://example.com/a#section και #κύπρος' ), 'A #anchor in a link is not a tag' );
$yt_cta = PressHub_Social_YouTube::attach_upload( [ 'id' => 9, 'post_id' => 890, 'platform' => 'youtube', 'post_copy' => 'Λεζάντα. Ολόκληρη η ενημέρωση: λινκ στο bio #κύπρος', 'video_title' => 'H', 'post_url' => 'https://example.com/?p=890', 'tiktok_short' => [ 'index' => 0 ] ] );
assert_false( str_contains( $yt_cta['youtube']['description'] ?? '', 'bio' ), 'A YouTube description never says "link in bio"' );

// A new OAuth client drops the old sign-in.
PressHub_Social_YouTube::client_changed( 'old-client.apps.googleusercontent.com', 'new-client.apps.googleusercontent.com' );
assert_equals( '', PressHub_Social_YouTube::get_refresh_token(), 'A changed client ID forgets the sign-in' );
assert_equals( [], PressHub_Social_YouTube::channel(), 'A changed client ID forgets the channel' );
update_option( PressHub_Social_YouTube::REFRESH_TOKEN_OPTION, '1//REFRESH' );
PressHub_Social_YouTube::client_changed( 'same', 'same' );
assert_equals( '1//REFRESH', PressHub_Social_YouTube::get_refresh_token(), 'Saving the same client keeps the sign-in' );

// Disconnect forgets everything.
PressHub_Social_YouTube::disconnect();
assert_false( PressHub_Social_YouTube::is_connected(), 'Disconnect removes the sign-in' );
assert_equals( [], PressHub_Social_YouTube::channel(), 'Disconnect removes the channel' );

PressHub_Social_YouTube::set_http( null );
PressHub_Social_Settings_Storage::set_platform_enabled( 'youtube', false );
delete_option( 'presshub_social_youtube_shorts_enabled' );
delete_option( 'presshub_social_youtube_podcast_enabled' );
delete_option( 'presshub_social_youtube_podcast_playlist_id' );
delete_option( 'presshub_social_youtube_client_id' );
delete_option( 'presshub_social_youtube_client_secret' );
PressHub_Social_AI_Copywriter::set_ai_caller( fn() => 'Λεζάντα άρθρου TikTok #κύπρος #αθλητισμός' );

// Fallback: an article short that cannot be prepared is posted the classic way.
update_post_meta( 890, PressHub_Social_TikTok_Shorts::PLAN_META, '' );
PressHub_Social_TikTok_Shorts::set_ai_caller( fn() => 'Συγγνώμη, δεν μπορώ.' );
PressHub_Social_Queue::reset_memory_store();
$fb_ids = PressHub_Social_TikTok_Shorts::build_jobs( 890, 'automation', null, null, time() - 60, false, 'tiktok', '' );
$fb_id  = PressHub_Social_Queue::enqueue( $fb_ids[0] );
for ( $run = 1; $run < PressHub_Social_Settings_Storage::get_max_attempts(); $run++ ) {
    PressHub_Social_TikTok_Shorts::prepare_post( 890 );
}
assert_true( PressHub_Social_TikTok_Shorts::is_short_job( PressHub_Social_Queue::get_job( $fb_id ) ), 'An article short is retried before it falls back' );
PressHub_Social_TikTok_Shorts::prepare_post( 890 );
$fb_job = PressHub_Social_Queue::get_job( $fb_id );
assert_equals( 'pending', $fb_job['status'], 'An article short that cannot be made stays pending instead of failing' );
assert_false( PressHub_Social_TikTok_Shorts::is_short_job( $fb_job ), 'The fallen-back article job is a classic post again' );
assert_true( ! empty( json_decode( (string) $fb_job['metadata'], true )['tiktok_short_fallback']['error'] ?? '' ), 'The fallback keeps the reason in the job metadata' );
assert_equals( 'Λεζάντα άρθρου TikTok #κύπρος #αθλητισμός', $fb_job['post_copy'], 'The fallen-back job gets the usual TikTok caption in place of the placeholder' );
PressHub_Social_TikTok_Narrator::set_synthesizer( fn() => [ 'url' => 'https://example.com/uploads/narration-890.wav', 'id' => 990 ] );
$fb_claim = PressHub_Social_REST_Controller::handle_claim( $req_art );
PressHub_Social_TikTok_Narrator::set_synthesizer( null );
assert_equals( 1, count( $fb_claim['jobs'] ), 'The fallen-back article job is claimed as a classic TikTok post' );
assert_true( empty( $fb_claim['jobs'][0]['tiktok_short'] ), 'The fallen-back article job is rendered the classic way' );

PressHub_Social_TikTok_Shorts::set_ai_caller( fn() => 'Συγγνώμη, δεν μπορώ.' );
PressHub_Social_AI_Copywriter::set_ai_caller( fn() => 'Γενική λεζάντα TikTok #news' );

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
// TikTok shorts background music: mood per story, Lyria track library
// ----------------------------------------------------------------------
delete_option( 'presshub_social_prompt_tiktok_shorts_music' );
delete_option( PressHub_Social_TikTok_Music::LIBRARY_OPTION );
assert_equals( [ 'neutral', 'serious', 'upbeat', 'urgent', 'none' ], PressHub_Social_TikTok_Music::moods(), 'The default music prompts give four moods plus none' );
assert_equals(
    [ 'calm' => 'Soft piano. Instrumental.', 'tense-2' => 'Ticking clock' ],
    PressHub_Social_TikTok_Music::parse_prompts( "# comment\n\nCalm: Soft piano. Instrumental.\nnone: ignored\nbroken line\ntense-2 : Ticking clock\nempty:   " ),
    'parse_prompts reads "mood: prompt" lines and skips comments, none, blanks and broken lines'
);
$shipped_prompt = PressHub_Social_Prompt_Loader::load( 'social_tiktok_shorts.txt' );
assert_true( str_contains( $shipped_prompt, '{moods}' ) && str_contains( $shipped_prompt, '"mood"' ), 'The shorts prompt asks for a mood from {moods}' );
assert_true( str_contains( PressHub_Social_TikTok_Shorts::build_prompt( 880, 'M={moods}', '', 3, 130 ), 'M=neutral, serious, upbeat, urgent, none' ), 'build_prompt fills {moods}' );
$mood_plan = PressHub_Social_TikTok_Shorts::parse_plan( '{"shorts":[{"hook":"a","script":"[SPEAKER_1]: Α","caption":"c","mood":" Serious "},{"hook":"a","script":"[SPEAKER_1]: Α","caption":"c","mood":"none"},{"hook":"a","script":"[SPEAKER_1]: Α","caption":"c","mood":"epic"},{"hook":"a","script":"[SPEAKER_1]: Α","caption":"c"}]}', 4 );
assert_equals( [ 'serious', 'none', 'neutral', 'neutral' ], array_column( $mood_plan, 'mood' ), 'parse_plan keeps a known mood and turns a missing or unknown one into neutral' );

// Lyria response parsing.
$fake_mp3   = 'ID3' . str_repeat( "\x00", 2000 );
$lyria_body = wp_json_encode( [ 'steps' => [
    [ 'type' => 'user_input', 'content' => [ [ 'type' => 'text', 'text' => 'prompt' ] ] ],
    [ 'type' => 'model_output', 'content' => [ [ 'type' => 'text', 'text' => '[Intro]' ], [ 'type' => 'audio', 'data' => base64_encode( $fake_mp3 ) ] ] ],
] ] );
assert_equals( $fake_mp3, PressHub_Social_TikTok_Music::audio_from_response( $lyria_body ), 'audio_from_response decodes the audio block of the model output' );
$no_audio = PressHub_Social_TikTok_Music::audio_from_response( wp_json_encode( [ 'steps' => [ [ 'type' => 'model_output', 'content' => [ [ 'type' => 'text', 'text' => 'x' ] ] ] ] ] ) );
assert_true( is_wp_error( $no_audio ) && 'tiktok_music_no_audio' === $no_audio->get_error_code(), 'A response without audio is an error' );

// Generating the library, one track per call.
update_option( 'presshub_social_tiktok_shorts_music_tracks', 2 );
$lyria_calls = [];
$uploads     = [];
PressHub_Social_TikTok_Music::set_generator( function ( string $prompt, string $model ) use ( &$lyria_calls, $fake_mp3 ) {
    $lyria_calls[] = [ $prompt, $model ];
    return $fake_mp3;
} );
PressHub_Social_TikTok_Music::set_uploader( function ( string $filename, string $bytes, string $title ) use ( &$uploads ) {
    $uploads[] = $filename;
    return [ 'id' => 9000 + count( $uploads ), 'url' => 'https://example.com/uploads/' . $filename ];
} );
assert_equals( 8, PressHub_Social_TikTok_Music::missing_count(), 'Four moods with two tracks each are missing at first' );
$first_track = PressHub_Social_TikTok_Music::generate_next();
assert_equals( 'neutral', $first_track['mood'] ?? '', 'The first generated track is for the first mood' );
assert_equals( 7, $first_track['remaining'] ?? null, 'Each call makes one track' );
assert_equals( 'lyria-3-clip-preview', $lyria_calls[0][1] ?? '', 'Tracks use the default Lyria model' );
assert_true( str_contains( $lyria_calls[0][0], 'Instrumental only' ), 'The mood prompt is sent to Lyria' );
assert_true( (bool) preg_match( '/^presshub-music-neutral-1-[0-9a-f]{8}\.mp3$/', $uploads[0] ?? '' ), 'The track is saved as an MP3 named after its mood' );
for ( $i = 0; $i < 10 && PressHub_Social_TikTok_Music::missing_count() > 0; $i++ ) {
    PressHub_Social_TikTok_Music::generate_next();
}
assert_equals( 8, count( $lyria_calls ), 'Generating stops when every mood has its tracks' );
assert_equals( [ 'mood' => '', 'remaining' => 0 ], PressHub_Social_TikTok_Music::generate_next(), 'Nothing is generated when no track is missing' );
assert_equals( 8, count( $lyria_calls ), 'A complete library makes no Lyria call' );

// A changed mood prompt replaces only that mood's tracks.
update_option( 'presshub_social_prompt_tiktok_shorts_music', str_replace( 'Serious, restrained', 'Very serious', PressHub_Social_Prompt_Loader::load( 'social_tiktok_shorts_music.txt' ) ) );
assert_equals( 2, PressHub_Social_TikTok_Music::missing_count(), 'Changing one mood prompt makes only that mood\'s tracks stale' );
assert_equals( 'serious', PressHub_Social_TikTok_Music::generate_next()['mood'] ?? '', 'The stale mood is generated again' );
assert_equals( 1, count( PressHub_Social_TikTok_Music::library()['serious'] ?? [] ), 'The stale tracks are dropped when the first new one is made' );
PressHub_Social_TikTok_Music::generate_next();

// A Lyria failure is reported, nothing is stored.
PressHub_Social_TikTok_Music::clear_library();
PressHub_Social_TikTok_Music::set_generator( fn() => new WP_Error( 'tiktok_music_http_429', 'Lyria returned HTTP 429.' ) );
$lyria_fail = PressHub_Social_TikTok_Music::generate_next();
assert_true( is_wp_error( $lyria_fail ) && 'tiktok_music_http_429' === $lyria_fail->get_error_code(), 'A Lyria error is returned to the settings page' );
assert_equals( [], PressHub_Social_TikTok_Music::library(), 'A failed generation stores no track' );
// Lyria's copyright filter: retried with a reworded prompt, then a clear message.
$blocked_calls = [];
PressHub_Social_TikTok_Music::set_generator( function ( string $prompt ) use ( &$blocked_calls, $fake_mp3 ) {
    $blocked_calls[] = $prompt;
    return 1 === count( $blocked_calls ) ? new WP_Error( 'tiktok_music_http_400', 'Lyria returned HTTP 400: Request blocked due to copyright/recitation content. Please modify your input and retry.' ) : $fake_mp3;
} );
$after_block = PressHub_Social_TikTok_Music::generate_next();
assert_equals( 'neutral', $after_block['mood'] ?? '', 'A track blocked by the copyright filter is retried and saved' );
assert_equals( 2, count( $blocked_calls ), 'The blocked track took one retry' );
assert_true( $blocked_calls[0] !== $blocked_calls[1], 'The retry sends a reworded prompt' );
assert_true( PressHub_Social_TikTok_Music::variation_prompt( 'P.', 1 ) !== PressHub_Social_TikTok_Music::variation_prompt( 'P.', 2 ), 'Each track of a mood asks for a different variation' );
assert_true( str_starts_with( PressHub_Social_TikTok_Music::variation_prompt( 'Calm piano.', 2, 1 ), 'Calm piano. ' ), 'The variation is added after the mood prompt' );
$blocked_calls = [];
PressHub_Social_TikTok_Music::set_generator( function ( string $prompt ) use ( &$blocked_calls ) {
    $blocked_calls[] = $prompt;
    return new WP_Error( 'tiktok_music_http_400', 'Lyria returned HTTP 400: Request blocked due to copyright/recitation content.' );
} );
$always_blocked = PressHub_Social_TikTok_Music::generate_next();
assert_equals( PressHub_Social_TikTok_Music::MAX_ATTEMPTS, count( $blocked_calls ), 'A blocked track is tried MAX_ATTEMPTS times' );
assert_true( is_wp_error( $always_blocked ) && str_contains( $always_blocked->get_error_message(), 'Reword the "neutral:" line' ), 'A track blocked every time tells the operator which prompt line to reword' );
PressHub_Social_TikTok_Music::clear_library();
PressHub_Social_TikTok_Music::set_generator( fn() => 'tiny' );
assert_true( is_wp_error( PressHub_Social_TikTok_Music::generate_next() ), 'Empty audio from Lyria is an error' );
PressHub_Social_TikTok_Music::set_generator( function () use ( $fake_mp3 ) {
    return $fake_mp3;
} );
for ( $i = 0; $i < 10 && PressHub_Social_TikTok_Music::missing_count() > 0; $i++ ) {
    PressHub_Social_TikTok_Music::generate_next();
}

// Image style per mood: neutral and upbeat are illustrated, the others use the Image Prompt.
delete_option( 'presshub_social_prompt_tiktok_shorts_image_moods' );
assert_true( str_contains( PressHub_Social_TikTok_Shorts::image_style_for( 'upbeat' ), 'playful editorial illustration' ), 'An upbeat story gets the playful illustration style' );
assert_equals( PressHub_Social_Settings_Storage::get_tiktok_shorts_image_style(), PressHub_Social_TikTok_Shorts::image_style_for( 'serious' ), 'A serious story keeps the photographic Image Prompt' );
assert_equals( PressHub_Social_Settings_Storage::get_tiktok_shorts_image_style(), PressHub_Social_TikTok_Shorts::image_style_for( 'none' ), 'A sad story keeps the photographic Image Prompt' );
assert_true( str_contains( PressHub_Social_TikTok_Shorts::image_style_for( '' ), 'editorial illustration' ), 'A short without a mood uses the neutral style' );
update_option( 'presshub_social_prompt_tiktok_shorts_image_moods', "none: {description} Black and white photo." );
assert_equals( '{description} Black and white photo.', PressHub_Social_TikTok_Shorts::image_style_for( 'none' ), 'A "none:" image style line is allowed' );
delete_option( 'presshub_social_prompt_tiktok_shorts_image_moods' );

// Post text length and the comment question.
update_option( 'presshub_social_tiktok_shorts_caption_words', 150 );
assert_equals( 'W=150', PressHub_Social_TikTok_Shorts::build_prompt( 880, 'W={caption_words}', '', 3, 130 ), 'build_prompt fills {caption_words} from Short Post Text Length' );
update_option( 'presshub_social_tiktok_shorts_caption_words', 9999 );
assert_equals( 300, PressHub_Social_Settings_Storage::get_tiktok_shorts_caption_words(), 'Short Post Text Length is capped at 300 words' );
delete_option( 'presshub_social_tiktok_shorts_caption_words' );
assert_equals( 120, PressHub_Social_Settings_Storage::get_tiktok_shorts_caption_words(), 'Short Post Text Length defaults to 120 words' );
assert_true( str_contains( $shipped_prompt, '{caption_words}' ) && str_contains( $shipped_prompt, 'σχόλια' ), 'The shorts prompt asks for a story summary and a comment question' );

// A saved prompt keeps its backslashes (options.php has already unslashed it).
assert_equals( '{"script":"[SPEAKER_1]: ...\\n[SPEAKER_2]: ..."}', PressHub_Social_Settings_Storage::sanitize_prompt( '{"script":"[SPEAKER_1]: ...\\n[SPEAKER_2]: ..."}', 'presshub_social_prompt_tiktok_shorts' ), 'sanitize_prompt keeps the \\n of a JSON example' );
assert_equals( 'a\\b', PressHub_Social_Settings_Storage::sanitize_template( 'a\\b', 'presshub_social_template_twitter' ), 'sanitize_template keeps backslashes' );

// Music for a claimed short.
update_option( 'presshub_social_tiktok_shorts_music_enabled', false );
assert_equals( [], PressHub_Social_TikTok_Shorts::music_for( [ 'mood' => 'serious' ], 5 ), 'No music while background music is off' );
update_option( 'presshub_social_tiktok_shorts_music_enabled', true );
update_option( 'presshub_social_tiktok_shorts_music_volume', 12 );
$serious_music = PressHub_Social_TikTok_Shorts::music_for( [ 'mood' => 'serious' ], 5 );
assert_equals( 'serious', $serious_music['mood'] ?? '', 'The short keeps its mood' );
assert_true( str_contains( (string) ( $serious_music['music_url'] ?? '' ), 'presshub-music-serious-' ), 'A serious story gets a serious track' );
assert_equals( 0.12, $serious_music['music_volume'] ?? null, 'The music volume setting is sent as 0-1' );
assert_equals( $serious_music['music_url'], PressHub_Social_TikTok_Shorts::music_for( [ 'mood' => 'serious' ], 5 )['music_url'] ?? '', 'The same job always gets the same track' );
assert_true( $serious_music['music_url'] !== ( PressHub_Social_TikTok_Shorts::music_for( [ 'mood' => 'serious' ], 6 )['music_url'] ?? '' ), 'Shorts of the same mood take turns between its tracks' );
assert_equals( [ 'mood' => 'none' ], PressHub_Social_TikTok_Shorts::music_for( [ 'mood' => 'none' ], 5 ), 'A sad story (none) gets no music' );
assert_true( str_contains( (string) ( PressHub_Social_TikTok_Shorts::music_for( [], 5 )['music_url'] ?? '' ), 'presshub-music-neutral-' ), 'A short prepared before moods existed gets neutral music' );
delete_option( PressHub_Social_TikTok_Music::LIBRARY_OPTION );
assert_equals( [ 'mood' => 'upbeat' ], PressHub_Social_TikTok_Shorts::music_for( [ 'mood' => 'upbeat' ], 5 ), 'A mood without tracks renders without music instead of failing' );
update_option( 'presshub_social_prompt_tiktok_shorts_music', '' );
assert_equals( [ 'none' ], PressHub_Social_TikTok_Music::moods(), 'Emptied music prompts leave only none' );
assert_true( is_wp_error( PressHub_Social_TikTok_Music::generate_next() ), 'Generating with no music prompts fails loudly' );

update_option( 'presshub_social_tiktok_shorts_music_enabled', false );
delete_option( 'presshub_social_prompt_tiktok_shorts_music' );
delete_option( PressHub_Social_TikTok_Music::LIBRARY_OPTION );
PressHub_Social_TikTok_Music::set_generator( null );
PressHub_Social_TikTok_Music::set_uploader( null );

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
// Guard against publishing "[AI Copy Generation Failed: ...]" placeholders
// ----------------------------------------------------------------------
echo "\n--- Suite: Failed AI Copy Placeholder Guards ---\n";

assert_true(
    PressHub_Social_Dispatcher::is_failed_copy_placeholder( '[AI Copy Generation Failed: Invalid response from Gemini.]' ),
    'is_failed_copy_placeholder detects failed AI copy prefix'
);
assert_true(
    PressHub_Social_Dispatcher::is_failed_copy_placeholder( '  [AI Copy Generation Failed: Timeout]' ),
    'is_failed_copy_placeholder trims leading whitespace'
);
assert_false(
    PressHub_Social_Dispatcher::is_failed_copy_placeholder( 'Breaking news from Cyprus https://presshub.cy/1' ),
    'is_failed_copy_placeholder allows normal social copy'
);

$bad_copy = '[AI Copy Generation Failed: Invalid response from Gemini.]';

$meta_reject = PressHub_Social_Meta_API_Client::publish( [
    'id'        => 9901,
    'post_id'   => 101,
    'platform'  => 'twitter',
    'post_copy' => $bad_copy,
] );
assert_true( is_wp_error( $meta_reject ), 'Meta/Twitter API client rejects failed AI copy placeholder' );
assert_equals( 'failed_copy_placeholder', $meta_reject->get_error_code(), 'Meta/Twitter API client returns failed_copy_placeholder code' );

$worker_reject = PressHub_Social_Worker_Client::dispatch( [
    'id'        => 9902,
    'post_id'   => 101,
    'platform'  => 'twitter',
    'post_copy' => $bad_copy,
] );
assert_true( is_wp_error( $worker_reject ), 'Worker client rejects failed AI copy placeholder' );
assert_equals( 'failed_copy_placeholder', $worker_reject->get_error_code(), 'Worker client returns failed_copy_placeholder code' );

// Enqueue a failed job with placeholder copy, retry it, and verify Pull Mode claim & dispatch_job guard it.
$placeholder_job_id = PressHub_Social_Queue::enqueue( [
    'post_id'      => 902,
    'platform'     => 'twitter',
    'engine'       => 'automation',
    'post_copy'    => $bad_copy,
    'status'       => 'failed',
    'last_error'   => 'Invalid response from Gemini.',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
] );
assert_true( is_int( $placeholder_job_id ) && $placeholder_job_id > 0, 'Placeholder failed job enqueued for guard test' );

// Reposting a job with placeholder copy when AI copywriter fails must return WP_Error instead of queueing the error string.
update_option( 'presshub_ai_providers', [
    'openai-test' => [
        'id'            => 'openai-test',
        'type'          => 'openai',
        'name'          => 'Test OpenAI',
        'api_key'       => 'sk-test-key-12345',
        'default_model' => 'gpt-4o',
        'enabled'       => true,
    ],
] );
PressHub_Social_AI_Copywriter::set_ai_caller( function() {
    return new WP_Error( 'api_error', 'Invalid response from Gemini.' );
} );
update_option( 'presshub_social_copy_mode', 'ai' );
update_option( 'presshub_social_prompt_twitter', 'Write a tweet for {title} {url}' );
$repost_err = PressHub_Social_Queue::repost_as_new_job( $placeholder_job_id );
assert_true( is_wp_error( $repost_err ), 'repost_as_new_job refuses placeholder copy when AI regeneration fails' );

// Retry sets status=pending; handle_claim must NOT hand it to worker when regeneration fails, and must record failure.
PressHub_Social_Queue::retry_job( $placeholder_job_id );
PressHub_Social_Queue::update_job( $placeholder_job_id, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
$claim_res_fail = PressHub_Social_REST_Controller::handle_claim( [
    'platforms' => [ 'twitter' ],
    'limit'     => 10,
] );
$claimed_ids_fail = array_map( static fn( $j ) => (int) ( $j['id'] ?? 0 ), $claim_res_fail['jobs'] ?? [] );
assert_false( in_array( $placeholder_job_id, $claimed_ids_fail, true ), 'handle_claim never returns job with failed AI copy placeholder when regeneration fails' );
$after_fail_job = PressHub_Social_Queue::get_job( $placeholder_job_id );
assert_equals( 'Invalid response from Gemini.', $after_fail_job['last_error'] ?? null, 'handle_claim records failure reason when regeneration fails' );
assert_true( (int) ( $after_fail_job['attempts'] ?? 0 ) >= 1, 'handle_claim increments attempts via mark_failed' );

// When AI regeneration succeeds, dispatch_job in Pull Mode regenerates copy before queueing pending, and handle_claim returns the clean copy.
PressHub_Social_AI_Copywriter::set_ai_caller( function() {
    return 'Fresh regenerated tweet copy!';
} );
update_option( 'presshub_social_worker_mode', 'pull' );
$dispatch_pull = PressHub_Social_Dispatcher::dispatch_job( $placeholder_job_id );
assert_true( is_wp_error( $dispatch_pull ) && 'pull_mode_queued' === $dispatch_pull->get_error_code(), 'dispatch_job in Pull Mode succeeds in regenerating and queueing job' );
$after_regen_job = PressHub_Social_Queue::get_job( $placeholder_job_id );
assert_true( str_contains( (string) ( $after_regen_job['post_copy'] ?? '' ), 'Fresh regenerated tweet copy!' ), 'dispatch_job replaced placeholder copy with regenerated copy' );
assert_false( PressHub_Social_Dispatcher::is_failed_copy_placeholder( (string) ( $after_regen_job['post_copy'] ?? '' ) ), 'Regenerated job no longer has failed copy placeholder' );

PressHub_Social_AI_Copywriter::set_ai_caller( null );

// ----------------------------------------------------------------------
// Suite: Instagram Reels (Shorts) Integration
// ----------------------------------------------------------------------
echo "\n--- Suite: Instagram Reels (Shorts) Integration ---\n";

delete_option( 'presshub_social_instagram_shorts_enabled' );
assert_false( PressHub_Social_Settings_Storage::get_instagram_shorts_enabled(), 'Instagram shorts disabled by default' );

update_option( 'presshub_social_instagram_shorts_enabled', true );
$_POST = [ 'unrelated_tab' => '1' ];
assert_true( PressHub_Social_Settings_Storage::sanitize_instagram_shorts_enabled( null ), 'sanitize_instagram_shorts_enabled preserves stored value when checkbox absent on another tab' );
$_POST = [];

update_option( 'presshub_social_worker_mode', 'pull' );
update_option( 'presshub_social_tiktok_shorts_enabled', true );
update_option( 'presshub_social_tiktok_shorts_count', 2 );
update_option( 'presshub_social_tiktok_shorts_images', 1 );

$ig_short_post_id = 910;
$GLOBALS['mock_posts'][ $ig_short_post_id ] = (object) [
    'ID'           => $ig_short_post_id,
    'post_title'   => 'Daily Briefing Podcast IG Reels Test',
    'post_content' => 'Podcast body',
    'post_status'  => 'publish',
    'post_type'    => 'post',
];
update_post_meta( $ig_short_post_id, '_presshub_briefing_type', 'podcast' );
update_post_meta( $ig_short_post_id, '_presshub_podcast_script', '[SPEAKER_1]: Welcome to the news.' );

assert_true( PressHub_Social_TikTok_Shorts::should_split( $ig_short_post_id, 'automation', 'instagram' ), 'should_split returns true for instagram when enabled in pull mode' );
assert_false( PressHub_Social_TikTok_Shorts::should_split( $ig_short_post_id, 'api', 'instagram' ), 'should_split returns false for instagram when engine is api' );

$ig_built = PressHub_Social_TikTok_Shorts::build_jobs( $ig_short_post_id, 'automation', null, null, time(), false, 'instagram' );
assert_equals( 2, count( $ig_built ), 'build_jobs builds configured count of instagram short jobs' );
assert_equals( 'instagram', $ig_built[0]['platform'] ?? '', 'build_jobs sets platform=instagram when requested' );
assert_true( PressHub_Social_TikTok_Shorts::is_short_job( $ig_built[0] ), 'is_short_job recognizes instagram short jobs' );

// Enqueue both TikTok and Instagram short jobs for the same podcast post and verify prepare_post reuses audio & images
foreach ( PressHub_Social_TikTok_Shorts::build_jobs( $ig_short_post_id, 'automation', null, null, time() - 60, false, 'tiktok' ) as $j ) {
    PressHub_Social_Queue::enqueue( $j );
}
foreach ( PressHub_Social_TikTok_Shorts::build_jobs( $ig_short_post_id, 'automation', null, null, time() - 60, false, 'instagram' ) as $j ) {
    PressHub_Social_Queue::enqueue( $j );
}

$synth_calls = 0;
$img_calls   = 0;
PressHub_Social_TikTok_Shorts::set_ai_caller( function() {
    return json_encode( [
        'shorts' => [
            [
                'topic'   => 'Story One',
                'hook'    => 'Breaking update',
                'script'  => '[SPEAKER_1]: First story update.',
                'caption' => 'Story one caption',
                'mood'    => 'neutral',
                'visuals' => [ 'Editorial scene one' ],
            ],
            [
                'topic'   => 'Story Two',
                'hook'    => 'Second headline',
                'script'  => '[SPEAKER_1]: Second story update.',
                'caption' => 'Story two caption',
                'mood'    => 'serious',
                'visuals' => [ 'Editorial scene two' ],
            ],
        ],
    ] );
} );
PressHub_Social_TikTok_Shorts::set_synthesizer( function( $short, $job ) use ( &$synth_calls ) {
    $synth_calls++;
    return [
        'url'      => 'https://example.com/audio-' . $synth_calls . '.mp3',
        'id'       => 100 + $synth_calls,
        'segments' => [ [ 'speaker' => 'SPEAKER_1', 'text' => 'Line', 'start' => 0.0, 'end' => 2.5 ] ],
    ];
} );
PressHub_Social_TikTok_Shorts::set_image_generator( function( $prompt, $pid, $short ) use ( &$img_calls ) {
    $img_calls++;
    return [ 'url' => 'https://example.com/img-' . $img_calls . '.png', 'id' => 200 + $img_calls ];
} );

PressHub_Social_TikTok_Shorts::prepare_post( $ig_short_post_id );

assert_equals( 2, $synth_calls, 'prepare_post synthesizes audio once per story across TikTok + Instagram sibling jobs' );
assert_equals( 2, $img_calls, 'prepare_post generates images once per story across TikTok + Instagram sibling jobs' );

$prepared_ig_jobs = array_values( array_filter(
    PressHub_Social_Queue::get_jobs_for_post( $ig_short_post_id ),
    static fn( $j ) => 'instagram' === ( $j['platform'] ?? '' )
) );
assert_equals( 2, count( $prepared_ig_jobs ), 'Both Instagram short jobs exist in queue' );
assert_true( PressHub_Social_TikTok_Shorts::is_ready( $prepared_ig_jobs[0] ), 'Instagram short job #1 is marked ready with shared audio and images' );
assert_true( PressHub_Social_TikTok_Shorts::is_ready( $prepared_ig_jobs[1] ), 'Instagram short job #2 is marked ready with shared audio and images' );

PressHub_Social_TikTok_Shorts::set_ai_caller( null );
PressHub_Social_TikTok_Shorts::set_synthesizer( null );
PressHub_Social_TikTok_Shorts::set_image_generator( null );

// Verify cancel_superseded cancels older pending Instagram short jobs for a newer podcast post
$newer_ig_post_id = 911;
$cancelled_ig     = PressHub_Social_TikTok_Shorts::cancel_superseded( $newer_ig_post_id, 'instagram' );
assert_equals( 2, count( $cancelled_ig ), 'cancel_superseded cancels pending Instagram short jobs from older podcast post' );
$after_cancel_ig = array_values( array_filter(
    PressHub_Social_Queue::get_jobs_for_post( $ig_short_post_id ),
    static fn( $j ) => 'instagram' === ( $j['platform'] ?? '' ) && 'cancelled' === ( $j['status'] ?? '' )
) );
assert_equals( 2, count( $after_cancel_ig ), 'Older Instagram short jobs transitioned to cancelled' );
$still_pending_tt = array_values( array_filter(
    PressHub_Social_Queue::get_jobs_for_post( $ig_short_post_id ),
    static fn( $j ) => 'tiktok' === ( $j['platform'] ?? '' ) && 'pending' === ( $j['status'] ?? '' )
) );
assert_equals( 2, count( $still_pending_tt ), 'cancel_superseded(instagram) leaves TikTok short jobs pending' );

// ----------------------------------------------------------------------
// Suite: Facebook Reels (Shorts), next to the Facebook link post
// ----------------------------------------------------------------------
echo "\n--- Suite: Facebook Reels (Shorts) ---\n";

delete_option( 'presshub_social_facebook_shorts_enabled' );
assert_false( PressHub_Social_Settings_Storage::get_facebook_shorts_enabled(), 'Facebook Reels are off by default' );
update_option( 'presshub_social_facebook_shorts_enabled', true );
$_POST = [ 'unrelated_tab' => '1' ];
assert_true( PressHub_Social_Settings_Storage::sanitize_facebook_shorts_enabled( null ), 'sanitize_facebook_shorts_enabled keeps the stored value when the box is on another tab' );
$_POST = [];
assert_true( in_array( 'facebook', PressHub_Social_TikTok_Shorts::PLATFORMS, true ), 'Facebook is a shorts platform' );
assert_false( PressHub_Social_TikTok_Shorts::has_classic_fallback( [ 'platform' => 'facebook' ] ), 'A Facebook Reel never falls back to a classic post (the link post is already made)' );
assert_false( PressHub_Social_TikTok_Shorts::has_classic_fallback( [ 'platform' => 'youtube' ] ), 'A YouTube short never falls back to a classic post' );
assert_true( PressHub_Social_TikTok_Shorts::has_classic_fallback( [ 'platform' => 'instagram' ] ), 'An Instagram article short still falls back to the classic post' );

$fb_short_post_id = 912;
$GLOBALS['mock_posts'][ $fb_short_post_id ] = (object) [
    'ID'           => $fb_short_post_id,
    'post_title'   => 'Daily Briefing Podcast FB Reels Test',
    'post_content' => 'Podcast body',
    'post_status'  => 'publish',
    'post_type'    => 'post',
];
update_post_meta( $fb_short_post_id, '_presshub_briefing_type', 'podcast' );
update_post_meta( $fb_short_post_id, '_presshub_podcast_script', '[SPEAKER_1]: Welcome to the news.' );
assert_true( PressHub_Social_TikTok_Shorts::should_split( $fb_short_post_id, 'automation', 'facebook' ), 'should_split is true for Facebook when Facebook Reels are on in Pull Mode' );
assert_false( PressHub_Social_TikTok_Shorts::should_split( $fb_short_post_id, 'api', 'facebook' ), 'Facebook Reels need the automation engine' );

$fb_link_time = time() + 600;
$fb_ids       = PressHub_Social_Dispatcher::enqueue_facebook_shorts( $fb_short_post_id, 'automation', null, null, $fb_link_time );
assert_equals( 2, count( $fb_ids ), 'A podcast gets Shorts per Podcast Facebook Reel jobs' );
$fb_first = PressHub_Social_Queue::get_job( $fb_ids[0] );
assert_equals( 'facebook', $fb_first['platform'], 'The Reel job is a Facebook job' );
assert_true( PressHub_Social_TikTok_Shorts::is_short_job( $fb_first ), 'The Facebook Reel job is a short job' );
assert_true( PressHub_Social_TikTok_Shorts::is_placeholder_copy( (string) $fb_first['post_copy'] ), 'The Reel keeps the placeholder until the short\'s own caption fills it' );
assert_equals(
    gmdate( 'Y-m-d H:i:s', $fb_link_time + PressHub_Social_Settings_Storage::get_tiktok_shorts_spacing_minutes() * 60 ),
    (string) $fb_first['scheduled_at'],
    'The first Reel follows the link post by the time between shorts'
);

update_option( 'presshub_social_facebook_shorts_enabled', false );
assert_equals( [], PressHub_Social_Dispatcher::enqueue_facebook_shorts( $fb_short_post_id, 'automation', null, null, time() ), 'No Reel when Facebook Reels are off' );

// Through the dispatcher: the link post stays and the Reels come after it.
update_option( 'presshub_social_facebook_shorts_enabled', true );
update_option( 'presshub_social_engine_facebook', 'automation' );
$fb_saved_platforms = get_option( 'presshub_social_enabled_platforms', null );
$fb_saved_sessions  = get_option( 'presshub_social_worker_sessions', null );
update_option( 'presshub_social_enabled_platforms', [ 'facebook' ] );
update_option( 'presshub_social_worker_sessions', [ 'facebook' => true ] );
PressHub_Social_Queue::reset_memory_store();
$fb_all = PressHub_Social_Dispatcher::enqueue_for_post( $fb_short_post_id, [ 'facebook' ] );
$fb_jobs  = array_map( [ 'PressHub_Social_Queue', 'get_job' ], $fb_all );
$fb_links = array_values( array_filter( $fb_jobs, fn( $j ) => ! PressHub_Social_TikTok_Shorts::is_short_job( $j ) ) );
$fb_reels = array_values( array_filter( $fb_jobs, [ 'PressHub_Social_TikTok_Shorts', 'is_short_job' ] ) );
assert_equals( 1, count( $fb_links ), 'With Facebook Reels on, the Facebook link post is still queued' );
assert_false( PressHub_Social_TikTok_Shorts::is_placeholder_copy( (string) ( $fb_links[0]['post_copy'] ?? '' ) ), 'The link post has its own copy, not a short placeholder' );
assert_equals( 2, count( $fb_reels ), 'The Reels are queued next to the link post' );
assert_true( strtotime( $fb_reels[0]['scheduled_at'] . ' UTC' ) > strtotime( $fb_links[0]['scheduled_at'] . ' UTC' ), 'The first Reel goes out after the link post' );
PressHub_Social_Queue::reset_memory_store();
delete_option( 'presshub_social_engine_facebook' );
delete_option( 'presshub_social_facebook_shorts_enabled' );
null === $fb_saved_platforms ? delete_option( 'presshub_social_enabled_platforms' ) : update_option( 'presshub_social_enabled_platforms', $fb_saved_platforms );
null === $fb_saved_sessions ? delete_option( 'presshub_social_worker_sessions' ) : update_option( 'presshub_social_worker_sessions', $fb_saved_sessions );

// Verify wait_for_instagram_container handles IN_PROGRESS -> FINISHED, ERROR, and timeout
$poll_calls = 0;
PressHub_Social_Meta_API_Client::set_http_get( function() use ( &$poll_calls ) {
    $poll_calls++;
    return [ 'status_code' => $poll_calls < 2 ? 'IN_PROGRESS' : 'FINISHED' ];
} );
$poll_ok = PressHub_Social_Meta_API_Client::wait_for_instagram_container( 'v22.0', '12345', 'tok', 5, 0 );
assert_true( true === $poll_ok, 'wait_for_instagram_container returns true when container finishes' );
assert_equals( 2, $poll_calls, 'wait_for_instagram_container polls until FINISHED' );

PressHub_Social_Meta_API_Client::set_http_get( function() {
    return [ 'status_code' => 'ERROR', 'status' => 'Video codec unsupported' ];
} );
$poll_err = PressHub_Social_Meta_API_Client::wait_for_instagram_container( 'v22.0', '12345', 'tok', 5, 0 );
assert_true( is_wp_error( $poll_err ) && 'instagram_container_processing_error' === $poll_err->get_error_code(), 'wait_for_instagram_container fails loudly on ERROR status' );

PressHub_Social_Meta_API_Client::set_http_get( function() {
    return [ 'status_code' => 'IN_PROGRESS' ];
} );
$poll_timeout = PressHub_Social_Meta_API_Client::wait_for_instagram_container( 'v22.0', '12345', 'tok', 3, 0 );
assert_true( is_wp_error( $poll_timeout ) && 'instagram_container_timeout' === $poll_timeout->get_error_code(), 'wait_for_instagram_container returns timeout WP_Error when never finishing' );

// Verify wait_for_instagram_container fails fast on Graph API error payload without status_code
$api_err_calls = 0;
PressHub_Social_Meta_API_Client::set_http_get( function() use ( &$api_err_calls ) {
    $api_err_calls++;
    return [ 'error' => [ 'message' => 'Invalid OAuth access token' ] ];
} );
$poll_api_err = PressHub_Social_Meta_API_Client::wait_for_instagram_container( 'v22.0', '12345', 'tok', 5, 0 );
assert_true( is_wp_error( $poll_api_err ) && 'instagram_container_status_error' === $poll_api_err->get_error_code(), 'wait_for_instagram_container fails fast on Graph API error payload' );
assert_equals( 1, $api_err_calls, 'wait_for_instagram_container stops polling on first Graph API error' );

// Verify repost strips instagram_creation_id so a reposted Reel creates a fresh container
$ig_repost_src_id = PressHub_Social_Queue::enqueue( [
    'post_id'      => $ig_short_post_id,
    'platform'     => 'instagram',
    'engine'       => 'api',
    'post_copy'    => 'Reel repost test',
    'media_url'    => 'https://example.com/reel.mp4',
    'media_type'   => 'video',
    'status'       => 'published',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s' ),
    'metadata'     => [ 'instagram_creation_id' => '99887766', 'keep_key' => 'yes' ],
] );
$ig_repost_id = PressHub_Social_Queue::repost_as_new_job( (int) $ig_repost_src_id );
assert_true( is_int( $ig_repost_id ) && $ig_repost_id > 0, 'repost creates a new Instagram job' );
$ig_repost_job  = PressHub_Social_Queue::get_job( (int) $ig_repost_id );
$ig_repost_meta = is_string( $ig_repost_job['metadata'] ?? null ) ? json_decode( $ig_repost_job['metadata'], true ) : ( $ig_repost_job['metadata'] ?? [] );
assert_false( isset( $ig_repost_meta['instagram_creation_id'] ), 'repost strips stale instagram_creation_id from metadata' );
assert_equals( 'yes', $ig_repost_meta['keep_key'] ?? '', 'repost preserves unrelated metadata keys' );

// Verify transient HTTP 5xx, 429, is_transient, Graph rate-limit codes, and non-JSON 200 HTML keep polling
$transient_calls = 0;
PressHub_Social_Meta_API_Client::set_http_get( function() use ( &$transient_calls ) {
    $transient_calls++;
    if ( 1 === $transient_calls ) {
        return [ 'http_code' => 502, 'error' => [ 'message' => 'Bad Gateway' ] ];
    }
    if ( 2 === $transient_calls ) {
        return [ 'http_code' => 429, 'error' => [ 'message' => 'Too Many Requests' ] ];
    }
    if ( 3 === $transient_calls ) {
        return [ 'error' => [ 'code' => 4, 'message' => 'Application request limit reached' ] ];
    }
    if ( 4 === $transient_calls ) {
        return [ 'error' => [ 'code' => 2, 'is_transient' => true, 'message' => 'Unexpected error' ] ];
    }
    if ( 5 === $transient_calls ) {
        return [ 'response' => [ 'code' => 200 ], 'body' => '<html>temporary proxy</html>' ];
    }
    return [ 'status_code' => 'FINISHED' ];
} );
$poll_transient = PressHub_Social_Meta_API_Client::wait_for_instagram_container( 'v22.0', '12345', 'tok', 8, 0 );
assert_true( true === $poll_transient, 'wait_for_instagram_container keeps polling through transient errors and non-JSON 200' );
assert_equals( 6, $transient_calls, 'wait_for_instagram_container rode out all 5 transient responses before FINISHED' );

// Verify publish_instagram clears instagram_creation_id after media_publish, clears on dead container (code 100), and keeps on auth error (code 190)
update_option( 'presshub_social_meta_access_token', 'test-meta-token' );
update_option( 'presshub_social_instagram_account_id', 'ig-user-1' );

$pub_job_id = PressHub_Social_Queue::enqueue( [
    'post_id'      => $ig_short_post_id,
    'platform'     => 'instagram',
    'engine'       => 'api',
    'post_copy'    => 'Reel publish clear test',
    'media_url'    => 'https://example.com/reel-pub.mp4',
    'media_type'   => 'video',
    'status'       => 'pending',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s' ),
    'metadata'     => [ 'keep_key' => 'yes' ],
] );
PressHub_Social_Meta_API_Client::set_http_post( function( $endpoint ) {
    if ( false !== strpos( $endpoint, '/media_publish' ) ) {
        return [ 'code' => 200, 'body' => [ 'id' => 'media-999' ] ];
    }
    return [ 'code' => 200, 'body' => [ 'id' => 'creation-111' ] ];
} );
PressHub_Social_Meta_API_Client::set_http_get( function() {
    return [ 'status_code' => 'FINISHED' ];
} );
$pub_res = PressHub_Social_Meta_API_Client::publish_instagram( PressHub_Social_Queue::get_job( (int) $pub_job_id ) );
assert_true( is_array( $pub_res ) && 'media-999' === ( $pub_res['external_post_id'] ?? '' ), 'publish_instagram succeeds via mocked HTTP endpoints' );
$pub_job_after = PressHub_Social_Queue::get_job( (int) $pub_job_id );
$pub_meta_after = is_string( $pub_job_after['metadata'] ?? null ) ? json_decode( $pub_job_after['metadata'], true ) : ( $pub_job_after['metadata'] ?? [] );
assert_false( isset( $pub_meta_after['instagram_creation_id'] ), 'publish_instagram clears instagram_creation_id after media_publish succeeds' );
assert_equals( 'yes', $pub_meta_after['keep_key'] ?? '', 'publish_instagram preserves other metadata keys when clearing container ID' );

// Dead container (Graph code 100) clears instagram_creation_id so retry creates a fresh container
$dead_job_id = PressHub_Social_Queue::enqueue( [
    'post_id'      => $ig_short_post_id,
    'platform'     => 'instagram',
    'engine'       => 'api',
    'post_copy'    => 'Reel dead container test',
    'media_url'    => 'https://example.com/reel-dead.mp4',
    'media_type'   => 'video',
    'status'       => 'pending',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s' ),
    'metadata'     => [ 'instagram_creation_id' => 'dead-222' ],
] );
PressHub_Social_Meta_API_Client::set_http_get( function() {
    return [ 'http_code' => 400, 'error' => [ 'code' => 100, 'message' => 'Object with ID dead-222 does not exist' ] ];
} );
$dead_res = PressHub_Social_Meta_API_Client::publish_instagram( PressHub_Social_Queue::get_job( (int) $dead_job_id ) );
assert_true( is_wp_error( $dead_res ) && 'instagram_container_status_error' === $dead_res->get_error_code(), 'publish_instagram returns WP_Error on dead container' );
$dead_job_after  = PressHub_Social_Queue::get_job( (int) $dead_job_id );
$dead_meta_after = is_string( $dead_job_after['metadata'] ?? null ) ? json_decode( $dead_job_after['metadata'], true ) : ( $dead_job_after['metadata'] ?? [] );
assert_false( isset( $dead_meta_after['instagram_creation_id'] ), 'publish_instagram clears dead instagram_creation_id on non-auth 4xx status error' );

// Auth error (Graph code 190) preserves instagram_creation_id so token refresh can resume same container
$auth_job_id = PressHub_Social_Queue::enqueue( [
    'post_id'      => $ig_short_post_id,
    'platform'     => 'instagram',
    'engine'       => 'api',
    'post_copy'    => 'Reel auth error test',
    'media_url'    => 'https://example.com/reel-auth.mp4',
    'media_type'   => 'video',
    'status'       => 'pending',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s' ),
    'metadata'     => [ 'instagram_creation_id' => 'auth-333' ],
] );
PressHub_Social_Meta_API_Client::set_http_get( function() {
    return [ 'http_code' => 400, 'error' => [ 'code' => 190, 'type' => 'OAuthException', 'message' => 'Session has expired' ] ];
} );
$auth_res = PressHub_Social_Meta_API_Client::publish_instagram( PressHub_Social_Queue::get_job( (int) $auth_job_id ) );
assert_true( is_wp_error( $auth_res ) && 'instagram_container_auth_error' === $auth_res->get_error_code(), 'publish_instagram returns auth WP_Error on OAuth error' );
$auth_job_after  = PressHub_Social_Queue::get_job( (int) $auth_job_id );
$auth_meta_after = is_string( $auth_job_after['metadata'] ?? null ) ? json_decode( $auth_job_after['metadata'], true ) : ( $auth_job_after['metadata'] ?? [] );
assert_equals( 'auth-333', $auth_meta_after['instagram_creation_id'] ?? '', 'publish_instagram preserves instagram_creation_id on OAuth/auth error' );

// Verify a Reels container still IN_PROGRESS after the short poll budget returns a retryable pending result (issue #218 item 2)
PressHub_Social_Meta_API_Client::set_instagram_poll_budget( 3, 0 );
$ig_jt = static function( int $id ): array {
    $j = PressHub_Social_Queue::get_job( $id );
    $m = is_string( $j['metadata'] ?? null ) ? json_decode( $j['metadata'], true ) : ( $j['metadata'] ?? [] );
    return [ $j, is_array( $m ) ? $m : [] ];
};
$ig_posts = 0;
PressHub_Social_Meta_API_Client::set_http_post( function( $endpoint ) use ( &$ig_posts ) {
    $ig_posts++;
    if ( false !== strpos( $endpoint, '/media_publish' ) ) {
        return [ 'code' => 200, 'body' => [ 'id' => 'media-pending-1' ] ];
    }
    return [ 'code' => 200, 'body' => [ 'id' => 'creation-pending-1' ] ];
} );
$ig_status = 'IN_PROGRESS';
$ig_polls  = 0;
PressHub_Social_Meta_API_Client::set_http_get( function() use ( &$ig_status, &$ig_polls ) {
    $ig_polls++;
    return [ 'status_code' => $ig_status ];
} );

$pending_job_id = (int) PressHub_Social_Queue::enqueue( [
    'post_id'      => $ig_short_post_id,
    'platform'     => 'instagram',
    'engine'       => 'api',
    'post_copy'    => 'Reel pending container test',
    'media_url'    => 'https://example.com/reel-pending.mp4',
    'media_type'   => 'video',
    'status'       => 'pending',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s' ),
    'metadata'     => [ 'keep_key' => 'yes' ],
] );
$pending_res = PressHub_Social_Meta_API_Client::publish_instagram( PressHub_Social_Queue::get_job( $pending_job_id ) );
assert_true( is_wp_error( $pending_res ) && PressHub_Social_Meta_API_Client::INSTAGRAM_PENDING_CODE === $pending_res->get_error_code(), 'publish_instagram returns instagram_container_pending when container is still IN_PROGRESS' );
assert_equals( 3, $ig_polls, 'publish_instagram polls only for the short per-run budget' );
$pending_data = is_wp_error( $pending_res ) ? $pending_res->get_error_data() : [];
assert_equals( PressHub_Social_Meta_API_Client::INSTAGRAM_PENDING_RECHECK_DELAY, (int) ( $pending_data['retry_after'] ?? 0 ), 'pending result carries the short recheck delay' );
[ , $pending_meta ] = $ig_jt( $pending_job_id );
assert_equals( 'creation-pending-1', $pending_meta['instagram_creation_id'] ?? '', 'pending result keeps instagram_creation_id in job metadata' );
assert_true( (int) ( $pending_meta['instagram_container_started_at'] ?? 0 ) > 0, 'container start time is recorded when the Reels container is created' );
assert_equals( 'yes', $pending_meta['keep_key'] ?? '', 'pending result preserves other metadata keys' );

// Dispatcher defers the job: pending, short delay, attempt given back, container reused on the next run
$ig_polls = 0;
$ig_posts = 0;
$disp_pending = PressHub_Social_Dispatcher::dispatch_job( $pending_job_id );
assert_true( is_wp_error( $disp_pending ) && PressHub_Social_Meta_API_Client::INSTAGRAM_PENDING_CODE === $disp_pending->get_error_code(), 'dispatch_job surfaces the pending result' );
[ $deferred_job, $deferred_meta ] = $ig_jt( $pending_job_id );
assert_equals( 'pending', $deferred_job['status'] ?? '', 'deferred Instagram job stays pending' );
assert_equals( 0, (int) ( $deferred_job['attempts'] ?? -1 ), 'deferring an IN_PROGRESS container does not consume an attempt' );
$deferred_delay = strtotime( (string) $deferred_job['scheduled_at'] . ' UTC' ) - time();
assert_true( $deferred_delay > 30 && $deferred_delay <= PressHub_Social_Meta_API_Client::INSTAGRAM_PENDING_RECHECK_DELAY, 'deferred job is rescheduled for a short-delay check' );
assert_true( str_contains( (string) ( $deferred_job['last_error'] ?? '' ), 'still processing' ), 'deferred job records a status note' );
assert_equals( 0, $ig_posts, 'resumed dispatch reuses the stored container instead of creating a new one' );
assert_equals( 'creation-pending-1', $deferred_meta['instagram_creation_id'] ?? '', 'deferred job keeps instagram_creation_id' );

$disp_pending_2 = PressHub_Social_Dispatcher::dispatch_job( $pending_job_id );
[ $deferred_job_2 ] = $ig_jt( $pending_job_id );
assert_true( is_wp_error( $disp_pending_2 ) && 0 === (int) ( $deferred_job_2['attempts'] ?? -1 ), 'repeated pending checks never use up the retry budget' );

$ig_status = 'FINISHED';
$disp_done = PressHub_Social_Dispatcher::dispatch_job( $pending_job_id );
[ $done_job, $done_meta ] = $ig_jt( $pending_job_id );
assert_true( true === $disp_done && 'published' === ( $done_job['status'] ?? '' ), 'deferred job publishes once the container finishes' );
assert_equals( 1, $ig_posts, 'finished container is published with a single media_publish call' );
assert_false( isset( $done_meta['instagram_creation_id'] ) || isset( $done_meta['instagram_container_started_at'] ), 'publish clears the container ID and start time' );

// Waiting cap: a container IN_PROGRESS for longer than INSTAGRAM_CONTAINER_MAX_WAIT fails with a clear error and is forgotten
$ig_status   = 'IN_PROGRESS';
$cap_job_id  = (int) PressHub_Social_Queue::enqueue( [
    'post_id'      => $ig_short_post_id,
    'platform'     => 'instagram',
    'engine'       => 'api',
    'post_copy'    => 'Reel wait cap test',
    'media_url'    => 'https://example.com/reel-cap.mp4',
    'media_type'   => 'video',
    'status'       => 'pending',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s' ),
    'metadata'     => [
        'instagram_creation_id'         => 'creation-stuck-1',
        'instagram_container_started_at' => time() - PressHub_Social_Meta_API_Client::INSTAGRAM_CONTAINER_MAX_WAIT - 5,
    ],
] );
$ig_posts = 0;
$cap_res  = PressHub_Social_Dispatcher::dispatch_job( $cap_job_id );
assert_true( is_wp_error( $cap_res ) && 'instagram_container_timeout' === $cap_res->get_error_code(), 'container past the waiting cap fails with instagram_container_timeout' );
assert_true( is_wp_error( $cap_res ) && str_contains( $cap_res->get_error_message(), 'creation-stuck-1' ), 'waiting-cap error names the stuck container' );
[ $cap_job, $cap_meta ] = $ig_jt( $cap_job_id );
assert_false( isset( $cap_meta['instagram_creation_id'] ) || isset( $cap_meta['instagram_container_started_at'] ), 'waiting cap clears the stuck container ID and start time' );
assert_equals( 1, (int) ( $cap_job['attempts'] ?? 0 ), 'waiting-cap failure goes through the normal retry budget' );
assert_equals( 0, $ig_posts, 'waiting-cap check does not create or publish a container' );

// Container stored without a start time (before this change) starts the clock on its first pending check
$legacy_job_id = (int) PressHub_Social_Queue::enqueue( [
    'post_id'      => $ig_short_post_id,
    'platform'     => 'instagram',
    'engine'       => 'api',
    'post_copy'    => 'Reel legacy container test',
    'media_url'    => 'https://example.com/reel-legacy.mp4',
    'media_type'   => 'video',
    'status'       => 'pending',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s' ),
    'metadata'     => [ 'instagram_creation_id' => 'creation-legacy-1' ],
] );
$legacy_res = PressHub_Social_Meta_API_Client::publish_instagram( PressHub_Social_Queue::get_job( $legacy_job_id ) );
[ , $legacy_meta ] = $ig_jt( $legacy_job_id );
assert_true( is_wp_error( $legacy_res ) && PressHub_Social_Meta_API_Client::INSTAGRAM_PENDING_CODE === $legacy_res->get_error_code(), 'legacy stored container without start time is treated as pending' );
assert_true( abs( (int) ( $legacy_meta['instagram_container_started_at'] ?? 0 ) - time() ) <= 5, 'legacy container gets its start time recorded on first pending check' );

// ERROR containers keep the existing behaviour: fail and forget the container
$ig_status   = 'ERROR';
$err_job_id  = (int) PressHub_Social_Queue::enqueue( [
    'post_id'      => $ig_short_post_id,
    'platform'     => 'instagram',
    'engine'       => 'api',
    'post_copy'    => 'Reel error container test',
    'media_url'    => 'https://example.com/reel-error.mp4',
    'media_type'   => 'video',
    'status'       => 'pending',
    'scheduled_at' => gmdate( 'Y-m-d H:i:s' ),
    'metadata'     => [ 'instagram_creation_id' => 'creation-err-1', 'instagram_container_started_at' => time() ],
] );
$err_res = PressHub_Social_Dispatcher::dispatch_job( $err_job_id );
[ $err_job, $err_meta ] = $ig_jt( $err_job_id );
assert_true( is_wp_error( $err_res ) && 'instagram_container_processing_error' === $err_res->get_error_code(), 'ERROR container still fails with instagram_container_processing_error' );
assert_false( isset( $err_meta['instagram_creation_id'] ) || isset( $err_meta['instagram_container_started_at'] ), 'ERROR container is forgotten so the retry creates a fresh one' );
assert_equals( 1, (int) ( $err_job['attempts'] ?? 0 ), 'ERROR container consumes a normal attempt' );

PressHub_Social_Meta_API_Client::set_instagram_poll_budget( 4, 5 );

// facebook_page_token(): a stored user token is swapped for the Page's own token (cached),
// a lookup error or a token that already is a Page token falls back to the stored token.
$fb_lookups = [];
PressHub_Social_Meta_API_Client::set_http_get( function( $url ) use ( &$fb_lookups ) {
    $fb_lookups[] = $url;
    return [ 'access_token' => 'PAGE_TOKEN_123', 'id' => '1520729961113876' ];
} );
$fb_pt = PressHub_Social_Meta_API_Client::facebook_page_token( '1520729961113876', 'USER_TOKEN_A' );
assert_equals( 'PAGE_TOKEN_123', $fb_pt, 'facebook_page_token swaps a user token for the Page token' );
assert_true( str_contains( $fb_lookups[0] ?? '', '/1520729961113876?fields=access_token&access_token=USER_TOKEN_A' ), 'facebook_page_token asks Graph for the Page access_token with the stored token' );
PressHub_Social_Meta_API_Client::facebook_page_token( '1520729961113876', 'USER_TOKEN_A' );
assert_equals( 1, count( $fb_lookups ), 'facebook_page_token caches the Page token per stored token' );

PressHub_Social_Meta_API_Client::set_http_get( function() {
    return [ 'error' => [ 'message' => 'Error validating access token: The session has been invalidated' ] ];
} );
assert_equals( 'USER_TOKEN_B', PressHub_Social_Meta_API_Client::facebook_page_token( '1520729961113876', 'USER_TOKEN_B' ), 'facebook_page_token falls back to the stored token on a Graph error' );

PressHub_Social_Meta_API_Client::set_http_get( function() {
    return [ 'id' => '1520729961113876' ];
} );
assert_equals( 'PAGE_TOKEN_ALREADY', PressHub_Social_Meta_API_Client::facebook_page_token( '1520729961113876', 'PAGE_TOKEN_ALREADY' ), 'facebook_page_token keeps a token Graph returns no other token for' );
assert_equals( 'TOK', PressHub_Social_Meta_API_Client::facebook_page_token( '', 'TOK' ), 'facebook_page_token without a Page ID returns the stored token' );

// An HTTP 4xx/5xx with no Graph error body falls back, and the fallback is cached briefly.
PressHub_Social_Meta_API_Client::set_http_get( null );
$fb_http_gets = 0;
$GLOBALS['GET_RESPONSE_FILTER'] = function ( $url ) use ( &$fb_http_gets ) {
    if ( ! str_contains( $url, 'fields=access_token' ) ) {
        return null;
    }
    $fb_http_gets++;
    return [ 'response' => [ 'code' => 502 ], 'body' => '<html>Bad Gateway</html>' ];
};
assert_equals( 'USER_TOKEN_C', PressHub_Social_Meta_API_Client::facebook_page_token( '1520729961113876', 'USER_TOKEN_C' ), 'facebook_page_token falls back to the stored token on HTTP 502' );
PressHub_Social_Meta_API_Client::facebook_page_token( '1520729961113876', 'USER_TOKEN_C' );
assert_equals( 1, $fb_http_gets, 'facebook_page_token caches a failed lookup instead of repeating it on every post' );
$GLOBALS['GET_RESPONSE_FILTER'] = function ( $url ) {
    return str_contains( $url, 'fields=access_token' ) ? [ 'response' => [ 'code' => 200 ], 'body' => '{"access_token":"PAGE_TOKEN_D","id":"1520729961113876"}' ] : null;
};
assert_equals( 'PAGE_TOKEN_D', PressHub_Social_Meta_API_Client::facebook_page_token( '1520729961113876', 'USER_TOKEN_D' ), 'facebook_page_token reads the Page token from a real HTTP 200 body' );
unset( $GLOBALS['GET_RESPONSE_FILTER'] );

PressHub_Social_Meta_API_Client::set_http_get( null );
PressHub_Social_Meta_API_Client::set_http_post( null );
delete_option( 'presshub_social_meta_access_token' );
delete_option( 'presshub_social_instagram_account_id' );
delete_option( 'presshub_social_instagram_shorts_enabled' );
delete_option( 'presshub_social_tiktok_shorts_enabled' );


// ----------------------------------------------------------------------
// AI post images (#194): generated images for X, Facebook, Instagram, TikTok
// ----------------------------------------------------------------------
echo "\n--- AI post images ---\n";

$GLOBALS['POSTS_STORE'][760] = [
    'ID'           => 760,
    'post_type'    => 'post',
    'post_status'  => 'publish',
    'post_title'   => 'Πρωινή Ενημέρωση: ΕΕ και Κυπριακό',
    'post_content' => '<p>Η ΕΕ συζητά το Κυπριακό.</p><p>Ο Great Sea Interconnector προχωρά.</p>',
];
$GLOBALS['test_posts'][760] = new WP_Post( $GLOBALS['POSTS_STORE'][760] );
$GLOBALS['POST_THUMBNAILS'][760] = 7601;
update_option( 'presshub_social_engine_twitter', 'automation' );
update_option( 'presshub_social_engine_facebook', 'automation' );
update_option( 'presshub_social_engine_instagram', 'automation' );
update_option( 'presshub_social_worker_mode', 'pull' );
$img_saved_sessions = get_option( 'presshub_social_worker_sessions', [] );
update_option( 'presshub_social_worker_sessions', [ 'twitter' => true, 'facebook' => true, 'instagram' => true, 'tiktok' => true ] );
$img_saved_enabled = get_option( 'presshub_social_enabled_platforms', null );
foreach ( [ 'twitter', 'facebook', 'instagram' ] as $img_p ) {
    PressHub_Social_Settings_Storage::set_platform_enabled( $img_p, true );
}
PressHub_Social_AI_Copywriter::set_ai_caller( fn() => 'Κείμενο ανάρτησης https://presshub.cy/x' );
delete_option( 'presshub_social_post_images_enabled' );

// Settings: defaults and clamps.
assert_false( PressHub_Social_Settings_Storage::get_post_images_enabled(), 'AI post images are off by default' );
assert_equals( 1, PressHub_Social_Settings_Storage::get_post_images_count( 'twitter' ), 'X gets 1 AI image by default' );
assert_equals( 4, PressHub_Social_Settings_Storage::sanitize_post_images_count( 9, 'twitter' ), 'X images clamp to 4' );
assert_equals( 4, PressHub_Social_Settings_Storage::sanitize_post_images_count( 7, 'facebook' ), 'Facebook images clamp to 4' );
assert_equals( 1, PressHub_Social_Settings_Storage::sanitize_post_images_count( 3, 'instagram' ), 'Instagram takes 1 image (the card background)' );
assert_equals( 1, PressHub_Social_Settings_Storage::sanitize_post_images_count( 3, 'tiktok' ), 'TikTok takes 1 image (the video background)' );
assert_equals( 0, PressHub_Social_Settings_Storage::sanitize_post_images_count( -2, 'twitter' ), 'Image count never goes below 0' );
assert_true( str_contains( PressHub_Social_Settings_Storage::get_post_images_prompt(), '{count}' ), 'The planning prompt is seeded from social_post_images.txt' );
assert_true( str_contains( PressHub_Social_Settings_Storage::get_post_image_style(), '{orientation}' ), 'The image prompt is seeded from social_post_image_style.txt' );

// Library: build_prompt and key.
assert_equals( 'A flag. Photo, landscape 16:9 frame.', PressHub_Social_Image_Library::build_prompt( '{description} Photo, {orientation} frame.', 'A flag.', '16:9' ), 'Image prompt fills description and orientation' );
assert_equals( 'A flag. Photo.', PressHub_Social_Image_Library::build_prompt( 'Photo.', 'A flag.', '1:1' ), 'Without {description} the description comes first' );
assert_true( PressHub_Social_Image_Library::key( 'a', 's', '16:9' ) !== PressHub_Social_Image_Library::key( 'a', 's', '1:1' ), 'The same picture in another aspect is a different image' );

// Off: jobs carry no image state and nothing is scheduled.
PressHub_Social_Queue::reset_memory_store();
$GLOBALS['SCHEDULED_EVENTS'] = [];
$off_ids = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'twitter' ] );
assert_equals( '', PressHub_Social_Post_Images::state( PressHub_Social_Queue::get_job( $off_ids[0] ) ), 'With AI post images off a job has no image state' );
assert_equals( 0, count( array_filter( $GLOBALS['SCHEDULED_EVENTS'], fn( $e ) => PressHub_Social_Post_Images::PREPARE_HOOK === $e['hook'] ) ), 'With AI post images off nothing is prepared' );

// On: X wants 2, Facebook 1, Instagram 1.
update_option( 'presshub_social_post_images_enabled', true );
update_option( 'presshub_social_post_images_twitter', 2 );
update_option( 'presshub_social_post_images_facebook', 1 );
update_option( 'presshub_social_post_images_instagram', 1 );
PressHub_Social_Queue::reset_memory_store();
$GLOBALS['SCHEDULED_EVENTS'] = [];
delete_post_meta( 760, PressHub_Social_Image_Library::META );
delete_post_meta( 760, PressHub_Social_Post_Images::PLAN_META );
$img_ids = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'twitter', 'facebook', 'instagram' ] );
assert_equals( 3, count( $img_ids ), 'Three jobs queued with AI post images on' );
[ $tw_id, $fb_id, $ig_id ] = $img_ids;
$tw_job  = PressHub_Social_Queue::get_job( $tw_id );
$tw_meta = json_decode( (string) $tw_job['metadata'], true )['post_images'] ?? [];
assert_equals( 'pending', $tw_meta['state'] ?? '', 'A new job waits for its AI images' );
assert_equals( 2, $tw_meta['count'] ?? 0, 'The X job asks for 2 images' );
assert_equals( '16:9', $tw_meta['aspect'] ?? '', 'X images are 16:9' );
assert_equals( '1:1', json_decode( (string) PressHub_Social_Queue::get_job( $ig_id )['metadata'], true )['post_images']['aspect'] ?? '', 'Instagram images are 1:1' );
assert_equals( 'http://example.test/?attachment_id=7601', $tw_job['media_url'], 'The featured image stays as the fallback' );
$img_prep = array_values( array_filter( $GLOBALS['SCHEDULED_EVENTS'], fn( $e ) => PressHub_Social_Post_Images::PREPARE_HOOK === $e['hook'] ) );
assert_equals( [ 760 ], $img_prep[0]['args'] ?? null, 'Enqueuing schedules the image preparation for the post' );

// Claim: a job waiting for its images is not claimed and stays pending.
foreach ( $img_ids as $iid ) {
    PressHub_Social_Queue::update_job( $iid, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
}
$img_req = new WP_REST_Request( 'POST', '/worker/claim' );
$img_req->set_param( 'sessions', [ 'twitter' => true, 'facebook' => true, 'instagram' => true ] );
$img_req->set_param( 'limit', 5 );
$img_claim = PressHub_Social_REST_Controller::handle_claim( $img_req );
assert_equals( 0, count( $img_claim['jobs'] ), 'Jobs waiting for AI images are not claimed' );
assert_equals( 'pending', PressHub_Social_Queue::get_job( $tw_id )['status'], 'A job waiting for AI images stays pending' );

// Prepare: one plan call, images shared between X and Facebook (same aspect).
$plan_calls = [];
PressHub_Social_Post_Images::set_ai_caller( function ( $sys, $user ) use ( &$plan_calls ) {
    $plan_calls[] = $user;
    return '```json' . "\n" . '{"images":["EU flag over Nicosia old town at dawn","Undersea power cable on a ship deck at Limassol port","Extra description"]}' . "\n```";
} );
$gen_calls = [];
PressHub_Social_Image_Library::set_generator( function ( $prompt, $aspect, $post_id, $context ) use ( &$gen_calls ) {
    $gen_calls[] = [ $prompt, $aspect ];
    $n = count( $gen_calls );
    return [ 'url' => 'https://example.com/uploads/post-img-' . $n . '.png', 'id' => 8800 + $n ];
} );
PressHub_Social_Post_Images::prepare_post( 760 );
assert_equals( 1, count( $plan_calls ), 'One planning call for all platforms of the post' );
assert_true( str_contains( $plan_calls[0] ?? '', 'Πρωινή Ενημέρωση' ) && str_contains( $plan_calls[0] ?? '', 'Great Sea Interconnector' ) && ! str_contains( $plan_calls[0] ?? '', '<p>' ), 'The plan sees the title and the plain article text' );
assert_true( str_contains( $plan_calls[0] ?? '', 'Plan 2 image(s)' ), 'The plan asks for the most images any platform wants' );
assert_equals( 3, count( $gen_calls ), 'X and Facebook share their 16:9 images; Instagram gets its own 1:1 image' );
assert_true( str_contains( $gen_calls[0][0], 'EU flag over Nicosia' ) && str_contains( $gen_calls[0][0], 'landscape 16:9' ), 'The image prompt has the description and the platform shape' );
$tw_job  = PressHub_Social_Queue::get_job( $tw_id );
$tw_meta = json_decode( (string) $tw_job['metadata'], true )['post_images'] ?? [];
assert_equals( 'ready', $tw_meta['state'] ?? '', 'The X job is ready after preparation' );
assert_equals( 2, count( $tw_meta['images'] ?? [] ), 'The X job has 2 images' );
assert_equals( 'https://example.com/uploads/post-img-1.png', $tw_job['media_url'], 'The first AI image becomes the job media' );
assert_equals( [ 'https://example.com/uploads/post-img-1.png' ], PressHub_Social_Post_Images::media_urls( PressHub_Social_Queue::get_job( $fb_id ) ), 'Facebook reuses the first X image' );
assert_equals( 'https://example.com/uploads/post-img-3.png', PressHub_Social_Queue::get_job( $ig_id )['media_url'], 'Instagram gets its square image' );
assert_equals( 3, count( PressHub_Social_Image_Library::all( 760 ) ), 'The post library holds the 3 images for later reuse' );

// Running again makes no new call.
PressHub_Social_Post_Images::prepare_post( 760 );
assert_equals( 1, count( $plan_calls ), 'A prepared post is not planned again' );
assert_equals( 3, count( $gen_calls ), 'A prepared post makes no new images' );

// Claim now hands out the jobs with every image.
$img_claim = PressHub_Social_REST_Controller::handle_claim( $img_req );
$claimed_by_platform = [];
foreach ( $img_claim['jobs'] as $cj ) {
    $claimed_by_platform[ $cj['platform'] ] = $cj;
}
assert_equals( 3, count( $img_claim['jobs'] ), 'Prepared jobs are claimed' );
assert_equals( [ 'https://example.com/uploads/post-img-1.png', 'https://example.com/uploads/post-img-2.png' ], $claimed_by_platform['twitter']['media_urls'] ?? null, 'The claimed X job carries both images' );
assert_equals( 'https://example.com/uploads/post-img-3.png', $claimed_by_platform['instagram']['media_url'] ?? null, 'The claimed Instagram job uses its AI image for the card' );

// A new job for the same post reuses the stored plan and images.
PressHub_Social_Queue::reset_memory_store();
$again = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'twitter' ] );
PressHub_Social_Post_Images::prepare_post( 760 );
assert_equals( 1, count( $plan_calls ), 'A repost reuses the stored image plan' );
assert_equals( 3, count( $gen_calls ), 'A repost reuses the stored images' );
assert_equals( 2, count( PressHub_Social_Post_Images::media_urls( PressHub_Social_Queue::get_job( $again[0] ) ) ), 'The repost has its 2 images' );

// Facebook API: several images become one multi-photo post.
update_option( 'presshub_social_engine_facebook', 'api' );
update_option( 'presshub_social_meta_page_id', 'page-1' );
update_option( 'presshub_social_meta_access_token', 'tok-1' );
update_option( 'presshub_social_post_images_facebook', 2 );
PressHub_Social_Queue::reset_memory_store();
$fb_api_ids = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'facebook' ] );
$fb_waiting = PressHub_Social_Dispatcher::dispatch_job( $fb_api_ids[0] );
assert_true( is_wp_error( $fb_waiting ) && PressHub_Social_Post_Images::PENDING_CODE === $fb_waiting->get_error_code(), 'An API job waiting for AI images is not sent' );
assert_equals( 'pending', PressHub_Social_Queue::get_job( $fb_api_ids[0] )['status'], 'The waiting API job stays pending' );
PressHub_Social_Post_Images::prepare_post( 760 );
$GLOBALS['CAPTURED_REQUESTS'] = [];
$fb_photo_n = 0;
$GLOBALS['CAPTURE_FILTER'] = function ( $pre, $req ) use ( &$fb_photo_n ) {
    [ $url ] = $req;
    if ( str_contains( $url, '/photos' ) ) {
        $fb_photo_n++;
        return [ 'response' => [ 'code' => 200 ], 'body' => '{"id":"photo-' . $fb_photo_n . '"}' ];
    }
    if ( str_contains( $url, '/feed' ) ) {
        return [ 'response' => [ 'code' => 200 ], 'body' => '{"id":"page-1_post-9"}' ];
    }
    return null;
};
$GLOBALS['GET_RESPONSE_FILTER'] = function ( $url ) {
    return str_contains( $url, '/page-1?fields=access_token&access_token=tok-1' )
        ? [ 'response' => [ 'code' => 200 ], 'body' => '{"access_token":"page-tok-1","id":"page-1"}' ]
        : null;
};
$fb_sent = PressHub_Social_Dispatcher::dispatch_job( $fb_api_ids[0] );
unset( $GLOBALS['CAPTURE_FILTER'], $GLOBALS['GET_RESPONSE_FILTER'] );
$fb_reqs = $GLOBALS['CAPTURED_REQUESTS'];
assert_equals( [ 'page-tok-1', 'page-tok-1', 'page-tok-1' ], array_map( fn( $r ) => $r[1]['body']['access_token'] ?? '', $fb_reqs ), 'Every multi-photo request posts with the Page token derived from the stored user token' );
assert_true( true === $fb_sent, 'The Facebook multi-photo post is published' );
assert_equals( 3, count( $fb_reqs ), 'Two unpublished photo uploads and one feed post' );
assert_equals( 'false', $fb_reqs[0][1]['body']['published'] ?? '', 'Photos are uploaded unpublished' );
assert_equals( 'https://example.com/uploads/post-img-2.png', $fb_reqs[1][1]['body']['url'] ?? '', 'The second photo is the second AI image' );
assert_true( str_ends_with( $fb_reqs[2][0], '/page-1/feed' ) && '{"media_fbid":"photo-2"}' === ( $fb_reqs[2][1]['body']['attached_media[1]'] ?? '' ), 'The feed post attaches both photos' );
assert_equals( 'published', PressHub_Social_Queue::get_job( $fb_api_ids[0] )['status'], 'The job is published' );
update_option( 'presshub_social_engine_facebook', 'automation' );
update_option( 'presshub_social_post_images_facebook', 1 );

// Every image fails: the job posts with the featured image.
PressHub_Social_Queue::reset_memory_store();
delete_post_meta( 760, PressHub_Social_Image_Library::META );
PressHub_Social_Image_Library::set_generator( fn() => new WP_Error( 'image_http_429', 'Image API rate limit' ) );
$fail_ids = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'twitter' ] );
PressHub_Social_Post_Images::prepare_post( 760 );
$fail_job = PressHub_Social_Queue::get_job( $fail_ids[0] );
assert_equals( 'fallback', PressHub_Social_Post_Images::state( $fail_job ), 'A job whose images all fail falls back' );
assert_equals( 'http://example.test/?attachment_id=7601', $fail_job['media_url'], 'The fallback job keeps the featured image' );
assert_true( str_contains( (string) ( json_decode( (string) $fail_job['metadata'], true )['post_images']['reason'] ?? '' ), 'rate limit' ), 'The fallback records why' );
assert_true( PressHub_Social_Post_Images::ready_to_post( $fail_job ), 'A fallback job can be posted' );

// A plan that keeps failing: retried with backoff, then the jobs fall back.
PressHub_Social_Queue::reset_memory_store();
delete_post_meta( 760, PressHub_Social_Post_Images::PLAN_META );
delete_post_meta( 760, PressHub_Social_Post_Images::PREP_ATTEMPTS_META );
PressHub_Social_Post_Images::set_ai_caller( fn() => 'not json at all' );
$plan_fail_ids = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'twitter' ] );
$GLOBALS['SCHEDULED_EVENTS'] = [];
$GLOBALS['NEXT_SCHEDULED'] = [];
PressHub_Social_Post_Images::prepare_post( 760 );
assert_equals( 'pending', PressHub_Social_Post_Images::state( PressHub_Social_Queue::get_job( $plan_fail_ids[0] ) ), 'After one failed plan the job still waits' );
assert_equals( 1, count( array_filter( $GLOBALS['SCHEDULED_EVENTS'], fn( $e ) => PressHub_Social_Post_Images::PREPARE_HOOK === $e['hook'] && $e['time'] > time() ) ), 'A failed plan is retried later' );
for ( $i = 0; $i < PressHub_Social_Settings_Storage::get_max_attempts(); $i++ ) {
    PressHub_Social_Post_Images::prepare_post( 760 );
}
assert_equals( 'fallback', PressHub_Social_Post_Images::state( PressHub_Social_Queue::get_job( $plan_fail_ids[0] ) ), 'After the retry budget the job falls back to the featured image' );

// A misplaced bracket is repaired; an unusable or empty answer is asked for again, with the reason.
assert_equals( [ 'a', 'b' ], PressHub_Social_Post_Images::parse_plan( '{"images":["a","b"]]}', 3 ), 'The image plan repairs a misplaced closing bracket' );
assert_true( is_wp_error( PressHub_Social_Post_Images::parse_plan( '{"images":["a","b"', 3 ) ), 'A cut-off image plan is rejected, not repaired' );
$broken_images = PressHub_Social_Post_Images::parse_plan( '{"images":["a" "b"]}', 3 );
assert_true( is_wp_error( $broken_images ) && str_contains( $broken_images->get_error_message(), 'not valid JSON' ), 'Broken image plan JSON is reported as invalid JSON' );
foreach ( [ 'invalid' => '{"images":[]}', 'empty' => '' ] as $kind_of_bad => $first_answer ) {
    PressHub_Social_Queue::reset_memory_store();
    delete_post_meta( 760, PressHub_Social_Post_Images::PLAN_META );
    delete_post_meta( 760, PressHub_Social_Post_Images::PREP_ATTEMPTS_META );
    $img_retry_prompts = [];
    PressHub_Social_Post_Images::set_ai_caller( function ( $sys, $user ) use ( &$img_retry_prompts, $first_answer ) {
        $img_retry_prompts[] = $user;
        return 1 === count( $img_retry_prompts ) ? $first_answer : '{"images":["Harbour at dawn","Power cable on a ship"]}';
    } );
    PressHub_Social_Image_Library::set_generator( fn( $prompt, $aspect, $post_id, $context ) => [ 'url' => 'https://example.com/uploads/retry-' . md5( $prompt . $aspect ) . '.png', 'id' => 8900 ] );
    $img_retry_ids = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'twitter' ] );
    PressHub_Social_Post_Images::prepare_post( 760 );
    assert_equals( 2, count( $img_retry_prompts ), "An {$kind_of_bad} image plan answer is asked for once more in the same run" );
    assert_true( str_contains( $img_retry_prompts[1] ?? '', 'Your previous answer was unusable:' ) && ! str_contains( $img_retry_prompts[0], 'previous answer' ), "The {$kind_of_bad} image plan retry says why" );
    assert_equals( 'ready', PressHub_Social_Post_Images::state( PressHub_Social_Queue::get_job( $img_retry_ids[0] ) ), "The images are made from the second answer after an {$kind_of_bad} one" );
}

// End to end for the image plan: records and rows marked, repair noted.
PressHub_Social_Queue::reset_memory_store();
delete_post_meta( 760, PressHub_Social_Post_Images::PLAN_META );
delete_post_meta( 760, PressHub_Social_Post_Images::PREP_ATTEMPTS_META );
$img_e2e = [];
PressHub_Social_Post_Images::set_ai_caller( function () use ( &$img_e2e ) {
    $answer    = 0 === count( $img_e2e ) ? '{"images":[]}' : '{"images":["Harbour at dawn","Power cable on a ship"]]}';
    $call      = presshub_test_plan_call( 'post_images_plan', 'social_post_images', $answer );
    $img_e2e[] = $call;
    return $call;
} );
$img_e2e_ids = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'twitter' ] );
PressHub_Social_Post_Images::prepare_post( 760 );
$img_first  = PressHub_AI_Generation_Recorder::get( $img_e2e[0]['record_id'] ?? 0 );
$img_second = PressHub_AI_Generation_Recorder::get( $img_e2e[1]['record_id'] ?? 0 );
assert_true( 'error' === ( $img_first['status'] ?? '' ) && 'error' === ( presshub_test_token_row( $img_e2e[0]['log_id'] )['status'] ?? '' ), 'Image plan: the unusable answer\'s record and row are marked error' );
assert_equals( 'JSON repaired: dropped 1 misplaced ].', $img_second['error_message'] ?? '', 'Image plan: the repaired answer is noted on its record' );
assert_equals( 'ready', PressHub_Social_Post_Images::state( PressHub_Social_Queue::get_job( $img_e2e_ids[0] ) ), 'Image plan: the images are made from the repaired answer' );

// A job still waiting long after its posting time is released.
PressHub_Social_Queue::reset_memory_store();
$late_ids = PressHub_Social_Dispatcher::enqueue_for_post( 760, [ 'twitter' ] );
PressHub_Social_Queue::update_job( $late_ids[0], [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - PressHub_Social_Post_Images::MAX_WAIT - 120 ) ] );
$late_claim = PressHub_Social_REST_Controller::handle_claim( $img_req );
assert_equals( 1, count( $late_claim['jobs'] ), 'A job waiting too long is claimed with the featured image' );
assert_equals( 'http://example.test/?attachment_id=7601', $late_claim['jobs'][0]['media_url'] ?? '', 'The released job posts the featured image' );
assert_false( isset( $late_claim['jobs'][0]['media_urls'] ), 'The released job carries no AI images' );

// Plan parsing.
assert_equals( [ 'a', 'b' ], PressHub_Social_Post_Images::parse_plan( '["a","b","c"]', 2 ), 'A bare JSON list is accepted and capped' );
assert_equals( [ 'x y' ], PressHub_Social_Post_Images::parse_plan( 'Here: {"images":[" x   y ", ""]}', 4 ), 'Prose around the JSON and blank items are ignored' );
assert_true( is_wp_error( PressHub_Social_Post_Images::parse_plan( '{"images":[]}', 2 ) ), 'An empty image list is an error' );

// Shorts images go through the shared library too.
assert_true( method_exists( 'PressHub_Social_Image_Library', 'get_or_create' ), 'Shorts and posts share the image library' );

PressHub_Social_Post_Images::set_ai_caller( null );
PressHub_Social_Image_Library::set_generator( null );
PressHub_Social_AI_Copywriter::set_ai_caller( null );
delete_option( 'presshub_social_post_images_enabled' );
delete_option( 'presshub_social_post_images_twitter' );
delete_option( 'presshub_social_post_images_facebook' );
delete_option( 'presshub_social_post_images_instagram' );
delete_option( 'presshub_social_meta_page_id' );
delete_option( 'presshub_social_meta_access_token' );
update_option( 'presshub_social_worker_sessions', $img_saved_sessions );
null === $img_saved_enabled ? delete_option( 'presshub_social_enabled_platforms' ) : update_option( 'presshub_social_enabled_platforms', $img_saved_enabled );

// ----------------------------------------------------------------------
// Contract (#237): the settings clean-up leaves the worker claim payload
// byte-identical for the same settings.
// ----------------------------------------------------------------------
echo "\n--- Contract: worker claim payload unchanged by the settings clean-up (#237) ---\n";
foreach ( [ 'class-settings-registry.php', 'class-settings-transfer.php', 'class-settings-cleanup.php' ] as $cleanup_f ) {
    require_once $base_dir . '/includes/' . $cleanup_f;
}
$GLOBALS['POSTS_STORE'][502] = [
    'ID'           => 502,
    'post_type'    => 'post',
    'post_status'  => 'publish',
    'post_title'   => 'Contract article',
    'post_content' => 'Content...',
];
// A site as 2.44.0 left it: the removed options still hold values.
$contract_removed = [
    'presshub_social_github_token'          => 'ghp_contract_social_token_0001',
    'presshub_social_twitter_api_key'       => 'contract-consumer-key',
    'presshub_social_twitter_api_secret'    => 'contract-consumer-secret',
    'presshub_social_twitter_access_secret' => 'contract-access-secret',
    'presshub_ai_briefing_podcast_prompt_1' => 'Old single-presenter prompt',
    'presshub_ai_briefing_podcast_prompt_2' => 'Old two-presenter prompt',
    'presshub_ai_briefing_podcast_prompt_3' => 'Old three-presenter prompt',
];
foreach ( $contract_removed as $k => $v ) {
    update_option( $k, $v );
}
delete_option( PressHub_Settings_Cleanup::VERSION_OPTION );
$contract_claim = static function () {
    $id  = PressHub_Social_Queue::enqueue( [
        'post_id'      => 502,
        'platform'     => 'twitter',
        'engine'       => 'automation',
        'post_copy'    => 'Contract tweet',
        'scheduled_at' => '2026-01-01 00:00:00',
    ] );
    $res = PressHub_Social_REST_Controller::handle_claim( new WP_REST_Request( 'POST', '/worker/claim' ) );
    $job = null;
    foreach ( (array) ( $res['jobs'] ?? [] ) as $j ) {
        if ( (int) ( $j['id'] ?? $j['job_id'] ?? 0 ) === (int) $id ) {
            $job = $j;
        }
    }
    // Only the job's own id and timestamps may differ between two claims.
    $strip = static function ( $v ) use ( &$strip ) {
        if ( ! is_array( $v ) ) {
            return $v;
        }
        $out = [];
        foreach ( $v as $k => $x ) {
            if ( in_array( (string) $k, [ 'id', 'job_id', 'created_at', 'updated_at', 'claimed_at', 'locked_at', 'lease_expires_at', 'attempts' ], true ) ) {
                continue;
            }
            $out[ $k ] = $strip( $x );
        }
        return $out;
    };
    return [ null !== $job, json_encode( [ 'ok' => $res['ok'] ?? null, 'job' => $strip( $job ) ] ) ];
};
[ $before_found, $before_json ] = $contract_claim();
$cleanup_report = PressHub_Settings_Cleanup::run();
[ $after_found, $after_json ] = $contract_claim();
assert_true( $before_found && $after_found, 'Contract: the worker claims the job before and after the clean-up' );
assert_true( ! empty( $cleanup_report['removed'] ) && 7 === count( array_filter( $cleanup_report['removed'] ) ), 'Contract: the clean-up removed the seven stored rows' );
assert_equals( $before_json, $after_json, 'Contract: worker claim payload is byte-identical before and after the clean-up' );
foreach ( array_keys( $contract_removed ) as $k ) {
    delete_option( $k );
}
delete_option( PressHub_Settings_Cleanup::VERSION_OPTION );

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
