<?php
/**
 * Settings → AI models (#236, settings overhaul phase 4).
 *
 * Verifies:
 *  1. The catalogue: kinds by list and by pattern, wrong kind refused,
 *     unknown ID warned, known ID fine.
 *  2. The table: one row per task (Social rows only with the Social
 *     capability), the default model, "Uses now", the curated list per
 *     provider plus "Other model ID…", a stored value outside the list kept
 *     as "Other", one expander per row, no option names on screen.
 *  3. Upgrade: every stored provider and model value shows in its row unchanged.
 *  4. Save: a wrong-type model is refused with a field error and nothing is
 *     written; an unknown ID saves with a warning; an unchanged wrong value
 *     does not block other rows; a provider whose default model is the wrong
 *     type is refused; every other save path keeps the stored value.
 *  5. Save isolation: posting one row writes that row's options only.
 *  6. Editorial QA: same as Co-author until its own provider or model is set,
 *     always with the Co-author parameters.
 *  7. The features show a read-only "Model: …" line linking to their row and
 *     no longer render the model fields.
 *  8. The error summary wiring in the scripts.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) { $GLOBALS['DEACTIVATION_HOOKS'][] = $callback; }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';
require_once __DIR__ . '/settings-sections-helper.php';

$failures = [];
$check    = static function ( bool $ok, string $msg ) use ( &$failures ) {
    if ( ! $ok ) {
        $failures[] = $msg;
    }
};

$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files', 'manage_options' ];
$GLOBALS['CURRENT_USER_ID']   = 1;
( new PressHub_AI_Settings() )->register_settings();
PressHub_Social_Settings::get_instance()->register_settings();

function mt_ajax( callable $fn, array $post ): ?array {
    $_POST                     = array_merge( [ 'nonce' => 'valid_nonce' ], $post );
    $_REQUEST                  = $_POST;
    $GLOBALS['NONCE_VALID']    = true;
    $GLOBALS['JSON_RESPONSES'] = [];
    try {
        $fn();
    } catch ( Throwable $t ) {
        // wp_send_json_* stubs throw to end the request.
    }
    $_POST = [];
    $r     = end( $GLOBALS['JSON_RESPONSES'] );
    return is_array( $r ) ? $r : null;
}

$providers = [
    [ 'id' => 'gemini-main', 'type' => 'gemini', 'name' => 'Gemini', 'enabled' => true, 'api_key' => 'AIzaTESTKEY0000000000000000000000001', 'default_model' => 'gemini-3.1-flash', 'available_models' => [ 'gemini-3.1-flash', 'gemini-3.1-flash-tts-preview' ] ],
    [ 'id' => 'gemini-voice', 'type' => 'gemini', 'name' => 'Gemini voice', 'enabled' => true, 'api_key' => 'AIzaTESTKEY0000000000000000000000002', 'default_model' => 'gemini-2.5-flash-preview-tts', 'available_models' => [ 'gemini-2.5-flash-preview-tts' ] ],
    [ 'id' => 'openai-main', 'type' => 'openai', 'name' => 'OpenAI', 'enabled' => true, 'api_key' => 'sk-test-000000000000000000000000000000', 'default_model' => 'gpt-4o', 'available_models' => [ 'gpt-4o' ] ],
];
$base_store = static function ( array $extra = [] ) use ( $providers ): void {
    $GLOBALS['OPTIONS_STORE'] = array_merge( [ 'presshub_ai_configured_providers' => $providers ], $extra );
};

// ---------------------------------------------------------------------
// 1. The catalogue.
// ---------------------------------------------------------------------
$check( 'speech' === PressHub_Model_Catalog::kind_of( 'gemini-2.5-flash-preview-tts' ), 'A Gemini TTS model is a speech model.' );
$check( 'speech' === PressHub_Model_Catalog::kind_of( 'models/gemini-9-flash-tts' ), 'A new *-tts model is a speech model by its name.' );
$check( 'image' === PressHub_Model_Catalog::kind_of( 'dall-e-3' ) && 'image' === PressHub_Model_Catalog::kind_of( 'imagen-5.0-generate' ), 'DALL-E and Imagen are image models.' );
$check( 'music' === PressHub_Model_Catalog::kind_of( 'lyria-9' ), 'Lyria is a music model.' );
$check( 'text' === PressHub_Model_Catalog::kind_of( 'gemini-3.1-pro' ), 'gemini-3.1-pro is a text model.' );
$check( null === PressHub_Model_Catalog::kind_of( 'brand-new-model-x' ), 'Nothing is known about an unlisted ID without a telling name.' );
$c = PressHub_Model_Catalog::check( 'text', 'gemini-2.5-flash-preview-tts' );
$check( 'blocked' === $c['status'] && false !== strpos( $c['message'], 'speech model' ) && false !== strpos( $c['message'], 'text model' ), 'A speech model on a text task is refused, saying both kinds: ' . json_encode( $c ) );
$c = PressHub_Model_Catalog::check( 'text', 'brand-new-model-x' );
$check( 'warning' === $c['status'] && false !== strpos( $c['message'], "doesn't know" ), 'An unknown ID is accepted with a warning.' );
$check( 'ok' === PressHub_Model_Catalog::check( 'text', 'gpt-4o' )['status'], 'A known text model on a text task is fine.' );
$check( 'ok' === PressHub_Model_Catalog::check( 'text', 'my-custom', [ 'available_models' => [ 'my-custom' ] ] )['status'], 'A model the provider lists is known.' );
$check( in_array( 'gemini-3.1-flash', PressHub_Model_Catalog::models_for( $providers[0], 'text' ), true ) && ! in_array( 'gemini-3.1-flash-tts-preview', PressHub_Model_Catalog::models_for( $providers[0], 'text' ), true ), 'A provider offers only models of the row\'s kind.' );

// ---------------------------------------------------------------------
// 2. The table.
// ---------------------------------------------------------------------
$base_store( [ 'presshub_social_ai_model' => 'my-finetune-1', 'presshub_ai_copilot_model' => 'gpt-4o', 'presshub_ai_copilot_provider' => 'openai-main' ] );
$html   = presshub_test_render_section( 'ai-models' );
$models = presshub_test_section_pane( $html, 'ai-models' );
$check( '' !== $models, 'The AI models pane renders.' );
foreach ( array_keys( PressHub_Model_Tasks::rows() ) as $id ) {
    $check( false !== strpos( $models, 'id="' . PressHub_Model_Tasks::anchor( $id ) . '"' ), "The table has a row for {$id}." );
}
$check( false !== strpos( $models, 'Default model: Gemini · gemini-3.1-flash.' ), 'The default model is named.' );
$check( false !== strpos( $models, 'Uses now: Gemini · gemini-3.1-flash' ), 'A row on Default shows the model it resolves to.' );
$check( false !== strpos( $models, 'Uses now: OpenAI · gpt-4o' ), 'A row with its own provider shows it.' );
$check( false !== strpos( $models, 'Other model ID…' ), 'Each model list ends with "Other model ID…".' );
$check( (bool) preg_match( '/id="presshub_social_ai_model-choice"[\s\S]*?<option value="__other" selected="selected">/', $models ), 'A stored model outside the list shows as Other.' );
$check( false !== strpos( $models, 'name="presshub_social_ai_model" id="presshub_social_ai_model" value="my-finetune-1"' ), 'A stored model outside the list is kept unchanged.' );
$check( (bool) preg_match( '/id="presshub_ai_copilot_model-choice"[\s\S]*?<optgroup label="OpenAI" data-provider="openai-main">[\s\S]*?<option value="gpt-4o" selected="selected">/', $models ), 'A listed model is selected in its provider\'s group.' );
$coauthor_list = preg_match( '/<select id="presshub_ai_coauthor_model-choice".*?<\/select>/s', $models, $m ) ? $m[0] : '';
$check( '' !== $coauthor_list && false === strpos( $coauthor_list, 'tts' ), 'A text row lists no speech models.' );
$speech_list = preg_match( '/<select id="presshub_ai_briefing_tts_model-choice".*?<\/select>/s', $models, $m ) ? $m[0] : '';
$check( false !== strpos( $speech_list, 'gemini-3.1-flash-tts-preview' ) && false === strpos( $speech_list, '"gpt-4o"' ), 'The Speech row lists speech models only.' );
$check( preg_match_all( '/<details class="presshub-model-params">/', $models ) === count( array_filter( PressHub_Model_Tasks::rows(), static function ( $r ) { return ! empty( $r['params'] ); } ) ), 'One expander per row that has parameters.' );
foreach ( PressHub_Model_Tasks::options() as $opt ) {
    $check( false !== strpos( $models, 'name="' . $opt . '"' ), "{$opt} has a field in the table." );
    $check( 'ai-models' === PressHub_Settings_Sections::section_of( $opt ), "{$opt} belongs to AI models." );
    $e = PressHub_Settings_Registry::get( $opt );
    $check( is_array( $e ) && ! empty( $e['rendered'] ), "{$opt} is a registered, rendered option." );
}
$visible_text = html_entity_decode( strip_tags( (string) preg_replace( '/<(script|style)\b.*?<\/\1>/s', '', $models ) ) );
$check( false === strpos( $visible_text, 'presshub_' ), 'No option name is shown on AI models.' );
$check( false === strpos( $models, 'name="presshub_ai_briefing_tts_model-choice"' ) && false === strpos( $models, 'name="presshub_ai_coauthor_model-choice"' ), 'The model lists are not posted; the text field holds the value.' );

// Without the Social capability: no Social rows.
add_filter( 'presshub_social_settings_cap', static function () { return 'presshub_social_only'; } );
$no_social = PressHub_Model_Tasks::render_table();
$check( false === strpos( $no_social, 'id="model-social-copy"' ) && false === strpos( $no_social, 'id="model-music"' ) && false !== strpos( $no_social, 'id="model-coauthor"' ), 'Social rows need the Social capability.' );
remove_all_filters( 'presshub_social_settings_cap' );

// A stored wrong value is shown on its row as an error.
$base_store( [ 'presshub_social_ai_model' => 'gemini-2.5-flash-preview-tts' ] );
$models = presshub_test_section_pane( presshub_test_render_section( 'ai-models' ), 'ai-models' );
$check( false !== strpos( $models, 'id="presshub_social_ai_model-model-error"' ) && false !== strpos( $models, 'Error: gemini-2.5-flash-preview-tts is a speech model. Pick a text model.' ), 'A stored speech model on Social copy shows the error on its row.' );

// ---------------------------------------------------------------------
// 3. Upgrade: stored values from 2.36.x show in the right rows unchanged.
// ---------------------------------------------------------------------
$stored = [
    'presshub_ai_coauthor_provider'             => 'openai-main',
    'presshub_ai_coauthor_model'                => 'gpt-4o-mini',
    'presshub_ai_copilot_provider'              => 'gemini-main',
    'presshub_ai_copilot_model'                 => 'gemini-2.0-flash',
    'presshub_ai_briefing_text_provider'        => 'gemini-main',
    'presshub_ai_briefing_text_model'           => 'gemini-1.5-pro-latest',
    'presshub_ai_briefing_podcast_provider'     => 'openai-main',
    'presshub_ai_briefing_podcast_model'        => 'o1',
    'presshub_ai_briefing_memory_provider'      => 'gemini-main',
    'presshub_ai_briefing_memory_model'         => 'gemini-2.5-flash',
    'presshub_ai_image_prompt_provider'         => 'openai-main',
    'presshub_ai_image_prompt_model'            => 'gpt-4o',
    'presshub_ai_image_generation_provider'     => 'openai-main',
    'presshub_social_ai_provider'               => 'gemini-main',
    'presshub_social_ai_model'                  => 'gemini-2.5-flash',
    'presshub_ai_briefing_podcast_tts_provider' => 'gemini-voice',
    'presshub_ai_briefing_tts_engine'           => 'google_cloud',
    'presshub_ai_briefing_tts_model'            => 'gemini-2.5-flash-preview-tts',
    'presshub_social_tiktok_shorts_music_model' => 'lyria-3.5',
    'presshub_ai_coauthor_temperature'          => '0.4',
    'presshub_ai_briefing_text_timeout'         => 420,
    'presshub_social_ai_max_tokens'             => 3000,
];
$base_store( $stored );
$models = presshub_test_section_pane( presshub_test_render_section( 'ai-models' ), 'ai-models' );
$row_of = static function ( string $id ) use ( $models ): string {
    $start = strpos( $models, '<tr id="' . PressHub_Model_Tasks::anchor( $id ) . '"' );
    if ( false === $start ) {
        return '';
    }
    $end = strpos( $models, '<tr id="model-', $start + 10 );
    return substr( $models, $start, false === $end ? null : $end - $start );
};
foreach ( PressHub_Model_Tasks::rows() as $id => $row ) {
    foreach ( PressHub_Model_Tasks::options( $row ) as $opt ) {
        if ( ! isset( $stored[ $opt ] ) ) {
            continue;
        }
        $v    = (string) $stored[ $opt ];
        $html = $row_of( $id );
        $ok   = (bool) preg_match( '/name="' . preg_quote( $opt, '/' ) . '"[^>]*value="' . preg_quote( $v, '/' ) . '"/', $html )
            || (bool) preg_match( '/<select name="' . preg_quote( $opt, '/' ) . '"[^>]*>.*?<option value="' . preg_quote( $v, '/' ) . '"( selected="selected"| selected)/s', $html );
        $check( $ok, "Upgrade: {$opt} should show {$v} in the {$id} row." );
        $check( $stored[ $opt ] === get_option( $opt ), "Upgrade: rendering must not change {$opt}." );
    }
}

// ---------------------------------------------------------------------
// 4. Save: the type check.
// ---------------------------------------------------------------------
$ajax = new PressHub_AI_Ajax_Handlers();
$save = static function ( array $payload ) use ( $ajax ) {
    return mt_ajax( [ $ajax, 'save_settings_section' ], [ 'tab' => 'ai-models', 'payload_b64' => base64_encode( (string) json_encode( $payload ) ) ] );
};
$base_store( [ 'presshub_social_ai_model' => 'gemini-2.5-flash', 'presshub_ai_coauthor_model' => '' ] );
$r = $save( [ 'presshub_social_ai_model' => 'gemini-2.5-flash-preview-tts', 'presshub_ai_coauthor_model' => 'gpt-4o' ] );
$check( is_array( $r ) && false === $r['success'] && isset( $r['data']['field_errors']['presshub_social_ai_model'] ), 'A speech model on Social copy is refused with a field error: ' . json_encode( $r ) );
$check( 'gemini-2.5-flash' === get_option( 'presshub_social_ai_model' ) && '' === get_option( 'presshub_ai_coauthor_model' ), 'A refused save writes nothing, not even the valid rows.' );
$check( false !== strpos( (string) ( $r['data']['field_errors']['presshub_social_ai_model'] ?? '' ), 'speech model' ), 'The field error names the wrong kind.' );

$r = $save( [ 'presshub_social_ai_model' => 'brand-new-model-x' ] );
$check( is_array( $r ) && true === $r['success'] && 'brand-new-model-x' === get_option( 'presshub_social_ai_model' ), 'An unknown model ID saves.' );
$check( ! empty( $r['data']['model_warnings'] ) && false !== strpos( implode( ' ', $r['data']['model_warnings'] ), 'Social copy:' ), 'An unknown model ID comes back with a warning naming the row.' );
$check( isset( ( (array) ( $r['data']['model_warning_fields'] ?? [] ) )['presshub_social_ai_model'] ), 'The warning comes back keyed by its field.' );

$base_store( [ 'presshub_social_ai_model' => 'gemini-2.5-flash-preview-tts' ] );
$r = $save( [ 'presshub_social_ai_model' => 'gemini-2.5-flash-preview-tts', 'presshub_ai_copilot_model' => 'gpt-4o' ] );
$check( is_array( $r ) && true === $r['success'] && 'gpt-4o' === get_option( 'presshub_ai_copilot_model' ), 'An unchanged stored wrong value does not stop other rows from saving.' );

$base_store();
$r = $save( [ 'presshub_ai_briefing_text_provider' => 'gemini-voice', 'presshub_ai_briefing_text_model' => '' ] );
$check( is_array( $r ) && false === $r['success'] && isset( $r['data']['field_errors']['presshub_ai_briefing_text_provider'] ), 'A provider whose default model is a speech model is refused for the curator.' );
$r = $save( [ 'presshub_ai_briefing_text_provider' => 'gemini-voice', 'presshub_ai_briefing_text_model' => 'gemini-3.1-pro' ] );
$check( is_array( $r ) && true === $r['success'] && 'gemini-voice' === get_option( 'presshub_ai_briefing_text_provider' ), 'The same provider with a text model of its own saves.' );
$r = $save( [ 'presshub_ai_briefing_tts_model' => 'gpt-4o' ] );
$check( is_array( $r ) && false === $r['success'] && isset( $r['data']['field_errors']['presshub_ai_briefing_tts_model'] ), 'A text model on Speech is refused.' );
$r = $save( [ 'presshub_social_tiktok_shorts_music_model' => 'gemini-3.1-flash-image' ] );
$check( is_array( $r ) && false === $r['success'] && isset( $r['data']['field_errors']['presshub_social_tiktok_shorts_music_model'] ), 'An image model on Music is refused.' );

// Every other save path (options.php, transfer, code) keeps the stored value.
$base_store( [ 'presshub_social_ai_model' => 'gemini-2.5-flash' ] );
update_option( 'presshub_social_ai_model', 'gemini-2.5-flash-preview-tts' );
$check( 'gemini-2.5-flash' === get_option( 'presshub_social_ai_model' ), 'update_option() with a speech model keeps the stored Social copy model.' );
update_option( 'presshub_social_ai_model', 'gpt-4o' );
$check( 'gpt-4o' === get_option( 'presshub_social_ai_model' ), 'update_option() with a text model stores it.' );

// ---------------------------------------------------------------------
// 5. Save isolation: one row's fields write that row's options only.
// ---------------------------------------------------------------------
$base_store();
$GLOBALS['UPDATE_OPTION_CALLS'] = [];
$row_post = [
    'presshub_ai_briefing_text_provider'    => 'openai-main',
    'presshub_ai_briefing_text_model'       => 'gpt-4o',
    'presshub_ai_briefing_text_temperature' => '0.3',
    'presshub_ai_briefing_text_max_tokens'  => '8000',
    'presshub_ai_briefing_text_timeout'     => '200',
];
$r       = $save( $row_post );
$written = array_values( array_unique( array_map( static function ( $c ) { return $c[0]; }, $GLOBALS['UPDATE_OPTION_CALLS'] ) ) );
// Settings only: the logger keeps its own bookkeeping options.
$written = array_values( array_filter( $written, static function ( $k ) { return null !== PressHub_Settings_Registry::get( $k ); } ) );
sort( $written );
$expect = array_keys( $row_post );
sort( $expect );
$check( is_array( $r ) && true === $r['success'], 'The curator row saves.' );
$check( $written === $expect, 'Saving the curator row writes exactly its five options; wrote: ' . implode( ', ', $written ) );

// ---------------------------------------------------------------------
// 6. Editorial QA.
// ---------------------------------------------------------------------
$base_store( [ 'presshub_ai_coauthor_provider' => 'openai-main', 'presshub_ai_coauthor_model' => 'gpt-4o-mini', 'presshub_ai_coauthor_temperature' => '0.25', 'presshub_ai_coauthor_timeout' => 123 ] );
$qa = PressHub_AI_API_Client::resolve_module_config( 'qa', true );
$co = PressHub_AI_API_Client::resolve_module_config( 'coauthor', true );
$check( 'openai-main' === $qa['id'] && 'gpt-4o-mini' === $qa['model'], 'QA with nothing set runs the Co-author provider and model: ' . $qa['id'] . ' ' . $qa['model'] );
$check( $qa['temperature'] === $co['temperature'] && 123 === $qa['timeout'], 'QA uses the Co-author parameters.' );
update_option( 'presshub_ai_qa_model', 'o1' );
$qa = PressHub_AI_API_Client::resolve_module_config( 'qa', true );
$check( 'openai-main' === $qa['id'] && 'o1' === $qa['model'], 'QA with its own model keeps the Co-author provider.' );
update_option( 'presshub_ai_qa_provider', 'gemini-main' );
update_option( 'presshub_ai_qa_model', '' );
$qa = PressHub_AI_API_Client::resolve_module_config( 'qa', true );
$check( 'gemini-main' === $qa['id'] && 'gemini-3.1-flash' === $qa['model'] && 123 === $qa['timeout'], 'QA with its own provider runs that provider\'s default model, with the Co-author parameters.' );
$check( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-qa-reviewer.php' ), "new PressHub_AI_API_Client( 'qa' )" ), 'The QA reviewer resolves through the qa task.' );
$base_store();
$models = presshub_test_section_pane( presshub_test_render_section( 'ai-models' ), 'ai-models' );
$check( (bool) preg_match( '/<select name="presshub_ai_qa_provider"[^>]*>\s*<option value="" selected="selected">Same as Co-author<\/option>/', $models ), 'The QA row starts on "Same as Co-author".' );

// ---------------------------------------------------------------------
// 7. Features show a read-only model line and no model fields.
// ---------------------------------------------------------------------
$base_store( [ 'presshub_ai_briefing_text_provider' => 'gemini-main', 'presshub_ai_briefing_text_model' => 'gemini-3.1-pro' ] );
$features = [
    'writing-qa'          => [ 'coauthor', 'qa', 'image_prompt', 'image_generation' ],
    'daily-briefing'      => [ 'briefing_text', 'briefing_podcast', 'speech' ],
    'editorial-knowledge' => [ 'briefing_memory' ],
    'social-publishing'   => [ 'social_copy', 'music' ],
];
foreach ( $features as $section => $tasks ) {
    $pane = presshub_test_section_pane( presshub_test_render_section( $section ), $section );
    foreach ( $tasks as $task ) {
        $check( (bool) preg_match( '/<p class="presshub-model-line" data-model-task="' . $task . '">[^<]*<a href="[^"]*section=ai-models#' . preg_quote( PressHub_Model_Tasks::anchor( $task ), '/' ) . '">Change in AI models<\/a><\/p>/', $pane ), "{$section} shows the {$task} model line linking to its row." );
        foreach ( PressHub_Model_Tasks::options( PressHub_Model_Tasks::rows()[ $task ] ) as $opt ) {
            $check( false === strpos( $pane, 'name="' . $opt . '"' ), "{$section} no longer renders {$opt}." );
        }
    }
}
$daily = presshub_test_section_pane( presshub_test_render_section( 'daily-briefing' ), 'daily-briefing' );
$check( false !== strpos( $daily, 'Briefing curator model: Gemini · gemini-3.1-pro.' ), 'The curator line names the provider and model it uses.' );
$shorts = presshub_test_section_pane( presshub_test_render_section( 'social-publishing' ), 'social-publishing' );
$check( false !== strpos( $shorts, 'section=ai-models#model-speech' ), 'The Shorts narration intro links to the Speech row.' );
$check( 'ai-models' === PressHub_Settings_Sections::LEGACY_TARGETS['ai']['copilot'][0] && 'model-copilot' === PressHub_Settings_Sections::LEGACY_TARGETS['ai']['copilot'][1], 'The old Copilot tab lands on its row.' );

// ---------------------------------------------------------------------
// 7b. Review round 1: the stored model is never lost on load.
// ---------------------------------------------------------------------
// Two Gemini providers share models: the stored speech model is selected in
// the group of the provider the row runs on, not the first group that lists it.
$base_store( [ 'presshub_ai_briefing_podcast_tts_provider' => 'gemini-voice', 'presshub_ai_briefing_tts_model' => 'gemini-2.5-flash-preview-tts' ] );
$table  = PressHub_Model_Tasks::render_table();
$speech = preg_match( '/<select id="presshub_ai_briefing_tts_model-choice".*?<\/select>/s', $table, $m ) ? $m[0] : '';
$check( (bool) preg_match( '/<optgroup label="Gemini voice" data-provider="gemini-voice">(?:(?!<\/optgroup>).)*<option value="gemini-2.5-flash-preview-tts" selected="selected">/s', $speech ), 'The stored speech model is selected in its own provider\'s group.' );
$check( 1 === substr_count( $speech, 'selected="selected"' ), 'Exactly one option is selected.' );
// A stored model the active provider doesn't list shows as Other, value kept.
$base_store( [ 'presshub_ai_coauthor_provider' => 'gemini-main', 'presshub_ai_qa_model' => 'o1' ] );
$table = PressHub_Model_Tasks::render_table();
$check( (bool) preg_match( '/id="presshub_ai_qa_model-choice"[\s\S]*?<option value="__other" selected="selected">/', $table ) && false !== strpos( $table, 'name="presshub_ai_qa_model" id="presshub_ai_qa_model" value="o1"' ), 'A QA model from another provider shows as Other, unchanged.' );
$page_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/settings-page.js' );
preg_match( '/function filterGroups\(\) \{.*?\n        \}\n/s', $page_js, $fg );
$check( ! empty( $fg[0] ) && false === strpos( $fg[0], "input.value = ''" ) && false === strpos( $fg[0], 'input.value =' ), 'Filtering the list by provider never changes the stored model.' );

// The provider's own default model is a known model (no false warning).
$check( PressHub_Model_Catalog::is_known( 'llama-local-7b', [ 'default_model' => 'llama-local-7b' ] ), 'A provider\'s default model is known.' );
// Image generation offers only providers that can draw.
$base_store( [ 'presshub_ai_configured_providers' => array_merge( $providers, [ [ 'id' => 'claude-main', 'type' => 'anthropic', 'name' => 'Claude', 'enabled' => true, 'api_key' => 'sk-ant-test-0000000000000000000000', 'default_model' => 'claude-3-5-haiku-20241022' ] ] ) ] );
$table = PressHub_Model_Tasks::render_table();
$img   = preg_match( '/<select name="presshub_ai_image_generation_provider".*?<\/select>/s', $table, $m ) ? $m[0] : '';
$cop   = preg_match( '/<select name="presshub_ai_copilot_provider".*?<\/select>/s', $table, $m ) ? $m[0] : '';
$check( false === strpos( $img, 'claude-main' ) && false !== strpos( $img, 'openai-main' ) && false !== strpos( $cop, 'claude-main' ), 'Image generation lists Gemini and OpenAI providers only; text rows list every provider.' );
// A list posted as a model is refused, not cast.
$check( isset( PressHub_Model_Tasks::validate( [ 'presshub_ai_copilot_model' => [ 'a', 'b' ] ] )['errors']['presshub_ai_copilot_model'] ), 'A non-scalar model is refused.' );
// The save-path filters are registered from names alone, matching the rows.
$from_rows = [];
foreach ( PressHub_Model_Tasks::rows() as $row ) {
    if ( '' !== $row['model'] ) {
        $from_rows[ $row['model'] ] = $row['kind'];
    }
}
$check( $from_rows == PressHub_Model_Tasks::MODEL_OPTIONS, 'MODEL_OPTIONS matches the model option and kind of every row.' );
$tasks_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-model-tasks.php' );
preg_match( '/function register_hooks\(\).*?\n    \}\n/s', $tasks_src, $rh );
$check( ! empty( $rh[0] ) && false === strpos( $rh[0], 'rows()' ) && false === strpos( $rh[0], '__(' ), 'register_hooks() runs at plugin load without translating anything.' );
$check( false !== strpos( $tasks_src, 'resolve_ai_provider_config( true )' ), 'The table resolves Social copy quietly (no log line per page view).' );
$cw_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-social-ai-copywriter.php' );
$check( false !== strpos( $cw_src, 'resolve_ai_provider_config( bool $quiet = false )' ) && false !== strpos( $cw_src, '$quiet || PressHub_Social_Logger::info(' ), 'The Social copy resolver can run without logging.' );

// ---------------------------------------------------------------------
// 8. Scripts.
// ---------------------------------------------------------------------
$admin_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
$page_js  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/settings-page.js' );
$check( false !== strpos( $admin_js, "trigger('presshub:settings-field-errors', [res.data.field_errors, err])" ), 'admin.js passes field errors to the error summary.' );
$check( false !== strpos( $page_js, "on('presshub:settings-field-errors'" ) && false !== strpos( $page_js, 'markFields(pairs)' ), 'settings-page.js marks each refused field and lists it in the summary.' );
$check( false !== strpos( $page_js, "choice.value === '__other'" ), 'settings-page.js keeps the list and the model ID field in step.' );
$check( false !== strpos( $admin_js, 'res.data.model_warnings' ), 'admin.js shows the unknown-model warnings after a save.' );
$check( false !== strpos( $admin_js, 'Saved, with a warning on a model. See its row.' ) && false !== strpos( $page_js, 'model_warning_fields' ), 'An unknown model\'s warning shows at save time, on its row and in the status line.' );
$check( false !== strpos( $page_js, "data-stored-invalid') === '1'" ), 'A clean save keeps the error mark on a stored wrong model.' );
$search_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/settings-search.js' );
$check( false !== strpos( $search_js, 'window.PressHubModelControl(hash)' ), 'A search link to a model option focuses the visible list.' );

if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo "  - {$f}\n";
    }
    exit( 1 );
}
echo "ModelsTableTest: OK\n";
