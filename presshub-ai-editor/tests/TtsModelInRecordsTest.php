<?php
/**
 * Generation records show which TTS model (and voices) produced the audio (2.89.2).
 *
 * - PressHub_AI_TTS_Call_Recorder::summarize(): models that spoke (fallback wins),
 *   voices, engines; tried models when no call succeeded.
 * - Records list: a TTS model column from tts.models_used, else tts.model, else the
 *   tts_test record's config.model; no TTS model for text records.
 * - Overview and TTS tab: TTS model, voices and engine from the logged calls.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-generation-recorder.php';
require_once __DIR__ . '/../includes/class-tts-call-recorder.php';
require_once __DIR__ . '/../includes/class-generation-inspector.php';

$GLOBALS['TTSM_FAILURES'] = 0;

function ttsm_check( $condition, string $message ): void {
    if ( ! $condition ) {
        $GLOBALS['TTSM_FAILURES']++;
        echo '  FAIL: ' . $message . PHP_EOL;
    }
}

function ttsm_call( array $over ): array {
    return array_merge( [
        'phase' => 'request', 'engine' => 'gemini', 'model' => 'gemini-2.5-pro-preview-tts', 'fallback_model' => '',
        'voice' => 'Kore', 'speakers' => null, 'error' => '',
    ], $over );
}

echo 'Running TtsModelInRecordsTest...' . PHP_EOL;

// 1. summarize().
$env = [ 'entries' => [
    ttsm_call( [ 'error' => 'HTTP 429' ] ),
    ttsm_call( [ 'fallback_model' => 'gemini-2.5-flash-preview-tts', 'speakers' => [ [ 'speaker' => 'A', 'voice' => 'Puck' ], [ 'speaker' => 'B', 'voice' => 'Kore' ] ], 'voice' => '' ] ),
    ttsm_call( [ 'fallback_model' => 'gemini-2.5-flash-preview-tts' ] ),
    ttsm_call( [] ),
    ttsm_call( [ 'phase' => 'cache_hit', 'model' => 'cached-model' ] ),
    [ 'phase' => 'stitched', 'summary' => [ 'bytes' => 1 ] ],
] ];
$sum = PressHub_AI_TTS_Call_Recorder::summarize( $env );
ttsm_check( [ 'gemini-2.5-flash-preview-tts' => 2, 'gemini-2.5-pro-preview-tts' => 1 ] === $sum['models'], 'summarize counts successful calls per spoken model (fallback wins, cache hits and summaries ignored)' );
ttsm_check( [ 'Puck', 'Kore' ] === $sum['voices'], 'summarize lists the voices once each' );
ttsm_check( [ 'gemini' ] === $sum['engines'] && false === $sum['failed'], 'summarize lists engines; not failed' );
ttsm_check( 'gemini-2.5-flash-preview-tts (2), gemini-2.5-pro-preview-tts (1)' === PressHub_AI_TTS_Call_Recorder::models_label( $sum ), 'models_label with counts for several models' );
$failed = PressHub_AI_TTS_Call_Recorder::summarize( [ 'entries' => [ ttsm_call( [ 'error' => 'quota' ] ) ] ] );
ttsm_check( [ 'gemini-2.5-pro-preview-tts' => 1 ] === $failed['models'] && true === $failed['failed'], 'only failed calls: tried models, failed=true' );
ttsm_check( 'gemini-2.5-pro-preview-tts' === PressHub_AI_TTS_Call_Recorder::models_label( $failed ), 'models_label without count for one model' );
ttsm_check( [] === PressHub_AI_TTS_Call_Recorder::summarize( null )['models'], 'summarize(null) is empty' );

// 2. Records.
global $wpdb;
$wpdb->tables          = [];
$wpdb->queries         = [];
$wpdb->auto_increments = [];
PressHub_AI_Generation_Recorder::create_table();

$podcast = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'podcast_script', 'briefing_date' => '2026-11-27', 'config' => [ 'model' => 'gemini-3.1-pro' ], 'response_text' => 'x' ] );
PressHub_AI_Generation_Recorder::update_record( $podcast, [ 'tts_calls' => $env ] );
ttsm_check( 2 === PressHub_AI_TTS_Call_Recorder::summarize_record( $podcast )['models']['gemini-2.5-flash-preview-tts'], 'summarize_record reads the stored calls' );
PressHub_AI_Generation_Recorder::update_record( $podcast, [ 'tts' => [ 'engine' => 'gemini', 'model' => 'gemini-2.5-pro-preview-tts', 'models_used' => PressHub_AI_TTS_Call_Recorder::summarize_record( $podcast )['models'], 'voices' => [ 'presenter_1' => 'Kore' ] ] ] );

$narration = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'tiktok_narration', 'config' => [ 'module' => 'tts' ] ] );
PressHub_AI_Generation_Recorder::update_record( $narration, [ 'tts' => [ 'engine' => 'gemini', 'model' => 'old-configured-tts' ] ] );
$test = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'tts_test', 'config' => [ 'model' => 'el-GR-Chirp3-HD-Aoede' ] ] );
$brief = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'briefing_text', 'config' => [ 'model' => 'gemini-3.1-flash' ] ] );

$by_id = [];
foreach ( PressHub_AI_Generation_Inspector::query_records( [] ) as $row ) {
    $by_id[ (int) $row['id'] ] = $row;
}
ttsm_check( 'gemini-2.5-flash-preview-tts (2), gemini-2.5-pro-preview-tts (1)' === $by_id[ $podcast ]['tts_model'], 'list: podcast TTS model from models_used' );
ttsm_check( 'gemini-3.1-pro' === $by_id[ $podcast ]['model'], 'list: podcast Model stays the text model' );
ttsm_check( 'old-configured-tts' === $by_id[ $narration ]['tts_model'], 'list: falls back to tts.model' );
ttsm_check( 'el-GR-Chirp3-HD-Aoede' === $by_id[ $test ]['tts_model'] && '' === $by_id[ $test ]['model'], 'list: tts_test shows its model as TTS model only' );
ttsm_check( '' === $by_id[ $brief ]['tts_model'] && 'gemini-3.1-flash' === $by_id[ $brief ]['model'], 'list: text records have no TTS model' );

$page = PressHub_AI_Generation_Inspector::build_records_page( [] );
ttsm_check( false !== strpos( $page, '<th>TTS model</th>' ), 'records page has a TTS model column' );
ttsm_check( false !== strpos( $page, 'gemini-2.5-flash-preview-tts (2)' ), 'records page shows the podcast TTS model' );

// 3. Overview and TTS tab.
$record   = PressHub_AI_Generation_Inspector::get_structured_record( $podcast );
$overview = PressHub_AI_Generation_Inspector::render_tab( $record, 'overview' );
ttsm_check( false !== strpos( $overview, 'TTS model' ) && false !== strpos( $overview, 'gemini-2.5-flash-preview-tts (2)' ), 'overview shows the TTS model' );
ttsm_check( false !== strpos( $overview, 'TTS voices' ) && false !== strpos( $overview, 'Puck, Kore' ), 'overview shows the voices' );
ttsm_check( false === strpos( $overview, 'Configured TTS model' ), 'no Configured row when the configured model spoke' );
$tab = PressHub_AI_Generation_Inspector::render_tab( $record, 'tts' );
ttsm_check( false !== strpos( $tab, 'TTS model' ) && false !== strpos( $tab, 'Configured model' ), 'TTS tab shows the spoken and configured model' );

$brief_overview = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( $brief ), 'overview' );
ttsm_check( false === strpos( $brief_overview, 'TTS model' ), 'text record overview has no TTS rows' );

// Only failed calls, configured model differs.
$short = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'tiktok_short', 'status' => 'error' ] );
PressHub_AI_Generation_Recorder::update_record( $short, [ 'tts_calls' => [ 'entries' => [ ttsm_call( [ 'model' => 'x-tts', 'error' => 'quota' ] ) ] ] ] );
$short_tab = PressHub_AI_Generation_Inspector::render_tab( PressHub_AI_Generation_Inspector::get_structured_record( $short ), 'tts' );
ttsm_check( false !== strpos( $short_tab, 'x-tts (no call succeeded)' ), 'TTS tab marks a model whose calls all failed' );

if ( $GLOBALS['TTSM_FAILURES'] > 0 ) {
    echo 'TtsModelInRecordsTest: ' . $GLOBALS['TTSM_FAILURES'] . ' FAILURE(S)' . PHP_EOL;
    exit( 1 );
}
echo 'TtsModelInRecordsTest: ALL TESTS PASSED! OK' . PHP_EOL;
