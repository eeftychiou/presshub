<?php
/**
 * #233 step 5: switches that existed only as code filters are now settings.
 *
 *  - With nothing stored, every switch keeps the value the code used before.
 *  - A saved value changes behaviour, and the filter still runs on top of it.
 *  - The Editorial knowledge tab renders them and saves them, including the
 *    per-task budgets posted as "option[task]" keys.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once __DIR__ . '/../presshub-ai-editor.php';
require_once __DIR__ . '/settings-sections-helper.php';

class SettingsKnowledgeSwitchesTest {

    private static function filters_off(): void {
        $GLOBALS['FILTERS'] = [];
        unset( $GLOBALS['wp_filter'] );
    }

    public static function run(): void {
        $failures = [];
        $S        = 'PressHub_AI_Settings_Storage';
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();

        // 1. Nothing stored: today's behaviour.
        $GLOBALS['OPTIONS_STORE'] = [];
        self::filters_off();
        $checks = [
            'policy fiches on'          => PressHub_AI_Policy_Fiches::is_enabled(),
            'people fiches on'          => PressHub_AI_Stakeholders::is_enabled(),
            'claim checks on'           => '' !== PressHub_AI_News_Curator::claim_check_rules(),
            'pool cleanup on'           => $S::get_pool_cleanup_enabled(),
            'show notes on'             => $S::get_podcast_show_notes_enabled(),
            'pool max age 48 h'         => PressHub_AI_Pool_Cleaner::MAX_AGE_HOURS === $S::get_pool_max_age_hours(),
            'duplicate similarity 0.6'  => abs( PressHub_AI_Pool_Cleaner::DUPLICATE_SIMILAR - $S::get_pool_duplicate_similarity() ) < 0.0001,
            'trending 1 per account'    => 1 === PressHub_AI_Social_Trending::max_per_account(),
            'trending from 08:00'       => 8 === PressHub_AI_Social_Trending::share_after_hour(),
            'policy budgets unchanged'  => PressHub_AI_Policy_Fiches::BUDGETS['podcast_script'] === PressHub_AI_Policy_Fiches::budget_for( 'podcast_script' ) && 800 === PressHub_AI_Policy_Fiches::budget_for( 'social_copy_facebook' ),
            'people budgets unchanged'  => PressHub_AI_Stakeholders::BUDGETS['briefing_curation'] === PressHub_AI_Stakeholders::budget_for( 'briefing_curation' ),
            'agenda budgets unchanged'  => PressHub_AI_Editorial_Agenda::BUDGETS['seo_'] === PressHub_AI_Editorial_Agenda::budget_for( 'seo_tags' ),
        ];
        foreach ( $checks as $label => $ok ) {
            if ( ! $ok ) {
                $failures[] = "Default: {$label} should hold with nothing stored.";
            }
        }

        // 2. Saved through the Editorial knowledge tab, the values apply.
        $GLOBALS['OPTIONS_STORE'] = [];
        $result = PressHub_Settings_Registry::save_posted( 'ai', 'knowledge', [
            // policy fiches and claim checks left unticked; Editorial memory
            // sits on this tab since #234 and stays ticked.
            'presshub_ai_memory_enabled'                        => '1',
            'presshub_ai_stakeholders_enabled'                  => '1',
            'presshub_ai_pool_cleanup_enabled'                  => '1',
            'presshub_ai_pool_max_age_hours'                    => '24',
            'presshub_ai_pool_duplicate_similarity'             => '0.8',
            'presshub_ai_policy_fiches_budgets[podcast_script]' => '500',
            'presshub_ai_policy_fiches_budgets[social_copy_]'   => '0',
            'presshub_ai_stakeholders_budgets[briefing_curation]' => '99999',
        ] );
        self::filters_off();
        if ( PressHub_AI_Policy_Fiches::is_enabled() ) {
            $failures[] = 'Unticked "Policy fiches in AI calls" should turn policy fiches off.';
        }
        if ( ! PressHub_AI_Stakeholders::is_enabled() ) {
            $failures[] = 'Ticked "People fiches in AI calls" should keep them on.';
        }
        if ( '' !== PressHub_AI_News_Curator::claim_check_rules() ) {
            $failures[] = 'Unticked claim checks should drop the claim-check rules.';
        }
        if ( 24 !== $S::get_pool_max_age_hours() || abs( 0.8 - $S::get_pool_duplicate_similarity() ) > 0.0001 ) {
            $failures[] = 'Pool max age and similarity should save.';
        }
        if ( 500 !== PressHub_AI_Policy_Fiches::budget_for( 'podcast_script' ) || 0 !== PressHub_AI_Policy_Fiches::budget_for( 'social_copy_x' ) ) {
            $failures[] = 'Policy fiche budgets posted as option[task] should save and apply.';
        }
        if ( PressHub_AI_Policy_Fiches::BUDGETS['coauthor_draft'] !== PressHub_AI_Policy_Fiches::budget_for( 'coauthor_draft' ) ) {
            $failures[] = 'A budget that was not posted should keep its default.';
        }
        if ( 20000 !== PressHub_AI_Stakeholders::budget_for( 'briefing_curation' ) ) {
            $failures[] = 'Budgets should be capped at 20,000 characters.';
        }
        foreach ( [ 'presshub_ai_podcast_show_notes_enabled', 'presshub_ai_social_trending_max_per_account' ] as $other ) {
            if ( array_key_exists( $other, $GLOBALS['OPTIONS_STORE'] ) ) {
                $failures[] = "Saving Editorial knowledge must not write {$other} (another tab).";
            }
        }
        if ( ! in_array( 'presshub_ai_policy_fiches_enabled', $result['saved_keys'], true ) || 0 !== $GLOBALS['OPTIONS_STORE']['presshub_ai_policy_fiches_enabled'] ) {
            $failures[] = 'An unticked switch should be stored as 0.';
        }

        // 3. The filter still runs on top of the saved value.
        add_filter( 'presshub_ai_policy_fiches_enabled', '__return_true' );
        if ( ! PressHub_AI_Policy_Fiches::is_enabled() ) {
            $failures[] = 'presshub_ai_policy_fiches_enabled filter should still override the setting.';
        }
        self::filters_off();
        add_filter( 'presshub_ai_policy_fiches_budgets', static function ( $b ) {
            $b['podcast_script'] = 123;
            return $b;
        } );
        if ( 123 !== PressHub_AI_Policy_Fiches::budget_for( 'podcast_script' ) ) {
            $failures[] = 'presshub_ai_policy_fiches_budgets filter should receive the saved budgets and win.';
        }
        self::filters_off();

        // 4. Memory off still turns the fiches off whatever the switch says.
        $GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_memory_enabled' => 0 ];
        if ( PressHub_AI_Policy_Fiches::is_enabled() || PressHub_AI_Stakeholders::is_enabled() ) {
            $failures[] = 'With Editorial memory off, fiches stay off.';
        }

        // 5. Trending switches save with Social listening (#234), show notes with the briefing tab.
        $GLOBALS['OPTIONS_STORE'] = [];
        PressHub_Settings_Registry::save_posted( 'ai', 'briefing', [] );
        PressHub_Settings_Registry::save_posted( 'ai', 'social_listening', [
            'presshub_ai_social_trending_max_per_account'  => '3',
            'presshub_ai_social_trending_share_after_hour' => '0',
        ] );
        if ( 3 !== PressHub_AI_Social_Trending::max_per_account() || 0 !== PressHub_AI_Social_Trending::share_after_hour() ) {
            $failures[] = 'Trending per-account cap and publish hour should save on the Social listening tab.';
        }
        if ( 0 !== ( $GLOBALS['OPTIONS_STORE']['presshub_ai_podcast_show_notes_enabled'] ?? null ) || $S::get_podcast_show_notes_enabled() ) {
            $failures[] = 'Unticked "Podcast show notes" on the briefing tab should store 0 and turn them off.';
        }

        // 6. The tab renders every switch.
        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_others_posts' ];
        $html = presshub_test_render_all_sections();
        if ( false === strpos( $html, 'id="presshub-tab-pane-editorial-knowledge"' ) || false === strpos( $html, 'data-active-tab="editorial-knowledge"' ) ) {
            $failures[] = 'The Editorial knowledge tab and its Save button should render.';
        }
        foreach ( PressHub_Settings_Registry::for_tab( 'ai', 'knowledge' ) as $key => $e ) {
            if ( false === strpos( $html, 'name="' . $key . '"' ) && false === strpos( $html, 'name="' . $key . '[' ) ) {
                $failures[] = "{$key} has no field on the Editorial knowledge tab.";
            }
        }
        if ( ! preg_match( '/name="presshub_ai_policy_fiches_enabled"[^>]*checked/', $html ) ) {
            $failures[] = 'With nothing stored, "Policy fiches in AI calls" should render ticked.';
        }

        if ( $failures ) {
            echo "FAIL\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }
        echo "SettingsKnowledgeSwitchesTest: defaults unchanged, settings apply, filters still win.\nOK\n";
    }
}

SettingsKnowledgeSwitchesTest::run();
