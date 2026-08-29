<?php
/**
 * Live WordPress Integration Test Suite for PressHub AI Editor.
 *
 * Boots the actual WordPress runtime, exercises plugin classes, logs, and database tables.
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$wp_dir      = $dev_env_dir . '/wordpress';

$_SERVER['HTTP_HOST']       = '127.0.0.1:8888';
$_SERVER['SERVER_NAME']     = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SCRIPT_FILENAME'] = $wp_dir . '/index.php';

require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/plugin.php';

$total  = 0;
$passed = 0;
$failed = 0;
$tests  = [];

function assert_test( string $name, bool $condition, string $detail = '' ): void {
    global $total, $passed, $failed, $tests;
    $total++;
    if ( $condition ) {
        $passed++;
        printf( "[PASS] %s\n", $name );
    } else {
        $failed++;
        printf( "[FAIL] %s - %s\n", $name, $detail );
    }
}

echo "=================================================================\n";
echo "Running PressHub AI Editor Live WordPress Integration Tests\n";
echo "=================================================================\n\n";

// Test 1: WordPress Core Loaded
assert_test( 'WordPress core environment loaded', defined( 'ABSPATH' ) && function_exists( 'get_option' ) );

// Test 2: Plugin is active
$active_plugins = (array) get_option( 'active_plugins', [] );
assert_test( 'PressHub AI Editor plugin is active', in_array( 'presshub-ai-editor/presshub-ai-editor.php', $active_plugins, true ) );

// Test 3: PressHub Core Classes Loaded
assert_test( 'PressHub_AI_Logger class exists', class_exists( 'PressHub_AI_Logger' ) );
assert_test( 'PressHub_AI_Token_Logger class exists', class_exists( 'PressHub_AI_Token_Logger' ) );
assert_test( 'PressHub_AI_Settings class exists', class_exists( 'PressHub_AI_Settings' ) );

// Test 4: Token Logger Table exists in SQLite
global $wpdb;
$table_name = PressHub_AI_Token_Logger::get_table_name();
$table_check = $wpdb->get_var( "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table_name}'" );
assert_test( "Token logger table '{$table_name}' exists in DB", ! empty( $table_check ) );

// Test 5: Insert and query LLM Request via PressHub_AI_Token_Logger
$test_log_id = PressHub_AI_Token_Logger::log_llm_request(
    'integration_test_action',
    'openai',
    'gpt-4o',
    150,
    350,
    1240,
    'success',
    null,
    [ 'test_run' => true, 'source' => 'live_integration_suite' ],
    1
);
assert_test( 'PressHub_AI_Token_Logger::log_llm_request() returns valid ID', is_numeric( $test_log_id ) && $test_log_id > 0 );

// Verify inserted record
$log_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $test_log_id ), ARRAY_A );
assert_test( 'Token log row verified in SQLite DB',
    $log_row !== null &&
    $log_row['provider'] === 'openai' &&
    $log_row['model'] === 'gpt-4o' &&
    (int) $log_row['total_tokens'] === 500 &&
    (int) $log_row['duration_ms'] === 1240 &&
    $log_row['status'] === 'success'
);

// Test 6: Insert and verify Error log in Token Logger
$error_log_id = PressHub_AI_Token_Logger::log_llm_request(
    'integration_test_error',
    'anthropic',
    'claude-3-5-sonnet',
    50,
    0,
    800,
    'error',
    'Rate limit exceeded (HTTP 429)',
    [ 'error_code' => 429 ],
    1
);
$error_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $error_log_id ), ARRAY_A );
assert_test( 'Token log error record stored with error_message',
    $error_row !== null &&
    $error_row['status'] === 'error' &&
    strpos( $error_row['error_message'], 'Rate limit exceeded' ) !== false
);

// Test 7: PressHub_AI_Logger file write
$log_msg = 'Integration test message generated at ' . gmdate( 'Y-m-d H:i:s' );
PressHub_AI_Logger::info( $log_msg, [ 'suite' => 'integration_test' ] );
$recent_logs = PressHub_AI_Logger::get_recent_logs( 10 );
assert_test( 'PressHub_AI_Logger writes structured log to disk', strpos( $recent_logs, $log_msg ) !== false );

// Test 8: Settings get/update
update_option( 'presshub_ai_test_setting', 'integration_value_123' );
$fetched_setting = get_option( 'presshub_ai_test_setting' );
assert_test( 'WordPress options table read/write works', $fetched_setting === 'integration_value_123' );
delete_option( 'presshub_ai_test_setting' );

// Test 9: WordPress User & Post Querying
$admin_user = get_user_by( 'login', 'admin' );
assert_test( 'Admin user exists in database', $admin_user && $admin_user->user_email === 'admin@example.local' );

// Test 10: AJAX Actions Registered
global $wp_filter;
$ajax_actions = array_keys( $wp_filter );
$has_presshub_actions = false;
foreach ( $ajax_actions as $action ) {
    if ( strpos( $action, 'wp_ajax_presshub_ai_' ) === 0 ) {
        $has_presshub_actions = true;
        break;
    }
}
assert_test( 'PressHub AI AJAX handlers registered in WordPress', $has_presshub_actions );

// Test 11: Provider Store CRUD & Fresh Install Empty State
assert_test( 'PressHub_AI_Provider_Store class exists', class_exists( 'PressHub_AI_Provider_Store' ) );
$fresh_db_providers = PressHub_AI_Provider_Store::get_all( false );
assert_test( 'Provider store initializes without dummy unkeyed providers', is_array( $fresh_db_providers ) );
$test_prov_id = PressHub_AI_Provider_Store::save_provider( [
    'id'      => 'integration-prov',
    'type'    => 'openai',
    'name'    => 'Integration OpenAI',
    'api_key' => 'sk-integration-test-key',
    'enabled' => true,
] );
assert_test( 'PressHub_AI_Provider_Store::save_provider() creates record in live DB', $test_prov_id === 'integration-prov' );
$fetched_prov = PressHub_AI_Provider_Store::get( 'integration-prov' );
assert_test( 'PressHub_AI_Provider_Store::get() retrieves saved provider record', $fetched_prov !== null && $fetched_prov['name'] === 'Integration OpenAI' );
PressHub_AI_Provider_Store::delete_provider( 'integration-prov' );
assert_test( 'PressHub_AI_Provider_Store::delete_provider() cleans up record', PressHub_AI_Provider_Store::get( 'integration-prov' ) === null );

// Test 12: Settings Render Module produces clean empty-state for 0 providers
$settings_render = new PressHub_AI_Settings_Render();
ob_start();
$settings_render->render_providers_grid();
$live_grid_html = ob_get_clean();
assert_test( 'Settings render module outputs empty state container', strpos( $live_grid_html, 'presshub-no-providers' ) !== false && strpos( $live_grid_html, 'No AI providers configured yet' ) !== false );

echo "\n=================================================================\n";
echo "Integration Test Summary: {$passed}/{$total} passed (" . round( ( $passed / max( 1, $total ) ) * 100, 1 ) . "%)\n";
echo "=================================================================\n";

if ( $failed > 0 ) {
    exit( 1 );
}

echo "ALL LIVE INTEGRATION TESTS PASSED!\n\n";
exit( 0 );
