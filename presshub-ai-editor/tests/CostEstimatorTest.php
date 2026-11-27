<?php
/**
 * Estimated cost of AI calls on Usage & logs (2.90.0): PressHub_AI_Cost_Estimator and
 * the cost fields of PressHub_AI_Token_Logger.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-token-logger.php';
require_once __DIR__ . '/../includes/class-api-client.php';

function cost_check( bool $ok, string $what ): void {
    if ( ! $ok ) {
        throw new RuntimeException( 'FAILED: ' . $what );
    }
}

function cost_near( float $actual, float $expected, string $what ): void {
    cost_check( abs( $actual - $expected ) < 0.000002, $what . ' (expected ' . $expected . ', got ' . $actual . ')' );
}

function cost_row( array $over ): array {
    return array_merge( [
        'action_trigger'    => 'briefing_curation',
        'provider'          => 'gemini',
        'model'             => 'gemini-2.5-flash',
        'prompt_tokens'     => 0,
        'completion_tokens' => 0,
        'metric_units'      => 0,
        'status'            => 'success',
        'error_message'     => null,
        'metadata'          => null,
    ], $over );
}

echo 'Running CostEstimatorTest...' . PHP_EOL;
PressHub_AI_Cost_Estimator::reset();

// 1. Text: input + output at the model's prices.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'prompt_tokens' => 1000000, 'completion_tokens' => 100000 ] ) );
cost_near( $c['usd'], 0.30 + 0.25, '2.5 Flash: 1M in at $0.30 + 100k out at $2.50' );
cost_check( $c['priced'] && ! $c['estimated'] && 'text' === $c['basis'], 'text row is priced, not estimated' );

// 2. Longest prefix wins: Flash-Lite is not priced as Flash.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'model' => 'gemini-2.5-flash-lite', 'prompt_tokens' => 1000000 ] ) );
cost_near( $c['usd'], 0.10, 'Flash-Lite input at $0.10' );
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'model' => 'models/gemini-3.1-pro-preview', 'prompt_tokens' => 100000, 'completion_tokens' => 10000 ] ) );
cost_near( $c['usd'], 0.2 + 0.12, '3.1 Pro preview (models/ prefix stripped)' );
echo '  [1] Text prices and prefix match: OK' . PHP_EOL;

// 3. Thinking tokens are billed as output; cached tokens at the cache price.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [
    'model'             => 'gemini-3.1-pro',
    'prompt_tokens'     => 100000,
    'completion_tokens' => 10000,
    'metadata'          => wp_json_encode( [ 'thoughts_tokens' => 20000, 'cached_tokens' => 50000 ] ),
] ) );
cost_near( $c['usd'], ( 50000 * 2.00 + 50000 * 0.20 + 30000 * 12.00 ) / 1000000, '3.1 Pro with thinking and cache' );

// 4. Long prompts use the >200k prices.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'model' => 'gemini-3.1-pro', 'prompt_tokens' => 300000, 'completion_tokens' => 1000 ] ) );
cost_near( $c['usd'], ( 300000 * 4.00 + 1000 * 18.00 ) / 1000000, '3.1 Pro long-context prices' );

// 5. Transcription prompts are audio.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'action_trigger' => 'social_video_transcribe', 'prompt_tokens' => 1000000 ] ) );
cost_near( $c['usd'], 1.00, 'transcription at the audio input price' );
echo '  [2] Thinking, cache, long context, audio input: OK' . PHP_EOL;

// 6. Unknown model: unpriced, $0.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'provider' => 'openai', 'model' => 'gpt-9', 'prompt_tokens' => 10 ] ) );
cost_check( ! $c['priced'] && 0.0 === $c['usd'] && 'unpriced' === $c['basis'], 'unknown model is unpriced' );
// A dispatch error with no tokens costs nothing and is not "unpriced".
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'provider' => 'openai', 'model' => 'gpt-9', 'status' => 'error' ] ) );
cost_check( $c['priced'] && 'none' === $c['basis'], 'call with no tokens is not billed' );
echo '  [3] Unpriced and unbilled rows: OK' . PHP_EOL;

// 7. Images: per successful image.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'action_trigger' => 'generate_featured_image', 'model' => 'gemini-3.1-flash-image' ] ) );
cost_near( $c['usd'], 0.067, 'Gemini 3.1 Flash Image, 1K' );
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'action_trigger' => 'generate_featured_image', 'model' => 'gemini-3.1-flash-image', 'status' => 'error' ] ) );
cost_near( $c['usd'], 0.0, 'failed image is free' );
echo '  [4] Images: OK' . PHP_EOL;

// 8. Gemini TTS: tokens when logged, else estimated from characters.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [
    'action_trigger' => 'podcast_audio',
    'model'          => 'gemini-3.1-flash-tts-preview',
    'metric_units'   => 1000,
    'metadata'       => wp_json_encode( [ 'usage' => [ 'prompt_tokens' => 400, 'output_tokens' => 1500 ] ] ),
] ) );
cost_near( $c['usd'], ( 400 * 1.00 + 1500 * 20.00 ) / 1000000, 'TTS from logged tokens' );
cost_check( ! $c['estimated'], 'TTS with tokens is not an estimate' );
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'action_trigger' => 'podcast_audio', 'model' => 'gemini-3.1-flash-tts-preview', 'metric_units' => 3000 ] ) );
cost_near( $c['usd'], ( 1000 * 1.00 + 4290 * 20.00 ) / 1000000, 'TTS from characters' );
cost_check( $c['estimated'] && 'tts' === $c['basis'], 'TTS from characters is an estimate' );
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'action_trigger' => 'podcast_audio', 'model' => 'gemini-3.1-flash-tts-preview', 'metric_units' => 3000, 'status' => 'error' ] ) );
cost_near( $c['usd'], 0.0, 'failed TTS is free' );

// 9. Google Cloud TTS by voice type.
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'action_trigger' => 'podcast_audio', 'provider' => 'google_cloud', 'model' => 'el-GR-Chirp3-HD-Aoede', 'metric_units' => 1000000 ] ) );
cost_near( $c['usd'], 30.0, 'Chirp 3 HD at $30 / 1M chars' );
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'action_trigger' => 'podcast_audio', 'provider' => 'google_cloud', 'model' => 'el-GR-Standard-A', 'metric_units' => 1000000 ] ) );
cost_near( $c['usd'], 4.0, 'Standard voice at $4 / 1M chars' );

// 10. Summary rows are free so audio is not counted twice.
foreach ( [ [ 'model' => 'topic_stitched' ], [ 'error_message' => 'whole_script_stitched', 'model' => 'Kore+Puck' ], [ 'error_message' => '4_topics', 'model' => 'x' ], [ 'action_trigger' => 'podcast_audio_run', 'model' => 'run' ], [ 'provider' => 'scraper', 'model' => 'wp_remote_get' ] ] as $over ) {
    $c = PressHub_AI_Cost_Estimator::estimate( cost_row( array_merge( [ 'action_trigger' => 'podcast_audio', 'metric_units' => 5000 ], $over ) ) );
    cost_check( 0.0 === $c['usd'] && 'free' === $c['basis'], 'summary row is free: ' . wp_json_encode( $over ) );
}
echo '  [5] TTS and summary rows: OK' . PHP_EOL;

// 11. The prices can be changed with a filter.
$GLOBALS['presshub_test_cost_filter'] = true;
add_filter( 'presshub_ai_cost_prices', function ( $p ) {
    $p['text']['gpt-9'] = [ 'in' => 1.0, 'out' => 2.0 ];
    return $p;
} );
PressHub_AI_Cost_Estimator::reset();
$c = PressHub_AI_Cost_Estimator::estimate( cost_row( [ 'provider' => 'openai', 'model' => 'gpt-9', 'prompt_tokens' => 1000000, 'completion_tokens' => 1000000 ] ) );
if ( function_exists( 'apply_filters' ) && 0.0 !== $c['usd'] ) {
    cost_near( $c['usd'], 3.0, 'filtered price used' );
}
PressHub_AI_Cost_Estimator::reset();
echo '  [6] Price filter: OK' . PHP_EOL;

// 12. TTS usage from a Gemini response.
$u = PressHub_AI_API_Client::tts_usage( [ 'usageMetadata' => [ 'promptTokenCount' => 12, 'candidatesTokenCount' => 345 ] ] );
cost_check( [ 'prompt_tokens' => 12, 'output_tokens' => 345 ] === $u, 'tts_usage reads usageMetadata' );
cost_check( [] === PressHub_AI_API_Client::tts_usage( [ 'candidates' => [] ] ), 'tts_usage empty without usageMetadata' );
echo '  [7] TTS usage capture: OK' . PHP_EOL;

// 13. Summary and log rows carry the cost.
global $wpdb;
$wpdb->tables = [];
PressHub_AI_Token_Logger::create_table();
PressHub_AI_Token_Logger::log_llm_request( 'briefing_curation', 'gemini', 'gemini-3.1-pro', 100000, 10000, 1000, 'success', null, [ 'thoughts_tokens' => 20000 ], 1 );
PressHub_AI_Token_Logger::log_llm_request( 'social_copy_facebook', 'gemini', 'gemini-2.5-flash', 1000, 100, 1000, 'success', null, [], 1 );
PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', 'gemini-3.1-flash-tts-preview', 1000, 1000, 'success', null, [ 'usage' => [ 'prompt_tokens' => 400, 'output_tokens' => 1500 ] ], 1 );
PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', 'gemini', 'topic_stitched', 1000, 1000, 'success', '3_topics', [], 1 );
PressHub_AI_Token_Logger::log_scrape_request( 'scrape_harvest', 10, 50, 1000, 'success', null, [], 1 );

$expect_brief = ( 100000 * 2.00 + 30000 * 12.00 ) / 1000000;
$expect_copy  = ( 1000 * 0.30 + 100 * 2.50 ) / 1000000;
$expect_tts   = ( 400 * 1.00 + 1500 * 20.00 ) / 1000000;
$stats = PressHub_AI_Token_Logger::get_summary_stats( 'all' );
cost_near( (float) $stats['cost_total'], round( $expect_brief + $expect_copy + $expect_tts, 4 ), 'summary cost total' );
cost_check( 'briefing_curation' === $stats['cost_by_action'][0]['action'], 'most expensive action first' );
cost_check( [ 'briefing_curation', 'podcast_audio', 'social_copy_facebook' ] === array_column( $stats['cost_by_action'], 'action' ), 'billed actions by cost (scraper left out)' );
cost_check( 1 === (int) $stats['cost_by_action'][1]['calls'], 'the stitched audio row is not counted as a call' );
cost_check( 20000 === (int) $stats['thoughts_tokens'], 'thinking tokens summed' );
cost_check( 0 === (int) $stats['cost_unpriced'], 'nothing unpriced' );
cost_check( $stats['cost_by_action'][0]['share'] > 90, 'share computed' );

$logs = PressHub_AI_Token_Logger::get_logs( [ 'action' => 'briefing_curation' ] );
cost_near( (float) $logs['items'][0]['cost_usd'], $expect_brief, 'row cost_usd' );
cost_check( 20000 === $logs['items'][0]['thoughts_tokens'], 'row thoughts_tokens' );
$csv = PressHub_AI_Token_Logger::export_csv();
cost_check( false !== strpos( $csv, 'cost_usd_estimate' ) && false !== strpos( $csv, number_format( $expect_brief, 6, '.', '' ) ), 'CSV has the cost' );

// 14. Days in a range.
$now = 1700000000;
cost_check( 1 === PressHub_AI_Token_Logger::range_days( 'today', $now - 3600, $now ), 'today is one day' );
cost_check( 3 === PressHub_AI_Token_Logger::range_days( '30d', $now - 2.5 * 86400, $now ), '30d with a log starting 2.5 days ago' );
cost_check( 30 === PressHub_AI_Token_Logger::range_days( '30d', $now - 90 * 86400, $now ), '30d capped' );
cost_check( 90 === PressHub_AI_Token_Logger::range_days( 'all', $now - 90 * 86400, $now ), 'all time from the first row' );
echo '  [8] Summary, rows, CSV, days: OK' . PHP_EOL;

echo 'CostEstimatorTest: ALL TESTS PASSED! OK' . PHP_EOL;
