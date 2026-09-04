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
        // Case 1: Admin Menu Registration (Issue #73 — top-level menu)
        // =========================================================================
        self::reset_world();
        $admin = new PressHub_AI_Briefing_Admin();
        $admin->add_admin_menu();

        // 1a) The hub must NOT live under Settings anymore.
        $general_subs = $GLOBALS['SUBMENU_PAGES']['options-general.php'] ?? [];
        foreach ( $general_subs as $m ) {
            if ( ( $m['menu_slug'] ?? '' ) === 'presshub-ai-briefing-hub' ) {
                $failures[] = 'Daily Briefing Hub must no longer be registered under options-general.php; found: ' . json_encode( $general_subs );
            }
        }

        // 1b) The hub must be registered as a TOP-level admin menu entry.
        $top_level = $GLOBALS['MENU_PAGES'] ?? [];
        $briefing_top = null;
        foreach ( $top_level as $m ) {
            if ( ( $m['menu_slug'] ?? '' ) === 'presshub-ai-briefing-hub' ) {
                $briefing_top = $m;
                break;
            }
        }

        if ( ! $briefing_top ) {
            $failures[] = 'Daily Briefing Hub must be registered as a top-level admin menu (MENU_PAGES); got: ' . json_encode( $top_level );
        } else {
            if ( false === strpos( $briefing_top['page_title'], 'Daily Briefing Hub' ) ) {
                $failures[] = 'Top-level page title should contain "Daily Briefing Hub"; got: ' . $briefing_top['page_title'];
            }
            if ( false === strpos( $briefing_top['menu_title'], 'Daily Briefing Hub' ) ) {
                $failures[] = 'Top-level menu title should contain "Daily Briefing Hub"; got: ' . $briefing_top['menu_title'];
            }
            if ( $briefing_top['capability'] !== 'edit_posts' ) {
                $failures[] = 'Top-level capability should default to edit_posts; got: ' . $briefing_top['capability'];
            }
            if ( empty( $briefing_top['icon_url'] ) ) {
                $failures[] = 'Top-level icon_url must be set (dashicons-* expected); got: ' . ( $briefing_top['icon_url'] ?? '' );
            }
        }

        // 1c) The same page should also be registered as a submenu of itself.
        $hub_subs = $GLOBALS['SUBMENU_PAGES']['presshub-ai-briefing-hub'] ?? [];
        $found_self_sub = false;
        foreach ( $hub_subs as $m ) {
            if ( ( $m['menu_slug'] ?? '' ) === 'presshub-ai-briefing-hub' ) {
                $found_self_sub = true;
                break;
            }
        }
        if ( ! $found_self_sub ) {
            $failures[] = 'Daily Briefing Hub must also be registered as a submenu of its new top-level slug; got: ' . json_encode( $hub_subs );
        }

        // =========================================================================
        // Case 2: Admin Script & Stylesheet Enqueueing
        // =========================================================================
        self::reset_world();
        $admin = new PressHub_AI_Briefing_Admin();
        // add_menu_page() returns 'toplevel_page_<slug>'; the enqueue matcher
        // uses strpos() on the slug, so this still triggers asset loading.
        $admin->enqueue_assets( 'toplevel_page_presshub-ai-briefing-hub' );

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
            'presshub-harvest-inspector-section',
            'presshub-inspector-toolbar',
            'presshub-inspector-card',
            'presshub-selected-articles-count',
            'presshub-selected-words-total',
            'presshub-selected-tokens-total',
            'presshub-inspector-count-badge',
            'presshub-inspector-words-total',
            'presshub-inspector-tokens-total',
            'presshub-aggregate-totals',
            'btn-inspector-select-all',
            'btn-inspector-deselect-all',
            'presshub-article-checkbox',
            'presshub-article-source-pill',
            'presshub-context-mode-group',
            'presshub_podcast_context_mode',
            'curated_briefing',
            'harvested_articles',
            'presshub-briefing-script-editor',
            'presshub-briefing-manual-upload',
            'presshub-source-health-section',
            'presshub-source-health-table',
            'presshub-source-health-tbody',
            'btn-refresh-diagnostics',
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
        // Case 3b: Issue #57 — Aggregate word/token totals must be PHP-computed
        //          on initial render (first paint, even with JS disabled).
        // =========================================================================
        // Regression for issue #57: previously the milestone card and inspector
        // toolbar displayed hard-coded "Total: 0 words" / "~0 tokens" placeholders
        // because the JS recompute (updateSelectedCountBadge) was never invoked
        // during $(document).ready boot. The fix adds:
        //   (a) a server-side fallback that computes totals from $status['articles']
        //   (b) a JS boot call to updateSelectedCountBadge().
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];

        $agg_test_date    = '2026-08-27';
        $agg_known_content = 'Ελληνική είδηση με πολλές λέπτομέρειες για να μετρηθεί σωστά το μήκος του κειμένου και να ελεγχθεί ο υπολογισμός λέξεων.';

        ( new PressHub_AI_News_Harvester() )->save_snapshot( $agg_test_date, [
            'date'     => $agg_test_date,
            'articles' => [
                [
                    'url'          => 'https://www.example.gr/aggregate-test',
                    'title'        => 'Test Aggregate Article',
                    'content'      => $agg_known_content,
                    'source'       => 'example.gr',
                    'is_manual'    => false,
                    'harvested_at' => '2026-08-27T06:30:00Z',
                ],
            ],
        ] );

        $agg_admin = new PressHub_AI_Briefing_Admin();
        ob_start();
        $agg_admin->render_hub_page( $agg_test_date );
        $agg_html = ob_get_clean();

        $expected_words  = PressHub_AI_Context_Estimator::utf8_word_count( $agg_known_content );
        $expected_tokens = PressHub_AI_Context_Estimator::estimate_tokens( $agg_known_content );

        $expected_words_text  = 'Total: ' . number_format_i18n( $expected_words ) . ' words';
        $expected_tokens_text = '~' . number_format_i18n( $expected_tokens ) . ' tokens';

        // Milestone card span
        if ( false === strpos( $agg_html, 'id="presshub-selected-words-total"' ) ) {
            $failures[] = 'Expected milestone card span #presshub-selected-words-total is missing.';
        } elseif ( ! self::span_contains_text( $agg_html, 'presshub-selected-words-total', $expected_words_text ) ) {
            $failures[] = sprintf(
                'Milestone card #presshub-selected-words-total must be PHP-computed (first paint, no JS). Expected "%s", got surrounding: %s',
                $expected_words_text,
                self::surrounding( $agg_html, 'presshub-selected-words-total', 60 )
            );
        }
        if ( false === strpos( $agg_html, 'id="presshub-selected-tokens-total"' ) ) {
            $failures[] = 'Expected milestone card span #presshub-selected-tokens-total is missing.';
        } elseif ( ! self::span_contains_text( $agg_html, 'presshub-selected-tokens-total', $expected_tokens_text ) ) {
            $failures[] = sprintf(
                'Milestone card #presshub-selected-tokens-total must be PHP-computed (first paint, no JS). Expected "%s", got surrounding: %s',
                $expected_tokens_text,
                self::surrounding( $agg_html, 'presshub-selected-tokens-total', 60 )
            );
        }

        // Inspector toolbar span
        if ( false === strpos( $agg_html, 'id="presshub-inspector-words-total"' ) ) {
            $failures[] = 'Expected inspector toolbar span #presshub-inspector-words-total is missing.';
        } elseif ( ! self::span_contains_text( $agg_html, 'presshub-inspector-words-total', $expected_words_text ) ) {
            $failures[] = sprintf(
                'Inspector toolbar #presshub-inspector-words-total must be PHP-computed (first paint, no JS). Expected "%s", got surrounding: %s',
                $expected_words_text,
                self::surrounding( $agg_html, 'presshub-inspector-words-total', 60 )
            );
        }
        if ( false === strpos( $agg_html, 'id="presshub-inspector-tokens-total"' ) ) {
            $failures[] = 'Expected inspector toolbar span #presshub-inspector-tokens-total is missing.';
        } elseif ( ! self::span_contains_text( $agg_html, 'presshub-inspector-tokens-total', $expected_tokens_text ) ) {
            $failures[] = sprintf(
                'Inspector toolbar #presshub-inspector-tokens-total must be PHP-computed (first paint, no JS). Expected "%s", got surrounding: %s',
                $expected_tokens_text,
                self::surrounding( $agg_html, 'presshub-inspector-tokens-total', 60 )
            );
        }

        // Must not contain the placeholder strings when there is real content.
        if ( false !== strpos( $agg_html, 'Total: 0 words' ) ) {
            $failures[] = "Aggregate spans must not fall back to literal 'Total: 0 words' when articles have content.";
        }
        if ( false !== strpos( $agg_html, '~0 tokens' ) ) {
            $failures[] = "Aggregate spans must not fall back to literal '~0 tokens' when articles have content.";
        }

        // JS boot call: ensure briefing-admin.js contains a top-level invocation
        // of updateSelectedCountBadge so the live path also fills the spans
        // (the defensive PHP fallback alone would not refresh the live state
        // after an incremental AJAX refresh with new totals).
        $js_path  = __DIR__ . '/../assets/briefing-admin.js';
        $js_boot_marker   = "if (typeof updateSelectedCountBadge === 'function') {";
        if ( ! is_file( $js_path ) ) {
            $failures[] = "briefing-admin.js missing at {$js_path}.";
        } else {
            $js_contents = file_get_contents( $js_path );
            if ( false === strpos( $js_contents, $js_boot_marker ) ) {
                $failures[] = "briefing-admin.js must invoke updateSelectedCountBadge() at boot so first paint recomputes aggregate totals (#57).";
            }
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
            'briefing_curate_text',
            'briefing_run_script',
            'briefing_generate_podcast',
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
                    'body'     => '<html><body><h1>Είδηση Τίτλος για την Επικαιρότητα</h1><p>Περιεχόμενο είδησης με αναλυτική καταγραφή των γεγονότων και όλες τις απαραίτητες λεπτομέρειες για την πορεία των διαπραγματεύσεων και τις νέες αποφάσεις που ελήφθησαν κατά τη διάρκεια της συνόδου.</p></body></html>',
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '<html><body><a href="https://www.tovima.gr/politics/article-1">Είδηση για την Επικαιρότητα</a></body></html>',
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
            $content = "# Σημαντικές Εξελίξεις\n\n## Πολιτική\nΑνάλυση γεγονότων...";
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices'    => [ [ 'message' => [ 'content' => $content ] ] ],
                    'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => $content ] ] ] ] ],
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
            $content = "[Μαρία]: Καλημέρα!\n[Νίκος]: Καλημέρα Μαρία!";
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices'    => [ [ 'message' => [ 'content' => $content ] ] ],
                    'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => $content ] ] ] ] ],
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
        $GLOBALS['OPTIONS_STORE']['presshub_ai_gemini_api_key'] = 'test-gemini-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model'] = 'gemini-2.5-flash-preview-tts';
        $_POST['date']   = $test_date;
        $_POST['script'] = "[Μαρία]: Καλημέρα!\n[Νίκος]: Καλημέρα!";

        $GLOBALS['CAPTURE_FILTER'] = function( $result, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'inlineData' => [
                                            'mimeType' => 'audio/pcm;rate=24000',
                                            'data'     => base64_encode( str_repeat( "\x11\x22", 2400 ) ),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ] ),
            ];
        };

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_generate_audio' ] );
        if ( ! $response['success'] || empty( $response['data']['audio_url'] ) || empty( $response['data']['post_id'] ) ) {
            $failures[] = 'briefing_generate_audio AJAX should synthesize audio and create podcast post; got: ' . json_encode( $response );
        }

        // =========================================================================
        // Case 13: AJAX Endpoint Execution - presshub_ai_briefing_curate_text with selected_articles
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date'] = $test_date;
        $_POST['selected_articles'] = [ 0, 2 ];

        $harvester = new PressHub_AI_News_Harvester();
        $harvester->save_snapshot( $test_date, [
            'date'     => $test_date,
            'articles' => [
                [ 'id' => 101, 'title' => 'Άρθρο 1', 'content' => 'Κείμενο 1', 'source' => 'kathimerini.gr' ],
                [ 'id' => 102, 'title' => 'Άρθρο 2', 'content' => 'Κείμενο 2', 'source' => 'tovima.gr' ],
                [ 'id' => 103, 'title' => 'Άρθρο 3', 'content' => 'Κείμενο 3', 'source' => 'in.gr' ],
            ],
        ] );

        $GLOBALS['CAPTURE_FILTER'] = function( $result, $args ) {
            $content = "# Επιλεγμένες Ειδήσεις\n\n## Σύνοψη\nΣύνοψη επιλεγμένων ειδήσεων...";
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices'    => [ [ 'message' => [ 'content' => $content ] ] ],
                    'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => $content ] ] ] ] ],
                ] ),
            ];
        };

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_curate_text' ] );
        if ( ! $response['success'] || empty( $response['data']['post_id'] ) ) {
            $failures[] = 'briefing_curate_text AJAX should succeed with selected_articles array; got: ' . json_encode( $response );
        } elseif ( ( $response['data']['articles_count'] ?? 0 ) !== 2 ) {
            $failures[] = 'briefing_curate_text should have filtered down to 2 selected articles; got count: ' . ( $response['data']['articles_count'] ?? 'null' );
        }

        // Test comma-separated string format
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date'] = $test_date;
        $_POST['selected_articles'] = '1';

        $harvester->save_snapshot( $test_date, [
            'date'     => $test_date,
            'articles' => [
                [ 'id' => 101, 'title' => 'Άρθρο 1', 'content' => 'Κείμενο 1', 'source' => 'kathimerini.gr' ],
                [ 'id' => 102, 'title' => 'Άρθρο 2', 'content' => 'Κείμενο 2', 'source' => 'tovima.gr' ],
                [ 'id' => 103, 'title' => 'Άρθρο 3', 'content' => 'Κείμενο 3', 'source' => 'in.gr' ],
            ],
        ] );

        $GLOBALS['CAPTURE_FILTER'] = function( $result, $args ) {
            $content = "# Επιλεγμένη Είδηση\n\n## Σύνοψη\nΣύνοψη...";
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices'    => [ [ 'message' => [ 'content' => $content ] ] ],
                    'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => $content ] ] ] ] ],
                ] ),
            ];
        };

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_run_curation' ] );
        if ( ! $response['success'] || ( $response['data']['articles_count'] ?? 0 ) !== 1 ) {
            $failures[] = 'briefing_run_curation should support comma-separated selected_articles; got: ' . json_encode( $response );
        }

        // =========================================================================
        // Case 14: AJAX Endpoint Execution - presshub_ai_briefing_generate_podcast with context_mode & selected_articles
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID'] = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date']              = $test_date;
        $_POST['context_mode']       = 'harvested_articles';
        $_POST['selected_articles'] = [ 0 ];

        $harvester->save_snapshot( $test_date, [
            'date'     => $test_date,
            'articles' => [
                [ 'id' => 201, 'title' => 'Ειδικό Θέμα 1', 'content' => 'Μοναδικό Περιεχόμενο 1', 'source' => 'kathimerini.gr' ],
                [ 'id' => 202, 'title' => 'Ειδικό Θέμα 2', 'content' => 'Μοναδικό Περιεχόμενο 2', 'source' => 'tovima.gr' ],
            ],
        ] );

        $captured_prompt = '';
        $GLOBALS['CAPTURE_FILTER'] = function( $result, $args ) use ( &$captured_prompt ) {
            $body = json_decode( $args['body'] ?? '{}', true );
            $captured_prompt = json_encode( $body );
            $content = "[Μαρία]: Καλημέρα!\n[Νίκος]: Καλημέρα Μαρία!";
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices'    => [ [ 'message' => [ 'content' => $content ] ] ],
                    'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => $content ] ] ] ] ],
                ] ),
            ];
        };

        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_generate_podcast' ] );
        if ( ! $response['success'] || empty( $response['data']['turns_count'] ) || $response['data']['turns_count'] !== 2 ) {
            $failures[] = 'briefing_generate_podcast AJAX should succeed with context_mode & selected_articles; got: ' . json_encode( $response );
        }
        if ( false !== strpos( $captured_prompt, 'Μοναδικό Περιεχόμενο 2' ) ) {
            $failures[] = 'Podcast dialogue prompt should not include unselected Article 2; captured: ' . $captured_prompt;
        }

        // =========================================================================
        // Case 15: Per-Source Article Quota Schema Normalization & Modal Rendering (Issue #31)
        // =========================================================================
        self::reset_world();
        
        // 1. Schema normalization tests
        $test_sources = [
            [
                'id'           => 'src_1',
                'name'         => 'Default Quota Outlet',
                'url'          => 'https://www.source1.gr',
                'type'         => 'text_news',
                // max_articles omitted -> default 5
            ],
            [
                'id'           => 'src_2',
                'name'         => 'Custom Quota Outlet',
                'url'          => 'https://www.source2.gr',
                'type'         => 'text_news',
                'max_articles' => 8,
            ],
            [
                'id'           => 'src_3',
                'name'         => 'Below Range Outlet',
                'url'          => 'https://www.source3.gr',
                'type'         => 'text_news',
                'max_articles' => 0, // Clamped to 1
            ],
            [
                'id'           => 'src_4',
                'name'         => 'Above Range Outlet',
                'url'          => 'https://www.source4.gr',
                'type'         => 'text_news',
                'max_articles' => 50, // Clamped to 30
            ],
            'https://www.legacy-string-source.gr', // Legacy string -> default 5
        ];

        $normalized = PressHub_AI_Settings_Storage::normalize_sources( $test_sources );

        if ( count( $normalized ) !== 5 ) {
            $failures[] = 'normalize_sources should normalize all 5 test sources; got: ' . count( $normalized );
        } else {
            if ( ( $normalized[0]['max_articles'] ?? null ) !== 5 ) {
                $failures[] = 'Source 1 default max_articles must be 5; got: ' . json_encode( $normalized[0] );
            }
            if ( ( $normalized[1]['max_articles'] ?? null ) !== 8 ) {
                $failures[] = 'Source 2 custom max_articles must be 8; got: ' . json_encode( $normalized[1] );
            }
            if ( ( $normalized[2]['max_articles'] ?? null ) !== 1 ) {
                $failures[] = 'Source 3 below-range max_articles (0) must be clamped to 1; got: ' . json_encode( $normalized[2] );
            }
            if ( ( $normalized[3]['max_articles'] ?? null ) !== 30 ) {
                $failures[] = 'Source 4 above-range max_articles (50) must be clamped to 30; got: ' . json_encode( $normalized[3] );
            }
            if ( ( $normalized[4]['max_articles'] ?? null ) !== 5 ) {
                $failures[] = 'Legacy string source max_articles must default to 5; got: ' . json_encode( $normalized[4] );
            }
        }

        // 2. Modal markup rendering
        $settings_render = new PressHub_AI_Settings_Render();
        ob_start();
        $settings_render->render_source_modal();
        $modal_html = ob_get_clean();

        if ( false === strpos( $modal_html, 'id="source-form-max-articles"' ) ) {
            $failures[] = 'render_source_modal() must render #source-form-max-articles input element; got: ' . $modal_html;
        }
        if ( false === strpos( $modal_html, 'name="max_articles"' ) ) {
            $failures[] = 'render_source_modal() must render name="max_articles"; got: ' . $modal_html;
        }
        if ( false === strpos( $modal_html, 'min="1"' ) || false === strpos( $modal_html, 'max="30"' ) ) {
            $failures[] = 'render_source_modal() must enforce min="1" and max="30" on max_articles input; got: ' . $modal_html;
        }
        if ( false === strpos( $modal_html, 'Max Articles to Harvest' ) ) {
            $failures[] = 'render_source_modal() must include "Max Articles to Harvest" label; got: ' . $modal_html;
        }

        // 3. Source row rendering with quota badge
        $row_html = $settings_render->render_source_row( [
            'id'           => 'src_test_quota',
            'name'         => 'Quota Test Outlet',
            'url'          => 'https://www.quota-test.gr',
            'type'         => 'text_news',
            'enabled'      => true,
            'category'     => 'General',
            'notes'        => '',
            'max_articles' => 12,
        ] );

        if ( false === strpos( $row_html, 'presshub-source-quota' ) ) {
            $failures[] = 'render_source_row() must include presshub-source-quota element; got: ' . $row_html;
        }
        if ( false === strpos( $row_html, '12 articles' ) ) {
            $failures[] = 'render_source_row() must display "12 articles" for source with max_articles = 12; got: ' . $row_html;
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

        echo "DailyBriefingAdminTest: OK (80+ checks)\n";
    }

    /**
     * Returns true if the text appears between <span id="X"> ... </span> on
     * the same span node (allowing arbitrary leading/trailing whitespace
     * produced by the PHP heredoc template indentation).
     */
    private static function span_contains_text( string $haystack, string $span_id, string $text ): bool {
        $open  = 'id="' . $span_id . '"';
        $open_pos = strpos( $haystack, $open );
        if ( false === $open_pos ) {
            return false;
        }
        // Find the closing </span> after the opener.
        $close_pos = strpos( $haystack, '</span>', $open_pos );
        if ( false === $close_pos ) {
            return false;
        }
        $inner = substr( $haystack, $open_pos, $close_pos - $open_pos );
        return false !== strpos( $inner, $text );
    }

    /**
     * Extract a substring around a keyword for friendlier failure messages.
     */
    private static function surrounding( string $haystack, string $needle, int $radius = 60 ): string {
        $pos = strpos( $haystack, $needle );
        if ( false === $pos ) {
            return '';
        }
        $start = max( 0, $pos - $radius );
        $end   = min( strlen( $haystack ), $pos + strlen( $needle ) + $radius );
        return '...' . substr( $haystack, $start, $end - $start ) . '...';
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
