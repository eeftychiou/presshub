<?php
/**
 * NewsHarvesterPoliteDelayTest — Unit tests for GitHub Issue #35:
 * "enhancement(workflow): support 15-minute harvest budget and polite crawl delay between successive requests"
 *
 * Covers:
 *   1. 15-Minute Harvest Time Budget Clamping (10s – 900s) in Settings Storage & Render.
 *   2. Polite Crawl Delay Throttle (throttle_host_request, per-host timing, presshub_ai_crawl_delay_ms filter).
 *   3. Graceful RSS Description Fallback on WAF / 403 / 503 blocks (tier: 'rss_description', fallback_reason: 'waf_or_http_error').
 *   4. Modern browser User-Agent and headers in PressHub_AI_URL_Fetcher and PressHub_AI_News_Harvester.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

class NewsHarvesterPoliteDelayTest
{
    public static function run(): void {
        $failures = [];
        $test_upload_dir = sys_get_temp_dir() . '/presshub-test-polite-' . uniqid();
        $GLOBALS['UPLOAD_DIR'] = $test_upload_dir;

        $check = function( $label, $condition ) use ( &$failures ) {
            if ( ! $condition ) {
                $failures[] = $label;
                fwrite( STDERR, "FAIL: {$label}\n" );
            }
        };

        // =========================================================================
        // Case 1: 15-Minute Harvest Time Budget Clamping (10s – 900s)
        // =========================================================================
        self::reset_world();
        $cbs = $GLOBALS['SANITIZE_CALLBACKS'] ?? [];

        // Clamping bounds
        $check( 'Time budget below 10 clamps to 10', 10 === PressHub_AI_Settings_Storage::sanitize_harvest_time_budget( 5 ) );
        $check( 'Time budget negative clamps to 10', 10 === PressHub_AI_Settings_Storage::sanitize_harvest_time_budget( -20 ) );
        $check( 'Time budget 60 passes through', 60 === PressHub_AI_Settings_Storage::sanitize_harvest_time_budget( 60 ) );
        $check( 'Time budget 300 passes through', 300 === PressHub_AI_Settings_Storage::sanitize_harvest_time_budget( 300 ) );
        $check( 'Time budget 600 passes through (10 min)', 600 === PressHub_AI_Settings_Storage::sanitize_harvest_time_budget( 600 ) );
        $check( 'Time budget 900 passes through (15 min)', 900 === PressHub_AI_Settings_Storage::sanitize_harvest_time_budget( 900 ) );
        $check( 'Time budget 1200 clamps to 900 max', 900 === PressHub_AI_Settings_Storage::sanitize_harvest_time_budget( 1200 ) );
        $check( 'Time budget non-numeric defaults to 60', 60 === PressHub_AI_Settings_Storage::sanitize_harvest_time_budget( 'invalid' ) );

        // Getter retrieval
        $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] = 750;
        $check( 'get_harvest_time_budget returns 750s', 750 === PressHub_AI_Settings_Storage::get_harvest_time_budget() );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] = 900;
        $check( 'get_harvest_time_budget returns 900s', 900 === PressHub_AI_Settings_Storage::get_harvest_time_budget() );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] = 1000;
        $check( 'get_harvest_time_budget falls back to 60 on out-of-bounds', 60 === PressHub_AI_Settings_Storage::get_harvest_time_budget() );
        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] );

        // Render input bounds
        $renderer = new PressHub_AI_Settings_Render();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] = 300;
        ob_start();
        $renderer->render_harvest_time_budget_field();
        $html = ob_get_clean();
        $check( 'render_harvest_time_budget_field renders min="10"', false !== strpos( $html, 'min="10"' ) );
        $check( 'render_harvest_time_budget_field renders max="900"', false !== strpos( $html, 'max="900"' ) );
        $check( 'render_harvest_time_budget_field renders step="15"', false !== strpos( $html, 'step="15"' ) );
        $check( 'render_harvest_time_budget_field mentions 900 seconds / 15 minutes in description', false !== strpos( $html, '900 seconds / 15 minutes' ) );

        // =========================================================================
        // Case 2: Polite Crawl Delay & Rate-Limit Throttle
        // =========================================================================
        self::reset_world();
        $harvester = new PressHub_AI_News_Harvester();

        // 2a. Default empty throttle state
        $check( 'Initial host throttle map is empty', empty( $harvester->get_last_host_request_time() ) );

        // 2b. Throttle with 40ms delay
        add_filter( 'presshub_ai_crawl_delay_ms', function( $delay, $host ) {
            return 40;
        }, 10, 2 );

        $t0 = microtime( true );
        $harvester->throttle_host_request( 'https://www.newsout1.gr/section' );
        $t1 = microtime( true );
        $check( 'First request to host records timestamp without delay', ( $t1 - $t0 ) < 0.03 );

        $recorded = $harvester->get_last_host_request_time();
        $check( 'Host timestamp recorded for www.newsout1.gr', isset( $recorded['www.newsout1.gr'] ) );

        // Successive request to SAME host throttles for remaining delay
        $t2 = microtime( true );
        $harvester->throttle_host_request( 'https://www.newsout1.gr/article-1' );
        $t3 = microtime( true );
        $elapsed_same_host = ( $t3 - $t2 );
        $check( 'Successive request to same host sleeps for remaining delay (>= 30ms)', $elapsed_same_host >= 0.03 );

        // Request to DIFFERENT host proceeds without delay
        $t4 = microtime( true );
        $harvester->throttle_host_request( 'https://www.anothernews.gr/home' );
        $t5 = microtime( true );
        $elapsed_diff_host = ( $t5 - $t4 );
        $check( 'Request to different host executes immediately (< 25ms)', $elapsed_diff_host < 0.025 );

        // 2c. Disabled delay (0ms)
        remove_all_filters( 'presshub_ai_crawl_delay_ms' );
        add_filter( 'presshub_ai_crawl_delay_ms', function() { return 0; } );
        $harvester->reset_host_throttle();
        $harvester->throttle_host_request( 'https://www.zero-delay.gr/1' );
        $tz0 = microtime( true );
        $harvester->throttle_host_request( 'https://www.zero-delay.gr/2' );
        $tz1 = microtime( true );
        $check( '0ms delay executes immediately (< 10ms)', ( $tz1 - $tz0 ) < 0.01 );
        remove_all_filters( 'presshub_ai_crawl_delay_ms' );

        // =========================================================================
        // Case 3: Graceful RSS Description Fallback on WAF / 403 / 503 Blocks
        // =========================================================================
        self::reset_world();
        $harvester->reset_host_throttle();

        // Feed XML containing 2 articles with complete description summaries (> 30 words)
        $rss_feed_xml = '<?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <title>Greek News Wire</title>
            <link>https://www.ant1live.gr</link>
            <item>
              <title>Σημαντική οικονομική συμφωνία υπεγράφη στη Λευκωσία</title>
              <link>https://www.ant1live.gr/article-100</link>
              <pubDate>Wed, 26 Aug 2026 08:30:00 +0300</pubDate>
              <description><![CDATA[Υπεγράφη σήμερα στη Λευκωσία νέα συμφωνία οικονομικής συνεργασίας μεταξύ των δύο πλευρών, ανοίγοντας τον δρόμο για νέες επενδύσεις στον τομέα της πράσινης ενέργειας και της ψηφιακής τεχνολογίας με στόχο τη βιώσιμη ανάπτυξη των τοπικών υποδομών και την ενίσχυση της απασχόλησης.]]></description>
            </item>
            <item>
              <title>Νέα μέτρα για την προστασία του περιβάλλοντος</title>
              <link>https://www.ant1live.gr/article-200</link>
              <pubDate>Wed, 26 Aug 2026 09:00:00 +0300</pubDate>
              <description><![CDATA[Ανακοινώθηκαν από το αρμόδιο υπουργείο τα νέα έκτακτα μέτρα για την προστασία του περιβάλλοντος και τη μείωση των εκπομπών ρύπων στις αστικές περιοχές, περιλαμβάνοντας κίνητρα για ηλεκτροκίνηση και αναβάθμιση ενεργειακών κτιρίων σε όλες τις επαρχίες.]]></description>
            </item>
          </channel>
        </rss>';

        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $rss_feed_xml ) {
            // Source homepage returns RSS feed directly or discovers feed
            if ( 'https://www.ant1live.gr' === $url || false !== strpos( $url, 'feed' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => $rss_feed_xml,
                ];
            }
            // Direct HTML page scraping is blocked by WAF (Cloudflare 403 Forbidden)
            if ( false !== strpos( $url, 'article-100' ) ) {
                return [
                    'response' => [ 'code' => 403 ],
                    'body'     => '<!DOCTYPE html><html><head><title>Attention Required! | Cloudflare</title></head><body><h1>403 Forbidden</h1><p>Sorry, you have been blocked by Cloudflare WAF.</p></body></html>',
                ];
            }
            if ( false !== strpos( $url, 'article-200' ) ) {
                return [
                    'response' => [ 'code' => 503 ],
                    'body'     => '503 Service Unavailable',
                ];
            }
            return [
                'response' => [ 'code' => 404 ],
                'body'     => 'Not Found',
            ];
        };

        // Harvest source with WAF blocked individual pages
        $waf_harvest = $harvester->harvest_source( 'https://www.ant1live.gr', '2026-08-29', 60 );

        $check( 'WAF-blocked source yields articles via RSS fallback', ! empty( $waf_harvest['articles'] ) );
        $check( 'WAF-blocked source yields exactly 2 articles', count( $waf_harvest['articles'] ?? [] ) === 2 );

        if ( ! empty( $waf_harvest['articles'][0] ) ) {
            $art1 = $waf_harvest['articles'][0];
            $check( 'Article 1 tier is rss_description', 'rss_description' === ( $art1['tier'] ?? '' ) );
            $check( 'Article 1 fallback_reason is waf_or_http_error', 'waf_or_http_error' === ( $art1['fallback_reason'] ?? '' ) );
            $check( 'Article 1 title preserved from RSS', false !== strpos( $art1['title'] ?? '', 'Σημαντική οικονομική συμφωνία' ) );
            $check( 'Article 1 content extracted from RSS description', false !== strpos( $art1['content'] ?? '', 'πράσινης ενέργειας' ) );
            $check( 'Article 1 char_count > 100', ( $art1['char_count'] ?? 0 ) > 100 );
        }

        if ( ! empty( $waf_harvest['articles'][1] ) ) {
            $art2 = $waf_harvest['articles'][1];
            $check( 'Article 2 tier is rss_description', 'rss_description' === ( $art2['tier'] ?? '' ) );
            $check( 'Article 2 fallback_reason is waf_or_http_error', 'waf_or_http_error' === ( $art2['fallback_reason'] ?? '' ) );
            $check( 'Article 2 title preserved from RSS', false !== strpos( $art2['title'] ?? '', 'Νέα μέτρα για την προστασία' ) );
        }

        // =========================================================================
        // Case 3b: Concise RSS Description Fallback (Issue #37 - ant1live.com 11-19 words)
        // =========================================================================
        self::reset_world();
        $harvester->reset_host_throttle();

        // Feed XML containing articles with concise description summaries (11-19 words)
        $ant1_concise_rss = '<?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <title>Ant1Live News</title>
            <link>https://www.ant1live.com</link>
            <item>
              <title>Συνάντηση Προέδρου με τον Υπουργό Εξωτερικών</title>
              <link>https://www.ant1live.com/article-ant1-1</link>
              <pubDate>Wed, 26 Aug 2026 10:00:00 +0300</pubDate>
              <description><![CDATA[Συνάντηση με τον Πρόεδρο της Δημοκρατίας πραγματοποίησε σήμερα ο Υπουργός Εξωτερικών για το Κυπριακό.]]></description>
            </item>
            <item>
              <title>Επιχείρηση της Αστυνομίας στη Λεμεσό</title>
              <link>https://www.ant1live.com/article-ant1-2</link>
              <pubDate>Wed, 26 Aug 2026 10:30:00 +0300</pubDate>
              <description><![CDATA[Σε εξέλιξη βρίσκεται μεγάλη επιχείρηση της Αστυνομίας στη Λεμεσό για πάταξη του οργανωμένου εγκλήματος.]]></description>
            </item>
          </channel>
        </rss>';

        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) use ( $ant1_concise_rss ) {
            if ( 'https://www.ant1live.com' === $url || false !== strpos( $url, 'rss' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => $ant1_concise_rss,
                ];
            }
            // Direct HTML page scraping blocked by Cloudflare 403
            return [
                'response' => [ 'code' => 403 ],
                'body'     => '<html><body>403 Forbidden Cloudflare WAF</body></html>',
            ];
        };

        $ant1_harvest = $harvester->harvest_source( 'https://www.ant1live.com', '2026-08-30', 60 );

        $check( 'Concise RSS feed with 403 HTML yields articles via RSS fallback', ! empty( $ant1_harvest['articles'] ) );
        $check( 'Concise RSS feed yields exactly 2 articles', count( $ant1_harvest['articles'] ?? [] ) === 2 );

        if ( ! empty( $ant1_harvest['articles'][0] ) ) {
            $ant1_art1 = $ant1_harvest['articles'][0];
            $check( 'Concise art1 tier is rss_description', 'rss_description' === ( $ant1_art1['tier'] ?? '' ) );
            $check( 'Concise art1 title preserved', false !== strpos( $ant1_art1['title'] ?? '', 'Συνάντηση Προέδρου' ) );
            $check( 'Concise art1 content combines title and description', false !== strpos( $ant1_art1['content'] ?? '', 'Συνάντηση Προέδρου με τον Υπουργό Εξωτερικών —' ) );
            $check( 'Concise art1 fallback_reason is waf_or_http_error', 'waf_or_http_error' === ( $ant1_art1['fallback_reason'] ?? '' ) );
        }
        if ( ! empty( $ant1_harvest['articles'][1] ) ) {
            $ant1_art2 = $ant1_harvest['articles'][1];
            $check( 'Concise art2 tier is rss_description', 'rss_description' === ( $ant1_art2['tier'] ?? '' ) );
            $check( 'Concise art2 content preserves description without duplication', false !== strpos( $ant1_art2['content'] ?? '', 'επιχείρηση της Αστυνομίας στη Λεμεσό' ) );
        }

        // =========================================================================
        // Case 4: Modern Browser Headers & User Agent in PressHub_AI_URL_Fetcher
        // =========================================================================
        self::reset_world();
        $check( 'PressHub_AI_URL_Fetcher::USER_AGENT contains Mozilla/5.0 and Chrome/128',
            false !== strpos( PressHub_AI_URL_Fetcher::USER_AGENT, 'Mozilla/5.0' ) &&
            false !== strpos( PressHub_AI_URL_Fetcher::USER_AGENT, 'Chrome/128' )
        );

        $headers = PressHub_AI_URL_Fetcher::get_default_headers();
        $check( 'Default headers array is not empty', is_array( $headers ) && ! empty( $headers ) );
        $check( 'Default headers contain Accept', isset( $headers['Accept'] ) );
        $check( 'Default headers contain Accept-Language with el/el-GR', false !== strpos( $headers['Accept-Language'] ?? '', 'el' ) );
        $check( 'Default headers contain Sec-Ch-Ua with Chromium/Chrome 128', false !== strpos( $headers['Sec-Ch-Ua'] ?? '', '128' ) );
        $check( 'Default headers contain Sec-Ch-Ua-Platform Windows', '"Windows"' === ( $headers['Sec-Ch-Ua-Platform'] ?? '' ) );
        $check( 'Default headers contain Upgrade-Insecure-Requests', '1' === ( $headers['Upgrade-Insecure-Requests'] ?? '' ) );

        // Filterable headers
        add_filter( 'presshub_ai_url_fetcher_headers', function( $h ) {
            $h['X-Custom-Test'] = 'PressHub-Test';
            return $h;
        } );
        $filtered_headers = PressHub_AI_URL_Fetcher::get_default_headers();
        $check( 'presshub_ai_url_fetcher_headers filter is applied', 'PressHub-Test' === ( $filtered_headers['X-Custom-Test'] ?? '' ) );
        remove_all_filters( 'presshub_ai_url_fetcher_headers' );

        // Output summary
        if ( $failures ) {
            fwrite( STDERR, "NewsHarvesterPoliteDelayTest: FAIL (" . count( $failures ) . " failures)\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "NewsHarvesterPoliteDelayTest: OK (28+ checks passed)\n";
    }

    private static function reset_world(): void {
        self::reset_options();
        $GLOBALS['FILTERS'] = [];
        $GLOBALS['ACTIONS'] = [];
        $GLOBALS['GET_RESPONSE_FILTER'] = null;
    }

    private static function reset_options(): void {
        $GLOBALS['OPTIONS_STORE'] = [];
    }
}

NewsHarvesterPoliteDelayTest::run();
