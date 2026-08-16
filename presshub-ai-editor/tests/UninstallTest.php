<?php
/**
 * TDD tests for plugin lifecycle hygiene (Batch B) + uninstall batching
 * (Batch C1, Antigravity C-6/P-4):
 *
 *   - presshub-ai-editor.php registers a deactivation hook whose callback
 *     clears both research crons (presshub_ai_cleanup_research and
 *     presshub_ai_do_research);
 *   - uninstall.php refuses to run without WP_UNINSTALL_PLUGIN;
 *   - uninstall.php deletes every presshub_ai_* option, per-user preset
 *     metas for ALL users, and every presshub_research post, and clears
 *     the crons as belt-and-braces;
 *   - user enumeration and research-post queries are batched 200 at a
 *     time (never posts_per_page=-1 / unbounded get_users);
 *   - when $wpdb is available the per-user meta deletes collapse into ONE
 *     bulk DELETE (no per-user delete_user_meta calls at all).
 *
 * The main plugin file is loaded with PRESSHUB_AI_SKIP_UPDATE_CHECKER so
 * the embedded PUC library (which needs a full WP install) is skipped.
 * get_users/get_posts are overridden BEFORE wordpress-stubs.php so the
 * uninstall batch loops can be driven with paging semantics.
 */

// --- Paging-aware stubs for the uninstall batch loops. Defined before
// wordpress-stubs.php so the guarded generic stubs are skipped. ---
if ( ! function_exists( 'get_users' ) ) {
    function get_users( $args = [] ) {
        $GLOBALS['GET_USERS_ARGS'][] = $args;
        $users = $GLOBALS['USERS'] ?? [];
        if ( isset( $args['number'] ) && $args['number'] > 0 ) {
            return array_slice( $users, (int) ( $args['offset'] ?? 0 ), (int) $args['number'] );
        }
        return $users;
    }
}
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        $GLOBALS['GET_POSTS_ARGS'][] = $args;
        $all = $GLOBALS['GET_POSTS_RESULT'] ?? [];
        if ( isset( $args['posts_per_page'] ) && $args['posts_per_page'] > 0 ) {
            return array_slice( $all, (int) ( $args['offset'] ?? 0 ), (int) $args['posts_per_page'] );
        }
        return $all;
    }
}

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
if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $key ) {
        unset( $GLOBALS['OPTIONS_STORE'][ $key ] );
        return true;
    }
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';

// The update checker library cannot load outside a full WP install; the
// plugin honours this guard (also useful for hosts that disable updaters).
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once __DIR__ . '/../presshub-ai-editor.php';

class UninstallTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: deactivation hook registered and clears both crons ---
        // The hook was captured during the file-scope require of the main
        // plugin file (DEACTIVATION_HOOKS). Invoke its callback and assert
        // both research crons are cleared.
        $hooks = $GLOBALS['DEACTIVATION_HOOKS'] ?? [];
        if ( count( $hooks ) !== 1 ) {
            $failures[] = 'Expected exactly one register_deactivation_hook call, got: ' . json_encode( $hooks );
        } else {
            $callback = $hooks[0]['callback'];
            if ( ! is_callable( $callback ) ) {
                $failures[] = 'Deactivation callback should be callable; got: ' . var_export( $callback, true );
            } else {
                $GLOBALS['CLEARED_HOOKS'] = [];
                call_user_func( $callback );
                $cleared = array_column( $GLOBALS['CLEARED_HOOKS'], 'hook' );
                foreach ( [ 'presshub_ai_cleanup_research', 'presshub_ai_do_research' ] as $hook ) {
                    if ( ! in_array( $hook, $cleared, true ) ) {
                        $failures[] = "Deactivation must wp_clear_scheduled_hook( '{$hook}' ); cleared: " . json_encode( $cleared );
                    }
                }
            }
        }

        // --- Case 2: uninstall.php guard refuses to run without WP_UNINSTALL_PLUGIN ---
        $out = shell_exec( 'php -r ' . escapeshellarg( 'require ' . var_export( __DIR__ . '/../uninstall.php', true ) . '; echo "REACHED";' ) );
        if ( false !== strpos( (string) $out, 'REACHED' ) ) {
            $failures[] = 'uninstall.php must exit unless WP_UNINSTALL_PLUGIN is defined.';
        }

        // --- Case 3: uninstall removes options, user metas and research posts ---
        self::reset_stores();
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_provider'                      => 'openai',
            'presshub_ai_model'                         => 'gpt-4o',
            'presshub_ai_model_openai'                  => 'gpt-4o',
            'presshub_ai_model_anthropic'               => 'claude-3',
            'presshub_ai_model_gemini'                  => 'gemini-1.5',
            'presshub_ai_temperature_openai'            => 0.7,
            'presshub_ai_temperature_anthropic'         => 0.7,
            'presshub_ai_temperature_gemini'            => 0.7,
            'presshub_ai_max_tokens_openai'             => 2000,
            'presshub_ai_max_tokens_anthropic'          => 2000,
            'presshub_ai_max_tokens_gemini'             => 2000,
            'presshub_ai_timeout_openai'                => 60,
            'presshub_ai_timeout_anthropic'             => 90,
            'presshub_ai_timeout_gemini'                => 90,
            'presshub_ai_api_key'                       => 'sk-secret',
            'presshub_ai_google_cloud_api_key'          => 'gc-secret',
            'presshub_ai_github_token'                  => 'ghp-secret',
            'presshub_ai_remove_api_key'                => 0,
            'presshub_ai_remove_google_cloud_api_key'   => 0,
            'presshub_ai_remove_github_token'           => 0,
            'presshub_ai_openai_org'                    => 'org-1',
            'presshub_ai_anthropic_version'             => '2023-06-01',
            'presshub_ai_imagen_region'                 => 'us-central1',
            'presshub_ai_gcloud_project_id'             => 'presshub-ai',
            'presshub_ai_rate_limit_enabled'            => 1,
            'presshub_ai_rate_limit_per_hour'           => 30,
            'presshub_ai_rate_limit_window_seconds'     => 3600,
            'presshub_ai_research_retention_days'       => 30,
            'presshub_ai_default_presets'               => [ [ 'slug' => 'wire', 'name' => 'Wire' ] ],
            'presshub_ai_migrated_models'               => 1,
            'unrelated_option'                          => 'must-survive',
        ];
        $GLOBALS['USER_META_STORE'] = [
            1 => [
                'presshub_ai_author_presets'           => [ [ 'slug' => 'a', 'name' => 'A' ] ],
                'presshub_ai_default_preset_id'        => 'a',
                'presshub_ai_disabled_default_presets' => [ 'b' ],
                'nickname'                             => 'author-one',
            ],
            2 => [
                'presshub_ai_author_presets'           => [],
                'presshub_ai_default_preset_id'        => '',
                'presshub_ai_disabled_default_presets' => [],
                'nickname'                             => 'author-two',
            ],
        ];
        $GLOBALS['USERS']           = [ 1, 2 ];
        $GLOBALS['GET_POSTS_RESULT'] = [ 101, 102 ];
        $GLOBALS['CLEARED_HOOKS']    = [];

        defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'presshub-ai-editor/presshub-ai-editor.php' );
        self::run_uninstall();

        foreach ( $GLOBALS['OPTIONS_STORE'] as $key => $_ ) {
            if ( 0 === strpos( $key, 'presshub_ai_' ) ) {
                $failures[] = "Uninstall should delete option {$key}; remaining: " . json_encode( array_keys( $GLOBALS['OPTIONS_STORE'] ) );
            }
        }
        if ( ( $GLOBALS['OPTIONS_STORE']['unrelated_option'] ?? null ) !== 'must-survive' ) {
            $failures[] = 'Uninstall must not delete unrelated options.';
        }
        foreach ( $GLOBALS['USER_META_STORE'] as $uid => $metas ) {
            foreach ( array_keys( $metas ) as $meta_key ) {
                if ( 0 === strpos( $meta_key, 'presshub_ai_' ) ) {
                    $failures[] = "Uninstall should delete user meta {$meta_key} for user {$uid}.";
                }
            }
            if ( ( $GLOBALS['USER_META_STORE'][ $uid ]['nickname'] ?? null ) !== ( 1 === $uid ? 'author-one' : 'author-two' ) ) {
                $failures[] = "Uninstall must not delete non-plugin user meta for user {$uid}.";
            }
        }
        $deleted = array_column( $GLOBALS['DELETED_POSTS'] ?? [], 'id' );
        if ( $deleted !== [ 101, 102 ] ) {
            $failures[] = 'Uninstall should wp_delete_post every research post; got: ' . json_encode( $deleted );
        }
        $cleared = array_column( $GLOBALS['CLEARED_HOOKS'] ?? [], 'hook' );
        foreach ( [ 'presshub_ai_cleanup_research', 'presshub_ai_do_research' ] as $hook ) {
            if ( ! in_array( $hook, $cleared, true ) ) {
                $failures[] = "Uninstall should wp_clear_scheduled_hook( '{$hook}' ); cleared: " . json_encode( $cleared );
            }
        }
        // The per-user path must batch: 200 at a time, never unbounded.
        foreach ( $GLOBALS['GET_USERS_ARGS'] as $args ) {
            if ( ( $args['number'] ?? 0 ) !== 200 ) {
                $failures[] = 'get_users must be called with number=200; got: ' . json_encode( $args );
            }
        }
        foreach ( $GLOBALS['GET_POSTS_ARGS'] as $args ) {
            if ( ( $args['posts_per_page'] ?? 0 ) !== 200 ) {
                $failures[] = 'get_posts must be called with posts_per_page=200 (never -1); got: ' . json_encode( $args );
            }
        }

        // --- Case 4 (C-6/P-4): 450 users + 450 posts -> 200-per-batch loops ---
        self::reset_stores();
        $GLOBALS['USERS']            = range( 1, 450 );
        $GLOBALS['GET_POSTS_RESULT'] = range( 1001, 1450 );
        $GLOBALS['OPTIONS_STORE']    = [ 'presshub_ai_provider' => 'openai', 'unrelated' => 'keep' ];
        $GLOBALS['USER_META_STORE']  = [];
        foreach ( $GLOBALS['USERS'] as $uid ) {
            $GLOBALS['USER_META_STORE'][ $uid ]['presshub_ai_author_presets'] = [ [ 'slug' => 'x' ] ];
        }
        self::run_uninstall();

        if ( count( $GLOBALS['GET_USERS_ARGS'] ) !== 4 ) {
            $failures[] = '450 users should take 3 data batches + 1 terminator probe; got ' . count( $GLOBALS['GET_USERS_ARGS'] ) . ': ' . json_encode( $GLOBALS['GET_USERS_ARGS'] );
        }
        $offsets = array_column( $GLOBALS['GET_USERS_ARGS'], 'offset' );
        if ( $offsets !== [ 0, 200, 400, 450 ] ) {
            $failures[] = 'get_users batch offsets should advance 0/200/400 then probe at 450; got: ' . json_encode( $offsets );
        }
        if ( count( $GLOBALS['GET_POSTS_ARGS'] ) !== 4 ) {
            $failures[] = '450 posts should take 3 data batches + 1 terminator probe; got ' . count( $GLOBALS['GET_POSTS_ARGS'] ) . ': ' . json_encode( $GLOBALS['GET_POSTS_ARGS'] );
        }
        $post_offsets = array_column( $GLOBALS['GET_POSTS_ARGS'], 'offset' );
        if ( $post_offsets !== [ 0, 200, 400, 450 ] ) {
            $failures[] = 'get_posts batch offsets should advance 0/200/400 then probe at 450; got: ' . json_encode( $post_offsets );
        }
        foreach ( $GLOBALS['USERS'] as $uid ) {
            if ( ! empty( $GLOBALS['USER_META_STORE'][ $uid ] ) ) {
                $failures[] = "All plugin user metas must be deleted for user {$uid}; remaining: " . json_encode( $GLOBALS['USER_META_STORE'][ $uid ] );
            }
        }
        $deleted = array_column( $GLOBALS['DELETED_POSTS'] ?? [], 'id' );
        if ( count( $deleted ) !== 450 || $deleted[0] !== 1001 || $deleted[449] !== 1450 ) {
            $failures[] = 'All 450 research posts should be deleted in order; got: ' . json_encode( [ 'count' => count( $deleted ), 'first' => $deleted[0] ?? null, 'last' => $deleted[449] ?? null ] );
        }
        if ( ( $GLOBALS['OPTIONS_STORE']['unrelated'] ?? null ) !== 'keep' ) {
            $failures[] = 'Uninstall must not delete unrelated options (batch case).';
        }

        // --- Case 5 (C-6/P-4): $wpdb available -> ONE bulk DELETE, no user scan ---
        self::reset_stores();
        $GLOBALS['USERS']            = [ 1, 2 ];
        $GLOBALS['GET_POSTS_RESULT'] = [ 201 ];
        $GLOBALS['OPTIONS_STORE']    = [ 'presshub_ai_provider' => 'openai' ];
        $GLOBALS['USER_META_STORE']  = [
            1 => [ 'presshub_ai_author_presets' => [ [ 'slug' => 'a' ] ], 'nickname' => 'author-one' ],
            2 => [ 'presshub_ai_default_preset_id' => 'b', 'nickname' => 'author-two' ],
        ];
        $wpdb = new class {
            public $usermeta = 'wp_usermeta';
            public $queries  = [];
            public function prepare( $query, ...$args ) {
                $flat = [];
                foreach ( $args as $arg ) {
                    if ( is_array( $arg ) ) {
                        foreach ( $arg as $item ) { $flat[] = $item; }
                    } else {
                        $flat[] = $arg;
                    }
                }
                $i = 0;
                return preg_replace_callback( '/%s/', function () use ( $flat, &$i ) {
                    return "'" . addslashes( (string) ( $flat[ $i++ ] ?? '' ) ) . "'";
                }, $query );
            }
            public function query( $sql ) {
                $this->queries[] = $sql;
                return true;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
        self::run_uninstall();

        if ( ! empty( $GLOBALS['GET_USERS_ARGS'] ) ) {
            $failures[] = 'With $wpdb available, uninstall must NOT enumerate users; got: ' . json_encode( $GLOBALS['GET_USERS_ARGS'] );
        }
        if ( count( $wpdb->queries ) !== 1 ) {
            $failures[] = 'Expected exactly one bulk $wpdb query; got: ' . json_encode( $wpdb->queries );
        } else {
            $sql = $wpdb->queries[0];
            foreach ( [ 'DELETE FROM wp_usermeta', 'meta_key IN', 'presshub_ai_author_presets', 'presshub_ai_default_preset_id', 'presshub_ai_disabled_default_presets' ] as $needle ) {
                if ( false === strpos( $sql, $needle ) ) {
                    $failures[] = "Bulk query should contain '{$needle}'; got: {$sql}";
                }
            }
        }
        // The bulk DELETE replaced per-user deletes: the meta store must be
        // untouched by uninstall (the DB query owns that data in prod).
        if ( ( $GLOBALS['USER_META_STORE'][1]['nickname'] ?? null ) !== 'author-one' || ( $GLOBALS['USER_META_STORE'][1]['presshub_ai_author_presets'] ?? null ) === null ) {
            $failures[] = 'Bulk path must not call delete_user_meta per user.';
        }
        $deleted = array_column( $GLOBALS['DELETED_POSTS'] ?? [], 'id' );
        if ( $deleted !== [ 201 ] ) {
            $failures[] = 'Research posts must still be deleted on the bulk path; got: ' . json_encode( $deleted );
        }
        unset( $GLOBALS['wpdb'] );

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    /** Reset every global store the uninstall scenarios touch. */
    private static function reset_stores(): void {
        unset(
            $GLOBALS['OPTIONS_STORE'],
            $GLOBALS['USER_META_STORE'],
            $GLOBALS['GET_POSTS_RESULT'],
            $GLOBALS['DELETED_POSTS'],
            $GLOBALS['CLEARED_HOOKS'],
            $GLOBALS['USERS'],
            $GLOBALS['GET_USERS_ARGS'],
            $GLOBALS['GET_POSTS_ARGS'],
            $GLOBALS['wpdb']
        );
        $GLOBALS['GET_USERS_ARGS']  = [];
        $GLOBALS['GET_POSTS_ARGS']  = [];
        $GLOBALS['DELETED_POSTS']   = [];
        $GLOBALS['CLEARED_HOOKS']   = [];
    }

    /** Run the uninstall script against the current global stores. */
    private static function run_uninstall(): void {
        include __DIR__ . '/../uninstall.php';
    }
}

UninstallTest::run();
