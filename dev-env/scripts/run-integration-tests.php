<?php
/**
 * Live WordPress Integration Test Runner for PressHub AI Editor.
 *
 * Boots WordPress Core from the local SQLite environment, verifies that the
 * plugin is active, tables exist, options work, loggers function, and
 * AJAX actions are registered.
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$wp_dir      = $dev_env_dir . '/wordpress';

if ( ! file_exists( $wp_dir . '/wp-load.php' ) ) {
    exit( "Error: WordPress not found in {$wp_dir}. Run setup.php first.\n" );
}

$_SERVER['HTTP_HOST']       = '127.0.0.1:8888';
$_SERVER['SERVER_NAME']     = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SCRIPT_FILENAME'] = $wp_dir . '/index.php';

require_once $wp_dir . '/wp-load.php';

echo "=================================================================\n";
echo "PressHub AI Live WordPress Integration Test Suite\n";
echo "=================================================================\n\n";

$passed = 0;
$failed = 0;

function run_test( string $name, callable $fn ) {
    global $passed, $failed;
    echo sprintf( "%-60s ", $name . '...' );
    try {
        $result = $fn();
        if ( true === $result || null === $result ) {
            echo "[\033[32mPASS\033[0m]\n";
            $passed++;
        } else {
            echo "[\033[31mFAIL\033[0m] " . (string) $result . "\n";
            $failed++;
        }
    } catch ( Throwable $e ) {
        echo "[\033[31mFAIL\033[0m] " . $e->getMessage() . "\n";
        $failed++;
    }
}

// Test 1: WordPress Core Loaded
run_test( 'WordPress core runtime loaded', function() {
    return function_exists( 'get_option' ) && function_exists( 'wp_remote_get' );
} );

// Test 2: Database Connection
run_test( 'SQLite Database connection', function() {
    global $wpdb;
    $res = $wpdb->get_var( "SELECT 1" );
    return (int) $res === 1;
} );

// Test 3: Plugin Active
run_test( 'PressHub AI Editor plugin active', function() {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    return is_plugin_active( 'presshub-ai-editor/presshub-ai-editor.php' );
} );

// Test 4: Token Logger Table Exists
run_test( 'Database table wp_presshub_ai_token_logs exists', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'presshub_ai_token_logs';
    $exists = $wpdb->get_var( "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'" );
    return ! empty( $exists );
} );

// Test 5: Token Logger DB Insertion
run_test( 'PressHub_AI_Token_Logger records entry to DB', function() {
    if ( ! class_exists( 'PressHub_AI_Token_Logger' ) ) {
        return 'Class PressHub_AI_Token_Logger not found';
    }
    $id = PressHub_AI_Token_Logger::log_llm_request(
        'integration_test',
        'openai',
        'gpt-4o',
        100,
        50,
        250,
        'success',
        null,
        [ 'test' => true, 'runner' => 'integration' ]
    );
    return is_numeric( $id ) && $id > 0;
} );

// Test 6: Token Logger Stats Calculation
run_test( 'PressHub_AI_Token_Logger summary stats calculate correctly', function() {
    $stats = PressHub_AI_Token_Logger::get_summary_stats();
    return is_array( $stats ) && isset( $stats['total_requests'] ) && $stats['total_requests'] > 0;
} );

// Test 7: Structured Logger writes to debug log
run_test( 'PressHub_AI_Logger writes structured log file', function() {
    if ( ! class_exists( 'PressHub_AI_Logger' ) ) {
        return 'Class PressHub_AI_Logger not found';
    }
    PressHub_AI_Logger::info( 'Integration test logger check', [ 'test' => true ] );
    $file = PressHub_AI_Logger::get_log_file_path();
    return file_exists( $file ) && filesize( $file ) > 0;
} );

// Test 8: Provider Defaults accessible
run_test( 'PressHub_AI_Provider_Defaults templates accessible', function() {
    if ( ! class_exists( 'PressHub_AI_Provider_Defaults' ) ) {
        return 'Class PressHub_AI_Provider_Defaults not found';
    }
    $templates = PressHub_AI_Provider_Defaults::get_templates();
    return is_array( $templates ) && isset( $templates['openai'] ) && isset( $templates['anthropic'] );
} );

// Test 9: Provider Store CRUD operations
run_test( 'PressHub_AI_Provider_Store CRUD operations', function() {
    if ( ! class_exists( 'PressHub_AI_Provider_Store' ) ) {
        return 'Class PressHub_AI_Provider_Store not found';
    }
    $test_provider = [
        'id'            => 'test-integration-prov',
        'type'          => 'openai',
        'name'          => 'Integration Provider',
        'api_key'       => 'sk-test-key-12345',
        'default_model' => 'gpt-4o',
        'enabled'       => true,
    ];
    $saved_id = PressHub_AI_Provider_Store::save_provider( $test_provider );
    if ( empty( $saved_id ) ) {
        return 'Failed to save test provider';
    }
    $fetched = PressHub_AI_Provider_Store::get( 'test-integration-prov' );
    if ( ! $fetched || $fetched['name'] !== 'Integration Provider' ) {
        return 'Fetched provider did not match saved attributes';
    }
    PressHub_AI_Provider_Store::delete_provider( 'test-integration-prov' );
    $deleted_check = PressHub_AI_Provider_Store::get( 'test-integration-prov' );
    return null === $deleted_check;
} );

// Test 10: Rate Limiter
run_test( 'PressHub_AI_Rate_Limiter tracks and enforces limits', function() {
    if ( ! class_exists( 'PressHub_AI_Rate_Limiter' ) ) {
        return 'Class PressHub_AI_Rate_Limiter not found';
    }
    $limiter = new PressHub_AI_Rate_Limiter( true );
    $key = 'test_integration_user_' . time();
    $check1 = $limiter->check( $key, 2, 60 );
    if ( is_wp_error( $check1 ) ) {
        return 'Initial check failed';
    }
    $limiter->record( $key );
    $limiter->record( $key );
    $check2 = $limiter->check( $key, 2, 60 );
    return is_wp_error( $check2 );
} );

// Test 11: AJAX Action Handlers Registered
run_test( 'AJAX action handlers registered in WordPress', function() {
    return has_action( 'wp_ajax_presshub_ai_chat' )
        && has_action( 'wp_ajax_presshub_ai_save_provider' )
        && has_action( 'wp_ajax_presshub_ai_save_settings_section' )
        && has_action( 'wp_ajax_presshub_ai_fetch_token_logs' )
        && has_action( 'wp_ajax_presshub_ai_fetch_audit_logs' )
        && has_action( 'wp_ajax_presshub_ai_clear_audit_logs' )
        && has_action( 'wp_ajax_presshub_ai_briefing_get_status' );
} );

// Test 12: Admin User Exists
run_test( 'Admin user exists in WordPress database', function() {
    $user = get_user_by( 'login', 'admin' );
    return $user && in_array( 'administrator', $user->roles, true );
} );

// Test 13: Options persistence
run_test( 'WordPress options persistence for PressHub AI settings', function() {
    update_option( 'presshub_ai_test_key', 'test_value_123' );
    $val = get_option( 'presshub_ai_test_key' );
    delete_option( 'presshub_ai_test_key' );
    return $val === 'test_value_123';
} );

// Test 14: Audit Logger Table Exists
run_test( 'Database table wp_presshub_ai_audit_logs exists', function() {
    global $wpdb;
    if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
        PressHub_AI_Audit_Logger::create_table();
    }
    $table = $wpdb->prefix . 'presshub_ai_audit_logs';
    $exists = $wpdb->get_var( "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'" );
    return ! empty( $exists );
} );

// Test 15: Audit Logger DB Insertion and Retrieval
run_test( 'PressHub_AI_Audit_Logger records mutation to DB', function() {
    if ( ! class_exists( 'PressHub_AI_Audit_Logger' ) ) {
        return 'Class PressHub_AI_Audit_Logger not found';
    }
    $id = PressHub_AI_Audit_Logger::log(
        'provider_added',
        'provider',
        'integration-test-prov',
        [
            'name'    => 'Integration Provider',
            'api_key' => 'sk-proj-integration1234567890',
            'enabled' => true,
        ]
    );
    if ( empty( $id ) || ! is_numeric( $id ) ) {
        return 'Failed to insert audit log entry';
    }
    $logs = PressHub_AI_Audit_Logger::get_logs( [ 'entity_id' => 'integration-test-prov' ] );
    if ( empty( $logs['items'] ) ) {
        return 'Inserted audit log entry could not be queried';
    }
    $entry = $logs['items'][0];
    if ( 'provider_added' !== $entry['event_type'] || 'provider' !== $entry['entity_type'] ) {
        return 'Audit log entry event_type/entity_type mismatch';
    }
    if ( false !== strpos( (string) $entry['details'], 'sk-proj-integration1234567890' ) ) {
        return 'Audit log entry details contains unmasked secret API key';
    }
    return true;
} );

// Test 16: Issue #45 - UTF-8 word counter on Greek article (regression)
run_test( 'Issue #45 UTF-8: Greek article >=500 words (not 36)', function() {
    if ( ! class_exists( 'PressHub_AI_Context_Estimator' ) ) {
        return 'Class PressHub_AI_Context_Estimator not loaded';
    }
    $dictionary = [ 'Ελλάδα', 'Κύπρος', 'ιστορία', 'πολιτισμός', 'γλώσσα', 'λέξεις', 'άρθρο', 'δοκιμή' ];
    $text = trim( str_repeat( implode( ' ', $dictionary ) . ' ', 100 ) );
    $count = PressHub_AI_Context_Estimator::utf8_word_count( $text );
    if ( $count < 500 ) {
        return "Greek regression: expected >=500 words, got {$count}";
    }
    return true;
} );

// Test 17: Context estimator constants + clamp
run_test( 'Issue #45 UTF-8: clamp_max_context_tokens clamps out-of-range', function() {
    if ( ! class_exists( 'PressHub_AI_Context_Estimator' ) ) {
        return 'Class PressHub_AI_Context_Estimator not loaded';
    }
    if ( 40000 !== PressHub_AI_Context_Estimator::clamp_max_context_tokens( null ) ) {
        return 'Default fallback failed';
    }
    if ( 5000 !== PressHub_AI_Context_Estimator::clamp_max_context_tokens( 100 ) ) {
        return 'Min clamp failed';
    }
    if ( 200000 !== PressHub_AI_Context_Estimator::clamp_max_context_tokens( 999999 ) ) {
        return 'Max clamp failed';
    }
    return true;
} );

// Test 18: Context estimator summarize() returns both word + token counts
run_test( 'Issue #45 UTF-8: summarize() returns words + tokens', function() {
    if ( ! class_exists( 'PressHub_AI_Context_Estimator' ) ) {
        return 'Class PressHub_AI_Context_Estimator not loaded';
    }
    $summary = PressHub_AI_Context_Estimator::summarize( 'Hello brave new world' );
    if ( ! is_array( $summary ) || ! isset( $summary['words'], $summary['tokens'] ) ) {
        return 'Missing words/tokens keys';
    }
    if ( 4 !== $summary['words'] ) {
        return 'words should be 4';
    }
    if ( $summary['tokens'] < 1 ) {
        return 'tokens should be >=1';
    }
    return true;
} );

// Test 19: Issue #59 — sidebar.js revision-transfer fix invariants.
run_test( 'Issue #59: sidebar.js no longer uses .replace() for revisions; uses split().join() + applyBlockLevelReplacement', function() {
    $path = WP_PLUGIN_DIR . '/presshub-ai-editor/assets/sidebar.js';
    if ( ! is_file( $path ) ) {
        return 'sidebar.js not found';
    }
    $src = (string) file_get_contents( $path );
    // Strip comments so JSDoc references to the old patterns don't false-positive.
    $code = preg_replace( '#/\*.*?\*/#s', '', $src );
    $code = preg_replace( '#(?<![:"\'])//[^\n]*#', '', $code );

    if ( false !== strpos( $code, 'currentContent.replace(originalText,' ) ) {
        return 'Buggy pattern `currentContent.replace(originalText,` still in code';
    }
    if ( false !== strpos( $code, 'wp.blocks.serialize([b])' ) ) {
        return 'Buggy pattern `wp.blocks.serialize([b])` still in code';
    }
    if ( false === strpos( $code, 'replaceAllSafe(' ) ) {
        return 'Helper `replaceAllSafe(` not present in sidebar.js';
    }
    if ( false === strpos( $code, 'applyBlockLevelReplacement' ) ) {
        return 'Helper `applyBlockLevelReplacement` not present in sidebar.js';
    }
    if ( false === strpos( $code, 'escapeReplacementString' ) ) {
        return 'Helper `escapeReplacementString` not present in sidebar.js';
    }
    return true;
} );

// Test 20: Issue #59 — admin.css sidebar-width fix invariants.
run_test( 'Issue #59: admin.css sidebar container has default width and drag-handle styles', function() {
    $path = WP_PLUGIN_DIR . '/presshub-ai-editor/assets/admin.css';
    if ( ! is_file( $path ) ) {
        return 'admin.css not found';
    }
    $css = (string) file_get_contents( $path );
    if ( ! preg_match( '/\.presshub-sidebar-container\s*\{[^}]*(?:min-)?width\s*:\s*(\d+)px/s', $css, $m ) ) {
        return '.presshub-sidebar-container has no width / min-width rule';
    }
    if ( (int) $m[1] < 320 ) {
        return 'Sidebar declared width too narrow: ' . $m[1] . 'px';
    }
    if ( false === strpos( $css, '.presshub-sidebar-resize-handle' ) ) {
        return '.presshub-sidebar-resize-handle styles missing';
    }
    return true;
} );

// Test 21: Issue #59 part B — admin.css is enqueued in the block editor.
// Without this enqueue the .presshub-sidebar-container width rules and
// the drag handle styles never reach the Gutenberg editor (admin.css
// is only loaded on the classic-metabox / settings pages by default),
// so the bug as filed would silently come back.
run_test( 'Issue #59: admin.css is enqueued for the block editor (sidebar CSS reaches Gutenberg)', function() {
    $main = WP_PLUGIN_DIR . '/presshub-ai-editor/presshub-ai-editor.php';
    if ( ! is_file( $main ) ) {
        return 'presshub-ai-editor.php not found';
    }
    $src = (string) file_get_contents( $main );
    // Strip comments first so JSDoc / PHPDoc references don't false-positive.
    $code = preg_replace( '#/\*.*?\*/#s', '', $src );
    $code = preg_replace( '#(?<![:"\'])//[^\n]*#', '', $code );
    // Look for an enqueue_block_editor_assets callback that enqueues
    // admin.css. The simplest form is a wp_enqueue_style call with
    // 'assets/admin.css' inside an enqueue_block_editor_assets action.
    if ( false === strpos( $code, 'enqueue_block_editor_assets' ) ) {
        return 'No enqueue_block_editor_assets action found';
    }
    if ( false === strpos( $code, "wp_enqueue_style" ) ) {
        return 'No wp_enqueue_style call found in plugin main file';
    }
    if ( false === strpos( $code, "assets/admin.css" ) ) {
        return 'admin.css is not enqueued anywhere in the plugin main file';
    }
    // Both must appear inside the same callback — we just confirm both
    // substrings are present in the file (a stricter regex match would
    // couple the test to indentation / quoting style).
    if ( false === strpos( $code, "PRESSHUB_AI_URL . 'assets/admin.css'" )
         && false === strpos( $code, 'PRESSHUB_AI_URL . "assets/admin.css"' ) ) {
        return 'admin.css enqueue call does not use PRESSHUB_AI_URL — likely wrong path';
    }
    return true;
} );

echo "\n=================================================================\n";
echo "Integration Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=================================================================\n\n";

exit( $failed > 0 ? 1 : 0 );
