<?php
/**
 * TtsRateLimitTest - Issue #153.
 *
 * Gemini TTS 429 handling and pacing. Uses explicit checks (not assert()),
 * prints "OK" on success and exits 1 on any failure. HTTP is stubbed through
 * CAPTURE_FILTER; sleeping and the clock are injected into
 * PressHub_AI_TTS_Rate_Limiter, so nothing actually sleeps.
 *
 * Covers:
 *   1. parse_retry_delay(): RetryInfo, Retry-After, "retry in Ns" message, none.
 *   2. is_daily_quota(): per-minute vs per-day quota ids, metrics and messages.
 *   3. acquire(): sliding 60 s window, shared through the transient, 0 = off.
 *   4. synthesize_speech_via_gemini(): waits the server's retry delay (not 2 s),
 *      the cap applies, a 429 without a hint uses the backoff, the wait is stored
 *      on the TTS call entry.
 *   5. A per-day quota 429 is not retried and returns tts_daily_quota_exceeded.
 *   6. Pacing: the request over the per-minute budget waits for the window.
 *   7. Scheduled synthesis retry: scheduled once, not for excluded codes, not
 *      when disabled or already queued; the retry skips an existing podcast post,
 *      never reschedules, and logs ERROR when it fails.
 *   8. Settings-First registration of the three new options.
 */

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) { $GLOBALS['DEACTIVATION_HOOKS'][] = $callback; }
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function trl_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$test_upload_dir       = sys_get_temp_dir() . '/presshub-trl-test-' . uniqid();
$GLOBALS['UPLOAD_DIR'] = $test_upload_dir;
$log_file              = sys_get_temp_dir() . '/presshub-trl-log-' . uniqid() . '.log';
PressHub_AI_Logger::set_log_file_path( $log_file );

const TRL_MODEL = 'gemini-3.8-flash-tts';

/** Fake clock + recording sleeper: sleeping advances the clock. */
$GLOBALS['TRL_NOW']    = 1000.0;
$GLOBALS['TRL_SLEEPS'] = [];
PressHub_AI_TTS_Rate_Limiter::set_clock( static function () { return $GLOBALS['TRL_NOW']; } );
PressHub_AI_TTS_Rate_Limiter::set_sleeper( static function ( int $ms ) {
    $GLOBALS['TRL_SLEEPS'][] = $ms;
    $GLOBALS['TRL_NOW']     += $ms / 1000;
} );

function trl_reset( int $rpm = 0 ): void {
    global $wpdb;
    $GLOBALS['OPTIONS_STORE']   = [];
    $GLOBALS['TRANSIENT_STORE'] = [];
    $GLOBALS['TRL_SLEEPS']      = [];
    $GLOBALS['TRL_NOW']         = 1000.0;
    $GLOBALS['TRL_HTTP_PLAN']   = [];
    $GLOBALS['TRL_HTTP_COUNT']  = 0;
    $GLOBALS['SCHEDULED_EVENTS'] = [];
    $GLOBALS['NEXT_SCHEDULED']  = [];
    $GLOBALS['GET_POSTS_RESULT'] = [];
    $wpdb->tables          = [];
    $wpdb->auto_increments = [];
    PressHub_AI_Generation_Recorder::create_table();
    PressHub_AI_TTS_Rate_Limiter::reset( TRL_MODEL );
    $GLOBALS['OPTIONS_STORE']['presshub_ai_gemini_api_key']                     = 'test-gemini-key';
    $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model']                 = TRL_MODEL;
    $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_requests_per_minute']   = $rpm;
    $GLOBALS['CAPTURE_FILTER'] = 'trl_http';
    file_put_contents( $GLOBALS['log_file'], '' );
}

function trl_ok(): array {
    return [
        'response' => [ 'code' => 200 ],
        'body'     => json_encode( [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'inlineData' => [ 'mimeType' => 'audio/pcm;rate=24000', 'data' => base64_encode( str_repeat( "\x12\x34", 1200 ) ) ] ] ] ] ] ] ] ),
    ];
}

/** Gemini 429 body as returned on 2026-11-09 (per-minute quota + RetryInfo). */
function trl_429( ?string $retry_delay = '43.491170966s', string $quota_id = 'GenerateRequestsPerMinutePerProjectPerModel', string $metric = 'generativelanguage.googleapis.com/generate_requests_per_model' ): array {
    $details = [
        [ '@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [ [ 'quotaMetric' => $metric, 'quotaId' => $quota_id, 'quotaValue' => '10' ] ] ],
    ];
    if ( null !== $retry_delay ) {
        $details[] = [ '@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retry_delay ];
    }
    return [
        'response' => [ 'code' => 429 ],
        'body'     => json_encode( [ 'error' => [
            'code'    => 429,
            'message' => 'You exceeded your current quota, please check your plan and billing details.',
            'status'  => 'RESOURCE_EXHAUSTED',
            'details' => $details,
        ] ] ),
    ];
}

function trl_http( $existing, $req ) {
    $url = is_array( $req ) ? (string) ( $req[0] ?? '' ) : '';
    if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
        return null;
    }
    $GLOBALS['TRL_HTTP_COUNT']++;
    $step = array_shift( $GLOBALS['TRL_HTTP_PLAN'] );
    return is_array( $step ) ? $step : trl_ok();
}

/** Synthesize one turn inside a TTS session; returns [ result, entries ]. */
function trl_synth( string $text = 'Δοκιμή ρυθμού αιτημάτων.' ): array {
    $parent = (int) PressHub_AI_Generation_Recorder::record( [ 'kind' => 'podcast_script' ] );
    PressHub_AI_TTS_Call_Recorder::begin_session( $parent );
    try {
        $res = ( new PressHub_AI_API_Client() )->synthesize_speech_via_gemini( $text, 'Kore' );
    } finally {
        PressHub_AI_TTS_Call_Recorder::end_session();
    }
    $entries = PressHub_AI_Generation_Recorder::get( $parent )['tts_calls']['entries'] ?? [];
    return [ $res, $entries ];
}

// =========================================================================
// 1. parse_retry_delay()
// =========================================================================
$body_ri = json_decode( trl_429()['body'], true );
$hint    = PressHub_AI_TTS_Rate_Limiter::parse_retry_delay( $body_ri );
trl_check( 'parse: RetryInfo "43.491170966s" -> 43491 ms', is_array( $hint ) && 43491 === $hint['ms'] && 'retry_info' === $hint['source'] );
trl_check( 'parse: RetryInfo "43s" -> 43000 ms', 43000 === ( PressHub_AI_TTS_Rate_Limiter::parse_retry_delay( json_decode( trl_429( '43s' )['body'], true ) )['ms'] ?? 0 ) );
$body_none = json_decode( trl_429( null )['body'], true );
trl_check( 'parse: no RetryInfo, Retry-After "12" -> 12000 ms', [ 'ms' => 12000, 'source' => 'retry_after' ] === PressHub_AI_TTS_Rate_Limiter::parse_retry_delay( $body_none, '12' ) );
trl_check( 'parse: message "Please retry in 7.5s." -> 7500 ms', [ 'ms' => 7500, 'source' => 'message' ] === PressHub_AI_TTS_Rate_Limiter::parse_retry_delay( [ 'error' => [ 'message' => 'Quota exceeded. Please retry in 7.5s.' ] ] ) );
trl_check( 'parse: no hint at all -> null', null === PressHub_AI_TTS_Rate_Limiter::parse_retry_delay( $body_none ) );
trl_check( 'parse: RetryInfo wins over Retry-After', 'retry_info' === ( PressHub_AI_TTS_Rate_Limiter::parse_retry_delay( $body_ri, '5' )['source'] ?? '' ) );

// =========================================================================
// 2. is_daily_quota()
// =========================================================================
trl_check( 'daily: per-minute quota is not daily', false === PressHub_AI_TTS_Rate_Limiter::is_daily_quota( $body_ri ) );
trl_check( 'daily: PerDay quota id is daily', true === PressHub_AI_TTS_Rate_Limiter::is_daily_quota( json_decode( trl_429( null, 'GenerateRequestsPerDayPerProjectPerModel' )['body'], true ) ) );
trl_check( 'daily: _per_day metric is daily', true === PressHub_AI_TTS_Rate_Limiter::is_daily_quota( json_decode( trl_429( null, 'X', 'generativelanguage.googleapis.com/generate_requests_per_model_per_day' )['body'], true ) ) );
trl_check( 'daily: "per day" in the message is daily', true === PressHub_AI_TTS_Rate_Limiter::is_daily_quota( [ 'error' => [ 'message' => 'Quota exceeded for requests per day.' ] ] ) );

// =========================================================================
// 3. acquire(): sliding window
// =========================================================================
trl_reset( 3 );
$w = [];
for ( $i = 0; $i < 3; $i++ ) {
    $w[] = PressHub_AI_TTS_Rate_Limiter::acquire( TRL_MODEL );
    $GLOBALS['TRL_NOW'] += 1.0;
}
trl_check( 'acquire: the first 3 requests within budget do not wait', [ 0, 0, 0 ] === $w && [] === $GLOBALS['TRL_SLEEPS'] );
$fourth = PressHub_AI_TTS_Rate_Limiter::acquire( TRL_MODEL );
// Oldest request was at t=1000, now is t=1003: wait until t=1060 plus the margin.
trl_check( 'acquire: the 4th request waits for the oldest to leave the window', 57000 + PressHub_AI_TTS_Rate_Limiter::MARGIN_MS === $fourth && [ $fourth ] === $GLOBALS['TRL_SLEEPS'] );
$stored = get_transient( PressHub_AI_TTS_Rate_Limiter::TRANSIENT_PREFIX . md5( TRL_MODEL ) );
trl_check( 'acquire: the window is shared through a transient (3 requests in the last 60 s)', is_array( $stored ) && 3 === count( $stored ) );
// Another process: in-process copy gone, transient still there.
$rp = new ReflectionProperty( 'PressHub_AI_TTS_Rate_Limiter', 'windows' );
$rp->setAccessible( true );
$rp->setValue( null, [] );
$GLOBALS['TRL_SLEEPS'] = [];
PressHub_AI_TTS_Rate_Limiter::acquire( TRL_MODEL );
trl_check( 'acquire: a fresh process reads the shared window and waits', 1 === count( $GLOBALS['TRL_SLEEPS'] ) );
trl_reset( 0 );
for ( $i = 0; $i < 20; $i++ ) {
    PressHub_AI_TTS_Rate_Limiter::acquire( TRL_MODEL );
}
trl_check( 'acquire: 0 requests per minute disables pacing', [] === $GLOBALS['TRL_SLEEPS'] );

// =========================================================================
// 4. 429 retry waits
// =========================================================================
trl_reset( 0 );
$GLOBALS['TRL_HTTP_PLAN'] = [ trl_429() ];
list( $res, $entries ) = trl_synth();
trl_check( '429: succeeds on the retry', is_string( $res ) && '' !== $res && 2 === $GLOBALS['TRL_HTTP_COUNT'] );
$wait = $GLOBALS['TRL_SLEEPS'][0] ?? 0;
trl_check( '429: waits the server delay (43.5 s + jitter), not 2 s', 1 === count( $GLOBALS['TRL_SLEEPS'] ) && $wait >= 43491 + 250 && $wait <= 43491 + 1000 );
trl_check( '429: first entry has no wait, the retry entry stores wait and source', 0 === ( $entries[0]['wait_ms'] ?? -1 ) && 429 === ( $entries[0]['http_status'] ?? 0 ) && $wait === ( $entries[1]['wait_ms'] ?? 0 ) && 'retry_info' === ( $entries[1]['wait_source'] ?? '' ) && 1 === ( $entries[1]['retry_attempt'] ?? 0 ) );
$log = (string) file_get_contents( $log_file );
trl_check( '429: WARNING names the wait and its source', false !== strpos( $log, 'WARNING' ) && false !== strpos( $log, 'retry_info' ) );

trl_reset( 0 );
$GLOBALS['TRL_HTTP_PLAN'] = [ trl_429( '300s' ) ];
trl_synth();
trl_check( '429: the wait is capped by presshub_ai_briefing_tts_max_retry_wait_ms (default 60000)', [ 60000 ] === $GLOBALS['TRL_SLEEPS'] );

trl_reset( 0 );
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_max_retry_wait_ms'] = 5000;
$GLOBALS['TRL_HTTP_PLAN'] = [ trl_429( '43s' ) ];
trl_synth();
trl_check( '429: a lower configured cap applies', [ 5000 ] === $GLOBALS['TRL_SLEEPS'] );

trl_reset( 0 );
$GLOBALS['TRL_HTTP_PLAN'] = [ trl_429( null ), trl_429( null ) ];
list( , $entries ) = trl_synth();
trl_check( '429 without a hint: exponential backoff 2000 then 4000 ms', [ 2000, 4000 ] === $GLOBALS['TRL_SLEEPS'] && 'backoff' === ( $entries[1]['wait_source'] ?? '' ) );

trl_reset( 0 );
$GLOBALS['TRL_HTTP_PLAN'] = [ trl_429(), trl_429(), trl_429() ];
list( $res ) = trl_synth();
trl_check( '429 persisting: gives up after max_retries + 1 attempts with gemini_audio_error', is_wp_error( $res ) && 'gemini_audio_error' === $res->get_error_code() && 3 === $GLOBALS['TRL_HTTP_COUNT'] && 2 === count( $GLOBALS['TRL_SLEEPS'] ) );

// =========================================================================
// 5. Per-day quota: no retry
// =========================================================================
trl_reset( 0 );
$GLOBALS['TRL_HTTP_PLAN'] = [ trl_429( '43s', 'GenerateRequestsPerDayPerProjectPerModel' ) ];
list( $res, $entries ) = trl_synth();
trl_check( 'daily quota: one attempt, no wait', 1 === $GLOBALS['TRL_HTTP_COUNT'] && [] === $GLOBALS['TRL_SLEEPS'] && 1 === count( $entries ) );
trl_check( 'daily quota: returns tts_daily_quota_exceeded', is_wp_error( $res ) && 'tts_daily_quota_exceeded' === $res->get_error_code() );
$log = (string) file_get_contents( $log_file );
trl_check( 'daily quota: ERROR logged', false !== strpos( $log, 'ERROR' ) && false !== strpos( $log, 'Daily quota exceeded' ) );

// =========================================================================
// 6. Pacing inside synthesis
// =========================================================================
trl_reset( 2 );
$e = [];
for ( $i = 0; $i < 3; $i++ ) {
    list( $r, $entries ) = trl_synth( 'Στροφή ' . $i );
    $e[] = $entries[0] ?? [];
    $GLOBALS['TRL_NOW'] += 5.0; // each call takes ~5 s
}
trl_check( 'pacing: the 3rd request in a minute waits (budget 2)', 1 === count( $GLOBALS['TRL_SLEEPS'] ) && 0 === ( $e[0]['wait_ms'] ?? -1 ) && 0 === ( $e[1]['wait_ms'] ?? -1 ) && ( $e[2]['wait_ms'] ?? 0 ) > 40000 );
trl_check( 'pacing: no 429s and every call succeeded', 200 === ( $e[2]['http_status'] ?? 0 ) && 3 === $GLOBALS['TRL_HTTP_COUNT'] );

// The settings test button shares the budget.
trl_reset( 1 );
PressHub_AI_TTS_Rate_Limiter::acquire( TRL_MODEL );
( new PressHub_AI_API_Client() )->test_synthesize_speech( [ 'text' => 'Καλημέρα', 'two_hosts' => false, 'speaker1' => 'Kore' ] );
trl_check( 'pacing: the TTS test button waits on the shared budget', 1 === count( $GLOBALS['TRL_SLEEPS'] ) );

// =========================================================================
// 7. Scheduled synthesis retry
// =========================================================================
trl_check( 'cron retry: hook registered', has_action( 'presshub_ai_retry_podcast_synthesis', 'presshub_ai_execute_synthesis_retry' ) !== false );

trl_reset( 0 );
$before = time();
$ok     = presshub_ai_maybe_schedule_synthesis_retry( '2026-11-09', 'noon', new WP_Error( 'gemini_audio_error', 'quota' ) );
$ev     = $GLOBALS['SCHEDULED_EVENTS'][0] ?? [];
trl_check( 'cron retry: scheduled once, 5 minutes later, with date and edition', true === $ok && 1 === count( $GLOBALS['SCHEDULED_EVENTS'] ) && 'presshub_ai_retry_podcast_synthesis' === ( $ev['hook'] ?? '' ) && [ '2026-11-09', 'noon' ] === ( $ev['args'] ?? [] ) && ( $ev['time'] ?? 0 ) >= $before + 300 && ( $ev['time'] ?? 0 ) <= time() + 300 );
$log = (string) file_get_contents( $log_file );
trl_check( 'cron retry: WARNING logged when scheduling', false !== strpos( $log, 'WARNING' ) && false !== strpos( $log, 'automatic retry scheduled' ) );

trl_reset( 0 );
$GLOBALS['NEXT_SCHEDULED']['presshub_ai_retry_podcast_synthesis'] = time() + 120;
trl_check( 'cron retry: not scheduled twice', false === presshub_ai_maybe_schedule_synthesis_retry( '2026-11-09', 'noon', new WP_Error( 'gemini_audio_error', 'x' ) ) && [] === $GLOBALS['SCHEDULED_EVENTS'] );

foreach ( [ 'tts_daily_quota_exceeded', 'missing_tts_model', 'missing_tts_voice', 'no_gemini_key' ] as $code ) {
    trl_reset( 0 );
    presshub_ai_maybe_schedule_synthesis_retry( '2026-11-09', 'morning', new WP_Error( $code, 'x' ) );
    trl_check( "cron retry: not scheduled for {$code}", [] === $GLOBALS['SCHEDULED_EVENTS'] );
}

trl_reset( 0 );
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_cron_retry_delay_min'] = 0;
trl_check( 'cron retry: delay 0 disables it', false === presshub_ai_maybe_schedule_synthesis_retry( '2026-11-09', 'noon', new WP_Error( 'gemini_audio_error', 'x' ) ) && [] === $GLOBALS['SCHEDULED_EVENTS'] );

trl_reset( 0 );
$GLOBALS['GET_POSTS_RESULT'] = [ 586 ];
$skip = presshub_ai_execute_synthesis_retry( '2026-11-09', 'noon' );
trl_check( 'cron retry: skipped when the podcast post already exists (no TTS call)', null === $skip && 0 === $GLOBALS['TRL_HTTP_COUNT'] );

trl_reset( 0 );
$GLOBALS['GET_POSTS_RESULT'] = [];
$fail = presshub_ai_execute_synthesis_retry( '2099-01-01', 'noon' );
$log  = (string) file_get_contents( $log_file );
trl_check( 'cron retry: a failed retry returns the error, logs ERROR and never reschedules', is_wp_error( $fail ) && false !== strpos( $log, 'giving up' ) && [] === $GLOBALS['SCHEDULED_EVENTS'] );

// =========================================================================
// 8. Settings-First registration
// =========================================================================
$GLOBALS['SANITIZE_CALLBACKS'] = [];
$GLOBALS['FIELDS']             = [];
( new PressHub_AI_Settings() )->register_settings();
foreach ( [
    'presshub_ai_briefing_tts_requests_per_minute'  => [ 9, -5, 0, 9999, 600 ],
    'presshub_ai_briefing_tts_max_retry_wait_ms'    => [ 60000, 10, 1000, 999999, 120000 ],
    'presshub_ai_briefing_tts_cron_retry_delay_min' => [ 5, -1, 0, 500, 120 ],
] as $option => list( $default, $low, $low_clamped, $high, $high_clamped ) ) {
    $cb = $GLOBALS['SANITIZE_CALLBACKS'][ $option ] ?? null;
    trl_check( "settings: {$option} registered with a sanitizer", is_callable( $cb ) );
    trl_check( "settings: {$option} clamps", is_callable( $cb ) && $low_clamped === call_user_func( $cb, $low ) && $high_clamped === call_user_func( $cb, $high ) && $default === call_user_func( $cb, 'abc' ) );
    $getter = 'get_' . substr( $option, strlen( 'presshub_ai_' ) );
    unset( $GLOBALS['OPTIONS_STORE'][ $option ] );
    trl_check( "settings: {$option} getter default is {$default}", $default === PressHub_AI_Settings_Storage::$getter() );
    $render = 'render_' . substr( $option, strlen( 'presshub_ai_' ) ) . '_field';
    trl_check( "settings: {$option} has a render method", method_exists( 'PressHub_AI_Settings_Render', $render ) );
}

PressHub_AI_TTS_Rate_Limiter::set_sleeper( null );
PressHub_AI_TTS_Rate_Limiter::set_clock( null );
@unlink( $log_file );

if ( $failures > 0 ) {
    fwrite( STDERR, "TtsRateLimitTest: {$failures} failure(s) of {$checks} checks\n" );
    exit( 1 );
}
echo "OK ({$checks} checks)\n";
