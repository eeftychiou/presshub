<?php
/**
 * Regression: the News Sources table in assets/admin.js re-renders rows
 * after every change. Its media-type map must know every source type the
 * server offers, or X / TikTok accounts show as "News Website (HTML)".
 */
class AdminJsSourceTypeLabelsTest
{
    public static function run(): void {
        $js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
        $failures = array();
        if ( ! preg_match( '/var mediaTypesMeta = \{(.*?)\};/s', $js, $m ) ) {
            $failures[] = 'mediaTypesMeta map not found in assets/admin.js';
        } else {
            $expected = array(
                'text_news'     => 'News Website (HTML)',
                'rss_feed'      => 'RSS / Atom Feed',
                'social_x'      => 'X (Twitter) Account',
                'social_tiktok' => 'TikTok Account',
                'social_facebook' => 'Facebook Page',
                'youtube'       => 'YouTube Channel / Playlist',
                'vlog'          => 'Video / Vlog Feed',
                'podcast_audio' => 'Audio Podcast / RSS',
            );
            foreach ( $expected as $key => $label ) {
                if ( ! preg_match( "/'" . preg_quote( $key, '/' ) . "'\s*:\s*\{\s*label:\s*'" . preg_quote( $label, '/' ) . "'/", $m[1] ) ) {
                    $failures[] = "mediaTypesMeta is missing '{$key}' => '{$label}'";
                }
            }
        }
        if ( $failures ) {
            fwrite( STDERR, "FAIL\n  - " . implode( "\n  - ", $failures ) . "\n" );
            exit( 1 );
        }
        echo "OK - AdminJsSourceTypeLabelsTest: all checks passed\n";
    }
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === realpath( __FILE__ ) ) {
    AdminJsSourceTypeLabelsTest::run();
}
