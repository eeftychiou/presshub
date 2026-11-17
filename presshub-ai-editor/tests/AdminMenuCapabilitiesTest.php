<?php
/**
 * AdminMenuCapabilitiesTest — settings overhaul phase 3a (#235).
 *
 *  1. One top-level PressHub menu with every PressHub page under it, in order;
 *     nothing left under WordPress Settings or in the old top-level menus.
 *  2. Editor role: every PressHub page is reachable and the settings AJAX
 *     actions accept an editor, as they do an administrator.
 *  3. Lower roles (author, contributor): no PressHub page, settings AJAX refused.
 *  4. Filter: presshub_ai_settings_cap = manage_options restores admin-only
 *     Settings, Social settings, Presets, Social Queue and Usage & logs.
 *  5. Every PressHub option group lets the PressHub capability save through
 *     options.php (option_page_capability_<group>).
 *  6. Old URLs redirect to the matching page and keep ?tab= and other arguments.
 *  7. CI greps: no raw manage_options check in plugin admin code, and no link
 *     to a removed menu location in code, assets or the worker's .env.example.
 *  8. Secrets are never printed on the settings pages for an editor.
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
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function amc_check( string $label, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}

const AMC_ADMIN       = [ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files', 'manage_options' ];
const AMC_EDITOR      = [ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files' ];
const AMC_AUTHOR      = [ 'read', 'edit_posts', 'publish_posts', 'upload_files' ];
const AMC_CONTRIBUTOR = [ 'read', 'edit_posts' ];

/** Register every PressHub menu entry the way admin_menu does. */
function amc_build_menu(): array {
    $GLOBALS['MENU_PAGES']    = [];
    $GLOBALS['SUBMENU_PAGES'] = [];
    PressHub_Admin_Menu::add_top_level();
    ( new PressHub_AI_Briefing_Admin() )->add_admin_menu();
    PressHub_Social_Settings::get_instance()->add_admin_menu();
    ( new PressHub_AI_Fiches_Admin() )->add_admin_menu();
    PressHub_Admin_Menu::add_usage_page();
    ( new PressHub_AI_Settings() )->add_settings_page();
    ( new PressHub_AI_Admin_Presets() )->add_presets_submenu();
    return $GLOBALS['SUBMENU_PAGES'][ PressHub_Admin_Menu::PARENT ] ?? [];
}

function amc_can( string $cap ): bool {
    return current_user_can( $cap );
}

/** Run an AJAX handler and return its last JSON response (or null). */
function amc_ajax( callable $fn, array $post = [] ): ?array {
    $_POST                     = array_merge( [ 'nonce' => 'valid_nonce' ], $post );
    $_REQUEST                  = $_POST;
    $GLOBALS['NONCE_VALID']    = true;
    $GLOBALS['JSON_RESPONSES'] = [];
    try {
        $fn();
    } catch ( Throwable $t ) {
        // wp_send_json_* and wp_die stubs throw to end the request.
    }
    $r = end( $GLOBALS['JSON_RESPONSES'] );
    return is_array( $r ) ? $r : null;
}

$expected = [
    'presshub-ai-briefing-hub'       => 'Daily Briefing Hub',
    'presshub-ai-generation-records' => 'Generation records',
    'presshub-social-queue'          => 'Social Queue',
    'presshub-ai-memory'             => 'Memory',
    'presshub-ai-agenda'             => 'Editorial agenda',
    'presshub-ai-policies'           => 'Policies',
    'presshub-ai-people'             => 'People',
    'presshub-ai-usage'              => 'Usage & logs',
    'presshub-ai'                    => 'Settings',
    'presshub-social-settings'       => 'Social settings',
    'presshub-ai-presets'            => 'Presets',
];

// ---------------------------------------------------------------------
// 1. Menu.
// ---------------------------------------------------------------------
$subs = amc_build_menu();
amc_check( 'menu: exactly one top-level entry', 1 === count( $GLOBALS['MENU_PAGES'] ) );
$top = $GLOBALS['MENU_PAGES'][0] ?? [];
amc_check( 'menu: labelled PressHub', 'PressHub' === ( $top['menu_title'] ?? '' ) );
amc_check( 'menu: opens the Daily Briefing Hub', 'presshub-ai-briefing-hub' === ( $top['menu_slug'] ?? '' ) );
amc_check( 'menu: top level takes the Hub capability', PressHub_Capabilities::briefing() === ( $top['capability'] ?? '' ) );
$titles = array_column( $subs, 'menu_title', 'menu_slug' );
foreach ( $expected as $slug => $title ) {
    amc_check( "menu: {$title} listed under PressHub", $title === ( $titles[ $slug ] ?? null ) );
}
amc_check( 'menu: nothing under WordPress Settings', empty( $GLOBALS['SUBMENU_PAGES']['options-general.php'] ) );
amc_check( 'menu: no other parent menus', [ PressHub_Admin_Menu::PARENT ] === array_keys( $GLOBALS['SUBMENU_PAGES'] ) );
foreach ( $subs as $row ) {
    amc_check( "menu: page title follows the PressHub — pattern ({$row['menu_slug']})", 0 === strpos( (string) $row['page_title'], 'PressHub — ' ) );
}

// Order: WordPress-shaped rows in registration order come out in ORDER.
$GLOBALS['submenu'] = [ PressHub_Admin_Menu::PARENT => array_map( static function ( $r ) {
    return [ $r['menu_title'], $r['capability'], $r['menu_slug'], $r['page_title'] ];
}, array_reverse( $subs ) ) ];
$GLOBALS['submenu'][ PressHub_Admin_Menu::PARENT ][] = [ 'Add-on', 'read', 'some-addon-page', 'Add-on' ];
PressHub_Admin_Menu::order_submenu();
$order = array_column( $GLOBALS['submenu'][ PressHub_Admin_Menu::PARENT ], 2 );
amc_check( 'order: submenu follows the planned order, add-ons last', array_merge( array_keys( $expected ), [ 'some-addon-page' ] ) === $order );

// Page hooks the asset loaders recognise.
amc_check( 'hook: Settings page under PressHub', PressHub_AI_Settings::is_settings_hook( 'presshub_page_presshub-ai' ) );
amc_check( 'hook: old Settings hook still recognised', PressHub_AI_Settings::is_settings_hook( 'settings_page_presshub-ai' ) );
amc_check( 'hook: Presets page is not the Settings page', ! PressHub_AI_Settings::is_settings_hook( 'presshub_page_presshub-ai-presets' ) );
amc_check( 'hook: Hub is not the Settings page', ! PressHub_AI_Settings::is_settings_hook( 'toplevel_page_presshub-ai-briefing-hub' ) );
$GLOBALS['ENQUEUED_SCRIPTS'] = [];
$GLOBALS['CURRENT_USER_CAPS'] = AMC_EDITOR;
( new PressHub_AI_Admin_Presets() )->enqueue_scripts( 'presshub_page_presshub-ai-presets' );
amc_check( 'hook: Presets assets load on the new Presets page', isset( $GLOBALS['ENQUEUED_SCRIPTS'][ PressHub_AI_Admin_Presets::SCRIPT_HANDLE ] ) );
$GLOBALS['ENQUEUED_SCRIPTS'] = [];
( new PressHub_AI_Settings() )->enqueue_scripts( 'presshub_page_presshub-ai' );
amc_check( 'hook: settings search loads on the new Settings page', isset( $GLOBALS['ENQUEUED_SCRIPTS']['presshub-settings-search'] ) );
amc_check( 'hook: admin.js knows the Usage & logs URL', false !== strpos( wp_json_encode( $GLOBALS['LOCALIZED_SCRIPTS']['presshub-ai-admin-js']['data'] ?? [] ), 'presshub-ai-usage' ) );

// ---------------------------------------------------------------------
// 2-4. Roles and the filter.
// ---------------------------------------------------------------------
$ajax = new PressHub_AI_Ajax_Handlers();
$settings_actions = [
    'save_settings_section' => [ [ $ajax, 'save_settings_section' ], [ 'tab' => 'copilot', 'payload_b64' => base64_encode( '{}' ) ] ],
    'save_settings'         => [ [ $ajax, 'save_settings' ], [ 'payload_b64' => base64_encode( '{}' ) ] ],
    'fetch_token_logs'      => [ [ $ajax, 'fetch_token_logs' ], [] ],
    'fetch_audit_logs'      => [ [ $ajax, 'fetch_audit_logs' ], [] ],
    'log_exposure_recheck'  => [ [ 'PressHub_AI_Log_Exposure_Check', 'ajax_recheck' ], [] ],
];

foreach ( [ 'administrator' => AMC_ADMIN, 'editor' => AMC_EDITOR ] as $role => $caps ) {
    $GLOBALS['CURRENT_USER_CAPS'] = $caps;
    $subs = amc_build_menu();
    amc_check( "{$role}: sees the PressHub menu", amc_can( $GLOBALS['MENU_PAGES'][0]['capability'] ) );
    foreach ( $subs as $row ) {
        amc_check( "{$role}: may open {$row['menu_slug']}", amc_can( (string) $row['capability'] ) );
    }
    foreach ( $settings_actions as $name => $spec ) {
        $r = amc_ajax( $spec[0], $spec[1] );
        amc_check( "{$role}: AJAX {$name} succeeds", null !== $r && true === $r['success'] );
    }
    // The rendered pages pass their own capability gate.
    $GLOBALS['WP_DIE_CALLED'] = false;
    try {
        ob_start();
        ( new PressHub_AI_Settings_Render() )->render_settings_page();
        PressHub_Admin_Menu::render_usage_page();
        ob_end_clean();
        $rendered = true;
    } catch ( Throwable $t ) {
        ob_end_clean();
        $rendered = false;
    }
    amc_check( "{$role}: Settings and Usage & logs render", $rendered );
}

foreach ( [ 'author' => AMC_AUTHOR, 'contributor' => AMC_CONTRIBUTOR ] as $role => $caps ) {
    $GLOBALS['CURRENT_USER_CAPS'] = $caps;
    $subs = amc_build_menu();
    amc_check( "{$role}: no PressHub menu", ! amc_can( $GLOBALS['MENU_PAGES'][0]['capability'] ) );
    foreach ( $subs as $row ) {
        amc_check( "{$role}: may not open {$row['menu_slug']}", ! amc_can( (string) $row['capability'] ) );
    }
    foreach ( $settings_actions as $name => $spec ) {
        $r = amc_ajax( $spec[0], $spec[1] );
        amc_check( "{$role}: AJAX {$name} refused", null === $r || false === $r['success'] );
    }
}

$GLOBALS['FILTERS']['presshub_ai_settings_cap'][] = [ 'callback' => static function () { return 'manage_options'; }, 'priority' => 10, 'accepted_args' => 1 ];
$admin_only = [ 'presshub-ai', 'presshub-social-settings', 'presshub-ai-presets', 'presshub-social-queue', 'presshub-ai-usage' ];
$GLOBALS['CURRENT_USER_CAPS'] = AMC_EDITOR;
$caps = array_column( amc_build_menu(), 'capability', 'menu_slug' );
foreach ( $admin_only as $slug ) {
    amc_check( "filter: {$slug} is admin-only again", 'manage_options' === ( $caps[ $slug ] ?? '' ) && ! amc_can( $caps[ $slug ] ) );
}
amc_check( 'filter: editors keep the Hub', amc_can( $caps['presshub-ai-briefing-hub'] ?? 'x' ) );
$r = amc_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'copilot', 'payload_b64' => base64_encode( '{}' ) ] );
amc_check( 'filter: editor settings save refused', null !== $r && false === $r['success'] );
$GLOBALS['CURRENT_USER_CAPS'] = AMC_ADMIN;
$r = amc_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'copilot', 'payload_b64' => base64_encode( '{}' ) ] );
amc_check( 'filter: administrator settings save still works', null !== $r && true === $r['success'] );
unset( $GLOBALS['FILTERS']['presshub_ai_settings_cap'] );
amc_check( 'default: PressHub capability is edit_others_posts', 'edit_others_posts' === PressHub_Capabilities::manage() );

// ---------------------------------------------------------------------
// 5. options.php capability per option group.
// ---------------------------------------------------------------------
PressHub_Settings_Registry::reset();
PressHub_Settings_Registry::ensure_registered();
$groups = array_unique( array_column( PressHub_Settings_Registry::registered(), 'group' ) );
amc_check( 'groups: both settings groups registered', ! array_diff( array_values( PressHub_Settings_Registry::GROUPS ), $groups ) );
foreach ( $groups as $group ) {
    $cap = apply_filters( 'option_page_capability_' . $group, 'manage_options' );
    amc_check( "groups: {$group} saves with the PressHub capability", PressHub_Capabilities::manage() === $cap );
}

// ---------------------------------------------------------------------
// 6. Old URLs.
// ---------------------------------------------------------------------
$redirects = [
    [ 'options-general.php', [ 'page' => 'presshub-ai' ], 'page=presshub-ai', [] ],
    [ 'options-general.php', [ 'page' => 'presshub-ai', 'tab' => 'briefing' ], 'page=presshub-ai', [ 'tab=briefing' ] ],
    [ 'options-general.php', [ 'page' => 'presshub-ai-presets' ], 'page=presshub-ai-presets', [] ],
    [ 'options-general.php', [ 'page' => 'presshub-social-queue', 'status' => 'failed' ], 'page=presshub-social-queue', [ 'status=failed' ] ],
    [ 'options-general.php', [ 'page' => 'presshub-social-settings', 'tab' => 'worker' ], 'page=presshub-social-settings', [ 'tab=worker' ] ],
    [ 'admin.php', [ 'page' => 'presshub-ai', 'tab' => 'token_logs' ], 'page=presshub-ai-usage', [] ],
    [ 'admin.php', [ 'page' => 'presshub' ], 'page=presshub-ai-briefing-hub', [] ],
];
foreach ( $redirects as $r ) {
    $url = PressHub_Admin_Menu::legacy_redirect_url( $r[0], $r[1] );
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
    $ok = '/wp-admin/admin.php' === parse_url( $url, PHP_URL_PATH ) && 'page=' . ( $q['page'] ?? '' ) === $r[2];
    foreach ( $r[3] as $kept ) {
        [ $k, $v ] = explode( '=', $kept );
        $ok = $ok && ( $q[ $k ] ?? null ) === $v;
    }
    if ( 'token_logs' === ( $r[1]['tab'] ?? '' ) ) {
        $ok = $ok && ! isset( $q['tab'] );
    }
    amc_check( 'redirect: ' . $r[0] . '?' . http_build_query( $r[1] ) . ' → ' . $url, $ok );
}
foreach ( [
    [ 'admin.php', [ 'page' => 'presshub-ai' ] ],
    [ 'admin.php', [ 'page' => 'presshub-ai', 'tab' => 'briefing' ] ],
    [ 'admin.php', [ 'page' => 'presshub-social-settings', 'tab' => 'worker' ] ],
    [ 'admin.php', [ 'page' => 'presshub-ai-briefing-hub' ] ],
    [ 'admin.php', [ 'page' => 'presshub-ai-agenda' ] ],
    [ 'options-general.php', [ 'page' => 'some-other-plugin' ] ],
    [ 'options-general.php', [] ],
    [ 'options-general.php', [ 'page' => [ 'presshub-ai' ] ] ],
] as $r ) {
    amc_check( 'no redirect: ' . $r[0] . ' ' . wp_json_encode( $r[1] ), '' === PressHub_Admin_Menu::legacy_redirect_url( $r[0], $r[1] ) );
}

// ---------------------------------------------------------------------
// 7. CI greps.
// ---------------------------------------------------------------------
$root  = dirname( __DIR__ );
$files = array_merge( glob( $root . '/includes/*.php' ), [ $root . '/presshub-ai-editor.php', $root . '/uninstall.php' ] );
foreach ( $files as $file ) {
    $src = (string) file_get_contents( $file );
    // Strip comments so documentation may still name the WordPress capability.
    $code = '';
    foreach ( token_get_all( $src ) as $tok ) {
        if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
            continue;
        }
        $code .= is_array( $tok ) ? $tok[1] : $tok;
    }
    amc_check( 'grep: no raw manage_options check in ' . basename( $file ), false === strpos( $code, "'manage_options'" ) && false === strpos( $code, '"manage_options"' ) );
}

$link_files = array_merge(
    glob( $root . '/includes/*.php' ),
    glob( $root . '/assets/*.js' ),
    glob( $root . '/assets/js/*.js' ),
    [ $root . '/presshub-ai-editor.php' ]
);
$worker_env = dirname( $root ) . '/presshub-social-worker/.env.example';
if ( file_exists( $worker_env ) ) {
    $link_files[] = $worker_env;
}
foreach ( $link_files as $file ) {
    $src = (string) file_get_contents( $file );
    $bad = preg_match( '/options-general\.php\?page=presshub|page=presshub-ai-policy-fiches|presshub_social_settings[\'"]\s*\]/', $src );
    amc_check( 'grep: no link to a removed menu location in ' . basename( $file ), 0 === $bad );
}

// ---------------------------------------------------------------------
// 8. Secrets stay masked for editors.
// ---------------------------------------------------------------------
$GLOBALS['CURRENT_USER_CAPS'] = AMC_EDITOR;
$secrets = [];
foreach ( PressHub_Settings_Registry::all() as $key => $e ) {
    if ( ! empty( $e['secret'] ) ) {
        $secrets[ $key ] = 'SECRETVALUE' . substr( md5( $key ), 0, 10 ) . 'END';
        $GLOBALS['OPTIONS_STORE'][ $key ] = $secrets[ $key ];
    }
}
amc_check( 'secrets: the registry declares secret options', count( $secrets ) > 3 );
ob_start();
try {
    ( new PressHub_AI_Settings_Render() )->render_settings_page();
} catch ( Throwable $t ) {
}
PressHub_Settings_Registry::ensure_registered();
try {
    PressHub_Social_Settings::get_instance()->register_settings();
    foreach ( [ 'general', 'ai', 'credentials', 'worker', 'shorts', 'queue', 'prompts' ] as $presshub_tab ) {
        $_GET['tab'] = $presshub_tab;
        PressHub_Social_Settings::get_instance()->render_settings_page();
    }
    unset( $_GET['tab'] );
} catch ( Throwable $t ) {
    echo 'social render threw: ' . $t->getMessage();
}
$html = (string) ob_get_clean();
amc_check( 'secrets: both settings pages rendered for the editor', false !== strpos( $html, 'id="presshub-ai-settings-form"' ) && false !== strpos( $html, 'id="presshub-social-settings-form"' ) );
$leaked = array_keys( array_filter( $secrets, static function ( $v ) use ( $html ) {
    return false !== strpos( $html, $v );
} ) );
amc_check( 'secrets: no secret value printed (' . implode( ', ', $leaked ) . ')', [] === $leaked );

if ( $failures > 0 ) {
    echo "AdminMenuCapabilitiesTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "AdminMenuCapabilitiesTest: OK ({$checks} checks)\n";
