<?php
/**
 * FactChecksTest — fact checks kept in the editorial memory (2.80.0).
 *
 *  1. Shape: the memory validator keeps fact_checks only when valid, drops
 *     unknown verdicts and vague match phrases, and leaves old content as it was.
 *  2. Register: active digests of the last N days, newest first, one per claim.
 *  3. Matching: whole phrases in folded text, Greek accents and case ignored.
 *  4. Injection: budget per action (none for memory building), block appended,
 *     the generation record gets the prompt as sent and the claims.
 *  5. Trending roundup: the summary call gets the block, the reply's
 *     fact_checks become notes from the stored check (unknown refs dropped),
 *     notes render under the post, the summary is redone when a new fact
 *     check matches.
 *  6. Memory page: the list and Remove.
 *
 * The case is the "Free Cyprus" graffiti photo that FactCheck Cyprus found
 * altered (brief of 20/11/2026) and that topped the roundup of 21/11.
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
require_once dirname( __DIR__ ) . '/includes/class-memory-store.php';
require_once dirname( __DIR__ ) . '/includes/class-generation-recorder.php';
require_once dirname( __DIR__ ) . '/includes/class-memory-editor.php';

$failures = 0;
$checks   = 0;
function fc_check( string $label, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        fwrite( STDERR, "FAIL: {$label}\n" );
    }
}

$F = 'PressHub_AI_Fact_Checks';

global $wpdb;
$wpdb->tables          = [];
$wpdb->queries         = [];
$wpdb->auto_increments = [];
$GLOBALS['OPTIONS_STORE']['presshub_ai_memory_enabled'] = 1;

$munich = [
    'claim'      => 'Φωτογραφία με γκράφιτι «Free Cyprus» στο Μόναχο',
    'verdict'    => 'manipulated',
    'checked_by' => 'FactCheck Cyprus',
    'finding'    => 'Η φωτογραφία είναι προϊόν ψηφιακής παραποίησης',
    'match'      => [ 'Free Cyprus', 'γκράφιτι Μόναχο', 'Κύπρος' ],
    'source_url' => 'https://factcheckcyprus.org/free-cyprus',
];

function fc_seed( string $date, array $fact_checks, string $status = 'active', string $edition = 'morning' ): int {
    $res = PressHub_AI_Memory_Store::upsert_generated( [
        'scope'             => 'daily',
        'period_start'      => $date,
        'period_end'        => $date,
        'edition'           => $edition,
        'generated_content' => [
            'stories'     => [ [ 'key' => 'story-' . $date, 'title' => 'Θέμα', 'development' => 'Εξέλιξη', 'status' => 'new', 'entities' => [], 'source_urls' => [], 'include' => true ] ],
            'podcast'     => [ 'opening_line' => '', 'closing_line' => '' ],
            'narrative'   => '',
            'fact_checks' => $fact_checks,
        ],
        'status'            => $status,
    ] );
    PressHub_AI_Fact_Checks::flush_cache();
    return is_array( $res ) ? (int) $res['id'] : 0;
}

// 1. Shape.
$base = [ 'stories' => [], 'podcast' => [ 'opening_line' => '', 'closing_line' => '' ], 'narrative' => '' ];
$v    = PressHub_AI_Memory_Store::validate_content( $base );
fc_check( 'shape: content without fact checks unchanged', ! is_wp_error( $v ) && ! array_key_exists( 'fact_checks', $v ) );
$v = PressHub_AI_Memory_Store::validate_content( $base + [ 'fact_checks' => [] ] );
fc_check( 'shape: empty list not kept', ! is_wp_error( $v ) && ! array_key_exists( 'fact_checks', $v ) );
$v = PressHub_AI_Memory_Store::validate_content( $base + [ 'fact_checks' => [ $munich, [ 'claim' => 'x', 'verdict' => 'maybe', 'match' => [ 'Free Cyprus' ] ], [ 'claim' => 'Κάτι', 'verdict' => 'false', 'match' => [ 'Κύπρος' ] ], $munich ] ] );
fc_check( 'shape: valid kept, bad verdict, no usable phrase and duplicate dropped', ! is_wp_error( $v ) && 1 === count( $v['fact_checks'] ?? [] ) );
fc_check( 'shape: vague one-word phrase dropped', [ 'Free Cyprus', 'γκράφιτι Μόναχο' ] === ( $v['fact_checks'][0]['match'] ?? null ) );
fc_check( 'shape: fields kept', 'manipulated' === $v['fact_checks'][0]['verdict'] && 'FactCheck Cyprus' === $v['fact_checks'][0]['checked_by'] && 'https://factcheckcyprus.org/free-cyprus' === $v['fact_checks'][0]['source_url'] );
fc_check( 'phrase: one word alone is too vague', '' === $F::phrase_pattern( 'Κύπρος' ) && '' !== $F::phrase_pattern( 'Free Cyprus' ) && '' !== $F::phrase_pattern( 'Μόναχο γκράφιτι' ) && '' === $F::phrase_pattern( 'Banksy' ) );
$prompt = (string) file_get_contents( dirname( __DIR__ ) . '/assets/prompts/memory/daily_digest.txt' );
fc_check( 'digest prompt asks for fact checks', false !== strpos( $prompt, '"fact_checks"' ) && false !== strpos( $prompt, 'FactCheck Cyprus' ) );

// 2. Register.
$today = '2026-11-21';
$e_old = fc_seed( '2026-10-01', [ [ 'claim' => 'Παλιός ψευδής ισχυρισμός', 'verdict' => 'false', 'match' => [ 'παλιός ισχυρισμός' ] ] ] );
$e_ex  = fc_seed( '2026-11-19', [ [ 'claim' => 'Αποκλεισμένο', 'verdict' => 'false', 'match' => [ 'αποκλεισμένο θέμα' ] ] ], 'excluded' );
$e_20  = fc_seed( '2026-11-20', [ $munich ] );
$e_21  = fc_seed( '2026-11-21', [ array_merge( $munich, [ 'finding' => 'Νεότερο εύρημα' ] ), [ 'claim' => 'Ψεύτικο βίντεο με πλημμύρα στη Λεμεσό', 'verdict' => 'false', 'checked_by' => 'AFP', 'match' => [ 'πλημμύρα Λεμεσός', 'Limassol flood' ] ] ] );
$all = $F::all( $today, 30 );
fc_check( 'register: two claims, one per claim', 2 === count( $all ) );
fc_check( 'register: newest digest wins for the same claim', 'Νεότερο εύρημα' === ( $all[0]['finding'] ?? '' ) && '2026-11-21' === $all[0]['date'] && $e_21 === $all[0]['entry_id'] );
fc_check( 'register: old and excluded digests left out', ! in_array( 'Παλιός ψευδής ισχυρισμός', array_column( $all, 'claim' ), true ) && ! in_array( 'Αποκλεισμένο', array_column( $all, 'claim' ), true ) );
fc_check( 'register: days setting widens the window', 3 === count( $F::all( $today, 60 ) ) );
fc_check( 'setting: days clamped, default 30', 30 === PressHub_AI_Settings_Storage::get_fact_check_days() && 180 === PressHub_AI_Settings_Storage::sanitize_fact_check_days( 999 ) && 1 === PressHub_AI_Settings_Storage::sanitize_fact_check_days( 0 ) );

// 3. Matching.
$tweet = "Scotland has it's own Banksy. None of that woke shite. 'First World Banksy' we salute you. FREE CYPRUS 🇨🇾 pic.twitter.com/x";
fc_check( 'match: slogan in an English post, any case', 1 === count( $F::find_in_text( $tweet, $all ) ) );
fc_check( 'match: Greek without accents', 1 === count( $F::find_in_text( 'ΠΛΗΜΜΥΡΑ ΛΕΜΕΣΟΣ τώρα', $all ) ) );
fc_check( 'match: no partial words', empty( $F::find_in_text( 'freecyprus2026 and free cyprusians', $all ) ) );
fc_check( 'match: unrelated text', empty( $F::find_in_text( 'Συνάντηση των δύο ηγετών την Τρίτη.', $all ) ) );

// 4. Injection.
fc_check( 'budget: trending summary, social copy, shorts, briefing, podcast', $F::budget_for( 'social_trending_summary' ) > 0 && $F::budget_for( 'social_copy_x' ) > 0 && $F::budget_for( 'social_tiktok_shorts' ) > 0 && $F::budget_for( 'briefing_curation' ) > 0 && $F::budget_for( 'podcast_script' ) > 0 && $F::budget_for( 'coauthor_draft' ) > 0 );
fc_check( 'budget: none for memory building or extraction', 0 === $F::budget_for( 'briefing_memory' ) && 0 === $F::budget_for( 'memory_stakeholders' ) && 0 === $F::budget_for( 'generate_image_prompt' ) );
$GLOBALS['OPTIONS_STORE']['presshub_ai_fact_checks_budgets'] = [ 'social_copy_' => 0 ];
fc_check( 'budget: stored value wins, 0 turns a task off', 0 === $F::budget_for( 'social_copy_facebook' ) && 1500 === $F::budget_for( 'social_trending_summary' ) );
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_fact_checks_budgets'] );

$F::flush_cache();
// apply() reads the register for today (site time); seed today's date too.
$today_real = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
fc_seed( $today_real, [ $munich ], 'active', 'noon' );
fc_check( 'apply: memory building untouched', 'SYS' === $F::apply( 'SYS', $tweet, 'briefing_memory' ) && null === $F::last_applied() );
fc_check( 'apply: nothing matched, untouched', 'SYS' === $F::apply( 'SYS', 'Καιρός.', 'social_copy_x' ) && null === $F::last_applied() );
$out = $F::apply( 'SYS', $tweet, 'social_copy_x' );
fc_check( 'apply: a check already told is not news again', false !== strpos( $out, 'Ένας έλεγχος που ήδη αναφέραμε δεν είναι σημερινή είδηση' ) );
fc_check( 'apply: block appended', 0 === strpos( $out, "SYS\n\n=== ΕΛΕΓΧΟΙ ΓΕΓΟΝΟΤΩΝ" ) && false !== strpos( $out, '[FC1] «Φωτογραφία με γκράφιτι «Free Cyprus» στο Μόναχο» — παραποιημένο (FactCheck Cyprus' ) && false !== strpos( $out, '=== ΤΕΛΟΣ ΕΛΕΓΧΩΝ ΓΕΓΟΝΟΤΩΝ ===' ) );
// The pool drops a fact-checker's article about a check we already reported.
$PC = 'PressHub_AI_Pool_Cleaner';
fc_check( 'pool: a fact-checker article on a reported check is told', $PC::repeats_reported_fact_check( [ 'url' => 'https://factcheckcyprus.org/the-spread-of-free-cyprus-graffiti-and-the-visegrad-24-network-2/', 'title' => 'The spread of "Free Cyprus" graffiti and the Visegrád 24 network', 'content' => 'Images of Free Cyprus graffiti in European cities...' ] ) );
fc_check( 'pool: a news site article on the same claim is kept', ! $PC::repeats_reported_fact_check( [ 'url' => 'https://politis.com.cy/a/1', 'title' => 'Free Cyprus', 'content' => 'Free Cyprus graffiti' ] ) );
fc_check( 'pool: a new check that mentions the old debunk only in its text is kept', ! $PC::repeats_reported_fact_check( [ 'url' => 'https://factcheckcyprus.org/new-video/', 'title' => 'Νέο παραποιημένο βίντεο για το Κυπριακό', 'content' => 'Όπως και με το γκράφιτι Free Cyprus στο Μόναχο, ...' ] ) );
$fc_hosts = static function () { return [ 'WWW.Example-Checks.org ' ]; };
add_filter( 'presshub_ai_fact_check_hosts', $fc_hosts );
fc_check( 'pool: the hosts filter adds a fact-checker (case and www ignored)', $PC::repeats_reported_fact_check( [ 'url' => 'https://www.example-checks.org/a/', 'title' => 'Free Cyprus graffiti again', 'content' => '' ] ) );
remove_filter( 'presshub_ai_fact_check_hosts', $fc_hosts );
fc_check( 'pool: a fact-checker article on a new claim is kept', ! $PC::repeats_reported_fact_check( [ 'url' => 'https://factcheckcyprus.org/new-claim/', 'title' => 'Ψεύτικο βίντεο για τις τιμές του ρεύματος', 'content' => 'Το βίντεο είναι παλιό.' ] ) );

// Our own memory of earlier editions never brings a fact check back.
$mem_text = "### Πρόσφατη Επικαιρότητα\n- " . $tweet;
$rp = new ReflectionProperty( 'PressHub_AI_Memory_Context_Builder', 'rendered_texts' );
$rp->setAccessible( true );
$rp->setValue( null, [ $mem_text ] );
$out_mem = $F::apply( "SYS\n" . $mem_text, 'Σημερινά άρθρα για τον καιρό.', 'briefing_curation' );
fc_check( 'apply: a claim only in the memory block adds no fact check', "SYS\n" . $mem_text === $out_mem && null === $F::last_applied() );
$out_both = $F::apply( "SYS\n" . $mem_text, 'Σημερινό άρθρο: ' . $tweet, 'briefing_curation' );
fc_check( 'apply: a claim in today\'s material still gets its fact check', false !== strpos( $out_both, '=== ΕΛΕΓΧΟΙ ΓΕΓΟΝΟΤΩΝ' ) );
$out_user = $F::apply( 'SYS', "Άρθρα:\n\n" . trim( $mem_text ) . "\n\nΣημερινά άρθρα για τον καιρό.", 'podcast_script' );
fc_check( 'apply: memory placed in the user prompt (trimmed) adds no fact check either', 'SYS' === $out_user && null === $F::last_applied() );
PressHub_AI_Memory_Context_Builder::reset_rendered_texts();
$out = $F::apply( 'SYS', $tweet, 'social_copy_x' );
$last = $F::last_applied();
fc_check( 'apply: last_applied has refs and the prompt', is_array( $last ) && isset( $last['refs']['FC1'] ) && $out === $last['prompt'] && md5( 'SYS' ) === $last['original_hash'] );
[ $sent, $cfg ] = PressHub_AI_Generation_Recorder::apply_injected_context( 'SYS', [] );
fc_check( 'record: prompt as sent and the claims', $out === $sent && 1 === count( $cfg['fact_checks'] ?? [] ) && (int) ( $cfg['fact_checks_chars'] ?? 0 ) > 0 && null === $F::last_applied() );
$GLOBALS['OPTIONS_STORE']['presshub_ai_fact_checks_enabled'] = 0;
fc_check( 'switch off: untouched', 'SYS' === $F::apply( 'SYS', $tweet, 'social_copy_x' ) );
$GLOBALS['OPTIONS_STORE']['presshub_ai_fact_checks_enabled'] = 1;
$qa = $F::apply( 'SYS', $tweet, 'coauthor_scorecard' );
fc_check( 'apply: QA gate told to lower the score', false !== strpos( $qa, 'μείωσε τη βαθμολογία' ) );
$r = $F::render( $F::find_in_text( $tweet ), 'social_copy_x', 40 );
fc_check( 'render: too small a budget sends nothing and reports the cut', '' === $r['text'] && 'no_room' === ( $r['left_out'][0]['reason'] ?? '' ) );
$api = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-api-client.php' );
fc_check( 'wiring: call_provider applies fact checks after the policy fiches', false !== strpos( $api, 'PressHub_AI_Fact_Checks::apply( $sys_prompt, $policy_material, $this->get_action() )' ) && strpos( $api, 'PressHub_AI_Policy_Fiches::apply(' ) < strpos( $api, 'PressHub_AI_Fact_Checks::apply(' ) );

// 5. Trending roundup.
$T     = 'PressHub_AI_Social_Trending';
$items = [
    [ 'key' => 'x_1', 'platform' => 'x', 'author_name' => 'Tommy Robinson', 'handle' => 'trobinsonnewera', 'text' => $tweet, 'likes' => 3915, 'comments' => 61, 'shares' => 505, 'views' => 73600, 'created_ts' => time() - 3600, 'url' => 'https://x.com/a/status/1' ],
    [ 'key' => 'facebook_2', 'platform' => 'facebook', 'author_name' => 'ΑΚΕΛ', 'handle' => 'akel1926', 'text' => 'Οι ευθύνες για το ενεργειακό χάος.', 'likes' => 686, 'comments' => 50, 'shares' => 153, 'views' => 0, 'created_ts' => time() - 7200, 'url' => 'https://www.facebook.com/akel1926/posts/2' ],
];
$seen_sys = '';
$T::$summary_caller = static function ( $sys, $user ) use ( &$seen_sys ) {
    $seen_sys = $sys;
    return '{"summary":"Αναρτήσεις για το σύνθημα «Free Cyprus», με φωτογραφία που το FactCheck Cyprus έχει κρίνει παραποιημένη.","fact_checks":[{"post":1,"check":"FC1"},{"post":2,"check":"FC9"},{"post":7,"check":"FC1"}]}';
};
$summary = $T::generate_summary( $items, 0 );
fc_check( 'trending: summary call gets the fact checks', false !== strpos( $seen_sys, '=== ΕΛΕΓΧΟΙ ΓΕΓΟΝΟΤΩΝ' ) && false !== strpos( $seen_sys, '"fact_checks"' ) );
fc_check( 'trending: summary text parsed', is_string( $summary ) && false !== strpos( $summary, 'FactCheck Cyprus' ) );
$notes = $T::$last_summary_notes;
fc_check( 'trending: only the known ref on a real post becomes a note', [ 'x_1' ] === array_keys( $notes ) );
fc_check( 'trending: note written from the stored check', 'FactCheck Cyprus: «Φωτογραφία με γκράφιτι «Free Cyprus» στο Μόναχο» — παραποιημένο. Η φωτογραφία είναι προϊόν ψηφιακής παραποίησης.' === ( $notes['x_1']['text'] ?? '' ) && 'https://factcheckcyprus.org/free-cyprus' === ( $notes['x_1']['url'] ?? '' ) );
fc_check( 'trending: no refs sent, no notes', [] === $T::parse_fact_check_notes( '{"summary":"x","fact_checks":[{"post":1,"check":"FC1"}]}', [], $items ) );
$GLOBALS['OPTIONS_STORE']['presshub_ai_social_trending_max_age_hours'] = 24;
$html = $T::build_content( $items, time(), (string) $summary, $notes );
$p1   = strpos( $html, 'presshub-trending-factcheck' );
fc_check( 'trending: note under the first post, before the second card', false !== $p1 && $p1 < strpos( $html, 'presshub-trending-facebook' ) && 1 === substr_count( $html, 'class="presshub-trending-factcheck"' ) );
fc_check( 'trending: note links to the check', false !== strpos( $html, 'href="https://factcheckcyprus.org/free-cyprus"' ) );
fc_check( 'trending: no notes, no note blocks', false === strpos( $T::build_content( $items, time(), 'x' ), 'presshub-trending-factcheck' ) );
$sig = $T::fact_check_signature( $items );
fc_check( 'trending: signature set when a post matches', '' !== $sig && '' === $T::fact_check_signature( [ $items[1] ] ) );

// Reuse: same posts and same checks reuse the stored summary; a new matching check redoes it.
$GLOBALS['OPTIONS_STORE']['presshub_ai_social_trending_summary_enabled'] = 1;
$calls = 0;
$T::$summary_caller = static function () use ( &$calls ) { $calls++; return '{"summary":"Σύνοψη.","fact_checks":[]}'; };
$GLOBALS['POST_META_STORE'][555][ $T::META_SUMMARY ] = [ 'hash' => $T::summary_hash( $items ), 'text' => 'Παλιά σύνοψη', 'notes' => [], 'facts' => '' ];
$res = $T::summary_for( $items, 555 );
fc_check( 'trending: summary redone when a fact check now matches', 1 === $calls && true === $res['fresh'] && $sig === $res['facts'] );
$GLOBALS['POST_META_STORE'][555][ $T::META_SUMMARY ] = [ 'hash' => $T::summary_hash( $items ), 'text' => 'Παλιά σύνοψη', 'notes' => [ 'x_1' => [ 'text' => 'n', 'url' => '' ] ], 'facts' => $sig ];
$res = $T::summary_for( $items, 555 );
fc_check( 'trending: reused with its notes while nothing changed', 1 === $calls && false === $res['fresh'] && isset( $res['notes']['x_1'] ) );
$T::$summary_caller = null;
$tpl = (string) file_get_contents( dirname( __DIR__ ) . '/assets/prompts/social_trending_summary.txt' );
fc_check( 'trending prompt: allows the fact-check exception and the reply field', false !== strpos( $tpl, 'fact checks block' ) && false !== strpos( $tpl, '"fact_checks":[{"post":1,"check":"FC1"}]' ) );

// 6. Memory page.
$F::flush_cache();
$page = $F::build_section( $today );
fc_check( 'memory page: lists the fact checks with a Remove form', false !== strpos( $page, 'Fact checks in memory' ) && false !== strpos( $page, 'FactCheck Cyprus' ) && false !== strpos( $page, 'value="' . $F::POST_REMOVE . '"' ) );
$id  = $F::id_for( $munich );
$res = $F::remove( $e_20, $id );
fc_check( 'remove: taken out of its digest', true === $res );
$entry   = PressHub_AI_Memory_Store::get_entry( $e_20 );
$content = PressHub_AI_Memory_Store::decode_content( (string) $entry['content_json'] );
fc_check( 'remove: digest marked edited, stories kept', 'edited' === (string) $entry['origin'] && ! isset( $content['fact_checks'] ) && 1 === count( $content['stories'] ) );
fc_check( 'remove: unknown id refused', is_wp_error( $F::remove( $e_20, 'nope' ) ) );
fc_check( 'memory page: section on the Memory page', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-memory-editor.php' ), 'PressHub_AI_Fact_Checks::build_section' ) );

if ( $failures > 0 ) {
    fwrite( STDERR, "FactChecksTest: {$failures} of {$checks} checks failed\n" );
    exit( 1 );
}
echo "FactChecksTest: OK ({$checks} checks)\n";
