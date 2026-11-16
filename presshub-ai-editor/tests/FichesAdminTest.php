<?php
/**
 * FichesAdminTest — top-level "Policy Fiches" menu for the editorial agenda
 * (#198) and the people / stakeholder fiches (#197).
 *
 *  1. Menu: top-level entry with Editorial agenda and People submenus, editor capability.
 *  2. Legacy Memory-tab links redirect to the new pages, keeping other arguments.
 *  3. The Memory page no longer renders the fiches tabs and links to the new menu.
 *  4. Assets only on the fiches pages.
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
$checks   = 0;
function fa_check( string $label, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}

$F = 'PressHub_AI_Fiches_Admin';

// 1. Menu.
$GLOBALS['MENU_PAGES']    = [];
$GLOBALS['SUBMENU_PAGES'] = [];
( new $F() )->add_admin_menu();
$top = $GLOBALS['MENU_PAGES'][0] ?? [];
fa_check( 'menu: one top-level entry', 1 === count( $GLOBALS['MENU_PAGES'] ) );
fa_check( 'menu: labelled Policy Fiches', 'Policy Fiches' === ( $top['menu_title'] ?? '' ) );
fa_check( 'menu: lands on the agenda page', 'presshub-ai-agenda' === ( $top['menu_slug'] ?? '' ) );
fa_check( 'menu: editor capability, same as Memory', PressHub_AI_Memory_Editor::CAPABILITY === ( $top['capability'] ?? '' ) );
fa_check( 'menu: sits after Daily Briefing Hub (26) and before PressHub Social (30)', 27 === ( $top['position'] ?? null ) );
$subs = array_column( $GLOBALS['SUBMENU_PAGES']['presshub-ai-agenda'] ?? [], 'menu_title', 'menu_slug' );
fa_check( 'submenu: Editorial agenda', 'Editorial agenda' === ( $subs['presshub-ai-agenda'] ?? '' ) );
fa_check( 'submenu: People', 'People' === ( $subs['presshub-ai-people'] ?? '' ) );
fa_check( 'submenu: Memory stays under Daily Briefing Hub', ! isset( $subs['presshub-ai-memory'] ) );

// 2. Legacy redirects.
$u = $F::legacy_redirect_url( [ 'page' => 'presshub-ai-memory', 'tab' => 'agenda', 'agenda_notice' => 'saved' ] );
fa_check( 'redirect: agenda tab → agenda page with notice', false !== strpos( $u, 'page=presshub-ai-agenda' ) && false !== strpos( $u, 'agenda_notice=saved' ) && false === strpos( $u, 'tab=' ) );
$u = $F::legacy_redirect_url( [ 'page' => 'presshub-ai-memory', 'tab' => 'stakeholders', 'fiche' => 'makis-keraunos', 'sh_notice' => 'saved' ] );
fa_check( 'redirect: stakeholders tab → people page with the fiche kept', false !== strpos( $u, 'page=presshub-ai-people' ) && false !== strpos( $u, 'fiche=makis-keraunos' ) && false !== strpos( $u, 'sh_notice=saved' ) && false === strpos( $u, 'tab=' ) );
fa_check( 'redirect: timeline untouched', '' === $F::legacy_redirect_url( [ 'page' => 'presshub-ai-memory' ] ) );
fa_check( 'redirect: entry deep link untouched', '' === $F::legacy_redirect_url( [ 'page' => 'presshub-ai-memory', 'entry_id' => '4' ] ) );
fa_check( 'redirect: other pages untouched', '' === $F::legacy_redirect_url( [ 'page' => 'presshub-ai', 'tab' => 'agenda' ] ) );
fa_check( 'redirect: non-scalar tab ignored', '' === $F::legacy_redirect_url( [ 'page' => 'presshub-ai-memory', 'tab' => [ 'agenda' ] ] ) );

// 3. Memory page.
$memory = PressHub_AI_Memory_Editor::build_page( [ 'tab' => 'agenda' ] );
fa_check( 'memory: no fiches tabs any more', false === strpos( $memory, 'presshub-memory-tabs' ) && false === strpos( $memory, PressHub_AI_Editorial_Agenda::POST_SAVE ) );
fa_check( 'memory: links to the Policy Fiches pages', false !== strpos( $memory, 'page=presshub-ai-agenda' ) && false !== strpos( $memory, 'page=presshub-ai-people' ) );
fa_check( 'memory: timeline still rendered', false !== strpos( $memory, 'presshub-memory-timeline' ) );

// 4. Assets.
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts' ];
$GLOBALS['ENQUEUED_STYLES']   = [];
( new $F() )->enqueue_assets( 'edit.php' );
fa_check( 'assets: none on unrelated pages', empty( $GLOBALS['ENQUEUED_STYLES'] ) );
( new $F() )->enqueue_assets( 'policy-fiches_page_presshub-ai-people' );
fa_check( 'assets: memory stylesheet on the People page', ! empty( $GLOBALS['ENQUEUED_STYLES']['presshub-ai-memory-editor-css'] ) );

if ( $failures > 0 ) {
    echo "FichesAdminTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "FichesAdminTest: OK ({$checks} checks)\n";
