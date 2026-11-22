<?php
/**
 * TrendingShortTest — trending roundup shorts show the posts the voice talks about.
 *
 * Test cases:
 *   1. items(): the roundup's posts from its meta; none for other posts.
 *   2. prompt_block(): numbered posts with platform and author, text clipped and recorded.
 *   3. norm_word() / find_anchor(): accents, punctuation and final sigma ignored; fewer words tried.
 *   4. attach_plan(): references placed in the script, unknown posts and missing words left out, cards built.
 *   5. word_timer() / card_spans(): seconds from the turn timings, last span about as long as the others.
 *   6. get_plan(): a roundup's prompt lists its posts, the plan keeps the references, a new set re-plans.
 *   7. attach_short(): the claim carries cards and spans; an article that is not a roundup does not.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
function ts_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$items = [
    [ 'key' => 'x_111', 'platform' => 'x', 'id' => '111', 'handle' => 'nikos_cy', 'author_name' => 'Νίκος Κ.', 'url' => 'https://x.com/nikos_cy/status/111', 'text' => 'Η αποφυλάκιση Αϊκούτ είναι ντροπή για τη χώρα.', 'thumbnail' => 'https://pbs.twimg.com/media/a.jpg', 'likes' => 1200, 'comments' => 80, 'shares' => 40, 'views' => 30000, 'created_ts' => time() ],
    [ 'key' => 'facebook_222', 'platform' => 'facebook', 'id' => '222', 'handle' => '', 'author_name' => 'Philenews', 'url' => 'https://www.facebook.com/philenews/posts/222', 'text' => str_repeat( 'Μαζωτός αφαλάτωση διαμαρτυρία. ', 40 ), 'thumbnail' => '', 'likes' => 500, 'comments' => 200, 'shares' => 30, 'views' => 0, 'created_ts' => time() ],
    [ 'key' => 'tiktok_333', 'platform' => 'tiktok', 'id' => '333', 'handle' => 'maria.cy', 'author_name' => '', 'url' => 'https://www.tiktok.com/@maria.cy/video/333', 'text' => '', 'thumbnail' => 'http://insecure.example/cover.jpg', 'likes' => 9000, 'comments' => 10, 'shares' => 5, 'views' => 100000, 'created_ts' => time() ],
];

// 1. items()
$GLOBALS['POSTS_STORE'][700] = [ 'ID' => 700, 'post_title' => 'Τι συζητά η Κύπρος στα social – 22/11/2026', 'post_content' => '<p>Τρεις αναρτήσεις συζητήθηκαν σήμερα: για τον Αϊκούτ, τον Μαζωτό και ένα TikTok.</p>', 'post_excerpt' => '', 'post_status' => 'publish' ];
update_post_meta( 700, PressHub_AI_Social_Trending::META_ITEMS, wp_json_encode( $items ) );
$GLOBALS['POSTS_STORE'][701] = [ 'ID' => 701, 'post_title' => 'Άρθρο', 'post_content' => '<p>Ένα απλό άρθρο για την ακρίβεια στην Κύπρο.</p>', 'post_excerpt' => '', 'post_status' => 'publish' ];
ts_check( 'A roundup has its posts', 3 === count( PressHub_Social_Trending_Short::items( 700 ) ) );
ts_check( 'An article has no roundup posts', [] === PressHub_Social_Trending_Short::items( 701 ) );
update_post_meta( 702, PressHub_AI_Social_Trending::META_ITEMS, 'not json' );
ts_check( 'Broken meta is no posts', [] === PressHub_Social_Trending_Short::items( 702 ) );

// 2. prompt_block()
PressHub_AI_Knowledge_Cuts::forget_material( 'social_tiktok_shorts' );
$block = PressHub_Social_Trending_Short::prompt_block( $items );
ts_check( 'The block lists post 1 with platform and author', str_contains( $block, '1. [X] Νίκος Κ.: Η αποφυλάκιση Αϊκούτ' ) );
ts_check( 'A post without a name is listed by its handle', str_contains( $block, '3. [TikTok] @maria.cy: (βίντεο ή εικόνα χωρίς κείμενο)' ) );
ts_check( 'The block asks for post_refs', str_contains( $block, '"post_refs"' ) && ! str_contains( $block, '{posts}' ) );
$long_line = array_values( array_filter( explode( "\n", $block ), fn( $l ) => str_starts_with( $l, '2. ' ) ) )[0] ?? '';
ts_check( 'A long post text is clipped with an ellipsis', str_ends_with( $long_line, '…' ) && mb_strlen( $long_line ) < 600 );
PressHub_AI_Knowledge_Cuts::start_call( 'social_tiktok_shorts' );
ts_check( 'The clipped post text is recorded as material of the shorts call', ! empty( array_filter( (array) PressHub_AI_Knowledge_Cuts::last_material(), fn( $n ) => str_contains( (string) ( $n['item'] ?? '' ), 'Philenews' ) && 'clipped' === ( $n['reason'] ?? '' ) ) ) );
PressHub_AI_Knowledge_Cuts::clear_last_material();
PressHub_AI_Knowledge_Cuts::forget_material( 'social_tiktok_shorts' );
ts_check( 'No posts, no block', '' === PressHub_Social_Trending_Short::prompt_block( [] ) );

// 3. norm_word() / find_anchor()
ts_check( 'Accents and punctuation are ignored', PressHub_Social_Trending_Short::norm_word( 'Αϊκούτ,' ) === PressHub_Social_Trending_Short::norm_word( 'αικουτ' ) );
ts_check( 'Final sigma matches sigma', PressHub_Social_Trending_Short::norm_word( 'Κύπρος' ) === PressHub_Social_Trending_Short::norm_word( 'ΚΥΠΡΟΣ' ) );
$script = "[SPEAKER_1]: Τι συζητούν οι Κύπριοι; Στο X, ο Νίκος γράφει ότι η αποφυλάκιση είναι ντροπή. Στο Facebook, οι κάτοικοι του Μαζωτού διαμαρτύρονται για την αφαλάτωση. Και στο TikTok, η Μαρία χορεύει. Γράψε μας στα σχόλια.";
$words  = PressHub_Social_Trending_Short::spoken_words( $script );
$norm   = array_map( [ 'PressHub_Social_Trending_Short', 'norm_word' ], $words );
ts_check( 'Speaker tags are not spoken words', 'Τι' === $words[0] );
$at_x = PressHub_Social_Trending_Short::find_anchor( $norm, 'Στο X, ο Νίκος γράφει' );
ts_check( 'The anchor is found where it starts', 'Στο' === $words[ $at_x ] && 'X,' === $words[ $at_x + 1 ] );
ts_check( 'A changed last word still matches on fewer words', PressHub_Social_Trending_Short::find_anchor( $norm, 'στο facebook οι ΚΑΤΟΙΚΟΙ της' ) > $at_x );
ts_check( 'Words not in the script are not found', -1 === PressHub_Social_Trending_Short::find_anchor( $norm, 'κάτι εντελώς άλλο' ) );
ts_check( 'One word alone is not enough', -1 === PressHub_Social_Trending_Short::find_anchor( $norm, 'Facebook' ) );

// 4. attach_plan()
$plan_json = static function ( array $refs ) use ( $script ): string {
    return wp_json_encode( [ 'shorts' => [ [
        'topic'     => 'Τι συζητά η Κύπρος',
        'hook'      => 'Τι συζητούν οι Κύπριοι στα social;',
        'script'    => $script,
        'caption'   => 'Λεζάντα #κύπρος',
        'visuals'   => [ 'A café terrace in Nicosia' ],
        'post_refs' => $refs,
    ] ] ] );
};
$raw    = $plan_json( [
    [ 'post' => 1, 'words' => 'Στο X, ο Νίκος' ],
    [ 'post' => 9, 'words' => 'Στο Facebook, οι κάτοικοι' ],
    [ 'post' => 2, 'words' => 'Στο Facebook, οι κάτοικοι' ],
    [ 'post' => 3, 'words' => 'κάτι που δεν ειπώθηκε ποτέ' ],
    [ 'post' => 1, 'words' => 'Γράψε μας στα σχόλια' ],
] );
$parsed = PressHub_Social_TikTok_Shorts::parse_plan( $raw, 1, 0, 1 );
$with   = PressHub_Social_Trending_Short::attach_plan( $parsed, $raw, $items, 700 );
$refs   = $with[0]['post_refs'] ?? [];
ts_check( 'Three references are placed (unknown post and missing words left out)', 3 === count( $refs ) );
ts_check( 'Two cards: a post named twice has one card', 2 === count( $with[0]['post_cards'] ?? [] ) );
ts_check( 'References are in script order', ( $refs[0]['from'] ?? 99 ) < ( $refs[1]['from'] ?? 0 ) && ( $refs[1]['from'] ?? 99 ) < ( $refs[2]['from'] ?? 0 ) );
ts_check( 'The post named again reuses its card', 0 === ( $refs[0]['card'] ?? -1 ) && 0 === ( $refs[2]['card'] ?? -1 ) && 1 === ( $refs[1]['card'] ?? -1 ) );
ts_check( 'script_words counts the spoken words', count( $words ) === ( $with[0]['script_words'] ?? 0 ) );
$card_x = $with[0]['post_cards'][0] ?? [];
ts_check( 'The card has the author, handle, platform, text and numbers', 'x' === ( $card_x['platform'] ?? '' ) && 'Νίκος Κ.' === ( $card_x['author'] ?? '' ) && 'nikos_cy' === ( $card_x['handle'] ?? '' ) && 1200 === ( $card_x['likes'] ?? 0 ) && 30000 === ( $card_x['views'] ?? 0 ) && str_contains( $card_x['text'] ?? '', 'Αϊκούτ' ) );
ts_check( 'The card carries the post photo', 'https://pbs.twimg.com/media/a.jpg' === ( $card_x['photo'] ?? '' ) );
ts_check( 'A post without a photo has none on its card', ! isset( $with[0]['post_cards'][1]['photo'] ) );
ts_check( 'An http photo is not sent', ! isset( PressHub_Social_Trending_Short::card( $items[2] )['photo'] ) );
ts_check( 'A card without a name uses the handle', '@maria.cy' === PressHub_Social_Trending_Short::card( $items[2] )['author'] );
$none_raw = $plan_json( [] );
$none     = PressHub_Social_Trending_Short::attach_plan( PressHub_Social_TikTok_Shorts::parse_plan( $none_raw, 1, 0, 1 ), $none_raw, $items, 700 );
ts_check( 'A plan without references has no cards', ! isset( $none[0]['post_refs'] ) && ! isset( $none[0]['post_cards'] ) );
$noref_raw = wp_json_encode( [ 'shorts' => [ [ 'topic' => 'a', 'hook' => 'b', 'script' => $script, 'caption' => 'c #κύπρος' ] ] ] );
$noref     = PressHub_Social_Trending_Short::attach_plan( PressHub_Social_TikTok_Shorts::parse_plan( $noref_raw, 1 ), $noref_raw, $items, 700 );
ts_check( 'A plan without the field is left as it is', ! isset( $noref[0]['post_refs'] ) );

// 5. word_timer() / card_spans()
ts_check( 'No timings, no timer', null === PressHub_Social_TikTok_Shorts::word_timer( [], 10 ) );
$timer = PressHub_Social_TikTok_Shorts::word_timer( [ [ 'text' => 'ένα δύο τρία τέσσερα', 'start' => 0.0, 'end' => 4.0 ], [ 'text' => 'πέντε έξι', 'start' => 5.0, 'end' => 7.0 ] ], 6 );
ts_check( 'A word inside the first turn', abs( $timer( 2 ) - 2.0 ) < 0.001 );
ts_check( 'A word inside the second turn', abs( $timer( 5 ) - 6.0 ) < 0.001 );
ts_check( 'Past the end is the end', abs( $timer( 6 ) - 7.0 ) < 0.001 );
$short = $with[0] + [ 'audio' => [ 'segments' => [ [ 'speaker' => 1, 'text' => implode( ' ', $words ), 'start' => 0.0, 'end' => (float) count( $words ) ] ] ] ];
$spans = PressHub_Social_Trending_Short::card_spans( $short );
ts_check( 'One span per reference', 3 === count( $spans ) );
ts_check( 'A span starts where its part starts', abs( ( $spans[0]['start'] ?? -1 ) - $refs[0]['from'] ) < 0.01 && abs( ( $spans[1]['start'] ?? -1 ) - $refs[1]['from'] ) < 0.01 );
ts_check( 'A span ends where the next part starts', abs( ( $spans[0]['end'] ?? -1 ) - $refs[1]['from'] ) < 0.01 );
ts_check( 'The last span never runs past the voice', ( $spans[2]['end'] ?? 99 ) <= count( $words ) + 0.01 && $spans[2]['end'] > $spans[2]['start'] );
ts_check( 'No timings, no spans', [] === PressHub_Social_Trending_Short::card_spans( $with[0] ) );
$lone = PressHub_Social_Trending_Short::card_spans( [ 'post_refs' => [ [ 'card' => 0, 'from' => 0 ] ], 'post_cards' => [ [ 'platform' => 'x' ] ], 'script_words' => 100, 'audio' => [ 'segments' => [ [ 'text' => implode( ' ', array_fill( 0, 100, 'λ' ) ), 'start' => 0, 'end' => 50 ] ] ] ] );
ts_check( 'A lone reference stays on for LONE_REF_WORDS words', 1 === count( $lone ) && abs( $lone[0]['end'] - 15.0 ) < 0.01 );

// 6. get_plan()
update_option( 'presshub_social_tiktok_shorts_images', 1 );
$ai_prompts = [];
PressHub_Social_TikTok_Shorts::set_ai_caller( function ( string $sys, string $user ) use ( &$ai_prompts, $plan_json ) {
    $ai_prompts[] = $user;
    return $plan_json( [ [ 'post' => 1, 'words' => 'Στο X, ο Νίκος' ], [ 'post' => 2, 'words' => 'Στο Facebook, οι κάτοικοι' ] ] );
} );
$plan = PressHub_Social_TikTok_Shorts::get_plan( 700, 1 );
ts_check( 'The roundup plan is made', is_array( $plan ) && 1 === count( $plan['shorts'] ?? [] ) );
ts_check( 'The roundup prompt lists its posts', str_contains( $ai_prompts[0] ?? '', '1. [X] Νίκος Κ.:' ) && str_contains( $ai_prompts[0] ?? '', '"post_refs"' ) );
ts_check( 'The plan keeps the references and cards', 2 === count( $plan['shorts'][0]['post_refs'] ?? [] ) && 2 === count( $plan['shorts'][0]['post_cards'] ?? [] ) );
PressHub_Social_TikTok_Shorts::get_plan( 700, 1 );
ts_check( 'The stored plan is reused', 1 === count( $ai_prompts ) );
$items2 = $items;
array_pop( $items2 );
update_post_meta( 700, PressHub_AI_Social_Trending::META_ITEMS, wp_json_encode( $items2 ) );
PressHub_Social_TikTok_Shorts::get_plan( 700, 1 );
ts_check( 'A new set of posts makes a new plan', 2 === count( $ai_prompts ) );
PressHub_Social_TikTok_Shorts::get_plan( 701, 1 );
ts_check( 'An article that is not a roundup gets no posts block', 3 === count( $ai_prompts ) && ! str_contains( $ai_prompts[2], '"post_refs"' ) );
PressHub_Social_TikTok_Shorts::set_ai_caller( null );

// 7. attach_short()
$prepared = [
    'index' => 0, 'count' => 1, 'total' => 1, 'source' => 'article',
    'topic' => 'Τι συζητά η Κύπρος', 'hook' => 'Hook', 'caption' => 'Λεζάντα #κύπρος', 'mood' => '',
    'images' => [ [ 'url' => 'https://presshub.test/a.jpg', 'id' => 1, 'prompt' => 'A café terrace in Nicosia' ] ], 'images_hash' => 'h',
    'audio'  => [ 'hash' => 'a', 'audio_url' => 'https://presshub.test/v.wav', 'attachment_id' => 2, 'segments' => $short['audio']['segments'] ],
    'post_cards' => $with[0]['post_cards'], 'post_refs' => $with[0]['post_refs'], 'script_words' => $with[0]['script_words'],
];
$job     = [ 'id' => 0, 'post_id' => 700, 'platform' => 'tiktok', 'post_copy' => 'Λεζάντα #κύπρος', 'metadata' => wp_json_encode( [ PressHub_Social_TikTok_Shorts::JOB_META_KEY => $prepared ] ) ];
$claimed = PressHub_Social_TikTok_Shorts::attach_short( $job );
ts_check( 'The claim carries the cards', 2 === count( $claimed['tiktok_short']['cards'] ?? [] ) );
ts_check( 'The claim carries the spans in seconds', 3 === count( $claimed['tiktok_short']['card_spans'] ?? [] ) && isset( $claimed['tiktok_short']['card_spans'][0]['start'] ) );
ts_check( 'The story images stay for the other slots', [ 'https://presshub.test/a.jpg' ] === ( $claimed['tiktok_short']['images'] ?? [] ) );
$plain = $prepared;
unset( $plain['post_cards'], $plain['post_refs'], $plain['script_words'] );
$plain_claim = PressHub_Social_TikTok_Shorts::attach_short( [ 'post_id' => 701 ] + array_merge( $job, [ 'metadata' => wp_json_encode( [ PressHub_Social_TikTok_Shorts::JOB_META_KEY => $plain ] ) ] ) );
ts_check( 'An ordinary article short has no cards', ! isset( $plain_claim['tiktok_short']['cards'] ) && ! isset( $plain_claim['tiktok_short']['card_spans'] ) );

if ( $failures > 0 ) {
    fwrite( STDERR, "{$failures} check(s) failed\n" );
    exit( 1 );
}
echo "OK\n";
