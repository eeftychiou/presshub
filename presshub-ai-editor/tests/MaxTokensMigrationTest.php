<?php
/**
 * MaxTokensMigrationTest — the 1.2.8 migration must clear per-provider
 * max_tokens options still holding the OLD default (2000) so the new
 * 10000 default applies, while leaving deliberate custom values alone.
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
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
function mtm_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

// --- Case 1: stale 2000 cleared, custom value kept -------------------------
$GLOBALS['OPTIONS_STORE'] = [];
$GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_openai']    = '2000'; // stale default
$GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_anthropic']  = '4000'; // deliberate
$GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_gemini']     = '2000'; // stale default

presshub_ai_migrate_max_tokens_defaults();

mtm_check( 'stale openai 2000 cleared', ! isset( $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_openai'] ) );
mtm_check( 'stale gemini 2000 cleared', ! isset( $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_gemini'] ) );
mtm_check( 'custom anthropic 4000 kept', ( $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_anthropic'] ?? '' ) === '4000' );
mtm_check( 'migration flag set', ( $GLOBALS['OPTIONS_STORE']['presshub_ai_migrated_max_tokens'] ?? '' ) === '1' );

// The cleared providers now resolve to the new default.
$api = new PressHub_AI_API_Client();
mtm_check( 'default after migration is 10000', 10000 === PressHub_AI_Provider_Defaults::default_max_tokens() );
mtm_check( 'meta reflects 10000 default', false !== strpos( PressHub_AI_API_Client::current_request_meta(), 'max_tokens=10000' ) );

// --- Case 2: idempotent — second run changes nothing ------------------------
$before = $GLOBALS['OPTIONS_STORE'];
presshub_ai_migrate_max_tokens_defaults();
mtm_check( 'second run is a no-op', $before === $GLOBALS['OPTIONS_STORE'] );

// --- Case 3: migration flag already set → untouched stale values ------------
$GLOBALS['OPTIONS_STORE'] = [
    'presshub_ai_migrated_max_tokens' => '1',
    'presshub_ai_max_tokens_openai'   => '2000',
];
presshub_ai_migrate_max_tokens_defaults();
mtm_check( 'flag-set run leaves values alone', isset( $GLOBALS['OPTIONS_STORE']['presshub_ai_max_tokens_openai'] ) );

if ( $failures > 0 ) {
    fwrite( STDERR, "MaxTokensMigrationTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "MaxTokensMigrationTest: OK (8 checks)\n";
