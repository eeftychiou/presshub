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
            'presshub_ai_briefing_schedule_enabled',
            'presshub_ai_briefing_tts_engine',
            'presshub_ai_briefing_tts_api_key',
            'presshub_ai_briefing_tts_model',
            'presshub_ai_briefing_tts_timeout',
            'presshub_ai_briefing_harvest_time',
            'presshub_ai_briefing_generation_time',
            'presshub_ai_harvest_time_budget',
            'presshub_ai_curation_max_articles',
            'presshub_ai_curation_max_chars_per_article',
            'presshub_ai_briefing_text_preset',
            'presshub_ai_briefing_podcast_preset',
            'presshub_ai_briefing_target_duration',
            'presshub_ai_briefing_host_count',
            // Issue #90 — host-name fields (host_female/host_male/host_tertiary)
            // are intentionally hidden from the settings UI. The legacy options
            // remain in the DB for any code that still reads them.
            'presshub_ai_briefing_voice_female',
            'presshub_ai_briefing_voice_male',
            'presshub_ai_briefing_voice_tertiary',
            'presshub_ai_briefing_voice_speed',
            'presshub_ai_briefing_voice_pitch',
            'presshub_ai_briefing_audio_split_by_topic',
            'presshub_ai_briefing_tts_style',
            'presshub_ai_briefing_tts_custom_style',
            'presshub_ai_briefing_text_category',
            'presshub_ai_briefing_podcast_category',
            'presshub_ai_briefing_text_status',
            'presshub_ai_briefing_podcast_status',
            'presshub_ai_briefing_text_prompt',
            'presshub_ai_briefing_podcast_prompt_1',
            'presshub_ai_briefing_podcast_prompt_2',
            'presshub_ai_briefing_podcast_prompt_3',
        ];

        foreach ( $expected_fields as $expected_field ) {
            if ( ! in_array( $expected_field, $field_ids, true ) ) {
                $failures[] = "Field {$expected_field} is missing from presshub_ai_briefing section.";
            }
        }

        $cbs = $GLOBALS['SANITIZE_CALLBACKS'] ?? [];

        // --- Case 2: Sanitization of presshub_ai_briefing_sources (Structured Schema & Migration) ---
        self::reset_options();
        $raw_sources_str = " https://www.kathimerini.gr \n\n  https://www.tovima.gr\njavascript:alert(1)\nnot-a-url\nhttps://www.kathimerini.gr ";
        $sanitized_str = self::sanitize( $cbs, 'presshub_ai_briefing_sources', $raw_sources_str );
        if ( ! is_array( $sanitized_str ) || count( $sanitized_str ) !== 2 ) {
            $failures[] = 'presshub_ai_briefing_sources should sanitize legacy string to 2 structured source items; got: ' . var_export( $sanitized_str, true );
        } else {
            $urls = array_column( $sanitized_str, 'url' );
            if ( ! in_array( 'https://www.kathimerini.gr', $urls, true ) || ! in_array( 'https://www.tovima.gr', $urls, true ) ) {
                $failures[] = 'presshub_ai_briefing_sources must contain normalized URLs; got: ' . var_export( $urls, true );
            }
            if ( empty( $sanitized_str[0]['name'] ) || empty( $sanitized_str[0]['id'] ) || 'text_news' !== $sanitized_str[0]['type'] || true !== $sanitized_str[0]['enabled'] ) {
                $failures[] = 'presshub_ai_briefing_sources legacy string should set structured defaults; got: ' . var_export( $sanitized_str[0], true );
            }
        }

        // Array input handling (legacy array migration)
        $raw_sources_arr = [ ' https://www.naftemporiki.gr ', 'https://www.in.gr', 'bad-entry', 'https://www.naftemporiki.gr' ];
        $sanitized_arr = self::sanitize( $cbs, 'presshub_ai_briefing_sources', $raw_sources_arr );
        if ( ! is_array( $sanitized_arr ) || count( $sanitized_arr ) !== 2 ) {
            $failures[] = 'presshub_ai_briefing_sources array input should return clean array of 2 unique structured sources; got: ' . var_export( $sanitized_arr, true );
        }

        // Structured JSON input handling
        $json_input = json_encode( [
            [
                'id'       => 'src_custom_1',
                'name'     => 'Custom Greek Feed',
                'url'      => 'https://www.custom-news.gr/feed.xml',
                'type'     => 'rss_feed',
                'enabled'  => true,
                'category' => 'Technology',
                'notes'    => 'Main RSS stream',
            ],
            [
                'id'       => 'src_youtube_1',
                'name'     => 'Greek News Daily Vlog',
                'url'      => 'https://www.youtube.com/@GreekNewsDaily',
                'type'     => 'youtube',
                'enabled'  => false,
                'category' => 'Video',
                'notes'    => 'YouTube daily briefing channel',
            ],
            [
                'id'       => 'src_podcast_1',
                'name'     => 'Morning Briefing Podcast',
                'url'      => 'https://podcast.example.com/rss',
                'type'     => 'podcast_audio',
                'enabled'  => true,
                'category' => 'Podcast',
                'notes'    => 'Audio podcast feed',
            ],
        ] );
        $sanitized_json = self::sanitize( $cbs, 'presshub_ai_briefing_sources', $json_input );
        if ( ! is_array( $sanitized_json ) || count( $sanitized_json ) !== 3 ) {
            $failures[] = 'presshub_ai_briefing_sources should correctly decode and sanitize JSON input; got: ' . var_export( $sanitized_json, true );
        } else {
            if ( $sanitized_json[0]['type'] !== 'rss_feed' || true !== $sanitized_json[0]['enabled'] || 'Technology' !== $sanitized_json[0]['category'] ) {
                $failures[] = 'presshub_ai_briefing_sources JSON rss_feed not sanitized correctly; got: ' . var_export( $sanitized_json[0], true );
            }
            if ( $sanitized_json[1]['type'] !== 'youtube' || false !== $sanitized_json[1]['enabled'] ) {
                $failures[] = 'presshub_ai_briefing_sources JSON youtube disabled source not sanitized correctly; got: ' . var_export( $sanitized_json[1], true );
            }
            if ( $sanitized_json[2]['type'] !== 'podcast_audio' || true !== $sanitized_json[2]['enabled'] ) {
                $failures[] = 'presshub_ai_briefing_sources JSON podcast_audio source not sanitized correctly; got: ' . var_export( $sanitized_json[2], true );
            }
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
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_female', 'en-US-Neural2-A' ) !== 'Kore' ) {
            $failures[] = 'invalid female voice should fall back to Kore.';
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

        // --- Case 7b: Sanitization of delivery style & custom style prompt ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_style', 'storyteller' ) !== 'storyteller' ) {
            $failures[] = 'storyteller style should be accepted.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_style', 'unknown_style' ) !== 'formal' ) {
            $failures[] = 'invalid style should fall back to formal default.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_custom_style', '  Custom speaking prompt  ' ) !== 'Custom speaking prompt' ) {
            $failures[] = 'custom style prompt should be trimmed.';
        }

        // --- Case 7c: Sanitization of speech generation timeout (clamped 60–900s, default 300s) ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_timeout', 300 ) !== 300 ) {
            $failures[] = '300s valid timeout should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_timeout', 15 ) !== 60 ) {
            $failures[] = 'timeout 15s (below min 60s) should clamp to 60s; got: ' . self::sanitize( $cbs, 'presshub_ai_briefing_tts_timeout', 15 );
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_timeout', 1500 ) !== 900 ) {
            $failures[] = 'timeout 1500s (above max 900s) should clamp to 900s; got: ' . self::sanitize( $cbs, 'presshub_ai_briefing_tts_timeout', 1500 );
        }
        if ( PressHub_AI_Settings_Storage::get_briefing_tts_timeout() !== 300 ) {
            $failures[] = 'default get_briefing_tts_timeout should return 300s when option empty.';
        }

        // --- Case 7d: Issue #71: Host count, tertiary host persona, and topic split settings ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_host_count', 2 ) !== 2 ) {
            $failures[] = 'host count 2 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_host_count', 0 ) !== 1 ) {
            $failures[] = 'host count 0 should clamp to 1; got: ' . self::sanitize( $cbs, 'presshub_ai_briefing_host_count', 0 );
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_host_count', 5 ) !== 3 ) {
            $failures[] = 'host count 5 should clamp to 3; got: ' . self::sanitize( $cbs, 'presshub_ai_briefing_host_count', 5 );
        }
        if ( PressHub_AI_Settings_Storage::get_briefing_host_count() !== 2 ) {
            $failures[] = 'default get_briefing_host_count should return 2.';
        }

        if ( self::sanitize( $cbs, 'presshub_ai_briefing_host_tertiary', '  Κώστας  ' ) !== 'Κώστας' ) {
            $failures[] = 'host_tertiary should be trimmed.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_tertiary', 'Puck' ) !== 'Puck' ) {
            $failures[] = 'valid tertiary voice Puck should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_voice_tertiary', 'InvalidVoice' ) !== 'Puck' ) {
            $failures[] = 'invalid tertiary voice should fall back to default Puck.';
        }

        if ( self::sanitize( $cbs, 'presshub_ai_briefing_audio_split_by_topic', 1 ) !== 1 ) {
            $failures[] = 'audio_split_by_topic 1 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_audio_split_by_topic', 0 ) !== 0 ) {
            $failures[] = 'audio_split_by_topic 0 should pass through.';
        }
        if ( PressHub_AI_Settings_Storage::get_briefing_audio_split_by_topic() !== true ) {
            $failures[] = 'default get_briefing_audio_split_by_topic should return true (1).';
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

        // --- Case 12b: First-time option add (add_option hook) triggers scheduling ---
        self::reset_world();
        $GLOBALS['TIME_NOW'] = strtotime( '2026-08-26 04:00:00' );
        $GLOBALS['CLEARED_HOOKS'] = [];
        $GLOBALS['RECURRING_EVENTS'] = [];

        if ( ! function_exists( 'presshub_ai_on_briefing_option_added' ) ) {
            $failures[] = 'presshub_ai_on_briefing_option_added function must exist.';
        } else {
            $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_schedule_enabled'] = 1;
            presshub_ai_on_briefing_option_added( 'presshub_ai_briefing_schedule_enabled', 1 );

            $added_recurring = array_column( $GLOBALS['RECURRING_EVENTS'] ?? [], 'hook' );
            if ( ! in_array( 'presshub_daily_news_harvest', $added_recurring, true ) ) {
                $failures[] = 'presshub_ai_on_briefing_option_added must schedule presshub_daily_news_harvest on first-time option add.';
            }
            if ( ! in_array( 'presshub_daily_news_generate', $added_recurring, true ) ) {
                $failures[] = 'presshub_ai_on_briefing_option_added must schedule presshub_daily_news_generate on first-time option add.';
            }
        }

        // --- Case 13: Cron Execution Hooks Registration ---
        if ( ! function_exists( 'presshub_ai_execute_harvest_cron' ) ) {
            $failures[] = 'presshub_ai_execute_harvest_cron function must exist.';
        }
        if ( ! function_exists( 'presshub_ai_execute_generation_cron' ) ) {
            $failures[] = 'presshub_ai_execute_generation_cron function must exist.';
        }

        // --- Case 14: Sanitization of speech generation API key & remove key flag ---
        self::reset_options();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_api_key'] = 'AIzaSyExistingKey1234';
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_api_key', '' ) !== 'AIzaSyExistingKey1234' ) {
            $failures[] = 'empty briefing tts api key post should preserve existing key.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_api_key', '••••1234' ) !== 'AIzaSyExistingKey1234' ) {
            $failures[] = 'masked briefing tts api key post should preserve existing key.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_api_key', '<script>AIzaSyNewKey5678</script>' ) !== 'AIzaSyNewKey5678' ) {
            $failures[] = 'briefing tts api key should strip HTML tags.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_remove_briefing_tts_api_key', '1' ) !== 0 ) {
            $failures[] = 'remove briefing tts api key flag should sanitize to 0.';
        }
        if ( array_key_exists( 'presshub_ai_briefing_tts_api_key', $GLOBALS['OPTIONS_STORE'] ) ) {
            $failures[] = 'checking remove briefing tts api key should delete the option.';
        }

        // --- Case 15: Harvest Execution Time Budget (clamping, options map, getter, render) ---
        self::reset_options();
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', 60 ) !== 60 ) {
            $failures[] = 'harvest time budget 60 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', 90 ) !== 90 ) {
            $failures[] = 'harvest time budget 90 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', '90' ) !== 90 ) {
            $failures[] = 'harvest time budget string "90" should sanitize to int 90.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', -5 ) !== 10 ) {
            $failures[] = 'harvest time budget -5 should clamp to min 10.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', 5 ) !== 10 ) {
            $failures[] = 'harvest time budget 5 (below 10) should clamp to min 10.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', 500 ) !== 500 ) {
            $failures[] = 'harvest time budget 500 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', 900 ) !== 900 ) {
            $failures[] = 'harvest time budget 900 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', 1200 ) !== 900 ) {
            $failures[] = 'harvest time budget 1200 (above 900) should clamp to max 900.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_harvest_time_budget', 'invalid' ) !== 60 ) {
            $failures[] = 'non-numeric harvest time budget should fall back to default 60.';
        }

        // Getter tests
        self::reset_options();
        if ( PressHub_AI_Settings_Storage::get_harvest_time_budget() !== 60 ) {
            $failures[] = 'get_harvest_time_budget() should default to 60.';
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] = 900;
        if ( PressHub_AI_Settings_Storage::get_harvest_time_budget() !== 900 ) {
            $failures[] = 'get_harvest_time_budget() should return saved option 900.';
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] = 5;
        if ( PressHub_AI_Settings_Storage::get_harvest_time_budget() !== 60 ) {
            $failures[] = 'get_harvest_time_budget() should fallback to 60 for out-of-range option 5.';
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] = 950;
        if ( PressHub_AI_Settings_Storage::get_harvest_time_budget() !== 60 ) {
            $failures[] = 'get_harvest_time_budget() should fallback to 60 for out-of-range option 950.';
        }

        // Section options map inclusion test
        $briefing_map = PressHub_AI_Settings_Storage::get_section_options_map( 'briefing' );
        if ( ! isset( $briefing_map['presshub_ai_harvest_time_budget'] ) || ! is_callable( $briefing_map['presshub_ai_harvest_time_budget'] ) ) {
            $failures[] = 'presshub_ai_harvest_time_budget must be present and callable in briefing section options map.';
        }

        // Render test
        self::reset_options();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_harvest_time_budget'] = 75;
        $renderer = new PressHub_AI_Settings_Render();
        ob_start();
        $renderer->render_harvest_time_budget_field();
        $render_html = ob_get_clean();

        if ( false === strpos( $render_html, 'name="presshub_ai_harvest_time_budget"' ) ) {
            $failures[] = 'render_harvest_time_budget_field should render input with name="presshub_ai_harvest_time_budget".';
        }
        if ( false === strpos( $render_html, 'min="10"' ) || false === strpos( $render_html, 'max="900"' ) ) {
            $failures[] = 'render_harvest_time_budget_field should render min="10" and max="900".';
        }
        if ( false === strpos( $render_html, 'value="75"' ) ) {
            $failures[] = 'render_harvest_time_budget_field should render value="75".';
        }

        // --- Case 15b: Issue #61 — Curation LLM context cap options ---
        // Settings-First: max articles (1..200, default 40) and max chars
        // per article (100..400000, default 3000) are registered, clamped by
        // sanitize callbacks, readable via get_* helpers, present in the
        // briefing section options map, and rendered with matching min/max.
        self::reset_options();

        // Sanitizer: max articles.
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_articles', 40 ) !== 40 ) {
            $failures[] = 'curation max articles 40 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_articles', '10' ) !== 10 ) {
            $failures[] = 'curation max articles string "10" should sanitize to int 10.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_articles', 0 ) !== 1 ) {
            $failures[] = 'curation max articles 0 should clamp to min 1.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_articles', -5 ) !== 1 ) {
            $failures[] = 'curation max articles -5 should clamp to min 1.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_articles', 200 ) !== 200 ) {
            $failures[] = 'curation max articles 200 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_articles', 201 ) !== 200 ) {
            $failures[] = 'curation max articles 201 should clamp to max 200.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_articles', 'invalid' ) !== 40 ) {
            $failures[] = 'non-numeric curation max articles should fall back to default 40.';
        }

        // Sanitizer: max chars per article.
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_chars_per_article', 3000 ) !== 3000 ) {
            $failures[] = 'curation max chars 3000 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_chars_per_article', '1500' ) !== 1500 ) {
            $failures[] = 'curation max chars string "1500" should sanitize to int 1500.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_chars_per_article', 99 ) !== 100 ) {
            $failures[] = 'curation max chars 99 should clamp to min 100.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_chars_per_article', 0 ) !== 100 ) {
            $failures[] = 'curation max chars 0 should clamp to min 100.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_chars_per_article', 400000 ) !== 400000 ) {
            $failures[] = 'curation max chars 400000 should pass through.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_chars_per_article', 500000 ) !== 400000 ) {
            $failures[] = 'curation max chars 500000 should clamp to max 400000.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_curation_max_chars_per_article', 'invalid' ) !== 3000 ) {
            $failures[] = 'non-numeric curation max chars should fall back to default 3000.';
        }

        // Getters: defaults apply when options are absent.
        self::reset_options();
        if ( PressHub_AI_Settings_Storage::get_curation_max_articles() !== 40 ) {
            $failures[] = 'get_curation_max_articles() should default to 40.';
        }
        if ( PressHub_AI_Settings_Storage::get_curation_max_chars_per_article() !== 3000 ) {
            $failures[] = 'get_curation_max_chars_per_article() should default to 3000.';
        }

        // Getters: saved in-range options pass through.
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'] = 10;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 2500;
        if ( PressHub_AI_Settings_Storage::get_curation_max_articles() !== 10 ) {
            $failures[] = 'get_curation_max_articles() should return saved option 10.';
        }
        if ( PressHub_AI_Settings_Storage::get_curation_max_chars_per_article() !== 2500 ) {
            $failures[] = 'get_curation_max_chars_per_article() should return saved option 2500.';
        }

        // Getters: out-of-range options fall back to defaults.
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'] = 0;
        if ( PressHub_AI_Settings_Storage::get_curation_max_articles() !== 40 ) {
            $failures[] = 'get_curation_max_articles() should fall back to 40 for out-of-range option 0.';
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'] = 500;
        if ( PressHub_AI_Settings_Storage::get_curation_max_articles() !== 40 ) {
            $failures[] = 'get_curation_max_articles() should fall back to 40 for out-of-range option 500.';
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 50;
        if ( PressHub_AI_Settings_Storage::get_curation_max_chars_per_article() !== 3000 ) {
            $failures[] = 'get_curation_max_chars_per_article() should fall back to 3000 for out-of-range option 50.';
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 999999;
        if ( PressHub_AI_Settings_Storage::get_curation_max_chars_per_article() !== 3000 ) {
            $failures[] = 'get_curation_max_chars_per_article() should fall back to 3000 for out-of-range option 999999.';
        }
        unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'], $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] );

        // Section options map inclusion.
        if ( ! isset( $briefing_map['presshub_ai_curation_max_articles'] ) || ! is_callable( $briefing_map['presshub_ai_curation_max_articles'] ) ) {
            $failures[] = 'presshub_ai_curation_max_articles must be present and callable in briefing section options map.';
        }
        if ( ! isset( $briefing_map['presshub_ai_curation_max_chars_per_article'] ) || ! is_callable( $briefing_map['presshub_ai_curation_max_chars_per_article'] ) ) {
            $failures[] = 'presshub_ai_curation_max_chars_per_article must be present and callable in briefing section options map.';
        }

        // Render: max articles field.
        self::reset_options();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_articles'] = 10;
        ob_start();
        $renderer->render_curation_max_articles_field();
        $render_articles_html = ob_get_clean();
        if ( false === strpos( $render_articles_html, 'name="presshub_ai_curation_max_articles"' ) ) {
            $failures[] = 'render_curation_max_articles_field should render input with name="presshub_ai_curation_max_articles".';
        }
        if ( false === strpos( $render_articles_html, 'min="1"' ) || false === strpos( $render_articles_html, 'max="200"' ) ) {
            $failures[] = 'render_curation_max_articles_field should render min="1" and max="200".';
        }
        if ( false === strpos( $render_articles_html, 'value="10"' ) ) {
            $failures[] = 'render_curation_max_articles_field should render value="10".';
        }

        // Render: max chars per article field.
        self::reset_options();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_curation_max_chars_per_article'] = 2500;
        ob_start();
        $renderer->render_curation_max_chars_per_article_field();
        $render_chars_html = ob_get_clean();
        if ( false === strpos( $render_chars_html, 'name="presshub_ai_curation_max_chars_per_article"' ) ) {
            $failures[] = 'render_curation_max_chars_per_article_field should render input with name="presshub_ai_curation_max_chars_per_article".';
        }
        if ( false === strpos( $render_chars_html, 'min="100"' ) || false === strpos( $render_chars_html, 'max="400000"' ) ) {
            $failures[] = 'render_curation_max_chars_per_article_field should render min="100" and max="400000".';
        }
        if ( false === strpos( $render_chars_html, 'value="2500"' ) ) {
            $failures[] = 'render_curation_max_chars_per_article_field should render value="2500".';
        }

        // --- Case 15c: Issue #108 — Podcast Dialogue Styles & Prompt Studio (Options map & Render) ---
        self::reset_world();
        $briefing_map = PressHub_AI_Settings_Storage::get_section_options_map( 'briefing' );
        if ( ! isset( $briefing_map['presshub_ai_briefing_podcast_style'] ) || ! is_callable( $briefing_map['presshub_ai_briefing_podcast_style'] ) ) {
            $failures[] = 'presshub_ai_briefing_podcast_style must be present and callable in briefing section options map.';
        }

        $styles = [ 'default_greek_chat', 'bbc_broadcasting_standards', 'conversational_news_reporting' ];
        foreach ( $styles as $style ) {
            foreach ( [ 1, 2, 3 ] as $host_count_n ) {
                $prompt_key = 'presshub_ai_briefing_podcast_prompt_' . $host_count_n . '_' . $style;
                if ( ! isset( $briefing_map[ $prompt_key ] ) || ! is_callable( $briefing_map[ $prompt_key ] ) ) {
                    $failures[] = "{$prompt_key} must be present and callable in briefing section options map.";
                }
            }
        }

        // Render page test: verify Section 3 renders podcast style selector and obsolete prompt-1-row is removed
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        $renderer = new PressHub_AI_Settings_Render();
        ob_start();
        $renderer->render_settings_page();
        $page_html = ob_get_clean();

        if ( false === strpos( $page_html, 'name="presshub_ai_briefing_podcast_style"' ) ) {
            $failures[] = 'render_settings_page should render briefing podcast style selector.';
        }
        if ( false === strpos( $page_html, 'name="presshub_ai_briefing_voice_pitch"' ) ) {
            $failures[] = 'render_settings_page should render input for presshub_ai_briefing_voice_pitch.';
        }
        if ( false !== strpos( $page_html, 'id="presshub-prompt-1-row"' ) ) {
            $failures[] = 'render_settings_page must not render obsolete id="presshub-prompt-1-row".';
        }
        if ( false !== strpos( $page_html, 'id="presshub-prompt-2-row"' ) ) {
            $failures[] = 'render_settings_page must not render obsolete id="presshub-prompt-2-row".';
        }
        if ( false !== strpos( $page_html, 'id="presshub-prompt-3-row"' ) ) {
            $failures[] = 'render_settings_page must not render obsolete id="presshub-prompt-3-row".';
        }
        if ( false === strpos( $page_html, '3. Podcast Dialogue Styles' ) ) {
            $failures[] = 'render_settings_page should render Section 3: Podcast Dialogue Styles & Prompt Studio.';
        }

        // Issue #108: Assert obsolete host name inputs and dialogue preset are absent
        if ( false !== strpos( $page_html, 'name="presshub_ai_briefing_host_female"' ) ) {
            $failures[] = 'render_settings_page must not render obsolete host female input.';
        }
        if ( false !== strpos( $page_html, 'name="presshub_ai_briefing_host_male"' ) ) {
            $failures[] = 'render_settings_page must not render obsolete host male input.';
        }
        if ( false !== strpos( $page_html, 'name="presshub_ai_briefing_host_tertiary"' ) ) {
            $failures[] = 'render_settings_page must not render obsolete host tertiary input.';
        }
        if ( false !== strpos( $page_html, 'name="presshub_ai_briefing_podcast_preset"' ) ) {
            $failures[] = 'render_settings_page must not render obsolete podcast preset dropdown.';
        }
        if ( false !== strpos( $page_html, 'presshub-podcast-preview' ) ) {
            $failures[] = 'render_settings_page must not render obsolete Effective Prompt Preview.';
        }

        // Issue #108: Assert Presenter 1, 2, and 3 voice dropdowns contain voices from both male and female catalogs
        preg_match( '/<select[^>]+name="presshub_ai_briefing_voice_female"[^>]*>(.*?)<\/select>/s', $page_html, $v1_match );
        if ( empty( $v1_match[1] ) || false === strpos( $v1_match[1], 'value="Fenrir"' ) || false === strpos( $v1_match[1], 'value="Kore"' ) ) {
            $failures[] = 'Presenter 1 voice dropdown must contain both male and female voices.';
        }

        preg_match( '/<select[^>]+name="presshub_ai_briefing_voice_male"[^>]*>(.*?)<\/select>/s', $page_html, $v2_match );
        if ( empty( $v2_match[1] ) || false === strpos( $v2_match[1], 'value="Fenrir"' ) || false === strpos( $v2_match[1], 'value="Kore"' ) ) {
            $failures[] = 'Presenter 2 voice dropdown must contain both male and female voices.';
        }

        preg_match( '/<select[^>]+name="presshub_ai_briefing_voice_tertiary"[^>]*>(.*?)<\/select>/s', $page_html, $v3_match );
        if ( empty( $v3_match[1] ) || false === strpos( $v3_match[1], 'value="Fenrir"' ) || false === strpos( $v3_match[1], 'value="Kore"' ) ) {
            $failures[] = 'Presenter 3 voice dropdown must contain both male and female voices.';
        }

        // Issue #108: Assert Prompt Studio renders style tabs, subtabs, and prompt textareas
        if ( false === strpos( $page_html, 'presshub-prompt-style-tabs' ) ) {
            $failures[] = 'render_settings_page should render Prompt Studio style tabs.';
        }
        if ( false === strpos( $page_html, 'presshub-prompt-host-tabs' ) ) {
            $failures[] = 'render_settings_page should render Prompt Studio presenter subtabs.';
        }
        if ( false === strpos( $page_html, 'presshub-prompt-textarea' ) ) {
            $failures[] = 'render_settings_page should render Prompt Studio prompt textareas.';
        }
        foreach ( $styles as $s ) {
            foreach ( [ 1, 2, 3 ] as $hc_n ) {
                $p_key = 'presshub_ai_briefing_podcast_prompt_' . $hc_n . '_' . $s;
                if ( false === strpos( $page_html, 'name="' . $p_key . '"' ) ) {
                    $failures[] = "render_settings_page should render textarea for {$p_key}.";
                }
            }
        }

        // Issue #113: Assert Voice Generation AI Model is rendered in Section 2
        if ( false === strpos( $page_html, 'name="presshub_ai_briefing_tts_model"' ) ) {
            $failures[] = 'render_settings_page must render presshub_ai_briefing_tts_model input in Section 2.';
        }
        if ( false === strpos( $page_html, 'Voice Generation AI Model' ) ) {
            $failures[] = 'render_settings_page must render Voice Generation AI Model label in Section 2.';
        }

        // Issue #113: briefing TTS model helper & sanitizer
        self::reset_options();
        if ( PressHub_AI_Settings_Storage::get_briefing_tts_model() !== '' ) {
            $failures[] = 'get_briefing_tts_model() should default to empty string.';
        }
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model'] = 'gemini-2.5-flash-preview-tts';
        if ( PressHub_AI_Settings_Storage::get_briefing_tts_model() !== 'gemini-2.5-flash-preview-tts' ) {
            $failures[] = 'get_briefing_tts_model() should return saved option.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_model', '  gemini-2.5-flash-preview-tts  ' ) !== 'gemini-2.5-flash-preview-tts' ) {
            $failures[] = 'presshub_ai_briefing_tts_model sanitizer should trim input.';
        }
        if ( self::sanitize( $cbs, 'presshub_ai_briefing_tts_model', '' ) !== '' ) {
            $failures[] = 'presshub_ai_briefing_tts_model sanitizer should allow empty string.';
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
            'update_option_presshub_ai_briefing_schedule_enabled' => [ 'presshub_ai_on_briefing_time_updated' ],
            'update_option_presshub_ai_briefing_harvest_time'     => [ 'presshub_ai_schedule_briefing_crons' ],
            'update_option_presshub_ai_briefing_generation_time'  => [ 'presshub_ai_schedule_briefing_crons' ],
            'add_option_presshub_ai_briefing_schedule_enabled'    => [ 'presshub_ai_on_briefing_option_added' ],
            'add_option_presshub_ai_briefing_harvest_time'         => [ 'presshub_ai_on_briefing_option_added' ],
            'add_option_presshub_ai_briefing_generation_time'      => [ 'presshub_ai_on_briefing_option_added' ],
            'presshub_daily_news_harvest'                         => [ 'presshub_ai_execute_harvest_cron' ],
            'presshub_daily_news_generate'                        => [ 'presshub_ai_execute_generation_cron' ],
        ];
    }

}

DailyBriefingSettingsTest::run();
