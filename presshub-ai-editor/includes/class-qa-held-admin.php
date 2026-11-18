<?php
/**
 * PressHub_AI_QA_Held_Admin — where held posts are visible without email.
 *
 *   - PressHub → Held by QA: every post the QA gate is keeping in Pending
 *     Review, with the failed checks and a "Publish anyway" link.
 *   - An admin notice for editors and admins on the dashboard, the Posts
 *     list and the PressHub pages while any post is held.
 *   - A "QA" column in the Posts list with one badge per check (editorial,
 *     legal, SEO) from the latest review.
 *   - A QA gate panel in the editor's PressHub box: the three verdicts in
 *     detail, recent reviews and "Publish anyway" while held.
 *
 * A post is held when it is pending, carries the status it was going to
 * (PressHub_AI_Publish_Gate::META_TARGET_STATUS) and its latest review
 * holds it (hold or error).
 *
 * @package PressHub_AI_Editor
 * @since 2.49.0
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

    /** Most held posts listed (and counted for the notice). */
    const MAX_HELD = 200;

    /** Admin screens that show the "posts held" notice. */
    const NOTICE_SCREENS = [ 'dashboard', 'edit-post' ];

    /** Short badge letters per check. */
    const CHECK_LETTERS = [ 'editorial' => 'E', 'legal' => 'L', 'seo' => 'S' ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_notices', [ $this, 'render_notice' ] );
        add_filter( 'manage_post_posts_columns', [ __CLASS__, 'add_column' ] );
        add_action( 'manage_post_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
        add_action( 'admin_head-edit.php', [ __CLASS__, 'print_badge_css' ] );
        add_action( 'admin_head-post.php', [ __CLASS__, 'print_badge_css' ] );
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

    /**
     * Posts the QA gate is holding, newest first.
     *
     * @return array<int, array{post: object, review: array}>
     */
    public static function held_posts( int $limit = self::MAX_HELD ): array {
        if ( ! function_exists( 'get_posts' ) ) {
            return [];
        }
        $posts = get_posts( [
            'post_type'        => 'post',
            'post_status'      => 'pending',
            'posts_per_page'   => max( 1, min( self::MAX_HELD, $limit ) ),
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'meta_key'         => PressHub_AI_Publish_Gate::META_TARGET_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- small, indexed by post status.
            'suppress_filters' => false,
        ] );
        $out = [];
        foreach ( (array) $posts as $post ) {
            if ( ! is_object( $post ) || 'pending' !== ( $post->post_status ?? '' ) ) {
                continue;
            }
            $review = get_post_meta( (int) $post->ID, PressHub_AI_Publish_Gate::META_REVIEW, true );
            if ( is_array( $review ) && PressHub_AI_Publish_Gate::holds( $review ) ) {
                $out[] = [ 'post' => $post, 'review' => $review ];
            }
        }
        return $out;
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

    /** One line naming what failed: "editorial 50/80, legal High". */
    public static function failed_summary( array $review ): string {
        $checks = (array) ( $review['checks'] ?? [] );
        $parts  = [];
        if ( 'error' === ( $review['verdict'] ?? '' ) ) {
            $parts[] = __( 'review could not run', 'presshub-ai-editor' );
        }
        $ed = (array) ( $checks['editorial'] ?? [] );
        if ( 'fail' === ( $ed['result'] ?? '' ) ) {
            $parts[] = sprintf( /* translators: 1: score, 2: minimum. */ __( 'editorial %1$d/%2$d', 'presshub-ai-editor' ), (int) ( $ed['score'] ?? 0 ), (int) ( $ed['threshold'] ?? 0 ) );
        }
        $lg = (array) ( $checks['legal'] ?? [] );
        if ( 'fail' === ( $lg['result'] ?? '' ) ) {
            $parts[] = sprintf( /* translators: %s: Low, Medium or High. */ __( 'legal %s', 'presshub-ai-editor' ), (string) ( $lg['risk'] ?? '' ) );
        }
        $seo = (array) ( $checks['seo'] ?? [] );
        if ( 'fail' === ( $seo['result'] ?? '' ) ) {
            $parts[] = sprintf( /* translators: 1: score, 2: minimum. */ __( 'SEO %1$d/%2$d', 'presshub-ai-editor' ), (int) ( $seo['score'] ?? 0 ), (int) ( $seo['threshold'] ?? 0 ) );
        }
        return implode( ', ', $parts );
    }

    public function render_page(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to view held posts.', 'presshub-ai-editor' ) );
        }
        $held = self::held_posts();
        echo '<div class="wrap presshub-qa-held-wrap" id="presshub-qa-held-page">';
        echo '<h1>' . esc_html( PressHub_Admin_Menu::page_title( __( 'Held by QA', 'presshub-ai-editor' ) ) ) . '</h1>';
        echo '<p>' . esc_html__( 'Posts the QA gate is keeping in Pending Review. Edit a post and publish it again to have it reviewed again, or release it as it is with Publish anyway and a reason.', 'presshub-ai-editor' ) . '</p>';
        if ( empty( $held ) ) {
            echo '<p>' . esc_html__( 'No posts are held by the QA gate.', 'presshub-ai-editor' ) . '</p></div>';
            return;
        }
        echo self::render_table( $held ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_table().
        echo '</div>';
    }

    public static function render_table( array $held ): string {
        $html = '<table class="widefat striped presshub-qa-held-table"><thead><tr>';
        foreach ( [
            __( 'Post', 'presshub-ai-editor' ),
            __( 'Was going to', 'presshub-ai-editor' ),
            __( 'Reviewed', 'presshub-ai-editor' ),
            __( 'QA', 'presshub-ai-editor' ),
            __( 'What failed', 'presshub-ai-editor' ),
            __( 'Actions', 'presshub-ai-editor' ),
        ] as $heading ) {
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
            $targets = [
                'publish' => __( 'Publish', 'presshub-ai-editor' ),
                'future'  => __( 'Scheduled', 'presshub-ai-editor' ),
                'private' => __( 'Private', 'presshub-ai-editor' ),
            ];
            $actions = [];
            if ( '' !== $edit ) {
                $actions[] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'presshub-ai-editor' ) . '</a>';
            }
            $actions[] = '<a href="' . esc_url( PressHub_Admin_Menu::url( PressHub_AI_QA_Reviews_Admin::PAGE_SLUG, [ 'post_id' => $post_id ] ) ) . '">' . esc_html__( 'Reviews', 'presshub-ai-editor' ) . '</a>';
            if ( PressHub_AI_QA_Reviews_Admin::can_override( $post_id ) ) {
                $actions[] = '<a class="button button-small" href="' . esc_url( PressHub_AI_QA_Reviews_Admin::override_url( $post_id ) ) . '">' . esc_html__( 'Publish anyway', 'presshub-ai-editor' ) . '</a>';
            }

            $html .= '<tr>';
            $html .= '<td>' . ( '' !== $edit ? '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $title ) . '</strong></a>' : '<strong>' . esc_html( $title ) . '</strong>' ) . '</td>';
            $html .= '<td>' . esc_html( $targets[ $target ] ?? $target ) . '</td>';
            $html .= '<td>' . esc_html( PressHub_AI_QA_Reviews_Admin::trigger_label( (string) ( $review['trigger'] ?? '' ) ) ) . '<br /><span class="description">' . esc_html( (string) ( $review['reviewed_at'] ?? '' ) ) . '</span></td>';
            $html .= '<td>' . self::badges( $review ) . '</td>';
            $html .= '<td>' . esc_html( self::failed_summary( $review ) ) . '</td>';
            $html .= '<td>' . implode( ' · ', $actions ) . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** Admin notice while posts are held (editors and admins, on the dashboard, Posts list and PressHub pages). */
    public function render_notice(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() || ! self::notice_screen() ) {
            return;
        }
        $count = count( self::held_posts() );
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

    private static function notice_screen(): bool {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }
        $id = (string) ( $screen->id ?? '' );
        // The Held page itself already lists them.
        if ( false !== strpos( $id, self::PAGE_SLUG ) ) {
            return false;
        }
        return in_array( $id, self::NOTICE_SCREENS, true ) || false !== strpos( $id, 'presshub' );
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
        echo is_array( $review ) ? self::badges( $review ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not reviewed', 'presshub-ai-editor' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badges().
    }

    /** Three small badges: E, L, S, coloured by result, with the score or risk in the tooltip. */
    public static function badges( array $review ): string {
        $checks = (array) ( $review['checks'] ?? [] );
        $words  = [
            'pass'  => __( 'passed', 'presshub-ai-editor' ),
            'fail'  => __( 'failed', 'presshub-ai-editor' ),
            'skip'  => __( 'not checked', 'presshub-ai-editor' ),
            'error' => __( 'could not run', 'presshub-ai-editor' ),
        ];
        $names  = [
            'editorial' => __( 'Editorial', 'presshub-ai-editor' ),
            'legal'     => __( 'Legal', 'presshub-ai-editor' ),
            'seo'       => __( 'SEO', 'presshub-ai-editor' ),
        ];
        $html = '<span class="presshub-qa-badges">';
        foreach ( self::CHECK_LETTERS as $key => $letter ) {
            $check  = (array) ( $checks[ $key ] ?? [] );
            $result = (string) ( $check['result'] ?? 'skip' );
            $result = isset( $words[ $result ] ) ? $result : 'skip';
            $detail = '';
            if ( isset( $check['score'] ) && null !== $check['score'] ) {
                $detail = ' ' . (int) $check['score'] . '/' . (int) ( $check['threshold'] ?? 0 );
            } elseif ( 'legal' === $key && ! empty( $check['risk'] ) ) {
                $detail = ' ' . (string) $check['risk'];
            }
            $label = $names[ $key ] . ': ' . $words[ $result ] . $detail;
            $html .= '<span class="presshub-qa-badge presshub-qa-badge-' . esc_attr( $result ) . '" title="' . esc_attr( $label ) . '"><span aria-hidden="true">' . esc_html( $letter ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
        }
        return $html . '</span>';
    }

    // ------------------------------------------------------------------
    // Editor metabox panel
    // ------------------------------------------------------------------

    /** Reviews listed in the editor before the link to the full history. */
    const METABOX_HISTORY = 5;

    /**
     * The QA gate panel in the PressHub metabox: the latest verdict with
     * the three checks in detail, the last few reviews, and "Publish
     * anyway" while the post is held. Empty when the post was never
     * reviewed by the gate.
     */
    public static function metabox_html( int $post_id ): string {
        $review = $post_id > 0 ? get_post_meta( $post_id, PressHub_AI_Publish_Gate::META_REVIEW, true ) : null;
        if ( ! is_array( $review ) || empty( $review['checks'] ) ) {
            return '';
        }
        $checks   = (array) $review['checks'];
        $held     = PressHub_AI_QA_Reviews_Admin::is_held( $post_id );
        $verdicts = [
            'pass'  => __( 'Passed', 'presshub-ai-editor' ),
            'alert' => __( 'Published with an alert', 'presshub-ai-editor' ),
            'hold'  => __( 'Held for review', 'presshub-ai-editor' ),
            'error' => __( 'Review could not run', 'presshub-ai-editor' ),
        ];
        $verdict = (string) ( $review['verdict'] ?? '' );
        if ( 'alert' === $verdict && $held ) {
            $verdict_text = $verdicts['hold'];
        } else {
            $verdict_text = $verdicts[ $verdict ] ?? $verdict;
        }

        $html  = '<div class="presshub-qa-gate-panel" id="presshub-qa-gate-panel">';
        $html .= '<h3>' . esc_html__( 'QA gate', 'presshub-ai-editor' ) . '</h3>';
        $html .= '<p>' . self::badges( $review ) . ' <strong>' . esc_html( $verdict_text ) . '</strong>';
        $html .= '<br /><span class="description">' . esc_html( PressHub_AI_QA_Reviews_Admin::trigger_label( (string) ( $review['trigger'] ?? '' ) ) . ' · ' . (string) ( $review['reviewed_at'] ?? '' ) ) . '</span></p>';
        if ( PressHub_AI_Publish_Gate::is_overridden( $post_id ) ) {
            $html .= '<p class="description">' . esc_html__( 'Released with Publish anyway. The reason is in the review history.', 'presshub-ai-editor' ) . '</p>';
        }

        // Editorial.
        $ed = (array) ( $checks['editorial'] ?? [] );
        if ( 'skip' !== ( $ed['result'] ?? 'skip' ) ) {
            $line = isset( $ed['score'] ) && null !== $ed['score']
                ? sprintf( /* translators: 1: score, 2: minimum. */ __( 'Editorial: %1$d/100 (minimum %2$d)', 'presshub-ai-editor' ), (int) $ed['score'], (int) ( $ed['threshold'] ?? 0 ) )
                : __( 'Editorial: could not run', 'presshub-ai-editor' );
            $html .= '<p><strong>' . esc_html( $line ) . '</strong>';
            if ( '' !== (string) ( $ed['feedback'] ?? '' ) ) {
                $html .= '<br />' . esc_html( (string) $ed['feedback'] );
            }
            $html .= '</p>';
        }

        // Legal.
        $lg = (array) ( $checks['legal'] ?? [] );
        if ( 'skip' !== ( $lg['result'] ?? 'skip' ) ) {
            $line = 'error' === ( $lg['result'] ?? '' )
                ? __( 'Legal: could not run', 'presshub-ai-editor' )
                : sprintf( /* translators: %s: Low, Medium or High. */ __( 'Legal: %s risk', 'presshub-ai-editor' ), (string) ( $lg['risk'] ?? '' ) );
            $html .= '<p><strong>' . esc_html( $line ) . '</strong>';
            $concerns = trim( (string) ( $lg['concerns'] ?? '' ) );
            if ( '' !== $concerns && 'None identified' !== $concerns ) {
                $html .= '<br />' . esc_html( $concerns );
            }
            $html .= '</p>';
            $issues = (array) ( $lg['issues'] ?? [] );
            if ( $issues ) {
                $html .= '<ul class="presshub-qa-legal-issues">';
                foreach ( $issues as $issue ) {
                    $html .= '<li><em>' . esc_html( (string) ( $issue['severity'] ?? '' ) ) . '</em>';
                    if ( '' !== (string) ( $issue['quote'] ?? '' ) ) {
                        $html .= ' “' . esc_html( (string) $issue['quote'] ) . '”';
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
            $line = 'error' === ( $seo['result'] ?? '' )
                ? __( 'SEO: could not run', 'presshub-ai-editor' )
                : sprintf( /* translators: 1: score, 2: minimum. */ __( 'SEO: %1$d/100 (minimum %2$d)', 'presshub-ai-editor' ), (int) ( $seo['score'] ?? 0 ), (int) ( $seo['threshold'] ?? 0 ) );
            $html .= '<p><strong>' . esc_html( $line ) . '</strong>';
            $failing = [];
            foreach ( (array) ( $seo['review']['checks'] ?? [] ) as $c ) {
                if ( 'fail' === ( $c['status'] ?? '' ) && '' !== (string) ( $c['label'] ?? '' ) ) {
                    $failing[] = (string) $c['label'];
                }
            }
            if ( $failing ) {
                $html .= '<br />' . esc_html__( 'Failing:', 'presshub-ai-editor' ) . ' ' . esc_html( implode( ', ', $failing ) );
            }
            if ( ! empty( $seo['fixed'] ) ) {
                $html .= '<br /><span class="description">' . esc_html__( 'The missing excerpt or tags were filled in automatically.', 'presshub-ai-editor' ) . '</span>';
            }
            $html .= '</p>';
        }

        if ( $held && PressHub_AI_QA_Reviews_Admin::can_override( $post_id ) ) {
            $html .= '<p><a class="button" href="' . esc_url( PressHub_AI_QA_Reviews_Admin::override_url( $post_id ) ) . '">' . esc_html__( 'Publish anyway', 'presshub-ai-editor' ) . '</a> <span class="description">' . esc_html__( 'Or fix the post and publish again to have it reviewed again.', 'presshub-ai-editor' ) . '</span></p>';
        }

        // History.
        $rows = class_exists( 'PressHub_AI_QA_Review_Store' ) ? PressHub_AI_QA_Review_Store::for_post( $post_id, self::METABOX_HISTORY ) : [];
        if ( $rows ) {
            $html .= '<details class="presshub-qa-history"><summary>' . esc_html__( 'Review history', 'presshub-ai-editor' ) . '</summary><ul>';
            foreach ( $rows as $row ) {
                $row_verdict = (string) ( $row['verdict'] ?? '' );
                $item        = (string) ( $row['created_at'] ?? '' ) . ' · ' . ( $verdicts[ $row_verdict ] ?? $row_verdict ) . ' · ' . PressHub_AI_QA_Reviews_Admin::trigger_label( (string) ( $row['trigger_type'] ?? '' ) );
                $html       .= '<li>' . esc_html( $item );
                if ( ! empty( $row['override_at'] ) ) {
                    $html .= '<br /><em>' . esc_html( sprintf( /* translators: %s: reason. */ __( 'Published anyway: %s', 'presshub-ai-editor' ), (string) ( $row['override_reason'] ?? '' ) ) ) . '</em>';
                }
                $html .= '</li>';
            }
            $html .= '</ul><a href="' . esc_url( PressHub_Admin_Menu::url( PressHub_AI_QA_Reviews_Admin::PAGE_SLUG, [ 'post_id' => $post_id ] ) ) . '">' . esc_html__( 'All reviews of this post', 'presshub-ai-editor' ) . '</a></details>';
        }

        return $html . '</div><hr />';
    }

    public static function print_badge_css(): void {
        echo '<style id="presshub-qa-badge-css">'
            . '.presshub-qa-badges{display:inline-flex;gap:3px}'
            . '.presshub-qa-badge{display:inline-block;min-width:18px;padding:1px 4px;border-radius:3px;font-size:11px;font-weight:600;line-height:16px;text-align:center;border:1px solid #c3c4c7;background:#f6f7f7;color:#50575e}'
            . '.presshub-qa-badge-pass{background:#edfaef;border-color:#68de7c;color:#00450c}'
            . '.presshub-qa-badge-fail{background:#fcf0f1;border-color:#f86368;color:#8a2424}'
            . '.presshub-qa-badge-error{background:#fcf9e8;border-color:#f0c33c;color:#614200}'
            . '.column-presshub_qa{width:84px}'
            . '</style>';
    }
}
