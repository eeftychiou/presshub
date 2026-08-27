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
// 1. Available Greek Voice Models Directory
// =========================================================================

$voices = $synthesizer->get_available_voices();

as_check( 'voices: returns array with female and male sections', is_array( $voices ) && isset( $voices['female'], $voices['male'] ) );
as_check( 'voices: female voices contains el-GR-Wavenet-A', isset( $voices['female']['el-GR-Wavenet-A'] ) );
as_check( 'voices: female voices contains el-GR-Chirp3-HD-Aoede', isset( $voices['female']['el-GR-Chirp3-HD-Aoede'] ) );
as_check( 'voices: male voices contains el-GR-Chirp3-HD-Achird', isset( $voices['male']['el-GR-Chirp3-HD-Achird'] ) );
as_check( 'voices: male voices contains el-GR-Chirp3-HD-Algenib', isset( $voices['male']['el-GR-Chirp3-HD-Algenib'] ) );

$sample_voice = $voices['male']['el-GR-Chirp3-HD-Achird'];
as_check( 'voices: metadata contains name', ( $sample_voice['name'] ?? '' ) === 'el-GR-Chirp3-HD-Achird' );
as_check( 'voices: metadata contains gender', ( $sample_voice['gender'] ?? '' ) === 'MALE' );
as_check( 'voices: metadata contains type Chirp3-HD', ( $sample_voice['type'] ?? '' ) === 'Chirp3-HD' );


// =========================================================================
// 2. Speaker Turn Mapping to Voice Models & Options
// =========================================================================

$GLOBALS['OPTIONS_STORE'] = [];

// Test 2a: Defaults
as_check( 'speaker_mapping: default female voice is el-GR-Wavenet-A', $synthesizer->get_voice_for_speaker( 'female' ) === 'el-GR-Wavenet-A' );
as_check( 'speaker_mapping: default male voice is el-GR-Chirp3-HD-Achird', $synthesizer->get_voice_for_speaker( 'male' ) === 'el-GR-Chirp3-HD-Achird' );
as_check( 'speaker_mapping: Μαρία maps to female voice', $synthesizer->get_voice_for_speaker( 'Μαρία' ) === 'el-GR-Wavenet-A' );
as_check( 'speaker_mapping: Νίκος maps to male voice', $synthesizer->get_voice_for_speaker( 'Νίκος' ) === 'el-GR-Chirp3-HD-Achird' );

// Test 2b: Custom configured voice models in options
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_female'] = 'el-GR-Chirp3-HD-Aoede';
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_voice_male'] = 'el-GR-Chirp3-HD-Algenib';

as_check( 'speaker_mapping: option overrides female voice', $synthesizer->get_voice_for_speaker( 'female' ) === 'el-GR-Chirp3-HD-Aoede' );
as_check( 'speaker_mapping: option overrides male voice', $synthesizer->get_voice_for_speaker( 'male' ) === 'el-GR-Chirp3-HD-Algenib' );
as_check( 'speaker_mapping: host1 alias maps to configured female voice', $synthesizer->get_voice_for_speaker( 'host1' ) === 'el-GR-Chirp3-HD-Aoede' );
as_check( 'speaker_mapping: host2 alias maps to configured male voice', $synthesizer->get_voice_for_speaker( 'host2' ) === 'el-GR-Chirp3-HD-Algenib' );


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


// =========================================================================
// 5. API Client synthesize_speech_with_options()
// =========================================================================

$GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = 'test-gcloud-key';
$GLOBALS['CAPTURED_REQUESTS'] = [];

$api_client = new PressHub_AI_API_Client();

// Test 5a: Missing API key error
$GLOBALS['OPTIONS_STORE']['presshub_ai_google_cloud_api_key'] = '';
$no_key_client = new PressHub_AI_API_Client();
$err_no_key = $no_key_client->synthesize_speech_with_options( 'Δοκιμή φωνής' );
as_check( 'api_client: missing key returns WP_Error', is_wp_error( $err_no_key ) && 'no_gc_key' === $err_no_key->get_error_code() );

// Test 5b: Successful synthesis request encoding
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
    public function synthesize_speech_with_options( $text, $voice_model = 'el-GR-Wavenet-A', $speed = 1.0, $pitch = 0.0 ) {
        $this->synthesized_calls[] = [
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
as_check( 'e2e: synthesized 2 turns via API client', count( $mock_tts->synthesized_calls ) === 2 );
as_check( 'e2e: turn 0 called with female voice', ( $mock_tts->synthesized_calls[0]['voice_model'] ?? '' ) === 'el-GR-Wavenet-A' );
as_check( 'e2e: turn 1 called with male voice', ( $mock_tts->synthesized_calls[1]['voice_model'] ?? '' ) === 'el-GR-Chirp3-HD-Achird' );

// Test 7b: Custom script argument
$mock_tts_custom = new Mock_Audio_API_Client();
$custom_script = "[Νίκος]: Μόνο ο Νίκος μιλάει εδώ.";
$result_custom = $synthesizer->synthesize_podcast( $test_date_e2e, $custom_script, $mock_tts_custom );
as_check( 'e2e: custom script overrides stored script', count( $mock_tts_custom->synthesized_calls ) === 1 && ( $mock_tts_custom->synthesized_calls[0]['voice_model'] ?? '' ) === 'el-GR-Chirp3-HD-Achird' );

// Test 7c: Missing script returns WP_Error
$err_no_script = $synthesizer->synthesize_podcast( '1980-01-01', '', $mock_tts );
as_check( 'e2e: missing script returns WP_Error', is_wp_error( $err_no_script ) && 'no_script' === $err_no_script->get_error_code() );

// Test 7d: Invalid script with no speaker tags returns WP_Error
$err_invalid_script = $synthesizer->synthesize_podcast( $test_date_e2e, 'Απλό κείμενο χωρίς ομιλητές.', $mock_tts );
as_check( 'e2e: script without turns returns WP_Error', is_wp_error( $err_invalid_script ) && 'invalid_script' === $err_invalid_script->get_error_code() );


// Cleanup test uploads dir
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
echo "AudioSynthesizerTest: OK (50 checks)\n";
