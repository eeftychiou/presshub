<?php
/**
 * EmbedsTest — TikTok photo posts never use TikTok's player.
 *
 * Test cases:
 *   1. URL helpers: /photo/ links recognised, handle read; other links not.
 *   2. Embed handler registered for /photo/ links when the plugin loads.
 *   3. Handler output: link card to the post, handle shown, no iframe, escaped.
 *   4. Image card block: linked image + caption link, attributes escaped.
 */

require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/wordpress-stubs.php';

defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );

$GLOBALS['EMBED_HANDLERS'] = [];
function wp_embed_register_handler( $id, $regex, $callback, $priority = 10 ) {
    $GLOBALS['EMBED_HANDLERS'][ $id ] = [ 'regex' => $regex, 'callback' => $callback ];
}

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

// 1. URL helpers.
em_check( 'url: photo link recognised', $E::is_tiktok_photo_url( $photo ) );
em_check( 'url: photo link with query recognised', $E::is_tiktok_photo_url( $photo . '?is_from_webapp=1' ) );
em_check( 'url: no-www photo link recognised', $E::is_tiktok_photo_url( 'https://tiktok.com/@a/photo/5' ) );
em_check( 'url: video link not a photo link', ! $E::is_tiktok_photo_url( $video ) );
em_check( 'url: profile link not a photo link', ! $E::is_tiktok_photo_url( 'https://www.tiktok.com/@eleni.krystallis' ) );
em_check( 'url: other site not a photo link', ! $E::is_tiktok_photo_url( 'https://example.com/@a/photo/5' ) );
em_check( 'url: handle read', 'eleni.krystallis' === $E::tiktok_photo_handle( $photo ) );
em_check( 'url: no handle for other links', '' === $E::tiktok_photo_handle( $video ) );

// 2. Registered.
$h = $GLOBALS['EMBED_HANDLERS'][ $E::HANDLER_ID ] ?? null;
em_check( 'handler: registered', null !== $h && [ 'PressHub_AI_Embeds', 'tiktok_photo_handler' ] === $h['callback'] );
em_check( 'handler: matches photo links only', null !== $h && 1 === preg_match( $h['regex'], $photo ) && 0 === preg_match( $h['regex'], $video ) );
em_check( 'handler: old oEmbed fetch rewrite gone', empty( $GLOBALS['FILTERS']['oembed_fetch_url'] ) );

// 3. Handler output.
preg_match( $E::PHOTO_URL, $photo, $m );
$html = $E::tiktok_photo_handler( $m, [], $photo, [] );
em_check( 'card: links to the post', false !== strpos( $html, 'href="' . $photo . '"' ) );
em_check( 'card: names the account', false !== strpos( $html, '@eleni.krystallis' ) );
em_check( 'card: no TikTok player', false === stripos( $html, 'iframe' ) && false === strpos( $html, 'player/v1' ) );
$evil = $E::link_card( 'https://www.tiktok.com/@a"><script>/photo/5', '<b>x</b>' );
em_check( 'card: text escaped', false === strpos( $evil, '<script>' ) && false === strpos( $evil, '<b>x</b>' ) );

// 4. Image card block.
$block = $E::image_card_block( $photo, 903, 'https://presshub.test/wp-content/uploads/a.jpg', 'Η "πρώτη" γιατρός' );
em_check( 'image card: image block with the attachment id', 0 === strpos( $block, '<!-- wp:image {"id":903,' ) && false !== strpos( $block, 'wp-image-903' ) );
em_check( 'image card: image links to the post', false !== strpos( $block, '<a href="' . $photo . '" target="_blank" rel="noopener nofollow"><img src="https://presshub.test/wp-content/uploads/a.jpg"' ) );
em_check( 'image card: caption links to the post', false !== strpos( $block, '<figcaption class="wp-element-caption"><a href="' . $photo . '"' ) );
em_check( 'image card: alt escaped', false !== strpos( $block, 'alt="Η &quot;πρώτη&quot; γιατρός"' ) );
em_check( 'image card: block closed', "<!-- /wp:image -->" === substr( $block, -18 ) );

if ( $failures > 0 ) {
    echo "EmbedsTest: {$failures} failure(s)\n";
    exit( 1 );
}
echo "OK\n";
