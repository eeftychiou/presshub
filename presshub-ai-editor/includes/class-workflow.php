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

        // A new post saved through REST had no ID when its terms were
        // remembered: this first transition is that post's insert.
        if ( isset( self::$rest_state[0] ) && ! empty( $post->ID ) ) {
            if ( ! isset( self::$rest_state[ (int) $post->ID ] ) ) {
                self::$rest_state[ (int) $post->ID ] = self::$rest_state[0];
            }
            unset( self::$rest_state[0] );
        }

        // Automated posts (Daily Briefing text and podcast, the Trending
        // roundup) are reviewed by PressHub_AI_Publish_Gate::release() after
        // their tags, excerpt and image are in place, and the gate's own
        // status changes must not be reviewed again here.
        if ( PressHub_AI_Publish_Gate::is_releasing()
            || ! empty( get_post_meta( $post->ID, '_presshub_briefing_type', true ) )
            || ! empty( get_post_meta( $post->ID, '_presshub_social_trending_key', true ) ) ) {
            return;
        }

        // Bail out if we are already enforcing to prevent infinite recursion
        // when wp_update_post() fires transition_post_status again below.
        if ( self::$enforcing ) {
            return;
        }

        $goes_live = 'publish' === $new_status && 'publish' !== $old_status;
        $schedules = 'future' === $new_status && 'future' !== $old_status;
        if ( ( ! $goes_live && ! $schedules ) || ! PressHub_AI_Settings_Storage::get_qa_enabled() ) {
            return;
        }

        // A scheduled post coming due is published by cron (user 0). It was
        // reviewed when it was scheduled, and the saved review is reused
        // unless the post or the gate settings changed since.
        $by_cron  = ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
        $due      = $goes_live && 'future' === $old_status;
        if ( $by_cron && ! $due ) {
            return;
        }

        // Editors and admins are checked too, unless "Check posts editors publish" is off.
        // At go-live by cron there is no user: look at who last saved the post.
        if ( ! PressHub_AI_Settings_Storage::get_qa_check_editors() ) {
            if ( $by_cron ) {
                $last = (int) get_post_meta( $post->ID, '_edit_last', true );
                $last = $last > 0 ? $last : (int) ( $post->post_author ?? 0 );
                if ( $last > 0 && function_exists( 'user_can' ) && user_can( $last, 'edit_others_posts' ) ) {
                    return;
                }
            } elseif ( current_user_can( 'edit_others_posts' ) ) {
                return;
            }
        }

        // Released with "Publish anyway" and unchanged since.
        if ( PressHub_AI_Publish_Gate::is_overridden( $post ) ) {
            return;
        }

        if ( $due ) {
            $trigger = 'scheduled_publish';
        } elseif ( $schedules ) {
            $trigger = current_user_can( 'edit_others_posts' ) ? 'editor_schedule' : 'author_schedule';
        } else {
            $trigger = current_user_can( 'edit_others_posts' ) ? 'editor_publish' : 'author_publish';
        }

        $gate = PressHub_AI_Publish_Gate::review_post( $post, $trigger, [
            'seo_input' => self::seo_input( $post ),
        ] );

        if ( PressHub_AI_Publish_Gate::holds( $gate ) ) {
            self::$enforcing = true;
            try {
                // Remember where it was going, so "Publish anyway" can send it there.
                update_post_meta( $post->ID, PressHub_AI_Publish_Gate::META_TARGET_STATUS, $new_status );
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

    /**
     * Block editor saves set tags, categories and the featured image after
     * wp_insert_post() has fired transition_post_status, so remember what the
     * request carries and let the SEO check measure that.
     *
     * @param stdClass        $prepared_post
     * @param WP_REST_Request $request
     */
    public function remember_rest_terms( $prepared_post, $request ) {
        if ( is_object( $prepared_post ) && is_object( $request ) && method_exists( $request, 'get_param' ) ) {
            $state = [];
            foreach ( [ 'tags', 'categories', 'featured_media' ] as $key ) {
                $value = $request->get_param( $key );
                if ( null !== $value ) {
                    $state[ $key ] = $value;
                }
            }
            if ( $state ) {
                // A new post created straight as published has no ID yet: keep it under 0 for its insert.
                self::$rest_state[ (int) ( $prepared_post->ID ?? 0 ) ] = $state;
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
        // One request's terms are used once, never by a later save.
        unset( self::$rest_state[ $post_id ] );
        if ( isset( $state['tags'] ) && function_exists( 'get_term' ) ) {
            $input['tags_from_request'] = true;
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
