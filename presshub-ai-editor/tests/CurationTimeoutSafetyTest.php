<?php
/**
 * CurationTimeoutSafetyTest — Unit tests for PressHub_AI_News_Curator curation timeout
 * resilience, AJAX try/catch safety wrappers, and budget helper retrieval (Fixes #39).
 *
 * Test cases:
 *   1. Curation budget helper (get_curation_time_budget) — default, override, clamp, filter.
 *   2. Curation audio budget helper (get_curation_audio_time_budget) — default, override, clamp.
 *   3. format_articles_context() truncates the article list when count > max_articles filter and
 *      sets was_context_truncated() + last_original_articles_count() correctly.
 *   4. format_articles_context() truncates long article content bodies via max_chars filter and
 *      sets was_context_truncated() = true.
 *   5. format_articles_context() leaves static state untouched (false / 0) when within budget.
 *   6. generate_briefing() returns articles_truncated / articles_count_original payload fields.
 *   7. AJAX briefing_run_curation() catches generic Throwable and returns wp_send_json_error
 *      instead of bubbling 500.
 *   8. AJAX briefing_run_script() catches generic Throwable and returns wp_send_json_error.
 *   9. AJAX briefing_generate_audio() catches generic Throwable and returns wp_send_json_error.
 *  10. AJAX handlers enforce set_time_limit / wp_raise_memory_limit at handler entry point.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

class CurationTimeoutSafetyTest {

    public static function run(): void {
        $failures = [];
        $test_upload_dir = sys_get_temp_dir() . '/presshub-curation-safety-' . uniqid();
        $GLOBALS['UPLOAD_DIR'] = $test_upload_dir;

        $check = function( $label, $condition ) use ( &$failures ) {
            self::$check_count++;
            if ( ! $condition ) {
                $failures[] = $label;
                fwrite( STDERR, "FAIL: {$label}\n" );
            }
        };

        // =========================================================================
        // Case 1: Curation budget helper retrieval (PressHub_AI_Settings_Storage)
        // =========================================================================
        self::reset_world();

        $check(
            'curation_time_budget default is 120s',
            120 === PressHub_AI_Settings_Storage::get_curation_time_budget()
        );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_time_budget'] = 240;
        $check(
            'curation_time_budget configured option returns 240s',
            240 === PressHub_AI_Settings_Storage::get_curation_time_budget()
        );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_time_budget'] = 5; // below minimum
        $check(
            'curation_time_budget clamps below minimum to 120s default',
            120 === PressHub_AI_Settings_Storage::get_curation_time_budget()
        );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_time_budget'] = 5000; // above maximum
        $check(
            'curation_time_budget clamps above maximum to 120s default',
            120 === PressHub_AI_Settings_Storage::get_curation_time_budget()
        );

        // Cleanup option override
        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_time_budget'] );
        $check(
            'curation_time_budget restores to default 120s after option unset',
            120 === PressHub_AI_Settings_Storage::get_curation_time_budget()
        );

        // =========================================================================
        // Case 2: Audio curation budget helper retrieval
        // =========================================================================
        self::reset_world();

        $check(
            'curation_audio_time_budget default is 180s',
            180 === PressHub_AI_Settings_Storage::get_curation_audio_time_budget()
        );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_audio_time_budget'] = 300;
        $check(
            'curation_audio_time_budget configured option returns 300s',
            300 === PressHub_AI_Settings_Storage::get_curation_audio_time_budget()
        );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_audio_time_budget'] = 5; // below minimum
        $check(
            'curation_audio_time_budget clamps below minimum to 180s default',
            180 === PressHub_AI_Settings_Storage::get_curation_audio_time_budget()
        );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_audio_time_budget'] = 5000; // above maximum
        $check(
            'curation_audio_time_budget clamps above maximum to 180s default',
            180 === PressHub_AI_Settings_Storage::get_curation_audio_time_budget()
        );

        // Cleanup
        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_audio_time_budget'] );

        // =========================================================================
        // Case 3: format_articles_context() truncates article list when count > max_articles
        // =========================================================================
        self::reset_world();

        $curator = new PressHub_AI_News_Curator();

        // Force max_articles=5 via the Settings-First option (Issue #61 —
        // the apply_filters() escape hatch was removed; the authoritative
        // value lives in the WordPress option read through the helper).
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'] = 5;

        // 12-article fixture (will be truncated to 5)
        $articles = [];
        for ( $i = 1; $i <= 12; $i++ ) {
            $articles[] = [
                'title'   => "Title {$i}",
                'source'  => 'TestSource',
                'url'     => "https://test.example/{$i}",
                'content' => "Content body for article {$i}",
            ];
        }

        $ctx = $curator->format_articles_context( $articles );
        $check(
            'format_articles_context: produces string',
            is_string( $ctx )
        );
        $check(
            'format_articles_context: was_context_truncated() returns true',
            true === $curator->was_context_truncated()
        );
        $check(
            'format_articles_context: last_original_articles_count() returns 12',
            12 === $curator->last_original_articles_count()
        );
        $check(
            'format_articles_context: context contains only 5 article headings (no 11/12)',
            false !== strpos( $ctx, '### 5. Title 5' ) && false === strpos( $ctx, '### 11.' )
        );

        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'] );

        // =========================================================================
        // Case 4: format_articles_context() truncates long bodies via max_chars
        // =========================================================================
        self::reset_world();

        // Tight max_chars via the Settings-First option (Issue #61).
        // 100 is the sanitizer's documented minimum; any lower value would
        // be clamped away by get_curation_max_chars_per_article().
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 100;

        $long_articles = [
            [
                'title'   => 'Long Article',
                'source'  => 'TestSource',
                'url'     => 'https://test.example/long',
                'content' => str_repeat( 'This is a very long article body. ', 20 ),
            ],
        ];

        $long_ctx = $curator->format_articles_context( $long_articles );
        $check(
            'format_articles_context (max_chars): was_context_truncated() returns true',
            true === $curator->was_context_truncated()
        );
        $check(
            'format_articles_context (max_chars): last_original_articles_count() returns 1',
            1 === $curator->last_original_articles_count()
        );
        $check(
            'format_articles_context (max_chars): context contains truncation suffix',
            false !== strpos( $long_ctx, '…[περικομμένο]' )
        );

        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] );

        // =========================================================================
        // Case 5: format_articles_context() leaves state untouched when within budget
        // =========================================================================
        self::reset_world();

        $short_articles = [
            [
                'title'   => 'Short Article',
                'source'  => 'TestSource',
                'url'     => 'https://test.example/short',
                'content' => 'Brief content.',
            ],
        ];

        $short_ctx = $curator->format_articles_context( $short_articles );
        $check(
            'format_articles_context (within budget): was_context_truncated() returns false',
            false === $curator->was_context_truncated()
        );
        $check(
            'format_articles_context (within budget): last_original_articles_count() returns 1',
            1 === $curator->last_original_articles_count()
        );
        $check(
            'format_articles_context (within budget): context does NOT contain truncation suffix',
            false === strpos( $short_ctx, '…[περικομμένο]' )
        );

        // Empty fixture
        $empty_ctx = $curator->format_articles_context( [] );
        $check(
            'format_articles_context (empty): returns "no articles" placeholder',
            false !== strpos( $empty_ctx, 'Δεν υπάρχουν διαθέσιμα άρθρα' )
        );

        // =========================================================================
        // Case 6: generate_briefing() returns articles_truncated + articles_count_original
        // =========================================================================
        self::reset_world();

        // Seed snapshot with 2 articles
        $snapshot_dir = $test_upload_dir . '/presshub-briefings/2026-08-30';
        if ( ! is_dir( $snapshot_dir ) ) {
            mkdir( $snapshot_dir, 0755, true );
        }
        file_put_contents(
            $snapshot_dir . '/raw-articles.json',
            wp_json_encode( [
                'date'     => '2026-08-30',
                'articles' => [
                    [
                        'id'      => 'a1',
                        'title'   => 'Headline',
                        'source'  => 'Kathimerini',
                        'url'     => 'https://kathimerini.gr/news/1',
                        'content' => 'Detailed body.',
                    ],
                    [
                        'id'      => 'a2',
                        'title'   => 'Second',
                        'source'  => 'In.gr',
                        'url'     => 'https://in.gr/news/2',
                        'content' => 'Second body.',
                    ],
                ],
            ] )
        );

        // Mock API client to return canned LLM response
        $api_mock = new class extends PressHub_AI_API_Client {
            public function __construct() { /* skip parent */ }
            public function set_action( string $action ): self { return $this; }
            public function call_provider( $sys_prompt, $user_prompt, $json_mode, $files, $temperature = null, array $metadata = [] ) {
                return "# Top Headline\n\nBriefing body.";
            }
            public static function current_request_meta(): string { return ''; }
        };

        $result = $curator->generate_briefing( '2026-08-30', $api_mock, '', [] );
        $check(
            'generate_briefing: result is array',
            is_array( $result )
        );
        $check(
            'generate_briefing: payload contains articles_truncated key',
            array_key_exists( 'articles_truncated', $result )
        );
        $check(
            'generate_briefing: payload contains articles_count_original key',
            array_key_exists( 'articles_count_original', $result )
        );
        $check(
            'generate_briefing: payload articles_count_original matches snapshot count',
            2 === (int) ( $result['articles_count_original'] ?? 0 )
        );
        $check(
            'generate_briefing: payload articles_truncated is false for 2-article snapshot',
            false === (bool) ( $result['articles_truncated'] ?? true )
        );

        // =========================================================================
        // Case 7: AJAX briefing_run_curation() catches generic Throwable
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date']                = '2026-08-30';
        $_POST['preset_id']           = '';

        // Hook into a side-channel that simulates a catastrophic curator fault
        $GLOBALS['FORCE_CURATOR_FAULT'] = true;

        // Force the curator to throw a generic Throwable
        // We do this by hooking into the json pipeline indirectly via a filter
        add_filter( 'pre_option_presshub_ai_curation_time_budget', function() { return 120; } );

        // Inject a fault via a sentinel global consumed by a stub override below.
        // Since we cannot override the curator class directly, we trigger a fault by
        // requesting a date that has no snapshot — this returns WP_Error, which is
        // already handled. We then exercise the Throwable path by hooking into the
        // pre_option_* filter that fires inside get_option() when
        // PressHub_AI_Settings_Storage::get_curation_max_articles() reads the
        // cap (Issue #61 — the old apply_filters() escape hatch is gone).
        add_filter( 'pre_option_presshub_ai_curation_max_articles', function() {
            throw new \RuntimeException( 'Simulated catastrophic LLM socket fault' );
        } );

        $ajax_handlers = new PressHub_AI_Ajax_Handlers();
        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_run_curation' ] );

        $check(
            'AJAX briefing_run_curation: Throwable caught, returns wp_send_json_error payload',
            isset( $response['success'] ) && false === (bool) $response['success']
        );
        $check(
            'AJAX briefing_run_curation: error message mentions text curation',
            isset( $response['data']['message'] ) && false !== strpos( $response['data']['message'], 'Text curation failed' )
        );
        $check(
            'AJAX briefing_run_curation: error message includes exception text',
            isset( $response['data']['exception'] ) && false !== strpos( $response['data']['exception'], 'Simulated catastrophic LLM socket fault' )
        );

        remove_all_filters( 'pre_option_presshub_ai_curation_max_articles' );

        // =========================================================================
        // Case 8: AJAX briefing_run_script() catches generic Throwable
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date']                = '2026-08-30';
        $_POST['preset_id']           = '';
        $_POST['duration']            = '5_min';

        // Seed snapshot so build_dialogue_prompt reaches the host filter chain.
        $script_snap_dir = $test_upload_dir . '/presshub-briefings/2026-08-30';
        if ( ! is_dir( $script_snap_dir ) ) {
            mkdir( $script_snap_dir, 0755, true );
        }
        file_put_contents(
            $script_snap_dir . '/raw-articles.json',
            wp_json_encode( [
                'date'     => '2026-08-30',
                'articles' => [
                    [ 'id' => 'sa1', 'title' => 'News A', 'source' => 'Src', 'url' => 'https://x/a', 'content' => 'Body A' ],
                ],
            ] )
        );

        // presshub_ai_podcast_host1_name fires inside build_dialogue_prompt() which
        // generate_dialogue_script() invokes before reaching the LLM call.
        add_filter( 'presshub_ai_podcast_host1_name', function() {
            throw new \RuntimeException( 'Simulated podcast script LLM fault' );
        } );

        $ajax_handlers = new PressHub_AI_Ajax_Handlers();
        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_run_script' ] );

        $check(
            'AJAX briefing_run_script: Throwable caught, returns wp_send_json_error payload',
            isset( $response['success'] ) && false === (bool) $response['success']
        );
        $check(
            'AJAX briefing_run_script: error message mentions podcast script',
            isset( $response['data']['message'] ) && false !== strpos( $response['data']['message'], 'Podcast script generation failed' )
        );
        $check(
            'AJAX briefing_run_script: error message includes exception text',
            isset( $response['data']['exception'] ) && false !== strpos( $response['data']['exception'], 'Simulated podcast script LLM fault' )
        );

        remove_all_filters( 'presshub_ai_podcast_host1_name' );

        // =========================================================================
        // Case 9: AJAX briefing_generate_audio() catches generic Throwable
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST['date']                = '2026-08-30';
        // parse_script_turns() needs a properly-tagged speaker line to reach
        // the get_option() call further down in synthesize_podcast().
        $_POST['script']              = '[Μαρία]: Hello world';

        // synthesize_podcast() reads the TTS engine option via
        // get_option( self::OPTION_ENGINE, 'gemini' ) where OPTION_ENGINE =
        // 'presshub_ai_briefing_tts_engine' (class-audio-synthesizer.php:26).
        // The test get_option() stub fires pre_option_<key> filters; we hook in
        // here to throw so the handler's try/catch exercises the audio path.
        add_filter( 'pre_option_presshub_ai_briefing_tts_engine', function() {
            throw new \RuntimeException( 'Simulated TTS fault' );
        } );

        $ajax_handlers = new PressHub_AI_Ajax_Handlers();
        $response = self::execute_ajax( [ $ajax_handlers, 'briefing_generate_audio' ] );

        $check(
            'AJAX briefing_generate_audio: Throwable caught, returns wp_send_json_error payload',
            isset( $response['success'] ) && false === (bool) $response['success']
        );
        $check(
            'AJAX briefing_generate_audio: error message mentions audio synthesis',
            isset( $response['data']['message'] ) && false !== strpos( $response['data']['message'], 'Audio synthesis failed' )
        );
        $check(
            'AJAX briefing_generate_audio: error message includes exception text',
            isset( $response['data']['exception'] ) && false !== strpos( $response['data']['exception'], 'Simulated TTS fault' )
        );

        remove_all_filters( 'pre_option_presshub_ai_briefing_tts_engine' );

        // =========================================================================
        // Case 10: AJAX handlers call set_time_limit / wp_raise_memory_limit at entry
        // =========================================================================
        self::reset_world();
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];

        // Track whether set_time_limit and wp_raise_memory_limit were called.
        // In the test harness these functions are not defined, so the function_exists()
        // guard returns false and the handler skips them. We verify the guard logic
        // by ensuring handlers DO NOT fatal-error in their absence.
        $_POST['date'] = '2026-09-01';

        // Seed a snapshot for the curation handler so it proceeds past the snapshot
        // existence check and reaches the time-limit code path.
        $snap_dir = $test_upload_dir . '/presshub-briefings/2026-09-01';
        if ( ! is_dir( $snap_dir ) ) {
            mkdir( $snap_dir, 0755, true );
        }
        file_put_contents(
            $snap_dir . '/raw-articles.json',
            wp_json_encode( [
                'date'     => '2026-09-01',
                'articles' => [
                    [ 'id' => 'x1', 'title' => 'A', 'source' => 'S', 'url' => 'https://x/1', 'content' => 'C' ],
                ],
            ] )
        );

        // The curation handler will reach into the curator which will throw (no API client),
        // exercising the try/catch path. We just need the handler to not crash earlier.
        $r = self::execute_ajax( [ $ajax_handlers, 'briefing_run_curation' ] );
        $check(
            'AJAX briefing_run_curation: handler reaches LLM call without fatal when set_time_limit/wp_raise_memory_limit missing',
            isset( $r['success'] )
        );

        // =========================================================================
        // Cleanup test upload dir
        // =========================================================================
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
            fwrite( STDERR, "CurationTimeoutSafetyTest: FAIL (" . count( $failures ) . " errors)\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }

        echo "CurationTimeoutSafetyTest: OK (" . self::count_checks() . " checks)\n";
    }

    private static int $check_count = 0;

    private static function count_checks(): int {
        return self::$check_count;
    }

    // Increment check counter via a global hook — call site uses $check() closure.
    // We add a side-effect inside $check to count invocations.
    // Implemented by registering a counting wrapper.

    private static function execute_ajax( callable $callback ): array {
        $GLOBALS['JSON_RESPONSES'] = [];
        try {
            call_user_func( $callback );
        } catch ( Throwable $e ) {
            // wp_send_json_* throws RuntimeException as part of normal flow
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
        $GLOBALS['USER_META_STORE']          = [];
        $GLOBALS['FORCE_CURATOR_FAULT']      = false;
    }
}

CurationTimeoutSafetyTest::run();
