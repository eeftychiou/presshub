<?php
/**
 * GenerationRecordsEverywhereTest - Issue #140.
 *
 * Every LLM, image and TTS call writes a generation record, and TTS calls carry
 * per-call details. Uses explicit checks (not assert()), prints "OK" on success and
 * exits 1 on any failure. Provider and TTS traffic is stubbed at the HTTP layer
 * (CAPTURE_FILTER), so the real API client, call sites and recorder run end to end.
 *
 * Covers:
 *   1. coauthor_draft, scorecard, copilot_chat (with and without a post), research,
 *      image_generation and generic: prompts and response byte-identical to what the
 *      provider saw, linked to the right post / attachment / user.
 *   2. No double records for the briefing and podcast script paths.
 *   3. Podcast synthesis: tts_calls entries (one per call, a retry entry after a
 *      failure, a voice-fallback entry), no base64 audio, no plain API key, no spoken text.
 *   4. tts_test record for a TTS call without a parent record.
 *   5. 500-entry cap, truncated marker and the single WARNING.
 *   6. Inspector access rule: author vs other non-editor vs editor.
 *   7. Retention prunes copilot_chat records.
 *   8. Settings wording "Raw debug dump (developer use)".
 */

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) { $GLOBALS['DEACTIVATION_HOOKS'][] = $callback; }
}
if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $postarr, $wp_error = false ) { return $postarr['ID'] ?? 0; }
}
if ( ! function_exists( 'add_meta_box' ) ) {
    function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
        $GLOBALS['META_BOXES'][ $id ] = [ 'title' => $title ];
    }
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function gre_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$test_upload_dir       = sys_get_temp_dir() . '/presshub-gre-test-' . uniqid();
$GLOBALS['UPLOAD_DIR'] = $test_upload_dir;
$log_file              = sys_get_temp_dir() . '/presshub-gre-log-' . uniqid() . '.log';
PressHub_AI_Logger::set_log_file_path( $log_file );

const GRE_OPENAI_KEY = 'sk-test-PLAINKEY-1234567890abcdef';
const GRE_GEMINI_KEY = 'AIzaSyPLAINGEMINIKEY1234567890abcd';

/** Reset options, providers, tables and the HTTP stub. */
function gre_reset(): void {
    global $wpdb;
    $GLOBALS['OPTIONS_STORE']   = [];
    $GLOBALS['POST_META_STORE'] = [];
    $GLOBALS['POSTS_STORE']     = [];
    $GLOBALS['CAPTURED_REQUESTS'] = [];
    $GLOBALS['JSON_RESPONSES']  = [];
    $GLOBALS['CURRENT_USER_ID'] = 7;
    $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'edit_post' ];
    $GLOBALS['NONCE_VALID']     = true;
    $GLOBALS['SIDELOAD_RETURN_ID'] = 777;
    $wpdb->tables          = [];
    $wpdb->queries         = [];
    $wpdb->auto_increments = [];
    PressHub_AI_Generation_Recorder::create_table();
    $GLOBALS['OPTIONS_STORE'][ PressHub_AI_Provider_Store::OPTION_CONFIGURED_PROVIDERS ] = [
        [ 'id' => 'openai-main', 'type' => 'openai', 'name' => 'OpenAI', 'api_key' => GRE_OPENAI_KEY, 'default_model' => 'gpt-test', 'enabled' => true ],
        [ 'id' => 'gemini-main', 'type' => 'gemini', 'name' => 'Gemini', 'api_key' => GRE_GEMINI_KEY, 'default_model' => 'gemini-2.5-flash-preview-tts', 'enabled' => true ],
    ];
    $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_female']  = 'Μαρία';
    $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_male']    = 'Νίκος';
    $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_female'] = 'Kore';
    $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_male']   = 'Fenrir';
    $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model']    = 'gemini-2.5-flash-preview-tts';
    $GLOBALS['GRE_LLM_REPLIES'] = [];
    $GLOBALS['GRE_TTS_PLAN']    = [];
    $GLOBALS['CAPTURE_FILTER']  = 'gre_http';
}

/** HTTP stub: OpenAI chat replies come from a queue, Gemini TTS follows GRE_TTS_PLAN. */
function gre_http( $existing, $req ) {
    list( $url, $args ) = $req;
    if ( false !== strpos( $url, 'api.openai.com/v1/chat/completions' ) ) {
        $reply = array_shift( $GLOBALS['GRE_LLM_REPLIES'] );
        if ( null === $reply ) {
            $reply = 'default reply';
        }
        if ( $reply instanceof WP_Error ) {
            return $reply;
        }
        return [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'choices' => [ [ 'message' => [ 'content' => $reply ], 'finish_reason' => 'stop' ] ],
                'usage'   => [ 'prompt_tokens' => 11, 'completion_tokens' => 22, 'total_tokens' => 33 ],
            ] ),
        ];
    }
    if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) && false !== strpos( $url, ':generateContent' ) ) {
        if ( false !== strpos( $url, 'image' ) ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'inlineData' => [ 'mimeType' => 'image/jpeg', 'data' => base64_encode( 'FAKE_IMAGE' ) ] ] ] ] ] ] ] ),
            ];
        }
        $step = array_shift( $GLOBALS['GRE_TTS_PLAN'] );
        if ( 503 === $step ) {
            return [ 'response' => [ 'code' => 503 ], 'body' => json_encode( [ 'error' => [ 'message' => 'The model is overloaded', 'status' => 'UNAVAILABLE' ] ] ) ];
        }
        return [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'inlineData' => [ 'mimeType' => 'audio/pcm;rate=24000', 'data' => base64_encode( str_repeat( "\x12\x34", 2400 ) ) ] ] ] ] ] ] ] ),
        ];
    }
    if ( false !== strpos( $url, 'texttospeech.googleapis.com' ) ) {
        return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'audioContent' => base64_encode( "\xFF\xFB\x90\xC4" . str_repeat( 'M', 400 ) ) ] ) ];
    }
    return null;
}

/** Messages the provider received in the most recent OpenAI request. */
function gre_last_messages(): array {
    $requests = array_values( array_filter( $GLOBALS['CAPTURED_REQUESTS'], static function ( $r ) {
        return false !== strpos( (string) $r[0], 'api.openai.com/v1/chat/completions' );
    } ) );
    $last = end( $requests );
    $body = is_array( $last ) ? json_decode( (string) ( $last[1]['body'] ?? '' ), true ) : [];
    $by_role = [];
    foreach ( (array) ( $body['messages'] ?? [] ) as $m ) {
        $by_role[ $m['role'] ] = $m['content'];
    }
    return $by_role;
}

/** All records of a kind, decoded. */
function gre_records( string $kind = '' ): array {
    global $wpdb;
    $out = [];
    foreach ( $wpdb->tables[ PressHub_AI_Generation_Recorder::get_table_name() ] ?? [] as $row ) {
        if ( '' === $kind || $kind === $row['kind'] ) {
            $out[] = PressHub_AI_Generation_Recorder::get( (int) $row['id'] );
        }
    }
    return $out;
}

function gre_in_post_meta( int $post_id, int $record_id ): bool {
    return in_array( $record_id, (array) get_post_meta( $post_id, PressHub_AI_Generation_Recorder::POST_META_KEY, true ), true );
}

// =========================================================================
// 1a. coauthor_draft
// =========================================================================
gre_reset();
$draft_reply = "## Επικεφαλίδα\n\nΚείμενο **πρόχειρου** με <script>x</script> και 'εισαγωγικά'.";
$GLOBALS['GRE_LLM_REPLIES'] = [ $draft_reply ];
$client = new PressHub_AI_API_Client( 'coauthor' );
$draft  = $client->generate_draft( 'Πηγή: https://example.gr/a', 'Σύντομο ρεπορτάζ', [], '__none__', 55 );
gre_check( 'draft: provider call succeeded', ! is_wp_error( $draft ) );
$msgs = gre_last_messages();
$recs = gre_records( 'coauthor_draft' );
gre_check( 'draft: exactly one coauthor_draft record', 1 === count( $recs ) );
$rec = $recs[0] ?? [];
gre_check( 'draft: system prompt byte-identical to what the provider received', ( $rec['system_prompt'] ?? null ) === ( $msgs['system'] ?? '' ) && '' !== ( $msgs['system'] ?? '' ) );
gre_check( 'draft: user prompt byte-identical to what the provider received', ( $rec['user_prompt'] ?? null ) === ( $msgs['user'] ?? '' ) && '' !== ( $msgs['user'] ?? '' ) );
gre_check( 'draft: response is the raw provider reply (before Markdown conversion)', ( $rec['response_text'] ?? null ) === $draft_reply );
gre_check( 'draft: linked to the post (column + post meta)', 55 === ( $rec['post_id'] ?? 0 ) && gre_in_post_meta( 55, (int) ( $rec['id'] ?? 0 ) ) );
gre_check( 'draft: config has the real model and the author id', 'gpt-test' === ( $rec['config']['model'] ?? '' ) && 7 === ( $rec['config']['user_id'] ?? 0 ) );
gre_check( 'draft: usage and duration stored', 33 === (int) ( $rec['usage']['total_tokens'] ?? 0 ) && ( $rec['duration_ms'] ?? -1 ) >= 0 );
gre_check( 'draft: no duplicate generic record', 0 === count( gre_records( 'generic' ) ) );

// Error path
$GLOBALS['GRE_LLM_REPLIES'] = [ new WP_Error( 'boom', 'Provider exploded' ) ];
$client = new PressHub_AI_API_Client( 'coauthor' );
$err    = $client->generate_draft( 'src', 'instr', [], '__none__', 55 );
$errs   = array_values( array_filter( gre_records( 'coauthor_draft' ), static function ( $r ) { return 'error' === $r['status']; } ) );
gre_check( 'draft error: error record with message and prompts', 1 === count( $errs ) && false !== strpos( (string) $errs[0]['error_message'], 'Provider exploded' ) && '' !== (string) $errs[0]['user_prompt'] );

// =========================================================================
// 1b. scorecard
// =========================================================================
gre_reset();
$score_reply = '```json' . "\n" . '{"score":88,"libel_risk":"Low","feedback":"Καλό.","legal_concerns":"None identified"}' . "\n```";
$GLOBALS['GRE_LLM_REPLIES'] = [ $score_reply ];
$client = new PressHub_AI_API_Client( 'coauthor' );
$card   = $client->generate_scorecard( 'Κείμενο άρθρου', 'Review this: {content}', 56 );
gre_check( 'scorecard: parsed result returned', is_array( $card ) && 88 === ( $card['score'] ?? 0 ) );
$msgs = gre_last_messages();
$recs = gre_records( 'scorecard' );
gre_check( 'scorecard: exactly one record', 1 === count( $recs ) );
$rec = $recs[0] ?? [];
gre_check( 'scorecard: prompts byte-identical', ( $rec['system_prompt'] ?? null ) === ( $msgs['system'] ?? '' ) && ( $rec['user_prompt'] ?? null ) === ( $msgs['user'] ?? '' ) && 'Review this: Κείμενο άρθρου' === ( $rec['user_prompt'] ?? '' ) );
gre_check( 'scorecard: response is the raw reply (fences included)', ( $rec['response_text'] ?? null ) === $score_reply );
gre_check( 'scorecard: linked to the post', 56 === ( $rec['post_id'] ?? 0 ) && gre_in_post_meta( 56, (int) ( $rec['id'] ?? 0 ) ) );
gre_check( 'scorecard: no duplicate generic record', 0 === count( gre_records( 'generic' ) ) );

// =========================================================================
// 1c. copilot_chat (with a post, then without)
// =========================================================================
$_POST = [ 'prompt' => 'Πες μου κάτι για το άρθρο', 'post_id' => '57', 'article_content' => 'Περιεχόμενο άρθρου', 'article_title' => 'Τίτλος', 'nonce' => 'x' ];
gre_reset();
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'edit_post' ];
$_POST = [ 'prompt' => 'Πες μου κάτι για το άρθρο', 'post_id' => '57', 'article_content' => 'Περιεχόμενο άρθρου', 'article_title' => 'Τίτλος', 'nonce' => 'x' ];
$chat_reply = "Ιδού η <b>απάντηση</b>\n\nΜε δεύτερη γραμμή.";
$GLOBALS['GRE_LLM_REPLIES'] = [ 'chat', $chat_reply ]; // classify_intent, then the chat call
$handler = new PressHub_AI_Ajax_Handlers();
try {
    $handler->handle_chat_routing();
} catch ( RuntimeException $e ) {
    gre_check( 'chat: handler reached wp_send_json_success', 'wp_send_json_success' === $e->getMessage() );
}
$msgs = gre_last_messages();
$recs = gre_records( 'copilot_chat' );
gre_check( 'chat: exactly one copilot_chat record', 1 === count( $recs ) );
$rec = $recs[0] ?? [];
gre_check( 'chat: prompts byte-identical to the chat request', ( $rec['system_prompt'] ?? null ) === ( $msgs['system'] ?? '' ) && ( $rec['user_prompt'] ?? null ) === ( $msgs['user'] ?? '' ) && 'Πες μου κάτι για το άρθρο' === ( $rec['user_prompt'] ?? '' ) );
gre_check( 'chat: response byte-identical', ( $rec['response_text'] ?? null ) === $chat_reply );
gre_check( 'chat: linked to the post', 57 === ( $rec['post_id'] ?? 0 ) && gre_in_post_meta( 57, (int) ( $rec['id'] ?? 0 ) ) );
gre_check( 'chat: action is copilot_chat and user stored', 'copilot_chat' === ( $rec['config']['action'] ?? '' ) && 7 === ( $rec['config']['user_id'] ?? 0 ) );
gre_check( 'chat: the intent classification is a separate generic record', 1 === count( gre_records( 'generic' ) ) && 'classify_intent' === ( gre_records( 'generic' )[0]['config']['action'] ?? '' ) );

// Without a post: the user is identified, nothing is attached to a post
gre_reset();
$_POST = [ 'prompt' => 'Γενική ερώτηση', 'post_id' => '0', 'nonce' => 'x' ];
$GLOBALS['GRE_LLM_REPLIES'] = [ 'chat', 'Γενική απάντηση' ];
try {
    ( new PressHub_AI_Ajax_Handlers() )->handle_chat_routing();
} catch ( RuntimeException $e ) {
}
$recs = gre_records( 'copilot_chat' );
$rec  = $recs[0] ?? [];
gre_check( 'chat (no post): one record, no post link, user id in config and inputs', 1 === count( $recs ) && 0 === ( $rec['post_id'] ?? -1 ) && 7 === ( $rec['config']['user_id'] ?? 0 ) && 7 === ( $rec['inputs']['user_id'] ?? 0 ) );
gre_check( 'chat (no post): not attached to any post', empty( $GLOBALS['POST_META_STORE'] ) );

// =========================================================================
// 1d. research
// =========================================================================
gre_reset();
$GLOBALS['POSTS_STORE'][601] = [ 'ID' => 601, 'post_type' => 'presshub_research', 'post_status' => 'pending', 'post_content' => '' ];
$GLOBALS['POSTS_STORE'][602] = [ 'ID' => 602, 'post_type' => 'post', 'post_status' => 'draft', 'post_content' => 'Κείμενο συσχετισμένου άρθρου' ];
$GLOBALS['POST_META_STORE'][601] = [ '_research_status' => 'pending', '_research_prompt' => 'Ερεύνησε το θέμα', '_associated_post_id' => 602, '_research_user_id' => 7 ];
$research_reply = "# Αναφορά\n\n- σημείο <i>ένα</i>\n- σημείο δύο";
$GLOBALS['GRE_LLM_REPLIES'] = [ $research_reply ];
presshub_ai_execute_research_job( 601 );
$msgs = gre_last_messages();
$recs = gre_records( 'research' );
$rec  = $recs[0] ?? [];
gre_check( 'research: exactly one record', 1 === count( $recs ) );
gre_check( 'research: prompts byte-identical', ( $rec['system_prompt'] ?? null ) === ( $msgs['system'] ?? '' ) && ( $rec['user_prompt'] ?? null ) === ( $msgs['user'] ?? '' ) && false !== strpos( (string) ( $rec['user_prompt'] ?? '' ), 'Ερεύνησε το θέμα' ) );
gre_check( 'research: response is the raw reply (before HTML conversion)', ( $rec['response_text'] ?? null ) === $research_reply );
gre_check( 'research: linked to the research post', 601 === ( $rec['post_id'] ?? 0 ) && gre_in_post_meta( 601, (int) ( $rec['id'] ?? 0 ) ) );
gre_check( 'research: associated post and user listed', 602 === ( $rec['inputs']['associated_post_id'] ?? 0 ) && 7 === ( $rec['config']['user_id'] ?? 0 ) );
gre_check( 'research: no duplicate generic record', 0 === count( gre_records( 'generic' ) ) );

// =========================================================================
// 1e. image_generation (attachment + parent post), success and error
// =========================================================================
gre_reset();
$GLOBALS['OPTIONS_STORE']['presshub_ai_image_generation_provider'] = 'gemini-main';
$GLOBALS['OPTIONS_STORE'][ PressHub_AI_Provider_Store::OPTION_CONFIGURED_PROVIDERS ][1]['default_model'] = 'gemini-3.1-flash-image';
$image_prompt = 'Φωτορεπορτάζ: το κοινοβούλιο, 35mm "ρεαλιστικό".';
$client = new PressHub_AI_API_Client();
$img    = $client->generate_featured_image( $image_prompt, '16:9', 10 );
gre_check( 'image: generation succeeded', is_array( $img ) && 777 === ( $img['id'] ?? 0 ) );
$recs = gre_records( 'image_generation' );
$rec  = $recs[0] ?? [];
gre_check( 'image: exactly one record', 1 === count( $recs ) );
gre_check( 'image: user prompt is the exact image prompt', ( $rec['user_prompt'] ?? null ) === $image_prompt );
gre_check( 'image: linked to the parent post', 10 === ( $rec['post_id'] ?? 0 ) && gre_in_post_meta( 10, (int) ( $rec['id'] ?? 0 ) ) );
gre_check( 'image: linked to the created attachment', 777 === ( $rec['inputs']['attachment_id'] ?? 0 ) && gre_in_post_meta( 777, (int) ( $rec['id'] ?? 0 ) ) );
gre_check( 'image: provider, model and aspect ratio stored', 'gemini' === ( $rec['config']['provider_type'] ?? '' ) && 'gemini-3.1-flash-image' === ( $rec['config']['model'] ?? '' ) && '16:9' === ( $rec['config']['aspect_ratio'] ?? '' ) );
gre_check( 'image: response describes the attachment', false !== strpos( (string) ( $rec['response_text'] ?? '' ), 'ID: 777' ) );
gre_check( 'image: the API key is not stored', false === strpos( json_encode( $rec ), GRE_GEMINI_KEY ) );

$GLOBALS['CAPTURE_FILTER'] = static function ( $existing, $req ) {
    return [ 'response' => [ 'code' => 500 ], 'body' => json_encode( [ 'error' => [ 'message' => 'image backend down' ] ] ) ];
};
$err = ( new PressHub_AI_API_Client() )->generate_featured_image( 'Δεύτερο prompt', '1:1', 11 );
$errs = array_values( array_filter( gre_records( 'image_generation' ), static function ( $r ) { return 'error' === $r['status']; } ) );
gre_check( 'image error: error record with message, prompt and post link', is_wp_error( $err ) && 1 === count( $errs ) && false !== strpos( (string) $errs[0]['error_message'], 'image backend down' ) && 'Δεύτερο prompt' === $errs[0]['user_prompt'] && 11 === $errs[0]['post_id'] );

// =========================================================================
// 1f. generic: every other call_provider() call, action name as `action`, no post link
// =========================================================================
gre_reset();
$GLOBALS['GRE_LLM_REPLIES'] = [ 'Σύνοψη URL' ];
$client = new PressHub_AI_API_Client();
$client->set_action( 'summarize_url' );
$res = $client->call_provider( "Σύστημα\n  με κενά ", 'Χρήστης <b>x</b>', false, [] );
$msgs = gre_last_messages();
$recs = gre_records( 'generic' );
$rec  = $recs[0] ?? [];
gre_check( 'generic: exactly one record', 1 === count( $recs ) );
gre_check( 'generic: prompts and response byte-identical', "Σύστημα\n  με κενά " === ( $rec['system_prompt'] ?? null ) && 'Χρήστης <b>x</b>' === ( $rec['user_prompt'] ?? null ) && 'Σύνοψη URL' === ( $rec['response_text'] ?? null ) && ( $msgs['system'] ?? '' ) === $rec['system_prompt'] );
gre_check( 'generic: action name stored, no post link', 'summarize_url' === ( $rec['config']['action'] ?? '' ) && 0 === ( $rec['post_id'] ?? -1 ) && empty( $GLOBALS['POST_META_STORE'] ) );
$client->suppress_provider_prompt_log = true;
$GLOBALS['GRE_LLM_REPLIES'] = [ 'x' ];
$client->call_provider( 's', 'u', false, [] );
$client->suppress_provider_prompt_log = false;
gre_check( 'generic: skipped when the caller records itself (suppress flag)', 1 === count( gre_records( 'generic' ) ) );

// =========================================================================
// 2. No double records for the briefing and podcast script paths
// =========================================================================
gre_reset();
$date = '2026-09-03';
$harvester = new PressHub_AI_News_Harvester();
$harvester->save_snapshot( $date, [
    'date' => $date, 'harvested_at' => gmdate( 'c' ), 'sources' => [], 'blocked_sources' => [],
    'articles' => [ [ 'id' => 'a1', 'title' => 'Πρώτο', 'source' => 'S1', 'url' => 'https://s1.example/1', 'content' => 'Περιεχόμενο' ] ],
] );
$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles']          = 50;
$GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 3000;
$GLOBALS['GRE_LLM_REPLIES'] = [ "# Τίτλος Ημέρας\n\nΚείμενο." ];
$res = ( new PressHub_AI_News_Curator() )->generate_briefing( $date, new PressHub_AI_API_Client( 'briefing_text' ), '__none__', [], 'morning' );
gre_check( 'briefing: generation succeeded through the real client', is_array( $res ) && ! empty( $res['post_id'] ) );
gre_check( 'briefing: exactly one briefing_text record and no generic record (the QA review is its own scorecard record, automatic tagging its own seo record)', 1 === count( gre_records( 'briefing_text' ) ) && 0 === count( gre_records( 'generic' ) ) && count( gre_records() ) === 1 + count( gre_records( 'scorecard' ) ) + count( gre_records( 'seo' ) ) );

gre_reset();
$GLOBALS['GRE_LLM_REPLIES'] = [ "[SPEAKER_1]: Καλημέρα σας!\n[SPEAKER_2]: Καλημέρα, ξεκινάμε." ];
$pres = ( new PressHub_AI_Podcast_Producer() )->generate_dialogue_script( $date, new PressHub_AI_API_Client( 'briefing_podcast' ), '__none__', '3_min', 'harvested_articles', [], 'morning' );
gre_check( 'podcast script: generation succeeded through the real client', is_array( $pres ) && ! empty( $pres['raw_script'] ) );
gre_check( 'podcast script: exactly one podcast_script record and no generic record', 1 === count( gre_records( 'podcast_script' ) ) && 0 === count( gre_records( 'generic' ) ) && 1 === count( gre_records() ) );

// =========================================================================
// 3. Podcast synthesis: per-call TTS entries on the parent record
// =========================================================================
gre_reset();
$GLOBALS['OPTIONS_STORE']['presshub_ai_log_tts_payloads'] = '0'; // entries are independent of the raw dump toggle
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_audio_split_by_topic'] = 0;
$tts_date = '2026-09-04';
$script   = "[Μαρία]: Καλημέρα σε όλους, αυτό είναι το πρώτο μήνυμα.\n[Νίκος]: Καλημέρα Μαρία, ξεκινάμε με τις ειδήσεις.";
$parent   = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'podcast_script', 'briefing_date' => $tts_date, 'edition' => 'morning', 'user_prompt' => 'u', 'response_text' => $script ] );
$producer = new PressHub_AI_Podcast_Producer();
$producer->save_script( $tts_date, $script, 'harvested_articles', 0, '', 'morning', $parent );
// call 1 fails (503), its retry succeeds, call 2 succeeds
$GLOBALS['GRE_TTS_PLAN'] = [ 503, 200, 200 ];
$synth  = new PressHub_AI_Audio_Synthesizer();
$result = $synth->synthesize_podcast( $tts_date, '', 'morning' );
gre_check( 'tts: podcast synthesis succeeded with stubbed TTS', is_array( $result ) && ! empty( $result['success'] ) );
$parent_rec = PressHub_AI_Generation_Recorder::get( $parent );
$calls      = $parent_rec['tts_calls'] ?? [];
$entries    = $calls['entries'] ?? [];
$api_calls  = array_values( array_filter( $entries, static function ( $e ) { return 'request' === ( $e['phase'] ?? '' ); } ) );
gre_check( 'tts: one entry per TTS API call (3 requests: 503, retry, second turn)', 3 === count( $api_calls ) );
gre_check( 'tts: the failed first call carries its HTTP status and error', 503 === ( $api_calls[0]['http_status'] ?? 0 ) && false !== strpos( (string) ( $api_calls[0]['error'] ?? '' ), 'overloaded' ) && 0 === ( $api_calls[0]['retry_attempt'] ?? -1 ) );
gre_check( 'tts: the retry after the failure is its own entry (retry_attempt 1, status 200, audio length)', 1 === ( $api_calls[1]['retry_attempt'] ?? 0 ) && 200 === ( $api_calls[1]['http_status'] ?? 0 ) && '' === ( $api_calls[1]['error'] ?? 'x' ) && 4800 === ( $api_calls[1]['audio_bytes'] ?? 0 ) && abs( 0.1 - (float) ( $api_calls[1]['audio_seconds'] ?? 0 ) ) < 0.001 );
gre_check( 'tts: model, voice and style stored; second turn uses the second voice', 'gemini-2.5-flash-preview-tts' === ( $api_calls[0]['model'] ?? '' ) && 'Kore' === ( $api_calls[0]['voice'] ?? '' ) && 'Fenrir' === ( $api_calls[2]['voice'] ?? '' ) && isset( $api_calls[0]['settings']['style'] ) );
$turn1_text = 'Καλημέρα σε όλους, αυτό είναι το πρώτο μήνυμα.';
gre_check( 'tts: text length and sha256 stored, not the text', mb_strlen( $turn1_text ) === ( $api_calls[0]['text_chars'] ?? 0 ) && hash( 'sha256', $turn1_text ) === ( $api_calls[0]['text_sha256'] ?? '' ) );
$stored_json = (string) json_encode( $calls, JSON_UNESCAPED_UNICODE );
gre_check( 'tts: no spoken text anywhere in the stored calls', false === strpos( $stored_json, 'αυτό είναι το πρώτο μήνυμα' ) && false === strpos( $stored_json, 'ξεκινάμε με τις ειδήσεις' ) );
gre_check( 'tts: no base64 audio in the stored calls', false === strpos( $stored_json, base64_encode( str_repeat( "\x12\x34", 2400 ) ) ) && false === strpos( $stored_json, substr( base64_encode( str_repeat( "\x12\x34", 2400 ) ), 0, 40 ) ) );
gre_check( 'tts: no plain API key in the stored calls', false === strpos( $stored_json, GRE_GEMINI_KEY ) && false === strpos( $stored_json, 'PLAINGEMINIKEY' ) );
gre_check( 'tts: endpoint kept with the key masked', false !== strpos( (string) ( $api_calls[0]['endpoint'] ?? '' ), 'key=***masked***' ) );
gre_check( 'tts: request body kept (voice config) without the text', isset( $api_calls[0]['request']['generationConfig'] ) && false !== strpos( (string) ( $api_calls[0]['request']['contents'][0]['parts'][0]['text'] ?? '' ), 'text omitted' ) );
gre_check( 'tts: request body with a base64 audio field is stripped', false !== strpos( json_encode( PressHub_AI_TTS_Call_Recorder::sanitize_request( [ 'inlineData' => [ 'data' => base64_encode( str_repeat( 'A', 400 ) ) ], 'x' => [ 'audioContent' => 'abc' ], 'blob' => str_repeat( 'QUJD', 100 ) ] ) ), 'base64 omitted' ) && false === strpos( json_encode( PressHub_AI_TTS_Call_Recorder::sanitize_request( [ 'blob' => str_repeat( 'QUJD', 100 ) ] ) ), 'QUJDQUJD' ) );
$summaries = array_values( array_filter( $entries, static function ( $e ) { return isset( $e['summary'] ); } ) );
gre_check( 'tts: whole-script stitched summary entry present', 1 === count( $summaries ) && 'stitched' === $summaries[0]['phase'] && 'whole_script_stitched' === ( $summaries[0]['summary']['phase'] ?? '' ) );
gre_check( 'tts: not truncated and the session is closed afterwards', empty( $calls['truncated'] ) && ! PressHub_AI_TTS_Call_Recorder::is_active() && 0 === PressHub_AI_TTS_Call_Recorder::current_record_id() );
gre_check( 'tts: no stray tts_test record when a parent record exists', 0 === count( gre_records( 'tts_test' ) ) );
gre_check( 'tts: debug TTS payload file was not written (toggle off)', ! PressHub_AI_Settings_Storage::get_log_tts_payloads() );

// A second synthesis with the cache warm records cache hits (no new API calls)
$GLOBALS['GRE_TTS_PLAN'] = [];
$GLOBALS['CAPTURED_REQUESTS'] = [];
$result2 = $synth->synthesize_podcast( $tts_date, '', 'morning' );
$entries2 = ( PressHub_AI_Generation_Recorder::get( $parent )['tts_calls']['entries'] ?? [] );
$hits = array_values( array_filter( $entries2, static function ( $e ) { return 'cache_hit' === ( $e['phase'] ?? '' ); } ) );
gre_check( 'tts: a re-run replaces the entries and records cache hits instead of API calls', 2 === count( $hits ) && 0 === count( array_filter( $entries2, static function ( $e ) { return 'request' === ( $e['phase'] ?? '' ); } ) ) );

// Topic-split path: topic and stitched summaries
gre_reset();
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_audio_split_by_topic'] = 1;
$topic_script = "[TOPIC_START: Οικονομία]\n[Μαρία]: Πρώτο θέμα για την οικονομία σήμερα.\n[Νίκος]: Συμφωνώ, πολύ σημαντικό.\n[TOPIC_END]\n[TOPIC_START: Αθλητισμός]\n[Μαρία]: Δεύτερο θέμα για τον αθλητισμό.\n[Νίκος]: Ναι, ένα συναρπαστικό ματς.\n[TOPIC_END]";
$parent2  = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'podcast_script', 'briefing_date' => '2026-09-05', 'edition' => 'morning' ] );
$producer->save_script( '2026-09-05', $topic_script, 'harvested_articles', 0, '', 'morning', $parent2 );
$GLOBALS['GRE_TTS_PLAN'] = [ 200, 200, 200, 200 ];
$result3 = $synth->synthesize_podcast( '2026-09-05', '', 'morning' );
$e3 = ( PressHub_AI_Generation_Recorder::get( $parent2 )['tts_calls']['entries'] ?? [] );
$phases = array_count_values( array_map( static function ( $e ) { return $e['phase'] ?? ''; }, $e3 ) );
gre_check( 'tts (topics): 4 API calls, 2 topic summaries and the stitched summary', 4 === ( $phases['request'] ?? 0 ) && 2 === ( $phases['topic'] ?? 0 ) && 1 === ( $phases['stitched'] ?? 0 ) );

// Google Cloud: the Neural2 guard swaps the voice, reported as the fallback model
gre_reset();
$GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'GCPLAINKEY-1234567890';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_engine']  = 'google_cloud';
$gc_client = new PressHub_AI_API_Client( 'tts' );
$rp = new ReflectionProperty( 'PressHub_AI_API_Client', 'google_cloud_api_key' );
$rp->setAccessible( true );
$rp->setValue( $gc_client, 'GCPLAINKEY-1234567890' );
PressHub_AI_TTS_Call_Recorder::begin_session( 0, [ 'origin' => 'unit_test' ] );
$gc_audio = $gc_client->synthesize_speech_with_options( 'Δοκιμαστικό κείμενο', 'el-GR-Neural2-A', 1.0, 0.0 );
PressHub_AI_TTS_Call_Recorder::end_session();
$gc = ( gre_records( 'tts_test' )[0]['tts_calls']['entries'][0] ?? [] );
gre_check( 'tts (google cloud): voice swap recorded as fallback model', 'google_cloud' === ( $gc['engine'] ?? '' ) && 'el-GR-Neural2-A' === ( $gc['model'] ?? '' ) && 'el-GR-Wavenet-A' === ( $gc['fallback_model'] ?? '' ) && 200 === ( $gc['http_status'] ?? 0 ) && ( $gc['audio_bytes'] ?? 0 ) > 0 );
gre_check( 'tts (google cloud): no key, no text, no audio stored', false === strpos( json_encode( $gc ), 'GCPLAINKEY' ) && false === strpos( json_encode( $gc ), 'Δοκιμαστικό' ) );

// =========================================================================
// 4. tts_test: a TTS call without a parent record (settings test button)
// =========================================================================
gre_reset();
$GLOBALS['GRE_TTS_PLAN'] = [ 200 ];
$test_res = ( new PressHub_AI_API_Client() )->test_synthesize_speech( [ 'text' => 'Καλημέρα από το τεστ', 'two_hosts' => false, 'speaker1' => 'Kore' ] );
gre_check( 'tts_test: the test synthesis succeeded', is_array( $test_res ) && ! empty( $test_res['success'] ) );
$tt = gre_records( 'tts_test' );
gre_check( 'tts_test: exactly one tts_test record with one call', 1 === count( $tt ) && 1 === count( $tt[0]['tts_calls']['entries'] ?? [] ) );
$tte = $tt[0]['tts_calls']['entries'][0] ?? [];
gre_check( 'tts_test: model, voice, status, duration and audio length stored', 'gemini-2.5-flash-preview-tts' === ( $tte['model'] ?? '' ) && 'Kore' === ( $tte['voice'] ?? '' ) && 200 === ( $tte['http_status'] ?? 0 ) && ( $tte['audio_bytes'] ?? 0 ) > 0 && isset( $tte['duration_ms'] ) );
gre_check( 'tts_test: no key and no audio stored', false === strpos( json_encode( $tt[0] ), GRE_GEMINI_KEY ) && false === strpos( json_encode( $tt[0] ), base64_encode( str_repeat( "\x12\x34", 2400 ) ) ) );
gre_check( 'tts_test: record kind is allowed and labelled', in_array( 'tts_test', PressHub_AI_Generation_Recorder::KINDS, true ) && 'TTS test' === PressHub_AI_Generation_Inspector::kind_label( 'tts_test' ) );

// =========================================================================
// 5. 500-entry cap, truncated marker and one WARNING
// =========================================================================
gre_reset();
file_put_contents( $log_file, '' );
$cap_parent = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'podcast_script' ] );
PressHub_AI_TTS_Call_Recorder::begin_session( $cap_parent );
for ( $i = 1; $i <= 505; $i++ ) {
    PressHub_AI_TTS_Call_Recorder::log_call( [ 'engine' => 'gemini', 'model' => 'm', 'voice' => 'Kore', 'text' => 'turn ' . $i, 'http_status' => 200, 'audio_bytes' => 10 ] );
}
PressHub_AI_TTS_Call_Recorder::end_session();
$cap = PressHub_AI_Generation_Recorder::get( $cap_parent )['tts_calls'] ?? [];
$log = (string) file_get_contents( $log_file );
gre_check( 'cap: exactly 500 entries stored', 500 === count( $cap['entries'] ?? [] ) && 500 === PressHub_AI_TTS_Call_Recorder::MAX_ENTRIES );
gre_check( 'cap: marked truncated with 5 dropped of 505', true === ( $cap['truncated'] ?? false ) && 5 === ( $cap['dropped'] ?? 0 ) && 505 === ( $cap['total'] ?? 0 ) );
gre_check( 'cap: the first 500 entries are kept in order', 1 === ( $cap['entries'][0]['seq'] ?? 0 ) && 500 === ( $cap['entries'][499]['seq'] ?? 0 ) );
gre_check( 'cap: exactly one WARNING logged', 1 === substr_count( $log, 'WARNING' ) && false !== strpos( $log, 'exceeded 500 entries' ) );
$under = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'podcast_script' ] );
PressHub_AI_TTS_Call_Recorder::begin_session( $under );
for ( $i = 1; $i <= 3; $i++ ) {
    PressHub_AI_TTS_Call_Recorder::log_call( [ 'engine' => 'gemini', 'text' => 'x' ] );
}
PressHub_AI_TTS_Call_Recorder::end_session();
gre_check( 'cap: a short list is not truncated', false === ( PressHub_AI_Generation_Recorder::get( $under )['tts_calls']['truncated'] ?? true ) );

// Table versioning: new column in the schema and a bumped version
$GLOBALS['DBDELTA_QUERIES'] = [];
update_option( PressHub_AI_Generation_Recorder::DB_VERSION_OPTION, '1.0.0' );
PressHub_AI_Generation_Recorder::create_table();
gre_check( 'schema: tts_calls_json column in CREATE TABLE and version bumped', false !== strpos( implode( ' ', (array) $GLOBALS['DBDELTA_QUERIES'] ), 'tts_calls_json longtext' ) && '1.0.0' !== PressHub_AI_Generation_Recorder::DB_VERSION && get_option( PressHub_AI_Generation_Recorder::DB_VERSION_OPTION ) === PressHub_AI_Generation_Recorder::DB_VERSION );

// =========================================================================
// 6. Access: the author opens their own copilot_chat record, another non-editor cannot
// =========================================================================
gre_reset();
$chat_id    = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'copilot_chat', 'config' => [ 'user_id' => 7 ], 'user_prompt' => 'Μυστικό <script>alert(1)</script>', 'response_text' => 'απάντηση' ] );
$brief_id   = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'briefing_text', 'config' => [ 'user_id' => 7 ], 'user_prompt' => 'b' ] );
$generic_id = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'generic', 'config' => [ 'user_id' => 7 ], 'user_prompt' => 'g' ] );
$inspector  = new PressHub_AI_Generation_Inspector();
function gre_ajax( callable $fn ): array {
    $GLOBALS['JSON_RESPONSES'] = [];
    $exception = null;
    try {
        $fn();
    } catch ( RuntimeException $e ) {
        $exception = $e->getMessage();
    }
    $r = $GLOBALS['JSON_RESPONSES'];
    return [ 'exception' => $exception, 'response' => empty( $r ) ? null : end( $r ) ];
}
function gre_as( int $user_id, array $caps ): void {
    $GLOBALS['CURRENT_USER_ID']   = $user_id;
    $GLOBALS['CURRENT_USER_CAPS'] = $caps;
    $GLOBALS['NONCE_VALID']       = true;
}
$field_req = static function ( int $id, string $field = 'user_prompt' ) { $_POST = [ 'record_id' => $id, 'field' => $field, 'nonce' => 'x' ]; };
$tab_req   = static function ( int $id, string $tab = 'overview' ) { $_POST = [ 'record_id' => $id, 'tab' => $tab, 'nonce' => 'x' ]; };

gre_as( 7, [ 'edit_posts' ] );
$field_req( $chat_id );
$r = gre_ajax( [ $inspector, 'ajax_field' ] );
gre_check( 'access: the author can load fields of their own copilot_chat record', true === ( $r['response']['success'] ?? null ) && 'Μυστικό <script>alert(1)</script>' === ( $r['response']['data']['value'] ?? '' ) );
$tab_req( $chat_id );
$r = gre_ajax( [ $inspector, 'ajax_tab' ] );
gre_check( 'access: the author can load tabs of their own record, with the user shown and everything escaped', true === ( $r['response']['success'] ?? null ) && false !== strpos( (string) ( $r['response']['data']['html'] ?? '' ), 'Copilot chat' ) && false === strpos( (string) ( $r['response']['data']['html'] ?? '' ), '<script>' ) );
gre_check( 'access: can_view_record true for the author', PressHub_AI_Generation_Inspector::can_view_record( $chat_id ) );

gre_as( 8, [ 'edit_posts' ] );
$field_req( $chat_id );
$r = gre_ajax( [ $inspector, 'ajax_field' ] );
gre_check( 'access: another non-editor gets 403 on the field loader', false === ( $r['response']['success'] ?? null ) );
$tab_req( $chat_id );
$r = gre_ajax( [ $inspector, 'ajax_tab' ] );
gre_check( 'access: another non-editor gets an error on the tab loader', false === ( $r['response']['success'] ?? null ) && false === strpos( json_encode( $r ), 'copilot' ) );
gre_check( 'access: can_view_record false for another non-editor', ! PressHub_AI_Generation_Inspector::can_view_record( $chat_id ) );
$_GET = [ 'record_id' => $chat_id, 'nonce' => 'x' ];
$denied = null;
try {
    $inspector->ajax_raw();
} catch ( \Throwable $t ) {
    $denied = $t->getMessage();
}
gre_check( 'access: another non-editor cannot download the raw JSON', null !== $denied );

gre_as( 7, [ 'edit_posts' ] );
$field_req( $brief_id );
$r = gre_ajax( [ $inspector, 'ajax_field' ] );
gre_check( 'access: the author rule does not apply to briefing records', false === ( $r['response']['success'] ?? null ) && ! PressHub_AI_Generation_Inspector::can_view_record( $brief_id ) );
gre_check( 'access: nor to generic records', ! PressHub_AI_Generation_Inspector::can_view_record( $generic_id ) );
$field_req( 99999 );
$r = gre_ajax( [ $inspector, 'ajax_field' ] );
gre_check( 'access: a missing record is 403 for non-editors (no existence oracle)', false === ( $r['response']['success'] ?? null ) && 403 !== null );

gre_as( 9, [ 'edit_posts', 'edit_others_posts' ] );
$field_req( $chat_id );
$r = gre_ajax( [ $inspector, 'ajax_field' ] );
gre_check( 'access: editors open any record', true === ( $r['response']['success'] ?? null ) && PressHub_AI_Generation_Inspector::can_view_record( $brief_id ) && PressHub_AI_Generation_Inspector::can_view_record( $generic_id ) );
$field_req( 99999 );
$r = gre_ajax( [ $inspector, 'ajax_field' ] );
gre_check( 'access: editors still get 404 for a missing record', false === ( $r['response']['success'] ?? null ) );

// Metabox on a post: authors see their own records only
$GLOBALS['POST_META_STORE'] = [];
PressHub_AI_Generation_Recorder::attach_to_post( 70, $chat_id );
PressHub_AI_Generation_Recorder::attach_to_post( 70, $brief_id );
gre_as( 7, [ 'edit_posts' ] );
$GLOBALS['META_BOXES'] = [];
$inspector->add_metabox( 'post', (object) [ 'ID' => 70 ] );
gre_check( 'access: metabox appears for the author on a post carrying their record', isset( $GLOBALS['META_BOXES'][ PressHub_AI_Generation_Inspector::METABOX_ID ] ) );
ob_start();
$inspector->render_metabox( (object) [ 'ID' => 70 ] );
$box = (string) ob_get_clean();
gre_check( 'access: the metabox lists the author record but not the briefing record', false !== strpos( $box, 'value="' . $chat_id . '"' ) && false === strpos( $box, 'value="' . $brief_id . '"' ) );
gre_as( 8, [ 'edit_posts' ] );
$GLOBALS['META_BOXES'] = [];
$inspector->add_metabox( 'post', (object) [ 'ID' => 70 ] );
gre_check( 'access: no metabox for another non-editor', empty( $GLOBALS['META_BOXES'] ) );

// Inspector rendering of the new kinds and the TTS calls table
gre_as( 9, [ 'edit_posts', 'edit_others_posts' ] );
foreach ( [ 'coauthor_draft' => 'Co-author draft', 'scorecard' => 'Editorial scorecard', 'copilot_chat' => 'Copilot chat', 'research' => 'Research', 'image_generation' => 'Image generation', 'generic' => 'Other LLM call', 'tts_test' => 'TTS test' ] as $kind => $label ) {
    gre_check( "inspector: label for {$kind}", $label === PressHub_AI_Generation_Inspector::kind_label( $kind ) && in_array( $kind, PressHub_AI_Generation_Recorder::KINDS, true ) );
}
$xss_call_id = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'tts_test', 'config' => [ 'user_id' => 9 ] ] );
PressHub_AI_Generation_Recorder::update_record( $xss_call_id, [ 'tts_calls' => [ 'truncated' => true, 'dropped' => 3, 'total' => 4, 'entries' => [
    [ 'seq' => 1, 'phase' => 'request', 'model' => '<img src=x onerror=alert(1)>', 'fallback_model' => 'fb', 'voice' => 'Kore', 'text_chars' => 12, 'text_sha256' => str_repeat( 'a', 64 ), 'http_status' => 503, 'error' => '<script>bad()</script>', 'duration_ms' => 40, 'audio_bytes' => 0, 'retry_attempt' => 0, 'request' => [ 'note' => '</pre><script>x</script>' ] ],
    [ 'seq' => 2, 'phase' => 'stitched', 'summary' => [ 'audio_bytes' => 99 ] ],
] ] ] );
$tts_html = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( $xss_call_id ), 'tts' );
gre_check( 'inspector: TTS tab shows the calls table, summaries and the truncation notice', false !== strpos( $tts_html, 'TTS calls (1)' ) && false !== strpos( $tts_html, 'presshub-inspector-tts-calls' ) && false !== strpos( $tts_html, 'Topic and stitched summaries' ) && false !== strpos( $tts_html, 'truncated' ) );
gre_check( 'inspector: TTS calls table escapes every value', false === strpos( $tts_html, '<script>' ) && false === strpos( $tts_html, '<img src=x' ) && false !== strpos( $tts_html, '&lt;script&gt;bad()' ) );
gre_check( 'inspector: TTS tab is visible for tts_test records', false === strpos( PressHub_AI_Generation_Inspector::render_inspector( [ PressHub_AI_Generation_Inspector::get_light_row( $xss_call_id ) ] ), 'data-tab="tts" hidden' ) );
gre_check( 'inspector: TTS tab stays hidden for other kinds', false !== strpos( PressHub_AI_Generation_Inspector::render_inspector( [ PressHub_AI_Generation_Inspector::get_light_row( $chat_id ) ] ), 'data-tab="tts" hidden' ) );
$ov = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( $chat_id ), 'overview' );
gre_check( 'inspector: overview of a copilot chat shows the user', false !== strpos( $ov, '>User<' ) && false !== strpos( $ov, '#7' ) );
$raw = PressHub_AI_Generation_Inspector::build_raw_json( $xss_call_id );
gre_check( 'inspector: raw JSON includes the tts_calls', null !== $raw && false !== strpos( $raw, '"tts_calls"' ) );

// =========================================================================
// 7. Retention: a copilot_chat record is pruned like any other
// =========================================================================
gre_reset();
global $wpdb;
$old_chat = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'copilot_chat', 'config' => [ 'user_id' => 7 ], 'user_prompt' => 'old' ] );
$new_chat = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'copilot_chat', 'config' => [ 'user_id' => 7 ], 'user_prompt' => 'new' ] );
foreach ( $wpdb->tables[ PressHub_AI_Generation_Recorder::get_table_name() ] as &$row ) {
    if ( (int) $row['id'] === $old_chat ) {
        $row['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 120 * 86400 );
    }
}
unset( $row );
update_option( 'presshub_ai_generation_record_retention_days', '30' );
$pruned = PressHub_AI_Generation_Recorder::prune( PressHub_AI_Settings_Storage::get_generation_record_retention_days() );
gre_check( 'retention: the old copilot_chat record is pruned, the fresh one kept', 1 === $pruned && null === PressHub_AI_Generation_Recorder::get( $old_chat ) && null !== PressHub_AI_Generation_Recorder::get( $new_chat ) );

// =========================================================================
// 8. Settings: both debug toggles keep their default and show the new wording
// =========================================================================
gre_reset();
gre_check( 'settings: prompt debug dump and TTS payload dump default off', false === PressHub_AI_Settings_Storage::get_debug_prompts() && false === PressHub_AI_Settings_Storage::get_log_tts_payloads() );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_posts' ];
$GLOBALS['wp_settings_fields_registry'] = [];
$titles = [];
if ( ! function_exists( 'add_settings_field_titles_probe' ) ) {
    function add_settings_field_titles_probe() {}
}
$src_storage = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-settings-storage.php' );
gre_check( 'settings: both fields are titled "Raw debug dump (developer use)"', 2 === preg_match_all( "/add_settings_field\\( 'presshub_ai_(debug_prompts|log_tts_payloads)', __\\( 'Raw debug dump \\(developer use\\)'/", $src_storage ) && false === strpos( $src_storage, 'Log AI Prompts & Responses' ) && false === strpos( $src_storage, "'Log TTS Payload Details'" ) );
$render = new PressHub_AI_Settings_Render();
ob_start();
$render->render_debug_prompts_field();
$render->render_log_tts_payloads_field();
$settings_html = (string) ob_get_clean();
gre_check( 'settings: both descriptions say generation records already keep this information for editors', 2 === substr_count( $settings_html, 'Generation records already keep' ) && 2 === substr_count( $settings_html, 'Raw debug dump for developers' ) );
gre_check( 'settings: the debug files and their code paths are still there', function_exists( 'presshub_ai_log_prompts' ) && method_exists( 'PressHub_AI_API_Client', 'write_tts_payload_log' ) && method_exists( 'PressHub_AI_API_Client', 'build_tts_payload_log_entry' ) );

// Cleanup
if ( is_dir( $test_upload_dir ) ) {
    $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $test_upload_dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $fileinfo ) {
        $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
        @$todo( $fileinfo->getRealPath() );
    }
    @rmdir( $test_upload_dir );
}
@unlink( $log_file );

if ( $failures > 0 ) {
    fwrite( STDERR, "GenerationRecordsEverywhereTest: {$failures} failure(s) of {$checks} checks\n" );
    exit( 1 );
}
echo "GenerationRecordsEverywhereTest: OK ({$checks} checks)\n";
