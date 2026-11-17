<?php
/**
 * Embed fixes for links WordPress's oEmbed providers can't show on their own.
 *
 * TikTok photo posts (carousels) have /@user/photo/<id> links. WordPress
 * sends them to TikTok's oEmbed endpoint, which answers 400 for /photo/
 * links, so the post shows a bare link. The same id under /video/ embeds
 * and plays the carousel, so the link is rewritten just for the oEmbed
 * request. The post keeps its original link.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Embeds {

    const TIKTOK_OEMBED = 'https://www.tiktok.com/oembed';

    public static function register(): void {
        add_filter( 'oembed_fetch_url', [ __CLASS__, 'filter_oembed_fetch_url' ], 10, 2 );
    }

    /** The /video/ link for a TikTok /photo/ link, or null for any other link. */
    public static function tiktok_photo_to_video( string $url ): ?string {
        if ( 1 !== preg_match( '#^(https?://(?:www\.|m\.)?tiktok\.com/@[^/?\#]+)/photo/(\d+)(.*)$#i', $url, $m ) ) {
            return null;
        }
        return $m[1] . '/video/' . $m[2] . $m[3];
    }

    /**
     * oembed_fetch_url: point TikTok's oEmbed request for a /photo/ link at
     * the /video/ link. WordPress built $provider with
     * add_query_arg( 'url', urlencode( $url ), $provider ).
     */
    public static function filter_oembed_fetch_url( $provider, $url = '' ) {
        if ( ! is_string( $provider ) || ! is_string( $url ) || 0 !== stripos( $provider, self::TIKTOK_OEMBED ) ) {
            return $provider;
        }
        $video = self::tiktok_photo_to_video( $url );
        if ( null === $video ) {
            return $provider;
        }
        return str_replace( 'url=' . urlencode( $url ), 'url=' . urlencode( $video ), $provider );
    }
}
