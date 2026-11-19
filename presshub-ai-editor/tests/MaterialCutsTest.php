<?php
/**
 * MaterialCutsTest — cuts made to the material of an AI call are recorded.
 *
 *  1. PressHub_AI_Knowledge_Cuts::clip_material() / note_material() /
 *     forget_material() / start_call(): a note belongs to the calls of its
 *     action only (every platform, every retry), the latest note for a piece
 *     of material wins, a text that fits drops it, stale notes are dropped.
 *  2. The generation record stores them as material_left_out and the
 *     inspector lists them under "Left out because of limits" → Material.
 *  3. Real cut sites: SEO excerpts, the Shorts article text, post images
 *     (a stored plan leaves nothing), social copy (every platform, template
 *     mode, podcast script), the URL fetcher (cuts go to the caller).
 *  4. Source checks for the copilot chat order and the other sites.
 *  5. Settings: "Do the published fiches fit?" per task.
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
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function mc_check( string $label, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}

$C = 'PressHub_AI_Knowledge_Cuts';
PressHub_AI_Generation_Recorder::create_table();

// 1. Collector: notes belong to the calls of one action.
$C::clear_pending();
$C::clear_last_material();
mc_check( 'clip: text that fits is unchanged and nothing is noted', 'abc' === $C::clip_material( 'coauthor_draft', 'abc', 10, 'X' ) && ( $C::start_call( 'coauthor_draft' ) || true ) && null === $C::last_material() );
$out = $C::clip_material( 'coauthor_draft', str_repeat( 'α', 20 ), 8, 'Article text', '...' );
mc_check( 'clip: cut to the limit with the suffix', str_repeat( 'α', 8 ) . '...' === $out );
$C::note_material( 'coauthor_draft', 'Source URLs', 'max_items', 5, 8 );
$C::start_call( 'classify_intent' );
mc_check( 'start_call: another action does not take the notes', null === $C::last_material() );
$C::start_call( 'coauthor_draft' );
$last = $C::last_material();
mc_check( 'start_call: both notes belong to the call', is_array( $last ) && 2 === count( $last ) && 'clipped' === $last[0]['reason'] && 20 === $last[0]['needed'] && 8 === $last[0]['kept'] && 'max_items' === $last[1]['reason'] );
mc_check( 'start_call: the time stamp and target are not kept', ! isset( $last[0]['t'] ) && ! isset( $last[0]['for'] ) );
$C::start_call( 'coauthor_draft' );
mc_check( 'start_call: the call takes its notes, the next call of the action starts clean', null === $C::last_material() );
$C::clip_material( 'coauthor_draft', str_repeat( 'x', 30 ), 10, 'Article text' );
$C::note_material( 'coauthor_draft', 'Source URLs', 'max_items', 5, 8 );
$C::clip_material( 'coauthor_draft', 'short now', 8000, 'Article text' );
$C::start_call( 'coauthor_draft' );
mc_check( 'clip: a text that fits replaces the earlier note', [ 'Source URLs' ] === array_column( (array) $C::last_material(), 'item' ) );
$C::clip_material( 'coauthor_draft', str_repeat( 'x', 30 ), 10, 'Article text' );
$C::clip_material( 'coauthor_draft', str_repeat( 'x', 40 ), 10, 'Article text' );
$C::start_call( 'coauthor_draft' );
$m = (array) $C::last_material();
mc_check( 'clip: the same material clipped twice is noted once, latest wins', 1 === count( $m ) && 40 === $m[0]['needed'] );
$C::note_material( 'coauthor_draft', 'Source URLs', 'max_items', 5, 8 );
$C::forget_material( 'coauthor_draft' );
$C::start_call( 'coauthor_draft' );
mc_check( 'forget: drops the notes of the action', null === $C::last_material() );
$C::clip_material( 'social_copy_', str_repeat( 'x', 30 ), 10, 'Post text' );
$C::start_call( 'social_copy_facebook' );
$fb = $C::last_material();
$C::start_call( 'social_copy_x' );
$xx = $C::last_material();
$C::start_call( 'social_tiktok_shorts' );
mc_check( 'prefix: every platform call records the cut, other actions do not', is_array( $fb ) && is_array( $xx ) && null === $C::last_material() );
$C::forget_material( 'social_copy_' );
$ref = new ReflectionProperty( $C, 'pending' );
$ref->setAccessible( true );
$ref->setValue( null, [ 'x|old' => [ 'for' => 'x', 'item' => 'old', 'reason' => 'clipped', 'limit' => 1, 'needed' => 2, 't' => time() - 3600 ] ] );
$C::start_call( 'x' );
mc_check( 'start_call: a stale note is dropped', null === $C::last_material() && [] === $ref->getValue() );
$C::clip_material( 'social_tiktok_shorts', str_repeat( 'x', 30 ), 10, 'Article text' );
$C::start_call( 'social_tiktok_shorts' );
$C::start_call( 'social_tiktok_shorts' );
mc_check( 'multi-call: a retry of a MULTI_CALL action records the cut too', is_array( $C::last_material() ) );
$C::forget_material( 'social_tiktok_shorts' );
$C::clear_pending();
for ( $i = 0; $i < 60; $i++ ) {
    $C::note_material( 'cap_test', 'Item ' . $i, 'max_items', 1, 2 );
}
$C::note_material( 'cap_test', 'Item 3', 'max_items', 1, 9 );
$C::start_call( 'cap_test' );
$m = (array) $C::last_material();
mc_check( 'cap: at most 50 notes are kept, an existing one can still be updated', 50 === count( $m ) && 9 === $m[3]['needed'] );
$lines = $C::describe( [ [ 'item' => 'Source URLs', 'reason' => 'max_items', 'limit' => 5, 'needed' => 8 ] ] );
mc_check( 'describe: max items with a count says how many were sent', [ 'Source URLs: only 5 of 8 sent, the rest left out' ] === $lines );

// 2. Record and inspector.
$C::clear_pending();
$t0 = PressHub_AI_Generation_Recorder::begin_call();
$C::clip_material( 'social_copy_', str_repeat( 'x', 30 ), 10, 'Post text' );
$C::start_call( 'social_copy_facebook' );
$rid = PressHub_AI_Generation_Recorder::finish_call( $t0, 'generic', null, 'S', 'U', 'OK' );
$rec = PressHub_AI_Generation_Recorder::get( (int) $rid );
$cfg = is_array( $rec['config'] ?? null ) ? $rec['config'] : json_decode( (string) ( $rec['config'] ?? '' ), true );
mc_check( 'record: material cut stored', ! empty( $cfg['material_left_out'] ) && 'Post text' === $cfg['material_left_out'][0]['item'] && 30 === $cfg['material_left_out'][0]['needed'] );
mc_check( 'record: consumed', null === $C::last_material() );
$html = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( (int) $rid ), 'overview' );
mc_check( 'inspector: Material under "Left out because of limits"', false !== strpos( $html, 'Left out because of limits' ) && false !== strpos( $html, 'Post text: cut to 10 of 30 characters (limit 10)' ) );
PressHub_AI_Generation_Recorder::begin_call();
$C::start_call( 'social_copy_x' );
PressHub_AI_Generation_Recorder::begin_call();
mc_check( 'begin_call: clears a material list nobody recorded', null === $C::last_material() );
$C::clear_pending();

// 3. Real cut sites.
$ex = new ReflectionMethod( 'PressHub_AI_SEO', 'excerpt' );
$ex->setAccessible( true );
$short = $ex->invoke( null, 'Σύντομο', 2000, 'generate_post_seo' );
$long  = $ex->invoke( null, str_repeat( 'λ', 2500 ), 2000, 'generate_post_seo' );
$C::start_call( 'generate_image_metadata' );
$other = $C::last_material();
$C::start_call( 'generate_post_seo' );
$m = $C::last_material();
mc_check( 'seo: a long excerpt is cut and recorded for its own call only', 'Σύντομο' === $short && 2003 === mb_strlen( $long ) && null === $other && is_array( $m ) && 1 === count( $m ) && 2500 === $m[0]['needed'] );
$ex->invoke( null, 'Σύντομο', 2000, 'generate_post_seo' );
$C::start_call( 'generate_post_seo' );
mc_check( 'seo: the next short excerpt drops the earlier cut', null === $C::last_material() );

$GLOBALS['POSTS_STORE'][ 4242 ] = [ 'ID' => 4242, 'post_content' => '<p>' . str_repeat( 'Κείμενο ', 3000 ) . '</p>', 'post_title' => 'T', 'post_status' => 'publish', 'post_type' => 'post' ];
$C::clear_pending();
$plain = PressHub_Social_TikTok_Shorts::article_text( 4242 );
$C::start_call( 'social_tiktok_shorts' );
mc_check( 'shorts: the source check does not record a cut', PressHub_Social_TikTok_Shorts::ARTICLE_TEXT_MAX_CHARS === mb_strlen( $plain ) && null === $C::last_material() );
$sent = PressHub_Social_TikTok_Shorts::article_text( 4242, true );
$C::start_call( 'classify_intent' );
$wrong = $C::last_material();
$C::start_call( 'social_tiktok_shorts' );
$m = $C::last_material();
mc_check( 'shorts: the text for the call records its cut, on the shorts call only', $plain === $sent && null === $wrong && is_array( $m ) && 'clipped' === $m[0]['reason'] && $m[0]['needed'] > PressHub_Social_TikTok_Shorts::ARTICLE_TEXT_MAX_CHARS );
$C::start_call( 'social_tiktok_shorts' );
mc_check( 'shorts: a retry of the plan records it too', is_array( $C::last_material() ) );
$C::clear_pending();

// Post images: the cut goes to the planning call; a stored plan (no call) drops it.
$ctx = new ReflectionMethod( 'PressHub_Social_Post_Images', 'article_context' );
$ctx->setAccessible( true );
$GLOBALS['POSTS_STORE'][ 4343 ] = [ 'ID' => 4343, 'post_content' => '<p>' . str_repeat( 'Λέξη ', 3000 ) . '</p>', 'post_title' => 'Εικόνες', 'post_status' => 'publish', 'post_type' => 'post' ];
$c1 = $ctx->invoke( null, 4343 );
$ctx->invoke( null, 4343 );
$C::start_call( 'coauthor_draft' );
$wrong = $C::last_material();
$C::start_call( 'social_post_images' );
$m = (array) $C::last_material();
mc_check( 'post images: the cut is noted once, for the planning call only', null === $wrong && 1 === count( $m ) && 'Post text' === $m[0]['item'] );
$prompt = PressHub_Social_Settings_Storage::get_post_images_prompt();
update_post_meta( 4343, PressHub_Social_Post_Images::PLAN_META, [ 'hash' => hash( 'sha256', $prompt . '|2|' . $c1['title'] . '|' . $c1['content'] ), 'images' => [ 'a', 'b' ], 'generated_at' => 'now' ] );
$plan = PressHub_Social_Post_Images::get_plan( 4343, 2 );
$C::start_call( 'social_post_images' );
mc_check( 'post images: a stored plan (no AI call) leaves no cut behind', '' !== $prompt && is_array( $plan ) && [ 'a', 'b' ] === $plan['images'] && null === $C::last_material() );
$C::clear_pending();

// Social copy: every platform records the cut; nothing is left once the post is done.
$GLOBALS['POSTS_STORE'][ 4444 ] = [ 'ID' => 4444, 'post_content' => '<p>' . str_repeat( 'Κείμενο άρθρου. ', 400 ) . '</p>', 'post_title' => 'Copy', 'post_excerpt' => 'Μια περίληψη του άρθρου για τα κοινωνικά δίκτυα.', 'post_status' => 'publish', 'post_type' => 'post' ];
PressHub_Social_AI_Copywriter::build_article_data( 4444 );
$C::start_call( 'social_copy_facebook' );
$fb = $C::last_material();
$C::start_call( 'social_copy_x' );
$xx = $C::last_material();
mc_check( 'copy: each platform call records the post text cut', is_array( $fb ) && is_array( $xx ) && 'Post text' === $fb[0]['item'] && 'Post text' === $xx[0]['item'] );
update_option( 'presshub_social_copy_mode', 'template' );
update_option( 'presshub_social_template_facebook', '{title} {url}' );
PressHub_Social_AI_Copywriter::generate_all_for_post( 4444, [ 'facebook' ] );
$C::start_call( 'social_copy_facebook' );
mc_check( 'copy: template mode (no AI call) leaves no cut behind', null === $C::last_material() );
delete_option( 'presshub_social_copy_mode' );
delete_option( 'presshub_social_template_facebook' );
update_post_meta( 4444, '_presshub_briefing_type', 'podcast' );
update_post_meta( 4444, '_presshub_podcast_script', str_repeat( "SPEAKER_A: Μια γραμμή του σεναρίου.\n", 200 ) );
$data = PressHub_Social_AI_Copywriter::build_article_data( 4444 );
$C::start_call( 'social_copy_facebook' );
$items = array_column( (array) $C::last_material(), 'item' );
mc_check( 'copy: a podcast script that replaces the post text records only the script', is_array( $data ) && [ 'Podcast script' ] === $items );
$C::clear_pending();

update_option( 'presshub_ai_fetch_urls', '1' );
$GLOBALS['FILTERS']['presshub_ai_enable_llm_extractor'] = [ [ 'callback' => '__return_false' ] ];
$big = '<html><head><title>Big</title></head><body><article><h1>Big</h1>' . str_repeat( '<p>' . str_repeat( 'Μια μεγάλη πρόταση για το άρθρο. ', 40 ) . '</p>', 60 ) . '</article></body></html>';
$GLOBALS['GET_RESPONSE_FILTER'] = static function ( $url ) use ( $big ) {
    return false !== strpos( $url, 'big.example' ) ? [ 'response' => [ 'code' => 200 ], 'body' => $big, 'headers' => [] ] : null;
};
$srcs = "Notes\nhttps://big.example/a\nhttps://n1.example/1\nhttps://n2.example/2\nhttps://n3.example/3\nhttps://n4.example/4\nhttps://n5.example/5";
PressHub_AI_URL_Fetcher::process_sources( $srcs, 'coauthor_draft' );
$C::start_call( 'url_extract' );
$wrong = $C::last_material();
$C::start_call( 'coauthor_draft' );
$m     = (array) $C::last_material();
$items = array_column( $m, 'reason', 'item' );
mc_check( 'urls: the cuts go to the caller, not to a page extraction call', null === $wrong );
mc_check( 'urls: the URL count cap is recorded', 'max_items' === ( $items['Source URLs'] ?? '' ) );
mc_check( 'urls: a page over the per-URL limit is recorded', 'clipped' === ( $items['Fetched page https://big.example/a'] ?? '' ) );
$C::start_call( 'coauthor_draft' );
mc_check( 'urls: the draft call takes the cuts; a later default-action call does not see them', null === $C::last_material() );
PressHub_AI_URL_Fetcher::process_sources( $srcs, 'coauthor_draft' );
PressHub_AI_URL_Fetcher::process_sources( "Notes only", 'coauthor_draft' );
$C::start_call( 'coauthor_draft' );
mc_check( 'urls: a later sources block without URLs drops the earlier cuts', null === $C::last_material() );
unset( $GLOBALS['GET_RESPONSE_FILTER'], $GLOBALS['FILTERS']['presshub_ai_enable_llm_extractor'] );
$C::clear_pending();

// Trending summary: each post text cut to 500 characters is noted for the
// summary call, the prompt text is unchanged, and the call drops the notes.
$T     = 'PressHub_AI_Social_Trending';
$items = [
    [ 'platform' => 'x', 'author_name' => 'Α', 'handle' => '', 'likes' => 1, 'comments' => 0, 'shares' => 0, 'text' => str_repeat( 'λόγια ', 200 ) ],
    [ 'platform' => 'x', 'author_name' => 'Β', 'handle' => '', 'likes' => 1, 'comments' => 0, 'shares' => 0, 'text' => 'Σύντομο.' ],
];
$txt = $T::summary_posts_text( $items );
$C::start_call( 'coauthor_draft' );
$wrong = $C::last_material();
$C::start_call( 'social_trending_summary' );
$m = (array) $C::last_material();
mc_check( 'trending: the long post is noted for the summary call only, the text keeps its shape', null === $wrong && 1 === count( $m ) && 'Trending post 1 (Α)' === $m[0]['item'] && $m[0]['kept'] <= 500 && $m[0]['kept'] >= 499 && false !== strpos( $txt, mb_substr( str_repeat( 'λόγια ', 200 ), 0, $m[0]['kept'] ) . '…' ) && 1199 === $m[0]['needed'] && false !== strpos( $txt, '…' ) && false !== strpos( $txt, '2. [' ) );
$seen = null;
$T::$summary_caller = static function () use ( $C, &$seen ) { $C::start_call( 'social_trending_summary' ); $seen = $C::last_material(); return '{"summary":"Σύνοψη."}'; };
$T::generate_summary( $items, 0 );
$T::$summary_caller = null;
$C::start_call( 'social_trending_summary' );
mc_check( 'trending: the summary call records the cut and leaves nothing behind', is_array( $seen ) && 1 === count( $seen ) && null === $C::last_material() );
$C::clear_pending();

// Stakeholders: more known people than the prompt lists is recorded with the call.
class MC_Stakeholder_Client extends PressHub_AI_API_Client {
    public $seen = null;
    public function __construct() {}
    public function set_action( string $action ): PressHub_AI_API_Client { return $this; }
    public function get_action(): string { return 'memory_stakeholders'; }
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        PressHub_AI_Knowledge_Cuts::start_call( 'memory_stakeholders' );
        $this->seen = PressHub_AI_Knowledge_Cuts::last_material();
        return json_encode( [ 'people' => [] ] );
    }
}
update_option( 'presshub_ai_memory_enabled', 1 );
$people = [];
for ( $i = 0; $i < 85; $i++ ) {
    $people[ 'p' . $i ] = [ 'name' => 'Πρόσωπο ' . $i, 'role' => '', 'last_seen' => '2026-11-14' ];
}
update_option( PressHub_AI_Stakeholders::OPTION, $people );
$GLOBALS['POSTS_STORE'][ 4545 ] = [ 'ID' => 4545, 'post_content' => '<p>Ενημέρωση.</p>', 'post_date' => '2026-11-15 07:00:00', 'post_status' => 'publish' ];
$sc = new MC_Stakeholder_Client();
PressHub_AI_Stakeholders::extract_for_post( 4545, $sc );
$C::start_call( 'memory_stakeholders' );
mc_check( 'stakeholders: the known people cap is recorded with the call, then dropped', is_array( $sc->seen ) && 'Known people' === $sc->seen[0]['item'] && 'max_items' === $sc->seen[0]['reason'] && 80 === $sc->seen[0]['limit'] && 85 === $sc->seen[0]['needed'] && null === $C::last_material() );
delete_option( PressHub_AI_Stakeholders::OPTION );
$C::clear_pending();

// Byte limits say bytes.
mc_check( 'describe: a byte limit says bytes', [ 'Article text: cut to 200000 of 250000 bytes (limit 200000)' ] === $C::describe( $C::sanitize( [ [ 'item' => 'Article text', 'reason' => 'clipped', 'limit' => 200000, 'needed' => 250000, 'kept' => 200000, 'unit' => 'bytes' ] ] ) ) );

// 4. Source checks for the sites a unit test cannot reach.
$root  = dirname( __DIR__ ) . '/includes/';
$ajax  = (string) file_get_contents( $root . 'class-ajax-handlers.php' );
$p_cls = strpos( $ajax, '$api->classify_intent( $prompt )' );
$p_not = strpos( $ajax, "note_material( \$cut_for, __( 'Article text', 'presshub-ai-editor' ), 'clipped', 200000, \$article_bytes_cut, 200000, 'bytes' )" );
$p_cal = false === $p_not ? false : strpos( $ajax, '$api->call_provider( $sys, $prompt', $p_not );
mc_check( 'source: copilot notes the article cut after intent classification, right before the chat call', false !== $p_cls && false !== $p_not && $p_not > $p_cls && false !== $p_cal && $p_cal - $p_not < 400 );
mc_check( 'source: image prompt notes for its own action', false !== strpos( (string) file_get_contents( $root . 'class-api-client.php' ), "clip_material( 'generate_image_prompt', \$clean_content, 8000" ) );
mc_check( 'source: url_extract notes its HTML cut for itself', false !== strpos( (string) file_get_contents( $root . 'class-url-fetcher.php' ), "clip_material( 'url_extract', (string) \$stripped_html, 25000" ) );
mc_check( 'source: the draft passes its action to the URL fetcher', false !== strpos( (string) file_get_contents( $root . 'class-api-client.php' ), 'process_sources( $sources, $this->get_action() )' ) );
$shorts = (string) file_get_contents( $root . 'class-social-tiktok-shorts.php' );
mc_check( 'source: a stored shorts plan drops the cut', 1 === preg_match( "/PLAN_META, true \);\s*if \( is_array\( \\\$stored \).*?forget_material\( 'social_tiktok_shorts' \);\s*}\s*return \\\$stored;/s", $shorts ) );
mc_check( 'source: the shorts plan drops the cut once its attempts are over', 1 === preg_match( "/try \{\s*for \( \\\$attempt = 1;.*?\} finally \{\s*if \( class_exists\( 'PressHub_AI_Knowledge_Cuts' \) \) \{\s*PressHub_AI_Knowledge_Cuts::forget_material\( 'social_tiktok_shorts' \);/s", $shorts ) );
$pimg = (string) file_get_contents( $root . 'class-social-post-images.php' );
mc_check( 'post images keep their cut for every planning attempt (multi-call)', in_array( 'social_post_images', PressHub_AI_Knowledge_Cuts::MULTI_CALL, true ) );
mc_check( 'source: post images drop the cut once the planning attempts are over and after the library context', 1 === preg_match( "/try \{\s*for \( \\\$attempt = 1;.*?\} finally \{\s*self::forget_cuts\(\);/s", $pimg ) &&false !== strpos( $pimg, "\$context = self::article_context( \$post_id );\n        self::forget_cuts(); // the image library" ) );
mc_check( 'source: call_provider starts the call', false !== strpos( (string) file_get_contents( $root . 'class-api-client.php' ), 'PressHub_AI_Knowledge_Cuts::start_call( $this->get_action() )' ) );
foreach ( [ 'class-social-trending.php' => 'the briefing count left out', 'class-press-release-inbox.php' => 'per-check message limit', 'class-social-monitor.php' => 'the briefing limit left out' ] as $file => $needle ) {
    mc_check( "source: {$file} logs its cut", false !== strpos( (string) file_get_contents( $root . $file ), $needle ) );
}

// 5. Settings fit indicator.
$P = 'PressHub_AI_Policy_Fiches';
delete_option( $P::OPTION );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts' ];
$a = $P::create( 'Σύντομος φάκελος', [ 'aliases' => [ 'σύντομος' ] ] );
$P::save_core( $a, [ 'title' => 'Σύντομος φάκελος', 'aliases' => [ 'σύντομος' ], 'framing' => 'Ένας κανόνας.', 'status' => 'published' ], 1 );
$b = $P::create( 'Μακρύς φάκελος', [ 'aliases' => [ 'μακρύς' ] ] );
$P::save_core( $b, [ 'title' => 'Μακρύς φάκελος', 'aliases' => [ 'μακρύς' ], 'framing' => str_repeat( 'Μακρύς κανόνας πλαισίωσης. ', 70 ), 'status' => 'published' ], 1 );
$fit = $P::fit_report();
mc_check( 'fit: briefing fits both', 2 === $fit['briefing_curation']['fit'] && 2 === $fit['briefing_curation']['total'] );
mc_check( 'fit: social copy names the long fiche', 1 === $fit['social_copy_']['fit'] && [ 'Μακρύς φάκελος' ] === $fit['social_copy_']['too_long'] );
$fit_html = PressHub_AI_Settings_Render::policy_fit_html( $fit, [ 'social_copy_' => 'Social copy', 'briefing_curation' => 'Briefing curation' ] );
mc_check( 'fit html: heading and per-task lines', false !== strpos( $fit_html, 'Do the published fiches fit?' ) && false !== strpos( $fit_html, 'Briefing curation: all 2 published fiches fit' ) && false !== strpos( $fit_html, 'Social copy: 1 of 2 fit; too long: Μακρύς φάκελος' ) );
$c = $P::create( 'Φάκελος αρχών', [ 'aliases' => [ 'αρχών φάκελος' ] ] );
$P::save_core( $c, [ 'title' => 'Φάκελος αρχών', 'aliases' => [ 'αρχών φάκελος' ], 'principles' => 'Μόνο αρχές.', 'status' => 'published' ], 1 );
$fit = $P::fit_report();
mc_check( 'fit: a fiche with nothing for the task is not called too long', [ 'Φάκελος αρχών' ] === $fit['social_copy_']['empty'] && [ 'Μακρύς φάκελος' ] === $fit['social_copy_']['too_long'] && 3 === $fit['briefing_curation']['fit'] );
$fit_html = PressHub_AI_Settings_Render::policy_fit_html( $fit, [ 'social_copy_' => 'Social copy' ] );
mc_check( 'fit html: says which fiches have nothing to send', false !== strpos( $fit_html, 'Social copy: 1 of 2 fit; too long: Μακρύς φάκελος; nothing to send here: Φάκελος αρχών' ) && false !== strpos( $fit_html, 'at most ' . $P::MAX_INJECTED . ' are sent' ) );
$only = [ 'social_copy_' => [ 'budget' => 1200, 'fit' => 0, 'total' => 1, 'too_long' => [], 'empty' => [ 'Φάκελος αρχών' ] ] ];
mc_check( 'fit html: no fiche with text for the task says so', false !== strpos( PressHub_AI_Settings_Render::policy_fit_html( $only, [ 'social_copy_' => 'Social copy' ] ), 'Social copy: no published fiche has text for this task' ) );
$esc = [ 'social_copy_' => [ 'budget' => 10, 'fit' => 0, 'total' => 1, 'too_long' => [ '<b>Τίτλος</b>' ], 'empty' => [] ] ];
$esc_html = PressHub_AI_Settings_Render::policy_fit_html( $esc, [ 'social_copy_' => '<i>Task</i>' ] );
mc_check( 'fit html: titles and task names are escaped', false === strpos( $esc_html, '<b>' ) && false === strpos( $esc_html, '<i>' ) && false !== strpos( $esc_html, '&lt;b&gt;Τίτλος&lt;/b&gt;' ) );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( $P::BUDGETS, [ 'social_copy_' => 0 ] ) );
mc_check( 'fit: a task switched off lists no fiche as too long', [] === $P::fit_report()['social_copy_']['too_long'] );
mc_check( 'fit html: a task switched off says so', false !== strpos( PressHub_AI_Settings_Render::policy_fit_html( $P::fit_report(), [ 'social_copy_' => 'Social copy' ] ), 'Social copy: off (limit 0)' ) );
delete_option( 'presshub_ai_policy_fiches_budgets' );
delete_option( $P::OPTION );
mc_check( 'fit html: no published fiches', false !== strpos( PressHub_AI_Settings_Render::policy_fit_html( $P::fit_report(), [] ), 'No published policy fiches yet' ) );

// Trending AI roundup image (#314): the texts are cut when the job is queued
// (10 posts, 300 characters each) and the cuts are recorded on the image
// prompt call that runs later, in the cron request.
$tr_texts = new ReflectionMethod( 'PressHub_AI_Social_Trending', 'ai_image_texts' );
$tr_texts->setAccessible( true );
$tr_items = [];
for ( $i = 0; $i < 12; $i++ ) {
    $tr_items[] = [ 'text' => 0 === $i ? str_repeat( 'λ', 450 ) : 'Ανάρτηση ' . $i ];
}
$tr_cuts = [];
$tr_out  = $tr_texts->invokeArgs( null, [ $tr_items, &$tr_cuts ] );
mc_check( 'trending image: 10 texts sent, the long one cut to 300', 10 === count( $tr_out ) && 301 === mb_strlen( $tr_out[0] ) );
mc_check( 'trending image: the cut text and the post count limit are both listed', 2 === count( $tr_cuts ) && 'clipped' === $tr_cuts[0]['reason'] && 450 === $tr_cuts[0]['needed'] && 300 === $tr_cuts[0]['kept'] && 'max_items' === $tr_cuts[1]['reason'] && 12 === $tr_cuts[1]['needed'] );
$tr_none = [];
$tr_texts->invokeArgs( null, [ [ [ 'text' => 'σύντομο' ] ], &$tr_none ] );
mc_check( 'trending image: nothing cut, nothing listed', [] === $tr_none );
update_post_meta( 4242, PressHub_AI_Social_Trending::AI_IMAGE_CUTS_META, $tr_cuts );
$tr_note = new ReflectionMethod( 'PressHub_AI_Social_Trending', 'note_ai_image_cuts' );
$tr_note->setAccessible( true );
$tr_note->invoke( null, 4242 );
$C::start_call( 'something_else' );
mc_check( 'trending image: the cuts are not taken by another call', null === $C::last_material() );
$C::start_call( 'generate_image_prompt' );
$tr_last = $C::last_material();
mc_check( 'trending image: the image prompt call takes both cuts', is_array( $tr_last ) && 2 === count( $tr_last ) );
$C::start_call( 'generate_image_prompt' );
mc_check( 'trending image: the next image prompt call starts clean', null === $C::last_material() );

if ( $failures ) {
    echo "MaterialCutsTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "MaterialCutsTest: OK ({$checks} checks)\n";
