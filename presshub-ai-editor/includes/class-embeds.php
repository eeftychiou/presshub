<?php
/**
 * TikTok photo posts (carousels, /@user/photo/<id> links).
 *
 * TikTok's oEmbed answers 400 for /photo/ links, and the player it hands out
 * for the same id under /video/ shows "Player authentication failed" in many
 * browsers (any without TikTok cookies, incognito included). So PressHub
 * never shows TikTok's player for a photo post:
 *
 * - In posts, an embed of a /photo/ link renders a link card (embed handler,
 *   runs before oEmbed, also used by the block editor's preview).
 * - The Trending roundup shows the post's first image, copied into the Media
 *   Library, linking to TikTok (PressHub_AI_Social_Trending::embed_block()).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Embeds {

    const HANDLER_ID = 'presshub_tiktok_photo';
    const PHOTO_URL  = '#^https?://(?:www\.|m\.)?tiktok\.com/@([^/?\#]+)/photo/(\d+)#i';

    public static function register(): void {
        if ( function_exists( 'wp_embed_register_handler' ) ) {
            wp_embed_register_handler( self::HANDLER_ID, self::PHOTO_URL, [ __CLASS__, 'tiktok_photo_handler' ] );
        }
    }

    public static function is_tiktok_photo_url( string $url ): bool {
        return 1 === preg_match( self::PHOTO_URL, $url );
    }

    /** The account handle in a TikTok /photo/ link, '' for any other link. */
    public static function tiktok_photo_handle( string $url ): string {
        return 1 === preg_match( self::PHOTO_URL, $url, $m ) ? rawurldecode( $m[1] ) : '';
    }

    /** wp_embed_register_handler callback: ( $matches, $attr, $url, $rawattr ). */
    public static function tiktok_photo_handler( $matches, $attr, $url, $rawattr = [] ): string {
        return self::link_card( (string) $url );
    }

    /** "Photos by @handle on TikTok" card linking to the post. */
    public static function link_card( string $url, string $text = '' ): string {
        $handle = self::tiktok_photo_handle( $url );
        $label  = '' !== $handle ? sprintf( 'Φωτογραφίες του @%s στο TikTok', $handle ) : 'Φωτογραφίες στο TikTok';
        $quote  = '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '';
        return '<blockquote class="wp-block-quote presshub-tiktok-photo-link">' . $quote
            . '<p><strong>' . esc_html( $label ) . '</strong></p>'
            . '<cite><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow">Δείτε την ανάρτηση στο TikTok</a></cite></blockquote>';
    }

    /**
     * Block markup for an image that links to the TikTok post, with a caption
     * link. $attachment_id/$src are the Media Library copy of the image.
     */
    public static function image_card_block( string $url, int $attachment_id, string $src, string $alt ): string {
        $u     = esc_url( $url );
        $attrs = json_encode( [ 'id' => $attachment_id, 'sizeSlug' => 'large', 'linkDestination' => 'custom', 'align' => 'center', 'className' => 'presshub-tiktok-photo-card' ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
        return "<!-- wp:image " . $attrs . " -->\n"
            . '<figure class="wp-block-image aligncenter size-large presshub-tiktok-photo-card">'
            . '<a href="' . $u . '" target="_blank" rel="noopener nofollow"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $attachment_id . '"/></a>'
            . '<figcaption class="wp-element-caption"><a href="' . $u . '" target="_blank" rel="noopener nofollow">Δείτε όλη την ανάρτηση στο TikTok</a></figcaption>'
            . "</figure>\n<!-- /wp:image -->";
    }
}
