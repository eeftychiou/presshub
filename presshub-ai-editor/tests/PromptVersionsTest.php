<?php
/**
 * PromptVersionsTest (2.82.0) - prompt studio history.
 *
 * Covers:
 *   1. A saved prompt becomes a numbered version; an unchanged save adds none;
 *      saving an earlier text again is a new version "restored from vN".
 *   2. An emptied curator / QA prompt is kept as the built-in default it falls
 *      back to; an emptied podcast or Shorts prompt (off) adds no version.
 *   3. The first history keeps the prompts in place once (baseline / builtin).
 *   4. ref() names the newest version with the same text, and makes one for a
 *      text written around WordPress.
 *   5. A generation record with config.prompt_versions is linked to its
 *      versions (uses, counts, records filter); prune drops old links.
 *   6. The briefing text and the podcast script record the version they used
 *      (the template before placeholders); the Shorts, social copy and API
 *      client paths pass theirs on (source wiring).
 *   7. Diff: word level inside changed lines, removed text in <del>, added in
 *      <ins>, HTML escaped, word counts.
 *   8. Settings page data, history links, capabilities and uninstall entries.
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
$checks   = 0;
function pv_check( string $label, $condition ): void {
    global $failures, $checks;
    $checks++;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        $failures++;
    }
}

global $wpdb;
$GLOBALS['OPTIONS_STORE']   = [];
$GLOBALS['CURRENT_USER_ID'] = 7;
$GLOBALS['CURRENT_USER_CAPS'] = [ 'edit_others_posts', 'edit_posts' ];
$test_upload_dir            = sys_get_temp_dir() . '/presshub-pv-test-' . uniqid();
$GLOBALS['UPLOAD_DIR']      = $test_upload_dir;

PressHub_AI_Generation_Recorder::create_table();
PressHub_AI_Prompt_Versions::create_tables();
$vt = PressHub_AI_Prompt_Versions::versions_table();
$ut = PressHub_AI_Prompt_Versions::uses_table();
pv_check( 'tables created', isset( $wpdb->tables[ $vt ] ) && isset( $wpdb->tables[ $ut ] ) );
pv_check( 'db version option set', PressHub_AI_Prompt_Versions::DB_VERSION === get_option( PressHub_AI_Prompt_Versions::DB_VERSION_OPTION ) );

// -------------------------------------------------------------------------
// 1. Saves become versions
// -------------------------------------------------------------------------
$key = 'presshub_ai_briefing_text_prompt';
pv_check( 'curator prompt is tracked', PressHub_AI_Prompt_Versions::is_tracked( $key ) );
pv_check( 'all 9 podcast prompts are tracked', 9 === count( array_filter( array_keys( PressHub_AI_Prompt_Versions::tracked() ), static function ( $k ) {
    return 0 === strpos( $k, 'presshub_ai_briefing_podcast_prompt_' );
} ) ) );
foreach ( [ 'presshub_social_prompt_tiktok_shorts', 'presshub_social_prompt_tiktok_article_short', 'presshub_social_prompt_system', 'presshub_social_prompt_twitter', 'presshub_ai_qa_article_prompt' ] as $k ) {
    pv_check( "tracked: {$k}", PressHub_AI_Prompt_Versions::is_tracked( $k ) );
}
pv_check( 'untracked option is not', ! PressHub_AI_Prompt_Versions::is_tracked( 'presshub_ai_log_level' ) );

$v1_text = "Είσαι επιμελητής ειδήσεων για {date}.\r\nΓράψε σύντομα.\n";
update_option( $key, $v1_text );
$versions = PressHub_AI_Prompt_Versions::versions( $key );
pv_check( 'save: one version', 1 === count( $versions ) );
pv_check( 'save: v1 numbered 1', 1 === ( $versions[0]['version'] ?? 0 ) );
pv_check( 'save: text normalised (LF, trimmed)', "Είσαι επιμελητής ειδήσεων για {date}.\nΓράψε σύντομα." === ( $versions[0]['content'] ?? '' ) );
pv_check( 'save: source saved, user stored', 'saved' === $versions[0]['source'] && 7 === $versions[0]['user_id'] );

update_option( $key, $v1_text . "\n\n" );
pv_check( 'unchanged save (only whitespace): no new version', 1 === count( PressHub_AI_Prompt_Versions::versions( $key ) ) );

$v2_text = "Είσαι έμπειρος επιμελητής ειδήσεων για {date}.\nΓράψε σύντομα και με πηγές.";
update_option( $key, $v2_text );
$versions = PressHub_AI_Prompt_Versions::versions( $key );
pv_check( 'change: v2 added, newest first', 2 === count( $versions ) && 2 === $versions[0]['version'] && $v2_text === $versions[0]['content'] );

update_option( $key, $v1_text );
$versions = PressHub_AI_Prompt_Versions::versions( $key );
pv_check( 'restore: v3 added', 3 === count( $versions ) && 3 === $versions[0]['version'] );
pv_check( 'restore: marked restored from v1', 'restored' === $versions[0]['source'] && 1 === $versions[0]['restored_from'] );
pv_check( 'restore: source label says so', 'Restored from v1' === PressHub_AI_Prompt_Versions::source_label( $versions[0] ) );
pv_check( 'latest() is v3', 3 === ( PressHub_AI_Prompt_Versions::latest( $key )['version'] ?? 0 ) );

// -------------------------------------------------------------------------
// 2. Empty values
// -------------------------------------------------------------------------
$default_curation = PressHub_AI_Prompt_Versions::normalize( PressHub_AI_Prompt_Loader::get_curation_prompt() );
update_option( $key, '' );
$latest = PressHub_AI_Prompt_Versions::latest( $key );
pv_check( 'emptied curator prompt: kept as the built-in default', $default_curation === ( $latest['content'] ?? '' ) && 'builtin' === $latest['source'] && 4 === $latest['version'] );
pv_check( 'current_text() follows the fallback', $default_curation === PressHub_AI_Prompt_Versions::current_text( $key ) );

$pod_key = PressHub_AI_Prompt_Versions::podcast_key( 2, 'default_greek_chat' );
update_option( $pod_key, '[SPEAKER_1]: δοκιμή {date}' );
update_option( $pod_key, '   ' );
pv_check( 'emptied podcast prompt (off): no version for empty', 1 === count( PressHub_AI_Prompt_Versions::versions( $pod_key ) ) );
pv_check( 'emptied podcast prompt: current_text empty', '' === PressHub_AI_Prompt_Versions::current_text( $pod_key ) );

$before_tables = count( $wpdb->tables[ $vt ] );
$saved_db_version = get_option( PressHub_AI_Prompt_Versions::DB_VERSION_OPTION );
unset( $GLOBALS['OPTIONS_STORE'][ PressHub_AI_Prompt_Versions::DB_VERSION_OPTION ] );
update_option( 'presshub_social_prompt_facebook', 'Seeded before the tables exist' );
pv_check( 'before the tables exist: no insert (baseline keeps it later)', count( $wpdb->tables[ $vt ] ) === $before_tables );
update_option( PressHub_AI_Prompt_Versions::DB_VERSION_OPTION, $saved_db_version );

// -------------------------------------------------------------------------
// 3. Baseline, once
// -------------------------------------------------------------------------
$qa_key = 'presshub_ai_qa_briefing_prompt';
$GLOBALS['OPTIONS_STORE']['presshub_social_prompt_twitter'] = 'Γράψε tweet για {title}';
unset( $GLOBALS['OPTIONS_STORE'][ PressHub_AI_Prompt_Versions::BASELINE_OPTION ] );
PressHub_AI_Prompt_Versions::maybe_upgrade();
$tw = PressHub_AI_Prompt_Versions::versions( 'presshub_social_prompt_twitter' );
pv_check( 'baseline: stored prompt kept as baseline', 1 === count( $tw ) && 'baseline' === $tw[0]['source'] && 'Γράψε tweet για {title}' === $tw[0]['content'] );
$qa = PressHub_AI_Prompt_Versions::versions( $qa_key );
pv_check( 'baseline: never-saved QA prompt kept as builtin default', 1 === count( $qa ) && 'builtin' === $qa[0]['source'] );
pv_check( 'baseline: curator not duplicated', 4 === count( PressHub_AI_Prompt_Versions::versions( $key ) ) );
$before = count( $wpdb->tables[ $vt ] );
PressHub_AI_Prompt_Versions::maybe_upgrade();
pv_check( 'baseline: runs once', count( $wpdb->tables[ $vt ] ) === $before );

// -------------------------------------------------------------------------
// 4. ref()
// -------------------------------------------------------------------------
$ref = PressHub_AI_Prompt_Versions::ref( $key, $v1_text );
pv_check( 'ref: newest version with the same text (v3, not v1)', is_array( $ref ) && 3 === $ref['version'] && $key === $ref['key'] );
pv_check( 'ref: carries the label', 'Daily Briefing: curator system prompt' === ( $ref['label'] ?? '' ) );
$count_before = count( PressHub_AI_Prompt_Versions::versions( $key ) );
$ref_new      = PressHub_AI_Prompt_Versions::ref( $key, 'Κείμενο που γράφτηκε απευθείας στη βάση.' );
pv_check( 'ref: unknown text becomes a version', is_array( $ref_new ) && $count_before + 1 === count( PressHub_AI_Prompt_Versions::versions( $key ) ) && $ref_new['version'] === $count_before + 1 );
pv_check( 'ref: untracked key gives null', null === PressHub_AI_Prompt_Versions::ref( 'presshub_ai_log_level', 'x' ) );
pv_check( 'ref: empty text gives null', null === PressHub_AI_Prompt_Versions::ref( $key, "  \n" ) );
pv_check( 'clean_refs drops nulls', 1 === count( PressHub_AI_Prompt_Versions::clean_refs( [ null, $ref, [ 'version_id' => 0 ] ] ) ) );

// -------------------------------------------------------------------------
// 5. Uses
// -------------------------------------------------------------------------
$rid = (int) PressHub_AI_Generation_Recorder::record( [
    'kind'   => 'briefing_text',
    'config' => [ 'model' => 'm', 'prompt_versions' => [ $ref, $ref ] ],
] );
$rid2 = (int) PressHub_AI_Generation_Recorder::record( [
    'kind'   => 'podcast_script',
    'config' => [ 'prompt_versions' => [ $ref ] ],
] );
PressHub_AI_Generation_Recorder::record( [ 'kind' => 'generic', 'config' => [] ] );
pv_check( 'uses: one link per record and version (duplicates folded)', 2 === PressHub_AI_Prompt_Versions::count_uses( $ref['version_id'] ) );
pv_check( 'uses: record ids newest first', [ $rid2, $rid ] === PressHub_AI_Prompt_Versions::record_ids_for_version( $ref['version_id'] ) );
pv_check( 'uses: limit honoured', [ $rid2 ] === PressHub_AI_Prompt_Versions::record_ids_for_version( $ref['version_id'], 1 ) );
pv_check( 'uses: unused version counts 0', 0 === PressHub_AI_Prompt_Versions::count_uses( $ref_new['version_id'] ) );
$rec = PressHub_AI_Generation_Recorder::get( $rid );
pv_check( 'record keeps prompt_versions in config', (int) ( $rec['config']['prompt_versions'][0]['version_id'] ?? 0 ) === $ref['version_id'] );

$filtered = PressHub_AI_Generation_Inspector::query_records( [ 'prompt_version' => $ref['version_id'] ] );
pv_check( 'records filter: only the generations that used the version', 2 === count( $filtered ) && in_array( $filtered[0]['id'], [ $rid, $rid2 ], true ) );
pv_check( 'records filter: list rows carry prompt_versions', ! empty( $filtered[0]['prompt_versions'] ) );
pv_check( 'records filter: unused version lists nothing', [] === PressHub_AI_Generation_Inspector::query_records( [ 'prompt_version' => $ref_new['version_id'] ] ) );
pv_check( 'records filter: sanitised', [ 'prompt_version' => 5 ] === PressHub_AI_Generation_Inspector::sanitize_filters( [ 'prompt_version' => '5' ] ) && [] === PressHub_AI_Generation_Inspector::sanitize_filters( [ 'prompt_version' => '-3' ] ) );

$overview = PressHub_AI_Generation_Inspector::render_tab( $rec, 'overview' );
pv_check( 'inspector: shows the versions used', false !== strpos( $overview, 'Prompt versions used' ) && false !== strpos( $overview, 'v3' ) && false !== strpos( $overview, 'Daily Briefing: curator system prompt' ) );
pv_check( 'inspector: links the history and the other generations', false !== strpos( $overview, 'prompt_history=presshub_ai_briefing_text_prompt' ) && false !== strpos( $overview, 'prompt_version=' . $ref['version_id'] ) );
$page = PressHub_AI_Generation_Inspector::build_records_page( [ 'prompt_version' => (string) $ref['version_id'] ] );
pv_check( 'records page: says which version it shows', false !== strpos( $page, 'Showing generations that used Daily Briefing: curator system prompt v3.' ) );
pv_check( 'records page: prompt version column', false !== strpos( $page, 'Prompt version' ) );

$payload = PressHub_AI_Prompt_Versions::version_payload( PressHub_AI_Prompt_Versions::get( $ref['version_id'] ) );
pv_check( 'version payload: text and uses', $payload['content'] === PressHub_AI_Prompt_Versions::normalize( $v1_text ) && 2 === $payload['uses_count'] && 2 === count( $payload['uses'] ) );
$list = PressHub_AI_Prompt_Versions::list_payload( $key );
$current = array_values( array_filter( $list['versions'], static function ( $v ) { return $v['current']; } ) );
pv_check( 'list payload: newest first with use counts', $list['versions'][0]['version'] > $list['versions'][1]['version'] && 2 === $list['versions'][ count( $list['versions'] ) - 3 ]['uses'] );
pv_check( 'list payload: the version in force is marked', 1 === count( $current ) && $default_curation === PressHub_AI_Prompt_Versions::get( $current[0]['id'] )['content'] );

foreach ( $wpdb->tables[ $ut ] as &$use_row ) {
    $use_row['created_at'] = '2000-01-01 00:00:00';
}
unset( $use_row );
PressHub_AI_Generation_Recorder::prune( 30 );
pv_check( 'prune: old links dropped with the records', 0 === PressHub_AI_Prompt_Versions::count_uses( $ref['version_id'] ) );
pv_check( 'prune: versions are kept', count( PressHub_AI_Prompt_Versions::versions( $key ) ) >= 5 );

// -------------------------------------------------------------------------
// 6. Call sites
// -------------------------------------------------------------------------
class PV_Stub_Client extends PressHub_AI_API_Client {
    public $reply = "# Τίτλος\n\nΚείμενο.";
    public function __construct() {}
    public function call_provider( $sys_prompt, $user_prompt, $json_mode = false, $files = [], $temperature = null, array $metadata = [] ) {
        return $this->reply;
    }
    public function get_provider(): string { return 'gemini'; }
    public function get_model(): string { return 'stub-model'; }
}
$harvester = new PressHub_AI_News_Harvester();
$date      = '2026-11-20';
$harvester->save_snapshot( $date, [
    'date' => $date, 'harvested_at' => gmdate( 'c' ), 'sources' => [], 'blocked_sources' => [],
    'articles' => [
        [ 'id' => 'a1', 'title' => 'Πρώτο', 'source' => 'S1', 'url' => 'https://s1.example/1', 'content' => 'Κείμενο ένα' ],
        [ 'id' => 'a2', 'title' => 'Δεύτερο', 'source' => 'S2', 'url' => 'https://s2.example/2', 'content' => 'Κείμενο δύο' ],
    ],
] );
$curator_template = "Επιμελητής για {date}. Πηγές: {sources_list}";
update_option( $key, $curator_template );
$curator_version = PressHub_AI_Prompt_Versions::latest( $key );
$res  = ( new PressHub_AI_News_Curator() )->generate_briefing( $date, new PV_Stub_Client(), '__none__', [], 'morning' );
$brec = PressHub_AI_Generation_Recorder::get( (int) ( $res['generation_record_id'] ?? 0 ) );
$bref = $brec['config']['prompt_versions'][0] ?? [];
pv_check( 'briefing: record names the curator version used', $key === ( $bref['key'] ?? '' ) && (int) ( $bref['version_id'] ?? 0 ) === $curator_version['id'] );
pv_check( 'briefing: the version holds the template, the record the filled prompt', false !== strpos( $curator_version['content'], '{date}' ) && false === strpos( (string) $brec['system_prompt'], '{date}' ) );
pv_check( 'briefing: use linked', in_array( (int) $brec['id'], PressHub_AI_Prompt_Versions::record_ids_for_version( $curator_version['id'] ), true ) );
pv_check( 'briefing: post links the record (and so the version)', in_array( (int) $brec['id'], (array) get_post_meta( (int) $res['post_id'], PressHub_AI_Generation_Recorder::POST_META_KEY, true ), true ) );

update_option( 'presshub_ai_briefing_host_count', 2 );
update_option( 'presshub_ai_briefing_podcast_style', 'bbc_broadcasting_standards' );
$pod_key2     = PressHub_AI_Prompt_Versions::podcast_key( 2, 'bbc_broadcasting_standards' );
$pod_template = "Δύο παρουσιαστές για {date}.\n{articles_context}\n[SPEAKER_1]: [SPEAKER_2]:";
update_option( $pod_key2, $pod_template );
$pod_version = PressHub_AI_Prompt_Versions::latest( $pod_key2 );
$pod_client  = new PV_Stub_Client();
$pod_client->reply = "[SPEAKER_1]: Καλημέρα!\n[SPEAKER_2]: Καλημέρα σας.";
$pres = ( new PressHub_AI_Podcast_Producer() )->generate_dialogue_script( $date, $pod_client, '__none__', '3_min', 'harvested_articles', [], 'morning' );
$prec = PressHub_AI_Generation_Recorder::get( (int) ( $pres['generation_record_id'] ?? 0 ) );
$pref = $prec['config']['prompt_versions'][0] ?? [];
pv_check( 'podcast: record names the style and presenter prompt version used', $pod_key2 === ( $pref['key'] ?? '' ) && (int) ( $pref['version_id'] ?? 0 ) === (int) ( $pod_version['id'] ?? -1 ) );
pv_check( 'podcast: label names style and presenters', 'Podcast script: BBC Broadcasting Standards, 2 presenters' === PressHub_AI_Prompt_Versions::label( $pod_key2 ) );

$shorts_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-social-tiktok-shorts.php' );
pv_check( 'shorts: plan refs the script prompt and the system prompt', false !== strpos( $shorts_src, "PressHub_AI_Prompt_Versions::ref( \$article ? 'presshub_social_prompt_tiktok_article_short' : 'presshub_social_prompt_tiktok_shorts', \$prompt )" )
    && false !== strpos( $shorts_src, "PressHub_AI_Prompt_Versions::ref( 'presshub_social_prompt_system', \$sys_prompt )" ) );
pv_check( 'shorts: script record config carries them', false !== strpos( $shorts_src, "'prompt_versions' => self::\$prompt_versions" ) );
$copy_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-social-ai-copywriter.php' );
pv_check( 'social copy: system and platform prompt versions on the record', false !== strpos( $copy_src, "PressHub_AI_Prompt_Versions::ref( 'presshub_social_prompt_' . \$platform, \$user_template )" ) );
$api_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-api-client.php' );
pv_check( 'api client: generic record takes generation_record_config', false !== strpos( $api_src, "'config' => \$this->generation_record_config" ) );

// -------------------------------------------------------------------------
// 7. Diff
// -------------------------------------------------------------------------
$old = "Είσαι δημοσιογράφος.\nΓράψε σύντομα και καθαρά.\nΜην εφευρίσκεις γεγονότα.";
$new = "Είσαι έμπειρος δημοσιογράφος.\nΓράψε καθαρά.\nΜην εφευρίσκεις γεγονότα.\nΠρόσθεσε πηγές <b>πάντα</b>.";
$html = PressHub_AI_Prompt_Versions::diff_html( $old, $new );
pv_check( 'diff: added word bold (ins)', false !== strpos( $html, '<ins class="presshub-pv-ins">έμπειρος </ins>' ) );
pv_check( 'diff: removed words struck (del)', 1 === preg_match( '#<del class="presshub-pv-del">\s?σύντομα και\s?</del>#u', $html ) );
pv_check( 'diff: unchanged line plain', false !== strpos( $html, 'Μην εφευρίσκεις γεγονότα.' ) && false === strpos( $html, '<del class="presshub-pv-del">Μην' ) );
pv_check( 'diff: HTML in prompts escaped', false !== strpos( $html, '&lt;b&gt;πάντα&lt;/b&gt;' ) && false === strpos( $html, '<b>' ) );
$stats = PressHub_AI_Prompt_Versions::diff_stats( $old, $new );
pv_check( 'diff: word counts', 4 === $stats['added'] && 2 === $stats['removed'] );
pv_check( 'diff: identical texts have no changes', [ [ '=', 'a b' ] ] === PressHub_AI_Prompt_Versions::diff_ops( 'a b', 'a b' ) );
pv_check( 'diff: replaced phrase reads as one struck and one bold run', [ [ '-', 'one two three' ], [ '+', 'alpha beta gamma' ] ] === PressHub_AI_Prompt_Versions::diff_ops( 'one two three', 'alpha beta gamma' ) );
$rebuilt_old = '';
$rebuilt_new = '';
foreach ( PressHub_AI_Prompt_Versions::diff_ops( $old, $new ) as $op ) {
    $rebuilt_old .= '+' === $op[0] ? '' : $op[1];
    $rebuilt_new .= '-' === $op[0] ? '' : $op[1];
}
pv_check( 'diff: ops rebuild both texts exactly', $rebuilt_old === $old && $rebuilt_new === $new );
$big_old = implode( "\n", array_map( static function ( $i ) { return 'Γραμμή ' . $i . ' με λέξεις'; }, range( 1, 800 ) ) );
$big_new = str_replace( 'Γραμμή 400 με', 'Γραμμή 400 χωρίς', $big_old ) . "\nΝέα γραμμή";
$t0 = microtime( true );
$big = PressHub_AI_Prompt_Versions::diff_stats( $big_old, $big_new );
pv_check( 'diff: an 800-line prompt diffs quickly and exactly', 3 === $big['added'] && 1 === $big['removed'] && ( microtime( true ) - $t0 ) < 5 );

// -------------------------------------------------------------------------
// 8. Settings page, links, capabilities, uninstall
// -------------------------------------------------------------------------
$_GET['prompt_history'] = $key;
$_GET['prompt_version'] = '12';
$data = PressHub_AI_Prompt_Versions::script_data();
pv_check( 'script data: every tracked prompt listed with its label', count( $data['prompts'] ) === count( PressHub_AI_Prompt_Versions::tracked() ) && 'Daily Briefing: curator system prompt' === $data['prompts'][ $key ] );
pv_check( 'script data: opens the linked history', $key === $data['open'] && 12 === $data['open_version'] );
pv_check( 'script data: strings', '' !== ( $data['strings']['restored'] ?? '' ) && false !== strpos( $data['strings']['stats'], '%1$d' ) );
$_GET = [];
$url = PressHub_AI_Prompt_Versions::history_url( $key, 9 );
pv_check( 'history url: the prompt\'s section and field', false !== strpos( $url, 'section=daily-briefing' ) && false !== strpos( $url, 'prompt_version=9' ) && '#' . $key === substr( $url, -strlen( '#' . $key ) ) );
pv_check( 'history url: social prompts open Social publishing', false !== strpos( PressHub_AI_Prompt_Versions::history_url( 'presshub_social_prompt_tiktok_shorts' ), 'section=social-publishing' ) );
pv_check( 'capability: social prompts use the social capability', PressHub_Capabilities::social() === PressHub_AI_Prompt_Versions::capability_for( 'presshub_social_prompt_system' ) );
pv_check( 'capability: others use the settings capability', PressHub_Capabilities::manage() === PressHub_AI_Prompt_Versions::capability_for( $key ) );

$settings_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-settings.php' );
pv_check( 'settings page enqueues the history script with its data', false !== strpos( $settings_src, "assets/js/prompt-versions.js" ) && false !== strpos( $settings_src, "'presshubPromptVersions', PressHub_AI_Prompt_Versions::script_data()" ) );
$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/prompt-versions.js' );
pv_check( 'js: no alert/confirm', 0 === preg_match( '/\b(alert|confirm)\s*\(/', $js ) );
pv_check( 'js: restore fills the editor and does not save', false !== strpos( $js, ".val(res.data.content).trigger('input').trigger('change')" ) && false === strpos( $js, 'presshub_ai_save' ) );
pv_check( 'js: uses only the read-only actions', 0 === preg_match( "/request\\('(?!presshub_ai_prompt_version)/", $js ) && substr_count( $js, "request('presshub_ai_prompt_" ) >= 4 );
$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/settings.css' );
pv_check( 'css: struck-through removals and bold additions', 1 === preg_match( '/presshub-pv-del\s*\{[^}]*line-through/', $css ) && 1 === preg_match( '/presshub-pv-ins\s*\{[^}]*font-weight:\s*700/', $css ) );
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' !== $node ) {
    $out = [];
    $code = 0;
    exec( escapeshellarg( $node ) . ' --check ' . escapeshellarg( dirname( __DIR__ ) . '/assets/js/prompt-versions.js' ) . ' 2>&1', $out, $code );
    pv_check( 'js: parses (node --check)', 0 === $code );
}
$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
pv_check( 'uninstall: db version and baseline options listed', false !== strpos( $uninstall, "'presshub_ai_prompt_versions_db_version'" ) && false !== strpos( $uninstall, "'presshub_ai_prompt_versions_baseline'" ) );
$main = (string) file_get_contents( dirname( __DIR__ ) . '/presshub-ai-editor.php' );
pv_check( 'activation creates the tables', false !== strpos( $main, 'PressHub_AI_Prompt_Versions::create_tables();' ) );

// Cleanup
if ( is_dir( $test_upload_dir ) ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $test_upload_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $files as $fileinfo ) {
        $fileinfo->isDir() ? rmdir( $fileinfo->getRealPath() ) : unlink( $fileinfo->getRealPath() );
    }
    rmdir( $test_upload_dir );
}

if ( $failures > 0 ) {
    fwrite( STDERR, "PromptVersionsTest: {$failures} of {$checks} checks failed\n" );
    exit( 1 );
}
echo "PromptVersionsTest: {$checks} checks OK\n";
