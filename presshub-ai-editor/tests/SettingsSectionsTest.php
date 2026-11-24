<?php
/**
 * PressHub → Settings in sections (#246, settings overhaul phase 3b).
 *
 * Verifies:
 *  1. Every registry option belongs to exactly one section, and the
 *     generated inventory (docs/settings-inventory.md) has no option outside one.
 *  2. Each section page renders only its own options, and every option it
 *     holds that is meant to be on screen.
 *  3. A section's Save writes only that section's options, whatever else
 *     the request carries.
 *  4. Social options keep their rules: secrets keep-on-masked, "_remove"
 *     clears them, a "_present" marker saves an empty list.
 *  5. The prompts reset link and its redirect point at Social publishing.
 *  6. The section list has no tab roles and one aria-current link.
 *  7. The What's new panel shows once per page and stays dismissed.
 *  8. The settings renderers carry no inline style="" (CI greps the same).
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
require_once __DIR__ . '/settings-sections-helper.php';

$failures = [];
$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files', 'manage_options' ];
$GLOBALS['CURRENT_USER_ID']   = 1;
( new PressHub_AI_Settings() )->register_settings();
PressHub_Social_Settings::get_instance()->register_settings();

/** Option names posted by the main settings form of a rendered page. */
function sst_form_names( string $html ): array {
    $start = strpos( $html, 'id="presshub-ai-settings-form"' );
    if ( false === $start ) {
        return [];
    }
    $form = substr( $html, $start, (int) strpos( $html, '</form>', $start ) - $start );
    preg_match_all( '/\bname="([^"\[]+)/', $form, $m );
    return array_unique( $m[1] );
}

/** Run an AJAX handler and return its last JSON response (or null). */
function sst_ajax( callable $fn, array $post ): ?array {
    $_POST                     = array_merge( [ 'nonce' => 'valid_nonce' ], $post );
    $_REQUEST                  = $_POST;
    $GLOBALS['NONCE_VALID']    = true;
    $GLOBALS['JSON_RESPONSES'] = [];
    try {
        $fn();
    } catch ( Throwable $t ) {
        // wp_send_json_* stubs throw to end the request.
    }
    $_POST = [];
    $r     = end( $GLOBALS['JSON_RESPONSES'] );
    return is_array( $r ) ? $r : null;
}

$all   = PressHub_Settings_Registry::all();
$slugs = PressHub_Settings_Sections::slugs();

// ---------------------------------------------------------------------
// 1. One section per option; the inventory agrees.
// ---------------------------------------------------------------------
if ( 8 !== count( $slugs ) ) {
    $failures[] = 'There should be eight sections; got ' . count( $slugs );
}
$counted = 0;
foreach ( $slugs as $slug ) {
    $counted += count( PressHub_Settings_Sections::entries( $slug ) );
}
foreach ( $all as $key => $entry ) {
    if ( '' === PressHub_Settings_Sections::section_of( (string) $key, $entry ) ) {
        $failures[] = "Orphan: {$key} belongs to no section.";
    }
}
if ( $counted !== count( $all ) ) {
    $failures[] = "Sections hold {$counted} options; the registry has " . count( $all ) . '.';
}
$inventory = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/settings-inventory.md' );
if ( false !== strpos( $inventory, '## Not in a section' ) ) {
    $failures[] = 'docs/settings-inventory.md lists options that are not in a section.';
}
preg_match_all( '/^\| `([a-z0-9_]+)` \| .*\| (?:ai|social) \/ [a-z_]+ \|/m', $inventory, $inv );
if ( count( array_unique( $inv[1] ) ) !== count( $all ) ) {
    $failures[] = 'docs/settings-inventory.md should list every registry option once by section; got ' . count( array_unique( $inv[1] ) );
}

// ---------------------------------------------------------------------
// 2. Each page renders its own options, and only those.
// ---------------------------------------------------------------------
foreach ( $slugs as $slug ) {
    $html  = presshub_test_render_section( $slug );
    $names = sst_form_names( $html );
    foreach ( $names as $name ) {
        if ( isset( $all[ $name ] ) && PressHub_Settings_Sections::section_of( $name ) !== $slug ) {
            $failures[] = "{$slug} renders {$name}, which belongs to " . PressHub_Settings_Sections::section_of( $name ) . '.';
        }
    }
    foreach ( PressHub_Settings_Sections::entries( $slug ) as $key => $entry ) {
        if ( ! empty( $entry['rendered'] ) && empty( $entry['removes'] ) && ! PressHub_Settings_Registry::is_unused( $key )
            && false === strpos( $html, 'name="' . $key . '"' ) && false === strpos( $html, 'name="' . $key . '[' ) && false === strpos( $html, 'id="' . $key . '"' ) ) {
            $failures[] = "{$slug} does not render its option {$key}.";
        }
    }
    if ( false === strpos( $html, 'data-section="' . $slug . '"' ) ) {
        $failures[] = "{$slug}: the page should say which section it shows.";
    }
}
// An unknown or missing section falls back to Connections.
$_GET['section'] = 'nope';
if ( PressHub_Settings_Sections::DEFAULT_SECTION !== PressHub_Settings_Sections::current() ) {
    $failures[] = 'An unknown section should open the default section.';
}
unset( $_GET['section'] );

// ---------------------------------------------------------------------
// 3. A section's Save writes only that section.
// ---------------------------------------------------------------------
$ajax = new PressHub_AI_Ajax_Handlers();
foreach ( $slugs as $slug ) {
    $GLOBALS['OPTIONS_STORE'] = [];
    foreach ( $all as $key => $entry ) {
        if ( empty( $entry['secret'] ) && empty( $entry['removes'] ) ) {
            $GLOBALS['OPTIONS_STORE'][ $key ] = 'SENTINEL';
        }
    }
    $before = $GLOBALS['OPTIONS_STORE'];
    // The request carries every option, as a tampered or stale form could.
    $payload = [];
    foreach ( $all as $key => $entry ) {
        if ( empty( $entry['removes'] ) ) {
            $payload[ $key ] = 'CHANGED';
        }
    }
    $r = sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => $slug, 'payload_b64' => base64_encode( (string) json_encode( $payload ) ) ] );
    if ( null === $r || true !== $r['success'] ) {
        $failures[] = "{$slug}: the section save should succeed; got " . json_encode( $r );
    }
    foreach ( $before as $key => $value ) {
        if ( PressHub_Settings_Sections::section_of( (string) $key ) !== $slug && ( $GLOBALS['OPTIONS_STORE'][ $key ] ?? null ) !== $value ) {
            $failures[] = "{$slug} Save changed {$key} of " . PressHub_Settings_Sections::section_of( (string) $key ) . '.';
        }
    }
}

// Unticked checkboxes of the section save as 0; other sections' stay as they are.
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_debug_prompts' => 1, 'presshub_ai_qa_enabled' => 1 ];
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'site-system', 'payload_b64' => base64_encode( '{"presshub_ai_log_level":"INFO"}' ) ] );
if ( 0 !== (int) get_option( 'presshub_ai_debug_prompts' ) || 1 !== (int) get_option( 'presshub_ai_qa_enabled' ) ) {
    $failures[] = 'An unticked Site & system checkbox should save as 0 without touching Writing & QA.';
}

// An unknown section is refused.
$r = sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'nope', 'payload_b64' => base64_encode( '{}' ) ] );
if ( null === $r || false !== $r['success'] ) {
    $failures[] = 'Saving an unknown section should fail.';
}

// ---------------------------------------------------------------------
// 4. Social rules inside a section save.
// ---------------------------------------------------------------------
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_social_worker_secret' => 'old-secret' ];
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'connections', 'payload_b64' => base64_encode( (string) json_encode( [ 'presshub_social_worker_secret' => '••••••••cret' ] ) ) ] );
if ( 'old-secret' !== get_option( 'presshub_social_worker_secret' ) ) {
    $failures[] = 'A masked Social secret should keep the stored value.';
}
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'connections', 'payload_b64' => base64_encode( '{"presshub_social_worker_secret":""}' ) ] );
if ( 'old-secret' !== get_option( 'presshub_social_worker_secret' ) ) {
    $failures[] = 'An empty Social secret should keep the stored value.';
}
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'connections', 'payload_b64' => base64_encode( '{"presshub_social_worker_secret":"new-secret-123"}' ) ] );
if ( 'new-secret-123' !== get_option( 'presshub_social_worker_secret' ) ) {
    $failures[] = 'A new Social secret should be stored; got ' . var_export( get_option( 'presshub_social_worker_secret' ), true );
}
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'connections', 'payload_b64' => base64_encode( '{"presshub_social_worker_secret":"","presshub_social_worker_secret_remove":"1"}' ) ] );
if ( array_key_exists( 'presshub_social_worker_secret', $GLOBALS['OPTIONS_STORE'] ) ) {
    $failures[] = 'Ticking Remove should delete the Social secret.';
}
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_social_enabled_platforms' => [ 'facebook', 'x' ] ];
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'social-publishing', 'payload_b64' => base64_encode( '{"presshub_social_enabled_platforms_present":"1"}' ) ] );
if ( [] !== get_option( 'presshub_social_enabled_platforms' ) ) {
    $failures[] = 'A "_present" marker with nothing ticked should save an empty platform list; got ' . var_export( get_option( 'presshub_social_enabled_platforms' ), true );
}

// List fields: admin.js posts "option[]" as one array of every ticked value.
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_social_enabled_platforms' => [ 'tiktok' ] ];
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'social-publishing', 'payload_b64' => base64_encode( (string) json_encode( [
    'presshub_social_enabled_platforms_present' => '1',
    'presshub_social_enabled_platforms[]'       => [ 'twitter', 'facebook', 'instagram', 'tiktok' ],
] ) ) ] );
$saved = get_option( 'presshub_social_enabled_platforms' );
if ( ! is_array( $saved ) || 4 !== count( $saved ) || array_diff( [ 'twitter', 'facebook', 'instagram', 'tiktok' ], $saved ) ) {
    $failures[] = 'Saving four ticked platforms should keep all four; got ' . var_export( $saved, true );
}
if ( [ 'a', 'b' ] !== ( PressHub_Settings_Registry::expand_bracket_keys( [ 'x[]' => [ 'a', 'b' ] ] )['x'] ?? null ) ) {
    $failures[] = 'expand_bracket_keys() must not nest a list posted as an array.';
}
if ( [ 'a' ] !== ( PressHub_Settings_Registry::expand_bracket_keys( [ 'x[]' => 'a' ] )['x'] ?? null ) ) {
    $failures[] = 'expand_bracket_keys() should still accept a single "option[]" value.';
}
$two = PressHub_Settings_Registry::expand_bracket_keys( [ 'v[en][presenter_1]' => 'Kore', 'v[en][presenter_2]' => 'Puck', 'v[tr][presenter_1]' => 'Leda' ] );
if ( [ 'en' => [ 'presenter_1' => 'Kore', 'presenter_2' => 'Puck' ], 'tr' => [ 'presenter_1' => 'Leda' ] ] !== ( $two['v'] ?? null ) || count( $two ) !== 1 ) {
    $failures[] = 'expand_bracket_keys() must fold two-level keys such as "v[en][presenter_1]"; got ' . var_export( $two, true );
}
$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
if ( 2 !== substr_count( $js, '= collectSettingsPayload($' ) || false === strpos( $js, "if (/\\[\\]$/.test(name))" ) ) {
    $failures[] = 'Both settings saves in admin.js should use collectSettingsPayload(), which posts "option[]" fields as lists.';
}

// A new Social secret typed while "Remove" is still ticked is stored, not removed.
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_social_worker_secret' => 'old-secret' ];
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'connections', 'payload_b64' => base64_encode( '{"presshub_social_worker_secret":"brand-new-secret","presshub_social_worker_secret_remove":"1"}' ) ] );
if ( 'brand-new-secret' !== get_option( 'presshub_social_worker_secret' ) ) {
    $failures[] = 'A new Social secret should win over a ticked Remove; got ' . var_export( get_option( 'presshub_social_worker_secret' ), true );
}
if ( false === strpos( $js, 'function resetSocialSecretFields()' ) ) {
    $failures[] = 'admin.js should untick Social "Remove" boxes after a successful save.';
}

// The same for AI keys: a new GitHub token typed with "Remove" ticked is kept.
$GLOBALS['OPTIONS_STORE'] = [ 'presshub_ai_github_token' => 'ghp_oldtoken1234567' ];
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'connections', 'payload_b64' => base64_encode( '{"presshub_ai_github_token":"ghp_newtoken7654321","presshub_ai_remove_github_token":"1"}' ) ] );
if ( 'ghp_newtoken7654321' !== get_option( 'presshub_ai_github_token' ) ) {
    $failures[] = 'A new GitHub token should win over a ticked Remove; got ' . var_export( get_option( 'presshub_ai_github_token' ), true );
}
sst_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'connections', 'payload_b64' => base64_encode( '{"presshub_ai_github_token":"ghp_••••••••4321","presshub_ai_remove_github_token":"1"}' ) ] );
if ( '' !== (string) get_option( 'presshub_ai_github_token', '' ) ) {
    $failures[] = 'Remove with the masked GitHub token should delete it.';
}

// Empty featured-image pools say so on load (the notice is not hidden).
$GLOBALS['OPTIONS_STORE'] = [];
$briefing = presshub_test_render_section( 'daily-briefing' );
if ( ! preg_match( '/class="presshub-image-pool-empty"/', $briefing ) || ! preg_match( '/class="presshub-image-pool-fallback description"/', $briefing ) ) {
    $failures[] = 'An empty image pool should show its "No images in pool" and Noon fallback notices on load.';
}
if ( preg_match( "/\\? '' : ''/", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-settings-render.php' ) ) ) {
    $failures[] = "class-settings-render.php has a ternary that prints '' either way (a lost conditional class).";
}

// Old #hashes of the former page land in their section.
$hashes = PressHub_Settings_Registry::search_payload( 'connections' )['legacy_hashes'] ?? [];
foreach ( [
    'briefing-voices' => 'section=daily-briefing#briefing-voices',
    'briefing-sources' => 'section=daily-briefing#briefing-sources',
    'featured-images'  => 'section=writing-qa#featured-images',
    'memory'           => 'section=editorial-knowledge#knowledge-memory',
    'seo'              => 'section=writing-qa#seo',
    'social-listening' => 'section=social-listening',
    'images'           => 'section=writing-qa#featured-images',
    'token_logs'       => 'page=presshub-ai-usage',
    'usage'            => 'page=presshub-ai-usage',
] as $hash => $expect ) {
    if ( false === strpos( (string) ( $hashes[ $hash ] ?? '' ), $expect ) ) {
        $failures[] = "Old #{$hash} should go to {$expect}; got " . var_export( $hashes[ $hash ] ?? null, true );
    }
}

// The export link downloads; it is not a page the leave prompt guards.
if ( ! preg_match( '/<a class="button" href="[^"]*"\s+download>Export settings/', presshub_test_render_section( 'site-system' ) ) ) {
    $failures[] = 'The settings export link should carry the download attribute.';
}

// ---------------------------------------------------------------------
// 5. Prompts reset: link and redirect target.
// ---------------------------------------------------------------------
$GLOBALS['OPTIONS_STORE'] = [];
$social = presshub_test_render_section( 'social-publishing' );
if ( ! preg_match( '/<a href="[^"]*page=presshub-ai[^"]*section=social-publishing[^"]*action=reset_prompts[^"]*"[^>]*class="[^"]*presshub-confirm-link[^"]*"[^>]*data-confirm="[^"]+"/', $social ) ) {
    $failures[] = 'The prompts reset link should point at Social publishing and ask first (presshub-confirm-link).';
}
if ( false !== strpos( $social, 'onclick=' ) ) {
    $failures[] = 'Social publishing must not print inline onclick handlers.';
}
if ( false === strpos( PressHub_Social_Settings::settings_url( 'post-copy' ), 'page=presshub-ai&section=social-publishing#post-copy' ) ) {
    $failures[] = 'The prompts reset redirect should land on Social publishing → Post copy.';
}

// ---------------------------------------------------------------------
// 6. Section list semantics.
// ---------------------------------------------------------------------
foreach ( $slugs as $slug ) {
    $html = presshub_test_render_section( $slug );
    if ( false !== strpos( $html, 'role="tab"' ) || false !== strpos( $html, 'role="tablist"' ) ) {
        $failures[] = "{$slug}: role=\"tab\" must not render.";
    }
    if ( 1 !== substr_count( $html, 'aria-current="page"' ) ) {
        $failures[] = "{$slug}: one aria-current link expected.";
    }
}

// ---------------------------------------------------------------------
// 7. What's new.
// ---------------------------------------------------------------------
$GLOBALS['USER_META'] = [];
$html = presshub_test_render_section( 'connections' );
if ( 1 !== substr_count( $html, 'id="presshub-whats-new"' ) ) {
    $failures[] = "The What's new panel should show once.";
}
$r = sst_ajax( [ $ajax, 'dismiss_settings_whats_new' ], [] );
if ( null === $r || true !== $r['success'] ) {
    $failures[] = "Dismissing What's new should succeed; got " . json_encode( $r );
}
if ( false !== strpos( presshub_test_render_section( 'ai-models' ), 'id="presshub-whats-new"' ) ) {
    $failures[] = "A dismissed What's new panel should stay hidden.";
}
$GLOBALS['CURRENT_USER_CAPS'] = [ 'read' ];
$r = sst_ajax( [ $ajax, 'dismiss_settings_whats_new' ], [] );
if ( null === $r || false !== $r['success'] ) {
    $failures[] = "Dismissing What's new needs the settings capability.";
}

// ---------------------------------------------------------------------
// 8. No inline styles in the settings renderers.
// ---------------------------------------------------------------------
// #309 (E6): the same renderers as the CI grep.
foreach ( [ 'class-settings-render.php', 'class-social-settings-render.php', 'class-social-settings.php', 'class-model-tasks.php', 'class-secret-field.php', 'class-setup-check.php', 'class-connection-status.php', 'class-settings-cleanup.php', 'class-log-exposure-check.php' ] as $file ) {
    $src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/' . $file );
    if ( preg_match_all( '/(?<![-\w])style\s*=/', $src, $m ) ) {
        $failures[] = "{$file} has " . count( $m[0] ) . ' inline style= attribute(s); use a class in assets/css/settings.css.';
    }
}

if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo "  - {$f}\n";
    }
    exit( 1 );
}
echo "SettingsSectionsTest: eight sections, no orphans, saves stay in their section.\nOK\n";
