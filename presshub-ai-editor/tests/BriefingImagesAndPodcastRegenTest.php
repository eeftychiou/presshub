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

        // Separate Morning and Noon pools tests
        update_option( 'presshub_ai_briefing_featured_image_ids_morning', '101, 102' );
        update_option( 'presshub_ai_briefing_featured_image_ids_noon', '201, 202' );

        $morning_pool = PressHub_AI_Settings_Storage::get_briefing_featured_image_ids_morning();
        if ( [ 101, 102 ] !== $morning_pool ) {
            $failures[] = 'get_briefing_featured_image_ids_morning() failed; got: ' . json_encode( $morning_pool );
        }

        $noon_pool = PressHub_AI_Settings_Storage::get_briefing_featured_image_ids_noon();
        if ( [ 201, 202 ] !== $noon_pool ) {
            $failures[] = 'get_briefing_featured_image_ids_noon() failed; got: ' . json_encode( $noon_pool );
        }

        // Edition-aware get_briefing_featured_image_ids
        if ( [ 101, 102 ] !== PressHub_AI_Settings_Storage::get_briefing_featured_image_ids( 'morning' ) ) {
            $failures[] = 'get_briefing_featured_image_ids("morning") failed';
        }
        if ( [ 201, 202 ] !== PressHub_AI_Settings_Storage::get_briefing_featured_image_ids( 'noon' ) ) {
            $failures[] = 'get_briefing_featured_image_ids("noon") failed';
        }

        // Edition-aware random IDs
        $rand_morning = PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id( 'morning' );
        if ( ! in_array( $rand_morning, [ 101, 102 ], true ) ) {
            $failures[] = 'get_random_briefing_featured_image_id("morning") returned unexpected ID: ' . $rand_morning;
        }
        $rand_noon = PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id( 'noon' );
        if ( ! in_array( $rand_noon, [ 201, 202 ], true ) ) {
            $failures[] = 'get_random_briefing_featured_image_id("noon") returned unexpected ID: ' . $rand_noon;
        }

        // Fallback to morning when noon is empty
        update_option( 'presshub_ai_briefing_featured_image_ids_noon', '' );
        $noon_fallback = PressHub_AI_Settings_Storage::get_briefing_featured_image_ids( 'noon' );
        if ( [ 101, 102 ] !== $noon_fallback ) {
            $failures[] = 'get_briefing_featured_image_ids("noon") fallback to morning failed; got: ' . json_encode( $noon_fallback );
        }
        $rand_noon_fallback = PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id( 'noon' );
        if ( ! in_array( $rand_noon_fallback, [ 101, 102 ], true ) ) {
            $failures[] = 'get_random_briefing_featured_image_id("noon") fallback returned unexpected ID: ' . $rand_noon_fallback;
        }

        // Fallback to legacy presshub_ai_briefing_featured_image_ids when morning is also empty
        update_option( 'presshub_ai_briefing_featured_image_ids_morning', '' );
        update_option( 'presshub_ai_briefing_featured_image_ids', '301, 302' );
        if ( [ 301, 302 ] !== PressHub_AI_Settings_Storage::get_briefing_featured_image_ids( 'morning' ) ) {
            $failures[] = 'get_briefing_featured_image_ids("morning") legacy fallback failed';
        }
        if ( [ 301, 302 ] !== PressHub_AI_Settings_Storage::get_briefing_featured_image_ids( 'noon' ) ) {
            $failures[] = 'get_briefing_featured_image_ids("noon") legacy fallback failed';
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
        // 2. Featured Image Assignment on Curation Post (Morning & Noon)
        // -------------------------------------------------------------
        update_option( 'presshub_ai_briefing_featured_image_ids_morning', '444, 555' );
        update_option( 'presshub_ai_briefing_featured_image_ids_noon', '666, 777' );
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
                $failures[] = 'create_wordpress_post(morning) did not assign featured image from morning pool; got: ' . $thumb;
            }
        }

        $noon_text_post_id = $curator->create_wordpress_post(
            'Test Noon Curation Body.',
            '2026-10-16',
            'Test Noon Curation Title',
            'noon'
        );
        if ( ! is_numeric( $noon_text_post_id ) ) {
            $failures[] = 'create_wordpress_post(noon) failed to return numeric post ID';
        } else {
            $noon_thumb = get_post_thumbnail_id( (int) $noon_text_post_id );
            if ( ! in_array( $noon_thumb, [ 666, 777 ], true ) ) {
                $failures[] = 'create_wordpress_post(noon) did not assign featured image from noon pool; got: ' . $noon_thumb;
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
                $failures[] = 'create_podcast_post(morning) did not assign featured image from morning pool; got: ' . $p_thumb;
            }

            // Noon podcast post creation: assigns from noon pool
            $noon_podcast_post_id = $synthesizer->create_podcast_post(
                'http://example.test/pod_noon.mp3',
                890,
                $sample_script,
                '2026-10-16',
                'Test Noon Podcast',
                'noon'
            );
            $noon_p_thumb = get_post_thumbnail_id( (int) $noon_podcast_post_id );
            if ( ! in_array( $noon_p_thumb, [ 666, 777 ], true ) ) {
                $failures[] = 'create_podcast_post(noon) did not assign featured image from noon pool; got: ' . $noon_p_thumb;
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

        // -------------------------------------------------------------
        // 8. Same-Day Featured Image Collision Avoidance
        // -------------------------------------------------------------
        // 8a: get_used_briefing_thumbnail_ids_for_date finds thumbnails of existing posts
        $GLOBALS['WP_TEST_POSTS'] = [
            1001 => (object) [
                'ID'                      => 1001,
                '_presshub_briefing_date' => '2026-10-30',
                '_thumbnail_id'           => 501,
            ],
            1002 => (object) [
                'ID'                      => 1002,
                '_presshub_briefing_date' => '2026-10-30',
                '_thumbnail_id'           => 502,
            ],
            1003 => (object) [
                'ID'                      => 1003,
                '_presshub_briefing_date' => '2026-10-31',
                '_thumbnail_id'           => 503,
            ],
        ];

        $used_for_30 = PressHub_AI_Settings_Storage::get_used_briefing_thumbnail_ids_for_date( '2026-10-30' );
        sort( $used_for_30 );
        if ( [ 501, 502 ] !== $used_for_30 ) {
            $failures[] = 'get_used_briefing_thumbnail_ids_for_date(2026-10-30) failed; got: ' . json_encode( $used_for_30 );
        }

        // Exclude post ID
        $used_with_exclude = PressHub_AI_Settings_Storage::get_used_briefing_thumbnail_ids_for_date( '2026-10-30', 1001 );
        if ( [ 502 ] !== $used_with_exclude ) {
            $failures[] = 'get_used_briefing_thumbnail_ids_for_date() with exclude_post_id failed; got: ' . json_encode( $used_with_exclude );
        }

        unset( $GLOBALS['WP_TEST_POSTS'] );

        // 8b: get_random_briefing_featured_image_id excludes specified IDs when alternatives exist
        update_option( 'presshub_ai_briefing_featured_image_ids_morning', '101, 102, 103' );
        $only_103 = PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id( 'morning', [ 101, 102 ] );
        if ( 103 !== $only_103 ) {
            $failures[] = 'get_random_briefing_featured_image_id(morning, [101, 102]) must return 103; got: ' . $only_103;
        }

        $not_103 = PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id( 'morning', [ 103 ] );
        if ( ! in_array( $not_103, [ 101, 102 ], true ) ) {
            $failures[] = 'get_random_briefing_featured_image_id(morning, [103]) must return 101 or 102; got: ' . $not_103;
        }

        // 8c: When pool is exhausted (all pool IDs in exclude_ids), gracefully falls back to pool
        $fallback_id = PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id( 'morning', [ 101, 102, 103 ] );
        if ( ! in_array( $fallback_id, [ 101, 102, 103 ], true ) || 0 === $fallback_id ) {
            $failures[] = 'get_random_briefing_featured_image_id() when exhausted must fall back to pool; got: ' . $fallback_id;
        }

        // 8d: Morning text briefing and morning podcast created on same date receive distinct images
        update_option( 'presshub_ai_briefing_featured_image_ids_morning', '101, 102' );
        $same_day_date = '2026-10-25';

        $morning_text_id = $curator->create_wordpress_post(
            'Morning text briefing story content.',
            $same_day_date,
            'Morning Text Briefing 25/10',
            'morning'
        );
        $morning_text_thumb = get_post_thumbnail_id( (int) $morning_text_id );

        $morning_podcast_id = $synthesizer->create_podcast_post(
            'http://example.test/same_day_morning.mp3',
            911,
            "[SPEAKER_1]: Hello morning.\n[SPEAKER_2]: Good morning.",
            $same_day_date,
            'Morning Podcast 25/10',
            'morning'
        );
        $morning_podcast_thumb = get_post_thumbnail_id( (int) $morning_podcast_id );

        if ( ! in_array( $morning_text_thumb, [ 101, 102 ], true ) ) {
            $failures[] = 'Morning text briefing did not get image from morning pool; got: ' . $morning_text_thumb;
        }
        if ( ! in_array( $morning_podcast_thumb, [ 101, 102 ], true ) ) {
            $failures[] = 'Morning podcast did not get image from morning pool; got: ' . $morning_podcast_thumb;
        }
        if ( $morning_text_thumb === $morning_podcast_thumb ) {
            $failures[] = "Same-day morning text briefing and podcast collided on thumbnail ID: {$morning_text_thumb}";
        }

        // 8e: Noon text briefing and noon podcast created on same date receive distinct images
        update_option( 'presshub_ai_briefing_featured_image_ids_noon', '201, 202' );

        $noon_text_id = $curator->create_wordpress_post(
            'Noon text briefing story content.',
            $same_day_date,
            'Noon Text Briefing 25/10',
            'noon'
        );
        $noon_text_thumb = get_post_thumbnail_id( (int) $noon_text_id );

        $noon_podcast_id = $synthesizer->create_podcast_post(
            'http://example.test/same_day_noon.mp3',
            912,
            "[SPEAKER_1]: Hello noon.\n[SPEAKER_2]: Good afternoon.",
            $same_day_date,
            'Noon Podcast 25/10',
            'noon'
        );
        $noon_podcast_thumb = get_post_thumbnail_id( (int) $noon_podcast_id );

        if ( ! in_array( $noon_text_thumb, [ 201, 202 ], true ) ) {
            $failures[] = 'Noon text briefing did not get image from noon pool; got: ' . $noon_text_thumb;
        }
        if ( ! in_array( $noon_podcast_thumb, [ 201, 202 ], true ) ) {
            $failures[] = 'Noon podcast did not get image from noon pool; got: ' . $noon_podcast_thumb;
        }
        if ( $noon_text_thumb === $noon_podcast_thumb ) {
            $failures[] = "Same-day noon text briefing and podcast collided on thumbnail ID: {$noon_text_thumb}";
        }

        // -------------------------------------------------------------
        // 9. Morning Podcast Title Prefix Setting & Title Resolution
        // -------------------------------------------------------------
        if ( 'Πρωινό Podcast Ενημέρωσης - ' !== PressHub_AI_Settings_Storage::get_briefing_podcast_title_prefix() ) {
            $failures[] = 'get_briefing_podcast_title_prefix() should default to "Πρωινό Podcast Ενημέρωσης - "; got: ' . PressHub_AI_Settings_Storage::get_briefing_podcast_title_prefix();
        }
        if ( 'Πρωινό Podcast Ενημέρωσης - ' !== PressHub_AI_Settings_Storage::get_briefing_morning_podcast_title_prefix() ) {
            $failures[] = 'get_briefing_morning_podcast_title_prefix() alias should match primary getter';
        }
        // Custom prefix
        update_option( 'presshub_ai_briefing_podcast_title_prefix', 'Custom Morning Pod - ' );
        if ( 'Custom Morning Pod - ' !== PressHub_AI_Settings_Storage::get_briefing_podcast_title_prefix() ) {
            $failures[] = 'get_briefing_podcast_title_prefix() failed to return custom prefix';
        }
        $auto_title_pod_id = $synthesizer->create_podcast_post(
            'http://example.test/auto_title.mp3',
            991,
            "[SPEAKER_1]: Hello.\n[SPEAKER_2]: Hi.",
            '2026-10-18',
            '', // empty title -> should auto-format with prefix
            'morning'
        );
        $auto_title_pod = get_post( (int) $auto_title_pod_id );
        if ( ! $auto_title_pod || false === strpos( $auto_title_pod->post_title, 'Custom Morning Pod - ' ) ) {
            $failures[] = 'create_podcast_post() did not use customized morning podcast title prefix; got: ' . ( $auto_title_pod->post_title ?? 'null' );
        }
        delete_option( 'presshub_ai_briefing_podcast_title_prefix' );

        // -------------------------------------------------------------
        // 10. Podcast Excerpt Suppression & Filter Tests
        // -------------------------------------------------------------
        delete_option( 'presshub_ai_briefing_podcast_include_text' ); // default = 0
        $pod_post_excerpt_test_id = $synthesizer->create_podcast_post(
            'http://example.test/excerpt_test.mp3',
            992,
            "[TOPIC_START: Εισαγωγή] [SPEAKER_1]: Καλωσήρθατε στο podcast.\n[SPEAKER_2]: Γεια σας.\n[TOPIC_END]",
            '2026-10-18',
            'Excerpt Test Pod',
            'morning'
        );
        $pod_post_excerpt_obj = get_post( (int) $pod_post_excerpt_test_id );
        if ( '' !== ( $pod_post_excerpt_obj->post_excerpt ?? '' ) ) {
            $failures[] = 'create_podcast_post() post_excerpt must be empty string when include_text is 0; got: ' . $pod_post_excerpt_obj->post_excerpt;
        }

        // Test filter_podcast_excerpt on new post
        $filtered_excerpt = PressHub_AI_Audio_Synthesizer::filter_podcast_excerpt( $pod_post_excerpt_obj->post_excerpt, $pod_post_excerpt_obj );
        if ( '' !== $filtered_excerpt ) {
            $failures[] = 'filter_podcast_excerpt() must return empty string when include_text is 0; got: ' . $filtered_excerpt;
        }

        // Test filter_podcast_excerpt on legacy post where post_excerpt was already contaminated in DB
        $legacy_pod_post = (object) [
            'ID'           => 993,
            'post_title'   => 'Legacy Contaminated Podcast Post',
            'post_content' => '<figure class="wp-block-audio"><audio controls></audio></figure>',
            'post_excerpt' => '[TOPIC_START: Εισαγωγή] [SPEAKER_1]: Καλωσήρθατε στο σημερινό Briefing του Presshub.cy.',
        ];
        $GLOBALS['POSTS_STORE'][ 993 ] = (array) $legacy_pod_post;
        $GLOBALS['POST_META_STORE'][ 993 ] = [
            '_presshub_briefing_type'   => 'podcast',
            '_presshub_briefing_date'   => '2026-10-18',
            '_presshub_podcast_script' => '[TOPIC_START: Εισαγωγή] [SPEAKER_1]: Καλωσήρθατε στο σημερινό Briefing του Presshub.cy.',
        ];
        $legacy_filtered = PressHub_AI_Audio_Synthesizer::filter_podcast_excerpt( $legacy_pod_post->post_excerpt, $legacy_pod_post );
        if ( '' !== $legacy_filtered ) {
            $failures[] = 'filter_podcast_excerpt() must suppress legacy contaminated script excerpt when include_text is 0; got: ' . $legacy_filtered;
        }

        // Updating legacy post must clear post_excerpt in DB when include_text is 0
        $synthesizer->update_podcast_post( 993, 'http://example.test/updated_pod.mp3', 994, 'Updated script', '2026-10-18', 'Updated Title', 'morning' );
        $updated_legacy_obj = get_post( 993 );
        if ( '' !== ( $updated_legacy_obj->post_excerpt ?? '' ) ) {
            $failures[] = 'update_podcast_post() must clear post_excerpt to empty string when include_text is 0; got: ' . $updated_legacy_obj->post_excerpt;
        }

        // Non-podcast regular posts must NOT have their excerpts suppressed
        $regular_post = (object) [
            'ID'           => 995,
            'post_title'   => 'Regular News Article',
            'post_content' => 'Full article content.',
            'post_excerpt' => 'This is a genuine article excerpt.',
        ];
        $regular_filtered = PressHub_AI_Audio_Synthesizer::filter_podcast_excerpt( $regular_post->post_excerpt, $regular_post );
        if ( 'This is a genuine article excerpt.' !== $regular_filtered ) {
            $failures[] = 'filter_podcast_excerpt() must not modify non-podcast posts; got: ' . $regular_filtered;
        }

        // Cleanup test options
        delete_option( 'presshub_ai_briefing_featured_image_ids_morning' );
        delete_option( 'presshub_ai_briefing_featured_image_ids_noon' );
        delete_option( 'presshub_ai_briefing_featured_image_ids' );
        delete_option( 'presshub_ai_briefing_podcast_include_text' );
        delete_option( 'presshub_ai_briefing_podcast_title_prefix' );

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
