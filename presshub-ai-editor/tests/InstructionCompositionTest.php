<?php
/**
 * Contract tests: per-author instruction presets composed into the
 * outgoing AI request bodies (2026-08-15 design doc §7.3).
 *
 * Black-box assertions on the request body the API client actually sends:
 * when a preset applies, the composed system prompt is
 *   <built-in> . "\n\n" . <preset instruction_text>
 * and the existing presshub_ai_<endpoint>_system_prompt filter receives
 * the already-composed string. Exactly one preset applies per request
 * (per-request selection > author default; author presets shadow plugin
 * defaults with the same slug — enforced by PressHub_AI_Preset_Resolver,
 * Phase 1).
 *
 * Cases 1-6, 10-14 exercise generate_draft(); cases 7-9 pin the §3.3
 * editorial gate: scorecard / classify / audio are NEVER preset-composed.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-preset-sanitizer.php';
require_once __DIR__ . '/../includes/class-preset-store.php';
require_once __DIR__ . '/../includes/class-preset-resolver.php';
require_once __DIR__ . '/../includes/class-api-client.php';

class InstructionCompositionTest
{
    private const BUILTIN_DRAFT    = 'You are a professional AI journalist.';
    private const BUILTIN_CLASSIFY = "You are an orchestrator routing user prompts to specialized tools. Classify the user prompt into exactly one of these lowercase strings: 'chat', 'research', 'image', or 'report'.\n- 'chat': Normal Q&A, general questions, writing suggestions, conversations.\n- 'research': Comprehensive synthesis, deep analysis, research on a topic, or requests for a deep investigation.\n- 'image': Requests to generate, create, draw, paint, or design an image/illustration.\n- 'report': Requests to voice over, summarize, or translate an audio or video file/link into a narrated report.\nOutput ONLY the lowercase classification string (e.g. 'chat' or 'research') and absolutely nothing else.";
    private const BUILTIN_AUDIO    = "You are a professional news radio narrator. Convert the user's prompt or media notes into a short 4-5 sentence radio report script. Output ONLY the speech script and nothing else.";

    public static function run(): void {
        $failures = [];

        // --- Case 1: no presets anywhere -> built-in only, no extra \n\n ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT ) {
            $failures[] = "Case 1: expected exactly the built-in draft prompt, got: " . var_export( $sys, true );
        }

        // --- Case 2: plugin default only (via author's default slug) ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'wire-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT . "\n\n" . 'PLUGIN_TEXT' ) {
            $failures[] = "Case 2: expected built-in + plugin preset, got: " . var_export( $sys, true );
        }

        // --- Case 3: author default only ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT . "\n\n" . 'AUTHOR_TEXT' ) {
            $failures[] = "Case 3: expected built-in + author preset, got: " . var_export( $sys, true );
        }

        // --- Case 4: plugin + author presets both configured ---
        // The committed resolver applies EXACTLY ONE preset per request:
        // the author's default slug names one preset, and an author preset
        // shadows a same-slug plugin default. So with both scopes populated
        // and the author default pointing at the author preset, only the
        // author text is composed (plugin text stays out of the body).
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT . "\n\n" . 'AUTHOR_TEXT' ) {
            $failures[] = "Case 4a: expected built-in + author preset (author default wins), got: " . var_export( $sys, true );
        }
        if ( strpos( $sys, 'PLUGIN_TEXT' ) !== false ) {
            $failures[] = "Case 4a: plugin preset must NOT be composed when the author default names the author preset. Got: " . var_export( $sys, true );
        }
        // Flip the default to the plugin preset: now only the plugin text
        // applies; the author's non-default preset stays out.
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'wire-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT . "\n\n" . 'PLUGIN_TEXT' ) {
            $failures[] = "Case 4b: expected built-in + plugin preset when default names it, got: " . var_export( $sys, true );
        }
        if ( strpos( $sys, 'AUTHOR_TEXT' ) !== false ) {
            $failures[] = "Case 4b: author preset must NOT be composed when it is not the default. Got: " . var_export( $sys, true );
        }

        // --- Case 5: per-request selection wins over author default ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text', [], 'wire-style' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT . "\n\n" . 'PLUGIN_TEXT' ) {
            $failures[] = "Case 5: per-request slug should win over author default. Got: " . var_export( $sys, true );
        }
        if ( strpos( $sys, 'AUTHOR_TEXT' ) !== false ) {
            $failures[] = "Case 5: author default preset must not appear when per-request wins. Got: " . var_export( $sys, true );
        }

        // --- Case 6: '__none__' per-request disables presets entirely ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text', [], '__none__' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT ) {
            $failures[] = "Case 6: '__none__' should disable presets; got: " . var_export( $sys, true );
        }

        // --- Case 7: scorecard untouched (editorial gate invariant) ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_scorecard( 'some draft content here' );
        $sys = self::last_openai_system();
        if ( $sys !== PressHub_AI_Prompt_Loader::get_scorecard_system_prompt() ) {
            $failures[] = "Case 7: scorecard prompt must be untouched by presets. Got: " . var_export( $sys, true );
        }

        // --- Case 8: classify-intent untouched ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->classify_intent( 'hello there' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_CLASSIFY ) {
            $failures[] = "Case 8: classify-intent prompt must be untouched by presets. Got: " . var_export( $sys, true );
        }

        // --- Case 9: audio report untouched ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'gc-key';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_audio_report( 'summarize this video', 0 );
        $sys = self::first_gemini_system();
        if ( $sys !== self::BUILTIN_AUDIO ) {
            $failures[] = "Case 9: audio script prompt must be untouched by presets. Got: " . var_export( $sys, true );
        }

        // --- Case 10: oversized preset text truncated to 4,000 by sanitizer ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'long', 'name' => 'Long', 'instruction_text' => str_repeat( 'x', 5000 ), 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'long';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        $expected = self::BUILTIN_DRAFT . "\n\n" . str_repeat( 'x', 4000 );
        if ( $sys !== $expected ) {
            $failures[] = "Case 10: oversized preset should be truncated to 4,000 chars. Got length " . strlen( (string) $sys ) . ', expected ' . strlen( $expected );
        }

        // --- Case 11: disabled author preset skipped even as default ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => false ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT ) {
            $failures[] = "Case 11: disabled preset must be skipped. Got: " . var_export( $sys, true );
        }

        // --- Case 12: empty instruction_text skipped (no blank \n\n) ---
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'blank', 'name' => 'Blank', 'instruction_text' => '   ', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'blank';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT ) {
            $failures[] = "Case 12: empty preset text must not add a separator. Got: " . var_export( $sys, true );
        }

        // --- Case 13: 3-arg backward compatibility ---
        // (a) No presets configured: identical to pre-preset behaviour.
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT ) {
            $failures[] = "Case 13a: 3-arg call with no presets must match old behaviour. Got: " . var_export( $sys, true );
        }
        // (b) Author default configured: the omitted 4th arg ('' ) falls
        // back to the author's default — the documented contract.
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text' );
        $sys = self::last_openai_system();
        if ( $sys !== self::BUILTIN_DRAFT . "\n\n" . 'AUTHOR_TEXT' ) {
            $failures[] = "Case 13b: 3-arg call should fall back to the author default. Got: " . var_export( $sys, true );
        }

        // --- Case 14 (C-3): the pre-composition filter receives the BASE
        //             prompt only (preset appended AFTER it runs); the new
        //             presshub_ai_composed_system_prompt filter receives
        //             the full base + preset string.
        self::reset();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_api_key'] = 'test-key';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_provider'] = 'openai';
        $GLOBALS['CURRENT_USER_ID'] = 7;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
            [ 'slug' => 'wire-style', 'name' => 'Wire', 'instruction_text' => 'PLUGIN_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_author_presets'] = [
            [ 'slug' => 'my-style', 'name' => 'Mine', 'instruction_text' => 'AUTHOR_TEXT', 'enabled' => true ],
        ];
        $GLOBALS['USER_META_STORE'][7]['presshub_ai_default_preset_id'] = 'my-style';
        $captured = null;
        $captured_composed = null;
        add_filter( 'presshub_ai_draft_system_prompt', function ( $p ) use ( &$captured ) {
            $captured = $p;
            return $p;
        } );
        add_filter( 'presshub_ai_composed_system_prompt', function ( $p ) use ( &$captured_composed ) {
            $captured_composed = $p;
            return $p;
        } );
        $api = new PressHub_AI_API_Client();
        $api->generate_draft( 'source text', 'instructions text', [], 'wire-style' );
        $expected = self::BUILTIN_DRAFT . "\n\n" . 'PLUGIN_TEXT';
        if ( $captured !== self::BUILTIN_DRAFT ) {
            $failures[] = "Case 14: pre-composition filter must receive the BASE prompt (built-in, pre-append). Got: " . var_export( $captured, true );
        }
        if ( $captured_composed !== $expected ) {
            $failures[] = "Case 14: composed filter must receive base + per-request preset. Got: " . var_export( $captured_composed, true );
        }
        // And the composed filter's return value is what actually ships in the body.
        $sys = self::last_openai_system();
        if ( $sys !== $expected ) {
            $failures[] = "Case 14: composed string must reach the request body. Got: " . var_export( $sys, true );
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

    /**
     * System prompt from the most recent OpenAI chat-completions request.
     */
    private static function last_openai_system(): ?string {
        $requests = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        for ( $i = count( $requests ) - 1; $i >= 0; $i-- ) {
            [ $url, $args ] = $requests[ $i ];
            if ( str_contains( $url, 'api.openai.com' ) ) {
                $body = json_decode( $args['body'], true );
                return isset( $body['messages'][0]['content'] ) ? $body['messages'][0]['content'] : null;
            }
        }
        return null;
    }

    /**
     * System prompt from the first Gemini generateContent request
     * (generate_audio_report's script synthesis call).
     */
    private static function first_gemini_system(): ?string {
        $requests = $GLOBALS['CAPTURED_REQUESTS'] ?? [];
        foreach ( $requests as [ $url, $args ] ) {
            if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
                $body = json_decode( $args['body'], true );
                return $body['systemInstruction']['parts'][0]['text'] ?? null;
            }
        }
        return null;
    }

    private static function reset(): void {
        unset(
            $GLOBALS['FILTERS'],
            $GLOBALS['CAPTURED_REQUESTS'],
            $GLOBALS['OPTIONS_STORE'],
            $GLOBALS['USER_META_STORE'],
            $GLOBALS['CURRENT_USER_ID']
        );
        $GLOBALS['CAPTURE_FILTER'] = null;
        $GLOBALS['CAPTURED_GETS'] = [];
    }
}

InstructionCompositionTest::run();
