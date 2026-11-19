<?php
/**
 * MaterialCutsTest — cuts made to the material of an AI call are recorded.
 *
 *  1. PressHub_AI_Knowledge_Cuts::clip_material() / note_material() /
 *     start_call() / last_material(): noted, moved to the call, stale notes
 *     dropped, nothing noted when the text fits.
 *  2. The generation record stores them as material_left_out and the
 *     inspector lists them under "Left out because of limits" → Material.
 *  3. Real cut sites: SEO excerpts, the Shorts article text (only when it
 *     goes into the call), the URL fetcher's URL count and page length.
 *  4. Every listed cut site goes through the recorder (source check).
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

// 1. Collector.
$C::clear_pending();
$C::clear_last_material();
mc_check( 'clip: text that fits is unchanged and nothing is noted', 'abc' === $C::clip_material( 'abc', 10, 'X' ) && ( $C::start_call( 'a' ) || true ) && null === $C::last_material() );
$out = $C::clip_material( str_repeat( 'α', 20 ), 8, 'Article text', '...' );
mc_check( 'clip: cut to the limit with the suffix', str_repeat( 'α', 8 ) . '...' === $out );
$C::note_material( 'Source URLs', 'max_items', 5, 8 );
$C::start_call( 'coauthor_draft' );
$last = $C::last_material();
mc_check( 'start_call: both notes belong to the call', is_array( $last ) && 2 === count( $last ) && 'clipped' === $last[0]['reason'] && 20 === $last[0]['needed'] && 8 === $last[0]['kept'] && 'max_items' === $last[1]['reason'] );
mc_check( 'start_call: the time stamp is not kept', ! isset( $last[0]['t'] ) );
$C::start_call( 'coauthor_draft' );
mc_check( 'start_call: the next call starts clean', null === $C::last_material() );
$ref = new ReflectionProperty( $C, 'pending' );
$ref->setAccessible( true );
$ref->setValue( null, [ [ 'item' => 'old', 'reason' => 'clipped', 'limit' => 1, 'needed' => 2, 't' => time() - 3600 ] ] );
$C::start_call( 'x' );
mc_check( 'start_call: a stale note from a call that never ran is dropped', null === $C::last_material() );
$lines = $C::describe( [ [ 'item' => 'Source URLs', 'reason' => 'max_items', 'limit' => 5, 'needed' => 8 ] ] );
mc_check( 'describe: max items with a count says how many were sent', [ 'Source URLs: only 5 of 8 sent, the rest left out' ] === $lines );

// 2. Record and inspector.
$t0 = PressHub_AI_Generation_Recorder::begin_call();
$C::clip_material( str_repeat( 'x', 30 ), 10, 'Post text' );
$C::start_call( 'social_copy_facebook' );
$rid = PressHub_AI_Generation_Recorder::finish_call( $t0, 'generic', null, 'S', 'U', 'OK' );
$rec = PressHub_AI_Generation_Recorder::get( (int) $rid );
$cfg = is_array( $rec['config'] ?? null ) ? $rec['config'] : json_decode( (string) ( $rec['config'] ?? '' ), true );
mc_check( 'record: material cut stored', ! empty( $cfg['material_left_out'] ) && 'Post text' === $cfg['material_left_out'][0]['item'] && 30 === $cfg['material_left_out'][0]['needed'] );
mc_check( 'record: consumed', null === $C::last_material() );
$html = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( (int) $rid ), 'overview' );
mc_check( 'inspector: Material under "Left out because of limits"', false !== strpos( $html, 'Left out because of limits' ) && false !== strpos( $html, 'Post text: cut to 10 of 30 characters (limit 10)' ) );
PressHub_AI_Generation_Recorder::begin_call();
$C::clip_material( str_repeat( 'x', 30 ), 10, 'Post text' );
$C::start_call( 'a' );
PressHub_AI_Generation_Recorder::begin_call();
mc_check( 'begin_call: clears a material list nobody recorded', null === $C::last_material() );

// 3. Real cut sites.
$C::clear_pending();
$ex = new ReflectionMethod( 'PressHub_AI_SEO', 'excerpt' );
$ex->setAccessible( true );
$short = $ex->invoke( null, 'Σύντομο', 2000 );
$long  = $ex->invoke( null, str_repeat( 'λ', 2500 ), 2000 );
$C::start_call( 'seo' );
$m = $C::last_material();
mc_check( 'seo: a short excerpt is unchanged, a long one is cut and recorded once', 'Σύντομο' === $short && 2003 === mb_strlen( $long ) && is_array( $m ) && 1 === count( $m ) && 2500 === $m[0]['needed'] );

$GLOBALS['POSTS_STORE'][ 4242 ] = [ 'ID' => 4242, 'post_content' => '<p>' . str_repeat( 'Κείμενο ', 3000 ) . '</p>', 'post_title' => 'T', 'post_status' => 'publish', 'post_type' => 'post' ];
$C::clear_pending();
$plain = PressHub_Social_TikTok_Shorts::article_text( 4242 );
$C::start_call( 'probe' );
mc_check( 'shorts: the source check does not record a cut', PressHub_Social_TikTok_Shorts::ARTICLE_TEXT_MAX_CHARS === mb_strlen( $plain ) && null === $C::last_material() );
$sent = PressHub_Social_TikTok_Shorts::article_text( 4242, true );
$C::start_call( 'social_tiktok_shorts' );
$m = $C::last_material();
mc_check( 'shorts: the text for the call records its cut', $plain === $sent && is_array( $m ) && 'clipped' === $m[0]['reason'] && $m[0]['needed'] > PressHub_Social_TikTok_Shorts::ARTICLE_TEXT_MAX_CHARS );

update_option( 'presshub_ai_fetch_urls', '1' );
$GLOBALS['FILTERS']['presshub_ai_enable_llm_extractor'] = [ [ 'callback' => '__return_false' ] ];
$big = '<html><head><title>Big</title></head><body><article><h1>Big</h1>' . str_repeat( '<p>' . str_repeat( 'Μια μεγάλη πρόταση για το άρθρο. ', 40 ) . '</p>', 60 ) . '</article></body></html>';
$GLOBALS['GET_RESPONSE_FILTER'] = static function ( $url ) use ( $big ) {
    return false !== strpos( $url, 'big.example' ) ? [ 'response' => [ 'code' => 200 ], 'body' => $big, 'headers' => [] ] : null;
};
$C::clear_pending();
$srcs = "Notes\nhttps://big.example/a\nhttps://n1.example/1\nhttps://n2.example/2\nhttps://n3.example/3\nhttps://n4.example/4\nhttps://n5.example/5";
PressHub_AI_URL_Fetcher::process_sources( $srcs );
$C::start_call( 'coauthor_draft' );
$m     = (array) $C::last_material();
$items = array_column( $m, 'reason', 'item' );
mc_check( 'urls: the URL count cap is recorded', 'max_items' === ( $items['Source URLs'] ?? '' ) );
mc_check( 'urls: a page over the per-URL limit is recorded', 'clipped' === ( $items['Fetched page https://big.example/a'] ?? '' ) );
unset( $GLOBALS['GET_RESPONSE_FILTER'], $GLOBALS['FILTERS']['presshub_ai_enable_llm_extractor'] );

// 4. Source check: every listed cut site records through the collector.
$root  = dirname( __DIR__ ) . '/includes/';
$sites = [
    'class-ajax-handlers.php'         => 'note_material( __( \'Article text (bytes)\'',
    'class-api-client.php'            => 'clip_material( $clean_content, 8000',
    'class-social-ai-copywriter.php'  => 'clip_material( $cleaned, 4000',
    'class-social-post-images.php'    => 'clip_material( $content, self::CONTENT_LIMIT',
    'class-url-fetcher.php'           => 'clip_material( (string) $stripped_html, 25000',
];
foreach ( $sites as $file => $needle ) {
    mc_check( "source: {$file} records its cut", false !== strpos( (string) file_get_contents( $root . $file ), $needle ) );
}
mc_check( 'source: social copywriter post text cut recorded', false !== strpos( (string) file_get_contents( $root . 'class-social-ai-copywriter.php' ), "clip_material( \$content, 3000" ) );
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
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( $P::BUDGETS, [ 'social_copy_' => 0 ] ) );
mc_check( 'fit html: a task switched off says so', false !== strpos( PressHub_AI_Settings_Render::policy_fit_html( $P::fit_report(), [ 'social_copy_' => 'Social copy' ] ), 'Social copy: off (limit 0)' ) );
delete_option( 'presshub_ai_policy_fiches_budgets' );
delete_option( $P::OPTION );
mc_check( 'fit html: no published fiches', false !== strpos( PressHub_AI_Settings_Render::policy_fit_html( $P::fit_report(), [] ), 'No published policy fiches yet' ) );

if ( $failures ) {
    echo "MaterialCutsTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "MaterialCutsTest: OK ({$checks} checks)\n";
