<?php
/**
 * PodcastMp3Test — WAV podcast episodes converted to MP3 by the worker.
 *
 * Test cases:
 *   1. Settings: switch default on, bitrate default 64 and clamped to the offered values.
 *   2. queue(): only WAV, only with the switch on.
 *   3. claim_next(): oldest first, attempts counted, claimed entry not handed out twice,
 *      handed out again after CLAIM_TIMEOUT, given up after MAX_ATTEMPTS, superseded audio dropped.
 *   4. complete(): refuses unclaimed, superseded and non-MP3 uploads; swaps the player,
 *      the audio meta and the block id; schedules the WAV deletion; leaves the queue.
 *   5. record_failure(): retried, then failed.
 *   6. delete_wav(): deletes only a replaced WAV.
 *   7. Synthesizer queues a WAV episode; routes registered.
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
if ( ! function_exists( 'wp_delete_attachment' ) ) {
    function wp_delete_attachment( $id, $force = false ) {
        $GLOBALS['DELETED_ATTACHMENTS'][] = (int) $id;
        return true;
    }
}
if ( ! function_exists( 'get_attached_file' ) ) {
    function get_attached_file( $id ) {
        return $GLOBALS['ATTACHED_FILES'][ (int) $id ] ?? '';
    }
}
if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $ns, $route, $args ) {
        $GLOBALS['REST_ROUTES'][ $ns . $route ] = $args;
        return true;
    }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
function mp3_check( string $label, $condition ): void {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

/** $wpdb double: post_content updates land in POSTS_STORE, and every call is recorded. */
class Mp3_Test_WPDB extends PressHub_Test_WPDB {
    public $posts = 'wp_posts';
    public $last_error = '';
    public $fail_next = false;
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->queries[] = [ 'update', $table, $data, $where ];
        if ( $this->fail_next ) {
            $this->fail_next  = false;
            $this->last_error = 'Lost connection';
            return false;
        }
        if ( 'wp_posts' === $table && isset( $GLOBALS['POSTS_STORE'][ $where['ID'] ] ) ) {
            foreach ( $data as $k => $v ) {
                $GLOBALS['POSTS_STORE'][ $where['ID'] ][ $k ] = $v;
            }
            return 1;
        }
        return 0;
    }
}

/** Runs $fn when the sideload stub records its file, i.e. mid-sideload. */
class Mp3_Sideload_Spy extends ArrayObject {
    public $fn;
    public function offsetSet( $key, $value ): void {
        parent::offsetSet( $key, $value );
        if ( $this->fn ) {
            ( $this->fn )();
        }
    }
}

$M = 'PressHub_AI_Podcast_Mp3';
$S = 'PressHub_AI_Settings_Storage';

function mp3_reset(): void {
    $GLOBALS['OPTIONS_STORE']       = [];
    $GLOBALS['POST_META_STORE']     = [];
    $GLOBALS['POSTS_STORE']         = [];
    $GLOBALS['SCHEDULED_EVENTS']    = [];
    $GLOBALS['DELETED_ATTACHMENTS'] = [];
    $GLOBALS['ATTACHED_FILES']      = [];
    unset( $GLOBALS['SIDELOAD_FAIL'], $GLOBALS['SIDELOAD_RETURN_ID'], $GLOBALS['SIDELOAD_CAPTURE'] );
    $GLOBALS['wpdb'] = new Mp3_Test_WPDB();
}

/** A podcast post playing WAV attachment $att. */
function mp3_plant_post( int $post_id, int $att, string $url ): void {
    $GLOBALS['POSTS_STORE'][ $post_id ] = [
        'ID'           => $post_id,
        'post_title'   => 'Πρωινό Podcast Ενημέρωσης – 19/11/2026',
        'post_status'  => 'publish',
        'post_content' => "<p class=\"presshub-podcast-lead\">Lead.</p>\n\n<!-- wp:audio {\"id\":{$att}} -->\n<figure class=\"wp-block-audio\"><audio controls src=\"{$url}\"></audio></figure>\n<!-- /wp:audio -->\n\n<p>Show notes for attachment {$att}.</p>",
    ];
    $GLOBALS['POSTS_STORE'][ $att ] = [ 'ID' => $att, 'post_title' => 'Podcast WAV ' . $att ];
    $GLOBALS['POST_META_STORE'][ $post_id ]['_presshub_audio_attachment_id'] = $att;
    $GLOBALS['POST_META_STORE'][ $post_id ]['_presshub_audio_url']           = $url;
}

$mp3 = "ID3\x04\x00\x00\x00\x00\x00\x00" . str_repeat( "\xFF\xF3\x84\xC4" . str_repeat( "\x00", 140 ), 20 );
$wav_url = 'https://presshub.test/wp-content/uploads/2026/11/podcast-briefing-2026-11-19-abc.wav';

// 1. Settings.
mp3_reset();
mp3_check( 'settings: switch on by default', true === $S::get_podcast_mp3_enabled() );
mp3_check( 'settings: bitrate 64 by default', 64 === $S::get_podcast_mp3_bitrate() );
mp3_check( 'settings: bitrate 96 kept', 96 === $S::sanitize_podcast_mp3_bitrate( '96' ) );
mp3_check( 'settings: bitrate 70 becomes the default', 64 === $S::sanitize_podcast_mp3_bitrate( 70 ) );
mp3_check( 'settings: bitrate junk becomes the default', 64 === $S::sanitize_podcast_mp3_bitrate( 'abc' ) );
$reg = require dirname( __DIR__ ) . '/includes/settings-registry-data.php';
mp3_check( 'settings: registry default equals the getter default', 64 === ( $reg['presshub_ai_podcast_mp3_bitrate']['default'] ?? null ) && 1 === ( $reg['presshub_ai_podcast_mp3_enabled']['default'] ?? null ) );

// 2. queue().
mp3_check( 'queue: WAV URL recognised', $M::is_wav_url( $wav_url ) && $M::is_wav_url( $wav_url . '?ver=2' ) );
mp3_check( 'queue: MP3 URL not WAV', ! $M::is_wav_url( 'https://presshub.test/a.mp3' ) );
mp3_check( 'queue: MP3 episode not queued', false === $M::queue( 10, 20, 'https://presshub.test/a.mp3' ) && [] === $M::get_queue() );
update_option( 'presshub_ai_podcast_mp3_enabled', 0 );
mp3_check( 'queue: switch off, not queued', false === $M::queue( 10, 20, $wav_url ) && [] === $M::get_queue() );
update_option( 'presshub_ai_podcast_mp3_enabled', 1 );
mp3_check( 'queue: WAV episode queued', true === $M::queue( 10, 20, $wav_url ) && 'pending' === ( $M::get_queue()[10]['status'] ?? '' ) );

// 3. claim_next().
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
mp3_plant_post( 11, 21, str_replace( 'abc', 'def', $wav_url ) );
$M::queue( 11, 21, str_replace( 'abc', 'def', $wav_url ) );
$q = $M::get_queue();
$q[11]['queued_at'] = time() - 10;
update_option( $M::QUEUE_OPTION, $q );
$M::queue( 10, 20, $wav_url );
update_option( 'presshub_ai_podcast_mp3_bitrate', 96 );
$job = $M::claim_next();
mp3_check( 'claim: oldest first', 11 === ( $job['post_id'] ?? 0 ) && 21 === ( $job['attachment_id'] ?? 0 ) );
mp3_check( 'claim: bitrate and attempt sent', 96 === ( $job['bitrate_kbps'] ?? 0 ) && 1 === ( $job['attempt'] ?? 0 ) );
$job2 = $M::claim_next();
mp3_check( 'claim: a claimed entry is not handed out twice', 10 === ( $job2['post_id'] ?? 0 ) );
mp3_check( 'claim: nothing left', null === $M::claim_next() );
$q = $M::get_queue();
$q[11]['claimed_at'] = time() - $M::CLAIM_TIMEOUT - 1;
update_option( $M::QUEUE_OPTION, $q );
$job3 = $M::claim_next();
mp3_check( 'claim: handed out again after the timeout', 11 === ( $job3['post_id'] ?? 0 ) && 2 === ( $job3['attempt'] ?? 0 ) );
$q = $M::get_queue();
$q[11]['claimed_at'] = time() - $M::CLAIM_TIMEOUT - 1;
$q[11]['attempts']   = $M::MAX_ATTEMPTS;
update_option( $M::QUEUE_OPTION, $q );
mp3_check( 'claim: given up after the last attempt', null === $M::claim_next() && 'failed' === ( $M::get_queue()[11]['status'] ?? '' ) );
update_option( 'presshub_ai_podcast_mp3_enabled', 0 );
mp3_check( 'claim: switch off hands out nothing', null === $M::claim_next() );
update_option( 'presshub_ai_podcast_mp3_enabled', 1 );
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$M::queue( 10, 20, $wav_url );
$GLOBALS['POST_META_STORE'][10]['_presshub_audio_attachment_id'] = 30; // regenerated since
mp3_check( 'claim: superseded audio dropped', null === $M::claim_next() && [] === $M::get_queue() );

// 4. complete().
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$M::queue( 10, 20, $wav_url );
$r = $M::complete( 10, 20, $mp3 );
mp3_check( 'complete: refused before a claim', is_wp_error( $r ) && 'mp3_not_claimed' === $r->get_error_code() );
$M::claim_next();
$r = $M::complete( 10, 99, $mp3 );
mp3_check( 'complete: refused for another attachment', is_wp_error( $r ) && 'mp3_not_claimed' === $r->get_error_code() );
$r = $M::complete( 10, 20, str_repeat( 'x', 4096 ) );
mp3_check( 'complete: a non-MP3 upload refused and counted as a failure', is_wp_error( $r ) && 'mp3_invalid' === $r->get_error_code() && 'pending' === ( $M::get_queue()[10]['status'] ?? '' ) );
mp3_check( 'complete: post untouched after a refusal', $wav_url === get_post_meta( 10, '_presshub_audio_url', true ) );
$M::claim_next();
$GLOBALS['SIDELOAD_CAPTURE']   = [];
$GLOBALS['SIDELOAD_RETURN_ID'] = 77;
$r = $M::complete( 10, 20, $mp3 );
$new_url = wp_get_attachment_url( 77 );
mp3_check( 'complete: succeeds', is_array( $r ) && 77 === $r['attachment_id'] && strlen( $mp3 ) === $r['bytes'] );
mp3_check( 'complete: sideloaded as .mp3 named after the WAV', 'podcast-briefing-2026-11-19-abc.mp3' === ( $GLOBALS['SIDELOAD_CAPTURE'][0]['name'] ?? '' ) );
$content = (string) $GLOBALS['POSTS_STORE'][10]['post_content'];
mp3_check( 'complete: player plays the MP3', false !== strpos( $content, 'src="' . $new_url . '"' ) && false === strpos( $content, $wav_url ) );
mp3_check( 'complete: block id updated', false !== strpos( $content, '<!-- wp:audio {"id":77} -->' ) );
mp3_check( 'complete: other text unchanged', false !== strpos( $content, 'Show notes for attachment 20.' ) && false !== strpos( $content, 'Lead.' ) );
mp3_check( 'complete: meta points at the MP3', 77 === get_post_meta( 10, '_presshub_audio_attachment_id', true ) && $new_url === get_post_meta( 10, '_presshub_audio_url', true ) && 'mp3' === get_post_meta( 10, '_presshub_audio_format', true ) );
mp3_check( 'complete: WAV remembered', 20 === get_post_meta( 10, '_presshub_audio_wav_attachment_id', true ) );
$ev = $GLOBALS['SCHEDULED_EVENTS'][0] ?? [];
mp3_check( 'complete: WAV deletion scheduled a day later', $M::DELETE_WAV_HOOK === ( $ev['hook'] ?? '' ) && [ 20, 10 ] === ( $ev['args'] ?? [] ) && ( $ev['time'] ?? 0 ) >= time() + $M::WAV_DELETE_DELAY - 5 );
mp3_check( 'complete: entry leaves the queue', [] === $M::get_queue() );
mp3_check( 'complete: nothing deleted yet', [] === $GLOBALS['DELETED_ATTACHMENTS'] );
$upd = array_values( array_filter( $GLOBALS['wpdb']->queries, function ( $q ) { return 'update' === $q[0] && 'wp_posts' === $q[1]; } ) );
mp3_check( 'complete: only post_content written, straight to the posts table', 1 === count( $upd ) && [ 'post_content' ] === array_keys( $upd[0][2] ) && [ 'ID' => 10 ] === $upd[0][3] );

// The QA gate's review and override follow the new content, but only when they matched the old one.
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$GLOBALS['POST_META_STORE'][10]['_presshub_briefing_type'] = 'podcast';
$old_hash = PressHub_AI_Publish_Gate::input_hash_for_post( get_post( 10 ), 'podcast' );
$GLOBALS['POST_META_STORE'][10][ PressHub_AI_Publish_Gate::META_REVIEW ]   = [ 'verdict' => 'pass', 'content_hash' => $old_hash ];
$GLOBALS['POST_META_STORE'][10][ PressHub_AI_Publish_Gate::META_OVERRIDE ] = $old_hash;
$M::queue( 10, 20, $wav_url );
$M::claim_next();
$GLOBALS['SIDELOAD_RETURN_ID'] = 77;
$r = $M::complete( 10, 20, $mp3 );
$new_hash = PressHub_AI_Publish_Gate::input_hash_for_post( get_post( 10 ), 'podcast' );
$review   = get_post_meta( 10, PressHub_AI_Publish_Gate::META_REVIEW, true );
mp3_check( 'complete: content hash changed', is_array( $r ) && $new_hash !== $old_hash );
mp3_check( 'complete: QA review carried over to the new content', $new_hash === ( $review['content_hash'] ?? '' ) && 'pass' === ( $review['verdict'] ?? '' ) );
mp3_check( 'complete: QA override carried over', $new_hash === get_post_meta( 10, PressHub_AI_Publish_Gate::META_OVERRIDE, true ) );
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$GLOBALS['POST_META_STORE'][10]['_presshub_briefing_type'] = 'podcast';
$GLOBALS['POST_META_STORE'][10][ PressHub_AI_Publish_Gate::META_REVIEW ]   = [ 'verdict' => 'fail', 'content_hash' => 'stale' ];
$GLOBALS['POST_META_STORE'][10][ PressHub_AI_Publish_Gate::META_OVERRIDE ] = 'stale';
$M::queue( 10, 20, $wav_url );
$M::claim_next();
$GLOBALS['SIDELOAD_RETURN_ID'] = 77;
$M::complete( 10, 20, $mp3 );
mp3_check( 'complete: a stale QA review stays stale', 'stale' === ( get_post_meta( 10, PressHub_AI_Publish_Gate::META_REVIEW, true )['content_hash'] ?? '' ) && 'stale' === get_post_meta( 10, PressHub_AI_Publish_Gate::META_OVERRIDE, true ) );

// The episode is regenerated while the MP3 is being stored: the new attachment is thrown away.
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
mp3_plant_post( 11, 21, str_replace( 'abc', 'def', $wav_url ) );
$M::queue( 10, 20, $wav_url );
$M::claim_next();
$M::queue( 11, 21, str_replace( 'abc', 'def', $wav_url ) );
$spy = new Mp3_Sideload_Spy();
$spy->fn = function () {
    $GLOBALS['POST_META_STORE'][10]['_presshub_audio_attachment_id'] = 30;
    $q = PressHub_AI_Podcast_Mp3::get_queue();
    $q[12] = [ 'post_id' => 12, 'attachment_id' => 22, 'source_url' => 'x.wav', 'status' => 'pending', 'attempts' => 0, 'queued_at' => time() ];
    update_option( PressHub_AI_Podcast_Mp3::QUEUE_OPTION, $q );
};
$GLOBALS['SIDELOAD_CAPTURE']   = $spy;
$GLOBALS['SIDELOAD_RETURN_ID'] = 77;
$r = $M::complete( 10, 20, $mp3 );
mp3_check( 'complete: regenerated during the sideload is refused', is_wp_error( $r ) && 'mp3_superseded' === $r->get_error_code() );
mp3_check( 'complete: the orphan MP3 is deleted', [ 77 ] === $GLOBALS['DELETED_ATTACHMENTS'] );
mp3_check( 'complete: post content untouched', false !== strpos( (string) $GLOBALS['POSTS_STORE'][10]['post_content'], $wav_url ) );
mp3_check( 'complete: other episodes queued meanwhile are kept', [ 11, 12 ] === array_keys( $M::get_queue() ) );

// A failed database write throws the MP3 away and retries later.
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$M::queue( 10, 20, $wav_url );
$M::claim_next();
$GLOBALS['wpdb']->fail_next    = true;
$GLOBALS['SIDELOAD_RETURN_ID'] = 77;
$r = $M::complete( 10, 20, $mp3 );
mp3_check( 'complete: a failed post write is reported', is_wp_error( $r ) && 'mp3_post_update_failed' === $r->get_error_code() );
mp3_check( 'complete: ...the MP3 deleted, the meta untouched, retried later', [ 77 ] === $GLOBALS['DELETED_ATTACHMENTS'] && 20 === get_post_meta( 10, '_presshub_audio_attachment_id', true ) && 'pending' === ( $M::get_queue()[10]['status'] ?? '' ) );

mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$M::queue( 10, 20, $wav_url );
$M::claim_next();
$GLOBALS['POST_META_STORE'][10]['_presshub_audio_attachment_id'] = 30;
$r = $M::complete( 10, 20, $mp3 );
mp3_check( 'complete: superseded audio refused and dropped', is_wp_error( $r ) && 'mp3_superseded' === $r->get_error_code() && [] === $M::get_queue() );

mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$M::queue( 10, 20, $wav_url );
$M::claim_next();
$GLOBALS['SIDELOAD_FAIL'] = [ 'code' => 'upload_error', 'msg' => 'Disk full' ];
$r = $M::complete( 10, 20, $mp3 );
mp3_check( 'complete: sideload failure returned and retried later', is_wp_error( $r ) && 'pending' === ( $M::get_queue()[10]['status'] ?? '' ) && false !== strpos( (string) $M::get_queue()[10]['error'], 'Disk full' ) );

// Escaped URLs are swapped too.
$c = $M::swap_audio_in_content( '<audio src="http://a.test/x.wav?a=1&#038;b=2"></audio><!-- wp:audio {"id":5,"autoplay":false} --><!-- wp:audio {"id":55} -->', 'http://a.test/x.wav?a=1&b=2', 5, 'http://a.test/x.mp3', 9 );
mp3_check( 'swap: escaped URL swapped', false !== strpos( $c, 'x.mp3' ) && false === strpos( $c, 'x.wav' ) );
mp3_check( 'swap: only the matching block id', false !== strpos( $c, '{"id":9,"autoplay":false}' ) && false !== strpos( $c, '{"id":55}' ) );

// 5. record_failure().
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$M::queue( 10, 20, $wav_url );
$M::claim_next();
mp3_check( 'fail: unknown attachment ignored', false === $M::record_failure( 10, 99, 'x' ) );
mp3_check( 'fail: retried', true === $M::record_failure( 10, 20, 'ffmpeg exited with code 1' ) && 'pending' === ( $M::get_queue()[10]['status'] ?? '' ) );
$q = $M::get_queue();
$q[10]['attempts'] = $M::MAX_ATTEMPTS;
update_option( $M::QUEUE_OPTION, $q );
$M::record_failure( 10, 20, 'ffmpeg exited with code 1' );
mp3_check( 'fail: given up after the last attempt', 'failed' === ( $M::get_queue()[10]['status'] ?? '' ) && null === $M::claim_next() );
$r = $M::rest_fail( [ 'post_id' => 10, 'attachment_id' => 20, 'error' => '<b>x</b>' ] );
mp3_check( 'fail: REST handler answers', is_array( $r ) && array_key_exists( 'ok', $r ) );

// 6. delete_wav().
mp3_reset();
$GLOBALS['POST_META_STORE'][10]['_presshub_audio_attachment_id'] = 77;
$GLOBALS['ATTACHED_FILES'][20] = '/uploads/podcast.wav';
$GLOBALS['ATTACHED_FILES'][77] = '/uploads/podcast.mp3';
$M::delete_wav( 77, 10 );
mp3_check( 'delete: the file the post plays is kept', [] === $GLOBALS['DELETED_ATTACHMENTS'] );
$GLOBALS['POST_META_STORE'][11]['_presshub_audio_attachment_id'] = 5;
$M::delete_wav( 77, 11 );
mp3_check( 'delete: a non-WAV attachment is kept', [] === $GLOBALS['DELETED_ATTACHMENTS'] );
$M::delete_wav( 20, 10 );
mp3_check( 'delete: the replaced WAV is deleted', [ 20 ] === $GLOBALS['DELETED_ATTACHMENTS'] );

// 7. REST routes and the synthesizer hook.
$GLOBALS['REST_ROUTES'] = [];
$M::register_routes();
foreach ( [ 'claim', 'complete', 'fail' ] as $r ) {
    $route = $GLOBALS['REST_ROUTES'][ 'presshub-social/v1/worker/audio/' . $r ] ?? null;
    mp3_check( "routes: {$r} registered behind the worker secret", is_array( $route ) && [ 'PressHub_Social_REST_Controller', 'verify_worker_secret' ] === $route['permission_callback'] );
}
mp3_reset();
mp3_plant_post( 10, 20, $wav_url );
$M::queue( 10, 20, $wav_url );
$claim = $M::rest_claim( [] );
mp3_check( 'rest: claim returns the job', 10 === ( $claim['job']['post_id'] ?? 0 ) );
$GLOBALS['SIDELOAD_RETURN_ID'] = 78;
$done = $M::rest_complete( [ 'post_id' => '10', 'attachment_id' => '20', 'body' => $mp3 ] );
mp3_check( 'rest: complete stores the MP3', is_array( $done ) && true === $done['ok'] && 78 === $done['attachment_id'] );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-audio-synthesizer.php' );
$queue_at = strpos( $src, 'PressHub_AI_Podcast_Mp3::queue(' );
$done_at  = strpos( $src, "'_presshub_audio_completed_at'" );
mp3_check( 'synthesizer: queues only after the audio meta is complete', false !== $queue_at && false !== $done_at && $queue_at > $done_at );
mp3_check( 'synthesizer: queues WAV episodes after the post is saved', 1 === preg_match( "/'\\.wav' === \\\$ext \\) \\{\\s*PressHub_AI_Podcast_Mp3::queue\\( \\(int\\) \\\$post_id, \\\$attachment_id, \\\$audio_url \\);/", $src ) );

if ( $failures > 0 ) {
    echo "PodcastMp3Test: {$failures} failure(s)\n";
    exit( 1 );
}
echo "OK\n";
