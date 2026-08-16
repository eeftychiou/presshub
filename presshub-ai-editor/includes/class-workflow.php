<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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

        // Bail out if we are already enforcing to prevent infinite recursion
        // when wp_update_post() fires transition_post_status again below.
        if ( self::$enforcing ) {
            return;
        }

        // If an author tries to publish directly, block it if it hasn't been reviewed
        if ( 'publish' === $new_status && 'publish' !== $old_status ) {
            $scorecard = get_post_meta( $post->ID, '_presshub_ai_scorecard', true );

            // Allow editors/admins to bypass, but not authors
            if ( ! current_user_can( 'edit_others_posts' ) ) {
                if ( empty( $scorecard ) || ! isset( $scorecard['score'] ) || intval( $scorecard['score'] ) < 80 ) {
                    self::$enforcing = true;
                    try {
                        // Revert to pending
                        wp_update_post( [
                            'ID' => $post->ID,
                            'post_status' => 'pending'
                        ] );
                    } finally {
                        self::$enforcing = false;
                    }
                }
            }
        }
    }
}
