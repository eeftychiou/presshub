<?php
/**
 * Test suite for PressHub_AI_Memory_Editor (Issue #137).
 *
 * Explicit check() helper instead of assert() so failures exit 1 even when
 * zend.assertions is disabled.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-audit-logger.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-generation-recorder.php';
require_once __DIR__ . '/../includes/class-generation-inspector.php';
require_once __DIR__ . '/../includes/class-memory-store.php';
require_once __DIR__ . '/../includes/class-memory-context-builder.php';
require_once __DIR__ . '/../includes/class-memory-editor.php';

if ( ! defined( 'PRESSHUB_AI_URL' ) ) {
    define( 'PRESSHUB_AI_URL', 'http://example.test/wp-content/plugins/presshub-ai-editor/' );
}
if ( ! defined( 'PRESSHUB_AI_VERSION' ) ) {
    define( 'PRESSHUB_AI_VERSION', 'test' );
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false ) {
        $GLOBALS['ENQUEUED_SCRIPTS'][ $handle ] = $src;
    }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
        $GLOBALS['ENQUEUED_STYLES'][ $handle ] = $src;
    }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( $handle, $object_name, $data ) {
        $GLOBALS['LOCALIZED_SCRIPTS'][ $handle ] = $data;
    }
}

$GLOBALS['MEM_FAILURES'] = 0;

function mem_check( $condition, string $message ): void {
    if ( ! $condition ) {
        $GLOBALS['MEM_FAILURES']++;
        echo '  FAIL: ' . $message . PHP_EOL;
    }
}

/** Run an AJAX handler; returns [ 'exception' => msg|null, 'response' => last JSON response|null ]. */
function mem_call( callable $fn ): array {
    $GLOBALS['JSON_RESPONSES'] = [];
    $exception = null;
    try {
        $fn();
    } catch ( RuntimeException $e ) {
        $exception = $e->getMessage();
    }
    $responses = $GLOBALS['JSON_RESPONSES'];
    return [ 'exception' => $exception, 'response' => empty( $responses ) ? null : end( $responses ) ];
}

function mem_user( array $caps, bool $nonce_valid = true ): void {
    $GLOBALS['CURRENT_USER_CAPS'] = $caps;
    $GLOBALS['NONCE_VALID']       = $nonce_valid;
}

function mem_audit_events( string $type ): int {
    global $wpdb;
    $n = 0;
    foreach ( (array) ( $wpdb->tables[ PressHub_AI_Audit_Logger::get_table_name() ] ?? [] ) as $row ) {
        if ( ( $row['event_type'] ?? '' ) === $type ) {
            $n++;
        }
    }
    return $n;
}

function mem_story( string $key, string $title, string $dev, string $status = 'new' ): array {
    return [ 'key' => $key, 'title' => $title, 'development' => $dev, 'status' => $status, 'entities' => [ 'Αθήνα' ], 'source_urls' => [ 'https://example.gr/' . $key ], 'include' => true ];
}

function test_memory_editor() {
    global $wpdb;
    echo 'Running MemoryEditorTest...' . PHP_EOL;

    foreach ( [ PressHub_AI_Memory_Store::get_entries_table(), PressHub_AI_Memory_Store::get_links_table(), PressHub_AI_Audit_Logger::get_table_name() ] as $t ) {
        $wpdb->tables[ $t ]          = [];
        $wpdb->auto_increments[ $t ] = 0;
    }
    update_option( 'presshub_ai_memory_enabled', 1 );
    update_option( 'presshub_ai_memory_daily_days', 3 );
    update_option( 'presshub_ai_memory_background_days', 14 );

    $xss   = '<script>alert("x")</script>';
    $today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
    $d1    = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
    $d3    = gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) );

    $r1 = PressHub_AI_Memory_Store::upsert_generated( [
        'scope' => 'daily', 'period_start' => $d1, 'period_end' => $d1, 'edition' => 'morning', 'source_post_id' => 55,
        'generation_record_id' => 9,
        'generated_content' => [ 'stories' => [ mem_story( 'greek-story', 'Ελληνική ιστορία ' . $xss, 'Αρχική εξέλιξη', 'new' ), mem_story( 'other', 'Other', 'Other dev', 'developing' ) ] ],
    ] );
    $id1 = (int) $r1['id'];
    $r2 = PressHub_AI_Memory_Store::upsert_generated( [
        'scope' => 'weekly', 'period_start' => gmdate( 'Y-m-d', strtotime( $today . ' -7 days' ) ), 'period_end' => $d1, 'edition' => '',
        'generated_content' => [ 'stories' => [ mem_story( 'wk', 'Weekly', 'Weekly dev' ) ], 'narrative' => 'Εβδομαδιαία αφήγηση' ],
    ] );
    $rid = (int) $r2['id'];
    PressHub_AI_Memory_Store::link_rollup( $rid, [ $id1 ] );

    $editor = new PressHub_AI_Memory_Editor();
    mem_check( isset( $GLOBALS['ACTIONS']['wp_ajax_presshub_ai_memory_get'], $GLOBALS['ACTIONS']['wp_ajax_presshub_ai_memory_save'], $GLOBALS['ACTIONS']['wp_ajax_presshub_ai_memory_action'] ), 'AJAX actions registered' );

    // 1. Capability and nonce gates on every endpoint.
    $endpoints = [
        'get'    => [ $editor, 'ajax_get' ],
        'save'   => [ $editor, 'ajax_save' ],
        'action' => [ $editor, 'ajax_action' ],
    ];
    foreach ( $endpoints as $name => $cb ) {
        $_POST = [ 'nonce' => 'x', 'entry_id' => $id1, 'op' => 'exclude', 'stories' => '[]' ];
        mem_user( [ 'edit_others_posts' ], false );
        $res = mem_call( $cb );
        mem_check( null !== $res['exception'] && false !== strpos( (string) $res['exception'], 'check_ajax_referer' ), "$name: bad nonce rejected" );
        mem_user( [ 'edit_posts' ], true );
        $res = mem_call( $cb );
        mem_check( null !== $res['response'] && false === $res['response']['success'], "$name: missing capability rejected" );
    }
    mem_check( 'active' === PressHub_AI_Memory_Store::get_entry( $id1 )['status'], 'denied exclude did not mutate' );
    mem_check( 0 === mem_audit_events( 'memory_status_changed' ), 'denied requests wrote no audit entries' );

    // 2. Get payload (Greek preserved, diff empty, can-flags).
    mem_user( [ 'edit_others_posts' ], true );
    $_POST = [ 'nonce' => 'x', 'entry_id' => $id1 ];
    $res  = mem_call( [ $editor, 'ajax_get' ] );
    $data = $res['response']['data'] ?? [];
    mem_check( ! empty( $res['response']['success'] ), 'get succeeds' );
    mem_check( 2 === count( $data['content']['stories'] ), 'two stories returned' );
    mem_check( 'Αρχική εξέλιξη' === $data['content']['stories'][0]['development'], 'Greek development intact' );
    mem_check( [] === $data['diff'], 'no diff for generated entry' );
    mem_check( false === $data['can']['reset'] && true === $data['can']['exclude'], 'can flags for generated daily' );
    mem_check( false !== strpos( $data['record_url'], 'record_id=9' ), 'record url links to inspector' );

    // 3. Save validation rejects an invalid story status (store message).
    $bad = [ [ 'key' => 'greek-story', 'title' => 'T', 'development' => 'D', 'status' => 'bogus', 'entities' => [], 'include' => true ] ];
    $_POST = [ 'nonce' => 'x', 'entry_id' => $id1, 'stories' => wp_json_encode( $bad ) ];
    $res = mem_call( [ $editor, 'ajax_save' ] );
    mem_check( false === ( $res['response']['success'] ?? null ), 'invalid status rejected' );
    mem_check( 'invalid_story_status' === ( $res['response']['data']['code'] ?? '' ) && false !== strpos( (string) ( $res['response']['data']['message'] ?? '' ), 'invalid status' ), 'store error message surfaced' );
    mem_check( 'generated' === PressHub_AI_Memory_Store::get_entry( $id1 )['origin'], 'rejected save left origin generated' );
    $_POST['stories'] = 'not json';
    $res = mem_call( [ $editor, 'ajax_save' ] );
    mem_check( false === ( $res['response']['success'] ?? null ), 'non-JSON stories rejected' );

    // 4. Valid save: via store, origin=edited, audit entry, diff, stale rollup.
    $good = [
        [ 'key' => 'greek-story', 'title' => 'Ελληνική ιστορία', 'development' => 'Διορθωμένη εξέλιξη', 'status' => 'developing', 'entities' => [ 'Αθήνα', 'ΕΕ' ], 'include' => true ],
        [ 'key' => '', 'title' => 'Brand new', 'development' => 'Added by editor', 'status' => 'new', 'entities' => [], 'include' => false ],
    ];
    $_POST = [ 'nonce' => 'x', 'entry_id' => $id1, 'stories' => wp_json_encode( $good ) ];
    $res   = mem_call( [ $editor, 'ajax_save' ] );
    $data  = $res['response']['data'] ?? [];
    mem_check( ! empty( $res['response']['success'] ), 'valid save succeeds' );
    $entry   = PressHub_AI_Memory_Store::get_entry( $id1 );
    $content = json_decode( $entry['content_json'], true );
    mem_check( 'edited' === $entry['origin'], 'origin=edited after save' );
    mem_check( 1 === mem_audit_events( 'memory_content_updated' ), 'save created an audit entry via the store' );
    mem_check( in_array( 'edited', $data['entry']['badges'] ?? [], true ), 'edited badge returned' );
    mem_check( 'https://example.gr/greek-story' === ( $content['stories'][0]['source_urls'][0] ?? '' ), 'source_urls preserved for existing story' );
    mem_check( 2 === count( $content['stories'] ), 'story deleted and story added' );
    $types = array_column( $data['diff'] ?? [], 'type' );
    mem_check( in_array( 'changed', $types, true ) && in_array( 'added', $types, true ) && in_array( 'removed', $types, true ), 'diff lists changed, added and removed stories' );
    mem_check( 1 === (int) ( $data['dependents_stale'] ?? 0 ) && 'stale' === PressHub_AI_Memory_Store::get_entry( $rid )['status'], 'dependent rollup marked stale' );
    mem_check( 1 === count( $data['stale_rollups'] ?? [] ) && $rid === $data['stale_rollups'][0]['id'], 'stale rollup listed for one-click rebuild' );
    mem_check( true === ( $data['can']['reset'] ?? false ), 'reset available after edit' );

    // 5. Rollup actions hidden when the #136 class is absent; rebuild endpoint refuses.
    mem_check( ! class_exists( 'PressHub_AI_Memory_Rollup_Builder' ), 'rollup builder absent in this test process' );
    mem_check( false === PressHub_AI_Memory_Editor::rollup_actions_available(), 'rollup actions unavailable' );
    $_POST = [ 'nonce' => 'x', 'entry_id' => $rid, 'op' => 'rebuild' ];
    $res = mem_call( [ $editor, 'ajax_action' ] );
    mem_check( false === ( $res['response']['success'] ?? null ), 'rebuild refused without builder' );
    $_POST = [ 'nonce' => 'x', 'entry_id' => $rid ];
    $res = mem_call( [ $editor, 'ajax_get' ] );
    mem_check( false === $res['response']['data']['can']['rebuild'] && true === $res['response']['data']['can']['narrative'], 'rebuild hidden, narrative editable for rollups' );
    $editor->enqueue_assets( 'daily-briefing-hub_page_presshub-ai-memory' );
    mem_check( false === ( $GLOBALS['LOCALIZED_SCRIPTS']['presshub-ai-memory-editor']['data']['rollup_available'] ?? null ), 'JS config reports rollups unavailable' );

    // 6. Rollup narrative edit (in scope regardless of #136).
    $_POST = [
        'nonce' => 'x', 'entry_id' => $rid,
        'stories' => wp_json_encode( [ [ 'key' => 'wk', 'title' => 'Weekly', 'development' => 'Weekly dev', 'status' => 'new', 'entities' => [], 'include' => true ] ] ),
        'narrative' => 'Νέα αφήγηση',
    ];
    $res = mem_call( [ $editor, 'ajax_save' ] );
    mem_check( ! empty( $res['response']['success'] ) && 'Νέα αφήγηση' === ( json_decode( PressHub_AI_Memory_Store::get_entry( $rid )['content_json'], true )['narrative'] ?? '' ), 'rollup narrative saved' );

    // 7. Reset / exclude / include go through the store with audit entries.
    $_POST = [ 'nonce' => 'x', 'entry_id' => $id1, 'op' => 'reset' ];
    mem_call( [ $editor, 'ajax_action' ] );
    mem_check( 'generated' === PressHub_AI_Memory_Store::get_entry( $id1 )['origin'] && 1 === mem_audit_events( 'memory_reset_to_generated' ), 'reset via store, audited' );
    $_POST['op'] = 'exclude';
    mem_call( [ $editor, 'ajax_action' ] );
    mem_check( 'excluded' === PressHub_AI_Memory_Store::get_entry( $id1 )['status'] && 1 === mem_audit_events( 'memory_status_changed' ), 'exclude via store, audited' );
    $res = mem_call( [ $editor, 'ajax_action' ] );
    mem_check( false === ( $res['response']['success'] ?? null ), 'excluding an excluded entry refused' );
    $_POST['op'] = 'include';
    mem_call( [ $editor, 'ajax_action' ] );
    mem_check( 'active' === PressHub_AI_Memory_Store::get_entry( $id1 )['status'] && 2 === mem_audit_events( 'memory_status_changed' ), 'include via store, audited' );
    $_POST['op'] = 'nonsense';
    $res = mem_call( [ $editor, 'ajax_action' ] );
    mem_check( false === ( $res['response']['success'] ?? null ), 'unknown op refused' );

    // 8. Page: timeline, gaps, XSS inert.
    PressHub_AI_Memory_Store::update_content( $id1, [ 'stories' => [ mem_story( 'x', 'Title ' . $xss, 'Dev ' . $xss ) ] ] );
    PressHub_AI_Memory_Store::set_status( $rid, 'stale' );
    $html = PressHub_AI_Memory_Editor::build_page( [ 'entry_id' => $id1 ] );
    mem_check( false === strpos( $html, '<script' ), 'page contains no raw script tag' );
    mem_check( false !== strpos( $html, 'presshub-memory-missing' ) && false !== strpos( $html, $d3 ), 'dates without digest highlighted' );
    mem_check( false !== strpos( $html, 'presshub-memory-badge-edited' ) && false !== strpos( $html, 'presshub-memory-badge-stale' ), 'status badges rendered' );
    mem_check( false !== strpos( $html, 'post.php?post=55' ), 'source post link rendered' );
    mem_check( false !== strpos( $html, 'data-open-entry="' . $id1 . '"' ), 'deep link entry id carried to the page' );
    mem_check( false !== strpos( $html, 'presshub-memory-preview' ), 'preview panel rendered when context builder exists' );
    mem_check( false === strpos( $html, 'Rebuild rollup' ), 'no rebuild action in timeline without builder' );
    $_POST = [ 'nonce' => 'x', 'entry_id' => $id1 ];
    $res = mem_call( [ $editor, 'ajax_get' ] );
    mem_check( false !== strpos( $res['response']['data']['content']['stories'][0]['development'], '<script>' ), 'payload keeps story text as inert JSON data' );

    // Hostile story text reaching server-rendered HTML (error detail) is escaped.
    PressHub_AI_Memory_Store::record_failed_digest( gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) ), 'morning', 'Boom ' . $xss, 77 );
    $html = PressHub_AI_Memory_Editor::build_page( [] );
    mem_check( false === strpos( $html, '<script' ) && false !== strpos( $html, '&lt;script&gt;' ) && false !== strpos( $html, 'presshub-memory-badge-failed' ), 'failed entry error text rendered escaped' );

    // 9. JS never injects HTML; no alert().
    $js = (string) file_get_contents( __DIR__ . '/../assets/memory-editor.js' );
    mem_check( 1 !== preg_match( '/innerHTML|\.html\(|insertAdjacentHTML|document\.write|eval\(/', $js ), 'memory-editor.js never injects HTML' );
    mem_check( 1 !== preg_match( '/\balert\s*\(/', $js ), 'memory-editor.js has no alert()' );

    // 10. Inspector filter returns the editor URL.
    mem_user( [ 'edit_others_posts' ] );
    $url = apply_filters( 'presshub_ai_memory_editor_url', '', $id1 );
    mem_check( false !== strpos( $url, 'page=presshub-ai-memory' ) && false !== strpos( $url, 'entry_id=' . $id1 ), 'inspector filter returns memory editor URL' );
    mem_user( [ 'edit_posts' ] );
    mem_check( '' === apply_filters( 'presshub_ai_memory_editor_url', '', $id1 ), 'filter returns no URL without capability' );

    // 11. Page access gate.
    $denied = null;
    try {
        PressHub_AI_Memory_Editor::render_page();
    } catch ( RuntimeException $e ) {
        $denied = $e->getMessage();
    }
    mem_check( null !== $denied && 0 === strpos( $denied, 'wp_die' ), 'page denied without capability' );

    if ( $GLOBALS['MEM_FAILURES'] > 0 ) {
        echo 'MemoryEditorTest: ' . $GLOBALS['MEM_FAILURES'] . ' failure(s)' . PHP_EOL;
        exit( 1 );
    }
    echo 'OK' . PHP_EOL;
}

test_memory_editor();
