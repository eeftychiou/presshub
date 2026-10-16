<?php
/**
 * Test Suite for Featured Image Pool Cycling, Podcast Text Inclusion,
 * Dialogue Script Editor Metabox, and Audio Regeneration.
 *
 * Requirements 2 & 3.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-news-curator.php';
require_once __DIR__ . '/../includes/class-audio-synthesizer.php';
require_once __DIR__ . '/../includes/class-metaboxes.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class BriefingImagesAndPodcastRegenTest {

    public static function run(): void {
        $failures = [];

        // -------------------------------------------------------------
        // 1. Settings Storage: Featured Image Pool & Podcast Include Text
        // -------------------------------------------------------------
        update_option( 'presshub_ai_briefing_featured_image_ids', '101, 102, 103' );
        $ids = PressHub_AI_Settings_Storage::get_briefing_featured_image_ids();
        if ( [ 101, 102, 103 ] !== $ids ) {
            $failures[] = 'get_briefing_featured_image_ids() failed; got: ' . json_encode( $ids );
        }

        $sanitized = PressHub_AI_Settings_Storage::sanitize_briefing_featured_image_ids( '12, invalid, 34, -5, 56' );
        if ( '12,34,56' !== $sanitized ) {
            $failures[] = 'sanitize_briefing_featured_image_ids() failed; got: ' . $sanitized;
        }

        $random_id = PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id();
        if ( ! in_array( $random_id, [ 101, 102, 103 ], true ) ) {
            $failures[] = 'get_random_briefing_featured_image_id() returned unexpected ID: ' . $random_id;
        }

        // Test empty pool returns 0
        update_option( 'presshub_ai_briefing_featured_image_ids', '' );
        if ( 0 !== PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id() ) {
            $failures[] = 'get_random_briefing_featured_image_id() on empty pool must return 0';
        }

        // Test podcast include text default is false (0)
        delete_option( 'presshub_ai_briefing_podcast_include_text' );
        if ( false !== PressHub_AI_Settings_Storage::get_briefing_podcast_include_text() ) {
            $failures[] = 'get_briefing_podcast_include_text() default must be false';
        }
        update_option( 'presshub_ai_briefing_podcast_include_text', 1 );
        if ( true !== PressHub_AI_Settings_Storage::get_briefing_podcast_include_text() ) {
            $failures[] = 'get_briefing_podcast_include_text() should return true when set';
        }

        // -------------------------------------------------------------
        // 2. Featured Image Assignment on Curation Post
        // -------------------------------------------------------------
        update_option( 'presshub_ai_briefing_featured_image_ids', '444, 555' );
        $curator = new PressHub_AI_News_Curator();
        $text_post_id = $curator->create_wordpress_post(
            'Test Curation Body with some news.',
            '2026-10-16',
            'Test Curation Title',
            'morning'
        );

        if ( ! is_numeric( $text_post_id ) ) {
            $failures[] = 'create_wordpress_post() failed to return numeric post ID';
        } else {
            $thumb = get_post_thumbnail_id( (int) $text_post_id );
            if ( ! in_array( $thumb, [ 444, 555 ], true ) ) {
                $failures[] = 'create_wordpress_post() did not assign featured image from pool; got: ' . $thumb;
            }
        }

        // -------------------------------------------------------------
        // 3. Audio Podcast Post Creation: No Text by Default
        // -------------------------------------------------------------
        delete_option( 'presshub_ai_briefing_podcast_include_text' ); // Default = 0
        $synthesizer = new PressHub_AI_Audio_Synthesizer();
        $sample_script = "[SPEAKER_1]: Καλημέρα σας.\n[SPEAKER_2]: Καλημέρα, σήμερα έχουμε σημαντικές ειδήσεις.";
        
        $podcast_post_id = $synthesizer->create_podcast_post(
            'http://example.test/pod.mp3',
            888,
            $sample_script,
            '2026-10-16',
            'Test Podcast',
            'morning'
        );

        if ( ! is_numeric( $podcast_post_id ) ) {
            $failures[] = 'create_podcast_post() failed to return numeric post ID';
        } else {
            $post = get_post( (int) $podcast_post_id );
            $content = $post ? $post->post_content : '';
            
            // Audio block must be present
            if ( false === strpos( $content, 'wp:audio' ) || false === strpos( $content, 'http://example.test/pod.mp3' ) ) {
                $failures[] = 'create_podcast_post() content missing audio block: ' . $content;
            }

            // Transcript text must NOT be present in content when include_text is 0
            if ( false !== strpos( $content, 'Καλημέρα σας' ) || false !== strpos( $content, 'Απομαγνητοφώνηση / Διάλογος' ) ) {
                $failures[] = 'create_podcast_post() content must NOT contain transcript when include_text is 0; got: ' . $content;
            }

            // Script must be stored in post meta
            $saved_script = get_post_meta( (int) $podcast_post_id, '_presshub_podcast_script', true );
            if ( $saved_script !== $sample_script ) {
                $failures[] = '_presshub_podcast_script meta mismatch; got: ' . $saved_script;
            }

            // Featured image must be assigned
            $p_thumb = get_post_thumbnail_id( (int) $podcast_post_id );
            if ( ! in_array( $p_thumb, [ 444, 555 ], true ) ) {
                $failures[] = 'create_podcast_post() did not assign featured image from pool; got: ' . $p_thumb;
            }
        }

        // -------------------------------------------------------------
        // 4. Audio Podcast Post Creation: With Text when Enabled
        // -------------------------------------------------------------
        update_option( 'presshub_ai_briefing_podcast_include_text', 1 );
        $podcast_with_text_id = $synthesizer->create_podcast_post(
            'http://example.test/pod2.mp3',
            889,
            $sample_script,
            '2026-10-16',
            'Test Podcast With Text',
            'morning'
        );
        $content_with_text = get_post( (int) $podcast_with_text_id )->post_content ?? '';
        if ( false === strpos( $content_with_text, 'Καλημέρα σας' ) || false === strpos( $content_with_text, 'Απομαγνητοφώνηση / Διάλογος' ) ) {
            $failures[] = 'create_podcast_post() content should contain transcript when include_text is 1; got: ' . $content_with_text;
        }
        delete_option( 'presshub_ai_briefing_podcast_include_text' );

        // -------------------------------------------------------------
        // 5. Update Podcast Post & Audio Regeneration Method
        // -------------------------------------------------------------
        $updated_script = "[SPEAKER_1]: Καλημέρα σε όλους.\n[SPEAKER_2]: Διορθωμένο κείμενο διαλόγου.";
        $update_res = $synthesizer->update_podcast_post(
            (int) $podcast_post_id,
            'http://example.test/pod_v2.mp3',
            999,
            $updated_script,
            '2026-10-16',
            'Updated Podcast Title',
            'morning'
        );

        if ( (int) $update_res !== (int) $podcast_post_id ) {
            $failures[] = 'update_podcast_post() did not return expected post ID; got: ' . var_export( $update_res, true );
        }

        $updated_post = get_post( (int) $podcast_post_id );
        if ( false === strpos( $updated_post->post_content, 'http://example.test/pod_v2.mp3' ) ) {
            $failures[] = 'update_podcast_post() failed to update audio URL in post_content';
        }
        $updated_meta_script = get_post_meta( (int) $podcast_post_id, '_presshub_podcast_script', true );
        if ( $updated_meta_script !== $updated_script ) {
            $failures[] = 'update_podcast_post() failed to update _presshub_podcast_script meta';
        }

        // Test regenerate_podcast_for_post rejection on non-podcast post
        $non_podcast_res = $synthesizer->regenerate_podcast_for_post( (int) $text_post_id, 'New script' );
        if ( ! is_wp_error( $non_podcast_res ) || 'not_a_podcast' !== $non_podcast_res->get_error_code() ) {
            $failures[] = 'regenerate_podcast_for_post() must fail on non-podcast post; got: ' . var_export( $non_podcast_res, true );
        }

        // Test regenerate_podcast_for_post rejection on empty script
        $empty_script_res = $synthesizer->regenerate_podcast_for_post( (int) $podcast_post_id, '   ' );
        if ( ! is_wp_error( $empty_script_res ) || 'empty_script' !== $empty_script_res->get_error_code() ) {
            $failures[] = 'regenerate_podcast_for_post() must fail on empty script; got: ' . var_export( $empty_script_res, true );
        }

        // -------------------------------------------------------------
        // 6. Podcast Script Editor Metabox Render & Save
        // -------------------------------------------------------------
        $metaboxes = new PressHub_AI_Metaboxes();
        ob_start();
        $metaboxes->render_podcast_metabox( $updated_post );
        $metabox_html = ob_get_clean();

        if ( false === strpos( $metabox_html, 'id="presshub_podcast_script"' ) ) {
            $failures[] = 'render_podcast_metabox() missing #presshub_podcast_script textarea';
        }
        if ( false === strpos( $metabox_html, 'id="presshub-regenerate-podcast-btn"' ) ) {
            $failures[] = 'render_podcast_metabox() missing #presshub-regenerate-podcast-btn button';
        }
        if ( false === strpos( $metabox_html, 'Διορθωμένο κείμενο διαλόγου' ) ) {
            $failures[] = 'render_podcast_metabox() textarea missing current script content';
        }

        // Test metabox save
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'edit_post' ];
        $_POST['presshub_podcast_save_nonce'] = wp_create_nonce( 'presshub_podcast_save_script' );
        $_POST['presshub_podcast_script']     = 'Manually saved script in editor';
        $metaboxes->save_podcast_metabox_data( (int) $podcast_post_id );
        if ( 'Manually saved script in editor' !== get_post_meta( (int) $podcast_post_id, '_presshub_podcast_script', true ) ) {
            $failures[] = 'save_podcast_metabox_data() did not save script to post meta';
        }

        // -------------------------------------------------------------
        // 7. AJAX Endpoint: presshub_ai_regenerate_podcast_audio Gate & Call
        // -------------------------------------------------------------
        $ajax = new PressHub_AI_Ajax_Handlers();

        // 7a: Simple author (only edit_posts, no edit_others_posts) is denied
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST = [
            'nonce'   => wp_create_nonce( 'presshub_ai_nonce' ),
            'post_id' => $podcast_post_id,
            'script'  => 'Valid script text',
        ];
        $captured_error = null;
        try {
            $ajax->regenerate_podcast_audio();
        } catch ( \RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
            $captured_error = end( $responses );
        }
        if ( empty( $captured_error ) || false !== ( $captured_error['success'] ?? true ) ) {
            $failures[] = 'regenerate_podcast_audio AJAX must deny access to simple authors; got: ' . json_encode( $captured_error );
        }

        // Cleanup test options
        delete_option( 'presshub_ai_briefing_featured_image_ids' );
        delete_option( 'presshub_ai_briefing_podcast_include_text' );

        if ( ! empty( $failures ) ) {
            echo "FAIL: BriefingImagesAndPodcastRegenTest\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }

        echo "BriefingImagesAndPodcastRegenTest: OK\n";
    }
}

BriefingImagesAndPodcastRegenTest::run();
