<?php
/**
 * SourceAlignmentTest — source alignment tags, the curation claim check and
 * the Hub's one-sided pool warning.
 *
 *  1. normalize_sources keeps a known alignment and drops anything else.
 *  2. get_source_alignment_map maps hosts; article_alignment matches host and
 *     parent domain; press releases and social posts carry none.
 *  3. format_articles_context labels aligned articles, adds one note and
 *     records the alignment in the breakdown; nothing when no source is tagged.
 *  4. alignment_balance and the Hub box flag an all-pro-government pool.
 *  5. The claim-check rules are in the curation user prompt and can be
 *     switched off with a filter.
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
function sa_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$S = 'PressHub_AI_Settings_Storage';
$C = 'PressHub_AI_News_Curator';

// 1. normalize
$norm = $S::normalize_sources( [
    [ 'name' => 'Gov', 'url' => 'https://www.gov-news.example/', 'alignment' => 'government' ],
    [ 'name' => 'Odd', 'url' => 'https://odd.example/', 'alignment' => 'reptilian' ],
    [ 'name' => 'Plain', 'url' => 'https://plain.example/' ],
    'https://legacy.example/',
] );
sa_check( 'normalize: alignment kept', 'government' === $norm[0]['alignment'] );
sa_check( 'normalize: unknown alignment dropped', '' === $norm[1]['alignment'] );
sa_check( 'normalize: default is not set', '' === $norm[2]['alignment'] && '' === $norm[3]['alignment'] );

// 2. map + article_alignment
update_option( 'presshub_ai_briefing_sources', [
    [ 'name' => 'Gov', 'url' => 'https://www.gov-news.example/', 'alignment' => 'government' ],
    [ 'name' => 'Opp', 'url' => 'https://opp-news.example/', 'alignment' => 'opposition' ],
    [ 'name' => 'Plain', 'url' => 'https://plain.example/' ],
] );
$map = $S::get_source_alignment_map();
sa_check( 'map: tagged hosts only, www stripped', [ 'gov-news.example' => 'government', 'opp-news.example' => 'opposition' ] === $map );
$C::reset_alignment_map();
sa_check( 'article: host match', 'government' === $C::article_alignment( [ 'url' => 'https://gov-news.example/a' ] ) );
sa_check( 'article: subdomain matches parent', 'opposition' === $C::article_alignment( [ 'url' => 'https://m.opp-news.example/a' ] ) );
sa_check( 'article: explicit value wins', 'independent' === $C::article_alignment( [ 'url' => 'https://gov-news.example/a', 'source_alignment' => 'independent' ] ) );
sa_check( 'article: untagged host', '' === $C::article_alignment( [ 'url' => 'https://plain.example/a' ] ) );
sa_check( 'article: press release has none', '' === $C::article_alignment( [ 'url' => 'https://gov-news.example/a', 'is_press_release' => true ] ) );

// 3. context
$C::reset_priority_map();
$pool = [
    [ 'title' => 'T1', 'source' => 'gov', 'url' => 'https://gov-news.example/1', 'content' => 'a' ],
    [ 'title' => 'T2', 'source' => 'plain', 'url' => 'https://plain.example/2', 'content' => 'b' ],
    [ 'title' => 'T3', 'source' => 'opp', 'url' => 'https://opp-news.example/3', 'content' => 'c' ],
];
$curator = new $C();
$ctx     = $curator->format_articles_context( $pool );
sa_check( 'context: labels on tagged articles', 1 === substr_count( $ctx, '**Τάση πηγής:** φιλοκυβερνητική' ) && 1 === substr_count( $ctx, '**Τάση πηγής:** αντιπολιτευτική' ) );
sa_check( 'context: one note first', 0 === strpos( $ctx, 'Σημείωση για την τάση των πηγών' ) && 1 === substr_count( $ctx, 'Σημείωση για την τάση των πηγών' ) );
$bd = $curator->last_articles_breakdown();
sa_check( 'breakdown: alignment on tagged entries only', [ 'government', 'opposition' ] === array_values( array_filter( array_map( static function ( $e ) { return $e['alignment'] ?? ''; }, $bd['sent'] ) ) ) );

update_option( 'presshub_ai_briefing_sources', [ [ 'name' => 'Plain', 'url' => 'https://plain.example/' ] ] );
$C::reset_alignment_map();
$ctx = $curator->format_articles_context( $pool );
sa_check( 'context: no tags, no labels, no note', false === strpos( $ctx, 'Τάση πηγής' ) && false === strpos( $ctx, 'τάση των πηγών' ) );

// 4. balance
update_option( 'presshub_ai_briefing_sources', [
    [ 'name' => 'Gov', 'url' => 'https://gov-news.example/', 'alignment' => 'government' ],
    [ 'name' => 'Opp', 'url' => 'https://opp-news.example/', 'alignment' => 'opposition' ],
    [ 'name' => 'Parl', 'url' => 'https://parliament.example/', 'alignment' => 'institutional' ],
] );
$C::reset_alignment_map();
$one_sided = [
    [ 'url' => 'https://gov-news.example/1' ],
    [ 'url' => 'https://gov-news.example/2' ],
    [ 'url' => 'https://parliament.example/3' ],
    [ 'url' => 'https://plain.example/4' ],
];
$b = $C::alignment_balance( $one_sided );
sa_check( 'balance: counts', 2 === $b['counts']['government'] && 1 === $b['counts']['institutional'] && 1 === $b['untagged'] );
sa_check( 'balance: no opposition/independent/social partner = one-sided', true === $b['one_sided'] );
sa_check( 'balance: an opposition article clears it', false === $C::alignment_balance( array_merge( $one_sided, [ [ 'url' => 'https://opp-news.example/5' ] ] ) )['one_sided'] );
$html = PressHub_AI_Briefing_Admin::source_balance_html( $one_sided );
sa_check( 'hub: box with counts and the warning', false !== strpos( $html, 'Source balance' ) && false !== strpos( $html, 'Pro-government: 2' ) && false !== strpos( $html, 'One-sided pool' ) );
sa_check( 'hub: no box when nothing is tagged', '' === PressHub_AI_Briefing_Admin::source_balance_html( [ [ 'url' => 'https://plain.example/1' ] ] ) );
sa_check( 'settings: alignment badge', false !== strpos( PressHub_AI_Settings_Render::alignment_badge_html( 'opposition' ), 'Alignment: Opposition' ) && '' === PressHub_AI_Settings_Render::alignment_badge_html( 'nope' ) );

// 5. claim check
$rules = $C::claim_check_rules();
sa_check( 'claim check: rules present', false !== strpos( $rules, 'Έλεγχος ισχυρισμών' ) && false !== strpos( $rules, 'ποιος αποφασίζει' ) );
$prompt = $curator->build_prompt( $pool, '', '2026-11-16' );
sa_check( 'claim check: in the curation user prompt', false !== strpos( $prompt['user_prompt'], 'Έλεγχος ισχυρισμών' ) );
add_filter( 'presshub_ai_briefing_claim_check', '__return_false' );
sa_check( 'claim check: filter switches it off', '' === $C::claim_check_rules() );

delete_option( 'presshub_ai_briefing_sources' );

if ( $failures > 0 ) {
    fwrite( STDERR, "SourceAlignmentTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "SourceAlignmentTest: OK ({$checks} checks)\n";
