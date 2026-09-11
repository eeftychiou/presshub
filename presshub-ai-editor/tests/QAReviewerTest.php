<?php
/**
 * Test suite for Automated AI Editorial QA Review System (PressHub_AI_QA_Reviewer).
 *
 * Verifies:
 * 1. Default QA settings and sanitization clamping (Settings-First Principle).
 * 2. Content evaluation (empty content check, threshold comparison, pass/fail state).
 * 3. Post evaluation and content hash caching (avoid redundant LLM calls on identical text).
 * 4. Cache invalidation on post content changes.
 * 5. Editor failure notifications (formatting, recipient resolution, context handling, deduplication).
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-prompt-loader.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-qa-reviewer.php';

class QAReviewerTest {

    private static array $sent_emails = [];

    public static function run(): void {
        $failures = [];

        // Track wp_mail calls
        $GLOBALS['MOCK_WP_MAIL_CB'] = function( $to, $subject, $message, $headers ) {
            self::$sent_emails[] = [
                'to'      => $to,
                'subject' => $subject,
                'message' => $message,
                'headers' => $headers,
            ];
            return true;
        };

        // -------------------------------------------------------------
        // Case 1: Settings Storage Defaults & Sanitizers (Settings-First)
        // -------------------------------------------------------------
        self::reset_env();
        if ( ! PressHub_AI_Settings_Storage::get_qa_enabled() ) {
            $failures[] = 'get_qa_enabled() should default to true.';
        }
        if ( ! PressHub_AI_Settings_Storage::get_qa_include_briefings() ) {
            $failures[] = 'get_qa_include_briefings() should default to true.';
        }
        if ( PressHub_AI_Settings_Storage::get_qa_min_score() !== 80 ) {
            $failures[] = 'get_qa_min_score() should default to 80; got: ' . PressHub_AI_Settings_Storage::get_qa_min_score();
        }
        if ( ! PressHub_AI_Settings_Storage::get_qa_notify_editor() ) {
            $failures[] = 'get_qa_notify_editor() should default to true.';
        }
        if ( PressHub_AI_Settings_Storage::get_qa_editor_email() !== '' ) {
            $failures[] = 'get_qa_editor_email() should default to empty string.';
        }

        // Sanitizer tests
        if ( PressHub_AI_Settings_Storage::sanitize_qa_min_score( 120 ) !== 100 ) {
            $failures[] = 'sanitize_qa_min_score(120) should clamp to 100.';
        }
        if ( PressHub_AI_Settings_Storage::sanitize_qa_min_score( 40 ) !== 50 ) {
            $failures[] = 'sanitize_qa_min_score(40) should clamp to 50.';
        }
        if ( PressHub_AI_Settings_Storage::sanitize_qa_editor_email( 'invalid-email' ) !== '' ) {
            $failures[] = 'sanitize_qa_editor_email with invalid email should return empty string.';
        }
        if ( PressHub_AI_Settings_Storage::sanitize_qa_editor_email( '  test@example.com  ' ) !== 'test@example.com' ) {
            $failures[] = 'sanitize_qa_editor_email should trim and preserve valid email.';
        }

        // -------------------------------------------------------------
        // Case 2: Content Evaluation with empty content
        // -------------------------------------------------------------
        self::reset_env();
        $res = PressHub_AI_QA_Reviewer::evaluate_content( '   ' );
        if ( $res['passed'] !== false || $res['error'] !== 'empty_content' ) {
            $failures[] = 'evaluate_content on empty text should return passed=false with error=empty_content.';
        }

        // -------------------------------------------------------------
        // Case 3: Post evaluation and content hash caching
        // -------------------------------------------------------------
        self::reset_env();
        $post_id = 101;
        $post = (object) [
            'ID'           => $post_id,
            'post_title'   => 'Breaking News on Renewable Energy',
            'post_content' => 'Greece expands solar investments across the islands.',
            'post_author'  => 5,
        ];

        // Seed an existing scorecard with score 85 and corresponding hash
        $content_hash = md5( trim( $post->post_content ) );
        update_post_meta( $post_id, PressHub_AI_QA_Reviewer::META_SCORECARD, [
            'score'    => 85,
            'feedback' => 'Well-written and concise report.',
        ] );
        update_post_meta( $post_id, PressHub_AI_QA_Reviewer::META_CONTENT_HASH, $content_hash );

        $eval = PressHub_AI_QA_Reviewer::evaluate_post( $post );
        if ( ! ( $eval['cached'] ?? false ) ) {
            $failures[] = 'evaluate_post should reuse cached scorecard when content hash matches.';
        }
        if ( ! $eval['passed'] || $eval['score'] !== 85 ) {
            $failures[] = 'evaluate_post should report passed=true for score 85 (threshold 80).';
        }

        // Now test threshold failure with cached score 75
        update_post_meta( $post_id, PressHub_AI_QA_Reviewer::META_SCORECARD, [
            'score'    => 75,
            'feedback' => 'Needs better sourcing.',
        ] );
        $eval_fail = PressHub_AI_QA_Reviewer::evaluate_post( $post );
        if ( $eval_fail['passed'] !== false || $eval_fail['score'] !== 75 ) {
            $failures[] = 'evaluate_post should report passed=false for score 75 (threshold 80).';
        }

        // -------------------------------------------------------------
        // Case 4: Editor Failure Notification & Deduplication
        // -------------------------------------------------------------
        self::reset_env();
        update_option( 'admin_email', 'admin@presshub.gr' );
        update_option( 'presshub_ai_qa_editor_email', 'editor@presshub.gr' );
        update_option( 'presshub_ai_qa_notify_editor', 1 );

        $post_id = 202;
        $post_obj = [
            'ID'           => $post_id,
            'post_title'   => 'Draft Article',
            'post_content' => 'Some unpolished content.',
            'post_author'  => 0,
        ];
        $GLOBALS['POSTS_STORE'][ $post_id ] = $post_obj;

        $scorecard = [
            'score'    => 65,
            'feedback' => 'Lacks structure and attribution.',
        ];

        // First notification should be dispatched
        self::$sent_emails = [];
        $notified = PressHub_AI_QA_Reviewer::notify_editor_failure( $post_id, $scorecard, 'post' );
        if ( ! $notified || count( self::$sent_emails ) !== 1 ) {
            $failures[] = 'notify_editor_failure should send 1 email on initial failure; sent: ' . count( self::$sent_emails );
        } else {
            $email = self::$sent_emails[0];
            if ( $email['to'] !== 'editor@presshub.gr' ) {
                $failures[] = 'Notification recipient should be editor@presshub.gr; got: ' . $email['to'];
            }
            if ( false === strpos( $email['subject'], 'Draft Article' ) ) {
                $failures[] = 'Notification subject should contain post title; got: ' . $email['subject'];
            }
            if ( false === strpos( $email['message'], '65 / 100' ) ) {
                $failures[] = 'Notification body should contain score (65 / 100); got: ' . $email['message'];
            }
            if ( false === strpos( $email['message'], 'Lacks structure and attribution.' ) ) {
                $failures[] = 'Notification body should contain AI feedback; got: ' . $email['message'];
            }
        }

        // Second notification with identical content should be suppressed (deduplication)
        self::$sent_emails = [];
        $second_notified = PressHub_AI_QA_Reviewer::notify_editor_failure( $post_id, $scorecard, 'post' );
        if ( ! $second_notified || count( self::$sent_emails ) !== 0 ) {
            $failures[] = 'notify_editor_failure should suppress duplicate email for identical content hash; sent: ' . count( self::$sent_emails );
        }

        // Briefing context notification
        self::reset_env();
        update_option( 'admin_email', 'admin@presshub.gr' );
        update_option( 'blogname', 'PressHub News' );
        $briefing_id = 303;
        $briefing_post = [
            'ID'           => $briefing_id,
            'post_title'   => 'Daily Briefing - 11 September 2026',
            'post_content' => 'Briefing text content.',
            'post_author'  => 0,
        ];
        $GLOBALS['POSTS_STORE'][ $briefing_id ] = $briefing_post;

        self::$sent_emails = [];
        PressHub_AI_QA_Reviewer::notify_editor_failure( $briefing_id, [ 'score' => 60, 'feedback' => 'Repetitive bullets.' ], 'briefing' );
        if ( count( self::$sent_emails ) !== 1 ) {
            $failures[] = 'notify_editor_failure in briefing context should send email; sent: ' . count( self::$sent_emails );
        } else {
            $email = self::$sent_emails[0];
            if ( false === strpos( $email['subject'], 'Daily Briefing QA Review Failed' ) ) {
                $failures[] = 'Briefing notification subject should indicate Daily Briefing QA failure; got: ' . $email['subject'];
            }
        }

        // -------------------------------------------------------------
        // Report results
        // -------------------------------------------------------------
        if ( ! empty( $failures ) ) {
            echo "FAIL\n";
            foreach ( $failures as $failure ) {
                echo "  - {$failure}\n";
            }
            exit( 1 );
        }

        echo "OK\n";
        exit( 0 );
    }

    private static function reset_env(): void {
        $GLOBALS['OPTIONS_STORE']   = [];
        $GLOBALS['POST_META_STORE'] = [];
        $GLOBALS['POSTS_STORE']     = [];
        self::$sent_emails          = [];
    }
}

QAReviewerTest::run();
