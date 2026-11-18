<?php
/**
 * The one PressHub admin menu (#235, settings overhaul phase 3a).
 *
 *   PressHub                     top-level, opens the Daily Briefing Hub
 *   ├─ Daily Briefing Hub        admin.php?page=presshub-ai-briefing-hub
 *   ├─ Generation records        admin.php?page=presshub-ai-generation-records
 *   ├─ QA reviews                admin.php?page=presshub-ai-qa-reviews
 *   ├─ Social Queue              admin.php?page=presshub-social-queue
 *   ├─ Memory                    admin.php?page=presshub-ai-memory
 *   ├─ Editorial agenda          admin.php?page=presshub-ai-agenda
 *   ├─ Policies                  admin.php?page=presshub-ai-policies
 *   ├─ People                    admin.php?page=presshub-ai-people
 *   ├─ Usage & logs              admin.php?page=presshub-ai-usage
 *   ├─ Settings                  admin.php?page=presshub-ai&section=<slug>
 *   ├─ Presets                   admin.php?page=presshub-ai-presets
 *   └─ Help                      admin.php?page=presshub-ai-help
 *
 * Since 3b (#246) Settings holds both former settings pages in eight
 * sections, and the old Social settings URL redirects to its section.
 *
 * The top-level entry reuses the Hub's slug, so the Hub URL and its page hook
 * (toplevel_page_presshub-ai-briefing-hub) are unchanged. Each page class still
 * registers its own submenu under PressHub_Admin_Menu::PARENT; this class adds
 * the top-level entry first, puts the submenu in the order above and sends old
 * URLs (the redirect map lives in PressHub_Settings_Registry) to their new home.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';

class PressHub_Admin_Menu {

    /** Top-level slug: the Daily Briefing Hub, as before. */
    const PARENT = 'presshub-ai-briefing-hub';

    /** Menu label. Not translated: it is the product name and keeps page hooks stable. */
    const MENU_TITLE = 'PressHub';

    /** Page-hook prefix WordPress derives from MENU_TITLE for every submenu. */
    const HOOK_PREFIX = 'presshub_page_';

    /** Usage & logs page (Token & Usage Logs moved off the settings tabs). */
    const USAGE_SLUG = 'presshub-ai-usage';

    const POSITION = 26; // After Comments (25), where the Daily Briefing Hub sat.

    /** Submenu order. Slugs not listed keep their place after these. */
    const ORDER = [
        'presshub-ai-briefing-hub',
        'presshub-ai-generation-records',
        'presshub-ai-qa-reviews',
        'presshub-social-queue',
        'presshub-ai-memory',
        'presshub-ai-agenda',
        'presshub-ai-policies',
        'presshub-ai-people',
        self::USAGE_SLUG,
        'presshub-ai',
        'presshub-ai-presets',
        'presshub-ai-help',
    ];

    public static function register_hooks(): void {
        // Old URLs first: before WordPress checks access to a page that moved.
        add_action( 'admin_menu', [ __CLASS__, 'maybe_redirect_legacy' ], 0 );
        add_action( 'admin_menu', [ __CLASS__, 'add_top_level' ], 5 );
        add_action( 'admin_menu', [ __CLASS__, 'add_usage_page' ], 10 );
        add_action( 'admin_menu', [ __CLASS__, 'order_submenu' ], 999 );
    }

    /** Page title in the "PressHub — <page>" pattern. */
    public static function page_title( string $page ): string {
        return 'PressHub — ' . $page;
    }

    /** admin.php URL of a PressHub page. */
    public static function url( string $slug, array $args = [] ): string {
        $url = admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
        if ( $args && function_exists( 'add_query_arg' ) ) {
            $url = add_query_arg( $args, $url );
        }
        return $url;
    }

    /** Whether an admin_enqueue_scripts hook suffix is the given PressHub page. */
    public static function is_page_hook( string $hook, string $slug ): bool {
        if ( self::PARENT === $slug ) {
            return 'toplevel_page_' . self::PARENT === $hook;
        }
        if ( self::HOOK_PREFIX . $slug === $hook ) {
            return true;
        }
        // A translated or filtered parent title changes the prefix; the slug suffix does not.
        $suffix = '_page_' . $slug;
        return strlen( $hook ) > strlen( $suffix ) && substr( $hook, -strlen( $suffix ) ) === $suffix;
    }

    public static function add_top_level(): void {
        add_menu_page(
            self::page_title( __( 'Daily Briefing Hub', 'presshub-ai-editor' ) ),
            self::MENU_TITLE,
            PressHub_Capabilities::briefing(),
            self::PARENT,
            '', // The Hub registers its own first submenu with the render callback.
            'dashicons-megaphone',
            self::POSITION
        );
    }

    public static function add_usage_page(): void {
        add_submenu_page(
            self::PARENT,
            self::page_title( __( 'Usage & logs', 'presshub-ai-editor' ) ),
            __( 'Usage & logs', 'presshub-ai-editor' ),
            PressHub_Capabilities::manage(),
            self::USAGE_SLUG,
            [ __CLASS__, 'render_usage_page' ]
        );
    }

    /** Usage & logs: the settings clean-up report (#237), today's Token & Usage Logs dashboard and the settings audit log. */
    public static function render_usage_page(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to view PressHub usage and logs.', 'presshub-ai-editor' ) );
        }
        echo '<div class="wrap presshub-ai-settings-wrap presshub-usage-logs-wrap" id="presshub-usage-logs-page">';
        echo '<h1>' . esc_html( self::page_title( __( 'Usage & logs', 'presshub-ai-editor' ) ) ) . '</h1>';
        // #237: the settings clean-up report, once a clean-up has run.
        if ( class_exists( 'PressHub_Settings_Cleanup' ) ) {
            PressHub_Settings_Cleanup::render_report();
        }
        ( new PressHub_AI_Settings_Render() )->render_token_logs_tab();
        echo '</div>';
    }

    /**
     * Put the PressHub submenu in ORDER. WordPress lists submenus in the order
     * they were added, which depends on hook order across several classes.
     */
    public static function order_submenu(): void {
        global $submenu;
        if ( empty( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) {
            return;
        }
        $submenu[ self::PARENT ] = self::sorted( self::without_auto_parent_row( $submenu[ self::PARENT ] ) );
    }

    /**
     * WordPress adds a row for the parent itself (titled "PressHub") when the
     * first submenu under it is not the parent's own slug. The Hub registers
     * its own "Daily Briefing Hub" row with that slug, so drop the automatic
     * one and keep a single entry per slug for the Hub.
     *
     * @param array<int,array> $items
     * @return array<int,array>
     */
    public static function without_auto_parent_row( array $items ): array {
        $hub_rows = array_keys( array_filter( $items, static function ( $item ) {
            return is_array( $item ) && self::PARENT === (string) ( $item[2] ?? '' );
        } ) );
        if ( count( $hub_rows ) < 2 ) {
            return $items;
        }
        // Keep the row the Hub registered (not titled with the bare menu title), else the last.
        $keep = end( $hub_rows );
        foreach ( $hub_rows as $i ) {
            if ( self::MENU_TITLE !== (string) ( $items[ $i ][0] ?? '' ) ) {
                $keep = $i;
                break;
            }
        }
        foreach ( $hub_rows as $i ) {
            if ( $i !== $keep ) {
                unset( $items[ $i ] );
            }
        }
        return array_values( $items );
    }

    /**
     * Sort submenu rows (WordPress shape: [ title, cap, slug, page title ]) by ORDER.
     *
     * @param array<int,array> $items
     * @return array<int,array>
     */
    public static function sorted( array $items ): array {
        $rank  = array_flip( self::ORDER );
        $keyed = [];
        $i     = 0;
        foreach ( $items as $item ) {
            $slug    = is_array( $item ) ? (string) ( $item[2] ?? '' ) : '';
            $keyed[] = [ $rank[ $slug ] ?? ( count( $rank ) + $i ), $i, $item ];
            $i++;
        }
        usort( $keyed, static function ( $a, $b ) {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        } );
        return array_values( array_map( static function ( $row ) {
            return $row[2];
        }, $keyed ) );
    }

    // ------------------------------------------------------------------
    // Redirects for old URLs.
    // ------------------------------------------------------------------

    /**
     * New URL for an old PressHub admin URL, or '' when the request is not one.
     *
     * @param string $pagenow The admin file (options-general.php, admin.php…).
     * @param array  $query   The request's query arguments.
     */
    public static function legacy_redirect_url( string $pagenow, array $query ): string {
        $page = isset( $query['page'] ) && is_scalar( $query['page'] ) ? (string) $query['page'] : '';
        if ( '' === $page ) {
            return '';
        }
        foreach ( PressHub_Settings_Registry::legacy_admin_redirects() as $rule ) {
            if ( $rule['file'] !== $pagenow || $rule['page'] !== $page ) {
                continue;
            }
            if ( isset( $rule['tab'] ) ) {
                $tab = isset( $query['tab'] ) && is_scalar( $query['tab'] ) ? (string) $query['tab'] : '';
                if ( $tab !== $rule['tab'] ) {
                    continue;
                }
            }
            $args = $query;
            unset( $args['page'] );
            $lead = [ 'page' => (string) $rule['to'] ];
            if ( isset( $rule['tab'] ) || ! empty( $rule['drop_tab'] ) || ! empty( $rule['section'] ) ) {
                unset( $args['tab'] );
            }
            if ( ! empty( $rule['section'] ) ) {
                // #246: the one Settings page opens the section that holds the old tab.
                unset( $args['section'] );
                $lead['section'] = (string) $rule['section'];
            }
            $args = array_filter( $args, 'is_scalar' );
            // Build the query ourselves: add_query_arg() does not encode array values.
            $query  = http_build_query( array_merge( $lead, array_map( 'strval', $args ) ), '', '&', PHP_QUERY_RFC3986 );
            $anchor = isset( $rule['anchor'] ) ? (string) $rule['anchor'] : '';
            return admin_url( 'admin.php?' . $query ) . ( '' !== $anchor ? '#' . rawurlencode( $anchor ) : '' );
        }
        return '';
    }

    /**
     * 302 old URLs to their new page, keeping ?tab= and other arguments (the
     * browser keeps a #fragment across the redirect). Runs on admin_menu,
     * before WordPress refuses a page that is no longer registered there.
     */
    public static function maybe_redirect_legacy(): void {
        if ( ! is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
            return;
        }
        if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
            return;
        }
        $pagenow = (string) ( $GLOBALS['pagenow'] ?? '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
        $query = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET ) : $_GET;
        $url   = self::legacy_redirect_url( $pagenow, is_array( $query ) ? $query : [] );
        if ( '' === $url ) {
            return;
        }
        wp_safe_redirect( $url, 302 );
        exit;
    }
}
