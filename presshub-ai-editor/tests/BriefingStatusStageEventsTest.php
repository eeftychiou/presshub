<?php
/**
 * TDD Unit Tests for Issue #79 — Daily Briefing Hub Stage Status Cards.
 *
 * Validates that:
 *   - PressHub_AI_Briefing_Admin::get_briefing_status() returns a
 *     `stage_events` sub-array with per-stage attempted_at / completed_at /
 *     status / duration_ms derived from `wp_presshub_ai_token_logs`.
 *   - get_briefing_status() surfaces the new curation source-context
 *     (type/count/preset), the new script lifecycle metadata
 *     (context_mode/source_post_id/attempted_at/completed_at), and the new
 *     audio stats block (duration_sec/filesize/sample_rate/format/engine/
 *     voices/host_count/split_by_topic/topic_count/attempted_at/completed_at).
 *   - PressHub_AI_Briefing_Admin::collect_stage_execution_events() prefers the
 *     most recent `success` log row, gracefully returns empty arrays when
 *     no logs exist, and respects a missing wpdb (legacy installs).
 *   - PressHub_AI_News_Curator::set_source_context / ::last_source_context /
 *     ::get_source_context round-trip correctly.
 *   - PressHub_AI_Podcast_Producer::save_script / ::get_script_meta persist
 *     and recover the lifecycle metadata JSON sidecar file.
 *   - PressHub_AI_Audio_Synthesizer::get_audio_meta falls back to empty
 *     defaults for legacy posts with no audio stats meta.
 *
 * @group issue-79
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once __DIR__ . '/../presshub-ai-editor.php';

class BriefingStatusStageEventsTest {

    public static function run(): void {
        $failures = [];

        // =========================================================================
        // Case 1: collect_stage_execution_events() with no logs returns the empty
        //         four-stage structure so consumers can rely on the shape.
        // =========================================================================
        self::reset_world();
        $events = PressHub_AI_Briefing_Admin::collect_stage_execution_events( '2026-09-02' );
        $expected_keys = [ 'harvest', 'curation', 'script', 'audio' ];
        foreach ( $expected_keys as $k ) {
            if ( ! isset( $events[ $k ] ) ) {
                $failures[] = "collect_stage_execution_events() must contain '{$k}' sub-key even when no logs exist.";
                continue;
            }
            foreach ( [ 'attempted_at', 'completed_at', 'status', 'duration_ms', 'metric_units', 'sources_count', 'error_message' ] as $f ) {
                if ( ! array_key_exists( $f, $events[ $k ] ) ) {
                    $failures[] = "stage '{$k}' must have field '{$f}'.";
                }
            }
            if ( '' !== $events[ $k ]['attempted_at'] ) {
                $failures[] = "empty '{$k}' must have empty attempted_at; got '" . $events[ $k ]['attempted_at'] . "'.";
            }
        }

        // =========================================================================
        // Case 2: Seed harvest + curation + script + audio rows, verify the
        //         aggregator exposes them via stage_events + get_briefing_status().
        // =========================================================================
        self::reset_world();
        global $wpdb;
        $wpdb->tables['wp_presshub_ai_token_logs'] = [];
        $wpdb->auto_increments['wp_presshub_ai_token_logs'] = 0;

        // Harvest (scrape_harvest): 12 articles, 18.5s, 3 sources. Add
        // metadata.started_at so we can verify the explicit-start path.
        PressHub_AI_Token_Logger::log_scrape_request(
            'scrape_harvest',
            3,
            12,
            18500,
            'success',
            null,
            [ 'sources_count' => 3, 'started_at' => '2026-09-02 07:00:12' ],
            1
        );

        // Curation (briefing_curation): 2300 tokens, 3.2s, success.
        PressHub_AI_Token_Logger::log_llm_request(
            'briefing_curation',
            'gemini',
            'gemini-2.5-flash',
            1500,
            800,
            3200,
            'success',
            null,
            [ 'started_at' => '2026-09-02 07:03:05' ],
            1
        );

        // Script (podcast_script): 780 chars / 4.5s.
        PressHub_AI_Token_Logger::log_tts_request(
            'podcast_script',
            'gemini',
            'gemini-2.5-flash',
            780,
            4500,
            'success',
            null,
            [ 'started_at' => '2026-09-02 07:05:00' ],
            1
        );

        // Audio (podcast_audio): 112s.
        PressHub_AI_Token_Logger::log_tts_request(
            'podcast_audio',
            'gemini',
            'Kore+Fenrir',
            4000,
            112000,
            'success',
            null,
            [ 'started_at' => '2026-09-02 07:06:00' ],
            1
        );

        $events = PressHub_AI_Briefing_Admin::collect_stage_execution_events( '2026-09-02' );
        if ( '' === $events['harvest']['attempted_at'] ) {
            $failures[] = 'harvest.attempted_at should reflect metadata.started_at (07:00:12); got empty.';
        } elseif ( false === strpos( $events['harvest']['attempted_at'], '07:00:12' ) ) {
            $failures[] = 'harvest.attempted_at should embed 07:00:12 from metadata.started_at; got ' . $events['harvest']['attempted_at'];
        }
        if ( 12 !== (int) $events['harvest']['metric_units'] ) {
            $failures[] = 'harvest.metric_units should be 12 articles; got ' . $events['harvest']['metric_units'];
        }
        if ( 3 !== (int) $events['harvest']['sources_count'] ) {
            $failures[] = 'harvest.sources_count should be 3 (from metadata); got ' . $events['harvest']['sources_count'];
        }
        if ( 3200 !== (int) $events['curation']['duration_ms'] ) {
            $failures[] = 'curation.duration_ms should be 3200ms; got ' . $events['curation']['duration_ms'];
        }
        if ( 112000 !== (int) $events['audio']['duration_ms'] ) {
            $failures[] = 'audio.duration_ms should be 112000ms; got ' . $events['audio']['duration_ms'];
        }
        if ( 'success' !== $events['audio']['status'] ) {
            $failures[] = 'audio.status should be success; got ' . $events['audio']['status'];
        }

        // =========================================================================
        // Case 3: collect_stage_execution_events() picks the most recent success
        //         if multiple rows exist for the same action_trigger.
        // =========================================================================
        self::reset_world();
        $wpdb->tables['wp_presshub_ai_token_logs'] = [];
        $wpdb->auto_increments['wp_presshub_ai_token_logs'] = 0;
        // First failed attempt.
        PressHub_AI_Token_Logger::log_tts_request(
            'podcast_audio',
            'gemini',
            'old_voice',
            1000,
            5000,
            'error',
            'synth timed out',
            [],
            1
        );
        // Second successful attempt with new started_at metadata.
        PressHub_AI_Token_Logger::log_tts_request(
            'podcast_audio',
            'gemini',
            'Kore',
            4000,
            100000,
            'success',
            null,
            [ 'started_at' => '2026-09-02 07:30:00' ],
            1
        );
        $events = PressHub_AI_Briefing_Admin::collect_stage_execution_events( '2026-09-02' );
        if ( 'success' !== $events['audio']['status'] ) {
            $failures[] = 'aggregator should prefer recent success row; got status=' . $events['audio']['status'];
        }
        if ( 100000 !== (int) $events['audio']['duration_ms'] ) {
            $failures[] = 'aggregator should pick the recent success row; got duration_ms=' . $events['audio']['duration_ms'];
        }
        if ( false === strpos( $events['audio']['attempted_at'], '07:30:00' ) ) {
            $failures[] = 'aggregator should preserve recent success started_at; got ' . $events['audio']['attempted_at'];
        }

        // =========================================================================
        // Case 4: PressHub_AI_News_Curator::set_source_context round-trips
        //         through last_source_context() and get_source_context().
        // =========================================================================
        self::reset_world();
        // Bootstrap required option storage stub.
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_briefing_text_title_prefix'      => 'Πρωινή Ενημέρωση: ',
            'presshub_ai_briefing_text_title_date_format' => 'd/m/Y',
        ];
        PressHub_AI_News_Curator::set_source_context( 'harvested_articles', 12, 'preset_default_greek_wire' );
        $stash = PressHub_AI_News_Curator::last_source_context();
        if ( 'harvested_articles' !== $stash['type'] ) {
            $failures[] = 'last_source_context().type should mirror input; got ' . $stash['type'];
        }
        if ( 12 !== (int) $stash['count'] ) {
            $failures[] = 'last_source_context().count should mirror input; got ' . $stash['count'];
        }
        if ( 'preset_default_greek_wire' !== $stash['preset'] ) {
            $failures[] = 'last_source_context().preset should mirror input; got ' . $stash['preset'];
        }

        // get_source_context() falls back gracefully for nonexistent post.
        $ctx = PressHub_AI_News_Curator::get_source_context( 0 );
        if ( 'curated_briefing' !== $ctx['type'] ) {
            $failures[] = 'get_source_context(0).type should default to curated_briefing; got ' . $ctx['type'];
        }

        // =========================================================================
        // Case 5: PressHub_AI_Podcast_Producer::save_script / ::get_script_meta
        //         persist and recover lifecycle meta sidecar.
        // =========================================================================
        self::reset_world();
        $producer = new PressHub_AI_Podcast_Producer();
        $date = '2026-09-02';
        $producer->save_script(
            $date,
            "[Μαρία]: Γεια σου κόσμε.\n[Νίκος]: Γεια σου Μαρία.",
            'curated_briefing',
            0,
            '2026-09-02 07:10:00'
        );
        $meta = $producer->get_script_meta( $date );
        if ( 'curated_briefing' !== $meta['context_mode'] ) {
            $failures[] = "get_script_meta().context_mode should be 'curated_briefing'; got " . $meta['context_mode'];
        }
        if ( '' === $meta['attempted_at'] ) {
            $failures[] = 'get_script_meta().attempted_at should be non-empty when save_script() populated it.';
        }
        if ( '' === $meta['completed_at'] ) {
            $failures[] = 'get_script_meta().completed_at should be auto-populated at save time.';
        }

        // Invalid context_mode is ignored.
        $producer->save_script( $date, '...', 'not_a_real_mode', 0, '2026-09-02 07:10:00' );
        $meta2 = $producer->get_script_meta( $date );
        if ( 'curated_briefing' !== $meta2['context_mode'] ) {
            $failures[] = "save_script() must reject invalid context_mode and retain prior value; got " . $meta2['context_mode'];
        }

        // No sidecar → defaults.
        $producer = new PressHub_AI_Podcast_Producer();
        $legacy_date = '2026-09-02-legacy'; // unique date with no prior file.
        $meta3 = $producer->get_script_meta( $legacy_date );
        if ( '' !== $meta3['context_mode'] ) {
            $failures[] = 'get_script_meta() should yield empty context_mode for legacy runs; got ' . $meta3['context_mode'];
        }
        if ( 0 !== (int) $meta3['source_post_id'] ) {
            $failures[] = 'get_script_meta() should yield 0 source_post_id for legacy runs; got ' . $meta3['source_post_id'];
        }

        // =========================================================================
        // Case 6: PressHub_AI_Audio_Synthesizer::get_audio_meta falls back to
        //         safe defaults for legacy/empty posts.
        // =========================================================================
        self::reset_world();
        $defaults = PressHub_AI_Audio_Synthesizer::get_audio_meta( 0 );
        $expected_audio_keys = [
            'duration_sec', 'filesize', 'sample_rate', 'format', 'engine',
            'female_voice', 'male_voice', 'tertiary_voice',
            'host_count', 'split_by_topic', 'topic_count',
            'attempted_at', 'completed_at',
        ];
        foreach ( $expected_audio_keys as $k ) {
            if ( ! array_key_exists( $k, $defaults ) ) {
                $failures[] = "get_audio_meta() default must contain '{$k}'.";
            }
        }
        if ( 0.0 !== (float) $defaults['duration_sec'] ) {
            $failures[] = 'get_audio_meta(0).duration_sec must default to 0.0; got ' . $defaults['duration_sec'];
        }

        // =========================================================================
        // Case 7: get_briefing_status() now contains the new keys.
        // =========================================================================
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        ( new PressHub_AI_News_Harvester() )->save_snapshot( '2026-09-02', [
            'date'         => '2026-09-02',
            'articles'     => [],
            'sources'      => [ 'https://www.example.gr' ],
            'blocked_sources' => [],
        ] );
        $admin = new PressHub_AI_Briefing_Admin();
        $status = $admin->get_briefing_status( '2026-09-02' );

        $required_keys = [
            'stage_events', 'text_source_context', 'script_meta', 'audio_meta',
            'active_source_labels', 'blocked_source_labels',
            'harvest_total_ms', 'curation_total_ms', 'script_total_ms', 'audio_total_ms',
        ];
        foreach ( $required_keys as $key ) {
            if ( ! array_key_exists( $key, $status ) ) {
                $failures[] = "get_briefing_status() must contain '{$key}'.";
            }
        }
        if ( ! is_array( $status['stage_events'] ) || ! isset( $status['stage_events']['harvest'] ) ) {
            $failures[] = "stage_events['harvest'] missing in status payload.";
        }
        if ( ! is_array( $status['audio_meta'] ) ) {
            $failures[] = 'audio_meta must be an array even when no audio post exists.';
        }

        // =========================================================================
        // Result.
        // =========================================================================
        if ( empty( $failures ) ) {
            echo 'BriefingStatusStageEventsTest: ALL TESTS PASSED! OK' . PHP_EOL;
            return;
        }
        echo 'BriefingStatusStageEventsTest: FAIL' . PHP_EOL;
        foreach ( $failures as $f ) {
            echo '  - ' . $f . PHP_EOL;
        }
        exit( 1 );
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
        global $wpdb;
        $wpdb->tables = [];
        $wpdb->queries = [];
        $wpdb->auto_increments = [];
        // Re-seed default option stubs that other tests rely on so curator
        // helpers don't blow up on Settings-First reads.
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_briefing_text_title_prefix'      => 'Πρωινή Ενημέρωση:',
            'presshub_ai_briefing_text_title_date_format' => 'd/m/Y',
        ];
    }
}

BriefingStatusStageEventsTest::run();
