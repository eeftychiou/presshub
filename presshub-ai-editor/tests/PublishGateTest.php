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
pg_check( 0 === PressHub_AI_QA_Review_Store::count( [ 'verdict' => 'hold' ] ), 'filter by verdict' );
pg_check( [] === PressHub_AI_QA_Review_Store::sanitize_filters( [ 'verdict' => 'bogus', 'failed' => "x' OR 1=1" ] ), 'unknown filter values are dropped' );
echo "  [7] review_post and history: OK\n";

// 8. Alerts: one email per version, naming each failed check.
$GLOBALS['PG_MAILS'] = [];
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

if ( $GLOBALS['PG_FAILURES'] > 0 ) {
    echo "PublishGateTest: {$GLOBALS['PG_FAILURES']} failure(s)\n";
    exit( 1 );
}
echo "PublishGateTest: OK\n";
