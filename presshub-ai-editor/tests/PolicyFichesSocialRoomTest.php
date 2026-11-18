<?php
/**
 * PolicyFichesSocialRoomTest — policy fiches get real room in social copy.
 *
 *  1. Framing-only tasks (social copy, shorts) use a short header; the long
 *     header stays for every other task.
 *  2. With the default 1200 budget the worked example's full framing fits.
 *  3. The one-off migration raises a stored 800 to the new default, keeps any
 *     other stored value, and runs once.
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
function psr_check( string $label, bool $ok ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $ok ) {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}

// 1-2. Header and room.
$fiche = PressHub_AI_Policy_Fiches::normalize( array_merge(
    PressHub_AI_Policy_Fiches::empty_fiche( 'Δοκιμή' ),
    [ 'status' => 'published', 'principles' => 'Αρχή.', 'framing' => 'Κανόνας πλαισίωσης.' ]
) );
$set    = [ 'f' => [ 'count' => 1, 'fiche' => $fiche ] ];
$social = (string) PressHub_AI_Policy_Fiches::render( $set, 'social_copy_facebook', 100000 )['text'];
$shorts = (string) PressHub_AI_Policy_Fiches::render( $set, 'social_tiktok_shorts', 100000 )['text'];
$brief  = (string) PressHub_AI_Policy_Fiches::render( $set, 'briefing_curation', 100000 )['text'];
$qa     = (string) PressHub_AI_Policy_Fiches::render( $set, 'coauthor_scorecard', 100000 )['text'];
psr_check( 'header: social copy gets the short header', false !== strpos( $social, 'Δεσμευτικοί κανόνες πλαισίωσης' ) && false === strpos( $social, 'Τα επιβεβαιωμένα στοιχεία' ) );
psr_check( 'header: shorts get the short header', false !== strpos( $shorts, 'Δεσμευτικοί κανόνες πλαισίωσης' ) );
psr_check( 'header: briefing keeps the long header', false !== strpos( $brief, 'Τα επιβεβαιωμένα στοιχεία' ) );
psr_check( 'header: QA gate keeps its own header', false !== strpos( $qa, 'ΕΛΕΓΧΟΣ ΜΕ ΒΑΣΗ' ) );
psr_check( 'header: social block is shorter than the briefing block', strlen( $social ) < strlen( $brief ) );
psr_check( 'header: social still gets framing only', false !== strpos( $social, 'Κανόνας πλαισίωσης' ) && false === strpos( $social, 'Αρχή.' ) );
psr_check( 'budget: social defaults are 1200', 1200 === PressHub_AI_Policy_Fiches::budget_for( 'social_copy_x' ) && 1200 === PressHub_AI_Policy_Fiches::budget_for( 'social_tiktok_shorts' ) );
psr_check( 'room: the worked example fits social copy in full', '' !== PressHub_AI_Help_Admin::sample_policy_block( 'social_copy_facebook' ) );
psr_check( 'room: the worked example fits the shorts plan in full', '' !== PressHub_AI_Help_Admin::sample_policy_block( 'social_tiktok_shorts' ) );

psr_check( 'room: a positive budget too small for any fiche gives nothing, not a bare header', '' === (string) PressHub_AI_Policy_Fiches::render( $set, 'social_copy_facebook', 150 )['text'] );

// Help page with a budget too small even for the first framing rule.
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( PressHub_AI_Policy_Fiches::BUDGETS, [ 'social_copy_' => 200 ] ) );
$tiny = PressHub_AI_Help_Admin::build_page();
psr_check( 'help: tiny budget says social copy gets no fiche', false !== strpos( $tiny, 'would get no policy fiche' ) && false === strpos( $tiny, 'only its first framing rule' ) );

// Shorts warning only when its budget is set apart from social copy.
$long = PressHub_AI_Policy_Fiches::normalize( array_merge(
    PressHub_AI_Policy_Fiches::empty_fiche( 'Μακρύς' ),
    [ 'status' => 'published', 'framing' => str_repeat( 'Οι αριθμοί αποδίδονται στην πηγή τους. ', 15 ) ]
) );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( PressHub_AI_Policy_Fiches::BUDGETS, [ 'social_tiktok_shorts' => 300 ] ) );
$w = implode( "\n", PressHub_AI_Policy_Fiches::core_warnings( $long ) );
psr_check( 'warnings: shorts warned when only its budget is lowered', false !== strpos( $w, 'TikTok short plan' ) && false === strpos( $w, 'social copy calls' ) );
delete_option( 'presshub_ai_policy_fiches_budgets' );
psr_check( 'warnings: no separate shorts warning at the defaults', false === strpos( implode( "\n", PressHub_AI_Policy_Fiches::core_warnings( $long ) ), 'TikTok short plan' ) );

// 3. Migration.
delete_option( 'presshub_ai_migrated_social_fiche_budgets' );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( PressHub_AI_Policy_Fiches::BUDGETS, [ 'social_copy_' => 800, 'social_tiktok_shorts' => 650, 'podcast_script' => 800 ] ) );
presshub_ai_migrate_social_fiche_budgets();
$after = get_option( 'presshub_ai_policy_fiches_budgets' );
psr_check( 'migration: stored 800 for social copy raised to 1200', 1200 === (int) $after['social_copy_'] );
psr_check( 'migration: an editor-chosen value is kept', 650 === (int) $after['social_tiktok_shorts'] );
psr_check( 'migration: other tasks untouched', 800 === (int) $after['podcast_script'] );
psr_check( 'migration: flag set', '1' === get_option( 'presshub_ai_migrated_social_fiche_budgets' ) );
delete_option( 'presshub_ai_migrated_social_fiche_budgets' );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( PressHub_AI_Policy_Fiches::BUDGETS, [ 'social_copy_' => '800', 'social_tiktok_shorts' => 800, 'podcast_script' => 0 ] ) );
presshub_ai_migrate_social_fiche_budgets();
$b = get_option( 'presshub_ai_policy_fiches_budgets' );
psr_check( 'migration: shorts stored at 800 raised, string \'800\' raised', 1200 === (int) $b['social_tiktok_shorts'] && 1200 === (int) $b['social_copy_'] );
psr_check( 'migration: an explicit 0 is kept', 0 === (int) $b['podcast_script'] );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( $after, [ 'social_copy_' => 800 ] ) );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( PressHub_AI_Policy_Fiches::BUDGETS, [ 'social_copy_' => 0 ] ) );
delete_option( 'presshub_ai_migrated_social_fiche_budgets' );
presshub_ai_migrate_social_fiche_budgets();
psr_check( 'migration: an explicit 0 for social copy is kept', 0 === (int) get_option( 'presshub_ai_policy_fiches_budgets' )['social_copy_'] );
update_option( 'presshub_ai_policy_fiches_budgets', array_merge( $after, [ 'social_copy_' => 800 ] ) );
presshub_ai_migrate_social_fiche_budgets();
psr_check( 'migration: runs once (a later 800 is the editor\'s choice)', 800 === (int) get_option( 'presshub_ai_policy_fiches_budgets' )['social_copy_'] );
delete_option( 'presshub_ai_migrated_social_fiche_budgets' );
delete_option( 'presshub_ai_policy_fiches_budgets' );
presshub_ai_migrate_social_fiche_budgets();
psr_check( 'migration: nothing stored stays nothing stored', false === get_option( 'presshub_ai_policy_fiches_budgets', false ) || [] === get_option( 'presshub_ai_policy_fiches_budgets', [] ) );
psr_check( 'migration: hooked on admin_init', false !== has_action( 'admin_init', 'presshub_ai_migrate_social_fiche_budgets' ) );
delete_option( 'presshub_ai_migrated_social_fiche_budgets' );

if ( $failures ) {
    echo "PolicyFichesSocialRoomTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "PolicyFichesSocialRoomTest: OK ({$checks} checks)\n";
