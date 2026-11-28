<?php
/**
 * NumberDigitsTest — spelled-out Greek numbers become digits in social post text (2.91.0).
 *
 * Test cases:
 *   1. apply(): dates, percentages, thousands, years, millions, decimals, case and accents.
 *   2. apply(): ordinary words stay (small numbers, two numbers in a row, "χιλιάδες"
 *      alone, "κόμμα" the party, ordinals, hashtags and links, other languages).
 *   3. Shorts, brief and article short prompts ask for digits in the caption and hook,
 *      and their unedited 2.89 defaults are upgraded.
 *   4. PressHub_Social_TikTok_Shorts::digits() is the same conversion.
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

$checks   = 0;
$failures = 0;

function nd_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$D = 'PressHub_AI_Number_Digits';

// 1. Converted.
$converted = [
    'Σάββατο είκοσι οκτώ Νοεμβρίου με τις εξελίξεις' => 'Σάββατο 28 Νοεμβρίου με τις εξελίξεις',
    'ανεργία είκοσι έξι τοις εκατό, τρίτη χειρότερη' => 'ανεργία 26%, τρίτη χειρότερη',
    'είκοσι εννέα εκατομμυρίων ευρώ'                 => '29 εκατομμυρίων ευρώ',
    'τριάντα τρία από τα ογδόντα οκτώ πάρκα'         => '33 από τα 88 πάρκα',
    'δύο χιλιάδες πεντακόσια ευρώ'                   => '2.500 ευρώ',
    'χίλια διακόσια ευρώ'                            => '1.200 ευρώ',
    'σαράντα πέντε χιλιάδες'                         => '45.000',
    'δεκαπέντε χιλιάδες τριακόσιοι ψηφοφόροι'        => '15.300 ψηφοφόροι',
    'το δύο χιλιάδες είκοσι έξι'                     => 'το 2026',
    'εκατόν πενήντα θέσεις'                          => '150 θέσεις',
    'εκατό τοις εκατό'                               => '100%',
    'δεκαοκτώ μήνες, έντεκα και δώδεκα'              => '18 μήνες, 11 και 12',
    'δέκα'                                           => '10',
    'πέντε τοις εκατό'                               => '5%',
    'τρεις Δεκεμβρίου'                               => '3 Δεκεμβρίου',
    'τρία εκατομμύρια επισκέπτες'                    => '3 εκατομμύρια επισκέπτες',
    'δύο κόμμα πέντε εκατομμύρια'                    => '2,5 εκατομμύρια',
    'Είκοσι οκτώ άτομα'                              => '28 άτομα',
    'ΕΙΚΟΣΙ ΟΚΤΩ ΝΟΕΜΒΡΙΟΥ'                          => '28 ΝΟΕΜΒΡΙΟΥ',
    'δεκαεφτά και δεκαεννιά'                         => '17 και 19',
];
foreach ( $converted as $in => $want ) {
    $got = $D::apply( $in );
    nd_check( "apply converts: {$in} => {$want} (got {$got})", $want === $got );
}

// 2. Left as written.
foreach ( [
    'δύο υπουργοί και ένας στους τρεις',
    'δύο τρεις φορές',
    'χιλιάδες πολίτες και εκατομμύρια ευρώ',
    'μισό τοις εκατό',
    'το κόμμα και ένα κόμμα',
    'τρίτη θέση, εικοστή επέτειος',
    'δεκάδες άτομα, εκατοντάδες σπίτια',
    'https://x.com/είκοσι',
    '#δεκαπέντε',
    'Twenty eight people',
    'ένα χιλιάδες',
    'Ήδη 28 Νοεμβρίου και 15%',
] as $kept ) {
    nd_check( "apply keeps: {$kept}", $kept === $D::apply( $kept ) );
}
nd_check( 'apply: a hashtag word stays, the plain word changes', '#δεκαπέντε 15.' === $D::apply( '#δεκαπέντε δεκαπέντε.' ) );
nd_check( 'apply: idempotent', 'Σάββατο 28 Νοεμβρίου' === $D::apply( $D::apply( 'Σάββατο είκοσι οκτώ Νοεμβρίου' ) ) );
nd_check( 'apply: empty text', '' === $D::apply( '' ) );

// 3. Prompts: digits for the caption and hook; unedited 2.89 defaults upgraded.
$assets = dirname( __DIR__ ) . '/assets/prompts/';
$undo   = [
    'social_tiktok_shorts.txt'        => [ 'podcast', 4, [ 'Σε όλες', 'Στο τέλος', '3 έως' ] ],
    'social_tiktok_brief.txt'         => [ 'podcast', 4, [ 'Η ίδια', 'Στο τέλος', '3 έως' ] ],
    'social_tiktok_article_short.txt' => [ 'άρθρο', 3, [ 'Στο τέλος', '3 έως' ] ],
];
foreach ( $undo as $file => [ $source, $n, $after ] ) {
    $text = (string) file_get_contents( $assets . $file );
    $rule = "{$n}. Αριθμοί με ψηφία, όπως στο σενάριο (π.χ. 28 Νοεμβρίου, 45, 15%, 1.200 ευρώ), ακόμη κι αν το {$source} τους γράφει ολογράφως.";
    nd_check( "prompt {$file}: caption rule", false !== strpos( $text, "\n" . $rule . "\n" ) );
    nd_check( "prompt {$file}: hook rule", false !== strpos( $text, 'Ακριβές, όχι clickbait. Αριθμοί με ψηφία (π.χ. 28, 15%).' ) );

    // The 2.89 default: no hook rule, no caption rule, the caption rules after it one lower.
    $old = str_replace( [ ' Αριθμοί με ψηφία (π.χ. 28, 15%).', "\n" . $rule ], '', $text );
    foreach ( $after as $k => $start ) {
        $old = str_replace( "\n" . ( $n + 1 + $k ) . '. ' . $start, "\n" . ( $n + $k ) . '. ' . $start, $old );
    }
    nd_check( "prompt {$file}: 2.89 default rebuilt", false === strpos( $old, 'όπως στο σενάριο' ) );
    switch ( $file ) {
        case 'social_tiktok_shorts.txt':
            nd_check( 'legacy: 2.89 shorts default recognised', PressHub_Social_Settings_Storage::is_legacy_tiktok_shorts_prompt( str_replace( "\n", "\r\n", $old ) ) );
            nd_check( 'legacy: current shorts default is not legacy', ! PressHub_Social_Settings_Storage::is_legacy_tiktok_shorts_prompt( $text ) );
            update_option( 'presshub_social_prompt_tiktok_shorts', $old );
            nd_check( 'legacy: 2.89 shorts default replaced on read', false !== strpos( PressHub_Social_Settings_Storage::get_tiktok_shorts_prompt(), 'όπως στο σενάριο' ) );
            update_option( 'presshub_social_prompt_tiktok_shorts', $old . "\nEdited." );
            nd_check( 'legacy: edited shorts prompt kept', false !== strpos( PressHub_Social_Settings_Storage::get_tiktok_shorts_prompt(), 'Edited.' ) );
            break;
        case 'social_tiktok_brief.txt':
            nd_check( 'legacy: 2.89 brief default recognised', PressHub_Social_Settings_Storage::is_legacy_tiktok_brief_prompt( $old ) );
            nd_check( 'legacy: current brief default is not legacy', ! PressHub_Social_Settings_Storage::is_legacy_tiktok_brief_prompt( $text ) );
            update_option( 'presshub_social_prompt_tiktok_brief', $old );
            nd_check( 'legacy: 2.89 brief default replaced on read', false !== strpos( PressHub_Social_Settings_Storage::get_tiktok_brief_prompt(), 'όπως στο σενάριο' ) );
            break;
        default:
            nd_check( 'legacy: 2.89 article default recognised', PressHub_Social_Settings_Storage::is_legacy_tiktok_article_short_prompt( $old ) );
            nd_check( 'legacy: current article default is not legacy', ! PressHub_Social_Settings_Storage::is_legacy_tiktok_article_short_prompt( $text ) );
            update_option( 'presshub_social_prompt_tiktok_article_short', $old );
            nd_check( 'legacy: 2.89 article default replaced on read', false !== strpos( PressHub_Social_Settings_Storage::get_tiktok_article_short_prompt(), 'όπως στο σενάριο' ) );
    }
}

// 4. The shorts use the same conversion.
nd_check( 'shorts digits(): same conversion', '28 Νοεμβρίου' === PressHub_Social_TikTok_Shorts::digits( 'είκοσι οκτώ Νοεμβρίου' ) );

echo "\nNumberDigitsTest: {$checks} checks, {$failures} failures\n";
if ( $failures > 0 ) {
    exit( 1 );
}
echo "OK\n";
