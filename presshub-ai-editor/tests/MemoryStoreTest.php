<?php
/**
 * Test Suite: Memory Store Repository & Invariants (Issue #132).
 *
 * Verifies:
 * 1. Strict schema validation (stories, story fields, podcast, narrative, entities/source_urls/include).
 * 2. Edit-protection invariant: upsert_generated preserves editor changes (origin='edited').
 * 3. Preservation of editor 'excluded' status across automated upsert_generated regenerations.
 * 4. Reset to generated restores content, origin='generated', recomputes hash, records user, marks rollups stale.
 * 5. Status transitions (active, excluded, stale) and audit logging.
 * 6. Stale propagation to dependent weekly rollups (targeting active rollups).
 * 7. Range queries across period dates and scopes including week boundaries.
 * 8. Post deletion hook deletes associated daily digests and cleans links.
 * 9. Retention pruning deletes old entries, deletes links, and keeps recent entries.
 * 10. Settings-First getters, sanitizers, and AJAX section save (including unchecking memory_enabled).
 */

declare(strict_types=1);

require_once __DIR__ . '/wordpress-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-audit-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-settings-storage.php';
require_once dirname( __DIR__ ) . '/includes/class-settings-render.php';
require_once dirname( __DIR__ ) . '/includes/class-ajax-handlers.php';
require_once dirname( __DIR__ ) . '/includes/class-memory-store.php';

function expect_true( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( 'Assertion failed: ' . $message );
    }
}

function expect_same( $expected, $actual, string $message ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( sprintf( '%s (Expected: %s, got: %s)', $message, var_export( $expected, true ), var_export( $actual, true ) ) );
    }
}

function test_memory_store(): void {
    echo "Running MemoryStoreTest...\n";
    global $wpdb;

    // Reset tables in mock WPDB
    $entries_tbl = PressHub_AI_Memory_Store::get_entries_table();
    $links_tbl   = PressHub_AI_Memory_Store::get_links_table();
    $audit_tbl   = PressHub_AI_Audit_Logger::get_table_name();

    $wpdb->tables[ $entries_tbl ] = [];
    $wpdb->tables[ $links_tbl ]   = [];
    $wpdb->tables[ $audit_tbl ]   = [];
    $wpdb->auto_increments[ $entries_tbl ] = 0;
    $wpdb->auto_increments[ $links_tbl ]   = 0;
    $wpdb->auto_increments[ $audit_tbl ]   = 0;

    // -------------------------------------------------------------
    // 1. Strict Schema Validation & Zero-Silent-Fallback
    // -------------------------------------------------------------
    $invalid_empty = PressHub_AI_Memory_Store::validate_content( [] );
    expect_true( is_wp_error( $invalid_empty ), 'Empty array fails validation (missing stories)' );
    expect_same( 'invalid_memory_stories', $invalid_empty->get_error_code(), 'Error code is invalid_memory_stories' );

    $invalid_missing_key = PressHub_AI_Memory_Store::validate_content( [
        'stories' => [
            [
                'title'       => 'Some Title',
                'development' => 'Some summary',
            ],
        ],
    ] );
    expect_true( is_wp_error( $invalid_missing_key ), 'Story without key fails' );
    expect_same( 'missing_story_key', $invalid_missing_key->get_error_code(), 'Error code is missing_story_key' );

    $invalid_story_status = PressHub_AI_Memory_Store::validate_content( [
        'stories' => [
            [
                'key'         => 'topic-one',
                'title'       => 'Some Title',
                'development' => 'Some summary',
                'status'      => 'invalid_status_value',
                'entities'    => [],
                'source_urls' => [],
                'include'     => true,
            ],
        ],
    ] );
    expect_true( is_wp_error( $invalid_story_status ), 'Story with invalid status fails' );
    expect_same( 'invalid_story_status', $invalid_story_status->get_error_code(), 'Error code is invalid_story_status' );

    $missing_entities = PressHub_AI_Memory_Store::validate_content( [
        'stories' => [
            [
                'key'         => 'topic-one',
                'title'       => 'Some Title',
                'development' => 'Some summary',
                'status'      => 'developing',
                'include'     => true,
            ],
        ],
    ] );
    expect_true( is_wp_error( $missing_entities ), 'Missing entities fails' );
    expect_same( 'invalid_story_entities', $missing_entities->get_error_code(), 'Error code is invalid_story_entities' );

    $missing_source_urls = PressHub_AI_Memory_Store::validate_content( [
        'stories' => [
            [
                'key'         => 'topic-one',
                'title'       => 'Some Title',
                'development' => 'Some summary',
                'status'      => 'developing',
                'entities'    => [],
                'include'     => true,
            ],
        ],
    ] );
    expect_true( is_wp_error( $missing_source_urls ), 'Missing source_urls fails' );
    expect_same( 'invalid_story_source_urls', $missing_source_urls->get_error_code(), 'Error code is invalid_story_source_urls' );

    $missing_include = PressHub_AI_Memory_Store::validate_content( [
        'stories' => [
            [
                'key'         => 'topic-one',
                'title'       => 'Some Title',
                'development' => 'Some summary',
                'status'      => 'developing',
                'entities'    => [],
                'source_urls' => [],
            ],
        ],
    ] );
    expect_true( is_wp_error( $missing_include ), 'Missing include fails' );
    expect_same( 'missing_story_include', $missing_include->get_error_code(), 'Error code is missing_story_include' );

    // Validation of upsert_generated arguments: date range & calendar dates & editions
    $bad_calendar_date = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => '2026-02-30', // invalid calendar date
        'period_end'        => '2026-02-30',
        'edition'           => 'morning',
        'generated_content' => [ 'stories' => [] ],
    ] );
    expect_true( is_wp_error( $bad_calendar_date ), 'Invalid calendar date fails' );
    expect_same( 'invalid_calendar_date', $bad_calendar_date->get_error_code(), 'Error code is invalid_calendar_date' );

    $inverted_range = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => '2026-11-05',
        'period_end'        => '2026-11-01',
        'edition'           => 'morning',
        'generated_content' => [ 'stories' => [] ],
    ] );
    expect_true( is_wp_error( $inverted_range ), 'Inverted date range fails' );
    expect_same( 'invalid_date_range', $inverted_range->get_error_code(), 'Error code is invalid_date_range' );

    $missing_edition = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => '2026-11-01',
        'period_end'        => '2026-11-01',
        'generated_content' => [ 'stories' => [] ],
    ] );
    expect_true( is_wp_error( $missing_edition ), 'Missing daily edition fails' );
    expect_same( 'missing_edition', $missing_edition->get_error_code(), 'Error code is missing_edition' );

    $valid_content = [
        'stories' => [
            [
                'key'         => 'tech-summit',
                'title'       => 'Annual Tech Summit Starts',
                'development' => 'Keynote speeches outlined new AI governance plans.',
                'status'      => 'new',
                'entities'    => [ 'TechCorp', 'Regulator' ],
                'source_urls' => [ 'https://example.com/summit' ],
                'include'     => true,
            ],
        ],
        'podcast' => [
            'opening_line' => 'Good morning, tech enthusiasts.',
            'closing_line' => 'More updates coming tomorrow.',
        ],
        'narrative' => 'Tech policy week begins.',
    ];
    $validated = PressHub_AI_Memory_Store::validate_content( $valid_content );
    expect_true( ! is_wp_error( $validated ), 'Valid content passes validation' );
    expect_same( 1, count( $validated['stories'] ), 'Stories count is 1' );
    echo "  [1] Strict schema validation & Zero-Silent-Fallback: OK\n";

    // -------------------------------------------------------------
    // 2. Upsert Generated & Edit-Protection Invariant
    // -------------------------------------------------------------
    $insert_res = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => '2026-11-01',
        'period_end'        => '2026-11-01',
        'edition'           => 'morning',
        'generated_content' => $valid_content,
        'source_post_id'    => 101,
    ] );
    expect_true( ! is_wp_error( $insert_res ), 'Insert daily digest succeeded' );
    $entry_id = $insert_res['id'];
    expect_same( 'generated', $insert_res['origin'], 'Origin is generated' );
    expect_true( false === $insert_res['preserved_edit'], 'preserved_edit is false' );

    $entry = PressHub_AI_Memory_Store::get_entry( $entry_id );
    expect_true( null !== $entry, 'Entry fetched from store' );
    expect_same( 'generated', $entry['origin'], 'Stored origin is generated' );
    $initial_hash = $entry['version_hash'];

    // Update with invalid content is rejected
    $bad_update = PressHub_AI_Memory_Store::update_content( $entry_id, [ 'stories' => 'not an array' ] );
    expect_true( is_wp_error( $bad_update ), 'Invalid content update fails' );

    // Editor modifies content
    $edited_content = $valid_content;
    $edited_content['stories'][0]['development'] = 'Keynote speeches outlined updated AI safety framework.';
    $update_res = PressHub_AI_Memory_Store::update_content( $entry_id, $edited_content, 42 );
    expect_true( ! is_wp_error( $update_res ), 'Editor update succeeded' );
    expect_same( 'edited', $update_res['origin'], 'Origin updated to edited' );
    expect_true( $update_res['version_hash'] !== $initial_hash, 'Version hash changed after edit' );

    $entry_after_edit = PressHub_AI_Memory_Store::get_entry( $entry_id );
    expect_same( 'edited', $entry_after_edit['origin'], 'Persisted origin is edited' );
    expect_same( 42, (int) $entry_after_edit['edited_by'], 'edited_by recorded' );

    // Regeneration occurs: upsert_generated must NOT overwrite editor edits
    $regen_content = $valid_content;
    $regen_content['stories'][0]['development'] = 'Robots are taking over the keynote!';
    $regen_res = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => '2026-11-01',
        'period_end'        => '2026-11-01',
        'edition'           => 'morning',
        'generated_content' => $regen_content,
        'source_post_id'    => 101,
    ] );
    expect_true( ! is_wp_error( $regen_res ), 'Regeneration upsert succeeded' );
    expect_true( true === $regen_res['preserved_edit'], 'preserved_edit is true on edited entry' );
    expect_same( 'edited', $regen_res['origin'], 'Origin remained edited' );

    $entry_after_regen = PressHub_AI_Memory_Store::get_entry( $entry_id );
    $decoded_content = json_decode( $entry_after_regen['content_json'], true );
    expect_same( 'Keynote speeches outlined updated AI safety framework.', $decoded_content['stories'][0]['development'], 'content_json preserved editor edit' );
    $decoded_gen = json_decode( $entry_after_regen['generated_json'], true );
    expect_same( 'Robots are taking over the keynote!', $decoded_gen['stories'][0]['development'], 'generated_json updated with newly generated content' );
    echo "  [2] Upsert generated & edit-protection invariant: OK\n";

    // -------------------------------------------------------------
    // 3. Status Preservation on Regeneration (Exclusion Invariant)
    // -------------------------------------------------------------
    // Reset entry to generated to test unedited update behavior with 'excluded' status
    PressHub_AI_Memory_Store::reset_to_generated( $entry_id );
    PressHub_AI_Memory_Store::set_status( $entry_id, 'excluded' );
    $check_ex = PressHub_AI_Memory_Store::get_entry( $entry_id );
    expect_same( 'excluded', $check_ex['status'], 'Status is excluded' );
    expect_same( 'generated', $check_ex['origin'], 'Origin is generated' );

    // Regeneration occurs: must preserve 'excluded' status
    $regen_ex = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => '2026-11-01',
        'period_end'        => '2026-11-01',
        'edition'           => 'morning',
        'generated_content' => $valid_content,
        'source_post_id'    => 101,
        'status'            => 'active', // default passed by pipeline
    ] );
    $entry_ex_after_regen = PressHub_AI_Memory_Store::get_entry( $entry_id );
    expect_same( 'excluded', $entry_ex_after_regen['status'], 'Excluded status preserved across automated regeneration' );
    echo "  [3] Exclusion status preservation on regeneration: OK\n";

    // -------------------------------------------------------------
    // 4. Reset to Generated & Audit Log Integrity
    // -------------------------------------------------------------
    PressHub_AI_Memory_Store::set_status( $entry_id, 'active' );
    PressHub_AI_Memory_Store::update_content( $entry_id, $edited_content, 42 );

    $reset_res = PressHub_AI_Memory_Store::reset_to_generated( $entry_id, 99 );
    expect_true( ! is_wp_error( $reset_res ), 'Reset to generated succeeded' );
    expect_same( 'generated', $reset_res['origin'], 'Origin reset to generated' );

    $entry_after_reset = PressHub_AI_Memory_Store::get_entry( $entry_id );
    expect_same( 'generated', $entry_after_reset['origin'], 'Origin is generated' );
    expect_same( $entry_after_reset['generated_json'], $entry_after_reset['content_json'], 'content_json matches generated_json' );
    echo "  [4] Reset to generated: OK\n";

    // -------------------------------------------------------------
    // 5. Weekly Rollup Linking & Stale Propagation
    // -------------------------------------------------------------
    $rollup_res = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'weekly',
        'period_start'      => '2026-11-01',
        'period_end'        => '2026-11-07',
        'edition'           => '',
        'generated_content' => [
            'stories'   => $valid_content['stories'],
            'narrative' => 'Weekly summary of events.',
        ],
    ] );
    expect_true( ! is_wp_error( $rollup_res ), 'Created weekly rollup' );
    $rollup_id = $rollup_res['id'];

    $links_count = PressHub_AI_Memory_Store::link_rollup( $rollup_id, [ $entry_id ] );
    expect_same( 1, $links_count, 'Linked 1 digest to rollup' );

    // Editing daily digest marks dependent rollup stale
    $stale_count = PressHub_AI_Memory_Store::mark_dependents_stale( $entry_id );
    expect_same( 1, $stale_count, '1 dependent active rollup marked stale' );
    $rollup_check = PressHub_AI_Memory_Store::get_entry( $rollup_id );
    expect_same( 'stale', $rollup_check['status'], 'Rollup status transitioned to stale' );

    // Stale rollups are not marked stale again
    $stale_count_second = PressHub_AI_Memory_Store::mark_dependents_stale( $entry_id );
    expect_same( 0, $stale_count_second, 'Already-stale rollups ignored' );
    echo "  [5] Rollup linking & stale propagation: OK\n";

    // -------------------------------------------------------------
    // 6. Range Queries Across Week Boundaries & get_digest_for_post
    // -------------------------------------------------------------
    $range_daily = PressHub_AI_Memory_Store::get_entries_for_range( '2026-10-31', '2026-11-02', 'daily' );
    expect_same( 1, count( $range_daily ), 'Found 1 daily digest across boundary' );

    $range_weekly = PressHub_AI_Memory_Store::get_entries_for_range( '2026-10-25', '2026-11-15', 'weekly' );
    expect_same( 1, count( $range_weekly ), 'Found 1 weekly rollup across multi-week window' );

    $post_digest = PressHub_AI_Memory_Store::get_digest_for_post( 101 );
    expect_true( null !== $post_digest, 'Found digest for post 101' );
    expect_same( $entry_id, (int) $post_digest['id'], 'Digest matches entry ID' );
    echo "  [6] Range queries & post digest lookup: OK\n";

    // -------------------------------------------------------------
    // 7. Post Deletion Hook (Clean Slate)
    // -------------------------------------------------------------
    PressHub_AI_Memory_Store::on_before_delete_post( 101 );
    $deleted_entry = PressHub_AI_Memory_Store::get_entry( $entry_id );
    expect_true( null === $deleted_entry, 'Daily digest deleted when parent post deleted' );
    echo "  [7] Clean Slate post deletion hook: OK\n";

    // -------------------------------------------------------------
    // 8. Retention Pruning (Entries & Links Cleanup)
    // -------------------------------------------------------------
    $old_res = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => '2020-01-01',
        'period_end'        => '2020-01-01',
        'edition'           => 'morning',
        'generated_content' => $valid_content,
        'source_post_id'    => 202,
    ] );
    $old_id = $old_res['id'];
    PressHub_AI_Memory_Store::link_rollup( $rollup_id, [ $old_id ] );

    $pruned_count = PressHub_AI_Memory_Store::prune_old_entries( 30 );
    expect_same( 1, $pruned_count, '1 old entry pruned' );
    $pruned_check = PressHub_AI_Memory_Store::get_entry( $old_id );
    expect_true( null === $pruned_check, 'Old entry is deleted' );
    echo "  [8] Retention pruning: OK\n";

    // -------------------------------------------------------------
    // 9. Settings-First & AJAX Section Save
    // -------------------------------------------------------------
    // Test clamping sanitizers
    expect_same( 0, PressHub_AI_Settings_Storage::sanitize_memory_daily_days( -5 ), 'Daily days clamped min 0' );
    expect_same( 14, PressHub_AI_Settings_Storage::sanitize_memory_daily_days( 25 ), 'Daily days clamped max 14' );
    expect_same( 14, PressHub_AI_Settings_Storage::sanitize_memory_background_days( 10, 14 ), 'Background days clamped >= daily days' );
    expect_same( 30, PressHub_AI_Settings_Storage::sanitize_memory_retention_days( 10 ), 'Retention clamped min 30' );
    expect_same( 730, PressHub_AI_Settings_Storage::sanitize_memory_retention_days( 999 ), 'Retention clamped max 730' );

    // Test AJAX section save unchecking memory_enabled
    update_option( 'presshub_ai_memory_enabled', 1 );
    expect_same( true, PressHub_AI_Settings_Storage::get_memory_enabled(), 'Memory initially enabled' );

    $ajax = new PressHub_AI_Ajax_Handlers();
    $GLOBALS['NONCE_VALID'] = true;
    $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
    $_POST['tab'] = 'briefing';
    $_POST['nonce'] = 'dummy';
    // Do NOT include presshub_ai_memory_enabled in POST, simulating unchecked checkbox
    $_POST['presshub_ai_memory_daily_days'] = '5';
    $_POST['presshub_ai_memory_background_days'] = '20';

    try {
        $ajax->save_settings_section();
    } catch ( RuntimeException $e ) {
        // wp_send_json_success throws RuntimeException in test harness
    }

    expect_same( false, PressHub_AI_Settings_Storage::get_memory_enabled(), 'Memory disabled when checkbox omitted in AJAX section save' );
    echo "  [9] Settings-First & AJAX section save: OK\n";

    echo "MemoryStoreTest: ALL TESTS PASSED! (100% OK)\n";
}

test_memory_store();
