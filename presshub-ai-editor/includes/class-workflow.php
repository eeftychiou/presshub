<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-settings-storage.php';
require_once __DIR__ . '/class-qa-reviewer.php';
require_once __DIR__ . '/class-seo.php';
require_once __DIR__ . '/class-publish-gate.php';

class PressHub_AI_Workflow {
    /**
     * Re-entrancy guard for enforce_editorial_workflow().
     *
     * wp_update_post() fires transition_post_status again, which would loop
     * indefinitely without this flag. static means the guard is shared across
     * every instance in the same request — important because WordPress
     * instantiates the plugin class once but the guard must hold for any
     * future instantiation in the same request lifecycle.
     */
    private static $enforcing = false;

    /** Terms and featured image of the REST request being saved, by post id. */
    private static $rest_state = [];

    public function __construct() {
        // Intercept transitions to ensure authors cannot publish without review
        add_action( 'transition_post_status', [ $this, 'enforce_editorial_workflow' ], 10, 3 );
        add_filter( 'rest_pre_insert_post', [ $this, 'remember_rest_terms' ], 10, 2 );
    }

    public function enforce_editorial_workflow( $new_status, $old_status, $post ) {
        // The editorial gate only applies to regular posts (Antigravity
        // C-5). Research posts and other CPTs are never routed through the
        // publish gate — they are written by cron/headless flows where
        // current_user_can() is unreliable (user 0), and reverting them
        // would corrupt non-article content. Real WP always passes a full
        // WP_Post here; bail defensively when post_type is missing.
        if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'post' !== $post->post_type ) {
            return;
        }

        // Automated Daily Briefing Hub posts (text briefing stories & audio podcasts)
        // are governed by their own settings (presshub_ai_briefing_text_status /
        // presshub_ai_briefing_podcast_status), not by the co-pilot author scorecard gate.
        if ( ! empty( get_post_meta( $post->ID, '_presshub_briefing_type', true ) ) ) {
            return;
        }

        // The Trending in Cyprus social roundup is written by the worker / cron
        // and follows its own "New posts are" setting.
        if ( ! empty( get_post_meta( $post->ID, '_presshub_social_trending_key', true ) ) ) {
            return;
        }

        // Cron and headless flows run without a logged-in user (user 0) where
        // current_user_can() is unreliable. Never block scheduled/cron publishing.
        if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }

        // Bail out if we are already enforcing to prevent infinite recursion
        // when wp_update_post() fires transition_post_status again below.
        if ( self::$enforcing ) {
            return;
        }

        // If an author tries to publish directly, run the QA gate (editorial,
        // legal and SEO checks) and hold the post when the verdict says so.
        if ( 'publish' === $new_status && 'publish' !== $old_status ) {
            // Allow editors/admins to bypass, but not authors
            if ( current_user_can( 'edit_others_posts' ) || ! PressHub_AI_Settings_Storage::get_qa_enabled() ) {
                return;
            }

            $gate = PressHub_AI_Publish_Gate::review_post( $post, 'author_publish', [
                'seo_input' => self::seo_input( $post ),
            ] );

            if ( PressHub_AI_Publish_Gate::holds( $gate ) ) {
                self::$enforcing = true;
                try {
                    wp_update_post( [
                        'ID'          => $post->ID,
                        'post_status' => 'pending',
                    ] );
                    PressHub_AI_Publish_Gate::notify( (int) $post->ID, $gate );
                } finally {
                    self::$enforcing = false;
                }
            } elseif ( 'alert' === ( $gate['verdict'] ?? '' ) ) {
                PressHub_AI_Publish_Gate::notify( (int) $post->ID, $gate );
            }
        }
    }

    /**
     * Block editor saves set tags, categories and the featured image after
     * wp_insert_post() has fired transition_post_status, so remember what the
     * request carries and let the SEO check measure that.
     *
     * @param stdClass        $prepared_post
     * @param WP_REST_Request $request
     */
    public function remember_rest_terms( $prepared_post, $request ) {
        if ( is_object( $prepared_post ) && ! empty( $prepared_post->ID ) && is_object( $request ) && method_exists( $request, 'get_param' ) ) {
            $state = [];
            foreach ( [ 'tags', 'categories', 'featured_media' ] as $key ) {
                $value = $request->get_param( $key );
                if ( null !== $value ) {
                    $state[ $key ] = $value;
                }
            }
            if ( $state ) {
                self::$rest_state[ (int) $prepared_post->ID ] = $state;
            }
        }
        return $prepared_post;
    }

    /** SEO input of the post being published, with the REST request's terms and image. */
    private static function seo_input( $post ): array {
        $post_id = (int) ( $post->ID ?? 0 );
        $input   = class_exists( 'PressHub_AI_SEO' ) ? PressHub_AI_SEO::input_for_post( $post_id ) : [ 'post_id' => $post_id ];
        foreach ( [ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' ] as $key => $field ) {
            if ( isset( $post->$field ) ) {
                $input[ $key ] = (string) $post->$field;
            }
        }
        $state = self::$rest_state[ $post_id ] ?? [];
        if ( isset( $state['tags'] ) && function_exists( 'get_term' ) ) {
            $input['tags'] = [];
            foreach ( (array) $state['tags'] as $tid ) {
                $term = get_term( (int) $tid, 'post_tag' );
                if ( $term && ! is_wp_error( $term ) && isset( $term->name ) ) {
                    $input['tags'][] = (string) $term->name;
                }
            }
        }
        if ( isset( $state['categories'] ) && function_exists( 'get_cat_name' ) ) {
            $input['categories'] = array_values( array_filter( array_map( static function ( $cid ) {
                return (string) get_cat_name( (int) $cid );
            }, (array) $state['categories'] ) ) );
        }
        if ( isset( $state['featured_media'] ) ) {
            $input['featured_image_id'] = (int) $state['featured_media'];
        }
        return $input;
    }
}
