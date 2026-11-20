<?php
/**
 * SpeechRequestsTest (2.74.0) - fewer Gemini speech requests per episode, and one
 * audio job per edition.
 *
 *   1. plan_speech_requests(): per_turn (unchanged), same_voice, two_voices, the
 *      character cap, and the fallbacks (3 hosts, equal voices, Google Cloud).
 *   2. two_voice_labels() and split_request_seconds().
 *   3. build_gemini_tts_request_body() with labelled dialogue: one part, labels kept.
 *   4. synthesize_podcast() end to end in each mode: request count, bodies, timeline.
 *   5. A failed or cut-short joined request is voiced turn by turn; a daily quota error is not.
 *   6. The audio run lock: second job refused, re-entry, release, stale takeover.
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
function sr_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$S = 'PressHub_AI_Audio_Synthesizer';
$voice_of = static function ( string $speaker ): string {
    return [ 'female' => 'Aoede', 'male' => 'Charon', 'tertiary' => 'Puck' ][ $speaker ] ?? 'Aoede';
};
$turns = [
    [ 'speaker' => 'female', 'text' => 'Καλημέρα σας.' ],
    [ 'speaker' => 'female', 'text' => "Σήμερα\nέχουμε τρία θέματα." ],
    [ 'speaker' => 'male', 'text' => 'Ας ξεκινήσουμε.' ],
    [ 'speaker' => 'female', 'text' => 'Πρώτο θέμα.' ],
];
$labels = [ 'Μαρία', 'Νίκος' ];
$tv     = [ 'Aoede', 'Charon' ];

// 1. Planner -------------------------------------------------------------
$p = $S::plan_speech_requests( $turns, 'per_turn', 1500, $voice_of, 2, 'gemini', $labels, $tv );
sr_check( 'per_turn: one request per turn', 4 === count( $p ) );
sr_check( 'per_turn: text unchanged', "Σήμερα\nέχουμε τρία θέματα." === $p[1]['text'] && 'Aoede' === $p[1]['voice'] && null === $p[1]['speakers'] );

$p = $S::plan_speech_requests( $turns, 'same_voice', 1500, $voice_of, 2, 'gemini', $labels, $tv );
sr_check( 'same_voice: back-to-back lines by one voice share a request', 3 === count( $p ) && [ 0, 1 ] === $p[0]['turns'] );
sr_check( 'same_voice: joined text, no labels, whitespace flattened', "Καλημέρα σας.\n\nΣήμερα έχουμε τρία θέματα." === $p[0]['text'] && null === $p[0]['speakers'] );

$p = $S::plan_speech_requests( $turns, 'two_voices', 1500, $voice_of, 2, 'gemini', $labels, $tv );
sr_check( 'two_voices: the whole list in one request', 1 === count( $p ) && [ 0, 1, 2, 3 ] === $p[0]['turns'] );
sr_check( 'two_voices: labelled lines', "Μαρία: Καλημέρα σας.\nΜαρία: Σήμερα έχουμε τρία θέματα.\nΝίκος: Ας ξεκινήσουμε.\nΜαρία: Πρώτο θέμα." === $p[0]['text'] );
sr_check( 'two_voices: speakers map labels to voices', [ [ 'speaker' => 'Μαρία', 'voice' => 'Aoede' ], [ 'speaker' => 'Νίκος', 'voice' => 'Charon' ] ] === $p[0]['speakers'] );

$p = $S::plan_speech_requests( $turns, 'two_voices', 40, $voice_of, 2, 'gemini', $labels, $tv );
$max = 0;
foreach ( $p as $r ) {
    $max = max( $max, mb_strlen( $r['text'] ) );
}
sr_check( 'cap: more requests with a small cap', count( $p ) >= 2 );
sr_check( 'cap: every turn planned once, in order', [ 0, 1, 2, 3 ] === array_merge( ...array_map( static function ( $r ) { return $r['turns']; }, $p ) ) );

$long = [ [ 'speaker' => 'female', 'text' => str_repeat( 'α', 500 ) ], [ 'speaker' => 'female', 'text' => 'β' ] ];
$p    = $S::plan_speech_requests( $long, 'same_voice', 300, $voice_of, 2, 'gemini', $labels, $tv );
sr_check( 'cap: a turn longer than the cap is a request of its own', 2 === count( $p ) );

$p = $S::plan_speech_requests( $turns, 'two_voices', 1500, $voice_of, 3, 'gemini', $labels, $tv );
sr_check( 'two_voices with 3 hosts: plans like same_voice', 3 === count( $p ) && null === $p[0]['speakers'] );
$p = $S::plan_speech_requests( $turns, 'two_voices', 1500, $voice_of, 2, 'gemini', $labels, [ 'Aoede', 'Aoede' ] );
sr_check( 'two_voices with one voice twice: plans like same_voice', 3 === count( $p ) );
$p = $S::plan_speech_requests( $turns, 'two_voices', 1500, $voice_of, 2, 'google_cloud', $labels, $tv );
sr_check( 'Google Cloud TTS: always per turn', 4 === count( $p ) );
$p = $S::plan_speech_requests( $turns, 'nonsense', 1500, $voice_of, 2, 'gemini', $labels, $tv );
sr_check( 'unknown mode: per turn', 4 === count( $p ) );
$solo = static function ( string $s ): string { return 'Aoede'; };
$p = $S::plan_speech_requests( $turns, 'same_voice', 1500, $solo, 1, 'gemini', $labels, $tv );
sr_check( 'one host, same_voice: one request', 1 === count( $p ) && null === $p[0]['speakers'] );

// 2. Labels and timing -----------------------------------------------------
sr_check( 'labels: plain names kept', [ 'Μαρία', 'Νίκος' ] === $S::two_voice_labels( [ ' Μαρία ', 'Νίκος' ] ) );
sr_check( 'labels: odd name becomes SpeakerN', [ 'Speaker1', 'Νίκος' ] === $S::two_voice_labels( [ '**[Μαρία]**', 'Νίκος' ] ) );
sr_check( 'labels: equal names become Speaker1/2', [ 'Speaker1', 'Speaker2' ] === $S::two_voice_labels( [ 'Anna', 'anna' ] ) );
$sh = $S::split_request_seconds( 10.5, [ 'aaaa', 'aaaaaaaa' ], 500 );
sr_check( 'split: turns plus pauses equal the audio', abs( array_sum( $sh ) + 0.5 - 10.5 ) < 1e-9 );
sr_check( 'split: by text length', abs( $sh[1] - 2 * $sh[0] ) < 1e-9 );
sr_check( 'split: one turn keeps the whole length', [ 3.0 ] === $S::split_request_seconds( 3.0, [ 'x' ], 250 ) );

// 3. Request body ------------------------------------------------------------
$ms   = [
    [ 'speaker' => 'Μαρία', 'voiceConfig' => [ 'prebuiltVoiceConfig' => [ 'voiceName' => 'Aoede' ] ] ],
    [ 'speaker' => 'Νίκος', 'voiceConfig' => [ 'prebuiltVoiceConfig' => [ 'voiceName' => 'Charon' ] ] ],
];
$text = "Μαρία: Καλημέρα.\nΝίκος: Γεια σας.";
$body = PressHub_AI_API_Client::build_gemini_tts_request_body( $text, 'Aoede', true, $ms, 'formal', 1.0, 0.0, 'structured', '', true );
$parts = $body['contents'][0]['parts'] ?? [];
sr_check( 'body: one part holding the labelled dialogue', 1 === count( $parts ) && $text === $parts[0]['text'] );
sr_check( 'body: no per-part speaker tag', ! isset( $parts[0]['speech_metadata']['speaker'] ) && isset( $parts[0]['speech_metadata']['style'] ) );
sr_check( 'body: multiSpeakerVoiceConfig names match the labels', 'Μαρία' === ( $body['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'][0]['speaker'] ?? '' ) );
$old = PressHub_AI_API_Client::build_gemini_tts_request_body( $text, 'Aoede', true, $ms, 'formal', 1.0, 0.0, 'structured', '' );
sr_check( 'body: without the flag, the old split parts are unchanged', 2 === count( $old['contents'][0]['parts'] ?? [] ) );

// 4. End to end ---------------------------------------------------------------
$GLOBALS['UPLOAD_DIR'] = sys_get_temp_dir() . '/presshub-speech-req-' . uniqid();
$GLOBALS['OPTIONS_STORE']['presshub_ai_provider']              = 'gemini';
$GLOBALS['OPTIONS_STORE']['presshub_ai_configured_providers']  = presshub_test_provider_keys( 'test-gemini-key', [ 'gemini' ] );
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_female'] = 'Aoede';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_male']   = 'Charon';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model']    = 'gemini-2.5-flash-preview-tts';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_count']   = 2;
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_female']  = 'Μαρία';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_male']    = 'Νίκος';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_turn_pause_ms'] = 250;

$captured = [];
$respond  = 'audio'; // audio | short | error | quota
$GLOBALS['CAPTURE_FILTER'] = function ( $default, $req ) use ( &$captured, &$respond ) {
    $args       = is_array( $req ) && isset( $req[1] ) ? $req[1] : $req;
    $body       = json_decode( $args['body'] ?? '{}', true );
    if ( ! isset( $body['generationConfig']['speechConfig'] ) ) {
        return null; // not a speech request
    }
    $captured[] = $body;
    $multi      = ! empty( $body['generationConfig']['speechConfig']['multiSpeakerVoiceConfig'] );
    if ( 'quota' === $respond || ( 'error' === $respond && $multi ) ) {
        $quota = 'quota' === $respond;
        return [
            'response' => [ 'code' => $quota ? 429 : 400, 'message' => 'x' ],
            'body'     => json_encode( [ 'error' => [
                'code'    => $quota ? 429 : 400,
                'status'  => $quota ? 'RESOURCE_EXHAUSTED' : 'INVALID_ARGUMENT',
                'message' => $quota ? 'Quota exceeded for metric: generate_requests_per_model_per_day' : 'bad request',
                'details' => $quota ? [ [ '@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [ [ 'quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier' ] ] ] ] : [],
            ] ] ),
        ];
    }
    $chars   = 0;
    foreach ( (array) ( $body['contents'][0]['parts'] ?? [] ) as $part ) {
        $chars += mb_strlen( (string) ( $part['text'] ?? '' ) );
    }
    $seconds = ( 'short' === $respond && $multi ) ? 0.1 : max( 0.2, $chars / 14 );
    $pcm     = str_repeat( "\x01\x02", (int) ( 24000 * $seconds ) );
    return [
        'response' => [ 'code' => 200, 'message' => 'OK' ],
        'body'     => json_encode( [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'inlineData' => [ 'mimeType' => 'audio/pcm;rate=24000', 'data' => base64_encode( $pcm ) ] ] ] ] ] ] ] ),
    ];
};

$script = "[Μαρία]: Καλημέρα σας από την πρωινή ενημέρωση.\n[Μαρία]: Έχουμε τρία θέματα σήμερα.\n[Νίκος]: Ας ξεκινήσουμε με το πρώτο.\n[Μαρία]: Το πρώτο θέμα είναι ο καιρός.\n[Νίκος]: Και ο καιρός είναι καλός.";

$run = function ( string $mode, string $date ) use ( &$captured, $script ) {
    $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_request_mode'] = $mode;
    PressHub_AI_Audio_Synthesizer::clear_turn_cache( $date );
    $captured = [];
    $synth    = new PressHub_AI_Audio_Synthesizer();
    return $synth->synthesize_podcast( $date, $script, null );
};

$res = $run( 'per_turn', '2026-11-21' );
sr_check( 'e2e per_turn: succeeds', is_array( $res ) && ! empty( $res['success'] ) );
sr_check( 'e2e per_turn: 5 requests, as before', 5 === count( $captured ) );
sr_check( 'e2e per_turn: no multiSpeakerVoiceConfig', 0 === count( array_filter( $captured, static function ( $b ) { return ! empty( $b['generationConfig']['speechConfig']['multiSpeakerVoiceConfig'] ); } ) ) );

$res = $run( 'same_voice', '2026-11-22' );
sr_check( 'e2e same_voice: succeeds', is_array( $res ) && ! empty( $res['success'] ) );
sr_check( 'e2e same_voice: 4 requests (first two lines joined)', 4 === count( $captured ) );

$res = $run( 'two_voices', '2026-11-23' );
sr_check( 'e2e two_voices: succeeds', is_array( $res ) && ! empty( $res['success'] ) );
sr_check( 'e2e two_voices: 1 request', 1 === count( $captured ) );
$b = $captured[0] ?? [];
sr_check( 'e2e two_voices: labelled text in one part', 1 === count( $b['contents'][0]['parts'] ?? [] ) && 0 === strpos( (string) ( $b['contents'][0]['parts'][0]['text'] ?? '' ), 'Μαρία: Καλημέρα' ) );
sr_check( 'e2e two_voices: Νίκος is Charon', 'Charon' === ( $b['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'][1]['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? '' ) );
$pid = (int) ( $res['post_id'] ?? 0 );
$timeline = $pid ? get_post_meta( $pid, PressHub_AI_Podcast_Timeline::META_KEY, true ) : null;
if ( is_string( $timeline ) ) {
    $timeline = json_decode( $timeline, true );
}
$segments = is_array( $timeline ) ? (array) ( $timeline['segments'] ?? [] ) : [];
sr_check( 'e2e two_voices: timeline keeps all 5 turns', 5 === count( $segments ) );
if ( 5 === count( $segments ) ) {
    sr_check( 'e2e two_voices: speaker colours kept', [ 1, 1, 2, 1, 2 ] === array_map( static function ( $s ) { return (int) $s['speaker']; }, $segments ) );
    $ordered = true;
    for ( $i = 1; $i < 5; $i++ ) {
        $ordered = $ordered && $segments[ $i ]['start'] >= $segments[ $i - 1 ]['end'];
    }
    sr_check( 'e2e two_voices: segments in order', $ordered );
}

$captured = [];
$res      = ( new PressHub_AI_Audio_Synthesizer() )->synthesize_podcast( '2026-11-23', $script, null );
sr_check( 'e2e two_voices: a second run comes from the cache', is_array( $res ) && 0 === count( $captured ) );

// 5. Fallbacks ------------------------------------------------------------------
$respond = 'error';
$res     = $run( 'two_voices', '2026-11-24' );
sr_check( 'fallback error: episode still made', is_array( $res ) && ! empty( $res['success'] ) );
$single = array_filter( $captured, static function ( $b ) { return empty( $b['generationConfig']['speechConfig']['multiSpeakerVoiceConfig'] ); } );
sr_check( 'fallback error: then voiced turn by turn', 5 === count( $single ) );

$respond = 'short';
$res     = $run( 'two_voices', '2026-11-25' );
sr_check( 'fallback short audio: episode still made', is_array( $res ) && ! empty( $res['success'] ) );
$single = array_filter( $captured, static function ( $b ) { return empty( $b['generationConfig']['speechConfig']['multiSpeakerVoiceConfig'] ); } );
sr_check( 'fallback short audio: voiced turn by turn', 5 === count( $single ) );

$respond = 'quota';
$res     = $run( 'two_voices', '2026-11-26' );
sr_check( 'quota: stops with the quota error', is_wp_error( $res ) && 'tts_daily_quota_exceeded' === $res->get_error_code() );
sr_check( 'quota: no turn-by-turn retry', 1 === count( $captured ) );
$respond = 'audio';

// 6. Lock ---------------------------------------------------------------------------
sr_check( 'lock: first job takes it', $S::acquire_run_lock( '2026-11-27', 'morning' ) );
sr_check( 'lock: re-entry in the same process', $S::acquire_run_lock( '2026-11-27', 'morning' ) );
sr_check( 'lock: other edition is free', $S::acquire_run_lock( '2026-11-27', 'noon' ) );
$S::release_run_lock( '2026-11-27', 'noon' );

// A second process: the lock is held by someone else.
$held = new ReflectionProperty( $S, 'held_run_locks' );
$held->setAccessible( true );
$mine = $held->getValue();
$held->setValue( null, [] );
sr_check( 'lock: a second job is refused', ! $S::acquire_run_lock( '2026-11-27', 'morning' ) );
$captured = [];
$res      = ( new PressHub_AI_Audio_Synthesizer() )->synthesize_podcast( '2026-11-27', $script, 'morning' );
sr_check( 'lock: synthesize_podcast stops with synthesis_in_progress', is_wp_error( $res ) && 'synthesis_in_progress' === $res->get_error_code() );
sr_check( 'lock: no speech request sent', 0 === count( $captured ) );
$step = presshub_ai_run_podcast_audio_step( '2026-11-27', 'morning', 0, true );
sr_check( 'lock: the audio step is skipped quietly', null === $step );

// A lock left by a dead process is taken over.
$mem = new ReflectionProperty( $S, 'memory_locks' );
$mem->setAccessible( true );
$rows = $mem->getValue();
$name = $S::run_lock_name( '2026-11-27', 'morning' );
$rows[ $name ] = ( time() - $S::RUN_LOCK_TTL - 5 ) . '.dead';
$mem->setValue( null, $rows );
sr_check( 'lock: a stale lock is taken over', $S::acquire_run_lock( '2026-11-27', 'morning' ) );
$S::release_run_lock( '2026-11-27', 'morning' );
sr_check( 'lock: released', ! isset( $mem->getValue()[ $name ] ) );
$held->setValue( null, $mine );
$S::release_run_lock( '2026-11-27', 'morning' );

$res = ( new PressHub_AI_Audio_Synthesizer() )->synthesize_podcast( '2026-11-28', $script, 'noon' );
sr_check( 'lock: a finished run releases its lock', is_array( $res ) && ! isset( $mem->getValue()[ $S::run_lock_name( '2026-11-28', 'noon' ) ] ) );

foreach ( [ '2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28' ] as $d ) {
    PressHub_AI_Audio_Synthesizer::clear_turn_cache( $d );
}

echo "SpeechRequestsTest: {$checks} checks\n";
if ( $failures > 0 ) {
    echo "FAIL ({$failures})\n";
    exit( 1 );
}
echo "OK\n";
