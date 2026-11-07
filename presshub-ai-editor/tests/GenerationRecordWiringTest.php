<?php
/**
 * GenerationRecordWiringTest - Issue #131 call-site wiring.
 *
 * Covers:
 *   1. generate_briefing(): one briefing_text record whose prompts/response equal
 *      exactly what the stub client received/returned; attached to the created post.
 *   2. Per-article breakdown (cap_articles + per-article char cap + selection
 *      filter) and byte-identical rendering of format_articles_context().
 *   3. A WP_Error from the provider still yields an error record with prompts.
 *   4. build_client_config() reports the client instance's own configuration
 *      (regression for the old current_request_meta() first-enabled-provider bug).
 *   5. Podcast script generation creates a podcast_script record and the sidecar
 *      meta carries generation_record_id.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) { $GLOBALS['DEACTIVATION_HOOKS'][] = $callback; }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';
require_once dirname( __DIR__ ) . '/includes/class-generation-recorder.php';

$failures = 0;
$checks   = 0;
function grw_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$test_upload_dir = sys_get_temp_dir() . '/presshub-genrec-test-' . uniqid();
$GLOBALS['UPLOAD_DIR'] = $test_upload_dir;
$GLOBALS['OPTIONS_STORE'] = [];
$GLOBALS['CURRENT_USER_ID'] = 7;

PressHub_AI_Generation_Recorder::create_table();

/** Stub client capturing exactly what the call site hands to call_provider(). */
class GRW_Stub_Client extends PressHub_AI_API_Client {
    public $received = [];
    public $reply    = "# Τίτλος Δοκιμής\n\nΚείμενο απάντησης.";
    public function __construct() {}
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->received = [ 'system' => $sys_prompt, 'user' => $user_prompt ];
        return $this->reply;
    }
    public function get_provider(): string { return 'gemini'; }
    public function get_provider_id(): string { return 'gemini-override'; }
    public function get_model(): string { return 'override-model-x'; }
    public function get_provider_source(): string { return 'module_override:briefing_text'; }
    public function get_model_source(): string { return 'module_override:briefing_text'; }
    public function get_module(): ?string { return 'briefing_text'; }
    public function get_action(): string { return 'briefing_curation'; }
    public function get_temperature(): float { return 0.42; }
    public function get_max_tokens(): int { return 1234; }
    public function get_timeout(): int { return 77; }
}

$harvester = new PressHub_AI_News_Harvester();
$curator   = new PressHub_AI_News_Curator();

$articles = [
    [ 'id' => 'a1', 'title' => 'Πρώτο', 'source' => 'S1', 'url' => 'https://s1.example/1', 'content' => str_repeat( 'α', 250 ) ],
    [ 'title' => 'Δεύτερο', 'source' => 'S2', 'url' => 'https://s2.example/2', 'content' => 'Σύντομο κείμενο' ],
    [ 'title' => 'Τρίτο', 'source' => 'S3', 'content' => str_repeat( 'β', 120 ) ],
    [ 'id' => 'a4', 'title' => 'Τέταρτο', 'source' => 'S4', 'url' => 'https://s4.example/4', 'content' => 'Τέταρτο περιεχόμενο' ],
];

// -------------------------------------------------------------------------
// 1. Briefing: record equals exactly what the stub saw; attached to post
// -------------------------------------------------------------------------
$date = '2026-09-01';
$harvester->save_snapshot( $date, [
    'date' => $date, 'harvested_at' => gmdate( 'c' ), 'sources' => [], 'blocked_sources' => [],
    'articles' => $articles,
] );
$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles']          = 50;
$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 3000;

$stub = new GRW_Stub_Client();
$res  = $curator->generate_briefing( $date, $stub, '__none__', [], 'morning' );
grw_check( 'briefing: success payload', is_array( $res ) && ! empty( $res['post_id'] ) );
$rid = (int) ( $res['generation_record_id'] ?? 0 );
grw_check( 'briefing: generation_record_id returned', $rid > 0 );
$rec = PressHub_AI_Generation_Recorder::get( $rid );
grw_check( 'briefing: record exists', is_array( $rec ) );
grw_check( 'briefing: kind is briefing_text', ( $rec['kind'] ?? '' ) === 'briefing_text' );
grw_check( 'briefing: status success', ( $rec['status'] ?? '' ) === 'success' );
grw_check( 'briefing: system_prompt identical to what stub received', ( $rec['system_prompt'] ?? null ) === $stub->received['system'] );
grw_check( 'briefing: user_prompt identical to what stub received', ( $rec['user_prompt'] ?? null ) === $stub->received['user'] );
grw_check( 'briefing: response_text identical to stub reply', ( $rec['response_text'] ?? null ) === $stub->reply );
grw_check( 'briefing: briefing_date/edition stored', ( $rec['briefing_date'] ?? '' ) === $date && ( $rec['edition'] ?? '' ) === 'morning' );
grw_check( 'briefing: record attached to created post', in_array( $rid, (array) get_post_meta( $res['post_id'], PressHub_AI_Generation_Recorder::POST_META_KEY, true ), true ) );
grw_check( 'briefing: record post_id column set', (int) ( $rec['post_id'] ?? 0 ) === (int) $res['post_id'] );
grw_check( 'briefing: inputs has articles sent=4', count( $rec['inputs']['articles']['sent'] ?? [] ) === 4 );
grw_check( 'briefing: inputs has totals + token_estimate', isset( $rec['inputs']['totals']['cap_articles'] ) && isset( $rec['inputs']['token_estimate'] ) );
grw_check( 'briefing: config articles_placement is user', ( $rec['config']['articles_placement'] ?? '' ) === 'user' );
grw_check( 'briefing: config preset_id/edition extras', ( $rec['config']['preset_id'] ?? '' ) === '__none__' && ( $rec['config']['edition'] ?? '' ) === 'morning' );

// -------------------------------------------------------------------------
// 4. Config reports the instance's own provider/model (override regression)
// -------------------------------------------------------------------------
$cfg = $rec['config'] ?? [];
grw_check( 'config: provider_id equals client getter', ( $cfg['provider_id'] ?? '' ) === $stub->get_provider_id() );
grw_check( 'config: model equals client getter', ( $cfg['model'] ?? '' ) === $stub->get_model() );
grw_check( 'config: provider_source equals client getter', ( $cfg['provider_source'] ?? '' ) === $stub->get_provider_source() );
grw_check( 'config: temperature/max_tokens/timeout equal client getters', (float) ( $cfg['temperature'] ?? -1 ) === 0.42 && (int) ( $cfg['max_tokens'] ?? 0 ) === 1234 && (int) ( $cfg['timeout'] ?? 0 ) === 77 );
$direct = PressHub_AI_Generation_Recorder::build_client_config( $stub, [ 'x' => 1 ] );
grw_check( 'config: build_client_config merges extras', 1 === ( $direct['x'] ?? 0 ) && ( $direct['model'] ?? '' ) === 'override-model-x' );

// New getters on the real client return the resolved private fields.
$real = ( new ReflectionClass( 'PressHub_AI_API_Client' ) )->newInstanceWithoutConstructor();
foreach ( [ 'provider' => 'openai', 'provider_id' => 'oa-1', 'model' => 'gpt-x', 'temperature' => '0.3', 'max_tokens' => '999', 'timeout' => '45' ] as $prop => $val ) {
    $rp = new ReflectionProperty( 'PressHub_AI_API_Client', $prop );
    $rp->setAccessible( true );
    $rp->setValue( $real, $val );
}
grw_check( 'client getters: get_temperature float', $real->get_temperature() === 0.3 );
grw_check( 'client getters: get_max_tokens int', $real->get_max_tokens() === 999 );
grw_check( 'client getters: get_timeout int', $real->get_timeout() === 45 );
grw_check( 'client getters: get_request_meta reflects the instance', false !== strpos( $real->get_request_meta(), 'provider_id=oa-1' ) && false !== strpos( $real->get_request_meta(), 'model=gpt-x' ) );
// call_provider's generic debug log must use the instance meta, not the first enabled provider.
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-api-client.php' );
grw_check( 'call_provider: generic log uses $this->get_request_meta()', 1 === preg_match( '/presshub_ai_log_prompts\(\s*\$endpoint,\s*\$sys_prompt,\s*\$user_prompt,\s*\$resp_str,\s*\$this->get_request_meta\(\)\s*\)/', $src ) );

// -------------------------------------------------------------------------
// 2. Breakdown + unchanged rendering
// -------------------------------------------------------------------------
/** Verbatim copy of the pre-#131 rendering logic (reference). */
function grw_old_render( array $articles, int $max_articles, int $max_chars ): string {
    $suffix = __( '…[περικομμένο]', 'presshub-ai-editor' );
    if ( count( $articles ) > $max_articles ) {
        $articles = array_slice( $articles, 0, $max_articles );
    }
    $blocks = [];
    foreach ( $articles as $index => $article ) {
        $num     = $index + 1;
        $title   = ! empty( $article['title'] ) ? trim( (string) $article['title'] ) : __( 'Χωρίς τίτλο', 'presshub-ai-editor' );
        $source  = ! empty( $article['source'] ) ? trim( (string) $article['source'] ) : __( 'Άγνωστη πηγή', 'presshub-ai-editor' );
        $url     = ! empty( $article['url'] ) ? trim( (string) $article['url'] ) : '';
        $content = ! empty( $article['content'] ) ? trim( (string) $article['content'] ) : '';
        $block   = "### {$num}. {$title}\n";
        $block  .= "**Πηγή:** {$source}";
        if ( ! empty( $url ) ) {
            $block .= " | **URL:** {$url}";
        }
        if ( ! empty( $content ) ) {
            if ( mb_strlen( $content ) > $max_chars ) {
                $content = mb_substr( $content, 0, $max_chars ) . $suffix;
            }
            $block .= "\n\n{$content}";
        }
        $blocks[] = $block;
    }
    return implode( "\n\n---\n\n", $blocks );
}

$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles']          = 2;
$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 100;
$rendered = $curator->format_articles_context( $articles );
grw_check( 'rendering: byte-identical to pre-#131 logic', $rendered === grw_old_render( $articles, 2, 100 ) );
$bd = $curator->last_articles_breakdown();
grw_check( 'breakdown: 2 sent', 2 === count( $bd['sent'] ) );
grw_check( 'breakdown: sent[0] id from article id', 'a1' === $bd['sent'][0]['id'] );
grw_check( 'breakdown: sent[0] truncated, chars_sent=100, chars_original=250', true === $bd['sent'][0]['truncated'] && 100 === $bd['sent'][0]['chars_sent'] && 250 === $bd['sent'][0]['chars_original'] );
grw_check( 'breakdown: sent[1] id is sha1(url), not truncated', sha1( 'https://s2.example/2' ) === $bd['sent'][1]['id'] && false === $bd['sent'][1]['truncated'] && mb_strlen( 'Σύντομο κείμενο' ) === $bd['sent'][1]['chars_sent'] );
grw_check( 'breakdown: sent carries title/source/url', 'Πρώτο' === $bd['sent'][0]['title'] && 'S1' === $bd['sent'][0]['source'] && 'https://s1.example/1' === $bd['sent'][0]['url'] );
grw_check( 'breakdown: 2 dropped by cap_articles', 2 === count( $bd['dropped'] ) && 'cap_articles' === $bd['dropped'][0]['reason'] && 'cap_articles' === $bd['dropped'][1]['reason'] );
grw_check( 'breakdown: dropped ids (idx fallback / id)', 'idx:2' === $bd['dropped'][0]['id'] && 'a4' === $bd['dropped'][1]['id'] );

// build_prompt: not_selected + placement + reset between runs.
$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles']          = 50;
$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 3000;
$curator->build_prompt( $articles, '__none__', $date, [ 'a1', 'a4' ] );
$bd2 = $curator->last_articles_breakdown();
$ns  = array_values( array_filter( $bd2['dropped'], static function ( $d ) { return 'not_selected' === $d['reason']; } ) );
grw_check( 'build_prompt: 2 not_selected dropped', 2 === count( $ns ) );
grw_check( 'build_prompt: sent contains the 2 selected', 2 === count( $bd2['sent'] ) && 'a1' === $bd2['sent'][0]['id'] && 'a4' === $bd2['sent'][1]['id'] );
grw_check( 'build_prompt: placement user for default prompt', 'user' === $curator->last_articles_placement() );
$curator->build_prompt( $articles, '__none__', $date, [] );
$bd3 = $curator->last_articles_breakdown();
grw_check( 'build_prompt: second run starts clean (no stale not_selected)', 0 === count( $bd3['dropped'] ) && 4 === count( $bd3['sent'] ) );
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_prompt'] = "Date {date}\n{articles_context}";
$curator->build_prompt( $articles, '__none__', $date, [] );
grw_check( 'build_prompt: placement system when {articles_context} in base prompt', 'system' === $curator->last_articles_placement() );
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_prompt'] );

// -------------------------------------------------------------------------
// 3. WP_Error still records an error record with prompts
// -------------------------------------------------------------------------
class GRW_Error_Client extends GRW_Stub_Client {
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->received = [ 'system' => $sys_prompt, 'user' => $user_prompt ];
        return new WP_Error( 'provider_boom', 'Provider exploded' );
    }
}
$err_client = new GRW_Error_Client();
$err_res    = $curator->generate_briefing( $date, $err_client, '__none__', [], 'morning' );
grw_check( 'error: generate_briefing returns the WP_Error', is_wp_error( $err_res ) && 'provider_boom' === $err_res->get_error_code() );
global $wpdb;
$err_row = null;
foreach ( $wpdb->tables[ PressHub_AI_Generation_Recorder::get_table_name() ] ?? [] as $row ) {
    if ( 'error' === ( $row['status'] ?? '' ) ) {
        $err_row = $row;
    }
}
grw_check( 'error: an error record was stored', is_array( $err_row ) );
if ( is_array( $err_row ) ) {
    $err_rec = PressHub_AI_Generation_Recorder::get( (int) $err_row['id'] );
    grw_check( 'error: error_message stored', false !== strpos( (string) $err_rec['error_message'], 'Provider exploded' ) );
    grw_check( 'error: prompts still stored verbatim', $err_rec['system_prompt'] === $err_client->received['system'] && $err_rec['user_prompt'] === $err_client->received['user'] );
    grw_check( 'error: response_text empty', '' === (string) $err_rec['response_text'] );
    grw_check( 'error: kind briefing_text', 'briefing_text' === $err_rec['kind'] );
}

// -------------------------------------------------------------------------
// 5. Podcast script generation
// -------------------------------------------------------------------------
class GRW_Podcast_Client extends GRW_Stub_Client {
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->received = [ 'system' => $sys_prompt, 'user' => $user_prompt ];
        return "[SPEAKER_1]: Καλημέρα σας!\n[SPEAKER_2]: Καλημέρα, ξεκινάμε με τις ειδήσεις.\n[SPEAKER_1]: Πρώτο θέμα η οικονομία.";
    }
}
$producer = new PressHub_AI_Podcast_Producer();
$pod      = new GRW_Podcast_Client();
$pres     = $producer->generate_dialogue_script( $date, $pod, '__none__', '3_min', 'harvested_articles', [ 'a1', 'a4' ], 'morning' );
grw_check( 'podcast: success payload', is_array( $pres ) && ! empty( $pres['raw_script'] ) );
$prid = (int) ( $pres['generation_record_id'] ?? 0 );
grw_check( 'podcast: generation_record_id returned', $prid > 0 );
$prec = PressHub_AI_Generation_Recorder::get( $prid );
grw_check( 'podcast: kind podcast_script', ( $prec['kind'] ?? '' ) === 'podcast_script' );
grw_check( 'podcast: prompts/response verbatim', ( $prec['system_prompt'] ?? null ) === $pod->received['system'] && ( $prec['user_prompt'] ?? null ) === $pod->received['user'] && ( $prec['response_text'] ?? null ) === $pres['raw_script'] );
grw_check( 'podcast: config extras', ( $prec['config']['context_mode'] ?? '' ) === 'harvested_articles' && ( $prec['config']['duration'] ?? '' ) === '3_min' && isset( $prec['config']['style'] ) && ( $prec['config']['preset_id'] ?? '' ) === '__none__' );
grw_check( 'podcast: inputs lists the 2 selected articles + 2 not_selected', 2 === count( $prec['inputs']['articles'] ?? [] ) && 2 === count( $prec['inputs']['not_selected'] ?? [] ) && 'harvested_articles' === ( $prec['inputs']['context_used'] ?? '' ) );
$smeta = $producer->get_script_meta( $date, 'morning' );
grw_check( 'podcast: sidecar meta carries generation_record_id', (int) ( $smeta['generation_record_id'] ?? 0 ) === $prid );
grw_check( 'podcast: sidecar keeps existing keys', 'harvested_articles' === $smeta['context_mode'] && '' !== $smeta['completed_at'] && 'morning' === $smeta['edition'] );
grw_check( 'podcast: legacy save_script call still works', true === $producer->save_script( '2026-09-02', "[SPEAKER_1]: x", 'harvested_articles' ) && 0 === $producer->get_script_meta( '2026-09-02' )['generation_record_id'] );

// Cleanup
if ( is_dir( $test_upload_dir ) ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $test_upload_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $files as $fileinfo ) {
        $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
        @$todo( $fileinfo->getRealPath() );
    }
    @rmdir( $test_upload_dir );
}

if ( $failures > 0 ) {
    fwrite( STDERR, "GenerationRecordWiringTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "GenerationRecordWiringTest: OK ({$checks} checks)\n";
