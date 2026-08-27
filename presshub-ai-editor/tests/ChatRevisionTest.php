<?php
/**
 * TDD Unit Tests for Chat Revision Protocol and Article Context Injection.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class ChatRevisionTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: Chat response without revision blocks passes through cleanly ---
        self::reset();
        $_POST = [
            'prompt'          => 'How should I improve the headline?',
            'article_content' => '<p>The government announced new measures today.</p>',
            'article_title'   => 'Economic Policy Update',
            'post_id'         => 0,
        ];
        $count1 = 0;
        $GLOBALS['CAPTURE_FILTER'] = function ( $prev, $ctx ) use ( &$count1 ) {
            $count1++;
            if ( 1 === $count1 ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'chat' ] ] ] ] ),
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices' => [ [ 'message' => [ 'content' => 'Consider using active verbs and highlighting the consumer impact.' ] ] ],
                ] ),
            ];
        };

        $data = null;
        try {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->handle_chat_routing();
        } catch ( RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'];
            $last = end( $responses );
            $data = $last ? $last['data'] : null;
        }

        if ( ! is_array( $data ) || ( $data['type'] ?? '' ) !== 'chat' ) {
            $failures[] = 'Case 1: Expected type=chat response; got: ' . json_encode( $data );
        }
        if ( ! empty( $data['revisions'] ) ) {
            $failures[] = 'Case 1: Expected empty revisions array; got: ' . json_encode( $data['revisions'] );
        }
        if ( false === strpos( $data['content'] ?? '', 'active verbs' ) ) {
            $failures[] = 'Case 1: Content should contain raw answer; got: ' . ( $data['content'] ?? '' );
        }

        // --- Case 2: Structured revision blocks are parsed and extracted cleanly ---
        self::reset();
        $sample_ai_output = "I have reviewed your article and addressed the editor's comments regarding conciseness.\n\n" .
            "<<<REVISION\n" .
            "ORIGINAL:\n" .
            "<p>The government of the republic announced a set of brand new fiscal measures today during the morning briefing.</p>\n" .
            "REVISED:\n" .
            "<p>The government announced decisive new fiscal measures today.</p>\n" .
            "SUMMARY:\n" .
            "Streamlined lead paragraph for impact\n" .
            "REVISION>>>\n\n" .
            "<<<REVISION\n" .
            "ORIGINAL:\n" .
            "<p>In conclusion, things will probably change over time.</p>\n" .
            "REVISED:\n" .
            "<p>These measures mark a critical shift in national economic trajectory.</p>\n" .
            "SUMMARY:\n" .
            "Strengthened conclusion paragraph\n" .
            "REVISION>>>\n\n" .
            "Let me know if you would like any further adjustments!";

        $_POST = [
            'prompt'          => 'Address editor feedback: make the lead punchier and strengthen the ending.',
            'article_content' => '<p>The government of the republic announced a set of brand new fiscal measures today during the morning briefing.</p><p>In conclusion, things will probably change over time.</p>',
            'article_title'   => 'New Economic Package',
            'post_id'         => 0,
        ];

        $count2 = 0;
        $GLOBALS['CAPTURE_FILTER'] = function ( $prev, $ctx ) use ( &$count2, $sample_ai_output ) {
            $count2++;
            if ( 1 === $count2 ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'chat' ] ] ] ] ),
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices' => [ [ 'message' => [ 'content' => $sample_ai_output ] ] ],
                ] ),
            ];
        };

        $data = null;
        try {
            $h = new PressHub_AI_Ajax_Handlers();
            $h->handle_chat_routing();
        } catch ( RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'];
            $last = end( $responses );
            $data = $last ? $last['data'] : null;
        }

        if ( ! is_array( $data ) || empty( $data['revisions'] ) ) {
            $failures[] = 'Case 2: Expected revisions array; got: ' . json_encode( $data );
        } elseif ( count( $data['revisions'] ) !== 2 ) {
            $failures[] = 'Case 2: Expected 2 revisions; got count: ' . count( $data['revisions'] );
        } else {
            $rev1 = $data['revisions'][0];
            if ( false === strpos( $rev1['original'], 'brand new fiscal measures' ) ) {
                $failures[] = 'Case 2: Revision 1 original mismatch: ' . var_export( $rev1, true );
            }
            if ( false === strpos( $rev1['revised'], 'decisive new fiscal measures' ) ) {
                $failures[] = 'Case 2: Revision 1 revised mismatch: ' . var_export( $rev1, true );
            }
            if ( $rev1['summary'] !== 'Streamlined lead paragraph for impact' ) {
                $failures[] = 'Case 2: Revision 1 summary mismatch: ' . var_export( $rev1, true );
            }

            $rev2 = $data['revisions'][1];
            if ( false === strpos( $rev2['original'], 'things will probably change' ) ) {
                $failures[] = 'Case 2: Revision 2 original mismatch: ' . var_export( $rev2, true );
            }
        }

        // Conversational content should NOT have raw revision tags
        if ( false !== strpos( $data['content'] ?? '', '<<<REVISION' ) || false !== strpos( $data['content'] ?? '', 'REVISION>>>' ) ) {
            $failures[] = 'Case 2: Raw revision marker tags should be stripped from conversational content; got: ' . ( $data['content'] ?? '' );
        }
        if ( false === strpos( $data['content'] ?? '', "addressed the editor's comments" ) ) {
            $failures[] = 'Case 2: Conversational intro missing from content; got: ' . ( $data['content'] ?? '' );
        }

        if ( ! empty( $failures ) ) {
            echo "ChatRevisionTest FAIL:\n  - " . implode( "\n  - ", $failures ) . "\n";
            exit( 1 );
        }

        echo "ChatRevisionTest: OK\n";
    }

    private static function reset(): void {
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID'] = 1;
        $GLOBALS['JSON_RESPONSES'] = [];
        $GLOBALS['CAPTURE_FILTER'] = null;
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $GLOBALS['OPTIONS_STORE'] = [
            'presshub_ai_provider' => 'openai',
            'presshub_ai_api_key'  => 'sk-test-key',
        ];
        $GLOBALS['NONCE_VALID'] = true;
        $_POST = [];
    }
}

ChatRevisionTest::run();
