<?php
/**
 * PressHub_AI_Prompt_Loader — Dedicated prompt template asset loader.
 *
 * Externalizes prompt engineering templates from PHP business logic into
 * dedicated asset files located in `assets/prompts/`.
 *
 * Implements the Zero-Silent-Fallback Principle: if a requested template
 * file is missing, empty, or unreadable on disk, execution fails loudly
 * with a RuntimeException and logs an ERROR to PressHub_AI_Logger.
 *
 * @package PressHub_AI_Editor
 * @since 2.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Prompt_Loader {

    /**
     * In-memory cache for prompt templates to avoid redundant disk I/O.
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * Get the base directory path for prompt asset templates.
     *
     * @return string Normalized directory path.
     */
    public static function get_prompts_dir(): string {
        return dirname( __DIR__ ) . '/assets/prompts';
    }

    /**
     * Clear the in-memory prompt template cache.
     *
     * @return void
     */
    public static function clear_cache(): void {
        self::$cache = [];
    }

    /**
     * Load a prompt template from disk by relative path.
     *
     * @param string $relative_path Relative path from assets/prompts (e.g. 'podcast/default_greek_chat_2.txt').
     * @return string Prompt template contents.
     * @throws InvalidArgumentException If relative path attempts directory traversal.
     * @throws RuntimeException If the template file is missing, unreadable, or empty.
     */
    public static function load( string $relative_path ): string {
        $clean_path = str_replace( [ '\\', "\0" ], [ '/', '' ], trim( $relative_path ) );

        if ( str_contains( $clean_path, '..' ) ) {
            throw new InvalidArgumentException(
                sprintf( 'Directory traversal detected in prompt path: %s', esc_html( $relative_path ) )
            );
        }

        if ( isset( self::$cache[ $clean_path ] ) ) {
            return self::$cache[ $clean_path ];
        }

        $full_path = self::get_prompts_dir() . '/' . ltrim( $clean_path, '/' );

        if ( ! file_exists( $full_path ) || ! is_readable( $full_path ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error(
                    'Missing prompt template asset file',
                    [
                        'relative_path' => $clean_path,
                        'full_path'     => $full_path,
                    ]
                );
            }
            throw new RuntimeException(
                sprintf( 'Prompt template asset file missing or unreadable: %s', $clean_path )
            );
        }

        $content = file_get_contents( $full_path );
        if ( false === $content || '' === trim( $content ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error(
                    'Prompt template asset file is empty',
                    [
                        'relative_path' => $clean_path,
                        'full_path'     => $full_path,
                    ]
                );
            }
            throw new RuntimeException(
                sprintf( 'Prompt template asset file is empty: %s', $clean_path )
            );
        }

        self::$cache[ $clean_path ] = $content;
        return $content;
    }

    /**
     * Get the built-in default podcast dialogue prompt for a given host count and style.
     *
     * @param int    $host_count Number of presenters (1, 2, or 3).
     * @param string $style      Dialogue style key.
     * @return string Loaded prompt template.
     */
    public static function get_podcast_prompt( int $host_count = 2, string $style = 'default_greek_chat' ): string {
        $allowed_styles = [ 'default_greek_chat', 'bbc_broadcasting_standards', 'conversational_news_reporting' ];
        if ( ! in_array( $style, $allowed_styles, true ) ) {
            $style = 'default_greek_chat';
        }

        $host_count = ( $host_count >= 1 && $host_count <= 3 ) ? $host_count : 2;

        $file = sprintf( 'podcast/%s_%d.txt', $style, $host_count );
        return self::load( $file );
    }

    /**
     * Get the built-in default news curation briefing prompt.
     *
     * @return string Loaded curation prompt template.
     */
    public static function get_curation_prompt(): string {
        return self::load( 'curation/default_greek_briefing.txt' );
    }
}