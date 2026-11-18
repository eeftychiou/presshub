<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-api-client.php';
require_once __DIR__ . '/class-settings-storage.php';
require_once __DIR__ . '/class-logger.php';

class PressHub_AI_QA_Reviewer {

    /**
     * Post meta keys used by the QA reviewer.
     */
    const META_SCORECARD     = '_presshub_ai_scorecard';
    const META_CONTENT_HASH  = '_presshub_ai_scorecard_hash';
    const META_NOTIFIED_HASH = '_presshub_ai_qa_notified_hash';

    /**
     * Evaluate raw content using the AI Scorecard.
     *
     * @param string $content Article text content.
     * @param string      $context  Evaluation context: 'post' (default) or 'briefing'.
     * @param int         $post_id  Post the review belongs to (generation record link).
     * @param string|null $template Prompt template to use instead of the context's
     *                              QA prompt (the publish gate fills its SEO placeholders first).
     * @return array [ 'passed' => bool, 'score' => int, 'feedback' => string, 'libel_risk' => string,
     *                 'legal_concerns' => string, 'error' => string|null, 'raw' => array (the decoded reply) ]
     */
    public static function evaluate_content( string $content, string $context = 'post', int $post_id = 0, ?string $template = null ): array {
        if ( '' === trim( $content ) ) {
            return [
                'passed'         => false,
                'score'          => 0,
                'feedback'       => __( 'Empty article content cannot be evaluated.', 'presshub-ai-editor' ),
                'libel_risk'     => 'Low',
                'legal_concerns' => 'None identified',
                'error'          => 'empty_content',
                'raw'            => [],
            ];
        }

        $min_score = PressHub_AI_Settings_Storage::get_qa_min_score();
        if ( null === $template ) {
            $template = ( 'briefing' === $context )
                ? PressHub_AI_Settings_Storage::get_qa_briefing_prompt()
                : PressHub_AI_Settings_Storage::get_qa_article_prompt();
        }

        $api       = new PressHub_AI_API_Client( 'qa' );
        $scorecard = $api->generate_scorecard( $content, $template, $post_id );

        if ( is_wp_error( $scorecard ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error(
                    'AI QA review failed to generate scorecard',
                    [ 'error' => $scorecard->get_error_message(), 'context' => $context ]
                );
            }
            return [
                'passed'         => false,
                'score'          => 0,
                'feedback'       => $scorecard->get_error_message(),
                'libel_risk'     => 'Low',
                'legal_concerns' => 'None identified',
                'error'          => $scorecard->get_error_code(),
                'raw'            => [],
            ];
        }

        $score          = isset( $scorecard['score'] ) ? (int) $scorecard['score'] : 0;
        $feedback       = isset( $scorecard['feedback'] ) ? (string) $scorecard['feedback'] : '';
        $raw_libel      = isset( $scorecard['libel_risk'] ) ? trim( (string) $scorecard['libel_risk'] ) : 'Low';
        $libel_risk     = in_array( ucfirst( strtolower( $raw_libel ) ), [ 'Low', 'Medium', 'High' ], true )
            ? ucfirst( strtolower( $raw_libel ) )
            : 'Low';
        $legal_concerns = ( isset( $scorecard['legal_concerns'] ) && '' !== trim( (string) $scorecard['legal_concerns'] ) )
            ? trim( (string) $scorecard['legal_concerns'] )
            : 'None identified';
        $passed         = ( $score >= $min_score );

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info(
                sprintf( 'AI QA evaluation completed (%s): score %d/100 (threshold: %d) -> %s', $context, $score, $min_score, $passed ? 'PASS' : 'FAIL' ),
                [
                    'context'        => $context,
                    'score'          => $score,
                    'threshold'      => $min_score,
                    'passed'         => $passed,
                    'feedback'       => $feedback,
                    'libel_risk'     => $libel_risk,
                    'legal_concerns' => $legal_concerns,
                ]
            );
        }

        return [
            'passed'         => $passed,
            'score'          => $score,
            'feedback'       => $feedback,
            'libel_risk'     => $libel_risk,
            'legal_concerns' => $legal_concerns,
            'error'          => null,
            'raw'            => $scorecard,
        ];
    }

    /**
     * Evaluate a WP_Post object. Uses content hashing to avoid redundant API calls
     * if the post content has not changed since the last review.
     *
     * @param WP_Post|object $post    WordPress post object.
     * @param string         $context Evaluation context: 'post' (default) or 'briefing'.
     * @return array Evaluation result with 'passed', 'score', 'feedback', 'libel_risk', 'legal_concerns'.
     */
    public static function evaluate_post( $post, string $context = 'post' ): array {
        if ( ! is_object( $post ) || empty( $post->ID ) ) {
            return [
                'passed'         => false,
                'score'          => 0,
                'feedback'       => __( 'Invalid post object.', 'presshub-ai-editor' ),
                'libel_risk'     => 'Low',
                'legal_concerns' => 'None identified',
                'error'          => 'invalid_post',
            ];
        }

        $post_id      = (int) $post->ID;
        $content      = isset( $post->post_content ) ? (string) $post->post_content : '';
        $content_hash = md5( trim( $content ) );
        $min_score    = PressHub_AI_Settings_Storage::get_qa_min_score();

        $cached_hash        = (string) get_post_meta( $post_id, self::META_CONTENT_HASH, true );
        $existing_scorecard = get_post_meta( $post_id, self::META_SCORECARD, true );

        // If content hasn't changed and we have a valid scorecard, reuse it
        if ( $cached_hash === $content_hash && is_array( $existing_scorecard ) && isset( $existing_scorecard['score'] ) ) {
            $score          = (int) $existing_scorecard['score'];
            $feedback       = (string) ( $existing_scorecard['feedback'] ?? '' );
            $raw_libel      = isset( $existing_scorecard['libel_risk'] ) ? trim( (string) $existing_scorecard['libel_risk'] ) : 'Low';
            $libel_risk     = in_array( ucfirst( strtolower( $raw_libel ) ), [ 'Low', 'Medium', 'High' ], true )
                ? ucfirst( strtolower( $raw_libel ) )
                : 'Low';
            $legal_concerns = ( isset( $existing_scorecard['legal_concerns'] ) && '' !== trim( (string) $existing_scorecard['legal_concerns'] ) )
                ? trim( (string) $existing_scorecard['legal_concerns'] )
                : 'None identified';
            return [
                'passed'         => ( $score >= $min_score ),
                'score'          => $score,
                'feedback'       => $feedback,
                'libel_risk'     => $libel_risk,
                'legal_concerns' => $legal_concerns,
                'error'          => null,
                'cached'         => true,
            ];
        }

        $result = self::evaluate_content( $content, $context );

        // Persist scorecard and content hash
        update_post_meta( $post_id, self::META_SCORECARD, [
            'score'          => $result['score'],
            'feedback'       => $result['feedback'],
            'libel_risk'     => $result['libel_risk'],
            'legal_concerns' => $result['legal_concerns'],
        ] );
        update_post_meta( $post_id, self::META_CONTENT_HASH, $content_hash );

        return $result;
    }

    /**
     * Dispatch an email notification to the editor when a post fails QA review.
     * Deduplicates so the same content revision doesn't trigger multiple emails.
     *
     * @param int    $post_id   WordPress post ID.
     * @param array  $scorecard Scorecard data containing 'score' and 'feedback'.
     * @param string $context   Context identifier ('post' or 'briefing').
     * @return bool True if notification sent or previously sent, false on failure.
     */
    public static function notify_editor_failure( int $post_id, array $scorecard, string $context = 'post' ): bool {
        if ( ! PressHub_AI_Settings_Storage::get_qa_notify_editor() ) {
            return false;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }

        // Deduplication via hash: the gate's input hash, else the content hash.
        $content_hash = (string) ( $scorecard['content_hash'] ?? '' );
        if ( '' === $content_hash ) {
            $content_hash = (string) get_post_meta( $post_id, self::META_CONTENT_HASH, true );
        }
        if ( '' === $content_hash ) {
            $content_hash = md5( trim( (string) $post->post_content ) );
        }
        // A gate alert also keys on the verdict, so a real result still goes
        // out after an earlier "review could not run" email for the same text.
        if ( isset( $scorecard['checks'] ) ) {
            $content_hash .= '|' . (string) ( $scorecard['verdict'] ?? '' );
        }
        $already_notified = (string) get_post_meta( $post_id, self::META_NOTIFIED_HASH, true );
        if ( $already_notified === $content_hash ) {
            return true; // Already notified for this exact content revision
        }

        // Recipient
        $to = PressHub_AI_Settings_Storage::get_qa_editor_email();
        if ( empty( $to ) || ! is_email( $to ) ) {
            $to = (string) get_option( 'admin_email' );
        }

        $author_name = 'Automated System';
        if ( ! empty( $post->post_author ) ) {
            $author = get_userdata( $post->post_author );
            if ( $author ) {
                $author_name = ! empty( $author->display_name ) ? $author->display_name : $author->user_login;
            }
        }

        $score          = isset( $scorecard['score'] ) ? (int) $scorecard['score'] : 0;
        $min_score      = PressHub_AI_Settings_Storage::get_qa_min_score();
        $feedback       = isset( $scorecard['feedback'] ) ? (string) $scorecard['feedback'] : __( 'No critique provided.', 'presshub-ai-editor' );
        $libel_risk     = isset( $scorecard['libel_risk'] ) ? trim( (string) $scorecard['libel_risk'] ) : '';
        $legal_concerns = isset( $scorecard['legal_concerns'] ) ? trim( (string) $scorecard['legal_concerns'] ) : '';
        $edit_url       = admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );
        $post_title     = ! empty( $post->post_title ) ? $post->post_title : sprintf( __( '(Post #%d)', 'presshub-ai-editor' ), $post_id );
        $site_name      = function_exists( 'wp_specialchars_decode' ) ? wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) : get_option( 'blogname' );

        $libel_section = '';
        if ( '' !== $libel_risk && 'None identified' !== $libel_risk ) {
            $libel_section = sprintf( "Libel Risk: %s\n", $libel_risk );
        }

        $legal_section = '';
        if ( '' !== $legal_concerns && 'None identified' !== $legal_concerns ) {
            $legal_section = sprintf( "\nLegal Concerns:\n%s\n", $legal_concerns );
        }

        if ( isset( $scorecard['checks'] ) && is_array( $scorecard['checks'] ) ) {
            list( $subject, $body ) = self::gate_email( $scorecard, $context, $site_name, $post_title, $author_name, $edit_url, $post_id, 'publish' === (string) ( $post->post_status ?? '' ) );
        } elseif ( 'briefing' === $context ) {
            $subject = sprintf( '[%s] Daily Briefing QA Review Failed (Score: %d/%d)', $site_name, $score, $min_score );
            $intro   = __( 'The automated Daily Briefing article failed the AI Editorial QA Review and has been held in Pending Review status.', 'presshub-ai-editor' );
        } else {
            $subject = sprintf( '[%s] Article QA Review Failed: %s', $site_name, $post_title );
            $intro   = sprintf( __( 'An article submitted by %s failed the AI Editorial QA Review and was blocked from immediate publication.', 'presshub-ai-editor' ), $author_name );
        }

        if ( ! isset( $body ) ) {
            $body = sprintf(
                "%s\n\n" .
                "--------------------------------------------------\n" .
                "Article: %s\n" .
                "Author: %s\n" .
                "Score: %d / 100 (Minimum required: %d)\n" .
                "%s" .
                "Status: Pending Review\n" .
                "--------------------------------------------------\n\n" .
                "AI Editorial Critique:\n%s\n" .
                "%s\n" .
                "Review and edit the post here:\n%s\n\n" .
                "--\nPressHub AI Editorial Co-Pilot",
                $intro,
                $post_title,
                $author_name,
                $score,
                $min_score,
                $libel_section,
                $feedback,
                $legal_section,
                $edit_url
            );
        }

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        $sent = function_exists( 'wp_mail' ) ? wp_mail( $to, $subject, $body, $headers ) : false;

        if ( $sent ) {
            update_post_meta( $post_id, self::META_NOTIFIED_HASH, $content_hash );
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info(
                    sprintf( 'Editor failure notification sent for post #%d to %s', $post_id, $to ),
                    [
                        'post_id'   => $post_id,
                        'recipient' => $to,
                        'score'     => $score,
                        'context'   => $context,
                    ]
                );
            }
        } else {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning(
                    sprintf( 'Failed to send editor failure notification for post #%d to %s', $post_id, $to ),
                    [
                        'post_id'   => $post_id,
                        'recipient' => $to,
                    ]
                );
            }
        }

        return (bool) $sent;
    }

    /**
     * Subject and body of a publish gate alert: one block per failed check.
     *
     * @return array{0:string,1:string}
     */
    private static function gate_email( array $gate, string $context, string $site_name, string $post_title, string $author_name, string $edit_url, int $post_id, bool $published = true ): array {
        $checks  = (array) $gate['checks'];
        $verdict = (string) ( $gate['verdict'] ?? 'hold' );
        $held    = in_array( $verdict, [ 'hold', 'error' ], true );
        $failed  = [];
        $blocks  = [];

        $ed = (array) ( $checks['editorial'] ?? [] );
        if ( 'error' === $verdict || 'error' === ( $ed['result'] ?? '' ) ) {
            $failed[] = __( 'review could not run', 'presshub-ai-editor' );
            $blocks[] = sprintf( "The QA review could not run (%s), so the post was held until someone reviews it.\n%s", (string) ( $ed['error'] ?? $gate['error'] ?? 'error' ), (string) ( $ed['feedback'] ?? '' ) );
        } elseif ( 'fail' === ( $ed['result'] ?? '' ) ) {
            $failed[] = sprintf( 'editorial %d/%d', (int) $ed['score'], (int) $ed['threshold'] );
            $blocks[] = sprintf( "EDITORIAL: %d / 100 (minimum %d)\n%s", (int) $ed['score'], (int) $ed['threshold'], (string) ( $ed['feedback'] ?? '' ) );
        }

        $lg = (array) ( $checks['legal'] ?? [] );
        if ( 'fail' === ( $lg['result'] ?? '' ) ) {
            $failed[] = sprintf( 'legal %s', (string) ( $lg['risk'] ?? '' ) );
            $lines    = [ sprintf( 'LEGAL: %s risk (fails at %s)', (string) ( $lg['risk'] ?? '' ), 'medium' === ( $lg['fail_risk'] ?? 'high' ) ? 'Medium' : 'High' ) ];
            if ( '' !== (string) ( $lg['concerns'] ?? '' ) ) {
                $lines[] = (string) $lg['concerns'];
            }
            foreach ( (array) ( $lg['issues'] ?? [] ) as $i => $issue ) {
                $lines[] = sprintf( "%d. [%s, %s] \"%s\"\n   Fix: %s", $i + 1, (string) $issue['severity'], str_replace( '_', ' ', (string) $issue['type'] ), (string) $issue['quote'], (string) $issue['fix'] );
            }
            $blocks[] = implode( "\n", $lines );
        }

        $seo = (array) ( $checks['seo'] ?? [] );
        if ( 'fail' === ( $seo['result'] ?? '' ) ) {
            $failed[] = sprintf( 'SEO %d/%d', (int) $seo['score'], (int) $seo['threshold'] );
            $lines    = [ sprintf( 'SEO: %d / 100 (minimum %d)', (int) $seo['score'], (int) $seo['threshold'] ) ];
            foreach ( (array) ( $seo['hard_fail'] ?? [] ) as $key ) {
                $lines[] = 'title' === $key ? '- The post has no headline.' : '- The post has no featured image.';
            }
            foreach ( (array) ( $seo['review']['checks'] ?? [] ) as $c ) {
                if ( 'fail' === ( $c['status'] ?? '' ) && ! in_array( $c['key'] ?? '', (array) ( $seo['hard_fail'] ?? [] ), true ) ) {
                    $lines[] = sprintf( '- %s: %s', (string) $c['label'], (string) $c['detail'] );
                }
            }
            foreach ( (array) ( $seo['review']['suggestions'] ?? [] ) as $tip ) {
                $lines[] = '- ' . (string) $tip;
            }
            $blocks[] = implode( "\n", $lines );
        }

        $whats = [
            'briefing' => __( 'Daily Briefing', 'presshub-ai-editor' ),
            'podcast'  => __( 'Podcast', 'presshub-ai-editor' ),
            'trending' => __( 'Trending roundup', 'presshub-ai-editor' ),
        ];
        $what = $whats[ $context ] ?? __( 'Article', 'presshub-ai-editor' );
        $subject = sprintf(
            '[%s] %s %s: %s (%s)',
            $site_name,
            $what,
            $held ? __( 'held by the QA gate', 'presshub-ai-editor' ) : ( $published ? __( 'published with QA warnings', 'presshub-ai-editor' ) : __( 'has QA warnings', 'presshub-ai-editor' ) ),
            $post_title,
            implode( ', ', $failed )
        );
        $status = $held
            ? __( 'Held in Pending Review', 'presshub-ai-editor' )
            : ( $published
                ? __( 'Published; please review the warnings below', 'presshub-ai-editor' )
                : __( 'Not published yet; please review the warnings below before publishing', 'presshub-ai-editor' ) );
        $reviews_url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=presshub-ai-qa-reviews&post_id=' . $post_id ) : '';
        $override    = $held && function_exists( 'admin_url' )
            ? "\n\n" . __( 'Publish anyway (editors and admins, with a reason):', 'presshub-ai-editor' ) . "\n" . admin_url( 'admin.php?page=presshub-ai-qa-reviews&override=' . $post_id )
            : '';

        $body = sprintf(
            "%s\n\n--------------------------------------------------\nArticle: %s\nAuthor: %s\nStatus: %s\n--------------------------------------------------\n\n%s\n\nReview and edit the post here:\n%s\n\nAll QA reviews of this post:\n%s%s\n\n--\nPressHub QA gate",
            $held
                ? __( 'This post did not pass the QA gate and was not published.', 'presshub-ai-editor' )
                : ( $published
                    ? __( 'This post was published, but some QA checks failed.', 'presshub-ai-editor' )
                    : __( 'Some QA checks failed on this post. It can still be published.', 'presshub-ai-editor' ) ),
            $post_title,
            $author_name,
            $status,
            implode( "\n\n", $blocks ),
            $edit_url,
            $reviews_url,
            $override
        );
        return [ $subject, $body ];
    }
}
