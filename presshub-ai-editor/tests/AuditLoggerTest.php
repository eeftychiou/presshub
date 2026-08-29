<?php
/**
 * Test suite for PressHub_AI_Audit_Logger.
 *
 * Covers table creation, structured logging of mutations (providers, settings,
 * news sources), recursive credential redaction/masking, query filtering & pagination,
 * RFC 4180 CSV export, retention pruning, and truncate operations.
 *
 * @package PressHub_AI_Editor
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-settings-migration.php';
require_once __DIR__ . '/../includes/class-audit-logger.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-briefing-admin.php';

function test_audit_logger() {
    global $wpdb;

    echo 'Running AuditLoggerTest...' . PHP_EOL;

    // Reset test environment
    $wpdb->tables          = [];
    $wpdb->queries         = [];
    $wpdb->auto_increments = [];
    $GLOBALS['OPTIONS_STORE'] = [];
    $GLOBALS['CURRENT_USER_ID'] = 42;

    // -----------------------------------------------------------------
    // 1. Table Creation & Schema Version
    // -----------------------------------------------------------------
    $table_name = PressHub_AI_Audit_Logger::get_table_name();
    assert( 'wp_presshub_ai_audit_logs' === $table_name, 'Table name is wp_presshub_ai_audit_logs' );

    PressHub_AI_Audit_Logger::create_table();
    assert( isset( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'Table wp_presshub_ai_audit_logs created in wpdb' );
    assert( get_option( PressHub_AI_Audit_Logger::DB_VERSION_OPTION ) === PressHub_AI_Audit_Logger::DB_VERSION, 'DB version option saved' );
    echo '  [1] Table creation & schema version: OK' . PHP_EOL;

    // -----------------------------------------------------------------
    // 2. Secret Redaction & Key Masking
    // -----------------------------------------------------------------
    $raw_sk = 'sk-proj-abc1234567890defghijklmnop';
    $raw_ant = 'sk-ant-api03-1234567890abcdef123456';
    $raw_bearer = 'Bearer sk-proj-11223344556677889900aabb';
    $clean_secret = 'super_secret_password_123';

    $sensitive_payload = [
        'name'          => 'OpenAI Main',
        'api_key'       => $raw_sk,
        'anthropic_key' => $raw_ant,
        'auth_token'    => $clean_secret,
        'nested'        => [
            'bearer_header' => $raw_bearer,
            'normal_field'  => 'keep_me_intact',
            'deep_secret'   => [
                'password'  => 'p@ssw0rd999',
            ],
        ],
        'regular_text'  => 'just plain text',
    ];

    $masked = PressHub_AI_Audit_Logger::mask_details( $sensitive_payload );

    assert( false === strpos( json_encode( $masked ), $raw_sk ), 'Raw OpenAI key is not in masked payload' );
    assert( false === strpos( json_encode( $masked ), $raw_ant ), 'Raw Anthropic key is not in masked payload' );
    assert( false === strpos( json_encode( $masked ), $clean_secret ), 'Raw auth token is not in masked payload' );
    assert( false === strpos( json_encode( $masked ), 'p@ssw0rd999' ), 'Deep password is not in masked payload' );
    assert( 'keep_me_intact' === $masked['nested']['normal_field'], 'Normal non-sensitive nested field preserved' );
    assert( 'just plain text' === $masked['regular_text'], 'Normal top-level field preserved' );
    assert( false !== strpos( $masked['api_key'], '••••' ), 'API key contains masking bullet characters' );
    assert( false !== strpos( $masked['nested']['bearer_header'], '••••' ), 'Bearer header contains masking bullet characters' );
    echo '  [2] Secret redaction & key masking: OK' . PHP_EOL;

    // -----------------------------------------------------------------
    // 3. Direct Log Insertion
    // -----------------------------------------------------------------
    $id1 = PressHub_AI_Audit_Logger::log(
        'provider_added',
        'provider',
        'openai-primary',
        [
            'name'          => 'OpenAI Production',
            'type'          => 'openai',
            'api_key'       => $raw_sk,
            'default_model' => 'gpt-4o',
            'enabled'       => true,
        ]
    );

    assert( is_numeric( $id1 ) && $id1 > 0, 'Log entry inserted with positive ID' );
    assert( 1 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), '1 record in audit table' );

    $row1 = $wpdb->tables['wp_presshub_ai_audit_logs'][0];
    assert( 'provider_added' === $row1['event_type'], 'Event type is provider_added' );
    assert( 'provider' === $row1['entity_type'], 'Entity type is provider' );
    assert( 'openai-primary' === $row1['entity_id'], 'Entity ID matches' );
    assert( 42 === (int) $row1['user_id'], 'User ID matches current user' );
    assert( false === strpos( $row1['details'], $raw_sk ), 'Details JSON does not contain unmasked API key' );
    echo '  [3] Direct log insertion: OK' . PHP_EOL;

    // -----------------------------------------------------------------
    // 4. Provider Store Hooks Integration
    // -----------------------------------------------------------------
    $new_provider = [
        'id'            => 'claude-store-test',
        'type'          => 'anthropic',
        'name'          => 'Anthropic Claude',
        'api_key'       => $raw_ant,
        'default_model' => 'claude-3-5-sonnet-20241022',
        'enabled'       => true,
    ];

    PressHub_AI_Provider_Store::save_provider( $new_provider );
    assert( 2 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'Audit log recorded for Provider Store save_provider (added)' );
    $row_prov_added = $wpdb->tables['wp_presshub_ai_audit_logs'][1];
    assert( 'provider_added' === $row_prov_added['event_type'], 'Provider added event logged' );
    assert( 'claude-store-test' === $row_prov_added['entity_id'], 'Entity ID is claude-store-test' );
    assert( false === strpos( $row_prov_added['details'], $raw_ant ), 'Anthropic key was masked in provider_added log' );

    // Toggle provider
    PressHub_AI_Provider_Store::toggle_provider( 'claude-store-test', false );
    assert( 3 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'Audit log recorded for Provider Store toggle' );
    $row_prov_toggled = $wpdb->tables['wp_presshub_ai_audit_logs'][2];
    assert( 'provider_toggled' === $row_prov_toggled['event_type'], 'Provider toggled event logged' );

    // Update provider
    $new_provider['default_model'] = 'claude-3-7-sonnet';
    PressHub_AI_Provider_Store::save_provider( $new_provider );
    assert( 4 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'Audit log recorded for Provider Store update' );
    $row_prov_upd = $wpdb->tables['wp_presshub_ai_audit_logs'][3];
    assert( 'provider_updated' === $row_prov_upd['event_type'], 'Provider updated event logged' );

    // Delete provider
    PressHub_AI_Provider_Store::delete_provider( 'claude-store-test' );
    assert( 5 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'Audit log recorded for Provider Store delete' );
    $row_prov_del = $wpdb->tables['wp_presshub_ai_audit_logs'][4];
    assert( 'provider_deleted' === $row_prov_del['event_type'], 'Provider deleted event logged' );
    echo '  [4] Provider Store hooks integration: OK' . PHP_EOL;

    // -----------------------------------------------------------------
    // 5. Settings Storage & News Source Hooks Integration
    // -----------------------------------------------------------------
    // Settings saved
    PressHub_AI_Settings_Storage::log_settings_saved( [ 'presshub_ai_provider', 'presshub_ai_coauthor_model' ], 2 );
    assert( 6 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'Settings saved audit log recorded' );
    $row_settings = $wpdb->tables['wp_presshub_ai_audit_logs'][5];
    assert( 'settings_saved' === $row_settings['event_type'], 'Event type is settings_saved' );
    assert( 'settings' === $row_settings['entity_type'], 'Entity type is settings' );

    // News source added
    $source_data = [
        'id'       => 'src_custom_test_news',
        'name'     => 'Custom Test Outlet',
        'url'      => 'https://www.example.com/news',
        'type'     => 'text_news',
        'enabled'  => true,
        'category' => 'Politics',
    ];
    PressHub_AI_Briefing_Admin::save_news_source( $source_data );
    assert( 7 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'News source added audit log recorded' );
    $row_src_add = $wpdb->tables['wp_presshub_ai_audit_logs'][6];
    assert( 'news_source_added' === $row_src_add['event_type'], 'Event is news_source_added' );
    assert( 'src_custom_test_news' === $row_src_add['entity_id'], 'Entity ID matches added source' );

    // News source toggled
    $source_data['enabled'] = false;
    PressHub_AI_Briefing_Admin::save_news_source( $source_data );
    assert( 8 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'News source toggled audit log recorded' );
    $row_src_tog = $wpdb->tables['wp_presshub_ai_audit_logs'][7];
    assert( 'news_source_toggled' === $row_src_tog['event_type'], 'Event is news_source_toggled' );

    // News source updated
    $source_data['name'] = 'Custom Test Outlet Renamed';
    PressHub_AI_Briefing_Admin::save_news_source( $source_data );
    assert( 9 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'News source updated audit log recorded' );
    $row_src_upd = $wpdb->tables['wp_presshub_ai_audit_logs'][8];
    assert( 'news_source_updated' === $row_src_upd['event_type'], 'Event is news_source_updated' );

    // News source deleted
    PressHub_AI_Briefing_Admin::delete_news_source( 'src_custom_test_news' );
    assert( 10 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), 'News source deleted audit log recorded' );
    $row_src_del = $wpdb->tables['wp_presshub_ai_audit_logs'][9];
    assert( 'news_source_deleted' === $row_src_del['event_type'], 'Event is news_source_deleted' );
    echo '  [5] Settings Storage & News Source hooks integration: OK' . PHP_EOL;

    // -----------------------------------------------------------------
    // 6. Query Filtering, Pagination & Counting
    // -----------------------------------------------------------------
    $all_logs = PressHub_AI_Audit_Logger::get_logs( [ 'per_page' => 50 ] );
    assert( 10 === $all_logs['total'], 'Total logs count is 10' );
    assert( 10 === count( $all_logs['items'] ), '10 items returned' );

    $provider_logs = PressHub_AI_Audit_Logger::get_logs( [ 'entity_type' => 'provider' ] );
    assert( 5 === $provider_logs['total'], '5 provider entity logs found' );

    $settings_logs = PressHub_AI_Audit_Logger::get_logs( [ 'event_type' => 'settings_saved' ] );
    assert( 1 === $settings_logs['total'], '1 settings_saved log found' );

    $paged_logs = PressHub_AI_Audit_Logger::get_logs( [ 'page' => 2, 'per_page' => 4 ] );
    assert( 10 === $paged_logs['total'], 'Paged total count is 10' );
    assert( 4 === count( $paged_logs['items'] ), '4 items returned for page 2' );

    $counted_prov = PressHub_AI_Audit_Logger::count_logs( '', 'provider' );
    assert( 5 === $counted_prov, 'count_logs for provider entity returns 5' );

    $counted_settings = PressHub_AI_Audit_Logger::count_logs( 'settings_saved' );
    assert( 1 === $counted_settings, 'count_logs for settings_saved event returns 1' );
    echo '  [6] Query filtering, pagination & counting: OK' . PHP_EOL;

    // -----------------------------------------------------------------
    // 7. CSV Export
    // -----------------------------------------------------------------
    $csv = PressHub_AI_Audit_Logger::export_csv();
    assert( false !== strpos( $csv, 'id,created_at,user_id,user_login,event_type,entity_type,entity_id' ), 'CSV contains standard header' );
    assert( false !== strpos( $csv, 'provider_added' ), 'CSV contains provider_added event' );
    assert( false !== strpos( $csv, 'settings_saved' ), 'CSV contains settings_saved event' );
    assert( false === strpos( $csv, $raw_sk ), 'CSV does NOT contain raw API keys' );
    echo '  [7] CSV export: OK' . PHP_EOL;

    // -----------------------------------------------------------------
    // 8. Retention Pruning & Clear Logs
    // -----------------------------------------------------------------
    $wpdb->tables['wp_presshub_ai_audit_logs'][] = [
        'id'          => 999,
        'created_at'  => '2024-01-01 00:00:00',
        'user_id'     => 1,
        'user_login'  => 'admin',
        'event_type'  => 'settings_saved',
        'entity_type' => 'settings',
        'entity_id'   => 'old_settings',
        'details'     => null,
        'ip_address'  => '127.0.0.1',
    ];
    assert( 11 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), '11 rows before pruning' );

    $pruned = PressHub_AI_Audit_Logger::prune_logs( 60 );
    assert( $pruned >= 1, 'Pruned at least 1 old record' );
    assert( 10 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), '10 rows remain after pruning' );

    $cleared = PressHub_AI_Audit_Logger::clear_all_logs();
    assert( true === $cleared, 'clear_all_logs returns true' );
    assert( 0 === count( $wpdb->tables['wp_presshub_ai_audit_logs'] ), '0 rows remain after clear_all_logs' );
    echo '  [8] Retention pruning & clear logs: OK' . PHP_EOL;

    echo 'AuditLoggerTest: ALL TESTS PASSED! (100% OK)' . PHP_EOL;
}

test_audit_logger();
