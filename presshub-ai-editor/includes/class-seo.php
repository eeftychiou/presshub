<?php
/**
 * SEO, tagging and media-metadata helpers.
 *
 * - Picks the right source text for featured-image analysis (post text or the
 *   podcast dialogue script).
 * - Writes descriptive Title / Alt Text / Caption / Description onto AI-generated
 *   featured images so they can be found and reused in the Media Library.
 * - Generates tags, meta description and focus keyphrase for automated posts
 *   (Daily Briefing text, podcasts) and co-author drafts.
 * - Produces a separate, advisory SEO score for the AI Editorial Review from
 *   deterministic on-page checks combined with an AI assessment.
 *
 * Every AI call degrades gracefully: on a provider or JSON failure the caller gets
 * deterministic fallbacks (or nothing is changed), never a fatal.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_SEO {

    /** Post meta: generated SEO metadata (focus keyphrase, meta description). */
    const META_SEO = '_presshub_ai_seo';

    /** Post meta: last SEO review result shown in the editorial metabox. */
    const META_SEO_REVIEW = '_presshub_ai_seo_review';

    /** Characters of article text sent to the metadata / review prompts. */
    const CONTENT_EXCERPT_CHARS = 6000;

    // ------------------------------------------------------------------
    // Source text
    // ------------------------------------------------------------------

    /**
     * Strip HTML, podcast topic markers and speaker labels so only readable text remains.
     */
    public static function clean_text( string $text ): string {
        if ( '' === trim( $text ) ) {
            return '';
        }
        if ( class_exists( 'PressHub_AI_Audio_Synthesizer' ) ) {
            $synth = new PressHub_AI_Audio_Synthesizer();
            $text  = $synth->strip_topic_markers( $text );
        }
        $text = preg_replace( '/\[\s*(?:SPEAKER[_\s]*\d+|Presenter\s*\d+)\s*\]\s*:?\s*/iu', '', $text );
        $text = preg_replace( '/^\s*(?:SPEAKER[_\s]*\d+|Presenter\s*\d+)\s*:\s*/imu', '', $text );
        $text = preg_replace( '/<!--.*?-->/s', ' ', (string) $text );
        $text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/[ \t]+/u', ' ', $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );
        return trim( (string) $text );
    }

    /**
     * Resolve the text the featured-image prompt should be drafted from.
     *
     * Podcast posts usually hold only an audio player, so their dialogue script
     * (`_presshub_podcast_script`) is used. Any other post uses the supplied editor
     * content, or the saved post content when nothing was supplied.
     *
     * @return array{content:string,title:string,source:string}
     */
    public static function resolve_image_source( int $post_id, string $content = '', string $title = '' ): array {
        $post   = ( $post_id > 0 && function_exists( 'get_post' ) ) ? get_post( $post_id ) : null;
        $script = ( $post_id > 0 && function_exists( 'get_post_meta' ) ) ? (string) get_post_meta( $post_id, '_presshub_podcast_script', true ) : '';
        $type   = ( $post_id > 0 && function_exists( 'get_post_meta' ) ) ? (string) get_post_meta( $post_id, '_presshub_briefing_type', true ) : '';

        if ( '' === trim( $title ) && $post && isset( $post->post_title ) ) {
            $title = (string) $post->post_title;
        }

        $text = self::clean_text( $content );
        if ( '' === $text && $post && isset( $post->post_content ) ) {
            $text = self::clean_text( (string) $post->post_content );
        }

        $script_text = self::clean_text( $script );
        if ( '' !== $script_text && ( 'podcast' === $type || mb_strlen( $text ) < 200 ) ) {
            return [ 'content' => $script_text, 'title' => $title, 'source' => 'podcast_script' ];
        }

        return [ 'content' => $text, 'title' => $title, 'source' => 'post_content' ];
    }

    // ------------------------------------------------------------------
    // Image metadata
    // ------------------------------------------------------------------

    /**
     * Build Media Library metadata for a generated image.
     *
     * @return array{title:string,alt_text:string,caption:string,description:string,filename:string,source:string}
     */
    public static function generate_image_metadata( string $visual_prompt, string $title, string $content, int $post_id = 0 ): array {
        $fallback = self::fallback_image_metadata( $visual_prompt, $title );

        if ( class_exists( 'PressHub_AI_Settings_Storage' ) && ! PressHub_AI_Settings_Storage::get_image_metadata_enabled() ) {
            return $fallback;
        }

        $template = self::load_prompt( 'seo/image_metadata.txt' );
        if ( '' === $template ) {
            return $fallback;
        }

        $user_prompt = strtr( $template, [
            '{title}'         => trim( $title ),
            '{content}'       => self::excerpt( $content, 2000 ),
            '{visual_prompt}' => trim( $visual_prompt ),
        ] );

        $data = self::call_json( 'image_prompt', 'generate_image_metadata', $user_prompt, $post_id );
        if ( ! is_array( $data ) ) {
            return $fallback;
        }

        $meta = [
            'title'       => self::clean_line( (string) ( $data['title'] ?? '' ), 120 ),
            'alt_text'    => self::clean_line( (string) ( $data['alt_text'] ?? '' ), 125 ),
            'caption'     => self::clean_line( (string) ( $data['caption'] ?? '' ), 250 ),
            'description' => self::clean_line( (string) ( $data['description'] ?? '' ), 600 ),
            'filename'    => self::slugify( (string) ( $data['filename'] ?? '' ) ),
            'source'      => 'ai',
        ];
        foreach ( [ 'title', 'alt_text', 'caption', 'description', 'filename' ] as $key ) {
            if ( '' === $meta[ $key ] ) {
                $meta[ $key ] = $fallback[ $key ];
            }
        }
        return $meta;
    }

    /**
     * Deterministic metadata used when the AI call is disabled or fails.
     */
    public static function fallback_image_metadata( string $visual_prompt, string $title ): array {
        $first_sentence = preg_split( '/(?<=[.!?])\s+/u', trim( $visual_prompt ) );
        $first_sentence = is_array( $first_sentence ) && isset( $first_sentence[0] ) ? $first_sentence[0] : $visual_prompt;
        $prompt_words   = self::words( $visual_prompt );
        $clean_title    = self::clean_line( $title, 120 );
        if ( '' === $clean_title ) {
            $clean_title = self::clean_line( implode( ' ', array_slice( $prompt_words, 0, 10 ) ), 120 );
        }
        return [
            'title'       => $clean_title,
            'alt_text'    => self::clean_line( $first_sentence, 125 ),
            'caption'     => $clean_title,
            'description' => self::clean_line( $visual_prompt, 600 ),
            'filename'    => self::slugify( implode( ' ', array_slice( $prompt_words, 0, 8 ) ) ),
            'source'      => 'fallback',
        ];
    }

    /**
     * Write metadata onto an attachment: title, caption (excerpt), description (content), alt text.
     */
    public static function apply_image_metadata( int $attachment_id, array $meta ): void {
        if ( $attachment_id <= 0 ) {
            return;
        }
        $update = [ 'ID' => $attachment_id ];
        if ( ! empty( $meta['title'] ) ) {
            $update['post_title'] = (string) $meta['title'];
        }
        if ( ! empty( $meta['caption'] ) ) {
            $update['post_excerpt'] = (string) $meta['caption'];
        }
        if ( ! empty( $meta['description'] ) ) {
            $update['post_content'] = (string) $meta['description'];
        }
        if ( count( $update ) > 1 && function_exists( 'wp_update_post' ) ) {
            wp_update_post( $update );
        }
        if ( ! empty( $meta['alt_text'] ) && function_exists( 'update_post_meta' ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', (string) $meta['alt_text'] );
        }
    }

    /**
     * Article title + text for a post's featured image metadata.
     *
     * @return array{title:string,content:string}
     */
    public static function article_context_for_post( int $post_id ): array {
        if ( $post_id <= 0 ) {
            return [ 'title' => '', 'content' => '' ];
        }
        $src = self::resolve_image_source( $post_id );
        return [ 'title' => $src['title'], 'content' => $src['content'] ];
    }

    // ------------------------------------------------------------------
    // Post SEO (tags, meta description, focus keyphrase)
    // ------------------------------------------------------------------

    /**
     * Ask the AI for tags, a meta description and a focus keyphrase.
     *
     * @return array{tags:string[],meta_description:string,focus_keyphrase:string}|WP_Error
     */
    public static function generate_post_seo( string $title, string $content, int $post_id = 0, string $module = 'coauthor' ) {
        $template = self::load_prompt( 'seo/post_seo_metadata.txt' );
        if ( '' === $template ) {
            return new WP_Error( 'missing_template', __( 'SEO metadata prompt template is missing.', 'presshub-ai-editor' ) );
        }

        $max_tags    = self::max_tags();
        $user_prompt = strtr( $template, [
            '{title}'         => trim( $title ),
            '{content}'       => self::excerpt( self::clean_text( $content ), self::CONTENT_EXCERPT_CHARS ),
            '{max_tags}'      => (string) $max_tags,
            '{existing_tags}' => self::existing_tags_list(),
        ] );

        $data = self::call_json( $module, 'generate_post_seo', $user_prompt, $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'seo_json_error', __( 'Failed to parse SEO metadata JSON.', 'presshub-ai-editor' ) );
        }

        return [
            'tags'             => self::normalize_tags( (array) ( $data['tags'] ?? [] ), $max_tags ),
            'meta_description' => self::clean_line( (string) ( $data['meta_description'] ?? '' ), 160 ),
            'focus_keyphrase'  => self::clean_line( (string) ( $data['focus_keyphrase'] ?? '' ), 60 ),
        ];
    }

    /**
     * Save generated SEO metadata on a post: append tags, set the excerpt (meta
     * description), store the keyphrase, and mirror both into Yoast / Rank Math when active.
     */
    public static function apply_post_seo( int $post_id, array $seo, bool $set_excerpt = true, bool $assign_tags = true ): void {
        if ( $post_id <= 0 ) {
            return;
        }

        $tags = self::normalize_tags( (array) ( $seo['tags'] ?? [] ), self::max_tags() );
        if ( $assign_tags && ! empty( $tags ) && function_exists( 'wp_set_post_tags' ) ) {
            wp_set_post_tags( $post_id, $tags, true );
        }

        $description = (string) ( $seo['meta_description'] ?? '' );
        $keyphrase   = (string) ( $seo['focus_keyphrase'] ?? '' );

        if ( $set_excerpt && '' !== $description && function_exists( 'wp_update_post' ) ) {
            wp_update_post( [
                'ID'           => $post_id,
                'post_excerpt' => $description,
            ] );
        }

        if ( function_exists( 'update_post_meta' ) ) {
            update_post_meta( $post_id, self::META_SEO, [
                'tags'             => $tags,
                'meta_description' => $description,
                'focus_keyphrase'  => $keyphrase,
                'generated_at'     => gmdate( 'c' ),
            ] );

            if ( defined( 'WPSEO_VERSION' ) ) {
                if ( '' !== $description ) {
                    update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
                }
                if ( '' !== $keyphrase ) {
                    update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyphrase );
                }
            }
            if ( defined( 'RANK_MATH_VERSION' ) ) {
                if ( '' !== $description ) {
                    update_post_meta( $post_id, 'rank_math_description', $description );
                }
                if ( '' !== $keyphrase ) {
                    update_post_meta( $post_id, 'rank_math_focus_keyword', $keyphrase );
                }
            }
        }
    }

    /**
     * Generate and apply SEO metadata for an automatically created post.
     * Gated by the "Automatic Tags & SEO" setting. Never throws.
     *
     * @return bool True when metadata was applied.
     */
    public static function auto_optimize_post( int $post_id, string $title, string $content, string $context = 'post' ): bool {
        if ( $post_id <= 0 ) {
            return false;
        }
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) && ! PressHub_AI_Settings_Storage::get_seo_auto_enabled() ) {
            return false;
        }
        try {
            $modules = [
                'briefing_text' => 'briefing_text',
                'podcast'       => 'briefing_podcast',
            ];
            $seo = self::generate_post_seo( $title, $content, $post_id, $modules[ $context ] ?? 'coauthor' );
            if ( is_wp_error( $seo ) ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( 'Automatic SEO metadata generation failed', [
                        'post_id' => $post_id,
                        'context' => $context,
                        'error'   => $seo->get_error_message(),
                    ] );
                }
                return false;
            }
            self::apply_post_seo( $post_id, $seo, true );
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info( 'Automatic SEO metadata applied', [
                    'post_id'   => $post_id,
                    'context'   => $context,
                    'tags'      => $seo['tags'],
                    'keyphrase' => $seo['focus_keyphrase'],
                ] );
            }
            return true;
        } catch ( Throwable $t ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( 'Automatic SEO metadata threw: ' . $t->getMessage(), [ 'post_id' => $post_id, 'context' => $context ] );
            }
            return false;
        }
    }

    // ------------------------------------------------------------------
    // SEO review (advisory score for the AI Editorial Review)
    // ------------------------------------------------------------------

    /**
     * Run deterministic on-page checks.
     *
     * @param array $input title, content (HTML), excerpt, tags (string[]), categories (string[]),
     *                     featured_image_id (int).
     * @return array{score:int,checks:array<int,array{key:string,label:string,status:string,detail:string}>}
     */
    public static function run_checks( array $input ): array {
        $title      = trim( (string) ( $input['title'] ?? '' ) );
        $html       = (string) ( $input['content'] ?? '' );
        $excerpt    = trim( (string) ( $input['excerpt'] ?? '' ) );
        $tags       = array_values( array_filter( array_map( 'strval', (array) ( $input['tags'] ?? [] ) ) ) );
        $categories = array_values( array_filter( array_map( 'strval', (array) ( $input['categories'] ?? [] ) ) ) );
        $thumb_id   = (int) ( $input['featured_image_id'] ?? 0 );

        $text       = self::clean_text( $html );
        $word_count = count( self::words( $text ) );
        $checks     = [];

        // Headline length.
        $len = mb_strlen( $title );
        $checks[] = self::check(
            'title',
            __( 'Headline length', 'presshub-ai-editor' ),
            0 === $len ? 'fail' : ( ( $len >= 30 && $len <= 70 ) ? 'pass' : 'warn' ),
            0 === $len
                ? __( 'The post has no headline.', 'presshub-ai-editor' )
                /* translators: %d: number of characters. */
                : sprintf( __( '%d characters (aim for 30 - 70).', 'presshub-ai-editor' ), $len ),
            3
        );

        // Meta description / excerpt.
        $elen = mb_strlen( $excerpt );
        $checks[] = self::check(
            'excerpt',
            __( 'Meta description (excerpt)', 'presshub-ai-editor' ),
            0 === $elen ? 'fail' : ( ( $elen >= 110 && $elen <= 160 ) ? 'pass' : 'warn' ),
            0 === $elen
                ? __( 'No excerpt set; search engines will pick an arbitrary snippet.', 'presshub-ai-editor' )
                /* translators: %d: number of characters. */
                : sprintf( __( '%d characters (aim for 120 - 155).', 'presshub-ai-editor' ), $elen ),
            2
        );

        // Length.
        $checks[] = self::check(
            'length',
            __( 'Article length', 'presshub-ai-editor' ),
            $word_count >= 300 ? 'pass' : ( $word_count >= 150 ? 'warn' : 'fail' ),
            /* translators: %d: number of words. */
            sprintf( __( '%d words (300+ recommended).', 'presshub-ai-editor' ), $word_count ),
            2
        );

        // Subheadings for longer pieces.
        if ( $word_count >= 400 ) {
            $has_sub = (bool) preg_match( '/<h[2-4][\s>]/i', $html );
            $checks[] = self::check(
                'headings',
                __( 'Subheadings', 'presshub-ai-editor' ),
                $has_sub ? 'pass' : 'warn',
                $has_sub ? __( 'Uses subheadings.', 'presshub-ai-editor' ) : __( 'Long article without H2/H3 subheadings.', 'presshub-ai-editor' ),
                1
            );
        }

        // Tags.
        $tcount = count( $tags );
        $checks[] = self::check(
            'tags',
            __( 'Tags', 'presshub-ai-editor' ),
            0 === $tcount ? 'fail' : ( ( $tcount >= 3 && $tcount <= 10 ) ? 'pass' : 'warn' ),
            /* translators: %d: number of tags. */
            sprintf( __( '%d tags (aim for 3 - 10 specific tags).', 'presshub-ai-editor' ), $tcount ),
            2
        );

        // Category.
        $default_cat_name = '';
        if ( function_exists( 'get_option' ) && function_exists( 'get_cat_name' ) ) {
            $default_cat_name = (string) get_cat_name( (int) get_option( 'default_category', 1 ) );
        }
        $real_cats = array_filter( $categories, function ( $c ) use ( $default_cat_name ) {
            $lc = mb_strtolower( trim( $c ) );
            return '' !== $lc && 'uncategorized' !== $lc && 'χωρίς κατηγορία' !== $lc && ( '' === $default_cat_name || mb_strtolower( $default_cat_name ) !== $lc );
        } );
        $checks[] = self::check(
            'category',
            __( 'Category', 'presshub-ai-editor' ),
            ! empty( $real_cats ) ? 'pass' : 'fail',
            ! empty( $real_cats ) ? implode( ', ', $real_cats ) : __( 'Only the default category (or none) is assigned.', 'presshub-ai-editor' ),
            1
        );

        // Featured image + its alt text.
        $thumb_alt = ( $thumb_id > 0 && function_exists( 'get_post_meta' ) ) ? trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) : '';
        $checks[] = self::check(
            'featured_image',
            __( 'Featured image', 'presshub-ai-editor' ),
            $thumb_id > 0 ? 'pass' : 'fail',
            $thumb_id > 0 ? __( 'Set.', 'presshub-ai-editor' ) : __( 'No featured image; social shares will look bare.', 'presshub-ai-editor' ),
            2
        );
        if ( $thumb_id > 0 ) {
            $checks[] = self::check(
                'featured_image_alt',
                __( 'Featured image alt text', 'presshub-ai-editor' ),
                '' !== $thumb_alt ? 'pass' : 'fail',
                '' !== $thumb_alt ? $thumb_alt : __( 'Missing alternative text.', 'presshub-ai-editor' ),
                1
            );
        }

        // Inline images alt text.
        if ( preg_match_all( '/<img\b[^>]*>/i', $html, $imgs ) && ! empty( $imgs[0] ) ) {
            $missing = 0;
            foreach ( $imgs[0] as $img ) {
                // Quoted alt with visible text, or an unquoted HTML5 value (alt=logo).
                if ( ! preg_match( '/\balt\s*=\s*(?:(["\'])\s*(?:(?!\1)\S)(?:(?!\1).)*\1|[^\s"\'=<>`]+)/is', $img ) ) {
                    $missing++;
                }
            }
            $total = count( $imgs[0] );
            $checks[] = self::check(
                'inline_alt',
                __( 'Image alt text in article', 'presshub-ai-editor' ),
                0 === $missing ? 'pass' : 'fail',
                /* translators: 1: images missing alt, 2: total images. */
                sprintf( __( '%1$d of %2$d images lack alt text.', 'presshub-ai-editor' ), $missing, $total ),
                1
            );
        }

        // Links.
        $home_host = function_exists( 'home_url' ) ? self::url_host( (string) home_url() ) : '';
        $internal  = 0;
        $external  = 0;
        if ( preg_match_all( '/<a\b[^>]*href\s*=\s*(["\'])([^"\']+)\1/i', $html, $links ) ) {
            foreach ( $links[2] as $href ) {
                $href = trim( (string) $href );
                if ( '' === $href || '#' === $href[0] || preg_match( '/^(?:mailto|tel|javascript|sms|data):/i', $href ) ) {
                    continue;
                }
                $host = self::url_host( $href );
                if ( '' === $host || ( '' !== $home_host && $host === $home_host ) ) {
                    $internal++;
                } else {
                    $external++;
                }
            }
        }
        $checks[] = self::check(
            'internal_links',
            __( 'Internal links', 'presshub-ai-editor' ),
            $internal > 0 ? 'pass' : 'warn',
            /* translators: %d: number of links. */
            sprintf( __( '%d links to other site articles.', 'presshub-ai-editor' ), $internal ),
            1
        );
        $checks[] = self::check(
            'external_links',
            __( 'Source links', 'presshub-ai-editor' ),
            $external > 0 ? 'pass' : 'warn',
            /* translators: %d: number of links. */
            sprintf( __( '%d links to external sources.', 'presshub-ai-editor' ), $external ),
            1
        );

        $total_weight = 0;
        $earned       = 0.0;
        foreach ( $checks as $c ) {
            $total_weight += $c['weight'];
            $earned       += $c['weight'] * ( 'pass' === $c['status'] ? 1.0 : ( 'warn' === $c['status'] ? 0.5 : 0.0 ) );
        }
        $score = $total_weight > 0 ? (int) round( 100 * $earned / $total_weight ) : 0;

        return [ 'score' => $score, 'checks' => $checks, 'word_count' => $word_count ];
    }

    /**
     * Full SEO review: deterministic checks + AI assessment.
     *
     * @param array $input See run_checks(); post_id optional.
     * @return array seo_score, checks_score, ai_score, focus_keyphrase, summary, suggestions,
     *               suggested_tags, suggested_meta_description, checks, error.
     */
    public static function review( array $input ): array {
        $checks = self::run_checks( $input );
        $result = [
            'seo_score'                  => $checks['score'],
            'checks_score'               => $checks['score'],
            'ai_score'                   => null,
            'focus_keyphrase'            => '',
            'summary'                    => '',
            'suggestions'                => [],
            'suggested_tags'             => [],
            'suggested_meta_description' => '',
            'checks'                     => $checks['checks'],
            'error'                      => null,
            'reviewed_at'                => gmdate( 'c' ),
        ];

        $template = self::load_prompt( 'seo/seo_review.txt' );
        if ( '' === $template ) {
            $result['error'] = 'missing_template';
            return $result;
        }

        $facts = [];
        foreach ( $checks['checks'] as $c ) {
            $facts[] = sprintf( '- %s: %s (%s)', $c['label'], strtoupper( $c['status'] ), $c['detail'] );
        }
        if ( ! empty( $input['tags'] ) ) {
            $facts[] = '- Current tags: ' . implode( ', ', array_map( 'strval', (array) $input['tags'] ) );
        }
        if ( ! empty( $input['excerpt'] ) ) {
            $facts[] = '- Current excerpt: ' . trim( (string) $input['excerpt'] );
        }

        $user_prompt = strtr( $template, [
            '{title}'         => trim( (string) ( $input['title'] ?? '' ) ),
            '{content}'       => self::excerpt( self::clean_text( (string) ( $input['content'] ?? '' ) ), self::CONTENT_EXCERPT_CHARS ),
            '{seo_facts}'     => implode( "\n", $facts ),
            '{existing_tags}' => self::existing_tags_list(),
        ] );

        $data = self::call_json( 'coauthor', 'seo_review', $user_prompt, (int) ( $input['post_id'] ?? 0 ) );
        if ( ! is_array( $data ) ) {
            $result['error'] = is_wp_error( $data ) ? $data->get_error_code() : 'seo_json_error';
            return $result;
        }

        $ai_score = isset( $data['seo_score'] ) ? max( 0, min( 100, (int) $data['seo_score'] ) ) : null;
        $suggestions = [];
        foreach ( (array) ( $data['suggestions'] ?? [] ) as $s ) {
            $line = self::clean_line( (string) $s, 300 );
            if ( '' !== $line ) {
                $suggestions[] = $line;
            }
        }

        $result['ai_score']                   = $ai_score;
        $result['seo_score']                  = null === $ai_score ? $checks['score'] : (int) round( ( $checks['score'] + $ai_score ) / 2 );
        $result['focus_keyphrase']            = self::clean_line( (string) ( $data['focus_keyphrase'] ?? '' ), 60 );
        $result['summary']                    = self::clean_line( (string) ( $data['summary'] ?? '' ), 600 );
        $result['suggestions']                = array_slice( $suggestions, 0, 6 );
        $result['suggested_tags']             = self::normalize_tags( (array) ( $data['suggested_tags'] ?? [] ), self::max_tags() );
        $result['suggested_meta_description'] = self::clean_line( (string) ( $data['suggested_meta_description'] ?? '' ), 160 );

        return $result;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Normalize, de-duplicate and cap tag names.
     *
     * @param array $tags Raw tag strings.
     * @return string[]
     */
    public static function normalize_tags( array $tags, int $max ): array {
        $out  = [];
        $seen = [];
        foreach ( $tags as $tag ) {
            if ( ! is_scalar( $tag ) ) {
                continue;
            }
            $t = trim( (string) $tag );
            $t = ltrim( $t, '#' );
            $t = preg_replace( '/\s+/u', ' ', $t );
            $t = trim( $t, " \t\n\r\0\x0B.,;:!?\"'«»“”()[]" );
            if ( '' === $t || mb_strlen( $t ) > 40 || count( explode( ' ', $t ) ) > 4 ) {
                continue;
            }
            $key = mb_strtolower( $t );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[]        = $t;
            if ( count( $out ) >= $max ) {
                break;
            }
        }
        return $out;
    }

    /**
     * English, lowercase, hyphenated slug (ASCII only), max 7 words / 60 chars.
     */
    public static function slugify( string $text ): string {
        $text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
        $text = strtolower( (string) $text );
        $text = preg_replace( '/[^a-z0-9]+/', '-', $text );
        $parts = array_filter( explode( '-', (string) $text ), function ( $p ) {
            return '' !== $p && ! in_array( $p, [ 'a', 'an', 'the', 'of', 'and', 'with', 'in', 'on', 'at', 'to' ], true );
        } );
        $slug = implode( '-', array_slice( array_values( $parts ), 0, 7 ) );
        return rtrim( substr( $slug, 0, 60 ), '-' );
    }

    /**
     * Attachment filename from the metadata slug.
     */
    public static function image_filename( array $meta, string $ext ): string {
        $slug = self::slugify( (string) ( $meta['filename'] ?? '' ) );
        $slug = '' !== $slug ? $slug : 'featured-image';
        $rand = function_exists( 'wp_generate_password' ) ? strtolower( wp_generate_password( 4, false, false ) ) : substr( md5( uniqid( '', true ) ), 0, 4 );
        return $slug . '-' . gmdate( 'Ymd' ) . '-' . $rand . '.' . $ext;
    }

    private static function check( string $key, string $label, string $status, string $detail, int $weight ): array {
        return [
            'key'    => $key,
            'label'  => $label,
            'status' => $status,
            'detail' => $detail,
            'weight' => $weight,
        ];
    }

    /**
     * Whitespace-separated words; [] for empty or invalid UTF-8 input.
     *
     * @return string[]
     */
    private static function words( string $text ): array {
        $parts = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
        if ( false === $parts ) {
            $parts = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
        }
        return is_array( $parts ) ? $parts : [];
    }

    /**
     * Lower-cased host of a URL without "www." ('' for relative URLs).
     */
    private static function url_host( string $url ): string {
        $host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST );
        return is_string( $host ) ? strtolower( (string) preg_replace( '/^www\./', '', $host ) ) : '';
    }

    private static function max_tags(): int {
        return class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_seo_max_tags() : 8;
    }

    private static function clean_line( string $text, int $max ): string {
        $text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text );
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        $text = trim( $text, "\"'`" );
        if ( mb_strlen( $text ) > $max ) {
            $cut  = mb_substr( $text, 0, $max );
            $last = mb_strrpos( $cut, ' ' );
            $text = ( false !== $last && $last > $max * 0.6 ) ? mb_substr( $cut, 0, $last ) : $cut;
            $text = rtrim( $text, ' ,;:' );
        }
        return $text;
    }

    private static function excerpt( string $text, int $max ): string {
        $text = trim( $text );
        return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '...' : $text;
    }

    /**
     * Comma-separated list of the site's most used tags (helps keep tags consistent).
     */
    private static function existing_tags_list(): string {
        if ( ! function_exists( 'get_tags' ) ) {
            return '(none)';
        }
        $terms = get_tags( [
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => 80,
            'hide_empty' => true,
        ] );
        if ( ! is_array( $terms ) || empty( $terms ) ) {
            return '(none)';
        }
        $names = [];
        foreach ( $terms as $t ) {
            if ( is_object( $t ) && isset( $t->name ) ) {
                $names[] = (string) $t->name;
            }
        }
        return empty( $names ) ? '(none)' : implode( ', ', $names );
    }

    private static function load_prompt( string $relative ): string {
        if ( ! class_exists( 'PressHub_AI_Prompt_Loader' ) ) {
            return '';
        }
        try {
            return (string) PressHub_AI_Prompt_Loader::load( $relative );
        } catch ( Throwable $t ) {
            return '';
        }
    }

    /**
     * Call the module's provider in JSON mode and decode the object.
     *
     * @return array|WP_Error|null Decoded array, provider error, or null on bad JSON.
     */
    private static function call_json( string $module, string $action, string $user_prompt, int $post_id = 0 ) {
        if ( ! class_exists( 'PressHub_AI_API_Client' ) ) {
            return new WP_Error( 'no_client', 'API client unavailable' );
        }
        $sys_prompt = 'You are a meticulous news SEO and media librarian. You reply with a single valid JSON object and nothing else.';
        try {
            $client = new PressHub_AI_API_Client( $module );
            $raw    = $client->call_seo_json( $sys_prompt, $user_prompt, $action, $post_id );
        } catch ( Throwable $t ) {
            return new WP_Error( 'seo_call_failed', $t->getMessage() );
        }
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }
        $data = self::decode_json_object( (string) $raw );
        if ( null === $data && class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::warning( 'SEO helper received non-JSON response', [ 'action' => $action, 'post_id' => $post_id ] );
        }
        return $data;
    }

    /**
     * Decode the first JSON object in a model reply (tolerates code fences / prose).
     */
    public static function decode_json_object( string $raw ): ?array {
        $raw = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $raw ) ) );
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }
        $start = strpos( $raw, '{' );
        $end   = strrpos( $raw, '}' );
        if ( false !== $start && false !== $end && $end > $start ) {
            $decoded = json_decode( substr( $raw, $start, $end - $start + 1 ), true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }
        return null;
    }
}
