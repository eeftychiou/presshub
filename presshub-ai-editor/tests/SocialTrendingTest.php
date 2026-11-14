<?php
/**
 * SocialTrendingTest — Trending in Cyprus roundup post.
 *
 * Test cases:
 *   1. Settings: sanitizers (lines, hashtags, Facebook Pages, enums, clamps), map and booleans.
 *   2. Runs: disabled, not due, due; worker-only run waits; Instagram-only run publishes at once.
 *   3. Worker plan: targets (X search, TikTok searches, Facebook Pages), handed out once, re-handed after lock.
 *   4. Ingest: stale run refused, validation (platform, ids, age, URLs), canonical URLs.
 *   5. Selection: min engagement, per-platform cap, per-account cap, mixed order, recency beats size.
 *   6. Publishing: category created, daily post updated in place, new day = new post, rolling, each, draft.
 *   7. Content: embeds for X/TikTok, quotes for Instagram/Facebook, escaped text.
 *   8. Instagram Graph API: hashtag ids cached, top media mapped, errors tolerated.
 *   9. Cron tick: a run whose worker never reports is published after the wait.
 *  10. REST handlers and settings render.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

$GLOBALS['TERMS'] = [];
function get_term_by( $field, $value, $taxonomy = '' ) {
    foreach ( $GLOBALS['TERMS'] as $t ) {
        if ( ( 'name' === $field && $t->name === $value ) || ( 'slug' === $field && $t->slug === $value ) ) {
            return $t;
        }
    }
    return false;
}
function wp_insert_term( $name, $taxonomy, $args = [] ) {
    $id = 500 + count( $GLOBALS['TERMS'] );
    $GLOBALS['TERMS'][] = (object) [ 'term_id' => $id, 'name' => $name, 'slug' => $args['slug'] ?? $name ];
    return [ 'term_id' => $id, 'term_taxonomy_id' => $id ];
}
function get_users( $args = [] ) {
    return [ 7 ];
}
function wp_nonce_url( $url, $action = -1 ) {
    return $url . '&_wpnonce=test';
}

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
function st_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

function st_reset( array $opts = [] ) {
    $GLOBALS['OPTIONS_STORE'] = array_merge( [
        'presshub_ai_social_trending_enabled'            => 1,
        'presshub_ai_social_trending_interval_hours'     => 6,
        'presshub_ai_social_trending_platform_x'         => 1,
        'presshub_ai_social_trending_platform_tiktok'    => 1,
        'presshub_ai_social_trending_platform_instagram' => 0,
        'presshub_ai_social_trending_platform_facebook'  => 1,
        'presshub_ai_social_trending_facebook_pages'     => "https://www.facebook.com/philenews",
        'presshub_ai_social_trending_min_engagement'     => 10,
        'gmt_offset'                                     => 2,
    ], $opts );
    $GLOBALS['TERMS']               = [];
    $GLOBALS['POSTS_STORE']         = [];
    $GLOBALS['WP_INSERTED_POSTS']   = [];
    $GLOBALS['POST_META_STORE']     = [];
    $GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
    PressHub_AI_Social_Trending::$http_get = null;
}

function st_post( string $platform, string $id, int $likes, int $age_min, array $extra = [] ) {
    $base = $GLOBALS['ST_NOW'] ?? $GLOBALS['now'];
    return array_merge( [
        'platform'    => $platform,
        'id'          => $id,
        'handle'      => 'acct' . $id,
        'author_name' => 'Account ' . $id,
        'url'         => '',
        'text'        => 'Κείμενο ' . $id,
        'created_at'  => gmdate( 'c', $base - $age_min * 60 ),
        'media'       => [ 'type' => 'none', 'duration_sec' => 0 ],
        'metrics'     => [ 'likes' => $likes, 'comments' => 0, 'shares' => 0, 'views' => 0 ],
    ], $extra );
}

$now = 1794650000; // fixed: 2026-11-14 ~09:13 UTC
$S   = 'PressHub_AI_Settings_Storage';
$T   = 'PressHub_AI_Social_Trending';

// =========================================================================
// 1. Settings
// =========================================================================
st_check( 'sanitize: keywords trimmed, deduped, html stripped', "Κύπρος\nCyprus" === $S::sanitize_social_trending_keywords( "  Κύπρος \n\nCyprus\nκύπρος\n" ) );
st_check( 'sanitize: hashtags lowercased, # removed, junk dropped, max 5', "cyprus\nκύπρος\na\nb\nc" === $S::sanitize_social_trending_hashtags( "#Cyprus\nΚύπρος\nbad tag\na\nb\nc\nd" ) );
st_check( 'sanitize: facebook pages normalised', "https://www.facebook.com/philenews\nhttps://www.facebook.com/profile.php?id=12345" === $S::sanitize_social_trending_facebook_pages( "https://m.facebook.com/philenews/?ref=x\nhttps://www.facebook.com/profile.php?id=12345&sk=about\nhttps://evil.com/philenews\nhttps://www.facebook.com/philenews" ) );
st_check( 'sanitize: post mode enum', 'daily' === $S::sanitize_social_trending_post_mode( 'weird' ) && 'each' === $S::sanitize_social_trending_post_mode( 'each' ) );
st_check( 'sanitize: post status enum', 'publish' === $S::sanitize_social_trending_post_status( 'private' ) && 'draft' === $S::sanitize_social_trending_post_status( 'draft' ) );
st_check( 'sanitize: interval clamp', 1 === $S::sanitize_social_trending_interval_hours( 0 ) && 48 === $S::sanitize_social_trending_interval_hours( 500 ) );
st_check( 'sanitize: empty category falls back', $S::SOCIAL_TRENDING_DEFAULT_CATEGORY === $S::sanitize_social_trending_category( '  ' ) );
$map = $S::get_social_trending_options_map();
st_check( 'map: every boolean is in the map', [] === array_diff( $S::get_social_trending_boolean_options(), array_keys( $map ) ) );
st_check( 'map: merged into the briefing section map', isset( $S::get_section_options_map( 'briefing' )['presshub_ai_social_trending_interval_hours'] ) );
st_reset( [ 'presshub_ai_social_trending_keywords' => '' ] );
st_check( 'defaults: empty keywords use the defaults', in_array( 'Cyprus', $S::get_social_trending_keywords(), true ) );
st_check( 'defaults: platforms read from options', [ 'x', 'tiktok', 'facebook' ] === $S::get_social_trending_platforms() );

// =========================================================================
// 2. Runs
// =========================================================================
st_reset( [ 'presshub_ai_social_trending_enabled' => 0 ] );
st_check( 'run: disabled never starts', null === $T::maybe_start_run( $now ) );

st_reset();
$state = $T::maybe_start_run( $now );
st_check( 'run: first run starts', is_array( $state ) && ! empty( $state['open'] ) && ! empty( $state['worker_pending'] ) );
$run_id = $state['run_id'];
st_check( 'run: open run is returned again, not restarted', $run_id === $T::maybe_start_run( $now + 60 )['run_id'] );

st_reset( [
    'presshub_ai_social_trending_platform_x'         => 0,
    'presshub_ai_social_trending_platform_tiktok'    => 0,
    'presshub_ai_social_trending_platform_facebook'  => 0,
    'presshub_ai_social_trending_platform_instagram' => 0,
] );
st_check( 'run: nothing readable -> no run, note left', null === $T::maybe_start_run( $now ) && '' !== $T::get_state()['last_note'] );

st_reset( [ 'presshub_ai_social_trending_platform_facebook' => 1, 'presshub_ai_social_trending_platform_x' => 0, 'presshub_ai_social_trending_platform_tiktok' => 0, 'presshub_ai_social_trending_facebook_pages' => '' ] );
st_check( 'run: facebook without pages is not a worker platform', null === $T::maybe_start_run( $now ) );

// =========================================================================
// 3. Worker plan
// =========================================================================
st_reset();
$plan = $T::get_worker_plan( false, $now );
st_check( 'plan: due with run id', true === $plan['due'] && '' !== $plan['run_id'] );
$by = [];
foreach ( $plan['targets'] as $t ) {
    $by[ $t['platform'] ][] = $t['url'];
}
st_check( 'plan: Greek and other X searches on the Top tab', 2 === count( $by['x'] ?? [] ) && false !== strpos( $by['x'][0], 'f=top' ) );
$xg = rawurldecode( $by['x'][0] );
$xe = rawurldecode( $by['x'][1] );
st_check( 'plan: Greek X search uses lang:el and a lower bar', false !== strpos( $xg, 'Κύπρος OR Λευκωσία' ) && false !== strpos( $xg, 'lang:el' ) && false !== strpos( $xg, 'min_faves:5' ) && false === strpos( $xg, 'Cyprus' ) );
st_check( 'plan: other X search has a higher bar and no language', '(Cyprus) -filter:replies -filter:retweets min_faves:20' === explode( 'q=', explode( '&src', $xe )[0] )[1] );
st_check( 'plan: TikTok hashtag pages first, then two searches', [ 'https://www.tiktok.com/tag/cyprus', 'https://www.tiktok.com/tag/' . rawurlencode( 'κύπρος' ), 'https://www.tiktok.com/tag/limassol' ] === array_slice( $by['tiktok'] ?? [], 0, 3 ) && 5 === count( $by['tiktok'] ) && 0 === strpos( $by['tiktok'][3], 'https://www.tiktok.com/search/video?q=' ) );
st_check( 'plan: facebook pages', [ 'https://www.facebook.com/philenews' ] === ( $by['facebook'] ?? [] ) );
st_check( 'plan: not handed out twice', false === $T::get_worker_plan( false, $now + 60 )['due'] );
st_check( 'plan: handed out again after the lock', true === $T::get_worker_plan( false, $now + $T::PLAN_LOCK_SEC + 1 )['due'] );
st_reset( [ 'presshub_ai_social_trending_keywords' => "Κύπρος\nΑγία Νάπα" ] );
$plan = $T::get_worker_plan( false, $now );
st_check( 'plan: multi-word terms quoted for X', false !== strpos( rawurldecode( $plan['targets'][0]['url'] ), '"Αγία Νάπα"' ) );
st_check( 'plan: no English terms -> one X search', 1 === count( array_filter( $plan['targets'], static function ( $t ) { return 'x' === $t['platform']; } ) ) );

// =========================================================================
// 4. Ingest
// =========================================================================
st_reset();
$plan = $T::get_worker_plan( false, $now );
$res  = $T::ingest_worker_posts( 'wrong', [], [], $now );
st_check( 'ingest: stale run refused', is_wp_error( $res ) && 'stale_run' === $res->get_error_code() );

st_check( 'normalize: x canonical url', 'https://x.com/acct1/status/1' === $T::normalize_item( st_post( 'x', '1', 50, 30, [ 'url' => 'https://evil.com' ] ), $now )['url'] );
st_check( 'normalize: x non-numeric id rejected', null === $T::normalize_item( st_post( 'x', 'abc', 50, 30 ), $now ) );
st_check( 'normalize: too old rejected', null === $T::normalize_item( st_post( 'x', '2', 50, 25 * 60 ), $now ) );
st_check( 'normalize: future rejected', null === $T::normalize_item( st_post( 'x', '3', 50, -60 ), $now ) );
st_check( 'normalize: unknown platform rejected', null === $T::normalize_item( st_post( 'myspace', '4', 50, 30 ), $now ) );
st_check( 'normalize: tiktok photo url kept', 'https://www.tiktok.com/@acct5/photo/5' === $T::normalize_item( st_post( 'tiktok', '5', 50, 30, [ 'url' => 'https://www.tiktok.com/@acct5/photo/5' ] ), $now )['url'] );
st_check( 'normalize: facebook url canonical', 'https://www.facebook.com/philenews/posts/pfbid0xyz' === $T::normalize_item( st_post( 'facebook', '123456', 50, 30, [ 'url' => 'https://m.facebook.com/philenews/posts/pfbid0xyz?__cft__=abc' ] ), $now )['url'] );
st_check( 'normalize: facebook permalink.php keeps ids only', 'https://www.facebook.com/permalink.php?story_fbid=99&id=12' === $T::facebook_url( 'https://www.facebook.com/permalink.php?story_fbid=99&id=12&__tn__=x', '99' ) );
st_check( 'normalize: facebook foreign host replaced', 'https://www.facebook.com/77' === $T::facebook_url( 'https://evil.com/x', '77' ) );
st_check( 'normalize: html stripped from text', 'hi there' === $T::normalize_item( st_post( 'x', '6', 50, 30, [ 'text' => '<b>hi</b> there' ] ), $now )['text'] );
st_check( 'normalize: string counts accepted', 1234 === $T::normalize_item( st_post( 'x', '7', 0, 30, [ 'metrics' => [ 'likes' => '1,234' ] ] ), $now )['likes'] );
st_check( 'normalize: instagram needs a permalink', null === $T::normalize_item( st_post( 'instagram', '8', 50, 30, [ 'url' => 'https://evil.com/p/x' ] ), $now ) );

// =========================================================================
// 5. Selection
// =========================================================================
st_reset( [ 'presshub_ai_social_trending_max_items' => 6, 'presshub_ai_social_trending_max_per_platform' => 3 ] );
$cands = [];
foreach ( [
    st_post( 'x', '11', 500, 60 ),
    st_post( 'x', '12', 400, 60 ),
    st_post( 'x', '13', 300, 60 ),
    st_post( 'x', '14', 200, 60 ),
    st_post( 'x', '15', 5, 60 ),                                   // below min engagement
    st_post( 'tiktok', '21', 9000, 60 ),
    st_post( 'tiktok', '22', 8000, 60, [ 'handle' => 'acct21' ] ),
    st_post( 'tiktok', '23', 7000, 60, [ 'handle' => 'acct21' ] ), // third by same account
    st_post( 'facebook', '31', 50, 60 ),
] as $raw ) {
    $c = $T::normalize_item( $raw, $now );
    $cands[ $c['key'] ] = $c;
}
$picked = $T::select( $cands, $now );
$keys   = array_map( static function ( $p ) { return $p['key']; }, $picked );
st_check( 'select: total cap', 6 === count( $picked ) );
st_check( 'select: below min engagement dropped', ! in_array( 'x_15', $keys, true ) );
st_check( 'select: per-account cap of 2', ! in_array( 'tiktok_23', $keys, true ) );
st_check( 'select: platforms take turns, strongest first', [ 'tiktok_21', 'x_11', 'facebook_31', 'tiktok_22', 'x_12', 'x_13' ] === $keys );
// Excluded words and accounts without a handle.
$spam  = $T::normalize_item( st_post( 'x', '43', 900, 30, [ 'text' => 'Free BITCOIN airdrop Cyprus' ] ), $now );
$ok    = $T::normalize_item( st_post( 'x', '44', 900, 30, [ 'text' => 'Bitcoiners? no: Cyprus news' ] ), $now );
$words = $S::get_social_trending_excluded_words();
st_check( 'exclude: default list has spam words', in_array( 'crypto', $words, true ) );
st_check( 'exclude: whole word, any case', $T::is_excluded( $spam, $words ) && ! $T::is_excluded( $ok, $words ) );
st_check( 'exclude: dropped from selection', [] === $T::select( [ $spam['key'] => $spam ], $now ) );
$GLOBALS['OPTIONS_STORE']['presshub_ai_social_trending_excluded_words'] = "ακίνητα προς πώληση";
st_check( 'exclude: custom Greek phrase', $T::is_excluded( $T::normalize_item( st_post( 'facebook', '45', 90, 30, [ 'text' => 'Ακίνητα προς πώληση στη Λεμεσό' ] ), $now ), $S::get_social_trending_excluded_words() ) );
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_social_trending_excluded_words'] );
$fb = [];
foreach ( [ '46', '47', '48' ] as $fid ) {
    $c = $T::normalize_item( st_post( 'facebook', $fid, 100 + (int) $fid, 30, [ 'handle' => '', 'author_name' => 'Δήμος Λεμεσού' ] ), $now );
    $fb[ $c['key'] ] = $c;
}
st_check( 'select: no handle -> capped by author name', 2 === count( $T::select( $fb, $now ) ) );
st_check( 'account key: id handles kept', 'id-1000641' === $T::account_key( $T::normalize_item( st_post( 'facebook', '49', 100, 30, [ 'handle' => 'id-1000641' ] ), $now ) ) );
$fresh = $T::normalize_item( st_post( 'x', '41', 100, 30 ), $now );
$old   = $T::normalize_item( st_post( 'x', '42', 400, 20 * 60 ), $now );
st_check( 'score: fresh post with traction beats an older bigger one', $T::score( $fresh, $now ) > $T::score( $old, $now ) );

// =========================================================================
// 6. Publishing
// =========================================================================
st_reset();
$plan = $T::get_worker_plan( false, $now );
$res  = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '11', 500, 60 ), st_post( 'tiktok', '21', 900, 30 ), st_post( 'x', 'bad', 1, 1 ) ], [], $now );
st_check( 'publish: accepted / rejected counted', 2 === $res['accepted'] && 1 === $res['rejected'] && $res['post_id'] > 0 );
$first_id = $res['post_id'];
$ins      = $GLOBALS['WP_INSERTED_POSTS'][0] ?? [];
st_check( 'publish: category created and used', 1 === count( $GLOBALS['TERMS'] ) && [ $GLOBALS['TERMS'][0]->term_id ] === ( $ins['post_category'] ?? null ) );
st_check( 'publish: published by an administrator', 'publish' === $ins['post_status'] && 7 === $ins['post_author'] );
st_check( 'publish: title has the local date', 'Τι συζητά η Κύπρος στα social – 14/11/2026' === $ins['post_title'] );
st_check( 'publish: meta key is the day', 'day:2026-11-14' === $ins['meta_input'][ $T::META_KEY ] );
$state = $T::get_state();
st_check( 'publish: run closed with count', empty( $state['open'] ) && 2 === $state['last_count'] && $first_id === $state['last_post_id'] );
st_check( 'publish: candidates cleared', [] === get_option( $T::OPTION_CANDIDATES ) );

// Next run, same day: same post updated, no new category.
$later = $now + 6 * 3600 + 5;
$plan  = $T::get_worker_plan( false, $later );
st_check( 'publish: next run due after the interval', true === $plan['due'] );
$res = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '51', 800, 10 ) ], [], $later );
st_check( 'publish: same day updates the same post', $first_id === $res['post_id'] && 1 === count( $GLOBALS['WP_INSERTED_POSTS'] ) && 1 === $GLOBALS['WP_UPDATE_POST_CALLS'] && 1 === count( $GLOBALS['TERMS'] ) );
st_check( 'publish: updated content has the new post', false !== strpos( $GLOBALS['POSTS_STORE'][ $first_id ]['post_content'], 'acct51/status/51' ) );

// Next day: new post.
$next_day = $now + 86400;
$GLOBALS['ST_NOW'] = $next_day;
$plan     = $T::get_worker_plan( false, $next_day );
$res      = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '61', 800, 10 ) ], [], $next_day );
st_check( 'publish: new day creates a new post', $res['post_id'] !== $first_id && 2 === count( $GLOBALS['WP_INSERTED_POSTS'] ) );

// A trashed day post is recreated.
$GLOBALS['POSTS_STORE'][ $res['post_id'] ]['post_status'] = 'trash';
$again = $next_day + 7 * 3600;
$GLOBALS['ST_NOW'] = $again;
$plan  = $T::get_worker_plan( false, $again );
$res2  = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '62', 800, 10 ) ], [], $again );
st_check( 'publish: trashed post is not reused', $res2['post_id'] !== $res['post_id'] );

// Nothing selected: post untouched, note left.
unset( $GLOBALS['ST_NOW'] );
st_reset();
$plan = $T::get_worker_plan( false, $now );
$res  = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '71', 1, 10 ) ], [ [ 'platform' => 'x', 'url' => 'u', 'error' => 'login' ] ], $now );
st_check( 'publish: nothing selected -> no post', 0 === $res['post_id'] && empty( $GLOBALS['WP_INSERTED_POSTS'] ) && false !== strpos( $T::get_state()['last_note'], 'No post passed' ) );

// Rolling and each modes, draft status.
st_reset( [ 'presshub_ai_social_trending_post_mode' => 'rolling', 'presshub_ai_social_trending_post_status' => 'draft' ] );
$plan = $T::get_worker_plan( false, $now );
$a    = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '81', 100, 10 ) ], [], $now );
$plan = $T::get_worker_plan( false, $now + 86400 * 3 );
$GLOBALS['ST_NOW'] = $now + 86400 * 3;
$b    = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '82', 100, 10 ) ], [], $now + 86400 * 3 );
unset( $GLOBALS['ST_NOW'] );
st_check( 'mode rolling: one post forever', $a['post_id'] === $b['post_id'] && 'draft' === $GLOBALS['WP_INSERTED_POSTS'][0]['post_status'] );
st_reset( [ 'presshub_ai_social_trending_post_mode' => 'each' ] );
$plan = $T::get_worker_plan( false, $now );
$a    = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '91', 100, 10 ) ], [], $now );
$plan = $T::get_worker_plan( false, $now + 6 * 3600 );
$b    = $T::ingest_worker_posts( $plan['run_id'], [ st_post( 'x', '92', 100, 10 ) ], [], $now + 6 * 3600 );
st_check( 'mode each: a post per run', $a['post_id'] !== $b['post_id'] && 2 === count( $GLOBALS['WP_INSERTED_POSTS'] ) );

// Existing category reused (matched by name).
st_reset();
$GLOBALS['TERMS'][] = (object) [ 'term_id' => 42, 'name' => $S::SOCIAL_TRENDING_DEFAULT_CATEGORY, 'slug' => 'whatever' ];
st_check( 'category: existing one reused', 42 === $T::ensure_category() && 1 === count( $GLOBALS['TERMS'] ) );

// =========================================================================
// 7. Content
// =========================================================================
st_reset();
$items = [
    $T::normalize_item( st_post( 'x', '101', 1500, 90, [ 'metrics' => [ 'likes' => 1500, 'comments' => 20, 'shares' => 30, 'views' => 25000 ] ] ), $now ),
    $T::normalize_item( st_post( 'tiktok', '102', 100, 30 ), $now ),
    $T::normalize_item( st_post( 'instagram', '103', 100, 30, [ 'url' => 'https://www.instagram.com/p/AbC_1/', 'text' => 'Ηλιοβασίλεμα <script>alert(1)</script> & θάλασσα', 'author_name' => '' ] ), $now ),
    $T::normalize_item( st_post( 'facebook', '104', 100, 30, [ 'url' => 'https://www.facebook.com/philenews/posts/1' ] ), $now ),
];
$html = $T::build_content( $items, $now );
st_check( 'content: X embed via twitter.com', false !== strpos( $html, '<!-- wp:embed {"url":"https://twitter.com/acct101/status/101"' ) && false !== strpos( $html, 'wp-block-embed-twitter' ) );
st_check( 'content: TikTok embed', false !== strpos( $html, '"providerNameSlug":"tiktok"' ) );
st_check( 'content: Instagram quoted with link, text escaped', false !== strpos( $html, 'https://www.instagram.com/p/AbC_1/' ) && false === strpos( $html, '<script>' ) && false !== strpos( $html, 'Δείτε την ανάρτηση στο Instagram' ) );
st_check( 'content: Instagram without author shows platform', false !== strpos( $html, '3. Instagram στο Instagram' ) || false !== strpos( $html, '3. @acct103 στο Instagram' ) );
st_check( 'content: stats line', false !== strpos( $html, '1.500 likes · 20 σχόλια · 30 αναδημοσιεύσεις · 25K προβολές · πριν από 1 ώρα' ) );
st_check( 'content: facebook reactions wording', false !== strpos( $html, '100 αντιδράσεις' ) );
st_check( 'content: intro names platforms', false !== strpos( $html, 'X, TikTok, Instagram, Facebook' ) );

// =========================================================================
// 8. Instagram Graph API
// =========================================================================
st_reset( [
    'presshub_ai_social_trending_platform_x'         => 0,
    'presshub_ai_social_trending_platform_tiktok'    => 0,
    'presshub_ai_social_trending_platform_facebook'  => 0,
    'presshub_ai_social_trending_platform_instagram' => 1,
    'presshub_ai_social_trending_hashtags'           => "cyprus\nlimassol",
    'presshub_social_instagram_account_id'           => '178414',
    'presshub_social_meta_access_token'              => 'TOKEN',
] );
$calls = [];
$T::$http_get = static function ( $url ) use ( &$calls, $now ) {
    $calls[] = $url;
    if ( false !== strpos( $url, 'ig_hashtag_search' ) ) {
        if ( false !== strpos( $url, 'q=limassol' ) ) {
            return [ 'response' => [ 'code' => 400 ], 'body' => '{"error":{"message":"limit reached"}}' ];
        }
        return [ 'response' => [ 'code' => 200 ], 'body' => '{"data":[{"id":"1789"}]}' ];
    }
    return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'data' => [
        [ 'id' => '9001', 'caption' => 'Λευκωσία', 'media_type' => 'IMAGE', 'like_count' => 300, 'comments_count' => 12, 'permalink' => 'https://www.instagram.com/p/XyZ/', 'timestamp' => gmdate( 'Y-m-d\TH:i:sO', $now - 7200 ), 'media_url' => 'https://cdn.example/i.jpg' ],
        [ 'id' => '9002', 'caption' => 'old', 'media_type' => 'VIDEO', 'like_count' => 9000, 'comments_count' => 1, 'permalink' => 'https://www.instagram.com/reel/Old/', 'timestamp' => gmdate( 'Y-m-d\TH:i:sO', $now - 5 * 86400 ) ],
    ] ] ) ];
};
$state = $T::maybe_start_run( $now );
st_check( 'instagram: run published at once (no worker)', is_array( $state ) && empty( $state['open'] ) && 1 === $state['last_count'] && $state['last_post_id'] > 0 );
st_check( 'instagram: hashtag id cached, failed tag tolerated', [ 'cyprus' => '1789' ] === get_option( $T::OPTION_IG_TAG_IDS ) );
st_check( 'instagram: token and user id sent', false !== strpos( $calls[1], '/1789/top_media?user_id=178414' ) && false !== strpos( $calls[1], 'access_token=TOKEN' ) );
$n = count( $calls );
$T::fetch_instagram( $now );
st_check( 'instagram: cached id skips the lookup next time', false === strpos( $calls[ $n ], 'ig_hashtag_search' ) );
$ig = $T::fetch_instagram( $now );
st_check( 'instagram: old media filtered, counts mapped', 1 === count( $ig ) && 300 === $ig[0]['likes'] && 12 === $ig[0]['comments'] && 'https://cdn.example/i.jpg' === $ig[0]['thumbnail'] );

// =========================================================================
// 9. Cron tick
// =========================================================================
st_reset();
$T::tick( $now );
st_check( 'tick: starts a due run', ! empty( $T::get_state()['open'] ) );
$T::tick( $now + 600 );
st_check( 'tick: waits for the worker', ! empty( $T::get_state()['open'] ) );
$T::tick( $now + $T::WORKER_WAIT_SEC + 1 );
$state = $T::get_state();
st_check( 'tick: closes a run the worker never reported', empty( $state['open'] ) && false !== strpos( $state['last_note'], 'did not report' ) );
$T::tick( $now + $T::WORKER_WAIT_SEC + 120 );
st_check( 'tick: no new run before the interval', empty( $T::get_state()['open'] ) );

// =========================================================================
// 10. REST and render
// =========================================================================
st_reset();
$GLOBALS['ST_NOW'] = time();
$plan = $T::rest_plan( [] );
$plan = $plan instanceof WP_REST_Response ? $plan->get_data() : $plan;
st_check( 'rest: plan', true === $plan['due'] );
$bad = $T::rest_posts( [ 'run_id' => $plan['run_id'], 'posts' => 'nope' ] );
st_check( 'rest: posts must be an array', is_wp_error( $bad ) );
$ok = $T::rest_posts( [ 'run_id' => $plan['run_id'], 'posts' => [ st_post( 'x', '111', 100, 10 ) ] ] );
$ok = $ok instanceof WP_REST_Response ? $ok->get_data() : $ok;
st_check( 'rest: posts accepted', 1 === $ok['accepted'] );

ob_start();
( new PressHub_AI_Settings_Render() )->render_social_trending_rows();
$out = ob_get_clean();
st_check( 'render: fields present', false !== strpos( $out, 'name="presshub_ai_social_trending_interval_hours"' ) && false !== strpos( $out, 'name="presshub_ai_social_trending_platform_instagram"' ) && false !== strpos( $out, 'name="presshub_ai_social_trending_post_mode"' ) );
st_check( 'render: refresh-now link when enabled', false !== strpos( $out, 'action=' . $T::RUN_ACTION ) );

// Editorial QA gate leaves roundup posts alone (they follow their own status setting).
st_reset( [ 'presshub_ai_qa_enabled' => 1 ] );
$GLOBALS['CURRENT_USER_CAPS'] = [];
$GLOBALS['POST_META_STORE'][ 900 ][ $T::META_KEY ] = 'day:2026-11-14';
$GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
( new PressHub_AI_Workflow() )->enforce_editorial_workflow( 'publish', 'new', (object) [ 'ID' => 900, 'post_type' => 'post', 'post_content' => 'x' ] );
st_check( 'workflow: roundup post not sent back to pending', 0 === $GLOBALS['WP_UPDATE_POST_CALLS'] );

if ( $failures > 0 ) {
    fwrite( STDERR, "{$failures} failure(s)\n" );
    exit( 1 );
}
echo "OK\n";
