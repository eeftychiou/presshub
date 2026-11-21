<?php
/**
 * #234 settings overhaul phase 2: layout, moves and helpers.
 *
 *  1. AI page: Daily Briefing renders six groups and an "On this page" bar,
 *     no numbered sections; Social listening is its own tab with the monitor
 *     and trending fields; editorial memory renders on Editorial knowledge;
 *     SEO & tags render in a Co-Author sub-tab, not on Featured Images.
 *  2. Every rendered AI option is drawn inside the pane of its registry tab,
 *     so a tab's Save sends what it shows (save isolation).
 *  3. Social page: Shorts & Reels fields and prompts are on the shorts pages,
 *     Automation Worker keeps only the four worker fields, X max characters
 *     sits with the engines, card/video sizes are under Images & video.
 *  4. The Shorts readiness notice lists every unmet requirement.
 *  5. The search index skips secrets and unused options and carries defaults.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) {
        return rtrim( (string) $value, '/\\' );
    }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once __DIR__ . '/../presshub-ai-editor.php';
require_once __DIR__ . '/../includes/class-social-settings.php';
require_once __DIR__ . '/../includes/class-social-settings-render.php';
require_once __DIR__ . '/settings-sections-helper.php';

class SettingsPhase2LayoutTest {

    private static function pane( string $html, string $tab ): string {
        return presshub_test_tab_pane( $html, $tab );
    }

    public static function run(): void {
        $failures = [];

        // 1. AI page layout.
        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_others_posts' ];
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        $html = presshub_test_render_all_sections();

        $briefing      = self::pane( $html, 'briefing' );
        $briefing_page = presshub_test_render_section( 'daily-briefing' );
        foreach ( array_keys( PressHub_AI_Settings_Render::briefing_groups() ) as $group ) {
            // #246: the "On this page" bar sits above the section's form.
            if ( false === strpos( $briefing, 'id="' . $group . '"' ) || false === strpos( $briefing_page, 'href="#' . $group . '"' ) ) {
                $failures[] = "Daily Briefing should render group {$group} with an On this page link.";
            }
        }
        if ( preg_match( '/>\s*[1-4]\.\s/', $briefing ) ) {
            $failures[] = 'Daily Briefing should no longer have numbered sections.';
        }
        if ( 1 !== substr_count( $briefing, '<details class="presshub-group-advanced"' ) ) {
            $failures[] = 'Daily Briefing should have exactly one Advanced block (Voices & audio).';
        }
        $social = self::pane( $html, 'social_listening' );
        foreach ( [ 'presshub_ai_social_monitor_enabled', 'presshub_ai_social_trending_enabled', 'presshub_ai_social_trending_max_per_account' ] as $key ) {
            if ( false === strpos( $social, 'name="' . $key . '"' ) || false !== strpos( $briefing, 'name="' . $key . '"' ) ) {
                $failures[] = "{$key} should render on Social listening only.";
            }
        }
        if ( false === strpos( self::pane( $html, 'knowledge' ), 'name="presshub_ai_memory_enabled"' ) ) {
            $failures[] = 'Editorial memory should render on Editorial knowledge.';
        }
        // #246: SEO & tags and Featured images are groups of Writing & QA.
        if ( false === strpos( presshub_test_group( $html, 'seo' ), 'presshub_ai_seo_max_tags' ) || false !== strpos( presshub_test_group( $html, 'featured-images' ), 'presshub_ai_seo_max_tags' ) ) {
            $failures[] = 'SEO & tags should render in the SEO & tags group, not in Featured images.';
        }

        // 2. Every rendered AI option is drawn in its section's pane (#246: the
        // section, not the old tab, decides where an option lives).
        foreach ( PressHub_Settings_Registry::for_page( 'ai' ) as $key => $e ) {
            if ( empty( $e['rendered'] ) || ! empty( $e['removes'] ) || PressHub_Settings_Registry::is_unused( $key ) || in_array( $e['tab'], [ 'providers', 'advanced' ], true ) ) {
                continue;
            }
            $pane = self::pane( $html, PressHub_Settings_Sections::section_of( $key ) );
            if ( '' === $pane ) {
                $failures[] = "No pane for tab {$e['tab']} ({$key}).";
                continue;
            }
            if ( false === strpos( $pane, 'name="' . $key . '"' ) && false === strpos( $pane, 'name="' . $key . '[' ) ) {
                // Options rendered elsewhere on purpose (e.g. by JS-built widgets) are not expected here.
                if ( false !== strpos( $html, 'name="' . $key . '"' ) ) {
                    $failures[] = "{$key} (tab {$e['tab']}) renders outside its tab's pane.";
                }
            }
        }

        // 3. Social page fields.
        $GLOBALS['FIELDS']   = [];
        $GLOBALS['SECTIONS'] = [];
        ( new PressHub_Social_Settings() )->register_settings();
        $F    = $GLOBALS['FIELDS'];
        $ids  = static function ( $page, $section ) use ( $F ) {
            return array_column( $F[ $page ][ $section ] ?? [], 'id' );
        };
        $worker = $ids( 'presshub_social_worker', 'presshub_social_worker_sec' );
        if ( [ 'presshub_social_worker_mode', 'presshub_social_worker_url', 'presshub_social_worker_secret', 'presshub_social_worker_timeout' ] !== $worker || ! empty( $F['presshub_social_worker']['presshub_social_media_specs_sec'] ) ) {
            $failures[] = 'Automation Worker should keep only the four worker fields; got ' . implode( ', ', $worker );
        }
        if ( ! in_array( 'presshub_social_twitter_max_chars', $ids( 'presshub_social_general', 'presshub_social_engines_sec' ), true ) ) {
            $failures[] = 'X max characters should sit with the dispatch engines.';
        }
        if ( ! in_array( 'presshub_social_instagram_card_width', $ids( 'presshub_social_ai', 'presshub_social_media_specs_sec' ), true ) ) {
            $failures[] = 'Card and video sizes should be under AI Copywriter -> Images & video.';
        }
        $shorts = array_merge(
            $ids( 'presshub_social_shorts', 'presshub_social_shorts_where_sec' ),
            $ids( 'presshub_social_shorts', 'presshub_social_shorts_script_sec' ),
            $ids( 'presshub_social_shorts', 'presshub_social_shorts_narration_sec' ),
            $ids( 'presshub_social_shorts', 'presshub_social_shorts_music_sec' )
        );
        foreach ( [ 'presshub_social_tiktok_shorts_enabled', 'presshub_social_instagram_shorts_enabled', 'presshub_social_tiktok_article_shorts_enabled', 'presshub_social_tiktok_shorts_voice_1', 'presshub_social_tiktok_shorts_music_enabled' ] as $key ) {
            if ( ! in_array( $key, $shorts, true ) ) {
                $failures[] = "{$key} should be on Shorts & Reels.";
            }
        }
        if ( 7 !== count( $ids( 'presshub_social_shorts_prompts', 'presshub_social_shorts_prompts_sec' ) ) ) {
            $failures[] = 'All seven Shorts prompts (with the photo slide sound searches) should be in the collapsed Shorts prompts block.';
        }
        foreach ( PressHub_Settings_Registry::for_page( 'social' ) as $key => $e ) {
            if ( 0 === strpos( $key, 'presshub_social_tiktok_shorts_' ) && 'shorts' !== $e['tab'] ) {
                $failures[] = "Registry tab of {$key} should be shorts.";
            }
        }

        // 4. Readiness notice.
        $render = new PressHub_Social_Settings_Render();
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_social_tiktok_shorts_enabled'    => 1,
            'presshub_social_instagram_shorts_enabled' => 1,
            'presshub_social_worker_mode'              => 'push',
            'presshub_social_engine_tiktok'            => 'api',
            'presshub_social_engine_instagram'         => 'api',
        ];
        if ( 3 !== count( $render->shorts_unmet_requirements() ) ) {
            $failures[] = 'With Push mode and API engines, all three requirements should be listed; got ' . count( $render->shorts_unmet_requirements() );
        }
        $GLOBALS['OPTIONS_STORE']['presshub_social_worker_mode']      = 'pull';
        $GLOBALS['OPTIONS_STORE']['presshub_social_engine_tiktok']    = 'automation';
        $GLOBALS['OPTIONS_STORE']['presshub_social_engine_instagram'] = 'automation';
        if ( [] !== $render->shorts_unmet_requirements() ) {
            $failures[] = 'With everything ready, no requirement should be listed.';
        }
        $GLOBALS['OPTIONS_STORE'] = [ 'presshub_social_worker_mode' => 'push', 'presshub_social_tiktok_shorts_enabled' => 0, 'presshub_social_instagram_shorts_enabled' => 0, 'presshub_social_tiktok_article_shorts_enabled' => 1, 'presshub_social_tiktok_slides_enabled' => 0 ];
        if ( [] !== $render->shorts_unmet_requirements() ) {
            $failures[] = 'With Shorts, Reels and photo slides off, no notice.';
        }
        // Photo slides alone (on by default) still need Pull mode and the TikTok automation engine (#333 review).
        $GLOBALS['OPTIONS_STORE'] = [ 'presshub_social_worker_mode' => 'push', 'presshub_social_engine_tiktok' => 'api', 'presshub_social_enabled_platforms' => [ 'tiktok' ] ];
        $slide_issues = $render->shorts_unmet_requirements();
        if ( 2 !== count( $slide_issues ) || false === strpos( $slide_issues[1]['text'] ?? '', 'photo slides' ) ) {
            $failures[] = 'Photo slides alone should list Push mode and the TikTok engine; got ' . count( $slide_issues );
        }
        $GLOBALS['OPTIONS_STORE']['presshub_social_enabled_platforms'] = [ 'facebook' ];
        if ( [] !== $render->shorts_unmet_requirements() ) {
            $failures[] = 'Photo slides should not raise a notice when TikTok is not posted to.';
        }

        // 5. Search index.
        $index = PressHub_Settings_Registry::search_index();
        $keys  = array_column( $index, 'key' );
        foreach ( $index as $row ) {
            if ( PressHub_Settings_Registry::is_unused( $row['key'] ) ) {
                $failures[] = "Search index must not include {$row['key']}.";
            }
            // #309 (E3): secrets are in the index by label only, never with a default.
            if ( PressHub_Settings_Registry::is_secret( $row['key'] ) && ( empty( $row['secret'] ) || array_key_exists( 'default', $row ) ) ) {
                $failures[] = "Secret {$row['key']} must be in the index by label only.";
            }
        }
        if ( ! in_array( 'presshub_ai_briefing_host_female', $keys, true ) || ! in_array( 'presshub_social_tiktok_shorts_count', $keys, true ) ) {
            $failures[] = 'Search index should cover both pages.';
        }
        $payload = PressHub_Settings_Registry::search_payload( 'writing-qa' );
        if ( 'writing-qa' !== $payload['section'] || empty( $payload['section_urls']['social-publishing'] ) || empty( $payload['section_labels']['daily-briefing'] ) || empty( $payload['whats_new'] ) || empty( $payload['legacy_hashes']['briefing'] ) ) {
            $failures[] = 'Search payload should carry the section, every section URL and label, old #tab hashes and what\'s new.';
        }
        foreach ( $payload['entries'] as $row ) {
            if ( '' === ( $row['section'] ?? '' ) ) {
                $failures[] = "Search entry {$row['key']} has no section.";
            }
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo "OK\n";
    }
}

SettingsPhase2LayoutTest::run();
