<?php
/**
 * PodcastProducerTest — Unit tests for PressHub_AI_Podcast_Producer.
 *
 * Test cases:
 *   1. Duration specs calculation: 3_min (~450 words, 6-8 turns), 5_min (~750 words, 12-15 turns), 10_min (~1500 words, 20+ turns), and fallback.
 *   2. Preset resolution using PressHub_AI_Preset_Store & Resolver for 'podcast' endpoint.
 *   3. Prompt hydration with {date}, {articles_context}, {sources_list}, {duration_text}, {word_budget}, {host1_name}, {host2_name}.
 *   4. Strict dual-speaker Greek dialogue parsing ([Μαρία]: / [Νίκος]:).
 *   5. Dialogue turn normalization (markdown bold around tags, whitespace, empty line stripping, multiline turns).
 *   6. Script persistence to briefing storage directory (save_script and get_script).
 *   7. End-to-end generate_dialogue_script() with mock API client and error handling.
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
function pp_check( $label, $condition ) {
    global $failures;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

$test_upload_dir = sys_get_temp_dir() . '/presshub-podcast-test-' . uniqid();
$GLOBALS['UPLOAD_DIR'] = $test_upload_dir;

$producer = new PressHub_AI_Podcast_Producer();

// =========================================================================
// 1. Duration Specs Calculation
// =========================================================================

$specs_3 = $producer->get_duration_specs( '3_min' );
pp_check( 'duration_specs: 3_min minutes is 3', ( $specs_3['minutes'] ?? 0 ) === 3 );
pp_check( 'duration_specs: 3_min target_words is ~450', ( $specs_3['target_words'] ?? 0 ) === 450 );
pp_check( 'duration_specs: 3_min target_turns is 6-8', ( $specs_3['target_turns'] ?? '' ) === '6-8' );
pp_check( 'duration_specs: 3_min description contains 3 λεπτά and 450', false !== strpos( $specs_3['description'] ?? '', '3 λεπτά' ) && false !== strpos( $specs_3['description'] ?? '', '450' ) );

$specs_5 = $producer->get_duration_specs( '5_min' );
pp_check( 'duration_specs: 5_min minutes is 5', ( $specs_5['minutes'] ?? 0 ) === 5 );
pp_check( 'duration_specs: 5_min target_words is ~750', ( $specs_5['target_words'] ?? 0 ) === 750 );
pp_check( 'duration_specs: 5_min target_turns is 12-15', ( $specs_5['target_turns'] ?? '' ) === '12-15' );
pp_check( 'duration_specs: 5_min description contains 5 λεπτά and 750', false !== strpos( $specs_5['description'] ?? '', '5 λεπτά' ) && false !== strpos( $specs_5['description'] ?? '', '750' ) );

$specs_10 = $producer->get_duration_specs( '10_min' );
pp_check( 'duration_specs: 10_min minutes is 10', ( $specs_10['minutes'] ?? 0 ) === 10 );
pp_check( 'duration_specs: 10_min target_words is ~1500', ( $specs_10['target_words'] ?? 0 ) === 1500 );
pp_check( 'duration_specs: 10_min target_turns is 20+', ( $specs_10['target_turns'] ?? '' ) === '20+' );
pp_check( 'duration_specs: 10_min description contains 10 λεπτά and 1500', false !== strpos( $specs_10['description'] ?? '', '10 λεπτά' ) && false !== strpos( $specs_10['description'] ?? '', '1500' ) );

// Fallback for unknown / empty duration
$specs_default = $producer->get_duration_specs( '' );
pp_check( 'duration_specs: empty falls back to 5 minutes', ( $specs_default['minutes'] ?? 0 ) === 5 );
$specs_invalid = $producer->get_duration_specs( 'unknown_format' );
pp_check( 'duration_specs: unknown format falls back to 5 minutes', ( $specs_invalid['minutes'] ?? 0 ) === 5 );


// =========================================================================
// 2. Preset Resolution for Podcast Producer Endpoint
// =========================================================================

$GLOBALS['OPTIONS_STORE'] = [];
$GLOBALS['USER_META_STORE'] = [];
$GLOBALS['CURRENT_USER_ID'] = 24;

$GLOBALS['OPTIONS_STORE']['presshub_ai_default_presets'] = [
    [
        'slug'             => 'podcast-morning-coffee',
        'name'             => 'Morning Coffee Podcast',
        'instruction_text' => 'Διατήρησε χαλαρό και φιλικό τόνο με έξυπνες εναλλαγές ατάκας.',
        'enabled'          => true,
    ],
    [
        'slug'             => 'podcast-deep-dive',
        'name'             => 'Deep Dive Podcast',
        'instruction_text' => 'Εστίασε σε αναλυτική δημοσιογραφική συζήτηση για τα οικονομικά γεγονότα.',
        'enabled'          => true,
    ],
];

$GLOBALS['USER_META_STORE'][24]['presshub_ai_author_presets'] = [
    [
        'slug'             => 'author-podcast-style',
        'name'             => 'My Podcast Style',
        'instruction_text' => 'Χρησιμοποίησε ζωηρές ερωτήσεις μεταξύ των δύο παρουσιαστών.',
        'enabled'          => true,
    ],
];

$sample_articles = [
    [
        'title'   => 'Νέο φορολογικό νομοσχέδιο',
        'source'  => 'Kathimerini',
        'url'     => 'https://kathimerini.gr/tax',
        'content' => 'Ελαφρύνσεις για τις μεσαίες επιχειρήσεις.',
    ],
    [
        'title'   => 'Ψηφιακές υπηρεσίες υγείας',
        'source'  => 'In.gr',
        'url'     => 'https://in.gr/health',
        'content' => 'Νέα πλατφόρμα ραντεβού στα νοσοκομεία.',
    ],
];

// Test 2a: Explicit author preset
$prompts_explicit = $producer->build_dialogue_prompt( $sample_articles, 'author-podcast-style', '5_min', '2026-08-26' );
pp_check( 'preset: returns system and user prompt array', is_array( $prompts_explicit ) && isset( $prompts_explicit['system_prompt'], $prompts_explicit['user_prompt'] ) );
pp_check( 'preset: explicit author preset appended', false !== strpos( $prompts_explicit['system_prompt'], 'Χρησιμοποίησε ζωηρές ερωτήσεις' ) );

// Test 2b: Plugin default preset
$prompts_plugin = $producer->build_dialogue_prompt( $sample_articles, 'podcast-deep-dive', '5_min', '2026-08-26' );
pp_check( 'preset: plugin default preset appended', false !== strpos( $prompts_plugin['system_prompt'], 'Εστίασε σε αναλυτική δημοσιογραφική συζήτηση' ) );

// Test 2c: Author default preset
$GLOBALS['USER_META_STORE'][24]['presshub_ai_default_preset_id'] = 'author-podcast-style';
$prompts_default = $producer->build_dialogue_prompt( $sample_articles, '', '5_min', '2026-08-26' );
pp_check( 'preset: author default preset applies when preset_id empty', false !== strpos( $prompts_default['system_prompt'], 'Χρησιμοποίησε ζωηρές ερωτήσεις' ) );

// Test 2d: Sentinel __none__
$prompts_none = $producer->build_dialogue_prompt( $sample_articles, '__none__', '5_min', '2026-08-26' );
pp_check( 'preset: __none__ omits preset text', false === strpos( $prompts_none['system_prompt'], 'Χρησιμοποίησε ζωηρές' ) && false === strpos( $prompts_none['system_prompt'], 'Εστίασε σε αναλυτική' ) );


// =========================================================================
// 3. Prompt Hydration & Deduplication
// =========================================================================

$hydrated = $producer->build_dialogue_prompt( $sample_articles, '__none__', '3_min', '2026-08-26', 'harvested_articles' );
$sys_p = $hydrated['system_prompt'];
$usr_p = $hydrated['user_prompt'];

// Issue #90 — generic [SPEAKER_N]: labels; no host-name placeholders.
pp_check( 'hydration: {date} replaced', false !== strpos( $sys_p, '2026-08-26' ) && false === strpos( $sys_p, '{date}' ) );
pp_check( 'hydration: {duration_text} replaced', false !== strpos( $sys_p, '3 λεπτά' ) && false === strpos( $sys_p, '{duration_text}' ) );
pp_check( 'hydration: {word_budget} replaced with 450', false !== strpos( $sys_p, '450' ) && false === strpos( $sys_p, '{word_budget}' ) );
pp_check( 'hydration: contains [SPEAKER_1]: generic tag', false !== strpos( $sys_p, '[SPEAKER_1]:' ) );
pp_check( 'hydration: contains [SPEAKER_2]: generic tag', false !== strpos( $sys_p, '[SPEAKER_2]:' ) );
pp_check( 'hydration: no {host1_name} placeholder', false === strpos( $sys_p, '{host1_name}' ) );
pp_check( 'hydration: no {host2_name} placeholder', false === strpos( $sys_p, '{host2_name}' ) );
pp_check( 'hydration: no {host3_name} placeholder', false === strpos( $sys_p, '{host3_name}' ) );
pp_check( 'hydration: no Μαρία literal in system prompt', false === strpos( $sys_p, 'Μαρία' ) );
pp_check( 'hydration: no Νίκος literal in system prompt', false === strpos( $sys_p, 'Νίκος' ) );
pp_check( 'hydration: {sources_list} replaced with Kathimerini, In.gr', false !== strpos( $sys_p, 'Kathimerini, In.gr' ) && false === strpos( $sys_p, '{sources_list}' ) );
pp_check( 'deduplication: system_prompt does NOT contain raw articles context', false === strpos( $sys_p, 'Νέο φορολογικό νομοσχέδιο' ) && false === strpos( $sys_p, '{articles_context}' ) );

// Verify custom template with {articles_context} still hydrates correctly via hydrate_prompt
$custom_tpl = $producer->hydrate_prompt( "Custom: {articles_context}", '2026-08-26', $sample_articles );
pp_check( 'hydration: custom template with {articles_context} hydrates articles', false !== strpos( $custom_tpl, 'Νέο φορολογικό νομοσχέδιο' ) );

pp_check( 'hydration: user_prompt contains date', false !== strpos( $usr_p, '2026-08-26' ) );
pp_check( 'hydration: user_prompt contains duration specs', false !== strpos( $usr_p, '3 λεπτά' ) );
pp_check( 'hydration: user_prompt contains word budget', false !== strpos( $usr_p, '450' ) );
// Issue #90 — user prompt no longer mentions host names (presenter_mention is now English+generic).
pp_check( 'hydration: user_prompt contains generic [SPEAKER_1] tag', false !== strpos( $usr_p, '[SPEAKER_1]' ) );
pp_check( 'hydration: user_prompt contains generic [SPEAKER_2] tag', false !== strpos( $usr_p, '[SPEAKER_2]' ) );
pp_check( 'hydration: user_prompt does NOT contain literal host name', false === strpos( $usr_p, 'Μαρία' ) && false === strpos( $usr_p, 'Νίκος' ) );
pp_check( 'hydration: user_prompt contains article context', false !== strpos( $usr_p, 'Ψηφιακές υπηρεσίες υγείας' ) );


// =========================================================================
// 4. Strict Dual-Speaker Greek Dialogue Parsing
// =========================================================================

$clean_script = "[Μαρία]: Καλημέρα σε όλους! Είναι Τετάρτη 26 Αυγούστου και ακούτε το PressHub Podcast.\n"
    . "[Νίκος]: Καλημέρα Μαρία, καλημέρα σε όλους τους φίλους. Σήμερα έχουμε πλούσια ατζέντα ειδήσεων.\n"
    . "[Μαρία]: Ακριβώς Νίκο, ξεκινάμε με τις εξελίξεις στην οικονομία.\n"
    . "[Νίκος]: Ναι, ανακοινώθηκαν τα νέα μέτρα για τις επιχειρήσεις.";

$parsed_turns = $producer->parse_script_turns( $clean_script, 'Μαρία', 'Νίκος' );

pp_check( 'parse_turns: parses exactly 4 turns', count( $parsed_turns ) === 4 );
pp_check( 'parse_turns: turn 0 speaker is female', ( $parsed_turns[0]['speaker'] ?? '' ) === 'female' );
pp_check( 'parse_turns: turn 0 speaker_name is Μαρία', ( $parsed_turns[0]['speaker_name'] ?? '' ) === 'Μαρία' );
pp_check( 'parse_turns: turn 0 text is correct', false !== strpos( $parsed_turns[0]['text'] ?? '', 'Καλημέρα σε όλους' ) );
pp_check( 'parse_turns: turn 1 speaker is male', ( $parsed_turns[1]['speaker'] ?? '' ) === 'male' );
pp_check( 'parse_turns: turn 1 speaker_name is Νίκος', ( $parsed_turns[1]['speaker_name'] ?? '' ) === 'Νίκος' );
pp_check( 'parse_turns: turn 1 text is correct', false !== strpos( $parsed_turns[1]['text'] ?? '', 'Καλημέρα Μαρία' ) );
pp_check( 'parse_turns: turn 2 speaker is female', ( $parsed_turns[2]['speaker'] ?? '' ) === 'female' );
pp_check( 'parse_turns: turn 3 speaker is male', ( $parsed_turns[3]['speaker'] ?? '' ) === 'male' );


// =========================================================================
// 5. Dialogue Turn Normalization
// =========================================================================

// Test 5a: Markdown bold tags around speaker headers & multiline turn text
$messy_script = "### Daily Podcast Script\n\n"
    . "**[Μαρία]:** Καλημέρα σας!\nΣήμερα έχουμε πολύ ενδιαφέροντα νέα.\n\n"
    . "**[Νίκος]**: Καλημέρα Μαρία!\n"
    . "Πράγματι, η επικαιρότητα τρέχει.\n\n"
    . "**Μαρία:** Ας δούμε την πρώτη είδηση.\n\n"
    . "Νίκος:   Σωστά, αφορά το νέο νομοσχέδιο.\n\n";

$normalized_turns = $producer->parse_script_turns( $messy_script, 'Μαρία', 'Νίκος' );

pp_check( 'normalization: extracts 4 turns despite markdown bold and whitespace', count( $normalized_turns ) === 4 );
pp_check( 'normalization: turn 0 contains multiline combined text without bold tags', ( $normalized_turns[0]['speaker'] ?? '' ) === 'female' && false !== strpos( $normalized_turns[0]['text'] ?? '', 'Καλημέρα σας!' ) && false !== strpos( $normalized_turns[0]['text'] ?? '', 'Σήμερα έχουμε πολύ ενδιαφέροντα νέα.' ) );
pp_check( 'normalization: turn 0 stripped of tag prefix', false === strpos( $normalized_turns[0]['text'] ?? '', '[Μαρία]' ) && false === strpos( $normalized_turns[0]['text'] ?? '', 'Μαρία:' ) );
pp_check( 'normalization: turn 1 speaker is male and text preserved', ( $normalized_turns[1]['speaker'] ?? '' ) === 'male' && false !== strpos( $normalized_turns[1]['text'] ?? '', 'Καλημέρα Μαρία!' ) && false !== strpos( $normalized_turns[1]['text'] ?? '', 'Πράγματι, η επικαιρότητα τρέχει.' ) );
pp_check( 'normalization: handles **Μαρία:** without brackets', ( $normalized_turns[2]['speaker'] ?? '' ) === 'female' && false !== strpos( $normalized_turns[2]['text'] ?? '', 'Ας δούμε την πρώτη είδηση.' ) );
pp_check( 'normalization: handles plain Νίκος: tag', ( $normalized_turns[3]['speaker'] ?? '' ) === 'male' && false !== strpos( $normalized_turns[3]['text'] ?? '', 'Σωστά, αφορά το νέο νομοσχέδιο.' ) );

// Test 5b: Empty script returns empty array
pp_check( 'normalization: empty script returns empty array', [] === $producer->parse_script_turns( '' ) );
pp_check( 'normalization: script with no speaker tags returns empty array', [] === $producer->parse_script_turns( 'Απλό κείμενο χωρίς ομιλητές.' ) );


// =========================================================================
// 6. Script Persistence (save_script and get_script)
// =========================================================================

$test_date_persist = '2026-08-26';
$script_to_save = "[Μαρία]: Καλημέρα!\n[Νίκος]: Καλημέρα σε όλους!";

$save_ok = $producer->save_script( $test_date_persist, $script_to_save );
pp_check( 'persistence: save_script returns true', true === $save_ok );

$loaded_script = $producer->get_script( $test_date_persist );
pp_check( 'persistence: get_script returns saved content', $loaded_script === $script_to_save );

$missing_script = $producer->get_script( '1999-12-31' );
pp_check( 'persistence: get_script returns null for non-existent date', null === $missing_script );


// =========================================================================
// 7. End-to-End generate_dialogue_script() Workflow
// =========================================================================

$harvester = new PressHub_AI_News_Harvester();
$test_date_e2e = '2026-08-26';
$harvester->save_snapshot( $test_date_e2e, [
    'date'            => $test_date_e2e,
    'harvested_at'    => gmdate( 'c' ),
    'sources'         => [ 'https://kathimerini.gr' ],
    'blocked_sources' => [],
    'articles'        => [
        [
            'title'   => 'Νέο τεχνολογικό πάρκο στην Αθήνα',
            'source'  => 'Kathimerini',
            'url'     => 'https://kathimerini.gr/tech-hub',
            'content' => 'Επένδυση 50 εκατ. ευρώ για καινοτόμες νεοφυείς επιχειρήσεις.',
        ],
    ],
] );

class Mock_Podcast_API_Client extends PressHub_AI_API_Client {
    public function __construct() {}
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        return "[Μαρία]: Καλημέρα σας! Σήμερα ξεκινάμε με μια μεγάλη επένδυση στην τεχνολογία.\n"
            . "[Νίκος]: Πράγματι Μαρία, ανακοινώθηκε το νέο τεχνολογικό πάρκο στην Αθήνα.\n"
            . "[Μαρία]: Μια επένδυση ύψους 50 εκατομμυρίων ευρώ.\n"
            . "[Νίκος]: Ακριβώς, που θα δώσει ώθηση στις ελληνικές startups.";
    }
}

$mock_client = new Mock_Podcast_API_Client();
$gen_result = $producer->generate_dialogue_script( $test_date_e2e, $mock_client, 'podcast-morning-coffee', '3_min' );

pp_check( 'e2e: returns array on success', is_array( $gen_result ) );
pp_check( 'e2e: contains date', ( $gen_result['date'] ?? '' ) === $test_date_e2e );
pp_check( 'e2e: raw_script is present', ! empty( $gen_result['raw_script'] ) );
pp_check( 'e2e: turns count is 4', ( $gen_result['turns_count'] ?? 0 ) === 4 );
pp_check( 'e2e: turns array has valid structure', isset( $gen_result['turns'][0]['speaker'] ) && 'female' === $gen_result['turns'][0]['speaker'] );
pp_check( 'e2e: word count is calculated', ( $gen_result['word_count'] ?? 0 ) > 10 );
pp_check( 'e2e: script was saved to storage', $producer->get_script( $test_date_e2e ) === $gen_result['raw_script'] );

pp_check( 'e2e: context_mode returned in result payload', ( $gen_result['context_mode'] ?? '' ) === 'curated_briefing' );

// Test 7b: Error handling when no snapshot exists and no briefing
$err_no_snapshot = $producer->generate_dialogue_script( '1985-05-15', $mock_client, '', '', 'harvested_articles' );
pp_check( 'e2e: returns WP_Error when snapshot missing', is_wp_error( $err_no_snapshot ) && 'no_articles' === $err_no_snapshot->get_error_code() );

// Test 7c: Error handling when AI response is unparseable
class Mock_Bad_Dialogue_API_Client extends PressHub_AI_API_Client {
    public function __construct() {}
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        return "Αυτό είναι ένα απλό κείμενο χωρίς ετικέτες ομιλητών.";
    }
}
$bad_client = new Mock_Bad_Dialogue_API_Client();
$err_bad_format = $producer->generate_dialogue_script( $test_date_e2e, $bad_client );
pp_check( 'e2e: returns WP_Error when response has no speaker turns', is_wp_error( $err_bad_format ) && 'invalid_dialogue_format' === $err_bad_format->get_error_code() );


// =========================================================================
// 8. Context Mode: 'curated_briefing' vs Fallback
// =========================================================================

$briefing_date = '2026-08-26';
$mock_briefing_story = "# Πρωινή Ενημέρωση: Σημαντικές Εξελίξεις\n\nΣυνοπτική εικόνα των σημερινών γεγονότων.";

// Test 8a: Explicit briefing_text passed in
$curated_prompt = $producer->build_dialogue_prompt(
    $sample_articles,
    '__none__',
    '5_min',
    $briefing_date,
    'curated_briefing',
    [],
    $mock_briefing_story
);
pp_check( 'curated_briefing: user_prompt contains curated briefing text', false !== strpos( $curated_prompt['user_prompt'], 'Σημαντικές Εξελίξεις' ) );
pp_check( 'curated_briefing: user_prompt mentions Curated Morning Briefing', false !== strpos( $curated_prompt['user_prompt'], 'Curated Morning Briefing' ) );
pp_check( 'curated_briefing: system_prompt does NOT contain raw articles', false === strpos( $curated_prompt['system_prompt'], 'Νέο φορολογικό νομοσχέδιο' ) );

// Test 8b: Fetching briefing text via NewsCurator snapshot file
$file_dir = $harvester->get_snapshot_dir( '2026-08-29' );
if ( ! is_dir( $file_dir ) ) {
    mkdir( $file_dir, 0777, true );
}
file_put_contents( trailingslashit( $file_dir ) . 'briefing-text.md', "# Briefing 29ης Αυγούστου\n\nΑυτόματο κείμενο ενημέρωσης." );

$auto_curated_prompt = $producer->build_dialogue_prompt(
    [],
    '__none__',
    '5_min',
    '2026-08-29',
    'curated_briefing'
);
pp_check( 'curated_briefing: auto-fetches briefing file from snapshot dir', false !== strpos( $auto_curated_prompt['user_prompt'], 'Briefing 29ης Αυγούστου' ) );

// Test 8c: Fallback to harvested articles if briefing text does not exist
$fallback_prompt = $producer->build_dialogue_prompt(
    $sample_articles,
    '__none__',
    '5_min',
    '2026-08-01', // Date with no briefing post or file
    'curated_briefing'
);
pp_check( 'curated_briefing: falls back to harvested articles context when briefing absent', false !== strpos( $fallback_prompt['user_prompt'], 'Ψηφιακές υπηρεσίες υγείας' ) );


// =========================================================================
// 9. Context Mode: 'harvested_articles' & Selective Article Filtering
// =========================================================================

$multi_articles = [
    [
        'id'      => 'pod-1',
        'title'   => 'Πρώτο Άρθρο Podcast',
        'source'  => 'Kathimerini',
        'url'     => 'https://kathimerini.gr/p1',
        'content' => 'Κείμενο 1.',
    ],
    [
        'id'      => 'pod-2',
        'title'   => 'Δεύτερο Άρθρο Podcast',
        'source'  => 'In.gr',
        'url'     => 'https://in.gr/p2',
        'content' => 'Κείμενο 2.',
    ],
];

// Test 9a: Filter by ID in harvested_articles mode
$filtered_pod_prompt = $producer->build_dialogue_prompt(
    $multi_articles,
    '__none__',
    '5_min',
    '2026-08-26',
    'harvested_articles',
    [ 'pod-2' ]
);
pp_check( 'harvested_articles: filtering includes only selected article in user_prompt', false !== strpos( $filtered_pod_prompt['user_prompt'], 'Δεύτερο Άρθρο Podcast' ) && false === strpos( $filtered_pod_prompt['user_prompt'], 'Πρώτο Άρθρο Podcast' ) );

// Test 9b: System prompt has no raw article content (deduplication)
pp_check( 'harvested_articles: system_prompt has no duplicate raw article context', false === strpos( $filtered_pod_prompt['system_prompt'], 'Κείμενο 2' ) );


// =========================================================================
// 10. Issue #71: Topic Markers, parse_script_topics(), and 1/2/3 Host Prompts
// =========================================================================

// Test 10a: Explicit Topic Markers parsing
$topic_script = <<<SCRIPT
[TOPIC_START: Εισαγωγή & Τίτλοι Ειδήσεων]
[Μαρία]: Καλωσήρθατε στην Πρωινή Ενημέρωση του PressHub.
[Νίκος]: Καλημέρα Μαρία, ας δούμε τα πρωτοσέλιδα.
[TOPIC_END]

[TOPIC_START: Οικονομία & Αγορές]
[Μαρία]: Στην οικονομία έχουμε θετικά νέα για τον πληθωρισμό.
[Νίκος]: Πράγματι, η αποκλιμάκωση συνεχίζεται με ταχείς ρυθμούς.
[TOPIC_END]

[TOPIC_START: Διεθνή Γεγονότα]
[Μαρία]: Στα διεθνή, εξελίξεις έχουμε στην Ευρωπαϊκή Ένωση.
[Νίκος]: Σημαντικές αποφάσεις αναμένονται στη σύνοδο κορυφής.
[TOPIC_END]
SCRIPT;

$topics = $producer->parse_script_topics( $topic_script, 'Μαρία', 'Νίκος' );
pp_check( 'issue_71: parse_script_topics returns 3 topic blocks', count( $topics ) === 3 );
pp_check( 'issue_71: topic 1 title is Εισαγωγή & Τίτλοι Ειδήσεων', ( $topics[0]['title'] ?? '' ) === 'Εισαγωγή & Τίτλοι Ειδήσεων' );
pp_check( 'issue_71: topic 1 has 2 turns', count( $topics[0]['turns'] ?? [] ) === 2 );
pp_check( 'issue_71: topic 2 title is Οικονομία & Αγορές', ( $topics[1]['title'] ?? '' ) === 'Οικονομία & Αγορές' );
pp_check( 'issue_71: topic 3 title is Διεθνή Γεγονότα', ( $topics[2]['title'] ?? '' ) === 'Διεθνή Γεγονότα' );
pp_check( 'issue_71: topic 1 script contains only topic 1 dialogue', false !== strpos( $topics[0]['script'], 'Καλωσήρθατε' ) && false === strpos( $topics[0]['script'], 'πληθωρισμό' ) );

// Test 10b: Fallback chunking when script lacks explicit topic markers
$unmarked_script = <<<SCRIPT
[Μαρία]: Ατάκα 1.
[Νίκος]: Ατάκα 2.
[Μαρία]: Ατάκα 3.
[Νίκος]: Ατάκα 4.
[Μαρία]: Ατάκα 5.
[Νίκος]: Ατάκα 6.
[Μαρία]: Ατάκα 7.
[Νίκος]: Ατάκα 8.
[Μαρία]: Ατάκα 9.
[Νίκος]: Ατάκα 10.
SCRIPT;

$fallback_topics = $producer->parse_script_topics( $unmarked_script, 'Μαρία', 'Νίκος' );
pp_check( 'issue_71: fallback chunking splits 10 turns into multiple chunks', count( $fallback_topics ) >= 2 );
pp_check( 'issue_71: each fallback chunk contains turns', ! empty( $fallback_topics[0]['turns'] ) );

// Test 10c: 3-Host turn parsing
$three_host_script = <<<SCRIPT
[Μαρία]: Καλωσήρθατε στην εκπομπή.
[Νίκος]: Καλημέρα Μαρία.
[Κώστας]: Καλημέρα σε όλους, έχουμε ενδιαφέρουσα ανάλυση σήμερα.
SCRIPT;

$three_turns = $producer->parse_script_turns( $three_host_script, 'Μαρία', 'Νίκος', 'Κώστας' );
pp_check( 'issue_71: parse_script_turns supports 3 speakers', count( $three_turns ) === 3 );
pp_check( 'issue_71: speaker 1 is female Μαρία', ( $three_turns[0]['speaker'] ?? '' ) === 'female' );
pp_check( 'issue_71: speaker 2 is male Νίκος', ( $three_turns[1]['speaker'] ?? '' ) === 'male' );
pp_check( 'issue_71: speaker 3 is tertiary Κώστας', ( $three_turns[2]['speaker'] ?? '' ) === 'tertiary' && ( $three_turns[2]['speaker_name'] ?? '' ) === 'Κώστας' );

// Test 10d: Host count prompt generation
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_count'] = 1;
$solo_prompt = $producer->build_dialogue_prompt( [], '', '3_min', '2026-09-01' );
pp_check( 'issue_71: 1 host prompt focuses on solo presenter', false !== strpos( $solo_prompt['system_prompt'], 'έναν κεντρικό παρουσιαστή' ) || false !== strpos( $solo_prompt['system_prompt'], 'μονόλογο' ) || false !== strpos( $solo_prompt['system_prompt'], 'έναν παρουσιαστή' ) );

$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_count'] = 3;
$GLOBALS['OPTIONS_STORE']['presshub_ai_briefing_host_tertiary'] = 'Κώστας';
$panel_prompt = $producer->build_dialogue_prompt( [], '', '5_min', '2026-09-01' );
// Issue #90 — host names are no longer in the system prompt; expect generic labels instead.
pp_check( 'issue_71: 3 host prompt contains generic [SPEAKER_3]: tag', false !== strpos( $panel_prompt['system_prompt'], '[SPEAKER_3]:' ) );
pp_check( 'issue_71: 3 host prompt mentions 3 presenters', false !== strpos( $panel_prompt['system_prompt'], 'τρεις' ) || false !== strpos( $panel_prompt['system_prompt'], 'τριών' ) || false !== strpos( $panel_prompt['system_prompt'], '[SPEAKER_1]:, [SPEAKER_2]' ) );
pp_check( 'issue_71: 3 host prompt does NOT contain literal host name', false === strpos( $panel_prompt['system_prompt'], 'Κώστας' ) );
pp_check( 'issue_71: system prompt instructs topic markers', false !== strpos( $panel_prompt['system_prompt'], 'TOPIC_START' ) );



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
    fwrite( STDERR, "PodcastProducerTest: {$failures} failure(s)\n" );
    exit( 1 );
}
echo "PodcastProducerTest: OK (75 checks)\n";
