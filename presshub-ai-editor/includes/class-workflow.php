<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-settings-storage.php';
require_once __DIR__ . '/class-qa-reviewer.php';

class PressHub_AI_Workflow {
    /**
     * Re-entrancy guard for enforce_editorial_workflow().
     *
     * wp_update_post() fires transition_post_status again, which would loop
     * indefinitely without this flag. static means the guard is shared across
     * every instance in the same request — important because WordPress
     * instantiates the plugin class once but the guard must hold for any
     * future instantiation in the same request lifecycle.
     */
    private static $enforcing = false;

    public function __construct() {
        // Intercept transitions to ensure authors cannot publish without review
        add_action( 'transition_post_status', [ $this, 'enforce_editorial_workflow' ], 10, 3 );
    }

    public function enforce_editorial_workflow( $new_status, $old_status, $post ) {
        // The editorial gate only applies to regular posts (Antigravity
        // C-5). Research posts and other CPTs are never routed through the
        // publish gate — they are written by cron/headless flows where
        // current_user_can() is unreliable (user 0), and reverting them
        // would corrupt non-article content. Real WP always passes a full
        // WP_Post here; bail defensively when post_type is missing.
        if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'post' !== $post->post_type ) {
            return;
        }

        // Automated Daily Briefing Hub posts (text briefing stories & audio podcasts)
        // are governed by their own settings (presshub_ai_briefing_text_status /
        // presshub_ai_briefing_podcast_status), not by the co-pilot author scorecard gate.
        if ( ! empty( get_post_meta( $post->ID, '_presshub_briefing_type', true ) ) ) {
            return;
        }

        // Cron and headless flows run without a logged-in user (user 0) where
        // current_user_can() is unreliable. Never block scheduled/cron publishing.
        if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }

        // Bail out if we are already enforcing to prevent infinite recursion
        // when wp_update_post() fires transition_post_status again below.
        if ( self::$enforcing ) {
            return;
        }

        // If an author tries to publish directly, block it if it hasn't passed QA review
        if ( 'publish' === $new_status && 'publish' !== $old_status ) {
            // Allow editors/admins to bypass, but not authors
            if ( ! current_user_can( 'edit_others_posts' ) ) {
                if ( ! PressHub_AI_Settings_Storage::get_qa_enabled() ) {
                    return;
                }

                $min_score = PressHub_AI_Settings_Storage::get_qa_min_score();
                $scorecard = get_post_meta( $post->ID, '_presshub_ai_scorecard', true );

                $content_hash = md5( trim( (string) ( $post->post_content ?? '' ) ) );
                $cached_hash  = (string) get_post_meta( $post->ID, '_presshub_ai_scorecard_hash', true );

                $needs_eval = true;
                if ( is_array( $scorecard ) && isset( $scorecard['score'] ) ) {
                    if ( '' !== $cached_hash && $cached_hash === $content_hash ) {
                        $needs_eval = false;
                    } elseif ( '' === $cached_hash ) {
                        // Seeded or legacy meta without cached hash
                        $needs_eval = false;
                    }
                }

                $eval_result = null;
                if ( $needs_eval && class_exists( 'PressHub_AI_QA_Reviewer' ) ) {
                    $eval_result = PressHub_AI_QA_Reviewer::evaluate_post( $post );
                    $passed      = (bool) ( $eval_result['passed'] ?? false );
                } else {
                    $score       = isset( $scorecard['score'] ) ? (int) $scorecard['score'] : 0;
                    $passed      = ( $score >= $min_score );
                    $eval_result = [
                        'passed'   => $passed,
                        'score'    => $score,
                        'feedback' => (string) ( $scorecard['feedback'] ?? '' ),
                        'error'    => null,
                    ];
                }

                if ( ! $passed ) {
                    self::$enforcing = true;
                    try {
                        // Revert to pending
                        wp_update_post( [
                            'ID'          => $post->ID,
                            'post_status' => 'pending',
                        ] );

                        if ( class_exists( 'PressHub_AI_QA_Reviewer' ) ) {
                            PressHub_AI_QA_Reviewer::notify_editor_failure( $post->ID, $eval_result, 'post' );
                        }
                    } finally {
                        self::$enforcing = false;
                    }
                }
            }
        }
    }
}
