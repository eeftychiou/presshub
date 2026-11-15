<?php
/**
 * StageEventsPerEditionTest — Issue #154.
 *
 *  - Morning and noon runs on the same date give different stage events.
 *  - Rows written before the edition was recorded count as morning.
 *  - The audio card reads the run-level podcast_audio_run row: the whole
 *    run's duration, and a failed last run shows as an error with its message.
 *  - synthesize_podcast() writes one podcast_audio_run row per call, also on failure.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';
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
require_once __DIR__ . '/../presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function se_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

global $wpdb;
$GLOBALS['CURRENT_TEST_TIME'] = '2026-11-09 05:00:00';
$wpdb->tables['wp_presshub_ai_token_logs']          = [];
$wpdb->auto_increments['wp_presshub_ai_token_logs'] = 0;
$L = 'PressHub_AI_Token_Logger';
$B = 'PressHub_AI_Briefing_Admin';

// Morning run.
$L::log_tts_request( 'briefing_curation', 'gemini', 'm', 100, 38000, 'success', null, [ 'edition' => 'morning', 'started_at' => '2026-11-09 04:40:00' ], 1 );
$L::log_tts_request( 'podcast_script', 'gemini', 'm', 100, 40000, 'success', null, [ 'edition' => 'morning', 'started_at' => '2026-11-09 04:42:00' ], 1 );
$L::log_tts_request( 'podcast_audio_run', 'gemini', 'run', 0, 240000, 'success', null, [ 'edition' => 'morning', 'started_at' => '2026-11-09 04:47:00', 'turns' => 40 ], 1 );
// Per-turn rows must not be what the audio card shows.
$L::log_tts_request( 'podcast_audio', 'gemini', 'Kore', 300, 4000, 'success', null, [], 1 );

// Noon run: curation and script succeed, audio fails after 4 minutes.
$GLOBALS['CURRENT_TEST_TIME'] = '2026-11-09 11:20:00';
$L::log_tts_request( 'briefing_curation', 'gemini', 'm', 100, 38000, 'success', null, [ 'edition' => 'noon', 'started_at' => '2026-11-09 11:15:25' ], 1 );
$L::log_tts_request( 'podcast_script', 'gemini', 'm', 100, 40000, 'success', null, [ 'edition' => 'noon', 'started_at' => '2026-11-09 11:16:06' ], 1 );
$L::log_tts_request( 'podcast_audio_run', 'gemini', 'run', 0, 230000, 'success', null, [ 'edition' => 'noon', 'started_at' => '2026-11-09 10:00:00' ], 1 );
$L::log_tts_request( 'podcast_audio_run', '', 'run', 0, 241000, 'error', 'TTS rate limit reached', [ 'edition' => 'noon', 'started_at' => '2026-11-09 11:16:15' ], 1 );
$L::log_tts_request( 'podcast_audio', 'gemini', 'Kore', 300, 4000, 'success', null, [], 1 );

$m = $B::collect_stage_execution_events( '2026-11-09', 'morning' );
$n = $B::collect_stage_execution_events( '2026-11-09', 'noon' );

se_check( 'morning curation from the morning row', false !== strpos( $m['curation']['attempted_at'], '04:40:00' ) );
se_check( 'noon curation from the noon row', false !== strpos( $n['curation']['attempted_at'], '11:15:25' ) );
se_check( 'script differs per edition', false !== strpos( $m['script']['attempted_at'], '04:42:00' ) && false !== strpos( $n['script']['attempted_at'], '11:16:06' ) );
se_check( 'morning audio is the whole run', 240000 === $m['audio']['duration_ms'] && 'success' === $m['audio']['status'] && false !== strpos( $m['audio']['attempted_at'], '04:47:00' ) );
se_check( 'noon audio shows the failed last run', 'error' === $n['audio']['status'] && 241000 === $n['audio']['duration_ms'] && 'TTS rate limit reached' === $n['audio']['error_message'] );
se_check( 'noon audio start is the failed run start', false !== strpos( $n['audio']['attempted_at'], '11:16:15' ) );
se_check( 'default edition is morning', $B::collect_stage_execution_events( '2026-11-09' ) === $m );

// Legacy rows without an edition count as morning.
se_check( 'legacy row edition', 'morning' === $B::stage_row_edition( [ 'metadata' => null ] ) && 'noon' === $B::stage_row_edition( [ 'metadata' => '{"edition":"noon"}' ] ) );

// Status payload passes the edition through.
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-briefing-admin.php' );
se_check( 'wiring: status uses the edition', false !== strpos( $src, 'self::collect_stage_execution_events( $date, $edition )' ) );
se_check( 'wiring: stage lookup wide enough for both editions', false !== strpos( $src, "'per_page'   => 100," ) );
$syn_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-audio-synthesizer.php' );
se_check( 'wiring: an exception during synthesis is logged with its message', false !== strpos( $syn_src, "\$result = new WP_Error( 'synthesis_exception', \$e->getMessage() );" ) );

// synthesize_podcast() writes a run row even when it fails early (no script).
$wpdb->tables['wp_presshub_ai_token_logs']          = [];
$wpdb->auto_increments['wp_presshub_ai_token_logs'] = 0;
$synth = new PressHub_AI_Audio_Synthesizer();
$res   = $synth->synthesize_podcast( '2026-11-10', '', 'noon' );
$rows  = array_values( array_filter( $wpdb->tables['wp_presshub_ai_token_logs'], static function ( $r ) { return 'podcast_audio_run' === ( $r['action_trigger'] ?? '' ); } ) );
se_check( 'run row: synthesis without a script fails', is_wp_error( $res ) );
se_check( 'run row: one row written', 1 === count( $rows ) );
$meta = json_decode( (string) ( $rows[0]['metadata'] ?? '' ), true );
se_check( 'run row: error with message and code', 'error' === ( $rows[0]['status'] ?? '' ) && '' !== (string) ( $rows[0]['error_message'] ?? '' ) && 'no_script' === ( $meta['error_code'] ?? '' ) );
se_check( 'run row: edition, date and start recorded', 'noon' === ( $meta['edition'] ?? '' ) && '2026-11-10' === ( $meta['date'] ?? '' ) && '' !== ( $meta['started_at'] ?? '' ) );
se_check( 'run row: no characters counted (per-turn rows carry them)', 0 === (int) ( $rows[0]['metric_units'] ?? -1 ) );
se_check( 'edition args: legacy (date, "noon") form', 'noon' === PressHub_AI_Audio_Synthesizer::resolve_run_edition( 'noon', 'morning' ) && 'morning' === PressHub_AI_Audio_Synthesizer::resolve_run_edition( '', null ) );

if ( $failures > 0 ) {
    echo "StageEventsPerEditionTest: {$failures} of {$checks} checks failed\n";
    exit( 1 );
}
echo "StageEventsPerEditionTest: OK ({$checks} checks)\n";
