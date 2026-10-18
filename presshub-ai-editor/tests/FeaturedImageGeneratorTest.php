<?php
/**
 * Tests for the AI Featured Image Generation feature:
 * - Settings storage & sanitizers
 * - API Client prompt analysis & image synthesis (Gemini & OpenAI)
 * - Daily Briefing text & podcast automatic generation with pool fallback
 * - AJAX handlers & capability checks
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-prompt-loader.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-markdown.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-news-curator.php';
require_once __DIR__ . '/../includes/class-audio-synthesizer.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';

class FeaturedImageGeneratorTest {
    private $failures = [];

    public function run(): void {
        $this->test_settings_and_defaults();
        $this->test_analyze_article_for_image_prompt();
        $this->test_generate_featured_image_gemini();
        $this->test_generate_featured_image_openai();
        $this->test_briefing_curator_auto_image_generation();
        $this->test_briefing_curator_auto_image_fallback_to_pool();
        $this->test_podcast_auto_image_generation();
        $this->test_ajax_handlers();

        if ( ! empty( $this->failures ) ) {
            echo "FAILED:\n" . implode( "\n", $this->failures ) . "\n";
            exit( 1 );
        }
        echo "OK\n";
    }

    private function assert( bool $condition, string $message ): void {
        if ( ! $condition ) {
            $this->failures[] = $message;
        }
    }

    private function reset_env(): void {
        $GLOBALS['OPTIONS_STORE']     = [];
        $GLOBALS['POST_META']         = [];
        $GLOBALS['POST_THUMBNAILS']   = [];
        $GLOBALS['WP_TEST_POSTS']     = [];
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'manage_options', 'edit_others_posts' ];
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['REMOTE_RESPONSES']  = [];
        $GLOBALS['SIDELOAD_RETURN_ID'] = 42;

        $GLOBALS['CAPTURE_FILTER'] = function( $existing, $req ) {
            $url = $req[0] ?? '';
            if ( isset( $GLOBALS['REMOTE_RESPONSES'][ $url ] ) ) {
                return $GLOBALS['REMOTE_RESPONSES'][ $url ];
            }
            foreach ( $GLOBALS['REMOTE_RESPONSES'] as $prefix => $resp ) {
                if ( false !== strpos( $url, $prefix ) ) {
                    return $resp;
                }
            }
            return null;
        };
    }

    public function test_settings_and_defaults(): void {
        $this->reset_env();

        // Defaults
        $this->assert( false === PressHub_AI_Settings_Storage::get_image_feature_enabled(), 'Default image feature enabled should be false' );
        $this->assert( '16:9' === PressHub_AI_Settings_Storage::get_image_aspect_ratio(), 'Default aspect ratio should be 16:9' );
        $this->assert( 'pool' === PressHub_AI_Settings_Storage::get_briefing_featured_image_source(), 'Default briefing image source should be pool' );
        $this->assert( 'pool' === PressHub_AI_Settings_Storage::get_briefing_podcast_featured_image_source(), 'Default briefing podcast image source should be pool' );

        // Sanitizers
        $this->assert( 1 === PressHub_AI_Settings_Storage::sanitize_image_feature_enabled( '1' ), 'Sanitize enabled "1" -> 1' );
        $this->assert( 0 === PressHub_AI_Settings_Storage::sanitize_image_feature_enabled( '0' ), 'Sanitize enabled "0" -> 0' );
        $this->assert( '1:1' === PressHub_AI_Settings_Storage::sanitize_image_aspect_ratio( '1:1' ), 'Sanitize aspect ratio 1:1' );
        $this->assert( '16:9' === PressHub_AI_Settings_Storage::sanitize_image_aspect_ratio( 'invalid' ), 'Invalid aspect ratio clamps to 16:9' );
        $this->assert( 'auto_generate' === PressHub_AI_Settings_Storage::sanitize_briefing_featured_image_source( 'auto_generate' ), 'Sanitize auto_generate source' );
        $this->assert( 'pool' === PressHub_AI_Settings_Storage::sanitize_briefing_featured_image_source( 'invalid_source' ), 'Invalid source clamps to pool' );
    }

    public function test_analyze_article_for_image_prompt(): void {
        $this->reset_env();

        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-test',
            'type'          => 'openai',
            'name'          => 'OpenAI Test',
            'api_key'       => 'test-api-key',
            'default_model' => 'gpt-4o',
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_prompt_provider'] = 'openai-test';

        $expected_prompt = 'A wide-angle 35mm photograph of solar panels on a hillside under morning sunlight.';
        $GLOBALS['REMOTE_RESPONSES']['https://api.openai.com/v1/chat/completions'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'choices' => [
                    [
                        'message' => [
                            'role'    => 'assistant',
                            'content' => $expected_prompt,
                        ],
                    ],
                ],
            ] ),
        ];

        $client = new PressHub_AI_API_Client();
        $prompt = $client->analyze_article_for_image_prompt( 'Cyprus inaugurates a new solar power facility in Nicosia.', 'New Solar Park Opens' );

        $this->assert( ! is_wp_error( $prompt ), 'Prompt analysis should not return WP_Error' );
        $this->assert( $prompt === $expected_prompt, "Expected prompt '{$expected_prompt}', got '" . ( is_wp_error( $prompt ) ? $prompt->get_error_message() : $prompt ) . "'" );
    }

    public function test_generate_featured_image_gemini(): void {
        $this->reset_env();

        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'gemini-img',
            'type'          => 'gemini',
            'name'          => 'Gemini Image',
            'api_key'       => 'fake-gemini-key',
            'default_model' => 'gemini-3.1-flash-image',
            'enabled'       => true,
        ] );

        $fake_image_b64 = base64_encode( 'FAKE_JPEG_IMAGE_DATA_GEMINI' );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_generation_provider'] = 'gemini-img';
        $GLOBALS['SIDELOAD_RETURN_ID']                                      = 777;

        $GLOBALS['REMOTE_RESPONSES']['https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image:generateContent'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'inlineData' => [
                                        'mimeType' => 'image/jpeg',
                                        'data'     => $fake_image_b64,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ] ),
        ];

        $client = new PressHub_AI_API_Client();
        $result = $client->generate_featured_image( 'A realistic photojournalistic image of a parliament building.', '16:9', 10 );

        $this->assert( ! is_wp_error( $result ), 'Gemini image generation should not return WP_Error' );
        $this->assert( isset( $result['id'] ) && 777 === $result['id'], 'Sideloaded attachment ID should be 777' );
        $this->assert( isset( $result['url'] ) && strpos( $result['url'], '777' ) !== false, 'URL should reference attachment 777' );
        $this->assert( get_post_meta( 10, '_presshub_ai_image_prompt', true ) === 'A realistic photojournalistic image of a parliament building.', 'Post meta _presshub_ai_image_prompt should be saved' );
        $this->assert( get_post_meta( 777, '_presshub_ai_image_prompt', true ) === 'A realistic photojournalistic image of a parliament building.', 'Attachment meta _presshub_ai_image_prompt should be saved' );
    }

    public function test_generate_featured_image_openai(): void {
        $this->reset_env();

        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-img',
            'type'          => 'openai',
            'name'          => 'OpenAI Image',
            'api_key'       => 'fake-openai-key',
            'default_model' => 'dall-e-3',
            'enabled'       => true,
        ] );

        $fake_image_b64 = base64_encode( 'FAKE_PNG_IMAGE_DATA_OPENAI' );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_generation_provider'] = 'openai-img';
        $GLOBALS['SIDELOAD_RETURN_ID']                                      = 888;

        $GLOBALS['REMOTE_RESPONSES']['https://api.openai.com/v1/images/generations'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'data' => [
                    [
                        'b64_json' => $fake_image_b64,
                    ],
                ],
            ] ),
        ];

        $client = new PressHub_AI_API_Client();
        $result = $client->generate_featured_image( 'A photojournalistic view of harbor cranes at sunset.', '16:9', 20 );

        $this->assert( ! is_wp_error( $result ), 'OpenAI image generation should not return WP_Error' );
        $this->assert( isset( $result['id'] ) && 888 === $result['id'], 'Sideloaded attachment ID should be 888' );
        $this->assert( get_post_meta( 20, '_presshub_ai_image_prompt', true ) === 'A photojournalistic view of harbor cranes at sunset.', 'Post meta should be saved' );
    }

    public function test_briefing_curator_auto_image_generation(): void {
        $this->reset_env();

        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-briefing',
            'type'          => 'openai',
            'name'          => 'OpenAI Briefing',
            'api_key'       => 'test-key',
            'default_model' => 'gpt-4o',
            'enabled'       => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-img-gen',
            'type'          => 'openai',
            'name'          => 'OpenAI Image Gen',
            'api_key'       => 'test-key',
            'default_model' => 'dall-e-3',
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_feature_enabled']          = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_featured_image_source'] = 'auto_generate';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_prompt_provider']         = 'openai-briefing';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_generation_provider']     = 'openai-img-gen';
        $GLOBALS['SIDELOAD_RETURN_ID']                                         = 999;

        // Mock chat completion for prompt
        $GLOBALS['REMOTE_RESPONSES']['https://api.openai.com/v1/chat/completions'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'choices' => [ [ 'message' => [ 'role' => 'assistant', 'content' => 'Editorial photo of financial district.' ] ] ],
            ] ),
        ];
        // Mock image generation
        $GLOBALS['REMOTE_RESPONSES']['https://api.openai.com/v1/images/generations'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'data' => [ [ 'b64_json' => base64_encode( 'AUTO_IMG_DATA' ) ] ],
            ] ),
        ];

        $curator = new PressHub_AI_News_Curator();
        $post_id = $curator->create_wordpress_post( 'Market briefing content for today...', '2026-10-18', 'Morning Finance Brief', 'morning' );

        $this->assert( ! is_wp_error( $post_id ), 'Briefing post creation should succeed' );
        $this->assert( get_post_thumbnail_id( $post_id ) === 999, 'Briefing post should receive auto-generated thumbnail ID 999' );
    }

    public function test_briefing_curator_auto_image_fallback_to_pool(): void {
        $this->reset_env();

        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-fail',
            'type'          => 'openai',
            'name'          => 'OpenAI Fail',
            'api_key'       => 'test-key',
            'default_model' => 'gpt-4o',
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_feature_enabled']             = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_featured_image_source']    = 'auto_generate';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_featured_image_ids_morning'] = '555, 556';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_prompt_provider']            = 'openai-fail';
        $GLOBALS['REMOTE_RESPONSES']['https://api.openai.com/v1/chat/completions'] = [
            'response' => [ 'code' => 500 ],
            'body'     => 'Internal Server Error',
        ];

        $curator = new PressHub_AI_News_Curator();
        $post_id = $curator->create_wordpress_post( 'Story content...', '2026-10-18', 'Headline', 'morning' );

        $this->assert( ! is_wp_error( $post_id ), 'Briefing post creation should still succeed on image error' );
        $thumb_id = get_post_thumbnail_id( $post_id );
        $this->assert( in_array( $thumb_id, [ 555, 556 ], true ), "Expected thumbnail from pool [555, 556], got {$thumb_id}" );
    }

    public function test_podcast_auto_image_generation(): void {
        $this->reset_env();

        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-podcast',
            'type'          => 'openai',
            'name'          => 'OpenAI Podcast',
            'api_key'       => 'test-key',
            'default_model' => 'gpt-4o',
            'enabled'       => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-img-podcast',
            'type'          => 'openai',
            'name'          => 'OpenAI Image Gen',
            'api_key'       => 'test-key',
            'default_model' => 'dall-e-3',
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_feature_enabled']                    = 1;
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_featured_image_source']   = 'auto_generate';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_prompt_provider']                   = 'openai-podcast';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_generation_provider']               = 'openai-img-podcast';
        $GLOBALS['SIDELOAD_RETURN_ID']                                                 = 789;

        // Mock chat completion for prompt
        $GLOBALS['REMOTE_RESPONSES']['https://api.openai.com/v1/chat/completions'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'choices' => [ [ 'message' => [ 'role' => 'assistant', 'content' => 'Editorial photo of radio studio.' ] ] ],
            ] ),
        ];
        // Mock image generation
        $GLOBALS['REMOTE_RESPONSES']['https://api.openai.com/v1/images/generations'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'data' => [ [ 'b64_json' => base64_encode( 'PODCAST_IMG_DATA' ) ] ],
            ] ),
        ];

        $synth = new PressHub_AI_Audio_Synthesizer();
        $post_id = $synth->create_podcast_post( '2026-10-18', 123, 'http://example.test/audio.mp3', 'SPEAKER 1: Good morning...', 'morning' );

        $this->assert( ! is_wp_error( $post_id ), 'Podcast post creation should succeed' );
        $this->assert( get_post_thumbnail_id( $post_id ) === 789, 'Podcast post should receive auto-generated thumbnail ID 789' );
    }

    public function test_ajax_handlers(): void {
        $this->reset_env();

        // 1. Capability check on generate_image_prompt
        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $_POST = [
            'nonce'   => 'test_nonce',
            'content' => 'Some test content',
        ];

        $ajax = new PressHub_AI_Ajax_Handlers();
        $threw_or_exited = false;
        try {
            $ajax->generate_image_prompt();
        } catch ( Exception $e ) {
            $threw_or_exited = true;
        }
        $this->assert( $threw_or_exited || ! empty( $GLOBALS['LAST_JSON_ERROR'] ), 'Unprivileged user should be denied generate_image_prompt' );

        // 2. set_featured_image assigns thumbnail and post meta
        $this->reset_env();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $_POST = [
            'nonce'         => 'test_nonce',
            'post_id'       => 15,
            'attachment_id' => 321,
            'prompt'        => 'Custom prompt text',
        ];

        try {
            $ajax->set_featured_image();
        } catch ( Throwable $e ) {
        }

        $this->assert( get_post_thumbnail_id( 15 ) === 321, 'set_featured_image should set thumbnail to 321' );
        $this->assert( get_post_meta( 15, '_presshub_ai_image_prompt', true ) === 'Custom prompt text', 'Prompt should be saved in post meta' );
    }

    public function test_image_generation_model_picked_from_provider_record(): void {
        $this->reset_env();

        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'gemini-custom-img',
            'type'          => 'gemini',
            'name'          => 'Gemini Custom Image',
            'api_key'       => 'test-key',
            'default_model' => 'gemini-3.1-flash-lite-image',
            'enabled'       => true,
        ] );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_generation_provider'] = 'gemini-custom-img';

        $client = new PressHub_AI_API_Client();
        $config = $client->resolve_module_config( 'image_generation' );

        $this->assert( 'gemini-3.1-flash-lite-image' === $config['model'], 'Model must resolve directly from provider default_model' );
        $this->assert( 'provider_store_default:gemini-custom-img' === $config['model_source'], 'Model source must indicate provider store default' );
    }
}

$test = new FeaturedImageGeneratorTest();
$test->run();
