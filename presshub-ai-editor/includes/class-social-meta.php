<?php
/**
 * Social Meta Tags & Document Title Length Optimizer for PressHub AI.
 *
 * Emits complete OpenGraph and Twitter Card metadata for daily briefings
 * and podcast posts, and optimizes document title lengths to stay within
 * search engine snippet and social card limits (<= 60 characters).
 *
 * @package PressHub_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Social_Meta {

    /**
     * Initialize social meta hooks.
     */
    public function __construct() {
        add_action( 'wp_head', [ $this, 'render_social_meta_tags' ], 5 );
        add_filter( 'document_title_parts', [ $this, 'optimize_document_title_parts' ], 20 );
    }

    /**
     * Check if current queried post is a PressHub briefing or podcast post.
     *
     * @param \WP_Post|null $post Optional post object.
     * @return bool
     */
    public function is_presshub_post( $post = null ): bool {
        if ( ! $post && function_exists( 'is_singular' ) && ! is_singular( 'post' ) && function_exists( 'is_single' ) && ! is_single() ) {
            return false;
        }
        if ( ! $post && function_exists( 'get_queried_object' ) ) {
            $post = get_queried_object();
        }
        if ( ! $post && ! empty( $GLOBALS['post'] ) ) {
            $post = $GLOBALS['post'];
        }
        if ( ! is_object( $post ) || empty( $post->ID ) ) {
            return false;
        }

        $type = get_post_meta( $post->ID, '_presshub_briefing_type', true );
        return ! empty( $type );
    }

    /**
     * Render OpenGraph, Twitter Card, and standard description meta tags in <head>.
     */
    public function render_social_meta_tags(): void {
        if ( function_exists( 'is_singular' ) && ! is_singular( 'post' ) && function_exists( 'is_single' ) && ! is_single() ) {
            return;
        }

        // Allow third-party SEO plugins or operators to disable tags if desired
        if ( ! apply_filters( 'presshub_ai_enable_social_meta', true ) ) {
            return;
        }

        $post = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
        if ( ! is_object( $post ) || empty( $post->ID ) ) {
            return;
        }

        // Only emit if this is a PressHub post or if general post meta is requested
        $is_presshub = $this->is_presshub_post( $post );
        if ( ! $is_presshub && ! apply_filters( 'presshub_ai_social_meta_all_posts', false ) ) {
            return;
        }

        $title       = $this->get_social_title( $post );
        $description = $this->get_social_description( $post );
        $image_url   = $this->get_social_image_url( $post );
        $url         = function_exists( 'get_permalink' ) ? get_permalink( $post->ID ) : '';
        $site_name   = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'PressHub';

        echo "\n<!-- PressHub AI Social & OpenGraph Meta Tags -->\n";

        // Standard meta description
        if ( ! empty( $description ) ) {
            echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
        }

        // OpenGraph tags
        echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        if ( ! empty( $url ) ) {
            echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
        }
        if ( ! empty( $description ) ) {
            echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
        }
        if ( ! empty( $image_url ) ) {
            echo '<meta property="og:image" content="' . esc_url( $image_url ) . '" />' . "\n";
            echo '<meta property="og:image:secure_url" content="' . esc_url( $image_url ) . '" />' . "\n";
        }

        // Twitter Cards (X) tags
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
        if ( ! empty( $description ) ) {
            echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
        }
        if ( ! empty( $image_url ) ) {
            echo '<meta name="twitter:image" content="' . esc_url( $image_url ) . '" />' . "\n";
        }
        echo "<!-- /PressHub AI Social Meta Tags -->\n\n";
    }

    /**
     * Resolve plain text title for social cards.
     *
     * @param \WP_Post $post Post object.
     * @return string
     */
    public function get_social_title( $post ): string {
        $raw_title = function_exists( 'get_the_title' ) ? get_the_title( $post ) : ( $post->post_title ?? '' );
        $clean = wp_strip_all_tags( html_entity_decode( $raw_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        return trim( $clean );
    }

    /**
     * Resolve description for social cards and search snippets (150-160 characters).
     *
     * @param \WP_Post $post Post object.
     * @return string
     */
    public function get_social_description( $post ): string {
        $is_podcast   = function_exists( 'get_post_meta' ) && ( 'podcast' === get_post_meta( $post->ID, '_presshub_briefing_type', true ) );
        $include_text = class_exists( 'PressHub_AI_Settings_Storage' ) && PressHub_AI_Settings_Storage::get_briefing_podcast_include_text();

        // 1. Post excerpt (ignore if contaminated with raw topic or speaker tags)
        $text = ! empty( $post->post_excerpt ) ? (string) $post->post_excerpt : '';
        if ( false !== strpos( $text, 'TOPIC' ) || false !== strpos( $text, 'SPEAKER_' ) ) {
            $text = '';
        }

        // For podcasts with include_text off: produce clean editorial audio description
        if ( $is_podcast && ! $include_text && empty( $text ) ) {
            $date = function_exists( 'get_post_meta' ) ? get_post_meta( $post->ID, '_presshub_briefing_date', true ) : '';
            $formatted_date = ! empty( $date )
                ? ( function_exists( 'date_i18n' ) ? date_i18n( 'd/m/Y', strtotime( $date ) ) : $date )
                : '';
            return ! empty( $formatted_date )
                ? sprintf( __( 'Ακούστε το ηχητικό podcast ενημέρωσης του PressHub (%s).', 'presshub-ai-editor' ), $formatted_date )
                : __( 'Ακούστε το ηχητικό podcast ενημέρωσης του PressHub.', 'presshub-ai-editor' );
        }

        // 2. Podcast script if post_excerpt is blank (only when include_text is enabled)
        if ( empty( $text ) && function_exists( 'get_post_meta' ) ) {
            if ( ! $is_podcast || $include_text ) {
                $script = get_post_meta( $post->ID, '_presshub_podcast_script', true );
                if ( ! empty( $script ) ) {
                    $text = (string) $script;
                }
            }
        }

        // 3. Post content fallback
        if ( empty( $text ) && ! empty( $post->post_content ) ) {
            $text = (string) $post->post_content;
        }

        if ( empty( $text ) ) {
            return '';
        }

        // Strip HTML, shortcodes, topic markers, audio blocks, and decode entities
        if ( function_exists( 'strip_shortcodes' ) ) {
            $text = strip_shortcodes( $text );
        }
        if ( class_exists( 'PressHub_AI_Audio_Synthesizer' ) ) {
            $synth = new PressHub_AI_Audio_Synthesizer();
            $text  = $synth->strip_topic_markers( $text );
        }
        $text  = preg_replace( '/\[\s*(?:SPEAKER_\d+|Presenter\s*\d+)\s*\]\s*:\s*/iu', '', $text );
        $clean = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        // Normalize whitespace
        $clean = preg_replace( '/\s+/', ' ', $clean );

        return trim( wp_trim_words( $clean, 28, '...' ) );
    }

    /**
     * Resolve social image URL.
     *
     * @param \WP_Post $post Post object.
     * @return string
     */
    public function get_social_image_url( $post ): string {
        // 1. Featured image (thumbnail)
        if ( function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post->ID ) ) {
            $thumb_id = get_post_thumbnail_id( $post->ID );
            if ( $thumb_id && function_exists( 'wp_get_attachment_image_src' ) ) {
                $src = wp_get_attachment_image_src( $thumb_id, 'full' );
                if ( ! empty( $src[0] ) ) {
                    return $src[0];
                }
            }
        }

        // 2. Pool fallback if post lacks a featured image
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            $edition   = get_post_meta( $post->ID, '_presshub_briefing_edition', true ) ?: 'morning';
            $image_ids = PressHub_AI_Settings_Storage::get_briefing_featured_image_ids( $edition );
            if ( ! empty( $image_ids ) && function_exists( 'wp_get_attachment_image_src' ) ) {
                $first_id = (int) reset( $image_ids );
                if ( $first_id > 0 ) {
                    $src = wp_get_attachment_image_src( $first_id, 'full' );
                    if ( ! empty( $src[0] ) ) {
                        return $src[0];
                    }
                }
            }
        }

        return '';
    }

    /**
     * Optimize document title parts to keep the overall title concise (<= 60 characters).
     *
     * @param array $parts Document title parts: 'title', 'page', 'tagline', 'site'.
     * @return array
     */
    public function optimize_document_title_parts( array $parts, $post = null ): array {
        if ( ! $post && function_exists( 'get_queried_object' ) ) {
            $post = get_queried_object();
        }
        if ( ! $post && ! empty( $GLOBALS['post'] ) ) {
            $post = $GLOBALS['post'];
        }
        if ( ! $this->is_presshub_post( $post ) ) {
            return $parts;
        }

        if ( ! is_object( $post ) || empty( $post->ID ) ) {
            return $parts;
        }

        $site  = ! empty( $parts['site'] ) ? $parts['site'] : ( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'PressHub' );
        $title = ! empty( $parts['title'] ) ? $parts['title'] : ( function_exists( 'get_the_title' ) ? get_the_title( $post ) : ( $post->post_title ?? '' ) );

        $site_len = function_exists( 'mb_strlen' ) ? mb_strlen( $site ) : strlen( $site );
        $title_len = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
        $total_len = $title_len + 3 + $site_len; // " - " separator

        if ( $total_len > 60 ) {
            $edition = get_post_meta( $post->ID, '_presshub_briefing_edition', true );
            $date    = get_post_meta( $post->ID, '_presshub_briefing_date', true );
            $type    = get_post_meta( $post->ID, '_presshub_briefing_type', true );

            if ( ! empty( $date ) && 'podcast' === $type ) {
                $formatted_date = function_exists( 'date_i18n' ) ? date_i18n( 'd/m/Y', strtotime( $date ) ) : date( 'd/m/Y', strtotime( $date ) );
                $prefix = ( 'noon' === $edition ) ? 'Μεσημβρινό Podcast' : 'Πρωινό Podcast';
                $parts['title'] = sprintf( '%s (%s)', $prefix, $formatted_date );
            } elseif ( ! empty( $date ) && 'text' === $type ) {
                $formatted_date = function_exists( 'date_i18n' ) ? date_i18n( 'd/m/Y', strtotime( $date ) ) : date( 'd/m/Y', strtotime( $date ) );
                $prefix = ( 'noon' === $edition ) ? 'Μεσημβρινή Ενημέρωση' : 'Πρωινή Ενημέρωση';
                $parts['title'] = sprintf( '%s (%s)', $prefix, $formatted_date );
            } else {
                $max_title_len = max( 20, 56 - $site_len );
                if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title ) ) > $max_title_len ) {
                    $parts['title'] = ( function_exists( 'mb_substr' ) ? mb_substr( $title, 0, $max_title_len - 1 ) : substr( $title, 0, $max_title_len - 1 ) ) . '…';
                }
            }

            // Ensure combined title + site does not exceed 60 characters
            $cur_site = ! empty( $parts['site'] ) ? $parts['site'] : $site;
            $cur_site_len = function_exists( 'mb_strlen' ) ? mb_strlen( $cur_site ) : strlen( $cur_site );
            $cur_title_len = function_exists( 'mb_strlen' ) ? mb_strlen( $parts['title'] ) : strlen( $parts['title'] );

            if ( $cur_title_len + 3 + $cur_site_len > 60 ) {
                // If site has a separator like " - " or " | ", trim off the extra tagline
                if ( ! empty( $parts['site'] ) && preg_match( '/^([^-\|]+)[-\|]/u', $parts['site'], $sm ) ) {
                    $parts['site'] = trim( $sm[1] );
                    $cur_site_len = function_exists( 'mb_strlen' ) ? mb_strlen( $parts['site'] ) : strlen( $parts['site'] );
                }
                if ( $cur_title_len + 3 + $cur_site_len > 60 ) {
                    $max_site = max( 8, 60 - 3 - $cur_title_len );
                    $parts['site'] = ( function_exists( 'mb_substr' ) ? mb_substr( $parts['site'], 0, $max_site - 1 ) : substr( $parts['site'], 0, $max_site - 1 ) ) . '…';
                }
            }
        }

        return $parts;
    }
}
