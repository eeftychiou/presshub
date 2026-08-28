<?php
/**
 * NewsHarvesterTest — Unit tests for PressHub_AI_News_Harvester.
 *
 * Test cases:
 *   1. fetch_homepage_links extracts article links from Greek news homepage markup
 *      while filtering out nav/footer/privacy/tags/categories/social/anchors.
 *   2. Cloudflare / 403 / 503 bot protection detection & recording in blocked_sources.
 *   3. Cross-outlet article scraping & deduplication.
 *   4. Manual upload merging (PDF/DOCX/text extracted notes) into daily articles pool.
 *   5. Saving & loading daily JSON snapshots at wp-content/uploads/presshub-briefings/YYYY-MM-DD/raw-articles.json.
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
function nh_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$test_upload_dir = sys_get_temp_dir() . '/presshub-test-uploads-' . uniqid();
$GLOBALS['UPLOAD_DIR'] = $test_upload_dir;

// =========================================================================
// 1. Homepage link extraction from Greek news HTML markup
// =========================================================================

$sample_greek_html = '<!DOCTYPE html>
<html lang="el">
<head><title>Η Καθημερινή - Ειδήσεις</title></head>
<body>
    <header>
        <nav>
            <a href="/">Αρχική</a>
            <a href="/category/politiki">Πολιτική</a>
            <a href="/category/oikonomia">Οικονομία</a>
            <a href="https://facebook.com/kathimerinigr">Facebook</a>
            <a href="/tag/ekloges">#Εκλογές</a>
            <a href="/privacy-policy">Πολιτική Απορρήτου</a>
            <a href="/terms-of-use">Όροι Χρήσης</a>
            <a href="javascript:void(0);">Login</a>
            <a href="#top">Επάνω</a>
        </nav>
    </header>
    <main>
        <div class="top-news">
            <h2><a href="/politiki/562345/synantisi-mitsotaki-erntogan-stin-agkyra/">Συνάντηση Μητσοτάκη - Ερντογάν στην Άγκυρα για τα ελληνοτουρκικά</a></h2>
            <h2><a href="https://www.kathimerini.gr/oikonomia/562346/neo-programma-epidotisis-gia-energeiaki-anavathmisi/">Νέο πρόγραμμα επιδότησης για ενεργειακή αναβάθμιση κατοικιών</a></h2>
            <h2><a href="/politiki/562345/synantisi-mitsotaki-erntogan-stin-agkyra/">Duplicate link of top story</a></h2>
        </div>
        <div class="more-stories">
            <article>
                <a href="/kosmos/562347/symfonia-stin-ee-gia-ton-proypologismo/">Συμφωνία στην ΕΕ για τον νέο ευρωπαϊκό προϋπολογισμό</a>
            </article>
            <article>
                <a href="/epikairothta/2026/08/26/fotia-stin-voreia-eyvoia-epicheiroyn-enaeria-mesa/">Φωτιά στη Βόρεια Εύβοια: Επιχειρούν ισχυρές δυνάμεις</a>
            </article>
        </div>
    </main>
    <footer>
        <a href="/contact">Επικοινωνία</a>
        <a href="/about-us">Σχετικά με εμάς</a>
        <a href="/rss">RSS Feed</a>
        <p>&copy; 2026 Καθημερινή</p>
    </footer>
</body>
</html>';

$GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $sample_greek_html ) {
    if ( 'https://www.kathimerini.gr' === $url || 'https://www.kathimerini.gr/' === $url ) {
        return [
            'response' => [ 'code' => 200 ],
            'body'     => $sample_greek_html,
        ];
    }
    return [ 'response' => [ 'code' => 200 ], 'body' => '<article><h1>Άρθρο</h1><p>Περιεχόμενο άρθρου.</p></article>' ];
};

$harvester = new PressHub_AI_News_Harvester();
$extracted_links = $harvester->fetch_homepage_links( 'https://www.kathimerini.gr' );

nh_check( 'fetch_homepage_links: returns array', is_array( $extracted_links ) );
nh_check( 'fetch_homepage_links: extracted count >= 4', count( $extracted_links ) >= 4 );
nh_check( 'fetch_homepage_links: relative link converted to absolute', in_array( 'https://www.kathimerini.gr/politiki/562345/synantisi-mitsotaki-erntogan-stin-agkyra/', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: absolute link preserved', in_array( 'https://www.kathimerini.gr/oikonomia/562346/neo-programma-epidotisis-gia-energeiaki-anavathmisi/', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: world news link found', in_array( 'https://www.kathimerini.gr/kosmos/562347/symfonia-stin-ee-gia-ton-proypologismo/', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: current news link found', in_array( 'https://www.kathimerini.gr/epikairothta/2026/08/26/fotia-stin-voreia-eyvoia-epicheiroyn-enaeria-mesa/', $extracted_links, true ) );

// Verify filtering out boilerplate/nav/footer/tags/social
nh_check( 'fetch_homepage_links: filters category', ! in_array( 'https://www.kathimerini.gr/category/politiki', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: filters tag', ! in_array( 'https://www.kathimerini.gr/tag/ekloges', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: filters privacy policy', ! in_array( 'https://www.kathimerini.gr/privacy-policy', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: filters terms', ! in_array( 'https://www.kathimerini.gr/terms-of-use', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: filters social link', ! in_array( 'https://facebook.com/kathimerinigr', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: filters contact', ! in_array( 'https://www.kathimerini.gr/contact', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: filters rss', ! in_array( 'https://www.kathimerini.gr/rss', $extracted_links, true ) );
nh_check( 'fetch_homepage_links: deduplicates duplicate links', count( array_filter( $extracted_links, function( $l ) {
    return $l === 'https://www.kathimerini.gr/politiki/562345/synantisi-mitsotaki-erntogan-stin-agkyra/';
} ) ) === 1 );


// =========================================================================
// 2. Cloudflare / 403 / 503 bot protection detection & blocked_sources
// =========================================================================

$cf_challenge_html = '<html><head><title>Just a moment...</title></head><body>'
    . '<div class="cf-browser-verification cf-im-under-attack">'
    . '<h1>Checking if the site connection is secure</h1>'
    . '<p>Enable JavaScript and cookies to continue</p>'
    . '<span id="challenge-platform">Ray ID: 7fc1234567890</span>'
    . '</div></body></html>';

$blocked_sources_tests = [
    'https://www.blocked-403.gr' => [ 'response' => [ 'code' => 403 ], 'body' => 'Forbidden Access' ],
    'https://www.blocked-503.gr' => [ 'response' => [ 'code' => 503 ], 'body' => 'Service Unavailable' ],
    'https://www.cloudflare-challenge.gr' => [ 'response' => [ 'code' => 200 ], 'body' => $cf_challenge_html ],
    'https://www.normal-news.gr' => [
        'response' => [ 'code' => 200 ],
        'body'     => '<article><h1><a href="https://www.normal-news.gr/news/101">Κανονική Είδηση</a></h1><p>Περιεχόμενο κανονικής είδησης.</p></article>',
    ],
    'https://www.normal-news.gr/news/101' => [
        'response' => [ 'code' => 200 ],
        'body'     => '<article><h1>Κανονική Είδηση</h1><p>Αναλυτικό κείμενο για την είδηση στην Ελλάδα.</p></article>',
    ],
];

$GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $blocked_sources_tests ) {
    if ( isset( $blocked_sources_tests[ $url ] ) ) {
        return $blocked_sources_tests[ $url ];
    }
    return [ 'response' => [ 'code' => 200 ], 'body' => '<article><p>Dummy text</p></article>' ];
};

$sources = [
    'https://www.blocked-403.gr',
    'https://www.blocked-503.gr',
    'https://www.cloudflare-challenge.gr',
    'https://www.normal-news.gr',
];

$harvest_result = $harvester->harvest_all( $sources, '2026-08-26' );

nh_check( 'harvest_all: returns array', is_array( $harvest_result ) );
nh_check( 'harvest_all: date set', ( $harvest_result['date'] ?? '' ) === '2026-08-26' );
nh_check( 'harvest_all: blocked_sources contains 3 blocked sites', count( $harvest_result['blocked_sources'] ?? [] ) === 3 );
nh_check( 'harvest_all: blocked 403 recorded', in_array( 'https://www.blocked-403.gr', $harvest_result['blocked_sources'], true ) );
nh_check( 'harvest_all: blocked 503 recorded', in_array( 'https://www.blocked-503.gr', $harvest_result['blocked_sources'], true ) );
nh_check( 'harvest_all: cloudflare challenge recorded', in_array( 'https://www.cloudflare-challenge.gr', $harvest_result['blocked_sources'], true ) );
nh_check( 'harvest_all: normal site scraped into articles', count( $harvest_result['articles'] ?? [] ) >= 1 );
nh_check( 'harvest_all: normal article title / content present', false !== strpos( $harvest_result['articles'][0]['content'] ?? '', 'Αναλυτικό κείμενο' ) || false !== strpos( $harvest_result['articles'][0]['title'] ?? '', 'Κανονική Είδηση' ) );


// =========================================================================
// 3. Deduplication across outlets
// =========================================================================

$outlet_a_url = 'https://www.outlet-a.gr';
$outlet_b_url = 'https://www.outlet-b.gr';
$shared_article_url = 'https://www.amna.gr/article/789123/koino-tilegrafima-ape-mpe';

$multi_outlet_responses = [
    $outlet_a_url => [
        'response' => [ 'code' => 200 ],
        'body' => '<main><a href="' . $shared_article_url . '">Τηλεγράφημα ΑΠΕ-ΜΠΕ</a><a href="https://www.outlet-a.gr/news/1">Μοναδική Είδηση Α</a></main>',
    ],
    $outlet_b_url => [
        'response' => [ 'code' => 200 ],
        'body' => '<main><a href="' . $shared_article_url . '">Τηλεγράφημα ΑΠΕ-ΜΠΕ</a><a href="https://www.outlet-b.gr/news/2">Μοναδική Είδηση Β</a></main>',
    ],
    $shared_article_url => [
        'response' => [ 'code' => 200 ],
        'body' => '<article><h1>Κοινό Τηλεγράφημα</h1><p>Επίσημη ανακοίνωση από το πρακτορείο ειδήσεων.</p></article>',
    ],
    'https://www.outlet-a.gr/news/1' => [
        'response' => [ 'code' => 200 ],
        'body' => '<article><h1>Είδηση Α</h1><p>Αποκλειστικό ρεπορτάζ του πρώτου μέσου.</p></article>',
    ],
    'https://www.outlet-b.gr/news/2' => [
        'response' => [ 'code' => 200 ],
        'body' => '<article><h1>Είδηση Β</h1><p>Αποκλειστικό ρεπορτάζ του δεύτερου μέσου.</p></article>',
    ],
];

$GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $multi_outlet_responses ) {
    return $multi_outlet_responses[ $url ] ?? [ 'response' => [ 'code' => 200 ], 'body' => '' ];
};

$dedupe_result = $harvester->harvest_all( [ $outlet_a_url, $outlet_b_url ], '2026-08-26' );
$harvested_article_urls = array_column( $dedupe_result['articles'], 'url' );

nh_check( 'deduplication: shared article only appears once', count( array_keys( $harvested_article_urls, $shared_article_url ) ) === 1 );
nh_check( 'deduplication: total articles count is 3', count( $dedupe_result['articles'] ) === 3 );


// =========================================================================
// 4. Manual upload merging (PDF/DOCX/text extracted notes)
// =========================================================================

$initial_pool = [
    'date' => '2026-08-26',
    'harvested_at' => '2026-08-26T06:30:00Z',
    'sources' => [ 'https://www.kathimerini.gr' ],
    'blocked_sources' => [ 'https://www.kathimerini.gr' ],
    'articles' => [
        [
            'url' => 'https://www.other-outlet.gr/news/1',
            'title' => 'Υφιστάμενη Είδηση',
            'content' => 'Κείμενο από άλλο μέσο που λειτούργησε κανονικά.',
            'source' => 'other-outlet.gr',
            'is_manual' => false,
            'harvested_at' => '2026-08-26T06:30:00Z',
        ],
    ],
];

$harvester->save_snapshot( '2026-08-26', $initial_pool );

$manual_uploads = [
    [
        'title'   => 'Καθημερινή PDF: Κεντρικό Άρθρο',
        'content' => 'Εξαχθέν κείμενο από το πρωινό PDF της Καθημερινής σχετικά με τον πληθωρισμό.',
        'source'  => 'kathimerini.gr (Manual PDF)',
        'url'     => '',
    ],
    [
        'title'   => 'Χειρόγραφες Σημειώσεις Συντάκτη',
        'content' => 'Σημαντικές δηλώσεις του Υπουργού Οικονομικών στο ραδιόφωνο.',
        'source'  => 'Editor Notes',
        'url'     => '',
    ],
];

$merged_pool = $harvester->handle_manual_upload( $manual_uploads, '2026-08-26' );

nh_check( 'manual_upload: articles count is now 3', count( $merged_pool['articles'] ) === 3 );
nh_check( 'manual_upload: manual flag set on first upload', ! empty( $merged_pool['articles'][1]['is_manual'] ) );
nh_check( 'manual_upload: manual title preserved', ( $merged_pool['articles'][1]['title'] ?? '' ) === 'Καθημερινή PDF: Κεντρικό Άρθρο' );
nh_check( 'manual_upload: manual content preserved', false !== strpos( $merged_pool['articles'][1]['content'] ?? '', 'πληθωρισμό' ) );
nh_check( 'manual_upload: second upload title preserved', ( $merged_pool['articles'][2]['title'] ?? '' ) === 'Χειρόγραφες Σημειώσεις Συντάκτη' );

// Duplicate manual upload check
$dup_manual = [
    [
        'title'   => 'Καθημερινή PDF: Κεντρικό Άρθρο',
        'content' => 'Εξαχθέν κείμενο από το πρωινό PDF της Καθημερινής σχετικά με τον πληθωρισμό.',
        'source'  => 'kathimerini.gr (Manual PDF)',
    ]
];
$merged_pool_2 = $harvester->handle_manual_upload( $dup_manual, '2026-08-26' );
nh_check( 'manual_upload: duplicate upload is not added twice', count( $merged_pool_2['articles'] ) === 3 );


// =========================================================================
// 5. Saving and loading daily JSON snapshot
// =========================================================================

$test_date = '2026-08-26';
$snapshot_path = $harvester->get_snapshot_path( $test_date );

nh_check( 'snapshot_path: contains date', false !== strpos( $snapshot_path, '2026-08-26' ) );
nh_check( 'snapshot_path: ends with raw-articles.json', false !== strpos( $snapshot_path, 'raw-articles.json' ) );
nh_check( 'snapshot_path: file exists on disk', file_exists( $snapshot_path ) );

$loaded_data = $harvester->load_snapshot( $test_date );
nh_check( 'load_snapshot: returns array', is_array( $loaded_data ) );
nh_check( 'load_snapshot: articles count matches merged count', count( $loaded_data['articles'] ?? [] ) === 3 );
nh_check( 'load_snapshot: date matches', ( $loaded_data['date'] ?? '' ) === '2026-08-26' );

// Nonexistent date returns null/empty array
$nonexistent = $harvester->load_snapshot( '1999-01-01' );
nh_check( 'load_snapshot: nonexistent date returns null or empty', empty( $nonexistent ) );

// Cleanup test uploads dir
if ( is_dir( $test_upload_dir ) ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $test_upload_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $files as $fileinfo ) {
        $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
        @$todo( $fileinfo->getRealPath() );
    }
    @rmdir( $test_upload_dir );
}

if ( $failures > 0 ) {
    fwrite( STDERR, "NewsHarvesterTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "NewsHarvesterTest: OK (28 checks)\n";
