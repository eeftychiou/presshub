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
     * @param string $context Evaluation context: 'post' (default) or 'briefing'.
     * @return array [ 'passed' => bool, 'score' => int, 'feedback' => string, 'error' => string|null ]
     */
    public static function evaluate_content( string $content, string $context = 'post' ): array {
        if ( '' === trim( $content ) ) {
            return [
                'passed'   => false,
                'score'    => 0,
                'feedback' => __( 'Empty article content cannot be evaluated.', 'presshub-ai-editor' ),
                'error'    => 'empty_content',
            ];
        }

        $min_score = PressHub_AI_Settings_Storage::get_qa_min_score();
        $template  = ( 'briefing' === $context )
            ? PressHub_AI_Settings_Storage::get_qa_briefing_prompt()
            : PressHub_AI_Settings_Storage::get_qa_article_prompt();

        $api       = new PressHub_AI_API_Client( 'coauthor' );
        $scorecard = $api->generate_scorecard( $content, $template );

        if ( is_wp_error( $scorecard ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error(
                    'AI QA review failed to generate scorecard',
                    [ 'error' => $scorecard->get_error_message(), 'context' => $context ]
                );
            }
            return [
                'passed'   => false,
                'score'    => 0,
                'feedback' => $scorecard->get_error_message(),
                'error'    => $scorecard->get_error_code(),
            ];
        }

        $score    = isset( $scorecard['score'] ) ? (int) $scorecard['score'] : 0;
        $feedback = isset( $scorecard['feedback'] ) ? (string) $scorecard['feedback'] : '';
        $passed   = ( $score >= $min_score );

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info(
                sprintf( 'AI QA evaluation completed (%s): score %d/100 (threshold: %d) -> %s', $context, $score, $min_score, $passed ? 'PASS' : 'FAIL' ),
                [
                    'context'   => $context,
                    'score'     => $score,
                    'threshold' => $min_score,
                    'passed'    => $passed,
                    'feedback'  => $feedback,
                ]
            );
        }

        return [
            'passed'   => $passed,
            'score'    => $score,
            'feedback' => $feedback,
            'error'    => null,
        ];
    }

    /**
     * Evaluate a WP_Post object. Uses content hashing to avoid redundant API calls
     * if the post content has not changed since the last review.
     *
     * @param WP_Post|object $post    WordPress post object.
     * @param string         $context Evaluation context: 'post' (default) or 'briefing'.
     * @return array Evaluation result with 'passed', 'score', 'feedback'.
     */
    public static function evaluate_post( $post, string $context = 'post' ): array {
        if ( ! is_object( $post ) || empty( $post->ID ) ) {
            return [
                'passed'   => false,
                'score'    => 0,
                'feedback' => __( 'Invalid post object.', 'presshub-ai-editor' ),
                'error'    => 'invalid_post',
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
            $score    = (int) $existing_scorecard['score'];
            $feedback = (string) ( $existing_scorecard['feedback'] ?? '' );
            return [
                'passed'   => ( $score >= $min_score ),
                'score'    => $score,
                'feedback' => $feedback,
                'error'    => null,
                'cached'   => true,
            ];
        }

        $result = self::evaluate_content( $content, $context );

        // Persist scorecard and content hash
        update_post_meta( $post_id, self::META_SCORECARD, [
            'score'    => $result['score'],
            'feedback' => $result['feedback'],
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

        // Deduplication via hash
        $content_hash = (string) get_post_meta( $post_id, self::META_CONTENT_HASH, true );
        if ( '' === $content_hash ) {
            $content_hash = md5( trim( (string) $post->post_content ) );
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

        $score       = isset( $scorecard['score'] ) ? (int) $scorecard['score'] : 0;
        $min_score   = PressHub_AI_Settings_Storage::get_qa_min_score();
        $feedback    = isset( $scorecard['feedback'] ) ? (string) $scorecard['feedback'] : __( 'No critique provided.', 'presshub-ai-editor' );
        $edit_url    = admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );
        $post_title  = ! empty( $post->post_title ) ? $post->post_title : sprintf( __( '(Post #%d)', 'presshub-ai-editor' ), $post_id );
        $site_name   = function_exists( 'wp_specialchars_decode' ) ? wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) : get_option( 'blogname' );

        if ( 'briefing' === $context ) {
            $subject = sprintf( '[%s] Daily Briefing QA Review Failed (Score: %d/%d)', $site_name, $score, $min_score );
            $intro   = __( 'The automated Daily Briefing article failed the AI Editorial QA Review and has been held in Pending Review status.', 'presshub-ai-editor' );
        } else {
            $subject = sprintf( '[%s] Article QA Review Failed: %s', $site_name, $post_title );
            $intro   = sprintf( __( 'An article submitted by %s failed the AI Editorial QA Review and was blocked from immediate publication.', 'presshub-ai-editor' ), $author_name );
        }

        $body = sprintf(
            "%s\n\n" .
            "--------------------------------------------------\n" .
            "Article: %s\n" .
            "Author: %s\n" .
            "Score: %d / 100 (Minimum required: %d)\n" .
            "Status: Pending Review\n" .
            "--------------------------------------------------\n\n" .
            "AI Editorial Critique:\n%s\n\n" .
            "Review and edit the post here:\n%s\n\n" .
            "--\nPressHub AI Editorial Co-Pilot",
            $intro,
            $post_title,
            $author_name,
            $score,
            $min_score,
            $feedback,
            $edit_url
        );

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
}
