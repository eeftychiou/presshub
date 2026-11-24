<?php
/**
 * Settings clean-up (#237, settings overhaul phase 5).
 *
 * Removes the stored rows of settings that PressHub no longer reads, once per
 * clean-up version, on the first admin page load after the upgrade:
 *
 *  1. Saves a copy of every setting (the #233 export format, without API keys
 *     or tokens) and keeps it downloadable from the clean-up report.
 *  2. Keeps what is still needed: the second GitHub token goes into the AI
 *     GitHub token when that one is empty (2.45.0); an old API key goes into
 *     a Connections provider when no provider of its type has a key (2.46.0).
 *     An old key that differs from the one in Connections is kept, unread,
 *     for one release and named in the report.
 *  3. Deletes the removed rows and writes a report: PressHub → Usage & logs.
 *  4. Shows a notice once to the next administrator.
 *
 * For one release, reading a removed option fires _doing_it_wrong(), so code
 * outside PressHub that still reads one shows up in debug logs.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Settings_Cleanup {

    /** Bump to run a new clean-up; each REMOVED entry names the version that removes it. */
    const VERSION = 3;

    const VERSION_OPTION  = 'presshub_settings_cleanup_version';
    const REPORT_OPTION   = 'presshub_settings_cleanup_report';
    /** The copy saved by clean-up 1; later ones add "_<version>". */
    const EXPORT_OPTION   = 'presshub_settings_cleanup_export';
    const NOTICE_OPTION   = 'presshub_settings_cleanup_notice';
    const LOCK_OPTION     = 'presshub_settings_cleanup_lock';
    const DOWNLOAD_ACTION = 'presshub_settings_cleanup_export';
    const NONCE           = 'presshub_settings_cleanup_export';
    const ANCHOR          = 'presshub-cleanup-report';

    /** Seconds after which a lock left by a request that died is taken over. */
    const LOCK_TTL = 600;

    /** Release of each clean-up version, for _doing_it_wrong() and the report. */
    const RELEASES = [
        1 => '2.45.0',
        2 => '2.46.0',
        3 => '2.71.0',
    ];

    /** Release that removed the first options (kept for callers of 2.45.0). */
    const REMOVED_IN = '2.45.0';

    /**
     * Removed options => [ group, secret, since (clean-up version), key_type ].
     * key_type marks an old API key and the provider type it belongs to
     * ('@provider' = the type in presshub_ai_provider). Groups are report rows.
     * Evidence for each is in docs/settings-inventory.md (#237).
     */
    const REMOVED = [
        'presshub_social_github_token'            => [ 'group' => 'github', 'secret' => true, 'since' => 1 ],
        'presshub_social_twitter_api_key'         => [ 'group' => 'x_oauth1', 'secret' => true, 'since' => 1 ],
        'presshub_social_twitter_api_secret'      => [ 'group' => 'x_oauth1', 'secret' => true, 'since' => 1 ],
        'presshub_social_twitter_access_secret'   => [ 'group' => 'x_oauth1', 'secret' => true, 'since' => 1 ],
        'presshub_ai_briefing_podcast_prompt_1'   => [ 'group' => 'podcast_prompts', 'secret' => false, 'since' => 1 ],
        'presshub_ai_briefing_podcast_prompt_2'   => [ 'group' => 'podcast_prompts', 'secret' => false, 'since' => 1 ],
        'presshub_ai_briefing_podcast_prompt_3'   => [ 'group' => 'podcast_prompts', 'secret' => false, 'since' => 1 ],
        'presshub_ai_api_key'                     => [ 'group' => 'legacy_api_key', 'secret' => true, 'since' => 2, 'key_type' => '@provider' ],
        'presshub_ai_remove_api_key'              => [ 'group' => 'legacy_api_key', 'secret' => false, 'since' => 2 ],
        'presshub_ai_gemini_api_key'              => [ 'group' => 'legacy_gemini_key', 'secret' => true, 'since' => 2, 'key_type' => 'gemini' ],
        'presshub_ai_briefing_tts_api_key'        => [ 'group' => 'legacy_speech_key', 'secret' => true, 'since' => 2, 'key_type' => 'gemini' ],
        'presshub_ai_remove_briefing_tts_api_key' => [ 'group' => 'legacy_speech_key', 'secret' => false, 'since' => 2 ],
        // #309 (E1): old options that were still registered but never shown.
        'presshub_ai_provider'                    => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_model_openai'                => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_temperature_openai'          => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_max_tokens_openai'           => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_timeout_openai'              => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_model_anthropic'             => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_temperature_anthropic'       => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_max_tokens_anthropic'        => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_timeout_anthropic'           => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_model_gemini'                => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_temperature_gemini'          => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_max_tokens_gemini'           => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_timeout_gemini'              => [ 'group' => 'flat_provider', 'secret' => false, 'since' => 3 ],
        'presshub_ai_openai_org'                  => [ 'group' => 'provider_headers', 'secret' => false, 'since' => 3 ],
        'presshub_ai_anthropic_version'           => [ 'group' => 'provider_headers', 'secret' => false, 'since' => 3 ],
        'presshub_ai_briefing_morning_podcast_title_prefix'=> [ 'group' => 'morning_prefix', 'secret' => false, 'since' => 3 ],
        'presshub_ai_briefing_featured_image_ids' => [ 'group' => 'morning_pool', 'secret' => false, 'since' => 3 ],
        'presshub_social_enabled_twitter'         => [ 'group' => 'social_switches', 'secret' => false, 'since' => 3 ],
        'presshub_social_enabled_facebook'        => [ 'group' => 'social_switches', 'secret' => false, 'since' => 3 ],
        'presshub_social_enabled_instagram'       => [ 'group' => 'social_switches', 'secret' => false, 'since' => 3 ],
        'presshub_social_enabled_tiktok'          => [ 'group' => 'social_switches', 'secret' => false, 'since' => 3 ],
        'presshub_social_enabled_youtube'         => [ 'group' => 'social_switches', 'secret' => false, 'since' => 3 ],
    ];

    /** Where the social switches went (#309 E1), in the plugin's platform order. */
    const SOCIAL_PLATFORMS = [ 'twitter', 'facebook', 'instagram', 'tiktok', 'youtube' ];
    const SOCIAL_PLATFORMS_OPTION = 'presshub_social_enabled_platforms';
    const PODCAST_PREFIX_OPTION   = 'presshub_ai_briefing_podcast_title_prefix';
    const MORNING_POOL_OPTION     = 'presshub_ai_briefing_featured_image_ids_morning';

    /** Old option => [ provider type, header ] it moves to (#309 E1). */
    const PROVIDER_HEADERS = [
        'presshub_ai_openai_org'        => [ 'openai', 'OpenAI-Organization' ],
        'presshub_ai_anthropic_version' => [ 'anthropic', 'anthropic-version' ],
    ];

    /** Plugin updates read this token; the second one is copied here if it is empty. */
    const GITHUB_TOKEN = 'presshub_ai_github_token';

    /** True while run() reads the removed options itself. */
    private static $running = false;

    /** Options already reported by _doing_it_wrong() in this request. */
    private static $warned = [];

    public static function register_hooks(): void {
        // After PressHub registers its settings (admin_init, priority 10), so
        // the export does not register them a second time.
        add_action( 'admin_init', [ __CLASS__, 'maybe_run' ], 20 );
        add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
        add_action( 'admin_post_' . self::DOWNLOAD_ACTION, [ __CLASS__, 'handle_download' ] );
        foreach ( array_keys( self::REMOVED ) as $key ) {
            add_filter( 'pre_option_' . $key, [ __CLASS__, 'warn_removed_read' ], 10, 2 );
        }
    }

    /** Report rows: label, why it went, and what happened to it. */
    public static function groups(): array {
        return [
            'github'            => [
                'label'  => __( 'Second GitHub token', 'presshub-ai-editor' ),
                'reason' => __( 'Plugin updates use the AI GitHub token.', 'presshub-ai-editor' ),
            ],
            'x_oauth1'          => [
                'label'  => __( 'X OAuth1 keys (3)', 'presshub-ai-editor' ),
                'reason' => __( 'Never read. Posting to X uses the bearer token, the access token or the worker.', 'presshub-ai-editor' ),
            ],
            'podcast_prompts'   => [
                'label'  => __( 'Podcast prompts 1–3', 'presshub-ai-editor' ),
                'reason' => __( 'Replaced by the per-style prompt studio.', 'presshub-ai-editor' ),
            ],
            'legacy_api_key'    => [
                'label'  => __( 'Old single API key', 'presshub-ai-editor' ),
                'reason' => __( 'Each AI provider in Connections has its own key.', 'presshub-ai-editor' ),
            ],
            'legacy_gemini_key' => [
                'label'  => __( 'Old Gemini key', 'presshub-ai-editor' ),
                'reason' => __( 'The Gemini provider in Connections holds the key.', 'presshub-ai-editor' ),
            ],
            'legacy_speech_key' => [
                'label'  => __( 'Separate speech key', 'presshub-ai-editor' ),
                'reason' => __( 'Speech uses the key of the provider chosen for Speech in AI models.', 'presshub-ai-editor' ),
            ],
            'flat_provider'     => [
                'label'  => __( 'Old provider choice and per-provider model, temperature, max tokens and timeout (13)', 'presshub-ai-editor' ),
                'reason' => __( 'Each AI provider in Connections has its own model and tuning, and AI models picks the provider for each task.', 'presshub-ai-editor' ),
            ],
            'provider_headers'  => [
                'label'  => __( 'OpenAI organization and Anthropic API version', 'presshub-ai-editor' ),
                'reason' => __( 'Set on the OpenAI and Anthropic providers in Connections.', 'presshub-ai-editor' ),
            ],
            'morning_prefix'    => [
                'label'  => __( 'Old morning podcast title prefix', 'presshub-ai-editor' ),
                'reason' => __( 'Replaced by the podcast title prefix.', 'presshub-ai-editor' ),
            ],
            'morning_pool'      => [
                'label'  => __( 'Old featured image pool', 'presshub-ai-editor' ),
                'reason' => __( 'Replaced by the Morning image pool.', 'presshub-ai-editor' ),
            ],
            'social_switches'   => [
                'label'  => __( 'Per-platform social switches (5)', 'presshub-ai-editor' ),
                'reason' => __( 'The enabled platforms list is the only switch.', 'presshub-ai-editor' ),
            ],
        ];
    }

    /** @return string[] */
    public static function removed_keys( int $since = 0 ): array {
        return array_keys( array_filter( self::REMOVED, static function ( $meta ) use ( $since ) {
            return 0 === $since || (int) $meta['since'] === $since;
        } ) );
    }

    public static function is_removed( string $key ): bool {
        return isset( self::REMOVED[ $key ] );
    }

    /** Option that holds the pre-clean-up copy of one clean-up version. */
    public static function export_option( int $version ): string {
        return $version <= 1 ? self::EXPORT_OPTION : self::EXPORT_OPTION . '_' . $version;
    }

    /** The clean-up version this site has run (0: none). */
    public static function done_version( bool $fresh = false ): int {
        if ( $fresh && function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( self::VERSION_OPTION, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            wp_cache_delete( 'alloptions', 'options' );
        }
        return (int) get_option( self::VERSION_OPTION, 0 );
    }

    public static function maybe_run(): void {
        if ( self::done_version() >= self::VERSION ) {
            return;
        }
        if ( ! class_exists( 'PressHub_Settings_Transfer' ) || ! class_exists( 'PressHub_Settings_Registry' ) ) {
            return;
        }
        // A page load runs it, not Heartbeat, other AJAX calls or cron.
        if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
            return;
        }
        if ( ! self::acquire_lock() ) {
            return;
        }
        try {
            // Another request may have finished while this one waited for the lock.
            if ( self::done_version( true ) < self::VERSION ) {
                self::run();
            }
        } finally {
            delete_option( self::LOCK_OPTION );
        }
    }

    /**
     * One request at a time. add_option() is not atomic in WordPress (it
     * upserts), so the lock row is inserted with INSERT IGNORE when $wpdb is
     * there: only the request whose insert affected a row runs.
     */
    private static function acquire_lock(): bool {
        global $wpdb;
        $now = time();
        if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
            $inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')", self::LOCK_OPTION, (string) $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ( 1 === (int) $inserted ) {
                return true;
            }
            $held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ( $held > 0 && $now - $held < self::LOCK_TTL ) {
                return false;
            }
            // A stale lock: take it over only if nobody else did first.
            return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", (string) $now, self::LOCK_OPTION, (string) $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }
        $held = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $held > 0 && $now - $held < self::LOCK_TTL ) {
            return false;
        }
        update_option( self::LOCK_OPTION, $now, false );
        return true;
    }

    /**
     * The clean-up itself: every option removed after the version this site
     * last ran. Returns the report it saved (empty when it stopped early).
     *
     * @return array{time:int,version:string,cleanup:int,removed:array<string,bool>,copied:array<string,string>,kept:string[],export_saved:bool}
     */
    public static function run(): array {
        self::$running = true;
        try {
            $from    = self::done_version();
            $keys    = array_keys( array_filter( self::REMOVED, static function ( $meta ) use ( $from ) {
                return (int) $meta['since'] > $from;
            } ) );
            $missing = new stdClass();
            $stored  = [];
            foreach ( $keys as $key ) {
                $value = get_option( $key, $missing );
                if ( $value !== $missing ) {
                    $stored[ $key ] = $value;
                }
            }

            // 1. A copy of every setting first. The removed options are no
            // longer registered, so add the ones that are not secrets.
            $export = PressHub_Settings_Transfer::export_data();
            foreach ( $stored as $key => $value ) {
                if ( empty( self::REMOVED[ $key ]['secret'] ) ) {
                    $export['options'][ $key ] = $value;
                } else {
                    $export['secrets_left_out'][] = $key;
                }
            }
            $export['note'] = 'Saved by PressHub before the settings clean-up (#237).';
            $flags          = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
            $json           = function_exists( 'wp_json_encode' ) ? wp_json_encode( $export, $flags ) : json_encode( $export, $flags );
            // Never delete without the copy; try again on the next request.
            if ( ! is_string( $json ) || '' === $json ) {
                return [];
            }
            $export_option = self::export_option( self::VERSION );
            update_option( $export_option, $json, false );
            if ( get_option( $export_option, '' ) !== $json ) {
                return [];
            }

            // 2. Keep what is still needed.
            $copied = []; // option => where it went.
            $kept   = [];
            foreach ( $stored as $key => $value ) {
                if ( empty( self::REMOVED[ $key ]['key_type'] ) ) {
                    continue;
                }
                $outcome = self::keep_api_key( $key, trim( (string) $value ) );
                if ( 'kept' === $outcome ) {
                    $kept[] = $key;
                } elseif ( '' !== $outcome ) {
                    $copied[ $key ] = $outcome;
                }
            }

            if ( self::removes_since( $keys, 3 ) ) {
                // After the key copies above, so a provider they create also
                // gets its header. The provider store's one-time migration
                // reads the old flat options; let it run before they go.
                self::load_provider_store();
                if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
                    PressHub_AI_Provider_Store::get_all( false );
                }
                $copied += self::keep_social_switches( $stored );
                $copied += self::keep_morning_values( $stored );
                foreach ( self::PROVIDER_HEADERS as $key => $target ) {
                    if ( ! array_key_exists( $key, $stored ) ) {
                        continue;
                    }
                    $outcome = self::keep_provider_header( $target[0], $target[1], trim( (string) $stored[ $key ] ) );
                    if ( 'kept' === $outcome ) {
                        $kept[] = $key;
                    } elseif ( '' !== $outcome ) {
                        $copied[ $key ] = $outcome;
                    }
                }
            }
            $social = trim( (string) ( $stored['presshub_social_github_token'] ?? '' ) );
            if ( '' !== $social && '' === trim( (string) get_option( self::GITHUB_TOKEN, '' ) ) ) {
                update_option( self::GITHUB_TOKEN, $social, false );
                $copied['presshub_social_github_token'] = __( 'the GitHub token in Connections', 'presshub-ai-editor' );
            }
            // 3. Delete the rows (an old key that differs from Connections stays one more release).
            $removed = [];
            foreach ( $keys as $key ) {
                $removed[ $key ] = array_key_exists( $key, $stored ) && ! in_array( $key, $kept, true );
                if ( $removed[ $key ] ) {
                    delete_option( $key );
                }
            }

            $report  = [
                'time'         => time(),
                'version'      => defined( 'PRESSHUB_AI_VERSION' ) ? (string) PRESSHUB_AI_VERSION : '',
                'cleanup'      => self::VERSION,
                'removed'      => $removed,
                'copied'       => $copied,
                'kept'         => $kept,
                'export_saved' => true,
            ];
            $reports = self::reports();
            $reports[ self::VERSION ] = $report;
            krsort( $reports );
            update_option( self::REPORT_OPTION, [ 'runs' => $reports ], false );
            update_option( self::NOTICE_OPTION, ( count( array_filter( $removed ) ) + count( $kept ) ) > 0 ? 1 : 0, false );
            update_option( self::VERSION_OPTION, self::VERSION, false );
            return $report;
        } finally {
            self::$running = false;
        }
    }

    /** True when $keys holds an option removed by clean-up $version. */
    private static function removes_since( array $keys, int $version ): bool {
        foreach ( $keys as $key ) {
            if ( (int) ( self::REMOVED[ $key ]['since'] ?? 0 ) === $version ) {
                return true;
            }
        }
        return false;
    }

    private static function load_provider_store(): void {
        if ( ! class_exists( 'PressHub_AI_Provider_Store' ) && is_readable( __DIR__ . '/class-provider-store.php' ) ) {
            require_once __DIR__ . '/class-provider-defaults.php';
            require_once __DIR__ . '/class-provider-store.php';
        }
    }

    /**
     * #309 (E1): fold the per-platform switches into the platforms list, the
     * way get_enabled_platforms() combined them (a switch that is on adds its
     * platform, one that is off removes it).
     *
     * @return array<string,string> option => where it went (only switches that changed the list).
     */
    private static function keep_social_switches( array $stored ): array {
        $platforms = get_option( self::SOCIAL_PLATFORMS_OPTION, null );
        if ( ! is_array( $platforms ) ) {
            $platforms = class_exists( 'PressHub_Social_Settings_Storage' ) ? PressHub_Social_Settings_Storage::DEFAULT_ENABLED_PLATFORMS : [ 'twitter', 'facebook', 'instagram', 'tiktok' ];
        }
        $list   = array_values( array_intersect( self::SOCIAL_PLATFORMS, $platforms ) );
        $copied = [];
        foreach ( self::SOCIAL_PLATFORMS as $platform ) {
            $key = 'presshub_social_enabled_' . $platform;
            if ( ! array_key_exists( $key, $stored ) ) {
                continue;
            }
            $on = in_array( $stored[ $key ], [ 1, '1', true, 'on', 'yes', 'true' ], true );
            if ( $on !== in_array( $platform, $list, true ) ) {
                $list           = $on ? array_merge( $list, [ $platform ] ) : array_values( array_diff( $list, [ $platform ] ) );
                $copied[ $key ] = $on
                    ? __( 'the enabled platforms list in Social settings (turned on)', 'presshub-ai-editor' )
                    : __( 'the enabled platforms list in Social settings (turned off)', 'presshub-ai-editor' );
            }
        }
        if ( $copied ) {
            update_option( self::SOCIAL_PLATFORMS_OPTION, array_values( array_intersect( self::SOCIAL_PLATFORMS, $list ) ) );
        }
        return $copied;
    }

    /**
     * #309 (E1): the old morning title prefix and featured image pool, each
     * copied into its replacement when that one is not set, as the getters
     * used them.
     *
     * @return array<string,string>
     */
    private static function keep_morning_values( array $stored ): array {
        $copied = [];
        $prefix = 'presshub_ai_briefing_morning_podcast_title_prefix';
        if ( array_key_exists( $prefix, $stored ) && null === get_option( self::PODCAST_PREFIX_OPTION, null ) ) {
            update_option( self::PODCAST_PREFIX_OPTION, (string) $stored[ $prefix ] );
            $copied[ $prefix ] = __( 'the podcast title prefix', 'presshub-ai-editor' );
        }
        $pool = 'presshub_ai_briefing_featured_image_ids';
        if ( array_key_exists( $pool, $stored ) && '' !== trim( (string) $stored[ $pool ] )
            && '' === trim( (string) get_option( self::MORNING_POOL_OPTION, '' ) ) ) {
            update_option( self::MORNING_POOL_OPTION, (string) $stored[ $pool ] );
            $copied[ $pool ] = __( 'the Morning image pool', 'presshub-ai-editor' );
        }
        return $copied;
    }

    /**
     * #309 (E1): an old header option onto every provider of its type in
     * Connections that sets no value of its own. '' when there is nothing to
     * copy, where it went, or 'kept' when a save failed (the row stays).
     */
    private static function keep_provider_header( string $type, string $header, string $value ): string {
        if ( '' === $value || ! class_exists( 'PressHub_AI_Provider_Store' ) ) {
            return '';
        }
        $names = [];
        foreach ( PressHub_AI_Provider_Store::get_all( false ) as $record ) {
            if ( ! is_array( $record ) || ( $record['type'] ?? '' ) !== $type ) {
                continue;
            }
            $headers = is_array( $record['headers'] ?? null ) ? $record['headers'] : [];
            if ( '' !== trim( (string) ( $headers[ $header ] ?? '' ) ) ) {
                continue;
            }
            $headers[ $header ] = $value;
            $record['headers']  = $headers;
            $id                 = PressHub_AI_Provider_Store::save_provider( $record );
            $saved              = PressHub_AI_Provider_Store::get( (string) $id );
            if ( ! is_array( $saved ) || trim( (string) ( $saved['headers'][ $header ] ?? '' ) ) !== $value ) {
                return 'kept';
            }
            $names[] = (string) ( $saved['name'] ?? $type );
        }
        if ( ! $names ) {
            return '';
        }
        /* translators: %s: provider names in Connections */
        return sprintf( __( 'the %s provider in Connections', 'presshub-ai-editor' ), implode( ', ', $names ) );
    }

    /**
     * An old API key: '' when Connections already holds it (or it is empty),
     * a description of where it was copied, or 'kept' when Connections holds
     * a different key of that type (the row stays for one release, unread).
     */
    private static function keep_api_key( string $key, string $value ): string {
        if ( '' === $value || false !== strpos( $value, '•' ) ) {
            return '';
        }
        self::load_provider_store();
        if ( ! class_exists( 'PressHub_AI_Provider_Store' ) ) {
            // Never delete a key we could not copy.
            return 'kept';
        }
        $type = (string) self::REMOVED[ $key ]['key_type'];
        if ( '@provider' === $type ) {
            $type = sanitize_key( (string) get_option( 'presshub_ai_provider', 'gemini' ) );
            $type = '' !== $type ? $type : 'gemini';
        }
        $records  = PressHub_AI_Provider_Store::get_all( false );
        $of_type  = [];
        $has_key  = false;
        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $record_key = trim( (string) ( $record['api_key'] ?? '' ) );
            if ( '' !== $record_key && hash_equals( $record_key, $value ) ) {
                return ''; // The same key is already in Connections.
            }
            if ( ( $record['type'] ?? '' ) === $type ) {
                $of_type[] = $record;
                $has_key   = $has_key || '' !== $record_key;
            }
        }
        if ( $has_key ) {
            return 'kept';
        }
        $target = $of_type[0] ?? null;
        if ( null === $target ) {
            $target            = PressHub_AI_Provider_Store::get_default_provider_record( $type );
            $target['enabled'] = true;
        }
        $target['api_key'] = $value;
        $id                = PressHub_AI_Provider_Store::save_provider( $target );
        $saved             = PressHub_AI_Provider_Store::get( $id );
        if ( ! is_array( $saved ) || trim( (string) ( $saved['api_key'] ?? '' ) ) !== $value ) {
            return 'kept';
        }
        /* translators: %s: provider name in Connections */
        return sprintf( __( 'the %s provider in Connections', 'presshub-ai-editor' ), (string) ( $saved['name'] ?? $type ) );
    }

    /** pre_option_{removed} filter: a removed option was read. */
    public static function warn_removed_read( $pre, $option = '' ) {
        $option = (string) $option;
        if ( ! self::$running && '' !== $option && ! isset( self::$warned[ $option ] ) && function_exists( '_doing_it_wrong' ) ) {
            self::$warned[ $option ] = true;
            $release = self::RELEASES[ (int) ( self::REMOVED[ $option ]['since'] ?? 1 ) ] ?? self::REMOVED_IN;
            _doing_it_wrong(
                'get_option',
                sprintf( 'PressHub removed the %s option in %s; it is no longer saved or read.', esc_html( $option ), $release ),
                $release
            );
        }
        return $pre;
    }

    /**
     * Read a removed option without the _doing_it_wrong() notice: for the
     * one-time migrations that turn an old value into its new place
     * (PressHub_AI_Provider_Store::migrate_legacy_options()).
     */
    public static function read_removed( string $key, $default = false ) {
        $was           = self::$running;
        self::$running = true;
        try {
            return get_option( $key, $default );
        } finally {
            self::$running = $was;
        }
    }

    /** Test helper. */
    public static function reset_warnings(): void {
        self::$warned = [];
    }

    // ------------------------------------------------------------------
    // Report, notice, download.
    // ------------------------------------------------------------------

    /** Every clean-up report, newest first, keyed by clean-up version. */
    public static function reports(): array {
        $stored = get_option( self::REPORT_OPTION, null );
        if ( ! is_array( $stored ) ) {
            return [];
        }
        if ( isset( $stored['removed'] ) ) {
            // Written by 2.45.0 (clean-up 1): one flat report.
            $stored['cleanup'] = 1;
            return [ 1 => $stored ];
        }
        $runs = [];
        foreach ( (array) ( $stored['runs'] ?? [] ) as $v => $r ) {
            if ( is_array( $r ) && isset( $r['removed'] ) ) {
                $r['cleanup']     = (int) $v;
                $runs[ (int) $v ] = $r;
            }
        }
        krsort( $runs );
        return $runs;
    }

    /** The latest clean-up report. */
    public static function report(): ?array {
        $runs = self::reports();
        return $runs ? reset( $runs ) : null;
    }

    /** Number of removed settings that had a saved value. */
    public static function removed_count( ?array $report = null ): int {
        $report = $report ?? self::report();
        return $report ? count( array_filter( (array) $report['removed'] ) ) : 0;
    }

    public static function report_url(): string {
        $slug = class_exists( 'PressHub_Admin_Menu' ) ? PressHub_Admin_Menu::USAGE_SLUG : 'presshub-ai-usage';
        return admin_url( 'admin.php?page=' . $slug ) . '#' . self::ANCHOR;
    }

    public static function download_url( int $cleanup = 1 ): string {
        $url = admin_url( 'admin-post.php?action=' . self::DOWNLOAD_ACTION . '&cleanup=' . $cleanup );
        return function_exists( 'wp_nonce_url' ) ? wp_nonce_url( $url, self::NONCE ) : $url;
    }

    /** Shown once, to the first administrator who opens wp-admin after the clean-up. */
    public static function render_notice(): void {
        if ( ! (int) get_option( self::NOTICE_OPTION, 0 ) || ! PressHub_Capabilities::current_user_can_manage() ) {
            return;
        }
        update_option( self::NOTICE_OPTION, 0, false );
        $report = self::report();
        $count  = self::removed_count( $report );
        $kept   = count( (array) ( $report['kept'] ?? [] ) );
        echo '<div class="notice ' . ( $kept ? 'notice-warning' : 'notice-success' ) . ' is-dismissible presshub-cleanup-notice"><p><strong>';
        if ( $count ) {
            /* translators: %d: number of removed settings */
            echo esc_html( sprintf( _n( 'PressHub cleaned up its settings: %d unused setting was removed.', 'PressHub cleaned up its settings: %d unused settings were removed.', $count, 'presshub-ai-editor' ), $count ) );
        } else {
            echo esc_html__( 'PressHub checked its old settings.', 'presshub-ai-editor' );
        }
        echo '</strong> ' . esc_html__( 'A copy of all settings was saved first. Nothing you use changed.', 'presshub-ai-editor' );
        if ( $kept ) {
            echo ' ' . esc_html__( 'An old API key differs from the key in Connections and is no longer used; the report says which. Add it in Connections if you still need it.', 'presshub-ai-editor' );
        }
        echo '</p><p><a class="button" href="' . esc_url( self::report_url() ) . '">' . esc_html__( 'See the report', 'presshub-ai-editor' ) . '</a></p></div>';
    }

    /** PressHub → Usage & logs → Clean-up report(s). Prints nothing before a clean-up ran. */
    public static function render_report(): void {
        $runs = self::reports();
        if ( ! $runs ) {
            return;
        }
        $first = true;
        foreach ( $runs as $cleanup => $report ) {
            self::render_one_report( (int) $cleanup, $report, $first );
            $first = false;
        }
    }

    private static function render_one_report( int $cleanup, array $report, bool $first ): void {
        $removed = (array) $report['removed'];
        $copied  = (array) ( $report['copied'] ?? [] );
        $kept    = (array) ( $report['kept'] ?? [] );
        if ( array_values( $copied ) === $copied ) {
            // 2.45.0 stored a list of copied options.
            $copied = array_fill_keys( $copied, __( 'the GitHub token in Connections', 'presshub-ai-editor' ) );
        }
        $when = function_exists( 'wp_date' ) ? wp_date( 'j M Y, H:i', (int) $report['time'] ) : gmdate( 'j M Y, H:i', (int) $report['time'] );
        $id   = $first ? self::ANCHOR : self::ANCHOR . '-' . $cleanup;
        echo '<section class="presshub-cleanup-report" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-title">';
        /* translators: 1: release, 2: date and time of the clean-up */
        echo '<h2 id="' . esc_attr( $id ) . '-title">' . esc_html( sprintf( __( 'Clean-up report, %1$s (%2$s)', 'presshub-ai-editor' ), self::RELEASES[ $cleanup ] ?? (string) ( $report['version'] ?? '' ), $when ) ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'PressHub no longer reads these settings, so their saved values were deleted. A copy of all settings was saved first.', 'presshub-ai-editor' ) . '</p>';
        echo '<table class="widefat striped presshub-cleanup-table"><thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Setting', 'presshub-ai-editor' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'What happened', 'presshub-ai-editor' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Why', 'presshub-ai-editor' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( self::groups() as $group => $row ) {
            $keys = array_values( array_filter( array_keys( $removed ), static function ( $k ) use ( $group ) {
                return ( self::REMOVED[ $k ]['group'] ?? '' ) === $group;
            } ) );
            if ( ! $keys ) {
                continue;
            }
            $had        = array_values( array_filter( $keys, static function ( $k ) use ( $removed ) {
                return ! empty( $removed[ $k ] );
            } ) );
            $group_kept = array_values( array_intersect( $keys, $kept ) );
            $group_copy = array_intersect_key( $copied, array_flip( $keys ) );
            if ( $group_kept ) {
                $result = __( 'Kept for one more release, no longer used: it differs from the key in Connections. Add it there if you still need it.', 'presshub-ai-editor' );
            } elseif ( $group_copy ) {
                // Every place the group's values went (#309 E1: one group can copy to several).
                /* translators: %s: where the value went, e.g. "the Google Gemini provider in Connections" */
                $result = sprintf( __( 'Copied to %s, then removed', 'presshub-ai-editor' ), implode( '; ', array_unique( array_map( 'strval', $group_copy ) ) ) );
            } elseif ( $had ) {
                $result = __( 'Removed', 'presshub-ai-editor' );
            } else {
                $result = __( 'Nothing saved; nothing to remove', 'presshub-ai-editor' );
            }
            echo '<tr><td>' . esc_html( $row['label'] ) . '<details class="presshub-cleanup-details"><summary>' . esc_html__( 'Details', 'presshub-ai-editor' ) . '</summary><ul>';
            foreach ( $keys as $k ) {
                if ( in_array( $k, $group_kept, true ) ) {
                    $state = __( 'kept, not read', 'presshub-ai-editor' );
                } elseif ( isset( $group_copy[ $k ] ) ) {
                    /* translators: %s: where the value went */
                    $state = sprintf( __( 'copied to %s, then deleted', 'presshub-ai-editor' ), (string) $group_copy[ $k ] );
                } elseif ( in_array( $k, $had, true ) ) {
                    $state = __( 'saved value deleted', 'presshub-ai-editor' );
                } else {
                    $state = __( 'was not saved', 'presshub-ai-editor' );
                }
                echo '<li><code>' . esc_html( $k ) . '</code> ' . esc_html( $state ) . '</li>';
            }
            echo '</ul></details></td><td>' . esc_html( $result ) . '</td><td>' . esc_html( $row['reason'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( ! empty( $report['export_saved'] ) && get_option( self::export_option( $cleanup ) ) ) {
            echo '<p><a class="button" href="' . esc_url( self::download_url( $cleanup ) ) . '" download>' . esc_html__( 'Download the pre-clean-up copy (JSON, no keys)', 'presshub-ai-editor' ) . '</a> ';
            echo '<span class="description">' . esc_html__( 'Import it under Settings → Site & system → Diagnostics to restore a value.', 'presshub-ai-editor' ) . '</span></p>';
        }
        echo '</section>';
    }

    public static function handle_download(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You are not allowed to download PressHub settings.', 'presshub-ai-editor' ), 403 );
        }
        check_admin_referer( self::NONCE );
        $cleanup = isset( $_GET['cleanup'] ) ? max( 1, (int) $_GET['cleanup'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
        $json    = (string) get_option( self::export_option( $cleanup ), '' );
        if ( '' === $json ) {
            wp_die( esc_html__( 'There is no pre-clean-up copy on this site.', 'presshub-ai-editor' ), 404 );
        }
        $runs   = self::reports();
        $name   = 'presshub-settings-before-clean-up-' . gmdate( 'Y-m-d', (int) ( $runs[ $cleanup ]['time'] ?? time() ) ) . '.json';
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
        exit;
    }
}
