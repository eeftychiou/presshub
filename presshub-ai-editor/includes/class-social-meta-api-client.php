<?php
/**
 * Direct official API client for Meta (Facebook & Instagram), Twitter/X, and TikTok.
 *
 * @package PressHub_Social_Autopost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Meta_API_Client {

    /**
     * Graph API version for Meta.
     */
    public const GRAPH_API_VERSION = 'v21.0';

    /**
     * Error code returned while a Reels container is still IN_PROGRESS after the short poll budget.
     * The dispatcher reschedules the job (PressHub_Social_Queue::defer_job()) instead of failing it.
     */
    public const INSTAGRAM_PENDING_CODE = 'instagram_container_pending';

    /**
     * Seconds until the next status check of an IN_PROGRESS Reels container.
     */
    public const INSTAGRAM_PENDING_RECHECK_DELAY = 60;

    /**
     * Total seconds a Reels container may stay IN_PROGRESS before the job fails and a fresh container is created.
     */
    public const INSTAGRAM_CONTAINER_MAX_WAIT = 900;

    /**
     * Status polls per publish run, and seconds between them (about 15 s of sleeping per cron run).
     */
    private static int $instagram_poll_attempts = 4;
    private static int $instagram_poll_interval = 5;

    /**
     * Publish a social job using the appropriate official platform API.
     *
     * @param array $job
     * @return array|WP_Error
     */
    public static function publish( array $job ): array|WP_Error {
        $copy = (string) ( $job['post_copy'] ?? '' );
        if ( str_starts_with( ltrim( $copy ), '[AI Copy Generation Failed:' ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Meta/Twitter API publish rejected: post_copy contains AI copy failure placeholder.' );
            }
            return new WP_Error(
                'failed_copy_placeholder',
                __( 'Refusing to publish AI copy generation error placeholder.', 'presshub-social-autopost' )
            );
        }

        $platform = sanitize_key( (string) ( $job['platform'] ?? '' ) );

        return match ( $platform ) {
            'facebook'  => self::publish_facebook( $job ),
            'instagram' => self::publish_instagram( $job ),
            'twitter'   => self::publish_twitter( $job ),
            'tiktok'    => self::publish_tiktok( $job ),
            default     => new WP_Error( 'unsupported_platform', sprintf( __( 'Unsupported platform for API client: %s', 'presshub-social-autopost' ), $platform ) ),
        };
    }

    /**
     * Publish post to Facebook Page.
     *
     * @param array $job
     * @return array|WP_Error Array with external_post_id, external_post_url, or WP_Error.
     */
    public static function publish_facebook( array $job ): array|WP_Error {
        $page_id = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_meta_page_id' )
            ? PressHub_Social_Settings_Storage::get_meta_page_id()
            : '';
        $token   = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_meta_access_token' )
            ? PressHub_Social_Settings_Storage::get_meta_access_token()
            : '';

        // Zero-Silent-Fallback enforcement
        if ( empty( $page_id ) || empty( $token ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Facebook publish rejected: missing Page ID or Meta Access Token' );
            }
            return new WP_Error(
                'missing_facebook_credentials',
                __( 'Facebook credentials not configured. Please set Meta Page ID and Access Token in Settings.', 'presshub-social-autopost' )
            );
        }

        // A personal (user) token cannot post as the Page; use the Page's own token.
        $token = self::facebook_page_token( $page_id, $token );

        $copy      = (string) ( $job['post_copy'] ?? '' );
        $media_url = ! empty( $job['media_url'] ) ? (string) $job['media_url'] : '';
        $has_image = ! empty( $media_url ) && ( empty( $job['media_type'] ) || 'image' === $job['media_type'] );

        $version = self::GRAPH_API_VERSION;

        // Several AI post images: one post with every photo attached.
        $image_urls = class_exists( 'PressHub_Social_Post_Images' ) ? PressHub_Social_Post_Images::media_urls( $job ) : [];
        if ( count( $image_urls ) > 1 ) {
            return self::publish_facebook_photos( $page_id, $token, $copy, $image_urls );
        }

        if ( $has_image ) {
            $endpoint = "https://graph.facebook.com/{$version}/{$page_id}/photos";
            $body     = [
                'url'          => $media_url,
                'caption'      => $copy,
                'access_token' => $token,
            ];
        } else {
            $endpoint = "https://graph.facebook.com/{$version}/{$page_id}/feed";
            $body     = [
                'message'      => $copy,
                'access_token' => $token,
            ];
        }

        $response = wp_remote_post( $endpoint, [
            'timeout'   => 30,
            'body'      => $body,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Facebook HTTP transport error: ' . $response->get_error_message() );
            }
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code >= 400 || ! empty( $data['error'] ) ) {
            $err_msg = $data['error']['message'] ?? sprintf( 'Facebook API returned HTTP %d: %s', $code, $raw );
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Facebook API error: ' . $err_msg, [ 'status_code' => $code ] );
            }
            return new WP_Error( 'facebook_api_error', $err_msg );
        }

        $id = $data['id'] ?? ( $data['post_id'] ?? '' );

        return [
            'external_post_id'  => (string) $id,
            'external_post_url' => "https://www.facebook.com/{$id}",
            'metadata'          => [ 'raw_response' => $data ],
        ];
    }

    /**
     * The token to post to a Facebook Page with. The stored Meta Access Token may be a
     * personal (user) token, e.g. a long-lived one from the Access Token Debugger: Graph
     * rejects Page posts made with it ("(#200) The permission(s) publish_actions are not
     * available"). `GET /{page_id}?fields=access_token` with that token returns the Page's
     * own token (one made from a long-lived user token does not expire). A Page token
     * passed in is returned as is (Graph gives back no other token for it). The result is
     * cached for 6 hours per stored token. On a Graph error or an HTTP 4xx/5xx the stored
     * token is used, a WARNING is logged and that fallback is cached for 15 minutes; a
     * transport error falls back without caching. A token that already is a Page token
     * keeps working.
     */
    public static function facebook_page_token( string $page_id, string $token ): string {
        if ( '' === $page_id || '' === $token ) {
            return $token;
        }
        $cache_key = 'presshub_fb_page_token_' . md5( $page_id . '|' . $token );
        $cached    = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        $url = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION . '/' . rawurlencode( $page_id )
            . '?fields=access_token&access_token=' . rawurlencode( $token );
        $res = null !== self::$http_get
            ? call_user_func( self::$http_get, $url )
            : wp_remote_get( $url, [ 'timeout' => 15, 'sslverify' => true ] );

        if ( is_wp_error( $res ) ) {
            // A transport error may be momentary: fall back, but do not cache it.
            PressHub_Social_Logger::warning( 'Facebook Page token lookup failed (' . $res->get_error_message() . '); posting with the stored Meta Access Token.' );
            return $token;
        }
        $status = 0;
        if ( is_array( $res ) && ( isset( $res['access_token'] ) || isset( $res['error'] ) || isset( $res['id'] ) ) ) {
            $data = $res;
        } else {
            $status = (int) wp_remote_retrieve_response_code( $res );
            $data   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        }
        if ( $status >= 400 || ! is_array( $data ) || ! empty( $data['error'] ) ) {
            if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
                $msg = (string) $data['error']['message'];
            } elseif ( $status >= 400 ) {
                $msg = sprintf( 'HTTP %d', $status );
            } else {
                $msg = 'invalid response';
            }
            PressHub_Social_Logger::warning( 'Facebook Page token lookup failed (' . $msg . '); posting with the stored Meta Access Token.' );
            // Remember the fallback briefly so a run of posts does not repeat a failing lookup.
            self::cache_facebook_page_token( $cache_key, $token, self::FB_PAGE_TOKEN_FAILURE_TTL );
            return $token;
        }

        $page_token = (string) ( $data['access_token'] ?? '' );
        if ( '' === $page_token ) {
            // Already a Page token (or the user cannot manage the Page): post with it as is.
            self::cache_facebook_page_token( $cache_key, $token, self::FB_PAGE_TOKEN_TTL );
            return $token;
        }
        if ( $page_token !== $token ) {
            PressHub_Social_Logger::info( sprintf( 'Facebook: using the Page access token of Page %s derived from the stored Meta Access Token.', $page_id ) );
        }
        self::cache_facebook_page_token( $cache_key, $page_token, self::FB_PAGE_TOKEN_TTL );
        return $page_token;
    }

    /** Seconds a derived (or confirmed) Facebook Page token is cached. */
    private const FB_PAGE_TOKEN_TTL = 6 * 3600;

    /** Seconds a failed Page token lookup falls back to the stored token before retrying. */
    private const FB_PAGE_TOKEN_FAILURE_TTL = 15 * 60;

    private static function cache_facebook_page_token( string $cache_key, string $value, int $ttl ): void {
        if ( function_exists( 'set_transient' ) ) {
            set_transient( $cache_key, $value, $ttl );
        }
    }

    /**
     * Publish one Facebook Page post with several photos: each photo is uploaded
     * unpublished, then a feed post attaches them all.
     *
     * @param string[] $image_urls
     * @return array|WP_Error
     */
    private static function publish_facebook_photos( string $page_id, string $token, string $copy, array $image_urls ): array|WP_Error {
        $version  = self::GRAPH_API_VERSION;
        $media    = [];
        foreach ( $image_urls as $i => $url ) {
            $response = wp_remote_post( "https://graph.facebook.com/{$version}/{$page_id}/photos", [
                'timeout'   => 30,
                'body'      => [
                    'url'          => $url,
                    'published'    => 'false',
                    'access_token' => $token,
                ],
                'sslverify' => true,
            ] );
            $data = self::facebook_response( $response, sprintf( 'photo %d/%d upload', $i + 1, count( $image_urls ) ) );
            if ( is_wp_error( $data ) ) {
                return $data;
            }
            if ( empty( $data['id'] ) ) {
                return new WP_Error( 'facebook_api_error', sprintf( 'Facebook photo %d/%d upload returned no id.', $i + 1, count( $image_urls ) ) );
            }
            $media[] = (string) $data['id'];
        }

        $body = [
            'message'      => $copy,
            'access_token' => $token,
        ];
        foreach ( $media as $i => $media_id ) {
            $body[ "attached_media[{$i}]" ] = wp_json_encode( [ 'media_fbid' => $media_id ] );
        }
        $response = wp_remote_post( "https://graph.facebook.com/{$version}/{$page_id}/feed", [
            'timeout'   => 30,
            'body'      => $body,
            'sslverify' => true,
        ] );
        $data = self::facebook_response( $response, 'multi-photo post' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $id = (string) ( $data['id'] ?? '' );
        return [
            'external_post_id'  => $id,
            'external_post_url' => "https://www.facebook.com/{$id}",
            'metadata'          => [ 'raw_response' => $data, 'photo_ids' => $media ],
        ];
    }

    /**
     * Decode a Graph API response, or the error it carries.
     *
     * @param array|WP_Error $response
     * @return array|WP_Error
     */
    private static function facebook_response( $response, string $step ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            PressHub_Social_Logger::error( sprintf( 'Facebook HTTP transport error (%s): %s', $step, $response->get_error_message() ) );
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );
        if ( $code >= 400 || ! is_array( $data ) || ! empty( $data['error'] ) ) {
            $err_msg = is_array( $data ) ? ( $data['error']['message'] ?? sprintf( 'Facebook API returned HTTP %d: %s', $code, $raw ) ) : sprintf( 'Facebook API returned HTTP %d: %s', $code, $raw );
            PressHub_Social_Logger::error( sprintf( 'Facebook API error (%s): %s', $step, $err_msg ), [ 'status_code' => $code ] );
            return new WP_Error( 'facebook_api_error', $err_msg );
        }
        return $data;
    }

    /**
     * Publish post to Instagram Business Account.
     *
     * @param array $job
     * @return array|WP_Error
     */
    public static function publish_instagram( array $job ): array|WP_Error {
        $ig_user_id = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_instagram_account_id' )
            ? PressHub_Social_Settings_Storage::get_instagram_account_id()
            : '';
        $token      = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_meta_access_token' )
            ? PressHub_Social_Settings_Storage::get_meta_access_token()
            : '';

        // Zero-Silent-Fallback enforcement
        if ( empty( $ig_user_id ) || empty( $token ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Instagram publish rejected: missing Instagram Account ID or Meta Access Token' );
            }
            return new WP_Error(
                'missing_instagram_credentials',
                __( 'Instagram credentials not configured. Please set Instagram Account ID and Meta Access Token in Settings.', 'presshub-social-autopost' )
            );
        }

        $media_url = ! empty( $job['media_url'] ) ? (string) $job['media_url'] : '';
        if ( empty( $media_url ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Instagram publish rejected: media_url is required by Instagram Graph API' );
            }
            return new WP_Error(
                'missing_instagram_media',
                __( 'Instagram requires a public media URL (image or video). None was provided.', 'presshub-social-autopost' )
            );
        }

        $version    = self::GRAPH_API_VERSION;
        $copy       = (string) ( $job['post_copy'] ?? '' );
        $media_type = (string) ( $job['media_type'] ?? 'image' );

        $raw_meta = $job['metadata'] ?? [];
        if ( is_string( $raw_meta ) && '' !== $raw_meta ) {
            $decoded_meta = json_decode( $raw_meta, true );
            $job_meta     = is_array( $decoded_meta ) ? $decoded_meta : [];
        } elseif ( is_array( $raw_meta ) ) {
            $job_meta = $raw_meta;
        } else {
            $job_meta = [];
        }

        $creation_id = ( 'video' === $media_type && ! empty( $job_meta['instagram_creation_id'] ) )
            ? (string) $job_meta['instagram_creation_id']
            : '';

        // Step 1: Create media container (unless a video container from an earlier attempt is already pending)
        if ( '' === $creation_id ) {
            $container_endpoint = "https://graph.facebook.com/{$version}/{$ig_user_id}/media";
            $container_body     = [
                'caption'      => $copy,
                'access_token' => $token,
            ];

            if ( 'video' === $media_type ) {
                $container_body['media_type'] = 'REELS';
                $container_body['video_url']   = $media_url;
            } else {
                $container_body['image_url']   = $media_url;
            }

            $response1 = null !== self::$http_post
                ? call_user_func( self::$http_post, $container_endpoint, $container_body )
                : wp_remote_post( $container_endpoint, [
                    'timeout'   => 45,
                    'body'      => $container_body,
                    'sslverify' => true,
                ] );

            if ( is_wp_error( $response1 ) ) {
                return $response1;
            }

            if ( is_array( $response1 ) && array_key_exists( 'body', $response1 ) && isset( $response1['code'] ) ) {
                $code1 = (int) $response1['code'];
                $data1 = is_array( $response1['body'] ) ? $response1['body'] : json_decode( (string) $response1['body'], true );
            } else {
                $code1 = (int) wp_remote_retrieve_response_code( $response1 );
                $data1 = json_decode( wp_remote_retrieve_body( $response1 ), true );
            }
            $data1 = is_array( $data1 ) ? $data1 : [];

            if ( $code1 >= 400 || empty( $data1['id'] ) ) {
                $err_msg = $data1['error']['message'] ?? sprintf( 'Instagram container creation failed with HTTP %d', $code1 );
                if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                    PressHub_Social_Logger::error( 'Instagram media container error: ' . $err_msg );
                }
                return new WP_Error( 'instagram_container_error', $err_msg );
            }

            $creation_id = (string) $data1['id'];

            if ( 'video' === $media_type && ! empty( $job['id'] ) && class_exists( 'PressHub_Social_Queue' ) ) {
                $job_meta['instagram_creation_id']         = $creation_id;
                $job_meta['instagram_container_started_at'] = time();
                PressHub_Social_Queue::update_job( (int) $job['id'], [
                    'metadata' => function_exists( 'wp_json_encode' ) ? wp_json_encode( $job_meta ) : json_encode( $job_meta ),
                ] );
            }
        }

        // Reels (video) containers are processed asynchronously by Meta; poll status before publishing.
        if ( 'video' === $media_type ) {
            // Poll only for a short budget so one cron run stays well under max_execution_time;
            // a container still IN_PROGRESS afterwards is rechecked on a later run.
            $ready = self::wait_for_instagram_container(
                $version,
                $creation_id,
                $token,
                self::$instagram_poll_attempts,
                self::$instagram_poll_interval
            );
            if ( is_wp_error( $ready ) ) {
                $err_code = $ready->get_error_code();
                if ( in_array( $err_code, [ 'instagram_container_processing_error', 'instagram_container_status_error' ], true ) ) {
                    self::forget_instagram_container( $job, $job_meta );
                }
                if ( 'instagram_container_timeout' === $err_code ) {
                    return self::instagram_container_pending( $job, $job_meta, $creation_id );
                }
                return $ready;
            }
        }

        // Step 2: Publish media container
        $publish_endpoint = "https://graph.facebook.com/{$version}/{$ig_user_id}/media_publish";
        $publish_body     = [
            'creation_id'  => $creation_id,
            'access_token' => $token,
        ];
        $response2        = null !== self::$http_post
            ? call_user_func( self::$http_post, $publish_endpoint, $publish_body )
            : wp_remote_post( $publish_endpoint, [
                'timeout'   => 45,
                'body'      => $publish_body,
                'sslverify' => true,
            ] );

        if ( is_wp_error( $response2 ) ) {
            return $response2;
        }

        if ( is_array( $response2 ) && array_key_exists( 'body', $response2 ) && isset( $response2['code'] ) ) {
            $code2 = (int) $response2['code'];
            $data2 = is_array( $response2['body'] ) ? $response2['body'] : json_decode( (string) $response2['body'], true );
        } else {
            $code2 = (int) wp_remote_retrieve_response_code( $response2 );
            $data2 = json_decode( wp_remote_retrieve_body( $response2 ), true );
        }
        $data2 = is_array( $data2 ) ? $data2 : [];

        if ( $code2 >= 400 || empty( $data2['id'] ) ) {
            $err_msg = $data2['error']['message'] ?? sprintf( 'Instagram publish container failed with HTTP %d', $code2 );
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Instagram media publish error: ' . $err_msg );
            }
            return new WP_Error( 'instagram_publish_error', $err_msg );
        }

        $media_id = (string) $data2['id'];

        self::forget_instagram_container( $job, $job_meta );

        return [
            'external_post_id'  => $media_id,
            'external_post_url' => "https://www.instagram.com/p/{$media_id}/",
            'metadata'          => [
                'creation_id' => $creation_id,
                'publish_id'  => $media_id,
            ],
        ];
    }

    /**
     * Remove a stored Instagram container ID from job metadata so retries or reposts create a fresh container.
     */
    private static function forget_instagram_container( array $job, array &$job_meta ): void {
        if ( empty( $job['id'] ) || ! class_exists( 'PressHub_Social_Queue' )
            || ( ! isset( $job_meta['instagram_creation_id'] ) && ! isset( $job_meta['instagram_container_started_at'] ) ) ) {
            return;
        }
        unset( $job_meta['instagram_creation_id'], $job_meta['instagram_container_started_at'] );
        PressHub_Social_Queue::update_job( (int) $job['id'], [
            'metadata' => function_exists( 'wp_json_encode' ) ? wp_json_encode( $job_meta ) : json_encode( $job_meta ),
        ] );
    }

    /**
     * A Reels container is still IN_PROGRESS after this run's poll budget.
     *
     * Within INSTAGRAM_CONTAINER_MAX_WAIT of the container's creation, returns the retryable
     * INSTAGRAM_PENDING_CODE error: instagram_creation_id stays in the job metadata, and the
     * dispatcher reschedules the job for a short-delay check without using up an attempt.
     * Past the cap, the container is forgotten and a plain instagram_container_timeout error
     * is returned, so the job fails normally and its next retry creates a fresh container.
     */
    private static function instagram_container_pending( array $job, array &$job_meta, string $creation_id ): WP_Error {
        $now        = time();
        $started_at = (int) ( $job_meta['instagram_container_started_at'] ?? 0 );

        if ( $started_at <= 0 ) {
            // Container stored before its start time was recorded: start the clock now.
            $started_at = $now;
            if ( ! empty( $job['id'] ) && class_exists( 'PressHub_Social_Queue' ) ) {
                $job_meta['instagram_container_started_at'] = $started_at;
                PressHub_Social_Queue::update_job( (int) $job['id'], [
                    'metadata' => function_exists( 'wp_json_encode' ) ? wp_json_encode( $job_meta ) : json_encode( $job_meta ),
                ] );
            }
        }

        $waited = max( 0, $now - $started_at );

        if ( $waited >= self::INSTAGRAM_CONTAINER_MAX_WAIT ) {
            $msg = sprintf(
                'Instagram Reels container %s was still processing after %d minutes; giving up on it (the next retry creates a new container).',
                $creation_id,
                (int) floor( $waited / 60 )
            );
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( $msg, [ 'job_id' => (int) ( $job['id'] ?? 0 ), 'creation_id' => $creation_id ] );
            }
            self::forget_instagram_container( $job, $job_meta );
            return new WP_Error( 'instagram_container_timeout', $msg );
        }

        $msg = sprintf(
            'Instagram is still processing the Reel (container %s, waited %ds so far); checking again in %ds.',
            $creation_id,
            $waited,
            self::INSTAGRAM_PENDING_RECHECK_DELAY
        );
        if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'info' ) ) {
            PressHub_Social_Logger::info( $msg, [ 'job_id' => (int) ( $job['id'] ?? 0 ), 'creation_id' => $creation_id ] );
        }

        return new WP_Error(
            self::INSTAGRAM_PENDING_CODE,
            $msg,
            [
                'retry_after' => self::INSTAGRAM_PENDING_RECHECK_DELAY,
                'creation_id' => $creation_id,
                'waited'      => $waited,
            ]
        );
    }

    /**
     * Override the per-run Reels status poll budget (unit tests use a zero interval).
     */
    public static function set_instagram_poll_budget( int $attempts, int $interval_seconds ): void {
        self::$instagram_poll_attempts = max( 1, $attempts );
        self::$instagram_poll_interval = max( 0, $interval_seconds );
    }

    /** @var callable|null Optional HTTP GET override for unit tests. */
    private static $http_get = null;

    /** @var callable|null Optional HTTP POST override for unit tests. */
    private static $http_post = null;

    public static function set_http_get( ?callable $http_get ): void {
        self::$http_get = $http_get;
    }

    public static function set_http_post( ?callable $http_post ): void {
        self::$http_post = $http_post;
    }

    /**
     * Poll an Instagram Graph API media container until it finishes processing (required for REELS).
     *
     * @param string $version
     * @param string $creation_id
     * @param string $token
     * @param int    $max_attempts
     * @param int    $sleep_seconds
     * @return true|WP_Error
     */
    public static function wait_for_instagram_container(
        string $version,
        string $creation_id,
        string $token,
        int $max_attempts = 24,
        int $sleep_seconds = 10
    ): bool|WP_Error {
        $status_url = "https://graph.facebook.com/{$version}/" . rawurlencode( $creation_id )
            . '?fields=status_code,status&access_token=' . rawurlencode( $token );

        for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
            $res = null !== self::$http_get
                ? call_user_func( self::$http_get, $status_url )
                : wp_remote_get( $status_url, [
                    'timeout'   => 15,
                    'sslverify' => true,
                ] );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            if ( is_array( $res ) && ( isset( $res['status_code'] ) || isset( $res['error'] ) || isset( $res['http_code'] ) ) ) {
                $http_code = (int) ( $res['http_code'] ?? 200 );
                $data      = $res;
            } else {
                $http_code = (int) wp_remote_retrieve_response_code( $res );
                $decoded   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
                $data      = is_array( $decoded ) ? $decoded : [];
            }

            if ( $http_code >= 400 || ! empty( $data['error'] ) ) {
                $err          = is_array( $data['error'] ?? null ) ? $data['error'] : [];
                $graph_code   = (int) ( $err['code'] ?? 0 );
                $is_transient = $http_code >= 500
                    || 429 === $http_code
                    || ! empty( $err['is_transient'] )
                    || in_array( $graph_code, [ 2, 4, 17, 32, 613 ], true );

                if ( ! $is_transient ) {
                    $msg           = (string) ( $err['message'] ?? sprintf( 'Instagram container status check failed with HTTP %d', $http_code ) );
                    $is_auth_error = ( 190 === $graph_code || 'OAuthException' === (string) ( $err['type'] ?? '' ) || 401 === $http_code );
                    if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                        PressHub_Social_Logger::error( 'Instagram container status error: ' . $msg );
                    }
                    return new WP_Error(
                        $is_auth_error ? 'instagram_container_auth_error' : 'instagram_container_status_error',
                        $msg
                    );
                }
            } else {
                $status_code = strtoupper( (string) ( $data['status_code'] ?? '' ) );

                if ( 'FINISHED' === $status_code || 'PUBLISHED' === $status_code ) {
                    return true;
                }
                if ( 'ERROR' === $status_code || 'EXPIRED' === $status_code ) {
                    $msg = (string) ( $data['status'] ?? $data['error']['message'] ?? "Instagram container processing failed ({$status_code})" );
                    if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                        PressHub_Social_Logger::error( 'Instagram container status error: ' . $msg );
                    }
                    return new WP_Error( 'instagram_container_processing_error', $msg );
                }
            }

            if ( $sleep_seconds > 0 && $attempt < $max_attempts - 1 ) {
                sleep( $sleep_seconds );
            }
        }

        return new WP_Error(
            'instagram_container_timeout',
            __( 'Instagram video container did not finish processing in time.', 'presshub-social-autopost' )
        );
    }

    /**
     * Publish tweet to Twitter/X API v2.
     *
     * @param array $job
     * @return array|WP_Error
     */
    public static function publish_twitter( array $job ): array|WP_Error {
        $bearer_token = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_twitter_bearer_token' )
            ? PressHub_Social_Settings_Storage::get_twitter_bearer_token()
            : '';

        if ( empty( $bearer_token ) && class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_twitter_access_token' ) ) {
            $bearer_token = PressHub_Social_Settings_Storage::get_twitter_access_token();
        }

        // Zero-Silent-Fallback enforcement
        if ( empty( $bearer_token ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Twitter publish rejected: missing Twitter Bearer/Access Token' );
            }
            return new WP_Error(
                'missing_twitter_credentials',
                __( 'Twitter credentials not configured. Please set Twitter Bearer or Access Token in Settings.', 'presshub-social-autopost' )
            );
        }

        $copy = (string) ( $job['post_copy'] ?? '' );
        $body = [ 'text' => $copy ];

        $response = wp_remote_post( 'https://api.twitter.com/2/tweets', [
            'timeout'   => 30,
            'headers'   => [
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type'  => 'application/json',
            ],
            'body'      => function_exists( 'wp_json_encode' ) ? wp_json_encode( $body ) : json_encode( $body ),
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 || empty( $data['data']['id'] ) ) {
            $err_msg = $data['detail'] ?? ( $data['title'] ?? ( $data['errors'][0]['message'] ?? sprintf( 'Twitter API error HTTP %d', $code ) ) );
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Twitter API error: ' . $err_msg );
            }
            return new WP_Error( 'twitter_api_error', $err_msg );
        }

        $tweet_id = (string) $data['data']['id'];

        return [
            'external_post_id'  => $tweet_id,
            'external_post_url' => "https://x.com/i/web/status/{$tweet_id}",
            'metadata'          => [ 'tweet_data' => $data['data'] ],
        ];
    }

    /**
     * Publish video/post to TikTok Content Posting API.
     *
     * @param array $job
     * @return array|WP_Error
     */
    public static function publish_tiktok( array $job ): array|WP_Error {
        $access_token = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_tiktok_access_token' )
            ? PressHub_Social_Settings_Storage::get_tiktok_access_token()
            : '';
        $open_id      = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_tiktok_open_id' )
            ? PressHub_Social_Settings_Storage::get_tiktok_open_id()
            : '';

        // Zero-Silent-Fallback enforcement
        if ( empty( $access_token ) || empty( $open_id ) ) {
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'TikTok publish rejected: missing Access Token or Open ID' );
            }
            return new WP_Error(
                'missing_tiktok_credentials',
                __( 'TikTok credentials not configured. Please set TikTok Access Token and Open ID in Settings.', 'presshub-social-autopost' )
            );
        }

        $copy      = (string) ( $job['post_copy'] ?? '' );
        $media_url = ! empty( $job['media_url'] ) ? (string) $job['media_url'] : '';

        $endpoint = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
        $payload  = [
            'post_info'   => [
                'title'          => substr( $copy, 0, 150 ),
                'privacy_level'  => 'PUBLIC_TO_EVERYONE',
                'disable_duet'   => false,
                'disable_stitch' => false,
            ],
            'source_info' => [
                'source'    => 'PULL_FROM_URL',
                'video_url' => $media_url,
            ],
        ];

        $response = wp_remote_post( $endpoint, [
            'timeout'   => 30,
            'headers'   => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json; charset=UTF-8',
            ],
            'body'      => function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ),
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 || ! empty( $data['error']['code'] ) && 'ok' !== strtolower( (string) $data['error']['code'] ) ) {
            $err_msg = $data['error']['message'] ?? sprintf( 'TikTok API error HTTP %d', $code );
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'TikTok API error: ' . $err_msg );
            }
            return new WP_Error( 'tiktok_api_error', $err_msg );
        }

        $publish_id = (string) ( $data['data']['publish_id'] ?? '' );

        return [
            'external_post_id'  => $publish_id,
            'external_post_url' => 'https://www.tiktok.com/',
            'metadata'          => [ 'tiktok_response' => $data['data'] ?? [] ],
        ];
    }

    /**
     * Unix time a Meta access token expires, from /debug_token (a token may
     * inspect itself). 0 when it never expires or the lookup fails. Called
     * only from a Test click, never on page load (#247).
     */
    public static function token_expires_at( string $token ): int {
        if ( '' === $token ) {
            return 0;
        }
        $url      = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION . '/debug_token?input_token=' . rawurlencode( $token ) . '&access_token=' . rawurlencode( $token );
        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) {
            return 0;
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $exp  = (int) ( $data['data']['expires_at'] ?? 0 );
        return $exp > 0 ? $exp : 0;
    }

    /**
     * Test connection credentials for a given platform.
     *
     * @param string $platform
     * @return array|WP_Error
     */
    public static function test_connection( string $platform ): array|WP_Error {
        $platform = sanitize_key( $platform );
        $version  = self::GRAPH_API_VERSION;

        switch ( $platform ) {
            case 'facebook':
                $page_id = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_meta_page_id' )
                    ? PressHub_Social_Settings_Storage::get_meta_page_id()
                    : '';
                $token   = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_meta_access_token' )
                    ? PressHub_Social_Settings_Storage::get_meta_access_token()
                    : '';

                if ( empty( $page_id ) || empty( $token ) ) {
                    return new WP_Error( 'missing_credentials', __( 'Meta Page ID and Access Token must be configured.', 'presshub-social-autopost' ) );
                }

                $url      = "https://graph.facebook.com/{$version}/{$page_id}?fields=id,name&access_token=" . rawurlencode( $token );
                $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $data['error'] ) ) {
                    return new WP_Error( 'fb_test_failed', $data['error']['message'] ?? 'Facebook validation failed.' );
                }
                return [
                    'success'    => true,
                    'platform'   => 'facebook',
                    'message'    => sprintf( __( 'Connected to Facebook Page: %s (ID: %s)', 'presshub-social-autopost' ), $data['name'] ?? '', $data['id'] ?? '' ),
                    // #247: when the token expires (0 = never or unknown), shown in Connections.
                    'expires_at' => self::token_expires_at( $token ),
                ];

            case 'instagram':
                $ig_id = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_instagram_account_id' )
                    ? PressHub_Social_Settings_Storage::get_instagram_account_id()
                    : '';
                $token = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_meta_access_token' )
                    ? PressHub_Social_Settings_Storage::get_meta_access_token()
                    : '';

                if ( empty( $ig_id ) || empty( $token ) ) {
                    return new WP_Error( 'missing_credentials', __( 'Instagram Account ID and Meta Access Token must be configured.', 'presshub-social-autopost' ) );
                }

                $url      = "https://graph.facebook.com/{$version}/{$ig_id}?fields=id,username&access_token=" . rawurlencode( $token );
                $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $data['error'] ) ) {
                    return new WP_Error( 'ig_test_failed', $data['error']['message'] ?? 'Instagram validation failed.' );
                }
                return [
                    'success'  => true,
                    'platform' => 'instagram',
                    'message'  => sprintf( __( 'Connected to Instagram Account: @%s (ID: %s)', 'presshub-social-autopost' ), $data['username'] ?? '', $data['id'] ?? '' ),
                ];

            case 'twitter':
                $bearer = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_twitter_bearer_token' )
                    ? PressHub_Social_Settings_Storage::get_twitter_bearer_token()
                    : '';
                if ( empty( $bearer ) ) {
                    return new WP_Error( 'missing_credentials', __( 'Twitter Bearer Token must be configured.', 'presshub-social-autopost' ) );
                }
                $response = wp_remote_get( 'https://api.twitter.com/2/users/me', [
                    'timeout' => 15,
                    'headers' => [ 'Authorization' => 'Bearer ' . $bearer ],
                ] );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $data['errors'] ) || ! empty( $data['title'] ) ) {
                    return new WP_Error( 'twitter_test_failed', $data['errors'][0]['message'] ?? ( $data['detail'] ?? 'Twitter test failed.' ) );
                }
                return [
                    'success'  => true,
                    'platform' => 'twitter',
                    'message'  => sprintf( __( 'Connected to Twitter/X user: @%s', 'presshub-social-autopost' ), $data['data']['username'] ?? 'authenticated' ),
                ];

            case 'tiktok':
                $token = class_exists( 'PressHub_Social_Settings_Storage' ) && method_exists( 'PressHub_Social_Settings_Storage', 'get_tiktok_access_token' )
                    ? PressHub_Social_Settings_Storage::get_tiktok_access_token()
                    : '';
                if ( empty( $token ) ) {
                    return new WP_Error( 'missing_credentials', __( 'TikTok Access Token must be configured.', 'presshub-social-autopost' ) );
                }
                $response = wp_remote_post( 'https://open.tiktokapis.com/v2/user/info/', [
                    'timeout' => 15,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                ] );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $data['error']['code'] ) && 'ok' !== strtolower( (string) $data['error']['code'] ) ) {
                    return new WP_Error( 'tiktok_test_failed', $data['error']['message'] ?? 'TikTok verification failed.' );
                }
                return [
                    'success'  => true,
                    'platform' => 'tiktok',
                    'message'  => __( 'Connected to TikTok Content Posting API.', 'presshub-social-autopost' ),
                ];

            default:
                return new WP_Error( 'invalid_platform', __( 'Unknown platform specified for connection test.', 'presshub-social-autopost' ) );
        }
    }
}
