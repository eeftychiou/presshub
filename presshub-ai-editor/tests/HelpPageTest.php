<?php
/**
 * HelpPageTest — PressHub → Help (PressHub_AI_Help_Admin).
 *
 *  1. Registered under the one PressHub menu, last in ORDER, editors may open
 *     it, authors may not; its stylesheet loads only on its own page.
 *  2. The page renders every section with its anchor and the table of contents.
 *  3. The "which task gets what" table follows the live budgets and switches.
 *  4. The prompt examples come from the real render() functions.
 *  5. Status lines reflect stored fiches and the memory switch.
 *  6. Rendering stores nothing and escapes stored text.
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
function hp_check( string $label, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}

const HP_EDITOR = [ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files' ];
const HP_AUTHOR = [ 'read', 'edit_posts', 'publish_posts', 'upload_files' ];

// ---------------------------------------------------------------------
// 1. Menu, capability, assets.
// ---------------------------------------------------------------------
$GLOBALS['SUBMENU_PAGES'] = [];
$help = new PressHub_AI_Help_Admin();
$help->add_admin_menu();
$rows = $GLOBALS['SUBMENU_PAGES'][ PressHub_Admin_Menu::PARENT ] ?? [];
$row  = $rows[0] ?? [];
hp_check( 'menu: Help is under PressHub', 'presshub-ai-help' === ( $row['menu_slug'] ?? '' ) && 'Help' === ( $row['menu_title'] ?? '' ) );
hp_check( 'menu: page title follows the PressHub — pattern', 'PressHub — Help' === ( $row['page_title'] ?? '' ) );
hp_check( 'menu: uses the PressHub capability', PressHub_Capabilities::manage() === ( $row['capability'] ?? '' ) );
hp_check( 'menu: Help is last in ORDER', 'presshub-ai-help' === PressHub_Admin_Menu::ORDER[ count( PressHub_Admin_Menu::ORDER ) - 1 ] );

$GLOBALS['CURRENT_USER_CAPS'] = HP_EDITOR;
$GLOBALS['ENQUEUED_STYLES'] = [];
$help->enqueue_assets( 'presshub_page_presshub-ai-help' );
$styles = $GLOBALS['ENQUEUED_STYLES'] ?? [];
hp_check( 'assets: help.css loads on the Help page', isset( $styles['presshub-ai-help'] ) || false !== strpos( wp_json_encode( $styles ), 'help.css' ) );
$GLOBALS['ENQUEUED_STYLES'] = [];
$help->enqueue_assets( 'presshub_page_presshub-ai-memory' );
hp_check( 'assets: help.css does not load elsewhere', false === strpos( wp_json_encode( $GLOBALS['ENQUEUED_STYLES'] ?? [] ), 'help.css' ) );
hp_check( 'assets: stylesheet exists', file_exists( dirname( __DIR__ ) . '/assets/css/help.css' ) );

$GLOBALS['CURRENT_USER_CAPS'] = HP_AUTHOR;
$refused = false;
try {
    ob_start();
    PressHub_AI_Help_Admin::render_page();
} catch ( Throwable $t ) {
    $refused = false !== strpos( $t->getMessage(), 'wp_die' );
} finally {
    ob_end_clean();
}
hp_check( 'capability: an author is refused', $refused );

$GLOBALS['CURRENT_USER_CAPS'] = HP_EDITOR;
ob_start();
PressHub_AI_Help_Admin::render_page();
$out = ob_get_clean();
hp_check( 'capability: an editor sees the page', false !== strpos( $out, 'id="presshub-help-page"' ) );

// ---------------------------------------------------------------------
// 2. Sections and anchors.
// ---------------------------------------------------------------------
$html = PressHub_AI_Help_Admin::build_page();
foreach ( array_keys( PressHub_AI_Help_Admin::sections() ) as $id ) {
    hp_check( "section: {$id} rendered", false !== strpos( $html, 'id="presshub-help-' . $id . '"' ) );
    hp_check( "section: {$id} in the table of contents", false !== strpos( $html, 'href="#presshub-help-' . $id . '"' ) );
}
foreach ( [ 'User stories', 'How it changes the output', 'Limitations', 'Agenda or policy fiche?' ] as $needle ) {
    hp_check( "content: {$needle}", false !== strpos( $html, $needle ) );
}
hp_check( 'content: no inline styles', false === strpos( $html, 'style=' ) );

// ---------------------------------------------------------------------
// 3. The task table follows budgets and switches.
// ---------------------------------------------------------------------
$matrix = PressHub_AI_Help_Admin::matrix_rows();
$by     = [];
foreach ( $matrix as $r ) {
    $by[ $r[0] ] = $r;
}
$brief = $by['Daily briefing text (morning and noon)'] ?? [];
hp_check( 'matrix: briefing agenda budget', '3,000 chars' === ( $brief[2] ?? '' ) || '3000 chars' === ( $brief[2] ?? '' ) );
hp_check( 'matrix: briefing gets memory', '—' !== ( $brief[1] ?? '—' ) );
hp_check( 'matrix: briefing policies get every part', false !== strpos( (string) ( $brief[4] ?? '' ), 'open questions' ) );
$social = $by['Social post copy (X, Facebook, Instagram, TikTok)'] ?? [];
hp_check( 'matrix: social copy gets framing and claims', false !== strpos( (string) ( $social[4] ?? '' ), '(framing, claims)' ) );
hp_check( 'matrix: social copy gets no people', '—' === ( $social[3] ?? '' ) );
hp_check( 'matrix: social copy gets no memory', '—' === ( $social[1] ?? '' ) );
$qa = $by['QA gate: editorial and legal review'] ?? [];
hp_check( 'matrix: QA gate gets no agenda', '—' === ( $qa[2] ?? '' ) );
hp_check( 'matrix: QA gate gets framing and claims', false !== strpos( (string) ( $qa[4] ?? '' ), 'framing, claims' ) );
$seo_check = $by['QA gate: SEO check'] ?? [];
hp_check( 'matrix: QA SEO check gets the agenda', '—' !== ( $seo_check[2] ?? '—' ) );
hp_check( 'matrix: QA SEO check gets no policy fiches', '—' === ( $seo_check[4] ?? '' ) );
$seo_meta = $by['SEO tags, meta description and focus keyphrase'] ?? [];
hp_check( 'matrix: post SEO row present', 5 === count( $seo_meta ) );
hp_check( 'matrix: post SEO gets nothing', [ '—', '—', '—', '—' ] === array_slice( $seo_meta, 1 ) );

// Every task key is an action the code really sets (exact literal, or a prefix the code builds on).
$src = '';
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $f ) {
    if ( basename( $f ) !== 'class-help-admin.php' ) {
        $src .= file_get_contents( $f );
    }
}
foreach ( array_keys( PressHub_AI_Help_Admin::tasks() ) as $action ) {
    $real = false !== strpos( $src, "'" . $action . "'" );
    if ( ! $real && preg_match( '/^([a-z_]+_)[a-z]+$/', $action, $m ) ) {
        $real = false !== strpos( $src, "'" . $m[1] . "' ." );
    }
    hp_check( "matrix: action {$action} is set somewhere in the code", $real );
}

// The page prose does not hard-code agenda budgets: changing them changes the text.
update_option( 'presshub_ai_editorial_agenda_budgets', array_merge( PressHub_AI_Editorial_Agenda::BUDGETS, [ 'social_copy_' => 777 ] ) );
hp_check( 'prose: social agenda budget read live', false !== strpos( PressHub_AI_Help_Admin::build_page(), '(social copy gets 777 characters)' ) );
delete_option( 'presshub_ai_editorial_agenda_budgets' );

update_option( 'presshub_ai_editorial_agenda_budgets', array_merge( PressHub_AI_Editorial_Agenda::BUDGETS, [ 'briefing_curation' => 0 ] ) );
$by = [];
foreach ( PressHub_AI_Help_Admin::matrix_rows() as $r ) {
    $by[ $r[0] ] = $r;
}
hp_check( 'matrix: a 0 budget shows a dash', '—' === ( $by['Daily briefing text (morning and noon)'][2] ?? '' ) );
delete_option( 'presshub_ai_editorial_agenda_budgets' );

update_option( 'presshub_ai_memory_enabled', 0 );
$by = [];
foreach ( PressHub_AI_Help_Admin::matrix_rows() as $r ) {
    $by[ $r[0] ] = $r;
}
$brief = $by['Daily briefing text (morning and noon)'] ?? [];
hp_check( 'matrix: memory off shows no memory', '—' === ( $brief[1] ?? '' ) );
hp_check( 'matrix: memory off shows no people', '—' === ( $brief[3] ?? '' ) );
hp_check( 'matrix: memory off shows no policies', '—' === ( $brief[4] ?? '' ) );
hp_check( 'matrix: memory off keeps the agenda', '—' !== ( $brief[2] ?? '—' ) );
$off_html = PressHub_AI_Help_Admin::build_page();
hp_check( 'status: memory off is shown', false !== strpos( $off_html, 'Off: not sent to the AI.' ) );
delete_option( 'presshub_ai_memory_enabled' );

// ---------------------------------------------------------------------
// 4. Prompt examples come from the real renderers.
// ---------------------------------------------------------------------
$agenda_block = PressHub_AI_Editorial_Agenda::render( PressHub_AI_Help_Admin::sample_agenda(), 3000 );
hp_check( 'example: agenda block is not empty', '' !== $agenda_block );
hp_check( 'example: agenda block is on the page', false !== strpos( $html, esc_html( $agenda_block ) ) );
$people_block = PressHub_AI_Help_Admin::sample_people_block();
hp_check( 'example: people block has the header', false !== strpos( $people_block, 'ΦΑΚΕΛΟΙ ΠΡΟΣΩΠΩΝ' ) && false !== strpos( $people_block, 'Υπουργός Εργασίας' ) );
$policy_full   = PressHub_AI_Help_Admin::sample_policy_block( 'briefing_curation' );
$policy_social = PressHub_AI_Help_Admin::sample_policy_block( 'social_copy_facebook' );
hp_check( 'example: the full worked example fits social copy with the default budget', '' !== $policy_social );
hp_check( 'example: social copy uses the short header', false !== strpos( $policy_social, 'Δεσμευτικοί κανόνες πλαισίωσης' ) && false === strpos( $policy_social, 'Τα επιβεβαιωμένα στοιχεία' ) );
hp_check( 'limits: page says cuts are recorded and where', false !== strpos( $html, 'Left out because of limits' ) && false !== strpos( $html, 'not the fixed instructions around them' ) );
hp_check( 'example: page says the social example is sent in full', false !== strpos( $html, 'so it is sent in full' ) );
$room = PressHub_AI_Help_Admin::policy_room( 'social_copy_facebook' );
hp_check( 'room: social copy room is its whole budget (fixed instructions not counted)', $room === PressHub_AI_Policy_Fiches::budget_for( 'social_copy_facebook' ) && $room > 0 );
hp_check( 'room: 0 for a task without policy fiches', 0 === PressHub_AI_Help_Admin::policy_room( 'generate_image_prompt' ) );
hp_check( 'example: briefing policy block has principles and claims', false !== strpos( $policy_full, 'Αρχές:' ) && false !== strpos( $policy_full, 'Ισχυρισμοί προς έλεγχο:' ) );
hp_check( 'example: social policy block has framing only', false !== strpos( $policy_social, 'Πλαισίωση:' ) && false === strpos( $policy_social, 'Αρχές:' ) && false === strpos( $policy_social, 'Ισχυρισμοί' ) );
hp_check( 'example: policy blocks on the page', false !== strpos( $html, esc_html( $policy_full ) ) && false !== strpos( $html, esc_html( $policy_social ) ) );

// ---------------------------------------------------------------------
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( PressHub_AI_Policy_Fiches::BUDGETS, [ 'social_copy_' => 0 ] ) );
hp_check( 'example: a 0 budget gives an empty example (the page says nothing is sent)', '' === PressHub_AI_Help_Admin::sample_policy_block( 'social_copy_facebook', true ) );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( PressHub_AI_Policy_Fiches::BUDGETS, [ 'social_copy_' => 300 ] ) );
$small_html = PressHub_AI_Help_Admin::build_page();
hp_check( 'example: a small social budget falls back to the first framing rule', '' === PressHub_AI_Help_Admin::sample_policy_block( 'social_copy_facebook' ) && false !== strpos( $small_html, 'only its first framing rule' ) );
delete_option( 'presshub_ai_policy_fiches_budgets' );

// 5-6. Status lines, no writes, escaping.
// ---------------------------------------------------------------------
hp_check( 'status: agenda not written yet', false !== strpos( PressHub_AI_Help_Admin::build_page(), 'Not written yet' ) );
update_option( PressHub_AI_Stakeholders::OPTION, [
    'x' => PressHub_AI_Stakeholders::normalize( [ 'name' => 'Δοκιμή <script>alert(1)</script> Δοκιμαστής', 'locked' => true ] ),
] );
PressHub_AI_Editorial_Agenda::save( [ [ 'title' => 'Θέμα <b>Ζχψ</b>', 'priority' => 5 ], [ 'title' => 'Παλιό', 'priority' => 2, 'expires' => '2000-01-01' ] ], 'Οδηγία' );
update_option( PressHub_AI_Policy_Fiches::OPTION, [
    'a' => PressHub_AI_Policy_Fiches::normalize( array_merge( PressHub_AI_Policy_Fiches::empty_fiche( 'Στεγαστικό σχέδιο' ), [ 'status' => 'published' ] ) ),
    'b' => PressHub_AI_Policy_Fiches::normalize( PressHub_AI_Policy_Fiches::empty_fiche( 'Συνταξιοδοτική μεταρρύθμιση' ) ),
] );
$before = $GLOBALS['OPTIONS_STORE'];
$html   = PressHub_AI_Help_Admin::build_page();
hp_check( 'status: people counts shown', false !== strpos( $html, '1 fiches, 1 locked, 0 excluded.' ) );
hp_check( 'status: agenda version and live themes', false !== strpos( $html, 'Version 1, 1 theme that has not expired.' ) );
hp_check( 'status: published and draft policy fiches', false !== strpos( $html, '1 published (sent to the AI), 1 drafts (never sent).' ) );
hp_check( 'no writes: options unchanged by rendering', $before === $GLOBALS['OPTIONS_STORE'] );
hp_check( 'status: stored names and titles are not printed', false === strpos( $html, 'Δοκιμαστής' ) && false === strpos( $html, 'Ζχψ' ) );
hp_check( 'escaping: no raw stored markup', false === strpos( $html, '<script>' ) && false === strpos( $html, '<b>Ζχψ' ) );

update_option( 'presshub_ai_policy_fiches_enabled', 0 );
update_option( 'presshub_ai_stakeholders_enabled', 0 );
$html = PressHub_AI_Help_Admin::build_page();
hp_check( 'status: switches off are shown', substr_count( $html, 'Off: not sent to the AI.' ) >= 2 );
$by = [];
foreach ( PressHub_AI_Help_Admin::matrix_rows() as $r ) {
    $by[ $r[0] ] = $r;
}
hp_check( 'matrix: people switch off shows a dash', '—' === ( $by['Daily briefing text (morning and noon)'][3] ?? '' ) );
hp_check( 'matrix: policy switch off shows a dash', '—' === ( $by['Daily briefing text (morning and noon)'][4] ?? '' ) );
delete_option( 'presshub_ai_policy_fiches_enabled' );
delete_option( 'presshub_ai_stakeholders_enabled' );

// url() and wiring in the plugin bootstrap.
hp_check( 'url: Help page URL', false !== strpos( PressHub_AI_Help_Admin::url(), 'page=presshub-ai-help' ) );
hp_check( 'url: with anchor', '#presshub-help-limits' === substr( PressHub_AI_Help_Admin::url( 'presshub-help-limits' ), -strlen( '#presshub-help-limits' ) ) );
$boot = file_get_contents( dirname( __DIR__ ) . '/presshub-ai-editor.php' );
hp_check( 'wiring: class file is loaded', false !== strpos( $boot, "includes/class-help-admin.php'" ) );
hp_check( 'wiring: page is created in presshub_ai_init()', (bool) preg_match( '/function presshub_ai_init\(\).*?new PressHub_AI_Help_Admin\(\);/s', $boot ) );

if ( $failures > 0 ) {
    echo "HelpPageTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "HelpPageTest: OK ({$checks} checks)\n";
