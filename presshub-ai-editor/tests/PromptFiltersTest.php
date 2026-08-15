<?php
/**
 * TDD tests for prompt template filters (apply_filters hooks).
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class PromptFiltersTest
{
    public static function run(): void {
        $failures = [];

        // --- Case 1: draft system prompt is filterable ---
        unset( $GLOBALS['FILTERS'], $GLOBALS['CAPTURED_REQUESTS'], $GLOBALS['OPTIONS_STORE'] );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        add_filter( 'presshub_ai_draft_system_prompt', function ( $p ) {
            return 'CUSTOM_DRAFT_SYSTEM';
        } );
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $body = self::last_request_body();
        if ( false === strpos( $body, 'CUSTOM_DRAFT_SYSTEM' ) ) {
            $failures[] = 'presshub_ai_draft_system_prompt filter was not applied. Body: ' . substr( $body, 0, 200 );
        }

        // --- Case 2: classify intent prompt is filterable ---
        unset( $GLOBALS['FILTERS'], $GLOBALS['CAPTURED_REQUESTS'] );
        add_filter( 'presshub_ai_classify_intent_prompt', function ( $p ) {
            return 'CUSTOM_CLASSIFY_PROMPT';
        } );
        $api = new PressHub_AI_API_Client();
        $api->classify_intent( 'hello there' );
        $body = self::last_request_body();
        if ( false === strpos( $body, 'CUSTOM_CLASSIFY_PROMPT' ) ) {
            $failures[] = 'presshub_ai_classify_intent_prompt filter was not applied. Body: ' . substr( $body, 0, 200 );
        }

        // --- Case 3: scorecard system prompt is filterable ---
        unset( $GLOBALS['FILTERS'], $GLOBALS['CAPTURED_REQUESTS'] );
        add_filter( 'presshub_ai_scorecard_system_prompt', function ( $p ) {
            return 'CUSTOM_SCORECARD_SYSTEM';
        } );
        $api = new PressHub_AI_API_Client();
        $api->generate_scorecard( 'some draft content here' );
        $body = self::last_request_body();
        if ( false === strpos( $body, 'CUSTOM_SCORECARD_SYSTEM' ) ) {
            $failures[] = 'presshub_ai_scorecard_system_prompt filter was not applied. Body: ' . substr( $body, 0, 200 );
        }

        // --- Case 4: without filters the default prompt is used ---
        unset( $GLOBALS['FILTERS'], $GLOBALS['CAPTURED_REQUESTS'] );
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $body = self::last_request_body();
        if ( false === strpos( $body, 'professional AI journalist' ) ) {
            $failures[] = 'Default draft prompt missing when no filter registered. Body: ' . substr( $body, 0, 200 );
        }

        if ( $failures ) {
            fwrite( STDERR, "FAIL\n" );
            foreach ( $failures as $f ) {
                fwrite( STDERR, "  - {$f}\n" );
            }
            exit( 1 );
        }
        echo "OK\n";
    }

    private static function last_request_body(): string {
        $requests = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        $last = end( $requests );
        if ( ! $last || ! isset( $last[1]['body'] ) ) {
            return '';
        }
        return is_string( $last[1]['body'] ) ? $last[1]['body'] : json_encode( $last[1]['body'] );
    }
}

PromptFiltersTest::run();
