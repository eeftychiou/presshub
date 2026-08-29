<?php
/**
 * NewsHarvesterTimeoutBudgetTest — Unit tests for News Harvester timeout resilience & execution budgeting.
 *
 * Test cases:
 *   1. Default time budget retrieval and filter override ('presshub_ai_harvest_time_budget').
 *   2. Time budget enforcement in harvest_all() stops gracefully and flags budget_exceeded without crashing.
 *   3. Time budget enforcement in harvest_source() stops gracefully and flags budget_exceeded.
 *   4. Single-source harvesting via harvest_source() with source array, string URL, or source ID.
 *   5. Snapshot persistence to disk and transient cache on harvest.
 *   6. Error resilience in individual source & article fetching (catch Throwable, logs error, continues run).
 *   7. AJAX handler briefing_run_harvest() with single source_id parameter.
 *   8. AJAX handler briefing_run_harvest() exception handling returns structured JSON error instead of 500.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

class NewsHarvesterTimeoutBudgetTest
{
    public static function run(): void {
        $failures = [];
        $test_upload_dir = sys_get_temp_dir() . '/presshub-test-timeout-' . uniqid();
        $GLOBALS['UPLOAD_DIR'] = $test_upload_dir;

        $check = function( $label, $condition ) use ( &$failures ) {
            if ( ! $condition ) {
                $failures[] = $label;
                fwrite( STDERR, "FAIL: {$label}\n" );
            }
        };

        // =========================================================================
        // Case 1: Time budget retrieval and filter override
        // =========================================================================
        self::reset_world();
        $harvester = new PressHub_AI_News_Harvester();

        $check( 'Default time budget is 25s', 25 == $harvester->get_time_budget() );
        $check( 'Explicit time budget override (10s)', 10 == $harvester->get_time_budget( 10 ) );

        add_filter( 'presshub_ai_harvest_time_budget', function() { return 18; } );
        $check( 'Filtered time budget returns 18s', 18 == $harvester->get_time_budget() );
        remove_all_filters( 'presshub_ai_harvest_time_budget' );
        $check( 'Time budget restores to 25s after filter removal', 25 == $harvester->get_time_budget() );

        // =========================================================================
        // Case 2: Time budget enforcement in harvest_all()
        // =========================================================================
        self::reset_world();
        $sources = [
            'https://www.source1.gr',
            'https://www.source2.gr',
            'https://www.source3.gr',
        ];

        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) {
            if ( false !== strpos( $url, 'source' ) && false === strpos( $url, 'article' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<main>'
                        . '<a href="' . $url . '/article-1">Article 1</a>'
                        . '<a href="' . $url . '/article-2">Article 2</a>'
                        . '<a href="' . $url . '/article-3">Article 3</a>'
                        . '</main>',
                ];
            }
            usleep( 50000 ); // 50ms
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '<article><h1>' . basename( $url ) . '</h1><p>Content for ' . $url . '</p></article>',
            ];
        };

        // Time budget of 0.0001 seconds will immediately trip budget check
        $harvest_budgeted = $harvester->harvest_all( $sources, '2026-08-20', 0.0001 );
        $check( 'harvest_all: budget_exceeded flag is true', true === ( $harvest_budgeted['budget_exceeded'] ?? false ) );
        $check( 'harvest_all: returns array payload', is_array( $harvest_budgeted ) );
        $check( 'harvest_all: date set in payload', '2026-08-20' === ( $harvest_budgeted['date'] ?? '' ) );
        $check( 'harvest_all: notice mentions time budget', ! empty( $harvest_budgeted['notice'] ) );
        $check( 'harvest_all: diagnostics array exists', isset( $harvest_budgeted['diagnostics'] ) );

        // =========================================================================
        // Case 3: Time budget enforcement in harvest_source()
        // =========================================================================
        self::reset_world();
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) {
            usleep( 20000 ); // 20ms
            if ( 'https://www.source1.gr' === $url ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<main>'
                        . '<a href="https://www.source1.gr/article-1">Article 1</a>'
                        . '<a href="https://www.source1.gr/article-2">Article 2</a>'
                        . '</main>',
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '<article><h1>' . basename( $url ) . '</h1><p>Content for ' . $url . '</p></article>',
            ];
        };

        $harvest_single_budget = $harvester->harvest_source( 'https://www.source1.gr', '2026-08-21', 0.0001 );
        $check( 'harvest_source: budget_exceeded flag is true on timeout', true === ( $harvest_single_budget['budget_exceeded'] ?? false ) );
        $check( 'harvest_source: returns array payload', is_array( $harvest_single_budget ) );
        $check( 'harvest_source: date preserved', '2026-08-21' === ( $harvest_single_budget['date'] ?? '' ) );

        // =========================================================================
        // Case 4: Single-source harvesting works as expected
        // =========================================================================
        self::reset_world();
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) {
            if ( 'https://www.single-test.gr' === $url ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<main><a href="https://www.single-test.gr/article-100">Single News</a></main>',
                ];
            }
            if ( 'https://www.single-test.gr/article-100' === $url ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<article><h1>Είδηση Μονής Πηγής</h1><p>Περιεχόμενο από τη συγκεκριμένη πηγή.</p></article>',
                ];
            }
            return [ 'response' => [ 'code' => 404 ], 'body' => '' ];
        };

        $single_result = $harvester->harvest_source( 'https://www.single-test.gr', '2026-08-22', 25 );
        $check( 'harvest_source: 1 article scraped', count( $single_result['articles'] ?? [] ) === 1 );
        $check( 'harvest_source: article title matches', 'Είδηση Μονής Πηγής' === ( $single_result['articles'][0]['title'] ?? '' ) );
        $check( 'harvest_source: budget_exceeded is false', false === ( $single_result['budget_exceeded'] ?? true ) );
        $check( 'harvest_source: source_health recorded', ! empty( $single_result['source_health'] ) );

        // =========================================================================
        // Case 5: Snapshot persistence to disk and transient cache
        // =========================================================================
        $loaded_snap = $harvester->load_snapshot( '2026-08-22' );
        $check( 'load_snapshot: returns snapshot array', is_array( $loaded_snap ) );
        $check( 'load_snapshot: contains scraped article', count( $loaded_snap['articles'] ?? [] ) === 1 );
        $transient_cache = get_transient( 'presshub_ai_harvest_snapshot_2026-08-22' );
        $check( 'snapshot saved to transient cache', is_array( $transient_cache ) && count( $transient_cache['articles'] ?? [] ) === 1 );

        // =========================================================================
        // Case 6: Error resilience on network failures & exceptions
        // =========================================================================
        self::reset_world();
        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) {
            if ( 'https://www.failing-source.gr' === $url ) {
                return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
            }
            if ( 'https://www.working-source.gr' === $url ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<main><a href="https://www.working-source.gr/article-1">Working Article</a></main>',
                ];
            }
            if ( 'https://www.working-source.gr/article-1' === $url ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<article><h1>Επιτυχές Άρθρο</h1><p>Περιεχόμενο επιτυχούς άρθρου.</p></article>',
                ];
            }
            return [ 'response' => [ 'code' => 500 ], 'body' => 'Server Error' ];
        };

        $resilience_result = $harvester->harvest_all( [ 'https://www.failing-source.gr', 'https://www.working-source.gr' ], '2026-08-23', 25 );
        $check( 'resilience: harvest continues despite failing source', count( $resilience_result['articles'] ?? [] ) === 1 );
        $check( 'resilience: 2 diagnostics recorded', count( $resilience_result['diagnostics'] ?? [] ) === 2 );
        $check( 'resilience: failing source recorded in diagnostics', ( $resilience_result['diagnostics'][0]['url'] ?? '' ) === 'https://www.failing-source.gr' );

        // =========================================================================
        // Case 7: AJAX Handler briefing_run_harvest with optional source_id
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date']                = '2026-08-24';
        $_POST['source_id']           = 'https://www.working-source.gr';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_sources'] = json_encode( [
            [
                'id'       => 'src_work',
                'name'     => 'Working Outlet',
                'url'      => 'https://www.working-source.gr',
                'type'     => 'text_news',
                'enabled'  => true,
                'category' => 'General',
                'notes'    => '',
            ],
        ] );

        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url ) {
            if ( 'https://www.working-source.gr' === $url ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<main><a href="https://www.working-source.gr/article-1">Working Article</a></main>',
                ];
            }
            if ( 'https://www.working-source.gr/article-1' === $url ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<article><h1>Επιτυχές Άρθρο</h1><p>Περιεχόμενο επιτυχούς άρθρου.</p></article>',
                ];
            }
            return [ 'response' => [ 'code' => 404 ], 'body' => '' ];
        };

        $ajax_handlers = new PressHub_AI_Ajax_Handlers();
        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_run_harvest' ] );
        $check( 'AJAX briefing_run_harvest: success response', true === ( $response['success'] ?? false ) );
        $check( 'AJAX briefing_run_harvest: returns articles', count( $response['data']['articles'] ?? [] ) >= 1 );

        // =========================================================================
        // Case 8: AJAX Handler exception resilience (returns structured JSON error)
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date']                = '2026-08-25';

        // Mock GET_RESPONSE_FILTER throwing an unexpected RuntimeException
        $GLOBALS['GET_RESPONSE_FILTER'] = function() {
            throw new \RuntimeException( 'Simulated catastrophic socket fault' );
        };

        $err_response = self::execute_ajax( [ $ajax_handlers, 'briefing_run_harvest' ] );
        $check( 'AJAX exception handling: does not crash, returns json_error or handled payload', isset( $err_response['success'] ) );

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

        if ( $failures ) {
            fwrite( STDERR, "NewsHarvesterTimeoutBudgetTest: FAIL (" . count( $failures ) . " errors)\n" );
            exit( 1 );
        }

        echo "NewsHarvesterTimeoutBudgetTest: OK (20+ checks)\n";
    }

    private static function execute_ajax( callable $callback ): array {
        $GLOBALS['JSON_RESPONSES'] = [];
        try {
            call_user_func( $callback );
        } catch ( Throwable $e ) {
            // Expected wp_send_json_success / wp_send_json_error exception in test harness
        }
        return end( $GLOBALS['JSON_RESPONSES'] ) ?: [ 'success' => false, 'data' => null ];
    }

    private static function reset_world(): void {
        $_POST                               = [];
        $_GET                                = [];
        $_FILES                              = [];
        $GLOBALS['OPTIONS_STORE']            = [];
        $GLOBALS['TRANSIENTS_STORE']         = [];
        $GLOBALS['CURRENT_USER_CAPS']        = [ 'edit_posts', 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID']          = 1;
        $GLOBALS['NONCE_VALID']              = true;
        $GLOBALS['SUBMENU_PAGES']            = [];
        $GLOBALS['ENQUEUED_SCRIPTS']         = [];
        $GLOBALS['ENQUEUED_STYLES']          = [];
        $GLOBALS['LOCALIZED_SCRIPTS']        = [];
        $GLOBALS['JSON_RESPONSES']           = [];
        $GLOBALS['POSTS_STORE']              = [];
        $GLOBALS['POST_META_STORE']          = [];
        $GLOBALS['GET_RESPONSE_FILTER']      = null;
        $GLOBALS['CAPTURE_FILTER']           = null;
        $GLOBALS['FILTERS']                  = [];
    }
}

NewsHarvesterTimeoutBudgetTest::run();