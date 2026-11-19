<?php
/**
 * BriefingStepSweepTest - lost briefing step sweep (2.65.0).
 *
 * On 19/11/2026 the noon script step was queued on presshub_ai_run_briefing_step
 * and its event vanished from the cron list before it ran (a request holding an
 * older copy of the cron list wrote it back). Queued steps are now remembered
 * until they start, and a sweep queues again a step whose event is gone.
 *
 * Covers:
 *   1. Queuing a step records it (due time, 0 attempts); starting it clears it.
 *   2. The sweep leaves alone a step that is not yet overdue, or whose event
 *      is still listed.
 *   3. A lost step is queued again with a WARNING and the attempt counted;
 *      after the maximum it is dropped with an ERROR.
 *   4. A step that cannot be queued (split steps off) is dropped with an ERROR.
 *   5. Entries over two days old are pruned; a broken entry is dropped.
 *   6. The throttled entry point runs in cron only once per lock window and
 *      not in a front-end request.
 *   7. Wiring: hooks, cron-cache refresh before scheduling, uninstall.
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

// Test doubles that the shared stubs lack or simplify: the event list is
// matched by hook and arguments, scheduling can fail or "start" the event at
// once, and cache deletes are counted.
$GLOBALS['BSS_EVENTS']       = [];
$GLOBALS['BSS_CACHE_DELETES'] = [];
$GLOBALS['BSS_FAIL_SCHEDULE'] = false;
$GLOBALS['BSS_ON_SCHEDULE']   = null;
function wp_schedule_single_event( $timestamp, $hook, $args = [] ) {
    if ( ! empty( $GLOBALS['BSS_FAIL_SCHEDULE'] ) ) {
        return false;
    }
    $GLOBALS['SCHEDULED_EVENTS'][] = [ 'time' => $timestamp, 'hook' => $hook, 'args' => $args ];
    if ( is_callable( $GLOBALS['BSS_ON_SCHEDULE'] ) ) {
        ( $GLOBALS['BSS_ON_SCHEDULE'] )( $hook, $args );
    }
    return true;
}
function wp_next_scheduled( $hook, $args = [] ) {
    $k = $hook . '|' . json_encode( array_values( (array) $args ) );
    return $GLOBALS['BSS_EVENTS'][ $k ] ?? false;
}
function wp_cache_delete( $key, $group = '' ) {
    $GLOBALS['BSS_CACHE_DELETES'][] = $group . ':' . $key;
    return true;
}
function bss_listed( string $date, string $edition, string $step, int $part, int $when ): void {
    $GLOBALS['BSS_EVENTS'][ 'presshub_ai_run_briefing_step|' . json_encode( [ $date, $edition, $step, $part ] ) ] = $when;
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
$checks   = 0;
function bss_check( $label, $condition ) {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$log_file = sys_get_temp_dir() . '/presshub-bss-log-' . uniqid() . '.log';
PressHub_AI_Logger::set_log_file_path( $log_file );

function bss_reset(): void {
    $GLOBALS['OPTIONS_STORE']      = [];
    $GLOBALS['TRANSIENT_STORE']    = [];
    $GLOBALS['SCHEDULED_EVENTS']   = [];
    $GLOBALS['NEXT_SCHEDULED']     = [];
    $GLOBALS['UNSCHEDULED_EVENTS'] = [];
    $GLOBALS['WP_DOING_CRON']      = false;
    $GLOBALS['BSS_EVENTS']         = [];
    $GLOBALS['BSS_CACHE_DELETES']  = [];
    $GLOBALS['BSS_FAIL_SCHEDULE']  = false;
    $GLOBALS['BSS_ON_SCHEDULE']    = null;
    $GLOBALS['GET_POSTS_RESULT']   = [];
    $GLOBALS['presshub_ai_pending_steps_lock_depth'] = 0;
    file_put_contents( $GLOBALS['log_file'], '' );
}

function bss_pending(): array {
    $v = $GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_OPTION ] ?? [];
    return is_array( $v ) ? $v : [];
}

function bss_steps_queued(): array {
    return array_values( array_filter( $GLOBALS['SCHEDULED_EVENTS'], static fn( $e ) => 'presshub_ai_run_briefing_step' === $e['hook'] ) );
}

function bss_log(): string {
    return (string) file_get_contents( $GLOBALS['log_file'] );
}

// =========================================================================
// 1. Record on queue, clear on start
// =========================================================================
bss_reset();
$before = time();
$ok     = presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' );
$key    = '2026-11-19|noon|script|0';
$p      = bss_pending();
bss_check( 'record: queuing a step records it', true === $ok && isset( $p[ $key ] ) );
bss_check( 'record: entry has date, edition, step, part, due and 0 attempts',
    '2026-11-19' === ( $p[ $key ]['date'] ?? '' ) && 'noon' === ( $p[ $key ]['edition'] ?? '' ) && 'script' === ( $p[ $key ]['step'] ?? '' )
    && 0 === ( $p[ $key ]['part'] ?? -1 ) && ( $p[ $key ]['due'] ?? 0 ) >= $before && 0 === ( $p[ $key ]['attempts'] ?? -1 ) );

presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'audio', 2, 30 );
$p = bss_pending();
bss_check( 'record: a continuation is its own entry with the delay in its due time', isset( $p['2026-11-19|noon|audio|2'] ) && $p['2026-11-19|noon|audio|2']['due'] >= $before + 30 );

presshub_ai_clear_pending_briefing_step( '2026-11-19', 'noon', 'script', 0 );
$p = bss_pending();
bss_check( 'clear: clearing one step keeps the others', ! isset( $p[ $key ] ) && isset( $p['2026-11-19|noon|audio|2'] ) );
presshub_ai_clear_pending_briefing_step( '2026-11-19', 'noon', 'audio', 2 );
bss_check( 'clear: the option is deleted when nothing is pending', ! array_key_exists( PRESSHUB_AI_PENDING_STEPS_OPTION, $GLOBALS['OPTIONS_STORE'] ) );

// Starting the step through the cron handler clears it before the step runs.
// The 2099 date has no snapshot, so the script step returns an error quickly.
bss_reset();
presshub_ai_schedule_briefing_step( '2099-01-01', 'noon', 'script' );
bss_check( 'start: precondition, the step is pending', isset( bss_pending()['2099-01-01|noon|script|0'] ) );
presshub_ai_execute_briefing_step( '2099-01-01', 'noon', 'script', 0 );
bss_check( 'start: the cron handler clears the pending step', ! isset( bss_pending()['2099-01-01|noon|script|0'] ) );

// =========================================================================
// 2. Not overdue, or still listed: left alone
// =========================================================================
bss_reset();
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' );
$GLOBALS['SCHEDULED_EVENTS'] = [];
$due = bss_pending()[ $key ]['due'];
bss_check( 'sweep: default lost-after is 300 s and 2 re-queues', 300 === presshub_ai_briefing_step_lost_after_sec() && 2 === presshub_ai_briefing_step_max_requeues() );
bss_check( 'sweep: a step within its grace time is left alone', 0 === presshub_ai_sweep_lost_briefing_steps( $due + 299 ) && [] === bss_steps_queued() );

bss_listed( '2026-11-19', 'noon', 'script', 1, $due );
bss_listed( '2026-11-19', 'morning', 'script', 0, $due );
bss_check( 'sweep: an event for another part or edition does not count as listed', 1 === presshub_ai_sweep_lost_briefing_steps( $due + 3600 ) );
bss_reset();
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' );
$GLOBALS['SCHEDULED_EVENTS'] = [];
$due = bss_pending()[ $key ]['due'];
bss_listed( '2026-11-19', 'noon', 'script', 0, $due );
bss_check( 'sweep: an overdue step whose event is still listed is left to WordPress', 0 === presshub_ai_sweep_lost_briefing_steps( $due + 3600 ) && [] === bss_steps_queued() && isset( bss_pending()[ $key ] ) );
$GLOBALS['BSS_EVENTS'] = [];

// =========================================================================
// 3. Lost: queued again, then given up
// =========================================================================
$n   = presshub_ai_sweep_lost_briefing_steps( $due + 301 );
$ev  = bss_steps_queued();
$p   = bss_pending();
bss_check( 'lost: the step is queued again with the same arguments', 1 === $n && 1 === count( $ev ) && [ '2026-11-19', 'noon', 'script', 0 ] === $ev[0]['args'] );
bss_check( 'lost: the attempt is counted and the due time renewed', 1 === ( $p[ $key ]['attempts'] ?? 0 ) && ( $p[ $key ]['due'] ?? 0 ) >= $due );
bss_check( 'lost: a WARNING names the step and the attempt', false !== strpos( bss_log(), '[WARNING]' ) && false !== strpos( bss_log(), 'Briefing step "script" was queued but its cron event disappeared before it ran; queued again (attempt 1 of 2)' ) );

$GLOBALS['SCHEDULED_EVENTS'] = [];
$due2 = bss_pending()[ $key ]['due'];
presshub_ai_sweep_lost_briefing_steps( $due2 + 301 );
bss_check( 'lost: the second loss is queued again (attempt 2)', 1 === count( bss_steps_queued() ) && 2 === ( bss_pending()[ $key ]['attempts'] ?? 0 ) );

$GLOBALS['SCHEDULED_EVENTS'] = [];
file_put_contents( $log_file, '' );
$due3 = bss_pending()[ $key ]['due'];
$n    = presshub_ai_sweep_lost_briefing_steps( $due3 + 301 );
bss_check( 'give up: after the maximum nothing is queued and the entry is dropped', 0 === $n && [] === bss_steps_queued() && ! isset( bss_pending()[ $key ] ) );
bss_check( 'give up: an ERROR tells to run it from the Hub', false !== strpos( bss_log(), '[ERROR]' ) && false !== strpos( bss_log(), 'run it from the Daily Briefing Hub' ) );

// Max re-queues 0: the first loss is already given up.
bss_reset();
add_filter( 'presshub_ai_briefing_step_max_requeues', static function () { return 0; } );
presshub_ai_schedule_briefing_step( '2026-11-19', 'morning', 'audio' );
$GLOBALS['SCHEDULED_EVENTS'] = [];
$n = presshub_ai_sweep_lost_briefing_steps( time() + 3600 );
remove_all_filters( 'presshub_ai_briefing_step_max_requeues' );
bss_check( 'give up: with 0 re-queues a lost step is only logged', 0 === $n && [] === bss_steps_queued() && [] === bss_pending() && false !== strpos( bss_log(), '[ERROR]' ) );

// =========================================================================
// 4. Cannot queue again
// =========================================================================
bss_reset();
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' );
$GLOBALS['SCHEDULED_EVENTS'] = [];
add_filter( 'presshub_ai_briefing_split_steps', '__return_false' );
$n = presshub_ai_sweep_lost_briefing_steps( time() + 3600 );
remove_all_filters( 'presshub_ai_briefing_split_steps' );
bss_check( 'no queue: a step that cannot be queued is dropped with an ERROR', 0 === $n && [] === bss_pending() && false !== strpos( bss_log(), 'could not be queued again' ) );

// =========================================================================
// 5. Pruning and broken entries
// =========================================================================
bss_reset();
$GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_OPTION ] = [
    'old|morning|script|0' => [ 'date' => '2026-11-10', 'edition' => 'morning', 'step' => 'script', 'part' => 0, 'due' => time() - 3 * DAY_IN_SECONDS, 'attempts' => 0 ],
    'broken'               => 'not an array',
];
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' );
$p = bss_pending();
bss_check( 'prune: entries over two days old and broken entries are dropped on record', [ $key ] === array_keys( $p ) );

bss_reset();
$GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_OPTION ] = [
    '|noon||0' => [ 'date' => '', 'edition' => 'noon', 'step' => '', 'part' => 0, 'due' => time() - 3600, 'attempts' => 0 ],
];
$n = presshub_ai_sweep_lost_briefing_steps();
bss_check( 'prune: an entry without date or step is dropped by the sweep, nothing queued', 0 === $n && [] === bss_pending() && [] === bss_steps_queued() );

bss_reset();
bss_check( 'sweep: nothing pending returns 0 without touching anything', 0 === presshub_ai_sweep_lost_briefing_steps() && [] === $GLOBALS['SCHEDULED_EVENTS'] );

// =========================================================================
// 6. Throttled entry point
// =========================================================================
bss_reset();
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' );
$GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_OPTION ][ $key ]['due'] = time() - 3600;
$GLOBALS['SCHEDULED_EVENTS'] = [];
bss_check( 'entry: not run in a front-end request', 0 === presshub_ai_maybe_sweep_lost_briefing_steps() && [] === bss_steps_queued() );
$GLOBALS['WP_DOING_CRON'] = true;
bss_check( 'entry: runs in a cron request', 1 === presshub_ai_maybe_sweep_lost_briefing_steps() && 1 === count( bss_steps_queued() ) );
$GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_OPTION ][ $key ]['due'] = time() - 3600;
$GLOBALS['SCHEDULED_EVENTS'] = [];
bss_check( 'entry: a second call inside the lock window does nothing', 0 === presshub_ai_maybe_sweep_lost_briefing_steps() && [] === bss_steps_queued() );

// =========================================================================
// 6b. Review round 1: order, restore, races, Hub runs, cache refresh
// =========================================================================

// The event starts the moment it is saved: the step must not stay recorded.
bss_reset();
$GLOBALS['BSS_ON_SCHEDULE'] = static function ( $hook, $args ) {
    $GLOBALS['BSS_PENDING_AT_SAVE'] = isset( bss_pending()[ implode( '|', $args ) ] );
    presshub_ai_clear_pending_briefing_step( $args[0], $args[1], $args[2], $args[3] );
};
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'audio' );
bss_check( 'order: the step is recorded before its event is saved', true === ( $GLOBALS['BSS_PENDING_AT_SAVE'] ?? null ) );
bss_check( 'order: a step that starts at once leaves no record behind', [] === bss_pending() );

// A failed schedule removes a new record, and puts back an earlier one.
bss_reset();
$GLOBALS['BSS_FAIL_SCHEDULE'] = true;
bss_check( 'restore: a failed schedule returns false and leaves no record', false === presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' ) && [] === bss_pending() );
$GLOBALS['BSS_FAIL_SCHEDULE'] = false;
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'translate:en', 0, 0 );
$tk = '2026-11-19|noon|translate:en|0';
$GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_OPTION ][ $tk ]['attempts'] = 1;
$kept = bss_pending()[ $tk ];
$GLOBALS['BSS_FAIL_SCHEDULE'] = true;
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'translate:en', 0, 600 );
bss_check( 'restore: a refused duplicate keeps the waiting step\'s record unchanged', $kept === ( bss_pending()[ $tk ] ?? null ) );

// The scheduled audio step never makes a second podcast post.
bss_reset();
$GLOBALS['GET_POSTS_RESULT'] = [ 722 ];
$r = presshub_ai_execute_briefing_step( '2026-11-19', 'noon', 'audio', 0 );
bss_check( 'audio: part 0 skips when the podcast post exists, nothing queued', null === $r && [] === $GLOBALS['SCHEDULED_EVENTS'] && false !== strpos( bss_log(), 'Scheduled podcast audio step skipped' ) );

// A sweep does nothing while another request holds the lock.
bss_reset();
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' );
$GLOBALS['SCHEDULED_EVENTS'] = [];
$GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_LOCK ] = time() . ':other';
$t0 = microtime( true );
$n  = presshub_ai_sweep_lost_briefing_steps( time() + 3600 );
bss_check( 'lock: a held lock stops the sweep (no re-queue, entry kept)', 0 === $n && [] === bss_steps_queued() && isset( bss_pending()[ $key ] ) );
bss_check( 'lock: the wait is bounded (about 3 s)', microtime( true ) - $t0 < 6 );
$GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_LOCK ] = ( time() - 31 ) . ':other';
bss_check( 'lock: a stale lock is taken over', 1 === presshub_ai_sweep_lost_briefing_steps( time() + 3600 ) );
bss_check( 'lock: released after the sweep', ! array_key_exists( PRESSHUB_AI_PENDING_STEPS_LOCK, $GLOBALS['OPTIONS_STORE'] ) && 0 === (int) $GLOBALS['presshub_ai_pending_steps_lock_depth'] );

// Unlock leaves a lock another request took over alone.
bss_reset();
$mine = presshub_ai_pending_steps_lock();
$GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_LOCK ] = time() . ':someone-else';
presshub_ai_pending_steps_unlock( $mine );
bss_check( 'lock: unlock does not delete a lock taken over by another request', true === $mine && ( time() . ':someone-else' ) === ( $GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_LOCK ] ?? '' ) );
$mine = true;
$GLOBALS['OPTIONS_STORE'] = [];
$mine = presshub_ai_pending_steps_lock();
$own  = $GLOBALS['OPTIONS_STORE'][ PRESSHUB_AI_PENDING_STEPS_LOCK ] ?? '';
presshub_ai_pending_steps_unlock( $mine );
bss_check( 'lock: the lock value is "<time>:<token>" and unlock removes its own', 1 === preg_match( '/^\d+:\S+$/', (string) $own ) && ! array_key_exists( PRESSHUB_AI_PENDING_STEPS_LOCK, $GLOBALS['OPTIONS_STORE'] ) );

// The cron cache is refreshed only when something is overdue.
bss_reset();
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'script' );
$GLOBALS['BSS_CACHE_DELETES'] = [];
presshub_ai_sweep_lost_briefing_steps( time() );
bss_check( 'cache: no alloptions refresh while nothing is overdue', ! in_array( 'options:alloptions', $GLOBALS['BSS_CACHE_DELETES'], true ) );
$GLOBALS['BSS_CACHE_DELETES'] = [];
presshub_ai_schedule_briefing_step( '2026-11-19', 'noon', 'audio' );
$sched = array_search( 'options:alloptions', $GLOBALS['BSS_CACHE_DELETES'], true );
bss_check( 'cache: scheduling a step refreshes alloptions and cron', false !== $sched && in_array( 'options:cron', $GLOBALS['BSS_CACHE_DELETES'], true ) );

// =========================================================================
// 7. Wiring
// =========================================================================
bss_check( 'wiring: the sweep runs on init and admin_init', false !== has_action( 'init', 'presshub_ai_maybe_sweep_lost_briefing_steps' ) && false !== has_action( 'admin_init', 'presshub_ai_maybe_sweep_lost_briefing_steps' ) );
$src   = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/presshub-ai-editor.php' ) );
$fn    = substr( $src, (int) strpos( $src, 'function presshub_ai_schedule_briefing_step(' ) );
$fresh = strpos( $fn, 'presshub_ai_refresh_cron_cache();' );
$sched = strpos( $fn, "wp_schedule_single_event( \$due, 'presshub_ai_run_briefing_step', \$args );" );
bss_check( 'wiring: the cron list is re-read before the step is scheduled', false !== $fresh && false !== $sched && $fresh < $sched );
$exec  = substr( $src, (int) strpos( $src, 'function presshub_ai_execute_briefing_step(' ) );
$clear = strpos( $exec, 'presshub_ai_clear_pending_briefing_step(' );
$audio = strpos( $exec, "if ( 'audio' === \$step )" );
bss_check( 'wiring: the cron handler clears the step before running it', false !== $clear && false !== $audio && $clear < $audio );
$rec = strpos( $fn, 'presshub_ai_record_pending_briefing_step(' );
bss_check( 'wiring: the step is recorded before wp_schedule_single_event', false !== $rec && $rec < $sched );
$ajax = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ajax-handlers.php' );
bss_check( 'wiring: Hub script and audio runs clear the queued step', false !== strpos( $ajax, "presshub_ai_clear_pending_briefing_step( \$date, \$edition, 'script', 0 );" ) && false !== strpos( $ajax, "presshub_ai_clear_pending_briefing_step( \$date, \$edition, 'audio', 0 );" ) );
bss_check( 'wiring: the Hub audio run updates an existing podcast post instead of creating a second one', false !== strpos( $ajax, '$existing_id = function_exists( \'presshub_ai_find_podcast_post_id\' ) ? (int) presshub_ai_find_podcast_post_id( $date, $edition ) : 0;' ) && false !== strpos( $ajax, 'synthesize_podcast( $date, $script, $edition, null, $existing_id )' ) );
$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
bss_check( 'wiring: uninstall removes the lock option', false !== strpos( $uninstall, "'presshub_ai_briefing_pending_steps_lock'" ) );
bss_check( 'wiring: uninstall removes the pending-steps option', false !== strpos( $uninstall, "'presshub_ai_briefing_pending_steps'" ) );

@unlink( $log_file );

if ( $failures > 0 ) {
    fwrite( STDERR, "{$failures} of {$checks} checks failed\n" );
    exit( 1 );
}
echo "OK ({$checks} checks)\n";
