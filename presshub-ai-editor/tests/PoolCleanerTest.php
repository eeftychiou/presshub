<?php
/**
 * PoolCleanerTest — Issue #190: cleaner article pool.
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
function pc_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$P    = 'PressHub_AI_Pool_Cleaner';
$date = '2026-11-15';
$now  = strtotime( '2026-11-15 05:00:00 UTC' );
update_option( 'presshub_ai_briefing_sources', [
    [ 'name' => 'Politis', 'url' => 'https://politis.com.cy/', 'priority' => 5 ],
    [ 'name' => 'Tabloid', 'url' => 'https://tabloid.example.com/', 'priority' => 1 ],
] );
PressHub_AI_News_Curator::reset_priority_map();

function pc_words( int $n, string $seed = 'λέξη' ): string {
    $out = [];
    for ( $i = 0; $i < $n; $i++ ) {
        $out[] = $seed . $i;
    }
    return implode( ' ', $out );
}

// clean_text
$dirty = "Αρχή<style>.a{color:red}</style> κείμενο <script>var x=1;</script><!-- c --> <img src=\"data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB\"> τέλος&nbsp;εδώ\n\n\n\nνέα γραμμή .cls{margin:0} μετά";
$clean = $P::clean_text( $dirty );
pc_check( 'clean: style/script/comment gone', false === strpos( $clean, 'color' ) && false === strpos( $clean, 'var x' ) && false === strpos( $clean, ' c ' ) );
pc_check( 'clean: data URI gone', false === strpos( $clean, 'base64' ) );
pc_check( 'clean: entities decoded, blank lines collapsed', false !== strpos( $clean, "τέλος εδώ\n\nνέα γραμμή" ) );
pc_check( 'clean: stray CSS rule gone', false === strpos( $clean, 'margin' ) && false !== strpos( $clean, 'μετά' ) );

// dates
pc_check( 'date: dd/mm/yy', '2026-11-08' === $P::find_date( 'Στων Αστεριών τις Νότες 08/11/26' ) );
pc_check( 'date: dd/mm/yyyy', '2026-11-14' === $P::find_date( '3ο Δελτίο Καιρού 14/11/2026' ) );
pc_check( 'date: dd.mm.yyyy and iso', '2026-11-01' === $P::find_date( 'x 1.11.2026' ) && '2026-10-30' === $P::find_date( 'x 2026-10-30 y' ) );
pc_check( 'date: none / invalid', '' === $P::find_date( 'Νόμος 12/2024' ) && '' === $P::find_date( 'Σκορ 31/13/2026' ) && '' === $P::find_date( 'Πορτογαλία – Δανία 1' ) );
pc_check( 'url date: /yyyy/mm/dd/', '2026-11-08' === $P::find_url_date( 'https://x.cy/2026/11/08/story/' ) );
pc_check( 'url date: none', '' === $P::find_url_date( 'https://x.cy/news/123456' ) );

// promo
pc_check( 'promo: Kathimerini Sunday promo', $P::is_promo_title( 'Διαβάστε αυτή την Κυριακή στην «Κ»' ) );
pc_check( 'score stub title', $P::is_score_title( 'Πορτογαλία – Δανία 1' ) && $P::is_score_title( 'ΑΠΟΕΛ - Ομόνοια 2-1' ) && ! $P::is_score_title( 'Η Ομόνοια νίκησε τον ΑΠΟΕΛ με 2-1' ) );
pc_check( 'promo: normal title kept', ! $P::is_promo_title( 'Η Βουλή ψήφισε τον προϋπολογισμό' ) );

$long = pc_words( 120 );
$pool = [
    // 0 stale radio show (title date)
    [ 'title' => 'Στων Αστεριών τις Νότες 08/11/26', 'content' => $long, 'url' => 'https://rik.cy/a', 'tier' => 'dom', 'source' => 'rik.cy' ],
    // 1 yesterday's weather
    [ 'title' => '3ο Δελτίο Καιρού 14/11/2026', 'content' => $long, 'url' => 'https://rik.cy/b', 'tier' => 'dom', 'source' => 'rik.cy' ],
    // 2 promo
    [ 'title' => 'Διαβάστε αυτή την Κυριακή στην Καθημερινή', 'content' => $long, 'url' => 'https://kathimerini.com.cy/c', 'tier' => 'dom', 'source' => 'kathimerini.com.cy' ],
    // 3 results stub (score-only title, little text)
    [ 'title' => 'Πορτογαλία – Δανία 1', 'content' => pc_words( 40 ), 'url' => 'https://omegalive.com.cy/d', 'tier' => 'dom', 'source' => 'omegalive' ],
    // 4 short RSS item is fine (RSS has its own threshold)
    [ 'title' => 'Σύντομη είδηση από ροή', 'content' => pc_words( 20, 'ροή' ), 'url' => 'https://e.cy/e', 'tier' => 'rss_description', 'source' => 'e.cy' ],
    // 5 old published_at
    [ 'title' => 'Παλιό άρθρο γνώμης για την οικονομία', 'content' => $long, 'url' => 'https://f.cy/f', 'tier' => 'dom', 'published_at' => '2026-11-10T08:00:00+00:00', 'source' => 'f.cy' ],
    // 6 good, today's date in the title
    [ 'title' => 'Δελτίο Καιρού 15/11/2026', 'content' => pc_words( 120, 'καιρός' ), 'url' => 'https://rik.cy/g', 'tier' => 'dom', 'published_at' => '2026-11-15T04:00:00+00:00', 'source' => 'rik.cy' ],
    // 7/8 duplicates: low-priority copy first, Politis copy second
    [ 'title' => 'Συμφωνία για τη διασύνδεση', 'content' => pc_words( 150, 'διασύνδεση' ), 'url' => 'https://tabloid.example.com/h', 'tier' => 'dom', 'source' => 'tabloid' ],
    [ 'title' => 'Συμφωνία για τη διασύνδεση', 'content' => pc_words( 150, 'διασύνδεση' ), 'url' => 'https://politis.com.cy/i', 'tier' => 'dom', 'source' => 'politis' ],
    // 9/10/11 same video on X, TikTok and a second X account; TikTok has the transcript
    [ 'title' => 'Fidias: Lisa Feldman Barrett', 'content' => 'Συζήτηση με τη Lisa Feldman Barrett για τα συναισθήματα και τον εγκέφαλο και πώς μαθαίνουμε', 'url' => 'https://x.com/fidias0/1', 'tier' => 'social_post', 'source_type' => 'social_post', 'is_social_post' => true, 'source' => 'X' ],
    [ 'title' => 'Fidias: Lisa Feldman Barrett', 'content' => 'Συζήτηση με τη Lisa Feldman Barrett για τα συναισθήματα και τον εγκέφαλο και πώς μαθαίνουμε. Απομαγνητοφώνηση: ...', 'url' => 'https://tiktok.com/@fidias/2', 'tier' => 'social_post', 'source_type' => 'social_post', 'is_social_post' => true, 'has_transcript' => true, 'source' => 'TikTok' ],
    [ 'title' => 'Fidias: Lisa Feldman Barrett', 'content' => 'Συζήτηση με τη Lisa Feldman Barrett για τα συναισθήματα και τον εγκέφαλο και πώς μαθαίνουμε', 'url' => 'https://x.com/fidiascyprus/3', 'tier' => 'social_post', 'source_type' => 'social_post', 'is_social_post' => true, 'source' => 'X' ],
    // 12 manual upload: short, old date, never dropped
    [ 'title' => 'Σημείωμα 01/11/2026', 'content' => 'λίγα λόγια', 'url' => '', 'tier' => 'manual', 'is_manual' => true, 'source' => 'Editor' ],
    // 13 press release with old date in title
    [ 'title' => 'Ανακοίνωση 10/11/2026', 'content' => 'σύντομη ανακοίνωση', 'url' => '', 'tier' => 'press_release', 'source_type' => 'press_release', 'is_press_release' => true, 'source' => 'PIO' ],
    // 14 different story from another outlet on the same topic: kept
    [ 'title' => 'Αντιδράσεις για τη διασύνδεση', 'content' => pc_words( 150, 'αντίδραση' ), 'url' => 'https://g.cy/j', 'tier' => 'dom', 'source' => 'g.cy' ],
    // 15 huge item with junk: trimmed
    [ 'title' => 'Μακρύ δοκίμιο για την Ευρώπη', 'content' => '<style>' . str_repeat( 'x', 500 ) . '</style>' . str_repeat( 'Ευρώπη και δημοκρατία σήμερα. ', 1200 ), 'url' => 'https://sub.substack.com/p/k', 'tier' => 'dom', 'source' => 'substack' ],
];
$payload = [ 'articles' => $pool ];
$sum     = $P::clean( $payload, $date, $now );
$urls    = array_column( $payload['articles'], 'url' );
$reasons = [];
foreach ( $sum['dropped'] as $d ) {
    $reasons[ $d['url'] ] = $d['reason'];
}

pc_check( 'stale: title date before the briefing date', 'stale' === ( $reasons['https://rik.cy/a'] ?? '' ) && 'stale' === ( $reasons['https://rik.cy/b'] ?? '' ) );
pc_check( 'stale: old published_at', 'stale' === ( $reasons['https://f.cy/f'] ?? '' ) );
pc_check( 'kept: an older date in a title is a reference, not staleness', '' === $P::drop_reason( [ 'title' => 'Τι άλλαξε από τις 1/3/2024 στη φορολογία', 'content' => $long, 'tier' => 'dom' ], $date, $now ) );
pc_check( 'kept: today-dated bulletin', in_array( 'https://rik.cy/g', $urls, true ) );
pc_check( 'promo dropped', 'promo' === ( $reasons['https://kathimerini.com.cy/c'] ?? '' ) );
pc_check( 'too short scraped item dropped', 'too_short' === ( $reasons['https://omegalive.com.cy/d'] ?? '' ) );
pc_check( 'short RSS item kept', in_array( 'https://e.cy/e', $urls, true ) );
pc_check( 'duplicate: higher-priority copy kept', in_array( 'https://politis.com.cy/i', $urls, true ) && 'duplicate' === ( $reasons['https://tabloid.example.com/h'] ?? '' ) );
pc_check( 'duplicate: drop row names the kept copy', in_array( 'https://politis.com.cy/i', array_column( $sum['dropped'], 'kept' ), true ) );
pc_check( 'duplicate: one copy of the social video, the one with a transcript', in_array( 'https://tiktok.com/@fidias/2', $urls, true ) && ! in_array( 'https://x.com/fidias0/1', $urls, true ) && ! in_array( 'https://x.com/fidiascyprus/3', $urls, true ) );
pc_check( 'kept: different story on the same topic', in_array( 'https://g.cy/j', $urls, true ) );
$manual = array_values( array_filter( $payload['articles'], static function ( $a ) { return ! empty( $a['is_manual'] ); } ) );
$pr     = array_values( array_filter( $payload['articles'], static function ( $a ) { return ! empty( $a['is_press_release'] ); } ) );
pc_check( 'protected: manual upload and press release never dropped', 1 === count( $manual ) && 1 === count( $pr ) );
$essay = array_values( array_filter( $payload['articles'], static function ( $a ) { return 'https://sub.substack.com/p/k' === $a['url']; } ) );
pc_check( 'trim: long item cut and flagged', 1 === count( $essay ) && ! empty( $essay[0]['content_trimmed'] ) && $essay[0]['char_count'] <= $P::STORED_MAX_CHARS + 2 && false === strpos( $essay[0]['content'], 'xxxx' ) );
pc_check( 'char_count recounted', $essay[0]['char_count'] === mb_strlen( $essay[0]['content'] ) );
pc_check( 'summary counts', 3 === $sum['counts']['stale'] && 1 === $sum['counts']['promo'] && 1 === $sum['counts']['too_short'] && 3 === $sum['counts']['duplicate'] && 1 === $sum['trimmed'] );
pc_check( 'summary before/after', 16 === $sum['before'] && 8 === $sum['after'] && $sum === $payload['pool_cleanup'] );
pc_check( 'order: kept items stay in pool order', [ 'https://e.cy/e', 'https://rik.cy/g', 'https://politis.com.cy/i', 'https://tiktok.com/@fidias/2' ] === array_slice( $urls, 0, 4 ) );

// filter switch
add_filter( 'presshub_ai_pool_cleanup_enabled', '__return_false' );
$p2 = [ 'articles' => $pool ];
$s2 = $P::clean( $p2, $date, $now );
pc_check( 'disabled: pool untouched', count( $pool ) === count( $p2['articles'] ) && false === $s2['enabled'] );
remove_filter( 'presshub_ai_pool_cleanup_enabled', '__return_false' );

// Undated pages are aged from the first harvest that saw the URL.
delete_option( 'presshub_ai_pool_first_seen' );
$undated = [ 'title' => 'Έρευνα για τους μεγάλους χρήστες νερού στην Κύπρο', 'content' => $long, 'url' => 'https://www.ciren.cy/investigation/water/', 'tier' => 'dom', 'published_at' => '', 'source' => 'ciren.cy' ];
$day1 = strtotime( '2026-11-18 05:00:00 UTC' );
$p3 = [ 'articles' => [ $undated ] ];
$P::clean( $p3, '2026-11-18', $day1 );
pc_check( 'first seen: kept on its first harvest', 1 === count( $p3['articles'] ) && gmdate( 'c', $day1 ) === ( $p3['articles'][0]['first_seen_at'] ?? '' ) );
$p4 = [ 'articles' => [ array_merge( $undated, [ 'url' => 'https://ciren.cy/investigation/water' ] ) ] ];
$P::clean( $p4, '2026-11-19', $day1 + 24 * 3600 );
pc_check( 'first seen: still kept inside the age limit (same URL without www and slash)', 1 === count( $p4['articles'] ) && gmdate( 'c', $day1 ) === ( $p4['articles'][0]['first_seen_at'] ?? '' ) );
$p5 = [ 'articles' => [ $undated ] ];
$s5 = $P::clean( $p5, '2026-11-24', $day1 + 6 * 24 * 3600 );
pc_check( 'first seen: dropped as stale after the age limit', 0 === count( $p5['articles'] ) && 1 === $s5['counts']['stale'] );
$dated = array_merge( $undated, [ 'url' => 'https://ciren.cy/other', 'published_at' => gmdate( 'c', $day1 + 6 * 24 * 3600 - 3600 ) ] );
$p6 = [ 'articles' => [ $dated ] ];
$P::clean( $p6, '2026-11-24', $day1 + 6 * 24 * 3600 );
$seen = get_option( 'presshub_ai_pool_first_seen', [] );
pc_check( 'first seen: dated pages are not recorded', 1 === count( $p6['articles'] ) && ! isset( $seen[ $P::url_key( 'https://ciren.cy/other' ) ] ) );
$P::clean( $p6, '2026-12-10', strtotime( '2026-12-10 05:00:00 UTC' ) );
pc_check( 'first seen: old entries are pruned', [] === get_option( 'presshub_ai_pool_first_seen', [] ) );

// Protected items and unreadable dates.
delete_option( 'presshub_ai_pool_first_seen' );
$manual = array_merge( $undated, [ 'url' => 'https://ciren.cy/manual', 'is_manual' => true ] );
$odd    = array_merge( $undated, [ 'url' => 'https://ciren.cy/odd', 'published_at' => '1 Οκτωβρίου' ] );
$p7 = [ 'articles' => [ $manual, $odd ] ];
$P::clean( $p7, '2026-11-18', $day1 );
$seen = get_option( 'presshub_ai_pool_first_seen', [] );
pc_check( 'first seen: protected items are not recorded', ! isset( $seen[ $P::url_key( 'https://ciren.cy/manual' ) ] ) );
pc_check( 'first seen: an unreadable published_at is aged like no date', isset( $seen[ $P::url_key( 'https://ciren.cy/odd' ) ] ) );
$p8 = [ 'articles' => [ $odd ] ];
$P::clean( $p8, '2026-11-24', $day1 + 6 * 24 * 3600 );
pc_check( 'first seen: the unreadable-date item is dropped after the limit', 0 === count( $p8['articles'] ) );

// The max-age filter also sets how long first-seen entries are kept.
delete_option( 'presshub_ai_pool_first_seen' );
$long_age = static function () { return 240; };
add_filter( 'presshub_ai_pool_max_age_hours', $long_age );
$p9 = [ 'articles' => [ $undated ] ];
$P::clean( $p9, '2026-11-18', $day1 );
$p10 = [ 'articles' => [ $undated ] ];
$P::clean( $p10, '2026-11-24', $day1 + 6 * 24 * 3600 );
pc_check( 'first seen: kept inside a filtered 240 h limit', 1 === count( $p10['articles'] ) && gmdate( 'c', $day1 ) === ( $p10['articles'][0]['first_seen_at'] ?? '' ) );
$p11 = [ 'articles' => [ $undated ] ];
$P::clean( $p11, '2026-11-29', $day1 + 11 * 24 * 3600 );
pc_check( 'first seen: dropped after the filtered limit, not reset', 0 === count( $p11['articles'] ) );
remove_filter( 'presshub_ai_pool_max_age_hours', $long_age );

// wiring
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-news-harvester.php' );
$pos_clean = strpos( $src, 'PressHub_AI_Pool_Cleaner::clean( $payload, $date )' );
pc_check( 'wiring: harvest_all cleans after merging social posts and before the snapshot', false !== $pos_clean && $pos_clean > strpos( $src, '$this->merge_social_posts( $payload, $date, $edition );' ) && $pos_clean < strpos( $src, '$this->save_snapshot( $date, $payload, $edition );', $pos_clean ) );

if ( $failures > 0 ) {
    echo "PoolCleanerTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "PoolCleanerTest: OK ({$checks} checks)\n";
