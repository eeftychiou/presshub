<?php
/**
 * TTSPronunciationsTest — pronunciation list for the voice (2.89.0).
 *
 * Test cases:
 *   1. parse(): "word = say", language prefixes, comments and broken lines skipped.
 *   2. apply(): whole words only, any case, Greek letters, longest entry first, no
 *      chained replacements, phrases across spaces, "$1" kept literally.
 *   3. Language: an entry for one language is used only there and wins over a
 *      general entry; "Turkish" maps to tr, empty to el.
 *   4. sanitize(): CRLF, tags and control characters removed, lines trimmed.
 *   5. Registered setting with registry entry; synthesize_turn() sends the replaced
 *      text (labels of a two-voice request untouched) and the cache key follows the list.
 *   6. Shorts prompts ask for digits, and the old defaults are upgraded.
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

function tp_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$P = 'PressHub_AI_TTS_Pronunciations';

// 1. parse()
$entries = $P::parse( "# notes\nBrexit = Μπρέξιτ\n\ntr: Λευκωσία = Lefkoşa\nno equals sign\n = nothing\nword =\nEN : CERN = Σερν" );
tp_check( 'parse: three entries', 3 === count( $entries ) );
tp_check( 'parse: general entry', [ 'lang' => '', 'word' => 'Brexit', 'say' => 'Μπρέξιτ' ] === ( $entries[0] ?? null ) );
tp_check( 'parse: tr entry', [ 'lang' => 'tr', 'word' => 'Λευκωσία', 'say' => 'Lefkoşa' ] === ( $entries[1] ?? null ) );
tp_check( 'parse: prefix case-insensitive', 'en' === ( $entries[2]['lang'] ?? '' ) && 'CERN' === ( $entries[2]['word'] ?? '' ) );
tp_check( 'parse: "a: b = c" is a prefix only for two letters', '' === ( $P::parse( 'Dr. No: film = Ντόκτορ Νο' )[0]['lang'] ?? 'x' ) );

// 2. apply()
$list = "Brexit = Μπρέξιτ\nΑΗΚ = Α Η Κάπα\nΑΗΚ Λεμεσού = Αρχή Ηλεκτρισμού Λεμεσού\nΝίκος = Νίκος\nCERN = Σερν\nX = $1 dollars";
tp_check( 'apply: replaces a word', 'Μετά το Μπρέξιτ, τι;' === $P::apply( 'Μετά το Brexit, τι;', '', $list ) );
tp_check( 'apply: any case', 'Το Μπρέξιτ και το Μπρέξιτ' === $P::apply( 'Το BREXIT και το brexit', '', $list ) );
tp_check( 'apply: whole words only', 'Brexiters και antiBrexit' === $P::apply( 'Brexiters και antiBrexit', '', $list ) );
tp_check( 'apply: Greek word boundaries', 'Η Α Η Κάπα είπε, ΑΗΚΑ όχι' === $P::apply( 'Η ΑΗΚ είπε, ΑΗΚΑ όχι', '', $list ) );
tp_check( 'apply: longest entry first', 'Η Αρχή Ηλεκτρισμού Λεμεσού.' === $P::apply( 'Η ΑΗΚ Λεμεσού.', '', $list ) );
tp_check( 'apply: phrase across spaces', 'Η Αρχή Ηλεκτρισμού Λεμεσού.' === $P::apply( 'Η ΑΗΚ   Λεμεσού.', '', $list ) );
tp_check( 'apply: no chained replacement', 'Σερν' === $P::apply( 'CERN', '', "CERN = Σερν\nΣερν = Λάθος" ) );
tp_check( 'apply: $1 in the respelling is literal', 'το $1 dollars' === $P::apply( 'το X', '', $list ) );
tp_check( 'apply: digits next to a word are not a boundary', 'X5 και 5X' === $P::apply( 'X5 και 5X', '', $list ) );
tp_check( 'apply: empty list leaves text', 'Brexit' === $P::apply( 'Brexit', '', '' ) );
tp_check( 'apply: special characters in a word', 'Ο Σι Πλας Πλας.' === $P::apply( 'Ο C++.', '', 'C++ = Σι Πλας Πλας' ) );

// 3. Language
$lang_list = "Paphos = Πάφος\nen: Paphos = Pafos\ntr: Lefkoşa = Lefkosha";
tp_check( 'lang: general entry in Greek', 'Πάφος' === $P::apply( 'Paphos', '', $lang_list ) );
tp_check( 'lang: English entry wins in English', 'Pafos' === $P::apply( 'Paphos', 'English', $lang_list ) );
tp_check( 'lang: Turkish entry only in Turkish', 'Lefkosha' === $P::apply( 'Lefkoşa', 'Turkish', $lang_list ) && 'Lefkoşa' === $P::apply( 'Lefkoşa', '', $lang_list ) );
tp_check( 'lang: codes', 'el' === $P::language_code( '' ) && 'tr' === $P::language_code( 'Turkish' ) && 'en' === $P::language_code( 'en' ) );

// 4. sanitize()
tp_check( 'sanitize: CRLF, tags, control chars, trimmed lines', "a = b\nc = d" === $P::sanitize( "  a = b  \r\n<b>c</b> = d\x07\r\n" ) );
tp_check( 'sanitize: not a string', '' === $P::sanitize( [ 'x' ] ) );
tp_check( 'sanitize: capped', $P::MAX_CHARS === mb_strlen( $P::sanitize( str_repeat( 'α', $P::MAX_CHARS + 50 ) ) ) );

// 5. Setting and synthesizer
$registry = include dirname( __DIR__ ) . '/includes/settings-registry-data.php';
tp_check( 'registry: entry on the voices tab', 'briefing' === ( $registry['presshub_ai_tts_pronunciations']['tab'] ?? '' ) );

class TP_Mock_TTS_Client extends PressHub_AI_API_Client {
    public $texts = [];
    public function __construct() {}
    public function synthesize_speech_via_gemini( string $text, string $voice_name = 'Kore', bool $as_wav = true, string $style = 'formal', $speaker_configs = null, float $speed = 1.0, float $pitch = 0.0 ) {
        $this->texts[] = $text;
        return self::pcm_to_wav( str_repeat( "\x12\x34", 1200 ), 24000 );
    }
}

$GLOBALS['UPLOAD_DIR'] = sys_get_temp_dir() . '/presshub-pron-test-' . uniqid();
update_option( $P::OPTION, "Brexit = Μπρέξιτ\nCERN = Σερν" );
$synth  = new PressHub_AI_Audio_Synthesizer();
$client = new TP_Mock_TTS_Client();
$synth->synthesize_turn( 'Το Brexit και το CERN.', 'Kore', 1.0, 0.0, $client, 'formal' );
tp_check( 'synth: voice gets the respelling', 'Το Μπρέξιτ και το Σερν.' === ( $client->texts[0] ?? '' ) );

$client->texts = [];
$synth->synthesize_turn( "CERN: Το CERN λέει\nΜαρία: Brexit", 'Kore', 1.0, 0.0, $client, 'formal', '', [ [ 'speaker' => 'CERN', 'voice' => 'Kore' ], [ 'speaker' => 'Μαρία', 'voice' => 'Puck' ] ] );
tp_check( 'synth: two-voice labels untouched', "CERN: Το Σερν λέει\nΜαρία: Μπρέξιτ" === ( $client->texts[0] ?? '' ) );

// Cache key follows the list: a dated turn is cached, an edited list sends it again.
$client->texts = [];
$synth->synthesize_turn( 'Το Brexit.', 'Kore', 1.0, 0.0, $client, 'formal', '2026-11-24' );
$synth->synthesize_turn( 'Το Brexit.', 'Kore', 1.0, 0.0, $client, 'formal', '2026-11-24' );
tp_check( 'synth: second dated turn is a cache hit', 1 === count( $client->texts ) );
update_option( $P::OPTION, 'Brexit = Μπρέξιτ τώρα' );
$synth->synthesize_turn( 'Το Brexit.', 'Kore', 1.0, 0.0, $client, 'formal', '2026-11-24' );
tp_check( 'synth: edited list synthesizes again', 2 === count( $client->texts ) && 'Το Μπρέξιτ τώρα.' === end( $client->texts ) );
update_option( $P::OPTION, '' );

// 6. Prompts: digits in the script; unedited older defaults are upgraded.
$assets = dirname( __DIR__ ) . '/assets/prompts/';
foreach ( [ 'social_tiktok_shorts.txt', 'social_tiktok_article_short.txt', 'social_tiktok_brief.txt' ] as $file ) {
    $text = (string) file_get_contents( $assets . $file );
    tp_check( "prompt {$file}: asks for digits", false !== strpos( $text, 'Αριθμοί με ψηφία' ) && false !== strpos( $text, '15%' ) );
    tp_check( "prompt {$file}: no spelled-out rule", false === strpos( $text, 'όπως εκφωνούνται' ) );
}
tp_check( 'legacy: current shorts default is not legacy', ! PressHub_Social_Settings_Storage::is_legacy_tiktok_shorts_prompt( (string) file_get_contents( $assets . 'social_tiktok_shorts.txt' ) ) );
tp_check( 'legacy: current brief default is not legacy', ! PressHub_Social_Settings_Storage::is_legacy_tiktok_brief_prompt( (string) file_get_contents( $assets . 'social_tiktok_brief.txt' ) ) );
tp_check( 'legacy: current article default is not legacy', ! PressHub_Social_Settings_Storage::is_legacy_tiktok_article_short_prompt( (string) file_get_contents( $assets . 'social_tiktok_article_short.txt' ) ) );

$old_rule = 'Ημερομηνίες και αριθμοί γραμμένοι όπως εκφωνούνται (π.χ. «3 Μαΐου»), χωρίς σύμβολα, emoji ή συνδέσμους.';
$new_rule_start = 'Αριθμοί με ψηφία';
$old_article = (string) file_get_contents( $assets . 'social_tiktok_article_short.txt' );
$pos = strpos( $old_article, $new_rule_start );
$end = strpos( $old_article, "\n", $pos );
$old_article = substr( $old_article, 0, $pos ) . $old_rule . substr( $old_article, $end );
tp_check( 'legacy: 2.87 article default recognised', PressHub_Social_Settings_Storage::is_legacy_tiktok_article_short_prompt( str_replace( "\n", "\r\n", $old_article ) ) );
update_option( 'presshub_social_prompt_tiktok_article_short', $old_article );
tp_check( 'legacy: 2.87 article default replaced on read', false !== strpos( PressHub_Social_Settings_Storage::get_tiktok_article_short_prompt(), $new_rule_start ) );
update_option( 'presshub_social_prompt_tiktok_article_short', $old_article . "\nEdited." );
tp_check( 'legacy: edited article prompt kept', false !== strpos( PressHub_Social_Settings_Storage::get_tiktok_article_short_prompt(), 'Edited.' ) );

echo "\nTTSPronunciationsTest: {$checks} checks, {$failures} failures\n";
if ( $failures > 0 ) {
    exit( 1 );
}
echo "OK\n";
