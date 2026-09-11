<?php
/**
 * Test suite for PressHub_AI_Prompt_Loader and prompt asset externalization.
 *
 * Verifies:
 *  - All 9 podcast dialogue prompt templates exist and load correctly from assets/prompts/
 *  - The news curation briefing prompt exists and loads correctly
 *  - Nameless presenter rule ("Απαγόρευση Ονομάτων") is present across all templates
 *  - TTS spoken date formatting rule ("Μορφοποίηση Ημερομηνιών για TTS") is present across all templates
 *  - Zero-Silent-Fallback: missing template files throw RuntimeException
 *  - Path traversal protection: relative paths with '..' throw InvalidArgumentException
 *  - Settings Storage get_curation_prompt() loads custom override or fallback default
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-prompt-loader.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';

$failures = 0;
function plt_check( string $desc, bool $ok ): void {
    global $failures;
    if ( ! $ok ) {
        $failures++;
        echo "FAIL: {$desc}\n";
    } else {
        echo "PASS: {$desc}\n";
    }
}

// 1. Verify prompt directory existence
$prompts_dir = PressHub_AI_Prompt_Loader::get_prompts_dir();
plt_check( 'prompts_dir exists', is_dir( $prompts_dir ) );
plt_check( 'prompts_dir podcast exists', is_dir( $prompts_dir . '/podcast' ) );
plt_check( 'prompts_dir curation exists', is_dir( $prompts_dir . '/curation' ) );
plt_check( 'prompts_dir scorecard exists', is_dir( $prompts_dir . '/scorecard' ) );

// 2. Verify all 9 podcast templates load and contain required rules
$styles = [ 'default_greek_chat', 'bbc_broadcasting_standards', 'conversational_news_reporting' ];
foreach ( $styles as $style ) {
    foreach ( [ 1, 2, 3 ] as $hosts ) {
        $prompt = PressHub_AI_Prompt_Loader::get_podcast_prompt( $hosts, $style );
        plt_check( "load podcast prompt {$style}_{$hosts}", ! empty( $prompt ) && is_string( $prompt ) );
        plt_check( "nameless speaker rule in {$style}_{$hosts}", false !== strpos( $prompt, 'Απαγόρευση Ονομάτων' ) );
        plt_check( "spoken date format rule in {$style}_{$hosts}", false !== strpos( $prompt, 'Μορφοποίηση Ημερομηνιών για TTS' ) && false !== strpos( $prompt, '03/05/2026' ) );
        plt_check( "speaker tag in {$style}_{$hosts}", false !== strpos( $prompt, '[SPEAKER_1]:' ) );
    }
}

// 3. Verify curation prompt loads
$curation_prompt = PressHub_AI_Prompt_Loader::get_curation_prompt();
plt_check( 'load curation prompt', ! empty( $curation_prompt ) && false !== strpos( $curation_prompt, 'Οδηγίες Σύνταξης:' ) && false !== strpos( $curation_prompt, '{sources_list}' ) );

// 3b. Verify scorecard prompt loads and contains temporal/factual directive
$scorecard_prompt = PressHub_AI_Prompt_Loader::get_scorecard_system_prompt();
plt_check( 'load scorecard prompt', ! empty( $scorecard_prompt ) && false !== strpos( $scorecard_prompt, 'CRITICAL FACTUAL AND TEMPORAL DIRECTIVE' ) && false !== strpos( $scorecard_prompt, 'knowledge cutoff' ) );

// 4. Verify Zero-Silent-Fallback on missing template
$caught_missing = false;
try {
    PressHub_AI_Prompt_Loader::load( 'podcast/non_existent_file.txt' );
} catch ( RuntimeException $e ) {
    $caught_missing = true;
}
plt_check( 'zero-silent-fallback: missing file throws RuntimeException', $caught_missing );

// 5. Verify Path Traversal protection
$caught_traversal = false;
try {
    PressHub_AI_Prompt_Loader::load( '../../wp-config.php' );
} catch ( InvalidArgumentException $e ) {
    $caught_traversal = true;
}
plt_check( 'security: path traversal throws InvalidArgumentException', $caught_traversal );

// 6. Verify Settings Storage get_curation_prompt fallback and override
$GLOBALS['OPTIONS_STORE'] = [];
// When empty, should return built-in template from loader
$default_curation = PressHub_AI_Settings_Storage::get_curation_prompt();
plt_check( 'settings_storage get_curation_prompt returns default when empty', $default_curation === $curation_prompt );

// When custom option is set, should return custom prompt
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_text_prompt'] = 'CUSTOM_CURATION_PROMPT_OVERRIDE';
$custom_curation = PressHub_AI_Settings_Storage::get_curation_prompt();
plt_check( 'settings_storage get_curation_prompt returns custom override', 'CUSTOM_CURATION_PROMPT_OVERRIDE' === $custom_curation );

// 7. Verify AJAX options map includes all per-style prompt options
require_once __DIR__ . '/../includes/class-ajax-handlers.php';
$reflector = new ReflectionClass( 'PressHub_AI_AJAX_Handlers' );
$method = $reflector->getMethod( 'save_settings' );
$source = file_get_contents( __DIR__ . '/../includes/class-ajax-handlers.php' );
plt_check( 'ajax_save_settings contains podcast_style in options_map', false !== strpos( $source, "'presshub_ai_briefing_podcast_style'" ) );
foreach ( $styles as $style ) {
    foreach ( [ 1, 2, 3 ] as $hosts ) {
        $opt = "'presshub_ai_briefing_podcast_prompt_{$hosts}_{$style}'";
        plt_check( "ajax_save_settings contains {$opt} in options_map", false !== strpos( $source, $opt ) );
    }
}

// 8. Verify Article and Daily Briefing QA prompts
$article_qa = PressHub_AI_Prompt_Loader::get_article_qa_prompt();
plt_check( 'load article qa prompt', ! empty( $article_qa ) && false !== strpos( $article_qa, '{content}' ) && false !== strpos( $article_qa, 'cutoff date' ) );

$briefing_qa = PressHub_AI_Prompt_Loader::get_briefing_qa_prompt();
plt_check( 'load briefing qa prompt', ! empty( $briefing_qa ) && false !== strpos( $briefing_qa, '{content}' ) && false !== strpos( $briefing_qa, 'cutoff date' ) );

// 9. Verify Settings Storage get_qa_article_prompt & get_qa_briefing_prompt fallback and override
$GLOBALS['OPTIONS_STORE'] = [];
plt_check( 'settings_storage get_qa_article_prompt returns default when empty', PressHub_AI_Settings_Storage::get_qa_article_prompt() === $article_qa );
plt_check( 'settings_storage get_qa_briefing_prompt returns default when empty', PressHub_AI_Settings_Storage::get_qa_briefing_prompt() === $briefing_qa );

$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_article_prompt'] = 'CUSTOM_ARTICLE_QA_PROMPT';
$GLOBALS['OPTIONS_STORE']['presshub_ai_qa_briefing_prompt'] = 'CUSTOM_BRIEFING_QA_PROMPT';
plt_check( 'settings_storage get_qa_article_prompt returns custom override', 'CUSTOM_ARTICLE_QA_PROMPT' === PressHub_AI_Settings_Storage::get_qa_article_prompt() );
plt_check( 'settings_storage get_qa_briefing_prompt returns custom override', 'CUSTOM_BRIEFING_QA_PROMPT' === PressHub_AI_Settings_Storage::get_qa_briefing_prompt() );

// 10. Verify ajax_save_settings contains QA prompt options in options_map
plt_check( 'ajax_save_settings contains presshub_ai_qa_article_prompt', false !== strpos( $source, "'presshub_ai_qa_article_prompt'" ) );
plt_check( 'ajax_save_settings contains presshub_ai_qa_briefing_prompt', false !== strpos( $source, "'presshub_ai_qa_briefing_prompt'" ) );

if ( $failures > 0 ) {
    echo "PromptLoaderTest: {$failures} failure(s)\n";
    exit( 1 );
}

echo "PromptLoaderTest: OK\n";
exit( 0 );