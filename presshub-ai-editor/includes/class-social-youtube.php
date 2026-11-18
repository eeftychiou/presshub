<?php
/**
 * YouTube publishing through the YouTube Data API v3.
 *
 * What goes to YouTube:
 * - Shorts: the same shorts as TikTok and Instagram (PressHub_Social_TikTok_Shorts), one
 *   job per story of a podcast and one per article, when YouTube Shorts are on. YouTube
 *   files any vertical video of three minutes or less as a Short by itself.
 * - Podcast episodes: each Daily Briefing podcast post as one full 16:9 video, added to
 *   the podcast playlist (`presshub_social_youtube_podcast_playlist_id`), when podcast
 *   episodes are on. The playlist is made a podcast once, in YouTube Studio.
 *
 * Who does what:
 * - WordPress holds the Google OAuth client (Settings → Connections) and the refresh
 *   token stored by "Connect YouTube". It writes each job's title, description and
 *   settings at claim time (attach_upload()) and hands the worker a short-lived access
 *   token on request (REST POST /presshub-social/v1/worker/youtube/token).
 * - The worker on the PC renders the video (it already renders the shorts), uploads it
 *   with a resumable upload, adds an episode to the playlist and reports the video URL.
 *   The refresh token and the client secret never leave WordPress.
 *
 * Quota: an upload is charged to the Video Uploads bucket (100 uploads a day by default,
 * since 2026-06-01); a playlist insert and the connection test cost 50 and 1 units of the
 * general 10,000-unit daily quota.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_YouTube {

    /** OAuth scope: upload videos and add them to a playlist of the channel. */
    const SCOPE = 'https://www.googleapis.com/auth/youtube';

    const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    const API_BASE   = 'https://www.googleapis.com/youtube/v3';

    /** Refresh token stored by the sign-in. Not a form field: only Connect and Disconnect change it. */
    const REFRESH_TOKEN_OPTION = 'presshub_social_youtube_refresh_token';

    /** Channel the refresh token belongs to: [ id, title, connected_at ]. */
    const CHANNEL_OPTION = 'presshub_social_youtube_channel';

    /** Cached access token: [ token, expires_at ]. */
    const ACCESS_TOKEN_TRANSIENT = 'presshub_social_youtube_access_token';

    const STATE_TRANSIENT_PREFIX  = 'presshub_social_youtube_state_';
    const NOTICE_TRANSIENT_PREFIX = 'presshub_social_youtube_notice_';

    const CONNECT_ACTION    = 'presshub_social_youtube_connect';
    const CALLBACK_ACTION   = 'presshub_social_youtube_oauth';
    const DISCONNECT_ACTION = 'presshub_social_youtube_disconnect';

    /** Connection status check id (PressHub_Connection_Status). */
    const CHECK_ID = 'social:youtube';

    /** Job metadata key of a full podcast episode job. */
    const EPISODE_META_KEY = 'youtube_episode';

    /** YouTube limits on a video's metadata (API protocol limits, not tunable). */
    const TITLE_MAX_CHARS        = 100;
    const DESCRIPTION_MAX_BYTES  = 5000;
    const TAGS_MAX_CHARS         = 500;

    /**
     * Frame of a podcast episode: YouTube's standard 16:9 1080p player size. A wide frame
     * also keeps an episode of three minutes or less from being filed as a Short.
     */
    const EPISODE_WIDTH  = 1920;
    const EPISODE_HEIGHT = 1080;

    /** Timeout of the small JSON calls to Google's token and Data API endpoints. */
    const HTTP_TIMEOUT = 20;

    /** Lifetime of a sign-in attempt (state) in seconds. */
    const STATE_TTL = 900;

    /** An access token this close to expiry (seconds) is refreshed instead of reused. */
    const TOKEN_MARGIN = 300;

    /**
     * Optional HTTP override for tests.
     * Signature: fn( string $method, string $url, array $args ): array|WP_Error
     * where the array is [ 'code' => int, 'body' => string ].
     *
     * @var callable|null
     */
    private static $http = null;

    public static function set_http( ?callable $http ): void {
        self::$http = $http;
    }

    public static function register_hooks(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'admin_post_' . self::CONNECT_ACTION, [ __CLASS__, 'handle_connect' ] );
        add_action( 'admin_post_' . self::CALLBACK_ACTION, [ __CLASS__, 'handle_callback' ] );
        add_action( 'admin_post_' . self::DISCONNECT_ACTION, [ __CLASS__, 'handle_disconnect' ] );
        // A new client means the old sign-in no longer works with it.
        add_action( 'update_option_presshub_social_youtube_client_id', [ __CLASS__, 'forget_access_token' ], 10, 0 );
        add_action( 'update_option_presshub_social_youtube_client_secret', [ __CLASS__, 'forget_access_token' ], 10, 0 );
    }

    // ------------------------------------------------------------------
    // Connection state.
    // ------------------------------------------------------------------

    public static function has_client(): bool {
        return '' !== PressHub_Social_Settings_Storage::get_youtube_client_id()
            && '' !== trim( PressHub_Social_Settings_Storage::get_youtube_client_secret() );
    }

    public static function get_refresh_token(): string {
        return function_exists( 'get_option' ) ? trim( (string) get_option( self::REFRESH_TOKEN_OPTION, '' ) ) : '';
    }

    /** Whether uploads can run: an OAuth client and a signed-in channel. */
    public static function is_connected(): bool {
        return self::has_client() && '' !== self::get_refresh_token();
    }

    /** @return array{id?:string,title?:string,connected_at?:int} */
    public static function channel(): array {
        $channel = function_exists( 'get_option' ) ? get_option( self::CHANNEL_OPTION, [] ) : [];
        return is_array( $channel ) ? $channel : [];
    }

    /** The address Google sends the sign-in back to. It goes in the OAuth client's Authorized redirect URIs. */
    public static function redirect_uri(): string {
        return admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
    }

    /** Link of the Connect button (starts the sign-in, with a nonce). */
    public static function connect_link(): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CONNECT_ACTION ), self::CONNECT_ACTION );
    }

    public static function disconnect_link(): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISCONNECT_ACTION ), self::DISCONNECT_ACTION );
    }

    /** Google's consent screen URL for a sign-in attempt. */
    public static function authorization_url( string $state ): string {
        return self::AUTH_URL . '?' . http_build_query( [
            'client_id'              => PressHub_Social_Settings_Storage::get_youtube_client_id(),
            'redirect_uri'           => self::redirect_uri(),
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            // Always ask, so Google always returns a refresh token.
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ], '', '&', PHP_QUERY_RFC3986 );
    }

    // ------------------------------------------------------------------
    // Sign-in (admin-post handlers).
    // ------------------------------------------------------------------

    private static function require_cap(): void {
        if ( ! current_user_can( PressHub_Capabilities::social() ) ) {
            wp_die( esc_html__( 'You do not have permission to connect YouTube.', 'presshub-social-autopost' ), 403 );
        }
    }

    /** Connections page, at the YouTube account row. */
    public static function settings_url(): string {
        if ( class_exists( 'PressHub_Settings_Sections' ) ) {
            return PressHub_Settings_Sections::url( 'connections', 'presshub-youtube-account' );
        }
        return admin_url( 'admin.php?page=presshub-ai&section=connections' );
    }

    public static function handle_connect(): void {
        self::require_cap();
        check_admin_referer( self::CONNECT_ACTION );

        if ( ! self::has_client() ) {
            self::flash( false, __( 'Save the YouTube OAuth client ID and client secret first, then connect.', 'presshub-social-autopost' ) );
            self::redirect( self::settings_url() );
            return;
        }

        $state = wp_generate_password( 32, false, false );
        set_transient( self::STATE_TRANSIENT_PREFIX . $state, (int) get_current_user_id(), self::STATE_TTL );
        self::redirect( self::authorization_url( $state ), true );
    }

    public static function handle_callback(): void {
        self::require_cap();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the OAuth state below is the CSRF check.
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $result = self::complete_sign_in( $state, $code, $error, (int) get_current_user_id() );
        if ( is_wp_error( $result ) ) {
            self::flash( false, $result->get_error_message() );
        } else {
            /* translators: %s: YouTube channel name */
            self::flash( true, sprintf( __( 'YouTube connected: %s.', 'presshub-social-autopost' ), $result['title'] ?? '' ) );
        }
        self::redirect( self::settings_url() );
    }

    /**
     * Checks the state, swaps the code for tokens, stores the refresh token and reads the
     * channel. Separate from the handler so it can be tested.
     *
     * @return array{id:string,title:string}|WP_Error The connected channel.
     */
    public static function complete_sign_in( string $state, string $code, string $error, int $user_id ) {
        $owner = '' !== $state ? get_transient( self::STATE_TRANSIENT_PREFIX . $state ) : false;
        if ( '' !== $state ) {
            delete_transient( self::STATE_TRANSIENT_PREFIX . $state );
        }
        if ( false === $owner || (int) $owner !== $user_id ) {
            PressHub_Social_Logger::warning( 'YouTube sign-in refused: unknown or expired state.', [ 'user_id' => $user_id ] );
            return new WP_Error( 'youtube_bad_state', __( 'The YouTube sign-in expired or was not started here. Click Connect YouTube again.', 'presshub-social-autopost' ) );
        }
        if ( '' !== $error ) {
            PressHub_Social_Logger::warning( sprintf( 'YouTube sign-in was not completed: %s', $error ) );
            return new WP_Error( 'youtube_consent_' . sanitize_key( $error ), sprintf( __( 'Google did not complete the sign-in (%s).', 'presshub-social-autopost' ), $error ) );
        }
        if ( '' === $code ) {
            return new WP_Error( 'youtube_no_code', __( 'Google returned no authorization code.', 'presshub-social-autopost' ) );
        }

        $tokens = self::token_request( [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::redirect_uri(),
        ] );
        if ( is_wp_error( $tokens ) ) {
            return $tokens;
        }
        $refresh = trim( (string) ( $tokens['refresh_token'] ?? '' ) );
        if ( '' === $refresh ) {
            PressHub_Social_Logger::error( 'YouTube sign-in returned no refresh token.' );
            return new WP_Error( 'youtube_no_refresh_token', __( 'Google returned no refresh token. Remove PressHub from your Google account\'s third-party access and connect again.', 'presshub-social-autopost' ) );
        }

        update_option( self::REFRESH_TOKEN_OPTION, $refresh, false );
        self::cache_access_token( (string) ( $tokens['access_token'] ?? '' ), (int) ( $tokens['expires_in'] ?? 0 ) );

        $channel = self::fetch_channel();
        if ( is_wp_error( $channel ) ) {
            PressHub_Connection_Status::record( self::CHECK_ID, false, $channel->get_error_message() );
            return $channel;
        }
        update_option( self::CHANNEL_OPTION, [ 'id' => $channel['id'], 'title' => $channel['title'], 'connected_at' => time() ], false );
        PressHub_Connection_Status::record( self::CHECK_ID, true, sprintf( 'Connected to YouTube channel %s', $channel['title'] ) );
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            PressHub_AI_Audit_Logger::log( 'youtube_connected', 'connection', 0, [ 'channel_id' => $channel['id'], 'channel' => $channel['title'] ] );
        }
        PressHub_Social_Logger::info( sprintf( 'YouTube connected to channel "%s" (%s).', $channel['title'], $channel['id'] ) );
        return $channel;
    }

    public static function handle_disconnect(): void {
        self::require_cap();
        check_admin_referer( self::DISCONNECT_ACTION );
        self::disconnect();
        self::flash( true, __( 'YouTube disconnected. Nothing more is uploaded until you connect again.', 'presshub-social-autopost' ) );
        self::redirect( self::settings_url() );
    }

    /** Revokes the sign-in at Google (best effort) and forgets it here. */
    public static function disconnect(): void {
        $refresh = self::get_refresh_token();
        if ( '' !== $refresh ) {
            $res = self::http( 'POST', self::REVOKE_URL, [ 'body' => [ 'token' => $refresh ] ] );
            if ( is_wp_error( $res ) || ( $res['code'] ?? 0 ) >= 400 ) {
                PressHub_Social_Logger::warning( 'YouTube token could not be revoked at Google; it is forgotten here anyway.', [ 'http_code' => is_wp_error( $res ) ? 0 : (int) $res['code'] ] );
            }
        }
        delete_option( self::REFRESH_TOKEN_OPTION );
        delete_option( self::CHANNEL_OPTION );
        self::forget_access_token();
        PressHub_Connection_Status::forget( self::CHECK_ID );
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            PressHub_AI_Audit_Logger::log( 'youtube_disconnected', 'connection', 0, [] );
        }
        PressHub_Social_Logger::info( 'YouTube disconnected.' );
    }

    /** One-time message shown on the account row after a redirect. */
    private static function flash( bool $ok, string $text ): void {
        set_transient( self::NOTICE_TRANSIENT_PREFIX . (int) get_current_user_id(), [ 'ok' => $ok, 'text' => $text ], 300 );
    }

    /** @return array{ok:bool,text:string}|null */
    public static function take_notice(): ?array {
        if ( ! function_exists( 'get_current_user_id' ) ) {
            return null;
        }
        $key    = self::NOTICE_TRANSIENT_PREFIX . (int) get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) ) {
            return null;
        }
        delete_transient( $key );
        return $notice;
    }

    private static function redirect( string $url, bool $external = false ): void {
        if ( $external ) {
            add_filter( 'allowed_redirect_hosts', static function ( $hosts ) {
                $hosts[] = 'accounts.google.com';
                return $hosts;
            } );
        }
        wp_safe_redirect( $url );
        exit;
    }

    // ------------------------------------------------------------------
    // Tokens.
    // ------------------------------------------------------------------

    /**
     * A valid access token for the connected channel, refreshed when needed.
     *
     * @return array{access_token:string,expires_at:int}|WP_Error
     */
    public static function access_token() {
        if ( ! self::has_client() ) {
            return new WP_Error( 'youtube_no_client', 'YouTube is not set up: the OAuth client ID or secret is empty (Settings → Connections → YouTube).' );
        }
        $refresh = self::get_refresh_token();
        if ( '' === $refresh ) {
            return new WP_Error( 'youtube_not_connected', 'YouTube is not connected: click Connect YouTube in Settings → Connections.' );
        }

        $cached = get_transient( self::ACCESS_TOKEN_TRANSIENT );
        if ( is_array( $cached ) && ! empty( $cached['access_token'] ) && (int) ( $cached['expires_at'] ?? 0 ) > time() + self::TOKEN_MARGIN ) {
            return [ 'access_token' => (string) $cached['access_token'], 'expires_at' => (int) $cached['expires_at'] ];
        }

        $tokens = self::token_request( [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
        ] );
        if ( is_wp_error( $tokens ) ) {
            if ( 'youtube_invalid_grant' === $tokens->get_error_code() ) {
                // The sign-in was revoked or expired (a Testing app's sign-in lasts 7 days).
                PressHub_Connection_Status::record( self::CHECK_ID, false, $tokens->get_error_message() );
            }
            return $tokens;
        }
        $token = (string) ( $tokens['access_token'] ?? '' );
        if ( '' === $token ) {
            return new WP_Error( 'youtube_no_access_token', 'Google returned no access token.' );
        }
        return [ 'access_token' => $token, 'expires_at' => self::cache_access_token( $token, (int) ( $tokens['expires_in'] ?? 0 ) ) ];
    }

    private static function cache_access_token( string $token, int $expires_in ): int {
        if ( '' === $token ) {
            return 0;
        }
        $expires_in = $expires_in > 0 ? $expires_in : 3600;
        $expires_at = time() + $expires_in;
        set_transient( self::ACCESS_TOKEN_TRANSIENT, [ 'access_token' => $token, 'expires_at' => $expires_at ], max( 60, $expires_in - 60 ) );
        return $expires_at;
    }

    public static function forget_access_token(): void {
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( self::ACCESS_TOKEN_TRANSIENT );
        }
    }

    /**
     * POST to Google's token endpoint with the client credentials.
     *
     * @return array|WP_Error Decoded response.
     */
    private static function token_request( array $params ) {
        $params['client_id']     = PressHub_Social_Settings_Storage::get_youtube_client_id();
        $params['client_secret'] = PressHub_Social_Settings_Storage::get_youtube_client_secret();
        $started = microtime( true );
        $res     = self::http( 'POST', self::TOKEN_URL, [ 'body' => $params ] );
        $ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
        if ( is_wp_error( $res ) ) {
            PressHub_Social_Logger::error( 'YouTube token request failed: ' . $res->get_error_message(), [ 'provider' => 'youtube', 'action' => $params['grant_type'], 'duration_ms' => $ms ] );
            return new WP_Error( 'youtube_token_http', 'Could not reach Google to get a YouTube token: ' . $res->get_error_message() );
        }
        $data = json_decode( (string) $res['body'], true );
        $data = is_array( $data ) ? $data : [];
        if ( (int) $res['code'] >= 400 || ! empty( $data['error'] ) ) {
            $err  = (string) ( $data['error'] ?? 'http_' . $res['code'] );
            $desc = (string) ( $data['error_description'] ?? '' );
            PressHub_Social_Logger::error( sprintf( 'YouTube token request refused: %s %s', $err, $desc ), [ 'provider' => 'youtube', 'action' => $params['grant_type'], 'http_code' => (int) $res['code'], 'duration_ms' => $ms ] );
            if ( 'invalid_grant' === $err ) {
                return new WP_Error( 'youtube_invalid_grant', 'The YouTube sign-in is no longer valid (revoked, or expired after 7 days because the Google app is still in Testing). Connect YouTube again in Settings → Connections.' );
            }
            if ( 'invalid_client' === $err || 'unauthorized_client' === $err ) {
                return new WP_Error( 'youtube_invalid_client', 'Google refused the YouTube OAuth client ID or secret. Check them in Settings → Connections → YouTube.' );
            }
            return new WP_Error( 'youtube_token_refused', sprintf( 'Google refused the YouTube token request: %s %s', $err, $desc ) );
        }
        PressHub_Social_Logger::debug( 'YouTube token request OK.', [ 'provider' => 'youtube', 'action' => $params['grant_type'], 'http_code' => (int) $res['code'], 'duration_ms' => $ms ] );
        return $data;
    }

    // ------------------------------------------------------------------
    // Data API.
    // ------------------------------------------------------------------

    /**
     * The channel of the signed-in account (channels.list, 1 unit).
     *
     * @return array{id:string,title:string}|WP_Error
     */
    public static function fetch_channel() {
        $token = self::access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $res = self::http( 'GET', self::API_BASE . '/channels?part=snippet&mine=true', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
        ] );
        if ( is_wp_error( $res ) ) {
            return new WP_Error( 'youtube_channel_http', 'Could not reach YouTube: ' . $res->get_error_message() );
        }
        $data = json_decode( (string) $res['body'], true );
        if ( (int) $res['code'] >= 400 ) {
            $msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
            PressHub_Social_Logger::error( 'YouTube channels.list failed: ' . $msg, [ 'provider' => 'youtube', 'http_code' => (int) $res['code'] ] );
            return new WP_Error( 'youtube_channel_failed', sprintf( 'YouTube refused to read the channel (HTTP %d): %s', (int) $res['code'], $msg ) );
        }
        $item = is_array( $data ) ? ( $data['items'][0] ?? null ) : null;
        if ( ! is_array( $item ) || empty( $item['id'] ) ) {
            return new WP_Error( 'youtube_no_channel', 'This Google account has no YouTube channel. Create the channel on youtube.com, then connect again.' );
        }
        return [ 'id' => (string) $item['id'], 'title' => (string) ( $item['snippet']['title'] ?? $item['id'] ) ];
    }

    /** Test button on Connections: a fresh token and the channel name. */
    public static function test_connection(): array|WP_Error {
        self::forget_access_token();
        $channel = self::fetch_channel();
        if ( is_wp_error( $channel ) ) {
            return $channel;
        }
        $stored = self::channel();
        if ( ( $stored['id'] ?? '' ) !== $channel['id'] || ( $stored['title'] ?? '' ) !== $channel['title'] ) {
            update_option( self::CHANNEL_OPTION, array_merge( $stored, $channel ), false );
        }
        $message = sprintf( __( 'Connected to YouTube channel %s', 'presshub-social-autopost' ), $channel['title'] );
        if ( 'pull' !== PressHub_Social_Settings_Storage::get_worker_mode() ) {
            $message .= ' ' . __( '(uploads need the worker in Pull mode)', 'presshub-social-autopost' );
        }
        return [ 'success' => true, 'platform' => 'youtube', 'message' => $message ];
    }

    /**
     * @return array{code:int,body:string}|WP_Error
     */
    private static function http( string $method, string $url, array $args ) {
        if ( null !== self::$http ) {
            return call_user_func( self::$http, $method, $url, $args );
        }
        $args['timeout'] = self::HTTP_TIMEOUT;
        $res = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        return [ 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ) ];
    }

    // ------------------------------------------------------------------
    // Jobs.
    // ------------------------------------------------------------------

    /** Whether a post goes to YouTube as a full podcast episode. */
    public static function wants_episode( int $post_id ): bool {
        if ( ! PressHub_Social_Settings_Storage::get_youtube_podcast_enabled() || $post_id <= 0 ) {
            return false;
        }
        return 'podcast' === (string) get_post_meta( $post_id, '_presshub_briefing_type', true );
    }

    public static function is_episode_job( array $job ): bool {
        if ( 'youtube' !== ( $job['platform'] ?? '' ) ) {
            return false;
        }
        $meta = self::decode_metadata( $job['metadata'] ?? null );
        return ! empty( $meta[ self::EPISODE_META_KEY ] );
    }

    /** Job row of a podcast episode, for PressHub_Social_Queue::enqueue(). */
    public static function episode_job( int $post_id, string $copy, ?string $media_url, ?string $media_type, string $scheduled_at, ?string $failed_error = null ): array {
        return [
            'post_id'      => $post_id,
            'platform'     => 'youtube',
            'engine'       => 'automation',
            'post_copy'    => $copy,
            'media_url'    => $media_url,
            'media_type'   => $media_type,
            'scheduled_at' => $scheduled_at,
            'status'       => $failed_error ? 'failed' : 'pending',
            'last_error'   => $failed_error,
            'metadata'     => [ self::EPISODE_META_KEY => [ 'queued_at' => gmdate( 'c' ) ] ],
        ];
    }

    /**
     * Adds what the worker needs to upload a claimed YouTube job: `youtube` = kind, title,
     * description, tags, category, privacy, language, AI and kids declarations, and the
     * playlist of an episode. A short must already carry its prepared short.
     *
     * @return array|WP_Error
     */
    public static function attach_upload( array $job ) {
        $post_id = (int) ( $job['post_id'] ?? 0 );
        $post    = $post_id > 0 ? get_post( $post_id ) : null;
        $title   = $post ? (string) ( is_object( $post ) ? $post->post_title : ( $post['post_title'] ?? '' ) ) : '';
        $is_ep   = self::is_episode_job( $job );
        $is_shrt = ! empty( $job['tiktok_short'] );

        if ( ! $is_ep && ! $is_shrt ) {
            return new WP_Error( 'youtube_not_a_video', sprintf( 'YouTube job #%d is neither a short nor a podcast episode; YouTube only gets videos.', (int) ( $job['id'] ?? 0 ) ) );
        }
        if ( $is_ep && empty( $job['audio_url'] ) ) {
            return new WP_Error( 'youtube_episode_no_audio', sprintf( 'Podcast post #%d has no audio file, so there is no episode to upload.', $post_id ) );
        }

        $video_title = $is_shrt ? (string) ( $job['video_title'] ?? '' ) : $title;
        if ( '' === trim( wp_strip_all_tags( $video_title ) ) ) {
            $video_title = $title;
        }
        $url         = (string) ( $job['post_url'] ?? ( $post_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '' ) );
        $description = (string) ( $job['post_copy'] ?? '' );
        if ( '' !== $url && false === strpos( $description, $url ) ) {
            $description = rtrim( $description ) . "\n\n" . $url;
        }

        $locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
        $lang   = '' !== $locale ? strtolower( substr( $locale, 0, 2 ) ) : '';

        $upload = [
            'kind'                     => $is_ep ? 'episode' : 'short',
            'title'                    => self::clean_title( $video_title ),
            'description'              => self::clean_description( $description ),
            'tags'                     => self::tags_from( $description ),
            'category_id'              => (string) PressHub_Social_Settings_Storage::get_youtube_category_id(),
            'privacy_status'           => PressHub_Social_Settings_Storage::get_youtube_privacy_status(),
            // Narrated by an AI voice over AI images: YouTube asks for this to be declared.
            'contains_synthetic_media' => true,
            'made_for_kids'            => false,
        ];
        if ( '' !== $lang ) {
            $upload['default_language']       = $lang;
            $upload['default_audio_language'] = $lang;
        }
        if ( $is_ep ) {
            $upload['playlist_id'] = PressHub_Social_Settings_Storage::get_youtube_podcast_playlist_id();
            $upload['width']       = self::EPISODE_WIDTH;
            $upload['height']      = self::EPISODE_HEIGHT;
            if ( '' === $upload['playlist_id'] ) {
                PressHub_Social_Logger::warning( sprintf( 'YouTube episode job #%d is uploaded without a podcast playlist: the Podcast Playlist ID is empty.', (int) ( $job['id'] ?? 0 ) ) );
            }
        }
        if ( '' === $upload['title'] ) {
            return new WP_Error( 'youtube_no_title', sprintf( 'YouTube job #%d has no title.', (int) ( $job['id'] ?? 0 ) ) );
        }

        $job['youtube'] = $upload;
        return $job;
    }

    /** A title YouTube accepts: plain text, no < or >, one line, at most 100 characters. */
    public static function clean_title( string $title ): string {
        $title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $title = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( [ '<', '>' ], '', $title ) ) );
        if ( mb_strlen( $title, 'UTF-8' ) > self::TITLE_MAX_CHARS ) {
            $title = rtrim( mb_substr( $title, 0, self::TITLE_MAX_CHARS - 1, 'UTF-8' ) ) . '…';
        }
        return $title;
    }

    /** A description YouTube accepts: no < or >, at most 5000 bytes. */
    public static function clean_description( string $text ): string {
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( str_replace( [ '<', '>', "\r\n", "\r" ], [ '', '', "\n", "\n" ], $text ) );
        if ( strlen( $text ) > self::DESCRIPTION_MAX_BYTES ) {
            $text = rtrim( mb_strcut( $text, 0, self::DESCRIPTION_MAX_BYTES - 3, 'UTF-8' ) ) . '…';
        }
        return $text;
    }

    /**
     * Tags from the description's hashtags (without #), within YouTube's 500-character
     * total. #Shorts is left out: it is a hashtag, not a tag.
     *
     * @return string[]
     */
    public static function tags_from( string $text ): array {
        if ( ! preg_match_all( '/#([\p{L}\p{N}_]+)/u', $text, $m ) ) {
            return [];
        }
        $tags  = [];
        $total = 0;
        foreach ( $m[1] as $tag ) {
            $key = mb_strtolower( $tag, 'UTF-8' );
            if ( 'shorts' === $key || isset( $tags[ $key ] ) ) {
                continue;
            }
            $len = mb_strlen( $tag, 'UTF-8' ) + 1; // YouTube counts the separating comma.
            if ( $total + $len > self::TAGS_MAX_CHARS ) {
                break;
            }
            $tags[ $key ] = $tag;
            $total       += $len;
        }
        return array_values( $tags );
    }

    private static function decode_metadata( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return [];
    }
}
