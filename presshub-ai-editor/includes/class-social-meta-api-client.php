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

        $copy      = (string) ( $job['post_copy'] ?? '' );
        $media_url = ! empty( $job['media_url'] ) ? (string) $job['media_url'] : '';
        $has_image = ! empty( $media_url ) && ( empty( $job['media_type'] ) || 'image' === $job['media_type'] );

        $version = self::GRAPH_API_VERSION;

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

        // Step 1: Create media container
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

        $response1 = wp_remote_post( $container_endpoint, [
            'timeout'   => 45,
            'body'      => $container_body,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response1 ) ) {
            return $response1;
        }

        $code1  = (int) wp_remote_retrieve_response_code( $response1 );
        $data1  = json_decode( wp_remote_retrieve_body( $response1 ), true );

        if ( $code1 >= 400 || empty( $data1['id'] ) ) {
            $err_msg = $data1['error']['message'] ?? sprintf( 'Instagram container creation failed with HTTP %d', $code1 );
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Instagram media container error: ' . $err_msg );
            }
            return new WP_Error( 'instagram_container_error', $err_msg );
        }

        $creation_id = $data1['id'];

        // Step 2: Publish media container
        $publish_endpoint = "https://graph.facebook.com/{$version}/{$ig_user_id}/media_publish";
        $response2        = wp_remote_post( $publish_endpoint, [
            'timeout'   => 45,
            'body'      => [
                'creation_id'  => $creation_id,
                'access_token' => $token,
            ],
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response2 ) ) {
            return $response2;
        }

        $code2 = (int) wp_remote_retrieve_response_code( $response2 );
        $data2 = json_decode( wp_remote_retrieve_body( $response2 ), true );

        if ( $code2 >= 400 || empty( $data2['id'] ) ) {
            $err_msg = $data2['error']['message'] ?? sprintf( 'Instagram publish container failed with HTTP %d', $code2 );
            if ( class_exists( 'PressHub_Social_Logger' ) && method_exists( 'PressHub_Social_Logger', 'error' ) ) {
                PressHub_Social_Logger::error( 'Instagram media publish error: ' . $err_msg );
            }
            return new WP_Error( 'instagram_publish_error', $err_msg );
        }

        $media_id = (string) $data2['id'];

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
                    'success'  => true,
                    'platform' => 'facebook',
                    'message'  => sprintf( __( 'Connected to Facebook Page: %s (ID: %s)', 'presshub-social-autopost' ), $data['name'] ?? '', $data['id'] ?? '' ),
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
