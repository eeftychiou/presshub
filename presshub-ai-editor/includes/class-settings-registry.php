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
    ];
    const QA_TAB_ALIASES = [ 'qa', 'scorecard', 'scorecards', 'editorial_qa' ];

    /** @var array<string,array>|null Declared metadata, keyed by option name. */
    private static $data = null;

    /** @var array<string,array> Runtime registrations: option => [ group, args ]. */
    private static $registered = [];

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
        self::$registered[ $option ] = [ 'group' => $group, 'args' => $args ];
        if ( function_exists( 'register_setting' ) ) {
            register_setting( $group, $option, $args );
        }
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
