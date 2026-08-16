<?php
/**
 * TDD tests for the research cron job hardening (Batch C1, Antigravity
 * review findings):
 *
 *   - C-2: presshub_ai_execute_research_job() re-entrance guard — a job
 *     whose _research_status is already 'processing' must return without
 *     making a second provider call (WP-Cron can fire the same scheduled
 *     event twice, e.g. overlapping executions).
 *   - C-3: research system-prompt composition order — the
 *     presshub_ai_research_system_prompt filter runs on the BASE prompt
 *     first, the resolved per-author preset is appended after it, and the
 *     final composition passes through the NEW
 *     presshub_ai_composed_research_system_prompt filter.
 *   - D-3: both failure paths (provider WP_Error, wp_update_post failure)
 *     write an error_log entry that includes the research id.
 *
 * The main plugin file is loaded with PRESSHUB_AI_SKIP_UPDATE_CHECKER so
 * the embedded PUC library (which needs a full WP install) is skipped.
 * wp_update_post is overridden BEFORE wordpress-stubs.php so the update-
 * failure path can be simulated (return 0) without the transition
 * re-fire machinery.
 */

// --- Extra stubs needed to load the main plugin file in the harness ---
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'http://example.test/wp-content/plugins/presshub-ai-editor/';
    }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) {
        $GLOBALS['DEACTIVATION_HOOKS'][] = [ 'file' => $file, 'callback' => $callback ];
    }
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
    function wp_clear_scheduled_hook( $hook, $args = [] ) {
        $GLOBALS['CLEARED_HOOKS'][] = [ 'hook' => $hook, 'args' => $args ];
        return true;
    }
}
if ( ! function_exists( 'wp_update_post' ) ) {
    /**
     * Minimal wp_update_post for this suite: records the call and, when
     * WP_UPDATE_POST_FAIL is set, returns 0 so the job's update-failure
     * path is exercised. (The shared stub's transition re-fire machinery
     * is irrelevant here.)
     */
    function wp_update_post( $postarr, $wp_error = false ) {
        $GLOBALS['WP_UPDATE_POST_CALLS'] = ( $GLOBALS['WP_UPDATE_POST_CALLS'] ?? 0 ) + 1;
        if ( ! empty( $GLOBALS['WP_UPDATE_POST_FAIL'] ) ) {
            return 0;
        }
        return $postarr['ID'] ?? 0;
    }
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

// The update checker library cannot load outside a full WP install; the
// plugin honours this guard (also useful for hosts that disable updaters).
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once __DIR__ . '/../presshub-ai-editor.php';

class ResearchJobGuardTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1 (C-2): status 'processing' -> job returns without calling the provider ---
        self::reset();
        $GLOBALS['POSTS_STORE'][501] = [
            'ID'          => 501,
            'post_type'   => 'presshub_research',
            'post_status' => 'pending',
            'post_content'=> '',
        ];
        $GLOBALS['POST_META_STORE'][501]['_research_status'] = 'processing';
        presshub_ai_execute_research_job( 501 );
        if ( ! empty( $GLOBALS['CAPTURED_REQUESTS'] ) ) {
            $failures[] = 'C-2: a processing job must not call the provider; requests=' . json_encode( $GLOBALS['CAPTURED_REQUESTS'] );
        }
        if ( ( $GLOBALS['POST_META_STORE'][501]['_research_status'] ?? null ) !== 'processing' ) {
            $failures[] = 'C-2: processing status must be left untouched by the guard; got: ' . var_export( $GLOBALS['POST_META_STORE'][501]['_research_status'] ?? null, true );
        }

        // --- Case 2 (C-2): status 'pending' -> job proceeds (provider called) ---
        self::reset();
        self::plant_research_job( 502 );
        $GLOBALS['POST_META_STORE'][502]['_research_status'] = 'pending';
        presshub_ai_execute_research_job( 502 );
        if ( empty( $GLOBALS['CAPTURED_REQUESTS'] ) ) {
            $failures[] = 'C-2: a pending job must proceed to the provider call; no requests captured.';
        }
        if ( ( $GLOBALS['POST_META_STORE'][502]['_research_status'] ?? null ) !== 'completed' ) {
            $failures[] = 'C-2: pending job should complete; status=' . var_export( $GLOBALS['POST_META_STORE'][502]['_research_status'] ?? null, true );
        }

        // --- Case 3 (C-3): base filter replacement survives preset append ---
        // The research_system_prompt filter REPLACES the base prompt with
        // CUSTOM_BASE; the author preset must still be appended on top,
        // and the composed filter must see the final value.
        self::reset();
        self::plant_research_job( 503 );
        $GLOBALS['POST_META_STORE'][503]['_research_status'] = 'pending';
        $GLOBALS['POST_META_STORE'][503]['_research_user_id'] = 7;
        $GLOBALS['USER_META_STORE'][7] = [
            'presshub_ai_author_presets' => [
                [ 'slug' => 'wire', 'name' => 'Wire', 'instruction_text' => 'Write in wire style', 'enabled' => true ],
            ],
            'presshub_ai_default_preset_id' => 'wire',
        ];
        $composed_seen = null;
        add_filter( 'presshub_ai_research_system_prompt', function ( $p ) {
            return 'CUSTOM_BASE';
        } );
        add_filter( 'presshub_ai_composed_research_system_prompt', function ( $p ) use ( &$composed_seen ) {
            $composed_seen = $p;
            return $p;
        } );
        presshub_ai_execute_research_job( 503 );
        $body = self::last_request_body();
        if ( false === strpos( $body, 'CUSTOM_BASE' ) ) {
            $failures[] = 'C-3: presshub_ai_research_system_prompt filter result missing from request body. Body: ' . substr( $body, 0, 300 );
        }
        if ( false === strpos( $body, 'Write in wire style' ) ) {
            $failures[] = 'C-3: resolved author preset missing after a replacing base filter. Body: ' . substr( $body, 0, 300 );
        }
        if ( false === strpos( (string) $composed_seen, 'CUSTOM_BASE' ) || false === strpos( (string) $composed_seen, 'Write in wire style' ) ) {
            $failures[] = 'C-3: presshub_ai_composed_research_system_prompt should receive base+preset; got: ' . var_export( $composed_seen, true );
        }

        // --- Case 4 (C-3): without an author preset the composed prompt is base only ---
        self::reset();
        self::plant_research_job( 504 );
        $GLOBALS['POST_META_STORE'][504]['_research_status'] = 'pending';
        $GLOBALS['POST_META_STORE'][504]['_research_user_id'] = 0; // no author context
        add_filter( 'presshub_ai_research_system_prompt', function ( $p ) {
            return 'CUSTOM_BASE';
        } );
        presshub_ai_execute_research_job( 504 );
        $body = self::last_request_body();
        if ( false === strpos( $body, 'CUSTOM_BASE' ) ) {
            $failures[] = 'C-3: base filter result missing without a preset. Body: ' . substr( $body, 0, 300 );
        }
        if ( false !== strpos( $body, 'Write in wire style' ) ) {
            $failures[] = 'C-3: no preset text may appear for user 0. Body: ' . substr( $body, 0, 300 );
        }

        // --- Case 5 (D-3): provider WP_Error path logs the research id ---
        self::reset();
        self::plant_research_job( 505 );
        $GLOBALS['POST_META_STORE'][505]['_research_status'] = 'pending';
        // No CAPTURE_FILTER: the default wp_remote_post stub returns
        // {"predictions":[]}, which call_openai() turns into a
        // WP_Error('api_error', ...).
        unset( $GLOBALS['CAPTURE_FILTER'] );
        $log_file = self::capture_error_log();
        presshub_ai_execute_research_job( 505 );
        $log = (string) file_get_contents( $log_file );
        if ( ( $GLOBALS['POST_META_STORE'][505]['_research_status'] ?? null ) !== 'failed' ) {
            $failures[] = 'D-3: provider failure should mark the job failed; got: ' . var_export( $GLOBALS['POST_META_STORE'][505]['_research_status'] ?? null, true );
        }
        if ( false === strpos( $log, '505' ) || false === strpos( $log, 'provider error' ) ) {
            $failures[] = 'D-3: provider failure must be error_logged with the research id; log: ' . var_export( $log, true );
        }
        self::release_error_log( $log_file );

        // --- Case 6 (D-3): wp_update_post failure path logs the research id ---
        self::reset();
        self::plant_research_job( 506 );
        $GLOBALS['POST_META_STORE'][506]['_research_status'] = 'pending';
        $GLOBALS['WP_UPDATE_POST_FAIL'] = true;
        $log_file = self::capture_error_log();
        presshub_ai_execute_research_job( 506 );
        $log = (string) file_get_contents( $log_file );
        if ( ( $GLOBALS['POST_META_STORE'][506]['_research_status'] ?? null ) !== 'failed' ) {
            $failures[] = 'D-3: update failure should mark the job failed; got: ' . var_export( $GLOBALS['POST_META_STORE'][506]['_research_status'] ?? null, true );
        }
        if ( false === strpos( $log, '506' ) || false === strpos( $log, 'post update' ) || false === strpos( $log, 'Failed to update research post content.' ) ) {
            $failures[] = 'D-3: update failure must be error_logged with the research id; log: ' . var_export( $log, true );
        }
        self::release_error_log( $log_file );

        // --- Case 7 (C-2): unknown/missing post -> early return, no provider call ---
        self::reset();
        $GLOBALS['POST_META_STORE'][999]['_research_status'] = 'pending';
        presshub_ai_execute_research_job( 999 );
        if ( ! empty( $GLOBALS['CAPTURED_REQUESTS'] ) ) {
            $failures[] = 'C-2: a missing research post must not reach the provider; requests=' . json_encode( $GLOBALS['CAPTURED_REQUESTS'] );
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    /**
     * Plant a research post (502-style) plus its associated post and the
     * minimal option/meta context the job needs, with a CAPTURE_FILTER
     * that returns a valid OpenAI completion so the job can complete.
     */
    private static function plant_research_job( int $id ): void {
        $GLOBALS['POSTS_STORE'][ $id ] = [
            'ID'          => $id,
            'post_type'   => 'presshub_research',
            'post_status' => 'pending',
            'post_content'=> 'draft log',
        ];
        $GLOBALS['POSTS_STORE'][ $id + 200 ] = [
            'ID'          => $id + 200,
            'post_type'   => 'post',
            'post_status' => 'draft',
            'post_content'=> 'Draft body context for research.',
        ];
        $GLOBALS['POST_META_STORE'][ $id ] = [
            '_research_prompt'       => 'Research the topic deeply',
            '_associated_post_id'    => $id + 200,
            '_research_user_id'      => 0,
        ];
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices' => [ [ 'message' => [ 'content' => '<h1>Report</h1><p>Findings.</p>' ] ] ],
                ] ),
            ];
        };
    }

    private static function last_request_body(): string {
        $requests = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        $last = end( $requests );
        if ( ! $last || ! isset( $last[1]['body'] ) ) {
            return '';
        }
        $decoded = json_decode( $last[1]['body'], true );
        if ( is_array( $decoded ) && isset( $decoded['messages'][0]['content'] ) ) {
            return $decoded['messages'][0]['content'];
        }
        return is_string( $last[1]['body'] ) ? $last[1]['body'] : json_encode( $last[1]['body'] );
    }

    /** Redirect error_log() to a fresh temp file; returns its path. */
    private static function capture_error_log(): string {
        $file = tempnam( sys_get_temp_dir(), 'presshub-joblog-' );
        ini_set( 'error_log', $file );
        return $file;
    }

    private static function release_error_log( string $file ): void {
        ini_restore( 'error_log' );
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
    }

    private static function reset(): void {
        unset( $GLOBALS['FILTERS'], $GLOBALS['POSTS_STORE'], $GLOBALS['POST_META_STORE'], $GLOBALS['USER_META_STORE'], $GLOBALS['CAPTURED_REQUESTS'], $GLOBALS['CAPTURE_FILTER'], $GLOBALS['WP_UPDATE_POST_FAIL'] );
        $GLOBALS['POSTS_STORE']     = [];
        $GLOBALS['POST_META_STORE'] = [];
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['OPTIONS_STORE']   = [
            'presshub_ai_api_key'  => 'test-key',
            'presshub_ai_provider' => 'openai',
        ];
        $GLOBALS['WP_UPDATE_POST_CALLS'] = 0;
    }
}

ResearchJobGuardTest::run();
