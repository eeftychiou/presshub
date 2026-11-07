<?php
/**
 * Test suite for PressHub_AI_Generation_Recorder (Issue #131).
 *
 * Uses an explicit check() helper instead of assert() so failures are reported
 * (and the process exits non-zero) even when zend.assertions is disabled.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-token-logger.php';
require_once __DIR__ . '/../includes/class-generation-recorder.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';

$GLOBALS['GEN_REC_FAILURES'] = 0;

function gen_rec_check( $condition, string $message ): void {
    if ( ! $condition ) {
        $GLOBALS['GEN_REC_FAILURES']++;
        echo '  FAIL: ' . $message . PHP_EOL;
    }
}

function test_generation_recorder() {
    global $wpdb;

    echo 'Running GenerationRecorderTest...' . PHP_EOL;

    $table = 'wp_presshub_ai_generation_records';

    // Reset state
    $wpdb->tables           = [];
    $wpdb->queries          = [];
    $wpdb->auto_increments  = [];
    $GLOBALS['POST_META_STORE'] = [];
    PressHub_AI_Generation_Recorder::clear_memory_context();

    // Redirect the structured log to a temp file so we can assert failure logging.
    $log_file = sys_get_temp_dir() . '/presshub-genrec-test-' . uniqid() . '.log';
    PressHub_AI_Logger::set_log_file_path( $log_file );

    // 1. Table creation
    PressHub_AI_Generation_Recorder::create_table();
    gen_rec_check( isset( $wpdb->tables[ $table ] ), 'Table ' . $table . ' created' );
    gen_rec_check( PressHub_AI_Generation_Recorder::get_table_name() === $table, 'get_table_name uses prefix' );
    gen_rec_check( get_option( PressHub_AI_Generation_Recorder::DB_VERSION_OPTION ) === PressHub_AI_Generation_Recorder::DB_VERSION, 'DB version option set' );
    echo '  [1] Table creation: OK' . PHP_EOL;

    // 2. Round-trip with verbatim prompts
    $system_prompt = "Είσαι δημοσιογράφος.\nΠάντα \"ακριβής\" & 'σύντομος' — <script>alert(1)</script> http://x.gr/a/b\r\n  trailing spaces   ";
    $user_prompt   = "  Άρθρα:\n\t1) «Τίτλος» \\ backslash / slash\n<script>evil()</script>\n";
    $response_text = "## Σύνοψη\n\n- Πρώτο \"θέμα\"\n- <b>Δεύτερο</b> θέμα με 'εισαγωγικά'\n\n";

    $id = PressHub_AI_Generation_Recorder::record( [
        'kind'          => 'briefing_text',
        'trace_id'      => 'trace-abc',
        'briefing_date' => '2026-11-07',
        'edition'       => 'morning',
        'config'        => [ 'provider_id' => 'gemini-main', 'model' => 'gemini-3.1-flash', 'url' => 'https://a.b/c' ],
        'inputs'        => [ 'sent' => [ [ 'title' => 'Ελληνικός τίτλος', 'url' => 'https://example.gr/x' ] ], 'pool' => 12 ],
        'system_prompt' => $system_prompt,
        'user_prompt'   => $user_prompt,
        'response_text' => $response_text,
        'usage'         => [ 'prompt_tokens' => 10, 'completion_tokens' => 5 ],
        'duration_ms'   => 1234,
    ] );
    gen_rec_check( is_int( $id ) && $id > 0, 'record() returns positive int ID' );
    $rec = PressHub_AI_Generation_Recorder::get( (int) $id );
    gen_rec_check( is_array( $rec ), 'get() returns array' );
    gen_rec_check( $rec['system_prompt'] === $system_prompt, 'system_prompt byte-identical' );
    gen_rec_check( $rec['user_prompt'] === $user_prompt, 'user_prompt byte-identical' );
    gen_rec_check( $rec['response_text'] === $response_text, 'response_text byte-identical' );
    gen_rec_check( 'briefing_text' === $rec['kind'], 'kind stored' );
    gen_rec_check( 'trace-abc' === $rec['trace_id'], 'trace_id stored' );
    gen_rec_check( '2026-11-07' === $rec['briefing_date'] && 'morning' === $rec['edition'], 'date + edition stored' );
    gen_rec_check( 'success' === $rec['status'], 'status defaults to success' );
    gen_rec_check( 1234 === $rec['duration_ms'], 'duration_ms stored' );
    gen_rec_check( 0 === $rec['post_id'], 'post_id 0 when unattached' );
    gen_rec_check( null === $wpdb->tables[ $table ][0]['post_id'], 'post_id stored as NULL when 0' );
    echo '  [2] Verbatim round-trip: OK' . PHP_EOL;

    // 3. JSON fields decode to arrays and are stored unescaped
    gen_rec_check( 'gemini-3.1-flash' === ( $rec['config']['model'] ?? '' ), 'config decoded' );
    gen_rec_check( 12 === ( $rec['inputs']['pool'] ?? 0 ), 'inputs decoded' );
    gen_rec_check( 'Ελληνικός τίτλος' === ( $rec['inputs']['sent'][0]['title'] ?? '' ), 'Greek in inputs decoded' );
    gen_rec_check( 5 === ( $rec['usage']['completion_tokens'] ?? 0 ), 'usage decoded' );
    gen_rec_check( null === $rec['tts'], 'tts is null when absent' );
    $raw_row = $wpdb->tables[ $table ][0];
    gen_rec_check( false !== strpos( $raw_row['inputs_json'], 'Ελληνικός τίτλος' ), 'unicode not escaped in JSON' );
    gen_rec_check( false !== strpos( $raw_row['config_json'], 'https://a.b/c' ), 'slashes not escaped in JSON' );
    echo '  [3] JSON fields: OK' . PHP_EOL;

    // 4. Memory defaults and one-shot pending context
    gen_rec_check( [ 'enabled' => false ] === $rec['memory'], 'memory defaults to enabled=false' );
    PressHub_AI_Generation_Recorder::set_memory_context( [ 'enabled' => true, 'entries' => [ 7, 9 ] ] );
    $id_mem1 = PressHub_AI_Generation_Recorder::record( [ 'kind' => 'briefing_text' ] );
    $id_mem2 = PressHub_AI_Generation_Recorder::record( [ 'kind' => 'briefing_text' ] );
    $m1 = PressHub_AI_Generation_Recorder::get( (int) $id_mem1 );
    $m2 = PressHub_AI_Generation_Recorder::get( (int) $id_mem2 );
    gen_rec_check( true === ( $m1['memory']['enabled'] ?? null ) && [ 7, 9 ] === ( $m1['memory']['entries'] ?? [] ), 'pending memory context used' );
    gen_rec_check( [ 'enabled' => false ] === $m2['memory'], 'pending memory context consumed once' );
    PressHub_AI_Generation_Recorder::set_memory_context( [ 'enabled' => true ] );
    PressHub_AI_Generation_Recorder::clear_memory_context();
    $id_mem3 = PressHub_AI_Generation_Recorder::record( [ 'kind' => 'memory_digest' ] );
    $m3 = PressHub_AI_Generation_Recorder::get( (int) $id_mem3 );
    gen_rec_check( [ 'enabled' => false ] === $m3['memory'], 'clear_memory_context discards pending context' );
    $id_mem4 = PressHub_AI_Generation_Recorder::record( [ 'kind' => 'memory_rollup', 'memory' => [ 'enabled' => true, 'x' => 1 ] ] );
    $m4 = PressHub_AI_Generation_Recorder::get( (int) $id_mem4 );
    gen_rec_check( 1 === ( $m4['memory']['x'] ?? 0 ), 'explicit memory array used' );
    echo '  [4] Memory context: OK' . PHP_EOL;

    // 5. Unknown kind => null + ERROR log with trace_id and kind
    $rows_before = count( $wpdb->tables[ $table ] );
    $bad = PressHub_AI_Generation_Recorder::record( [ 'kind' => 'bogus', 'trace_id' => 'trace-bad' ] );
    gen_rec_check( null === $bad, 'unknown kind returns null' );
    gen_rec_check( null === PressHub_AI_Generation_Recorder::record( [] ), 'missing kind returns null' );
    gen_rec_check( count( $wpdb->tables[ $table ] ) === $rows_before, 'no row inserted for unknown kind' );
    $log = file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';
    gen_rec_check( false !== strpos( $log, 'ERROR' ) && false !== strpos( $log, 'trace-bad' ) && false !== strpos( $log, 'bogus' ), 'ERROR logged with trace_id and kind' );
    echo '  [5] Unknown kind: OK' . PHP_EOL;

    // 5b. Error status and error message
    $id_err = PressHub_AI_Generation_Recorder::record( [
        'kind'          => 'podcast_script',
        'status'        => 'error',
        'error_message' => 'Provider timeout',
        'system_prompt' => 'sys',
        'user_prompt'   => 'usr',
        'response_text' => '',
    ] );
    $err = PressHub_AI_Generation_Recorder::get( (int) $id_err );
    gen_rec_check( 'error' === $err['status'] && 'Provider timeout' === $err['error_message'], 'error record stores status + message' );
    gen_rec_check( 'usr' === $err['user_prompt'], 'error record keeps prompts' );

    // 6. attach_to_post: appends, no duplicates, order preserved, sets post_id
    $a = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'briefing_text', 'user_prompt' => 'A' ] );
    $b = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'podcast_script', 'user_prompt' => 'B' ] );
    $c = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'briefing_text', 'user_prompt' => 'C' ] );
    PressHub_AI_Generation_Recorder::attach_to_post( 55, $b );
    PressHub_AI_Generation_Recorder::attach_to_post( 55, $a );
    PressHub_AI_Generation_Recorder::attach_to_post( 55, $b );
    PressHub_AI_Generation_Recorder::attach_to_post( 55, $c );
    PressHub_AI_Generation_Recorder::attach_to_post( 55, 0 );
    PressHub_AI_Generation_Recorder::attach_to_post( 0, $a );
    $ids = get_post_meta( 55, PressHub_AI_Generation_Recorder::POST_META_KEY, true );
    gen_rec_check( [ $b, $a, $c ] === $ids, 'attach_to_post appends without duplicates, order preserved' );
    gen_rec_check( 55 === PressHub_AI_Generation_Recorder::get( $b )['post_id'], 'attach_to_post sets post_id' );
    // Already-attached record keeps its original post_id when attached to another post
    PressHub_AI_Generation_Recorder::attach_to_post( 77, $b );
    gen_rec_check( 55 === PressHub_AI_Generation_Recorder::get( $b )['post_id'], 'attach_to_post does not overwrite existing post_id' );
    echo '  [6] attach_to_post: OK' . PHP_EOL;

    // 7. get_for_post order
    $for_post = PressHub_AI_Generation_Recorder::get_for_post( 55 );
    gen_rec_check( 3 === count( $for_post ), 'get_for_post returns 3 records' );
    gen_rec_check( [ 'B', 'A', 'C' ] === array_map( function ( $r ) { return $r['user_prompt']; }, $for_post ), 'get_for_post follows meta order' );
    gen_rec_check( [] === PressHub_AI_Generation_Recorder::get_for_post( 999 ), 'get_for_post empty for unknown post' );
    gen_rec_check( null === PressHub_AI_Generation_Recorder::get( 987654 ), 'get() null for missing id' );
    echo '  [7] get_for_post: OK' . PHP_EOL;

    // 8. update_record only changes allowed fields
    $tts = [ 'engine' => 'gemini', 'voices' => [ 'S1' => 'Puck', 'S2' => 'Kore' ], 'turns' => 14, 'note' => 'Καλημέρα' ];
    $ok  = PressHub_AI_Generation_Recorder::update_record( $a, [
        'tts'           => $tts,
        'status'        => 'error',
        'error_message' => 'tts failed',
        'post_id'       => 88,
        'user_prompt'   => 'HACKED',
        'kind'          => 'memory_rollup',
        'response_text' => 'HACKED',
    ] );
    $upd = PressHub_AI_Generation_Recorder::get( $a );
    gen_rec_check( true === $ok, 'update_record returns true' );
    gen_rec_check( $tts === $upd['tts'], 'tts round-trips' );
    gen_rec_check( 'error' === $upd['status'] && 'tts failed' === $upd['error_message'] && 88 === $upd['post_id'], 'allowed fields updated' );
    gen_rec_check( 'A' === $upd['user_prompt'] && 'briefing_text' === $upd['kind'] && '' === (string) $upd['response_text'], 'disallowed fields untouched' );
    gen_rec_check( false === PressHub_AI_Generation_Recorder::update_record( $a, [ 'user_prompt' => 'x' ] ), 'update with only disallowed fields returns false' );
    gen_rec_check( false === PressHub_AI_Generation_Recorder::update_record( 0, [ 'status' => 'error' ] ), 'update with invalid id returns false' );
    echo '  [8] update_record: OK' . PHP_EOL;

    // 9. prune
    $wpdb->tables[ $table ][] = [
        'id'            => 5000,
        'created_at'    => '2025-01-01 00:00:00',
        'trace_id'      => 'old',
        'kind'          => 'briefing_text',
        'briefing_date' => '2025-01-01',
        'edition'       => 'morning',
        'post_id'       => null,
        'status'        => 'success',
        'error_message' => null,
        'config_json'   => '[]',
        'inputs_json'   => '[]',
        'memory_json'   => '{"enabled":false}',
        'tts_json'      => null,
        'system_prompt' => 'old',
        'user_prompt'   => 'old',
        'response_text' => 'old',
        'usage_json'    => '[]',
        'duration_ms'   => 1,
    ];
    $before = count( $wpdb->tables[ $table ] );
    $pruned = PressHub_AI_Generation_Recorder::prune( 90 );
    gen_rec_check( 1 === $pruned, 'prune deleted exactly the old row (got ' . $pruned . ')' );
    gen_rec_check( count( $wpdb->tables[ $table ] ) === $before - 1, 'new rows kept' );
    gen_rec_check( null === PressHub_AI_Generation_Recorder::get( 5000 ), 'old row gone' );
    gen_rec_check( null !== PressHub_AI_Generation_Recorder::get( $a ), 'recent row still present' );
    echo '  [9] prune: OK' . PHP_EOL;

    // 10. Retention getter / sanitizer
    gen_rec_check( 90 === PressHub_AI_Settings_Storage::get_generation_record_retention_days(), 'retention default 90' );
    gen_rec_check( 7 === PressHub_AI_Settings_Storage::sanitize_generation_record_retention_days( 1 ), 'retention clamps low to 7' );
    gen_rec_check( 7 === PressHub_AI_Settings_Storage::sanitize_generation_record_retention_days( '-5' ), 'retention clamps negative to 7' );
    gen_rec_check( 365 === PressHub_AI_Settings_Storage::sanitize_generation_record_retention_days( 9999 ), 'retention clamps high to 365' );
    gen_rec_check( 7 === PressHub_AI_Settings_Storage::sanitize_generation_record_retention_days( '7' ), 'retention accepts 7' );
    gen_rec_check( 365 === PressHub_AI_Settings_Storage::sanitize_generation_record_retention_days( '365' ), 'retention accepts 365' );
    gen_rec_check( 90 === PressHub_AI_Settings_Storage::sanitize_generation_record_retention_days( 'garbage' ), 'retention garbage => 90' );
    gen_rec_check( 90 === PressHub_AI_Settings_Storage::sanitize_generation_record_retention_days( '' ), 'retention empty => 90' );
    gen_rec_check( 90 === PressHub_AI_Settings_Storage::sanitize_generation_record_retention_days( null ), 'retention null => 90' );
    update_option( 'presshub_ai_generation_record_retention_days', '30' );
    gen_rec_check( 30 === PressHub_AI_Settings_Storage::get_generation_record_retention_days(), 'getter reads stored option' );
    update_option( 'presshub_ai_generation_record_retention_days', '2' );
    gen_rec_check( 7 === PressHub_AI_Settings_Storage::get_generation_record_retention_days(), 'getter clamps stored option' );
    echo '  [10] Retention settings: OK' . PHP_EOL;

    // 11. Token logger last-request capture
    PressHub_AI_Token_Logger::reset_last_llm_request();
    gen_rec_check( null === PressHub_AI_Token_Logger::get_last_llm_request(), 'last request null after reset' );
    PressHub_AI_Token_Logger::log_llm_request( 'briefing_curation', 'gemini', 'gemini-2.5-flash', 1500, 800, 3200, 'success', null, [], 1 );
    $last = PressHub_AI_Token_Logger::get_last_llm_request();
    gen_rec_check( is_array( $last ), 'last request captured' );
    gen_rec_check( 'briefing_curation' === $last['action'] && 'gemini' === $last['provider'] && 'gemini-2.5-flash' === $last['model'], 'last request identity' );
    gen_rec_check( 1500 === $last['prompt_tokens'] && 800 === $last['completion_tokens'] && 2300 === $last['total_tokens'], 'last request tokens' );
    gen_rec_check( 3200 === $last['duration_ms'] && 'success' === $last['status'] && null === $last['error'], 'last request duration/status' );
    PressHub_AI_Token_Logger::log_llm_request( 'x', 'openai', 'gpt', 1, 1, 5, 'error', 'boom', [], 1 );
    $last = PressHub_AI_Token_Logger::get_last_llm_request();
    gen_rec_check( 'error' === $last['status'] && 'boom' === $last['error'], 'last request overwritten with error' );
    PressHub_AI_Token_Logger::reset_last_llm_request();
    gen_rec_check( null === PressHub_AI_Token_Logger::get_last_llm_request(), 'reset clears capture' );
    echo '  [11] Token logger capture: OK' . PHP_EOL;

    // 12. build_client_config
    $client = new class {
        public function get_module() { return 'briefing_text'; }
        public function get_action() { return 'briefing_curation'; }
        public function get_provider() { return 'gemini'; }
        public function get_provider_id() { return 'gemini-main'; }
        public function get_model() { return 'gemini-3.1-flash'; }
        public function get_request_meta() { return 'provider=gemini model=gemini-3.1-flash [source: module_override:presshub_ai_briefing_text_model] max_tokens=100'; }
        public function get_temperature() { return 0.4; }
        public function get_max_tokens() { return 8192; }
        public function get_timeout() { return 120; }
    };
    $cfg = PressHub_AI_Generation_Recorder::build_client_config( $client, [ 'edition' => 'noon', 'preset_id' => 'p1' ] );
    gen_rec_check( 'gemini' === $cfg['provider_type'] && 'gemini-main' === $cfg['provider_id'] && 'gemini-3.1-flash' === $cfg['model'], 'client config identity' );
    gen_rec_check( 8192 === $cfg['max_tokens'] && 120 === $cfg['timeout'] && 0.4 === $cfg['temperature'], 'client config tuning' );
    gen_rec_check( 'module_override:presshub_ai_briefing_text_model' === ( $cfg['model_source'] ?? '' ), 'model_source parsed from request meta' );
    gen_rec_check( 'noon' === $cfg['edition'] && 'p1' === $cfg['preset_id'], 'extra merged' );
    gen_rec_check( [ 'a' => 1 ] === PressHub_AI_Generation_Recorder::build_client_config( null, [ 'a' => 1 ] ), 'null client yields only extras' );
    echo '  [12] build_client_config: OK' . PHP_EOL;

    @unlink( $log_file );

    if ( $GLOBALS['GEN_REC_FAILURES'] > 0 ) {
        echo 'GenerationRecorderTest: ' . $GLOBALS['GEN_REC_FAILURES'] . ' FAILURE(S)' . PHP_EOL;
        exit( 1 );
    }
    echo 'GenerationRecorderTest: ALL TESTS PASSED! OK' . PHP_EOL;
}

test_generation_recorder();
