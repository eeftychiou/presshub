<?php
/**
 * AudioSynthesizerTest — Unit tests for PressHub_AI_Audio_Synthesizer.
 *
 * Test cases:
 *   1. Available Greek voice models directory (Neural2, Wavenet, Standard for female and male).
 *   2. Voice model and parameter mapping for speakers (female/male/custom names, speed, pitch).
 *   3. Silent MPEG MP3 frame generation with configurable duration.
 *   4. Binary MP3 chunk stitching with ID3 striping and silent pause intervals.
 *   5. API client synthesize_speech_with_options() request formatting and response decoding.
 *   6. Media sideloading and WordPress Podcast post creation (Audio block, transcript, category, status, meta).
 *   7. Full end-to-end synthesize_podcast() workflow with mock TTS client and error handling.
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

$failures = 0;
function as_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$test_upload_dir = sys_get_temp_dir() . '/presshub-audio-test-' . uniqid();
$GLOBALS['UPLOAD_DIR'] = $test_upload_dir;

$synthesizer = new PressHub_AI_Audio_Synthesizer();

// =========================================================================
// 1. Available Voice Models Directory (Gemini 2.0 Natural & Google Cloud)
// =========================================================================

$gemini_voices = $synthesizer->get_available_voices( 'gemini' );
as_check( 'voices: returns array with female and male sections', is_array( $gemini_voices ) && isset( $gemini_voices['female'], $gemini_voices['male'] ) );
as_check( 'voices: female voices contains Aoede', isset( $gemini_voices['female']['Aoede'] ) );
as_check( 'voices: female voices contains Kore', isset( $gemini_voices['female']['Kore'] ) );
as_check( 'voices: male voices contains Fenrir', isset( $gemini_voices['male']['Fenrir'] ) );
as_check( 'voices: male voices contains Puck', isset( $gemini_voices['male']['Puck'] ) );

$sample_voice = $gemini_voices['male']['Fenrir'];
as_check( 'voices: metadata contains name Fenrir', ( $sample_voice['name'] ?? '' ) === 'Fenrir' );
as_check( 'voices: metadata contains gender MALE', ( $sample_voice['gender'] ?? '' ) === 'MALE' );
as_check( 'voices: metadata contains type Gemini-Neural', ( $sample_voice['type'] ?? '' ) === 'Gemini-Neural' );

// Check available delivery styles (logosAI)
$styles = $synthesizer->get_available_styles();
as_check( 'styles: returns 11 delivery styles', count( $styles ) === 11 );
as_check( 'styles: contains formal style', isset( $styles['formal'] ) );
as_check( 'styles: contains storyteller style', isset( $styles['storyteller'] ) );
as_check( 'styles: contains dramatic style', isset( $styles['dramatic'] ) );
as_check( 'styles: contains custom style', isset( $styles['custom'] ) );


// =========================================================================
// 2. Speaker Turn Mapping to Voice Models & Options
// =========================================================================

$GLOBALS['OPTIONS_STORE'] = [];

// Test 2a: Defaults (Gemini Engine default)
as_check( 'speaker_mapping: default female voice is Kore', $synthesizer->get_voice_for_speaker( 'female' ) === 'Kore' );
as_check( 'speaker_mapping: default male voice is Fenrir', $synthesizer->get_voice_for_speaker( 'male' ) === 'Fenrir' );
as_check( 'speaker_mapping: Μαρία maps to female voice Kore', $synthesizer->get_voice_for_speaker( 'Μαρία' ) === 'Kore' );
as_check( 'speaker_mapping: Νίκος maps to male voice Fenrir', $synthesizer->get_voice_for_speaker( 'Νίκος' ) === 'Fenrir' );

// Test 2b: Custom configured voice models in options
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_female'] = 'Kore';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_male'] = 'Puck';

as_check( 'speaker_mapping: option overrides female voice to Kore', $synthesizer->get_voice_for_speaker( 'female' ) === 'Kore' );
as_check( 'speaker_mapping: option overrides male voice to Puck', $synthesizer->get_voice_for_speaker( 'male' ) === 'Puck' );
as_check( 'speaker_mapping: host1 alias maps to configured female voice', $synthesizer->get_voice_for_speaker( 'host1' ) === 'Kore' );
as_check( 'speaker_mapping: host2 alias maps to configured male voice', $synthesizer->get_voice_for_speaker( 'host2' ) === 'Puck' );

// Test 2c: Google Cloud Engine Mode
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_engine'] = 'google_cloud';
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_female'] );
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_male'] );
as_check( 'speaker_mapping: gc engine default female is el-GR-Wavenet-A', $synthesizer->get_voice_for_speaker( 'female' ) === 'el-GR-Wavenet-A' );
as_check( 'speaker_mapping: gc engine default male is el-GR-Chirp3-HD-Achird', $synthesizer->get_voice_for_speaker( 'male' ) === 'el-GR-Chirp3-HD-Achird' );

// Reset engine to gemini default
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_engine'] = 'gemini';


// =========================================================================
// 3. Silent MPEG MP3 Frame Generation
// =========================================================================

$silence_0 = $synthesizer->generate_silent_mp3_frame( 0 );
as_check( 'silence: 0ms duration returns empty string', '' === $silence_0 );

$silence_400 = $synthesizer->generate_silent_mp3_frame( 400 );
as_check( 'silence: 400ms duration returns non-empty binary string', is_string( $silence_400 ) && strlen( $silence_400 ) > 0 );
as_check( 'silence: starts with MPEG sync byte 0xFF', ord( $silence_400[0] ) === 0xFF );
as_check( 'silence: second byte has MPEG layer 3 sync bits 0xFB or 0xFA', ( ord( $silence_400[1] ) & 0xFE ) === 0xFA );
as_check( 'silence: frame buffer size is divisible by single frame length (417 bytes)', ( strlen( $silence_400 ) % 417 ) === 0 );


// =========================================================================
// 4. Binary MP3 Chunk Stitching & Pause Insertion
// =========================================================================

// Dummy MP3 frames simulating chunks
$frame_a = "\xFF\xFB\x90\xC4" . str_repeat( "\xAA", 413 );
$frame_b = "\xFF\xFB\x90\xC4" . str_repeat( "\xBB", 413 );

// Chunk 1 with ID3v2 header (10 bytes header + 10 bytes payload = 20 bytes total ID3)
$id3_header = "ID3\x04\x00\x00\x00\x00\x00\x0A" . str_repeat( "\x00", 10 );
$chunk_1 = $id3_header . $frame_a;

// Chunk 2 with ID3v1 footer (128 bytes starting with TAG)
$id3_footer = "TAG" . str_repeat( "\x00", 125 );
$chunk_2 = $frame_b . $id3_footer;

// Test 4a: Empty buffers
as_check( 'stitch: empty array returns empty string', '' === $synthesizer->stitch_audio_chunks( [] ) );

// Test 4b: Single buffer stripped of ID3 tags
$single_stitched = $synthesizer->stitch_audio_chunks( [ $chunk_1 ] );
as_check( 'stitch: single chunk preserves audio frame without ID3 header', $single_stitched === $frame_a );

// Test 4c: Multiple chunks stitched with silent pause
$stitched_two = $synthesizer->stitch_audio_chunks( [ $chunk_1, $chunk_2 ], 400 );
$expected_pause = $synthesizer->generate_silent_mp3_frame( 400 );
as_check( 'stitch: two chunks concatenated with pause in between and tags stripped', $stitched_two === ( $frame_a . $expected_pause . $frame_b ) );

// Test 4d: PCM to WAV header generation
$dummy_pcm = str_repeat( "\x11\x22", 12000 ); // 0.5s at 24kHz 16-bit mono
$wav_output = PressHub_AI_API_Client::pcm_to_wav( $dummy_pcm, 24000 );
as_check( 'pcm_to_wav: starts with RIFF header', 'RIFF' === substr( $wav_output, 0, 4 ) );
as_check( 'pcm_to_wav: contains WAVE format', 'WAVE' === substr( $wav_output, 8, 4 ) );
as_check( 'pcm_to_wav: total length is 44 bytes header + PCM size', strlen( $wav_output ) === 44 + strlen( $dummy_pcm ) );

// Test 4e: WAV chunk stitching with silent intervals
$wav_chunk_1 = PressHub_AI_API_Client::pcm_to_wav( str_repeat( "\xAA\xAA", 480 ), 24000 ); // 20ms
$wav_chunk_2 = PressHub_AI_API_Client::pcm_to_wav( str_repeat( "\xBB\xBB", 480 ), 24000 ); // 20ms
$stitched_wav = $synthesizer->stitch_wav_chunks( [ $wav_chunk_1, $wav_chunk_2 ], 100, 24000 ); // 100ms pause
as_check( 'stitch_wav: returns valid RIFF container', 'RIFF' === substr( $stitched_wav, 0, 4 ) );
// Expected size: 44 (header) + 960 (chunk 1) + 4800 (100ms silence at 48 bytes/ms) + 960 (chunk 2) = 6764
as_check( 'stitch_wav: stitched size includes pause silence bytes', strlen( $stitched_wav ) === ( 44 + 960 + 4800 + 960 ) );


// =========================================================================
// 5. API Client synthesize_speech_with_options()
// =========================================================================

$GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'test-gcloud-key';
$GLOBALS['CAPTURED_REQUESTS'] = [];

$api_client = new PressHub_AI_API_Client();

// Test 5a: Missing API key error
// Issue #43: The deprecated `presshub_ai_google_cloud_api_key` option is now
// migrated into a Gemini Provider Store record. To exercise the "no key →
// WP_Error" path we must clear both the legacy option AND the Provider Store
// record so that hydration has no source to draw from.
$GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = '';
$GLOBALS['OPTIONS_STORE'][ PressHub_AI_Provider_Store::OPTION_CONFIGURED_PROVIDERS ] = [];
$no_key_client = new PressHub_AI_API_Client();
$err_no_key = $no_key_client->synthesize_speech_with_options( 'Δοκιμή φωνής' );
as_check( 'api_client: missing key returns WP_Error', is_wp_error( $err_no_key ) && 'no_gc_key' === $err_no_key->get_error_code() );

// Re-populate the Provider Store with the test key for Test 5b (success path).
// Issue #43: the Gemini Provider Store record is now the canonical source.
$GLOBALS['OPTIONS_STORE'][ PressHub_AI_Provider_Store::OPTION_CONFIGURED_PROVIDERS ] = [
    [
        'id'      => 'gemini-main',
        'type'    => 'gemini',
        'name'    => 'Google Gemini',
        'api_key' => 'test-gcloud-key',
        'enabled' => true,
    ],
];
$GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'test-gcloud-key';
$GLOBALS['CAPTURE_FILTER'] = function( $default, $req ) {
    list( $url, $args ) = $req;
    $body = json_decode( $args['body'], true );
    if ( isset( $body['input']['text'] ) ) {
        // Return dummy base64 MP3 content
        $fake_mp3 = "\xFF\xFB\x90\xC4" . str_repeat( "\x11", 413 );
        return [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [ 'audioContent' => base64_encode( $fake_mp3 ) ] ),
        ];
    }
    return null;
};

$tts_result = $api_client->synthesize_speech_with_options( 'Καλημέρα σας!', 'el-GR-Wavenet-A', 1.05, 0.5 );
as_check( 'api_client: returns decoded binary MP3 string on success', is_string( $tts_result ) && strlen( $tts_result ) === 417 );

$last_req = end( $GLOBALS['CAPTURED_REQUESTS'] );
$req_url  = $last_req[0];
$req_body = json_decode( $last_req[1]['body'], true );

as_check( 'api_client: target endpoint is texttospeech.googleapis.com', false !== strpos( $req_url, 'texttospeech.googleapis.com' ) );
as_check( 'api_client: x-goog-api-key header passed', ( $last_req[1]['headers']['x-goog-api-key'] ?? '' ) === 'test-gcloud-key' );
as_check( 'api_client: request body contains input text', ( $req_body['input']['text'] ?? '' ) === 'Καλημέρα σας!' );
as_check( 'api_client: request body voice name is el-GR-Wavenet-A', ( $req_body['voice']['name'] ?? '' ) === 'el-GR-Wavenet-A' );
as_check( 'api_client: request body voice languageCode is el-GR', ( $req_body['voice']['languageCode'] ?? '' ) === 'el-GR' );
as_check( 'api_client: request body speakingRate is 1.05', ( $req_body['audioConfig']['speakingRate'] ?? 0 ) == 1.05 );
as_check( 'api_client: request body pitch is 0.5', ( $req_body['audioConfig']['pitch'] ?? 0 ) == 0.5 );


// =========================================================================
// 6. Media Sideloading & WordPress Podcast Post Creation
// =========================================================================

$GLOBALS['WP_INSERTED_POSTS'] = [];
$GLOBALS['POST_META_STORE'] = [];
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_category'] = 42;
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_status'] = 'publish';

$test_transcript = "[Μαρία]: Καλημέρα σε όλους!\n[Νίκος]: Καλημέρα Μαρία!";
$post_id = $synthesizer->create_podcast_post(
    'http://example.test/wp-content/uploads/podcast-2026-08-26.mp3',
    88,
    $test_transcript,
    '2026-08-26'
);

as_check( 'create_post: returns integer post ID', is_int( $post_id ) && $post_id > 0 );
as_check( 'create_post: post inserted into WordPress', ! empty( $GLOBALS['WP_INSERTED_POSTS'] ) );

$inserted = end( $GLOBALS['WP_INSERTED_POSTS'] );
as_check( 'create_post: title contains Πρωινό Podcast and date', false !== strpos( $inserted['post_title'], 'Πρωινό Podcast' ) && false !== strpos( $inserted['post_title'], '26/08/2026' ) );
as_check( 'create_post: configured category applied (42)', isset( $inserted['post_category'] ) && [ 42 ] === $inserted['post_category'] );
as_check( 'create_post: configured status applied (publish)', ( $inserted['post_status'] ?? '' ) === 'publish' );
as_check( 'create_post: content contains WordPress Audio Block', false !== strpos( $inserted['post_content'], '<!-- wp:audio {"id":88} -->' ) );
as_check( 'create_post: content contains audio src URL', false !== strpos( $inserted['post_content'], 'http://example.test/wp-content/uploads/podcast-2026-08-26.mp3' ) );
as_check( 'create_post: content contains formatted dialogue transcript', false !== strpos( $inserted['post_content'], '<strong>Μαρία:</strong>' ) && false !== strpos( $inserted['post_content'], 'Καλημέρα σε όλους!' ) );
as_check( 'create_post: meta _presshub_briefing_date saved', get_post_meta( $post_id, '_presshub_briefing_date', true ) === '2026-08-26' );
as_check( 'create_post: meta _presshub_briefing_type is podcast', get_post_meta( $post_id, '_presshub_briefing_type', true ) === 'podcast' );
as_check( 'create_post: meta _presshub_audio_attachment_id is 88', (int) get_post_meta( $post_id, '_presshub_audio_attachment_id', true ) === 88 );
as_check( 'create_post: meta _presshub_audio_url saved', get_post_meta( $post_id, '_presshub_audio_url', true ) === 'http://example.test/wp-content/uploads/podcast-2026-08-26.mp3' );

// Test 6b: Fallback category and status
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_category'] );
unset( $GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_podcast_status'] );

$fallback_post_id = $synthesizer->create_podcast_post(
    'http://example.test/wp-content/uploads/podcast-default.mp3',
    89,
    'Απλό κείμενο.',
    '2026-08-26'
);
$fallback_post = end( $GLOBALS['WP_INSERTED_POSTS'] );
as_check( 'create_post: status defaults to pending when option unset', ( $fallback_post['post_status'] ?? '' ) === 'pending' );


// =========================================================================
// 7. Full End-to-End synthesize_podcast() Workflow
// =========================================================================

$GLOBALS['OPTIONS_STORE'] = [];
$producer = new PressHub_AI_Podcast_Producer();
$test_date_e2e = '2026-08-26';
$script_content = "[Μαρία]: Καλωσήρθατε στην Πρωινή Ενημέρωση!\n[Νίκος]: Καλημέρα Μαρία, ας δούμε τις ειδήσεις.";
$producer->save_script( $test_date_e2e, $script_content );

class Mock_Audio_API_Client extends PressHub_AI_API_Client {
    public $synthesized_calls = [];
    public function __construct() {}
    public function synthesize_speech_via_gemini( string $text, string $voice_name = 'Kore', bool $as_wav = true, string $style = 'formal', $speaker_configs = null ) {
        $this->synthesized_calls[] = [
            'engine'     => 'gemini',
            'text'       => $text,
            'voice_name' => $voice_name,
            'style'      => $style,
        ];
        $fake_pcm = str_repeat( "\x12\x34", 1200 ); // 100ms at 24kHz
        return $as_wav ? self::pcm_to_wav( $fake_pcm, 24000 ) : $fake_pcm;
    }
    public function synthesize_speech_with_options( $text, $voice_model = 'el-GR-Wavenet-A', $speed = 1.0, $pitch = 0.0 ) {
        $this->synthesized_calls[] = [
            'engine'      => 'google_cloud',
            'text'        => $text,
            'voice_model' => $voice_model,
            'speed'       => $speed,
            'pitch'       => $pitch,
        ];
        return "\xFF\xFB\x90\xC4" . str_repeat( "\x22", 413 );
    }
}

$mock_tts = new Mock_Audio_API_Client();
$GLOBALS['SIDELOAD_RETURN_ID'] = 95;

$result_e2e = $synthesizer->synthesize_podcast( $test_date_e2e, '', $mock_tts );

as_check( 'e2e: returns array on success', is_array( $result_e2e ) );
as_check( 'e2e: post_id created', isset( $result_e2e['post_id'] ) && $result_e2e['post_id'] > 0 );
as_check( 'e2e: attachment_id is 95', ( $result_e2e['attachment_id'] ?? 0 ) === 95 );
as_check( 'e2e: audio_url contains attachment URL', false !== strpos( $result_e2e['audio_url'] ?? '', 'attachment_id=95' ) );
as_check( 'e2e: turns count is 2', ( $result_e2e['turns_count'] ?? 0 ) === 2 );
as_check( 'e2e: synthesized single pass via Gemini API client', count( $mock_tts->synthesized_calls ) === 1 );
as_check( 'e2e: single pass called with female voice Kore', ( $mock_tts->synthesized_calls[0]['voice_name'] ?? '' ) === 'Kore' );
as_check( 'e2e: single pass called with script text', false !== strpos( $mock_tts->synthesized_calls[0]['text'] ?? '', 'Μαρία' ) );

// Test 7b: Custom script argument
$mock_tts_custom = new Mock_Audio_API_Client();
$custom_script = "[Νίκος]: Μόνο ο Νίκος μιλάει εδώ.";
$result_custom = $synthesizer->synthesize_podcast( $test_date_e2e, $custom_script, $mock_tts_custom );
as_check( 'e2e: custom script overrides stored script', count( $mock_tts_custom->synthesized_calls ) === 1 && false !== strpos( $mock_tts_custom->synthesized_calls[0]['text'] ?? '', 'Μόνο ο Νίκος' ) );

// Test 7c: Missing script returns WP_Error
$err_no_script = $synthesizer->synthesize_podcast( '1980-01-01', '', $mock_tts );
as_check( 'e2e: missing script returns WP_Error', is_wp_error( $err_no_script ) && 'no_script' === $err_no_script->get_error_code() );

// Test 7d: Invalid script with no speaker tags returns WP_Error
$err_invalid_script = $synthesizer->synthesize_podcast( $test_date_e2e, 'Απλό κείμενο χωρίς ομιλητές.', $mock_tts );
as_check( 'e2e: script without turns returns WP_Error', is_wp_error( $err_invalid_script ) && 'invalid_script' === $err_invalid_script->get_error_code() );


// =========================================================================
// 8. Issue #67: Default API Client Instantiation Uses 'tts' Module
// =========================================================================

$GLOBALS['OPTIONS_STORE'] = [
    'presshub_ai_provider'           => 'gemini',
    'presshub_ai_gemini_api_key'     => 'test-gemini-key',
    'presshub_ai_model_gemini'       => 'gemini-3.7-flash',
    'presshub_ai_briefing_tts_model' => '',
];
$GLOBALS['OPTIONS_STORE'][ PressHub_AI_Provider_Store::OPTION_CONFIGURED_PROVIDERS ] = [
    [
        'id'            => 'gemini-main',
        'type'          => 'gemini',
        'name'          => 'Google Gemini',
        'api_key'       => 'test-gemini-key',
        'default_model' => 'gemini-3.7-flash',
        'enabled'       => true,
    ],
];

$captured_models = [];
$GLOBALS['CAPTURE_FILTER'] = function( $default, $req ) use ( &$captured_models ) {
    list( $url, $args ) = $req;
    if ( preg_match( '#/models/([^:]+):generateContent#', $url, $m ) ) {
        $captured_models[] = $m[1];
        $fake_pcm = str_repeat( "\x12\x34", 1200 );
        return [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'inlineData' => [
                                        'mimeType' => 'audio/pcm;rate=24000',
                                        'data'     => base64_encode( $fake_pcm ),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ] ),
        ];
    }
    return null;
};

// Test 8a: synthesize_turn with null api_client resolves tts model (gemini-3.1-flash-tts-preview)
$captured_models = [];
$turn_res = $synthesizer->synthesize_turn( '[Μαρία]: Γεια σας!', 'Kore', 1.0, 0.0, null, 'formal' );
as_check( 'tts_module: synthesize_turn succeeds with null api_client', ! is_wp_error( $turn_res ) && is_string( $turn_res ) );
as_check( 'tts_module: synthesize_turn with null api_client targets gemini-3.1-flash-tts-preview', ! empty( $captured_models ) && 'gemini-3.1-flash-tts-preview' === end( $captured_models ) );

// Test 8b: synthesize_podcast with null api_client resolves tts model (gemini-3.1-flash-tts-preview)
$captured_models = [];
$podcast_res = $synthesizer->synthesize_podcast( '2026-08-26', "[Μαρία]: Γεια σας!\n[Νίκος]: Καλημέρα!", null );
as_check( 'tts_module: synthesize_podcast succeeds with null api_client', is_array( $podcast_res ) && ( $podcast_res['success'] ?? false ) );
as_check( 'tts_module: synthesize_podcast with null api_client targets gemini-3.1-flash-tts-preview', ! empty( $captured_models ) && 'gemini-3.1-flash-tts-preview' === end( $captured_models ) );

// Test 8c: Custom presshub_ai_briefing_tts_model option is respected by default client
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_tts_model'] = 'gemini-2.5-flash-preview-tts';
$captured_models = [];
$turn_res_custom = $synthesizer->synthesize_turn( '[Μαρία]: Δοκιμή προσαρμοσμένου μοντέλου', 'Kore', 1.0, 0.0, null, 'formal' );
as_check( 'tts_module: custom briefing_tts_model option is used over general provider model', ! empty( $captured_models ) && 'gemini-2.5-flash-preview-tts' === end( $captured_models ) );


// =========================================================================
// 9. Issue #69: Multi-Speaker Persona Mapping & Resilient Fallback
// =========================================================================

$captured_requests = [];
$GLOBALS['CAPTURE_FILTER'] = function( $default, $req ) use ( &$captured_requests ) {
    list( $url, $args ) = $req;
    $body = json_decode( $args['body'] ?? '{}', true );
    $captured_requests[] = [
        'url'  => $url,
        'args' => $args,
        'body' => $body,
    ];
    $fake_pcm = str_repeat( "\x12\x34", 1200 );
    return [
        'response' => [ 'code' => 200 ],
        'body'     => json_encode( [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'audio/pcm;rate=24000',
                                    'data'     => base64_encode( $fake_pcm ),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ] ),
    ];
};

// Test 9a: Multi-speaker dialogue sends multiSpeakerVoiceConfig with female & male voices
$captured_requests = [];
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_female'] = 'Μαρία';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_male']   = 'Νίκος';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_female'] = 'Kore';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_male']   = 'Fenrir';

$dialogue_script = "[Μαρία]: Καλημέρα σε όλους!\n[Νίκος]: Καλημέρα Μαρία, ας δούμε τις ειδήσεις.";
$pod_multi = $synthesizer->synthesize_podcast( '2026-08-27', $dialogue_script, null );

as_check( 'issue_69: synthesize_podcast succeeds with 2 speakers', is_array( $pod_multi ) && ( $pod_multi['success'] ?? false ) );
as_check( 'issue_69: captured at least one request', ! empty( $captured_requests ) );

$last_req_body = end( $captured_requests )['body'] ?? [];
$speech_cfg    = $last_req_body['generationConfig']['speechConfig'] ?? [];
as_check( 'issue_69: multiSpeakerVoiceConfig present in speechConfig', isset( $speech_cfg['multiSpeakerVoiceConfig'] ) );

$speakers = $speech_cfg['multiSpeakerVoiceConfig']['speakerVoiceConfigs'] ?? [];
as_check( 'issue_69: 2 speakerVoiceConfigs configured', count( $speakers ) === 2 );
as_check( 'issue_69: speaker 1 is Μαρία with Kore', isset( $speakers[0]['speaker'] ) && 'Μαρία' === $speakers[0]['speaker'] && ( $speakers[0]['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? '' ) === 'Kore' );
as_check( 'issue_69: speaker 2 is Νίκος with Fenrir', isset( $speakers[1]['speaker'] ) && 'Νίκος' === $speakers[1]['speaker'] && ( $speakers[1]['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? '' ) === 'Fenrir' );

// Test 9b: Custom host names & personas dynamically map into multiSpeakerVoiceConfig
$captured_requests = [];
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_female'] = 'Ελένη';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_male']   = 'Γιώργος';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_female'] = 'Aoede';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_male']   = 'Charon';

$custom_script = "[Ελένη]: Καλωσήρθατε στο μαγκαζίνο!\n[Γιώργος]: Καλησπέρα Ελένη.";
$pod_custom = $synthesizer->synthesize_podcast( '2026-08-27', $custom_script, null );

$last_custom_body = end( $captured_requests )['body'] ?? [];
$custom_speakers  = $last_custom_body['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'] ?? [];
as_check( 'issue_69: custom female speaker Ελένη with Aoede', isset( $custom_speakers[0]['speaker'] ) && 'Ελένη' === $custom_speakers[0]['speaker'] && ( $custom_speakers[0]['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? '' ) === 'Aoede' );
as_check( 'issue_69: custom male speaker Γιώργος with Charon', isset( $custom_speakers[1]['speaker'] ) && 'Γιώργος' === $custom_speakers[1]['speaker'] && ( $custom_speakers[1]['voiceConfig']['prebuiltVoiceConfig']['voiceName'] ?? '' ) === 'Charon' );

// Test 9c: get_voice_for_speaker recognizes custom host names
as_check( 'issue_69: get_voice_for_speaker recognises custom female name', $synthesizer->get_voice_for_speaker( 'Ελένη' ) === 'Aoede' );
as_check( 'issue_69: get_voice_for_speaker recognises custom male name', $synthesizer->get_voice_for_speaker( 'Γιώργος' ) === 'Charon' );

// Test 9d: Resilient fallback to turn-by-turn stitching when single-pass fails
$fail_count = 0;
$GLOBALS['CAPTURE_FILTER'] = function( $default, $req ) use ( &$fail_count ) {
    list( $url, $args ) = $req;
    $body = json_decode( $args['body'] ?? '{}', true );
    // Fail single-pass if multiSpeakerVoiceConfig is requested (simulate timeout/error)
    if ( isset( $body['generationConfig']['speechConfig']['multiSpeakerVoiceConfig'] ) ) {
        $fail_count++;
        return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 90010 milliseconds with 0 bytes received' );
    }
    // Individual turns succeed
    $fake_pcm = str_repeat( "\x12\x34", 1200 );
    return [
        'response' => [ 'code' => 200 ],
        'body'     => json_encode( [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'audio/pcm;rate=24000',
                                    'data'     => base64_encode( $fake_pcm ),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ] ),
    ];
};

$fallback_script = "[Ελένη]: Πρώτη είδηση.\n[Γιώργος]: Δεύτερη είδηση.";
$pod_fallback = $synthesizer->synthesize_podcast( '2026-08-27', $fallback_script, null );
as_check( 'issue_69: single-pass failure triggers fallback to turn-by-turn', $fail_count >= 1 );
as_check( 'issue_69: fallback podcast synthesis succeeds', is_array( $pod_fallback ) && ( $pod_fallback['success'] ?? false ) );
as_check( 'issue_69: fallback audio is valid WAV', isset( $pod_fallback['audio_data'] ) && 0 === strpos( $pod_fallback['audio_data'], 'RIFF' ) );


if ( is_dir( $test_upload_dir ) ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $test_upload_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $files as $fileinfo ) {
        $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
        @$todo( $fileinfo->getRealPath() );
    }
    @rmdir( $test_upload_dir );
}

if ( $failures > 0 ) {
    fwrite( STDERR, "AudioSynthesizerTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "AudioSynthesizerTest: OK (67 checks)\n";
