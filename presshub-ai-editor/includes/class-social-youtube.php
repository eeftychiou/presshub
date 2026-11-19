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

    /** Set when Google refused the stored sign-in (invalid_grant): uploads stop until Connect again. */
    const NEEDS_RECONNECT_OPTION = 'presshub_social_youtube_needs_reconnect';

    /** Unix time until which uploads wait because the daily YouTube quota ran out. */
    const PAUSED_UNTIL_OPTION = 'presshub_social_youtube_paused_until';

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

    /** Most story images in one episode video. */
    const EPISODE_MAX_IMAGES = 30;

    /**
     * Longest video an unverified channel may upload (15 minutes). Longer uploads are
     * rejected by YouTube after they finish, until the channel is verified.
     */
    const LONG_UPLOAD_SECONDS = 900;

    /**
     * Seconds added to the audio length before comparing it with LONG_UPLOAD_SECONDS: the
     * worker adds up to about 3 seconds of silence at the end for the closing line.
     */
    const LONG_UPLOAD_MARGIN = 5;

    /** Seconds until a held episode is checked again: waiting for images, and for long uploads. */
    const HOLD_RECHECK_IMAGES = 300;
    const HOLD_RECHECK_LENGTH = 3600;

    /** Transient caching the channel's status.longUploadsStatus (an hour; ten minutes when unknown). */
    const LONG_UPLOADS_TRANSIENT = 'presshub_social_youtube_long_uploads';

    /** Timeout of the small JSON calls to Google's token and Data API endpoints. */
    const HTTP_TIMEOUT = 20;

    /** Lifetime of a sign-in attempt (state) in seconds. */
    const STATE_TTL = 900;

    /**
     * An access token this close to expiry (seconds) is refreshed instead of reused, so the
     * worker gets at least 20 minutes for a long episode upload (Google tokens last 60).
     */
    const TOKEN_MARGIN = 1200;

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
        add_action( 'update_option_presshub_social_youtube_client_id', [ __CLASS__, 'client_changed' ], 10, 2 );
        add_action( 'update_option_presshub_social_youtube_client_secret', [ __CLASS__, 'client_changed' ], 10, 2 );
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

    /**
     * Whether uploads can run: an OAuth client, a signed-in channel, and a sign-in Google
     * has not refused since.
     */
    public static function is_connected(): bool {
        return self::has_client() && '' !== self::get_refresh_token() && '' === self::reconnect_reason();
    }

    /**
     * Whether a channel was connected: an OAuth client and a stored sign-in, even one Google
     * refuses for now. New posts are queued for YouTube while this holds, so a refused
     * sign-in holds uploads (is_connected()) without dropping the posts made meanwhile.
     */
    public static function is_set_up(): bool {
        return self::has_client() && '' !== self::get_refresh_token();
    }

    /** Why the stored sign-in must be renewed (Google's refusal), or '' when it is fine. */
    public static function reconnect_reason(): string {
        return function_exists( 'get_option' ) ? trim( (string) get_option( self::NEEDS_RECONNECT_OPTION, '' ) ) : '';
    }

    /** Records that Google refused the stored sign-in, so claims stop handing out YouTube jobs. */
    public static function require_reconnect( string $reason ): void {
        $reason = '' !== trim( $reason ) ? trim( $reason ) : 'Google refused the stored YouTube sign-in.';
        update_option( self::NEEDS_RECONNECT_OPTION, $reason, false );
        self::forget_access_token();
        PressHub_Connection_Status::record( self::CHECK_ID, false, $reason );
        PressHub_Social_Logger::error( 'YouTube uploads stopped until the channel is connected again: ' . $reason );
    }

    /** Unix time until which YouTube uploads wait for the quota reset, or 0. */
    public static function paused_until(): int {
        $until = function_exists( 'get_option' ) ? (int) get_option( self::PAUSED_UNTIL_OPTION, 0 ) : 0;
        return $until > time() ? $until : 0;
    }

    /**
     * Pauses YouTube uploads until the daily quota resets (midnight Pacific Time, plus a
     * few minutes), and returns that time.
     */
    public static function pause_for_quota( string $reason ): int {
        $until = self::next_quota_reset();
        update_option( self::PAUSED_UNTIL_OPTION, $until, false );
        PressHub_Social_Logger::warning( sprintf( 'YouTube uploads paused until %s UTC (daily quota): %s', gmdate( 'Y-m-d H:i', $until ), $reason ) );
        return $until;
    }

    /** Next midnight in America/Los_Angeles (YouTube's quota day), plus 5 minutes, as Unix time. */
    public static function next_quota_reset( ?int $now = null ): int {
        $now = $now ?? time();
        try {
            $tz   = new DateTimeZone( 'America/Los_Angeles' );
            $next = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->modify( 'tomorrow' );
            return $next->getTimestamp() + 300;
        } catch ( Exception $e ) {
            return $now + DAY_IN_SECONDS;
        }
    }

    /**
     * A changed OAuth client cannot use the old sign-in: forget it (and its channel), so
     * Connections asks for Connect YouTube again instead of failing at upload time.
     *
     * @param mixed $old Previous value.
     * @param mixed $new New value.
     */
    public static function client_changed( $old = null, $new = null ): void {
        self::forget_access_token();
        if ( trim( (string) $old ) === trim( (string) $new ) || '' === self::get_refresh_token() ) {
            return;
        }
        delete_option( self::REFRESH_TOKEN_OPTION );
        delete_option( self::CHANNEL_OPTION );
        delete_option( self::NEEDS_RECONNECT_OPTION );
        delete_option( self::PAUSED_UNTIL_OPTION );
        PressHub_Connection_Status::forget( self::CHECK_ID );
        PressHub_Social_Logger::warning( 'YouTube OAuth client changed: the stored sign-in was removed. Click Connect YouTube again.' );
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

        $access = trim( (string) ( $tokens['access_token'] ?? '' ) );
        if ( '' === $access ) {
            PressHub_Social_Logger::error( 'YouTube sign-in returned no access token.' );
            return new WP_Error( 'youtube_no_access_token', __( 'Google returned no access token. Click Connect YouTube again.', 'presshub-social-autopost' ) );
        }

        // Read the channel with the new token before storing anything, so a sign-in with
        // an account that has no channel (or a failed channels.list) leaves the previous
        // connection, or none, exactly as it was.
        $channel = self::fetch_channel( $access );
        if ( is_wp_error( $channel ) ) {
            // Not revoked: Google revokes the whole grant, which would also end an earlier
            // connection of the same account that is still stored here.
            PressHub_Social_Logger::error( 'YouTube sign-in not saved: ' . $channel->get_error_message() );
            return $channel;
        }

        update_option( self::REFRESH_TOKEN_OPTION, $refresh, false );
        delete_option( self::NEEDS_RECONNECT_OPTION );
        // Another channel or project has its own quota.
        delete_option( self::PAUSED_UNTIL_OPTION );
        self::forget_access_token();
        self::cache_access_token( $access, (int) ( $tokens['expires_in'] ?? 0 ) );
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
        delete_option( self::NEEDS_RECONNECT_OPTION );
        delete_option( self::PAUSED_UNTIL_OPTION );
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
    public static function access_token( bool $retry_refused = false, bool $fresh = false ) {
        if ( ! self::has_client() ) {
            return new WP_Error( 'youtube_no_client', 'YouTube is not set up: the OAuth client ID or secret is empty (Settings → Connections → YouTube).' );
        }
        $refresh = self::get_refresh_token();
        if ( '' === $refresh ) {
            return new WP_Error( 'youtube_not_connected', 'YouTube is not connected: click Connect YouTube in Settings → Connections.' );
        }
        if ( ! $retry_refused && '' !== self::reconnect_reason() ) {
            return new WP_Error( 'youtube_not_connected', 'Google refused the stored YouTube sign-in (' . self::reconnect_reason() . '): click Connect YouTube in Settings → Connections.' );
        }

        $cached = $fresh ? null : get_transient( self::ACCESS_TOKEN_TRANSIENT );
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
                self::require_reconnect( $tokens->get_error_message() );
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
            // The channel's long-upload status is read again with the next sign-in too.
            delete_transient( self::LONG_UPLOADS_TRANSIENT );
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
    public static function fetch_channel( string $access_token = '' ) {
        if ( '' === $access_token ) {
            $token = self::access_token();
            if ( is_wp_error( $token ) ) {
                return $token;
            }
            $access_token = (string) $token['access_token'];
        }
        $res = self::http( 'GET', self::API_BASE . '/channels?part=snippet&mine=true', [
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
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

    /**
     * Checks that the podcast playlist exists on the connected channel (playlists.list, 1 unit).
     *
     * @return string|WP_Error The playlist title.
     */
    public static function check_playlist( string $playlist_id, string $channel_id = '' ) {
        $token = self::access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $res = self::http( 'GET', self::API_BASE . '/playlists?part=snippet&id=' . rawurlencode( $playlist_id ), [
            'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
        ] );
        if ( is_wp_error( $res ) ) {
            return new WP_Error( 'youtube_playlist_http', 'Could not reach YouTube: ' . $res->get_error_message() );
        }
        $data = json_decode( (string) $res['body'], true );
        if ( (int) $res['code'] >= 400 ) {
            $msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
            return new WP_Error( 'youtube_playlist_failed', sprintf( 'YouTube refused to read the podcast playlist (HTTP %d): %s', (int) $res['code'], $msg ) );
        }
        $item = is_array( $data ) ? ( $data['items'][0] ?? null ) : null;
        if ( ! is_array( $item ) ) {
            return new WP_Error( 'youtube_playlist_missing', sprintf( 'The podcast playlist %s does not exist or is not visible to this channel. Check the Podcast Playlist ID under Shorts → YouTube.', $playlist_id ) );
        }
        $owner = (string) ( $item['snippet']['channelId'] ?? '' );
        if ( '' !== $channel_id && '' !== $owner && $owner !== $channel_id ) {
            return new WP_Error( 'youtube_playlist_other_channel', sprintf( 'The podcast playlist %s belongs to another channel, so episodes cannot be added to it.', $playlist_id ) );
        }
        return (string) ( $item['snippet']['title'] ?? $playlist_id );
    }

    /** Test button on Connections: a fresh token and the channel name. */
    public static function test_connection(): array|WP_Error {
        self::forget_access_token();
        // The test tries the stored sign-in even after a refusal, so it can clear one.
        $token = self::access_token( true );
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $channel = self::fetch_channel( (string) $token['access_token'] );
        if ( is_wp_error( $channel ) ) {
            return $channel;
        }
        $stored = self::channel();
        if ( ( $stored['id'] ?? '' ) !== $channel['id'] || ( $stored['title'] ?? '' ) !== $channel['title'] ) {
            update_option( self::CHANNEL_OPTION, array_merge( $stored, $channel ), false );
        }
        // The sign-in works again: a reconnect flag from an earlier refusal is stale.
        delete_option( self::NEEDS_RECONNECT_OPTION );
        $message = sprintf( __( 'Connected to YouTube channel %s', 'presshub-social-autopost' ), $channel['title'] );

        $playlist = PressHub_Social_Settings_Storage::get_youtube_podcast_playlist_id();
        if ( '' !== $playlist && PressHub_Social_Settings_Storage::get_youtube_podcast_enabled() ) {
            $check = self::check_playlist( $playlist, $channel['id'] );
            if ( is_wp_error( $check ) ) {
                return $check;
            }
            $message .= '. ' . sprintf( __( 'Podcast playlist: %s', 'presshub-social-autopost' ), $check );
        }
        if ( 'pull' !== PressHub_Social_Settings_Storage::get_worker_mode() ) {
            $message .= ' ' . __( '(uploads need the worker in Pull mode)', 'presshub-social-autopost' );
        }
        $until = self::paused_until();
        if ( $until > 0 ) {
            $message .= ' ' . sprintf( __( '(uploads wait for the daily quota until %s UTC)', 'presshub-social-autopost' ), gmdate( 'Y-m-d H:i', $until ) );
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

    /**
     * Language of an episode job made from a translation of the podcast (en, tr), or ''
     * for the Greek episode. See PressHub_AI_Podcast_Translations.
     */
    public static function episode_language( array $job ): string {
        $meta = self::decode_metadata( $job['metadata'] ?? null );
        $lang = (string) ( $meta[ self::EPISODE_META_KEY ]['language'] ?? '' );
        return ( '' !== $lang && class_exists( 'PressHub_AI_Podcast_Translations' ) && PressHub_AI_Podcast_Translations::is_language( $lang ) ) ? $lang : '';
    }

    /** A job's platform as the Queue and the post box show it: "Youtube (EN)" for a language episode. */
    public static function platform_label( array $job ): string {
        $label = ucfirst( (string) ( $job['platform'] ?? '' ) );
        $lang  = 'youtube' === ( $job['platform'] ?? '' ) ? self::episode_language( $job ) : '';
        return '' !== $lang ? sprintf( '%s (%s)', $label, strtoupper( $lang ) ) : $label;
    }

    /**
     * Job row of a podcast episode, for PressHub_Social_Queue::enqueue(). $language
     * (en, tr) makes it the episode of that translation instead of the Greek one.
     */
    public static function episode_job( int $post_id, string $copy, ?string $media_url, ?string $media_type, string $scheduled_at, ?string $failed_error = null, string $language = '' ): array {
        $episode = [ 'queued_at' => gmdate( 'c' ) ];
        if ( '' !== $language ) {
            $episode['language']         = $language;
            $episode['description_hash'] = md5( trim( $copy ) );
        }
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
            'metadata'     => [ self::EPISODE_META_KEY => $episode ],
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
        // An English or Turkish episode: the translation's audio and title.
        $ep_lang = $is_ep ? self::episode_language( $job ) : '';
        if ( '' !== $ep_lang ) {
            $tr               = PressHub_AI_Podcast_Translations::get_entry( $post_id, $ep_lang );
            // Only a voiced translation has audio that matches its title and captions.
            $job['audio_url'] = 'voiced' === ( $tr['status'] ?? '' ) ? (string) ( $tr['audio_url'] ?? '' ) : '';
            if ( '' !== $job['audio_url'] && '' === trim( (string) ( $tr['title'] ?? '' ) ) ) {
                // Never the Greek title on an English or Turkish episode.
                return new WP_Error( 'youtube_episode_no_title', sprintf( 'The %s version of podcast post #%d has no title, so it is not uploaded.', PressHub_AI_Podcast_Translations::LANGUAGES[ $ep_lang ]['name'], $post_id ) );
            }
            $title = '' !== trim( (string) ( $tr['title'] ?? '' ) ) ? (string) $tr['title'] : $title;
            // A translation made again after the job was queued brings its new description,
            // unless the copy was edited by hand on the Queue.
            $tr_copy = trim( (string) ( $tr['description'] ?? '' ) );
            $queued  = (string) ( self::decode_metadata( $job['metadata'] ?? null )[ self::EPISODE_META_KEY ]['description_hash'] ?? '' );
            if ( '' !== $tr_copy && '' !== $queued && md5( trim( (string) ( $job['post_copy'] ?? '' ) ) ) === $queued ) {
                $job['post_copy'] = $tr_copy;
            }
        }

        if ( ! $is_ep && ! $is_shrt ) {
            return new WP_Error( 'youtube_not_a_video', sprintf( 'YouTube job #%d is neither a short nor a podcast episode; YouTube only gets videos.', (int) ( $job['id'] ?? 0 ) ) );
        }
        if ( $is_ep && empty( $job['audio_url'] ) ) {
            return new WP_Error( 'youtube_episode_no_audio', '' !== $ep_lang
                ? sprintf( 'The %s version of podcast post #%d has no audio file, so there is no episode to upload.', PressHub_AI_Podcast_Translations::LANGUAGES[ $ep_lang ]['name'], $post_id )
                : sprintf( 'Podcast post #%d has no audio file, so there is no episode to upload.', $post_id ) );
        }

        $video_title = $is_shrt ? (string) ( $job['video_title'] ?? '' ) : $title;
        if ( '' === trim( wp_strip_all_tags( $video_title ) ) ) {
            $video_title = $title;
        }
        $url         = (string) ( $job['post_url'] ?? ( $post_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '' ) );
        $description = self::youtube_cta( (string) ( $job['post_copy'] ?? '' ) );
        if ( '' !== $url && false === strpos( $description, $url ) ) {
            $description = rtrim( $description ) . "\n\n" . $url;
        }

        $locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
        $lang   = '' !== $ep_lang ? $ep_lang : ( '' !== $locale ? strtolower( substr( $locale, 0, 2 ) ) : '' );

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
            $video = '' !== $ep_lang ? PressHub_AI_Podcast_Translations::episode_video( $post_id, $ep_lang ) : self::episode_video( $post_id );
            if ( null === $video && PressHub_Social_Settings_Storage::get_youtube_episode_enhanced() ) {
                $tl_error = ( '' === $ep_lang && class_exists( 'PressHub_AI_Podcast_Timeline' ) ) ? PressHub_AI_Podcast_Timeline::error( $post_id ) : '';
                $why      = '' !== $tl_error
                    ? sprintf( 'its caption timeline could not be built when the podcast was made (%s)', $tl_error )
                    : ( '' !== $ep_lang ? 'its translation has no caption timeline' : 'the podcast has no caption timeline (made before 2.54.0)' );
                PressHub_Social_Logger::warning( sprintf( 'YouTube episode job #%d (post #%d) is rendered the plain way, on the featured image without captions: %s and its shorts have no story images.', (int) ( $job['id'] ?? 0 ), $post_id, $why ), [ 'job_id' => (int) ( $job['id'] ?? 0 ), 'post_id' => $post_id ] );
            } elseif ( null !== $video ) {
                $upload['episode_video'] = $video;
                $tl_error                = ( '' === $ep_lang && ! isset( $video['segments'] ) && class_exists( 'PressHub_AI_Podcast_Timeline' ) ) ? PressHub_AI_Podcast_Timeline::error( $post_id ) : '';
                if ( '' !== $tl_error ) {
                    PressHub_Social_Logger::warning( sprintf( 'YouTube episode job #%d (post #%d) has story images but no captions: its caption timeline could not be built when the podcast was made (%s).', (int) ( $job['id'] ?? 0 ), $post_id, $tl_error ), [ 'job_id' => (int) ( $job['id'] ?? 0 ), 'post_id' => $post_id ] );
                }
                // Chapters only from a measured timeline: an estimated one (MP3) may be off.
                $timeline                = '' !== $ep_lang ? PressHub_AI_Podcast_Translations::timeline( $post_id, $ep_lang ) : ( class_exists( 'PressHub_AI_Podcast_Timeline' ) ? PressHub_AI_Podcast_Timeline::get( $post_id ) : null );
                $chapters                = class_exists( 'PressHub_AI_Podcast_Timeline' ) && null !== $timeline && ! empty( $video['scenes'] ) && empty( $video['estimated'] )
                    ? PressHub_AI_Podcast_Timeline::chapters( $timeline )
                    : '';
                $upload['description']   = self::with_chapters( $upload['description'], $chapters );
            }
            // Each language has its own playlist; the Greek one is never used for a translation.
            $upload['playlist_id'] = '' !== $ep_lang
                ? PressHub_Social_Settings_Storage::get_youtube_podcast_playlist_id_for( $ep_lang )
                : PressHub_Social_Settings_Storage::get_youtube_podcast_playlist_id();
            $upload['width']       = self::EPISODE_WIDTH;
            $upload['height']      = self::EPISODE_HEIGHT;
            if ( '' === $upload['playlist_id'] ) {
                PressHub_Social_Logger::warning( '' !== $ep_lang
                    ? sprintf( 'YouTube %s episode job #%d is uploaded without a playlist: its playlist ID under Shorts, YouTube is empty.', PressHub_AI_Podcast_Translations::LANGUAGES[ $ep_lang ]['name'], (int) ( $job['id'] ?? 0 ) )
                    : sprintf( 'YouTube episode job #%d is uploaded without a podcast playlist: the Podcast Playlist ID is empty.', (int) ( $job['id'] ?? 0 ) ) );
            }
        }
        if ( '' === $upload['title'] ) {
            return new WP_Error( 'youtube_no_title', sprintf( 'YouTube job #%d has no title.', (int) ( $job['id'] ?? 0 ) ) );
        }

        $job['youtube'] = $upload;
        return $job;
    }

    /**
     * Why a due episode must wait, or null when it may be uploaded now:
     * [ 'delay' => seconds until it is due again, 'note' => why, shown on the Queue ].
     * The claim moves a held episode's scheduled_at forward by the delay (no attempt is
     * used), so held episodes never crowd the front of the queue. Length is checked first,
     * so the image wait (and its timeout warning) only runs for an episode that may go up.
     * - Length (episode_length_ok()): while the episode is longer than 15 minutes and the
     *   channel may not upload long videos (status.longUploadsStatus is not "allowed").
     * - Story images (episode_ready()): while a short of the post is still being prepared,
     *   for at most Wait for Story Images after the episode was queued.
     */
    public static function episode_hold( array $job, ?int $now = null ): ?array {
        if ( ! self::is_episode_job( $job ) ) {
            return null;
        }
        // An English or Turkish episode translated again waits for its new audio.
        $ep_lang = self::episode_language( $job );
        if ( '' !== $ep_lang && 'translated' === ( PressHub_AI_Podcast_Translations::get_entry( (int) ( $job['post_id'] ?? 0 ), $ep_lang )['status'] ?? '' ) ) {
            return [ 'delay' => self::HOLD_RECHECK_IMAGES, 'note' => sprintf( 'Waiting: the %s version is being voiced again.', PressHub_AI_Podcast_Translations::LANGUAGES[ $ep_lang ]['name'] ) ];
        }
        if ( ! self::episode_length_ok( $job ) ) {
            $status = self::long_uploads_status();
            return [
                'delay' => self::HOLD_RECHECK_LENGTH,
                'note'  => sprintf(
                    'Waiting: the episode is %d minutes long and the YouTube channel may not upload videos over 15 minutes yet (long uploads: %s). Verify the channel in YouTube Studio (Settings, Channel, Feature eligibility); the episode then goes up on its own.',
                    (int) ceil( self::job_seconds( $job ) / 60 ),
                    '' !== $status ? $status : 'could not be read'
                ),
            ];
        }
        if ( ! self::episode_ready( $job, $now ) ) {
            return [ 'delay' => self::HOLD_RECHECK_IMAGES, 'note' => sprintf( 'Waiting for the story images of the podcast\'s shorts, for at most %d minutes after the episode was queued.', PressHub_Social_Settings_Storage::get_youtube_episode_image_wait_minutes() ) ];
        }
        return null;
    }

    /**
     * Whether an episode has the story images it waits for: once no short of its post is
     * still being prepared, or Wait for Story Images minutes after it was queued (then
     * with a WARNING naming what is missing). Always true for non-episodes and when
     * enhanced episodes are off.
     */
    public static function episode_ready( array $job, ?int $now = null ): bool {
        if ( ! self::is_episode_job( $job ) || ! PressHub_Social_Settings_Storage::get_youtube_episode_enhanced() ) {
            return true;
        }
        $post_id = (int) ( $job['post_id'] ?? 0 );
        $waiting = 0;
        foreach ( self::short_jobs( $post_id ) as [ $short_job, $short ] ) {
            if ( in_array( $short_job['status'] ?? '', [ 'pending', 'processing' ], true ) && ! is_array( $short['images'] ?? null ) ) {
                $waiting++;
            }
        }
        if ( 0 === $waiting ) {
            return true;
        }
        $meta   = self::decode_metadata( $job['metadata'] ?? null );
        $queued = strtotime( (string) ( $meta[ self::EPISODE_META_KEY ]['queued_at'] ?? '' ) );
        if ( false === $queued || $queued <= 0 ) {
            return true;
        }
        $wait = PressHub_Social_Settings_Storage::get_youtube_episode_image_wait_minutes();
        if ( ( $now ?? time() ) - $queued < $wait * 60 ) {
            return false;
        }
        PressHub_Social_Logger::warning(
            sprintf( 'YouTube episode job #%d (post #%d) is uploaded without the images of %d short job(s) still not prepared after %d minutes (Wait for Story Images).', (int) ( $job['id'] ?? 0 ), $post_id, $waiting, $wait ),
            [ 'job_id' => (int) ( $job['id'] ?? 0 ), 'post_id' => $post_id ]
        );
        return true;
    }

    /**
     * Whether an episode's length is one the channel may upload: up to 15 minutes always
     * (with a margin for the silence the worker adds at the end), longer only when YouTube
     * reports long uploads as "allowed" (a verified channel). An unreadable status holds
     * the episode too: a long upload would most likely be rejected after it finished.
     */
    public static function episode_length_ok( array $job ): bool {
        if ( ! self::is_episode_job( $job ) ) {
            return true;
        }
        $seconds = self::job_seconds( $job );
        if ( $seconds + self::LONG_UPLOAD_MARGIN <= self::LONG_UPLOAD_SECONDS ) {
            return true;
        }
        return 'allowed' === self::long_uploads_status();
    }

    /** Length of an episode job's audio: its translation's for an English or Turkish episode. */
    public static function job_seconds( array $job ): float {
        $post_id = (int) ( $job['post_id'] ?? 0 );
        $ep_lang = self::episode_language( $job );
        return '' !== $ep_lang ? PressHub_AI_Podcast_Translations::duration( $post_id, $ep_lang ) : self::episode_seconds( $post_id );
    }

    /**
     * Length of a podcast post's audio in seconds: a measured timeline's, else the longer of
     * an estimated timeline and the stored audio length (an MP3 estimate can be short).
     */
    public static function episode_seconds( int $post_id ): float {
        $timeline = class_exists( 'PressHub_AI_Podcast_Timeline' ) ? PressHub_AI_Podcast_Timeline::get( $post_id ) : null;
        if ( null !== $timeline && empty( $timeline['estimated'] ) ) {
            return (float) $timeline['duration'];
        }
        $stored = $post_id > 0 ? (float) get_post_meta( $post_id, '_presshub_audio_duration_sec', true ) : 0.0;
        return max( $stored, null !== $timeline ? (float) $timeline['duration'] : 0.0 );
    }

    /**
     * The channel's status.longUploadsStatus (allowed, eligible, disallowed), or '' when it
     * cannot be read. channels.list costs 1 quota unit; the answer is cached.
     */
    public static function long_uploads_status(): string {
        $cached = get_transient( self::LONG_UPLOADS_TRANSIENT );
        if ( is_array( $cached ) ) {
            return (string) ( $cached['status'] ?? '' );
        }
        $status = '';
        $why    = '';
        $token  = self::access_token();
        if ( is_wp_error( $token ) ) {
            $why = $token->get_error_message();
        } else {
            $res = self::http( 'GET', self::API_BASE . '/channels?part=status&mine=true', [
                'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
            ] );
            if ( is_wp_error( $res ) ) {
                $why = $res->get_error_message();
            } elseif ( (int) $res['code'] >= 400 ) {
                $why = sprintf( 'HTTP %d', (int) $res['code'] );
            } else {
                $data   = json_decode( (string) $res['body'], true );
                $status = is_array( $data ) ? (string) ( $data['items'][0]['status']['longUploadsStatus'] ?? '' ) : '';
                $why    = '' === $status ? 'no longUploadsStatus in the answer' : '';
            }
        }
        if ( '' === $status ) {
            PressHub_Social_Logger::warning( 'Could not read whether the YouTube channel may upload videos over 15 minutes (' . $why . '); episodes over 15 minutes wait until it can be read.', [ 'provider' => 'youtube' ] );
        }
        set_transient( self::LONG_UPLOADS_TRANSIENT, [ 'status' => $status ], '' === $status ? 600 : 3600 );
        return $status;
    }

    /**
     * Short jobs of a post (every platform, test renders left out), newest first, each
     * with its decoded `tiktok_short` metadata.
     *
     * @return array<int, array{0: array, 1: array}>
     */
    private static function short_jobs( int $post_id ): array {
        if ( $post_id <= 0 || ! class_exists( 'PressHub_Social_TikTok_Shorts' ) ) {
            return [];
        }
        $out = [];
        foreach ( PressHub_Social_Queue::get_jobs_for_post( $post_id ) as $job ) {
            // A short an editor cancelled is left out, with its images.
            if ( ! PressHub_Social_TikTok_Shorts::is_short_job( $job ) || PressHub_Social_TikTok_Shorts::is_test_job( $job ) || 'cancelled' === ( $job['status'] ?? '' ) ) {
                continue;
            }
            $short = self::decode_metadata( $job['metadata'] ?? null )[ PressHub_Social_TikTok_Shorts::JOB_META_KEY ] ?? null;
            if ( is_array( $short ) ) {
                $out[] = [ $job, $short ];
            }
        }
        return $out;
    }

    /**
     * Story images of a post's shorts, one group per short in story order:
     * [ [ 'index' => int, 'topic' => string, 'urls' => string[] ], ... ]. Each short is
     * taken once (its TikTok, Reels and YouTube jobs share the same images), and no URL
     * appears twice. At most EPISODE_MAX_IMAGES in all.
     */
    public static function short_image_groups( int $post_id ): array {
        $groups = [];
        foreach ( self::short_jobs( $post_id ) as [ , $short ] ) {
            $index = (int) ( $short['index'] ?? -1 );
            if ( $index < 0 || empty( $short['images'] ) || ! is_array( $short['images'] ) ) {
                continue;
            }
            $urls = [];
            foreach ( $short['images'] as $image ) {
                $url = is_array( $image ) ? (string) ( $image['url'] ?? '' ) : (string) $image;
                if ( '' !== $url ) {
                    $urls[ $url ] = true;
                }
            }
            // The job of this short with the most images (one may have lost an image).
            if ( ! empty( $urls ) && count( $urls ) > count( $groups[ $index ]['urls'] ?? [] ) ) {
                $groups[ $index ] = [ 'index' => $index, 'topic' => trim( (string) ( $short['topic'] ?? '' ) ), 'urls' => array_keys( $urls ) ];
            }
        }
        ksort( $groups );
        $seen = [];
        foreach ( $groups as $i => $group ) {
            $groups[ $i ]['urls'] = array_values( array_filter( $group['urls'], static function ( $url ) use ( &$seen ) {
                if ( isset( $seen[ $url ] ) ) {
                    return false;
                }
                $seen[ $url ] = true;
                return true;
            } ) );
            if ( empty( $groups[ $i ]['urls'] ) ) {
                unset( $groups[ $i ] );
            }
        }
        $out   = [];
        $total = 0;
        foreach ( $groups as $group ) {
            $room = self::EPISODE_MAX_IMAGES - $total;
            if ( $room <= 0 ) {
                break;
            }
            $group['urls'] = array_slice( $group['urls'], 0, $room );
            $total        += count( $group['urls'] );
            $out[]         = $group;
        }
        return $out;
    }

    /**
     * What the worker needs to render the enhanced episode, or null when there is
     * nothing to add to the plain episode (no timeline and no story images):
     * - `duration`, `estimated`: the timeline's length and whether it is estimated (MP3),
     *   so the worker can fit it to the real audio.
     * - `segments`: the spoken turns, for the captions.
     * - `scenes`: one per topic [ start, end, title, images ], each with the images of the
     *   short about that topic (see episode_scenes()).
     * - `images`: every story image, for an episode without a timeline.
     */
    public static function episode_video( int $post_id ): ?array {
        if ( ! PressHub_Social_Settings_Storage::get_youtube_episode_enhanced() ) {
            return null;
        }
        $timeline = class_exists( 'PressHub_AI_Podcast_Timeline' ) ? PressHub_AI_Podcast_Timeline::get( $post_id ) : null;
        $groups   = self::short_image_groups( $post_id );
        if ( null === $timeline && empty( $groups ) ) {
            return null;
        }
        $images = [];
        foreach ( $groups as $group ) {
            array_push( $images, ...$group['urls'] );
        }
        $video = [ 'images' => $images ];
        if ( null !== $timeline ) {
            $video['duration']  = (float) $timeline['duration'];
            $video['estimated'] = ! empty( $timeline['estimated'] );
            $video['segments']  = array_values( array_map(
                static fn( $s ) => [
                    'speaker' => 2 === (int) ( $s['speaker'] ?? 1 ) ? 2 : 1,
                    'text'    => (string) ( $s['text'] ?? '' ),
                    'start'   => (float) ( $s['start'] ?? 0 ),
                    'end'     => (float) ( $s['end'] ?? 0 ),
                ],
                (array) $timeline['segments']
            ) );
            $video['scenes']    = self::episode_scenes( (array) ( $timeline['topics'] ?? [] ), $groups, (float) $timeline['duration'] );
        }
        return $video;
    }

    /**
     * Scenes of an episode, one per topic, covering the whole audio: the first starts at
     * 0 (with the intro) and each ends where the next begins. Each topic gets the images of
     * the short whose topic shares the most words with it; a topic no short matches gets
     * the shorts left over in turn, or every image when none is left.
     *
     * @param array $topics [ [ 'title', 'start', 'end' ], ... ] from the timeline.
     * @param array $groups short_image_groups().
     */
    public static function episode_scenes( array $topics, array $groups, float $duration ): array {
        $topics = array_values( array_filter( $topics, 'is_array' ) );
        $all    = [];
        foreach ( $groups as $group ) {
            array_push( $all, ...$group['urls'] );
        }
        if ( empty( $topics ) ) {
            return [ [ 'start' => 0.0, 'end' => $duration, 'title' => '', 'images' => $all ] ];
        }

        // Best match first, each short and each topic used once.
        $pairs = [];
        foreach ( $topics as $ti => $topic ) {
            foreach ( $groups as $gi => $group ) {
                $score = self::topic_overlap( (string) ( $topic['title'] ?? '' ), (string) $group['topic'] );
                if ( $score > 0 ) {
                    $pairs[] = [ $score, $ti, $gi ];
                }
            }
        }
        usort( $pairs, static fn( $a, $b ) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1] );
        $topic_group = [];
        $used        = [];
        foreach ( $pairs as [ , $ti, $gi ] ) {
            if ( isset( $topic_group[ $ti ] ) || isset( $used[ $gi ] ) ) {
                continue;
            }
            $topic_group[ $ti ] = $gi;
            $used[ $gi ]        = true;
        }
        $left = array_values( array_diff( array_keys( $groups ), array_keys( $used ) ) );
        $next = 0;

        $scenes = [];
        $count  = count( $topics );
        foreach ( $topics as $ti => $topic ) {
            if ( isset( $topic_group[ $ti ] ) ) {
                $images = $groups[ $topic_group[ $ti ] ]['urls'];
            } elseif ( ! empty( $left ) ) {
                $images = $groups[ $left[ $next % count( $left ) ] ]['urls'];
                $next++;
            } else {
                $images = $all;
            }
            $scenes[] = [
                'start'  => 0 === $ti ? 0.0 : (float) ( $topic['start'] ?? 0 ),
                'end'    => $ti + 1 < $count ? (float) ( $topics[ $ti + 1 ]['start'] ?? $duration ) : $duration,
                'title'  => trim( (string) ( $topic['title'] ?? '' ) ),
                'images' => array_values( $images ),
            ];
        }
        return $scenes;
    }

    /**
     * How alike two topic titles are, 0-1: the share of the shorter title's words (by
     * their first five letters, without accents, so Greek inflections still match) that
     * the other title has too. Words of fewer than four letters are ignored.
     */
    public static function topic_overlap( string $a, string $b ): float {
        $stems = static function ( string $text ): array {
            $text  = mb_strtolower( $text, 'UTF-8' );
            $text  = strtr( $text, [ 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ' ] );
            $words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
            $out   = [];
            foreach ( $words as $w ) {
                if ( mb_strlen( $w, 'UTF-8' ) >= 4 ) {
                    $out[ mb_substr( $w, 0, 5, 'UTF-8' ) ] = true;
                }
            }
            return $out;
        };
        $sa = $stems( $a );
        $sb = $stems( $b );
        if ( empty( $sa ) || empty( $sb ) ) {
            return 0.0;
        }
        return count( array_intersect_key( $sa, $sb ) ) / min( count( $sa ), count( $sb ) );
    }

    /**
     * The episode description with its chapter list ("0:00 Title" lines) after the text and
     * before the closing link and hashtag lines, which are kept whole. Only the text is
     * shortened when everything would not fit in DESCRIPTION_MAX_BYTES.
     */
    public static function with_chapters( string $description, string $chapters ): string {
        $chapters = trim( str_replace( [ '<', '>' ], '', $chapters ) );
        if ( '' === $chapters ) {
            return $description;
        }
        $parts = preg_split( '/\n{2,}/', trim( $description ) ) ?: [];
        $tail  = [];
        while ( count( $parts ) > 1 && preg_match( '~^(?:\s*(?:https?://\S+|#[\p{L}\p{N}_]+)\s*)+$~u', (string) end( $parts ) ) ) {
            array_unshift( $tail, array_pop( $parts ) );
        }
        $body  = implode( "\n\n", $parts );
        $after = implode( "\n\n", array_merge( [ $chapters ], $tail ) );
        $room  = self::DESCRIPTION_MAX_BYTES - strlen( $after ) - 2;
        if ( $room < 200 ) {
            return $description;
        }
        if ( strlen( $body ) > $room ) {
            $body = rtrim( mb_strcut( $body, 0, $room - 3, 'UTF-8' ) ) . '…';
        }
        return rtrim( $body ) . "\n\n" . $after;
    }

    /** Seconds after which a YouTube job still "processing" counts as stuck (the worker stopped mid-upload). */
    const STUCK_AFTER = 10800;

    /** Why an uploaded episode is not in the podcast playlist, or ''. */
    public static function playlist_error( array $job ): string {
        if ( 'youtube' !== ( $job['platform'] ?? '' ) ) {
            return '';
        }
        $meta = self::decode_metadata( $job['metadata'] ?? null );
        return trim( (string) ( $meta['youtube_playlist_error'] ?? '' ) );
    }

    /** What an enhanced episode was published without (story images, captions), or ''. */
    public static function episode_note( array $job ): string {
        if ( 'youtube' !== ( $job['platform'] ?? '' ) ) {
            return '';
        }
        $meta = self::decode_metadata( $job['metadata'] ?? null );
        return trim( (string) ( $meta['youtube_episode_note'] ?? '' ) );
    }

    /**
     * Whether Retry may run on a YouTube job. Not on a published one: that would upload the
     * video a second time (delete it on YouTube and use Re-post instead). A processing job
     * only once it has been stuck for STUCK_AFTER seconds.
     */
    public static function can_retry( array $job, ?int $now = null ): bool {
        $status = (string) ( $job['status'] ?? '' );
        if ( 'published' === $status ) {
            return false;
        }
        if ( 'processing' === $status ) {
            $since = strtotime( (string) ( $job['updated_at'] ?? '' ) . ' UTC' );
            return false !== $since && ( $now ?? time() ) - $since >= self::STUCK_AFTER;
        }
        return in_array( $status, [ 'failed', 'cancelled' ], true );
    }

    /** A title YouTube accepts: plain text, no < or >, one line, at most 100 characters. */
    public static function clean_title( string $title ): string {
        $title = html_entity_decode( wp_strip_all_tags( self::protect_bare_lt( $title ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $title = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( [ '<', '>' ], '', $title ) ) );
        if ( mb_strlen( $title, 'UTF-8' ) > self::TITLE_MAX_CHARS ) {
            $title = rtrim( mb_substr( $title, 0, self::TITLE_MAX_CHARS - 1, 'UTF-8' ) ) . '…';
        }
        return $title;
    }

    /** A description YouTube accepts: no < or >, at most 5000 bytes. */
    public static function clean_description( string $text ): string {
        $text = html_entity_decode( wp_strip_all_tags( self::protect_bare_lt( $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( str_replace( [ '<', '>', "\r\n", "\r" ], [ '', '', "\n", "\n" ], $text ) );
        if ( strlen( $text ) > self::DESCRIPTION_MAX_BYTES ) {
            $text = rtrim( mb_strcut( $text, 0, self::DESCRIPTION_MAX_BYTES - 3, 'UTF-8' ) ) . '…';
        }
        return $text;
    }

    /** A "<" that does not open a tag (as in "3 < 5") is dropped, so strip_tags keeps the text after it. */
    private static function protect_bare_lt( string $text ): string {
        return (string) preg_replace( '/<(?![a-zA-Z\/!?])/u', '', $text );
    }

    /**
     * TikTok's "link in bio" call to action, rewritten for YouTube, which shows the link in
     * the description: "…: λινκ στο bio" / "…, λινκ στο bio" become "… στο presshub.cy"
     * (the site's host).
     */
    public static function youtube_cta( string $text ): string {
        $host = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
        $host = '' !== $host ? preg_replace( '/^www\./i', '', $host ) : 'presshub.cy';
        $text = (string) preg_replace( '/(?:\s+στο\s+PressHub(?:\.cy)?)?\s*[:,]?\s*(?:(?:τον|το|ο)\s+)?(?:λινκ|link|σύνδεσμος|σύνδεσμο)\s+(?:στο|in)\s+(?:bio|βιογραφικό)\b/iu', ' στο ' . $host, $text );
        return (string) preg_replace( '/\blink in bio\b/iu', $host, $text );
    }

    /**
     * Tags from the description's hashtags (without #), within YouTube's 500-character
     * total. #Shorts is left out: it is a hashtag, not a tag.
     *
     * @return string[]
     */
    public static function tags_from( string $text ): array {
        // URLs first: a "#section" anchor is not a hashtag.
        $text = (string) preg_replace( '~\b(?:https?://|www\.)\S+~iu', ' ', $text );
        if ( ! preg_match_all( '/(?<![\p{L}\p{N}_&\/])#([\p{L}\p{N}_]+)/u', $text, $m ) ) {
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
