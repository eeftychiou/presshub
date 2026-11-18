<?php
/**
 * PressHub → QA reviews: every saved QA gate review, newest first.
 *
 * Editors and admins (PressHub_Capabilities::manage()) see each review's
 * verdict and, per check, the score, threshold and full feedback: the
 * editorial critique, each legal issue with its quote and fix, and the SEO
 * checks and suggestions. Filter by verdict, failed check or post.
 *
 * A held post can be released here with "Publish anyway" and a reason
 * (PressHub_AI_Publish_Gate::publish_anyway()), which is written on the
 * review row and in the audit log as qa_gate_override.
 *
 * @package PressHub_AI_Editor
 * @since 2.47.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-capabilities.php';
require_once __DIR__ . '/class-admin-menu.php';
require_once __DIR__ . '/class-qa-review-store.php';
require_once __DIR__ . '/class-publish-gate.php';

class PressHub_AI_QA_Reviews_Admin {

    const PAGE_SLUG = 'presshub-ai-qa-reviews';

    /** Reviews per page. */
    const PER_PAGE = 50;

    /** admin-post.php action of the "Publish anyway" form. */
    const OVERRIDE_ACTION = 'presshub_ai_qa_publish_anyway';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_post_' . self::OVERRIDE_ACTION, [ $this, 'handle_publish_anyway' ] );
    }

    /** The "Publish anyway" form for a post (linked from the alert email and the table). */
    public static function override_url( int $post_id ): string {
        return PressHub_Admin_Menu::url( self::PAGE_SLUG, [ 'override' => $post_id ] );
    }

    /** Whether the current user may release this post with "Publish anyway". */
    public static function can_override( int $post_id ): bool {
        return $post_id > 0
            && PressHub_Capabilities::current_user_can_manage()
            && current_user_can( 'edit_post', $post_id )
            && current_user_can( 'edit_others_posts' );
    }

    /** Whether the post is waiting in Pending Review because of the gate. */
    public static function is_held( int $post_id ): bool {
        $post = $post_id > 0 ? get_post( $post_id ) : null;
        if ( ! $post || 'pending' !== ( $post->post_status ?? '' ) ) {
            return false;
        }
        $review = get_post_meta( $post_id, PressHub_AI_Publish_Gate::META_REVIEW, true );
        return is_array( $review ) && PressHub_AI_Publish_Gate::holds( $review );
    }

    public function handle_publish_anyway(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below.
        check_admin_referer( self::OVERRIDE_ACTION . '_' . $post_id );
        if ( ! self::can_override( $post_id ) ) {
            wp_die( esc_html__( 'You do not have permission to publish this post.', 'presshub-ai-editor' ) );
        }
        $reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] ) ? (string) wp_unslash( $_POST['reason'] ) : '';
        $result = PressHub_AI_Publish_Gate::publish_anyway( $post_id, $reason );
        $args   = is_wp_error( $result )
            ? [ 'override' => $post_id, 'override_error' => $result->get_error_code() ]
            : [ 'post_id' => $post_id, 'overridden' => 1 ];
        wp_safe_redirect( PressHub_Admin_Menu::url( self::PAGE_SLUG, $args ) );
        exit;
    }

    private static function render_override_form( int $post_id, string $error ): void {
        $post = get_post( $post_id );
        if ( ! $post || ! self::can_override( $post_id ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'You cannot publish that post.', 'presshub-ai-editor' ) . '</p></div>';
            return;
        }
        echo '<div class="presshub-qa-override card">';
        echo '<h2>' . esc_html( sprintf( /* translators: %s: post title. */ __( 'Publish anyway: %s', 'presshub-ai-editor' ), (string) get_the_title( $post_id ) ) ) . '</h2>';
        if ( ! self::is_held( $post_id ) ) {
            echo '<p>' . esc_html__( 'This post is not held by the QA gate.', 'presshub-ai-editor' ) . '</p></div>';
            return;
        }
        $errors = [
            'presshub_qa_no_reason'  => __( 'Say why the post is published despite the QA gate.', 'presshub-ai-editor' ),
            'presshub_qa_changed'    => __( 'The post changed after the QA gate held it. Publish it again so the gate reviews the new version.', 'presshub-ai-editor' ),
            'presshub_qa_not_held'   => __( 'This post is not held by the QA gate.', 'presshub-ai-editor' ),
        ];
        if ( isset( $errors[ $error ] ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
        }
        echo '<p>' . esc_html__( 'The post is published as it is, without another review. Your name, the reason and the failed checks are saved on its QA review and in the audit log. If the post is edited afterwards, the gate checks it again.', 'presshub-ai-editor' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::OVERRIDE_ACTION ) . '" />';
        echo '<input type="hidden" name="post_id" value="' . (int) $post_id . '" />';
        wp_nonce_field( self::OVERRIDE_ACTION . '_' . $post_id );
        echo '<p><label for="presshub-qa-override-reason">' . esc_html__( 'Reason', 'presshub-ai-editor' ) . '</label><br />';
        echo '<textarea name="reason" id="presshub-qa-override-reason" rows="3" cols="60" required="required"></textarea></p>';
        submit_button( __( 'Publish anyway', 'presshub-ai-editor' ), 'primary', 'submit', false );
        $back = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post_id ) : '';
        if ( '' !== $back ) {
            echo ' <a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel, back to the post', 'presshub-ai-editor' ) . '</a>';
        }
        echo '</form></div>';
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            PressHub_Admin_Menu::PARENT,
            PressHub_Admin_Menu::page_title( __( 'QA reviews', 'presshub-ai-editor' ) ),
            __( 'QA reviews', 'presshub-ai-editor' ),
            PressHub_Capabilities::manage(),
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public static function verdict_label( string $verdict ): string {
        $labels = [
            'pass'  => __( 'Passed', 'presshub-ai-editor' ),
            'alert' => __( 'Passed with alerts', 'presshub-ai-editor' ),
            'hold'  => __( 'Held', 'presshub-ai-editor' ),
            'error' => __( 'Review failed to run', 'presshub-ai-editor' ),
        ];
        return $labels[ $verdict ] ?? $verdict;
    }

    public static function trigger_label( string $trigger ): string {
        $labels = [
            'author_publish'   => __( 'Author published', 'presshub-ai-editor' ),
            'review_button'    => __( 'Review button', 'presshub-ai-editor' ),
            'briefing_morning' => __( 'Morning briefing', 'presshub-ai-editor' ),
            'briefing_noon'    => __( 'Noon briefing', 'presshub-ai-editor' ),
            'editor_publish'   => __( 'Editor published', 'presshub-ai-editor' ),
            'author_schedule'  => __( 'Author scheduled', 'presshub-ai-editor' ),
            'editor_schedule'  => __( 'Editor scheduled', 'presshub-ai-editor' ),
            'scheduled_publish' => __( 'Scheduled post went live', 'presshub-ai-editor' ),
            'podcast_morning'  => __( 'Morning podcast', 'presshub-ai-editor' ),
            'podcast_noon'     => __( 'Noon podcast', 'presshub-ai-editor' ),
            'trending_roundup' => __( 'Trending roundup', 'presshub-ai-editor' ),
        ];
        return $labels[ $trigger ] ?? ( '' === $trigger ? '—' : str_replace( '_', ' ', $trigger ) );
    }

    public function render_page(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to view QA reviews.', 'presshub-ai-editor' ) );
        }
        // Read-only filters on a GET screen; no state changes.
        $filters = PressHub_AI_QA_Review_Store::sanitize_filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $total   = PressHub_AI_QA_Review_Store::count( $filters );
        $rows    = PressHub_AI_QA_Review_Store::query( $filters, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

        echo '<div class="wrap presshub-qa-reviews-wrap" id="presshub-qa-reviews-page">';
        echo '<h1>' . esc_html( PressHub_Admin_Menu::page_title( __( 'QA reviews', 'presshub-ai-editor' ) ) ) . '</h1>';
        echo '<p>' . esc_html__( 'Every review the QA gate made before a post was published: the editorial score, the legal risk and the SEO score, each with the feedback the reviewer gave. Thresholds are set in Settings → Writing & QA.', 'presshub-ai-editor' ) . '</p>';

        $override = (int) ( $_GET['override'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $override > 0 ) {
            self::render_override_form( $override, sanitize_key( (string) ( $_GET['override_error'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        if ( ! empty( $_GET['overridden'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success"><p>' . esc_html__( 'The post was published. The override is saved on its QA review and in the audit log.', 'presshub-ai-editor' ) . '</p></div>';
        }

        self::render_filters( $filters );

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No QA reviews match these filters yet.', 'presshub-ai-editor' ) . '</p></div>';
            return;
        }

        echo '<table class="widefat striped presshub-qa-reviews-table"><thead><tr>';
        foreach ( [
            __( 'Date', 'presshub-ai-editor' ),
            __( 'Post', 'presshub-ai-editor' ),
            __( 'Trigger', 'presshub-ai-editor' ),
            __( 'Verdict', 'presshub-ai-editor' ),
            __( 'Editorial', 'presshub-ai-editor' ),
            __( 'Legal', 'presshub-ai-editor' ),
            __( 'SEO', 'presshub-ai-editor' ),
            __( 'Feedback', 'presshub-ai-editor' ),
        ] as $heading ) {
            echo '<th scope="col">' . esc_html( $heading ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo self::render_row( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_row().
        }
        echo '</tbody></table>';

        self::render_pagination( $total, $paged, $filters );
        echo '</div>';
    }

    private static function render_filters( array $filters ): void {
        echo '<form method="get" class="presshub-qa-reviews-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';

        echo '<label for="presshub-qa-verdict">' . esc_html__( 'Verdict', 'presshub-ai-editor' ) . '</label> ';
        echo '<select name="verdict" id="presshub-qa-verdict"><option value="">' . esc_html__( 'All', 'presshub-ai-editor' ) . '</option>';
        foreach ( PressHub_AI_QA_Review_Store::VERDICTS as $v ) {
            echo '<option value="' . esc_attr( $v ) . '"' . selected( $filters['verdict'] ?? '', $v, false ) . '>' . esc_html( self::verdict_label( $v ) ) . '</option>';
        }
        echo '</select> ';

        echo '<label for="presshub-qa-failed">' . esc_html__( 'Failed check', 'presshub-ai-editor' ) . '</label> ';
        echo '<select name="failed" id="presshub-qa-failed"><option value="">' . esc_html__( 'Any', 'presshub-ai-editor' ) . '</option>';
        $check_labels = [
            'editorial' => __( 'Editorial', 'presshub-ai-editor' ),
            'legal'     => __( 'Legal', 'presshub-ai-editor' ),
            'seo'       => __( 'SEO', 'presshub-ai-editor' ),
        ];
        foreach ( $check_labels as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $filters['failed'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select> ';

        echo '<label for="presshub-qa-post">' . esc_html__( 'Post ID', 'presshub-ai-editor' ) . '</label> ';
        echo '<input type="number" min="0" name="post_id" id="presshub-qa-post" class="small-text" value="' . esc_attr( (string) ( $filters['post_id'] ?? '' ) ) . '" /> ';
        submit_button( __( 'Filter', 'presshub-ai-editor' ), 'secondary', '', false );
        echo '</form>';
    }

    /** One table row with its details. */
    public static function render_row( array $row ): string {
        $d       = (array) ( $row['details'] ?? [] );
        $checks  = (array) ( $d['checks'] ?? [] );
        $post_id = (int) $row['post_id'];

        $post_cell = '—';
        if ( $post_id > 0 ) {
            $title     = function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '';
            $title     = '' !== $title ? $title : sprintf( /* translators: %d: post ID. */ __( 'Post #%d', 'presshub-ai-editor' ), $post_id );
            $edit      = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post_id ) : '';
            $post_cell = '' !== $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
            $post_cell .= ' <a class="presshub-qa-history-link" href="' . esc_url( PressHub_Admin_Menu::url( self::PAGE_SLUG, [ 'post_id' => $post_id ] ) ) . '">' . esc_html__( 'history', 'presshub-ai-editor' ) . '</a>';
            // The latest review of a post the gate is holding: offer the override.
            $latest = get_post_meta( $post_id, PressHub_AI_Publish_Gate::META_REVIEW, true );
            if ( is_array( $latest ) && (int) ( $latest['review_id'] ?? 0 ) === (int) $row['id'] && self::is_held( $post_id ) && self::can_override( $post_id ) ) {
                $post_cell .= '<br /><a class="button button-small presshub-qa-override-link" href="' . esc_url( self::override_url( $post_id ) ) . '">' . esc_html__( 'Publish anyway', 'presshub-ai-editor' ) . '</a>';
            }
        }

        $html  = '<tr class="presshub-qa-review-' . esc_attr( (string) $row['verdict'] ) . '">';
        $html .= '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
        $html .= '<td>' . $post_cell . '</td>';
        $html .= '<td>' . esc_html( self::trigger_label( (string) $row['trigger_type'] ) ) . '</td>';
        $html .= '<td><strong>' . esc_html( self::verdict_label( (string) $row['verdict'] ) ) . '</strong></td>';
        $html .= '<td>' . self::score_cell( (string) $row['editorial_result'], $row['editorial_score'], $row['editorial_threshold'] ) . '</td>';
        $legal = (string) $row['legal_result'];
        $html .= '<td>' . esc_html( self::result_word( $legal ) ) . ( '' !== (string) $row['legal_risk'] ? ': ' . esc_html( sprintf( /* translators: %s: Low, Medium or High. */ __( '%s risk', 'presshub-ai-editor' ), (string) $row['legal_risk'] ) ) : '' ) . '</td>';
        $html .= '<td>' . self::score_cell( (string) $row['seo_result'], $row['seo_score'], $row['seo_threshold'] ) . '</td>';
        $html .= '<td>' . self::details_html( $checks, $d, $row ) . '</td>';
        $html .= '</tr>';
        return $html;
    }

    private static function result_word( string $result ): string {
        $words = [
            'pass'  => __( 'Pass', 'presshub-ai-editor' ),
            'fail'  => __( 'Fail', 'presshub-ai-editor' ),
            'skip'  => __( 'Not checked', 'presshub-ai-editor' ),
            'error' => __( 'Error', 'presshub-ai-editor' ),
        ];
        return $words[ $result ] ?? $result;
    }

    private static function score_cell( string $result, $score, $threshold ): string {
        $text = self::result_word( $result );
        if ( null !== $score && '' !== (string) $score ) {
            $text .= ': ' . (int) $score . ' / ' . (int) $threshold;
        }
        return esc_html( $text );
    }

    private static function details_html( array $checks, array $d, array $row ): string {
        $parts = [];
        $ed    = (array) ( $checks['editorial'] ?? [] );
        if ( '' !== (string) ( $ed['feedback'] ?? '' ) ) {
            $parts[] = '<h4>' . esc_html__( 'Editorial', 'presshub-ai-editor' ) . '</h4><p>' . esc_html( (string) $ed['feedback'] ) . '</p>';
        }
        $lg = (array) ( $checks['legal'] ?? [] );
        if ( 'skip' !== ( $lg['result'] ?? 'skip' ) ) {
            $legal = '<h4>' . esc_html__( 'Legal', 'presshub-ai-editor' ) . '</h4>';
            if ( 'error' === ( $lg['result'] ?? '' ) ) {
                $legal .= '<p>' . esc_html__( 'The legal check could not run because the review failed.', 'presshub-ai-editor' ) . '</p>';
            }
            if ( '' !== (string) ( $lg['concerns'] ?? '' ) ) {
                $legal .= '<p>' . esc_html( (string) $lg['concerns'] ) . '</p>';
            }
            if ( ! empty( $lg['issues'] ) ) {
                $legal .= '<ol>';
                foreach ( (array) $lg['issues'] as $issue ) {
                    $legal .= '<li><strong>' . esc_html( (string) ( $issue['severity'] ?? '' ) . ', ' . str_replace( '_', ' ', (string) ( $issue['type'] ?? '' ) ) ) . '</strong>: <q>' . esc_html( (string) ( $issue['quote'] ?? '' ) ) . '</q><br />' . esc_html__( 'Fix:', 'presshub-ai-editor' ) . ' ' . esc_html( (string) ( $issue['fix'] ?? '' ) ) . '</li>';
                }
                $legal .= '</ol>';
            }
            $parts[] = $legal;
        }
        $seo = (array) ( $checks['seo'] ?? [] );
        if ( 'error' === ( $seo['result'] ?? '' ) ) {
            $parts[] = '<h4>' . esc_html__( 'SEO', 'presshub-ai-editor' ) . '</h4><p>' . esc_html__( 'The SEO check could not run because the review failed.', 'presshub-ai-editor' ) . '</p>';
        } elseif ( 'skip' !== ( $seo['result'] ?? 'skip' ) ) {
            $review = (array) ( $seo['review'] ?? [] );
            $html   = '<h4>' . esc_html__( 'SEO', 'presshub-ai-editor' ) . '</h4>';
            if ( ! empty( $seo['fixed'] ) ) {
                $html .= '<p>' . esc_html( sprintf( /* translators: %s: excerpt, tags. */ __( 'Filled in automatically: %s.', 'presshub-ai-editor' ), implode( ', ', array_map( 'strval', (array) $seo['fixed'] ) ) ) ) . '</p>';
            }
            if ( '' !== (string) ( $review['summary'] ?? '' ) ) {
                $html .= '<p>' . esc_html( (string) $review['summary'] ) . '</p>';
            }
            $items = [];
            foreach ( (array) ( $review['checks'] ?? [] ) as $c ) {
                if ( 'pass' !== ( $c['status'] ?? '' ) ) {
                    $items[] = esc_html( (string) ( $c['label'] ?? '' ) . ': ' . (string) ( $c['detail'] ?? '' ) );
                }
            }
            foreach ( (array) ( $review['suggestions'] ?? [] ) as $tip ) {
                $items[] = esc_html( (string) $tip );
            }
            if ( $items ) {
                $html .= '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
            }
            $parts[] = $html;
        }
        if ( ! empty( $row['override_reason'] ) ) {
            $parts[] = '<h4>' . esc_html__( 'Published anyway', 'presshub-ai-editor' ) . '</h4><p>' . esc_html( (string) $row['override_reason'] ) . '</p>';
        }
        if ( empty( $parts ) ) {
            return '—';
        }
        return '<details><summary>' . esc_html__( 'Show feedback', 'presshub-ai-editor' ) . '</summary>' . implode( '', $parts ) . '</details>';
    }

    private static function render_pagination( int $total, int $paged, array $filters ): void {
        $pages = (int) ceil( $total / self::PER_PAGE );
        if ( $pages <= 1 ) {
            return;
        }
        echo '<p class="presshub-qa-reviews-pages">';
        for ( $i = 1; $i <= $pages; $i++ ) {
            if ( $i === $paged ) {
                echo '<strong>' . (int) $i . '</strong> ';
            } else {
                echo '<a href="' . esc_url( PressHub_Admin_Menu::url( self::PAGE_SLUG, array_merge( $filters, [ 'paged' => $i ] ) ) ) . '">' . (int) $i . '</a> ';
            }
        }
        echo '</p>';
    }
}
