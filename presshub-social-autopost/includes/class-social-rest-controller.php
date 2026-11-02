<?php
/**
 * REST API Controller for PressHub Social Local Worker (Pull Mode).
 *
 * Provides endpoints for worker heartbeats, job polling/claiming, and execution completion.
 *
 * @package PressHub_Social_AutoPost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-social-settings-storage.php';
require_once __DIR__ . '/class-social-queue.php';

class PressHub_Social_REST_Controller {

    /**
     * REST API Route Namespace.
     */
    public const REST_NAMESPACE = 'presshub-social/v1';

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public static function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/worker/heartbeat',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_heartbeat' ],
                'permission_callback' => [ __CLASS__, 'verify_worker_secret' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/worker/claim',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_claim' ],
                'permission_callback' => [ __CLASS__, 'verify_worker_secret' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/worker/complete',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_complete' ],
                'permission_callback' => [ __CLASS__, 'verify_worker_secret' ],
            ]
        );
    }

    /**
     * Permission callback: Verify worker shared secret token.
     *
     * @param WP_REST_Request|array $request
     * @return bool|WP_Error
     */
    public static function verify_worker_secret( $request ): bool|WP_Error {
        $expected = PressHub_Social_Settings_Storage::get_worker_secret();
        if ( '' === trim( $expected ) ) {
            return new WP_Error(
                'missing_worker_secret',
                __( 'Worker secret is not configured in WordPress settings.', 'presshub-social-autopost' ),
                [ 'status' => 401 ]
            );
        }

        $provided = self::extract_secret( $request );

        if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
            return new WP_Error(
                'invalid_worker_secret',
                __( 'Invalid worker secret.', 'presshub-social-autopost' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Extract secret token from request headers or environment.
     *
     * @param WP_REST_Request|array $request
     * @return string
     */
    protected static function extract_secret( $request ): string {
        $provided = '';

        if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
            $provided = (string) (
                $request->get_header( 'x-presshub-secret' )
                ?: $request->get_header( 'x_presshub_secret' )
                ?: $request->get_header( 'x-worker-secret' )
                ?: $request->get_header( 'x_worker_secret' )
                ?: ''
            );

            if ( '' === $provided ) {
                $auth = (string) $request->get_header( 'authorization' );
                if ( preg_match( '/Bearer\s+(.+)$/i', $auth, $matches ) ) {
                    $provided = trim( $matches[1] );
                }
            }
        } elseif ( is_array( $request ) ) {
            $headers = $request['headers'] ?? [];
            if ( is_array( $headers ) ) {
                $provided = (string) (
                    $headers['x-presshub-secret']
                    ?? $headers['X-PressHub-Secret']
                    ?? $headers['x_presshub_secret']
                    ?? $headers['x-worker-secret']
                    ?? $headers['X-Worker-Secret']
                    ?? $headers['x_worker_secret']
                    ?? ''
                );
                if ( '' === $provided && ! empty( $headers['authorization'] ) ) {
                    if ( preg_match( '/Bearer\s+(.+)$/i', (string) $headers['authorization'], $matches ) ) {
                        $provided = trim( $matches[1] );
                    }
                } elseif ( '' === $provided && ! empty( $headers['Authorization'] ) ) {
                    if ( preg_match( '/Bearer\s+(.+)$/i', (string) $headers['Authorization'], $matches ) ) {
                        $provided = trim( $matches[1] );
                    }
                }
            }

            if ( '' === $provided ) {
                $provided = (string) (
                    $request['x-presshub-secret']
                    ?? $request['X-PressHub-Secret']
                    ?? $request['x-worker-secret']
                    ?? $request['X-Worker-Secret']
                    ?? ''
                );
            }
        }

        // Server header fallback
        if ( '' === $provided ) {
            if ( ! empty( $_SERVER['HTTP_X_PRESSHUB_SECRET'] ) ) {
                $provided = (string) $_SERVER['HTTP_X_PRESSHUB_SECRET'];
            } elseif ( ! empty( $_SERVER['HTTP_X_WORKER_SECRET'] ) ) {
                $provided = (string) $_SERVER['HTTP_X_WORKER_SECRET'];
            } elseif ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
                if ( preg_match( '/Bearer\s+(.+)$/i', (string) $_SERVER['HTTP_AUTHORIZATION'], $matches ) ) {
                    $provided = trim( $matches[1] );
                }
            }
        }

        return trim( $provided );
    }

    /**
     * Safely read a parameter from a request object or array.
     *
     * @param WP_REST_Request|array $request
     * @param string                $key
     * @param mixed                 $default
     * @return mixed
     */
    protected static function get_param( $request, string $key, $default = null ) {
        if ( is_object( $request ) ) {
            if ( method_exists( $request, 'get_param' ) ) {
                $val = $request->get_param( $key );
                if ( null !== $val ) {
                    return $val;
                }
            }
            if ( method_exists( $request, 'get_json_params' ) ) {
                $json = $request->get_json_params();
                if ( is_array( $json ) && isset( $json[ $key ] ) ) {
                    return $json[ $key ];
                }
            }
        }
        if ( is_array( $request ) ) {
            return $request[ $key ] ?? $default;
        }
        return $default;
    }

    /**
     * Update worker presence (last_seen and optional platform sessions).
     *
     * @param WP_REST_Request|array $request
     * @return void
     */
    protected static function update_worker_presence( $request ): void {
        update_option( 'presshub_social_worker_last_seen', time() );

        $sessions = self::get_param( $request, 'sessions' );
        if ( is_array( $sessions ) ) {
            $sanitized = [];
            foreach ( $sessions as $platform => $active ) {
                $platform_key = sanitize_key( (string) $platform );
                if ( in_array( $platform_key, PressHub_Social_Settings_Storage::PLATFORMS, true ) ) {
                    $sanitized[ $platform_key ] = (bool) $active;
                }
            }
            update_option( 'presshub_social_worker_sessions', $sanitized );
        }
    }

    /**
     * Handle POST /presshub-social/v1/worker/heartbeat
     *
     * @param WP_REST_Request|array $request
     * @return array
     */
    public static function handle_heartbeat( $request ): array {
        self::update_worker_presence( $request );

        return [
            'ok'          => true,
            'mode'        => PressHub_Social_Settings_Storage::get_worker_mode(),
            'server_time' => gmdate( 'c' ),
        ];
    }

    /**
     * Handle POST /presshub-social/v1/worker/claim
     *
     * @param WP_REST_Request|array $request
     * @return array
     */
    public static function handle_claim( $request ): array {
        self::update_worker_presence( $request );

        $limit_param = self::get_param( $request, 'limit' );
        $limit       = ( $limit_param && (int) $limit_param > 0 )
            ? (int) $limit_param
            : PressHub_Social_Settings_Storage::get_batch_size();

        $due_jobs = PressHub_Social_Queue::get_due_pending_jobs( 20 );
        $automation_jobs = array_values( array_filter( $due_jobs, function( $j ) {
            return 'automation' === ( $j['engine'] ?? '' );
        } ) );

        $to_claim     = array_slice( $automation_jobs, 0, max( 1, $limit ) );
        $claimed_jobs = [];

        $site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'PressHub';
        if ( empty( $site_name ) ) {
            $site_name = 'PressHub';
        }

        $specs = [
            'instagram_card_width'   => PressHub_Social_Settings_Storage::get_instagram_card_width(),
            'instagram_card_height'  => PressHub_Social_Settings_Storage::get_instagram_card_height(),
            'tiktok_video_width'     => PressHub_Social_Settings_Storage::get_tiktok_video_width(),
            'tiktok_video_height'    => PressHub_Social_Settings_Storage::get_tiktok_video_height(),
            'tiktok_video_duration'  => PressHub_Social_Settings_Storage::get_tiktok_video_duration(),
        ];

        foreach ( $to_claim as $job ) {
            $job_id = (int) $job['id'];
            PressHub_Social_Queue::mark_processing( $job_id );

            $post_id    = (int) ( $job['post_id'] ?? 0 );
            $post       = $post_id > 0 && function_exists( 'get_post' ) ? get_post( $post_id ) : null;
            $post_title = '';
            if ( $post ) {
                $post_title = is_object( $post ) ? ( $post->post_title ?? '' ) : ( $post['post_title'] ?? '' );
            }

            $post_url = $post_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
            if ( empty( $post_url ) && $post_id > 0 && function_exists( 'home_url' ) ) {
                $post_url = home_url( '/?p=' . $post_id );
            }

            $job['post_title'] = $post_title;
            $job['post_url']   = $post_url;
            $job['site_name']  = $site_name;
            $job['specs']      = $specs;

            $claimed_jobs[] = $job;
        }

        return [
            'ok'   => true,
            'jobs' => $claimed_jobs,
        ];
    }

    /**
     * Handle POST /presshub-social/v1/worker/complete
     *
     * @param WP_REST_Request|array $request
     * @return array|WP_Error
     */
    public static function handle_complete( $request ): array|WP_Error {
        self::update_worker_presence( $request );

        $job_id            = (int) self::get_param( $request, 'job_id', 0 );
        $status            = sanitize_key( (string) self::get_param( $request, 'status', '' ) );
        $external_post_id  = (string) self::get_param( $request, 'external_post_id', '' );
        $external_post_url = (string) self::get_param( $request, 'external_post_url', '' );
        $error             = (string) self::get_param( $request, 'error', '' );
        $metadata          = (array) self::get_param( $request, 'metadata', [] );

        if ( $job_id <= 0 ) {
            return new WP_Error(
                'invalid_job_id',
                __( 'A valid positive job_id is required.', 'presshub-social-autopost' ),
                [ 'status' => 400 ]
            );
        }

        if ( 'published' === $status ) {
            PressHub_Social_Queue::mark_published( $job_id, $external_post_id, $external_post_url, $metadata );
        } else {
            $status = 'failed';
            PressHub_Social_Queue::mark_failed( $job_id, '' !== $error ? $error : 'Worker reported failure', $metadata );
        }

        return [
            'ok'     => true,
            'job_id' => $job_id,
            'status' => $status,
        ];
    }
}
