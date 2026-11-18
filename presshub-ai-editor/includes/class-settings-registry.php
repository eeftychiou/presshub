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
require_once __DIR__ . '/class-settings-sections.php';

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
                'section'  => PressHub_Settings_Sections::section_of( (string) $key, $e ),
                'group'    => (string) ( $e['group'] ?? '' ),
                'label'    => (string) ( $e['label'] ?? $key ),
                'help'     => (string) ( $e['help'] ?? '' ),
                'aliases'  => array_values( array_map( 'strval', (array) ( $e['aliases'] ?? [] ) ) ),
                'checkbox' => ! empty( $e['checkbox'] ),
            ];
            if ( ! empty( $e['depends_on'] ) && is_string( $e['depends_on'] ) ) {
                $parent            = self::get( $e['depends_on'] );
                $row['depends_on'] = $e['depends_on'];
                $row['depends_label'] = (string) ( $parent['label'] ?? $e['depends_on'] );
            }
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

    /** User meta that remembers a dismissed "What's new in settings" panel. */
    const WHATS_NEW_META = 'presshub_settings_whats_new_246_dismissed';

    /**
     * Everything assets/js/settings-search.js needs on the Settings page
     * (#246): the search index across all sections the user can open, each
     * section's URL and label, old #tab hashes and the What's new panel.
     *
     * @param string $section The section on screen.
     */
    public static function search_payload( string $section = '' ): array {
        $visible = PressHub_Settings_Sections::visible();
        $urls    = [];
        $labels  = [];
        foreach ( $visible as $slug ) {
            $urls[ $slug ]   = PressHub_Settings_Sections::url( $slug );
            $labels[ $slug ] = PressHub_Settings_Sections::label( $slug );
        }
        $entries = array_values( array_filter( self::search_index(), static function ( $row ) use ( $urls ) {
            return isset( $urls[ $row['section'] ?? '' ] );
        } ) );
        $dismissed = function_exists( 'get_user_meta' ) && function_exists( 'get_current_user_id' )
            && (bool) get_user_meta( get_current_user_id(), self::WHATS_NEW_META, true );
        return [
            'page'                => 'settings',
            'section'             => $section,
            'section_urls'        => $urls,
            'section_labels'      => $labels,
            'entries'             => $entries,
            'legacy_hashes'       => PressHub_Settings_Sections::legacy_hash_urls(),
            'whats_new_dismissed' => $dismissed,
            'whats_new'           => [
                __( 'PressHub AI and PressHub Social settings are now one Settings page. Pick a section on the left; each section has its own Save.', 'presshub-ai-editor' ),
                __( 'Connections holds every key and account: AI providers, social platform accounts, the automation worker, Google Cloud and plugin updates. AI models shows in one table which model each AI task uses.', 'presshub-ai-editor' ),
                __( 'Writing & QA brings together Co-author drafting, the QA gate, SEO & tags and Featured images. Social publishing holds everything from the old Social settings page except the accounts.', 'presshub-ai-editor' ),
                __( 'Site & system shows every retention period in one table, with link previews, rate limits and diagnostics. Token & usage logs are on PressHub → Usage & logs.', 'presshub-ai-editor' ),
                __( 'Leaving a section with unsaved changes asks first. Old links and bookmarks still work: they open the section that now holds the setting.', 'presshub-ai-editor' ),
            ],
            'i18n'                => [
                'search_label'       => __( 'Search settings', 'presshub-ai-editor' ),
                'search_placeholder' => __( 'Search settings…', 'presshub-ai-editor' ),
                'search_help'        => __( 'Type a setting name, its help text or an old name.', 'presshub-ai-editor' ),
                'no_results'         => __( 'No matching settings', 'presshub-ai-editor' ),
                /* translators: %d: number of results */
                'results_count'      => __( '%d settings found', 'presshub-ai-editor' ),
                'default_label'      => __( 'Default:', 'presshub-ai-editor' ),
                'reset'              => __( 'Reset to default', 'presshub-ai-editor' ),
                /* translators: %s: setting label */
                'reset_aria'         => __( 'Reset %s to its default', 'presshub-ai-editor' ),
                'on'                 => __( 'On', 'presshub-ai-editor' ),
                'off'                => __( 'Off', 'presshub-ai-editor' ),
                'empty_default'      => __( 'empty (uses the provider or built-in default)', 'presshub-ai-editor' ),
                /* translators: %s: label of the switch a setting depends on */
                'depends_reason'     => __( 'Used only when "%s" is on.', 'presshub-ai-editor' ),
                /* translators: 1: number of changed fields, 2: section name */
                'leave_confirm'      => __( "Leave this page? Your %1\$d changes in %2\$s aren't saved.", 'presshub-ai-editor' ),
                /* translators: %s: section name */
                'leave_confirm_one'  => __( "Leave this page? Your change in %s isn't saved.", 'presshub-ai-editor' ),
                'unsaved_one'        => __( '1 unsaved change', 'presshub-ai-editor' ),
                /* translators: %d: number of changed fields */
                'unsaved_many'       => __( '%d unsaved changes', 'presshub-ai-editor' ),
                'error_prefix'       => __( 'Error:', 'presshub-ai-editor' ),
                'invalid_value'      => __( 'Enter a valid value.', 'presshub-ai-editor' ),
                'not_saved'          => __( 'Not saved. Fix the fields listed above.', 'presshub-ai-editor' ),
                'whats_new_title'    => __( "What's new in settings", 'presshub-ai-editor' ),
                'whats_new_search'   => __( 'Search for a setting', 'presshub-ai-editor' ),
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
     * Old PressHub admin URLs and where they go now (#235, #246). Each rule:
     * the admin file and ?page= of the old URL, an optional ?tab= it must
     * carry, the new page slug and, for settings, the new section and the
     * group anchor inside it. ?tab= is dropped when the rule names a tab or
     * a section (drop_tab); other query arguments are kept.
     *
     * @return array<int,array{file:string,page:string,to:string,tab?:string,section?:string,anchor?:string,drop_tab?:string}>
     */
    public static function legacy_admin_redirects(): array {
        $rules = [
            // Settings → PressHub AI and its Presets page moved under PressHub.
            [ 'file' => 'options-general.php', 'page' => 'presshub-ai', 'to' => 'presshub-ai' ],
            [ 'file' => 'options-general.php', 'page' => 'presshub-ai-presets', 'to' => 'presshub-ai-presets' ],
            // An older build filed the Social Queue under Settings.
            [ 'file' => 'options-general.php', 'page' => 'presshub-social-queue', 'to' => 'presshub-social-queue' ],
            // Token & Usage Logs left the settings tabs for Usage & logs.
            [ 'file' => 'admin.php', 'page' => 'presshub-ai', 'tab' => 'token_logs', 'to' => 'presshub-ai-usage' ],
            [ 'file' => 'admin.php', 'page' => 'presshub-ai', 'tab' => 'token_usage', 'to' => 'presshub-ai-usage' ],
            [ 'file' => 'admin.php', 'page' => 'presshub-ai', 'tab' => 'usage', 'to' => 'presshub-ai-usage' ],
            // Short form of the menu.
            [ 'file' => 'admin.php', 'page' => 'presshub', 'to' => 'presshub-ai-briefing-hub' ],
        ];
        // #246: Social settings and every old tab of both pages go to their section.
        $rules = array_merge( $rules, PressHub_Settings_Sections::legacy_redirects() );
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
                    // "option[]" arrives as one list (admin.js) or one value.
                    foreach ( is_array( $value ) ? array_values( $value ) : [ $value ] as $item ) {
                        $post_data[ $m[1] ][] = $item;
                    }
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
     * Writes the posted values for a page (or one tab of it), by the rules
     * of write_posted(). The "save all" handler and older callers use it.
     *
     * @param string $page      'ai' or 'social'.
     * @param string $tab       Tab key, or '' for the whole page.
     * @param array  $post_data Decoded request data.
     * @return array{saved_keys:string[],count:int,keys:string[]}
     */
    public static function save_posted( string $page, string $tab, array $post_data ): array {
        $map        = self::options_map( $page, $tab );
        $saved_keys = self::write_posted( $map, self::expand_bracket_keys( $post_data ), $tab ?: 'all' );
        self::flush_option_cache( array_keys( $map ) );

        return [
            'saved_keys' => $saved_keys,
            'count'      => count( $saved_keys ),
            'keys'       => array_keys( $map ),
        ];
    }

    /**
     * Writes the posted values of one Settings section (#246), across both
     * option groups. Only the section's own options are read from the post,
     * so a section's Save never writes, zeroes or drops another section's
     * fields; each option keeps the sanitize callback (and so the group) it
     * was registered with.
     *
     * @param string $section     Section slug (PressHub_Settings_Sections).
     * @param array  $post_data   Decoded request data.
     * @param bool   $with_social Whether Social options may be written (the Social capability).
     * @return array{saved_keys:string[],count:int,keys:string[]}
     */
    public static function save_section( string $section, array $post_data, bool $with_social = true ): array {
        self::ensure_registered();
        $map = [];
        foreach ( PressHub_Settings_Sections::entries( $section ) as $key => $entry ) {
            if ( self::is_unused( $key ) || ( ! $with_social && 'social' === ( $entry['page'] ?? '' ) ) ) {
                continue;
            }
            $cb          = self::sanitizer( $key );
            $map[ $key ] = $cb ?: static function ( $v ) {
                return function_exists( 'wp_unslash' ) ? wp_unslash( $v ) : $v;
            };
        }
        $saved_keys = self::write_posted( $map, self::expand_bracket_keys( $post_data ), $section );
        self::flush_option_cache( array_keys( $map ) );

        return [
            'saved_keys' => $saved_keys,
            'count'      => count( $saved_keys ),
            'keys'       => array_keys( $map ),
        ];
    }

    /**
     * The one write loop behind save_posted() and save_section().
     *
     *  - Remove-key flags call their sanitizer (which deletes the key) when ticked.
     *    A new mailbox password typed in the same save wins over "Remove".
     *  - Secrets are written only when a new, unmasked value is posted
     *    (keep-on-empty). AI sanitizers store the value themselves; Social
     *    secrets are stored here, and their "<option>_remove" box clears them
     *    unless a new value is typed in the same save.
     *  - Rendered checkboxes that are absent from the post are saved as 0, but
     *    only for the options in $map, so other tabs and sections are never touched.
     *  - A Social field that is absent but posts a "<option>_present" marker, or
     *    is a rendered checkbox, is saved as its sanitizer's value for null, the
     *    way options.php saved it (an empty list, an unticked box, or no change).
     *  - Everything else is written only when posted.
     *
     * @param array<string,callable> $map
     * @return string[] Saved option names.
     */
    private static function write_posted( array $map, array $post_data, string $context ): array {
        $saved_keys = [];
        foreach ( $map as $option => $sanitizer ) {
            $entry  = self::get( $option ) ?? [];
            $social = 'social' === ( $entry['page'] ?? '' );
            try {
                if ( ! empty( $entry['removes'] ) ) {
                    // A new key, token or password typed in the same save wins over "Remove".
                    $new_secret = trim( (string) ( $post_data[ (string) $entry['removes'] ] ?? '' ) );
                    if ( '' !== $new_secret && false === strpos( $new_secret, '••••' ) ) {
                        continue;
                    }
                    if ( ! empty( $post_data[ $option ] ) ) {
                        call_user_func( $sanitizer, $post_data[ $option ] );
                        $saved_keys[] = $option;
                    }
                    continue;
                }

                if ( ! empty( $entry['secret'] ) ) {
                    $typed = isset( $post_data[ $option ] ) ? trim( (string) $post_data[ $option ] ) : '';
                    $is_new = '' !== $typed && false === strpos( $typed, '••••' );
                    // A new secret typed in the same save wins over a ticked "Remove".
                    if ( $social && ! $is_new && ! empty( $post_data[ $option . '_remove' ] ) ) {
                        // delete_option() skips the sanitizer, which keeps a stored secret on ''.
                        delete_option( $option );
                        $saved_keys[] = $option;
                        continue;
                    }
                    if ( isset( $post_data[ $option ] ) ) {
                        // The settings payload is JSON (not slashed); the mailbox
                        // password must keep any backslashes it contains.
                        $raw = 'presshub_ai_press_inbox_password' === $option
                            ? (string) $post_data[ $option ]
                            : trim( (string) wp_unslash( $post_data[ $option ] ) );
                        if ( '' !== $raw && false === strpos( $raw, '••••' ) ) {
                            $clean = call_user_func( $sanitizer, $raw );
                            if ( $social ) {
                                update_option( $option, $clean );
                            }
                            $saved_keys[] = $option;
                        }
                    }
                    continue;
                }

                if ( isset( $post_data[ $option ] ) ) {
                    update_option( $option, call_user_func( $sanitizer, $post_data[ $option ] ) );
                    $saved_keys[] = $option;
                } elseif ( $social && ( ! empty( $post_data[ $option . '_present' ] ) || ( ! empty( $entry['checkbox'] ) && ! empty( $entry['rendered'] ) ) ) ) {
                    // As options.php did: the Social sanitizer gets null and reads its
                    // "_present" marker (an empty list, an unticked box, or "keep").
                    update_option( $option, call_user_func( $sanitizer, null ) );
                    $saved_keys[] = $option;
                } elseif ( ! empty( $entry['checkbox'] ) && ! empty( $entry['rendered'] ) ) {
                    update_option( $option, 0 );
                    $saved_keys[] = $option;
                }
            } catch ( Throwable $e ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( sprintf( 'Error updating option %s in section %s: %s', $option, $context, $e->getMessage() ) );
                }
            }
        }
        return $saved_keys;
    }

    /** @param string[] $keys */
    private static function flush_option_cache( array $keys ): void {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            foreach ( $keys as $key ) {
                wp_cache_delete( $key, 'options' );
            }
        }
    }
}
