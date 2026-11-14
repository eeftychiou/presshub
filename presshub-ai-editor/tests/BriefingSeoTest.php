<?php
/**
 * BriefingSeoTest — structural SEO for Daily Briefing text posts
 * (PressHub_AI_Briefing_SEO and its wiring in PressHub_AI_News_Curator).
 *
 *   1. Headline shortening keeps the full title within 65 characters.
 *   2. Long paragraphs are split at sentence ends, never after "κ." and never inside a link.
 *   3. Only links to harvested articles survive; a "Πηγές" list is added.
 *   4. Tag mentions link to tag archives; a "Διαβάστε επίσης" list links related posts.
 *   5. create_wordpress_post() applies all of it, and the setting turns it off.
 *   6. build_prompt() appends the writing rules with the headline budget.
 */

// Taxonomy / URL stubs not provided by wordpress-stubs.php.
if ( ! function_exists( 'wp_get_post_tags' ) ) {
    function wp_get_post_tags( $post_id, $args = [] ) {
        $terms = $GLOBALS['POST_TAGS'][ (int) $post_id ] ?? [];
        if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
            return array_map( function ( $t ) { return (int) $t->term_id; }, $terms );
        }
        return $terms;
    }
}
if ( ! function_exists( 'get_tag_link' ) ) {
    function get_tag_link( $tag_id ) {
        return 'http://example.test/tag/t' . (int) $tag_id . '/';
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'http://example.test' . $path;
    }
}

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
function bs_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}
function bs_term( $id, $name ) {
    return (object) [ 'term_id' => $id, 'name' => $name ];
}

$GLOBALS['UPLOAD_DIR']    = sys_get_temp_dir() . '/presshub-briefing-seo-' . uniqid();
$GLOBALS['OPTIONS_STORE'] = [
    'presshub_ai_seo_auto_enabled' => 0, // no AI call; tags are seeded below
    'presshub_ai_memory_enabled'   => 0,
];

// =========================================================================
// 1. Headline shortening
// =========================================================================

$long = 'Συμβιβασμός στην ΕΕ για την Ουκρανία, κοινό μέτωπο Αθήνας-Λευκωσίας για το Κυπριακό και εξελίξεις στο Great Sea Interconnector';
$short = PressHub_AI_Briefing_SEO::shorten_headline( $long, 34 );
bs_check( 'shorten: fits the budget', mb_strlen( $short ) <= 34 );
bs_check( 'shorten: keeps the lead topic', 0 === strpos( $short, 'Συμβιβασμός στην ΕΕ' ) );
bs_check( 'shorten: cuts at the clause separator when it fits', 'Συμβιβασμός στην ΕΕ για την Ουκρανία' === PressHub_AI_Briefing_SEO::shorten_headline( $long, 40 ) );
bs_check( 'shorten: no trailing comma or ellipsis', ! preg_match( '/[,…]$/u', $short ) && false === strpos( $short, '...' ) );

$no_sep = PressHub_AI_Briefing_SEO::shorten_headline( 'Νέα μέτρα της κυβέρνησης για τη στέγαση των νέων ζευγαριών στην Κύπρο', 40 );
bs_check( 'shorten: word boundary fits budget', mb_strlen( $no_sep ) <= 40 );
bs_check( 'shorten: drops a dangling article/preposition', ! preg_match( '/\s(για|τη|των|της|στην)$/u', $no_sep ) );
bs_check( 'shorten: short headline unchanged', 'Σύντομος τίτλος ειδήσεων' === PressHub_AI_Briefing_SEO::shorten_headline( 'Σύντομος τίτλος ειδήσεων', 40 ) );

// =========================================================================
// 2. Paragraph splitting
// =========================================================================

$sentence = 'Ο κ. Χριστοδουλίδης συναντήθηκε σήμερα με τον Πρόεδρο της Επιτροπής στις Βρυξέλλες για το ενεργειακό ζήτημα.';
$long_p   = '<p>' . implode( ' ', array_fill( 0, 7, $sentence ) ) . ' Δείτε την <a href="https://philenews.com/a">ανακοίνωση. Πλήρες κείμενο</a> εδώ.</p>';
$split    = PressHub_AI_Briefing_SEO::split_long_paragraphs( $long_p );
bs_check( 'split: long paragraph becomes several', substr_count( $split, '<p>' ) >= 2 );
bs_check( 'split: no break after the abbreviation "κ."', false === strpos( $split, 'κ.</p>' ) );
bs_check( 'split: link left intact', false !== strpos( $split, '<a href="https://philenews.com/a">ανακοίνωση. Πλήρες κείμενο</a>' ) );
bs_check( 'split: text preserved', strip_tags( str_replace( '</p><p>', ' ', $split ) ) === strip_tags( $long_p ) );
foreach ( explode( '</p>', $split ) as $chunk ) {
    if ( '' !== trim( strip_tags( $chunk ) ) ) {
        bs_check( 'split: each paragraph within the max', mb_strlen( trim( strip_tags( $chunk ) ) ) <= PressHub_AI_Briefing_SEO::PARAGRAPH_MAX_CHARS );
    }
}
$short_p = '<p>Σύντομη παράγραφος. Δεύτερη πρόταση.</p>';
bs_check( 'split: short paragraph unchanged', $short_p === PressHub_AI_Briefing_SEO::split_long_paragraphs( $short_p ) );

// =========================================================================
// 3. Source links
// =========================================================================

$articles = [
    [ 'title' => 'Eurostat: Πληθωρισμός 2,1%', 'source' => 'Philenews', 'url' => 'https://www.philenews.com/oikonomia/123/' ],
    [ 'title' => 'Fitch για την Κύπρο', 'source' => 'Kathimerini', 'url' => 'https://kathimerini.com.cy/fitch' ],
];
$html  = '<p>Σύμφωνα με τον <a href="https://philenews.com/oikonomia/123">Φιλελεύθερο</a> και την <a href="https://made-up.example/fake">Eurostat</a>, και <a href="http://example.test/old-post/">παλαιότερο άρθρο</a>.</p>';
$links = PressHub_AI_Briefing_SEO::sanitize_links( $html, $articles );
bs_check( 'links: harvested article link kept (www / trailing slash ignored)', false !== strpos( $links['html'], 'href="https://philenews.com/oikonomia/123"' ) );
bs_check( 'links: made-up URL unwrapped, text kept', false === strpos( $links['html'], 'made-up.example' ) && false !== strpos( $links['html'], 'Eurostat' ) );
bs_check( 'links: internal link kept', false !== strpos( $links['html'], 'http://example.test/old-post/' ) );
bs_check( 'links: one removed, one cited', 1 === $links['removed'] && 1 === count( $links['cited'] ) );

$sources = PressHub_AI_Briefing_SEO::sources_section( $articles, $links['cited'] );
bs_check( 'sources: lists the cited article', false !== strpos( $sources, 'philenews.com/oikonomia/123' ) && false !== strpos( $sources, 'Πηγές' ) );
bs_check( 'sources: uncited article left out when some were cited', false === strpos( $sources, 'kathimerini.com.cy' ) );
$all = PressHub_AI_Briefing_SEO::sources_section( $articles, [] );
bs_check( 'sources: falls back to all articles when none cited', false !== strpos( $all, 'kathimerini.com.cy/fitch' ) && 2 === substr_count( $all, '<li>' ) );
bs_check( 'sources: nothing without URLs', '' === PressHub_AI_Briefing_SEO::sources_section( [ [ 'title' => 'x', 'url' => '' ] ], [] ) );

// =========================================================================
// 4. Tag links and related posts
// =========================================================================

$tag_html = '<h2>Κυπριακό</h2><p>Νέα κινητικότητα στο Κυπριακό και στην Τουρκία. Το Κυπριακό ξανά.</p><p>Ο <a href="https://x.test">Great Sea Interconnector</a> και η Τουρκία.</p>';
$tagged   = PressHub_AI_Briefing_SEO::link_tag_mentions( $tag_html, [
    'Κυπριακό'                 => 'http://example.test/tag/kypriako/',
    'Τουρκία'                  => 'http://example.test/tag/tourkia/',
    'Great Sea Interconnector' => 'http://example.test/tag/gsi/',
] );
bs_check( 'tags: heading not linked', false !== strpos( $tagged['html'], '<h2>Κυπριακό</h2>' ) );
bs_check( 'tags: first paragraph mention linked once', 1 === substr_count( $tagged['html'], 'tag/kypriako/' ) );
bs_check( 'tags: second tag in same text run linked', 1 === substr_count( $tagged['html'], 'tag/tourkia/' ) );
bs_check( 'tags: mention inside an existing link not linked', false === strpos( $tagged['html'], 'tag/gsi/' ) );

$GLOBALS['POST_TAGS'] = [
    501 => [ bs_term( 7, 'Κυπριακό' ), bs_term( 8, 'Τουρκία' ) ],
    502 => [ bs_term( 8, 'Τουρκία' ) ],
    503 => [ bs_term( 7, 'Κυπριακό' ), bs_term( 8, 'Τουρκία' ) ],
];
$GLOBALS['POST_META_STORE'][502]['_presshub_briefing_type'] = 'text';
$GLOBALS['GET_POSTS_RESULT'] = [
    (object) [ 'ID' => 502, 'post_title' => 'Προηγούμενη ενημέρωση' ],
    (object) [ 'ID' => 503, 'post_title' => 'Ανάλυση για το Κυπριακό' ],
];
$related = PressHub_AI_Briefing_SEO::related_posts( 501, [ 7, 8 ] );
bs_check( 'related: ranks the post sharing more tags first', ! empty( $related ) && 503 === $related[0]['id'] );
bs_check( 'related: includes permalink', ! empty( $related ) && 'http://example.test/?p=503' === $related[0]['url'] );

// =========================================================================
// 5. create_wordpress_post() end to end
// =========================================================================

$curator = new PressHub_AI_News_Curator();
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_prefix']      = 'Πρωινή Ενημέρωση:';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_title_date_format'] = 'd/m/Y';
$GLOBALS['WP_INSERTED_POSTS'] = [];

// Feed the articles the way generate_briefing() does (build_prompt -> format_articles_context).
$prompts = $curator->build_prompt( $articles, '', '2026-11-14' );
bs_check( 'prompt: SEO rules appended to the user prompt', false !== strpos( $prompts['user_prompt'], 'Κανόνες SEO και αναγνωσιμότητας' ) );
$budget = $curator->headline_char_budget( '2026-11-14' );
bs_check( 'prompt: headline budget matches prefix + date overhead', ( 65 - mb_strlen( 'Πρωινή Ενημέρωση:  - 14/11/2026' ) ) === $budget );
bs_check( 'prompt: rules state the budget', false !== strpos( $prompts['user_prompt'], 'το πολύ ' . $budget . ' χαρακτήρες' ) );

$body = '<h1>' . $long . '</h1>'
    . '<p>' . implode( ' ', array_fill( 0, 6, $sentence ) ) . ' Το Κυπριακό στο επίκεντρο, όπως γράφει ο <a href="https://philenews.com/oikonomia/123">Φιλελεύθερος</a>.</p>'
    . '<h2>Διεθνή</h2><p>Εξελίξεις με την <a href="https://invented.example/x">Τουρκία</a>.</p>';

// The new post will get id = count(WP_INSERTED_POSTS)+1 in the stub; seed its tags.
$next_id = ( $GLOBALS["WP_INSERT_POST_COUNTER"] ?? 100 ) + 1;
$GLOBALS['POST_TAGS'][ $next_id ] = [ bs_term( 7, 'Κυπριακό' ), bs_term( 8, 'Τουρκία' ) ];
$GLOBALS['POST_TAGS'][503] = [ bs_term( 7, 'Κυπριακό' ), bs_term( 8, 'Τουρκία' ) ];

$post_id = $curator->create_wordpress_post( $body, '2026-11-14', $long );
bs_check( 'post: created', is_int( $post_id ) && $post_id > 0 );
$inserted = end( $GLOBALS['WP_INSERTED_POSTS'] );
bs_check( 'post: title within 65 chars', mb_strlen( $inserted['post_title'] ) <= 65 );
bs_check( 'post: title keeps prefix and date', 0 === strpos( $inserted['post_title'], 'Πρωινή Ενημέρωση:' ) && false !== strpos( $inserted['post_title'], '14/11/2026' ) );
bs_check( 'post: invented link removed before insert', false === strpos( $inserted['post_content'], 'invented.example' ) );
bs_check( 'post: Πηγές list added', false !== strpos( $inserted['post_content'], PressHub_AI_Briefing_SEO::SOURCES_CLASS ) );
bs_check( 'post: long intro split', substr_count( $inserted['post_content'], '<p>' ) >= 3 );

$final = (string) ( $GLOBALS['POSTS_STORE'][ $post_id ]['post_content'] ?? '' );
bs_check( 'post: internal tag link added after tagging', false !== strpos( $final, 'http://example.test/tag/t7/' ) );
bs_check( 'post: related list added before Πηγές', false !== strpos( $final, PressHub_AI_Briefing_SEO::RELATED_CLASS )
    && strpos( $final, PressHub_AI_Briefing_SEO::RELATED_CLASS ) < strpos( $final, PressHub_AI_Briefing_SEO::SOURCES_CLASS ) );
$report = get_post_meta( $post_id, PressHub_AI_Briefing_SEO::META_APPLIED, true );
bs_check( 'post: report meta stored', is_array( $report ) && 1 === ( $report['source_links_removed'] ?? null ) && ! empty( $report['title_shortened_from'] ) );

// Setting off: legacy behaviour.
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_seo_enhance_enabled'] = 0;
$off_prompts = $curator->build_prompt( $articles, '', '2026-11-14' );
bs_check( 'off: no rules in the prompt', false === strpos( $off_prompts['user_prompt'], 'Κανόνες SEO' ) );
$off_id   = $curator->create_wordpress_post( $body, '2026-11-14', $long );
$off_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
bs_check( 'off: title not shortened', mb_strlen( $off_post['post_title'] ) > 65 );
bs_check( 'off: no Πηγές list', false === strpos( $off_post['post_content'], PressHub_AI_Briefing_SEO::SOURCES_CLASS ) );
bs_check( 'off: sanitizer accepts checkbox values', 0 === PressHub_AI_Settings_Storage::sanitize_briefing_seo_enhance_enabled( '0' ) && 1 === PressHub_AI_Settings_Storage::sanitize_briefing_seo_enhance_enabled( '1' ) );
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_seo_enhance_enabled'] );
bs_check( 'default: enabled', true === PressHub_AI_Settings_Storage::get_briefing_seo_enhance_enabled() );

if ( $failures > 0 ) {
    fwrite( STDERR, "BriefingSeoTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "BriefingSeoTest: OK\n";
exit( 0 );
