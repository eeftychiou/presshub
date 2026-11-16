<?php
/**
 * #261: social prompts and templates are stored the same way whether they
 * come from a settings save, a seeded default or an import.
 *
 *  1. normalize_multiline() turns CRLF/CR into LF and trims.
 *  2. sanitize_prompt() / sanitize_template() use it.
 *  3. The prompt loader returns normalized asset text, so seeds match saves.
 *  4. The one-time migration rewrites stored values with a trailing newline
 *     and sets its flag.
 *  5. Importing a value that sanitizes to what is stored writes nothing.
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

class SocialPromptNewlinesTest {

    public static function run(): void {
        $failures = [];
        $S        = 'PressHub_Social_Settings_Storage';

        // 1.
        if ( "a\nb" !== $S::normalize_multiline( "  a\r\nb\r\n" ) ) {
            $failures[] = 'normalize_multiline should convert CRLF and trim.';
        }
        if ( "a\nb" !== $S::normalize_multiline( "a\rb\n\n" ) ) {
            $failures[] = 'normalize_multiline should convert CR and trim trailing newlines.';
        }

        // 2.
        if ( "line1\nline2" !== $S::sanitize_prompt( "line1\r\nline2\r\n" ) ) {
            $failures[] = 'sanitize_prompt should normalize line endings.';
        }
        if ( "x\ny" !== $S::sanitize_template( "x\r\ny\n" ) ) {
            $failures[] = 'sanitize_template should normalize line endings.';
        }

        // 3.
        $loaded = PressHub_Social_Prompt_Loader::load( 'social_copy_twitter.txt' );
        if ( $loaded !== $S::normalize_multiline( $loaded ) || false !== strpos( $loaded, "\r" ) ) {
            $failures[] = 'The prompt loader should return normalized text.';
        }
        if ( $S::sanitize_prompt( $loaded ) !== $loaded ) {
            $failures[] = 'A seeded prompt should equal its sanitized form.';
        }

        // 4.
        $GLOBALS['OPTIONS_STORE']['presshub_social_prompt_twitter']   = "Prompt text\r\n";
        $GLOBALS['OPTIONS_STORE']['presshub_social_template_facebook'] = "Template\n";
        $GLOBALS['OPTIONS_STORE']['presshub_social_prompt_tiktok_shorts'] = 'Already clean';
        unset( $GLOBALS['OPTIONS_STORE']['presshub_social_migrated_prompt_newlines'] );
        $S::migrate_normalize_prompt_newlines();
        if ( 'Prompt text' !== get_option( 'presshub_social_prompt_twitter' ) ) {
            $failures[] = 'Migration should trim the twitter prompt.';
        }
        if ( 'Template' !== get_option( 'presshub_social_template_facebook' ) ) {
            $failures[] = 'Migration should trim the facebook template.';
        }
        if ( 'Already clean' !== get_option( 'presshub_social_prompt_tiktok_shorts' ) ) {
            $failures[] = 'Migration should leave clean values alone.';
        }
        if ( '1' !== get_option( 'presshub_social_migrated_prompt_newlines' ) ) {
            $failures[] = 'Migration should set its flag.';
        }
        $GLOBALS['OPTIONS_STORE']['presshub_social_prompt_twitter'] = "Again\n";
        $S::migrate_normalize_prompt_newlines();
        if ( "Again\n" !== get_option( 'presshub_social_prompt_twitter' ) ) {
            $failures[] = 'Migration should run only once.';
        }

        // 5.
        PressHub_Settings_Registry::reset();
        PressHub_Settings_Registry::ensure_registered();
        $GLOBALS['OPTIONS_STORE']['presshub_social_prompt_twitter'] = 'Same prompt';
        $written = PressHub_Settings_Transfer::apply( [ 'presshub_social_prompt_twitter' => "Same prompt\n" ] );
        if ( in_array( 'presshub_social_prompt_twitter', $written, true ) ) {
            $failures[] = 'Import should not report a value that sanitizes to the stored one.';
        }
        $written = PressHub_Settings_Transfer::apply( [ 'presshub_social_prompt_twitter' => 'New prompt' ] );
        if ( ! in_array( 'presshub_social_prompt_twitter', $written, true ) || 'New prompt' !== get_option( 'presshub_social_prompt_twitter' ) ) {
            $failures[] = 'Import should still write a real change.';
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

SocialPromptNewlinesTest::run();
