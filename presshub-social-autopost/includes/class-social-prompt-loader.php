<?php
/**
 * PressHub Social Prompt Loader
 *
 * Dedicated prompt template asset loader for social media copy and templates.
 * Enforces Zero-Silent-Fallback: missing, unreadable, or empty asset files
 * immediately log an error via PressHub_Social_Logger and throw a RuntimeException.
 *
 * @package PressHub_Social_AutoPost
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-social-logger.php';

class PressHub_Social_Prompt_Loader {

    /**
     * In-memory cache for loaded templates.
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * Get base directory for prompt assets.
     *
     * @return string Normalized directory path.
     */
    public static function get_prompts_dir(): string {
        return dirname( __DIR__ ) . '/assets/prompts';
    }

    /**
     * Clear in-memory prompt cache.
     *
     * @return void
     */
    public static function clear_cache(): void {
        self::$cache = [];
    }

    /**
     * Load a prompt or template file by relative path.
     *
     * @param string $relative_path Relative path from assets/prompts (e.g. 'social_system.txt').
     * @return string File content.
     * @throws InvalidArgumentException If path traversal is detected.
     * @throws RuntimeException If file is missing, unreadable, or empty.
     */
    public static function load( string $relative_path ): string {
        $clean_path = str_replace( [ '\\', "\0" ], [ '/', '' ], trim( $relative_path ) );

        if ( str_contains( $clean_path, '..' ) ) {
            $error_msg = sprintf( 'Directory traversal detected in prompt path: %s', $relative_path );
            PressHub_Social_Logger::error( $error_msg, [ 'path' => $relative_path ] );
            throw new InvalidArgumentException( $error_msg );
        }

        if ( isset( self::$cache[ $clean_path ] ) ) {
            return self::$cache[ $clean_path ];
        }

        $full_path = self::get_prompts_dir() . '/' . ltrim( $clean_path, '/' );

        if ( ! file_exists( $full_path ) || ! is_readable( $full_path ) ) {
            $error_msg = sprintf( 'Prompt template asset file missing or unreadable: %s', $clean_path );
            PressHub_Social_Logger::error( $error_msg, [
                'relative_path' => $clean_path,
                'full_path'     => $full_path,
            ] );
            throw new RuntimeException( $error_msg );
        }

        $content = file_get_contents( $full_path );
        if ( false === $content || '' === trim( $content ) ) {
            $error_msg = sprintf( 'Prompt template asset file is empty: %s', $clean_path );
            PressHub_Social_Logger::error( $error_msg, [
                'relative_path' => $clean_path,
                'full_path'     => $full_path,
            ] );
            throw new RuntimeException( $error_msg );
        }

        self::$cache[ $clean_path ] = $content;
        return $content;
    }

    /**
     * Check if a prompt asset file exists and is readable.
     *
     * @param string $relative_path Relative path from assets/prompts.
     * @return bool
     */
    public static function exists( string $relative_path ): bool {
        $clean_path = str_replace( [ '\\', "\0" ], [ '/', '' ], trim( $relative_path ) );
        if ( str_contains( $clean_path, '..' ) ) {
            return false;
        }
        $full_path = self::get_prompts_dir() . '/' . ltrim( $clean_path, '/' );
        return file_exists( $full_path ) && is_readable( $full_path );
    }
}
