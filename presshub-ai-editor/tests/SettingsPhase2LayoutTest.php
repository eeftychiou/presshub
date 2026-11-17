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

class SettingsPhase2LayoutTest {

    private static function pane( string $html, string $tab ): string {
        $start = strpos( $html, 'id="presshub-tab-pane-' . $tab . '"' );
        if ( false === $start ) {
            return '';
        }
        $next = strpos( $html, 'id="presshub-tab-pane-', $start + 10 );
        return substr( $html, $start, false === $next ? null : $next - $start );
    }

    public static function run(): void {
        $failures = [];

        // 1. AI page layout.
        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options' ];
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        ob_start();
        ( new PressHub_AI_Settings_Render() )->render_settings_page();
        $html = (string) ob_get_clean();

        $briefing = self::pane( $html, 'briefing' );
        foreach ( array_keys( PressHub_AI_Settings_Render::briefing_groups() ) as $group ) {
            if ( false === strpos( $briefing, 'id="' . $group . '"' ) || false === strpos( $briefing, 'href="#' . $group . '"' ) ) {
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
        if ( false === strpos( self::pane( $html, 'coauthor' ), 'id="presshub-subpane-coauthor-seo"' ) || false !== strpos( self::pane( $html, 'images' ), 'presshub_ai_seo_max_tags' ) ) {
            $failures[] = 'SEO & tags should render in the Co-Author SEO sub-tab, not on Featured Images.';
        }

        // 2. Every rendered AI option is drawn in its registry tab's pane.
        foreach ( PressHub_Settings_Registry::for_page( 'ai' ) as $key => $e ) {
            if ( empty( $e['rendered'] ) || ! empty( $e['removes'] ) || PressHub_Settings_Registry::is_unused( $key ) || in_array( $e['tab'], [ 'providers', 'advanced' ], true ) ) {
                continue;
            }
            $pane = self::pane( $html, $e['tab'] );
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
        if ( 6 !== count( $ids( 'presshub_social_shorts_prompts', 'presshub_social_shorts_prompts_sec' ) ) ) {
            $failures[] = 'All six Shorts prompts should be in the collapsed Shorts prompts block.';
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
        $GLOBALS['OPTIONS_STORE'] = [ 'presshub_social_worker_mode' => 'push', 'presshub_social_tiktok_shorts_enabled' => 0, 'presshub_social_instagram_shorts_enabled' => 0, 'presshub_social_tiktok_article_shorts_enabled' => 0 ];
        if ( [] !== $render->shorts_unmet_requirements() ) {
            $failures[] = 'With Shorts and Reels off, no notice.';
        }

        // 5. Search index.
        $index = PressHub_Settings_Registry::search_index();
        $keys  = array_column( $index, 'key' );
        foreach ( $index as $row ) {
            if ( PressHub_Settings_Registry::is_secret( $row['key'] ) || PressHub_Settings_Registry::is_unused( $row['key'] ) ) {
                $failures[] = "Search index must not include {$row['key']}.";
            }
        }
        if ( ! in_array( 'presshub_ai_briefing_host_female', $keys, true ) || ! in_array( 'presshub_social_tiktok_shorts_count', $keys, true ) ) {
            $failures[] = 'Search index should cover both pages.';
        }
        $payload = PressHub_Settings_Registry::search_payload( 'ai' );
        if ( 'ai' !== $payload['page'] || empty( $payload['tab_labels']['social']['shorts'] ) || empty( $payload['whats_new']['ai'] ) ) {
            $failures[] = 'Search payload should carry the page, tab labels and what\'s new.';
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
