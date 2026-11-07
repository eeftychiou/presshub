<?php
/**
 * Test suite for PressHub_AI_Generation_Inspector (Issue #133).
 *
 * Explicit check() helper instead of assert() so failures are reported (exit 1)
 * even when zend.assertions is disabled.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-generation-recorder.php';
require_once __DIR__ . '/../includes/class-generation-inspector.php';
require_once __DIR__ . '/../includes/class-briefing-admin.php';

if ( ! defined( 'PRESSHUB_AI_URL' ) ) {
    define( 'PRESSHUB_AI_URL', 'http://example.test/wp-content/plugins/presshub-ai-editor/' );
}
if ( ! defined( 'PRESSHUB_AI_VERSION' ) ) {
    define( 'PRESSHUB_AI_VERSION', 'test' );
}
if ( ! function_exists( 'add_meta_box' ) ) {
    function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
        $GLOBALS['META_BOXES'][ $id ] = [ 'title' => $title, 'callback' => $callback, 'screen' => $screen ];
    }
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

$GLOBALS['INSP_FAILURES'] = 0;

function insp_check( $condition, string $message ): void {
    if ( ! $condition ) {
        $GLOBALS['INSP_FAILURES']++;
        echo '  FAIL: ' . $message . PHP_EOL;
    }
}

/** Run an AJAX handler; returns [ 'exception' => msg|null, 'response' => last JSON response|null ]. */
function insp_call( callable $fn ): array {
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

function insp_set_user( array $caps, bool $nonce_valid = true ): void {
    $GLOBALS['CURRENT_USER_CAPS'] = $caps;
    $GLOBALS['NONCE_VALID']       = $nonce_valid;
}

function test_generation_inspector() {
    global $wpdb;
    echo 'Running GenerationInspectorTest...' . PHP_EOL;

    $wpdb->tables          = [];
    $wpdb->queries         = [];
    $wpdb->auto_increments = [];
    $GLOBALS['POST_META_STORE'] = [];
    $GLOBALS['META_BOXES']      = [];
    PressHub_AI_Generation_Recorder::create_table();

    $xss = '<script>alert("x")</script>';

    // Seed records. #1 briefing (hostile content everywhere), #2 podcast with TTS, #3 digest.
    $id1 = PressHub_AI_Generation_Recorder::record( [
        'kind'          => 'briefing_text',
        'trace_id'      => 'trace-1',
        'briefing_date' => '2026-11-05',
        'edition'       => 'morning',
        'status'        => 'error',
        'error_message' => 'Boom ' . $xss,
        'config'        => [ 'provider_id' => 'gemini-main' . $xss, 'provider_type' => 'gemini', 'provider_source' => 'module_override', 'model' => 'gemini-3.1-flash', 'model_source' => 'global', 'temperature' => 0.4, 'max_tokens' => 8192, 'timeout' => 120, 'preset_id' => 'p1' . $xss ],
        'inputs'        => [
            'articles'       => [
                'sent'    => [
                    [ 'title' => 'Title ' . $xss, 'source' => 'Src ' . $xss, 'url' => 'https://example.gr/a?x=1&y="2"', 'chars_sent' => 1200, 'truncated' => true ],
                    [ 'title' => 'Evil link', 'source' => 'S', 'url' => 'javascript:alert(1)', 'chars_sent' => 10, 'truncated' => false ],
                ],
                'dropped' => [ [ 'title' => 'Dropped ' . $xss, 'source' => 'D', 'url' => '', 'reason' => 'cap_articles' ] ],
            ],
            'totals'         => [ 'cap_articles' => 30, 'cap_chars_per_article' => 4000, 'articles_count' => 2, 'articles_count_original' => 40, 'articles_truncated' => true, 'pool_tokens_estimate' => 9000 ],
            'token_estimate' => 5100,
        ],
        'memory'        => [ 'enabled' => true, 'entries' => [ [ 'id' => 7, 'tier' => 'daily', 'period' => '2026-11-04', 'edition' => 'morning', 'chars' => 900, 'origin' => 'edited' . $xss, 'status' => 'active' ] ], 'trimmed' => [ [ 'id' => 8, 'tier' => 'weekly', 'reason' => 'budget' ] ], 'budgets' => [ 'briefing_budget_chars' => 12000 ] ],
        'system_prompt' => 'SYS ' . $xss . ' & "q"',
        'user_prompt'   => 'USR ' . $xss,
        'response_text' => 'RESP ' . $xss,
        'usage'         => [ 'prompt_tokens' => 4000, 'completion_tokens' => 700, 'total_tokens' => 4700 ],
        'duration_ms'   => 2500,
    ] );
    $id2 = PressHub_AI_Generation_Recorder::record( [
        'kind' => 'podcast_script', 'briefing_date' => '2026-11-06', 'edition' => 'noon', 'response_text' => 'Podcast',
        'inputs' => [ 'articles' => [ [ 'title' => 'P1', 'source' => 'S', 'url' => 'https://p.example/1', 'chars_sent' => 50, 'truncated' => false ] ], 'not_selected' => [ [ 'title' => 'Skipped', 'source' => 'S', 'url' => '' ] ] ],
    ] );
    PressHub_AI_Generation_Recorder::update_record( (int) $id2, [ 'tts' => [ 'engine' => 'gemini-3.1', 'model' => 'tts-model', 'payload_mode' => 'multi', 'voices' => [ 'presenter_1' => 'Kore' . $xss, 'presenter_2' => 'Puck' ], 'style' => 'warm', 'speed' => 1.0, 'pitch' => 0, 'turns_count' => 12, 'script_source' => 'edited' ] ] );
    $id3 = PressHub_AI_Generation_Recorder::record( [ 'kind' => 'memory_digest', 'briefing_date' => '2026-11-07', 'status' => 'success', 'response_text' => 'digest' ] );
    // Distinct, ordered timestamps (stub insert uses current_time()).
    $table = PressHub_AI_Generation_Recorder::get_table_name();
    $wpdb->tables[ $table ][0]['created_at'] = '2026-11-05 07:15:00';
    $wpdb->tables[ $table ][1]['created_at'] = '2026-11-06 12:45:00';
    $wpdb->tables[ $table ][2]['created_at'] = '2026-11-07 02:00:00';

    // 1. Capability and nonce gates on every endpoint.
    $inspector = new PressHub_AI_Generation_Inspector();
    insp_check( isset( $GLOBALS['ACTIONS']['wp_ajax_presshub_ai_inspector_tab'] ) && isset( $GLOBALS['ACTIONS']['wp_ajax_presshub_ai_inspector_field'] ) && isset( $GLOBALS['ACTIONS']['wp_ajax_presshub_ai_inspector_raw'] ), 'AJAX actions registered' );
    $endpoints = [
        'tab'   => [ [ $inspector, 'ajax_tab' ], [ 'tab' => 'overview' ] ],
        'field' => [ [ $inspector, 'ajax_field' ], [ 'field' => 'system_prompt' ] ],
    ];
    foreach ( $endpoints as $name => $spec ) {
        insp_set_user( [ 'edit_others_posts' ], false );
        $_POST = array_merge( [ 'nonce' => 'bad', 'record_id' => $id1 ], $spec[1] );
        $res   = insp_call( $spec[0] );
        insp_check( 'check_ajax_referer failed' === $res['exception'] && null === $res['response'], "$name: invalid nonce rejected before any work" );

        insp_set_user( [ 'edit_posts', 'manage_options' ], true );
        $_POST = array_merge( [ 'nonce' => 'ok', 'record_id' => $id1 ], $spec[1] );
        $res   = insp_call( $spec[0] );
        insp_check( null !== $res['response'] && false === $res['response']['success'], "$name: user without edit_others_posts rejected" );
        insp_check( false === strpos( wp_json_encode( $res['response'] ), 'SYS' ), "$name: no record data leaked to unauthorised user" );
    }
    // Raw download gate.
    insp_set_user( [ 'edit_others_posts' ], false );
    $_GET = [ 'nonce' => 'bad', 'record_id' => $id1 ];
    $res  = insp_call( [ $inspector, 'ajax_raw' ] );
    insp_check( 'check_ajax_referer failed' === $res['exception'], 'raw: invalid nonce rejected' );
    insp_set_user( [ 'edit_posts' ], true );
    $res = insp_call( [ $inspector, 'ajax_raw' ] );
    insp_check( is_string( $res['exception'] ) && 0 === strpos( $res['exception'], 'wp_die' ), 'raw: user without capability rejected' );
    insp_set_user( [ 'edit_others_posts' ], true );
    $_GET = [ 'nonce' => 'ok', 'record_id' => 99999 ];
    $res  = insp_call( [ $inspector, 'ajax_raw' ] );
    insp_check( is_string( $res['exception'] ) && 0 === strpos( $res['exception'], 'wp_die' ), 'raw: unknown record rejected' );
    // Raw JSON content (builder used by the handler).
    $raw         = PressHub_AI_Generation_Inspector::build_raw_json( (int) $id1 );
    $raw_decoded = json_decode( (string) $raw, true );
    insp_check( is_array( $raw_decoded ) && 'trace-1' === $raw_decoded['trace_id'] && 'USR ' . $xss === $raw_decoded['user_prompt'] && 30 === $raw_decoded['inputs']['totals']['cap_articles'], 'raw JSON carries the full record' );
    insp_check( null === PressHub_AI_Generation_Inspector::build_raw_json( 99999 ), 'raw JSON null for missing record' );
    $inspector_src = (string) file_get_contents( __DIR__ . '/../includes/class-generation-inspector.php' );
    insp_check( false === strpos( $inspector_src, 'error_log(' ), 'no raw error_log in inspector' );
    echo '  [1] Capability and nonce gates: OK' . PHP_EOL;

    // 2. Field loader returns the right field, rejects unknown fields.
    insp_set_user( [ 'edit_others_posts' ], true );
    $expected = [ 'system_prompt' => 'SYS ' . $xss . ' & "q"', 'user_prompt' => 'USR ' . $xss, 'response_text' => 'RESP ' . $xss ];
    foreach ( $expected as $field => $value ) {
        $_POST = [ 'nonce' => 'ok', 'record_id' => $id1, 'field' => $field ];
        $res   = insp_call( [ $inspector, 'ajax_field' ] );
        insp_check( 'wp_send_json_success' === $res['exception'] && true === $res['response']['success'], "field $field: success" );
        insp_check( $field === $res['response']['data']['field'] && $value === $res['response']['data']['value'], "field $field: exact value returned" );
        insp_check( mb_strlen( $value ) === $res['response']['data']['chars'], "field $field: char count" );
    }
    foreach ( [ 'config_json', 'id', 'usage_json', '', 'system_prompt; DROP TABLE x', 'memory_json' ] as $bad ) {
        $_POST = [ 'nonce' => 'ok', 'record_id' => $id1, 'field' => $bad ];
        $res   = insp_call( [ $inspector, 'ajax_field' ] );
        insp_check( false === $res['response']['success'], 'field loader rejects unknown field "' . $bad . '"' );
    }
    $_POST = [ 'nonce' => 'ok', 'record_id' => 99999, 'field' => 'system_prompt' ];
    $res   = insp_call( [ $inspector, 'ajax_field' ] );
    insp_check( false === $res['response']['success'], 'field loader rejects missing record' );
    $_POST = [ 'nonce' => 'ok', 'record_id' => $id1, 'tab' => 'system_prompt' ];
    $res   = insp_call( [ $inspector, 'ajax_tab' ] );
    insp_check( false === $res['response']['success'], 'tab endpoint rejects text fields and unknown tabs' );
    $_POST = [ 'nonce' => 'ok', 'record_id' => 99999, 'tab' => 'overview' ];
    $res   = insp_call( [ $inspector, 'ajax_tab' ] );
    insp_check( false === $res['response']['success'], 'tab endpoint rejects missing record' );
    echo '  [2] Field loader: OK' . PHP_EOL;

    // 3. Hostile content renders inert in every server-rendered surface.
    $surfaces = [];
    foreach ( [ 'overview', 'articles', 'memory' ] as $tab ) {
        $_POST = [ 'nonce' => 'ok', 'record_id' => $id1, 'tab' => $tab ];
        $res   = insp_call( [ $inspector, 'ajax_tab' ] );
        insp_check( true === $res['response']['success'], "tab $tab renders" );
        $surfaces[ $tab ] = (string) ( $res['response']['data']['html'] ?? '' );
    }
    $_POST           = [ 'nonce' => 'ok', 'record_id' => $id2, 'tab' => 'tts' ];
    $res             = insp_call( [ $inspector, 'ajax_tab' ] );
    $surfaces['tts'] = (string) ( $res['response']['data']['html'] ?? '' );
    $surfaces['page']  = PressHub_AI_Generation_Inspector::build_records_page( [] );
    $surfaces['shell'] = PressHub_AI_Generation_Inspector::render_inspector( PressHub_AI_Generation_Inspector::query_records( [] ) );
    foreach ( $surfaces as $name => $html ) {
        insp_check( '' !== $html, "surface $name not empty" );
        insp_check( false === stripos( $html, '<script' ), "surface $name has no raw <script tag" );
    }
    insp_check( false !== strpos( $surfaces['articles'], '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;' ), 'article title escaped (visible as text)' );
    insp_check( false !== strpos( $surfaces['articles'], 'href="https://example.gr/a?x=1&amp;y=&quot;2&quot;"' ), 'http(s) article URL linked and attribute-escaped' );
    insp_check( false === stripos( $surfaces['articles'], 'javascript:' ), 'javascript: URL never becomes a link' );
    insp_check( false !== strpos( $surfaces['articles'], 'Evil link' ), 'title with unsafe URL still shown as text' );
    insp_check( false !== strpos( $surfaces['articles'], 'rel="noopener noreferrer nofollow"' ), 'external links carry rel' );
    insp_check( false !== strpos( $surfaces['tts'], 'Kore&lt;script&gt;' ), 'TTS voice escaped' );
    insp_check( false !== strpos( $surfaces['overview'], 'Boom &lt;script&gt;' ), 'error message escaped' );
    insp_check( false !== strpos( $surfaces['memory'], 'edited&lt;script&gt;' ), 'memory origin escaped' );
    $js = (string) file_get_contents( __DIR__ . '/../assets/generation-inspector.js' );
    insp_check( false === strpos( $js, 'innerHTML' ) && false !== strpos( $js, ".text(value === '' ? i18n.empty : value)" ), 'JS inserts prompts/response with text(), not HTML' );
    insp_check( 1 === preg_match_all( '/\.html\(/', $js ), 'JS uses .html() only for the server-escaped fragment' );
    $node = trim( (string) @shell_exec( DIRECTORY_SEPARATOR === '\\' ? 'where node 2>NUL' : 'command -v node 2>/dev/null' ) );
    if ( '' !== $node ) {
        $out  = [];
        $code = 1;
        exec( 'node --check ' . escapeshellarg( __DIR__ . '/../assets/generation-inspector.js' ) . ' 2>&1', $out, $code );
        insp_check( 0 === $code, 'generation-inspector.js passes node --check' );
    }
    echo '  [3] Escaping / inert <script>: OK' . PHP_EOL;

    // 4. Tab content: overview, articles, memory, tts.
    $ov = $surfaces['overview'];
    foreach ( [ 'trace-1', '2500 ms', 'gemini-3.1-flash', 'module_override', 'global', '8192', '120', '4700', '5100', 'Articles truncated' ] as $needle ) {
        insp_check( false !== strpos( $ov, $needle ), "overview shows $needle" );
    }
    $art = $surfaces['articles'];
    insp_check( false !== strpos( $art, '>30<' ) && false !== strpos( $art, '>4000<' ) && false !== strpos( $art, '>1200<' ), 'articles show caps and chars sent' );
    insp_check( false !== strpos( $art, 'Over the maximum article count' ), 'dropped articles show reason' );
    $_POST = [ 'nonce' => 'ok', 'record_id' => $id2, 'tab' => 'articles' ];
    $res   = insp_call( [ $inspector, 'ajax_tab' ] );
    $art2  = (string) $res['response']['data']['html'];
    insp_check( false !== strpos( $art2, 'P1' ) && false !== strpos( $art2, 'Skipped' ) && false !== strpos( $art2, 'Not selected by the editor' ), 'podcast article shape (list + not_selected) rendered' );
    $mem = $surfaces['memory'];
    insp_check( false !== strpos( $mem, 'daily' ) && false !== strpos( $mem, '12000' ) && false !== strpos( $mem, 'Entries trimmed' ), 'memory shows entries, trimmed and budgets' );
    insp_check( false === strpos( $mem, '<a ' ), 'memory entries are plain text without a memory editor' );
    add_filter( 'presshub_ai_memory_editor_url', function ( $url, $entry_id ) { return 'http://example.test/memory?entry=' . (int) $entry_id; }, 10, 2 );
    $mem_linked = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( (int) $id1 ), 'memory' );
    insp_check( false !== strpos( $mem_linked, 'href="http://example.test/memory?entry=7"' ), 'memory entry links to the editor when the filter supplies a URL' );
    remove_all_filters( 'presshub_ai_memory_editor_url' );
    $disabled = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( (int) $id3 ), 'memory' );
    insp_check( false !== strpos( $disabled, 'Memory was disabled for this generation' ), 'memory disabled message' );
    insp_check( false !== strpos( $surfaces['tts'], 'gemini-3.1' ) && false !== strpos( $surfaces['tts'], 'multi' ) && false !== strpos( $surfaces['tts'], 'edited' ), 'TTS tab shows engine, payload mode, script_source' );
    $tts_na = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( (int) $id1 ), 'tts' );
    insp_check( false !== strpos( $tts_na, 'podcast script, TikTok narration and TTS test records only' ), 'TTS tab n/a for briefing records' );
    $shell = $surfaces['shell'];
    insp_check( false === strpos( $shell, 'SYS' ) && false === strpos( $shell, 'RESP' ), 'inspector shell does not embed prompts/response' );
    insp_check( false !== strpos( $shell, 'data-tab="tts"' ), 'TTS tab button present in shell' );
    echo '  [4] Tab content: OK' . PHP_EOL;

    // 5. Metabox registration and no-record behaviour.
    insp_set_user( [ 'edit_others_posts' ], true );
    foreach ( [ 10 => 'text', 11 => 'podcast', 12 => 'other' ] as $pid => $type ) {
        $GLOBALS['POST_META_STORE'][ $pid ]['_presshub_briefing_type'] = $type;
    }
    $GLOBALS['POST_META_STORE'][10][ PressHub_AI_Generation_Recorder::POST_META_KEY ] = [ (int) $id1, (int) $id2, (int) $id3, 424242 ];
    foreach ( [ 10, 11, 12 ] as $pid ) {
        $GLOBALS['META_BOXES'] = [];
        $inspector->add_metabox( 'post', (object) [ 'ID' => $pid ] );
        $registered = isset( $GLOBALS['META_BOXES'][ PressHub_AI_Generation_Inspector::METABOX_ID ] );
        insp_check( $registered === ( 12 !== $pid ), "metabox registered only for briefing/podcast posts (post $pid)" );
    }
    insp_set_user( [ 'edit_posts' ], true );
    $GLOBALS['META_BOXES'] = [];
    $inspector->add_metabox( 'post', (object) [ 'ID' => 10 ] );
    insp_check( empty( $GLOBALS['META_BOXES'] ), 'metabox not registered without capability' );
    insp_set_user( [ 'edit_others_posts' ], true );

    ob_start();
    $inspector->render_metabox( (object) [ 'ID' => 11 ] );
    $empty_html = ob_get_clean();
    insp_check( false !== strpos( $empty_html, 'No generation record (generated before records were introduced)' ), 'post without records shows the legacy message' );
    insp_check( false === strpos( $empty_html, 'presshub-inspector-select' ), 'no selector when there are no records' );
    $GLOBALS['POST_META_STORE'][11][ PressHub_AI_Generation_Recorder::POST_META_KEY ] = [ 555 ];
    ob_start();
    $inspector->render_metabox( (object) [ 'ID' => 11 ] );
    insp_check( false !== strpos( (string) ob_get_clean(), 'generated before records were introduced' ), 'dangling record ids behave like no records' );

    ob_start();
    $inspector->render_metabox( (object) [ 'ID' => 10 ] );
    $box = ob_get_clean();
    insp_check( false !== strpos( $box, 'presshub-inspector-select' ), 'selector rendered for post with records' );
    $p1 = strpos( $box, 'value="' . $id3 . '"' );
    $p2 = strpos( $box, 'value="' . $id2 . '"' );
    $p3 = strpos( $box, 'value="' . $id1 . '"' );
    insp_check( $p1 < $p2 && $p2 < $p3 && false === strpos( $box, '424242' ), 'selector lists records newest first and skips missing ids' );
    insp_check( false !== strpos( $box, 'action=presshub_ai_inspector_raw' ) && false !== strpos( $box, 'nonce=test-nonce' ), 'raw download URL carries action and nonce' );
    // Assets only for inspectable posts / the records page.
    $GLOBALS['ENQUEUED_SCRIPTS'] = [];
    $_GET = [ 'post' => '12' ];
    $inspector->enqueue_assets( 'post.php' );
    insp_check( empty( $GLOBALS['ENQUEUED_SCRIPTS'] ), 'assets not enqueued on non-briefing posts' );
    $_GET = [ 'post' => '10' ];
    $inspector->enqueue_assets( 'post.php' );
    insp_check( isset( $GLOBALS['ENQUEUED_SCRIPTS'][ PressHub_AI_Generation_Inspector::SCRIPT_HANDLE ] ), 'assets enqueued on briefing posts' );
    $GLOBALS['ENQUEUED_SCRIPTS'] = [];
    $inspector->enqueue_assets( 'daily-briefing-hub_page_presshub-ai-generation-records' );
    insp_check( isset( $GLOBALS['ENQUEUED_SCRIPTS'][ PressHub_AI_Generation_Inspector::SCRIPT_HANDLE ] ), 'assets enqueued on the records page' );
    echo '  [5] Metabox / no records: OK' . PHP_EOL;

    // 6. Admin list filters and page.
    $all = PressHub_AI_Generation_Inspector::query_records( [] );
    insp_check( [ $id3, $id2, $id1 ] === array_column( $all, 'id' ), 'list is newest first' );
    insp_check( 'gemini-3.1-flash' === $all[2]['model'], 'list carries the model' );
    insp_check( ! isset( $all[0]['system_prompt'] ) && ! isset( $all[0]['response_text'] ), 'list never loads prompts/response' );
    $by = static function ( array $f ) { return array_column( PressHub_AI_Generation_Inspector::query_records( $f ), 'id' ); };
    insp_check( [ $id2 ] === $by( [ 'kind' => 'podcast_script' ] ), 'filter by kind' );
    insp_check( [ $id1 ] === $by( [ 'status' => 'error' ] ), 'filter by status' );
    insp_check( [ $id3, $id2 ] === $by( [ 'date_from' => '2026-11-06' ] ), 'filter by date_from' );
    insp_check( [ $id1 ] === $by( [ 'date_to' => '2026-11-05' ] ), 'filter by date_to' );
    insp_check( [ $id2 ] === $by( [ 'date_from' => '2026-11-06', 'date_to' => '2026-11-06' ] ), 'filter by date range' );
    insp_check( [] === $by( [ 'kind' => 'briefing_text', 'status' => 'success' ] ), 'combined filters can match nothing' );
    insp_check( [ $id3, $id2, $id1 ] === $by( [ 'kind' => 'bogus', 'status' => 'x', 'date_from' => "2026-11-01' OR 1=1" ] ), 'invalid filter values are dropped, not used in SQL' );
    $page = PressHub_AI_Generation_Inspector::build_records_page( [ 'kind' => 'podcast_script' ] );
    insp_check( false !== strpos( $page, 'record_id=' . $id2 ) && false === strpos( $page, 'record_id=' . $id1 ), 'page lists only filtered rows with open links' );
    insp_check( false !== strpos( $page, 'selected="selected">Podcast script' ), 'page keeps the kind filter selected' );
    $detail = PressHub_AI_Generation_Inspector::build_records_page( [ 'record_id' => $id1 ] );
    insp_check( false !== strpos( $detail, 'presshub-inspector-select' ) && false !== strpos( $detail, 'data-record-id="' . $id1 . '"' ), 'record_id opens the same inspector' );
    $none = PressHub_AI_Generation_Inspector::build_records_page( [ 'kind' => 'memory_rollup' ] );
    insp_check( false !== strpos( $none, 'No generation records match these filters' ), 'empty list message' );
    insp_set_user( [ 'edit_posts' ], true );
    $res = insp_call( [ 'PressHub_AI_Generation_Inspector', 'render_records_page' ] );
    insp_check( is_string( $res['exception'] ) && 0 === strpos( $res['exception'], 'wp_die' ), 'records page requires capability' );
    $GLOBALS['SUBMENU_PAGES'] = [];
    insp_set_user( [ 'edit_others_posts' ], true );
    ( new PressHub_AI_Briefing_Admin() )->add_admin_menu();
    $slugs = array_column( $GLOBALS['SUBMENU_PAGES']['presshub-ai-briefing-hub'] ?? [], 'capability', 'menu_slug' );
    insp_check( 'edit_others_posts' === ( $slugs['presshub-ai-generation-records'] ?? '' ), 'Briefing Admin registers Generation records submenu with edit_others_posts' );
    echo '  [6] Admin list and filters: OK' . PHP_EOL;

    if ( $GLOBALS['INSP_FAILURES'] > 0 ) {
        echo 'GenerationInspectorTest: ' . $GLOBALS['INSP_FAILURES'] . ' FAILURE(S)' . PHP_EOL;
        exit( 1 );
    }
    echo 'GenerationInspectorTest: ALL TESTS PASSED! OK' . PHP_EOL;
}

test_generation_inspector();
