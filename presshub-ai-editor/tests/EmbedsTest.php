<?php
/**
 * EmbedsTest — TikTok /photo/ links embed through their /video/ link.
 *
 * Test cases:
 *   1. Link rewrite: /photo/ → /video/, query kept; other links left alone.
 *   2. oembed_fetch_url: TikTok request for a /photo/ link asks for /video/.
 *   3. oembed_fetch_url: other providers and TikTok /video/ links unchanged.
 *   4. The filter is registered when the plugin loads.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = 0;
function em_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$E     = 'PressHub_AI_Embeds';
$photo = 'https://www.tiktok.com/@eleni.krystallis/photo/7707224432422538518';
$video = 'https://www.tiktok.com/@eleni.krystallis/video/7707224432422538518';

// 1. Link rewrite.
em_check( 'rewrite: photo link becomes video link', $video === $E::tiktok_photo_to_video( $photo ) );
em_check( 'rewrite: query string kept', $video . '?is_from_webapp=1' === $E::tiktok_photo_to_video( $photo . '?is_from_webapp=1' ) );
em_check( 'rewrite: no www', 'https://tiktok.com/@a/video/5' === $E::tiktok_photo_to_video( 'https://tiktok.com/@a/photo/5' ) );
em_check( 'rewrite: video link left alone', null === $E::tiktok_photo_to_video( $video ) );
em_check( 'rewrite: profile link left alone', null === $E::tiktok_photo_to_video( 'https://www.tiktok.com/@eleni.krystallis' ) );
em_check( 'rewrite: other site left alone', null === $E::tiktok_photo_to_video( 'https://example.com/@a/photo/5' ) );

// 2. TikTok oEmbed request for a photo link (as WP_oEmbed::_fetch_with_format builds it).
$provider = 'https://www.tiktok.com/oembed?maxwidth=600&maxheight=750&url=' . urlencode( $photo ) . '&dnt=1';
$out      = $E::filter_oembed_fetch_url( $provider, $photo );
parse_str( (string) parse_url( $out, PHP_URL_QUERY ), $q );
em_check( 'fetch url: asks TikTok for the video link', $video === ( $q['url'] ?? '' ) );
em_check( 'fetch url: other args kept', '600' === ( $q['maxwidth'] ?? '' ) && '1' === ( $q['dnt'] ?? '' ) );
em_check( 'fetch url: same endpoint', 0 === strpos( $out, 'https://www.tiktok.com/oembed?' ) );

// 3. Left alone.
$yt = 'https://www.youtube.com/oembed?url=' . urlencode( $photo );
em_check( 'fetch url: other provider unchanged', $yt === $E::filter_oembed_fetch_url( $yt, $photo ) );
$tv = 'https://www.tiktok.com/oembed?url=' . urlencode( $video );
em_check( 'fetch url: TikTok video link unchanged', $tv === $E::filter_oembed_fetch_url( $tv, $video ) );
em_check( 'fetch url: non-string provider passed through', false === $E::filter_oembed_fetch_url( false, $photo ) );

// 4. Registered.
$hooked = false;
foreach ( $GLOBALS['FILTERS']['oembed_fetch_url'] ?? [] as $f ) {
    if ( [ 'PressHub_AI_Embeds', 'filter_oembed_fetch_url' ] === $f['callback'] && 2 === $f['accepted_args'] ) {
        $hooked = true;
    }
}
em_check( 'filter registered on oembed_fetch_url with 2 args', $hooked );

if ( $failures > 0 ) {
    echo "EmbedsTest: {$failures} failure(s)\n";
    exit( 1 );
}
echo "OK\n";
