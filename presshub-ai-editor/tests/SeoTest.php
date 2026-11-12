<?php
/**
 * Tests for PressHub_AI_SEO:
 * - featured-image source text (post text vs podcast script)
 * - Media Library metadata (title, alt, caption, description, filename) on generated images
 * - automatic tags / meta description on automated posts
 * - deterministic SEO checks and the combined SEO review score
 * - SEO settings sanitizers
 */

// Taxonomy stubs not provided by wordpress-stubs.php.
if ( ! function_exists( 'wp_set_post_tags' ) ) {
    function wp_set_post_tags( $post_id, $tags = '', $append = false ) {
        $GLOBALS['SET_POST_TAGS'][ $post_id ] = [ 'tags' => (array) $tags, 'append' => $append ];
        return [];
    }
}
if ( ! function_exists( 'get_tags' ) ) {
    function get_tags( $args = [] ) {
        return array_map( function ( $n ) { return (object) [ 'name' => $n ]; }, $GLOBALS['SITE_TAGS'] ?? [] );
    }
}

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../includes/class-settings-storage.php';
require_once __DIR__ . '/../includes/class-prompt-loader.php';
require_once __DIR__ . '/../includes/class-provider-defaults.php';
require_once __DIR__ . '/../includes/class-provider-store.php';
require_once __DIR__ . '/../includes/class-markdown.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-audio-synthesizer.php';
require_once __DIR__ . '/../includes/class-seo.php';

class SeoTest {
    private $failures = [];

    public function run(): void {
        $this->test_settings();
        $this->test_helpers();
        $this->test_resolve_image_source();
        $this->test_image_metadata_ai_and_fallback();
        $this->test_generate_featured_image_writes_metadata();
        $this->test_auto_optimize_post();
        $this->test_run_checks();
        $this->test_review_combines_scores();

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
        $GLOBALS['OPTIONS_STORE']      = [];
        $GLOBALS['POST_META_STORE']    = [];
        $GLOBALS['POSTS_STORE']        = [];
        $GLOBALS['SET_POST_TAGS']      = [];
        $GLOBALS['SITE_TAGS']          = [];
        $GLOBALS['CAPTURED_REQUESTS']  = [];
        $GLOBALS['SIDELOAD_CAPTURE']   = [];
        $GLOBALS['SIDELOAD_RETURN_ID'] = 42;
        $GLOBALS['LLM_QUEUE']          = [];
        $GLOBALS['CAPTURE_FILTER']     = function ( $existing, $req ) {
            $url = $req[0] ?? '';
            if ( false !== strpos( $url, '/v1/images/generations' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [ 'data' => [ [ 'b64_json' => base64_encode( 'IMG' ) ] ] ] ),
                ];
            }
            if ( false !== strpos( $url, '/v1/chat/completions' ) ) {
                $content = array_shift( $GLOBALS['LLM_QUEUE'] );
                if ( null === $content ) {
                    return [ 'response' => [ 'code' => 500 ], 'body' => 'no reply queued' ];
                }
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => json_encode( [ 'choices' => [ [ 'message' => [ 'role' => 'assistant', 'content' => $content ] ] ] ] ),
                ];
            }
            return null;
        };

        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-seo',
            'type'          => 'openai',
            'name'          => 'OpenAI',
            'api_key'       => 'test-key',
            'default_model' => 'gpt-4o',
            'enabled'       => true,
        ] );
        PressHub_AI_Provider_Store::save_provider( [
            'id'            => 'openai-img',
            'type'          => 'openai',
            'name'          => 'OpenAI Images',
            'api_key'       => 'test-key',
            'default_model' => 'dall-e-3',
            'enabled'       => true,
        ] );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_prompt_provider']     = 'openai-seo';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_coauthor_provider']         = 'openai-seo';
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_generation_provider'] = 'openai-img';
    }

    private function chat_requests(): array {
        return array_values( array_filter( $GLOBALS['CAPTURED_REQUESTS'], function ( $r ) {
            return false !== strpos( $r[0], '/v1/chat/completions' );
        } ) );
    }

    public function test_settings(): void {
        $this->reset_env();
        $this->assert( true === PressHub_AI_Settings_Storage::get_image_metadata_enabled(), 'Image metadata defaults to enabled' );
        $this->assert( true === PressHub_AI_Settings_Storage::get_seo_auto_enabled(), 'Auto SEO defaults to enabled' );
        $this->assert( true === PressHub_AI_Settings_Storage::get_seo_review_enabled(), 'SEO review defaults to enabled' );
        $this->assert( 8 === PressHub_AI_Settings_Storage::get_seo_max_tags(), 'Max tags defaults to 8' );
        $this->assert( 15 === PressHub_AI_Settings_Storage::sanitize_seo_max_tags( 99 ), 'Max tags clamps to 15' );
        $this->assert( 1 === PressHub_AI_Settings_Storage::sanitize_seo_max_tags( 1 ), 'Max tags keeps 1' );
        $this->assert( 8 === PressHub_AI_Settings_Storage::sanitize_seo_max_tags( '' ), 'Empty max tags falls back to default' );
        $this->assert( 0 === PressHub_AI_Settings_Storage::sanitize_seo_auto_enabled( '0' ), 'Checkbox sanitizer 0' );
    }

    public function test_helpers(): void {
        $tags = PressHub_AI_SEO::normalize_tags( [ '#Κυπριακό', 'Ευρωπαϊκή Ένωση', 'κυπριακό', ' Τουρκία. ', '', 'a very long tag with too many words in it' ], 3 );
        $this->assert( [ 'Κυπριακό', 'Ευρωπαϊκή Ένωση', 'Τουρκία' ] === $tags, 'normalize_tags strips #, dedupes case-insensitively, trims punctuation and caps: ' . json_encode( $tags, JSON_UNESCAPED_UNICODE ) );

        $this->assert( 'eu-turkey-flags-ankara-ministry' === PressHub_AI_SEO::slugify( 'EU & Turkey flags in Ankara ministry!' ), 'slugify drops stop words and punctuation: ' . PressHub_AI_SEO::slugify( 'EU & Turkey flags in Ankara ministry!' ) );
        $this->assert( '' === PressHub_AI_SEO::slugify( 'Σημαίες' ), 'slugify returns empty for non-Latin text (fallback name applies)' );

        $name = PressHub_AI_SEO::image_filename( [ 'filename' => 'eu-flags' ], 'png' );
        $this->assert( 1 === preg_match( '/^eu-flags-\d{8}-[a-z0-9]{4}\.png$/', $name ), "image_filename format: {$name}" );
        $name = PressHub_AI_SEO::image_filename( [ 'filename' => '' ], 'jpg' );
        $this->assert( 0 === strpos( $name, 'featured-image-' ), "image_filename fallback: {$name}" );

        $this->assert( [ 'a' => 1 ] === PressHub_AI_SEO::decode_json_object( "```json\n{\"a\":1}\n```" ), 'decode_json_object strips fences' );
        $this->assert( [ 'a' => 1 ] === PressHub_AI_SEO::decode_json_object( 'Sure! {"a":1} Done.' ), 'decode_json_object finds embedded object' );
        $this->assert( null === PressHub_AI_SEO::decode_json_object( 'not json' ), 'decode_json_object returns null for prose' );

        $clean = PressHub_AI_SEO::clean_text( "[SPEAKER_1]: Καλημέρα σας!\n[TOPIC: Κυπριακό]\n<p>Η ΕΕ <b>στην</b> Άγκυρα</p>" );
        $this->assert( false === strpos( $clean, 'SPEAKER' ) && false === strpos( $clean, '<b>' ) && false !== strpos( $clean, 'Καλημέρα σας!' ), "clean_text strips speakers/HTML: {$clean}" );
    }

    public function test_resolve_image_source(): void {
        $this->reset_env();
        $GLOBALS['POSTS_STORE'][ 5 ] = [ 'ID' => 5, 'post_title' => 'Podcast 12/11', 'post_content' => '<!-- wp:audio --><figure><audio></audio></figure><!-- /wp:audio -->' ];
        $GLOBALS['POST_META_STORE'][ 5 ]['_presshub_podcast_script']  = "[SPEAKER_1]: Η ΕΕ προτείνει αναβάθμιση της τελωνειακής ένωσης με την Τουρκία.";
        $GLOBALS['POST_META_STORE'][ 5 ]['_presshub_briefing_type']   = 'podcast';

        $src = PressHub_AI_SEO::resolve_image_source( 5, '<!-- wp:audio --><figure><audio></audio></figure><!-- /wp:audio -->', '' );
        $this->assert( 'podcast_script' === $src['source'], 'Podcast posts use the dialogue script' );
        $this->assert( false !== strpos( $src['content'], 'τελωνειακής' ) && false === strpos( $src['content'], 'SPEAKER' ), 'Script is cleaned of speaker labels' );
        $this->assert( 'Podcast 12/11' === $src['title'], 'Title falls back to the post title' );

        $GLOBALS['POSTS_STORE'][ 6 ] = [ 'ID' => 6, 'post_title' => 'Saved', 'post_content' => '<p>Saved body text.</p>' ];
        $src = PressHub_AI_SEO::resolve_image_source( 6, '', 'Editor Title' );
        $this->assert( 'post_content' === $src['source'] && 'Saved body text.' === $src['content'] && 'Editor Title' === $src['title'], 'Regular posts fall back to saved content; editor title wins' );

        $long = str_repeat( 'Κείμενο άρθρου. ', 30 );
        $src  = PressHub_AI_SEO::resolve_image_source( 6, '<p>' . $long . '</p>', 'T' );
        $this->assert( trim( $long ) === $src['content'], 'Editor content wins over the saved post' );
    }

    public function test_image_metadata_ai_and_fallback(): void {
        $this->reset_env();
        $GLOBALS['LLM_QUEUE'][] = json_encode( [
            'title'       => 'Σημαίες ΕΕ και Τουρκίας έξω από υπουργείο στην Άγκυρα',
            'alt_text'    => 'Οι σημαίες της ΕΕ και της Τουρκίας κυματίζουν μπροστά σε κυβερνητικό κτίριο',
            'caption'     => 'Η ΕΕ επιχειρεί επανεκκίνηση των συνομιλιών (εικονογράφηση AI)',
            'description' => 'Σημαίες ΕΕ και Τουρκίας. Κυπριακό, τελωνειακή ένωση.',
            'filename'    => 'EU Turkey flags Ankara',
        ], JSON_UNESCAPED_UNICODE );

        $meta = PressHub_AI_SEO::generate_image_metadata( 'The EU flag and the red Turkish flag outside a ministry in Ankara.', 'Διπλωματική κινητικότητα', 'Κείμενο' );
        $this->assert( 'ai' === $meta['source'], 'AI metadata used when JSON is valid' );
        $this->assert( 'Σημαίες ΕΕ και Τουρκίας έξω από υπουργείο στην Άγκυρα' === $meta['title'], 'AI title kept' );
        $this->assert( 'eu-turkey-flags-ankara' === $meta['filename'], 'Filename slugified: ' . $meta['filename'] );
        $user = json_decode( $this->chat_requests()[0][1]['body'] ?? '{}', true )['messages'][1]['content'] ?? '';
        $this->assert( false !== strpos( $user, 'outside a ministry in Ankara' ) && false !== strpos( $user, 'Διπλωματική κινητικότητα' ), 'Prompt carries the visual prompt and article title' );

        // Provider failure -> deterministic fallback.
        $this->reset_env();
        $meta = PressHub_AI_SEO::generate_image_metadata( 'Harbor cranes at sunset in Limassol. Wide shot.', 'Λιμάνι Λεμεσού', '' );
        $this->assert( 'fallback' === $meta['source'], 'Fallback when the provider fails' );
        $this->assert( 'Λιμάνι Λεμεσού' === $meta['title'] && 'Harbor cranes at sunset in Limassol.' === $meta['alt_text'], 'Fallback uses article title and first prompt sentence' );
        $this->assert( 'harbor-cranes-sunset-limassol-wide-shot' === $meta['filename'], 'Fallback filename from prompt: ' . $meta['filename'] );

        // Disabled -> no AI call.
        $this->reset_env();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_image_metadata_enabled'] = 0;
        PressHub_AI_SEO::generate_image_metadata( 'Prompt.', 'Title', '' );
        $this->assert( 0 === count( $this->chat_requests() ), 'No AI call when image metadata is disabled' );
    }

    public function test_generate_featured_image_writes_metadata(): void {
        $this->reset_env();
        $GLOBALS['SIDELOAD_RETURN_ID'] = 321;
        $GLOBALS['POSTS_STORE'][ 77 ]  = [ 'ID' => 77, 'post_title' => 'Πληθωρισμός στην Κύπρο', 'post_content' => '<p>Ο διοικητής προειδοποιεί για τον πληθωρισμό.</p>' ];
        $GLOBALS['LLM_QUEUE'][]        = json_encode( [
            'title'       => 'Δεξαμενές καυσίμων στο λιμάνι',
            'alt_text'    => 'Δεξαμενές καυσίμων κάτω από συννεφιασμένο ουρανό',
            'caption'     => 'Οι τιμές ενέργειας πιέζουν τον πληθωρισμό (εικονογράφηση AI)',
            'description' => 'Ενεργειακή υποδομή, πληθωρισμός, Κύπρος.',
            'filename'    => 'fuel-storage-tanks-port',
        ], JSON_UNESCAPED_UNICODE );

        $client = new PressHub_AI_API_Client();
        $res    = $client->generate_featured_image( 'Fuel storage tanks at a port under an overcast sky.', '16:9', 77 );

        $this->assert( ! is_wp_error( $res ) && 321 === $res['id'], 'Image generated and sideloaded' );
        $this->assert( 'Δεξαμενές καυσίμων στο λιμάνι' === ( $res['title'] ?? '' ), 'Result carries the media title' );
        $file = $GLOBALS['SIDELOAD_CAPTURE'][0]['name'] ?? '';
        $this->assert( 0 === strpos( $file, 'fuel-storage-tanks-port-' ), "Descriptive filename used: {$file}" );
        $this->assert( 'Δεξαμενές καυσίμων κάτω από συννεφιασμένο ουρανό' === get_post_meta( 321, '_wp_attachment_image_alt', true ), 'Alt text saved on the attachment' );
        $att = $GLOBALS['POSTS_STORE'][ 321 ] ?? [];
        $this->assert( 'Δεξαμενές καυσίμων στο λιμάνι' === ( $att['post_title'] ?? '' ), 'Attachment title updated' );
        $this->assert( 'Οι τιμές ενέργειας πιέζουν τον πληθωρισμό (εικονογράφηση AI)' === ( $att['post_excerpt'] ?? '' ), 'Attachment caption updated' );
        $this->assert( 'Ενεργειακή υποδομή, πληθωρισμός, Κύπρος.' === ( $att['post_content'] ?? '' ), 'Attachment description updated' );
        $user = json_decode( $this->chat_requests()[0][1]['body'] ?? '{}', true )['messages'][1]['content'] ?? '';
        $this->assert( false !== strpos( $user, 'Πληθωρισμός στην Κύπρο' ) && false !== strpos( $user, 'προειδοποιεί' ), 'Metadata prompt read the article from the post' );
    }

    public function test_auto_optimize_post(): void {
        $this->reset_env();
        $GLOBALS['SITE_TAGS']   = [ 'Κυπριακό', 'Τουρκία' ];
        $GLOBALS['LLM_QUEUE'][] = json_encode( [
            'tags'             => [ 'Κυπριακό', 'Ευρωπαϊκή Ένωση', 'Τουρκία', 'Τελωνειακή Ένωση' ],
            'meta_description' => 'Η ΕΕ προτείνει αναβάθμιση της τελωνειακής ένωσης με την Τουρκία για να επανεκκινήσουν οι συνομιλίες για το Κυπριακό.',
            'focus_keyphrase'  => 'Κυπριακό ΕΕ Τουρκία',
        ], JSON_UNESCAPED_UNICODE );
        $GLOBALS['OPTIONS_STORE']['presshub_ai_seo_max_tags'] = 3;

        $ok = PressHub_AI_SEO::auto_optimize_post( 90, 'Πρωινή ενημέρωση', 'Κείμενο για το Κυπριακό.', 'briefing_text' );
        $this->assert( true === $ok, 'auto_optimize_post applied' );
        $this->assert( [ 'Κυπριακό', 'Ευρωπαϊκή Ένωση', 'Τουρκία' ] === ( $GLOBALS['SET_POST_TAGS'][90]['tags'] ?? null ), 'Tags assigned and capped at max_tags' );
        $this->assert( true === ( $GLOBALS['SET_POST_TAGS'][90]['append'] ?? null ), 'Tags appended, not replaced' );
        $this->assert( 0 === strpos( (string) ( $GLOBALS['POSTS_STORE'][90]['post_excerpt'] ?? '' ), 'Η ΕΕ προτείνει' ), 'Excerpt set to the meta description' );
        $meta = get_post_meta( 90, PressHub_AI_SEO::META_SEO, true );
        $this->assert( 'Κυπριακό ΕΕ Τουρκία' === ( $meta['focus_keyphrase'] ?? '' ), 'Focus keyphrase stored' );
        $user = json_decode( $this->chat_requests()[0][1]['body'] ?? '{}', true )['messages'][1]['content'] ?? '';
        $this->assert( false !== strpos( $user, 'Existing site tags: Κυπριακό, Τουρκία' ) && false !== strpos( $user, 'between 3 and 3 tags' ), 'Prompt lists existing site tags and max tags' );

        // Bad JSON -> nothing applied.
        $this->reset_env();
        $GLOBALS['LLM_QUEUE'][] = 'Sorry, I cannot.';
        $this->assert( false === PressHub_AI_SEO::auto_optimize_post( 91, 'T', 'Body', 'podcast' ), 'Bad JSON returns false' );
        $this->assert( empty( $GLOBALS['SET_POST_TAGS'] ) && ! isset( $GLOBALS['POSTS_STORE'][91] ), 'Nothing written on bad JSON' );

        // Disabled -> no call.
        $this->reset_env();
        $GLOBALS['OPTIONS_STORE']['presshub_ai_seo_auto_enabled'] = 0;
        $this->assert( false === PressHub_AI_SEO::auto_optimize_post( 92, 'T', 'Body' ), 'Disabled returns false' );
        $this->assert( 0 === count( $this->chat_requests() ), 'No AI call when automatic SEO is disabled' );
    }

    public function test_run_checks(): void {
        $this->reset_env();
        $GLOBALS['POST_META_STORE'][ 500 ]['_wp_attachment_image_alt'] = 'Σημαίες';
        $body = '<p>' . str_repeat( 'λέξη ', 350 ) . '</p><h2>Υπότιτλος</h2><p><a href="/related">σχετικό</a> <a href="https://gov.cy/doc">πηγή</a></p>';
        $good = PressHub_AI_SEO::run_checks( [
            'title'             => 'Η ΕΕ προτείνει αναβάθμιση τελωνειακής ένωσης με την Τουρκία',
            'content'           => $body,
            'excerpt'           => str_repeat( 'α', 140 ),
            'tags'              => [ 'Κυπριακό', 'ΕΕ', 'Τουρκία' ],
            'categories'        => [ 'Πολιτική' ],
            'featured_image_id' => 500,
        ] );
        $this->assert( 100 === $good['score'], 'Well-prepared post scores 100, got ' . $good['score'] . ' ' . json_encode( array_filter( $good['checks'], function ( $c ) { return 'pass' !== $c['status']; } ), JSON_UNESCAPED_UNICODE ) );

        $bad = PressHub_AI_SEO::run_checks( [
            'title'      => 'Σύντομος',
            'content'    => '<p>Λίγα λόγια.</p><img src="x.jpg">',
            'excerpt'    => '',
            'tags'       => [],
            'categories' => [ 'Uncategorized' ],
        ] );
        $by_key = [];
        foreach ( $bad['checks'] as $c ) {
            $by_key[ $c['key'] ] = $c['status'];
        }
        $this->assert( $bad['score'] < 30, 'Bare post scores low, got ' . $bad['score'] );
        $this->assert( 'fail' === ( $by_key['tags'] ?? '' ) && 'fail' === ( $by_key['excerpt'] ?? '' ) && 'fail' === ( $by_key['featured_image'] ?? '' ) && 'fail' === ( $by_key['category'] ?? '' ) && 'fail' === ( $by_key['inline_alt'] ?? '' ), 'Missing tags/excerpt/image/category/alt flagged: ' . json_encode( $by_key ) );
        $this->assert( 'warn' === ( $by_key['title'] ?? '' ), 'Short headline warns' );
    }

    public function test_review_combines_scores(): void {
        $this->reset_env();
        $GLOBALS['LLM_QUEUE'][] = json_encode( [
            'seo_score'                  => 40,
            'focus_keyphrase'            => 'τελωνειακή ένωση',
            'summary'                    => 'Καλός τίτλος, λείπουν ετικέτες.',
            'suggestions'                => [ 'Προσθέστε ετικέτες.', 'Προσθέστε περίληψη.' ],
            'suggested_tags'             => [ 'Κυπριακό', 'Τουρκία' ],
            'suggested_meta_description' => 'Η ΕΕ και η Τουρκία συζητούν.',
        ], JSON_UNESCAPED_UNICODE );

        $input  = [ 'title' => 'Σύντομος', 'content' => '<p>Λίγα λόγια.</p>', 'excerpt' => '', 'tags' => [], 'categories' => [] ];
        $checks = PressHub_AI_SEO::run_checks( $input );
        $res    = PressHub_AI_SEO::review( $input );
        $this->assert( 40 === $res['ai_score'], 'AI score parsed' );
        $this->assert( (int) round( ( $checks['score'] + 40 ) / 2 ) === $res['seo_score'], 'Final score averages checks and AI' );
        $this->assert( [ 'Κυπριακό', 'Τουρκία' ] === $res['suggested_tags'] && 2 === count( $res['suggestions'] ), 'Suggestions and tags returned' );
        $user = json_decode( $this->chat_requests()[0][1]['body'] ?? '{}', true )['messages'][1]['content'] ?? '';
        $this->assert( false !== strpos( $user, 'Tags: FAIL' ), 'Deterministic checks are passed to the AI' );

        // AI failure -> deterministic score only.
        $this->reset_env();
        $res = PressHub_AI_SEO::review( $input );
        $this->assert( null === $res['ai_score'] && $checks['score'] === $res['seo_score'] && null !== $res['error'], 'Falls back to the checks score when the AI fails' );
    }
}

( new SeoTest() )->run();
