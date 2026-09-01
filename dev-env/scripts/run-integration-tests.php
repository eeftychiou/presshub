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

// Test 22: Issue #61 — News Curator cap observability.
// When format_articles_context() truncates the article set the curator
// must expose the capped char count, capped token estimate, and the
// truncation flag through their public API so the AJAX handler can
// surface them in the briefing response (and the token log metadata).
run_test( 'Issue #61: News Curator cap observability — capped < pool when truncated', function() {
    if ( ! class_exists( 'PressHub_AI_News_Curator' ) ) {
        return 'Class PressHub_AI_News_Curator not loaded';
    }
    $curator = new PressHub_AI_News_Curator();
    $big_articles = [];
    for ( $i = 1; $i <= 100; $i++ ) {
        $big_articles[] = [
            'title'   => "Article {$i}",
            'source'  => 'TestSource',
            'url'     => "https://example.test/{$i}",
            'content' => str_repeat( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ ', 40 ), // ~1040 chars
        ];
    }
    $curator->format_articles_context( $big_articles );
    if ( ! $curator->was_context_truncated() ) {
        return 'was_context_truncated() should be true with 100 articles';
    }
    if ( $curator->last_capped_chars() <= 0 ) {
        return 'last_capped_chars() should be > 0';
    }
    if ( $curator->last_capped_tokens_estimate() <= 0 ) {
        return 'last_capped_tokens_estimate() should be > 0';
    }
    if ( $curator->last_original_articles_count() !== 100 ) {
        return 'last_original_articles_count() should be 100';
    }
    return true;
} );

// Test 23: Issue #61 AC#4 - briefing_curation metadata JSON column.
// Full real-flow check: generate_briefing() -> real call_provider() ->
// real log_llm_request() -> SQLite row. HTTP is intercepted via
// pre_http_request so no external network call is made.
run_test( 'Issue #61 AC#4: briefing_curation row metadata contains pool/cap keys', function() {
    global $wpdb;
    if ( ! class_exists( 'PressHub_AI_News_Curator' ) || ! class_exists( 'PressHub_AI_API_Client' ) || ! class_exists( 'PressHub_AI_Token_Logger' ) ) {
        return 'Required classes not loaded';
    }

    // Seed a snapshot (unique date to avoid clashing with other tests).
    $test_date = '2026-09-02';
    $articles  = [];
    for ( $i = 1; $i <= 60; $i++ ) {
        $articles[] = [
            'id'      => "meta-art-{$i}",
            'title'   => "Meta Article {$i}",
            'source'  => 'MetaSource',
            'url'     => "https://example.test/meta/{$i}",
            'content' => str_repeat( "Greek meta content {$i} ", 60 ),
        ];
    }
    ( new PressHub_AI_News_Harvester() )->save_snapshot( $test_date, [
        'date'            => $test_date,
        'harvested_at'    => gmdate( 'c' ),
        'sources'         => [ 'https://example.test' ],
        'blocked_sources' => [],
        'articles'        => $articles,
    ] );

    // Real API client with a deterministic provider config.
    $api_client = new PressHub_AI_API_Client( [
        'id'            => 'integration-meta-prov',
        'type'          => 'openai',
        'name'          => 'Integration Meta Provider',
        'api_key'       => 'sk-integration-meta-key-12345',
        'model'         => 'gpt-4o-meta',
        'default_model' => 'gpt-4o-meta',
        'temperature'   => 0.2,
        'max_tokens'    => 1024,
        'timeout'       => 15,
        'headers'       => [],
    ] );
    if ( method_exists( $api_client, 'set_action' ) ) {
        $api_client->set_action( 'briefing_curation' );
    }

    // Intercept the HTTP request and return a deterministic success body.
    add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
        return [
            'headers'  => [],
            'body'     => wp_json_encode( [
                'choices' => [ [ 'message' => [ 'content' => "# Meta Briefing\n\nContent." ], 'finish_reason' => 'stop' ] ],
                'usage'   => [ 'prompt_tokens' => 1200, 'completion_tokens' => 200 ],
            ] ),
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }, 10, 3 );

    try {
        $result = ( new PressHub_AI_News_Curator() )->generate_briefing( $test_date, $api_client );
    } finally {
        remove_all_filters( 'pre_http_request' );
    }

    if ( is_wp_error( $result ) ) {
        return 'generate_briefing() failed: ' . $result->get_error_message();
    }

    // Query the latest briefing_curation success row written by this run.
    $table = $wpdb->prefix . 'presshub_ai_token_logs';
    //nolint:sql
    $rows = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE action_trigger = %s AND status = 'success' ORDER BY id DESC LIMIT 5", 'briefing_curation' ),
        ARRAY_A
    );
    if ( empty( $rows ) ) {
        return 'No briefing_curation success row found in token log';
    }
    $row = $rows[0];
    $meta = json_decode( (string) ( $row['metadata'] ?? '' ), true );
    if ( ! is_array( $meta ) ) {
        return 'briefing_curation row metadata is not a JSON object: ' . var_export( $row['metadata'], true );
    }
    foreach ( [ 'pool_chars', 'pool_tokens_estimate', 'capped_chars', 'capped_tokens_estimate', 'cap_articles', 'cap_chars_per_article', 'articles_count', 'articles_count_original', 'articles_truncated' ] as $key ) {
        if ( ! array_key_exists( $key, $meta ) ) {
            return "metadata missing key: {$key}. Full metadata: " . wp_json_encode( $meta );
        }
    }
    if ( (int) $meta['pool_chars'] <= 0 || (int) $meta['pool_tokens_estimate'] <= 0 ) {
        return 'pool_chars / pool_tokens_estimate must be positive, got: ' . wp_json_encode( $meta );
    }
    if ( (int) $meta['capped_chars'] <= 0 || (int) $meta['capped_tokens_estimate'] <= 0 ) {
        return 'capped_chars / capped_tokens_estimate must be positive, got: ' . wp_json_encode( $meta );
    }
    if ( (int) $meta['cap_articles'] < 1 || (int) $meta['cap_chars_per_article'] < 100 ) {
        return 'cap_articles / cap_chars_per_article out of range, got: ' . wp_json_encode( $meta );
    }
    if ( (int) $meta['articles_count'] < 1 || (int) $meta['articles_count'] > (int) $meta['articles_count_original'] ) {
        return 'articles_count must be >= 1 and <= articles_count_original, got: ' . wp_json_encode( $meta );
    }
    if ( (int) $meta['articles_truncated'] !== 1 ) {
        return 'articles_truncated must be true with a 60-article pool and default cap, got: ' . wp_json_encode( $meta );
    }
    if ( (int) $meta['capped_tokens_estimate'] >= (int) $meta['pool_tokens_estimate'] ) {
        return 'Expected capped_tokens_estimate < pool_tokens_estimate (truncation), got: ' . wp_json_encode( $meta );
    }
    return true;
} );

// Test 24: Issue #61 AC#2 - changing presshub_ai_curation_max_articles from
// the default 40 to 10 causes the next curation to send <= 10 articles and
// the briefing_curation metadata row reflects cap_articles = 10 with a
// smaller capped_tokens_estimate.
run_test( 'Issue #61 AC#2: reduced curation cap (10 articles) reflected in metadata', function() {
    global $wpdb;
    if ( ! class_exists( 'PressHub_AI_News_Curator' ) || ! class_exists( 'PressHub_AI_API_Client' ) || ! class_exists( 'PressHub_AI_Token_Logger' ) || ! class_exists( 'PressHub_AI_Settings_Storage' ) ) {
        return 'Required classes not loaded';
    }

    // Save the reduced cap (Settings-First read path).
    update_option( 'presshub_ai_curation_max_articles', 10 );
    $cap_readback = PressHub_AI_Settings_Storage::get_curation_max_articles();
    if ( 10 !== $cap_readback ) {
        delete_option( 'presshub_ai_curation_max_articles' );
        return "get_curation_max_articles() did not return saved 10, got: {$cap_readback}";
    }

    try {
        // Seed a fresh snapshot (unique date to avoid clashing).
        $test_date = '2026-09-03';
        $articles  = [];
        for ( $i = 1; $i <= 40; $i++ ) {
            $articles[] = [
                'id'      => "cap-art-{$i}",
                'title'   => "Cap Article {$i}",
                'source'  => 'CapSource',
                'url'     => "https://example.test/cap/{$i}",
                'content' => str_repeat( "Capped Greek content {$i} ", 40 ),
            ];
        }
        ( new PressHub_AI_News_Harvester() )->save_snapshot( $test_date, [
            'date'            => $test_date,
            'harvested_at'    => gmdate( 'c' ),
            'sources'         => [ 'https://example.test' ],
            'blocked_sources' => [],
            'articles'        => $articles,
        ] );

        $api_client = new PressHub_AI_API_Client( [
            'id'            => 'integration-cap-prov',
            'type'          => 'openai',
            'name'          => 'Integration Cap Provider',
            'api_key'       => 'sk-integration-cap-key-12345',
            'model'         => 'gpt-4o-cap',
            'default_model' => 'gpt-4o-cap',
            'temperature'   => 0.2,
            'max_tokens'    => 1024,
            'timeout'       => 15,
            'headers'       => [],
        ] );
        if ( method_exists( $api_client, 'set_action' ) ) {
            $api_client->set_action( 'briefing_curation' );
        }

        add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
            return [
                'headers'  => [],
                'body'     => wp_json_encode( [
                    'choices' => [ [ 'message' => [ 'content' => "# Cap Briefing\n\nContent." ], 'finish_reason' => 'stop' ] ],
                    'usage'   => [ 'prompt_tokens' => 600, 'completion_tokens' => 100 ],
                ] ),
                'response' => [ 'code' => 200, 'message' => 'OK' ],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );

        try {
            $result = ( new PressHub_AI_News_Curator() )->generate_briefing( $test_date, $api_client );
        } finally {
            remove_all_filters( 'pre_http_request' );
        }

        if ( is_wp_error( $result ) ) {
            return 'generate_briefing() failed with reduced cap: ' . $result->get_error_message();
        }

        // The payload must expose cap_articles = 10 and <= 10 articles.
        if ( (int) ( $result['cap_articles'] ?? 0 ) !== 10 ) {
            return 'payload cap_articles should be 10, got: ' . wp_json_encode( $result );
        }
        if ( (int) ( $result['articles_count'] ?? 0 ) > 10 ) {
            return 'payload articles_count should be <= 10, got: ' . wp_json_encode( $result );
        }

        // The briefing_curation metadata row must reflect the reduced cap.
        $table = $wpdb->prefix . 'presshub_ai_token_logs';
        //nolint:sql
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE action_trigger = %s AND status = 'success' ORDER BY id DESC LIMIT 3", 'briefing_curation' ),
            ARRAY_A
        );
        if ( empty( $rows ) ) {
            return 'No briefing_curation success row found after reduced-cap run';
        }
        $meta = json_decode( (string) ( $rows[0]['metadata'] ?? '' ), true );
        if ( ! is_array( $meta ) ) {
            return 'briefing_curation row metadata is not a JSON object: ' . var_export( $rows[0]['metadata'], true );
        }
        if ( (int) ( $meta['cap_articles'] ?? 0 ) !== 10 ) {
            return 'metadata cap_articles should be 10, got: ' . wp_json_encode( $meta );
        }
        if ( (int) ( $meta['articles_count'] ?? 0 ) > 10 ) {
            return 'metadata articles_count should be <= 10, got: ' . wp_json_encode( $meta );
        }
        if ( (int) ( $meta['articles_count_original'] ?? 0 ) !== 40 ) {
            return 'metadata articles_count_original should be 40 (pool size), got: ' . wp_json_encode( $meta );
        }
        if ( (int) ( $meta['articles_truncated'] ?? 0 ) !== 1 ) {
            return 'metadata articles_truncated should be true with 40-article pool and cap=10, got: ' . wp_json_encode( $meta );
        }
        return true;
    } finally {
        delete_option( 'presshub_ai_curation_max_articles' );
    }
} );

// =========================================================================
// Test 25: Issue #65 — Text Story Settings-First title composition
// =========================================================================
//
// Validates the live WordPress flow:
//   - presshub_ai_briefing_text_title_prefix / ..._date_format options are
//     registered as form options with callable sanitize callbacks.
//   - PressHub_AI_News_Curator::create_wordpress_post() composes the title
//     using the Settings values (no duplicate "Πρωινή Ενημέρωση:" prefix).
//   - The leading <h1>/<h2> block is stripped from the post body.
//   - The post meta _presshub_text_title_prefix_applied / ..._date_format_applied
//     are recorded for downstream observability.
//   - get_briefing_status() surfaces the prefix/date-format state to AJAX.
run_test( 'Issue #65: Text Story Settings-First prefix + h1-strip end-to-end', function() {
    global $wpdb;

    if ( ! class_exists( 'PressHub_AI_News_Curator' ) ) {
        return 'Class PressHub_AI_News_Curator not loaded';
    }
    if ( ! class_exists( 'PressHub_AI_Briefing_Admin' ) ) {
        return 'Class PressHub_AI_Briefing_Admin not loaded';
    }

    // Ensure clean Settings baseline.
    delete_option( 'presshub_ai_briefing_text_title_prefix' );
    delete_option( 'presshub_ai_briefing_text_title_date_format' );

    // 1. The two new Settings options must be registered and readable.
    //    WordPress tracks registered settings in the $wp_registered_settings
    //    super-global once the plugin's register_setting() calls have run.
    //    The integration test boots WP without firing admin_init, so we
    //    trigger it explicitly here.
    do_action( 'admin_init' );
    global $wp_registered_settings;
    $registered = is_array( $wp_registered_settings ?? null ) ? $wp_registered_settings : [];
    if ( empty( $registered['presshub_ai_briefing_text_title_prefix'] ) ) {
        return 'Option presshub_ai_briefing_text_title_prefix is not registered';
    }
    if ( empty( $registered['presshub_ai_briefing_text_title_date_format'] ) ) {
        return 'Option presshub_ai_briefing_text_title_date_format is not registered';
    }

    // 2. Defaults match the documented contract.
    if ( PressHub_AI_Settings_Storage::get_briefing_text_title_prefix() !== 'Πρωινή Ενημέρωση:' ) {
        return 'Default title prefix should be "Πρωινή Ενημέρωση:"';
    }
    if ( PressHub_AI_Settings_Storage::get_briefing_text_title_date_format() !== 'd/m/Y' ) {
        return 'Default date format should be "d/m/Y"';
    }

    // 3. Set a BREAKING: prefix and an empty date suffix to exercise both.
    update_option( 'presshub_ai_briefing_text_title_prefix', 'BREAKING:' );
    update_option( 'presshub_ai_briefing_text_title_date_format', '' );

    $curator = new PressHub_AI_News_Curator();
    $test_date = '2026-09-03';
    $html_body = "<h1>BREAKING: Σεισμός 5.8R στην Κρήτη</h1>\n<h2>Κοινωνία</h2>\n<p>Αναλυτική κάλυψη.</p>";

    $post_id = $curator->create_wordpress_post( $html_body, $test_date );
    if ( ! is_int( $post_id ) || $post_id <= 0 ) {
        return 'create_wordpress_post() returned a non-positive ID';
    }

    // 4. Title should be "BREAKING: Σεισμός 5.8R στην Κρήτη" (no duplicate
    //    prefix, no date suffix because the date format is empty).
    $title = (string) get_post_field( 'post_title', $post_id );
    if ( false !== strpos( $title, 'BREAKING: BREAKING:' ) ) {
        return "Title has duplicate prefix: {$title}";
    }
    if ( false === strpos( $title, 'BREAKING:' ) ) {
        return "Title missing BREAKING prefix: {$title}";
    }
    if ( false === strpos( $title, 'Σεισμός 5.8R στην Κρήτη' ) ) {
        return "Title missing headline: {$title}";
    }
    if ( preg_match( '/\d{2}\/\d{2}\/\d{4}/', $title ) ) {
        return "Title has unexpected date suffix (format was empty): {$title}";
    }

    // 5. Body should NOT start with the leading <h1>.
    $content = (string) get_post_field( 'post_content', $post_id );
    if ( false !== strpos( $content, '<h1>BREAKING: Σεισμός' ) ) {
        return "Body still contains the duplicate <h1> block: " . substr( $content, 0, 200 );
    }
    if ( false === strpos( $content, 'Αναλυτική κάλυψη' ) ) {
        return "Body missing expected paragraph: " . substr( $content, 0, 200 );
    }

    // 6. Post meta records the applied Settings state.
    if ( (string) get_post_meta( $post_id, '_presshub_text_title_prefix_applied', true ) !== 'BREAKING:' ) {
        return '_presshub_text_title_prefix_applied meta mismatch';
    }
    if ( (string) get_post_meta( $post_id, '_presshub_text_title_date_format_applied', true ) !== '' ) {
        return '_presshub_text_title_date_format_applied meta mismatch (should be empty)';
    }

    // 7. get_briefing_status() surfaces the new keys to the AJAX layer.
    $admin     = new PressHub_AI_Briefing_Admin();
    $status    = $admin->get_briefing_status( $test_date );
    if ( ! is_array( $status ) ) {
        return 'get_briefing_status() returned a non-array';
    }
    foreach ( [
        'text_title_prefix_applied',
        'text_title_date_format_applied',
        'text_title_prefix_current',
        'text_title_date_format_current',
    ] as $key ) {
        if ( ! array_key_exists( $key, $status ) ) {
            return "get_briefing_status() missing key: {$key}";
        }
    }
    if ( (string) $status['text_title_prefix_applied'] !== 'BREAKING:' ) {
        return 'get_briefing_status() text_title_prefix_applied mismatch';
    }
    if ( (string) $status['text_title_prefix_current'] !== 'BREAKING:' ) {
        return 'get_briefing_status() text_title_prefix_current mismatch';
    }

    // 8. Sanitize helpers reject invalid date format tokens and clamp the
    //    prefix to the documented 60-character cap.
    if ( PressHub_AI_Settings_Storage::sanitize_briefing_text_title_prefix( str_repeat( 'x', 100 ) ) !== str_repeat( 'x', 60 ) ) {
        return 'sanitize_briefing_text_title_prefix did not clamp to 60 chars';
    }
    if ( PressHub_AI_Settings_Storage::sanitize_briefing_text_title_date_format( '<?php exit;' ) !== 'd/m/Y' ) {
        return 'sanitize_briefing_text_title_date_format accepted an invalid token';
    }
    if ( PressHub_AI_Settings_Storage::sanitize_briefing_text_title_date_format( 'Y-m-d' ) !== 'Y-m-d' ) {
        return 'sanitize_briefing_text_title_date_format rejected a valid token';
    }

    // 8b. A NON-EMPTY, NON-DEFAULT date format token is honored when
    //     composing the title. This guards the regression where
    //     create_wordpress_post() hard-coded 'd/m/Y' and the setting only
    //     gated *whether* the suffix appeared — never *how* it was formatted.
    update_option( 'presshub_ai_briefing_text_title_date_format', 'Y-m-d' );
    $post_id_ymd = $curator->create_wordpress_post( $html_body, $test_date );
    if ( ! is_int( $post_id_ymd ) || $post_id_ymd <= 0 ) {
        return 'create_wordpress_post() returned a non-positive ID for Y-m-d phase';
    }
    $title_ymd = (string) get_post_field( 'post_title', $post_id_ymd );
    if ( false === strpos( $title_ymd, '2026-09-03' ) ) {
        return "Custom date format 'Y-m-d' not honored — expected 2026-09-03 in title: {$title_ymd}";
    }
    if ( preg_match( '/\d{2}\/\d{2}\/\d{4}/', $title_ymd ) ) {
        return "Title has hard-coded d/m/Y date despite Y-m-d setting: {$title_ymd}";
    }
    if ( (string) get_post_meta( $post_id_ymd, '_presshub_text_title_date_format_applied', true ) !== 'Y-m-d' ) {
        return '_presshub_text_title_date_format_applied meta mismatch (should be Y-m-d)';
    }

    // Reset Settings so subsequent test runs are isolated.
    delete_option( 'presshub_ai_briefing_text_title_prefix' );
    delete_option( 'presshub_ai_briefing_text_title_date_format' );

    return true;
} );

echo "\n=================================================================\n";
echo "Integration Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=================================================================\n\n";

exit( $failed > 0 ? 1 : 0 );
