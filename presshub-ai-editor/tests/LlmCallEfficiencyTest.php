<?php
/**
 * LlmCallEfficiencyTest — fewer wasted LLM calls and tokens.
 *
 *  1. usage_extras(): cache and thinking token counts per provider format,
 *     only non-zero counts returned.
 *  2. A Gemini call logs cached and thinking tokens in the token-log metadata.
 *  3. analyze_article_for_image_prompt() runs as `generate_image_prompt`
 *     (not `coauthor_draft`), so it carries no policy-fiche block.
 *  4. The shared image library asks for images without the metadata LLM call
 *     and hands the plan's description along; an explicit caller value wins.
 *  5. The policy-fiche and stakeholder cron jobs skip a brief already
 *     processed or one being processed right now.
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

$failures = 0;
$checks   = 0;
function le_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$C = 'PressHub_AI_API_Client';

// 1. usage_extras().
le_check( 'extras: gemini cached + thoughts', [ 'cached_tokens' => 1200, 'thoughts_tokens' => 345 ] === $C::usage_extras( 'gemini', [ 'usageMetadata' => [ 'promptTokenCount' => 5000, 'cachedContentTokenCount' => 1200, 'thoughtsTokenCount' => 345 ] ] ) );
le_check( 'extras: openai cached + reasoning', [ 'cached_tokens' => 1024, 'reasoning_tokens' => 64 ] === $C::usage_extras( 'openai', [ 'usage' => [ 'prompt_tokens_details' => [ 'cached_tokens' => 1024 ], 'completion_tokens_details' => [ 'reasoning_tokens' => 64 ] ] ] ) );
le_check( 'extras: anthropic read + creation', [ 'cached_tokens' => 900, 'cache_creation_tokens' => 100 ] === $C::usage_extras( 'anthropic', [ 'usage' => [ 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 100 ] ] ) );
le_check( 'extras: zero counts left out', [] === $C::usage_extras( 'gemini', [ 'usageMetadata' => [ 'promptTokenCount' => 10, 'cachedContentTokenCount' => 0 ] ] ) );
le_check( 'extras: no usage block', [] === $C::usage_extras( 'openai', [] ) && [] === $C::usage_extras( 'gemini', null ) );

// 2 + 3. A Gemini image-prompt call: action and usage metadata in the token log.
update_option( 'presshub_ai_provider', 'gemini' );
update_option( 'presshub_ai_api_key', 'test-key' );
update_option( 'presshub_ai_gemini_api_key', 'test-key' );
update_option( 'presshub_ai_image_prompt_provider', 'gemini' );
$GLOBALS['CAPTURED_REQUESTS'] = [];
$GLOBALS['CAPTURE_FILTER']    = function ( $existing, $req ) {
    [ $url ] = $req;
    if ( false !== strpos( $url, ':generateContent' ) ) {
        return [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'candidates'    => [ [ 'content' => [ 'parts' => [ [ 'text' => 'A quiet harbour at dawn.' ] ] ], 'finishReason' => 'STOP' ] ],
                'usageMetadata' => [ 'promptTokenCount' => 3000, 'candidatesTokenCount' => 40, 'cachedContentTokenCount' => 2048, 'thoughtsTokenCount' => 300 ],
            ] ),
        ];
    }
    return null;
};
if ( class_exists( 'PressHub_AI_Token_Logger' ) && method_exists( 'PressHub_AI_Token_Logger', 'reset_last_llm_request' ) ) {
    PressHub_AI_Token_Logger::reset_last_llm_request();
}
$client = new PressHub_AI_API_Client();
$prompt = $client->analyze_article_for_image_prompt( 'Η Βουλή ψήφισε σήμερα τον νέο νόμο για τις συντάξεις.', 'Συντάξεις' );
$last   = PressHub_AI_Token_Logger::get_last_llm_request();
le_check( 'image prompt: call answered', 'A quiet harbour at dawn.' === $prompt );
le_check( 'image prompt: logged as generate_image_prompt, not coauthor_draft', is_array( $last ) && 'generate_image_prompt' === ( $last['action'] ?? '' ) );
$sent = '';
foreach ( (array) ( $GLOBALS['CAPTURED_REQUESTS'] ?? [] ) as $r ) {
    if ( false !== strpos( (string) $r[0], ':generateContent' ) ) {
        $sent = (string) ( $r[1]['body'] ?? '' );
    }
}
le_check( 'image prompt: request sent', '' !== $sent );
le_check( 'image prompt: no policy-fiche block in the request', false === strpos( $sent, 'ΦΑΚΕΛΟΙ ΠΟΛΙΤΙΚΩΝ' ) );
// Token-log metadata carries the cache and thinking counts.
$meta_ok = false;
global $wpdb;
foreach ( array_reverse( (array) ( $wpdb->queries ?? [] ) ) as $q ) {
    if ( is_array( $q ) && 'insert' === ( $q[0] ?? '' ) && isset( $q[2]['metadata'] ) && 'generate_image_prompt' === ( $q[2]['action_trigger'] ?? '' ) ) {
        $m       = json_decode( (string) $q[2]['metadata'], true );
        $meta_ok = is_array( $m ) && 2048 === ( $m['cached_tokens'] ?? 0 ) && 300 === ( $m['thoughts_tokens'] ?? 0 );
        break;
    }
}
le_check( 'gemini: cached and thinking tokens reach the token-log metadata', $meta_ok );
unset( $GLOBALS['CAPTURE_FILTER'] );

// 4. Image library: no metadata LLM call for plan-described images.
$seen = [];
PressHub_Social_Image_Library::set_generator( function ( $prompt, $aspect, $post_id, $context ) use ( &$seen ) {
    $seen[] = $context;
    return [ 'id' => 0, 'url' => 'https://example.test/img-' . count( $seen ) . '.png' ];
} );
$GLOBALS['POSTS_STORE'][501] = [ 'ID' => 501, 'post_content' => '<p>Κείμενο</p>', 'post_title' => 'Τίτλος', 'post_status' => 'publish' ];
$img = PressHub_Social_Image_Library::get_or_create( 501, 'A lighthouse on a rocky cape', 'editorial', '9:16', [ 'title' => 'Θέμα' ], 'tiktok_short' );
le_check( 'library: image created', is_array( $img ) && ! empty( $img['url'] ) );
le_check( 'library: asks without the metadata LLM call', isset( $seen[0]['ai_metadata'] ) && false === $seen[0]['ai_metadata'] );
le_check( 'library: passes the plan description and keeps the title', 'A lighthouse on a rocky cape' === ( $seen[0]['description'] ?? '' ) && 'Θέμα' === ( $seen[0]['title'] ?? '' ) );
PressHub_Social_Image_Library::get_or_create( 501, 'A boat in the harbour', 'editorial', '1:1', [ 'ai_metadata' => true ], 'post_images' );
le_check( 'library: an explicit caller value wins', true === ( $seen[1]['ai_metadata'] ?? null ) );
PressHub_Social_Image_Library::set_generator( null );
$fb = PressHub_AI_SEO::fallback_image_metadata( 'A lighthouse on a rocky cape. Dramatic sky.', 'Θέμα' );
le_check( 'fallback metadata: alt text from the description, title from the topic', 'A lighthouse on a rocky cape.' === $fb['alt_text'] && 'Θέμα' === $fb['title'] );

// 5. Cron guards.
$P = 'PressHub_AI_Policy_Fiches';
$S = 'PressHub_AI_Stakeholders';
$GLOBALS['POSTS_STORE'][601] = [ 'ID' => 601, 'post_content' => '<p>Ενημέρωση για τις συντάξεις.</p>', 'post_date' => '2026-11-18 07:00:00', 'post_status' => 'publish' ];
$hash = md5( 'Ενημέρωση για τις συντάξεις.' );
$GLOBALS['CAPTURED_REQUESTS'] = [];

update_post_meta( 601, $P::META_SOURCE_HASH, $hash );
$P::handle_update_cron( 601 );
le_check( 'policy cron: an already processed brief makes no call', empty( $GLOBALS['CAPTURED_REQUESTS'] ) && false === get_transient( 'presshub_ai_policy_update_lock_601' ) );

update_post_meta( 601, $P::META_SOURCE_HASH, '' );
set_transient( 'presshub_ai_policy_update_lock_601', time(), 600 );
$P::handle_update_cron( 601 );
le_check( 'policy cron: a brief being processed is skipped', empty( $GLOBALS['CAPTURED_REQUESTS'] ) && '' === (string) get_post_meta( 601, $P::META_SOURCE_HASH, true ) );
delete_transient( 'presshub_ai_policy_update_lock_601' );

update_post_meta( 601, $S::META_SOURCE_HASH, $hash );
$S::handle_cron( 601 );
le_check( 'stakeholder cron: an already processed brief makes no call', empty( $GLOBALS['CAPTURED_REQUESTS'] ) && false === get_transient( 'presshub_ai_stakeholders_lock_601' ) );

update_post_meta( 601, $S::META_SOURCE_HASH, '' );
set_transient( 'presshub_ai_stakeholders_lock_601', time(), 600 );
$S::handle_cron( 601 );
le_check( 'stakeholder cron: a brief being processed is skipped', empty( $GLOBALS['CAPTURED_REQUESTS'] ) && '' === (string) get_post_meta( 601, $S::META_SOURCE_HASH, true ) );
delete_transient( 'presshub_ai_stakeholders_lock_601' );

if ( $failures > 0 ) {
    echo "LlmCallEfficiencyTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "OK: LlmCallEfficiencyTest, all {$checks} checks passed\n";
exit( 0 );
