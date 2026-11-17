<?php
/**
 * Test suite for Editorial QA Subtabs & Tabbed Prompt Studio UI in Settings.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-token-logger.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';
require_once __DIR__ . '/../includes/class-prompt-loader.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-settings-render.php';
require_once __DIR__ . '/../includes/class-settings.php';

class SettingsQAPromptStudioTest
{
    public static function run(): void {
        $failures = [];

        // -------------------------------------------------------------
        // Case 1: Tab 2 Subtabs Render Structure
        // -------------------------------------------------------------
        self::reset_world();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_others_posts' ];
        $settings = new PressHub_AI_Settings();
        $settings->register_settings();

        ob_start();
        $settings->render_settings_page();
        $html = ob_get_clean();

        if ( false === strpos( $html, 'class="presshub-subtab-wrapper nav-tab-wrapper presshub-coauthor-subtabs"' ) ) {
            $failures[] = 'Co-Author subtab wrapper .presshub-coauthor-subtabs missing from settings render.';
        }

        if ( false === strpos( $html, 'data-subtab="drafting"' ) || false === strpos( $html, 'data-subtab="qa"' ) ) {
            $failures[] = 'Co-Author subtabs data-subtab="drafting" or data-subtab="qa" missing.';
        }

        if ( false === strpos( $html, 'id="presshub-subpane-coauthor-drafting"' ) ) {
            $failures[] = 'Subpane #presshub-subpane-coauthor-drafting missing from settings render.';
        }

        if ( false === strpos( $html, 'id="presshub-subpane-coauthor-qa"' ) ) {
            $failures[] = 'Subpane #presshub-subpane-coauthor-qa missing from settings render.';
        }

        // Verify QA form fields exist inside the QA subpane
        $qa_fields = [
            'presshub_ai_qa_enabled',
            'presshub_ai_qa_include_briefings',
            'presshub_ai_qa_min_score',
            'presshub_ai_qa_notify_editor',
            'presshub_ai_qa_editor_email',
        ];
        foreach ( $qa_fields as $field ) {
            if ( false === strpos( $html, 'name="' . $field . '"' ) ) {
                $failures[] = "QA field {$field} missing from settings render HTML.";
            }
        }

        // -------------------------------------------------------------
        // Case 2: QA Prompt Studio (article prompt) on Co-Author; the
        // briefing QA prompt moved to Daily Briefing -> Text story (#234).
        // -------------------------------------------------------------
        if ( false === strpos( $html, 'id="presshub-qa-pane-article"' ) ) {
            $failures[] = '#presshub-qa-pane-article missing from settings render.';
        }
        if ( false === strpos( $html, 'name="presshub_ai_qa_article_prompt"' ) ) {
            $failures[] = 'Textarea presshub_ai_qa_article_prompt missing from settings render.';
        }
        if ( false === strpos( $html, 'data-target="presshub_ai_qa_article_prompt"' ) ) {
            $failures[] = 'Action button for presshub_ai_qa_article_prompt missing.';
        }
        if ( 1 !== substr_count( $html, 'name="presshub_ai_qa_briefing_prompt"' ) ) {
            $failures[] = 'presshub_ai_qa_briefing_prompt must render exactly once.';
        }
        if ( false === strpos( $html, 'data-target="presshub_ai_qa_briefing_prompt"' ) ) {
            $failures[] = 'Action button for presshub_ai_qa_briefing_prompt missing.';
        }

        // -------------------------------------------------------------
        // Case 3: the briefing tab holds the briefing QA prompt and the gate
        // notice; the gate switches stay on Co-Author.
        // -------------------------------------------------------------
        $tab3_start = strpos( $html, 'id="presshub-tab-pane-briefing"' );
        $tab4_start = strpos( $html, 'id="presshub-tab-pane-social_listening"' );
        if ( false === $tab3_start || false === $tab4_start ) {
            $failures[] = 'Could not locate the briefing tab boundaries in HTML.';
        } else {
            $tab3_html = substr( $html, $tab3_start, $tab4_start - $tab3_start );
            if ( false !== strpos( $tab3_html, 'name="presshub_ai_qa_include_briefings"' ) ) {
                $failures[] = 'The briefing tab must not render presshub_ai_qa_include_briefings (it stays on the QA gate).';
            }
            if ( false === strpos( $tab3_html, 'name="presshub_ai_qa_briefing_prompt"' ) ) {
                $failures[] = 'The briefing tab must render presshub_ai_qa_briefing_prompt in Text story.';
            }
            if ( false === strpos( $tab3_html, 'presshub-switch-to-qa-studio' ) ) {
                $failures[] = 'The briefing tab is missing the link to the QA review gate.';
            }
        }

        // -------------------------------------------------------------
        // Case 4: Settings Storage Section Maps & Persistence
        // -------------------------------------------------------------
        $coauthor_map = PressHub_AI_Settings_Storage::get_section_options_map( 'coauthor' );
        $briefing_map = PressHub_AI_Settings_Storage::get_section_options_map( 'briefing' );
        if ( ! isset( $briefing_map['presshub_ai_qa_briefing_prompt'] ) || isset( $coauthor_map['presshub_ai_qa_briefing_prompt'] ) ) {
            $failures[] = 'presshub_ai_qa_briefing_prompt should save with the briefing tab only.';
        }
        if ( ! isset( $coauthor_map['presshub_ai_qa_article_prompt'] ) ) {
            $failures[] = 'presshub_ai_qa_article_prompt missing from get_section_options_map("coauthor").';
        }
        if ( ! isset( $coauthor_map['presshub_ai_qa_enabled'] ) ) {
            $failures[] = 'presshub_ai_qa_enabled missing from get_section_options_map("coauthor").';
        }

        // Test QA section alias map
        $qa_map = PressHub_AI_Settings_Storage::get_section_options_map( 'qa' );
        if ( empty( $qa_map ) || ! isset( $qa_map['presshub_ai_qa_article_prompt'] ) ) {
            $failures[] = 'get_section_options_map("qa") should return QA options map.';
        }

        // Test saving coauthor section with customized prompts
        self::reset_world();
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'manage_options', 'edit_others_posts' ];
        $handlers = new PressHub_AI_Ajax_Handlers();
        $_POST = [
            'action'                           => 'presshub_ai_save_settings_section',
            'nonce'                            => 'valid_nonce',
            'tab'                              => 'coauthor',
            'presshub_ai_coauthor_provider'    => 'openai',
            'presshub_ai_qa_enabled'           => '1',
            'presshub_ai_qa_include_briefings' => '1',
            'presshub_ai_qa_min_score'         => '85',
            'presshub_ai_qa_article_prompt'    => 'Custom article QA prompt template',
        ];

        try {
            $handlers->save_settings_section();
        } catch ( Throwable $e ) {}

        $saved_article_prompt  = get_option( 'presshub_ai_qa_article_prompt' );
        $saved_briefing_prompt = get_option( 'presshub_ai_qa_briefing_prompt' );
        $saved_qa_score        = get_option( 'presshub_ai_qa_min_score' );

        if ( 'Custom article QA prompt template' !== $saved_article_prompt ) {
            $failures[] = 'Failed to save presshub_ai_qa_article_prompt via coauthor tab. Got: ' . var_export( $saved_article_prompt, true );
        }
        if ( 85 !== (int) $saved_qa_score ) {
            $failures[] = 'Failed to save presshub_ai_qa_min_score via coauthor tab. Got: ' . var_export( $saved_qa_score, true );
        }

        if ( ! empty( $failures ) ) {
            echo "FAIL: SettingsQAPromptStudioTest\n";
            foreach ( $failures as $failure ) {
                echo "  - {$failure}\n";
            }
            exit( 1 );
        }

        echo "OK: SettingsQAPromptStudioTest\n";
        exit( 0 );
    }

    private static function reset_world(): void {
        $GLOBALS['WP_OPTIONS']               = [];
        $GLOBALS['CURRENT_USER_CAPS']       = [ 'manage_options', 'edit_others_posts' ];
        $GLOBALS['REGISTERED_SETTINGS']     = [];
        $GLOBALS['SETTINGS_SECTIONS']        = [];
        $GLOBALS['SETTINGS_FIELDS']          = [];
        $GLOBALS['SANITIZE_CALLBACKS']       = [];
        $GLOBALS['RENDERED_SETTINGS_FIELDS'] = [];
        $GLOBALS['RENDERED_SECTIONS']        = [];
        $_POST                               = [];
    }
}

if ( php_sapi_name() === 'cli' && basename( __FILE__ ) === basename( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) {
    SettingsQAPromptStudioTest::run();
}
