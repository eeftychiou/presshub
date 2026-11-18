<?php
/**
 * PressHub_AI_Publish_Gate + PressHub_AI_QA_Review_Store (2.47.0).
 *
 * The QA gate scores a post three ways (editorial, legal, SEO) in one AI
 * call, each against its own threshold, saves every review and emails the
 * editor when a check fails.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-prompt-loader.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-seo.php';
require_once __DIR__ . '/../includes/class-publish-gate.php';
require_once __DIR__ . '/../includes/class-qa-reviews-admin.php';
require_once __DIR__ . '/../includes/class-qa-held-admin.php';
require_once __DIR__ . '/../includes/class-audit-logger.php';
require_once __DIR__ . '/../includes/class-workflow.php';

if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $key, $value = '' ) {
        unset( $GLOBALS['POST_META_STORE'][ $post_id ][ $key ] );
        return true;
    }
}
if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $cap ) {
        return in_array( $cap, $GLOBALS['PG_USER_CAPS'][ (int) $user_id ] ?? [], true );
    }
}
if ( ! function_exists( 'wp_set_post_tags' ) ) {
    function wp_set_post_tags( $post_id, $tags = '', $append = false ) {
        $GLOBALS['PG_SET_TAGS'][ $post_id ] = (array) $tags;
        return [];
    }
}

$GLOBALS['PG_FAILURES'] = 0;
$GLOBALS['PG_MAILS']    = [];
$GLOBALS['PG_CALLS']    = 0;
$GLOBALS['PG_REPLY']    = '';

function pg_check( $ok, string $label ): void {
    if ( ! $ok ) {
        $GLOBALS['PG_FAILURES']++;
        echo "  FAIL: {$label}\n";
    }
}

function pg_reset( array $options = [] ): void {
    global $wpdb;
    $wpdb->tables          = [];
    $wpdb->auto_increments = [];
    $GLOBALS['OPTIONS_STORE'] = array_merge( [
        'presshub_ai_configured_providers' => presshub_test_provider_keys( 'mock-key' ),
        'presshub_ai_provider'             => 'openai',
    ], $options );
    $GLOBALS['POST_META_STORE'] = [];
    $GLOBALS['POSTS_STORE']     = [];
    $GLOBALS['PG_MAILS']        = [];
    $GLOBALS['PG_CALLS']        = 0;
    PressHub_AI_QA_Review_Store::create_table();
}

/** Every provider call returns $reply (a JSON string). */
function pg_reply( array $reply ): void {
    $GLOBALS['PG_REPLY']       = json_encode( $reply );
    $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
        $GLOBALS['PG_CALLS']++;
        return [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => $GLOBALS['PG_REPLY'] ] ] ] ] ),
        ];
    };
}

$GLOBALS['MOCK_WP_MAIL_CB'] = function ( $to, $subject, $message, $headers ) {
    $GLOBALS['PG_MAILS'][] = [ 'to' => $to, 'subject' => $subject, 'message' => $message ];
    return true;
};

$good_seo_input = [
    'post_id'           => 0,
    'title'             => 'Νέο σχέδιο για τη στέγαση στη Λευκωσία',
    'content'           => '<p>' . str_repeat( 'Λέξη ', 320 ) . '</p><p><a href="https://example.org/a">πηγή</a> <a href="/related">σχετικό</a></p>',
    'excerpt'           => str_repeat( 'Περίληψη ', 15 ),
    'tags'              => [ 'Στέγαση', 'Λευκωσία', 'Κυβέρνηση' ],
    'categories'        => [ 'Ειδήσεις' ],
    'featured_image_id' => 0,
];

$clean = [
    'score' => 90, 'feedback' => 'Καλό.', 'libel_risk' => 'Low', 'legal_concerns' => 'None identified', 'legal_issues' => [],
    'seo'   => [ 'seo_score' => 90, 'focus_keyphrase' => 'στέγαση', 'summary' => 'Καλό.', 'suggestions' => [ 'Πρόσθεσε υπότιτλους' ], 'suggested_tags' => [], 'suggested_meta_description' => '' ],
];

echo "Running PublishGateTest...\n";

// 1. Settings defaults and sanitizers.
pg_reset();
pg_check( 'alert' === PressHub_AI_Settings_Storage::get_qa_legal_action(), 'legal action defaults to alert' );
pg_check( 'high' === PressHub_AI_Settings_Storage::get_qa_legal_fail_risk(), 'legal fail risk defaults to high' );
pg_check( 'alert' === PressHub_AI_Settings_Storage::get_qa_seo_action(), 'SEO action defaults to alert' );
pg_check( 60 === PressHub_AI_Settings_Storage::get_qa_seo_min_score(), 'SEO minimum defaults to 60' );
pg_check( 'alert' === PressHub_AI_Settings_Storage::sanitize_qa_legal_action( 'nonsense' ), 'unknown legal action falls back to the default' );
pg_check( 'off' === PressHub_AI_Settings_Storage::sanitize_qa_legal_action( 'off' ), 'legal action off is kept' );
pg_check( 'alert' === PressHub_AI_Settings_Storage::sanitize_qa_seo_action( 'off' ), 'SEO action has no off (the SEO switch turns it off)' );
pg_check( 100 === PressHub_AI_Settings_Storage::sanitize_qa_seo_min_score( 140 ) && 0 === PressHub_AI_Settings_Storage::sanitize_qa_seo_min_score( -5 ), 'SEO minimum is clamped to 0 - 100' );
echo "  [1] settings: OK\n";

// 2. Verdict from check results.
$v = function ( array $checks ) { return PressHub_AI_Publish_Gate::verdict( $checks ); };
pg_check( 'pass' === $v( [ 'editorial' => [ 'result' => 'pass' ], 'legal' => [ 'result' => 'pass' ], 'seo' => [ 'result' => 'skip' ] ] ), 'all pass -> pass' );
pg_check( 'hold' === $v( [ 'editorial' => [ 'result' => 'fail' ], 'legal' => [ 'result' => 'pass' ] ] ), 'editorial fail -> hold' );
pg_check( 'alert' === $v( [ 'editorial' => [ 'result' => 'pass' ], 'legal' => [ 'result' => 'fail', 'action' => 'alert' ] ] ), 'legal fail with alert -> alert' );
pg_check( 'hold' === $v( [ 'editorial' => [ 'result' => 'pass' ], 'legal' => [ 'result' => 'fail', 'action' => 'alert' ], 'seo' => [ 'result' => 'fail', 'action' => 'hold' ] ] ), 'any hold wins' );
pg_check( 'error' === $v( [ 'editorial' => [ 'result' => 'error' ] ] ), 'review error -> error' );
pg_check( PressHub_AI_Publish_Gate::holds( [ 'verdict' => 'error' ] ) && ! PressHub_AI_Publish_Gate::holds( [ 'verdict' => 'alert' ] ), 'error holds, alert does not' );
echo "  [2] verdict: OK\n";

// 3. A clean post passes; one AI call serves all three checks.
pg_reset();
pg_reply( $clean );
$input = $good_seo_input;
$gate  = PressHub_AI_Publish_Gate::run( $input['content'], 'post', 0, $input );
pg_check( 1 === $GLOBALS['PG_CALLS'], 'one AI call for editorial, legal and SEO; got ' . $GLOBALS['PG_CALLS'] );
pg_check( 'pass' === $gate['checks']['editorial']['result'] && 90 === $gate['checks']['editorial']['score'], 'editorial passes' );
pg_check( 'pass' === $gate['checks']['legal']['result'] && 'Low' === $gate['checks']['legal']['risk'], 'legal passes at Low risk' );
pg_check( 'fail' === $gate['checks']['seo']['result'] && [ 'featured_image' ] === $gate['checks']['seo']['hard_fail'], 'no featured image fails SEO outright' );
pg_check( 'alert' === $gate['verdict'], 'SEO failure alerts by default; got ' . $gate['verdict'] );
pg_check( null !== $gate['checks']['seo']['review']['ai_score'], 'the AI SEO score comes from the same call' );

// With the SEO check off, the same post passes.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
pg_reply( $clean );
$gate = PressHub_AI_Publish_Gate::run( $input['content'], 'post', 0, $input );
pg_check( 'skip' === $gate['checks']['seo']['result'] && 'pass' === $gate['verdict'], 'SEO switch off skips the check' );
echo "  [3] clean post: OK\n";

// 4. Legal thresholds and actions.
$risky = array_merge( $clean, [
    'libel_risk'     => 'Medium',
    'legal_concerns' => 'Ονομάζεται ύποπτος χωρίς απόδοση.',
    'legal_issues'   => [ [ 'type' => 'presumption_of_innocence', 'quote' => 'Ο Χ έκλεψε τα χρήματα.', 'severity' => 'High', 'fix' => 'Σύμφωνα με την αστυνομία, ο Χ κατηγορείται…' ], [ 'type' => 'made_up', 'quote' => '<b>q</b>', 'severity' => 'weird', 'fix' => 'f' ] ],
] );
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
pg_reply( $risky );
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, $input );
pg_check( 'High' === $gate['checks']['legal']['risk'], 'a High issue raises the overall risk to High' );
pg_check( 'fail' === $gate['checks']['legal']['result'] && 'alert' === $gate['verdict'], 'legal High fails and alerts by default' );
pg_check( 'other' === $gate['checks']['legal']['issues'][1]['type'] && 'Low' === $gate['checks']['legal']['issues'][1]['severity'] && 'q' === $gate['checks']['legal']['issues'][1]['quote'], 'issues are normalised and stripped of tags' );
pg_check( 'High' === $gate['libel_risk'], 'scorecard libel_risk follows the raised risk' );

pg_reset( [ 'presshub_ai_seo_review_enabled' => 0, 'presshub_ai_qa_legal_action' => 'hold' ] );
pg_reply( $risky );
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, $input );
pg_check( 'hold' === $gate['verdict'], 'legal action hold holds the post' );

pg_reset( [ 'presshub_ai_seo_review_enabled' => 0, 'presshub_ai_qa_legal_action' => 'off' ] );
pg_reply( $risky );
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, $input );
pg_check( 'skip' === $gate['checks']['legal']['result'] && 'pass' === $gate['verdict'], 'legal action off skips the check' );

$medium = array_merge( $clean, [ 'libel_risk' => 'Medium', 'legal_issues' => [] ] );
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
pg_reply( $medium );
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, $input );
pg_check( 'pass' === $gate['checks']['legal']['result'], 'Medium passes when only High fails' );
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0, 'presshub_ai_qa_legal_fail_risk' => 'medium' ] );
pg_reply( $medium );
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, $input );
pg_check( 'fail' === $gate['checks']['legal']['result'], 'Medium fails when set to Medium or High' );
echo "  [4] legal: OK\n";

// 5. Editorial threshold and review errors.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
pg_reply( array_merge( $clean, [ 'score' => 70 ] ) );
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, $input );
pg_check( 'fail' === $gate['checks']['editorial']['result'] && 'hold' === $gate['verdict'] && 80 === $gate['checks']['editorial']['threshold'], 'score under the editorial minimum holds' );

pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
$GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [];
$GLOBALS['CAPTURE_FILTER'] = null;
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, $input );
pg_check( 'error' === $gate['verdict'] && 'error' === $gate['checks']['legal']['result'], 'no API key -> error verdict, legal not judged' );
pg_check( PressHub_AI_Publish_Gate::holds( $gate ), 'an error holds the post' );
echo "  [5] editorial and errors: OK\n";

// 6. A custom prompt without the SEO part asks the SEO reviewer separately.
pg_reset();
pg_reply( [ 'score' => 90, 'feedback' => 'ok', 'libel_risk' => 'Low', 'legal_concerns' => 'None identified', 'seo_score' => 80 ] );
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, array_merge( $input, [ 'featured_image_id' => 0 ] ) );
pg_check( 2 === $GLOBALS['PG_CALLS'], 'legacy reply: the SEO review is a second call; got ' . $GLOBALS['PG_CALLS'] );
echo "  [6] custom prompt: OK\n";

// 7. review_post saves the review, reuses it while the post is unchanged, and records history.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
pg_reply( $risky );
$GLOBALS['POSTS_STORE'][501] = [ 'ID' => 501, 'post_title' => 'Τίτλος', 'post_content' => 'Κείμενο άρθρου', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => 0 ];
$post = get_post( 501 );
$g1   = PressHub_AI_Publish_Gate::review_post( $post, 'author_publish' );
$g2   = PressHub_AI_Publish_Gate::review_post( $post, 'author_publish' );
pg_check( 1 === $GLOBALS['PG_CALLS'] && ! empty( $g2['cached'] ), 'an unchanged post is not reviewed twice' );
pg_check( is_array( get_post_meta( 501, PressHub_AI_Publish_Gate::META_REVIEW, true ) ), 'latest review stored in post meta' );
$sc = get_post_meta( 501, '_presshub_ai_scorecard', true );
pg_check( 90 === ( $sc['score'] ?? null ) && 'High' === ( $sc['libel_risk'] ?? '' ), 'scorecard meta written for the metabox' );
pg_check( md5( 'Κείμενο άρθρου' ) === get_post_meta( 501, '_presshub_ai_scorecard_hash', true ), 'scorecard hash is the content hash' );
$post2               = clone $post;
$post2->post_content = 'Αλλαγμένο κείμενο';
PressHub_AI_Publish_Gate::review_post( $post2, 'author_publish' );
pg_check( 2 === $GLOBALS['PG_CALLS'], 'changed content is reviewed again' );
PressHub_AI_Publish_Gate::review_post( $post2, 'review_button', [ 'force' => true ] );
pg_check( 3 === $GLOBALS['PG_CALLS'], 'force reviews again' );

$history = PressHub_AI_QA_Review_Store::for_post( 501 );
pg_check( 3 === count( $history ), 'three reviews saved for the post; got ' . count( $history ) );
pg_check( 'review_button' === $history[0]['trigger_type'], 'history is newest first' );
$row = $history[0];
pg_check( 'alert' === $row['verdict'] && 'fail' === $row['legal_result'] && 'High' === $row['legal_risk'] && 90 === (int) $row['editorial_score'] && 80 === (int) $row['editorial_threshold'], 'row columns hold verdict, scores and thresholds' );
pg_check( 'Ο Χ έκλεψε τα χρήματα.' === ( $row['details']['checks']['legal']['issues'][0]['quote'] ?? '' ), 'row details keep the full legal feedback' );
pg_check( 3 === PressHub_AI_QA_Review_Store::count( [ 'failed' => 'legal' ] ), 'filter by failed check' );
// A settings change invalidates the saved review.
$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_legal_action'] = 'hold';
$g3 = PressHub_AI_Publish_Gate::review_post( $post2, 'author_publish' );
pg_check( 4 === $GLOBALS['PG_CALLS'] && empty( $g3['cached'] ) && 'hold' === $g3['verdict'], 'a stricter setting reviews again and holds' );
$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_legal_action'] = 'alert';
// A review that could not run keeps the last good scorecard.
$GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [];
$GLOBALS['CAPTURE_FILTER'] = null;
$ge = PressHub_AI_Publish_Gate::review_post( $post2, 'review_button', [ 'force' => true ] );
$sc = get_post_meta( 501, '_presshub_ai_scorecard', true );
pg_check( 'error' === $ge['verdict'] && 90 === ( $sc['score'] ?? null ), 'an error verdict leaves the scorecard as it was' );
pg_reply( $risky );
pg_check( 1 === PressHub_AI_QA_Review_Store::count( [ 'verdict' => 'hold' ] ) && 1 === PressHub_AI_QA_Review_Store::count( [ 'verdict' => 'error' ] ), 'filter by verdict' );
pg_check( [] === PressHub_AI_QA_Review_Store::sanitize_filters( [ 'verdict' => 'bogus', 'failed' => "x' OR 1=1" ] ), 'unknown filter values are dropped' );
echo "  [7] review_post and history: OK\n";

// 8. Alerts: one email per version, naming each failed check.
$GLOBALS['PG_MAILS'] = [];
PressHub_AI_Publish_Gate::notify( 501, $g1 );
$draft_mail = $GLOBALS['PG_MAILS'][0] ?? [ 'subject' => '' ];
pg_check( false === strpos( $draft_mail['subject'], 'published' ) && false !== strpos( $draft_mail['subject'], 'has QA warnings' ), 'a draft alert does not say published: ' . $draft_mail['subject'] );
$GLOBALS['PG_MAILS'] = [];
$GLOBALS['POST_META_STORE'][501]['_presshub_ai_qa_notified_hash'] = '';
$GLOBALS['POSTS_STORE'][501]['post_status'] = 'publish';
PressHub_AI_Publish_Gate::notify( 501, $g1 );
PressHub_AI_Publish_Gate::notify( 501, $g1 );
pg_check( 1 === count( $GLOBALS['PG_MAILS'] ), 'the same version alerts once; got ' . count( $GLOBALS['PG_MAILS'] ) );
$mail = $GLOBALS['PG_MAILS'][0] ?? [ 'subject' => '', 'message' => '' ];
pg_check( false !== strpos( $mail['subject'], 'published with QA warnings' ) && false !== strpos( $mail['subject'], 'legal High' ), 'subject says it published and names the legal risk: ' . $mail['subject'] );
pg_check( false !== strpos( $mail['message'], 'Ο Χ έκλεψε τα χρήματα.' ) && false !== strpos( $mail['message'], 'Fix:' ), 'body quotes the sentence and the fix' );
pg_check( false !== strpos( $mail['message'], 'presshub-ai-qa-reviews' ), 'body links the QA reviews page' );
$GLOBALS['PG_MAILS'] = [];
PressHub_AI_Publish_Gate::notify( 501, array_merge( $g1, [ 'verdict' => 'pass' ] ) );
pg_check( [] === $GLOBALS['PG_MAILS'], 'a pass sends nothing' );
$GLOBALS['POST_META_STORE'][501]['_presshub_ai_qa_notified_hash'] = '';
$held = array_merge( $g1, [ 'verdict' => 'hold' ] );
$held['checks']['editorial'] = [ 'result' => 'fail', 'score' => 50, 'threshold' => 80, 'feedback' => 'Αδύναμο.' ];
PressHub_AI_Publish_Gate::notify( 501, $held );
$mail = $GLOBALS['PG_MAILS'][0] ?? [ 'subject' => '', 'message' => '' ];
pg_check( false !== strpos( $mail['subject'], 'held by the QA gate' ) && false !== strpos( $mail['subject'], 'editorial 50/80' ), 'held subject names the editorial score: ' . $mail['subject'] );
pg_check( false !== strpos( $mail['message'], 'Held in Pending Review' ), 'held body says Pending Review' );
// After a "could not run" email, the real result for the same text still goes out.
$GLOBALS['PG_MAILS'] = [];
$GLOBALS['POST_META_STORE'][501]['_presshub_ai_qa_notified_hash'] = '';
$err = array_merge( $g1, [ 'verdict' => 'error' ] );
$err['checks']['editorial'] = [ 'result' => 'error', 'error' => 'timeout', 'feedback' => '' ];
PressHub_AI_Publish_Gate::notify( 501, $err );
PressHub_AI_Publish_Gate::notify( 501, $held );
pg_check( 2 === count( $GLOBALS['PG_MAILS'] ), 'an error email does not silence the later result; got ' . count( $GLOBALS['PG_MAILS'] ) );
echo "  [8] alerts: OK\n";

// 9. review_content + attach (Daily Briefing before the post exists).
pg_reset();
pg_reply( $clean );
$bg = PressHub_AI_Publish_Gate::review_content( 'Briefing body', 'briefing', 'briefing_morning' );
pg_check( 'skip' === $bg['checks']['seo']['result'] && 'pass' === $bg['verdict'], 'briefing review skips SEO before the post exists' );
pg_check( 0 === PressHub_AI_QA_Review_Store::count(), 'nothing saved before attach' );
PressHub_AI_Publish_Gate::attach( $bg, 777 );
$rows = PressHub_AI_QA_Review_Store::for_post( 777 );
pg_check( 1 === count( $rows ) && 'briefing_morning' === $rows[0]['trigger_type'] && 'briefing' === $rows[0]['context'], 'attach saves the briefing review' );
pg_check( md5( 'Briefing body' ) === get_post_meta( 777, '_presshub_ai_scorecard_hash', true ), 'attach writes the scorecard hash' );
echo "  [9] briefing: OK\n";

// 10. The QA reviews page escapes what the reviewer wrote.
$xss = PressHub_AI_QA_Reviews_Admin::render_row( [
    'id' => 1, 'post_id' => 0, 'created_at' => '2026-11-18 10:00:00', 'trigger_type' => 'author_publish', 'verdict' => 'alert',
    'editorial_result' => 'pass', 'editorial_score' => 90, 'editorial_threshold' => 80,
    'legal_result' => 'fail', 'legal_risk' => 'High', 'seo_result' => 'skip', 'seo_score' => null, 'seo_threshold' => null,
    'details' => [ 'checks' => [ 'editorial' => [ 'feedback' => '<script>alert(1)</script>' ], 'legal' => [ 'result' => 'fail', 'issues' => [ [ 'severity' => 'High', 'type' => 'defamation', 'quote' => '<img src=x onerror=1>', 'fix' => 'f' ] ] ] ] ],
] );
pg_check( false === strpos( $xss, '<script>' ) && false === strpos( $xss, '<img' ), 'feedback is escaped on the QA reviews page' );
pg_check( false !== strpos( $xss, 'Passed with alerts' ) && false !== strpos( $xss, 'High risk' ), 'row shows the verdict and the legal risk' );
echo "  [10] admin page: OK\n";


// 11. Coverage: automated posts are inserted pending and released by the gate.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
$arr = PressHub_AI_Publish_Gate::prepare_insert( [ 'post_status' => 'publish', 'post_title' => 't' ], 'briefing' );
pg_check( 'pending' === $arr['post_status'] && 'publish' === $arr['meta_input'][ PressHub_AI_Publish_Gate::META_TARGET_STATUS ], 'prepare_insert keeps a publishing briefing pending' );
$arr = PressHub_AI_Publish_Gate::prepare_insert( [ 'post_status' => 'draft' ], 'briefing' );
pg_check( 'draft' === $arr['post_status'] && empty( $arr['meta_input'] ), 'a draft briefing is inserted as it is' );
$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_include_podcasts'] = 0;
$arr = PressHub_AI_Publish_Gate::prepare_insert( [ 'post_status' => 'publish' ], 'podcast' );
pg_check( 'publish' === $arr['post_status'], 'podcasts go straight to their status when the podcast check is off' );
$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_include_podcasts'] = 1;

$mk = function ( int $id, string $status, string $target, array $extra = [] ) {
    $GLOBALS['POSTS_STORE'][ $id ] = array_merge( [ 'ID' => $id, 'post_title' => 'Ενημέρωση', 'post_content' => '<p>Κείμενο ' . $id . '</p>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => $status, 'post_author' => 0 ], $extra );
    if ( '' !== $target ) {
        $GLOBALS['POST_META_STORE'][ $id ][ PressHub_AI_Publish_Gate::META_TARGET_STATUS ] = $target;
    }
};
pg_reply( $clean );
$mk( 601, 'pending', 'publish' );
$g = PressHub_AI_Publish_Gate::release( 601, 'briefing', 'briefing_morning' );
pg_check( 'pass' === $g['verdict'] && 'publish' === $GLOBALS['POSTS_STORE'][601]['post_status'], 'a passing briefing is moved to publish' );
pg_check( '' === (string) get_post_meta( 601, PressHub_AI_Publish_Gate::META_TARGET_STATUS, true ), 'the target status is cleared after release' );
pg_check( 'briefing' === PressHub_AI_QA_Review_Store::for_post( 601 )[0]['context'], 'the release is saved in the history' );

pg_reply( array_merge( $clean, [ 'score' => 40 ] ) );
$mk( 602, 'pending', 'publish' );
$g = PressHub_AI_Publish_Gate::release( 602, 'briefing', 'briefing_noon' );
pg_check( 'hold' === $g['verdict'] && 'pending' === $GLOBALS['POSTS_STORE'][602]['post_status'], 'a failing briefing stays in Pending Review' );
pg_check( 'publish' === get_post_meta( 602, PressHub_AI_Publish_Gate::META_TARGET_STATUS, true ), 'a held post keeps its target for Publish anyway' );
pg_check( 1 === count( $GLOBALS['PG_MAILS'] ), 'the editor is alerted about the held briefing' );

// The roundup: no editorial score, legal decides; a live roundup that fails goes back to pending.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0, 'presshub_ai_qa_legal_action' => 'hold' ] );
pg_reply( array_merge( $risky, [ 'score' => 10 ] ) );
$mk( 603, 'publish', '' );
$g = PressHub_AI_Publish_Gate::release( 603, 'trending', 'trending_roundup' );
pg_check( 'skip' === $g['checks']['editorial']['result'] && 'fail' === $g['checks']['legal']['result'], 'the roundup is judged on legal only, not the editorial score' );
pg_check( 'pending' === $GLOBALS['POSTS_STORE'][603]['post_status'] && 'publish' === get_post_meta( 603, PressHub_AI_Publish_Gate::META_TARGET_STATUS, true ), 'a live roundup that is held goes back to pending' );
pg_reply( array_merge( $clean, [ 'score' => 10 ] ) );
$mk( 604, 'pending', 'future', [ 'post_date' => '2030-01-01 08:00:00' ] );
$g = PressHub_AI_Publish_Gate::release( 604, 'trending', 'trending_roundup' );
pg_check( 'pass' === $g['verdict'] && 'future' === $GLOBALS['POSTS_STORE'][604]['post_status'], 'a low editorial score does not hold the roundup; it goes to its scheduled time' );
$GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [];
$GLOBALS['CAPTURE_FILTER'] = null;
$mk( 605, 'pending', 'publish' );
$g = PressHub_AI_Publish_Gate::release( 605, 'trending', 'trending_roundup' );
pg_check( 'error' === $g['verdict'] && 'pending' === $GLOBALS['POSTS_STORE'][605]['post_status'], 'a roundup whose review cannot run is held' );
$mail = end( $GLOBALS['PG_MAILS'] );
pg_check( $mail && false !== strpos( $mail['subject'], 'Trending roundup' ) && false !== strpos( $mail['subject'], 'review could not run' ), 'the roundup error email says the review could not run: ' . ( $mail['subject'] ?? '' ) );

// Off: the post goes straight to its status, no review.
pg_reset( [ 'presshub_ai_qa_include_trending' => 0 ] );
$mk( 606, 'pending', 'publish' );
pg_check( null === PressHub_AI_Publish_Gate::release( 606, 'trending', 'trending_roundup' ) && 'publish' === $GLOBALS['POSTS_STORE'][606]['post_status'] && 0 === $GLOBALS['PG_CALLS'], 'with the roundup check off it is released unreviewed' );

// Podcast: the script is reviewed with the show notes; SEO is measured only (one call).
pg_reset();
pg_reply( [ 'score' => 85, 'feedback' => 'ok', 'libel_risk' => 'Low', 'legal_concerns' => 'None identified', 'legal_issues' => [] ] );
$mk( 607, 'pending', 'publish' );
$g = PressHub_AI_Publish_Gate::release( 607, 'podcast', 'podcast_morning', [ 'content' => 'Σημειώσεις. Podcast script: Ο παρουσιαστής μιλά.' ] );
pg_check( 1 === $GLOBALS['PG_CALLS'], 'a podcast costs one review call; got ' . $GLOBALS['PG_CALLS'] );
pg_check( 'skip' !== $g['checks']['seo']['result'] && null === ( $g['checks']['seo']['review']['ai_score'] ?? null ), 'the podcast SEO check is the measured checks only' );
pg_check( 'podcast' === PressHub_AI_QA_Review_Store::for_post( 607 )[0]['context'], 'podcast reviews are saved with their context' );
echo "  [11] automated posts: OK\n";

// 12. A review that cannot run marks SEO as an error, not a result.
pg_reset();
$GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers'] = [];
$GLOBALS['CAPTURE_FILTER'] = null;
$gate = PressHub_AI_Publish_Gate::run( 'text', 'post', 0, $good_seo_input );
pg_check( 'error' === $gate['checks']['seo']['result'] && 'error' === $gate['verdict'], 'no SEO result when the review failed' );
$row = PressHub_AI_QA_Reviews_Admin::render_row( [ 'id' => 9, 'post_id' => 0, 'created_at' => 'x', 'trigger_type' => 'author_publish', 'verdict' => 'error', 'editorial_result' => 'error', 'editorial_score' => null, 'editorial_threshold' => 80, 'legal_result' => 'error', 'legal_risk' => '', 'seo_result' => 'error', 'seo_score' => null, 'seo_threshold' => 60, 'details' => [ 'checks' => $gate['checks'] ] ] );
pg_check( false !== strpos( $row, 'The legal check could not run' ), 'the Legal heading explains an error instead of standing empty' );
echo "  [12] review errors: OK\n";

// 13. SEO fix: the suggested excerpt and tags fill the empty fields, then the checks run again.
pg_reset( [ 'presshub_ai_seo_auto_enabled' => 1, 'presshub_ai_qa_seo_min_score' => 95 ] );
$GLOBALS['PG_SET_TAGS'] = [];
pg_reply( array_merge( $clean, [ 'seo' => [ 'seo_score' => 70, 'focus_keyphrase' => 'στέγαση', 'summary' => '', 'suggestions' => [], 'suggested_tags' => [ 'Στέγαση', 'Λευκωσία' ], 'suggested_meta_description' => 'Το νέο σχέδιο στέγασης της κυβέρνησης για τη Λευκωσία, τι αλλάζει και ποιοι δικαιούνται βοήθεια από τον Ιανουάριο.' ] ] ) );
$GLOBALS['POSTS_STORE'][608] = [ 'ID' => 608, 'post_title' => $good_seo_input['title'], 'post_content' => $good_seo_input['content'], 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => 0 ];
$bare = array_merge( $good_seo_input, [ 'post_id' => 608, 'excerpt' => '', 'tags' => [], 'featured_image_id' => 55 ] );
$before = PressHub_AI_SEO::run_checks( $bare );
$g = PressHub_AI_Publish_Gate::review_post( (object) $GLOBALS['POSTS_STORE'][608], 'author_publish', [ 'seo_input' => $bare, 'force' => true ] );
pg_check( [ 'excerpt', 'tags' ] === ( $g['checks']['seo']['fixed'] ?? [] ), 'the gate filled the excerpt and the tags: ' . json_encode( $g['checks']['seo']['fixed'] ?? null ) );
pg_check( (int) $g['checks']['seo']['review']['checks_score'] > (int) $before['score'], 'the measured SEO score rose after the fix' );
pg_check( [ 'Στέγαση', 'Λευκωσία' ] === ( $GLOBALS['PG_SET_TAGS'][608] ?? null ), 'the suggested tags were set on the post' );
$fixed_input = array_merge( $bare, [ 'excerpt' => 'Το νέο σχέδιο στέγασης της κυβέρνησης για τη Λευκωσία, τι αλλάζει και ποιοι δικαιούνται βοήθεια από τον Ιανουάριο.', 'tags' => [ 'Στέγαση', 'Λευκωσία' ] ] );
$calls = $GLOBALS['PG_CALLS'];
$again = PressHub_AI_Publish_Gate::review_post( (object) $GLOBALS['POSTS_STORE'][608], 'scheduled_publish', [ 'seo_input' => $fixed_input ] );
pg_check( ! empty( $again['cached'] ) && $calls === $GLOBALS['PG_CALLS'], 'the review is cached under the fixed version, so go-live does not review again' );
$GLOBALS['PG_SET_TAGS'] = [];
pg_reply( array_merge( $clean, [ 'seo' => [ 'seo_score' => 10, 'suggested_tags' => [ 'Χ' ], 'suggested_meta_description' => '' ] ] ) );
$g = PressHub_AI_Publish_Gate::review_post( (object) $GLOBALS['POSTS_STORE'][608], 'author_publish', [ 'seo_input' => array_merge( $bare, [ 'tags_from_request' => true ] ), 'force' => true ] );
pg_check( empty( $GLOBALS['PG_SET_TAGS'] ), 'tags sent with the editor save are left alone' );
echo "  [13] SEO fix: OK\n";

// 14. Editors and scheduled posts, and Publish anyway.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
$wf = new PressHub_AI_Workflow();
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts', 'edit_post' ];
pg_reply( array_merge( $clean, [ 'score' => 40 ] ) );
$mk( 701, 'publish', '' );
$GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
$wf->enforce_editorial_workflow( 'publish', 'draft', (object) $GLOBALS['POSTS_STORE'][701] );
pg_check( 'pending' === $GLOBALS['POSTS_STORE'][701]['post_status'], 'an editor publish that fails is held' );
pg_check( 'editor_publish' === PressHub_AI_QA_Review_Store::for_post( 701 )[0]['trigger_type'], 'the trigger says an editor published' );

$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_check_editors'] = 0;
$mk( 702, 'publish', '' );
$wf->enforce_editorial_workflow( 'publish', 'draft', (object) $GLOBALS['POSTS_STORE'][702] );
pg_check( 'publish' === $GLOBALS['POSTS_STORE'][702]['post_status'] && 0 === count( PressHub_AI_QA_Review_Store::for_post( 702 ) ), 'with editor checks off, editors are not reviewed' );
$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_check_editors'] = 1;

// Scheduling is reviewed; going live by cron reuses that review when nothing changed.
pg_reply( $clean );
$GLOBALS['PG_CALLS'] = 0;
$mk( 703, 'future', '' );
$wf->enforce_editorial_workflow( 'future', 'draft', (object) $GLOBALS['POSTS_STORE'][703] );
pg_check( 1 === $GLOBALS['PG_CALLS'] && 'future' === $GLOBALS['POSTS_STORE'][703]['post_status'], 'a scheduled post is reviewed when it is scheduled' );
$GLOBALS['WP_DOING_CRON'] = true;
$GLOBALS['CURRENT_USER_CAPS'] = [];
$wf->enforce_editorial_workflow( 'publish', 'future', (object) array_merge( $GLOBALS['POSTS_STORE'][703], [ 'post_status' => 'publish' ] ) );
pg_check( 1 === $GLOBALS['PG_CALLS'], 'going live unchanged reuses the saved review; calls: ' . $GLOBALS['PG_CALLS'] );
pg_reply( array_merge( $clean, [ 'score' => 30 ] ) );
$GLOBALS['POSTS_STORE'][703]['post_content'] = '<p>Άλλαξε πριν βγει.</p>';
$wf->enforce_editorial_workflow( 'publish', 'future', (object) array_merge( $GLOBALS['POSTS_STORE'][703], [ 'post_status' => 'publish' ] ) );
pg_check( 'pending' === $GLOBALS['POSTS_STORE'][703]['post_status'] && 'scheduled_publish' === PressHub_AI_QA_Review_Store::for_post( 703 )[0]['trigger_type'], 'a scheduled post edited since and now failing is held at go-live' );
$GLOBALS['WP_DOING_CRON'] = false;

// With editor checks off, a post an editor scheduled is not held by cron at go-live.
$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_check_editors'] = 0;
$GLOBALS['PG_USER_CAPS'] = [ 9 => [ 'edit_others_posts' ] ];
$GLOBALS['PG_CALLS'] = 0;
$mk( 704, 'publish', '' );
$GLOBALS['POST_META_STORE'][704]['_edit_last'] = 9;
$GLOBALS['WP_DOING_CRON'] = true;
$wf->enforce_editorial_workflow( 'publish', 'future', (object) $GLOBALS['POSTS_STORE'][704] );
pg_check( 0 === $GLOBALS['PG_CALLS'] && 'publish' === $GLOBALS['POSTS_STORE'][704]['post_status'], 'an editor-scheduled post goes live unreviewed when editor checks are off' );
$GLOBALS['POST_META_STORE'][704]['_edit_last'] = 3; // an author
pg_reply( array_merge( $clean, [ 'score' => 30 ] ) );
$wf->enforce_editorial_workflow( 'publish', 'future', (object) $GLOBALS['POSTS_STORE'][704] );
pg_check( 'pending' === $GLOBALS['POSTS_STORE'][704]['post_status'], 'an author-scheduled post is still checked at go-live' );
$GLOBALS['WP_DOING_CRON'] = false;
$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_check_editors'] = 1;

// Publish anyway on the held editor post.
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts', 'edit_post' ];
$r = PressHub_AI_Publish_Gate::publish_anyway( 702, 'x' );
pg_check( is_wp_error( $r ) && 'presshub_qa_not_held' === $r->get_error_code(), 'Publish anyway refuses a post that is not held' );
$saved701 = $GLOBALS['POSTS_STORE'][701];
$GLOBALS['POSTS_STORE'][701]['post_content'] = '<p>Αλλαγμένο μετά την κράτηση.</p>';
$r = PressHub_AI_Publish_Gate::publish_anyway( 701, 'x' );
pg_check( is_wp_error( $r ) && 'presshub_qa_changed' === $r->get_error_code() && 'pending' === $GLOBALS['POSTS_STORE'][701]['post_status'], 'Publish anyway refuses a version the gate did not review' );
$GLOBALS['POSTS_STORE'][701] = $saved701;
$r = PressHub_AI_Publish_Gate::publish_anyway( 701, '' );
pg_check( is_wp_error( $r ), 'a reason is required' );
$r = PressHub_AI_Publish_Gate::publish_anyway( 701, 'Επιβεβαιωμένο από τον αρχισυντάκτη.' );
pg_check( true === $r && 'publish' === $GLOBALS['POSTS_STORE'][701]['post_status'], 'Publish anyway publishes the held post' );
$hist = PressHub_AI_QA_Review_Store::for_post( 701 );
pg_check( 'Επιβεβαιωμένο από τον αρχισυντάκτη.' === ( $hist[0]['override_reason'] ?? '' ), 'the reason is saved on the review row' );
$audit = array_values( array_filter( $wpdb->tables[ PressHub_AI_Audit_Logger::get_table_name() ] ?? [], function ( $r ) { return 'qa_gate_override' === ( $r['event_type'] ?? '' ); } ) );
pg_check( 1 === count( $audit ), 'a qa_gate_override event is in the audit log' );
$GLOBALS['PG_CALLS'] = 0;
$GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
$wf->enforce_editorial_workflow( 'publish', 'pending', (object) $GLOBALS['POSTS_STORE'][701] );
pg_check( 0 === $GLOBALS['PG_CALLS'] && 'publish' === $GLOBALS['POSTS_STORE'][701]['post_status'], 'the released version is not held again' );
$GLOBALS['CURRENT_USER_CAPS'] = [];
echo "  [14] editors, schedules and Publish anyway: OK\n";

// 15. Any check that errors makes the verdict an error.
pg_check( 'error' === PressHub_AI_Publish_Gate::verdict( [ 'editorial' => [ 'result' => 'pass' ], 'legal' => [ 'result' => 'skip' ], 'seo' => [ 'result' => 'error' ] ] ), 'an SEO error is an error verdict' );

// A new post created through REST: its terms move to its ID on its first transition and are not reused.
pg_reset();
$wf = new PressHub_AI_Workflow();
$req = new class { public function get_param( $k ) { return 'tags' === $k ? [ 5 ] : null; } };
$wf->remember_rest_terms( (object) [ 'post_type' => 'post' ], $req );
$mk( 801, 'draft', '' );
$wf->enforce_editorial_workflow( 'draft', 'new', (object) $GLOBALS['POSTS_STORE'][801] );
$state = ( new ReflectionProperty( 'PressHub_AI_Workflow', 'rest_state' ) );
$state->setAccessible( true );
$st = $state->getValue();
pg_check( ! isset( $st[0] ) && isset( $st[801] ), 'REST terms of a new post move to its ID and do not leak to the next post' );
echo "  [15] verdict and REST terms: OK\n";

// 16. Held podcasts can be released with Publish anyway; a hand publish of a held briefing is checked.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
pg_reply( array_merge( $clean, [ 'score' => 30 ] ) );
$mk( 901, 'pending', 'publish' );
$GLOBALS['POST_META_STORE'][901]['_presshub_briefing_type']  = 'podcast';
$GLOBALS['POST_META_STORE'][901]['_presshub_podcast_script'] = '[SPEAKER_1]: Καλημέρα.';
$g = PressHub_AI_Publish_Gate::release( 901, 'podcast', 'podcast_morning' );
pg_check( 'hold' === $g['verdict'] && 'pending' === $GLOBALS['POSTS_STORE'][901]['post_status'], 'a failing podcast is held' );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts', 'edit_post' ];
$r = PressHub_AI_Publish_Gate::publish_anyway( 901, 'Ελέγχθηκε.' );
pg_check( true === $r && 'publish' === $GLOBALS['POSTS_STORE'][901]['post_status'], 'Publish anyway releases a held podcast: ' . ( is_wp_error( $r ) ? $r->get_error_code() : 'ok' ) );
pg_check( PressHub_AI_Publish_Gate::is_overridden( (object) $GLOBALS['POSTS_STORE'][901], 'podcast' ), 'the released podcast version is recognised' );

$mk( 902, 'pending', 'publish' );
$GLOBALS['POST_META_STORE'][902]['_presshub_briefing_type'] = 'text';
PressHub_AI_Publish_Gate::release( 902, 'briefing', 'briefing_morning' );
$calls = $GLOBALS['PG_CALLS'];
$GLOBALS['POSTS_STORE'][902]['post_status'] = 'publish';
( new PressHub_AI_Workflow() )->enforce_editorial_workflow( 'publish', 'pending', (object) $GLOBALS['POSTS_STORE'][902] );
pg_check( 'pending' === $GLOBALS['POSTS_STORE'][902]['post_status'] && $calls === $GLOBALS['PG_CALLS'], 'a held briefing published by hand from the editor stays held (saved review reused)' );
$GLOBALS['CURRENT_USER_CAPS'] = [];

// A live roundup with a new set of posts is off line while it is reviewed.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
$mk( 903, 'publish', '' );
PressHub_AI_Publish_Gate::hold_for_review( 903 );
pg_check( 'pending' === $GLOBALS['POSTS_STORE'][903]['post_status'] && 'publish' === get_post_meta( 903, PressHub_AI_Publish_Gate::META_TARGET_STATUS, true ), 'hold_for_review takes a live post off line and keeps its status' );
pg_reply( $clean );
PressHub_AI_Publish_Gate::release( 903, 'trending', 'trending_roundup' );
pg_check( 'publish' === $GLOBALS['POSTS_STORE'][903]['post_status'], 'and release puts it back once it passes' );

// The email of an error verdict still shows an editorial failure.
list( $subject, $body ) = ( function () {
    $m = new ReflectionMethod( 'PressHub_AI_QA_Reviewer', 'gate_email' );
    $m->setAccessible( true );
    return $m->invoke( null, [ 'verdict' => 'error', 'checks' => [ 'editorial' => [ 'result' => 'fail', 'score' => 40, 'threshold' => 80, 'feedback' => 'Αδύναμο.' ], 'legal' => [ 'result' => 'pass' ], 'seo' => [ 'result' => 'error' ] ] ], 'post', 'Site', 'Τίτλος', 'A', 'u', 1, false );
} )();
pg_check( false !== strpos( $body, 'EDITORIAL: 40 / 100' ) && false !== strpos( $body, 'could not run' ), 'the error email keeps the editorial block' );
echo "  [16] podcasts, held briefings, roundups and emails: OK\n";

// 17. Held by QA list, Posts column, editor panel (2.51.0) and the PR 2 follow-ups.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0 ] );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts', 'edit_post' ];
pg_reply( array_merge( $clean, [ 'score' => 40, 'libel_risk' => 'High', 'legal_concerns' => 'Ισχυρισμός χωρίς πηγή.', 'legal_issues' => [ [ 'type' => 'defamation', 'quote' => 'είναι απατεώνας', 'severity' => 'High', 'fix' => 'Αφαίρεσε τον χαρακτηρισμό.' ] ] ] ) );
$mk( 1001, 'pending', 'publish' );
PressHub_AI_Publish_Gate::release( 1001, 'briefing', 'briefing_morning' );
pg_reply( $clean );
$mk( 1002, 'pending', 'publish' );
PressHub_AI_Publish_Gate::release( 1002, 'briefing', 'briefing_noon' ); // passes and goes live
$mk( 1003, 'pending', '' ); // pending for another reason, never reviewed
$GLOBALS['GET_POSTS_RESULT'] = [ 1001, 1002, 1003 ]; // fields => ids
pg_check( [ 1001 ] === PressHub_AI_QA_Held_Admin::held_ids(), 'only the post the gate is holding is listed: ' . json_encode( PressHub_AI_QA_Held_Admin::held_ids() ) );
$held = PressHub_AI_QA_Held_Admin::held_posts();
pg_check( 1 === count( $held ) && 1001 === (int) $held[0]['post']->ID, 'held_posts loads the held post with its review' );
$summary = PressHub_AI_QA_Held_Admin::failed_summary( $held[0]['review'] );
pg_check( 'Editorial 40 (needs 80); Legal High risk' === $summary, 'the list says what failed, scores read against their minimum: ' . $summary );
pg_check( 'SEO could not run' === PressHub_AI_QA_Held_Admin::failed_summary( [ 'verdict' => 'error', 'checks' => [ 'editorial' => [ 'result' => 'pass' ], 'seo' => [ 'result' => 'error' ] ] ] ), 'an error names the check that could not run' );
$table = PressHub_AI_QA_Held_Admin::render_table( $held );
pg_check( false !== strpos( $table, 'override=1001' ) && false !== strpos( $table, 'Publish anyway' ), 'the list links to Publish anyway' );
pg_check( false !== strpos( $table, 'data-colname="What failed"' ), 'cells carry their column name for the narrow layout' );
pg_check( false === strpos( $table, '+00:00' ) && 1 === preg_match( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $table ), 'times are shown in the site format, not raw ISO' );
$page = PressHub_AI_QA_Held_Admin::page_html( $held, 120, 2 );
pg_check( false !== strpos( $page, 'Showing 51–51 of 120' ) && false !== strpos( $page, 'Page 2 of 3' ) && false !== strpos( $page, 'paged=1' ) && false !== strpos( $page, 'paged=3' ), 'the page is paged' );
pg_check( false !== strpos( PressHub_AI_QA_Held_Admin::page_html( [], 0 ), 'No posts are held' ), 'the empty page says nothing is held' );
$GLOBALS['CURRENT_USER_CAPS'] = [];
pg_check( false === strpos( PressHub_AI_QA_Held_Admin::render_table( $held ), 'Publish anyway' ), 'no Publish anyway without the editor capability' );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts', 'edit_post' ];
pg_check( false !== strpos( PressHub_AI_QA_Held_Admin::notice_html( 2 ), '2 posts are held' ) && false !== strpos( PressHub_AI_QA_Held_Admin::notice_html( 2 ), 'presshub-ai-qa-held' ), 'the notice counts held posts and links to the list' );
pg_check( 1 === PressHub_AI_QA_Held_Admin::held_count(), 'the notice count' );
$GLOBALS['GET_POSTS_RESULT'] = [];
pg_check( 1 === PressHub_AI_QA_Held_Admin::held_count(), 'the count is cached between page loads' );
PressHub_AI_QA_Held_Admin::flush_count();
pg_check( 0 === PressHub_AI_QA_Held_Admin::held_count(), 'and cleared on a status change' );
PressHub_AI_QA_Held_Admin::flush_count();
$GLOBALS['GET_POSTS_RESULT'] = [ 1001, 1002, 1003 ];

$badges = PressHub_AI_QA_Held_Admin::badges( get_post_meta( 1001, PressHub_AI_Publish_Gate::META_REVIEW, true ) );
pg_check( false !== strpos( $badges, 'presshub-qa-badge-fail" title="Editorial: failed, 40 (needs 80)"' ), 'the editorial badge carries the score: ' . $badges );
pg_check( false !== strpos( $badges, '>E ✕<' ) && false !== strpos( $badges, '>S –<' ), 'each badge has a symbol, not only a colour' );
pg_check( false !== strpos( $badges, 'title="Legal: failed, High risk"' ) && false !== strpos( $badges, 'title="SEO: not checked"' ), 'legal shows the risk, SEO not checked' );
pg_check( false !== strpos( $badges, 'Editorial: failed, 40 (needs 80). </span>' ), 'screen-reader texts are separated' );
$cols = PressHub_AI_QA_Held_Admin::add_column( [ 'cb' => '', 'title' => 'Title', 'author' => 'Author' ] );
pg_check( [ 'cb', 'title', 'presshub_qa', 'author' ] === array_keys( $cols ), 'the QA column sits after the title' );
ob_start();
PressHub_AI_QA_Held_Admin::render_column( 'presshub_qa', 1003 );
pg_check( false !== strpos( (string) ob_get_clean(), 'Not reviewed' ), 'an unreviewed post shows a dash' );
ob_start();
PressHub_AI_QA_Held_Admin::render_column( 'presshub_qa', 1001 );
pg_check( false !== strpos( (string) ob_get_clean(), 'presshub-qa-failed" aria-hidden="true">Editorial 40 (needs 80); Legal High risk' ), 'the column shows what failed in words' );

$panel = PressHub_AI_QA_Held_Admin::metabox_html( 1001 );
pg_check( false !== strpos( $panel, 'Held for review' ) && false !== strpos( $panel, 'Editorial: failed, 40 (needs 80)' ) && false !== strpos( $panel, 'is-held' ), 'the editor panel shows the verdict and the editorial score' );
pg_check( false !== strpos( $panel, 'είναι απατεώνας' ) && false !== strpos( $panel, 'Αφαίρεσε τον χαρακτηρισμό.' ), 'the editor panel lists the legal issues with their fixes' );
pg_check( false !== strpos( $panel, 'override=1001' ) && false !== strpos( $panel, 'target="_blank"' ) && false !== strpos( $panel, 'Review history (1)' ), 'Publish anyway opens in a new tab; the history has its count' );
pg_check( false !== strpos( PressHub_AI_QA_Held_Admin::metabox_slot( 1001 ), 'id="presshub-qa-gate-panel-slot"' ), 'the panel sits in a slot the editor script refreshes' );
$panel = PressHub_AI_QA_Held_Admin::metabox_html( 1002 );
pg_check( false !== strpos( $panel, 'Passed' ) && false === strpos( $panel, 'override=' ), 'a passed post has no Publish anyway' );
pg_check( '' === PressHub_AI_QA_Held_Admin::metabox_html( 1003 ), 'no panel for a post the gate never reviewed' );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_post' ]; // an author
pg_check( false === strpos( PressHub_AI_QA_Held_Admin::metabox_html( 1001 ), 'All reviews of this post' ), 'authors get no link to the QA reviews page' );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts', 'edit_post' ];
PressHub_AI_Publish_Gate::publish_anyway( 1001, 'Ελέγχθηκε από νομικό.' );
$panel = PressHub_AI_QA_Held_Admin::metabox_html( 1001 );
pg_check( false !== strpos( $panel, 'Released with Publish anyway' ) && false === strpos( $panel, 'Held for review' ), 'after Publish anyway the panel says it was released: ' . strip_tags( $panel ) );
pg_check( false !== strpos( $panel, 'Published anyway: Ελέγχθηκε από νομικό.' ), 'the history shows the Publish anyway reason' );
$GLOBALS['GET_POSTS_RESULT'] = [];

// The SEO fix keeps the saved keyphrase and description it does not set.
pg_reset( [ 'presshub_ai_seo_auto_enabled' => 1, 'presshub_ai_qa_seo_min_score' => 95 ] );
pg_reply( array_merge( $clean, [ 'seo' => [ 'seo_score' => 70, 'focus_keyphrase' => 'άλλο', 'summary' => '', 'suggestions' => [], 'suggested_tags' => [ 'Στέγαση' ], 'suggested_meta_description' => '' ] ] ) );
$GLOBALS['POSTS_STORE'][1010] = [ 'ID' => 1010, 'post_title' => $good_seo_input['title'], 'post_content' => $good_seo_input['content'], 'post_excerpt' => 'Υπάρχει', 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => 0 ];
$GLOBALS['POST_META_STORE'][1010][ PressHub_AI_SEO::META_SEO ] = [ 'tags' => [], 'meta_description' => 'Η αποθηκευμένη περιγραφή.', 'focus_keyphrase' => 'στέγαση' ];
PressHub_AI_Publish_Gate::review_post( (object) $GLOBALS['POSTS_STORE'][1010], 'author_publish', [ 'seo_input' => array_merge( $good_seo_input, [ 'post_id' => 1010, 'tags' => [], 'featured_image_id' => 55 ] ), 'force' => true ] );
$meta = get_post_meta( 1010, PressHub_AI_SEO::META_SEO, true );
pg_check( 'στέγαση' === ( $meta['focus_keyphrase'] ?? '' ) && 'Η αποθηκευμένη περιγραφή.' === ( $meta['meta_description'] ?? '' ) && [ 'Στέγαση' ] === ( $meta['tags'] ?? null ), 'the SEO fix keeps the saved keyphrase and description: ' . json_encode( $meta, JSON_UNESCAPED_UNICODE ) );

// The last person to save a scheduled post decides the editor opt-out at go-live.
pg_reset( [ 'presshub_ai_seo_review_enabled' => 0, 'presshub_ai_qa_check_editors' => 0 ] );
$wf = new PressHub_AI_Workflow();
$GLOBALS['PG_USER_CAPS'] = [ 9 => [ 'edit_others_posts' ], 3 => [] ];
$GLOBALS['CURRENT_USER_ID'] = 9;
$mk( 1020, 'future', '' );
$wf->remember_scheduler( 1020, (object) $GLOBALS['POSTS_STORE'][1020] );
pg_check( 9 === (int) get_post_meta( 1020, PressHub_AI_Publish_Gate::META_SCHEDULED_BY, true ), 'saving a scheduled post remembers who saved it' );
$GLOBALS['POST_META_STORE'][1020]['_edit_last'] = 3; // not trusted: REST may leave it stale
$GLOBALS['CURRENT_USER_ID'] = 0;
$GLOBALS['CURRENT_USER_CAPS'] = [];
$GLOBALS['WP_DOING_CRON'] = true;
$GLOBALS['PG_CALLS'] = 0;
pg_reply( array_merge( $clean, [ 'score' => 30 ] ) );
$wf->enforce_editorial_workflow( 'publish', 'future', (object) array_merge( $GLOBALS['POSTS_STORE'][1020], [ 'post_status' => 'publish' ] ) );
pg_check( 0 === $GLOBALS['PG_CALLS'], 'a post an editor scheduled goes live unreviewed with editor checks off' );
$GLOBALS['WP_DOING_CRON'] = false;
// An author edits it while it is scheduled: it is checked at go-live.
$mk( 1021, 'future', '' );
$GLOBALS['CURRENT_USER_ID'] = 9;
$wf->remember_scheduler( 1021, (object) $GLOBALS['POSTS_STORE'][1021] );
$GLOBALS['CURRENT_USER_ID'] = 3;
$wf->remember_scheduler( 1021, (object) $GLOBALS['POSTS_STORE'][1021] );
$GLOBALS['CURRENT_USER_ID'] = 0;
$GLOBALS['WP_DOING_CRON'] = true;
$wf->remember_scheduler( 1021, (object) $GLOBALS['POSTS_STORE'][1021] ); // cron saves change nothing
pg_check( 3 === (int) get_post_meta( 1021, PressHub_AI_Publish_Gate::META_SCHEDULED_BY, true ), 'the author who edited last is remembered' );
$wf->enforce_editorial_workflow( 'publish', 'future', (object) array_merge( $GLOBALS['POSTS_STORE'][1021], [ 'post_status' => 'publish' ] ) );
pg_check( 'pending' === $GLOBALS['POSTS_STORE'][1021]['post_status'], 'an author edit to an editor-scheduled post is checked at go-live' );
$GLOBALS['WP_DOING_CRON'] = false;
echo "  [17] held list, column, editor panel, SEO meta, scheduled by: OK\n";

if ( $GLOBALS['PG_FAILURES'] > 0 ) {
    echo "PublishGateTest: {$GLOBALS['PG_FAILURES']} failure(s)\n";
    exit( 1 );
}
echo "PublishGateTest: OK\n";
