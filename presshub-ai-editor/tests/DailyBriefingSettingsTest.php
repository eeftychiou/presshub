<?php
/**
 * TDD Unit Tests for Daily Briefing & AI Podcast Settings and WP-Cron Scheduling.
 *
 * Covers:
 *   - Registration of 'presshub_ai_briefing' section and all Daily Briefing options.
 *   - Sanitization callbacks for all briefing options:
 *       * presshub_ai_briefing_sources (URLs sanitization, whitespace stripping)
 *       * presshub_ai_briefing_harvest_time (HH:MM format, default 06:30)
 *       * presshub_ai_briefing_generation_time (HH:MM format, default 07:15)
 *       * presshub_ai_briefing_text_preset & presshub_ai_briefing_podcast_preset
 *       * presshub_ai_briefing_target_duration (3_min, 5_min, 10_min)
 *       * presshub_ai_briefing_voice_female & presshub_ai_briefing_voice_male
 *       * presshub_ai_briefing_voice_speed (0.85 - 1.25) & voice_pitch (-4.0 - 4.0)
 *       * presshub_ai_briefing_text_status & presshub_ai_briefing_podcast_status (pending vs publish)
 *       * presshub_ai_briefing_text_prompt & presshub_ai_briefing_podcast_prompt
 *       * presshub_ai_briefing_text_category & presshub_ai_briefing_podcast_category
 *   - WP-Cron scheduling helpers:
 *       * Schedules presshub_daily_news_harvest event daily at harvest_time.
 *       * Schedules presshub_daily_news_generate event daily at generation_time.
 *       * Re-schedules events on option updates or activation.
 *       * Cron execution handlers.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

// Skip update checker in test harness
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once __DIR__ . '/../presshub-ai-editor.php';


class DailyBriefingSettingsTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: Daily Briefing section and fields registration ---
        self::reset_world();
        $settings = new PressHub_AI_Settings();
        $settings->register_settings();

        $sections    = $GLOBALS['SECTIONS']['presshub-ai'] ?? [];
        $section_ids = array_column( $sections, 'id' );
        if ( ! in_array( 'presshub_ai_briefing', $section_ids, true ) ) {
            $failures[] = 'presshub_ai_briefing section must be registered in presshub-ai page; got: ' . implode( ', ', $section_ids );
        }

        $fields = $GLOBALS['FIELDS']['presshub-ai']['presshub_ai_briefing'] ?? [];
        $field_ids = array_column( $fields, 'id' );
        $expected_fields = [
            'presshub_ai_briefing_sources',
            'presshub_ai_briefing_tts_engine',
            'presshub_ai_briefing_tts_model',
            'presshub_ai_briefing_harvest_time',
            'presshub_ai_briefing_generation_time',
            'presshub_ai_briefing_text_preset',
            'presshub_ai_briefing_podcast_preset',
            'presshub_ai_briefing_target_duration',
            'presshub_ai_briefing_host_female',
            'presshub_ai_briefing_host_male',
            'presshub_ai_briefing_voice_female',
            'presshub_ai_briefing_voice_male',
            'presshub_ai_briefing_voice_speed',
            'presshub_ai_briefing_voice_pitch',
            'presshub_ai_briefing_text_category',
            'presshub_ai_briefing_podcast_category',
            'presshub_ai_briefing_text_status',
            'presshub_ai_briefing_podcast_status',
            'presshub_ai_briefing_text_prompt',
            'presshub_ai_briefing_podcast_prompt',
        ];

        foreach ( $expected_fields as $expected_field ) {
            if ( ! in_array( $expected_field, $field_ids, true ) ) {
                $failures[] = "Field {$expected_field} is missing from presshub_ai_briefing section.";
            }
        }

        $cbs = $GLOBALS['SANITIZE_CALLBACKS'] ?? [];

        // --- Case 2: Sanitization of presshub_ai_briefing_sources ---
        self::reset_options();
        $raw_sources_str = " https://www.kathimerini.gr \n\n  https://www.tovima.gr\njavascript:alert(1)\nnot-a-url\nhttps://www.kathimerini.gr ";
        $sanitized_str = self::sanitize( $cbs, 'presshub_ai_briefing_sources', $raw_sources_str );
        if ( false === strpos( $sanitized_str, 'https://www.kathimerini.gr' ) || false === strpos( $sanitized_str, 'https://www.tovima.gr' ) ) {
            $failures[] = 'presshub_ai_briefing_sources should contain valid URLs; got: ' . var_export( $sanitized_str, true );
        }
        if ( false !== strpos( $sanitized_str, 'javascript' ) || false !== strpos( $sanitized_str, 'not-a-url' ) ) {
            $failures[] = 'presshub_ai_briefing_sources must strip invalid and non-http URLs; got: ' . var_export( $sanitized_str, true );
        }
        // Deduplication check
        $lines = array_filter( explode( "\n", trim( $sanitized_str ) ) );
        if ( count( $lines ) !== 2 ) {
            $failures[] = 'presshub_ai_briefing_sources should deduplicate URLs; got count ' . count( $lines );
        }

        // Array input handling
        $raw_sources_arr = [ ' https://www.naftemporiki.gr ', 'https://www.in.gr', 'bad-entry', 'https://www.naftemporiki.gr' ];
        $sanitized_arr = self::sanitize( $cbs, 'presshub_ai_briefing_sources', $raw_sources_arr );
        if ( ! is_array( $sanitized_arr ) || ! in_array( 'https://www.naftemporiki.gr', $sanitized_arr, true ) || count( $sanitized_arr ) !== 2 ) {
            $failures[] = 'presshub_ai_briefing_sources array input should return clean array of 2 unique URLs; got: ' . var_export( $sanitized_arr, true );
        }

        // --- Case 3: Sanitization of harvest_time & generation_time & tts_engine ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_engine', 'gemini' ) !== 'gemini' ) {
            $failures[] = 'tts_engine gemini should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_engine', 'google_cloud' ) !== 'google_cloud' ) {
            $failures[] = 'tts_engine google_cloud should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_engine', 'invalid-engine' ) !== 'gemini' ) {
            $failures[] = 'invalid tts_engine should fall back to gemini.';
        }

        if ( self::sanitize( $cbs, 'presshub_ai_briefing_harvest_time', '06:30' ) !== '06:30' ) {
            $failures[] = 'harvest_time 06:30 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_harvest_time', '6:30' ) !== '06:30' ) {
            $failures[] = 'harvest_time 6:30 should normalize to 06:30.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_harvest_time', '25:00' ) !== '06:30' ) {
            $failures[] = 'invalid harvest_time 25:00 should fall back to 06:30.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_harvest_time', 'invalid' ) !== '06:30' ) {
            $failures[] = 'non-time harvest_time should fall back to 06:30.';
        }

        if ( self::sanitize( $cbs, 'presshub_ai_briefing_generation_time', '07:15' ) !== '07:15' ) {
            $failures[] = 'generation_time 07:15 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_generation_time', '7:15' ) !== '07:15' ) {
            $failures[] = 'generation_time 7:15 should normalize to 07:15.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_generation_time', '12:99' ) !== '07:15' ) {
            $failures[] = 'invalid generation_time 12:99 should fall back to 07:15.';
        }

        // --- Case 4: Sanitization of presets ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_text_preset', 'wire-style' ) !== 'wire-style' ) {
            $failures[] = 'text preset wire-style should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_podcast_preset', ' <b>custom-podcast</b> ' ) !== 'custom-podcast' ) {
            $failures[] = 'podcast preset with HTML/spaces should sanitize to custom-podcast.';
        }

        // --- Case 5: Sanitization of target duration ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_target_duration', '3_min' ) !== '3_min' ) {
            $failures[] = 'duration 3_min should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_target_duration', '10_min' ) !== '10_min' ) {
            $failures[] = 'duration 10_min should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_target_duration', '60_min' ) !== '5_min' ) {
            $failures[] = 'invalid duration 60_min should fall back to 5_min.';
        }

        // --- Case 6: Sanitization of Greek voice models (Gemini + Google Cloud) ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_female', 'Aoede' ) !== 'Aoede' ) {
            $failures[] = 'female voice Aoede should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_female', 'el-GR-Wavenet-A' ) !== 'el-GR-Wavenet-A' ) {
            $failures[] = 'female voice el-GR-Wavenet-A should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_female', 'en-US-Neural2-A' ) !== 'Aoede' ) {
            $failures[] = 'invalid female voice should fall back to Aoede.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_male', 'Fenrir' ) !== 'Fenrir' ) {
            $failures[] = 'male voice Fenrir should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_male', 'el-GR-Chirp3-HD-Achird' ) !== 'el-GR-Chirp3-HD-Achird' ) {
            $failures[] = 'male voice el-GR-Chirp3-HD-Achird should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_male', 'unknown' ) !== 'Fenrir' ) {
            $failures[] = 'invalid male voice should fall back to Fenrir.';
        }

        // --- Case 7: Sanitization of voice speed & pitch clamps ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_speed', '1.10' ) !== 1.1 ) {
            $failures[] = 'speed 1.1 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_speed', '0.5' ) !== 0.85 ) {
            $failures[] = 'speed 0.5 (below min 0.85) should clamp to 0.85; got: ' . self::sanitize( $cbs, 'presshub_ai_briefing_voice_speed', '0.5' );
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_speed', '2.0' ) !== 1.25 ) {
            $failures[] = 'speed 2.0 (above max 1.25) should clamp to 1.25; got: ' . self::sanitize( $cbs, 'presshub_ai_briefing_voice_speed', '2.0' );
        }

        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_pitch', '2.0' ) !== 2.0 ) {
            $failures[] = 'pitch 2.0 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_pitch', '-10.0' ) !== -4.0 ) {
            $failures[] = 'pitch -10.0 (below min -4.0) should clamp to -4.0; got: ' . self::sanitize( $cbs, 'presshub_ai_briefing_voice_pitch', '-10.0' );
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_pitch', '8.5' ) !== 4.0 ) {
            $failures[] = 'pitch 8.5 (above max 4.0) should clamp to 4.0; got: ' . self::sanitize( $cbs, 'presshub_ai_briefing_voice_pitch', '8.5' );
        }

        // --- Case 8: Sanitization of post status & category ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_text_status', 'publish' ) !== 'publish' ) {
            $failures[] = 'text status publish should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_podcast_status', 'draft' ) !== 'draft' ) {
            $failures[] = 'podcast status draft should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_text_status', 'archived' ) !== 'pending' ) {
            $failures[] = 'invalid text status should fall back to pending.';
        }

        if ( self::sanitize( $cbs, 'presshub_ai_briefing_text_category', '42' ) !== 42 ) {
            $failures[] = 'category 42 should sanitize to integer 42.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_podcast_category', '-5' ) !== 0 ) {
            $failures[] = 'negative category should clamp to 0.';
        }

        // --- Case 9: Sanitization of prompt editors ---
        self::reset_options();
        $custom_prompt = "Custom system prompt for {date}\nSources: {sources_list}";
        $sanitized_prompt = self::sanitize( $cbs, 'presshub_ai_briefing_text_prompt', $custom_prompt );
        if ( $sanitized_prompt !== $custom_prompt ) {
            $failures[] = 'prompt editor should preserve placeholders and newlines; got: ' . var_export( $sanitized_prompt, true );
        }

        // --- Case 10: WP-Cron Timestamp calculation helper ---
        $fixed_now = strtotime( '2026-08-26 05:00:00' );
        $GLOBALS['TIME_NOW'] = $fixed_now;

        $target_same_day = presshub_ai_get_cron_timestamp( '06:30', $fixed_now );
        $expected_same_day = strtotime( '2026-08-26 06:30:00' );
        if ( $target_same_day !== $expected_same_day ) {
            $failures[] = "Timestamp for 06:30 when now is 05:00 should be today at 06:30 ({$expected_same_day}); got: {$target_same_day}";
        }

        // If time has passed today (now is 08:00, target 06:30), target should be tomorrow at 06:30
        $past_now = strtotime( '2026-08-26 08:00:00' );
        $target_next_day = presshub_ai_get_cron_timestamp( '06:30', $past_now );
        $expected_next_day = strtotime( '2026-08-27 06:30:00' );
        if ( $target_next_day !== $expected_next_day ) {
            $failures[] = "Timestamp for 06:30 when now is 08:00 should be tomorrow at 06:30 ({$expected_next_day}); got: {$target_next_day}";
        }

        // --- Case 11: WP-Cron Dual Scheduling Helper ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_harvest_time']    = '06:30';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_generation_time'] = '07:15';
        $GLOBALS['TIME_NOW'] = strtotime( '2026-08-26 04:00:00' );

        presshub_ai_schedule_briefing_crons();

        $recurring = $GLOBALS['RECURRING_EVENTS'] ?? [];
        $hooks_scheduled = array_column( $recurring, 'hook' );

        if ( ! in_array( 'presshub_daily_news_harvest', $hooks_scheduled, true ) ) {
            $failures[] = 'presshub_daily_news_harvest must be scheduled as recurring event; got: ' . json_encode( $recurring );
        }
        if ( ! in_array( 'presshub_daily_news_generate', $hooks_scheduled, true ) ) {
            $failures[] = 'presshub_daily_news_generate must be scheduled as recurring event; got: ' . json_encode( $recurring );
        }

        // Verify recurrence is daily
        foreach ( $recurring as $event ) {
            if ( in_array( $event['hook'], [ 'presshub_daily_news_harvest', 'presshub_daily_news_generate' ], true ) ) {
                if ( $event['recurrence'] !== 'daily' ) {
                    $failures[] = "Event {$event['hook']} recurrence should be 'daily'; got: {$event['recurrence']}";
                }
            }
        }

        // --- Case 12: Rescheduling when options update ---
        self::reset_world();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_harvest_time']    = '06:30';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_generation_time'] = '07:15';
        $GLOBALS['TIME_NOW'] = strtotime( '2026-08-26 04:00:00' );

        presshub_ai_schedule_briefing_crons();

        // Simulate changing harvest time
        $GLOBALS['CLEARED_HOOKS'] = [];
        $GLOBALS['RECURRING_EVENTS'] = [];
        update_option( 'presshub_ai_briefing_harvest_time', '05:45' );

        $cleared_hooks = array_column( $GLOBALS['CLEARED_HOOKS'] ?? [], 'hook' );
        if ( ! in_array( 'presshub_daily_news_harvest', $cleared_hooks, true ) ) {
            $failures[] = 'Updating harvest time must clear and reschedule presshub_daily_news_harvest.';
        }

        // --- Case 13: Cron Execution Hooks Registration ---
        if ( ! function_exists( 'presshub_ai_execute_harvest_cron' ) ) {
            $failures[] = 'presshub_ai_execute_harvest_cron function must exist.';
        }
        if ( ! function_exists( 'presshub_ai_execute_generation_cron' ) ) {
            $failures[] = 'presshub_ai_execute_generation_cron function must exist.';
        }

        if ( $failures ) {
            fwrite( STDERR, "DailyBriefingSettingsTest: FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "DailyBriefingSettingsTest: OK\n";
    }

    private static function sanitize( array $cbs, string $option, $value ) {
        if ( empty( $cbs[ $option ] ) || ! is_callable( $cbs[ $option ] ) ) {
            throw new RuntimeException( "No callable sanitize callback for option: {$option}" );
        }
        return call_user_func( $cbs[ $option ], $value );
    }

    private static function reset_options(): void {
        $GLOBALS['OPTIONS_STORE'] = [];
    }

    private static function reset_world(): void {
        $GLOBALS['OPTIONS_STORE']            = [];
        $GLOBALS['CURRENT_USER_CAPS']        = [ 'manage_options' ];
        $GLOBALS['CURRENT_USER_ID']          = 1;
        $GLOBALS['REGISTERED_SETTINGS']      = [];
        $GLOBALS['SANITIZE_CALLBACKS']       = [];
        $GLOBALS['SECTIONS']                 = [];
        $GLOBALS['FIELDS']                   = [];
        $GLOBALS['RENDERED_SECTIONS']        = [];
        $GLOBALS['RENDERED_SETTINGS_FIELDS'] = [];
        $GLOBALS['RECURRING_EVENTS']         = [];
        $GLOBALS['CLEARED_HOOKS']            = [];
        $GLOBALS['NEXT_SCHEDULED']           = [];
        $GLOBALS['ACTIONS']                  = [
            'update_option_presshub_ai_briefing_harvest_time'    => [ 'presshub_ai_schedule_briefing_crons' ],
            'update_option_presshub_ai_briefing_generation_time' => [ 'presshub_ai_schedule_briefing_crons' ],
            'presshub_daily_news_harvest'                        => [ 'presshub_ai_execute_harvest_cron' ],
            'presshub_daily_news_generate'                       => [ 'presshub_ai_execute_generation_cron' ],
        ];
    }

}

DailyBriefingSettingsTest::run();
