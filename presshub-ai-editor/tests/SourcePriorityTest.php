<?php
/**
 * SourcePriorityTest — Issue #196: source priority weights.
 *
 *  1. normalize_sources keeps a 1–5 priority (default 3) and clamps it.
 *  2. get_source_priority_map maps hosts (www stripped, highest wins).
 *  3. article_priority: explicit value, host and parent-domain match,
 *     press releases / social posts / unknown hosts stay normal.
 *  4. format_articles_context: over the cap, the highest priorities are kept
 *     (ties keep pool order) and rendered in pool order; labels, the note and
 *     the breakdown carry the priority.
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
function sp_check( $label, $condition ) {
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
    [ 'name' => 'Phileleftheros', 'url' => 'https://www.philenews.com/', 'priority' => 5 ],
    [ 'name' => 'Blog', 'url' => 'https://blog.example.org/', 'priority' => 9 ],
    [ 'name' => 'Plain', 'url' => 'https://plain.example.net/' ],
    'https://legacy.example.com/',
] );
sp_check( 'normalize: priority kept', 5 === $norm[0]['priority'] );
sp_check( 'normalize: priority clamped to 5', 5 === $norm[1]['priority'] );
sp_check( 'normalize: default priority 3', 3 === $norm[2]['priority'] && 3 === $norm[3]['priority'] );
sp_check( 'sanitize: low values clamp to 1', 1 === $S::sanitize_source_priority( -2 ) && 3 === $S::sanitize_source_priority( 'x' ) );

// 2. map
update_option( 'presshub_ai_briefing_sources', [
    [ 'name' => 'Phileleftheros', 'url' => 'https://www.philenews.com/', 'priority' => 5 ],
    [ 'name' => 'Phileleftheros feed', 'url' => 'https://philenews.com/feed/', 'priority' => 2 ],
    [ 'name' => 'Tabloid', 'url' => 'https://tabloid.example.com/', 'priority' => 1 ],
    [ 'name' => 'Normal', 'url' => 'https://normal.example.com/' ],
] );
$map = $S::get_source_priority_map();
sp_check( 'map: www stripped and the higher priority wins on one host', 5 === ( $map['philenews.com'] ?? 0 ) );
sp_check( 'map: other hosts', 1 === ( $map['tabloid.example.com'] ?? 0 ) && 3 === ( $map['normal.example.com'] ?? 0 ) );

// 3. article_priority
$C::reset_priority_map();
sp_check( 'article: host match', 5 === $C::article_priority( [ 'url' => 'https://www.philenews.com/eidiseis/1' ] ) );
sp_check( 'article: subdomain matches the parent source', 5 === $C::article_priority( [ 'url' => 'https://m.philenews.com/a' ] ) );
sp_check( 'article: explicit source_priority wins', 2 === $C::article_priority( [ 'url' => 'https://philenews.com/a', 'source_priority' => 2 ] ) );
sp_check( 'article: unknown host is normal', 3 === $C::article_priority( [ 'url' => 'https://unknown.test/a' ] ) );
sp_check( 'article: press release stays normal', 3 === $C::article_priority( [ 'url' => 'https://philenews.com/a', 'is_press_release' => true ] ) );
sp_check( 'article: social post stays normal', 3 === $C::article_priority( [ 'url' => 'https://x.com/a/status/1', 'source_type' => 'social_post' ] ) );

// 4. format_articles_context with a cap of 3 over 5 articles
update_option( 'presshub_ai_curation_max_articles', 3 );
$C::reset_priority_map();
$pool = [
    [ 'title' => 'T1 normal', 'source' => 'normal', 'url' => 'https://normal.example.com/1', 'content' => 'a' ],
    [ 'title' => 'T2 tabloid', 'source' => 'tabloid', 'url' => 'https://tabloid.example.com/2', 'content' => 'b' ],
    [ 'title' => 'T3 normal', 'source' => 'normal', 'url' => 'https://normal.example.com/3', 'content' => 'c' ],
    [ 'title' => 'T4 philenews', 'source' => 'philenews', 'url' => 'https://philenews.com/4', 'content' => 'd' ],
    [ 'title' => 'T5 normal', 'source' => 'normal', 'url' => 'https://normal.example.com/5', 'content' => 'e' ],
];
$curator = new PressHub_AI_News_Curator();
$ctx     = $curator->format_articles_context( $pool );
$order   = [];
preg_match_all( '/### \d+\. (T\d)/u', $ctx, $m );
$order = $m[1];
sp_check( 'cap: priority 5 kept, priority 1 and the last normal dropped, pool order kept', [ 'T1', 'T3', 'T4' ] === $order );
sp_check( 'cap: label on the high-priority article only', 1 === substr_count( $ctx, 'Προτεραιότητα πηγής:** πολύ υψηλή (5/5)' ) && 1 === substr_count( $ctx, '**Προτεραιότητα πηγής:**' ) );
sp_check( 'cap: the note explains the labels', 0 === strpos( $ctx, 'Σημείωση για τις πηγές' ) );
$bd = $curator->last_articles_breakdown();
sp_check( 'breakdown: sent entries carry priority', [ 3, 3, 5 ] === array_column( $bd['sent'], 'priority' ) );
$dropped = array_column( $bd['dropped'], 'priority', 'title' );
sp_check( 'breakdown: dropped entries carry priority and reason', [ 'T2 tabloid' => 1, 'T5 normal' => 3 ] === $dropped && [ 'cap_articles', 'cap_articles' ] === array_column( $bd['dropped'], 'reason' ) );

// No priorities set: no labels, no note, plain pool order.
delete_option( 'presshub_ai_briefing_sources' );
update_option( 'presshub_ai_briefing_sources', [ [ 'name' => 'Normal', 'url' => 'https://normal.example.com/' ] ] );
$C::reset_priority_map();
$ctx = $curator->format_articles_context( $pool );
preg_match_all( '/### \d+\. (T\d)/u', $ctx, $m );
sp_check( 'no priorities: first three in pool order, no label, no note', [ 'T1', 'T2', 'T3' ] === $m[1] && false === strpos( $ctx, 'Προτεραιότητα πηγής' ) );

delete_option( 'presshub_ai_curation_max_articles' );
delete_option( 'presshub_ai_briefing_sources' );

if ( $failures > 0 ) {
    fwrite( STDERR, "SourcePriorityTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "SourcePriorityTest: OK ({$checks} checks)\n";
