<?php
/**
 * #309 (E2): the Settings form without JavaScript.
 *
 * The form used to post to options.php with the whole presshub_ai_options
 * group: Social options were ignored, every other section's unposted
 * checkboxes were reset and no audit event was written. It now posts one
 * section to admin-post.php (PressHub_Settings_Sections::POST_ACTION), which
 * runs the same save as the AJAX Save.
 *
 *  1. The form posts to admin-post.php with its action, section and nonce,
 *     and prints no options.php fields.
 *  2. A no-JS save writes only that section (fields of other sections in the
 *     request are ignored, their checkboxes keep their value), unslashes the
 *     request, writes a settings_saved audit event and returns to the section.
 *  3. A Social section saves its Social options.
 *  4. A wrong-type model is refused: nothing is written and the error is shown.
 *  5. No nonce or no capability: nothing is written.
 *  6. The result notice shows once after the redirect.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) { $GLOBALS['DEACTIVATION_HOOKS'][] = $callback; }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $location, $status = 302 ) {
        $GLOBALS['REDIRECTED_TO'] = $location;
        throw new RuntimeException( 'redirect' );
    }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';
require_once __DIR__ . '/settings-sections-helper.php';

$failures = [];
$check    = static function ( bool $ok, string $what ) use ( &$failures ): void {
    if ( ! $ok ) {
        $failures[] = $what;
    }
};

$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files', 'manage_options' ];
$GLOBALS['CURRENT_USER_ID']   = 1;
( new PressHub_AI_Settings() )->register_settings();
PressHub_Social_Settings::get_instance()->register_settings();
$ajax = new PressHub_AI_Ajax_Handlers();

/** Posts the no-JS form: $_POST as WordPress slashes it. Returns the redirect target or the error. */
$post_form = static function ( string $section, array $fields, bool $nonce = true ) use ( $ajax ): string {
    $_POST                    = array_merge( [ 'action' => PressHub_Settings_Sections::POST_ACTION, 'presshub_settings_section' => $section, '_wpnonce' => 'n' ], function_exists( 'wp_slash' ) ? wp_slash( $fields ) : array_map( 'addslashes_deep_test', $fields ) );
    $_REQUEST                 = $_POST;
    $GLOBALS['NONCE_VALID']   = $nonce;
    $GLOBALS['REDIRECTED_TO'] = '';
    try {
        $ajax->handle_section_post();
    } catch ( Throwable $t ) {
        $_POST = [];
        return 'redirect' === $t->getMessage() ? (string) $GLOBALS['REDIRECTED_TO'] : 'error: ' . $t->getMessage();
    }
    $_POST = [];
    return '';
};
if ( ! function_exists( 'addslashes_deep_test' ) ) {
    function addslashes_deep_test( $v ) {
        return is_array( $v ) ? array_map( 'addslashes_deep_test', $v ) : addslashes( (string) $v );
    }
}
$audit_rows = static function (): int {
    global $wpdb;
    $n = 0;
    foreach ( (array) ( $wpdb->tables ?? [] ) as $name => $rows ) {
        if ( false === strpos( (string) $name, 'audit' ) ) {
            continue;
        }
        foreach ( (array) $rows as $row ) {
            if ( 'settings_saved' === ( $row['event_type'] ?? '' ) ) {
                $n++;
            }
        }
    }
    return $n;
};

// 1. The form.
$html = presshub_test_render_section( 'site-system' );
$check( (bool) preg_match( '/<form[^>]+action="[^"]*admin-post\.php"[^>]*id="presshub-ai-settings-form"/', $html ), 'The settings form posts to admin-post.php.' );
$check( false !== strpos( $html, 'name="action" value="' . PressHub_Settings_Sections::POST_ACTION . '"' ), 'The form carries the section-save action.' );
$check( false !== strpos( $html, 'name="presshub_settings_section" value="site-system"' ), 'The form carries its section.' );
$check( false === strpos( $html, 'name="option_page"' ), 'The form prints no options.php option_page field.' );

// 2. One section only, unslashed, audited, back to the section.
$GLOBALS['OPTIONS_STORE'] = [
    'presshub_ai_debug_prompts'      => 1,
    'presshub_ai_qa_enabled'         => 1,
    'presshub_ai_image_feature_enabled' => 1,
    'presshub_ai_coauthor_max_source_chars' => 200000,
];
$audits_before = $audit_rows();
$to = $post_form( 'site-system', [
    'presshub_ai_log_level'                 => 'INFO',
    'presshub_ai_research_retention_days'   => '9',
    // A field of another section in the request is ignored.
    'presshub_ai_coauthor_max_source_chars' => '5000',
] );
$check( false !== strpos( $to, 'section=site-system' ) && false !== strpos( $to, 'presshub_saved=1' ), 'A no-JS save returns to its section with presshub_saved=1; got ' . $to );
$check( 'INFO' === get_option( 'presshub_ai_log_level' ), 'The section option is saved.' );
$check( 9 === (int) get_option( 'presshub_ai_research_retention_days' ), 'A number in the section is saved.' );
$check( 0 === (int) get_option( 'presshub_ai_debug_prompts' ), 'An unticked checkbox of the section saves as 0.' );
$check( 1 === (int) get_option( 'presshub_ai_qa_enabled' ) && 1 === (int) get_option( 'presshub_ai_image_feature_enabled' ), 'Checkboxes of other sections keep their value.' );
$check( 200000 === (int) get_option( 'presshub_ai_coauthor_max_source_chars' ), 'An option of another section in the request is not written.' );
$check( $audit_rows() > $audits_before, 'A no-JS save writes a settings_saved audit event.' );
$result = get_transient( PressHub_Settings_Sections::post_result_key( 1 ) );
$check( is_array( $result ) && ! empty( $result['ok'] ) && 'site-system' === $result['section'], 'The result is kept for the page after the redirect.' );

// Slashes: a value with a quote is stored once, unslashed.
$post_form( 'daily-briefing', [ 'presshub_ai_briefing_podcast_title_prefix' => "Today's podcast - " ] );
$check( "Today's podcast - " === get_option( 'presshub_ai_briefing_podcast_title_prefix' ), 'A no-JS save stores the value unslashed; got ' . var_export( get_option( 'presshub_ai_briefing_podcast_title_prefix' ), true ) );

// 3. A Social section saves its Social options.
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_social_ai_temperature' => 0.7 ];
$post_form( 'ai-models', [ 'presshub_social_ai_temperature' => '0.4' ] );
$check( abs( (float) get_option( 'presshub_social_ai_temperature' ) - 0.4 ) < 0.001, 'A Social option of the section is saved without JavaScript; got ' . var_export( get_option( 'presshub_social_ai_temperature' ), true ) );

// 4. A wrong-type model is refused before anything is written.
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_copilot_temperature' => 0.7 ];
$post_form( 'ai-models', [ 'presshub_social_ai_model' => 'gemini-2.5-flash-preview-tts', 'presshub_ai_copilot_temperature' => '0.2' ] );
$check( false === get_option( 'presshub_social_ai_model', false ), 'A refused model is not stored.' );
$check( abs( (float) get_option( 'presshub_ai_copilot_temperature' ) - 0.7 ) < 0.001, 'A refused save writes nothing else either.' );
$result = get_transient( PressHub_Settings_Sections::post_result_key( 1 ) );
$check( is_array( $result ) && empty( $result['ok'] ) && isset( $result['field_errors']['presshub_social_ai_model'] ), 'The refusal and its field error are kept for the page.' );

// 6. The notice shows once (here: the refusal from step 4).
$_GET    = [ 'page' => 'presshub-ai', 'section' => 'ai-models', 'presshub_saved' => '1' ];
$notice  = presshub_test_render_section( 'ai-models' );
$check( false !== strpos( $notice, 'notice-error' ) && false !== strpos( $notice, 'href="#presshub_social_ai_model"' ), 'After the redirect the page shows the refusal with a link to the field.' );
$again   = presshub_test_render_section( 'ai-models' );
$check( false === strpos( $again, 'href="#presshub_social_ai_model"' ) || substr_count( $again, 'notice-error' ) < substr_count( $notice, 'notice-error' ), 'The notice shows only once.' );
$_GET    = [];

// 5. No nonce, or no capability: nothing is written.
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_log_level' => 'DEBUG' ];
$r = $post_form( 'site-system', [ 'presshub_ai_log_level' => 'ERROR' ], false );
$check( 0 === strpos( $r, 'error:' ) && 'DEBUG' === get_option( 'presshub_ai_log_level' ), 'Without a valid nonce nothing is saved.' );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts' ];
$r = $post_form( 'site-system', [ 'presshub_ai_log_level' => 'ERROR' ] );
$check( 0 === strpos( $r, 'error:' ) && 'DEBUG' === get_option( 'presshub_ai_log_level' ), 'Without the PressHub capability nothing is saved.' );

if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}
echo "SettingsNoJsSaveTest: OK\n";
