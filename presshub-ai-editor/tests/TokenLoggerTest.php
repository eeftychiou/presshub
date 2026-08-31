<?php
/**
 * Test suite for PressHub_AI_Token_Logger.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-token-logger.php';

function test_token_logger() {
    global $wpdb;

    echo 'Running TokenLoggerTest...' . PHP_EOL;

    // Reset tables
    $wpdb->tables = [];
    $wpdb->queries = [];
    $wpdb->auto_increments = [];

    // 1. Table creation
    PressHub_AI_Token_Logger::create_table();
    assert( isset( $wpdb->tables['wp_presshub_ai_token_logs'] ), 'Table wp_presshub_ai_token_logs created' );
    assert( get_option( PressHub_AI_Token_Logger::DB_VERSION_OPTION ) === PressHub_AI_Token_Logger::DB_VERSION, 'DB version option set' );
    echo '  [1] Table creation: OK' . PHP_EOL;

    // 2. Log LLM request
    $id1 = PressHub_AI_Token_Logger::log_llm_request(
        'briefing_curation',
        'gemini',
        'gemini-2.5-flash',
        1500,
        800,
        3200,
        'success',
        null,
        [ 'test' => true ],
        1
    );
    assert( $id1 > 0, 'LLM log inserted with positive ID' );
    assert( 1 === count( $wpdb->tables['wp_presshub_ai_token_logs'] ), 'One row in table' );
    $row1 = $wpdb->tables['wp_presshub_ai_token_logs'][0];
    assert( 1500 === (int) $row1['prompt_tokens'], 'Prompt tokens recorded' );
    assert( 800 === (int) $row1['completion_tokens'], 'Completion tokens recorded' );
    assert( 2300 === (int) $row1['total_tokens'], 'Total tokens computed' );
    assert( 'gemini-2.5-flash' === $row1['model'], 'Model recorded' );
    assert( 'briefing_curation' === $row1['action_trigger'], 'Action recorded' );
    echo '  [2] Log LLM request: OK' . PHP_EOL;

    // 3. Log TTS request
    $id2 = PressHub_AI_Token_Logger::log_tts_request(
        'podcast_audio',
        'google_cloud_tts',
        'el-GR-Neural2-A',
        1250,
        4500,
        'success',
        null,
        [],
        1
    );
    assert( $id2 > 0, 'TTS log inserted' );
    $row2 = $wpdb->tables['wp_presshub_ai_token_logs'][1];
    assert( 1250 === (int) $row2['metric_units'], 'TTS characters recorded in metric_units' );
    assert( 'podcast_audio' === $row2['action_trigger'], 'Action recorded' );
    echo '  [3] Log TTS request: OK' . PHP_EOL;

    // 4. Log Scrape request
    $id3 = PressHub_AI_Token_Logger::log_scrape_request(
        'scrape_harvest',
        13,
        146,
        18500,
        'success',
        null,
        [ 'date' => '2026-08-28' ],
        1
    );
    assert( $id3 > 0, 'Scrape log inserted' );
    $row3 = $wpdb->tables['wp_presshub_ai_token_logs'][2];
    assert( 146 === (int) $row3['metric_units'], 'Articles count recorded in metric_units' );
    $meta = json_decode( $row3['metadata'], true );
    assert( 13 === (int) ( $meta['sources_count'] ?? 0 ), 'Sources count recorded in metadata' );
    echo '  [4] Log Scrape request: OK' . PHP_EOL;

    // 5. Query logs with filters & pagination
    $logs = PressHub_AI_Token_Logger::get_logs( [ 'per_page' => 10 ] );
    assert( isset( $logs['items'], $logs['total'] ), 'get_logs returns items and total' );
    assert( 3 === $logs['total'], 'Total log count is 3' );
    assert( 3 === count( $logs['items'] ), '3 items returned' );

    $llm_logs = PressHub_AI_Token_Logger::get_logs( [ 'action' => 'briefing_curation' ] );
    assert( 1 === $llm_logs['total'], 'Action filter works' );
    assert( 'gemini-2.5-flash' === $llm_logs['items'][0]['model'], 'Filtered item matches' );
    echo '  [5] get_logs filtering: OK' . PHP_EOL;

    // 6. Summary stats
    $stats = PressHub_AI_Token_Logger::get_summary_stats();
    assert( 3 === $stats['total_requests'], 'Summary total requests is 3' );
    assert( 2300 === $stats['total_tokens'], 'Summary total tokens is 2300' );
    assert( 1250 === $stats['tts_chars'], 'Summary total TTS chars is 1250' );
    assert( 146 === $stats['scraped_articles'], 'Summary total scraped articles is 146' );
    assert( 100.0 === (float) $stats['success_rate'], 'Success rate is 100%' );
    assert( isset( $stats['tokens_by_model']['gemini-2.5-flash'] ), 'tokens_by_model grouping contains model' );
    echo '  [6] Summary stats: OK' . PHP_EOL;

    // 7. CSV export
    $csv = PressHub_AI_Token_Logger::export_csv();
    assert( false !== strpos( $csv, 'id,created_at,action_trigger,provider,model' ), 'CSV contains header' );
    assert( false !== strpos( $csv, 'briefing_curation' ), 'CSV contains log action' );
    assert( false !== strpos( $csv, 'gemini-2.5-flash' ), 'CSV contains model' );
    echo '  [7] CSV export: OK' . PHP_EOL;

    // 8. Pruning
    $wpdb->tables['wp_presshub_ai_token_logs'][] = [
        'id'                => 99,
        'created_at'        => '2025-01-01 00:00:00',
        'action_trigger'    => 'old_action',
        'provider'          => 'openai',
        'model'             => 'gpt-4o',
        'prompt_tokens'     => 100,
        'completion_tokens' => 50,
        'total_tokens'      => 150,
        'metric_units'      => 0,
        'duration_ms'       => 1000,
        'status'            => 'success',
        'user_id'           => 1,
        'error_message'     => null,
        'metadata'          => null,
    ];
    assert( 4 === count( $wpdb->tables['wp_presshub_ai_token_logs'] ), '4 rows before prune' );
    $pruned = PressHub_AI_Token_Logger::prune_old_logs( 60 );
    assert( $pruned >= 1, 'Pruned at least 1 old record' );
    assert( 3 === count( $wpdb->tables['wp_presshub_ai_token_logs'] ), '3 rows remain after prune' );
    echo '  [8] Prune old logs: OK' . PHP_EOL;

    // 9. Clear all logs
    $cleared = PressHub_AI_Token_Logger::clear_all_logs();
    assert( true === $cleared, 'clear_all_logs returns true' );
    assert( 0 === count( $wpdb->tables['wp_presshub_ai_token_logs'] ), '0 rows remain after clear' );
    echo '  [9] Clear all logs: OK' . PHP_EOL;

    // 10. Excluded actions are filtered from get_logs() by default.
    // See: https://github.com/eeftychiou/presshub/issues/40
    PressHub_AI_Token_Logger::log_llm_request(
        'integration_test',
        'openai',
        'gpt-4o',
        10,
        5,
        100,
        'success',
        null,
        [ 'synthetic' => true ],
        1
    );
    PressHub_AI_Token_Logger::log_llm_request(
        'briefing_curation',
        'gemini',
        'gemini-2.5-flash',
        1500,
        800,
        3200,
        'success',
        null,
        [ 'real' => true ],
        1
    );
    assert( 2 === count( $wpdb->tables['wp_presshub_ai_token_logs'] ), '2 rows present in stub table' );
    $logs = PressHub_AI_Token_Logger::get_logs( [ 'per_page' => 50 ] );
    assert( 1 === $logs['total'], 'integration_test row is excluded from get_logs() by default' );
    assert( 'briefing_curation' === $logs['items'][0]['action_trigger'], 'Remaining row is the real action' );
    echo '  [10] get_logs() excludes integration_test rows by default: OK' . PHP_EOL;

    // 11. Summary stats also exclude synthetic integration_test rows.
    $stats = PressHub_AI_Token_Logger::get_summary_stats();
    assert( 1 === $stats['total_requests'], 'get_summary_stats() total_requests ignores integration_test row' );
    assert( 2300 === $stats['total_tokens'], 'Summary total tokens reflects only the real LLM row' );
    assert( ! isset( $stats['tokens_by_action']['integration_test'] ), 'integration_test is not present in tokens_by_action' );
    echo '  [11] get_summary_stats() excludes integration_test rows: OK' . PHP_EOL;

    // 12. delete_logs_by_action() purges synthetic rows.
    $deleted = PressHub_AI_Token_Logger::delete_logs_by_action( 'integration_test' );
    assert( $deleted >= 1, 'delete_logs_by_action() returns at least 1 deleted row' );
    assert( 1 === count( $wpdb->tables['wp_presshub_ai_token_logs'] ), 'integration_test row was removed' );
    $remaining = PressHub_AI_Token_Logger::get_logs( [ 'per_page' => 50 ] );
    assert( 1 === $remaining['total'], '1 real row remains after delete_logs_by_action' );

    // Convenience wrapper delete_integration_test_logs() works too.
    PressHub_AI_Token_Logger::log_llm_request(
        'integration_test',
        'openai',
        'gpt-4o',
        10,
        5,
        100,
        'success',
        null,
        [],
        1
    );
    assert( 2 === count( $wpdb->tables['wp_presshub_ai_token_logs'] ), '2 rows after re-inserting integration_test' );
    $deleted2 = PressHub_AI_Token_Logger::delete_integration_test_logs();
    assert( $deleted2 >= 1, 'delete_integration_test_logs() removed the synthetic row' );
    assert( 1 === count( $wpdb->tables['wp_presshub_ai_token_logs'] ), '1 row remains after delete_integration_test_logs' );
    echo '  [12] delete_logs_by_action / delete_integration_test_logs: OK' . PHP_EOL;

    echo 'TokenLoggerTest: ALL TESTS PASSED!' . PHP_EOL;
}

test_token_logger();
