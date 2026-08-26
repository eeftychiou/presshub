<?php
/**
 * TDD Unit Tests for Daily News Briefing Hub (WP Admin Dashboard & AJAX Handlers).
 *
 * Covers:
 *   - Admin menu registration: PressHub AI > Daily Briefing Hub (hook: admin_menu).
 *   - Admin script & stylesheet enqueueing (hook: admin_enqueue_scripts).
 *   - Rendering of Daily Briefing Hub (milestone cards, manual upload box, script editor, action buttons).
 *   - Method get_briefing_status( $date ).
 *   - AJAX handlers in PressHub_AI_Ajax_Handlers with nonce & capability checks:
 *       * presshub_ai_briefing_get_status
 *       * presshub_ai_briefing_run_harvest
 *       * presshub_ai_briefing_run_curation
 *       * presshub_ai_briefing_run_script
 *       * presshub_ai_briefing_save_script
 *       * presshub_ai_briefing_generate_audio
 *       * presshub_ai_briefing_upload
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

// Skip update checker in test harness
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once __DIR__ . '/../presshub-ai-editor.php';

class DailyBriefingAdminTest
{
    public static function run(): void {
        $failures = [];

        // =========================================================================
        // Case 1: Admin Menu Registration
        // =========================================================================
        self::reset_world();
        $admin = new PressHub_AI_Briefing_Admin();
        $admin->add_admin_menu();

        $submenus = $GLOBALS['SUBMENU_PAGES']['options-general.php'] ?? [];
        $briefing_menu = null;
        foreach ( $submenus as $m ) {
            if ( ( $m['menu_slug'] ?? '' ) === 'presshub-ai-briefing-hub' ) {
                $briefing_menu = $m;
                break;
            }
        }

        if ( ! $briefing_menu ) {
            $failures[] = 'Daily Briefing Hub submenu (presshub-ai-briefing-hub) must be registered under options-general.php; got: ' . json_encode( $submenus );
        } else {
            if ( false === strpos( $briefing_menu['page_title'], 'Daily Briefing Hub' ) ) {
                $failures[] = 'Submenu page title should contain "Daily Briefing Hub"; got: ' . $briefing_menu['page_title'];
            }
            if ( $briefing_menu['capability'] !== 'edit_posts' ) {
                $failures[] = 'Submenu capability should default to edit_posts; got: ' . $briefing_menu['capability'];
            }
        }

        // =========================================================================
        // Case 2: Admin Script & Stylesheet Enqueueing
        // =========================================================================
        self::reset_world();
        $admin = new PressHub_AI_Briefing_Admin();
        $admin->enqueue_assets( 'settings_page_presshub-ai-briefing-hub' );

        $scripts = $GLOBALS['ENQUEUED_SCRIPTS'] ?? [];
        $styles  = $GLOBALS['ENQUEUED_STYLES'] ?? [];

        if ( empty( $scripts['presshub-ai-briefing-admin-js'] ) ) {
            $failures[] = 'presshub-ai-briefing-admin-js script must be enqueued on settings_page_presshub-ai-briefing-hub.';
        } else {
            $script_entry = $scripts['presshub-ai-briefing-admin-js'];
            if ( ! in_array( 'jquery', $script_entry['deps'], true ) ) {
                $failures[] = 'Script must have jquery dependency.';
            }
            if ( true !== $script_entry['in_footer'] ) {
                $failures[] = 'Script must be enqueued in footer.';
            }
        }

        if ( empty( $styles['presshub-ai-briefing-admin-css'] ) ) {
            $failures[] = 'presshub-ai-briefing-admin-css stylesheet must be enqueued on settings_page_presshub-ai-briefing-hub.';
        }

        $localized = $GLOBALS['LOCALIZED_SCRIPTS']['presshub-ai-briefing-admin-js'] ?? null;
        if ( ! $localized || 'presshubBriefingAdmin' !== $localized['object_name'] ) {
            $failures[] = 'Script must be localized with object name presshubBriefingAdmin; got: ' . json_encode( $localized );
        } else {
            $data = $localized['data'];
            if ( empty( $data['ajax_url'] ) || empty( $data['nonce'] ) ) {
                $failures[] = 'Localized payload must contain ajax_url and nonce; got: ' . json_encode( $data );
            }
            if ( empty( $data['date'] ) ) {
                $failures[] = 'Localized payload must contain date (YYYY-MM-DD); got: ' . json_encode( $data );
            }
            if ( empty( $data['i18n'] ) || ! is_array( $data['i18n'] ) ) {
                $failures[] = 'Localized payload must contain i18n dictionary; got: ' . json_encode( $data );
            }
        }

        // Must not enqueue on unrelated pages
        self::reset_world();
        $admin = new PressHub_AI_Briefing_Admin();
        $admin->enqueue_assets( 'edit.php' );
        if ( ! empty( $GLOBALS['ENQUEUED_SCRIPTS'] ) || ! empty( $GLOBALS['ENQUEUED_STYLES'] ) ) {
            $failures[] = 'Assets must not be enqueued on non-briefing pages (e.g. edit.php).';
        }

        // =========================================================================
        // Case 3: Daily Briefing Hub Page Rendering
        // =========================================================================
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $test_date = '2026-08-26';
        $admin = new PressHub_AI_Briefing_Admin();

        // Create a test snapshot with blocked source
        $harvester = new PressHub_AI_News_Harvester();
        $harvester->save_snapshot( $test_date, [
            'date'            => $test_date,
            'harvested_at'    => '2026-08-26T06:30:00Z',
            'sources'         => [ 'https://www.kathimerini.gr', 'https://www.tovima.gr' ],
            'blocked_sources' => [ 'https://www.kathimerini.gr' ],
            'articles'        => [
                [
                    'url'          => 'https://www.tovima.gr/politics/article-1',
                    'title'        => 'Σημαντικές εξελίξεις στην οικονομία',
                    'content'      => 'Περιεχόμενο άρθρου...',
                    'source'       => 'tovima.gr',
                    'is_manual'    => false,
                    'harvested_at' => '2026-08-26T06:30:00Z',
                ],
            ],
        ] );

        // Plant a text briefing post
        $post_id = wp_insert_post( [
            'post_title'   => 'Πρωινή Ενημέρωση: Οικονομία - 26/08/2026',
            'post_content' => '<p>Κείμενο ενημέρωσης</p>',
            'post_status'  => 'pending',
            'meta_input'   => [
                '_presshub_briefing_date' => $test_date,
                '_presshub_briefing_type' => 'text',
            ],
        ] );

        // Plant a podcast script
        $producer = new PressHub_AI_Podcast_Producer();
        $producer->save_script( $test_date, "[Μαρία]: Καλημέρα σε όλους!\n[Νίκος]: Καλημέρα Μαρία!" );

        ob_start();
        $admin->render_hub_page( $test_date );
        $html = ob_get_clean();

        // Verify HTML markup components
        $required_strings = [
            'presshub-briefing-hub',
            'milestone-harvest',
            'milestone-curation',
            'milestone-script',
            'milestone-audio',
            'presshub-briefing-script-editor',
            'presshub-briefing-manual-upload',
            'btn-run-scrape',
            'btn-run-curation',
            'btn-run-script',
            'btn-save-script',
            'btn-synthesize-audio',
            'btn-process-upload',
            'kathimerini.gr', // Blocked source notice
        ];

        foreach ( $required_strings as $str ) {
            if ( false === strpos( $html, $str ) ) {
                $failures[] = "render_hub_page() output is missing expected markup: '{$str}'";
            }
        }

        // Permission denied check
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $admin = new PressHub_AI_Briefing_Admin();
        $thrown = false;
        try {
            $admin->render_hub_page( $test_date );
        } catch ( Throwable $e ) {
            $thrown = true;
        }
        if ( ! $thrown ) {
            $failures[] = 'render_hub_page() must reject users without edit_posts capability.';
        }

        // =========================================================================
        // Case 4: get_briefing_status( $date ) Method
        // =========================================================================
        self::reset_world();
        $admin = new PressHub_AI_Briefing_Admin();
        $empty_date = '2026-01-01';

        // Empty state
        $empty_status = $admin->get_briefing_status( $empty_date );
        if ( true === $empty_status['harvested'] || $empty_status['article_count'] !== 0 ) {
            $failures[] = 'Empty date status should report harvested = false and article_count = 0; got: ' . json_encode( $empty_status );
        }
        if ( true === $empty_status['text_created'] || true === $empty_status['script_created'] || true === $empty_status['audio_created'] ) {
            $failures[] = 'Empty date status should report text/script/audio created = false; got: ' . json_encode( $empty_status );
        }

        // Populated state
        $test_date = '2026-08-26';
        $harvester = new PressHub_AI_News_Harvester();
        $harvester->save_snapshot( $test_date, [
            'date'            => $test_date,
            'harvested_at'    => '2026-08-26T06:30:00Z',
            'sources'         => [ 'https://www.in.gr' ],
            'blocked_sources' => [],
            'articles'        => [ [ 'title' => 'Test Article', 'content' => 'Content', 'source' => 'in.gr', 'url' => 'https://www.in.gr/1' ] ],
        ] );

        $curator_post_id = wp_insert_post( [
            'post_title'   => 'Πρωινή Ενημέρωση - 26/08/2026',
            'post_content' => '<p>Briefing Story</p>',
            'post_status'  => 'publish',
            'meta_input'   => [
                '_presshub_briefing_date' => $test_date,
                '_presshub_briefing_type' => 'text',
            ],
        ] );

        $producer = new PressHub_AI_Podcast_Producer();
        $script_content = "[Μαρία]: Καλημέρα!\n[Νίκος]: Καλημέρα σε όλους!";
        $producer->save_script( $test_date, $script_content );

        $audio_post_id = wp_insert_post( [
            'post_title'   => 'Podcast Ενημέρωσης - 26/08/2026',
            'post_content' => '<audio src="http://example.test/audio.mp3"></audio>',
            'post_status'  => 'pending',
            'meta_input'   => [
                '_presshub_briefing_date'        => $test_date,
                '_presshub_briefing_type'        => 'podcast',
                '_presshub_audio_attachment_id' => 456,
                '_presshub_audio_url'           => 'http://example.test/audio.mp3',
            ],
        ] );

        $full_status = $admin->get_briefing_status( $test_date );

        if ( ! $full_status['harvested'] || $full_status['article_count'] !== 1 ) {
            $failures[] = 'Status should report harvested = true with article_count = 1; got: ' . json_encode( $full_status );
        }
        if ( ! $full_status['text_created'] || (int) $full_status['text_post_id'] !== (int) $curator_post_id ) {
            $failures[] = "Status should report text_created = true and text_post_id = {$curator_post_id}; got: " . json_encode( $full_status );
        }
        if ( ! $full_status['script_created'] || false === strpos( $full_status['script_text'], 'Καλημέρα' ) ) {
            $failures[] = 'Status should report script_created = true with script content; got: ' . json_encode( $full_status );
        }
        if ( ! $full_status['audio_created'] || $full_status['audio_url'] !== 'http://example.test/audio.mp3' ) {
            $failures[] = 'Status should report audio_created = true with audio_url; got: ' . json_encode( $full_status );
        }

        // =========================================================================
        // Case 5: AJAX Handlers - Nonce & Capability Gate Tests
        // =========================================================================
        $ajax_handlers = new PressHub_AI_Ajax_Handlers();
        $endpoints = [
            'briefing_get_status',
            'briefing_run_harvest',
            'briefing_run_curation',
            'briefing_run_script',
            'briefing_save_script',
            'briefing_generate_audio',
            'briefing_upload',
        ];

        // Nonce failure test for each endpoint
        foreach ( $endpoints as $endpoint ) {
            self::reset_world();
            $GLOBALS['NONCE_VALID'] = false;
            $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
            $thrown = false;
            try {
                $ajax_handlers->{$endpoint}();
            } catch ( Throwable $e ) {
                $thrown = true;
            }
            if ( ! $thrown ) {
                $failures[] = "AJAX endpoint {$endpoint} must enforce check_ajax_referer.";
            }
        }

        // Capability failure test for each endpoint
        foreach ( $endpoints as $endpoint ) {
            self::reset_world();
            $GLOBALS['NONCE_VALID'] = true;
            $GLOBALS['CURRENT_USER_CAPS'] = []; // no caps
            $thrown = false;
            try {
                $ajax_handlers->{$endpoint}();
            } catch ( Throwable $e ) {
                $thrown = true;
                if ( false === strpos( $e->getMessage(), 'Permission denied' ) ) {
                    $failures[] = "AJAX endpoint {$endpoint} capability failure should emit 'Permission denied'; got: " . $e->getMessage();
                }
            }
            if ( ! $thrown ) {
                $failures[] = "AJAX endpoint {$endpoint} must reject requests without edit_posts capability.";
            }
        }

        // =========================================================================
        // Case 6: AJAX Endpoint Execution - presshub_ai_briefing_get_status
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date'] = $test_date;

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_get_status' ] );
        if ( ! $response['success'] || ! isset( $response['data']['harvested'] ) ) {
            $failures[] = 'briefing_get_status AJAX should return success with status data; got: ' . json_encode( $response );
        }

        // =========================================================================
        // Case 7: AJAX Endpoint Execution - presshub_ai_briefing_run_harvest
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date'] = $test_date;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_sources'] = 'https://www.tovima.gr';

        $GLOBALS['GET_RESPONSE_FILTER'] = function( $url, $args ) {
            if ( false !== strpos( $url, 'article-1' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '<html><body><h1>Είδηση Τίτλος</h1><p>Περιεχόμενο είδησης...</p></body></html>',
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '<html><body><a href="https://www.tovima.gr/politics/article-1">Είδηση</a></body></html>',
            ];
        };

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_run_harvest' ] );
        if ( ! $response['success'] || empty( $response['data']['articles'] ) ) {
            $failures[] = 'briefing_run_harvest AJAX should harvest articles successfully; got: ' . json_encode( $response );
        }

        // =========================================================================
        // Case 8: AJAX Endpoint Execution - presshub_ai_briefing_save_script
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date']   = $test_date;
        $_POST['script'] = "[Μαρία]: Νέο επεξεργασμένο κείμενο!\n[Νίκος]: Συμφωνώ απόλυτα!";

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_save_script' ] );
        if ( ! $response['success'] || empty( $response['data']['saved'] ) || ( $response['data']['turns_count'] ?? 0 ) !== 2 ) {
            $failures[] = 'briefing_save_script AJAX should save script and report 2 turns; got: ' . json_encode( $response );
        }

        // Empty script validation error
        $_POST['script'] = '   ';
        $thrown = false;
        try {
            $ajax_handlers->briefing_save_script();
        } catch ( Throwable $e ) {
            $thrown = true;
            if ( false === strpos( $e->getMessage(), 'empty' ) ) {
                $failures[] = 'briefing_save_script with empty script should return empty script error; got: ' . $e->getMessage();
            }
        }
        if ( ! $thrown ) {
            $failures[] = 'briefing_save_script must reject empty script.';
        }

        // =========================================================================
        // Case 9: AJAX Endpoint Execution - presshub_ai_briefing_upload
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date']    = $test_date;
        $_POST['source']  = 'Kathimerini';
        $_POST['title']   = 'Χειροκίνητη Ανάλυση';
        $_POST['content'] = 'Ανάλυση γεγονότων από την Καθημερινή...';

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_upload' ] );
        if ( ! $response['success'] || empty( $response['data']['articles'] ) ) {
            $failures[] = 'briefing_upload AJAX should merge manual notes; got: ' . json_encode( $response );
        } else {
            $merged_titles = array_column( $response['data']['articles'], 'title' );
            if ( ! in_array( 'Χειροκίνητη Ανάλυση', $merged_titles, true ) ) {
                $failures[] = 'Uploaded manual article title must appear in merged article list; got: ' . json_encode( $merged_titles );
            }
        }

        // =========================================================================
        // Case 10: AJAX Endpoint Execution - presshub_ai_briefing_run_curation
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date'] = $test_date;

        // Ensure snapshot exists
        $harvester = new PressHub_AI_News_Harvester();
        $harvester->save_snapshot( $test_date, [
            'date'     => $test_date,
            'articles' => [ [ 'title' => 'Άρθρο 1', 'content' => 'Κείμενο 1', 'source' => 'kathimerini.gr' ] ],
        ] );

        $GLOBALS['CAPTURE_FILTER'] = function( $result, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices' => [ [ 'message' => [ 'content' => "# Σημαντικές Εξελίξεις\n\n## Πολιτική\nΑνάλυση γεγονότων..." ] ] ],
                ] ),
            ];
        };

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_run_curation' ] );
        if ( ! $response['success'] || empty( $response['data']['post_id'] ) ) {
            $failures[] = 'briefing_run_curation AJAX should create text story post; got: ' . json_encode( $response );
        }

        // =========================================================================
        // Case 11: AJAX Endpoint Execution - presshub_ai_briefing_run_script
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date'] = $test_date;

        $GLOBALS['CAPTURE_FILTER'] = function( $result, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices' => [ [ 'message' => [ 'content' => "[Μαρία]: Καλημέρα!\n[Νίκος]: Καλημέρα Μαρία!" ] ] ],
                ] ),
            ];
        };

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_run_script' ] );
        if ( ! $response['success'] || empty( $response['data']['turns_count'] ) || $response['data']['turns_count'] !== 2 ) {
            $failures[] = 'briefing_run_script AJAX should generate podcast script with 2 turns; got: ' . json_encode( $response );
        }

        // =========================================================================
        // Case 12: AJAX Endpoint Execution - presshub_ai_briefing_generate_audio
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'test-gcloud-key';
        $_POST['date']   = $test_date;
        $_POST['script'] = "[Μαρία]: Καλημέρα!\n[Νίκος]: Καλημέρα!";

        $GLOBALS['CAPTURE_FILTER'] = function( $result, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'audioContent' => base64_encode( "\xFF\xFB\x90\xC4\x00\x03\xC0\x00\x01\xA4" . str_repeat( "\x55", 407 ) ),
                ] ),
            ];
        };

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_generate_audio' ] );
        if ( ! $response['success'] || empty( $response['data']['audio_url'] ) || empty( $response['data']['post_id'] ) ) {
            $failures[] = 'briefing_generate_audio AJAX should synthesize audio and create podcast post; got: ' . json_encode( $response );
        }

        // =========================================================================
        // Summary & Verdict
        // =========================================================================
        if ( $failures ) {
            fwrite( STDERR, "DailyBriefingAdminTest: FAIL (" . count( $failures ) . " errors)\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }

        echo "DailyBriefingAdminTest: OK (50+ checks)\n";
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
    }
}

DailyBriefingAdminTest::run();
