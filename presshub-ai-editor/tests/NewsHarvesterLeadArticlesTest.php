<?php
/**
 * NewsHarvesterLeadArticlesTest — Unit tests for GitHub Issue #27:
 * "bug(briefing): harvester collects category/section landing pages instead of top news articles"
 *
 * Test cases:
 *   1. Category / taxonomy URLs are rejected by is_article_url().
 *   2. Article URLs with dates, multi-segments, numeric IDs, or substantive slugs are accepted by is_article_url().
 *   3. extract_article_links_from_html() prioritizes editorial containers & headings over navigation/footer links.
 *   4. MAX_LINKS_PER_SOURCE = 4 default limit & filterable via get_max_links_per_source().
 *   5. Word count and title validation (is_valid_harvested_article) filters out shallow/empty pages and category titles.
 *   6. Full discover_source_articles() with 4-link sampling.
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
function lead_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$harvester = new PressHub_AI_News_Harvester();
$base_url  = 'https://www.kathimerini.gr';

// =========================================================================
// 1. Category / Taxonomy / Utility URLs are rejected by is_article_url()
// =========================================================================

$rejected_urls = [
    '/politiki/',
    '/politiki',
    '/category/politiki',
    '/category/oikonomia',
    '/tag/ekloges',
    '/tags/ellada',
    '/oikonomia/',
    '/oikonomia',
    '/koinonia/',
    '/kosmos/',
    '/apopseis/',
    '/politismos/',
    '/athlitismos/',
    '/kairos/',
    '/paixnidia/',
    '/ereplica/',
    '/stiles/',
    '/life/',
    '/media/',
    '/webtv/',
    '/podcasts/',
    '/contact',
    '/privacy',
    '/terms',
    '/about',
    '/rss',
    '/feed',
    '/politiki/apopseis/',
    '/oikonomia/ellada/',
    '/section/news',
    '/sections/world',
    '/topic/climate',
    '/author/john-doe',
    '/archive/2026/08',
    '/randomshallow/',
    '/terms-of-use',
    '/privacy-policy',
    'https://www.kathimerini.gr/politiki/',
    'https://www.kathimerini.gr/category/oikonomia',
    'https://www.kathimerini.gr/ereplica/',
    'https://www.kathimerini.gr/kairos/',
];

foreach ( $rejected_urls as $url ) {
    lead_check( "is_article_url rejects taxonomy/section: {$url}", ! $harvester->is_article_url( $url, $base_url ) );
}


// =========================================================================
// 2. Legitimate Article URLs are accepted by is_article_url()
// =========================================================================

$accepted_urls = [
    'https://www.kathimerini.gr/politiki/562345/synantisi-mitsotaki-erntogan-stin-agkyra/',
    'https://www.kathimerini.gr/oikonomia/562346/neo-programma-epidotisis-gia-energeiaki-anavathmisi/',
    'https://www.kathimerini.gr/kosmos/562347/symfonia-stin-ee-gia-ton-proypologismo/',
    'https://www.kathimerini.gr/epikairothta/2026/08/26/fotia-stin-voreia-eyvoia-epicheiroyn-enaeria-mesa/',
    'https://www.amna.gr/article/789123/koino-tilegrafima-ape-mpe',
    'https://www.news247.gr/politiki/kyvernhsh-metra-gia-akriveia-10542312.html',
    'https://www.iefimerida.gr/politiki/ayxiseis-stis-syntaxeis-poioi-einai-oi-dikaioyhoi',
    'https://www.normal-news.gr/news/101',
    'https://www.source1.gr/article-1',
    '/politiki/562345/synantisi-mitsotaki-erntogan-stin-agkyra/',
    '/2026/08/26/greece/pyrkagies-stin-attiki/',
];

foreach ( $accepted_urls as $url ) {
    lead_check( "is_article_url accepts article: {$url}", $harvester->is_article_url( $url, $base_url ) );
}


// =========================================================================
// 3. extract_article_links_from_html() prioritizes editorial containers & headings
// =========================================================================

$html_sample = '<!DOCTYPE html>
<html>
<head><title>Ειδησεογραφικό Portal</title></head>
<body>
    <header>
        <nav class="main-nav">
            <a href="/politiki/">Πολιτική</a>
            <a href="/oikonomia/">Οικονομία</a>
            <a href="/kosmos/">Κόσμος</a>
            <a href="/ereplica/">eReplica</a>
            <a href="/kairos/">Καιρός</a>
        </nav>
    </header>
    <main>
        <div class="hero">
            <h1><a href="/politiki/562345/synantisi-mitsotaki-erntogan-stin-agkyra/">Συνάντηση Μητσοτάκη - Ερντογάν στην Άγκυρα</a></h1>
        </div>
        <div class="card top-story">
            <h2><a href="/oikonomia/562346/neo-programma-epidotisis-gia-energeiaki-anavathmisi/">Νέο πρόγραμμα επιδότησης για ενεργειακή αναβάθμιση</a></h2>
        </div>
        <article class="featured">
            <h3><a href="/kosmos/562347/symfonia-stin-ee-gia-ton-proypologismo/">Συμφωνία στην ΕΕ για τον νέο ευρωπαϊκό προϋπολογισμό</a></h3>
        </article>
        <div class="regular-stories">
            <h4><a href="/epikairothta/2026/08/26/fotia-stin-voreia-eyvoia-epicheiroyn-enaeria-mesa/">Φωτιά στη Βόρεια Εύβοια: Επιχειρούν ισχυρές δυνάμεις</a></h4>
            <a href="/diethni/562348/diethnis-diaskepsi-gia-tin-eirini-sti-mesi-anatoli/">Διεθνής διάσκεψη για την ειρήνη</a>
        </div>
    </main>
    <footer>
        <div class="footer-links">
            <a href="/terms-of-use">Όροι Χρήσης</a>
            <a href="/privacy-policy">Πολιτική Απορρήτου</a>
            <a href="/contact">Επικοινωνία</a>
            <a href="/about-us">Σχετικά</a>
            <a href="/paixnidia/">Παιχνίδια</a>
        </div>
    </footer>
</body>
</html>';

$extracted_links = $harvester->extract_article_links_from_html( $html_sample, $base_url );

lead_check( 'extract_article_links_from_html: extracted links is array', is_array( $extracted_links ) );
lead_check( 'extract_article_links_from_html: exactly 5 article links extracted', count( $extracted_links ) === 5 );

// Priority check: Hero link is #1
lead_check( 'extract_article_links_from_html: hero story is first', isset( $extracted_links[0] ) && false !== strpos( $extracted_links[0], 'synantisi-mitsotaki' ) );

// Card / Top story is #2
lead_check( 'extract_article_links_from_html: top-story card is second', isset( $extracted_links[1] ) && false !== strpos( $extracted_links[1], 'neo-programma-epidotisis' ) );

// Featured article is #3
lead_check( 'extract_article_links_from_html: featured article is third', isset( $extracted_links[2] ) && false !== strpos( $extracted_links[2], 'symfonia-stin-ee' ) );

// Section and utility links are completely excluded
lead_check( 'extract_article_links_from_html: excludes /politiki/', ! in_array( 'https://www.kathimerini.gr/politiki/', $extracted_links, true ) );
lead_check( 'extract_article_links_from_html: excludes /ereplica/', ! in_array( 'https://www.kathimerini.gr/ereplica/', $extracted_links, true ) );
lead_check( 'extract_article_links_from_html: excludes /kairos/', ! in_array( 'https://www.kathimerini.gr/kairos/', $extracted_links, true ) );
lead_check( 'extract_article_links_from_html: excludes /paixnidia/', ! in_array( 'https://www.kathimerini.gr/paixnidia/', $extracted_links, true ) );
lead_check( 'extract_article_links_from_html: excludes /terms-of-use', ! in_array( 'https://www.kathimerini.gr/terms-of-use', $extracted_links, true ) );


// =========================================================================
// 4. MAX_LINKS_PER_SOURCE = 4 default limit & filterable override
// =========================================================================

lead_check( 'MAX_LINKS_PER_SOURCE constant equals 4', PressHub_AI_News_Harvester::MAX_LINKS_PER_SOURCE === 4 );
lead_check( 'get_max_links_per_source() returns 4 by default', $harvester->get_max_links_per_source() === 4 );
lead_check( 'get_max_links_per_source(6) returns explicit 6', $harvester->get_max_links_per_source( 6 ) === 6 );

add_filter( 'presshub_ai_harvest_max_links_per_source', function() { return 8; } );
lead_check( 'get_max_links_per_source() respects filter override (8)', $harvester->get_max_links_per_source() === 8 );
remove_all_filters( 'presshub_ai_harvest_max_links_per_source' );
lead_check( 'get_max_links_per_source() returns 4 after filter removal', $harvester->get_max_links_per_source() === 4 );


// =========================================================================
// 5. Word count & title validation (is_valid_harvested_article)
// =========================================================================

// Category titles should be rejected
lead_check( 'is_category_title: Πολιτική is category', $harvester->is_category_title( 'Πολιτική' ) );
lead_check( 'is_category_title: Οικονομία is category', $harvester->is_category_title( 'Οικονομία' ) );
lead_check( 'is_category_title: eReplica is category', $harvester->is_category_title( 'eReplica' ) );
lead_check( 'is_category_title: Καιρός is category', $harvester->is_category_title( 'Καιρός' ) );
lead_check( 'is_category_title: Real headline is not category', ! $harvester->is_category_title( 'Συνάντηση Μητσοτάκη με τον Πρόεδρο της Γαλλίας' ) );

// Short title (<= 2 words) rejected
$short_title_art = [
    'title'   => 'Νέα Είδηση',
    'content' => str_repeat( 'Αναλυτικό περιεχόμενο άρθρου για την ελληνική επικαιρότητα και την πολιτική σκηνή. ', 10 ),
];
lead_check( 'is_valid_harvested_article: rejects title with <= 2 words', ! $harvester->is_valid_harvested_article( $short_title_art ) );

// Shallow body (< 30 words) rejected
$shallow_body_art = [
    'title'   => 'Συνάντηση Κορυφής για την Ελληνική Οικονομία',
    'content' => 'Σύντομο κείμενο λίγων μόνο λέξεων.',
];
lead_check( 'is_valid_harvested_article: rejects body with < 30 words', ! $harvester->is_valid_harvested_article( $shallow_body_art ) );

// Category title rejected
$cat_title_art = [
    'title'   => 'Πολιτική',
    'content' => str_repeat( 'Αναλυτικό περιεχόμενο άρθρου για την ελληνική επικαιρότητα και την πολιτική σκηνή. ', 10 ),
];
lead_check( 'is_valid_harvested_article: rejects category title article', ! $harvester->is_valid_harvested_article( $cat_title_art ) );

// Valid substantive article accepted
$valid_art = [
    'title'   => 'Συνάντηση Μητσοτάκη με τον Γάλλο Πρόεδρο στο Παρίσι',
    'content' => 'Εκτενής συζήτηση πραγματοποιήθηκε σήμερα στο Παρίσι μεταξύ των δύο ηγετών, με επίκεντρο την ευρωπαϊκή ασφάλεια, την ενέργεια και τις οικονομικές σχέσεις των δύο κρατών. Οι δύο πλευρές συμφώνησαν σε κοινές πρωτοβουλίες για την ενίσχυση της περιφερειακής σταθερότητας στην Ανατολική Μεσόγειο.',
];
lead_check( 'is_valid_harvested_article: accepts valid substantive article', $harvester->is_valid_harvested_article( $valid_art ) );

// RSS fallback article with 10-25 words passes when tier is 'rss_description' or 'rss' (Issue #37)
$rss_concise_art = [
    'title'   => 'Συνάντηση Κορυφής για την Ελληνική Οικονομία',
    'content' => 'Υπεγράφη σήμερα νέα συμφωνία οικονομικής συνεργασίας μεταξύ των δύο πλευρών για πράσινη ενέργεια και τεχνολογία.',
    'tier'    => 'rss_description',
];
lead_check( 'is_valid_harvested_article: accepts concise RSS description fallback (14 words) when tier === rss_description', $harvester->is_valid_harvested_article( $rss_concise_art ) );

$rss_tier_art = [
    'title'   => 'Συνάντηση Κορυφής για την Ελληνική Οικονομία',
    'content' => 'Υπεγράφη σήμερα νέα συμφωνία οικονομικής συνεργασίας μεταξύ των δύο πλευρών για πράσινη ενέργεια και τεχνολογία.',
    'tier'    => 'rss',
];
lead_check( 'is_valid_harvested_article: accepts concise RSS article (14 words) when tier === rss', $harvester->is_valid_harvested_article( $rss_tier_art ) );

// Regular HTML article with same concise content (< 30 words) is rejected
$html_concise_art = [
    'title'   => 'Συνάντηση Κορυφής για την Ελληνική Οικονομία',
    'content' => 'Υπεγράφη σήμερα νέα συμφωνία οικονομικής συνεργασίας μεταξύ των δύο πλευρών για πράσινη ενέργεια και τεχνολογία.',
    'tier'    => 'dom',
];
lead_check( 'is_valid_harvested_article: rejects concise HTML scraped article (< 30 words) when tier !== rss_description', ! $harvester->is_valid_harvested_article( $html_concise_art ) );

// Very short RSS description (< 8 words) is still rejected
$rss_too_short = [
    'title'   => 'Συνάντηση Κορυφής για την Ελληνική Οικονομία',
    'content' => 'Σύντομη ανακοίνωση τύπου.',
    'tier'    => 'rss_description',
];
lead_check( 'is_valid_harvested_article: rejects RSS article with < 8 words', ! $harvester->is_valid_harvested_article( $rss_too_short ) );

// Custom filter presshub_ai_harvest_min_rss_words override
add_filter( 'presshub_ai_harvest_min_rss_words', function() { return 5; } );
$rss_5_words = [
    'title'   => 'Συνάντηση Κορυφής για την Ελληνική Οικονομία',
    'content' => 'Πέντε λέξεις στο κείμενο εδώ.',
    'tier'    => 'rss_description',
];
lead_check( 'is_valid_harvested_article: respects presshub_ai_harvest_min_rss_words filter', $harvester->is_valid_harvested_article( $rss_5_words ) );
remove_all_filters( 'presshub_ai_harvest_min_rss_words' );


// =========================================================================
// 6. Full discover_source_articles() with 4-link sampling
// =========================================================================

$GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $html_sample ) {
    return [
        'response' => [ 'code' => 200 ],
        'body'     => $html_sample,
    ];
};

$discovery = $harvester->discover_source_articles( 'https://www.kathimerini.gr' );

lead_check( 'discover_source_articles: article_urls capped at 4', count( $discovery['article_urls'] ) === 4 );
lead_check( 'discover_source_articles: first link is lead story', false !== strpos( $discovery['article_urls'][0], 'synantisi-mitsotaki' ) );
lead_check( 'discover_source_articles: fourth link is epikairothta', false !== strpos( $discovery['article_urls'][3], 'fotia-stin-voreia-eyvoia' ) );


// =========================================================================
// 7. Per-Source Article Quota Link Slicing (Issue #31)
// =========================================================================

$html_10_sample = '<!DOCTYPE html><html><body><main>
<h1><a href="/politiki/101-title-one/">Title 1</a></h1>
<h2><a href="/politiki/102-title-two/">Title 2</a></h2>
<h3><a href="/politiki/103-title-three/">Title 3</a></h3>
<h4><a href="/politiki/104-title-four/">Title 4</a></h4>
<h5><a href="/politiki/105-title-five/">Title 5</a></h5>
<h6><a href="/politiki/106-title-six/">Title 6</a></h6>
<div><a href="/politiki/107-title-seven/">Title 7</a></div>
<div><a href="/politiki/108-title-eight/">Title 8</a></div>
<div><a href="/politiki/109-title-nine/">Title 9</a></div>
<div><a href="/politiki/110-title-ten/">Title 10</a></div>
</main></body></html>';

$GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $html_10_sample ) {
    return [
        'response' => [ 'code' => 200 ],
        'body'     => $html_10_sample,
    ];
};

// Test source with max_articles = 8
$discovery_8 = $harvester->discover_source_articles( [
    'url'          => 'https://www.kathimerini.gr',
    'max_articles' => 8,
] );
lead_check( 'discover_source_articles: max_articles = 8 yields 8 links', count( $discovery_8['article_urls'] ) === 8 );
lead_check( 'discover_source_articles: 8th link is title-eight', isset( $discovery_8['article_urls'][7] ) && false !== strpos( $discovery_8['article_urls'][7], 'title-eight' ) );

// Test source with max_articles = 2
$discovery_2 = $harvester->discover_source_articles( [
    'url'          => 'https://www.kathimerini.gr',
    'max_articles' => 2,
] );
lead_check( 'discover_source_articles: max_articles = 2 yields 2 links', count( $discovery_2['article_urls'] ) === 2 );

// Test explicit limit parameter
$discovery_explicit_6 = $harvester->discover_source_articles( 'https://www.kathimerini.gr', 6 );
lead_check( 'discover_source_articles: explicit limit = 6 yields 6 links', count( $discovery_explicit_6['article_urls'] ) === 6 );

if ( $failures > 0 ) {
    fwrite( STDERR, "NewsHarvesterLeadArticlesTest: {$failures} failure(s)\n" );
    exit( 1 );
}

echo "NewsHarvesterLeadArticlesTest: OK (70+ checks)\n";