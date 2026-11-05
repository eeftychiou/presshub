<?php
/**
 * AJAX Handlers for PressHub Social Auto-Post.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Ajax_Handlers {

    /**
     * Register AJAX action hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'wp_ajax_presshub_social_generate_copy', [ __CLASS__, 'handle_generate_copy' ] );
        add_action( 'wp_ajax_presshub_social_enqueue_post', [ __CLASS__, 'handle_enqueue_post' ] );
        add_action( 'wp_ajax_presshub_social_test_connection', [ __CLASS__, 'handle_test_connection' ] );
        add_action( 'wp_ajax_presshub_social_retry_job', [ __CLASS__, 'handle_retry_job' ] );
        add_action( 'wp_ajax_presshub_social_cancel_job', [ __CLASS__, 'handle_cancel_job' ] );
        add_action( 'wp_ajax_presshub_social_dispatch_now', [ __CLASS__, 'handle_dispatch_now' ] );
        add_action( 'wp_ajax_presshub_social_regenerate_job_copy', [ __CLASS__, 'handle_regenerate_job_copy' ] );
        add_action( 'wp_ajax_presshub_social_update_job_copy', [ __CLASS__, 'handle_update_job_copy' ] );
        add_action( 'wp_ajax_presshub_social_repost_job', [ __CLASS__, 'handle_repost_job' ] );
    }

    /**
     * AJAX handler: Generate social copy with AI for a post.
     * Capability: edit_posts.
     *
     * @return void
     */
    public static function handle_generate_copy(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';

        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID provided.', 'presshub-social-autopost' ) ], 400 );
        }

        if ( ! class_exists( 'PressHub_Social_AI_Copywriter' ) ) {
            wp_send_json_error( [ 'message' => __( 'AI Copywriter module is unavailable.', 'presshub-social-autopost' ) ], 500 );
        }

        if ( ! empty( $platform ) ) {
            $copy = PressHub_Social_AI_Copywriter::generate_for_post( $post_id, $platform );
            if ( is_wp_error( $copy ) ) {
                wp_send_json_error( [ 'message' => $copy->get_error_message() ], 400 );
            }
            if ( '' !== trim( (string) $copy ) ) {
                update_post_meta( $post_id, '_presshub_social_copy_' . $platform, (string) $copy );
            }
            wp_send_json_success( [
                'post_id'  => $post_id,
                'platform' => $platform,
                'copy'     => $copy,
            ] );
        } else {
            $copies = PressHub_Social_AI_Copywriter::generate_all_for_post( $post_id );
            if ( is_wp_error( $copies ) ) {
                wp_send_json_error( [ 'message' => $copies->get_error_message() ], 400 );
            }
            if ( is_array( $copies ) ) {
                foreach ( $copies as $plat => $copy_text ) {
                    if ( '' !== trim( (string) $copy_text ) ) {
                        update_post_meta( $post_id, '_presshub_social_copy_' . sanitize_key( $plat ), (string) $copy_text );
                    }
                }
            }
            wp_send_json_success( [
                'post_id' => $post_id,
                'copy'    => $copies,
            ] );
        }
    }

    /**
     * AJAX handler: Manually enqueue social posts for a given post ID from the editor metabox.
     * Capability: edit_posts.
     *
     * @return void
     */
    public static function handle_enqueue_post(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID provided.', 'presshub-social-autopost' ) ], 400 );
        }

        // Persist selected platforms if passed from metabox
        $platforms = [];
        if ( isset( $_POST['platforms'] ) && is_array( $_POST['platforms'] ) ) {
            foreach ( $_POST['platforms'] as $p ) {
                $sanitized = sanitize_key( wp_unslash( $p ) );
                if ( in_array( $sanitized, PressHub_Social_Queue::VALID_PLATFORMS, true ) ) {
                    $platforms[] = $sanitized;
                }
            }
            update_post_meta( $post_id, '_presshub_social_platforms', $platforms );
        }

        // Persist any custom copy overrides sent from metabox textareas
        if ( isset( $_POST['copies'] ) && is_array( $_POST['copies'] ) ) {
            foreach ( PressHub_Social_Queue::VALID_PLATFORMS as $slug ) {
                if ( isset( $_POST['copies'][ $slug ] ) ) {
                    $copy_val = sanitize_textarea_field( wp_unslash( $_POST['copies'][ $slug ] ) );
                    if ( '' !== trim( $copy_val ) ) {
                        update_post_meta( $post_id, '_presshub_social_copy_' . $slug, $copy_val );
                    } else {
                        delete_post_meta( $post_id, '_presshub_social_copy_' . $slug );
                    }
                }
            }
        }

        $job_ids = PressHub_Social_Dispatcher::enqueue_for_post( $post_id, $platforms );
        if ( empty( $job_ids ) ) {
            wp_send_json_error( [
                'message' => __( 'No social jobs were enqueued. Please ensure at least one platform is selected.', 'presshub-social-autopost' ),
            ], 400 );
        }

        wp_send_json_success( [
            'message' => sprintf( __( 'Successfully queued %d social post(s).', 'presshub-social-autopost' ), count( $job_ids ) ),
            'post_id' => $post_id,
            'job_ids' => $job_ids,
        ] );
    }

    /**
     * AJAX handler: Test credentials or connection for a social platform or worker.
     * Capability: manage_options.
     *
     * @return void
     */
    public static function handle_test_connection(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';

        if ( empty( $platform ) ) {
            wp_send_json_error( [ 'message' => __( 'No platform specified for connection test.', 'presshub-social-autopost' ) ], 400 );
        }

        if ( 'worker' === $platform ) {
            $res = PressHub_Social_Worker_Client::check_health();
        } else {
            $engine = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_platform_engine' )
                ? PressHub_Social_Settings_Storage::get_platform_engine( $platform )
                : 'api';

            if ( 'automation' === $engine ) {
                $res = PressHub_Social_Worker_Client::verify_session( $platform );
            } else {
                $res = PressHub_Social_Meta_API_Client::test_connection( $platform );
            }
        }

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
        }

        wp_send_json_success( $res );
    }

    /**
     * AJAX handler: Retry a failed or cancelled queue job.
     * Capability: manage_options.
     *
     * @return void
     */
    public static function handle_retry_job(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $job_id       = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
        $dispatch_now = ! empty( $_POST['dispatch_now'] );

        if ( $job_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid job ID.', 'presshub-social-autopost' ) ], 400 );
        }

        $success = PressHub_Social_Queue::retry_job( $job_id );
        if ( ! $success ) {
            wp_send_json_error( [ 'message' => __( 'Could not reset job in queue.', 'presshub-social-autopost' ) ], 400 );
        }

        if ( $dispatch_now ) {
            $dispatch_result = PressHub_Social_Dispatcher::dispatch_job( $job_id );
            if ( is_wp_error( $dispatch_result ) ) {
                if ( 'pull_mode_queued' === $dispatch_result->get_error_code() ) {
                    wp_send_json_success( [
                        'message' => __( 'Job retried and queued for local worker.', 'presshub-social-autopost' ),
                        'job_id'  => $job_id,
                    ] );
                }
                wp_send_json_error( [
                    'message' => sprintf( __( 'Job retried but dispatch failed: %s', 'presshub-social-autopost' ), $dispatch_result->get_error_message() ),
                    'job_id'  => $job_id,
                ], 400 );
            }
            wp_send_json_success( [
                'message' => __( 'Job retried and dispatched successfully.', 'presshub-social-autopost' ),
                'job_id'  => $job_id,
            ] );
        }

        wp_send_json_success( [
            'message' => __( 'Job rescheduled for processing.', 'presshub-social-autopost' ),
            'job_id'  => $job_id,
        ] );
    }

    /**
     * AJAX handler: Cancel a pending job.
     * Capability: manage_options.
     *
     * @return void
     */
    public static function handle_cancel_job(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
        if ( $job_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid job ID.', 'presshub-social-autopost' ) ], 400 );
        }

        $success = PressHub_Social_Queue::cancel_job( $job_id );
        if ( ! $success ) {
            wp_send_json_error( [ 'message' => __( 'Could not cancel job.', 'presshub-social-autopost' ) ], 400 );
        }

        wp_send_json_success( [
            'message' => __( 'Job cancelled.', 'presshub-social-autopost' ),
            'job_id'  => $job_id,
        ] );
    }

    /**
     * AJAX handler: Dispatch a specific job or all due jobs immediately.
     * Capability: manage_options.
     *
     * @return void
     */
    public static function handle_dispatch_now(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

        if ( $job_id > 0 ) {
            $result = PressHub_Social_Dispatcher::dispatch_job( $job_id );
            if ( is_wp_error( $result ) ) {
                if ( 'pull_mode_queued' === $result->get_error_code() ) {
                    wp_send_json_success( [
                        'message' => __( 'Job is queued in Pull Mode and will be picked up by your local worker.', 'presshub-social-autopost' ),
                        'job_id'  => $job_id,
                    ] );
                }
                wp_send_json_error( [ 'message' => $result->get_error_message(), 'job_id' => $job_id ], 400 );
            }
            wp_send_json_success( [
                'message' => __( 'Job dispatched successfully.', 'presshub-social-autopost' ),
                'job_id'  => $job_id,
            ] );
        } else {
            $summary = PressHub_Social_Dispatcher::process_due_queue();
            wp_send_json_success( [
                'message' => sprintf( __( 'Processed %d due job(s): %d succeeded, %d failed.', 'presshub-social-autopost' ), $summary['total_due'], $summary['succeeded'], $summary['failed'] ),
                'summary' => $summary,
            ] );
        }
    }

    /**
     * AJAX handler: Regenerate social media copy for an existing queue job using AI prompts.
     * Capability: edit_posts or manage_options.
     *
     * @return void
     */
    public static function handle_regenerate_job_copy(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
        if ( $job_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid job ID.', 'presshub-social-autopost' ) ], 400 );
        }

        $job = PressHub_Social_Queue::get_job( $job_id );
        if ( ! $job ) {
            wp_send_json_error( [ 'message' => __( 'Job not found in queue.', 'presshub-social-autopost' ) ], 404 );
        }

        $post_id  = (int) ( $job['post_id'] ?? 0 );
        $platform = sanitize_key( (string) ( $job['platform'] ?? '' ) );

        if ( $post_id <= 0 || empty( $platform ) ) {
            wp_send_json_error( [ 'message' => __( 'Job is missing post ID or platform.', 'presshub-social-autopost' ) ], 400 );
        }

        if ( ! class_exists( 'PressHub_Social_AI_Copywriter' ) ) {
            wp_send_json_error( [ 'message' => __( 'AI Copywriter module is unavailable.', 'presshub-social-autopost' ) ], 500 );
        }

        $new_copy = PressHub_Social_AI_Copywriter::generate_for_post( $post_id, $platform );
        if ( is_wp_error( $new_copy ) ) {
            wp_send_json_error( [ 'message' => $new_copy->get_error_message() ], 400 );
        }

        $new_copy_str = trim( (string) $new_copy );
        if ( '' === $new_copy_str ) {
            wp_send_json_error( [ 'message' => __( 'Generated copy was empty.', 'presshub-social-autopost' ) ], 400 );
        }

        // Update the queue record's copy
        PressHub_Social_Queue::update_job_copy( $job_id, $new_copy_str );

        // Also sync postmeta for consistency
        update_post_meta( $post_id, '_presshub_social_copy_' . $platform, $new_copy_str );

        wp_send_json_success( [
            'message'  => sprintf( __( 'AI copy regenerated for %s.', 'presshub-social-autopost' ), ucfirst( $platform ) ),
            'job_id'   => $job_id,
            'post_id'  => $post_id,
            'platform' => $platform,
            'new_copy' => $new_copy_str,
            'preview'  => wp_trim_words( $new_copy_str, 15 ),
        ] );
    }

    /**
     * AJAX handler: Update the copy of an existing queue job.
     * Capability: edit_posts or manage_options.
     *
     * @return void
     */
    public static function handle_update_job_copy(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $job_id    = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
        $post_copy = isset( $_POST['post_copy'] ) ? sanitize_textarea_field( wp_unslash( $_POST['post_copy'] ) ) : '';

        if ( $job_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid job ID.', 'presshub-social-autopost' ) ], 400 );
        }

        if ( '' === trim( $post_copy ) ) {
            wp_send_json_error( [ 'message' => __( 'Post copy cannot be empty.', 'presshub-social-autopost' ) ], 400 );
        }

        $job = PressHub_Social_Queue::get_job( $job_id );
        if ( ! $job ) {
            wp_send_json_error( [ 'message' => __( 'Job not found.', 'presshub-social-autopost' ) ], 404 );
        }

        $updated = PressHub_Social_Queue::update_job_copy( $job_id, $post_copy );
        if ( ! $updated ) {
            wp_send_json_error( [ 'message' => __( 'Could not update post copy.', 'presshub-social-autopost' ) ], 400 );
        }

        if ( ! empty( $job['post_id'] ) && ! empty( $job['platform'] ) ) {
            update_post_meta( (int) $job['post_id'], '_presshub_social_copy_' . sanitize_key( $job['platform'] ), $post_copy );
        }

        wp_send_json_success( [
            'message'   => __( 'Job copy updated successfully.', 'presshub-social-autopost' ),
            'job_id'    => $job_id,
            'post_copy' => $post_copy,
            'preview'   => wp_trim_words( $post_copy, 15 ),
        ] );
    }

    /**
     * AJAX handler: Create a new queue job from an existing job with modified copy and dispatch it.
     * Leaves the original job completely untouched.
     * Capability: edit_posts or manage_options.
     *
     * @return void
     */
    public static function handle_repost_job(): void {
        check_ajax_referer( 'presshub_social_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'presshub-social-autopost' ) ], 403 );
        }

        $source_job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
        $custom_copy   = isset( $_POST['post_copy'] ) ? sanitize_textarea_field( wp_unslash( $_POST['post_copy'] ) ) : '';
        $dispatch_now  = ! isset( $_POST['dispatch_now'] ) || ! empty( $_POST['dispatch_now'] );

        if ( $source_job_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid source job ID.', 'presshub-social-autopost' ) ], 400 );
        }

        $new_job_id = PressHub_Social_Queue::repost_as_new_job( $source_job_id, $custom_copy );
        if ( is_wp_error( $new_job_id ) ) {
            wp_send_json_error( [ 'message' => $new_job_id->get_error_message() ], 400 );
        }

        $dispatch_message = '';
        if ( $dispatch_now ) {
            $dispatch_result = PressHub_Social_Dispatcher::dispatch_job( $new_job_id );
            if ( is_wp_error( $dispatch_result ) ) {
                $dispatch_message = sprintf( __( ' (Warning: dispatch encountered: %s)', 'presshub-social-autopost' ), $dispatch_result->get_error_message() );
            }
        }

        wp_send_json_success( [
            'message'    => sprintf( __( 'New social post #%d created and queued.%s', 'presshub-social-autopost' ), $new_job_id, $dispatch_message ),
            'new_job_id' => $new_job_id,
            'source_id'  => $source_job_id,
        ] );
    }
}
