<?php
/**
 * URLFetcherMultiTierFallbackTest — Multi-Tier DOM Container Extraction and Fallback unit tests.
 *
 * Verifies Issue #30 fix:
 *   1. Stockwatch CY sample HTML containing <div id="printSection"> extracted cleanly via Tier 2 DOM.
 *   2. Reporter CY sample HTML with 22 teaser <article> nodes selects the primary full article body.
 *   3. Drupal / CMS specific class selectors (field--name-body, node__content, text-formatted).
 *   4. Paragraph cluster fallback when no standard containers or IDs exist.
 *   5. Safe noise stripping preserving article content even when container has "related" / "shareable" classes.
 *   6. NewsHarvester validation accepts extracted articles as valid and substantive.
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
function utf_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$GLOBALS['OPTIONS_STORE']['presshub_ai_fetch_urls'] = '1';

// =========================================================================
// 1. Stockwatch CY Non-Standard DOM Container (#printSection)
// =========================================================================
$stockwatch_html = '<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <title>Σημαντική αύξηση στις καταθέσεις των τραπεζών - Stockwatch</title>
    <meta property="og:title" content="Σημαντική αύξηση στις καταθέσεις των τραπεζών" />
    <meta property="og:description" content="Άνοδος ρευστότητας στο τραπεζικό σύστημα." />
</head>
<body>
    <header>
        <nav><a href="/el/home">Αρχική</a> | <a href="/el/category/oikonomia">Οικονομία</a></nav>
    </header>
    <div class="main-layout">
        <div class="sidebar">Διαφημιστικό banner</div>
        <div class="content-pane">
            <div id="printSection">
                <h1>Σημαντική αύξηση στις καταθέσεις των τραπεζών</h1>
                <div class="meta-date">28 Αυγούστου 2026</div>
                <p>Σημαντική άνοδο κατέγραψαν οι συνολικές καταθέσεις στο κυπριακό τραπεζικό σύστημα σύμφωνα με τα νέα στοιχεία της Κεντρικής Τράπεζας της Κύπρου για τον μήνα Ιούλιο.</p>
                <p>Το σύνολο των καταθέσεων ανήλθε σε νέα ιστορικά υψηλά επίπεδα ενισχύοντας περαιτέρω τη ρευστότητα των χρηματοπιστωτικών ιδρυμάτων και προσφέροντας σταθερότητα στην αγορά.</p>
                <p>Σύμφωνα με τραπεζικούς αναλυτές, η διατήρηση των αυξημένων εισροών οφείλεται τόσο στις καταθέσεις εγχώριων νοικοκυριών όσο και σε εισροές κεφαλαίων από διεθνείς εταιρείες τεχνολογίας και χρηματοοικονομικών υπηρεσιών.</p>
                <p>Παράλληλα, τα επιτόκια καταθέσεων παραμένουν σε ελκυστικά επίπεδα, ενθαρρύνοντας την αποταμίευση έναντι άλλων μορφών επένδυσης υψηλότερου ρίσκου.</p>
            </div>
        </div>
    </div>
    <footer>© 2026 Stockwatch. Με την επιφύλαξη παντός δικαιώματος.</footer>
</body>
</html>';

$stockwatch_res = PressHub_AI_URL_Fetcher::extract_article_semantic( $stockwatch_html, 'https://www.stockwatch.com.cy/el/article/123' );
utf_check( 'stockwatch: tier is dom', ( $stockwatch_res['tier'] ?? '' ) === 'dom' );
utf_check( 'stockwatch: headline extracted', false !== strpos( $stockwatch_res['title'] ?? '', 'Σημαντική αύξηση στις καταθέσεις των τραπεζών' ) );
utf_check( 'stockwatch: extracted paragraph 1', false !== strpos( $stockwatch_res['content'] ?? '', 'Σημαντική άνοδο κατέγραψαν οι συνολικές καταθέσεις' ) );
utf_check( 'stockwatch: extracted paragraph 4', false !== strpos( $stockwatch_res['content'] ?? '', 'παραμένουν σε ελκυστικά επίπεδα' ) );
utf_check( 'stockwatch: sidebar banner excluded', false === strpos( $stockwatch_res['content'] ?? '', 'Διαφημιστικό banner' ) );
utf_check( 'stockwatch: nav excluded', false === strpos( $stockwatch_res['content'] ?? '', 'Αρχική' ) );
utf_check( 'stockwatch: footer excluded', false === strpos( $stockwatch_res['content'] ?? '', 'Με την επιφύλαξη παντός δικαιώματος' ) );
utf_check( 'stockwatch: char_count > 300', ( $stockwatch_res['char_count'] ?? 0 ) > 300 );


// =========================================================================
// 2. Reporter CY Multiple Teasers vs Full Article Body
// =========================================================================
$teaser_cards = '';
for ( $i = 1; $i <= 21; $i++ ) {
    $teaser_cards .= sprintf(
        '<article class="card-teaser-%d"><a href="/news/%d"><h3>Σύντομος τίτλος κάρτας %d</h3></a><div class="share-btn">Share</div><p>Σύντομη περιγραφή %d</p></article>',
        $i, $i, $i, $i
    );
}

$reporter_html = '<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <title>Μεγάλες αλλαγές στο κυπριακό φορολογικό σύστημα - Reporter</title>
    <meta property="og:title" content="Μεγάλες αλλαγές στο κυπριακό φορολογικό σύστημα" />
</head>
<body>
    <header><nav>Μενού πλοήγησης</nav></header>
    <div class="top-teasers">' . $teaser_cards . '</div>
    <div class="main-content-column">
        <article class="single-article-view">
            <h1>Μεγάλες αλλαγές στο κυπριακό φορολογικό σύστημα</h1>
            <div class="social-share-bar">Κοινοποίηση στο Facebook και Twitter</div>
            <p>Σε ευρείες αλλαγές και μεταρρυθμίσεις στο κυπριακό φορολογικό πλαίσιο προχωρά η κυβέρνηση, όπως ανακοίνωσε σήμερα σε διάσκεψη τύπου ο Υπουργός Οικονομικών.</p>
            <p>Το νέο φορολογικό νομοσχέδιο στοχεύει στην απλοποίηση των διαδικασιών, την ενίσχυση της διαφάνειας και την ουσιαστική ελάφρυνση των μεσαίων εισοδημάτων και των οικογενειών.</p>
            <p>Προβλέπονται επίσης γενναία φορολογικά κίνητρα για την προσέλκυση ξένων παραγωγικών επενδύσεων και την ψηφιακή και πράσινη μετάβαση των τοπικών μικρομεσαίων επιχειρήσεων.</p>
            <p>Οι εκπρόσωποι των εργοδοτικών οργανώσεων και των επαγγελματικών φορέων εξέφρασαν την ικανοποίησή τους για τις προωθούμενες ρυθμίσεις, τονίζοντας την ανάγκη ταχείας ψήφισης από τη Βουλή.</p>
            <div class="related-articles-box">Διαβάστε επίσης: Οικονομικές εξελίξεις</div>
        </article>
    </div>
    <footer>Υποσέλιδο ιστοσελίδας</footer>
</body>
</html>';

$reporter_res = PressHub_AI_URL_Fetcher::extract_article_semantic( $reporter_html, 'https://www.reporter.com.cy/article/456' );
utf_check( 'reporter: tier is dom', ( $reporter_res['tier'] ?? '' ) === 'dom' );
utf_check( 'reporter: extracted primary full article title', false !== strpos( $reporter_res['title'] ?? '', 'Μεγάλες αλλαγές στο κυπριακό φορολογικό σύστημα' ) );
utf_check( 'reporter: did not select teaser card 1', false === strpos( $reporter_res['content'] ?? '', 'Σύντομος τίτλος κάρτας 1' ) );
utf_check( 'reporter: extracted full main story para 1', false !== strpos( $reporter_res['content'] ?? '', 'Σε ευρείες αλλαγές και μεταρρυθμίσεις στο κυπριακό φορολογικό' ) );
utf_check( 'reporter: extracted full main story para 4', false !== strpos( $reporter_res['content'] ?? '', 'Οι εκπρόσωποι των εργοδοτικών οργανώσεων' ) );
utf_check( 'reporter: stripped social share bar', false === strpos( $reporter_res['content'] ?? '', 'Κοινοποίηση στο Facebook' ) );
utf_check( 'reporter: stripped related box', false === strpos( $reporter_res['content'] ?? '', 'Διαβάστε επίσης' ) );
utf_check( 'reporter: char_count > 400', ( $reporter_res['char_count'] ?? 0 ) > 400 );


// =========================================================================
// 3. Drupal / CMS Container Selectors (field--name-body / node__content)
// =========================================================================
$drupal_html = '<!DOCTYPE html>
<html>
<head><title>Νέες υποδομές στη Λεμεσό</title></head>
<body>
    <div class="page-wrapper">
        <div class="node node--type-news node--view-mode-full">
            <div class="node__content">
                <div class="field field--name-body field--type-text-with-summary field-name-body text-formatted">
                    <h1>Νέες υποδομές στη Λεμεσό</h1>
                    <p>Η κατασκευή νέων σύγχρονων υποδομών αναμένεται να επιταχυνθεί τα επόμενα χρόνια στην ευρύτερη επαρχία Λεμεσού με σημαντικά αναπτυξιακά έργα.</p>
                    <p>Τα έργα περιλαμβάνουν σύγχρονα οδικά δίκτυα, εκτεταμένες αναπλάσεις δημόσιων χώρων και πράσινων πάρκων, καθώς και αντιπλημμυρικές υποδομές.</p>
                    <p>Η συνολική χρηματοδότηση έχει εξασφαλιστεί πλήρως από ευρωπαϊκά ταμεία συνοχής και εθνικούς αναπτυξιακούς πόρους.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';

$drupal_res = PressHub_AI_URL_Fetcher::extract_article_semantic( $drupal_html );
utf_check( 'drupal: tier is dom', ( $drupal_res['tier'] ?? '' ) === 'dom' );
utf_check( 'drupal: extracted content', false !== strpos( $drupal_res['content'] ?? '', 'Η κατασκευή νέων σύγχρονων υποδομών' ) );
utf_check( 'drupal: extracted all paragraphs', false !== strpos( $drupal_res['content'] ?? '', 'ευρωπαϊκά ταμεία συνοχής' ) );


// =========================================================================
// 4. Paragraph Cluster Fallback (No Standard Container / IDs)
// =========================================================================
$cluster_html = '<!DOCTYPE html>
<html>
<head><title>Η κυπριακή ναυτιλία ενισχύει τη θέση της διεθνώς</title></head>
<body>
    <header>Logo</header>
    <div class="wrapper-unnamed-col">
        <div class="custom-block-784">
            <p>Η κυπριακή ναυτιλία συνεχίζει να αποτελεί έναν από τους βασικότερους πυλώνες ανάπτυξης και εξωστρέφειας της εθνικής οικονομίας.</p>
            <p>Με στόλο που ξεπερνά τα χίλια ποντοπόρα πλοία, η Κύπρος διατηρεί ηγετική θέση στην ευρωπαϊκή και παγκόσμια ναυτιλιακή βιομηχανία.</p>
            <p>Οι προκλήσεις της πράσινης μετάβασης, της απανθρακοποίησης και των νέων κανονισμών βρίσκονται στο επίκεντρο των διεθνών διαβουλεύσεων.</p>
            <p>Το αρμόδιο Υφυπουργείο Ναυτιλίας προωθεί σειρά μέτρων για την περαιτέρω ενίσχυση της ανταγωνιστικότητας του κυπριακού νηολογίου.</p>
        </div>
    </div>
</body>
</html>';

$cluster_res = PressHub_AI_URL_Fetcher::extract_article_semantic( $cluster_html );
utf_check( 'cluster fallback: tier is dom', ( $cluster_res['tier'] ?? '' ) === 'dom' );
utf_check( 'cluster fallback: contains para 1', false !== strpos( $cluster_res['content'] ?? '', 'Η κυπριακή ναυτιλία συνεχίζει' ) );
utf_check( 'cluster fallback: contains para 4', false !== strpos( $cluster_res['content'] ?? '', 'ανταγωνιστικότητας του κυπριακού νηολογίου' ) );


// =========================================================================
// 5. Safe Noise Stripping: Container with "related" and "shareable" classes
// =========================================================================
$safe_noise_html = '<!DOCTYPE html>
<html>
<head><title>Ανάλυση αγοράς ακινήτων</title></head>
<body>
    <article class="article-item related-topic-item shareable-view">
        <h1>Ανάλυση αγοράς ακινήτων</h1>
        <div class="social-share-widget">Share on Facebook Twitter</div>
        <p>Σταθερή ζήτηση καταγράφει η εγχώρια κτηματαγορά κατά το τρέχον έτος με έντονο ενδιαφέρον για οικιστικά ακίνητα υψηλής ενεργειακής κλάσης.</p>
        <p>Οι τιμές πώλησης και ενοικίασης παρουσιάζουν τάσεις σταθεροποίησης μετά από συνεχόμενα τρίμηνα ανόδου.</p>
        <p>Οι ειδικοί του κλάδου εκτιμούν ότι η προσφορά νέων διαμερισμάτων θα συμβάλει στην εξισορρόπηση της αγοράς.</p>
        <div class="advertisement-banner-slot">Ad 300x250</div>
    </article>
</body>
</html>';

$safe_noise_res = PressHub_AI_URL_Fetcher::extract_article_semantic( $safe_noise_html );
utf_check( 'safe noise: tier is dom', ( $safe_noise_res['tier'] ?? '' ) === 'dom' );
utf_check( 'safe noise: article not wiped', false !== strpos( $safe_noise_res['content'] ?? '', 'Σταθερή ζήτηση καταγράφει η εγχώρια κτηματαγορά' ) );
utf_check( 'safe noise: widget stripped', false === strpos( $safe_noise_res['content'] ?? '', 'Share on Facebook' ) );
utf_check( 'safe noise: ad stripped', false === strpos( $safe_noise_res['content'] ?? '', 'Ad 300x250' ) );


// =========================================================================
// 6. NewsHarvester Validation Integration
// =========================================================================
if ( class_exists( 'PressHub_AI_News_Harvester' ) ) {
    $harvester = new PressHub_AI_News_Harvester();

    $valid_stockwatch_data = [
        'title'      => $stockwatch_res['title'],
        'content'    => $stockwatch_res['content'],
        'source'     => 'stockwatch.com.cy',
        'url'        => 'https://www.stockwatch.com.cy/el/article/123',
        'char_count' => $stockwatch_res['char_count'],
    ];
    utf_check( 'harvester: stockwatch article is valid', $harvester->is_valid_harvested_article( $valid_stockwatch_data ) );

    $valid_reporter_data = [
        'title'      => $reporter_res['title'],
        'content'    => $reporter_res['content'],
        'source'     => 'reporter.com.cy',
        'url'        => 'https://www.reporter.com.cy/article/456',
        'char_count' => $reporter_res['char_count'],
    ];
    utf_check( 'harvester: reporter article is valid', $harvester->is_valid_harvested_article( $valid_reporter_data ) );
}


// =========================================================================
// 7. fetch_article_data HTTP Mock Integration
// =========================================================================
$GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $stockwatch_html ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => $stockwatch_html ];
};
$data = PressHub_AI_URL_Fetcher::fetch_article_data( 'https://www.stockwatch.com.cy/el/article/123' );
utf_check( 'fetch_article_data: success is true', true === $data['success'] );
utf_check( 'fetch_article_data: tier is dom', 'dom' === $data['tier'] );
utf_check( 'fetch_article_data: status_code is 200', 200 === $data['status_code'] );
utf_check( 'fetch_article_data: content contains stockwatch prose', false !== strpos( $data['content'], 'Σημαντική άνοδο κατέγραψαν' ) );

unset( $GLOBALS['GET_RESPONSE_FILTER'] );

if ( $failures > 0 ) {
    fwrite( STDERR, "URLFetcherMultiTierFallbackTest: {$failures} failure(s)\n" );
    exit( 1 );
}

echo "URLFetcherMultiTierFallbackTest: OK (23 checks)\n";
