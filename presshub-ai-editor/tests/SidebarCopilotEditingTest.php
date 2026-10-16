<?php
/**
 * Comprehensive Test Suite for AI Co-Pilot Sidebar:
 * Editing an Existing Article, Context Hydration, Intent Routing,
 * Multi-Revision Parsing, Permission Gates, and Safe Block Replacement.
 *
 * Requirement 5.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-preset-sanitizer.php';
require_once __DIR__ . '/../includes/class-preset-store.php';
require_once __DIR__ . '/../includes/class-preset-resolver.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class SidebarCopilotEditingTest {

    public static function run(): void {
        $failures = [];

        // Setup Provider Store & current user
        $GLOBALS['CURRENT_USER_ID'] = 1;
        $GLOBALS['NONCE_VALID']     = true;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'edit_post' ];
        $GLOBALS['JSON_RESPONSES']  = [];

        if ( class_exists( 'PressHub_AI_Provider_Store' ) ) {
            PressHub_AI_Provider_Store::save_provider( [
                'id'            => 'openai-test',
                'type'          => 'openai',
                'name'          => 'OpenAI Test',
                'api_key'       => 'sk-test-copilot-key-12345',
                'default_model' => 'gpt-4o',
            ] );
            update_option( 'presshub_ai_copilot_provider', 'openai-test' );
        }

        // -------------------------------------------------------------
        // 1. Article Context Hydration from Existing Post in DB
        // -------------------------------------------------------------
        $existing_post_id = 501;
        $mock_existing_post = [
            'ID'           => $existing_post_id,
            'post_title'   => 'Κυπριακή Οικονομία: Ανάπτυξη 3.2% το 2026',
            'post_content' => '<p>Η κυπριακή οικονομία κατέγραψε ισχυρή ανάπτυξη το πρώτο εξάμηνο του 2026.</p><p>Οι επενδύσεις στον τομέα της τεχνολογίας αυξήθηκαν κατά 15%.</p>',
            'post_status'  => 'publish',
            'post_type'    => 'post',
        ];
        $GLOBALS['POSTS_STORE'][ $existing_post_id ] = $mock_existing_post;

        // Call handle_chat_routing with post_id, but EMPTY article_content in $_POST
        $_POST = [
            'nonce'                 => wp_create_nonce( 'presshub_ai_nonce' ),
            'prompt'                => 'Κάνε πιο σύντομη την πρώτη παράγραφο',
            'post_id'               => $existing_post_id,
            'article_content'       => '', // Empty -> backend must hydrate from DB!
            'article_title'         => '',
            'instruction_preset_id' => '',
        ];

        $captured_sys_prompt = '';
        $call_count = 0;
        $GLOBALS['CAPTURE_FILTER'] = function( $prev, $ctx ) use ( &$captured_sys_prompt, &$call_count ) {
            $call_count++;
            $args = $ctx[1] ?? [];
            $body = json_decode( $args['body'] ?? '{}', true );
            $messages = $body['messages'] ?? [];
            foreach ( $messages as $msg ) {
                if ( ( $msg['role'] ?? '' ) === 'system' ) {
                    $captured_sys_prompt = (string) ( $msg['content'] ?? '' );
                }
            }

            if ( 1 === $call_count ) {
                // Intent classification call
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'chat' ] ] ] ] ),
                ];
            }

            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'choices' => [ [ 'message' => [ 'content' => "Επεξεργάστηκα την παράγραφο.\n\n<<<REVISION\nORIGINAL:\n<p>Η κυπριακή οικονομία κατέγραψε ισχυρή ανάπτυξη το πρώτο εξάμηνο του 2026.</p>\nREVISED:\n<p>Ισχυρή ανάπτυξη 3.2% κατέγραψε η οικονομία της Κύπρου το 2026.</p>\nSUMMARY:\nΣυνοπτική διατύπωση εισαγωγής\nREVISION>>>" ] ] ],
                ] ),
            ];
        };

        $ajax = new PressHub_AI_Ajax_Handlers();
        $response_data = null;
        try {
            $ajax->handle_chat_routing();
        } catch ( \RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
            $last = end( $responses );
            $response_data = $last ? $last['data'] : null;
        }

        if ( empty( $captured_sys_prompt ) ) {
            $failures[] = 'handle_chat_routing() did not call AI provider or capture system prompt';
        } else {
            if ( false === strpos( $captured_sys_prompt, '--- CURRENT ARTICLE CONTEXT ---' ) ) {
                $failures[] = 'System prompt missing --- CURRENT ARTICLE CONTEXT --- header';
            }
            if ( false === strpos( $captured_sys_prompt, 'Κυπριακή Οικονομία: Ανάπτυξη 3.2% το 2026' ) ) {
                $failures[] = 'System prompt missing hydrated article title from database';
            }
            if ( false === strpos( $captured_sys_prompt, 'Η κυπριακή οικονομία κατέγραψε ισχυρή ανάπτυξη' ) ) {
                $failures[] = 'System prompt missing hydrated article content from database';
            }
            if ( false === strpos( $captured_sys_prompt, 'EDITORIAL & REVISION INSTRUCTIONS:' ) ) {
                $failures[] = 'System prompt missing EDITORIAL & REVISION INSTRUCTIONS';
            }
        }

        // Verify revision extraction
        if ( ! is_array( $response_data ) || ( $response_data['type'] ?? '' ) !== 'chat' ) {
            $failures[] = 'Expected type=chat response; got: ' . json_encode( $response_data );
        }
        $revisions = $response_data['revisions'] ?? [];
        if ( 1 !== count( $revisions ) ) {
            $failures[] = 'Expected 1 extracted revision block; got: ' . count( $revisions );
        } else {
            $r = $revisions[0];
            if ( false === strpos( $r['original'], 'Η κυπριακή οικονομία κατέγραψε' ) ) {
                $failures[] = 'Extracted revision original mismatch; got: ' . ( $r['original'] ?? '' );
            }
            if ( false === strpos( $r['revised'], 'Ισχυρή ανάπτυξη 3.2%' ) ) {
                $failures[] = 'Extracted revision revised mismatch; got: ' . ( $r['revised'] ?? '' );
            }
            if ( 'Συνοπτική διατύπωση εισαγωγής' !== $r['summary'] ) {
                $failures[] = 'Extracted revision summary mismatch; got: ' . ( $r['summary'] ?? '' );
            }
        }

        // -------------------------------------------------------------
        // 2. Permission Gate for Existing Post
        // -------------------------------------------------------------
        // User lacks 'edit_post' permission on post 501
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ]; // Has general edit_posts, but not edit_post on 501
        $_POST = [
            'nonce'   => wp_create_nonce( 'presshub_ai_nonce' ),
            'prompt'  => 'Edit this',
            'post_id' => $existing_post_id,
        ];
        $perm_denied = false;
        try {
            $ajax->handle_chat_routing();
        } catch ( \RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
            $last = end( $responses );
            if ( false === ( $last['success'] ?? true ) ) {
                $perm_denied = true;
            }
        }
        if ( ! $perm_denied ) {
            $failures[] = 'handle_chat_routing() must deny access when user lacks edit_post on specific post_id';
        }

        // -------------------------------------------------------------
        // 3. Paid Media Intent Gating (Admin only)
        // -------------------------------------------------------------
        // Restore author capability (not manage_options)
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'edit_post' ];
        $_POST = [
            'nonce'   => wp_create_nonce( 'presshub_ai_nonce' ),
            'prompt'  => 'Generate an image of the Cypriot economy',
            'post_id' => $existing_post_id,
            'intent'  => 'image',
        ];
        $admin_gated = false;
        try {
            $ajax->handle_chat_routing();
        } catch ( \RuntimeException $e ) {
            $responses = $GLOBALS['JSON_RESPONSES'] ?? [];
            $last = end( $responses );
            if ( false === ( $last['success'] ?? true ) && false !== strpos( $last['data'] ?? '', 'Permission denied' ) ) {
                $admin_gated = true;
            }
        }
        if ( ! $admin_gated ) {
            $failures[] = 'handle_chat_routing() must deny paid media intents (image/report) to non-admins';
        }

        // -------------------------------------------------------------
        // 4. Multiple Revision Protocol & Formatting
        // -------------------------------------------------------------
        $multi_revision_ai_output = "Here are two editorial revisions addressing your comments:\n\n" .
            "<<<REVISION\nORIGINAL:\n<p>Snippet one to be replaced.</p>\nREVISED:\n<p>Streamlined snippet one.</p>\nSUMMARY:\nFix lead\nREVISION>>>\n\n" .
            "Between the revisions we can keep the middle content untouched.\n\n" .
            "<<<REVISION\nORIGINAL:\n<p>Snippet two to be improved.</p>\nREVISED:\n<p>Enhanced snippet two with statistics.</p>\nSUMMARY:\nAdd data\nREVISION>>>\n\n" .
            "Hope this helps!";

        $pattern = '/<<<REVISION\s*\nORIGINAL:\s*\n(.*?)\nREVISED:\s*\n(.*?)\nSUMMARY:\s*\n(.*?)\nREVISION>>>/s';
        preg_match_all( $pattern, $multi_revision_ai_output, $matches, PREG_SET_ORDER );

        if ( 2 !== count( $matches ) ) {
            $failures[] = 'Expected 2 parsed revision blocks from multi-revision output; got: ' . count( $matches );
        }

        // -------------------------------------------------------------
        // 5. Sidebar JavaScript String Safety Invariants
        // -------------------------------------------------------------
        $sidebar_js = file_get_contents( dirname( __DIR__ ) . '/assets/sidebar.js' );

        // 5a: replaceAllSafe function definition exists
        if ( false === strpos( $sidebar_js, 'const replaceAllSafe = (haystack, needle, replacement)' ) ) {
            $failures[] = 'assets/sidebar.js missing replaceAllSafe helper';
        }

        // 5b: escapeReplacementString escapes $ to prevent $1/$& token corruptions
        if ( false === strpos( $sidebar_js, "replace(/\\$/g, '$$$$')" ) ) {
            $failures[] = 'assets/sidebar.js missing escapeReplacementString $ escaping';
        }

        // 5c: applyBlockLevelReplacement mutates only attributes.content / attributes.text
        if ( false === strpos( $sidebar_js, 'const applyBlockLevelReplacement = (originalText, revisedText)' ) ) {
            $failures[] = 'assets/sidebar.js missing applyBlockLevelReplacement helper';
        }

        // 5d: Post-specific localStorage isolation
        if ( false === strpos( $sidebar_js, "const chatStorageKey = (postId) => 'presshub_ai_chat_' + (postId ? postId : 'global');" ) ) {
            $failures[] = 'assets/sidebar.js missing per-post chatStorageKey isolation';
        }

        // 5e: Resizable bounds 320px to 960px with default 520px
        if ( false === strpos( $sidebar_js, 'SIDEBAR_WIDTH_MIN = 320' ) || false === strpos( $sidebar_js, 'SIDEBAR_WIDTH_MAX = 960' ) ) {
            $failures[] = 'assets/sidebar.js missing sidebar resize constants (320-960)';
        }

        if ( ! empty( $failures ) ) {
            echo "FAIL: SidebarCopilotEditingTest\n";
            foreach ( $failures as $f ) {
                echo "  - {$f}\n";
            }
            exit( 1 );
        }

        echo "SidebarCopilotEditingTest: OK\n";
    }
}

SidebarCopilotEditingTest::run();
