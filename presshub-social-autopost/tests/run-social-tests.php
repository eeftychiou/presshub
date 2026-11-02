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
if ( file_exists( $base_dir . '/includes/class-social-ai-copywriter.php' ) ) {
    require_once $base_dir . '/includes/class-social-ai-copywriter.php';
}
require_once $base_dir . '/includes/class-social-activator.php';
require_once $base_dir . '/includes/class-social-queue.php';
require_once $base_dir . '/includes/class-social-meta-api-client.php';
require_once $base_dir . '/includes/class-social-worker-client.php';
require_once $base_dir . '/includes/class-social-dispatcher.php';
require_once $base_dir . '/includes/class-social-metabox.php';
require_once $base_dir . '/includes/class-social-queue-admin.php';
require_once $base_dir . '/includes/class-social-ajax-handlers.php';

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
update_option( 'presshub_social_twitter_max_chars', 120 );
PressHub_Social_AI_Copywriter::set_ai_caller( function( $sys, $user, $config ) {
    return str_repeat( 'Breaking news update ', 10 ) . 'https://presshub.ai/story #News';
} );
$ai_tweet = PressHub_Social_AI_Copywriter::generate_from_data( [
    'title' => 'Big Story',
    'url'   => 'https://presshub.ai/story',
], 'twitter' );
assert_true( is_string( $ai_tweet ) && mb_strlen( $ai_tweet ) <= 120, 'AI copywriter truncates tweet to configured max_chars' );
assert_true( is_string( $ai_tweet ) && str_contains( $ai_tweet, 'https://presshub.ai/story' ), 'AI copywriter preserves article URL during truncation' );
PressHub_Social_AI_Copywriter::set_ai_caller( null );

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
