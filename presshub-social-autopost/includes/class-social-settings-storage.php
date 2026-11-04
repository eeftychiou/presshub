<?php
/**
 * PressHub Social Settings Storage
 *
 * Provides centralized storage, retrieval, sanitization, and defaults
 * seeding for all social auto-post plugin options.
 *
 * Adheres strictly to the Settings-First Principle and Zero-Silent-Fallback Principle.
 *
 * @package PressHub_Social_AutoPost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-social-logger.php';
require_once __DIR__ . '/class-social-prompt-loader.php';

class PressHub_Social_Settings_Storage {

    /**
     * Supported social publishing platforms.
     */
    const PLATFORMS = [ 'twitter', 'facebook', 'instagram', 'tiktok' ];

    /**
     * Supported dispatch engines.
     */
    const ENGINES = [ 'api', 'automation' ];

    /**
     * Supported copy generation modes.
     */
    const COPY_MODES = [ 'ai', 'template' ];

    /**
     * Settings group identifier for the Settings API.
     */
    const OPTION_GROUP = 'presshub_social_options';

    /**
     * Re-entrancy guard for secret sanitization.
     *
     * @var array<string, bool>
     */
    private static array $sanitizing_secrets = [];

    // ------------------------------------------------------------------
    // Option Registration (Settings API)
    // ------------------------------------------------------------------

    /**
     * Register all plugin settings with the WordPress Settings API.
     *
     * @return void
     */
    public static function register_options(): void {
        self::seed_defaults();

        // 1. General & Mode
        register_setting( self::OPTION_GROUP, 'presshub_social_auto_publish_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_auto_publish_enabled' ],
            'default'           => true,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_enabled_platforms', [
            'type'              => 'array',
            'sanitize_callback' => [ __CLASS__, 'sanitize_enabled_platforms' ],
            'default'           => self::PLATFORMS,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_copy_mode', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_copy_mode' ],
            'default'           => 'ai',
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_github_token', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_github_token' ],
            'default'           => '',
        ] );

        // 2. Platform Engines
        foreach ( self::PLATFORMS as $platform ) {
            register_setting( self::OPTION_GROUP, 'presshub_social_engine_' . $platform, [
                'type'              => 'string',
                'sanitize_callback' => function( $val ) use ( $platform ) {
                    $default = in_array( $platform, [ 'twitter', 'tiktok' ], true ) ? 'automation' : 'api';
                    return self::sanitize_engine( $val, $default );
                },
            ] );
        }

        // 3. AI Module Tuning
        register_setting( self::OPTION_GROUP, 'presshub_social_ai_provider', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_ai_provider' ],
            'default'           => '',
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_ai_model', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_ai_model' ],
            'default'           => '',
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_ai_temperature', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_ai_temperature' ],
            'default'           => '0.7',
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_ai_max_tokens', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_ai_max_tokens' ],
            'default'           => 0,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_ai_timeout', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_ai_timeout' ],
            'default'           => 0,
        ] );

        // 4. Queue & Dispatch Limits
        register_setting( self::OPTION_GROUP, 'presshub_social_max_attempts', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_attempts' ],
            'default'           => 3,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_dispatch_interval', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_dispatch_interval' ],
            'default'           => 60,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_batch_size', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_batch_size' ],
            'default'           => 5,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_retry_base_delay', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_retry_base_delay' ],
            'default'           => 120,
        ] );

        // 5. Worker & Media Specifications
        register_setting( self::OPTION_GROUP, 'presshub_social_worker_mode', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_worker_mode' ],
            'default'           => 'pull',
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_worker_url', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_worker_url' ],
            'default'           => 'http://127.0.0.1:3456',
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_worker_secret', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_worker_secret' ],
            'default'           => '',
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_worker_timeout', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_worker_timeout' ],
            'default'           => 120,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_twitter_max_chars', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_twitter_max_chars' ],
            'default'           => 280,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_instagram_card_width', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_instagram_card_width' ],
            'default'           => 1080,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_instagram_card_height', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_instagram_card_height' ],
            'default'           => 1080,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_tiktok_video_width', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_tiktok_video_width' ],
            'default'           => 1080,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_tiktok_video_height', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_tiktok_video_height' ],
            'default'           => 1920,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_tiktok_video_duration', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_tiktok_video_duration' ],
            'default'           => 10,
        ] );

        // 6. Alerts
        register_setting( self::OPTION_GROUP, 'presshub_social_alert_email_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_alert_email_enabled' ],
            'default'           => false,
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_alert_email_address', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_alert_email_address' ],
            'default'           => '',
        ] );

        // 7. Platform API Credentials
        register_setting( self::OPTION_GROUP, 'presshub_social_meta_page_id', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_meta_page_id' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_meta_access_token', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_meta_access_token' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_instagram_account_id', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_instagram_account_id' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_twitter_bearer_token', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_twitter_bearer_token' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_twitter_api_key', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_twitter_api_key' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_twitter_api_secret', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_twitter_api_secret' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_twitter_access_token', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_twitter_access_token' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_twitter_access_secret', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_twitter_access_secret' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_tiktok_access_token', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_tiktok_access_token' ],
        ] );

        register_setting( self::OPTION_GROUP, 'presshub_social_tiktok_open_id', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_tiktok_open_id' ],
        ] );

        // 8. Prompts & Templates
        register_setting( self::OPTION_GROUP, 'presshub_social_prompt_system', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) {
                return self::sanitize_prompt( $val, 'presshub_social_prompt_system' );
            },
        ] );

        foreach ( self::PLATFORMS as $platform ) {
            register_setting( self::OPTION_GROUP, 'presshub_social_prompt_' . $platform, [
                'type'              => 'string',
                'sanitize_callback' => function( $val ) use ( $platform ) {
                    return self::sanitize_prompt( $val, 'presshub_social_prompt_' . $platform );
                },
            ] );
            register_setting( self::OPTION_GROUP, 'presshub_social_template_' . $platform, [
                'type'              => 'string',
                'sanitize_callback' => function( $val ) use ( $platform ) {
                    return self::sanitize_template( $val, 'presshub_social_template_' . $platform );
                },
            ] );
        }
    }

    // ------------------------------------------------------------------
    // Seeding & Resetting
    // ------------------------------------------------------------------

    /**
     * Seed default prompts, templates, and worker secret into options if missing,
     * and run one-time v2.6.3 repair for options wiped by single-tab form saves.
     *
     * @return void
     */
    public static function seed_defaults(): void {
        if ( false === get_option( 'presshub_social_prompt_system', false ) ) {
            try {
                $content = PressHub_Social_Prompt_Loader::load( 'social_system.txt' );
                update_option( 'presshub_social_prompt_system', $content );
            } catch ( Exception $e ) {
                PressHub_Social_Logger::error( 'Failed to seed system prompt: ' . $e->getMessage() );
            }
        }

        foreach ( self::PLATFORMS as $platform ) {
            $prompt_opt = 'presshub_social_prompt_' . $platform;
            if ( false === get_option( $prompt_opt, false ) ) {
                try {
                    $content = PressHub_Social_Prompt_Loader::load( 'social_copy_' . $platform . '.txt' );
                    update_option( $prompt_opt, $content );
                } catch ( Exception $e ) {
                    PressHub_Social_Logger::error( "Failed to seed prompt for {$platform}: " . $e->getMessage() );
                }
            }

            $template_opt = 'presshub_social_template_' . $platform;
            if ( false === get_option( $template_opt, false ) ) {
                try {
                    $content = PressHub_Social_Prompt_Loader::load( 'template_' . $platform . '.txt' );
                    update_option( $template_opt, $content );
                } catch ( Exception $e ) {
                    PressHub_Social_Logger::error( "Failed to seed template for {$platform}: " . $e->getMessage() );
                }
            }
        }

        $secret = get_option( 'presshub_social_worker_secret', '' );
        if ( '' === trim( (string) $secret ) ) {
            $generated = function_exists( 'wp_generate_password' )
                ? wp_generate_password( 32, false )
                : bin2hex( random_bytes( 16 ) );
            update_option( 'presshub_social_worker_secret', $generated );
        }

        self::maybe_repair_v263_options();
        self::maybe_upgrade_prompts_v269();
    }

    /**
     * One-time idempotent upgrade for v2.6.9: upgrades default Twitter prompt from
     * the generic English prompt to the rich Greek conversational editorial prompt.
     *
     * @return void
     */
    public static function maybe_upgrade_prompts_v269(): void {
        if ( '1' === (string) get_option( 'presshub_social_upgraded_prompts_v269', '' ) ) {
            return;
        }

        $twitter_prompt = (string) get_option( 'presshub_social_prompt_twitter', '' );
        if ( '' === trim( $twitter_prompt ) || false !== strpos( $twitter_prompt, 'Write a punchy, engaging X/Twitter post for the following article:' ) ) {
            try {
                $new_twitter = PressHub_Social_Prompt_Loader::load( 'social_copy_twitter.txt' );
                update_option( 'presshub_social_prompt_twitter', $new_twitter );
                PressHub_Social_Logger::info( 'Upgraded Twitter prompt to v2.6.9 Greek editorial prompt.' );
            } catch ( Exception $e ) {
                PressHub_Social_Logger::error( 'Failed to upgrade Twitter prompt: ' . $e->getMessage() );
            }
        }

        update_option( 'presshub_social_upgraded_prompts_v269', '1' );
    }

    /**
     * One-time idempotent repair for v2.6.3: heals options that were inadvertently
     * cleared when saving non-General tabs in v2.6.0-v2.6.2.
     *
     * @return void
     */
    public static function maybe_repair_v263_options(): void {
        if ( '1' === (string) get_option( 'presshub_social_repaired_v263', '' ) ) {
            return;
        }

        // Restore auto-publish and enabled platforms if they were wiped to false / empty array.
        if ( false === (bool) get_option( 'presshub_social_auto_publish_enabled', true ) ) {
            update_option( 'presshub_social_auto_publish_enabled', true );
        }

        $enabled_platforms = get_option( 'presshub_social_enabled_platforms', self::PLATFORMS );
        if ( ! is_array( $enabled_platforms ) || empty( $enabled_platforms ) ) {
            update_option( 'presshub_social_enabled_platforms', self::PLATFORMS );
        }

        // Restore any empty prompts or templates from external asset files.
        $sys_prompt = get_option( 'presshub_social_prompt_system', '' );
        if ( ! is_string( $sys_prompt ) || '' === trim( $sys_prompt ) ) {
            try {
                update_option( 'presshub_social_prompt_system', PressHub_Social_Prompt_Loader::load( 'social_system.txt' ) );
            } catch ( Exception $e ) {
                PressHub_Social_Logger::error( 'Failed to repair system prompt: ' . $e->getMessage() );
            }
        }

        foreach ( self::PLATFORMS as $platform ) {
            $prompt_opt = 'presshub_social_prompt_' . $platform;
            $cur_prompt = get_option( $prompt_opt, '' );
            if ( ! is_string( $cur_prompt ) || '' === trim( $cur_prompt ) ) {
                try {
                    update_option( $prompt_opt, PressHub_Social_Prompt_Loader::load( 'social_copy_' . $platform . '.txt' ) );
                } catch ( Exception $e ) {
                    PressHub_Social_Logger::error( "Failed to repair prompt for {$platform}: " . $e->getMessage() );
                }
            }

            $template_opt = 'presshub_social_template_' . $platform;
            $cur_template = get_option( $template_opt, '' );
            if ( ! is_string( $cur_template ) || '' === trim( $cur_template ) ) {
                try {
                    update_option( $template_opt, PressHub_Social_Prompt_Loader::load( 'template_' . $platform . '.txt' ) );
                } catch ( Exception $e ) {
                    PressHub_Social_Logger::error( "Failed to repair template for {$platform}: " . $e->getMessage() );
                }
            }
        }

        update_option( 'presshub_social_repaired_v263', '1' );
    }

    /**
     * Reset all prompt and template options to their pristine asset defaults.
     *
     * @return void
     */
    public static function reset_prompts_to_defaults(): void {
        PressHub_Social_Prompt_Loader::clear_cache();
        update_option( 'presshub_social_prompt_system', PressHub_Social_Prompt_Loader::load( 'social_system.txt' ) );
        foreach ( self::PLATFORMS as $platform ) {
            update_option( 'presshub_social_prompt_' . $platform, PressHub_Social_Prompt_Loader::load( 'social_copy_' . $platform . '.txt' ) );
            update_option( 'presshub_social_template_' . $platform, PressHub_Social_Prompt_Loader::load( 'template_' . $platform . '.txt' ) );
        }
    }

    // ------------------------------------------------------------------
    // Getters & Sanitizers: General & Mode
    // ------------------------------------------------------------------

    public static function get_auto_publish_enabled(): bool {
        return (bool) get_option( 'presshub_social_auto_publish_enabled', true );
    }

    public static function sanitize_auto_publish_enabled( $value ): bool {
        if ( null === $value && empty( $_POST['presshub_social_auto_publish_enabled_present'] ) && ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return self::get_auto_publish_enabled();
        }
        return in_array( $value, [ 1, '1', true, 'on', 'yes', 'true' ], true );
    }

    public static function get_enabled_platforms(): array {
        $platforms = get_option( 'presshub_social_enabled_platforms', self::PLATFORMS );
        if ( ! is_array( $platforms ) ) {
            return self::PLATFORMS;
        }
        $valid = array_intersect( $platforms, self::PLATFORMS );
        return array_values( $valid );
    }

    public static function is_platform_enabled( string $platform ): bool {
        return in_array( $platform, self::get_enabled_platforms(), true );
    }

    public static function sanitize_enabled_platforms( $value ): array {
        if ( null === $value && empty( $_POST['presshub_social_enabled_platforms_present'] ) && ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return self::get_enabled_platforms();
        }
        if ( ! is_array( $value ) ) {
            return [];
        }
        $clean = [];
        foreach ( $value as $item ) {
            $slug = sanitize_key( $item );
            if ( in_array( $slug, self::PLATFORMS, true ) && ! in_array( $slug, $clean, true ) ) {
                $clean[] = $slug;
            }
        }
        return $clean;
    }

    public static function get_platform_engine( string $platform ): string {
        $platform = sanitize_key( $platform );
        $default  = in_array( $platform, [ 'twitter', 'tiktok' ], true ) ? 'automation' : 'api';
        $engine   = (string) get_option( 'presshub_social_engine_' . $platform, $default );
        return in_array( $engine, self::ENGINES, true ) ? $engine : $default;
    }

    public static function sanitize_engine( $value, string $default = 'api' ): string {
        $val = sanitize_key( $value );
        return in_array( $val, self::ENGINES, true ) ? $val : $default;
    }

    public static function get_copy_mode(): string {
        $mode = (string) get_option( 'presshub_social_copy_mode', 'ai' );
        return in_array( $mode, self::COPY_MODES, true ) ? $mode : 'ai';
    }

    public static function sanitize_copy_mode( $value ): string {
        $val = sanitize_key( $value );
        return in_array( $val, self::COPY_MODES, true ) ? $val : 'ai';
    }

    // ------------------------------------------------------------------
    // Getters & Sanitizers: AI Module Tuning
    // ------------------------------------------------------------------

    public static function get_ai_provider(): string {
        return sanitize_key( (string) get_option( 'presshub_social_ai_provider', '' ) );
    }

    public static function sanitize_ai_provider( $value ): string {
        return sanitize_key( $value );
    }

    public static function get_ai_model(): string {
        return sanitize_text_field( (string) get_option( 'presshub_social_ai_model', '' ) );
    }

    public static function sanitize_ai_model( $value ): string {
        return sanitize_text_field( (string) $value );
    }

    public static function get_ai_temperature( bool $fallback_default = false ): ?float {
        $val = get_option( 'presshub_social_ai_temperature', null );
        if ( null === $val || '' === (string) $val ) {
            return $fallback_default ? 0.7 : null;
        }
        return max( 0.0, min( 2.0, (float) $val ) );
    }

    public static function sanitize_ai_temperature( $value ) {
        if ( null === $value || '' === trim( (string) $value ) ) {
            return '';
        }
        $val = (float) $value;
        return round( max( 0.0, min( 2.0, $val ) ), 2 );
    }

    public static function get_ai_max_tokens(): int {
        $val = get_option( 'presshub_social_ai_max_tokens', 0 );
        $n   = (int) $val;
        if ( $n < 64 ) {
            return 0; // 0 indicates defer to provider default.
        }
        return min( 65536, $n );
    }

    public static function sanitize_ai_max_tokens( $value ): int {
        if ( null === $value || '' === trim( (string) $value ) ) {
            return 0;
        }
        $n = (int) $value;
        if ( $n < 64 ) {
            return 0;
        }
        return min( 65536, $n );
    }

    public static function get_ai_timeout(): int {
        $val = get_option( 'presshub_social_ai_timeout', 0 );
        $n   = (int) $val;
        if ( $n < 10 ) {
            return 0; // 0 indicates defer to provider default.
        }
        return min( 600, $n );
    }

    public static function sanitize_ai_timeout( $value ): int {
        if ( null === $value || '' === trim( (string) $value ) ) {
            return 0;
        }
        $n = (int) $value;
        if ( $n < 10 ) {
            return 0;
        }
        return min( 600, $n );
    }

    // ------------------------------------------------------------------
    // Getters & Sanitizers: Queue & Dispatch Limits
    // ------------------------------------------------------------------

    public static function get_max_attempts(): int {
        $val = (int) get_option( 'presshub_social_max_attempts', 3 );
        return max( 1, min( 10, $val ) );
    }

    public static function sanitize_max_attempts( $value ): int {
        $val = (int) $value;
        return max( 1, min( 10, $val ) );
    }

    public static function get_dispatch_interval(): int {
        $val = (int) get_option( 'presshub_social_dispatch_interval', 60 );
        return max( 10, min( 3600, $val ) );
    }

    public static function sanitize_dispatch_interval( $value ): int {
        $val = (int) $value;
        return max( 10, min( 3600, $val ) );
    }

    public static function get_batch_size(): int {
        $val = (int) get_option( 'presshub_social_batch_size', 5 );
        return max( 1, min( 20, $val ) );
    }

    public static function sanitize_batch_size( $value ): int {
        $val = (int) $value;
        return max( 1, min( 20, $val ) );
    }

    public static function get_retry_base_delay(): int {
        $val = (int) get_option( 'presshub_social_retry_base_delay', 120 );
        return max( 30, min( 3600, $val ) );
    }

    public static function sanitize_retry_base_delay( $value ): int {
        $val = (int) $value;
        return max( 30, min( 3600, $val ) );
    }

    // ------------------------------------------------------------------
    // Getters & Sanitizers: Worker & Specifications
    // ------------------------------------------------------------------

    public static function get_worker_mode(): string {
        $val = (string) get_option( 'presshub_social_worker_mode', 'pull' );
        return in_array( $val, [ 'pull', 'push' ], true ) ? $val : 'pull';
    }

    public static function sanitize_worker_mode( $value ): string {
        if ( null === $value && ! empty( $_POST ) && ! isset( $_POST['presshub_social_worker_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return self::get_worker_mode();
        }
        $val = sanitize_key( (string) $value );
        return in_array( $val, [ 'pull', 'push' ], true ) ? $val : 'pull';
    }

    public static function get_worker_url(): string {
        $url = (string) get_option( 'presshub_social_worker_url', 'http://127.0.0.1:3456' );
        $url = untrailingslashit( trim( $url ) );
        return '' !== $url ? $url : 'http://127.0.0.1:3456';
    }

    public static function sanitize_worker_url( $value ): string {
        $raw   = trim( (string) $value );
        $clean = esc_url_raw( $raw );
        $clean = untrailingslashit( $clean );
        return '' !== $clean ? $clean : 'http://127.0.0.1:3456';
    }

    public static function get_worker_secret(): string {
        return (string) get_option( 'presshub_social_worker_secret', '' );
    }

    public static function sanitize_worker_secret( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_worker_secret' );
    }

    public static function get_worker_timeout(): int {
        $val = (int) get_option( 'presshub_social_worker_timeout', 120 );
        return max( 10, min( 300, $val ) );
    }

    public static function sanitize_worker_timeout( $value ): int {
        $val = (int) $value;
        return max( 10, min( 300, $val ) );
    }

    public static function get_twitter_max_chars(): int {
        $val = (int) get_option( 'presshub_social_twitter_max_chars', 280 );
        return max( 100, min( 4000, $val ) );
    }

    public static function sanitize_twitter_max_chars( $value ): int {
        $val = (int) $value;
        return max( 100, min( 4000, $val ) );
    }

    public static function get_instagram_card_width(): int {
        $val = (int) get_option( 'presshub_social_instagram_card_width', 1080 );
        return max( 600, min( 2160, $val ) );
    }

    public static function sanitize_instagram_card_width( $value ): int {
        $val = (int) $value;
        return max( 600, min( 2160, $val ) );
    }

    public static function get_instagram_card_height(): int {
        $val = (int) get_option( 'presshub_social_instagram_card_height', 1080 );
        return max( 600, min( 2160, $val ) );
    }

    public static function sanitize_instagram_card_height( $value ): int {
        $val = (int) $value;
        return max( 600, min( 2160, $val ) );
    }

    public static function get_tiktok_video_width(): int {
        $val = (int) get_option( 'presshub_social_tiktok_video_width', 1080 );
        return max( 540, min( 2160, $val ) );
    }

    public static function sanitize_tiktok_video_width( $value ): int {
        $val = (int) $value;
        return max( 540, min( 2160, $val ) );
    }

    public static function get_tiktok_video_height(): int {
        $val = (int) get_option( 'presshub_social_tiktok_video_height', 1920 );
        return max( 960, min( 3840, $val ) );
    }

    public static function sanitize_tiktok_video_height( $value ): int {
        $val = (int) $value;
        return max( 960, min( 3840, $val ) );
    }

    public static function get_tiktok_video_duration(): int {
        $val = (int) get_option( 'presshub_social_tiktok_video_duration', 10 );
        return max( 5, min( 600, $val ) );
    }

    public static function sanitize_tiktok_video_duration( $value ): int {
        $val = (int) $value;
        return max( 5, min( 600, $val ) );
    }

    // ------------------------------------------------------------------
    // Getters & Sanitizers: Alerts
    // ------------------------------------------------------------------

    public static function get_alert_email_enabled(): bool {
        return (bool) get_option( 'presshub_social_alert_email_enabled', false );
    }

    public static function sanitize_alert_email_enabled( $value ): bool {
        return in_array( $value, [ 1, '1', true, 'on', 'yes', 'true' ], true );
    }

    public static function get_alert_email_address(): string {
        $email = (string) get_option( 'presshub_social_alert_email_address', '' );
        if ( '' === $email && function_exists( 'get_option' ) ) {
            $email = (string) get_option( 'admin_email', '' );
        }
        return $email;
    }

    public static function sanitize_alert_email_address( $value ): string {
        $raw = trim( (string) $value );
        if ( '' === $raw ) {
            return '';
        }
        return sanitize_email( $raw );
    }

    // ------------------------------------------------------------------
    // Getters & Sanitizers: Platform API Credentials
    // ------------------------------------------------------------------

    public static function get_meta_page_id(): string {
        return sanitize_text_field( (string) get_option( 'presshub_social_meta_page_id', '' ) );
    }

    public static function sanitize_meta_page_id( $value ): string {
        return sanitize_text_field( (string) $value );
    }

    public static function get_meta_access_token(): string {
        return (string) get_option( 'presshub_social_meta_access_token', '' );
    }

    public static function sanitize_meta_access_token( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_meta_access_token' );
    }

    public static function get_instagram_account_id(): string {
        return sanitize_text_field( (string) get_option( 'presshub_social_instagram_account_id', '' ) );
    }

    public static function sanitize_instagram_account_id( $value ): string {
        return sanitize_text_field( (string) $value );
    }

    public static function get_twitter_bearer_token(): string {
        return (string) get_option( 'presshub_social_twitter_bearer_token', '' );
    }

    public static function sanitize_twitter_bearer_token( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_twitter_bearer_token' );
    }

    public static function get_twitter_api_key(): string {
        return (string) get_option( 'presshub_social_twitter_api_key', '' );
    }

    public static function sanitize_twitter_api_key( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_twitter_api_key' );
    }

    public static function get_twitter_api_secret(): string {
        return (string) get_option( 'presshub_social_twitter_api_secret', '' );
    }

    public static function sanitize_twitter_api_secret( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_twitter_api_secret' );
    }

    public static function get_twitter_access_token(): string {
        return (string) get_option( 'presshub_social_twitter_access_token', '' );
    }

    public static function sanitize_twitter_access_token( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_twitter_access_token' );
    }

    public static function get_twitter_access_secret(): string {
        return (string) get_option( 'presshub_social_twitter_access_secret', '' );
    }

    public static function sanitize_twitter_access_secret( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_twitter_access_secret' );
    }

    public static function get_tiktok_access_token(): string {
        return (string) get_option( 'presshub_social_tiktok_access_token', '' );
    }

    public static function sanitize_tiktok_access_token( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_tiktok_access_token' );
    }

    public static function get_tiktok_open_id(): string {
        return sanitize_text_field( (string) get_option( 'presshub_social_tiktok_open_id', '' ) );
    }

    public static function sanitize_tiktok_open_id( $value ): string {
        return sanitize_text_field( (string) $value );
    }

    public static function get_github_token(): string {
        $token = (string) get_option( 'presshub_social_github_token', '' );
        if ( '' !== trim( $token ) ) {
            return trim( $token );
        }
        return trim( (string) get_option( 'presshub_ai_github_token', '' ) );
    }

    public static function sanitize_github_token( $value ): string {
        return self::sanitize_secret( $value, 'presshub_social_github_token' );
    }

    // ------------------------------------------------------------------
    // Getters & Sanitizers: Prompts & Templates (Zero-Silent-Fallback)
    // ------------------------------------------------------------------

    /**
     * Get system prompt from options.
     *
     * In accordance with Zero-Silent-Fallback, returns the exact trimmed option value.
     * Does NOT silently substitute an inline fallback string.
     *
     * @return string System prompt or empty string if not configured.
     */
    public static function get_system_prompt(): string {
        $val = get_option( 'presshub_social_prompt_system', '' );
        return is_string( $val ) ? trim( $val ) : '';
    }

    /**
     * Get platform prompt template from options.
     *
     * In accordance with Zero-Silent-Fallback, returns the exact trimmed option value.
     * Does NOT silently substitute an inline fallback string.
     *
     * @param string $platform Platform slug.
     * @return string Prompt template or empty string if not configured.
     */
    public static function get_platform_prompt( string $platform ): string {
        $platform = sanitize_key( $platform );
        $val      = get_option( 'presshub_social_prompt_' . $platform, '' );
        return is_string( $val ) ? trim( $val ) : '';
    }

    /**
     * Get platform fallback template from options.
     *
     * In accordance with Zero-Silent-Fallback, returns the exact trimmed option value.
     * Does NOT silently substitute an inline fallback string.
     *
     * @param string $platform Platform slug.
     * @return string Template or empty string if not configured.
     */
    public static function get_platform_template( string $platform ): string {
        $platform = sanitize_key( $platform );
        $val      = get_option( 'presshub_social_template_' . $platform, '' );
        return is_string( $val ) ? trim( $val ) : '';
    }

    public static function sanitize_prompt( $value, string $option_name = '' ): string {
        if ( null === $value && '' !== $option_name ) {
            return (string) get_option( $option_name, '' );
        }
        if ( ! is_string( $value ) ) {
            return '';
        }
        $val = wp_unslash( $value );
        return substr( trim( $val ), 0, 10000 );
    }

    public static function sanitize_template( $value, string $option_name = '' ): string {
        if ( null === $value && '' !== $option_name ) {
            return (string) get_option( $option_name, '' );
        }
        if ( ! is_string( $value ) ) {
            return '';
        }
        $val = wp_unslash( $value );
        return substr( trim( $val ), 0, 5000 );
    }

    // ------------------------------------------------------------------
    // Internal Secret Sanitizer Helper
    // ------------------------------------------------------------------

    /**
     * Sanitize a secret token or key. Preserves the existing value if the incoming
     * value is empty or masked, preventing accidental overwriting.
     *
     * @param mixed  $value Incoming value from POST.
     * @param string $option_name WordPress option name.
     * @return string Sanitized secret.
     */
    public static function sanitize_secret( $value, string $option_name ): string {
        if ( ! empty( self::$sanitizing_secrets[ $option_name ] ) ) {
            return (string) $value;
        }

        self::$sanitizing_secrets[ $option_name ] = true;

        try {
            $value = wp_unslash( $value );
            if ( ! is_string( $value ) ) {
                $value = '';
            }
            $value    = trim( $value );
            $existing = (string) get_option( $option_name, '' );

            // Support explicit removal if requested.
            if ( '__CLEAR__' === $value || ! empty( $_POST[ $option_name . '_remove' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return '';
            }

            // If empty or masked, retain existing secret.
            if ( '' === $value || false !== strpos( $value, '••••••••' ) ) {
                return $existing;
            }

            $value = wp_strip_all_tags( $value );
            $value = preg_replace( '/[\r\n\t]+/', '', $value );
            return trim( $value );
        } finally {
            unset( self::$sanitizing_secrets[ $option_name ] );
        }
    }
}
