<?php
/**
 * PromptDebugLogTest — presshub_ai_log_prompts() must write the exact
 * composed SYSTEM and USER prompts to the debug log when the
 * presshub_ai_debug_prompts filter returns true (and stay silent by
 * default), mirrored through the presshub_ai_prompt_log action so
 * harness tests can assert the payloads.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

// --- Extra stubs needed to load the main plugin file in the harness ---
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'http://example.test/wp-content/plugins/presshub-ai-editor/';
    }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) {
        $GLOBALS['DEACTIVATION_HOOKS'][] = $callback;
    }
}

// The embedded PUC library cannot load outside a full WP install.
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
function pdl_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

// --- Case 1: default (filter off, option off) → nothing is logged --------
$GLOBALS['DO_ACTION_LOG'] = [];
presshub_ai_log_prompts( 'draft', 'SYS', 'USER' );
pdl_check( 'default: prompt_log action fired with debug off', empty( $GLOBALS['DO_ACTION_LOG'] ) );

// --- Case 2: filter on → action fires with exact payload ------------------
add_filter( 'presshub_ai_debug_prompts', function () {
    return true;
} );

$GLOBALS['DO_ACTION_LOG'] = [];
presshub_ai_log_prompts( 'draft', 'You are a professional AI journalist.', 'Write a draft.' );
pdl_check( 'debug on: prompt_log action did not fire', count( $GLOBALS['DO_ACTION_LOG'] ) === 1 );

$entry = $GLOBALS['DO_ACTION_LOG'][0] ?? [];
pdl_check(
    'debug on: wrong hook',
    isset( $entry['hook'] ) && 'presshub_ai_prompt_log' === $entry['hook']
);
pdl_check(
    'debug on: wrong endpoint',
    isset( $entry['args'][0] ) && 'draft' === $entry['args'][0]
);
pdl_check(
    'debug on: system prompt not passed through',
    isset( $entry['args'][1] ) && 'You are a professional AI journalist.' === $entry['args'][1]
);
pdl_check(
    'debug on: user prompt not passed through',
    isset( $entry['args'][2] ) && 'Write a draft.' === $entry['args'][2]
);

// --- Case 3: generate_draft() wires the logger ----------------------------
// Call through the real draft path: composed system prompt (base + preset)
// and the template user prompt must reach the log action.
$GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
$GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
$GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
    [ 'slug' => 'fact-check', 'name' => 'Fact-check everything', 'instruction_text' => 'After drafting, verify every factual claim against the provided sources: add a parenthetical citing the source paragraph, and flag any claim the sources do not support as [UNSOURCED].', 'enabled' => true ],
];
$GLOBALS['CURRENT_USER_ID'] = 5;

$GLOBALS['DO_ACTION_LOG'] = [];
$api = new PressHub_AI_API_Client();
// The metabox dropdown sends the selected preset slug (the user's real
// flow: 'Fact-check everything' was selected in the dropdown).
$api->generate_draft( 'https://example.com/article', 'Write a rebuttal in Greek', [], 'fact-check' );

$logged = array_values( array_filter(
    $GLOBALS['DO_ACTION_LOG'],
    fn ( $e ) => isset( $e['hook'] ) && 'presshub_ai_prompt_log' === $e['hook']
) );
pdl_check( 'generate_draft: no prompt_log entry', count( $logged ) === 1 );
$draft_entry = $logged[0]['args'] ?? [];
pdl_check( 'generate_draft: endpoint', isset( $draft_entry[0] ) && 'draft' === $draft_entry[0] );
pdl_check(
    'generate_draft: system prompt missing base',
    isset( $draft_entry[1] ) && false !== strpos( $draft_entry[1], 'You are a professional AI journalist.' )
);
pdl_check(
    'generate_draft: system prompt missing preset text',
    isset( $draft_entry[1] ) && false !== strpos( $draft_entry[1], 'After drafting, verify every factual claim' )
);
pdl_check(
    'generate_draft: user prompt missing sources',
    isset( $draft_entry[2] ) && false !== strpos( $draft_entry[2], 'https://example.com/article' )
);
pdl_check(
    'generate_draft: user prompt missing instructions',
    isset( $draft_entry[2] ) && false !== strpos( $draft_entry[2], 'Write a rebuttal in Greek' )
);

if ( $failures > 0 ) {
    fwrite( STDERR, "PromptDebugLogTest: {$failures} failure(s)\n" );
    exit( 1 );
}

// --- Case 4: settings option → JSONL file written to uploads/presshub-ai (1.2.5 + 2.3.2) ------------
$log_dir = sys_get_temp_dir() . '/presshub-pdl-' . getmypid();
@mkdir( $log_dir . '/presshub-ai', 0777, true );
$GLOBALS['UPLOAD_DIR'] = $log_dir;
$log_file = trailingslashit( $log_dir ) . 'presshub-ai/presshub-ai-debug.log';
@unlink( $log_file );

$GLOBALS['OPTIONS_STORE']['presshub_ai_debug_prompts'] = '1';
presshub_ai_log_prompts( 'chat', 'SYS-OPT', 'USER-OPT' );
pdl_check( 'option: log file created', file_exists( $log_file ) );
if ( file_exists( $log_file ) ) {
    $lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    pdl_check( 'option: exactly 1 line written', count( $lines ) === 1 );
    $decoded = json_decode( $lines[0] ?? '', true );
    pdl_check( 'option: line is valid JSON', is_array( $decoded ) );
    pdl_check( 'option: trace_id present in line', ! empty( $decoded['trace_id'] ) );
    pdl_check( 'option: timestamp present in line', ! empty( $decoded['timestamp'] ) );
    pdl_check( 'option: system prompt in json', ( $decoded['system_prompt'] ?? '' ) === 'SYS-OPT' );
    pdl_check( 'option: user prompt in json', ( $decoded['user_prompt'] ?? '' ) === 'USER-OPT' );
    pdl_check( 'option: endpoint tag in json', ( $decoded['endpoint'] ?? '' ) === 'chat' );
    pdl_check( 'option: no response when null', null === ( $decoded['response'] ?? null ) );
    pdl_check( 'option: response_chars is 0 when null', 0 === ( $decoded['response_chars'] ?? -1 ) );
}

// Response logging: passing the 4th arg appends a RESPONSE with chars count.
presshub_ai_log_prompts( 'draft', 'SYS-R', 'USER-R', 'FULL RESPONSE TEXT' );
if ( file_exists( $log_file ) ) {
    $lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    pdl_check( 'response: 2 lines written', count( $lines ) === 2 );
    $decoded2 = json_decode( $lines[1] ?? '', true );
    pdl_check( 'response: line 2 valid JSON', is_array( $decoded2 ) );
    pdl_check( 'response: trace_id present', ! empty( $decoded2['trace_id'] ) );
    pdl_check( 'response: response body in JSON', ( $decoded2['response'] ?? '' ) === 'FULL RESPONSE TEXT' );
    pdl_check( 'response: char count is 18', 18 === ( $decoded2['response_chars'] ?? 0 ) );
}

// Meta/config line: a 5th arg appears as config field in JSON.
presshub_ai_log_prompts( 'draft', 'SYS-M', 'USER-M', 'RESP-M', 'provider=openai model=gpt-4o max_tokens=3000' );
if ( file_exists( $log_file ) ) {
    $lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    pdl_check( 'meta: 3 lines written', count( $lines ) === 3 );
    $decoded3 = json_decode( $lines[2] ?? '', true );
    pdl_check( 'meta: line 3 valid JSON', is_array( $decoded3 ) );
    pdl_check( 'meta: config field present', ( $decoded3['config'] ?? '' ) === 'provider=openai model=gpt-4o max_tokens=3000' );
    pdl_check( 'meta: endpoint is draft', ( $decoded3['endpoint'] ?? '' ) === 'draft' );
}

// Errors are logged too.
presshub_ai_log_prompts( 'draft', 'SYS-E', 'USER-E', 'ERROR: boom' );
if ( file_exists( $log_file ) ) {
    $lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    $decoded4 = json_decode( $lines[3] ?? '', true );
    pdl_check( 'response: error string logged in json response', ( $decoded4['response'] ?? '' ) === 'ERROR: boom' );
}

// Option off → no new entry appended (filter from Case 2 removed first).
unset( $GLOBALS['FILTERS']['presshub_ai_debug_prompts'] );
$GLOBALS['OPTIONS_STORE']['presshub_ai_debug_prompts'] = '0';
presshub_ai_log_prompts( 'draft', 'SYS-OFF', 'USER-OFF' );
if ( file_exists( $log_file ) ) {
    $content = file_get_contents( $log_file );
    pdl_check( 'option off: no entry appended', false === strpos( $content, 'SYS-OFF' ) );
}

@unlink( $log_file );
@rmdir( $log_dir . '/presshub-ai' );
@rmdir( $log_dir );
unset( $GLOBALS['UPLOAD_DIR'], $GLOBALS['OPTIONS_STORE']['presshub_ai_debug_prompts'] );

if ( $failures > 0 ) {
    fwrite( STDERR, "PromptDebugLogTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "PromptDebugLogTest: OK (26 checks)\n";
