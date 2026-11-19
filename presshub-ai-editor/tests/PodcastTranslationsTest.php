<?php
/**
 * PodcastTranslationsTest - English and Turkish versions of the Daily Briefing podcast.
 *
 * Covers PressHub_AI_Podcast_Translations (answer parsing, structure check, the
 * translation call, voicing, the cron step chain), the synthesizer's audio-only run
 * (language-aware style, voice overrides, nothing saved) and the language settings.
 * The YouTube side (queueing and upload details) is in run-social-tests.php.
 *
 * Prints "OK" on success and exits 1 on any failure.
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
function pt_check( string $label, $condition ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$GLOBALS['UPLOAD_DIR'] = sys_get_temp_dir() . '/presshub-pt-test-' . uniqid();
@mkdir( $GLOBALS['UPLOAD_DIR'], 0777, true );

/** Translation model stub: records the call and returns a canned answer. */
class PT_Mock_Client extends PressHub_AI_API_Client {
    public $calls  = [];
    public $answer = '';
    public function __construct() {}
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        $this->calls[] = [ 'system' => $sys_prompt, 'user' => $user_prompt, 'action' => $this->get_action(), 'metadata' => $metadata ];
        return $this->answer;
    }
}

/** TTS stub: 0.5 s of silence per turn, recording voice and style. */
class PT_Mock_TTS extends PressHub_AI_API_Client {
    public $calls = [];
    public function __construct() {}
    public function synthesize_speech_via_gemini( string $text, string $voice_name = 'Kore', bool $as_wav = true, string $style = 'formal', $speaker_configs = null, float $speed = 1.0, float $pitch = 0.0 ) {
        $this->calls[] = [ 'text' => $text, 'voice' => $voice_name, 'style' => $style ];
        $pcm = str_repeat( "\x00\x00", 12000 );
        return $as_wav ? self::pcm_to_wav( $pcm, 24000 ) : $pcm;
    }
}

/** Synthesizer whose audio-only run always uses the TTS stub. */
class PT_Synth extends PressHub_AI_Audio_Synthesizer {
    public $tts;
    public function synthesize_script_audio( string $script, string $date, string $edition, string $language, array $voices = [], ?PressHub_AI_API_Client $api_client = null ) {
        return parent::synthesize_script_audio( $script, $date, $edition, $language, $voices, $this->tts );
    }
}

$greek = "[TOPIC_START: Οικονομία]\n[SPEAKER_1]: Καλημέρα, ο κατώτατος μισθός αυξάνεται.\n[SPEAKER_2]: Από τον Ιανουάριο.\n[TOPIC_END]\n"
    . "[TOPIC_START: Καιρός]\n[SPEAKER_1]: Βροχές στα κατεχόμενα και στη Λευκωσία.\n[SPEAKER_2]: Και χιόνι στο Τρόοδος.\n[TOPIC_END]";
$english = "[TOPIC_START: Economy]\n[SPEAKER_1]: Good morning, the minimum wage is going up.\n[SPEAKER_2]: From January.\n[TOPIC_END]\n"
    . "[TOPIC_START: Weather]\n[SPEAKER_1]: Rain in the occupied areas and in Nicosia.\n[SPEAKER_2]: And snow on Troodos.\n[TOPIC_END]";
$answer = "TITLE: PressHub Morning Briefing: minimum wage and rain\nDESCRIPTION:\nThe minimum wage rises from January, and rain is on the way.\n#Cyprus #News\nSCRIPT:\n" . $english;

// ---------------------------------------------------------------------------
// 1. Parsing the model's answer.
// ---------------------------------------------------------------------------
$parsed = PressHub_AI_Podcast_Translations::parse_response( $answer );
pt_check( 'parse: title', ! is_wp_error( $parsed ) && 'PressHub Morning Briefing: minimum wage and rain' === $parsed['title'] );
pt_check( 'parse: description keeps the hashtag line', ! is_wp_error( $parsed ) && str_contains( $parsed['description'], '#Cyprus #News' ) && ! str_contains( $parsed['description'], 'SCRIPT' ) );
pt_check( 'parse: script is everything after SCRIPT:', ! is_wp_error( $parsed ) && $english === $parsed['script'] );
$fenced = PressHub_AI_Podcast_Translations::parse_response( "```\n" . $answer . "\n```" );
pt_check( 'parse: a fenced answer is accepted', ! is_wp_error( $fenced ) && $english === $fenced['script'] );
$bold = PressHub_AI_Podcast_Translations::parse_response( str_replace( [ 'TITLE:', 'DESCRIPTION:', 'SCRIPT:' ], [ '**TITLE**:', '**DESCRIPTION**:', '**SCRIPT**:' ], $answer ) );
pt_check( 'parse: bold section names are accepted', ! is_wp_error( $bold ) && $english === $bold['script'] );
$bad = PressHub_AI_Podcast_Translations::parse_response( $english );
pt_check( 'parse: a bare script is refused', is_wp_error( $bad ) && 'translation_format' === $bad->get_error_code() );

// ---------------------------------------------------------------------------
// 2. Structure check.
// ---------------------------------------------------------------------------
pt_check( 'structure: a faithful translation passes', '' === PressHub_AI_Podcast_Translations::structure_error( $greek, $english ) );
$lost_topic = "[TOPIC_START: Economy]\n[SPEAKER_1]: a\n[SPEAKER_2]: b\n[SPEAKER_1]: c\n[SPEAKER_2]: d\n[TOPIC_END]";
pt_check( 'structure: a lost topic is refused', str_contains( PressHub_AI_Podcast_Translations::structure_error( $greek, $lost_topic ), 'topics' ) );
$short = "[TOPIC_START: Economy]\n[SPEAKER_1]: a\n[TOPIC_END]\n[TOPIC_START: Weather]\n[SPEAKER_2]: b\n[TOPIC_END]";
pt_check( 'structure: losing half the turns is refused', str_contains( PressHub_AI_Podcast_Translations::structure_error( $greek, $short ), 'turns' ) );
pt_check( 'structure: no turns is refused', '' !== PressHub_AI_Podcast_Translations::structure_error( $greek, 'Just prose.' ) );

// ---------------------------------------------------------------------------
// 3. Delivery style in another language.
// ---------------------------------------------------------------------------
$synth = new PressHub_AI_Audio_Synthesizer();
pt_check( 'style: formal becomes an English broadcast tone', 'Say in a professional, authoritative, articulate English news broadcast tone:' === $synth->style_for_language( 'formal', 'English' ) );
pt_check( 'style: a custom style gets the language added', 'Read slowly and warmly. Speak in Turkish:' === $synth->style_for_language( 'Read slowly and warmly.', 'Turkish' ) );
pt_check( 'style: no Greek is left', ! str_contains( $synth->style_for_language( 'natural', 'Turkish' ), 'Greek' ) );

// ---------------------------------------------------------------------------
// 4. Settings.
// ---------------------------------------------------------------------------
pt_check( 'settings: languages default to none', [] === PressHub_AI_Settings_Storage::get_briefing_podcast_languages() );
pt_check( 'settings: unknown languages are dropped, order is fixed', [ 'en', 'tr' ] === PressHub_AI_Settings_Storage::sanitize_briefing_podcast_languages( [ 'tr', 'fr', '', 'en', 'en' ] ) );
pt_check( 'settings: built-in Turkish notes use Turkish Cypriot place names', str_contains( PressHub_AI_Settings_Storage::get_briefing_podcast_language_notes( 'tr' ), 'Lefkoşa' ) );
update_option( 'presshub_ai_briefing_podcast_language_notes', [ 'en' => '', 'tr' => 'Kendi notlarım' ] );
pt_check( 'settings: an emptied box goes back to the built-in notes', str_contains( PressHub_AI_Settings_Storage::get_briefing_podcast_language_notes( 'en' ), 'Nicosia' ) );
pt_check( 'settings: saved notes are used', 'Kendi notlarım' === PressHub_AI_Settings_Storage::get_briefing_podcast_language_notes( 'tr' ) );
$voices = PressHub_AI_Settings_Storage::sanitize_briefing_podcast_language_voices( [ 'en' => [ 'presenter_1' => 'Aoede', 'presenter_2' => '<script>' ], 'xx' => [ 'presenter_1' => 'Kore' ] ] );
pt_check( 'settings: voices are kept per known language, bad names dropped', 'Aoede' === $voices['en']['presenter_1'] && '' === $voices['en']['presenter_2'] && ! isset( $voices['xx'] ) && '' === $voices['tr']['presenter_1'] );

// ---------------------------------------------------------------------------
// 5. Translation call.
// ---------------------------------------------------------------------------
$date = '2026-11-19';
( new PressHub_AI_Podcast_Producer() )->save_script( $date, $greek, 'curated_briefing', 0, '', 'morning' );
$GLOBALS['POSTS_STORE'][701] = [ 'ID' => 701, 'post_title' => 'Πρωινό Podcast Ενημέρωσης - 19/11/2026', 'post_status' => 'publish', 'post_content' => '' ];
update_post_meta( 701, '_presshub_briefing_type', 'podcast' );
update_post_meta( 701, '_presshub_briefing_date', $date );
update_post_meta( 701, '_presshub_briefing_edition', 'morning' );

$client         = new PT_Mock_Client();
$client->answer = $answer;
$entry          = PressHub_AI_Podcast_Translations::translate( 701, 'en', $client );
pt_check( 'translate: returns the stored entry', is_array( $entry ) && 'translated' === ( $entry['status'] ?? '' ) );
pt_check( 'translate: stored on the Greek podcast post', $english === ( PressHub_AI_Podcast_Translations::get_entry( 701, 'en' )['script'] ?? '' ) );
pt_check( 'translate: one call with its own action (no agenda or fiches)', 1 === count( $client->calls ) && 'podcast_translation' === $client->calls[0]['action'] );
pt_check( 'translate: the prompt names the language and carries the notes', str_contains( $client->calls[0]['system'], 'into English' ) && str_contains( $client->calls[0]['system'], 'Nicosia' ) && ! str_contains( $client->calls[0]['system'], '{notes}' ) );
pt_check( 'translate: the Greek script and title are sent', str_contains( $client->calls[0]['user'], 'Λευκωσία' ) && str_contains( $client->calls[0]['user'], 'Πρωινό Podcast' ) );
pt_check( 'translate: the source is remembered', md5( $greek ) === ( $entry['source_hash'] ?? '' ) );

$client->answer = "TITLE: x\nDESCRIPTION:\ny\nSCRIPT:\n" . $lost_topic;
$failed         = PressHub_AI_Podcast_Translations::translate( 701, 'tr', $client );
pt_check( 'translate: a broken translation fails', is_wp_error( $failed ) && 'translation_structure' === $failed->get_error_code() );
pt_check( 'translate: the failure is recorded for the language', 'failed' === ( PressHub_AI_Podcast_Translations::get_entry( 701, 'tr' )['status'] ?? '' ) );
pt_check( 'translate: other languages are untouched', 'translated' === ( PressHub_AI_Podcast_Translations::get_entry( 701, 'en' )['status'] ?? '' ) );

// ---------------------------------------------------------------------------
// 6. Voicing.
// ---------------------------------------------------------------------------
update_option( 'presshub_ai_briefing_tts_engine', 'gemini' );
update_option( 'presshub_ai_briefing_voice_female', 'Kore' );
update_option( 'presshub_ai_briefing_voice_male', 'Fenrir' );
update_option( 'presshub_ai_briefing_host_count', 2 );
update_option( 'presshub_ai_briefing_tts_style', 'formal' );
update_option( 'presshub_ai_briefing_podcast_language_voices', [ 'en' => [ 'presenter_2' => 'Puck' ] ] );
$posts_before            = $GLOBALS['INSERTED_POSTS'] ?? [];
$GLOBALS['SIDELOAD_RETURN_ID'] = 812;
$synth      = new PT_Synth();
$synth->tts = new PT_Mock_TTS();
$voiced     = PressHub_AI_Podcast_Translations::voice( 701, 'en', $synth );
pt_check( 'voice: returns the voiced entry', is_array( $voiced ) && 'voiced' === ( $voiced['status'] ?? '' ) );
pt_check( 'voice: every translated turn is voiced', 4 === count( $synth->tts->calls ) && 'Good morning, the minimum wage is going up.' === ( $synth->tts->calls[0]['text'] ?? '' ) );
pt_check( 'voice: presenter 1 keeps the Greek voice', 'Kore' === ( $synth->tts->calls[0]['voice'] ?? '' ) );
pt_check( 'voice: presenter 2 uses the English voice', 'Puck' === ( $synth->tts->calls[1]['voice'] ?? '' ) );
pt_check( 'voice: the delivery style is in English', str_contains( (string) ( $synth->tts->calls[0]['style'] ?? '' ), 'English news broadcast' ) );
pt_check( 'voice: the audio is in the Media Library', 812 === ( $voiced['attachment_id'] ?? 0 ) && str_contains( (string) ( $voiced['audio_url'] ?? '' ), 'attachment_id=812' ) );
pt_check( 'voice: the timeline has every turn and both topics', is_array( $voiced['timeline'] ?? null ) && 4 === count( $voiced['timeline']['segments'] ) && 2 === count( $voiced['timeline']['topics'] ) && 'Economy' === $voiced['timeline']['topics'][0]['title'] );
pt_check( 'voice: the length is the timeline\'s', abs( (float) $voiced['duration_sec'] - (float) $voiced['timeline']['duration'] ) < 0.01 && $voiced['duration_sec'] > 2.0 );
pt_check( 'voice: no podcast post is made', ( $GLOBALS['INSERTED_POSTS'] ?? [] ) === $posts_before );
pt_check( 'voice: the Greek post keeps its own audio', '' === get_post_meta( 701, '_presshub_audio_url', true ) );

$no_tr = PressHub_AI_Podcast_Translations::voice( 701, 'tr', $synth );
pt_check( 'voice: a language without a translation is not voiced', is_wp_error( $no_tr ) );
update_post_meta( 701, PressHub_AI_Podcast_Translations::META_KEY, array_merge( PressHub_AI_Podcast_Translations::get_all( 701 ), [ 'tr' => [ 'status' => 'translated', 'script' => $english, 'title' => 't' ] ] ) );
update_option( 'presshub_ai_briefing_tts_engine', 'google_cloud' );
$gc = PressHub_AI_Podcast_Translations::voice( 701, 'tr', $synth );
pt_check( 'voice: Google Cloud TTS needs voices of the language', is_wp_error( $gc ) && 'translation_voice_missing' === $gc->get_error_code() );
update_option( 'presshub_ai_briefing_tts_engine', 'gemini' );

// ---------------------------------------------------------------------------
// 7. Cron steps.
// ---------------------------------------------------------------------------
update_option( 'presshub_ai_briefing_podcast_languages', [ 'en', 'tr' ] );
pt_check( 'steps: Turkish follows English', 'tr' === PressHub_AI_Podcast_Translations::next_language( 'en' ) && '' === PressHub_AI_Podcast_Translations::next_language( 'tr' ) );
$GLOBALS['SCHEDULED_EVENTS'] = [];
PressHub_AI_Podcast_Translations::start( $date, 'morning', 701 );
$ev = $GLOBALS['SCHEDULED_EVENTS'][0] ?? [];
pt_check( 'steps: the Greek audio starts the English translation as its own cron step', 'presshub_ai_run_briefing_step' === ( $ev['hook'] ?? '' ) && [ $date, 'morning', 'translate:en', 0 ] === ( $ev['args'] ?? [] ) );
update_option( 'presshub_ai_briefing_podcast_languages', [] );
$GLOBALS['SCHEDULED_EVENTS'] = [];
PressHub_AI_Podcast_Translations::start( $date, 'morning', 701 );
pt_check( 'steps: nothing runs with no language turned on', empty( $GLOBALS['SCHEDULED_EVENTS'] ) );
pt_check( 'steps: an unknown step is ignored', null === presshub_ai_execute_briefing_step( $date, 'morning', 'translate:fr', 0 ) );

@array_map( 'unlink', glob( $GLOBALS['UPLOAD_DIR'] . '/*/*/*' ) ?: [] );

if ( $failures > 0 ) {
    fwrite( STDERR, "{$failures} of {$checks} checks failed\n" );
    exit( 1 );
}
echo "OK ({$checks} checks)\n";
exit( 0 );
