<?php
/**
 * PressHub_AI_Fiches_Admin - the fiche pages under the PressHub menu.
 *
 * The editorial agenda fiche (#198), the policy fiches and the stakeholder /
 * people fiches (#197) used to be tabs on Daily Briefing Hub → Memory, then had
 * their own "Policy Fiches" menu (2.37). Since #235 they sit under PressHub,
 * after Memory:
 *
 *   PressHub
 *     ├─ Editorial agenda         admin.php?page=presshub-ai-agenda
 *     ├─ Policies                 admin.php?page=presshub-ai-policies
 *     └─ People                   admin.php?page=presshub-ai-people
 *
 * The page slugs belong to the pages, not to the menu, so moving them changed
 * only the parent and every link keeps working.
 *
 * Old links (admin.php?page=presshub-ai-memory&tab=agenda|stakeholders) are
 * redirected to the new pages with their other query arguments kept.
 *
 * Stored data is untouched: presshub_ai_editorial_agenda* and
 * presshub_ai_stakeholders keep their names.
 *
 * @package PressHub_AI_Editor
 * @since 2.37.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Fiches_Admin {

    /** Former top-level menu slug (the Agenda page); kept for code that links to it. */
    const MENU_SLUG   = 'presshub-ai-agenda';
    const AGENDA_SLUG = 'presshub-ai-agenda';
    const PEOPLE_SLUG = 'presshub-ai-people';
    const POLICY_SLUG = 'presshub-ai-policies';

    /** Old Memory page tab → new page slug. */
    const LEGACY_TABS = [
        'agenda'       => self::AGENDA_SLUG,
        'stakeholders' => self::PEOPLE_SLUG,
    ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybe_redirect_legacy' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /** Same capability as the Memory editor (filterable there): editors and up. */
    public static function capability(): string {
        return class_exists( 'PressHub_AI_Memory_Editor' ) ? PressHub_AI_Memory_Editor::CAPABILITY : 'edit_others_posts';
    }

    public static function agenda_url( array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::AGENDA_SLUG ], $args ), admin_url( 'admin.php' ) );
    }

    public static function people_url( array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::PEOPLE_SLUG ], $args ), admin_url( 'admin.php' ) );
    }

    public function add_admin_menu(): void {
        $cap    = self::capability();
        $parent = class_exists( 'PressHub_Admin_Menu' ) ? PressHub_Admin_Menu::PARENT : 'presshub-ai-briefing-hub';

        // #235: the pages sit under the one PressHub menu; their slugs are unchanged.
        add_submenu_page(
            $parent,
            __( 'PressHub — Editorial agenda', 'presshub-ai-editor' ),
            __( 'Editorial agenda', 'presshub-ai-editor' ),
            $cap,
            self::AGENDA_SLUG,
            [ __CLASS__, 'render_agenda_page' ]
        );

        if ( class_exists( 'PressHub_AI_Policy_Fiches' ) ) {
            add_submenu_page(
                $parent,
                __( 'PressHub — Policies', 'presshub-ai-editor' ),
                __( 'Policies', 'presshub-ai-editor' ),
                $cap,
                self::POLICY_SLUG,
                [ __CLASS__, 'render_policy_page' ]
            );
        }

        if ( class_exists( 'PressHub_AI_Stakeholders' ) ) {
            add_submenu_page(
                $parent,
                __( 'PressHub — People', 'presshub-ai-editor' ),
                __( 'People', 'presshub-ai-editor' ),
                $cap,
                self::PEOPLE_SLUG,
                [ __CLASS__, 'render_people_page' ]
            );
        }
    }

    /** The agenda/people markup reuses the memory editor's stylesheet (preview <pre> etc.). */
    public function enqueue_assets( $hook ): void {
        $hook = (string) $hook;
        if ( false === strpos( $hook, self::AGENDA_SLUG ) && false === strpos( $hook, self::PEOPLE_SLUG ) && false === strpos( $hook, self::POLICY_SLUG ) ) {
            return;
        }
        if ( ! current_user_can( self::capability() ) ) {
            return;
        }
        wp_enqueue_style( 'presshub-ai-memory-editor-css', PRESSHUB_AI_URL . 'assets/memory-editor.css', [], PRESSHUB_AI_VERSION );
    }

    /**
     * Where an old Memory-tab link should go, or '' when it is not one.
     *
     * @param array $query The request's query arguments.
     */
    public static function legacy_redirect_url( array $query ): string {
        $memory = class_exists( 'PressHub_AI_Memory_Editor' ) ? PressHub_AI_Memory_Editor::PAGE_SLUG : 'presshub-ai-memory';
        $page   = isset( $query['page'] ) && is_scalar( $query['page'] ) ? (string) $query['page'] : '';
        $tab    = isset( $query['tab'] ) && is_scalar( $query['tab'] ) ? (string) $query['tab'] : '';
        if ( $memory !== $page || ! isset( self::LEGACY_TABS[ $tab ] ) ) {
            return '';
        }
        $args = [];
        foreach ( $query as $key => $value ) {
            if ( 'page' === $key || 'tab' === $key || ! is_scalar( $value ) ) {
                continue;
            }
            $args[ sanitize_key( (string) $key ) ] = rawurlencode( (string) $value );
        }
        return add_query_arg( array_merge( [ 'page' => self::LEGACY_TABS[ $tab ] ], $args ), admin_url( 'admin.php' ) );
    }

    public static function maybe_redirect_legacy(): void {
        if ( ! function_exists( 'is_admin' ) || ! is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
            return;
        }
        $url = self::legacy_redirect_url( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only redirect of a bookmark.
        if ( '' !== $url ) {
            wp_safe_redirect( $url, 301 );
            exit;
        }
    }

    // ---------------------------------------------------------------------
    // Pages
    // ---------------------------------------------------------------------

    private static function guard_page(): void {
        if ( ! current_user_can( self::capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to edit the policy fiches.', 'presshub-ai-editor' ) );
        }
    }

    public static function render_agenda_page(): void {
        self::guard_page();
        echo self::build_agenda_page( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput -- read-only; escaped in build_tab().
    }

    public static function render_people_page(): void {
        self::guard_page();
        echo self::build_people_page( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput -- read-only; escaped in build_tab().
    }

    public static function render_policy_page(): void {
        self::guard_page();
        echo self::build_policy_page( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput -- read-only; escaped in build_page().
    }

    public static function build_policy_page( array $query = [] ): string {
        return self::wrap(
            __( 'Policies', 'presshub-ai-editor' ),
            class_exists( 'PressHub_AI_Policy_Fiches' ) ? PressHub_AI_Policy_Fiches::build_page( $query ) : ''
        );
    }

    public static function build_agenda_page( array $query = [] ): string {
        return self::wrap(
            __( 'Editorial agenda', 'presshub-ai-editor' ),
            PressHub_AI_Editorial_Agenda::build_tab( $query )
        );
    }

    public static function build_people_page( array $query = [] ): string {
        return self::wrap(
            __( 'People', 'presshub-ai-editor' ),
            class_exists( 'PressHub_AI_Stakeholders' ) ? PressHub_AI_Stakeholders::build_tab( $query ) : ''
        );
    }

    private static function wrap( string $title, string $body ): string {
        return '<div class="wrap presshub-memory-editor-page presshub-fiches-page"><h1>' . esc_html( $title ) . '</h1>' . $body . '</div>';
    }
}
