<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Workflow {
    public function __construct() {
        // Intercept transitions to ensure authors cannot publish without review
        add_action( 'transition_post_status', [ $this, 'enforce_editorial_workflow' ], 10, 3 );
    }

    public function enforce_editorial_workflow( $new_status, $old_status, $post ) {
        // If an author tries to publish directly, block it if it hasn't been reviewed
        if ( 'publish' === $new_status && 'publish' !== $old_status ) {
            $scorecard = get_post_meta( $post->ID, '_presshub_ai_scorecard', true );
            
            // Allow editors/admins to bypass, but not authors
            if ( ! current_user_can( 'edit_others_posts' ) ) {
                if ( empty( $scorecard ) || ! isset( $scorecard['score'] ) || intval( $scorecard['score'] ) < 80 ) {
                    // Revert to pending
                    wp_update_post( [
                        'ID' => $post->ID,
                        'post_status' => 'pending'
                    ] );
                }
            }
        }
    }
}
