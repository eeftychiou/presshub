<?php
/**
 * HTTP client for communicating with the PressHub Social Worker service (Playwright / Puppeteer automation).
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Worker_Client {

    /**
     * Send an authenticated HTTP request to the social worker endpoint.
     *
     * @param string $path   Relative endpoint path (e.g. '/health').
     * @param string $method HTTP method (GET, POST).
     * @param array  $data   Payload for POST/PUT requests.
     * @return array|WP_Error Decoded JSON array or WP_Error on failure.
     */
    public static function send_request( string $path, string $method = 'GET', array $data = [] ): array|WP_Error {
        $worker_url = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_worker_url' )
            ? PressHub_Social_Settings_Storage::get_worker_url()
            : '';
        $secret     = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_worker_secret' )
            ? PressHub_Social_Settings_Storage::get_worker_secret()
            : '';
        $timeout    = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_worker_timeout' )
            ? PressHub_Social_Settings_Storage::get_worker_timeout()
            : 60;

        // Zero-Silent-Fallback enforcement
        if ( empty( $worker_url ) || empty( $secret ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Worker client call rejected: missing Worker URL or Worker Secret' );
            }
            return new WP_Error(
                'missing_worker_config',
                __( 'Social Worker URL or Secret is not configured in Settings.', 'presshub-social-autopost' )
            );
        }

        $base_url = rtrim( $worker_url, '/' );
        $endpoint = $base_url . '/' . ltrim( $path, '/' );

        $headers = [
            'Authorization'    => 'Bearer ' . $secret,
            'X-PressHub-Secret' => $secret,
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
        ];

        $args = [
            'method'    => strtoupper( $method ),
            'headers'   => $headers,
            'timeout'   => max( 10, (int) $timeout ),
            'sslverify' => true,
        ];

        if ( 'GET' !== strtoupper( $method ) && ! empty( $data ) ) {
            $args['body'] = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
        }

        $response = wp_remote_request( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error(
                    sprintf( 'Social Worker HTTP request failed: %s', $response->get_error_message() ),
                    [ 'endpoint' => $endpoint ]
                );
            }
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        if ( $code >= 400 || ( is_array( $body ) && isset( $body['success'] ) && false === $body['success'] ) ) {
            $err_msg = is_array( $body ) && ! empty( $body['error'] )
                ? ( is_array( $body['error'] ) ? ( $body['error']['message'] ?? json_encode( $body['error'] ) ) : (string) $body['error'] )
                : sprintf( 'Social Worker responded with HTTP %d: %s', $code, substr( $raw, 0, 300 ) );

            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error(
                    sprintf( 'Social Worker endpoint error (%s): %s', $path, $err_msg ),
                    [ 'endpoint' => $endpoint, 'status_code' => $code ]
                );
            }

            return new WP_Error( 'worker_api_error', $err_msg, [ 'status_code' => $code, 'body' => $body ] );
        }

        return is_array( $body ) ? $body : [ 'raw' => $raw ];
    }

    /**
     * Check health status of the worker service.
     *
     * @return array|WP_Error
     */
    public static function check_health(): array|WP_Error {
        $secret = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_worker_secret' )
            ? PressHub_Social_Settings_Storage::get_worker_secret()
            : '';

        if ( empty( $secret ) ) {
            return new WP_Error(
                'missing_worker_config',
                __( 'Social Worker URL or Secret is not configured in Settings.', 'presshub-social-autopost' )
            );
        }

        if ( class_exists( 'PressHub_Social_Settings_Storage' ) && 'pull' === PressHub_Social_Settings_Storage::get_worker_mode() ) {
            $last_seen = (int) get_option( 'presshub_social_worker_last_seen', 0 );
            if ( $last_seen > 0 && ( time() - $last_seen ) <= 180 ) {
                return [
                    'ok'      => true,
                    'status'  => 'healthy',
                    'message' => sprintf( __( 'Local Pull Worker Online (last heartbeat %ds ago)', 'presshub-social-autopost' ), time() - $last_seen ),
                ];
            }
            return new WP_Error(
                'worker_offline',
                __( 'Pull mode active: waiting for local worker heartbeat. Start presshub-social-worker on your local machine.', 'presshub-social-autopost' )
            );
        }

        $result = self::send_request( '/health', 'GET' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [
            'success' => true,
            'message' => __( 'Social Worker is healthy and responding.', 'presshub-social-autopost' ),
            'data'    => $result,
        ];
    }

    /**
     * Verify if an active browser session exists for the given platform.
     *
     * @param string $platform (e.g. 'twitter', 'facebook', 'instagram', 'tiktok')
     * @return array|WP_Error
     */
    public static function verify_session( string $platform ): array|WP_Error {
        $platform = sanitize_key( $platform );

        $secret = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_worker_secret' )
            ? PressHub_Social_Settings_Storage::get_worker_secret()
            : '';

        if ( empty( $secret ) ) {
            return new WP_Error(
                'missing_worker_config',
                __( 'Social Worker URL or Secret is not configured in Settings.', 'presshub-social-autopost' )
            );
        }

        if ( class_exists( 'PressHub_Social_Settings_Storage' ) && 'pull' === PressHub_Social_Settings_Storage::get_worker_mode() ) {
            $last_seen = (int) get_option( 'presshub_social_worker_last_seen', 0 );
            if ( $last_seen <= 0 || ( time() - $last_seen ) > 180 ) {
                return new WP_Error(
                    'worker_offline',
                    __( 'Pull mode active: waiting for local worker heartbeat. Start presshub-social-worker on your local machine.', 'presshub-social-autopost' )
                );
            }

            $sessions = get_option( 'presshub_social_worker_sessions', [] );
            if ( is_array( $sessions ) && ! empty( $sessions[ $platform ] ) ) {
                return [
                    'ok'      => true,
                    'valid'   => true,
                    'message' => sprintf( __( 'Active %s session verified on local pull worker.', 'presshub-social-autopost' ), ucfirst( $platform ) ),
                ];
            }

            return new WP_Error(
                'session_missing',
                sprintf( __( 'No active %s session reported by local worker. Run: npm run login -- %s', 'presshub-social-autopost' ), $platform, $platform )
            );
        }

        $result = self::send_request( '/verify-session', 'POST', [ 'platform' => $platform ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $is_authenticated = ! empty( $result['authenticated'] ) || ! empty( $result['session_valid'] );

        return [
            'success'       => $is_authenticated,
            'platform'      => $platform,
            'authenticated' => $is_authenticated,
            'message'       => $is_authenticated
                ? sprintf( __( 'Browser automation session active for %s.', 'presshub-social-autopost' ), ucfirst( $platform ) )
                : sprintf( __( 'No valid browser session found for %s on worker.', 'presshub-social-autopost' ), ucfirst( $platform ) ),
            'data'          => $result,
        ];
    }

    /**
     * Request the worker to generate a social image card.
     *
     * @param array $args Parameters: title, excerpt, brand_color, width, height, etc.
     * @return array|WP_Error
     */
    public static function generate_card( array $args ): array|WP_Error {
        $default_width = 1080;
        $default_height = 1080;

        if ( class_exists( 'PressHub_Social_Settings_Storage' ) ) {
            if ( method_exists( 'PressHub_Social_Settings_Storage', 'get_instagram_card_width' ) ) {
                $default_width = PressHub_Social_Settings_Storage::get_instagram_card_width();
            } elseif ( method_exists( 'PressHub_Social_Settings_Storage', 'get_card_width' ) ) {
                $default_width = PressHub_Social_Settings_Storage::get_card_width();
            }

            if ( method_exists( 'PressHub_Social_Settings_Storage', 'get_instagram_card_height' ) ) {
                $default_height = PressHub_Social_Settings_Storage::get_instagram_card_height();
            } elseif ( method_exists( 'PressHub_Social_Settings_Storage', 'get_card_height' ) ) {
                $default_height = PressHub_Social_Settings_Storage::get_card_height();
            }
        }

        $payload = array_merge( [
            'width'  => $default_width,
            'height' => $default_height,
        ], $args );

        return self::send_request( '/generate-card', 'POST', $payload );
    }

    /**
     * Request the worker to generate a short-form video.
     *
     * @param array $args Parameters: title, copy, duration, width, height, etc.
     * @return array|WP_Error
     */
    public static function generate_video( array $args ): array|WP_Error {
        $default_width    = 1080;
        $default_height   = 1920;
        $default_duration = 10;

        if ( class_exists( 'PressHub_Social_Settings_Storage' ) ) {
            if ( method_exists( 'PressHub_Social_Settings_Storage', 'get_tiktok_video_width' ) ) {
                $default_width = PressHub_Social_Settings_Storage::get_tiktok_video_width();
            } elseif ( method_exists( 'PressHub_Social_Settings_Storage', 'get_video_width' ) ) {
                $default_width = PressHub_Social_Settings_Storage::get_video_width();
            }

            if ( method_exists( 'PressHub_Social_Settings_Storage', 'get_tiktok_video_height' ) ) {
                $default_height = PressHub_Social_Settings_Storage::get_tiktok_video_height();
            } elseif ( method_exists( 'PressHub_Social_Settings_Storage', 'get_video_height' ) ) {
                $default_height = PressHub_Social_Settings_Storage::get_video_height();
            }

            if ( method_exists( 'PressHub_Social_Settings_Storage', 'get_tiktok_video_duration' ) ) {
                $default_duration = PressHub_Social_Settings_Storage::get_tiktok_video_duration();
            } elseif ( method_exists( 'PressHub_Social_Settings_Storage', 'get_video_duration' ) ) {
                $default_duration = PressHub_Social_Settings_Storage::get_video_duration();
            }
        }

        $payload = array_merge( [
            'width'    => $default_width,
            'height'   => $default_height,
            'duration' => $default_duration,
        ], $args );

        return self::send_request( '/generate-video', 'POST', $payload );
    }

    /**
     * Dispatch a social job payload to the worker for browser automation execution.
     *
     * @param array $job
     * @return array|WP_Error
     */
    public static function dispatch( array $job ): array|WP_Error {
        $copy = (string) ( $job['post_copy'] ?? '' );
        if ( str_starts_with( ltrim( $copy ), '[AI Copy Generation Failed:' ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Worker dispatch rejected: post_copy contains AI copy failure placeholder.' );
            }
            return new WP_Error(
                'failed_copy_placeholder',
                __( 'Refusing to dispatch AI copy generation error placeholder to worker.', 'presshub-social-autopost' )
            );
        }

        $payload = [
            'job_id'     => (int) ( $job['id'] ?? 0 ),
            'post_id'    => (int) ( $job['post_id'] ?? 0 ),
            'platform'   => sanitize_key( (string) ( $job['platform'] ?? '' ) ),
            'post_copy'  => (string) ( $job['post_copy'] ?? '' ),
            'media_url'  => ! empty( $job['media_url'] ) ? (string) $job['media_url'] : null,
            'media_type' => ! empty( $job['media_type'] ) ? (string) $job['media_type'] : null,
            'metadata'   => ! empty( $job['metadata'] ) ? json_decode( (string) $job['metadata'], true ) : [],
        ];

        $response = self::send_request( '/dispatch', 'POST', $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $external_id  = (string) ( $response['external_post_id'] ?? ( $response['post_id'] ?? '' ) );
        $external_url = (string) ( $response['external_post_url'] ?? ( $response['url'] ?? '' ) );

        return [
            'external_post_id'  => $external_id,
            'external_post_url' => $external_url,
            'metadata'          => $response,
        ];
    }
}
