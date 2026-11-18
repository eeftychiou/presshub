<?php
/**
 * Settings → Connections (#247, settings overhaul phase 3c).
 *
 * Verifies:
 *  1. Every secret is in Connections; the press inbox login is there and its
 *     folders and filters stay in Daily Briefing; AI provider cards moved in.
 *  2. Loading Connections (and the setup check on the Daily Briefing Hub)
 *     makes no remote request, even with the HTTP API failing.
 *  3. The status cache: record, describe (ok, warning, error, expired,
 *     unknown), the "checked" time, forget.
 *  4. Tests record their result and return the new pill; a test of unsaved
 *     modal fields records nothing.
 *  5. One "Replace key" pattern: no saved value in the page, empty keeps
 *     the key, a new value replaces it, Remove still clears it.
 *  6. Regenerate worker secret: nonce and the Social capability, a new
 *     secret, a confirmation that says the worker stops.
 *  7. The setup check lists blocking problems with links, or says
 *     "Everything is connected".
 *  8. No developer details or unused secrets on the screen.
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
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = [] ) {
        $GLOBALS['CAPTURED_OTHER'][] = [ $url, $args ];
        return new WP_Error( 'no_network', 'stub: network disabled in tests' );
    }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';
require_once __DIR__ . '/settings-sections-helper.php';

$failures = [];
$check    = static function ( bool $ok, string $msg ) use ( &$failures ) {
    if ( ! $ok ) {
        $failures[] = $msg;
    }
};

$ALL_CAPS = [ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files', 'manage_options' ];
$GLOBALS['CURRENT_USER_CAPS'] = $ALL_CAPS;
$GLOBALS['CURRENT_USER_ID']   = 1;
( new PressHub_AI_Settings() )->register_settings();
PressHub_Social_Settings::get_instance()->register_settings();

function cnt_ajax( callable $fn, array $post, bool $nonce = true ): ?array {
    $_POST                     = array_merge( [ 'nonce' => 'valid_nonce' ], $post );
    $_REQUEST                  = $_POST;
    $GLOBALS['NONCE_VALID']    = $nonce;
    $GLOBALS['JSON_RESPONSES'] = [];
    try {
        $fn();
    } catch ( Throwable $t ) {
        // wp_send_json_* stubs and a failed nonce throw to end the request.
    }
    $_POST = [];
    $r     = end( $GLOBALS['JSON_RESPONSES'] );
    return is_array( $r ) ? $r : null;
}

function cnt_reset_http(): void {
    $GLOBALS['CAPTURED_REQUESTS'] = [];
    $GLOBALS['CAPTURED_GETS']     = [];
    $GLOBALS['CAPTURED_OTHER']    = [];
}

function cnt_http_calls(): int {
    return count( $GLOBALS['CAPTURED_REQUESTS'] ?? [] ) + count( $GLOBALS['CAPTURED_GETS'] ?? [] ) + count( $GLOBALS['CAPTURED_OTHER'] ?? [] );
}

$ajax = new PressHub_AI_Ajax_Handlers();

// ---------------------------------------------------------------------
// 1. What lives in Connections.
// ---------------------------------------------------------------------
$secrets = [];
foreach ( PressHub_Settings_Registry::all() as $key => $e ) {
    if ( ! empty( $e['secret'] ) && ! PressHub_Settings_Registry::is_unused( $key ) ) {
        $secrets[] = $key;
        $check( 'connections' === PressHub_Settings_Sections::section_of( (string) $key ), "Secret {$key} should be in Connections; it is in " . PressHub_Settings_Sections::section_of( (string) $key ) . '.' );
    }
}
$check( in_array( 'presshub_ai_briefing_tts_api_key', $secrets, true ), 'The speech key should be an active secret.' );
foreach ( [ 'host', 'port', 'encryption', 'verify_ssl', 'username', 'password', 'timeout' ] as $f ) {
    $check( 'connections' === PressHub_Settings_Sections::section_of( 'presshub_ai_press_inbox_' . $f ), "The press inbox {$f} should be in Connections." );
}
foreach ( [ 'enabled', 'folder', 'processed_folder', 'allowed_senders', 'max_messages', 'max_age_hours', 'max_attachment_mb', 'extract_attachments' ] as $f ) {
    $check( 'daily-briefing' === PressHub_Settings_Sections::section_of( 'presshub_ai_press_inbox_' . $f ), "The press inbox {$f} should stay in Daily Briefing." );
}
$target = PressHub_Settings_Sections::legacy_target( 'ai', 'providers' );
$check( $target && 'connections' === $target['section'] && 'ai-providers' === $target['anchor'], 'The old Providers tab should open Connections → AI providers.' );
$hashes = PressHub_Settings_Sections::legacy_hash_urls();
$check( isset( $hashes['ai-providers'] ) && false !== strpos( $hashes['ai-providers'], 'section=connections#ai-providers' ), 'The #ai-providers hash should lead to Connections.' );

// ---------------------------------------------------------------------
// 2. Loading Connections makes no remote request.
// ---------------------------------------------------------------------
$GLOBALS['OPTIONS_STORE'] = [
    'presshub_social_meta_access_token'    => 'EAAB-secret-meta-token-12345',
    'presshub_social_twitter_bearer_token' => 'bearer-secret-67890',
    'presshub_social_tiktok_access_token'  => 'tiktok-secret-abcde',
    'presshub_social_worker_secret'        => 'worker-secret-fghij-0000',
    'presshub_social_worker_mode'          => 'push',
    'presshub_social_worker_url'           => 'http://127.0.0.1:3456',
    'presshub_ai_github_token'             => 'ghp_secretgithubtoken999',
    'presshub_ai_google_cloud_api_key'     => 'AIza-secret-google-cloud',
    'presshub_ai_briefing_tts_api_key'     => 'AIza-secret-speech-key',
    'presshub_ai_press_inbox_password'     => 'SuperSecretMailbox1',
    'presshub_ai_press_inbox_host'         => 'mail.example.test',
    'presshub_ai_press_inbox_username'     => 'press@example.test',
    'presshub_ai_press_inbox_enabled'      => 1,
    'presshub_ai_configured_providers'     => [],
];
PressHub_AI_Provider_Store::save_provider( [ 'id' => 'my-gemini', 'name' => 'My Gemini', 'type' => 'gemini', 'api_key' => 'AIza-secret-provider-key', 'default_model' => 'gemini-2.0-flash', 'enabled' => true ] );
$GLOBALS['CAPTURE_FILTER']      = static function () { return new WP_Error( 'blocked', 'HTTP blocked by ConnectionsTest' ); };
$GLOBALS['GET_RESPONSE_FILTER'] = static function () { return new WP_Error( 'blocked', 'HTTP blocked by ConnectionsTest' ); };
cnt_reset_http();
$html = presshub_test_render_section( 'connections' );
$check( 0 === cnt_http_calls(), 'Loading Connections should make no remote request; made ' . cnt_http_calls() . '.' );
cnt_reset_http();
PressHub_Setup_Check::problems();
PressHub_Setup_Check::render( true );
$check( 0 === cnt_http_calls(), 'The setup check should make no remote request.' );
cnt_reset_http();
ob_start();
try {
    ( new PressHub_AI_Briefing_Admin() )->render_hub_page( '2026-11-18' );
} catch ( Throwable $t ) {
    $failures[] = 'The Daily Briefing Hub should render: ' . $t->getMessage();
}
$hub = (string) ob_get_clean();
$check( false !== strpos( $hub, 'id="presshub-setup-check-hub"' ), 'The Daily Briefing Hub should show the setup check box.' );
$check( false === strpos( $hub, 'role="alert"' ) || false === strpos( substr( $hub, (int) strpos( $hub, 'presshub-setup-check-hub' ) - 200, 400 ), 'role="alert"' ), 'The setup check should not be an alert.' );

// The page itself.
$check( false !== strpos( $html, 'id="presshub-providers-grid"' ), 'Connections should hold the AI provider cards.' );
$check( false !== strpos( $html, 'id="presshub-provider-modal"' ) || false !== strpos( $html, 'presshub-provider-modal' ), 'Connections should hold the provider modal.' );
$ai_models = presshub_test_render_section( 'ai-models' );
$check( false === strpos( $ai_models, 'id="presshub-providers-grid"' ), 'AI models should no longer hold the provider cards.' );
$check( false !== strpos( $ai_models, 'section=connections#ai-providers' ), 'AI models should link to the provider keys in Connections.' );
$check( false !== strpos( $html, 'id="presshub-setup-check"' ) && false !== strpos( $html, '<h2 class="presshub-setup-check-title">' ), 'Connections should open with the setup check under a heading.' );
foreach ( [ 'ai-providers', 'google-cloud', 'press-inbox', 'social-accounts', 'automation-worker', 'plugin-updates' ] as $g ) {
    $check( false !== strpos( $html, 'id="' . $g . '"' ), "Connections should have the {$g} group." );
}
$briefing = presshub_test_render_section( 'daily-briefing' );
$check( false !== strpos( $briefing, 'name="presshub_ai_press_inbox_folder"' ) && false === strpos( $briefing, 'name="presshub_ai_press_inbox_host"' ), 'Daily Briefing should keep the press inbox folders, not its login.' );
$check( false !== strpos( $briefing, 'section=connections#press-inbox' ) && false !== strpos( $html, 'section=daily-briefing#briefing-sources' ), 'The press inbox should link both ways.' );

// ---------------------------------------------------------------------
// 5. Replace key: no saved value on the page, one pattern everywhere.
// ---------------------------------------------------------------------
foreach ( [ 'EAAB-secret-meta-token-12345', 'bearer-secret-67890', 'tiktok-secret-abcde', 'worker-secret-fghij-0000', 'ghp_secretgithubtoken999', 'AIza-secret-google-cloud', 'AIza-secret-speech-key', 'SuperSecretMailbox1', 'AIza-secret-provider-key' ] as $raw ) {
    $check( false === strpos( $html, $raw ), "Connections should never print a saved secret ({$raw})." );
}
foreach ( [ 'presshub_social_meta_access_token', 'presshub_social_twitter_bearer_token', 'presshub_social_tiktok_access_token', 'presshub_social_worker_secret', 'presshub_ai_github_token', 'presshub_ai_google_cloud_api_key', 'presshub_ai_briefing_tts_api_key', 'presshub_ai_press_inbox_password' ] as $name ) {
    $check( false !== strpos( $html, 'data-secret-for="' . $name . '"' ), "{$name} should use the Replace key field." );
    $check( false !== strpos( $html, 'name="' . $name . '" id="' . $name . '" value=""' ), "{$name} should post empty unless a new value is typed." );
}
$outside_modal = (string) preg_replace( '/<div[^>]+id="presshub-provider-modal".*/s', '', $html );
$check( preg_match_all( '/<input type="password"/', $outside_modal ) === preg_match_all( '/<div class="presshub-secret" /', $outside_modal ), 'Every password input outside the provider modal should belong to a Replace key field.' );
foreach ( [ 'presshub_ai_remove_github_token', 'presshub_ai_remove_google_cloud_api_key', 'presshub_ai_remove_briefing_tts_api_key', 'presshub_ai_remove_press_inbox_password', 'presshub_social_meta_access_token_remove' ] as $remove ) {
    $check( false !== strpos( $html, 'name="' . $remove . '"' ), "The remove box {$remove} should still be there." );
}

$save = static function ( array $payload ) use ( $ajax ) {
    return cnt_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'connections', 'payload_b64' => base64_encode( (string) json_encode( $payload ) ) ] );
};
$GLOBALS['OPTIONS_STORE']['presshub_ai_github_token']         = 'ghp_keep';
$GLOBALS['OPTIONS_STORE']['presshub_ai_press_inbox_password'] = 'keep-pass';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_api_key'] = 'keep-tts';
$GLOBALS['OPTIONS_STORE']['presshub_social_meta_access_token'] = 'keep-meta';
$save( [ 'presshub_ai_github_token' => '', 'presshub_ai_press_inbox_password' => '', 'presshub_ai_briefing_tts_api_key' => '', 'presshub_social_meta_access_token' => '' ] );
$check( 'ghp_keep' === get_option( 'presshub_ai_github_token' ) && 'keep-pass' === get_option( 'presshub_ai_press_inbox_password' ) && 'keep-tts' === get_option( 'presshub_ai_briefing_tts_api_key' ) && 'keep-meta' === get_option( 'presshub_social_meta_access_token' ), 'An empty Replace key field should keep every saved secret.' );
$save( [ 'presshub_ai_github_token' => 'ghp_new', 'presshub_ai_press_inbox_password' => 'new-pass', 'presshub_ai_briefing_tts_api_key' => 'new-tts', 'presshub_social_meta_access_token' => 'new-meta' ] );
$check( 'ghp_new' === get_option( 'presshub_ai_github_token' ) && 'new-pass' === get_option( 'presshub_ai_press_inbox_password' ) && 'new-tts' === get_option( 'presshub_ai_briefing_tts_api_key' ) && 'new-meta' === get_option( 'presshub_social_meta_access_token' ), 'A typed value should replace each secret.' );
$save( [ 'presshub_ai_briefing_tts_api_key' => '', 'presshub_ai_remove_briefing_tts_api_key' => '1', 'presshub_ai_press_inbox_password' => '', 'presshub_ai_remove_press_inbox_password' => '1' ] );
$check( '' === (string) get_option( 'presshub_ai_briefing_tts_api_key', '' ) && '' === (string) get_option( 'presshub_ai_press_inbox_password', '' ), 'Remove should still clear the speech key and the mailbox password.' );

// ---------------------------------------------------------------------
// 3. The status cache.
// ---------------------------------------------------------------------
$GLOBALS['TRANSIENT_STORE'] = [];
$now = time();
$check( 'unknown' === PressHub_Connection_Status::describe( PressHub_Connection_Status::get( 'worker' ), $now )['state'], 'A check that never ran should be "Not checked yet".' );
PressHub_Connection_Status::record( 'worker', true, 'Running' );
$d = PressHub_Connection_Status::describe( PressHub_Connection_Status::get( 'worker' ), $now );
$check( 'ok' === $d['state'] && 'Connected' === $d['label'] && 0 === strpos( $d['checked'], 'checked ' ), 'A passed test should read "Connected" with its checked time; got ' . json_encode( $d ) );
PressHub_Connection_Status::record( 'social:tiktok', false, "<b>Token\n revoked</b>" );
$e = PressHub_Connection_Status::get( 'social:tiktok' );
$check( 'Token revoked' === $e['text'], 'The stored message should be plain one-line text; got ' . var_export( $e['text'], true ) );
$check( 'error' === PressHub_Connection_Status::describe( $e, $now )['state'], 'A failed test should be an error.' );
$check( 'warning' === PressHub_Connection_Status::describe( [ 'ok' => true, 'checked_at' => $now, 'expires_at' => $now + 3 * 86400 + 60 ], $now )['state'], 'A token expiring in 3 days should be a warning.' );
$check( 'Valid, 3 days left' === PressHub_Connection_Status::describe( [ 'ok' => true, 'checked_at' => $now, 'expires_at' => $now + 3 * 86400 + 60 ], $now )['label'], 'The warning should say how many days are left.' );
$check( 'ok' === PressHub_Connection_Status::describe( [ 'ok' => true, 'checked_at' => $now, 'expires_at' => $now + 40 * 86400 ], $now )['state'], 'A token valid for 40 days should be ok.' );
$check( 'Expired' === PressHub_Connection_Status::describe( [ 'ok' => true, 'checked_at' => $now - 10, 'expires_at' => $now - 5 ], $now )['label'], 'A token past its expiry should read "Expired".' );
$pill = PressHub_Connection_Status::render_pill( 'social:tiktok' );
$check( false !== strpos( $pill, 'is-error' ) && false !== strpos( $pill, 'Not working' ) && false !== strpos( $pill, 'checked ' ), 'The pill should carry the state in words and its checked time.' );
PressHub_Connection_Status::forget( 'social:tiktok' );
$check( null === PressHub_Connection_Status::get( 'social:tiktok' ), 'forget() should drop a check.' );

// A replaced secret forgets its last test.
PressHub_Connection_Status::record( 'social:facebook', true, 'ok', $now + 86400 );
$save( [ 'presshub_social_meta_access_token' => 'replaced-meta-token' ] );
$check( null === PressHub_Connection_Status::get( 'social:facebook' ), 'Replacing the Meta token should forget its last test.' );

// ---------------------------------------------------------------------
// 4. Tests record their result.
// ---------------------------------------------------------------------
$GLOBALS['TRANSIENT_STORE'] = [];
cnt_reset_http();
$r = cnt_ajax( [ $ajax, 'test_provider' ], [ 'provider_id' => 'my-gemini' ] );
$entry = PressHub_Connection_Status::get( 'provider:my-gemini' );
$check( null !== $entry && false === $entry['ok'], 'A failed provider test should be recorded as not working.' );
$check( is_array( $r ) && false !== strpos( (string) ( $r['data']['status'] ?? '' ), 'data-check="provider:my-gemini"' ), 'The provider test should return the new pill.' );
$GLOBALS['TRANSIENT_STORE'] = [];
cnt_ajax( [ $ajax, 'test_provider' ], [ 'provider_id' => 'my-gemini', 'provider_data' => json_encode( [ 'id' => 'my-gemini', 'type' => 'gemini', 'api_key' => 'unsaved' ] ) ] );
$check( null === PressHub_Connection_Status::get( 'provider:my-gemini' ), 'Testing unsaved modal fields should not change the saved status.' );

$GLOBALS['OPTIONS_STORE']['presshub_social_worker_mode'] = 'pull';
unset( $GLOBALS['OPTIONS_STORE']['presshub_social_worker_last_seen'] );
$r = cnt_ajax( [ 'PressHub_Social_Ajax_Handlers', 'handle_test_connection' ], [ 'platform' => 'worker' ] );
$entry = PressHub_Connection_Status::get( 'worker' );
$check( null !== $entry && false === $entry['ok'], 'A worker test with no heartbeat should be recorded as not working.' );
$check( is_array( $r ) && false !== strpos( (string) ( $r['data']['status'] ?? '' ), 'data-check="worker"' ), 'A failed worker test should return the new pill.' );
$GLOBALS['OPTIONS_STORE']['presshub_social_worker_last_seen'] = time() - 20;
$r = cnt_ajax( [ 'PressHub_Social_Ajax_Handlers', 'handle_test_connection' ], [ 'platform' => 'worker' ] );
$check( true === ( PressHub_Connection_Status::get( 'worker' )['ok'] ?? null ) && false !== strpos( (string) ( $r['data']['status_html'] ?? '' ), 'is-ok' ), 'A worker with a recent heartbeat should be recorded as connected.' );

// Facebook token expiry comes from debug_token, only when Test is clicked.
$GLOBALS['GET_RESPONSE_FILTER'] = static function ( $url ) {
    return false !== strpos( $url, '/debug_token' ) ? [ 'response' => [ 'code' => 200 ], 'body' => '{"data":{"expires_at":1900000000}}' ] : null;
};
$check( 1900000000 === PressHub_Social_Meta_API_Client::token_expires_at( 'abc' ), 'token_expires_at() should read data.expires_at.' );
$GLOBALS['GET_RESPONSE_FILTER'] = static function () { return [ 'response' => [ 'code' => 200 ], 'body' => '{"data":{"expires_at":0}}' ]; };
$check( 0 === PressHub_Social_Meta_API_Client::token_expires_at( 'abc' ), 'A token that never expires should give 0.' );
$GLOBALS['GET_RESPONSE_FILTER'] = static function () { return new WP_Error( 'blocked', 'blocked' ); };

// ---------------------------------------------------------------------
// 6. Regenerate worker secret.
// ---------------------------------------------------------------------
$GLOBALS['OPTIONS_STORE']['presshub_social_worker_secret'] = 'old-worker-secret';
$r = cnt_ajax( [ 'PressHub_Social_Ajax_Handlers', 'handle_regenerate_worker_secret' ], [], false );
$check( 'old-worker-secret' === get_option( 'presshub_social_worker_secret' ), 'Regenerate without a valid nonce should change nothing.' );
$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts' ];
$r = cnt_ajax( [ 'PressHub_Social_Ajax_Handlers', 'handle_regenerate_worker_secret' ], [] );
$check( 'old-worker-secret' === get_option( 'presshub_social_worker_secret' ) && is_array( $r ) && false === $r['success'], 'Regenerate should need the Social capability.' );
$GLOBALS['CURRENT_USER_CAPS'] = $ALL_CAPS;
$r = cnt_ajax( [ 'PressHub_Social_Ajax_Handlers', 'handle_regenerate_worker_secret' ], [] );
$new = (string) get_option( 'presshub_social_worker_secret' );
$check( is_array( $r ) && true === $r['success'] && 32 === strlen( $new ) && 'old-worker-secret' !== $new && ( $r['data']['secret'] ?? '' ) === $new, 'Regenerate should store and return a new 32-character secret.' );
$check( false !== strpos( (string) ( $r['data']['message'] ?? '' ), '.env' ), 'The regenerate result should say where to paste the secret.' );
$check( null === PressHub_Connection_Status::get( 'worker' ) && false === get_option( 'presshub_social_worker_last_seen', false ), 'Regenerate should forget the worker status and heartbeat.' );
$check( 1 === preg_match( '/id="presshub-social-regenerate-secret"[^>]*data-confirm="[^"]*stops[^"]*\.env/s', $html ), 'Regenerate should ask for confirmation and say the worker stops until the .env has the new secret.' );
$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-social-settings.js' );
$check( false !== strpos( $js, "window.confirm(String(\$btn.data('confirm')" ), 'The Regenerate button should confirm before it posts.' );

// ---------------------------------------------------------------------
// 7. Setup check.
// ---------------------------------------------------------------------
$GLOBALS['TRANSIENT_STORE'] = [];
$GLOBALS['OPTIONS_STORE']   = [
    'presshub_social_enabled_platforms' => [],
    'presshub_social_enabled_twitter'   => 0,
    'presshub_social_enabled_facebook'  => 0,
    'presshub_social_enabled_instagram' => 0,
    'presshub_social_enabled_tiktok'    => 0,
];
$texts = static function (): string {
    return implode( ' | ', array_column( PressHub_Setup_Check::problems(), 'text' ) );
};
PressHub_AI_Provider_Store::save_provider( [ 'id' => 'only', 'name' => 'Only', 'type' => 'openai', 'api_key' => '', 'enabled' => true ] );
foreach ( PressHub_AI_Provider_Store::get_all( false ) as $p ) {
    if ( ! empty( $p['api_key'] ) ) {
        PressHub_AI_Provider_Store::delete_provider( (string) $p['id'] );
    }
}
$check( false !== strpos( $texts(), 'No AI provider has a key' ), 'With no provider key the setup check should say so; got ' . $texts() );
$items = PressHub_Setup_Check::problems();
$check( isset( $items[0]['url'] ) && false !== strpos( $items[0]['url'], 'section=connections#ai-providers' ), 'Each problem should link to its fix.' );
PressHub_AI_Provider_Store::save_provider( [ 'id' => 'only', 'name' => 'Only', 'type' => 'openai', 'api_key' => 'sk-test-key', 'enabled' => true ] );
$check( '' === $texts(), 'With a provider key and nothing else switched on there should be no problem; got ' . $texts() );
$box = PressHub_Setup_Check::render();
$check( false !== strpos( $box, 'Everything is connected' ) && false === strpos( $box, 'role="alert"' ) && false !== strpos( $box, 'class="notice' ), 'With nothing wrong the box should say "Everything is connected", as a static notice.' );

PressHub_Connection_Status::record( 'provider:only', false, 'Invalid API key' );
$check( false !== strpos( $texts(), 'Only: Not working' ), 'A failed provider test should be listed; got ' . $texts() );
PressHub_Connection_Status::forget( 'provider:only' );

$GLOBALS['OPTIONS_STORE']['presshub_ai_press_inbox_enabled'] = 1;
$check( false !== strpos( $texts(), 'press inbox is switched on' ), 'An inbox switched on without a login should be listed.' );
$GLOBALS['OPTIONS_STORE']['presshub_ai_press_inbox_enabled'] = 0;

$GLOBALS['OPTIONS_STORE']['presshub_social_enabled_twitter'] = 1;
$GLOBALS['OPTIONS_STORE']['presshub_social_enabled_platforms'] = [ 'twitter' ];
$GLOBALS['OPTIONS_STORE']['presshub_social_engine_twitter'] = 'automation';
$GLOBALS['OPTIONS_STORE']['presshub_social_worker_mode'] = 'pull';
$GLOBALS['OPTIONS_STORE']['presshub_social_worker_sessions'] = [ 'twitter' => false ];
$GLOBALS['OPTIONS_STORE']['presshub_social_worker_last_seen'] = time() - 3600;
$t = $texts();
$check( false !== strpos( $t, 'The worker is not running' ), 'A pull worker not heard from for an hour should be listed; got ' . $t );
$check( false !== strpos( $t, 'X needs a new login' ), 'A platform whose worker login lapsed should be listed; got ' . $t );
$GLOBALS['OPTIONS_STORE']['presshub_social_worker_last_seen'] = time() - 30;
$GLOBALS['OPTIONS_STORE']['presshub_social_worker_sessions'] = [ 'twitter' => true ];
$check( '' === $texts(), 'A running worker with its logins should leave nothing to fix; got ' . $texts() );

$GLOBALS['OPTIONS_STORE']['presshub_social_enabled_facebook'] = 1;
$GLOBALS['OPTIONS_STORE']['presshub_social_enabled_platforms'] = [ 'twitter', 'facebook' ];
$GLOBALS['OPTIONS_STORE']['presshub_social_engine_facebook'] = 'api';
$check( false !== strpos( $texts(), 'Facebook is switched on, but its account details are missing' ), 'A platform switched on without credentials should be listed.' );
$GLOBALS['OPTIONS_STORE']['presshub_social_meta_page_id'] = '123';
$GLOBALS['OPTIONS_STORE']['presshub_social_meta_access_token'] = 'EAAB';
PressHub_Connection_Status::record( 'social:facebook', true, 'ok', time() + 2 * 86400 );
$items = PressHub_Setup_Check::problems();
$check( 1 === count( $items ) && 'warning' === $items[0]['level'] && false !== strpos( $items[0]['text'], 'expires soon' ), 'A Facebook token expiring in 2 days should be the only item, as a warning; got ' . $texts() );

// ---------------------------------------------------------------------
// 8. No developer details or unused secrets on the screen.
// ---------------------------------------------------------------------
$GLOBALS['CURRENT_USER_CAPS'] = $ALL_CAPS;
$check( false === strpos( $html, 'wp-json' ) && false === strpos( $html, 'presshub-social/v1' ), 'Connections should not show REST endpoint URLs.' );
foreach ( [ 'presshub_social_twitter_api_key', 'presshub_social_twitter_api_secret', 'presshub_social_twitter_access_secret', 'presshub_social_github_token', 'presshub_ai_api_key' ] as $unused ) {
    $check( false === strpos( $html, 'name="' . $unused . '"' ), "Connections should not show the unused secret {$unused}." );
}
$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/settings.css' );
foreach ( [ 'is-ok', 'is-warning', 'is-error', 'is-unknown' ] as $state ) {
    $check( false !== strpos( $css, '.presshub-conn-pill.' . $state ), "settings.css should style the {$state} pill." );
}

if ( $failures ) {
    fwrite( STDERR, "ConnectionsTest: " . count( $failures ) . " failure(s)\n - " . implode( "\n - ", $failures ) . "\n" );
    exit( 1 );
}
echo "ConnectionsTest: all checks passed.\nOK\n";
