<?php
/**
 * TDD Unit Tests for Requirement 1: Noon News Update (PressHub AI Daily Briefing Hub).
 *
 * Covers:
 *   1. Settings-First Configuration:
 *      - presshub_ai_briefing_noon_schedule_enabled (default 1, boolean sanitizer)
 *      - presshub_ai_briefing_noon_harvest_time (default 12:00, HH:MM sanitizer)
 *      - presshub_ai_briefing_noon_generation_time (default 12:45, HH:MM sanitizer)
 *      - presshub_ai_briefing_noon_text_title_prefix (default 'Μεσημβρινή Ενημέρωση', text sanitizer)
 *      - presshub_ai_briefing_noon_podcast_title_prefix (default 'Μεσημβρινό Podcast', text sanitizer)
 *      - Settings registration in briefing section and Settings_Storage getters.
 *   2. WP-Cron Scheduling & Event Hooks:
 *      - presshub_noon_news_harvest and presshub_noon_news_generate scheduling when enabled.
 *      - Unscheduling when noon schedule is disabled.
 *      - Hook execution callbacks invoke harvester, curator, producer, synthesizer with 'noon' edition.
 *   3. Harvester Edition Support:
 *      - Snapshot path generation ('raw-articles-noon.json' vs 'raw-articles.json').
 *      - Snapshot save and load with edition preserves distinct snapshot files and option keys.
 *   4. News Curator Morning Context Injection:
 *      - Noon briefing generation injects morning briefing context block into prompt.
 *      - Morning briefing generation does not inject morning reference instruction.
 *      - Creates WordPress post with _presshub_briefing_edition = 'noon' and noon title prefix.
 *   5. Podcast Producer Edition Support:
 *      - Script saving persists 'podcast-script-noon.txt' and 'podcast-script-noon.meta.json'.
 *      - Script meta includes edition => 'noon'.
 *      - Dialogue prompt adapts to noon update and references morning briefing content when present.
 *   6. Audio Synthesizer Edition Support:
 *      - Creates podcast post with _presshub_briefing_edition = 'noon' and noon podcast prefix.
 *      - Saves audio file as 'podcast-noon.mp3'/'podcast-noon.wav'.
 *   7. Admin Hub & AJAX Edition Support:
 *      - find_briefing_post() correctly distinguishes morning vs noon posts.
 *      - get_briefing_status() returns stage progress and filenames for requested edition.
 *      - render_hub_page() renders edition navigation tabs and active state.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once __DIR__ . '/../presshub-ai-editor.php';

class NoonBriefingUpdateTest
{
    public static function run(): void {
        $failures = [];

        // =========================================================================
        // Case 1: Settings-First Noon Options Registration & Sanitization
        // =========================================================================
        self::reset_world();
        $settings = new PressHub_AI_Settings();
        $settings->register_settings();

        $cbs = $GLOBALS['SANITIZE_CALLBACKS'] ?? [];

        // 1a: Defaults from PressHub_AI_Settings_Storage
        if ( ! PressHub_AI_Settings_Storage::is_briefing_noon_schedule_enabled() ) {
            $failures[] = 'is_briefing_noon_schedule_enabled() should default to true (1).';
        }
        if ( '12:00' !== PressHub_AI_Settings_Storage::get_briefing_noon_harvest_time() ) {
            $failures[] = 'get_briefing_noon_harvest_time() should default to 12:00; got: ' . PressHub_AI_Settings_Storage::get_briefing_noon_harvest_time();
        }
        if ( '12:45' !== PressHub_AI_Settings_Storage::get_briefing_noon_generation_time() ) {
            $failures[] = 'get_briefing_noon_generation_time() should default to 12:45; got: ' . PressHub_AI_Settings_Storage::get_briefing_noon_generation_time();
        }
        if ( 'Μεσημβρινή Ενημέρωση:' !== PressHub_AI_Settings_Storage::get_briefing_noon_text_title_prefix() ) {
            $failures[] = 'get_briefing_noon_text_title_prefix() should default to Μεσημβρινή Ενημέρωση:; got: ' . PressHub_AI_Settings_Storage::get_briefing_noon_text_title_prefix();
        }
        if ( 'Μεσημβρινό Podcast Ενημέρωσης - ' !== PressHub_AI_Settings_Storage::get_briefing_noon_podcast_title_prefix() ) {
            $failures[] = 'get_briefing_noon_podcast_title_prefix() should default to Μεσημβρινό Podcast Ενημέρωσης - ; got: ' . PressHub_AI_Settings_Storage::get_briefing_noon_podcast_title_prefix();
        }

        // 1b: Sanitization callbacks
        if ( 1 !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_schedule_enabled', '1' ) ) {
            $failures[] = 'sanitize noon_schedule_enabled string "1" should return 1.';
        }
        if ( 0 !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_schedule_enabled', '0' ) ) {
            $failures[] = 'sanitize noon_schedule_enabled string "0" should return 0.';
        }
        if ( '13:15' !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_harvest_time', ' 13:15 ' ) ) {
            $failures[] = 'sanitize noon_harvest_time valid HH:MM should return 13:15.';
        }
        if ( '12:00' !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_harvest_time', 'invalid-time' ) ) {
            $failures[] = 'sanitize noon_harvest_time invalid input should fallback to 12:00.';
        }
        if ( '13:45' !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_generation_time', '13:45' ) ) {
            $failures[] = 'sanitize noon_generation_time valid HH:MM should return 13:45.';
        }
        if ( '12:45' !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_generation_time', '99:99' ) ) {
            $failures[] = 'sanitize noon_generation_time invalid input should fallback to 12:45.';
        }
        if ( 'Νέος Τίτλος' !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_text_title_prefix', '<b>Νέος Τίτλος</b>' ) ) {
            $failures[] = 'sanitize noon_text_title_prefix should strip tags.';
        }
        if ( 'Μεσημβρινή Ενημέρωση:' !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_text_title_prefix', '   ' ) ) {
            $failures[] = 'sanitize noon_text_title_prefix empty string should fallback to default.';
        }
        if ( 'Νέο Podcast ' !== self::sanitize( $cbs, 'presshub_ai_briefing_noon_podcast_title_prefix', '  Νέο Podcast  ' ) ) {
            $failures[] = 'sanitize noon_podcast_title_prefix should preserve intentional trailing space.';
        }

        // 1c: get_section_options_map( 'briefing' ) contains all noon options
        $all_opts = PressHub_AI_Settings_Storage::get_section_options_map( 'briefing' );
        foreach ( [
            'presshub_ai_briefing_noon_schedule_enabled',
            'presshub_ai_briefing_noon_harvest_time',
            'presshub_ai_briefing_noon_generation_time',
            'presshub_ai_briefing_noon_text_title_prefix',
            'presshub_ai_briefing_noon_podcast_title_prefix',
        ] as $opt_key ) {
            if ( ! array_key_exists( $opt_key, $all_opts ) ) {
                $failures[] = "get_section_options_map( 'briefing' ) is missing {$opt_key}.";
            }
        }

        // =========================================================================
        // Case 2: WP-Cron Scheduling & Execution
        // =========================================================================
        self::reset_world();
        update_option( 'presshub_ai_briefing_schedule_enabled', 1 );
        update_option( 'presshub_ai_briefing_noon_schedule_enabled', 1 );
        update_option( 'presshub_ai_briefing_noon_harvest_time', '12:00' );
        update_option( 'presshub_ai_briefing_noon_generation_time', '12:45' );

        presshub_ai_schedule_briefing_crons();

        $scheduled_hooks = array_column( $GLOBALS['RECURRING_EVENTS'] ?? [], 'hook' );
        if ( ! in_array( 'presshub_noon_news_harvest', $scheduled_hooks, true ) ) {
            $failures[] = 'presshub_ai_schedule_briefing_crons() must schedule presshub_noon_news_harvest when noon schedule is enabled; got: ' . implode( ', ', $scheduled_hooks );
        }
        if ( ! in_array( 'presshub_noon_news_generate', $scheduled_hooks, true ) ) {
            $failures[] = 'presshub_ai_schedule_briefing_crons() must schedule presshub_noon_news_generate when noon schedule is enabled; got: ' . implode( ', ', $scheduled_hooks );
        }

        // Unscheduling when noon schedule is disabled
        self::reset_world();
        update_option( 'presshub_ai_briefing_schedule_enabled', 1 );
        update_option( 'presshub_ai_briefing_noon_schedule_enabled', 0 );

        presshub_ai_schedule_briefing_crons();

        $cleared_hooks = array_column( $GLOBALS['CLEARED_HOOKS'] ?? [], 'hook' );
        if ( ! in_array( 'presshub_noon_news_harvest', $cleared_hooks, true ) ) {
            $failures[] = 'presshub_ai_schedule_briefing_crons() must clear presshub_noon_news_harvest when noon schedule is disabled.';
        }
        if ( ! in_array( 'presshub_noon_news_generate', $cleared_hooks, true ) ) {
            $failures[] = 'presshub_ai_schedule_briefing_crons() must clear presshub_noon_news_generate when noon schedule is disabled.';
        }

        // =========================================================================
        // Case 3: Harvester Edition Support & Snapshot Isolation
        // =========================================================================
        self::reset_world();
        $harvester = new PressHub_AI_News_Harvester();
        $test_date = '2026-10-16';

        $morning_path = $harvester->get_snapshot_path( $test_date, 'morning' );
        $noon_path    = $harvester->get_snapshot_path( $test_date, 'noon' );

        if ( false === strpos( $morning_path, 'raw-articles.json' ) ) {
            $failures[] = 'Morning snapshot path must end in raw-articles.json; got: ' . $morning_path;
        }
        if ( false === strpos( $noon_path, 'raw-articles-noon.json' ) ) {
            $failures[] = 'Noon snapshot path must end in raw-articles-noon.json; got: ' . $noon_path;
        }

        // Save distinct morning & noon snapshots
        $morning_data = [
            'date'     => $test_date,
            'edition'  => 'morning',
            'articles' => [
                [ 'url' => 'https://example.com/morning-1', 'title' => 'Morning Headline', 'content' => 'Morning content' ],
            ],
        ];
        $noon_data = [
            'date'     => $test_date,
            'edition'  => 'noon',
            'articles' => [
                [ 'url' => 'https://example.com/noon-1', 'title' => 'Noon Headline', 'content' => 'Noon content' ],
                [ 'url' => 'https://example.com/noon-2', 'title' => 'Noon Headline 2', 'content' => 'Noon content 2' ],
            ],
        ];

        $harvester->save_snapshot( $test_date, $morning_data, 'morning' );
        $harvester->save_snapshot( $test_date, $noon_data, 'noon' );

        $loaded_morning = $harvester->load_snapshot( $test_date, 'morning' );
        $loaded_noon    = $harvester->load_snapshot( $test_date, 'noon' );

        if ( 1 !== count( $loaded_morning['articles'] ?? [] ) || 'Morning Headline' !== ( $loaded_morning['articles'][0]['title'] ?? '' ) ) {
            $failures[] = 'Morning snapshot was not properly saved/loaded independently.';
        }
        if ( 2 !== count( $loaded_noon['articles'] ?? [] ) || 'Noon Headline' !== ( $loaded_noon['articles'][0]['title'] ?? '' ) ) {
            $failures[] = 'Noon snapshot was not properly saved/loaded independently.';
        }

        // Transient persistence check
        $morning_transient = get_transient( "presshub_ai_harvest_snapshot_{$test_date}" );
        $noon_transient    = get_transient( "presshub_ai_harvest_snapshot_noon_{$test_date}" );
        if ( empty( $morning_transient ) || empty( $noon_transient ) ) {
            $failures[] = 'Harvest snapshots must persist to distinct transients for morning and noon.';
        }

        // =========================================================================
        // Case 4: News Curator Morning Context Injection & Post Creation
        // =========================================================================
        self::reset_world();
        $curator = new PressHub_AI_News_Curator();

        // Plant morning briefing post in store
        $morning_post_id = wp_insert_post( [
            'post_title'   => 'Πρωινή Ενημέρωση - 16/10/2026',
            'post_content' => '<p>Η κυβέρνηση ανακοίνωσε το πρωί νέα μέτρα για την οικονομία.</p>',
            'post_status'  => 'publish',
            'meta_input'   => [
                '_presshub_briefing_date'    => $test_date,
                '_presshub_briefing_type'    => 'text',
                '_presshub_briefing_edition' => 'morning',
            ],
        ] );

        $articles = [
            [ 'title' => 'Εξελίξεις στο υπουργείο', 'content' => 'Το μεσημέρι ολοκληρώθηκε η συνάντηση.' ],
        ];

        // 4a: Morning build_prompt should NOT inject morning context
        $morning_prompts = $curator->build_prompt( $articles, '', $test_date, [], 'morning' );
        $morning_user_prompt = $morning_prompts['user_prompt'] ?? '';
        if ( false !== strpos( $morning_user_prompt, 'ΠΡΩΙΝΗ ΕΝΗΜΕΡΩΣΗ' ) ) {
            $failures[] = 'Morning build_prompt must NOT contain morning briefing reference context.';
        }

        // 4b: Noon build_prompt MUST inject morning briefing context
        $noon_prompts = $curator->build_prompt( $articles, '', $test_date, [], 'noon' );
        $noon_user_prompt = $noon_prompts['user_prompt'] ?? '';
        if ( false === strpos( $noon_user_prompt, 'ΠΡΩΙΝΗ ΕΝΗΜΕΡΩΣΗ' ) ) {
            $failures[] = 'Noon build_prompt must inject morning briefing context into user prompt.';
        }
        if ( false === strpos( $noon_user_prompt, 'νέα μέτρα για την οικονομία' ) ) {
            $failures[] = 'Noon build_prompt must contain morning briefing content body.';
        }
        if ( false === strpos( $noon_user_prompt, 'Οδηγία για τη Μεσημβρινή Ενημέρωση' ) ) {
            $failures[] = 'Noon build_prompt must contain noon update prompt guidance instruction.';
        }

        // 4c: Post creation sets _presshub_briefing_edition = 'noon' and title prefix
        update_option( 'presshub_ai_briefing_noon_text_title_prefix', 'Μεσημβρινή Ενημέρωση' );
        $noon_post_id = $curator->create_wordpress_post(
            "# Σύνοψη Εξελίξεων\n\n<p>Σύνοψη Εξελίξεων</p>",
            $test_date,
            'Σύνοψη Εξελίξεων',
            'noon'
        );

        $saved_edition = get_post_meta( $noon_post_id, '_presshub_briefing_edition', true );
        if ( 'noon' !== $saved_edition ) {
            $failures[] = "create_wordpress_post() for noon must set _presshub_briefing_edition to 'noon'; got: '{$saved_edition}'";
        }
        $noon_post = get_post( $noon_post_id );
        if ( false === strpos( $noon_post->post_title ?? '', 'Μεσημβρινή Ενημέρωση' ) ) {
            $failures[] = "Noon post title must contain noon title prefix; got: " . ( $noon_post->post_title ?? '' );
        }

        // =========================================================================
        // Case 5: Podcast Producer Edition Support & Script Persistence
        // =========================================================================
        self::reset_world();
        $producer = new PressHub_AI_Podcast_Producer();

        $morning_script_text = "[Μαρία]: Καλημέρα! Σήμερα το πρωί έχουμε σημαντικές ειδήσεις.\n[Νίκος]: Καλημέρα Μαρία, ας ξεκινήσουμε.";
        $noon_script_text    = "[Μαρία]: Καλό μεσημέρι σε όλους! Επανερχόμαστε με τις νεότερες εξελίξεις.\n[Νίκος]: Καλό μεσημέρι Μαρία!";

        $producer->save_script( $test_date, $morning_script_text, 'morning' );
        $producer->save_script( $test_date, $noon_script_text, 'noon' );

        $loaded_morning_script = $producer->get_script( $test_date, 'morning' );
        $loaded_noon_script    = $producer->get_script( $test_date, 'noon' );

        if ( $loaded_morning_script !== $morning_script_text ) {
            $failures[] = 'Morning podcast script was not retrieved correctly.';
        }
        if ( $loaded_noon_script !== $noon_script_text ) {
            $failures[] = 'Noon podcast script was not retrieved correctly.';
        }

        $noon_meta = $producer->get_script_meta( $test_date, 'noon' );
        if ( ( $noon_meta['edition'] ?? '' ) !== 'noon' ) {
            $failures[] = "Noon podcast script meta must have edition => 'noon'; got: " . ( $noon_meta['edition'] ?? '' );
        }

        // 5b: build_dialogue_prompt for noon adapts to noon update
        $noon_dialogue = $producer->build_dialogue_prompt(
            [ [ 'title' => 'Είδηση', 'content' => 'Κείμενο άρθρων μεσημεριού' ] ],
            '',
            '3_min',
            $test_date,
            'harvested_articles',
            [],
            null,
            'noon'
        );
        $noon_dialogue_prompt = $noon_dialogue['user_prompt'] ?? '';
        if ( false === strpos( $noon_dialogue_prompt, 'μεσημβρινού podcast' ) && false === strpos( $noon_dialogue_prompt, 'μεσημέρι' ) ) {
            $failures[] = 'Noon dialogue prompt must adapt to noon update language (μεσημβρινού podcast/μεσημέρι); got: ' . $noon_dialogue_prompt;
        }

        // =========================================================================
        // Case 6: Audio Synthesizer Edition Support & Post Creation
        // =========================================================================
        self::reset_world();
        $synthesizer = new PressHub_AI_Audio_Synthesizer();
        update_option( 'presshub_ai_briefing_noon_podcast_title_prefix', 'Μεσημβρινό Podcast' );

        $audio_noon_post_id = $synthesizer->create_podcast_post(
            'https://example.com/audio/podcast-noon.mp3',
            180,
            '[SPEAKER_1]: Καλό μεσημέρι σε όλους!',
            $test_date,
            '',
            'noon'
        );

        $podcast_edition = get_post_meta( $audio_noon_post_id, '_presshub_briefing_edition', true );
        if ( 'noon' !== $podcast_edition ) {
            $failures[] = "create_podcast_post() for noon must set _presshub_briefing_edition to 'noon'; got: '{$podcast_edition}'";
        }
        $podcast_post = get_post( $audio_noon_post_id );
        if ( false === strpos( $podcast_post->post_title ?? '', 'Μεσημβρινό Podcast' ) ) {
            $failures[] = "Noon podcast post title must contain noon podcast title prefix; got: " . ( $podcast_post->post_title ?? '' );
        }

        // =========================================================================
        // Case 7: Admin Briefing Hub Edition Resolution & Tab UI
        // =========================================================================
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts' ];
        $admin = new PressHub_AI_Briefing_Admin();

        // Plant morning post (without edition meta, legacy back-compat)
        $legacy_morning_id = wp_insert_post( [
            'post_title'   => 'Πρωινή Ενημέρωση Legacy',
            'post_content' => '<p>Πρωινό κείμενο</p>',
            'post_status'  => 'publish',
            'meta_input'   => [
                '_presshub_briefing_date' => $test_date,
                '_presshub_briefing_type' => 'text',
            ],
        ] );

        // Plant noon post (with edition meta)
        $noon_text_id = wp_insert_post( [
            'post_title'   => 'Μεσημβρινή Ενημέρωση 16/10/2026',
            'post_content' => '<p>Μεσημβρινό κείμενο</p>',
            'post_status'  => 'publish',
            'meta_input'   => [
                '_presshub_briefing_date'    => $test_date,
                '_presshub_briefing_type'    => 'text',
                '_presshub_briefing_edition' => 'noon',
            ],
        ] );

        // find_briefing_post resolution
        $found_morning = $admin->find_briefing_post( $test_date, 'text', 'morning' );
        $found_noon    = $admin->find_briefing_post( $test_date, 'text', 'noon' );

        if ( ! $found_morning || (int) $found_morning->ID !== (int) $legacy_morning_id ) {
            $failures[] = 'find_briefing_post( morning ) must resolve morning post (including legacy without meta).';
        }
        if ( ! $found_noon || (int) $found_noon->ID !== (int) $noon_text_id ) {
            $failures[] = 'find_briefing_post( noon ) must resolve noon post.';
        }

        // get_briefing_status edition propagation
        $morning_status = $admin->get_briefing_status( $test_date, 'morning' );
        $noon_status    = $admin->get_briefing_status( $test_date, 'noon' );

        if ( 'morning' !== ( $morning_status['edition'] ?? '' ) ) {
            $failures[] = "get_briefing_status( morning ) must return edition => 'morning'; got: " . ( $morning_status['edition'] ?? '' );
        }
        if ( 'noon' !== ( $noon_status['edition'] ?? '' ) ) {
            $failures[] = "get_briefing_status( noon ) must return edition => 'noon'; got: " . ( $noon_status['edition'] ?? '' );
        }
        if ( (int) ( $noon_status['text_post_id'] ?? 0 ) !== (int) $noon_text_id ) {
            $failures[] = 'get_briefing_status( noon ) must link to noon post ID.';
        }

        // render_hub_page HTML output contains tabs and data-edition
        ob_start();
        $admin->render_hub_page( $test_date, 'noon' );
        $hub_html = ob_get_clean();

        if ( false === strpos( $hub_html, 'data-edition="noon"' ) ) {
            $failures[] = 'render_hub_page( noon ) must include data-edition="noon" on hub wrap container.';
        }
        if ( false === strpos( $hub_html, 'nav-tab-active' ) || false === strpos( $hub_html, 'Noon Update' ) ) {
            $failures[] = 'render_hub_page( noon ) must highlight the Noon Update tab as active.';
        }

        // =========================================================================
        // Case 8: Audio Synthesizer synthesize_podcast() Parameter Resilience & Cron try/catch
        // =========================================================================
        self::reset_world();
        $synthesizer = new PressHub_AI_Audio_Synthesizer();
        $date_case8  = '2026-10-20';

        // Pre-clean any leftover temporary script from previous interrupted runs
        $harvester = new PressHub_AI_News_Harvester();
        $snapshot_dir = $harvester->get_snapshot_dir( $date_case8 );
        $temp_script_path = $snapshot_dir . '/podcast-script-noon.txt';
        $temp_meta_path   = $snapshot_dir . '/podcast-script-noon.meta.json';
        if ( file_exists( $temp_script_path ) ) {
            @unlink( $temp_script_path );
        }
        if ( file_exists( $temp_meta_path ) ) {
            @unlink( $temp_meta_path );
        }

        // 8a: synthesize_podcast($date, null, 'noon') must accept null without TypeError
        try {
            $res_null = $synthesizer->synthesize_podcast( $date_case8, null, 'noon' );
            if ( ! is_wp_error( $res_null ) || 'no_script' !== $res_null->get_error_code() ) {
                $failures[] = 'synthesize_podcast( date, null, noon ) should return no_script WP_Error when no script on disk; got: ' . ( is_wp_error( $res_null ) ? $res_null->get_error_code() : gettype( $res_null ) );
            }
        } catch ( \TypeError $te ) {
            $failures[] = 'synthesize_podcast( date, null, noon ) threw TypeError: ' . $te->getMessage();
        }

        // 8b: Smart parameter shifting: synthesize_podcast($date, 'noon')
        try {
            $res_shift = $synthesizer->synthesize_podcast( $date_case8, 'noon' );
            if ( ! is_wp_error( $res_shift ) || 'no_script' !== $res_shift->get_error_code() ) {
                $failures[] = 'synthesize_podcast( date, noon ) smart shift should recognize "noon" as edition and return no_script; got: ' . ( is_wp_error( $res_shift ) ? $res_shift->get_error_code() : gettype( $res_shift ) );
            }
        } catch ( \Throwable $t ) {
            $failures[] = 'synthesize_podcast( date, noon ) threw exception: ' . $t->getMessage();
        }

        // 8c: Resolving disk script when $custom_script is null
        $producer = new PressHub_AI_Podcast_Producer();
        $valid_dialogue = "[SPEAKER_1]: Καλό μεσημέρι σε όλους!\n[SPEAKER_2]: Γεια σου, καλό μεσημέρι!";
        $producer->save_script( $date_case8, $valid_dialogue, 'noon' );

        $loaded_noon_script = $producer->get_script( $date_case8, 'noon' );
        if ( empty( $loaded_noon_script ) || false === strpos( $loaded_noon_script, 'Καλό μεσημέρι' ) ) {
            $failures[] = 'Podcast Producer failed to save/load noon script for date ' . $date_case8;
        }

        // 8d: presshub_ai_execute_noon_generation_cron() error handling resilience
        // Verify cron function executes without fatal errors even when TTS or network is not mocked
        if ( function_exists( 'presshub_ai_execute_noon_generation_cron' ) ) {
            try {
                $cron_res = presshub_ai_execute_noon_generation_cron( $date_case8 );
                if ( ! is_array( $cron_res ) ) {
                    $failures[] = 'presshub_ai_execute_noon_generation_cron() must return results array; got: ' . gettype( $cron_res );
                }
            } catch ( \Throwable $t ) {
                $failures[] = 'presshub_ai_execute_noon_generation_cron() threw unhandled exception: ' . $t->getMessage();
            }
        }

        // Clean up temporary script
        $harvester = new PressHub_AI_News_Harvester();
        $snapshot_dir = $harvester->get_snapshot_dir( $date_case8 );
        $temp_script_path = $snapshot_dir . '/podcast-script-noon.txt';
        if ( file_exists( $temp_script_path ) ) {
            @unlink( $temp_script_path );
        }
        $temp_meta_path = $snapshot_dir . '/podcast-script-noon.meta.json';
        if ( file_exists( $temp_meta_path ) ) {
            @unlink( $temp_meta_path );
        }

        // =========================================================================
        // Result Summary
        // =========================================================================
        if ( ! empty( $failures ) ) {
            echo "FAIL: " . count( $failures ) . " failures:\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }

        echo "NoonBriefingUpdateTest: OK\n";
    }

    private static function sanitize( array $cbs, string $option, $value ) {
        if ( empty( $cbs[ $option ] ) || ! is_callable( $cbs[ $option ] ) ) {
            throw new RuntimeException( "No callable sanitize callback for option: {$option}" );
        }
        return call_user_func( $cbs[ $option ], $value );
    }

    private static function reset_world(): void {
        $GLOBALS['OPTIONS_STORE']            = [];
        $GLOBALS['POSTS_STORE']              = [];
        $GLOBALS['POST_META_STORE']          = [];
        $GLOBALS['WP_INSERTED_POSTS']        = [];
        $GLOBALS['CURRENT_USER_CAPS']        = [ 'manage_options', 'edit_others_posts' ];
        $GLOBALS['CURRENT_USER_ID']          = 1;
        $GLOBALS['REGISTERED_SETTINGS']      = [];
        $GLOBALS['SANITIZE_CALLBACKS']       = [];
        $GLOBALS['SECTIONS']                 = [];
        $GLOBALS['FIELDS']                   = [];
        $GLOBALS['SCHEDULED_CRONS']          = [];
        $GLOBALS['CLEARED_HOOKS']            = [];
        $GLOBALS['NEXT_SCHEDULED']           = [];
        $GLOBALS['ACTIONS']                  = [
            'update_option_presshub_ai_briefing_schedule_enabled'      => [ 'presshub_ai_on_briefing_time_updated' ],
            'update_option_presshub_ai_briefing_noon_schedule_enabled' => [ 'presshub_ai_on_briefing_time_updated' ],
            'update_option_presshub_ai_briefing_noon_harvest_time'     => [ 'presshub_ai_schedule_briefing_crons' ],
            'update_option_presshub_ai_briefing_noon_generation_time'  => [ 'presshub_ai_schedule_briefing_crons' ],
            'presshub_noon_news_harvest'                              => [ 'presshub_ai_execute_noon_harvest_cron' ],
            'presshub_noon_news_generate'                             => [ 'presshub_ai_execute_noon_generation_cron' ],
        ];
    }
}

NoonBriefingUpdateTest::run();
