<?php
/**
 * Featured Image Studio prompt templates:
 * - built-in templates (Editorial photo follows the Settings analysis prompt)
 * - saving, duplicate names, deleting, built-ins protected
 * - {title}/{content} rendering, article appended when {content} is missing
 * - the chosen template and its system prompt reach the provider call
 * - AJAX save/delete capability rules and the metabox dropdown
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-prompt-loader.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-markdown.php';
require_once __DIR__ . '/../includes/class-image-prompt-templates.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-metaboxes.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';
require_once __DIR__ . '/../includes/class-settings-render.php';

class ImagePromptTemplatesTest {
    private $failures = [];

    public function run(): void {
        $this->test_builtins();
        $this->test_add_and_delete();
        $this->test_render();
        $this->test_template_reaches_provider();
        $this->test_ajax_save_and_delete();
        $this->test_metabox_dropdown();
        $this->test_update_resolve_and_in_use();
        $this->test_ajax_update();
        $this->test_briefing_template_setting();
        $this->test_settings_manager();

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
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'edit_others_posts' ];
        $GLOBALS['CURRENT_USER_ID']   = 7;
        $GLOBALS['NONCE_VALID']       = true;
        $GLOBALS['JSON_RESPONSES']    = [];
        $GLOBALS['CAPTURED_REQUESTS'] = [];
        $_POST                        = [];
    }

    private function call_ajax( string $method ): array {
        $GLOBALS['JSON_RESPONSES'] = [];
        $ajax = new PressHub_AI_Ajax_Handlers();
        try {
            $ajax->$method();
        } catch ( Throwable $e ) {
        }
        return end( $GLOBALS['JSON_RESPONSES'] ) ?: [];
    }

    public function test_builtins(): void {
        $this->reset_env();
        $builtins = PressHub_AI_Image_Prompt_Templates::get_builtin_templates();
        $ids      = array_column( $builtins, 'id' );

        $this->assert( count( $builtins ) === 5, 'Five built-in templates expected, got ' . count( $builtins ) );
        $this->assert( 'editorial_photo' === $ids[0], 'Editorial photo is first' );
        foreach ( [ 'editorial_illustration', 'editorial_cartoon', 'conceptual_still_life', 'landscape_aerial' ] as $id ) {
            $this->assert( in_array( $id, $ids, true ), "Built-in {$id} present" );
            $tpl = PressHub_AI_Image_Prompt_Templates::get( $id );
            $this->assert( $tpl && false !== strpos( $tpl['template'], '{content}' ) && false !== strpos( $tpl['template'], '{title}' ), "{$id} uses {title} and {content}" );
            $this->assert( $tpl && false !== stripos( $tpl['template'], 'NO text' ), "{$id} keeps the text prohibition" );
        }

        $photo = PressHub_AI_Image_Prompt_Templates::get( 'editorial_photo' );
        $this->assert( $photo['template'] === PressHub_AI_Settings_Storage::get_image_analysis_prompt(), 'Editorial photo uses the Settings default prompt' );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_analysis_prompt'] = 'ADMIN CUSTOM {title} {content}';
        $photo = PressHub_AI_Image_Prompt_Templates::get( 'editorial_photo' );
        $this->assert( 'ADMIN CUSTOM {title} {content}' === $photo['template'], 'Editorial photo follows an admin-edited Settings prompt' );
    }

    public function test_add_and_delete(): void {
        $this->reset_env();

        $saved = PressHub_AI_Image_Prompt_Templates::add( '  Moody night photo ', 'Night scene for {title}: {content}', 7 );
        $this->assert( is_array( $saved ), 'add() returns the template' );
        $this->assert( 'custom_moody_night_photo' === $saved['id'], 'id derived from name, got ' . ( $saved['id'] ?? '' ) );
        $this->assert( 'Moody night photo' === $saved['name'], 'name trimmed' );
        $this->assert( 7 === $saved['author'], 'author stored' );

        $all = PressHub_AI_Image_Prompt_Templates::get_all();
        $this->assert( 6 === count( $all ), 'Saved template is listed after built-ins' );
        $this->assert( 'custom_moody_night_photo' === end( $all )['id'], 'Saved template comes last' );

        $dup = PressHub_AI_Image_Prompt_Templates::add( 'moody NIGHT photo', 'x {content}', 7 );
        $this->assert( is_wp_error( $dup ) && 'duplicate_name' === $dup->get_error_code(), 'Duplicate name rejected' );
        $dup2 = PressHub_AI_Image_Prompt_Templates::add( 'Editorial cartoon', 'x', 7 );
        $this->assert( is_wp_error( $dup2 ), 'Built-in name cannot be reused' );
        $this->assert( is_wp_error( PressHub_AI_Image_Prompt_Templates::add( '', 'x', 7 ) ), 'Empty name rejected' );
        $this->assert( is_wp_error( PressHub_AI_Image_Prompt_Templates::add( 'Empty', '   ', 7 ) ), 'Empty text rejected' );

        $this->assert( is_wp_error( PressHub_AI_Image_Prompt_Templates::delete( 'editorial_cartoon' ) ), 'Built-in cannot be deleted' );
        $this->assert( true === PressHub_AI_Image_Prompt_Templates::delete( 'custom_moody_night_photo' ), 'Saved template deleted' );
        $this->assert( null === PressHub_AI_Image_Prompt_Templates::get( 'custom_moody_night_photo' ), 'Deleted template is gone' );
        $this->assert( is_wp_error( PressHub_AI_Image_Prompt_Templates::delete( 'custom_moody_night_photo' ) ), 'Deleting twice reports not found' );
    }

    public function test_render(): void {
        $out = PressHub_AI_Image_Prompt_Templates::render( 'T={title} C={content}', ' Hello ', ' Body ' );
        $this->assert( 'T=Hello C=Body' === $out, 'Placeholders replaced, got ' . $out );

        $out = PressHub_AI_Image_Prompt_Templates::render( 'Draw it as a watercolour.', 'Headline', 'Article body' );
        $this->assert( false !== strpos( $out, 'Draw it as a watercolour.' ) && false !== strpos( $out, 'Article body' ) && false !== strpos( $out, 'Headline' ), 'Article appended when template has no {content}' );
    }

    private function setup_provider(): void {
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-test',
            'type'          => 'openai',
            'name'          => 'OpenAI Test',
            'api_key'       => 'test-api-key',
            'default_model' => 'gpt-4o',
            'enabled'       => true,
        ] );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_prompt_provider'] = 'openai-test';
        $GLOBALS['CAPTURE_FILTER'] = function ( $existing, $req ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'role' => 'assistant', 'content' => 'A drawn harbour.' ] ] ] ] ),
            ];
        };
    }

    private function last_messages(): array {
        $req  = end( $GLOBALS['CAPTURED_REQUESTS'] );
        $body = json_decode( (string) ( $req[1]['body'] ?? '' ), true );
        $out  = [];
        foreach ( (array) ( $body['messages'] ?? [] ) as $m ) {
            $out[ $m['role'] ] = is_array( $m['content'] ) ? wp_json_encode( $m['content'] ) : (string) $m['content'];
        }
        return $out;
    }

    public function test_template_reaches_provider(): void {
        $this->reset_env();
        $this->setup_provider();

        $client = new PressHub_AI_API_Client();
        $cartoon = PressHub_AI_Image_Prompt_Templates::get( 'editorial_cartoon' );
        $result = $client->analyze_article_for_image_prompt( 'Port of Limassol expands.', 'Harbour news', $cartoon['template'] . "\nEXTRA EDIT", 'editorial_cartoon' );
        $this->assert( 'A drawn harbour.' === $result, 'Draft returned' );
        $msgs = $this->last_messages();
        $this->assert( false !== strpos( $msgs['user'] ?? '', 'editorial cartoonist' ), 'Cartoon template sent as the user prompt' );
        $this->assert( false !== strpos( $msgs['user'] ?? '', 'EXTRA EDIT' ), 'Edited template text sent' );
        $this->assert( false !== strpos( $msgs['user'] ?? '', 'Port of Limassol expands.' ), 'Article content filled in' );
        $this->assert( false === strpos( $msgs['system'] ?? '', 'realistic' ), 'Non-photo template does not force realism in the system prompt' );

        // No template: unchanged default behaviour.
        $client->analyze_article_for_image_prompt( 'Port of Limassol expands.', 'Harbour news' );
        $msgs = $this->last_messages();
        $this->assert( ( $msgs['system'] ?? '' ) === PressHub_AI_Image_Prompt_Templates::PHOTO_SYSTEM_PROMPT, 'Default draft keeps the photo system prompt' );
        $this->assert( false !== strpos( $msgs['user'] ?? '', 'editorial photographer' ), 'Default draft uses the Settings analysis prompt' );

        // AJAX draft with only a template id resolves the stored text and remembers it on the post.
        $_POST = [ 'nonce' => 'n', 'post_id' => 55, 'content' => 'Water cuts in Nicosia.', 'title' => 'Drought', 'template_id' => 'landscape_aerial' ];
        $resp = $this->call_ajax( 'generate_image_prompt' );
        $this->assert( ! empty( $resp['success'] ), 'AJAX draft succeeds' );
        $msgs = $this->last_messages();
        $this->assert( false !== strpos( $msgs['user'] ?? '', 'aerial photographer' ), 'AJAX draft uses the chosen template' );
        $this->assert( 'landscape_aerial' === get_post_meta( 55, '_presshub_ai_image_template', true ), 'Chosen template remembered on the post' );

        // An oversized inline template is rejected before any provider call.
        $before = count( $GLOBALS['CAPTURED_REQUESTS'] );
        $_POST  = [ 'nonce' => 'n', 'post_id' => 55, 'content' => 'Body', 'title' => 'T', 'template_id' => 'editorial_photo', 'template' => str_repeat( 'a', PressHub_AI_Image_Prompt_Templates::MAX_TEMPLATE_LEN + 1 ) ];
        $resp   = $this->call_ajax( 'generate_image_prompt' );
        $this->assert( empty( $resp['success'] ), 'Oversized template rejected' );
        $this->assert( count( $GLOBALS['CAPTURED_REQUESTS'] ) === $before, 'No provider call for an oversized template' );
        unset( $GLOBALS['CAPTURE_FILTER'] );
    }

    public function test_ajax_save_and_delete(): void {
        $this->reset_env();

        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $_POST = [ 'nonce' => 'n', 'name' => 'X', 'template' => 'Y {content}' ];
        $resp  = $this->call_ajax( 'save_image_prompt_template' );
        $this->assert( empty( $resp['success'] ), 'Save denied without edit_posts' );
        $this->assert( [] === PressHub_AI_Image_Prompt_Templates::get_custom_templates(), 'Nothing saved when denied' );

        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID']   = 7;
        $_POST = [ 'nonce' => 'n', 'name' => 'Warm sunset', 'template' => 'Sunset style {content}' ];
        $resp  = $this->call_ajax( 'save_image_prompt_template' );
        $this->assert( ! empty( $resp['success'] ) && 'custom_warm_sunset' === ( $resp['data']['template']['id'] ?? '' ), 'Author saves a template' );

        $_POST = [ 'nonce' => 'n', 'name' => 'Warm sunset', 'template' => 'Again {content}' ];
        $resp  = $this->call_ajax( 'save_image_prompt_template' );
        $this->assert( empty( $resp['success'] ), 'Duplicate name returns an error' );

        // Another author without edit_others_posts cannot delete it.
        $GLOBALS['CURRENT_USER_ID'] = 9;
        $_POST = [ 'nonce' => 'n', 'template_id' => 'custom_warm_sunset' ];
        $resp  = $this->call_ajax( 'delete_image_prompt_template' );
        $this->assert( empty( $resp['success'] ), 'Other author cannot delete' );
        $this->assert( null !== PressHub_AI_Image_Prompt_Templates::get( 'custom_warm_sunset' ), 'Template still there' );

        // An editor can.
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'edit_others_posts' ];
        $resp = $this->call_ajax( 'delete_image_prompt_template' );
        $this->assert( ! empty( $resp['success'] ), 'Editor deletes the template' );

        $_POST = [ 'nonce' => 'n', 'template_id' => 'editorial_photo' ];
        $resp  = $this->call_ajax( 'delete_image_prompt_template' );
        $this->assert( empty( $resp['success'] ), 'Built-in delete rejected via AJAX' );
    }

    public function test_metabox_dropdown(): void {
        $this->reset_env();
        PressHub_AI_Image_Prompt_Templates::add( 'My saved one', 'Mine {content}', 7 );
        update_post_meta( 12, '_presshub_ai_image_template', 'editorial_cartoon' );

        $post     = (object) [ 'ID' => 12 ];
        $metabox  = ( new ReflectionClass( 'PressHub_AI_Metaboxes' ) )->newInstanceWithoutConstructor();
        $method   = new ReflectionMethod( 'PressHub_AI_Metaboxes', 'render_image_template_picker' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( $metabox, $post );
        $html = ob_get_clean();

        $this->assert( false !== strpos( $html, 'id="presshub-ai-image-template-select"' ), 'Dropdown rendered' );
        $this->assert( (bool) preg_match( '/value="editorial_cartoon"\s+selected/', $html ), 'Remembered template preselected' );
        $this->assert( false !== strpos( $html, 'My saved one' ), 'Saved template listed' );
        $this->assert( false !== strpos( $html, 'editorial cartoonist' ), 'Template text box holds the selected template' );
        $this->assert( false !== strpos( $html, 'Save as new template' ), 'Save button rendered' );

        // Unknown remembered id falls back to the default.
        update_post_meta( 12, '_presshub_ai_image_template', 'custom_gone' );
        ob_start();
        $method->invoke( $metabox, $post );
        $html = ob_get_clean();
        $this->assert( (bool) preg_match( '/value="editorial_photo"\s+selected/', $html ), 'Missing template falls back to Editorial photo' );
    }
    public function test_update_resolve_and_in_use(): void {
        $this->reset_env();
        PressHub_AI_Image_Prompt_Templates::add( 'Night photo', 'Night {content}', 7 );
        PressHub_AI_Image_Prompt_Templates::add( 'Day photo', 'Day {content}', 7 );

        $up = PressHub_AI_Image_Prompt_Templates::update( 'custom_night_photo', 'Night photo, blue hour', 'Blue hour {content}' );
        $this->assert( is_array( $up ) && 'Night photo, blue hour' === $up['name'] && 'Blue hour {content}' === $up['template'], 'update() changes name and text' );
        $tpl = PressHub_AI_Image_Prompt_Templates::get( 'custom_night_photo' );
        $this->assert( $tpl && 'Blue hour {content}' === $tpl['template'] && 7 === $tpl['author'], 'Updated text stored, id and author kept' );
        $this->assert( 2 === count( PressHub_AI_Image_Prompt_Templates::get_custom_templates() ), 'update() adds no template' );

        $dup = PressHub_AI_Image_Prompt_Templates::update( 'custom_night_photo', 'day PHOTO', 'x {content}' );
        $this->assert( is_wp_error( $dup ) && 'duplicate_name' === $dup->get_error_code(), 'update() refuses another template\'s name' );
        $same = PressHub_AI_Image_Prompt_Templates::update( 'custom_night_photo', 'Night photo, blue hour', 'Again {content}' );
        $this->assert( is_array( $same ), 'update() may keep its own name' );
        $this->assert( is_wp_error( PressHub_AI_Image_Prompt_Templates::update( 'editorial_cartoon', 'Cartoon 2', 'x' ) ), 'Built-ins cannot be updated' );
        $this->assert( is_wp_error( PressHub_AI_Image_Prompt_Templates::update( 'custom_gone', 'Gone', 'x' ) ), 'Unknown id reports not found' );
        $this->assert( is_wp_error( PressHub_AI_Image_Prompt_Templates::update( 'custom_day_photo', 'Day photo', '  ' ) ), 'Empty text refused on update' );

        $r = PressHub_AI_Image_Prompt_Templates::resolve( 'custom_day_photo' );
        $this->assert( 'custom_day_photo' === $r['id'] && 'Day {content}' === $r['template'], 'resolve() returns the chosen template' );
        $r = PressHub_AI_Image_Prompt_Templates::resolve( 'custom_gone', 'test' );
        $this->assert( 'editorial_photo' === $r['id'] && '' !== $r['template'], 'resolve() falls back to Editorial photo' );

        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_image_template'] = 'custom_day_photo';
        $this->assert( [ 'Morning podcast' ] === PressHub_AI_Image_Prompt_Templates::used_by( 'custom_day_photo' ), 'used_by() names the briefing setting' );
        $del = PressHub_AI_Image_Prompt_Templates::delete( 'custom_day_photo' );
        $this->assert( is_wp_error( $del ) && 'in_use' === $del->get_error_code(), 'A template Daily Briefing uses cannot be deleted' );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_image_template'] = 'editorial_photo';
        $this->assert( true === PressHub_AI_Image_Prompt_Templates::delete( 'custom_day_photo' ), 'Deletable once no longer used' );
    }

    public function test_ajax_update(): void {
        $this->reset_env();
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID']   = 7;
        PressHub_AI_Image_Prompt_Templates::add( 'Warm sunset', 'Sunset {content}', 7 );

        $GLOBALS['CURRENT_USER_CAPS'] = [];
        $_POST = [ 'nonce' => 'n', 'template_id' => 'custom_warm_sunset', 'name' => 'Warm sunset', 'template' => 'Hacked {content}' ];
        $resp  = $this->call_ajax( 'update_image_prompt_template' );
        $this->assert( empty( $resp['success'] ), 'Update denied without edit_posts' );

        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts' ];
        $GLOBALS['CURRENT_USER_ID']   = 9;
        $resp = $this->call_ajax( 'update_image_prompt_template' );
        $this->assert( empty( $resp['success'] ), 'Another author cannot change it' );
        $this->assert( 'Sunset {content}' === PressHub_AI_Image_Prompt_Templates::get( 'custom_warm_sunset' )['template'], 'Text unchanged when denied' );

        $GLOBALS['CURRENT_USER_ID'] = 7;
        $_POST = [ 'nonce' => 'n', 'template_id' => 'custom_warm_sunset', 'name' => 'Golden sunset', 'template' => 'Golden {content}' ];
        $resp  = $this->call_ajax( 'update_image_prompt_template' );
        $this->assert( ! empty( $resp['success'] ) && 'Golden sunset' === ( $resp['data']['template']['name'] ?? '' ), 'Author changes their template' );

        $GLOBALS['CURRENT_USER_ID']   = 9;
        $GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_posts', 'edit_others_posts' ];
        $_POST = [ 'nonce' => 'n', 'template_id' => 'custom_warm_sunset', 'name' => 'Golden sunset', 'template' => 'Edited by editor {content}' ];
        $resp  = $this->call_ajax( 'update_image_prompt_template' );
        $this->assert( ! empty( $resp['success'] ), 'An editor can change anyone\'s template' );

        $_POST = [ 'nonce' => 'n', 'template_id' => 'editorial_cartoon', 'name' => 'Cartoon', 'template' => 'x {content}' ];
        $resp  = $this->call_ajax( 'update_image_prompt_template' );
        $this->assert( empty( $resp['success'] ), 'Built-in update refused via AJAX' );

        $GLOBALS['NONCE_VALID'] = false;
        $_POST = [ 'nonce' => 'bad', 'template_id' => 'custom_warm_sunset', 'name' => 'X', 'template' => 'Y {content}' ];
        $resp  = $this->call_ajax( 'update_image_prompt_template' );
        $this->assert( empty( $resp['success'] ), 'Update refused with a bad nonce' );
    }

    public function test_briefing_template_setting(): void {
        $this->reset_env();
        PressHub_AI_Image_Prompt_Templates::add( 'Night photo', 'Night {content}', 7 );
        foreach ( [ 'briefing_morning', 'briefing_noon', 'podcast_morning', 'podcast_noon', 'social_trending' ] as $kind ) {
            $this->assert( 'editorial_photo' === PressHub_AI_Settings_Storage::get_auto_image_template( $kind ), "Default {$kind} template is Editorial photo" );
        }
        $this->assert( 5 === count( PressHub_AI_Settings_Storage::auto_image_template_options() ), 'Five automatic post types' );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_noon_image_template']         = 'editorial_cartoon';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_noon_podcast_image_template'] = 'custom_night_photo';
        $this->assert( 'editorial_cartoon' === PressHub_AI_Settings_Storage::get_briefing_edition_image_template( 'briefing', 'noon' ), 'Noon briefing reads its own setting' );
        $this->assert( 'editorial_photo' === PressHub_AI_Settings_Storage::get_briefing_edition_image_template( 'briefing', 'morning' ), 'Morning briefing reads its own setting' );
        $this->assert( 'custom_night_photo' === PressHub_AI_Settings_Storage::get_briefing_edition_image_template( 'podcast', 'noon' ), 'Noon podcast reads its own setting' );
        $this->assert( 'editorial_cartoon' === PressHub_AI_Settings_Storage::sanitize_briefing_image_template( 'editorial_cartoon' ), 'Built-in id kept' );
        $this->assert( 'custom_night_photo' === PressHub_AI_Settings_Storage::sanitize_briefing_podcast_image_template( 'custom_night_photo' ), 'Saved id kept' );
        $this->assert( 'editorial_photo' === PressHub_AI_Settings_Storage::sanitize_briefing_image_template( 'custom_nope' ), 'Unknown id becomes the default' );
        $this->assert( 'editorial_photo' === PressHub_AI_Settings_Storage::sanitize_briefing_image_template( '<script>' ), 'Junk becomes the default' );
        $this->assert( 'top_post' === PressHub_AI_Settings_Storage::get_social_trending_image_source(), 'Trending keeps the top post picture by default' );
        $this->assert( 'top_post' === PressHub_AI_Settings_Storage::sanitize_social_trending_image_source( 'nope' ), 'Unknown trending source becomes the default' );
        $this->assert( [ 'Noon podcast' ] === PressHub_AI_Image_Prompt_Templates::used_by( 'custom_night_photo' ), 'Noon podcast use blocks deletion' );

        $render = ( new ReflectionClass( 'PressHub_AI_Settings_Render' ) )->newInstanceWithoutConstructor();
        ob_start();
        $render->render_auto_images_table();
        $html = ob_get_clean();
        foreach ( PressHub_AI_Settings_Storage::auto_image_template_options() as $option ) {
            $this->assert( false !== strpos( $html, 'name="' . $option . '"' ), "Table posts {$option}" );
            $this->assert( false !== strpos( $html, 'label for="' . $option . '"' ), "{$option} has a label" );
        }
        foreach ( [ 'Morning briefing', 'Noon briefing', 'Morning podcast', 'Noon podcast', 'Trending in Cyprus' ] as $label ) {
            $this->assert( false !== strpos( $html, $label ), "Row {$label}" );
        }
        $this->assert( (bool) preg_match( '/name="presshub_ai_briefing_noon_podcast_image_template".*?value="custom_night_photo"\s+selected/s', $html ), 'Stored template selected' );
        $this->assert( false !== strpos( $html, 'Featured Image Pool, so no AI image is made' ), 'Shows that the pool is the image source' );
        $this->assert( false !== strpos( $html, 'Generate featured images is off' ), 'Warns that the feature is off' );
        $this->assert( false === strpos( $html, 'Default: Editorial photo' ), 'No repeated default in the intro' );
        $this->assert( false !== strpos( $html, 'aria-label="Change in Daily Briefing: image source for Noon podcast"' ), 'Each source link has its own name' );
        $this->assert( false !== strpos( $html, '#presshub_ai_briefing_podcast_featured_image_source' ) && false !== strpos( $html, '#presshub_ai_social_trending_image_source' ), 'Links go to the source fields' );
        $this->assert( false !== strpos( $html, 'optgroup data-group="saved"' ), 'Saved group marked for the store editor' );

        // AI chosen but the feature off: the row says so in words, not only by colour (rule 5.2).
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_featured_image_source'] = 'auto_generate';
        ob_start();
        $render->render_auto_images_table();
        $off = ob_get_clean();
        $this->assert( false !== strpos( $off, 'Featured Image Pool: AI is chosen, but Generate featured images is off' ) && false === strpos( $off, 'AI image with this template' ), 'Off state named in each row' );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_feature_enabled'] = 1;
        ob_start();
        $render->render_auto_images_table();
        $on = ob_get_clean();
        $this->assert( 2 === substr_count( $on, 'AI image with this template' ) && false === strpos( $on, 'Generate featured images is off' ), 'On: the podcast rows make AI images' );
        $this->assert( false === strpos( $html, 'style=' ), 'No inline styles' );

        // Daily Briefing shows the templates read-only, linking to their home.
        $line = ( new ReflectionMethod( 'PressHub_AI_Settings_Render', 'auto_image_templates_line' ) );
        $line->setAccessible( true );
        $out = $line->invoke( $render, [ 'briefing_morning', 'podcast_noon' ], '', 'Image template:' );
        $this->assert( false !== strpos( $out, 'Morning briefing: Editorial photo' ) && false !== strpos( $out, 'Noon podcast: Night photo' ), 'Read-only line names each template' );
        $this->assert( false !== strpos( $out, 'section=writing-qa' ) && false === strpos( $out, '<select' ), 'Read-only line links to Writing & QA, no field' );
        $this->assert( 0 === strpos( $out, '<p class="presshub-model-line">Image template: ' ) && false !== strpos( $out, '#presshub-auto-images-heading' ), 'Lead-in and link to the table' );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_image_template'] = 'custom_gone';
        $out = $line->invoke( $render, [ 'briefing_morning' ] );
        $this->assert( false !== strpos( $out, 'Missing template (Editorial photo is used)' ), 'Missing template shown without resolve()' );
    }

    public function test_settings_manager(): void {
        $this->reset_env();
        PressHub_AI_Image_Prompt_Templates::add( 'Night photo', 'Night <b>{content}</b>', 7 );
        $render = ( new ReflectionClass( 'PressHub_AI_Settings_Render' ) )->newInstanceWithoutConstructor();
        ob_start();
        $render->render_image_prompt_templates_manager();
        $html = ob_get_clean();
        $this->assert( false !== strpos( $html, 'id="presshub-image-templates-manager"' ), 'Manager rendered' );
        $this->assert( false !== strpos( $html, 'Night photo' ) && false !== strpos( $html, 'Editorial cartoon' ), 'Built-in and saved templates listed' );
        $this->assert( false !== strpos( $html, 'Add template' ), 'Add button rendered' );
        $this->assert( false === strpos( $html, 'style=' ), 'No inline styles in the manager' );
        $this->assert( false === strpos( $html, '<b>{content}</b>' ), 'Template text escaped in the payload' );
        // The editor's fields must never be posted by the section form.
        $this->assert( ! preg_match( '/<(input|textarea|select)[^>]*\sname=/', $html ), 'Manager fields carry no name' );
        $this->assert( 2 === substr_count( $html, 'presshub-no-dirty' ), 'Manager fields are left out of unsaved-changes tracking' );
    }
}

$test = new ImagePromptTemplatesTest();
$test->run();
