<?php
/**
 * PressHub_AI_QA_Held_Admin — where held posts are visible without email.
 *
 *   - PressHub → Held by QA: every post the QA gate is keeping in Pending
 *     Review, with the failed checks and a "Publish anyway" link.
 *   - An admin notice for editors and admins on the dashboard, the Posts
 *     list, the Daily Briefing Hub and QA reviews while any post is held.
 *   - A "QA" column in the Posts list with one badge per check (editorial,
 *     legal, SEO) from the latest review, and what failed in words.
 *   - A QA gate panel at the top of the editor's PressHub box: the three
 *     verdicts in detail, recent reviews and "Publish anyway" while held.
 *     The block editor saves through REST, so the panel reloads itself
 *     after each save (assets/js/qa-gate-panel.js).
 *
 * A post is held when it is pending, carries the status it was going to
 * (PressHub_AI_Publish_Gate::META_TARGET_STATUS) and its latest review
 * holds it (hold or error).
 *
 * @package PressHub_AI_Editor
 * @since 2.50.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';
require_once __DIR__ . '/class-admin-menu.php';
require_once __DIR__ . '/class-publish-gate.php';
require_once __DIR__ . '/class-qa-reviews-admin.php';

class PressHub_AI_QA_Held_Admin {

    const PAGE_SLUG = 'presshub-ai-qa-held';

    /** Most held posts looked at (listed and counted). */
    const MAX_HELD = 200;

    /** Held posts per page on Held by QA. */
    const PER_PAGE = 50;

    /** Reviews listed in the editor before the link to the full history. */
    const METABOX_HISTORY = 5;

    /** The held count for the notice is kept this long (cleared on any status change). */
    const COUNT_TRANSIENT = 'presshub_ai_qa_held_count';
    const COUNT_TTL       = 60;

    /** admin-ajax action that reloads the editor panel. */
    const PANEL_ACTION = 'presshub_ai_qa_gate_panel';

    /** Admin screens that show the "posts held" notice (ids, or a part of a PressHub page id). */
    const NOTICE_SCREENS = [ 'dashboard', 'edit-post' ];
    const NOTICE_PAGES   = [ 'presshub-ai-briefing-hub', 'presshub-ai-qa-reviews' ];

    /** Short badge letters per check. */
    const CHECK_LETTERS = [ 'editorial' => 'E', 'legal' => 'L', 'seo' => 'S' ];

    /** A symbol per result, so a badge never relies on colour alone. */
    const RESULT_GLYPHS = [ 'pass' => '✓', 'fail' => '✕', 'skip' => '–', 'error' => '!' ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_notices', [ $this, 'render_notice' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'transition_post_status', [ __CLASS__, 'flush_count' ] );
        add_action( 'wp_ajax_' . self::PANEL_ACTION, [ __CLASS__, 'ajax_panel' ] );
        add_filter( 'manage_post_posts_columns', [ __CLASS__, 'add_column' ] );
        add_action( 'manage_post_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            PressHub_Admin_Menu::PARENT,
            PressHub_Admin_Menu::page_title( __( 'Held by QA', 'presshub-ai-editor' ) ),
            __( 'Held by QA', 'presshub-ai-editor' ),
            PressHub_Capabilities::manage(),
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /** Badge styles on the Posts list, the editor, Held by QA and QA reviews; the panel script in the editor. */
    public static function enqueue_assets( $hook ): void {
        $hook = (string) $hook;
        if ( ! defined( 'PRESSHUB_AI_URL' ) ) {
            return;
        }
        $version = defined( 'PRESSHUB_AI_VERSION' ) ? PRESSHUB_AI_VERSION : false;
        $editor  = in_array( $hook, [ 'post.php', 'post-new.php' ], true );
        if ( $editor || 'edit.php' === $hook || false !== strpos( $hook, self::PAGE_SLUG ) || false !== strpos( $hook, PressHub_AI_QA_Reviews_Admin::PAGE_SLUG ) ) {
            wp_enqueue_style( 'presshub-qa-gate', PRESSHUB_AI_URL . 'assets/css/qa-gate.css', [], $version );
        }
        if ( $editor ) {
            global $post;
            $post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
            wp_enqueue_script( 'presshub-qa-gate-panel', PRESSHUB_AI_URL . 'assets/js/qa-gate-panel.js', [ 'wp-data' ], $version, true );
            wp_localize_script( 'presshub-qa-gate-panel', 'presshubQaGatePanel', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::PANEL_ACTION ),
                'postId'  => $post_id,
            ] );
        }
    }

    // ------------------------------------------------------------------
    // Which posts are held
    // ------------------------------------------------------------------

    /**
     * IDs of the posts the QA gate is holding, most recently changed first.
     * Only the IDs are loaded; the reviews come from the primed meta cache.
     *
     * @return int[]
     */
    public static function held_ids(): array {
        if ( ! function_exists( 'get_posts' ) ) {
            return [];
        }
        $found = get_posts( [
            'post_type'        => 'post',
            'post_status'      => 'pending',
            'posts_per_page'   => self::MAX_HELD,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'meta_key'         => PressHub_AI_Publish_Gate::META_TARGET_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- pending posts only.
            'suppress_filters' => false,
        ] );
        $ids = [];
        foreach ( (array) $found as $item ) {
            if ( is_object( $item ) && 'pending' !== ( $item->post_status ?? 'pending' ) ) {
                continue;
            }
            $id = is_object( $item ) ? (int) ( $item->ID ?? 0 ) : (int) $item;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        if ( $ids && function_exists( 'update_meta_cache' ) ) {
            update_meta_cache( 'post', $ids );
        }
        return array_values( array_filter( $ids, static function ( $id ) {
            $review = get_post_meta( $id, PressHub_AI_Publish_Gate::META_REVIEW, true );
            return is_array( $review ) && PressHub_AI_Publish_Gate::holds( $review );
        } ) );
    }

    /**
     * Held posts with their latest review.
     *
     * @param int[]|null $ids IDs from held_ids(), or null to look them up.
     * @return array<int, array{post: object, review: array}>
     */
    public static function held_posts( ?array $ids = null ): array {
        $out = [];
        foreach ( null === $ids ? self::held_ids() : $ids as $id ) {
            $post = get_post( (int) $id );
            if ( ! $post || 'pending' !== ( $post->post_status ?? '' ) ) {
                continue;
            }
            $review = get_post_meta( (int) $id, PressHub_AI_Publish_Gate::META_REVIEW, true );
            if ( is_array( $review ) ) {
                $out[] = [ 'post' => $post, 'review' => $review ];
            }
        }
        return $out;
    }

    /** How many posts are held, for the notice (cached for a minute, cleared on any status change). */
    public static function held_count(): int {
        $cached = function_exists( 'get_transient' ) ? get_transient( self::COUNT_TRANSIENT ) : false;
        if ( false !== $cached && is_numeric( $cached ) ) {
            return (int) $cached;
        }
        $count = count( self::held_ids() );
        if ( function_exists( 'set_transient' ) ) {
            set_transient( self::COUNT_TRANSIENT, $count, self::COUNT_TTL );
        }
        return $count;
    }

    public static function flush_count(): void {
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( self::COUNT_TRANSIENT );
        }
    }

    // ------------------------------------------------------------------
    // Words
    // ------------------------------------------------------------------

    /** Low / Medium / High in the site language. */
    public static function risk_label( string $risk ): string {
        $labels = [
            'Low'    => __( 'Low', 'presshub-ai-editor' ),
            'Medium' => __( 'Medium', 'presshub-ai-editor' ),
            'High'   => __( 'High', 'presshub-ai-editor' ),
        ];
        return $labels[ $risk ] ?? $risk;
    }

    /** "40 (needs 80)": a score against its minimum, the same everywhere. */
    public static function score_text( $score, $threshold ): string {
        return sprintf( /* translators: 1: score out of 100, 2: minimum score. */ __( '%1$d (needs %2$d)', 'presshub-ai-editor' ), (int) $score, (int) $threshold );
    }

    /** A check's result with its score or risk: "failed, 40 (needs 80)". */
    private static function check_text( string $key, array $check ): string {
        $words  = [
            'pass'  => __( 'passed', 'presshub-ai-editor' ),
            'fail'  => __( 'failed', 'presshub-ai-editor' ),
            'skip'  => __( 'not checked', 'presshub-ai-editor' ),
            'error' => __( 'could not run', 'presshub-ai-editor' ),
        ];
        $result = (string) ( $check['result'] ?? 'skip' );
        $text   = $words[ $result ] ?? $words['skip'];
        if ( in_array( $result, [ 'pass', 'fail' ], true ) ) {
            if ( 'legal' === $key && ! empty( $check['risk'] ) ) {
                $text .= ', ' . sprintf( /* translators: %s: Low, Medium or High. */ __( '%s risk', 'presshub-ai-editor' ), self::risk_label( (string) $check['risk'] ) );
            } elseif ( isset( $check['score'] ) && null !== $check['score'] ) {
                $text .= ', ' . self::score_text( $check['score'], $check['threshold'] ?? 0 );
            }
        }
        return $text;
    }

    private static function check_names(): array {
        return [
            'editorial' => __( 'Editorial', 'presshub-ai-editor' ),
            'legal'     => __( 'Legal', 'presshub-ai-editor' ),
            'seo'       => __( 'SEO', 'presshub-ai-editor' ),
        ];
    }

    /** The checks a review failed (or could not run), by key. */
    public static function failed_checks( array $review ): array {
        $failed = [];
        foreach ( (array) ( $review['checks'] ?? [] ) as $key => $check ) {
            if ( in_array( $check['result'] ?? '', [ 'fail', 'error' ], true ) ) {
                $failed[] = (string) $key;
            }
        }
        return $failed;
    }

    /** One line naming what failed: "Editorial 40 (needs 80); Legal High risk; SEO could not run". */
    public static function failed_summary( array $review ): string {
        $checks = (array) ( $review['checks'] ?? [] );
        $names  = self::check_names();
        $parts  = [];
        foreach ( self::CHECK_LETTERS as $key => $letter ) {
            $check  = (array) ( $checks[ $key ] ?? [] );
            $result = (string) ( $check['result'] ?? '' );
            if ( 'fail' === $result ) {
                if ( 'legal' === $key ) {
                    $parts[] = $names[ $key ] . ' ' . sprintf( /* translators: %s: Low, Medium or High. */ __( '%s risk', 'presshub-ai-editor' ), self::risk_label( (string) ( $check['risk'] ?? '' ) ) );
                } else {
                    $parts[] = $names[ $key ] . ' ' . self::score_text( $check['score'] ?? 0, $check['threshold'] ?? 0 );
                }
            } elseif ( 'error' === $result ) {
                $parts[] = sprintf( /* translators: %s: Editorial, Legal or SEO. */ __( '%s could not run', 'presshub-ai-editor' ), $names[ $key ] );
            }
        }
        if ( ! $parts && 'error' === ( $review['verdict'] ?? '' ) ) {
            $parts[] = __( 'The review could not run', 'presshub-ai-editor' );
        }
        return implode( '; ', $parts );
    }

    /** A gate time (ISO 8601, UTC) in the site's date format and time zone. */
    public static function format_iso( string $iso ): string {
        $ts = '' !== $iso ? strtotime( $iso ) : false;
        if ( false === $ts ) {
            return $iso;
        }
        $format = self::datetime_format();
        return function_exists( 'wp_date' ) ? (string) wp_date( $format, $ts ) : gmdate( $format, $ts );
    }

    /** A review-history time (stored in the site's time zone) in the site's format. */
    public static function format_local( string $mysql ): string {
        $ts = '' !== $mysql ? strtotime( $mysql . ' UTC' ) : false;
        if ( false === $ts ) {
            return $mysql;
        }
        // The stored wall time as it is (UTC keeps it from shifting), with translated month names.
        return function_exists( 'wp_date' ) ? (string) wp_date( self::datetime_format(), $ts, new DateTimeZone( 'UTC' ) ) : gmdate( self::datetime_format(), $ts );
    }

    private static function datetime_format(): string {
        $date = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'Y-m-d' ) : 'Y-m-d';
        $time = function_exists( 'get_option' ) ? (string) get_option( 'time_format', 'H:i' ) : 'H:i';
        return trim( ( '' !== $date ? $date : 'Y-m-d' ) . ' ' . ( '' !== $time ? $time : 'H:i' ) );
    }

    // ------------------------------------------------------------------
    // Held by QA page
    // ------------------------------------------------------------------

    public function render_page(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to view held posts.', 'presshub-ai-editor' ) );
        }
        $ids   = self::held_ids();
        $total = count( $ids );
        $pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $paged = min( $pages, max( 1, (int) ( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
        echo self::page_html( self::held_posts( array_slice( $ids, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) ), $total, $paged ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in page_html().
    }

    public static function page_html( array $held, int $total, int $paged = 1 ): string {
        $html  = '<div class="wrap presshub-qa-held-wrap" id="presshub-qa-held-page">';
        $html .= '<h1 class="wp-heading-inline">' . esc_html( PressHub_Admin_Menu::page_title( __( 'Held by QA', 'presshub-ai-editor' ) ) ) . '</h1>';
        $html .= '<hr class="wp-header-end" />';
        $html .= '<p>' . esc_html__( 'Posts the QA gate is keeping in Pending Review. Edit a post and publish it again to have it reviewed again, or release it as it is with Publish anyway and a reason.', 'presshub-ai-editor' ) . '</p>';
        if ( 0 === $total ) {
            $html .= '<div class="notice notice-success inline presshub-qa-held-empty"><p>' . esc_html__( 'No posts are held by the QA gate.', 'presshub-ai-editor' ) . '</p></div></div>';
            return $html;
        }
        $first = ( $paged - 1 ) * self::PER_PAGE + 1;
        $last  = min( $total, $first + count( $held ) - 1 );
        $html .= '<p class="description">' . esc_html( sprintf( /* translators: 1: first row, 2: last row, 3: total. */ __( 'Showing %1$d–%2$d of %3$d held posts.', 'presshub-ai-editor' ), $first, $last, $total ) );
        if ( $total >= self::MAX_HELD ) {
            $html .= ' ' . esc_html( sprintf( /* translators: %d: maximum. */ __( 'Only the %d most recently changed are listed.', 'presshub-ai-editor' ), self::MAX_HELD ) );
        }
        $html .= '</p>';
        $html .= self::render_table( $held );
        $pages = (int) ceil( $total / self::PER_PAGE );
        if ( $pages > 1 ) {
            $links = [];
            if ( $paged > 1 ) {
                $links[] = '<a class="button" href="' . esc_url( PressHub_Admin_Menu::url( self::PAGE_SLUG, [ 'paged' => $paged - 1 ] ) ) . '">' . esc_html__( '‹ Newer', 'presshub-ai-editor' ) . '</a>';
            }
            $links[] = '<span class="displaying-num">' . esc_html( sprintf( /* translators: 1: page, 2: pages. */ __( 'Page %1$d of %2$d', 'presshub-ai-editor' ), $paged, $pages ) ) . '</span>';
            if ( $paged < $pages ) {
                $links[] = '<a class="button" href="' . esc_url( PressHub_Admin_Menu::url( self::PAGE_SLUG, [ 'paged' => $paged + 1 ] ) ) . '">' . esc_html__( 'Older ›', 'presshub-ai-editor' ) . '</a>';
            }
            $html .= '<div class="tablenav bottom"><div class="tablenav-pages">' . implode( ' ', $links ) . '</div></div>';
        }
        return $html . '</div>';
    }

    public static function render_table( array $held ): string {
        $headings = [
            'post'     => __( 'Post', 'presshub-ai-editor' ),
            'target'   => __( 'Was going to', 'presshub-ai-editor' ),
            'reviewed' => __( 'Reviewed', 'presshub-ai-editor' ),
            'qa'       => __( 'QA', 'presshub-ai-editor' ),
            'failed'   => __( 'What failed', 'presshub-ai-editor' ),
            'actions'  => __( 'Actions', 'presshub-ai-editor' ),
        ];
        $targets  = [
            'publish' => __( 'Publish', 'presshub-ai-editor' ),
            'future'  => __( 'Scheduled', 'presshub-ai-editor' ),
            'private' => __( 'Private', 'presshub-ai-editor' ),
        ];
        $html = '<table class="widefat striped presshub-qa-held-table"><thead><tr>';
        foreach ( $headings as $heading ) {
            $html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $held as $item ) {
            $post    = $item['post'];
            $review  = $item['review'];
            $post_id = (int) $post->ID;
            $title   = '' !== (string) ( $post->post_title ?? '' ) ? (string) $post->post_title : sprintf( /* translators: %d: post ID. */ __( 'Post #%d', 'presshub-ai-editor' ), $post_id );
            $edit    = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post_id ) : '';
            $target  = (string) get_post_meta( $post_id, PressHub_AI_Publish_Gate::META_TARGET_STATUS, true );
            $actions = [];
            if ( '' !== $edit ) {
                $actions[] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'presshub-ai-editor' ) . '</a>';
            }
            $actions[] = '<a href="' . esc_url( PressHub_Admin_Menu::url( PressHub_AI_QA_Reviews_Admin::PAGE_SLUG, [ 'post_id' => $post_id ] ) ) . '">' . esc_html__( 'Reviews', 'presshub-ai-editor' ) . '</a>';
            if ( PressHub_AI_QA_Reviews_Admin::can_override( $post_id ) ) {
                $actions[] = '<a class="button button-small" href="' . esc_url( PressHub_AI_QA_Reviews_Admin::override_url( $post_id ) ) . '">' . esc_html__( 'Publish anyway', 'presshub-ai-editor' ) . '</a>';
            }
            $cells = [
                'post'     => '' !== $edit ? '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $title ) . '</strong></a>' : '<strong>' . esc_html( $title ) . '</strong>',
                'target'   => esc_html( $targets[ $target ] ?? $target ),
                'reviewed' => esc_html( PressHub_AI_QA_Reviews_Admin::trigger_label( (string) ( $review['trigger'] ?? '' ) ) ) . '<br /><span class="description">' . esc_html( self::format_iso( (string) ( $review['reviewed_at'] ?? '' ) ) ) . '</span>',
                'qa'       => self::badges( $review ),
                'failed'   => esc_html( self::failed_summary( $review ) ),
                'actions'  => implode( ' · ', $actions ),
            ];
            $html .= '<tr>';
            foreach ( $cells as $key => $cell ) {
                $html .= '<td data-colname="' . esc_attr( $headings[ $key ] ) . '">' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    // ------------------------------------------------------------------
    // Notice
    // ------------------------------------------------------------------

    public function render_notice(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() || ! self::notice_screen() ) {
            return;
        }
        $count = self::held_count();
        if ( $count <= 0 ) {
            return;
        }
        echo self::notice_html( $count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
    }

    public static function notice_html( int $count ): string {
        $text = sprintf(
            /* translators: %d: number of posts. */
            _n( '%d post is held in Pending Review by the QA gate.', '%d posts are held in Pending Review by the QA gate.', $count, 'presshub-ai-editor' ),
            $count
        );
        return '<div class="notice notice-warning presshub-qa-held-notice"><p>' . esc_html( $text ) . ' <a href="' . esc_url( PressHub_Admin_Menu::url( self::PAGE_SLUG ) ) . '">' . esc_html__( 'See held posts', 'presshub-ai-editor' ) . '</a></p></div>';
    }

    /** Dashboard, Posts list, Daily Briefing Hub and QA reviews. */
    public static function notice_screen(): bool {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();
        $id     = $screen ? (string) ( $screen->id ?? '' ) : '';
        if ( '' === $id ) {
            return false;
        }
        if ( in_array( $id, self::NOTICE_SCREENS, true ) ) {
            return true;
        }
        foreach ( self::NOTICE_PAGES as $page ) {
            if ( false !== strpos( $id, $page ) ) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Posts list column
    // ------------------------------------------------------------------

    public static function add_column( $columns ) {
        if ( ! is_array( $columns ) ) {
            return $columns;
        }
        $out = [];
        foreach ( $columns as $key => $label ) {
            $out[ $key ] = $label;
            if ( 'title' === $key ) {
                $out['presshub_qa'] = __( 'QA', 'presshub-ai-editor' );
            }
        }
        if ( ! isset( $out['presshub_qa'] ) ) {
            $out['presshub_qa'] = __( 'QA', 'presshub-ai-editor' );
        }
        return $out;
    }

    public static function render_column( $column, $post_id ): void {
        if ( 'presshub_qa' !== $column ) {
            return;
        }
        $review = get_post_meta( (int) $post_id, PressHub_AI_Publish_Gate::META_REVIEW, true );
        if ( ! is_array( $review ) ) {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not reviewed', 'presshub-ai-editor' ) . '</span>';
            return;
        }
        $failed = self::failed_summary( $review );
        echo self::badges( $review ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badges().
        if ( '' !== $failed ) {
            // Visible, so the score or risk is readable without a tooltip.
            echo '<span class="presshub-qa-failed" aria-hidden="true">' . esc_html( $failed ) . '</span>';
        }
    }

    /**
     * Three small badges: a letter and a symbol per check (✓ passed, ✕
     * failed, – not checked, ! could not run), coloured by result. Screen
     * readers hear each check in full; the tooltip repeats it.
     */
    public static function badges( array $review ): string {
        $checks = (array) ( $review['checks'] ?? [] );
        $names  = self::check_names();
        $html   = '<span class="presshub-qa-badges">';
        foreach ( self::CHECK_LETTERS as $key => $letter ) {
            $check  = (array) ( $checks[ $key ] ?? [] );
            $result = (string) ( $check['result'] ?? 'skip' );
            $result = isset( self::RESULT_GLYPHS[ $result ] ) ? $result : 'skip';
            $label  = $names[ $key ] . ': ' . self::check_text( $key, $check );
            $html  .= '<span class="presshub-qa-badge presshub-qa-badge-' . esc_attr( $result ) . '" title="' . esc_attr( $label ) . '">'
                . '<span aria-hidden="true">' . esc_html( $letter . ' ' . self::RESULT_GLYPHS[ $result ] ) . '</span>'
                . '<span class="screen-reader-text">' . esc_html( $label ) . '. </span></span>';
        }
        return $html . '</span>';
    }

    // ------------------------------------------------------------------
    // Editor panel
    // ------------------------------------------------------------------

    /** The panel inside the slot the editor script refreshes. */
    public static function metabox_slot( int $post_id ): string {
        return '<div id="presshub-qa-gate-panel-slot">' . self::metabox_html( $post_id ) . '</div>';
    }

    /** admin-ajax: the panel's HTML after a block editor save. */
    public static function ajax_panel(): void {
        check_ajax_referer( self::PANEL_ACTION );
        $post_id = (int) ( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
            return;
        }
        wp_send_json_success( [ 'html' => self::metabox_html( $post_id ) ] );
    }

    /**
     * The QA gate panel: the latest verdict with the three checks in
     * detail, the last few reviews, and "Publish anyway" while the post is
     * held. Empty when the gate never reviewed the post.
     */
    public static function metabox_html( int $post_id ): string {
        $review = $post_id > 0 ? get_post_meta( $post_id, PressHub_AI_Publish_Gate::META_REVIEW, true ) : null;
        if ( ! is_array( $review ) || empty( $review['checks'] ) ) {
            return '';
        }
        $checks  = (array) $review['checks'];
        $post    = get_post( $post_id );
        $context = (string) ( $review['context'] ?? '' );
        if ( ! in_array( $context, PressHub_AI_Publish_Gate::CONTEXTS, true ) ) {
            $context = $post ? PressHub_AI_Publish_Gate::context_for_post( $post ) : 'post';
        }
        $held       = PressHub_AI_QA_Reviews_Admin::is_held( $post_id );
        $overridden = ! $held && $post && PressHub_AI_Publish_Gate::is_overridden( $post, $context );
        $verdict    = (string) ( $review['verdict'] ?? '' );

        if ( $held ) {
            $state = 'held';
            $text  = 'error' === $verdict ? __( 'Held: the review could not run', 'presshub-ai-editor' ) : __( 'Held for review', 'presshub-ai-editor' );
        } elseif ( $overridden ) {
            $state = 'alert';
            $text  = __( 'Released with Publish anyway', 'presshub-ai-editor' );
        } else {
            $states = [
                'pass'  => [ 'pass', __( 'Passed', 'presshub-ai-editor' ) ],
                'alert' => [ 'alert', __( 'Passed with an alert to the editor', 'presshub-ai-editor' ) ],
                'hold'  => [ 'alert', __( 'Failed the review', 'presshub-ai-editor' ) ],
                'error' => [ 'error', __( 'The review could not run', 'presshub-ai-editor' ) ],
            ];
            list( $state, $text ) = $states[ $verdict ] ?? [ 'error', $verdict ];
        }

        $html  = '<div class="presshub-qa-gate-panel is-' . esc_attr( $state ) . '" id="presshub-qa-gate-panel">';
        $html .= '<h3>' . esc_html__( 'QA gate', 'presshub-ai-editor' ) . '</h3>';
        $html .= '<p>' . self::badges( $review ) . ' <strong>' . esc_html( $text ) . '</strong>';
        $html .= '<br /><span class="description">' . esc_html( PressHub_AI_QA_Reviews_Admin::trigger_label( (string) ( $review['trigger'] ?? '' ) ) . ' · ' . self::format_iso( (string) ( $review['reviewed_at'] ?? '' ) ) ) . '</span></p>';
        if ( $overridden ) {
            $html .= '<p class="description">' . esc_html__( 'The reason is in the review history below. Editing the post sends it through the gate again.', 'presshub-ai-editor' ) . '</p>';
        }

        $names = self::check_names();

        // Editorial.
        $ed = (array) ( $checks['editorial'] ?? [] );
        if ( 'skip' !== ( $ed['result'] ?? 'skip' ) ) {
            $html .= '<h4>' . esc_html( $names['editorial'] . ': ' . self::check_text( 'editorial', $ed ) ) . '</h4>';
            if ( '' !== (string) ( $ed['feedback'] ?? '' ) ) {
                $html .= '<p>' . esc_html( (string) $ed['feedback'] ) . '</p>';
            }
        }

        // Legal.
        $lg = (array) ( $checks['legal'] ?? [] );
        if ( 'skip' !== ( $lg['result'] ?? 'skip' ) ) {
            $html    .= '<h4>' . esc_html( $names['legal'] . ': ' . self::check_text( 'legal', $lg ) ) . '</h4>';
            $concerns = trim( (string) ( $lg['concerns'] ?? '' ) );
            if ( '' !== $concerns && 'None identified' !== $concerns ) {
                $html .= '<p>' . esc_html( $concerns ) . '</p>';
            }
            $issues = (array) ( $lg['issues'] ?? [] );
            if ( $issues ) {
                $html .= '<ul class="presshub-qa-legal-issues">';
                foreach ( $issues as $issue ) {
                    $html .= '<li><em>' . esc_html( sprintf( /* translators: %s: Low, Medium or High. */ __( '%s risk', 'presshub-ai-editor' ), self::risk_label( (string) ( $issue['severity'] ?? '' ) ) ) ) . '</em>';
                    if ( '' !== (string) ( $issue['quote'] ?? '' ) ) {
                        $html .= ': <q>' . esc_html( (string) $issue['quote'] ) . '</q>';
                    }
                    if ( '' !== (string) ( $issue['fix'] ?? '' ) ) {
                        $html .= '<br />' . esc_html__( 'Fix:', 'presshub-ai-editor' ) . ' ' . esc_html( (string) $issue['fix'] );
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
            }
        }

        // SEO.
        $seo = (array) ( $checks['seo'] ?? [] );
        if ( 'skip' !== ( $seo['result'] ?? 'skip' ) ) {
            $html   .= '<h4>' . esc_html( $names['seo'] . ': ' . self::check_text( 'seo', $seo ) ) . '</h4>';
            $failing = [];
            foreach ( (array) ( $seo['review']['checks'] ?? [] ) as $c ) {
                if ( 'fail' === ( $c['status'] ?? '' ) && '' !== (string) ( $c['label'] ?? '' ) ) {
                    $failing[] = (string) $c['label'];
                }
            }
            $lines = [];
            if ( $failing ) {
                $lines[] = esc_html__( 'Failing:', 'presshub-ai-editor' ) . ' ' . esc_html( implode( ', ', $failing ) );
            }
            if ( ! empty( $seo['fixed'] ) ) {
                $lines[] = '<span class="description">' . esc_html__( 'The missing excerpt or tags were filled in automatically.', 'presshub-ai-editor' ) . '</span>';
            }
            if ( $lines ) {
                $html .= '<p>' . implode( '<br />', $lines ) . '</p>';
            }
        }

        if ( $held && PressHub_AI_QA_Reviews_Admin::can_override( $post_id ) ) {
            $html .= '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( PressHub_AI_QA_Reviews_Admin::override_url( $post_id ) ) . '">' . esc_html__( 'Publish anyway', 'presshub-ai-editor' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'presshub-ai-editor' ) . '</span></a> <span class="description">' . esc_html__( 'Opens in a new tab, so nothing here is lost. Or fix the post and publish again to have it reviewed again.', 'presshub-ai-editor' ) . '</span></p>';
        }

        // History.
        $rows = class_exists( 'PressHub_AI_QA_Review_Store' ) ? PressHub_AI_QA_Review_Store::for_post( $post_id, self::METABOX_HISTORY ) : [];
        if ( $rows ) {
            $total    = PressHub_AI_QA_Review_Store::count( [ 'post_id' => $post_id ] );
            $verdicts = [
                'pass'  => __( 'Passed', 'presshub-ai-editor' ),
                'alert' => __( 'Alert', 'presshub-ai-editor' ),
                'hold'  => __( 'Held', 'presshub-ai-editor' ),
                'error' => __( 'Could not run', 'presshub-ai-editor' ),
            ];
            $html .= '<details class="presshub-qa-history"><summary>' . esc_html( sprintf( /* translators: %d: number of reviews. */ __( 'Review history (%d)', 'presshub-ai-editor' ), max( $total, count( $rows ) ) ) ) . '</summary><ul>';
            foreach ( $rows as $row ) {
                $row_verdict = (string) ( $row['verdict'] ?? '' );
                $item        = self::format_local( (string) ( $row['created_at'] ?? '' ) ) . ' · ' . ( $verdicts[ $row_verdict ] ?? $row_verdict ) . ' · ' . PressHub_AI_QA_Reviews_Admin::trigger_label( (string) ( $row['trigger_type'] ?? '' ) );
                $html       .= '<li>' . esc_html( $item );
                if ( ! empty( $row['override_at'] ) ) {
                    $html .= '<br /><em>' . esc_html( sprintf( /* translators: %s: reason. */ __( 'Published anyway: %s', 'presshub-ai-editor' ), (string) ( $row['override_reason'] ?? '' ) ) ) . '</em>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
            if ( PressHub_Capabilities::current_user_can_manage() ) {
                $html .= '<a href="' . esc_url( PressHub_Admin_Menu::url( PressHub_AI_QA_Reviews_Admin::PAGE_SLUG, [ 'post_id' => $post_id ] ) ) . '">' . esc_html__( 'All reviews of this post', 'presshub-ai-editor' ) . '</a>';
            }
            $html .= '</details>';
        }

        return $html . '</div>';
    }
}
