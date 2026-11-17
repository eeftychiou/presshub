<?php
/**
 * PressHub settings registry: the single source of truth for every option (#233).
 *
 * Two halves:
 *
 *  - Declared metadata (settings-registry-data.php): one entry per option with
 *    its page, tab, section, label, help, type, secret / checkbox flags, status
 *    and default. This is what later phases change to move a field.
 *  - Registration: the storage classes register each option through
 *    PressHub_Settings_Registry::register(), which records the group and the
 *    sanitize callback and then calls WordPress' register_setting().
 *
 * Every save path (options.php through register_setting, the AJAX "save all"
 * handler and the AJAX per-tab handler) reads which options to write, how to
 * sanitize them and how to treat checkboxes and secrets from here, so a field
 * can no longer be saved by one path and dropped by another (#163).
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';

class PressHub_Settings_Registry {

    const STATUS_ACTIVE = 'active';
    const STATUS_UNUSED = 'unused';

    /** Option groups per registry page. */
    const GROUPS = [
        'ai'     => 'presshub_ai_options',
        'social' => 'presshub_social_options',
    ];

    /**
     * AI page tab aliases accepted by the AJAX per-tab save (older JS and tests send these).
     * "qa" and its synonyms map to the QA options inside the Co-Author tab.
     */
    const TAB_ALIASES = [
        'image'           => 'images',
        'featured_images' => 'images',
        'logging'         => 'advanced',
        'social'          => 'advanced',
        'social_meta'     => 'advanced',
        'opengraph'       => 'advanced',
        'general'         => 'providers',
        'editorial_knowledge' => 'knowledge',
    ];
    const QA_TAB_ALIASES = [ 'qa', 'scorecard', 'scorecards', 'editorial_qa' ];

    /** @var array<string,array>|null Declared metadata, keyed by option name. */
    private static $data = null;

    /** @var array<string,array> Runtime registrations: option => [ group, args ]. */
    private static $registered = [];

    /** @var array<string,bool> Option groups whose option_page_capability filter is in place. */
    private static $groups = [];

    // ------------------------------------------------------------------
    // Declared metadata.
    // ------------------------------------------------------------------

    /**
     * All declared entries, keyed by option name.
     *
     * @return array<string,array>
     */
    public static function all(): array {
        if ( null === self::$data ) {
            $data       = require __DIR__ . '/settings-registry-data.php';
            self::$data = is_array( $data ) ? $data : [];
            /**
             * Filters the declared settings registry. Add-ons can declare their
             * own options here; every declared option must also be registered.
             *
             * @param array<string,array> $data Registry entries keyed by option name.
             */
            if ( function_exists( 'apply_filters' ) ) {
                $filtered = apply_filters( 'presshub_settings_registry', self::$data );
                if ( is_array( $filtered ) ) {
                    self::$data = $filtered;
                }
            }
        }
        return self::$data;
    }

    /** Declared entry for one option, or null. */
    public static function get( string $key ): ?array {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    public static function has( string $key ): bool {
        return null !== self::get( $key );
    }

    /**
     * Entries for one page ('ai' or 'social').
     *
     * @return array<string,array>
     */
    public static function for_page( string $page ): array {
        return array_filter( self::all(), static function ( $e ) use ( $page ) {
            return ( $e['page'] ?? '' ) === $page;
        } );
    }

    /**
     * Entries for one tab of a page. An empty tab returns the whole page.
     *
     * @return array<string,array>
     */
    public static function for_tab( string $page, string $tab ): array {
        // The aliases are the AI page's historical tab names; Social tabs are used as given.
        $tab = 'ai' === $page ? self::normalize_tab( $tab ) : strtolower( trim( $tab ) );
        if ( '' === $tab || 'all' === $tab ) {
            return self::for_page( $page );
        }
        if ( 'qa' === $tab ) {
            return array_filter( self::for_page( $page ), static function ( $e, $key ) {
                return 'coauthor' === ( $e['tab'] ?? '' ) && 0 === strpos( $key, 'presshub_ai_qa_' );
            }, ARRAY_FILTER_USE_BOTH );
        }
        return array_filter( self::for_page( $page ), static function ( $e ) use ( $tab ) {
            return ( $e['tab'] ?? '' ) === $tab;
        } );
    }

    /** Lower-cases a tab key and resolves the aliases older callers use. */
    public static function normalize_tab( string $tab ): string {
        $tab = strtolower( trim( $tab ) );
        $tab = str_replace( 'presshub_ai_', '', $tab );
        if ( in_array( $tab, self::QA_TAB_ALIASES, true ) ) {
            return 'qa';
        }
        return self::TAB_ALIASES[ $tab ] ?? $tab;
    }

    /**
     * Options declared unused: still registered and stored, scheduled for
     * removal in the clean-up phase (#237) once the owner approves the list.
     *
     * @return array<string,array>
     */
    public static function unused(): array {
        return array_filter( self::all(), static function ( $e ) {
            return self::STATUS_UNUSED === ( $e['status'] ?? self::STATUS_ACTIVE );
        } );
    }

    /** True when the option is declared unused (scheduled for removal in #237). */
    public static function is_unused( string $key ): bool {
        return self::STATUS_UNUSED === ( self::get( $key )['status'] ?? self::STATUS_ACTIVE );
    }

    public static function is_secret( string $key ): bool {
        return ! empty( self::get( $key )['secret'] );
    }

    /**
     * Declared default for an option: a literal 'default' or the value of its
     * 'default_callback'. Returns null when the registry declares none.
     *
     * @return mixed
     */
    public static function default_value( string $key ) {
        $e = self::get( $key );
        if ( ! $e ) {
            return null;
        }
        if ( array_key_exists( 'default', $e ) ) {
            return $e['default'];
        }
        if ( ! empty( $e['default_callback'] ) && is_callable( $e['default_callback'] ) ) {
            return call_user_func( $e['default_callback'] );
        }
        return null;
    }

    /**
     * Index for the settings search and the per-field Default / Reset links
     * (#234): every active, rendered, non-secret option of both pages.
     * Defaults come from the registry, else from register_setting(); only
     * scalar defaults are passed.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function search_index(): array {
        $registered = function_exists( 'get_registered_settings' ) ? get_registered_settings() : [];
        $index      = [];
        foreach ( self::all() as $key => $e ) {
            if ( empty( $e['rendered'] ) || ! empty( $e['secret'] ) || ! empty( $e['removes'] ) || self::is_unused( $key ) ) {
                continue;
            }
            $row = [
                'key'      => $key,
                'page'     => (string) ( $e['page'] ?? 'ai' ),
                'tab'      => (string) ( $e['tab'] ?? '' ),
                'group'    => (string) ( $e['group'] ?? '' ),
                'label'    => (string) ( $e['label'] ?? $key ),
                'help'     => (string) ( $e['help'] ?? '' ),
                'aliases'  => array_values( array_map( 'strval', (array) ( $e['aliases'] ?? [] ) ) ),
                'checkbox' => ! empty( $e['checkbox'] ),
            ];
            $default = self::default_value( $key );
            if ( null === $default && isset( $registered[ $key ] ) && array_key_exists( 'default', $registered[ $key ] ) ) {
                $default = $registered[ $key ]['default'];
            }
            if ( is_bool( $default ) ) {
                $default = $default ? 1 : 0;
            }
            if ( is_scalar( $default ) && ( ! is_string( $default ) || strlen( $default ) <= 200 ) ) {
                $row['default'] = $default;
            }
            $index[] = $row;
        }
        return $index;
    }

    /**
     * Everything assets/js/settings-search.js needs on one settings page.
     *
     * @param string $page 'ai' or 'social'.
     */
    public static function search_payload( string $page ): array {
        return [
            'page'       => $page,
            'pages'      => [
                'ai'     => admin_url( 'admin.php?page=presshub-ai' ),
                'social' => admin_url( 'admin.php?page=presshub-social-settings' ),
            ],
            'entries'    => self::search_index(),
            'tab_labels' => [
                'ai'     => [
                    'providers'        => __( 'AI Providers', 'presshub-ai-editor' ),
                    'coauthor'         => __( 'AI Co-Author & Review', 'presshub-ai-editor' ),
                    'briefing'         => __( 'Daily Briefing Hub', 'presshub-ai-editor' ),
                    'social_listening' => __( 'Social listening', 'presshub-ai-editor' ),
                    'images'           => __( 'AI Featured Images', 'presshub-ai-editor' ),
                    'copilot'          => __( 'AI Copilot & Assistant', 'presshub-ai-editor' ),
                    'knowledge'        => __( 'Editorial knowledge', 'presshub-ai-editor' ),
                    'advanced'         => __( 'Advanced & System', 'presshub-ai-editor' ),
                ],
                'social' => [
                    'general'     => __( 'General & Engines', 'presshub-ai-editor' ),
                    'ai'          => __( 'AI Copywriter', 'presshub-ai-editor' ),
                    'credentials' => __( 'API Credentials', 'presshub-ai-editor' ),
                    'worker'      => __( 'Automation Worker', 'presshub-ai-editor' ),
                    'shorts'      => __( 'Shorts & Reels', 'presshub-ai-editor' ),
                    'queue'       => __( 'Queue & Alerts', 'presshub-ai-editor' ),
                    'prompts'     => __( 'Prompts & Templates', 'presshub-ai-editor' ),
                ],
            ],
            'whats_new'  => [
                'ai'     => [
                    __( 'Daily Briefing Hub is one page of six groups: Schedule, Sources & harvesting, Text story, Podcast script, Voices & audio and Publishing, with an "On this page" bar.', 'presshub-ai-editor' ),
                    __( 'Social account monitoring and the Trending in Cyprus roundup moved to the new Social listening tab.', 'presshub-ai-editor' ),
                    __( 'Editorial memory moved to Editorial knowledge. SEO & tags moved to AI Co-Author & Review. The briefing QA prompt moved to Daily Briefing → Text story.', 'presshub-ai-editor' ),
                    __( 'Use the search box to find any setting, including by its old name. Fields show their default with a Reset link, and leaving a tab with unsaved changes asks first.', 'presshub-ai-editor' ),
                ],
                'social' => [
                    __( 'Everything Shorts and Reels use is on the new Shorts & Reels tab, with a list of anything that stops them from running.', 'presshub-ai-editor' ),
                    __( 'Automation Worker keeps only the worker connection. Card and video sizes and TikTok narration moved to AI Copywriter → Images & video. X max characters moved next to the X engine.', 'presshub-ai-editor' ),
                    __( 'Use the search box to find any setting on either settings page.', 'presshub-ai-editor' ),
                ],
            ],
            'i18n'       => [
                'search_label'       => __( 'Search settings', 'presshub-ai-editor' ),
                'search_placeholder' => __( 'Search settings (name, help text or old name)', 'presshub-ai-editor' ),
                'no_results'         => __( 'No matching settings', 'presshub-ai-editor' ),
                'default_label'      => __( 'Default:', 'presshub-ai-editor' ),
                'reset'              => __( 'Reset', 'presshub-ai-editor' ),
                /* translators: %s: setting label */
                'reset_aria'         => __( 'Reset %s to its default', 'presshub-ai-editor' ),
                'on'                 => __( 'On', 'presshub-ai-editor' ),
                'off'                => __( 'Off', 'presshub-ai-editor' ),
                'empty_default'      => __( 'empty (uses the provider or built-in default)', 'presshub-ai-editor' ),
                'whats_new_title'    => __( "What's new in settings", 'presshub-ai-editor' ),
                'dismiss'            => __( 'Dismiss this notice.', 'presshub-ai-editor' ),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Registration.
    // ------------------------------------------------------------------

    /**
     * Registers one option with WordPress and records it in the registry.
     * Same signature as register_setting(); storage classes call this instead.
     *
     * @param string $group  Option group (unchanged from 2.36.x).
     * @param string $option Option name.
     * @param array  $args   register_setting() arguments.
     */
    public static function register( string $group, string $option, array $args = [] ): void {
        if ( ! isset( self::$groups[ $group ] ) && class_exists( 'PressHub_Capabilities' ) ) {
            // #235: editors save through options.php with the PressHub capability.
            self::$groups[ $group ] = true;
            PressHub_Capabilities::register_option_group( $group );
        }
        self::$registered[ $option ] = [ 'group' => $group, 'args' => $args ];
        if ( function_exists( 'register_setting' ) ) {
            register_setting( $group, $option, $args );
        }
    }

    /**
     * Old PressHub admin URLs and where they go now (#235). 3b extends this
     * map to settings sections. Each rule: the admin file and ?page= of the
     * old URL, an optional ?tab= it must carry (dropped on redirect), and the
     * new page slug. Other query arguments are kept.
     *
     * @return array<int,array{file:string,page:string,to:string,tab?:string}>
     */
    public static function legacy_admin_redirects(): array {
        $rules = [
            // Settings → PressHub AI and its Presets page moved under PressHub.
            [ 'file' => 'options-general.php', 'page' => 'presshub-ai', 'to' => 'presshub-ai' ],
            [ 'file' => 'options-general.php', 'page' => 'presshub-ai-presets', 'to' => 'presshub-ai-presets' ],
            // An older build filed the Social Queue under Settings.
            [ 'file' => 'options-general.php', 'page' => 'presshub-social-queue', 'to' => 'presshub-social-queue' ],
            [ 'file' => 'options-general.php', 'page' => 'presshub-social-settings', 'to' => 'presshub-social-settings' ],
            // Token & Usage Logs left the settings tabs for Usage & logs.
            [ 'file' => 'admin.php', 'page' => 'presshub-ai', 'tab' => 'token_logs', 'to' => 'presshub-ai-usage' ],
            [ 'file' => 'admin.php', 'page' => 'presshub-ai', 'tab' => 'token_usage', 'to' => 'presshub-ai-usage' ],
            [ 'file' => 'admin.php', 'page' => 'presshub-ai', 'tab' => 'usage', 'to' => 'presshub-ai-usage' ],
            // Short form of the menu.
            [ 'file' => 'admin.php', 'page' => 'presshub', 'to' => 'presshub-ai-briefing-hub' ],
        ];
        /**
         * Filters the old-URL redirect map used by the PressHub menu.
         *
         * @param array $rules Redirect rules.
         */
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'presshub_legacy_admin_redirects', $rules );
            if ( is_array( $filtered ) ) {
                $rules = array_values( array_filter( $filtered, static function ( $r ) {
                    return is_array( $r ) && isset( $r['file'], $r['page'], $r['to'] );
                } ) );
            }
        }
        return $rules;
    }

    /**
     * Options registered so far in this request, keyed by option name.
     *
     * @return array<string,array{group:string,args:array}>
     */
    public static function registered(): array {
        return self::$registered;
    }

    /**
     * Makes sure both settings pages have registered their options. The AJAX
     * save handlers call this because they can run without admin_init having
     * registered anything (tests, some hosts).
     */
    public static function ensure_registered( string $page = '' ): void {
        if ( ( '' === $page || 'ai' === $page ) && ! isset( self::$registered['presshub_ai_provider'] ) && class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            // register_options() also adds the settings fields, whose callbacks live on the renderer.
            if ( ! class_exists( 'PressHub_AI_Settings_Render' ) ) {
                require_once __DIR__ . '/class-settings-render.php';
            }
            PressHub_AI_Settings_Storage::register_options();
        }
        if ( ( '' === $page || 'social' === $page ) && ! isset( self::$registered['presshub_social_copy_mode'] ) && class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'register_options' ) ) {
            PressHub_Social_Settings_Storage::register_options();
        }
    }

    /** Test helper: forget runtime registrations and reload declared data. */
    public static function reset(): void {
        self::$registered = [];
        self::$groups     = [];
        self::$data       = null;
    }

    /**
     * Sanitize callback for an option: the one it was registered with.
     *
     * @return callable|null
     */
    public static function sanitizer( string $key ) {
        $cb = self::$registered[ $key ]['args']['sanitize_callback'] ?? null;
        return is_callable( $cb ) ? $cb : null;
    }

    /**
     * Option => sanitize callback for a page or one of its tabs, in registry
     * order. Options without a sanitize callback fall back to a pass-through
     * that only unslashes, matching register_setting()'s behaviour.
     *
     * @return array<string,callable>
     */
    public static function options_map( string $page, string $tab = '' ): array {
        self::ensure_registered( $page );
        $map = [];
        foreach ( self::for_tab( $page, $tab ) as $key => $entry ) {
            $cb          = self::sanitizer( $key );
            $map[ $key ] = $cb ?: static function ( $v ) {
                return function_exists( 'wp_unslash' ) ? wp_unslash( $v ) : $v;
            };
        }
        return $map;
    }

    /**
     * The settings screen posts its fields as a flat JSON object, so an array
     * field such as "presshub_ai_policy_fiches_budgets[podcast_script]" arrives
     * as that literal key. Folds such keys into nested arrays (one level).
     */
    public static function expand_bracket_keys( array $post_data ): array {
        foreach ( $post_data as $key => $value ) {
            if ( is_string( $key ) && preg_match( '/^([a-z0-9_]+)\[([a-z0-9_]*)\]$/', $key, $m ) ) {
                if ( ! isset( $post_data[ $m[1] ] ) || ! is_array( $post_data[ $m[1] ] ) ) {
                    $post_data[ $m[1] ] = [];
                }
                if ( '' === $m[2] ) {
                    $post_data[ $m[1] ][] = $value;
                } else {
                    $post_data[ $m[1] ][ $m[2] ] = $value;
                }
                unset( $post_data[ $key ] );
            }
        }
        return $post_data;
    }

    // ------------------------------------------------------------------
    // The one save routine used by both AJAX save handlers.
    // ------------------------------------------------------------------

    /**
     * Writes the posted values for a page (or one tab of it).
     *
     *  - Remove-key flags call their sanitizer (which deletes the key) when ticked.
     *    A new mailbox password typed in the same save wins over "Remove".
     *  - Secrets are written only when a new, unmasked value is posted
     *    (keep-on-empty). Their sanitizers store the value themselves.
     *  - Rendered checkboxes that are absent from the post are saved as 0, but
     *    only for the tab being saved, so another tab's checkboxes are never touched.
     *  - Everything else is written only when posted.
     *
     * @param string $page      'ai' or 'social'.
     * @param string $tab       Tab key, or '' for the whole page.
     * @param array  $post_data Decoded request data.
     * @return array{saved_keys:string[],count:int,keys:string[]}
     */
    public static function save_posted( string $page, string $tab, array $post_data ): array {
        $map        = self::options_map( $page, $tab );
        $saved_keys = [];
        $post_data  = self::expand_bracket_keys( $post_data );

        foreach ( $map as $option => $sanitizer ) {
            $entry = self::get( $option ) ?? [];
            try {
                if ( ! empty( $entry['removes'] ) ) {
                    if ( 'presshub_ai_remove_press_inbox_password' === $option ) {
                        $new_pass = (string) ( $post_data['presshub_ai_press_inbox_password'] ?? '' );
                        if ( '' !== $new_pass && false === strpos( $new_pass, '••••' ) ) {
                            continue;
                        }
                    }
                    if ( ! empty( $post_data[ $option ] ) ) {
                        call_user_func( $sanitizer, $post_data[ $option ] );
                        $saved_keys[] = $option;
                    }
                    continue;
                }

                if ( ! empty( $entry['secret'] ) ) {
                    if ( isset( $post_data[ $option ] ) ) {
                        // The settings payload is JSON (not slashed); the mailbox
                        // password must keep any backslashes it contains.
                        $raw = 'presshub_ai_press_inbox_password' === $option
                            ? (string) $post_data[ $option ]
                            : trim( (string) wp_unslash( $post_data[ $option ] ) );
                        if ( '' !== $raw && false === strpos( $raw, '••••' ) ) {
                            call_user_func( $sanitizer, $raw );
                            $saved_keys[] = $option;
                        }
                    }
                    continue;
                }

                if ( isset( $post_data[ $option ] ) ) {
                    update_option( $option, call_user_func( $sanitizer, $post_data[ $option ] ) );
                    $saved_keys[] = $option;
                } elseif ( ! empty( $entry['checkbox'] ) && ! empty( $entry['rendered'] ) ) {
                    update_option( $option, 0 );
                    $saved_keys[] = $option;
                }
            } catch ( Throwable $e ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( sprintf( 'Error updating option %s in section %s: %s', $option, $tab ?: 'all', $e->getMessage() ) );
                }
            }
        }

        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            foreach ( array_keys( $map ) as $key ) {
                wp_cache_delete( $key, 'options' );
            }
        }

        return [
            'saved_keys' => $saved_keys,
            'count'      => count( $saved_keys ),
            'keys'       => array_keys( $map ),
        ];
    }
}
