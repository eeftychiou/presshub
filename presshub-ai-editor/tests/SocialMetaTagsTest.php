<?php
/**
 * Test Suite for Social Meta Tags (OpenGraph & Twitter Card) and Document Title Length Optimization.
 *
 * Requirement 4.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-social-meta.php';

class SocialMetaTagsTest {

    public static function run(): void {
        $failures = [];

        $social = new PressHub_AI_Social_Meta();

        // -------------------------------------------------------------
        // 1. Post Type Recognition
        // -------------------------------------------------------------
        $GLOBALS['IS_SINGULAR'] = true;
        $mock_post = (object) [
            'ID'           => 301,
            'post_title'   => 'Πρωινό Podcast Ενημέρωσης – 16/10/2026',
            'post_content' => '<!-- wp:audio {"id":123} --><figure class="wp-block-audio"><audio src="http://example.test/a.mp3"></audio></figure><!-- /wp:audio -->',
            'post_excerpt' => '',
        ];
        $GLOBALS['QUERIED_OBJECT'] = $mock_post;
        update_post_meta( 301, '_presshub_briefing_type', 'podcast' );
        update_post_meta( 301, '_presshub_briefing_date', '2026-10-16' );
        update_post_meta( 301, '_presshub_briefing_edition', 'morning' );
        update_post_meta( 301, '_presshub_podcast_script', "[TOPIC_START: Εισαγωγή]\n[SPEAKER_1]: Καλημέρα σας από το PressHub. Σήμερα εξετάζουμε τις οικονομικές εξελίξεις.\n[TOPIC_END]" );

        if ( ! $social->is_presshub_post( $mock_post ) ) {
            $failures[] = 'is_presshub_post() failed to recognize podcast briefing post';
        }

        $regular_post = (object) [ 'ID' => 302, 'post_title' => 'Regular post', 'post_content' => 'Content', 'post_excerpt' => '' ];
        if ( $social->is_presshub_post( $regular_post ) ) {
            $failures[] = 'is_presshub_post() should return false for regular posts';
        }

        // -------------------------------------------------------------
        // 2. Title & Description Resolution
        // -------------------------------------------------------------
        $title = $social->get_social_title( $mock_post );
        if ( false === strpos( $title, 'Πρωινό Podcast Ενημέρωσης' ) ) {
            $failures[] = 'get_social_title() unexpected result: ' . $title;
        }

        // When include_text is 0 (default), description must NOT leak dialogue script, but offer a clean audio summary
        $desc = $social->get_social_description( $mock_post );
        if ( false === strpos( $desc, 'Ακούστε το ηχητικό podcast ενημέρωσης' ) ) {
            $failures[] = 'get_social_description() for podcast (include_text=0) should produce clean audio description; got: ' . $desc;
        }
        if ( false !== strpos( $desc, 'TOPIC' ) || false !== strpos( $desc, 'SPEAKER_' ) ) {
            $failures[] = 'get_social_description() must not contain script markers: ' . $desc;
        }

        // When include_text is 1, description falls back to _presshub_podcast_script stripped of topic markers and speaker tags
        update_option( 'presshub_ai_briefing_podcast_include_text', 1 );
        $desc_with_text = $social->get_social_description( $mock_post );
        if ( false === strpos( $desc_with_text, 'Καλημέρα σας από το PressHub' ) ) {
            $failures[] = 'get_social_description() missing script content when include_text is 1: ' . $desc_with_text;
        }
        if ( false !== strpos( $desc_with_text, '[TOPIC_START' ) || false !== strpos( $desc_with_text, '[SPEAKER_' ) ) {
            $failures[] = 'get_social_description() must not contain [TOPIC_START or [SPEAKER_: ' . $desc_with_text;
        }
        delete_option( 'presshub_ai_briefing_podcast_include_text' );

        // -------------------------------------------------------------
        // 3. Featured Image Resolution with Pool Fallback
        // -------------------------------------------------------------
        // Initially no post thumbnail, fallback to pool
        update_option( 'presshub_ai_briefing_featured_image_ids', '777, 888' );
        $pool_img = $social->get_social_image_url( $mock_post );
        if ( 'https://example.com/wp-content/uploads/image-777.jpg' !== $pool_img ) {
            $failures[] = 'get_social_image_url() failed to fall back to pool image; got: ' . $pool_img;
        }

        // When post thumbnail is set, it takes priority
        set_post_thumbnail( $mock_post, 999 );
        $post_img = $social->get_social_image_url( $mock_post );
        if ( 'https://example.com/wp-content/uploads/image-999.jpg' !== $post_img ) {
            $failures[] = 'get_social_image_url() failed to use post thumbnail; got: ' . $post_img;
        }

        // -------------------------------------------------------------
        // 4. OpenGraph & Twitter Card Markup Output in wp_head
        // -------------------------------------------------------------
        ob_start();
        $social->render_social_meta_tags();
        $html = ob_get_clean();

        $required_tags = [
            '<meta name="description"',
            '<meta property="og:site_name"',
            '<meta property="og:type" content="article"',
            '<meta property="og:title"',
            '<meta property="og:url"',
            '<meta property="og:description"',
            '<meta property="og:image"',
            '<meta property="og:image:secure_url"',
            '<meta name="twitter:card" content="summary_large_image"',
            '<meta name="twitter:title"',
            '<meta name="twitter:description"',
            '<meta name="twitter:image"',
        ];

        foreach ( $required_tags as $tag ) {
            if ( false === strpos( $html, $tag ) ) {
                $failures[] = "render_social_meta_tags() missing tag: {$tag}\nRendered:\n{$html}";
            }
        }

        // -------------------------------------------------------------
        // 5. Document Title Length Optimizer (<= 60 chars)
        // -------------------------------------------------------------
        $parts = [
            'title' => 'Πρωινό Podcast Ενημέρωσης – 16/10/2026',
            'site'  => 'PressHub.Cy',
        ];
        // Combined length = 40 + 3 + 11 = 54 -> already good, but let's test a 64+ char title
        $long_parts = [
            'title' => 'Πρωινό Podcast Ενημέρωσης – 16/10/2026 – Αναλυτική Κάλυψη Ειδήσεων',
            'site'  => 'PressHub.Cy - Ηλεκτρονική Εφημερίδα',
        ];
        $optimized = $social->optimize_document_title_parts( $long_parts );
        $new_full_title = $optimized['title'] . ' - ' . $optimized['site'];

        if ( mb_strlen( $new_full_title ) > 60 ) {
            $failures[] = sprintf( 'optimize_document_title_parts() failed to reduce title <= 60 chars (got %d chars): "%s"', mb_strlen( $new_full_title ), $new_full_title );
        }
        if ( false === strpos( $optimized['title'], 'Πρωινό Podcast (16/10/2026)' ) ) {
            $failures[] = 'optimize_document_title_parts() did not format streamlined podcast title; got: ' . $optimized['title'];
        }

        // Test Noon briefing post title optimization
        $noon_post = (object) [ 'ID' => 303, 'post_title' => 'Long title', 'post_content' => '', 'post_excerpt' => '' ];
        $GLOBALS['QUERIED_OBJECT'] = $noon_post;
        update_post_meta( 303, '_presshub_briefing_type', 'text' );
        update_post_meta( 303, '_presshub_briefing_date', '2026-10-16' );
        update_post_meta( 303, '_presshub_briefing_edition', 'noon' );

        $noon_long_parts = [
            'title' => 'Μεσημβρινή Ενημέρωση: Έκτακτες Εξελίξεις στην Οικονομία και Πολιτική',
            'site'  => 'PressHub.Cy - Ειδήσεις',
        ];
        $noon_opt = $social->optimize_document_title_parts( $noon_long_parts );
        $noon_full = $noon_opt['title'] . ' - ' . $noon_opt['site'];
        if ( mb_strlen( $noon_full ) > 60 ) {
            $failures[] = sprintf( 'optimize_document_title_parts() for noon failed to reduce title <= 60 chars (got %d chars): "%s"', mb_strlen( $noon_full ), $noon_full );
        }
        if ( false === strpos( $noon_opt['title'], 'Μεσημβρινή Ενημέρωση (16/10/2026)' ) ) {
            $failures[] = 'optimize_document_title_parts() did not format streamlined noon text title; got: ' . $noon_opt['title'];
        }

        // Cleanup
        delete_option( 'presshub_ai_briefing_featured_image_ids' );

        if ( ! empty( $failures ) ) {
            echo "FAIL: SocialMetaTagsTest\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }

        echo "SocialMetaTagsTest: OK\n";
    }
}

SocialMetaTagsTest::run();
