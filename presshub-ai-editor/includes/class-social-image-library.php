<?php
/**
 * Social image library: AI images generated for a post's social media, stored once per
 * post and reused by every platform and feature that asks for the same picture.
 *
 * One image is identified by its description, the style it was drawn in and its aspect
 * ratio (key()). The first caller generates it through the site's image generator
 * (PressHub_AI_API_Client::generate_featured_image(), which also stores the
 * `image_generation` generation record with its cost); later callers with the same
 * key get the stored attachment back without a new call. Entries live in post meta
 * `_presshub_social_images`, keyed by key(), with the description, aspect, source
 * (e.g. `post_images`, `tiktok_short`) and creation time, so later features such as
 * the full-podcast video can list a post's images with all().
 *
 * Used by PressHub_Social_Post_Images (images for X, Facebook, Instagram and TikTok
 * article posts) and PressHub_Social_TikTok_Shorts (story images of the shorts).
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Image_Library {

    /** Post meta holding the post's generated social images, keyed by key(). */
    public const META = '_presshub_social_images';

    /** Most images kept per post; the oldest entries are dropped from the index. */
    public const MAX_ENTRIES = 60;

    /** Aspect ratios the image generators accept. */
    public const ASPECTS = [ '1:1', '16:9', '9:16', '4:5', '3:4', '4:3' ];

    /**
     * Optional image generator override for tests.
     * Signature: fn( string $prompt, string $aspect, int $post_id, array $context ): array{url:string,id:int}|WP_Error
     *
     * @var callable|null
     */
    private static $generator = null;

    public static function set_generator( ?callable $generator ): void {
        self::$generator = $generator;
    }

    /**
     * Cache key of one image: what it shows, how it is drawn and its shape.
     */
    public static function key( string $description, string $style, string $aspect ): string {
        return substr( hash( 'sha256', trim( $description ) . '|' . trim( $style ) . '|' . $aspect ), 0, 32 );
    }

    /**
     * Fill an image style with one description. `{description}` marks where it goes;
     * without it the description is put first. `{orientation}` becomes the shape in words
     * (e.g. "landscape 16:9"). An empty style sends the description alone.
     */
    public static function build_prompt( string $style, string $description, string $aspect = '' ): string {
        $style = strtr( $style, [ '{orientation}' => self::orientation( $aspect ) ] );
        if ( '' === trim( $style ) ) {
            return trim( $description );
        }
        if ( str_contains( $style, '{description}' ) ) {
            return trim( strtr( $style, [ '{description}' => trim( $description ) ] ) );
        }
        return trim( trim( $description ) . ' ' . $style );
    }

    /**
     * The aspect ratio in words, for the image prompt.
     */
    public static function orientation( string $aspect ): string {
        switch ( $aspect ) {
            case '16:9':
            case '4:3':
                return 'landscape ' . $aspect;
            case '9:16':
            case '3:4':
            case '4:5':
                return 'vertical ' . $aspect;
            case '1:1':
                return 'square 1:1';
            default:
                return $aspect;
        }
    }

    /**
     * The stored image with this key, or null. An entry whose attachment was deleted
     * from the Media Library is ignored.
     *
     * @return array{key:string,url:string,id:int,description:string,prompt:string,aspect:string,source:string,created_at:string}|null
     */
    public static function find( int $post_id, string $key ): ?array {
        $entries = self::all( $post_id );
        $entry   = $entries[ $key ] ?? null;
        if ( ! is_array( $entry ) || empty( $entry['url'] ) ) {
            return null;
        }
        if ( ! empty( $entry['id'] ) && function_exists( 'wp_get_attachment_url' ) && ! wp_get_attachment_url( (int) $entry['id'] ) ) {
            return null;
        }
        return $entry;
    }

    /**
     * Every image generated for the post's social media, oldest first.
     *
     * @return array<string, array>
     */
    public static function all( int $post_id ): array {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return [];
        }
        $stored = get_post_meta( $post_id, self::META, true );
        return is_array( $stored ) ? $stored : [];
    }

    /**
     * The image for this description, style and aspect: the stored one, or a new one
     * generated now and stored.
     *
     * @param array $context ['title' => string, 'content' => string] for the Media
     *                       Library metadata of a new image.
     * @param string $source Who asked first, for the index (e.g. `post_images`).
     * @return array{key:string,url:string,id:int,description:string,prompt:string,aspect:string,source:string,created_at:string,cached:bool}|WP_Error
     */
    public static function get_or_create( int $post_id, string $description, string $style, string $aspect, array $context = [], string $source = '' ) {
        $description = trim( $description );
        if ( '' === $description ) {
            return new WP_Error( 'social_image_no_description', 'the image has no description.' );
        }
        if ( ! in_array( $aspect, self::ASPECTS, true ) ) {
            $aspect = '1:1';
        }

        $key    = self::key( $description, $style, $aspect );
        $cached = self::find( $post_id, $key );
        if ( null !== $cached ) {
            return array_merge( $cached, [ 'cached' => true ] );
        }

        $prompt = self::build_prompt( $style, $description, $aspect );
        try {
            if ( null !== self::$generator ) {
                $result = call_user_func( self::$generator, $prompt, $aspect, $post_id, $context );
            } elseif ( class_exists( 'PressHub_AI_API_Client' ) ) {
                $client = new PressHub_AI_API_Client();
                $result = $client->generate_featured_image( $prompt, $aspect, $post_id, $context );
            } else {
                $result = new WP_Error( 'missing_api_client', 'PressHub_AI_API_Client is not loaded.' );
            }
        } catch ( Throwable $e ) {
            $result = new WP_Error( 'image_generation_exception', $e->getMessage() );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! is_array( $result ) || empty( $result['url'] ) ) {
            return new WP_Error( 'social_image_no_url', 'the image generator returned no image URL.' );
        }

        $entry = [
            'key'         => $key,
            'url'         => (string) $result['url'],
            'id'          => (int) ( $result['id'] ?? 0 ),
            'description' => $description,
            'prompt'      => $prompt,
            'aspect'      => $aspect,
            'source'      => $source,
            'created_at'  => gmdate( 'c' ),
        ];
        self::store( $post_id, $entry );
        return array_merge( $entry, [ 'cached' => false ] );
    }

    private static function store( int $post_id, array $entry ): void {
        if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
            return;
        }
        $entries = self::all( $post_id );
        unset( $entries[ $entry['key'] ] );
        $entries[ $entry['key'] ] = $entry;
        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, -self::MAX_ENTRIES, null, true );
        }
        update_post_meta( $post_id, self::META, $entries );
    }
}
