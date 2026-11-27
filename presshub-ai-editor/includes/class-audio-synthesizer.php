<?php
/**
 * PressHub_AI_Audio_Synthesizer — Multi-Voice Google Cloud TTS Podcast Synthesizer.
 *
 * Handles voice model directory for Greek TTS (Neural2, Wavenet, Standard),
 * speaker turn synthesis, silent MPEG frame generation, binary MP3 stitching,
 * Media Library sideloading, and WordPress Podcast post creation with Audio blocks.
 *
 * @package PressHub_AI_Editor
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-api-client.php';
require_once __DIR__ . '/class-news-harvester.php';
require_once __DIR__ . '/class-podcast-producer.php';
require_once __DIR__ . '/class-markdown.php';
require_once __DIR__ . '/class-podcast-timeline.php';
require_once __DIR__ . '/class-token-logger.php';

class PressHub_AI_Audio_Synthesizer {

    /**
     * Errors synthesize_speech_via_gemini() returns before it writes its own
     * Usage & logs row; every other Gemini result is already logged there.
     */
    const GEMINI_UNLOGGED_ERRORS = [ 'no_gemini_key', 'missing_tts_voice', 'missing_tts_model', 'decode_failed' ];

    /** Option key for audio synthesis engine ('gemini' or 'google_cloud'). */
    const OPTION_ENGINE = 'presshub_ai_briefing_tts_engine';

    /** Option key for female voice model. */
    const OPTION_VOICE_FEMALE = 'presshub_ai_briefing_voice_female';

    /** Option key for male voice model. */
    const OPTION_VOICE_MALE = 'presshub_ai_briefing_voice_male';

    /** Option key for speaking style / delivery. */
    const OPTION_STYLE = 'presshub_ai_briefing_tts_style';

    /** Option key for custom speaking style instruction. */
    const OPTION_CUSTOM_STYLE = 'presshub_ai_briefing_tts_custom_style';

    /** Option key for speaking rate/speed. */
    const OPTION_VOICE_SPEED = 'presshub_ai_briefing_voice_speed';

    /** Option key for voice pitch. */
    const OPTION_VOICE_PITCH = 'presshub_ai_briefing_voice_pitch';

    /** Option key for podcast post category. */
    const OPTION_CATEGORY = 'presshub_ai_briefing_podcast_category';

    /** Option key for podcast post status. */
    const OPTION_STATUS = 'presshub_ai_briefing_podcast_status';

    /**
     * Whether the most recent call to synthesize_turn() was served from disk cache.
     *
     * @var bool
     */
    public $last_turn_was_cache_hit = false;

    /**
     * Issue #187: microtime() after which no new TTS call is started (0 = no limit).
     * Cached turns are still returned, so a continuation run resumes quickly.
     *
     * @var float
     */
    private $live_call_deadline = 0.0;

    /**
     * Stop starting new TTS calls after $deadline (a microtime(true) value; 0 clears it).
     * synthesize_turn() then returns a `synthesis_time_budget` WP_Error, and the
     * caller resumes in a fresh request from the turn cache.
     */
    public function set_live_call_deadline( float $deadline ): void {
        $this->live_call_deadline = max( 0.0, $deadline );
    }

    /**
     * Set by synthesize_script_audio(): the run stops after stitching and returns the
     * audio and its timeline instead of saving a file and a podcast post.
     *
     * @var array|null [ 'language' => English name of the language, 'voices' => overrides ]
     */
    private $audio_only = null;

    /**
     * Voices a script in another language (an English or Turkish translation of the
     * Greek podcast) with the podcast's voice settings, and returns the stitched audio
     * without saving anything: no snapshot file, no Media Library item, no post.
     *
     * The delivery style is the podcast's style with "Greek" replaced by $language, and
     * $voices (presenter_1, presenter_2, presenter_3) replace the podcast's voices where
     * they are not empty. Honors set_live_call_deadline(), so a long translation resumes
     * from the turn cache like the Greek episode.
     *
     * @param string $script   Script with the same speaker and topic markers as the Greek one.
     * @param string $date     Briefing date (Y-m-d), for the turn cache.
     * @param string $edition  morning | noon, for the TTS call record.
     * @param string $language English name of the language, e.g. "English", "Turkish".
     * @param array  $voices   Optional voice overrides keyed presenter_1..presenter_3.
     * @return array|WP_Error [ audio, ext, timeline_topics, timeline_opts, turns_count, topic_count, engine, voices ]
     */
    public function synthesize_script_audio( string $script, string $date, string $edition, string $language, array $voices = [], ?PressHub_AI_API_Client $api_client = null ) {
        if ( '' === trim( $script ) ) {
            return new WP_Error( 'empty_script', __( 'The translated podcast script is empty.', 'presshub-ai-editor' ) );
        }
        $this->audio_only = [ 'language' => $language, 'voices' => $voices ];
        try {
            return $this->synthesize_podcast_run( $date, $script, 'noon' === $edition ? 'noon' : 'morning', $api_client, 0 );
        } finally {
            $this->audio_only = null;
            if ( class_exists( 'PressHub_AI_TTS_Call_Recorder' ) ) {
                PressHub_AI_TTS_Call_Recorder::end_session();
            }
        }
    }

    /**
     * Delivery style text for another language: the style's instruction with "Greek"
     * replaced by $language, or the custom instruction with the language added.
     */
    public function style_for_language( string $style, string $language ): string {
        $styles = $this->get_available_styles();
        $text   = ( isset( $styles[ $style ] ) && '' !== $styles[ $style ]['instruction'] ) ? $styles[ $style ]['instruction'] : $style;
        if ( '' === trim( $text ) ) {
            $text = $styles['formal']['instruction'];
        }
        if ( false !== stripos( $text, 'Greek' ) ) {
            return str_ireplace( 'Greek', $language, $text );
        }
        return rtrim( $text, " \t\n:." ) . '. Speak in ' . $language . ':';
    }

    /**
     * Constructor registers excerpt filter hooks.
     */
    public function __construct() {
        self::register_hooks();
    }

    /**
     * Register WordPress filter hooks.
     */
    public static function register_hooks(): void {
        if ( function_exists( 'add_filter' ) ) {
            add_filter( 'get_the_excerpt', [ __CLASS__, 'filter_podcast_excerpt' ], 20, 2 );
        }
        if ( function_exists( 'add_action' ) ) {
            add_action( 'save_post', [ __CLASS__, 'forget_brief_lead' ], 10, 1 );
        }
    }

    /**
     * Filter post excerpt for podcast briefing posts.
     *
     * Suppresses raw dialogue script from appearing in front-page article previews,
     * archive cards, and public RSS feeds when "Include Transcript in Podcast Post"
     * is disabled.
     *
     * @param string        $excerpt Current excerpt text.
     * @param \WP_Post|null $post    Post object.
     * @return string Filtered excerpt text.
     */
    public static function filter_podcast_excerpt( $excerpt, $post = null ): string {
        if ( empty( $post ) ) {
            $post = function_exists( 'get_post' ) ? get_post() : null;
        }
        if ( ! is_object( $post ) || empty( $post->ID ) ) {
            return (string) $excerpt;
        }

        $briefing_type = function_exists( 'get_post_meta' ) ? get_post_meta( $post->ID, '_presshub_briefing_type', true ) : '';
        if ( 'podcast' !== $briefing_type ) {
            return (string) $excerpt;
        }

        // The lead story text, when there is one, is what the card shows
        // (2.63.0). post_excerpt itself is left to the SEO description.
        $lead = self::podcast_lead_excerpt( $post );
        if ( '' !== $lead ) {
            return $lead;
        }

        $include_text = class_exists( 'PressHub_AI_Settings_Storage' )
            ? PressHub_AI_Settings_Storage::get_briefing_podcast_include_text()
            : false;

        // When include_text is disabled, suppress the dialogue script excerpt completely.
        if ( ! $include_text ) {
            return '';
        }

        // When include_text is enabled, strip any leaked topic markers or speaker prefixes.
        if ( false !== strpos( (string) $excerpt, 'TOPIC' ) || false !== strpos( (string) $excerpt, 'SPEAKER_' ) ) {
            $synth   = new self();
            $cleaned = $synth->strip_topic_markers( (string) $excerpt );
            $cleaned = preg_replace( '/\[\s*(?:SPEAKER_\d+|Presenter\s*\d+)\s*\]\s*:\s*/iu', '', $cleaned );
            return trim( $cleaned );
        }

        return (string) $excerpt;
    }

    /** Meta key holding the lead story text shown on a podcast post (2.63.0). */
    const META_LEAD = '_presshub_podcast_lead';

    /** Most words the lead story text keeps. */
    const LEAD_MAX_WORDS = 60;

    /** How long a looked-up lead for an older podcast post is cached, in seconds. */
    const LEAD_CACHE_TTL = 900;

    /** Leads looked up in this request, by "date|edition". */
    private static $lead_cache = [];

    /** Whether lead story text is on (the setting, then the filter). */
    public static function lead_enabled( string $date, string $edition ): bool {
        $on = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_podcast_lead_enabled() : true;
        return (bool) ( function_exists( 'apply_filters' ) ? apply_filters( 'presshub_ai_podcast_lead_enabled', $on, $date, $edition ) : $on );
    }

    /**
     * The card text of a podcast post: its saved lead story text, else (an
     * older post, or a brief published after the podcast) the lead of that
     * day's published brief, cached per request and in a short transient.
     * '' when the feature is off or there is no published brief.
     */
    public static function podcast_lead_excerpt( $post ): string {
        $post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return '';
        }
        $date    = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        $edition = 'noon' === get_post_meta( $post_id, '_presshub_briefing_edition', true ) ? 'noon' : 'morning';
        if ( ! self::lead_enabled( $date, $edition ) ) {
            return '';
        }
        $lead = trim( (string) get_post_meta( $post_id, self::META_LEAD, true ) );
        if ( '' !== $lead || '' === $date ) {
            return $lead;
        }
        $key = $date . '|' . $edition;
        if ( ! array_key_exists( $key, self::$lead_cache ) ) {
            $transient = 'presshub_ai_podcast_lead_' . str_replace( '-', '', $date ) . '_' . $edition;
            $cached    = function_exists( 'get_transient' ) ? get_transient( $transient ) : false;
            if ( is_array( $cached ) && isset( $cached['text'] ) ) {
                self::$lead_cache[ $key ] = (string) $cached['text'];
            } else {
                self::$lead_cache[ $key ] = ( new self() )->lead_story_text( $date, $edition );
                if ( function_exists( 'set_transient' ) ) {
                    set_transient( $transient, [ 'text' => self::$lead_cache[ $key ] ], self::LEAD_CACHE_TTL );
                }
            }
        }
        return self::$lead_cache[ $key ];
    }

    /**
     * A written brief was saved (published, edited, held): drop the cached
     * lead of its date and edition so podcast cards pick up the change.
     */
    public static function forget_brief_lead( $post_id ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) || 'text' !== get_post_meta( $post_id, '_presshub_briefing_type', true ) ) {
            return;
        }
        $date    = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        $edition = 'noon' === get_post_meta( $post_id, '_presshub_briefing_edition', true ) ? 'noon' : 'morning';
        if ( '' === $date ) {
            return;
        }
        unset( self::$lead_cache[ $date . '|' . $edition ] );
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( 'presshub_ai_podcast_lead_' . str_replace( '-', '', $date ) . '_' . $edition );
        }
    }

    /** Forget the leads looked up in this request (tests). */
    public static function reset_lead_cache(): void {
        self::$lead_cache = [];
    }

    /**
     * The lead story of that day's published written brief as plain text:
     * the paragraphs before its first heading, cut to whole sentences of at
     * most LEAD_MAX_WORDS words, else the brief's excerpt. '' when the
     * feature is off or the brief is missing or not published (a draft,
     * scheduled or QA-held brief never shows).
     */
    public function lead_story_text( string $date, string $edition ): string {
        if ( ! self::lead_enabled( $date, $edition ) ) {
            return '';
        }
        $brief = $this->find_brief_post( $date, $edition, [ 'publish' ] );
        if ( ! $brief || 'publish' !== (string) ( $brief->post_status ?? '' ) ) {
            return '';
        }
        $content = (string) ( $brief->post_content ?? '' );
        $parts   = preg_split( '/<h[1-6][\s>]/i', $content, 2 );
        $text    = self::plain_text( is_array( $parts ) ? (string) ( $parts[0] ?? '' ) : '' );
        if ( '' === $text ) {
            $text = self::plain_text( (string) ( $brief->post_excerpt ?? '' ) );
        }
        $text = self::cut_to_sentences( $text, self::LEAD_MAX_WORDS );
        if ( function_exists( 'apply_filters' ) ) {
            // A filter's own text is cleaned like the brief's (no tags, trimmed).
            $filtered = (string) apply_filters( 'presshub_ai_podcast_lead_text', $text, $date, $edition );
            $text     = $filtered === $text ? $text : self::plain_text( $filtered );
        }
        return $text;
    }

    /** The lead story paragraph for the post body, or '' for no text. */
    public function lead_story_html( string $lead ): string {
        if ( '' === trim( $lead ) ) {
            return '';
        }
        return "<!-- wp:paragraph {\"className\":\"presshub-podcast-lead\"} -->\n"
            . '<p class="presshub-podcast-lead">' . esc_html( $lead ) . "</p>\n"
            . '<!-- /wp:paragraph -->';
    }

    /** HTML to one line of plain text (no tags, entities decoded, spaces collapsed). '' on invalid UTF-8. */
    private static function plain_text( string $html ): string {
        $html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
        $html = (string) preg_replace( '#</(p|div|li|blockquote|figcaption)>#i', '$0 ', $html );
        $text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
    }

    /**
     * Whole sentences from the start of $text up to $max_words words. A
     * sentence ends at . ! ? ; ; (Greek question mark) or …, with any closing
     * quote or bracket, before a space and a capital letter, digit or opening
     * quote; abbreviations such as "κ.", "π.χ." or "Δρ." do not end one. A
     * first sentence longer than the limit is cut at a word with an ellipsis.
     */
    public static function cut_to_sentences( string $text, int $max_words ): string {
        $text = trim( $text );
        if ( '' === $text || $max_words < 1 ) {
            return '';
        }
        $pieces = preg_split( '/(?<=[.!?;\x{037E}…])(?:["»”’\')\]]*)\K\s+(?=["«“‘(\[]?[\p{Lu}\p{N}])/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $pieces ) ) {
            return '';
        }
        // Join pieces that end in an abbreviation back to the next one.
        $sentences = [];
        foreach ( $pieces as $piece ) {
            $last = count( $sentences ) - 1;
            if ( $last >= 0 && preg_match( '/(?:^|[\s(«"])(?:\p{L}|\p{L}\.\p{L}|κκ|κα|Δρ|Dr|Mr|Mrs|Ms|St|Αγ|Αρχ|Καθ|Prof|σελ|αρ|βλ)\.$/u', $sentences[ $last ] ) ) {
                $sentences[ $last ] .= ' ' . $piece;
            } else {
                $sentences[] = $piece;
            }
        }
        $out   = [];
        $words = 0;
        foreach ( $sentences as $sentence ) {
            $split = preg_split( '/\s+/u', trim( $sentence ), -1, PREG_SPLIT_NO_EMPTY );
            $n     = is_array( $split ) ? count( $split ) : 0;
            if ( $words + $n > $max_words ) {
                break;
            }
            $out[]  = trim( $sentence );
            $words += $n;
        }
        if ( empty( $out ) ) {
            $first = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
            return is_array( $first ) ? implode( ' ', array_slice( $first, 0, $max_words ) ) . '…' : '';
        }
        return implode( ' ', $out );
    }

    /**
     * Get list of delivery styles from logosAI.
     *
     * @return array Style dictionary.
     */
    public function get_available_styles(): array {
        return [
            'formal'      => [
                'id'          => 'formal',
                'label'       => __( 'Formal & Broadcast (Επίσημο & Επαγγελματικό)', 'presshub-ai-editor' ),
                'instruction' => 'Say in a professional, authoritative, articulate Greek news broadcast tone:',
            ],
            'natural'     => [
                'id'          => 'natural',
                'label'       => __( 'Natural & Warm (Φυσικό & Φιλικό)', 'presshub-ai-editor' ),
                'instruction' => 'Say naturally and clearly in Greek with warm human cadence:',
            ],
            'cheerful'    => [
                'id'          => 'cheerful',
                'label'       => __( 'Cheerful & Bright (Χαρούμενο & Φωτεινό)', 'presshub-ai-editor' ),
                'instruction' => 'Say cheerfully, enthusiastically, and with a bright uplifting smile in Greek:',
            ],
            'storyteller' => [
                'id'          => 'storyteller',
                'label'       => __( 'Storyteller & Narrative (Αφήγηση & Παραμύθι)', 'presshub-ai-editor' ),
                'instruction' => 'Say like an engaging, captivating storyteller with theatrical pacing and expressive pauses in Greek:',
            ],
            'calm'        => [
                'id'          => 'calm',
                'label'       => __( 'Calm & Soothing (Ήρεμο & Γαλήνιο)', 'presshub-ai-editor' ),
                'instruction' => 'Say in a peaceful, gentle, soothing, and relaxing tone in Greek:',
            ],
            'dramatic'    => [
                'id'          => 'dramatic',
                'label'       => __( 'Dramatic & Intense (Δραματικό & Έντονο)', 'presshub-ai-editor' ),
                'instruction' => 'Say with intense dramatic emotion, resonant weight, and vivid inflection in Greek:',
            ],
            'poetic'      => [
                'id'          => 'poetic',
                'label'       => __( 'Poetic & Lyrical (Ποιητικό & Λυρικό)', 'presshub-ai-editor' ),
                'instruction' => 'Say with deep lyrical emotion, soft melodic rhythm, and poetic sensitivity in Greek:',
            ],
            'epic'        => [
                'id'          => 'epic',
                'label'       => __( 'Epic & Classical (Επικό & Αρχαιοπρεπές)', 'presshub-ai-editor' ),
                'instruction' => 'Say in a grand, legendary, classical ancient oratorical style in Greek:',
            ],
            'whisper'     => [
                'id'          => 'whisper',
                'label'       => __( 'Gentle Whisper (Ψίθυρος)', 'presshub-ai-editor' ),
                'instruction' => 'Say in a soft, intimate, gentle quiet whisper in Greek:',
            ],
            'energetic'   => [
                'id'          => 'energetic',
                'label'       => __( 'Energetic & Dynamic (Δυναμικό & Ενθουσιώδες)', 'presshub-ai-editor' ),
                'instruction' => 'Say with high energy, vibrant excitement, and dynamic rhythm in Greek:',
            ],
            'custom'      => [
                'id'          => 'custom',
                'label'       => __( 'Custom Prompt Instruction (Προσαρμοσμένη Οδηγία)', 'presshub-ai-editor' ),
                'instruction' => '',
            ],
        ];
    }

    /** Option key for the voice catalog cache transient suffix (per engine/model). */
    const OPTION_VOICE_CATALOG_TTL = 24 * HOUR_IN_SECONDS;

    /** Bundled manifest relative path (resolved against plugin root). */
    const VOICE_CATALOG_MANIFEST = 'assets/data/tts-voice-catalog.json';

    /**
     * Get available voice models for the given TTS engine.
     *
     * Issue #89 — the original implementation returned a hardcoded
     * `[ 'female' => [...], 'male' => [...] ]` array regardless of engine,
     * which left operators on Google Cloud TTS, OpenAI TTS, ElevenLabs,
     * etc. seeing Gemini voice names in their persona dropdowns. The
     * engine parameter was documented as "kept for backwards compatibility"
     * and was never consulted.
     *
     * The new implementation loads the bundled JSON manifest at
     * `assets/data/tts-voice-catalog.json` and returns the slice for the
     * requested engine. Backward-compat: the returned array shape is
     * identical to the historical version (female/male buckets keyed by
     * voice name with `name`, `label`, `gender`, `type` per entry) so
     * existing downstream render code and `AudioSynthesizerTest.php`
     * assertions continue to hold.
     *
     * @param string $engine Optional engine key ('gemini-2.5', 'gemini-3.1',
     *                       'google_cloud', 'openai-tts', 'elevenlabs').
     *                       If empty, falls back to OPTION_ENGINE ('gemini').
     *                       Legacy callers passing 'gemini' get the
     *                       gemini-2.5 slice.
     * @return array{ female: array<string, array>, male: array<string, array> }
     *         Grouped voice directory keyed by voice name. Each entry
     *         carries `name`, `label`, `gender`, `type`, and (for
     *         manifest-loaded voices) `language`, `engine_version`.
     */
    public function get_available_voices( string $engine = 'gemini', string $language = '' ): array {
        $engine = '' === trim( $engine ) ? 'gemini' : trim( $engine );
        // Google Cloud voices speak one language each: the Greek podcast's lists show the
        // el-GR ones, an English or Turkish podcast's its own (`language` "en", "tr").
        if ( '' === $language && 'google_cloud' === $engine ) {
            $language = 'el';
        }

        // Backward-compat shim: legacy callers pass 'gemini' to mean
        // the canonical Gemini 2.5 catalog. Map that to the new
        // engine key.
        if ( 'gemini' === $engine ) {
            $engine = 'gemini-2.5';
        }

        $manifest = $this->load_voice_catalog_manifest();
        $engine_slice = $manifest['engines'][ $engine ] ?? null;

        if ( null === $engine_slice || empty( $manifest['engines'] ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( sprintf( 'TTS voice catalog missing manifest or engine slice for engine: %s', $engine ) );
            }
            return [ 'female' => [], 'male' => [] ];
        }

        $voices = (array) ( $engine_slice['voices'] ?? [] );
        if ( '' !== $language ) {
            $prefix = strtolower( $language ) . '-';
            $voices = array_filter( $voices, static function ( $v ) use ( $prefix ) {
                return ! is_array( $v ) || ! isset( $v['language'] ) || str_starts_with( strtolower( (string) $v['language'] ), $prefix );
            } );
        }
        return $this->group_voices_by_gender( $voices );
    }

    /**
     * Discover available Gemini TTS voice models via the live API.
     *
     * Hits `https://generativelanguage.googleapis.com/v1beta/models`,
     * filters the response to TTS-capable models (those whose id matches
     * `/tts/i`), and returns a `name => entry` map suitable for merging
     * with the bundled manifest. Results are cached in a 24h transient
     * keyed on `presshub_tts_voices_gemini` so the settings page is
     * offline-tolerant and fast.
     *
     * Pattern mirrors `PressHub_AI_API_Client::fetch_remote_models()` for
     * Gemini (`class-api-client.php:2064-2108`) but specialised for the
     * voice-catalog use case: only TTS models are returned, the response
     * shape is enriched with engine metadata, and the cache layer is
     * encapsulated here rather than at the call site.
     *
     * @param string $api_key Gemini API key.
     * @return array<string, array{name:string, label:string, gender:string,
     *                             language:string, engine_version:string,
     *                             type:string, source:string}>|WP_Error
     */
    public function discover_gemini_voices( string $api_key ) {
        $cache_key = 'presshub_tts_voices_gemini';
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        if ( '' === trim( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'Gemini API key is required to discover available TTS voices.', 'presshub-ai-editor' ) );
        }

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode( $api_key );
        $referer  = function_exists( 'home_url' ) ? trailingslashit( home_url() ) : 'https://presshub.cy/';
        $headers  = [
            'x-goog-api-key' => $api_key,
            'Content-Type'   => 'application/json',
            'Referer'        => $referer,
        ];

        $response = wp_remote_get( $endpoint, [
            'headers' => $headers,
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $res_body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $res_body ) ) {
            $msg = is_array( $res_body ) && isset( $res_body['error']['message'] )
                ? $res_body['error']['message']
                : sprintf( __( 'Google Gemini API returned HTTP %d while listing TTS models', 'presshub-ai-editor' ), $code );
            return new WP_Error( 'api_error', $msg );
        }

        $discovered = [];
        if ( isset( $res_body['models'] ) && is_array( $res_body['models'] ) ) {
            foreach ( $res_body['models'] as $item ) {
                $raw = $item['name'] ?? ( $item['id'] ?? '' );
                if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
                    continue;
                }
                $m = preg_replace( '#^models/#', '', trim( $raw ) );
                // Filter: only TTS models. This is the (d) acceptance
                // criterion — non-TTS models must NOT leak into the
                // voice catalog (they would let operators select a model
                // that has no voices to expose).
                if ( '' === $m || false === stripos( $m, 'tts' ) ) {
                    continue;
                }
                $discovered[ $m ] = [
                    'name'           => $m,
                    'label'          => $m,
                    'gender'         => 'neutral',
                    'language'       => 'el-GR',
                    'engine_version' => $this->infer_engine_version( $m ),
                    'type'           => 'Gemini-TTS-Model',
                    'source'         => 'discovered',
                ];
            }
        }

        // Cache for 24h. We cache even the empty result so a misconfigured
        // site does not hammer the Gemini API on every page load.
        set_transient( $cache_key, $discovered, self::OPTION_VOICE_CATALOG_TTL );

        return $discovered;
    }

    /**
     * Get the operational voice catalog for an engine: cached discovery
     * first, fresh discovery on miss, bundled manifest on failure.
     *
     * Returns the grouped `[ 'female' => [...], 'male' => [...] ]` array
     * shape that the settings render code already consumes, so callers
     * can drop it directly into a `<select>`.
     *
     * @param string $engine Engine key ('gemini-2.5', 'gemini-3.1', …).
     * @return array{ female: array<string, array>, male: array<string, array> }
     *         Always returns a non-empty array; falls back to bundled
     *         manifest on API failure so the UI never breaks.
     */
    public function get_voice_catalog( string $engine = 'gemini-2.5' ): array {
        $engine = '' === trim( $engine ) ? 'gemini-2.5' : trim( $engine );

        // Only Gemini engines participate in dynamic discovery in this PR.
        // Other engines are served exclusively from the bundled manifest
        // (which is intentionally sparse — the user accepted the
        // "best-effort manifest" notice on those engines).
        $is_gemini_engine = ( 'gemini-2.5' === $engine || 'gemini-3.1' === $engine );

        $manifest_slice = $this->get_available_voices( $engine );

        if ( ! $is_gemini_engine ) {
            return $manifest_slice;
        }

        // #237: the Speech provider chosen in AI models first, then any Gemini provider in Connections.
        $api_key = '';
        if ( class_exists( 'PressHub_AI_Provider_Store' ) && class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            $chosen = PressHub_AI_Settings_Storage::get_briefing_podcast_tts_provider();
            $record = '' !== $chosen ? PressHub_AI_Provider_Store::get( $chosen ) : null;
            if ( is_array( $record ) && 'gemini' === ( $record['type'] ?? '' ) && false === strpos( (string) ( $record['api_key'] ?? '' ), '•' ) ) {
                $api_key = (string) ( $record['api_key'] ?? '' );
            }
        }
        if ( '' === $api_key && class_exists( 'PressHub_AI_API_Client' ) ) {
            $api_key = PressHub_AI_API_Client::lookup_provider_key_by_type( 'gemini' );
        }
        if ( '' === $api_key ) {
            return $manifest_slice;
        }

        $discovered = $this->discover_gemini_voices( $api_key );
        if ( is_wp_error( $discovered ) || empty( $discovered ) ) {
            return $manifest_slice;
        }

        // Merge discovered TTS models into the manifest slice. The
        // discovered entries are MODEL-level (the model id), not voice-
        // level, so we add a synthetic placeholder entry under each
        // gender bucket so the settings UI shows them. Operators who
        // select a discovered model will get the canonical Gemini 2.5
        // voices (the model's known voices) — full per-model voice
        // mapping is tracked as a follow-up.
        $merged = $manifest_slice;
        foreach ( $discovered as $model_id => $entry ) {
            // Don't clobber existing manifest entries with the same name.
            if ( ! isset( $merged['female'][ $model_id ] ) && ! isset( $merged['male'][ $model_id ] ) ) {
                $merged['female'][ $model_id ] = $entry;
            }
        }
        return $merged;
    }

    /**
     * Load and decode the bundled voice-catalog manifest.
     *
     * @return array{ schema_version:int, engines:array<string, array> }
     *         Decoded manifest. Returns an empty `engines` array on
     *         any I/O or parse failure so callers can degrade gracefully.
     */
    private function load_voice_catalog_manifest(): array {
        static $cached = null;
        if ( null !== $cached ) {
            return $cached;
        }

        $path = plugin_dir_path( dirname( __FILE__ ) ) . self::VOICE_CATALOG_MANIFEST;
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            $cached = [ 'schema_version' => 0, 'engines' => [] ];
            return $cached;
        }

        $decoded = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['engines'] ) || ! is_array( $decoded['engines'] ) ) {
            $cached = [ 'schema_version' => 0, 'engines' => [] ];
            return $cached;
        }

        $cached = $decoded;
        return $cached;
    }

    /**
     * Group a flat list of voice entries by gender.
     *
     * Unknown / missing gender values bucket under 'female' (the
     * historical default) so the schema contract holds even for voices
     * whose metadata is incomplete. Each entry is enriched with a
     * `type` key for backward-compat with the legacy hardcoded shape.
     *
     * @param array<int, array> $voices
     * @return array{ female: array<string, array>, male: array<string, array> }
     */
    private function group_voices_by_gender( array $voices ): array {
        $grouped = [ 'female' => [], 'male' => [] ];
        foreach ( $voices as $v ) {
            if ( ! is_array( $v ) || empty( $v['name'] ) ) {
                continue;
            }
            $name   = (string) $v['name'];
            $gender = strtolower( (string) ( $v['gender'] ?? 'female' ) );
            if ( 'male' !== $gender ) {
                $gender = 'female'; // 'female' / 'neutral' / unknown → female bucket
            }
            $entry = $v;
            // Backward-compat: existing render code reads $entry['type'].
            // The legacy hardcoded catalog used the literal string
            // 'Gemini-Neural' for every Gemini voice, and the existing
            // AudioSynthesizerTest.php asserts on that exact value. To
            // preserve that contract we keep 'Gemini-Neural' for Gemini
            // engines and only emit the engine-versioned form for
            // non-Gemini engines (Google Cloud TTS, etc.).
            if ( ! isset( $entry['type'] ) ) {
                $is_gemini_family = isset( $v['engine_version'] ) && in_array( (string) $v['engine_version'], [ '2.5', '3.1' ], true );
                $entry['type'] = $is_gemini_family
                    ? 'Gemini-Neural'
                    : sprintf( 'Gemini-Neural-%s', (string) ( $v['engine_version'] ?? 'unknown' ) );
            }
            // Preserve historical upper-case gender (legacy callers
            // checked === 'MALE' / === 'FEMALE' in tests).
            $entry['gender'] = strtoupper( (string) ( $v['gender'] ?? 'female' ) );
            $grouped[ $gender ][ $name ] = $entry;
        }
        return $grouped;
    }

    /**
     * Infer Gemini engine version from a model id.
     *
     * @param string $model_id e.g. 'gemini-2.5-flash-preview-tts'
     * @return string '2.5' | '3.1' | 'unknown'
     */
    private function infer_engine_version( string $model_id ): string {
        if ( false !== stripos( $model_id, '3.1' ) ) {
            return '3.1';
        }
        if ( false !== stripos( $model_id, '2.5' ) ) {
            return '2.5';
        }
        return 'unknown';
    }

    /**
     * Get the configured voice model for a given speaker identifier.
     *
     * @param string $speaker Speaker identifier ('female', 'male', 'host1', 'host2', or speaker name).
     * @return string Voice model name (e.g. 'Kore', 'Fenrir', 'Puck', 'el-GR-Wavenet-A').
     */
    public function get_voice_for_speaker( string $speaker ): string {
        $clean = trim( $speaker );

        $engine = class_exists( 'PressHub_AI_Settings_Storage' )
            ? PressHub_AI_Settings_Storage::get_briefing_tts_engine()
            : (string) get_option( self::OPTION_ENGINE, 'gemini' );
        $default_p1 = ( 'google_cloud' === $engine ) ? 'el-GR-Wavenet-A' : 'Kore';
        $default_p2 = ( 'google_cloud' === $engine ) ? 'el-GR-Chirp3-HD-Achird' : 'Fenrir';
        $default_p3 = ( 'google_cloud' === $engine ) ? 'el-GR-Wavenet-C' : 'Puck';

        $p1_voice = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_presenter_1() : (string) get_option( self::OPTION_VOICE_FEMALE, $default_p1 );
        $p2_voice = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_presenter_2() : (string) get_option( self::OPTION_VOICE_MALE, $default_p2 );
        $p3_voice = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_presenter_3() : (string) get_option( 'presshub_ai_briefing_voice_tertiary', $default_p3 );

        if ( empty( $p1_voice ) ) {
            $p1_voice = $default_p1;
        }
        if ( empty( $p2_voice ) ) {
            $p2_voice = $default_p2;
        }
        if ( empty( $p3_voice ) ) {
            $p3_voice = $default_p3;
        }

        // Check if custom host names are configured
        $female_host   = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_female() : (string) get_option( 'presshub_ai_briefing_host_female', '' );
        $male_host     = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_male() : (string) get_option( 'presshub_ai_briefing_host_male', '' );
        $tertiary_host = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_tertiary() : (string) get_option( 'presshub_ai_briefing_host_tertiary', '' );

        $stripped = trim( preg_replace( '/^\[?(.*?)\]?:?$/u', '$1', $clean ) );
        $stripped_lower = strtolower( $stripped );

        // 1. Check tertiary host (Presenter 3)
        $is_tertiary = (
            in_array( $stripped_lower, [ 'speaker_3', 'speaker 3', 'speaker3', 'presenter_3', 'presenter 3', 'presenter3', 'host3', 'host 3', 'host_3', 'tertiary' ], true )
            || ( '' !== trim( $tertiary_host ) && function_exists( 'mb_stripos' ) && false !== mb_stripos( $clean, trim( $tertiary_host ) ) )
        );

        if ( $is_tertiary ) {
            return trim( $p3_voice );
        }

        // 2. Check presenter 1 / female lead
        $is_female = (
            in_array( $stripped_lower, [ 'speaker_1', 'speaker 1', 'speaker1', 'presenter_1', 'presenter 1', 'presenter1', 'host1', 'host 1', 'host_1', 'female' ], true )
            || ( '' !== trim( $female_host ) && function_exists( 'mb_stripos' ) && false !== mb_stripos( $clean, trim( $female_host ) ) )
        );

        if ( $is_female ) {
            return trim( $p1_voice );
        }

        // 3. Check presenter 2 / male co-host
        $is_male = (
            in_array( $stripped_lower, [ 'speaker_2', 'speaker 2', 'speaker2', 'presenter_2', 'presenter 2', 'presenter2', 'host2', 'host 2', 'host_2', 'male' ], true )
            || ( '' !== trim( $male_host ) && function_exists( 'mb_stripos' ) && false !== mb_stripos( $clean, trim( $male_host ) ) )
        );

        if ( $is_male ) {
            return trim( $p2_voice );
        }

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::error( sprintf( '[LogosAI Synthesizer] Unknown speaker label "%s" in get_voice_for_speaker. Falling back to presenter 1 voice.', $clean ) );
        }

        return trim( $p1_voice );
    }

    /**
     * Generate valid MPEG-1 Layer 3 silent audio frames.
     *
     * Standard MPEG-1 Layer 3 frame at 128kbps / 44.1kHz is 417 bytes and represents ~26.1224 ms.
     *
     * @param int $duration_ms Silence duration in milliseconds (default 400).
     * @return string Binary silent MP3 data buffer.
     */
    public function generate_silent_mp3_frame( int $duration_ms = 400 ): string {
        if ( $duration_ms <= 0 ) {
            return '';
        }

        // 417-byte canonical MPEG-1 Layer 3 silent frame (128kbps, 44.1kHz, Joint Stereo)
        $single_frame = "\xFF\xFB\x90\xC4"
            . "\x00\x03\xC0\x00\x01\xA4\x00\x00\x00\x20\x00\x00\x34\x80\x00\x00\x04"
            . str_repeat( "\x55", 396 );

        $num_frames = max( 1, (int) round( $duration_ms / 26.1224 ) );

        return str_repeat( $single_frame, $num_frames );
    }

    /**
     * Strip ID3v2 header and ID3v1 footer from raw MP3 buffer.
     *
     * @param string $mp3_buffer Raw MP3 binary buffer.
     * @return string Cleaned MP3 audio data without ID3 tags.
     */
    public function strip_id3_tags( string $mp3_buffer ): string {
        $len = strlen( $mp3_buffer );
        if ( $len === 0 ) {
            return '';
        }

        // 1. Strip ID3v2 header if present at start
        if ( $len >= 10 && 'ID3' === substr( $mp3_buffer, 0, 3 ) ) {
            $flags = ord( $mp3_buffer[5] );
            $has_footer = ( $flags & 0x10 ) !== 0; // Bit 4
            // Syncsafe integer: 4 bytes (7 bits each)
            $tag_size = ( ( ord( $mp3_buffer[6] ) & 0x7F ) << 21 )
                      | ( ( ord( $mp3_buffer[7] ) & 0x7F ) << 14 )
                      | ( ( ord( $mp3_buffer[8] ) & 0x7F ) << 7 )
                      | ( ord( $mp3_buffer[9] ) & 0x7F );
            $header_total = 10 + $tag_size + ( $has_footer ? 10 : 0 );
            if ( $header_total < $len ) {
                $mp3_buffer = substr( $mp3_buffer, $header_total );
                $len = strlen( $mp3_buffer );
            }
        }

        // 2. Strip ID3v1 tag if present at end (128 bytes starting with "TAG")
        if ( $len >= 128 && 'TAG' === substr( $mp3_buffer, -128, 3 ) ) {
            $mp3_buffer = substr( $mp3_buffer, 0, $len - 128 );
        }

        return $mp3_buffer;
    }

    /**
     * Stitch multiple binary MP3 chunks together with silent pause intervals.
     *
     * @param array $mp3_buffers List of binary MP3 buffers.
     * @param int   $pause_ms    Silence pause duration in milliseconds between turns (default 400).
     * @return string Concatenated binary MP3 data.
     */
    public function stitch_audio_chunks( array $mp3_buffers, int $pause_ms = 400 ): string {
        if ( empty( $mp3_buffers ) ) {
            return '';
        }

        $cleaned_buffers = [];
        foreach ( $mp3_buffers as $buffer ) {
            if ( ! is_string( $buffer ) || '' === $buffer ) {
                continue;
            }
            $cleaned = $this->strip_id3_tags( $buffer );
            if ( '' !== $cleaned ) {
                $cleaned_buffers[] = $cleaned;
            }
        }

        if ( empty( $cleaned_buffers ) ) {
            return '';
        }

        if ( 1 === count( $cleaned_buffers ) ) {
            return $cleaned_buffers[0];
        }

        $pause_frame = $this->generate_silent_mp3_frame( $pause_ms );
        return implode( $pause_frame, $cleaned_buffers );
    }

    /**
     * Stitch multiple WAV/PCM audio buffers together with silent intervals.
     *
     * @param array  $wav_or_pcm_buffers List of WAV or raw PCM binary buffers.
     * @param int    $pause_ms           Pause duration in milliseconds between turns (default 400).
     * @param int    $sample_rate        Sample rate in Hz (default 24000).
     * @param string $interstitial_pcm   Optional raw PCM binary to inject between chunks (e.g. SFX).
     * @return string Valid RIFF/WAV binary data.
     */
    public function stitch_wav_chunks( array $wav_or_pcm_buffers, int $pause_ms = 400, int $sample_rate = 24000, string $interstitial_pcm = '' ): string {
        if ( empty( $wav_or_pcm_buffers ) ) {
            return '';
        }

        $raw_pcm_chunks = [];
        foreach ( $wav_or_pcm_buffers as $buffer ) {
            if ( ! is_string( $buffer ) || '' === $buffer ) {
                continue;
            }
            // If it is a WAV container (starts with RIFF), extract PCM payload (strip header)
            if ( strlen( $buffer ) >= 44 && 'RIFF' === substr( $buffer, 0, 4 ) && 'WAVE' === substr( $buffer, 8, 4 ) ) {
                $pos = strpos( $buffer, 'data' );
                if ( false !== $pos && strlen( $buffer ) >= $pos + 8 ) {
                    $data_size = unpack( 'V', substr( $buffer, $pos + 4, 4 ) )[1] ?? 0;
                    $pcm = substr( $buffer, $pos + 8, $data_size > 0 ? $data_size : null );
                    $raw_pcm_chunks[] = $pcm;
                } else {
                    $raw_pcm_chunks[] = substr( $buffer, 44 );
                }
            } else {
                $raw_pcm_chunks[] = $buffer;
            }
        }

        if ( empty( $raw_pcm_chunks ) ) {
            return '';
        }

        // Generate silence PCM frames (zero bytes: 48 bytes per ms at 24kHz 16-bit mono)
        $bytes_per_ms = (int) ( $sample_rate * 2 / 1000 );
        $silence_bytes = str_repeat( "\x00", max( 0, $pause_ms * $bytes_per_ms ) );
        
        $separator = $silence_bytes;
        if ( '' !== $interstitial_pcm ) {
            // Pad the SFX with a small buffer of silence on both sides (e.g., 150ms)
            $short_silence = str_repeat( "\x00", 150 * $bytes_per_ms );
            $separator = $short_silence . $interstitial_pcm . $short_silence;
        }

        $combined_pcm = implode( $separator, $raw_pcm_chunks );
        return PressHub_AI_API_Client::pcm_to_wav( $combined_pcm, $sample_rate );
    }

    /**
     * Synthesize an individual speaker turn with voice identity isolation, zero-silent-fallback, and per-turn WAV caching.
     *
     * @param string                       $text        Dialogue turn text.
     * @param string                       $voice_model Configured voice persona (e.g. 'Kore', 'Fenrir').
     * @param float                        $speed       Speaking rate (default 1.0).
     * @param float                        $pitch       Voice pitch (default 0.0).
     * @param PressHub_AI_API_Client|null $api_client  Optional API client.
     * @param string                       $style       Delivery style instruction or preset.
     * @param string                       $date        Optional briefing date (YYYY-MM-DD) for disk caching.
     * @param array|null                   $speakers    2.74.0: two speakers for a labelled two-voice request
     *                                                  ([ [ 'speaker' => label, 'voice' => name ], x2 ]). $text then
     *                                                  holds "label: line" lines and $voice_model is only logged.
     * @return string|WP_Error Binary audio string or WP_Error on failure.
     */
    public function synthesize_turn( string $text, string $voice_model = '', float $speed = 1.0, float $pitch = 0.0, ?PressHub_AI_API_Client $api_client = null, string $style = 'formal', string $date = '', ?array $speakers = null ) {
        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'tts' );
        }
        if ( method_exists( $api_client, 'set_action' ) ) {
            $api_client->set_action( 'podcast_audio' );
        }

        // Zero-Silent-Fallback
        if ( '' === trim( $voice_model ) ) {
            $err_msg = __( 'No TTS voice persona configured for speaker turn.', 'presshub-ai-editor' );
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( '[LogosAI Synthesizer] Missing required TTS voice model in synthesize_turn.' );
            }
            return new WP_Error( 'missing_tts_voice', $err_msg );
        }

        if ( empty( $style ) ) {
            $style = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_style() : (string) get_option( self::OPTION_STYLE, 'formal' );
        }

        $engine = class_exists( 'PressHub_AI_Settings_Storage' )
            ? PressHub_AI_Settings_Storage::get_briefing_tts_engine()
            : (string) get_option( self::OPTION_ENGINE, 'gemini' );
        $model_id = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_model() : '';
        $payload_mode = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_payload_mode() : 'structured';

        $two_voices = 'google_cloud' !== $engine && is_array( $speakers ) && 2 === count( $speakers );
        $voice_key  = trim( $voice_model );
        if ( $two_voices ) {
            $voice_key = 'two_voices:' . implode( ',', array_map( static function ( $sp ) {
                return (string) ( $sp['speaker'] ?? '' ) . '=' . (string) ( $sp['voice'] ?? '' );
            }, $speakers ) );
        }

        // 2.89.0: the pronunciation list changes only what the voice is sent, never the
        // script that is stored or shown in captions. Before the cache key, so editing
        // the list re-synthesizes the turns it changes.
        $text = $this->apply_pronunciations( $text, $two_voices ? $speakers : null );

        // Deterministic cache key (a single-voice turn keeps the key it always had).
        $cache_key = hash( 'sha256', implode( '|', [ $engine, $model_id, $voice_key, $style, number_format( $speed, 2 ), number_format( $pitch, 1 ), $payload_mode, trim( $text ) ] ) );

        $cache_file = '';
        if ( '' !== $date ) {
            $uploads    = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [ 'basedir' => sys_get_temp_dir() ];
            $safe_date  = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $date ) : preg_replace( '/[^a-zA-Z0-9_\-]/', '', $date );
            $cache_dir  = trailingslashit( $uploads['basedir'] ) . 'presshub-ai/tts-cache/' . $safe_date;
            $ext        = ( 'google_cloud' === $engine ) ? 'mp3' : 'wav';
            $cache_file = $cache_dir . '/turn_' . $cache_key . '.' . $ext;

            if ( file_exists( $cache_file ) && filesize( $cache_file ) > 0 ) {
                $cached_audio = @file_get_contents( $cache_file );
                if ( false !== $cached_audio && '' !== $cached_audio ) {
                    $this->last_turn_was_cache_hit = true;
                    if ( class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::info( sprintf( '[LogosAI Synthesizer] Turn cache hit for hash %s (voice: %s, date: %s)', substr( $cache_key, 0, 8 ), $voice_model, $date ) );
                    }
                    // Issue #140 - cache hits are part of the per-call TTS details on the parent record.
                    if ( class_exists( 'PressHub_AI_TTS_Call_Recorder' ) ) {
                        PressHub_AI_TTS_Call_Recorder::log_cache_hit( [
                            'engine'      => $engine,
                            'model'       => $model_id,
                            'voice'       => $voice_model,
                            'text'        => $text,
                            'audio_bytes' => strlen( $cached_audio ),
                            'settings'    => [ 'style' => $style, 'speed' => $speed, 'pitch' => $pitch, 'payload_mode' => $payload_mode ],
                        ] );
                    }
                    if ( class_exists( 'PressHub_AI_Settings_Storage' ) && class_exists( 'PressHub_AI_API_Client' ) && PressHub_AI_Settings_Storage::get_log_tts_payloads() ) {
                        PressHub_AI_API_Client::write_tts_payload_log( PressHub_AI_API_Client::build_tts_payload_log_entry( 'cache_hit', [
                            'cache_key'   => $cache_key,
                            'voice'       => $voice_model,
                            'engine'      => $engine,
                            'audio_bytes' => strlen( $cached_audio ),
                        ] ) );
                    }
                    return $cached_audio;
                }
            }
        }

        $this->last_turn_was_cache_hit = false;

        if ( $this->live_call_deadline > 0 && microtime( true ) >= $this->live_call_deadline ) {
            return new WP_Error(
                'synthesis_time_budget',
                __( 'Stopped before the request time limit; the next step resumes from the turn cache.', 'presshub-ai-editor' )
            );
        }

        $start_time = microtime( true );

        if ( 'google_cloud' === $engine ) {
            $result = $api_client->synthesize_speech_with_options( $text, $voice_model, $speed, $pitch );
        } else {
            $speaker_configs = null;
            if ( $two_voices ) {
                $speaker_configs = [];
                foreach ( $speakers as $sp ) {
                    $speaker_configs[] = [ 'speaker' => (string) ( $sp['speaker'] ?? '' ), 'voice' => (string) ( $sp['voice'] ?? '' ), 'labelled' => true ];
                }
            }
            $result = $api_client->synthesize_speech_via_gemini( $text, $voice_model, true, $style, $speaker_configs, $speed, $pitch );
        }

        $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
        $char_count  = mb_strlen( $text );

        // synthesize_speech_via_gemini() logs every request it sends (with the
        // model it used), so a Gemini turn is logged here only when it stopped
        // before that log line: a missing key, voice or model, or audio that
        // could not be decoded. Google Cloud TTS does not log itself.
        $logged_by_client = 'google_cloud' !== $engine
            && ! ( is_wp_error( $result ) && in_array( $result->get_error_code(), self::GEMINI_UNLOGGED_ERRORS, true ) );
        if ( ! $logged_by_client && class_exists( 'PressHub_AI_Token_Logger' ) ) {
            if ( is_wp_error( $result ) ) {
                PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', $engine, $voice_model, $char_count, $duration_ms, 'error', $result->get_error_message() );
            } else {
                PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', $engine, $voice_model, $char_count, $duration_ms, 'success', null );
            }
        }

        if ( ! is_wp_error( $result ) && '' !== $cache_file ) {
            $dir = dirname( $cache_file );
            if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( $dir );
            }
            @file_put_contents( $cache_file, $result, LOCK_EX );
        }

        return $result;
    }

    /**
     * The text of a speech request with the pronunciation list applied (2.89.0), in
     * the language being voiced (the podcast language of a translated episode, else
     * Greek). In a labelled two-voice request the "label:" in front of each line is
     * left alone.
     *
     * @param array|null $speakers Two speakers of a labelled request, or null.
     */
    public function apply_pronunciations( string $text, ?array $speakers = null ): string {
        if ( ! class_exists( 'PressHub_AI_TTS_Pronunciations' ) ) {
            require_once __DIR__ . '/class-tts-pronunciations.php';
        }
        $language = is_array( $this->audio_only ) ? (string) ( $this->audio_only['language'] ?? '' ) : '';
        if ( ! is_array( $speakers ) ) {
            return PressHub_AI_TTS_Pronunciations::apply( $text, $language );
        }
        $labels = array_filter( array_map( static function ( $sp ) {
            return is_array( $sp ) ? (string) ( $sp['speaker'] ?? '' ) : '';
        }, $speakers ) );
        $lines = explode( "\n", $text );
        foreach ( $lines as $i => $line ) {
            $pos   = strpos( $line, ':' );
            $label = false === $pos ? '' : trim( substr( $line, 0, $pos ) );
            if ( '' !== $label && in_array( $label, $labels, true ) ) {
                $lines[ $i ] = substr( $line, 0, $pos + 1 ) . PressHub_AI_TTS_Pronunciations::apply( substr( $line, $pos + 1 ), $language );
            } else {
                $lines[ $i ] = PressHub_AI_TTS_Pronunciations::apply( $line, $language );
            }
        }
        return implode( "\n", $lines );
    }

    /**
     * Errors after which a joined request is not tried again turn by turn: they would
     * fail the same way (or, for the time budget, the next step resumes).
     */
    const NO_PER_TURN_FALLBACK = [ 'synthesis_time_budget', 'tts_daily_quota_exceeded', 'no_gemini_key', 'missing_tts_model', 'missing_tts_voice' ];

    /**
     * A joined request whose audio is shorter than its text at this many characters per
     * second was cut short (speech runs at about 14); its turns are voiced one by one.
     */
    const JOINED_MAX_CHARS_PER_SECOND = 40;

    /**
     * 2.76.1: whether Gemini accepts the labelled two-voice request. False since Gemini started
     * rejecting it (see plan_speech_requests()); tests switch it on to keep the joined path covered.
     */
    public static $two_voice_requests_accepted = false;

    /**
     * Label of a host in a two-voice request: the host's name when it is a plain name
     * (letters, digits, spaces, dots, hyphens; up to 40 characters), else SpeakerN.
     *
     * @param string[] $names Host names, presenter 1 first.
     * @return string[] Two distinct labels.
     */
    public static function two_voice_labels( array $names ): array {
        $labels = [];
        foreach ( [ 0, 1 ] as $i ) {
            $name = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $names[ $i ] ?? '' ) ) );
            $labels[ $i ] = ( '' !== $name && preg_match( '/^\p{L}[\p{L}\p{N} .\-]{0,39}$/u', $name ) ) ? $name : 'Speaker' . ( $i + 1 );
        }
        if ( mb_strtolower( $labels[0] ) === mb_strtolower( $labels[1] ) ) {
            $labels = [ 'Speaker1', 'Speaker2' ];
        }
        return $labels;
    }

    /**
     * Plans the speech requests for a list of turns (2.74.0).
     *
     * per_turn: one request per turn, as before. same_voice: back-to-back turns with
     * the same voice share a request. two_voices: back-to-back turns share a request
     * whatever the voice, the lines labelled with the host's name so Gemini picks the
     * voice of each line; only with two hosts and two different voices on Gemini,
     * otherwise it plans like same_voice. A request holds at most $max_chars characters
     * of text; a longer turn is a request of its own. Google Cloud TTS is always per turn.
     *
     * @param array    $turns      [ [ 'speaker' => string, 'text' => string ], ... ]
     * @param string   $mode       per_turn | same_voice | two_voices.
     * @param int      $max_chars  Most characters of text in a joined request.
     * @param callable $voice_of   fn( string $speaker ): string voice name.
     * @param int      $host_count 1, 2 or 3.
     * @param string   $engine     gemini | google_cloud.
     * @param string[] $labels     Two-voice labels for presenter 1 and 2 (two_voice_labels()).
     * @param string[] $two_voices The voices of presenter 1 and 2.
     * @return array [ [ 'turns' => int[], 'text' => string, 'voice' => string, 'speakers' => array|null ], ... ]
     */
    public static function plan_speech_requests( array $turns, string $mode, int $max_chars, callable $voice_of, int $host_count, string $engine, array $labels = [], array $two_voices = [] ): array {
        $turns  = array_values( $turns );
        $voices = [];
        foreach ( $turns as $i => $t ) {
            $voices[ $i ] = (string) $voice_of( (string) ( $t['speaker'] ?? '' ) );
        }
        if ( 'google_cloud' === $engine || ! in_array( $mode, [ 'same_voice', 'two_voices' ], true ) ) {
            $mode = 'per_turn';
        }
        $v1 = trim( (string) ( $two_voices[0] ?? '' ) );
        $v2 = trim( (string) ( $two_voices[1] ?? '' ) );
        if ( 'two_voices' === $mode && ( 2 !== $host_count || '' === $v1 || '' === $v2 || $v1 === $v2 ) ) {
            $mode = 'same_voice';
        }
        // 2.76.1: Gemini now rejects the labelled two-voice request ("Multi-speaker generation
        // requests must specify speech_metadata.speaker for each text part"), so every joined
        // request failed and was voiced again turn by turn: more requests, not fewer. Per-part
        // speakers are what caused the 4.18 voice drift, so two_voices plans like same_voice
        // (single-voice requests only) until a labelled request Gemini accepts is proven live.
        if ( 'two_voices' === $mode && ! self::$two_voice_requests_accepted ) {
            $mode = 'same_voice';
        }
        $labels   = array_values( $labels );
        $label_of = [ $v1 => (string) ( $labels[0] ?? 'Speaker1' ), $v2 => (string) ( $labels[1] ?? 'Speaker2' ) ];
        $flat     = static function ( string $text ): string {
            return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
        };

        $groups  = [];
        $current = [];
        $flush   = static function () use ( &$groups, &$current ) {
            if ( ! empty( $current ) ) {
                $groups[] = $current;
                $current  = [];
            }
        };
        foreach ( $turns as $i => $t ) {
            if ( 'per_turn' === $mode || empty( $current ) ) {
                $flush();
                $current = [ $i ];
                continue;
            }
            $prev_voice = $voices[ end( $current ) ];
            $fits_voice = 'two_voices' === $mode
                ? ( isset( $label_of[ $voices[ $i ] ] ) && isset( $label_of[ $prev_voice ] ) )
                : ( $voices[ $i ] === $prev_voice );
            $chars = 0;
            foreach ( array_merge( $current, [ $i ] ) as $j ) {
                $chars += mb_strlen( $flat( (string) ( $turns[ $j ]['text'] ?? '' ) ) ) + 2;
            }
            if ( $fits_voice && $chars <= $max_chars ) {
                $current[] = $i;
            } else {
                $flush();
                $current = [ $i ];
            }
        }
        $flush();

        $plan = [];
        foreach ( $groups as $g ) {
            $group_voices = array_values( array_unique( array_map( static function ( $j ) use ( $voices ) {
                return $voices[ $j ];
            }, $g ) ) );
            if ( 1 === count( $g ) ) {
                $plan[] = [ 'turns' => $g, 'text' => (string) ( $turns[ $g[0] ]['text'] ?? '' ), 'voice' => $voices[ $g[0] ], 'speakers' => null ];
            } elseif ( 1 === count( $group_voices ) ) {
                $plan[] = [
                    'turns'    => $g,
                    'text'     => implode( "\n\n", array_map( static function ( $j ) use ( $turns, $flat ) {
                        return $flat( (string) ( $turns[ $j ]['text'] ?? '' ) );
                    }, $g ) ),
                    'voice'    => $group_voices[0],
                    'speakers' => null,
                ];
            } else {
                $plan[] = [
                    'turns'    => $g,
                    'text'     => implode( "\n", array_map( static function ( $j ) use ( $turns, $flat, $voices, $label_of ) {
                        return $label_of[ $voices[ $j ] ] . ': ' . $flat( (string) ( $turns[ $j ]['text'] ?? '' ) );
                    }, $g ) ),
                    'voice'    => $v1,
                    'speakers' => [
                        [ 'speaker' => $label_of[ $v1 ], 'voice' => $v1 ],
                        [ 'speaker' => $label_of[ $v2 ], 'voice' => $v2 ],
                    ],
                ];
            }
        }
        return $plan;
    }

    /**
     * Splits the length of a joined request between its turns, by text length, so
     * that the turns plus the turn pauses the timeline adds between them last as
     * long as the request's audio.
     *
     * @param float    $seconds       Length of the request's audio.
     * @param string[] $texts         The turns' texts.
     * @param int      $turn_pause_ms Pause the timeline puts between turns.
     * @return float[]
     */
    public static function split_request_seconds( float $seconds, array $texts, int $turn_pause_ms ): array {
        $n = count( $texts );
        if ( $n <= 1 ) {
            return [ $seconds ];
        }
        $available = max( 0.0, $seconds - ( $n - 1 ) * max( 0, $turn_pause_ms ) / 1000 );
        $lengths   = array_map( static function ( $t ) {
            return max( 1, mb_strlen( trim( (string) $t ) ) );
        }, array_values( $texts ) );
        $total = array_sum( $lengths );
        return array_map( static function ( $len ) use ( $available, $total ) {
            return $available * $len / $total;
        }, $lengths );
    }

    /**
     * Voices a list of turns with the planned requests (plan_speech_requests()) and
     * returns the audio chunks to stitch with the turn pause and each turn's timeline
     * entry. A joined request that fails (other than NO_PER_TURN_FALLBACK) or comes
     * back too short is voiced again turn by turn.
     *
     * @param array $turns Parsed turns.
     * @param array $ctx   voice_of (callable), mode, max_chars, host_count, engine, labels,
     *                     two_voices, speed, pitch, style, date, api_client, turn_pause_ms,
     *                     request_delay_ms, is_test_env, timeline_mp3.
     * @return array|WP_Error [ 'chunks' => string[], 'turns' => timeline turns, 'requests' => int ]
     */
    private function synthesize_turn_list( array $turns, array $ctx ) {
        $turns    = array_values( $turns );
        $plan     = self::plan_speech_requests( $turns, (string) $ctx['mode'], (int) $ctx['max_chars'], $ctx['voice_of'], (int) $ctx['host_count'], (string) $ctx['engine'], (array) $ctx['labels'], (array) $ctx['two_voices'] );
        $chunks   = [];
        $tl_turns = [];
        $mp3      = ! empty( $ctx['timeline_mp3'] );
        $live     = 0;
        $delay    = function ( bool $more ) use ( $ctx ) {
            if ( ! $this->last_turn_was_cache_hit && (int) $ctx['request_delay_ms'] > 0 && empty( $ctx['is_test_env'] ) && $more ) {
                usleep( (int) $ctx['request_delay_ms'] * 1000 );
            }
        };
        $one_turn = function ( int $j, bool $more ) use ( $turns, $ctx, $mp3, &$chunks, &$tl_turns, &$live, $delay ) {
            $t     = $turns[ $j ];
            $voice = (string) ( $ctx['voice_of'] )( (string) ( $t['speaker'] ?? '' ) );
            $audio = $this->synthesize_turn( (string) ( $t['text'] ?? '' ), $voice, (float) $ctx['speed'], (float) $ctx['pitch'], $ctx['api_client'], (string) $ctx['style'], (string) $ctx['date'] );
            if ( is_wp_error( $audio ) ) {
                return $audio;
            }
            $live      += $this->last_turn_was_cache_hit ? 0 : 1;
            $chunks[]   = $audio;
            $tl_turns[] = [
                'speaker' => (string) ( $t['speaker'] ?? '' ),
                'text'    => (string) ( $t['text'] ?? '' ),
                'seconds' => PressHub_AI_Podcast_Timeline::turn_seconds( (string) $audio, (string) ( $t['text'] ?? '' ), $mp3 ),
            ];
            self::touch_run_locks();
            $delay( $more );
            return true;
        };

        foreach ( $plan as $k => $request ) {
            $more = $k + 1 < count( $plan );
            if ( 1 === count( $request['turns'] ) ) {
                $ok = $one_turn( (int) $request['turns'][0], $more );
                if ( is_wp_error( $ok ) ) {
                    return $ok;
                }
                continue;
            }

            $audio = $this->synthesize_turn( (string) $request['text'], (string) $request['voice'], (float) $ctx['speed'], (float) $ctx['pitch'], $ctx['api_client'], (string) $ctx['style'], (string) $ctx['date'], $request['speakers'] );
            $why   = '';
            if ( is_wp_error( $audio ) ) {
                if ( in_array( $audio->get_error_code(), self::NO_PER_TURN_FALLBACK, true ) ) {
                    return $audio;
                }
                $why = $audio->get_error_message();
            } else {
                $live    += $this->last_turn_was_cache_hit ? 0 : 1;
                $seconds = PressHub_AI_Podcast_Timeline::turn_seconds( (string) $audio, (string) $request['text'], $mp3 );
                if ( ! $mp3 && $seconds * self::JOINED_MAX_CHARS_PER_SECOND < mb_strlen( (string) $request['text'] ) ) {
                    $why = sprintf( 'audio too short (%.1f s for %d characters)', $seconds, mb_strlen( (string) $request['text'] ) );
                }
            }
            if ( '' !== $why ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( sprintf( '[LogosAI Synthesizer] Joined speech request (%d turns) not usable: %s. Voicing its turns one by one.', count( $request['turns'] ), $why ) );
                }
                foreach ( $request['turns'] as $n => $j ) {
                    $ok = $one_turn( (int) $j, $more || $n + 1 < count( $request['turns'] ) );
                    if ( is_wp_error( $ok ) ) {
                        return $ok;
                    }
                }
                continue;
            }

            $texts  = array_map( static function ( $j ) use ( $turns ) {
                return (string) ( $turns[ $j ]['text'] ?? '' );
            }, $request['turns'] );
            $shares = self::split_request_seconds( $seconds, $texts, (int) $ctx['turn_pause_ms'] );
            foreach ( $request['turns'] as $n => $j ) {
                $tl_turns[] = [
                    'speaker' => (string) ( $turns[ $j ]['speaker'] ?? '' ),
                    'text'    => (string) ( $turns[ $j ]['text'] ?? '' ),
                    'seconds' => (float) $shares[ $n ],
                ];
            }
            $chunks[] = $audio;
            self::touch_run_locks();
            $delay( $more );
        }
        return [ 'chunks' => $chunks, 'turns' => $tl_turns, 'requests' => $live ];
    }

    /** Seconds after its last sign of life that an audio run's lock is taken as left by a dead process. */
    const RUN_LOCK_TTL = 600;

    /** Locks this process holds: option name => token. */
    private static $held_run_locks = [];

    /** Option holding the audio run lock of one edition. */
    public static function run_lock_name( string $date, string $edition ): string {
        return 'presshub_ai_audio_run_lock_' . preg_replace( '/[^0-9A-Za-z_\-]/', '', $date ) . '_' . ( 'noon' === $edition ? 'noon' : 'morning' );
    }

    /**
     * Takes the audio lock of one edition, so only one audio job voices it at a time
     * (19/11/2026: two jobs started together and sent the same turns twice). True when
     * this process holds it (also when it already did), false when another live job
     * does. A lock untouched for RUN_LOCK_TTL seconds is taken over.
     */
    public static function acquire_run_lock( string $date, string $edition ): bool {
        $name = self::run_lock_name( $date, $edition );
        if ( isset( self::$held_run_locks[ $name ] ) ) {
            return true;
        }
        $token = time() . '.' . bin2hex( random_bytes( 4 ) );
        for ( $try = 0; $try < 2; $try++ ) {
            if ( self::insert_lock( $name, $token ) ) {
                self::$held_run_locks[ $name ] = $token;
                return true;
            }
            $current = self::read_lock( $name );
            if ( null !== $current && time() - (int) $current < self::RUN_LOCK_TTL ) {
                return false;
            }
            self::delete_lock( $name, $current );
        }
        return false;
    }

    /** Releases a lock taken by acquire_run_lock(). */
    public static function release_run_lock( string $date, string $edition ): void {
        $name = self::run_lock_name( $date, $edition );
        if ( isset( self::$held_run_locks[ $name ] ) ) {
            self::delete_lock( $name, self::$held_run_locks[ $name ] );
            unset( self::$held_run_locks[ $name ] );
        }
    }

    /** Whether this process holds the lock of one edition. */
    public static function holds_run_lock( string $date, string $edition ): bool {
        return isset( self::$held_run_locks[ self::run_lock_name( $date, $edition ) ] );
    }

    /** Renews the locks this process holds (called after every speech request). */
    public static function touch_run_locks(): void {
        foreach ( self::$held_run_locks as $name => $token ) {
            $new = time() . '.' . substr( (string) $token, (int) strpos( (string) $token, '.' ) + 1 );
            if ( self::update_lock( $name, (string) $token, $new ) ) {
                self::$held_run_locks[ $name ] = $new;
            }
        }
    }

    /** The database, when it can run the atomic lock queries. */
    private static function lock_db() {
        global $wpdb;
        return ( is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) ) ? $wpdb : null;
    }

    /** Lock rows when there is no database (unit tests without WordPress options). */
    private static $memory_locks = [];

    private static function insert_lock( string $name, string $token ): bool {
        $db = self::lock_db();
        if ( $db ) {
            return 1 === (int) $db->query( $db->prepare( "INSERT IGNORE INTO {$db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $token ) );
        }
        if ( isset( self::$memory_locks[ $name ] ) ) {
            return false;
        }
        self::$memory_locks[ $name ] = $token;
        return true;
    }

    private static function read_lock( string $name ): ?string {
        $db = self::lock_db();
        if ( $db ) {
            $val = $db->get_var( $db->prepare( "SELECT option_value FROM {$db->options} WHERE option_name = %s", $name ) );
            return null === $val ? null : (string) $val;
        }
        return self::$memory_locks[ $name ] ?? null;
    }

    private static function update_lock( string $name, string $old, string $new ): bool {
        $db = self::lock_db();
        if ( $db ) {
            return 1 === (int) $db->query( $db->prepare( "UPDATE {$db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $name, $old ) );
        }
        if ( ( self::$memory_locks[ $name ] ?? null ) !== $old ) {
            return false;
        }
        self::$memory_locks[ $name ] = $new;
        return true;
    }

    private static function delete_lock( string $name, ?string $token ): void {
        $db = self::lock_db();
        if ( $db ) {
            if ( null === $token ) {
                $db->query( $db->prepare( "DELETE FROM {$db->options} WHERE option_name = %s", $name ) );
            } else {
                $db->query( $db->prepare( "DELETE FROM {$db->options} WHERE option_name = %s AND option_value = %s", $name, $token ) );
            }
            if ( function_exists( 'wp_cache_delete' ) ) {
                wp_cache_delete( $name, 'options' );
            }
            return;
        }
        if ( null === $token || ( self::$memory_locks[ $name ] ?? null ) === $token ) {
            unset( self::$memory_locks[ $name ] );
        }
    }

    /**
     * Clear cached turn audio files from disk.
     *
     * @param string $date Optional briefing date (YYYY-MM-DD) to clear specific cache; empty clears all.
     * @return void
     */
    public static function clear_turn_cache( string $date = '' ): void {
        $uploads        = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [ 'basedir' => sys_get_temp_dir() ];
        $base_cache_dir = trailingslashit( $uploads['basedir'] ) . 'presshub-ai/tts-cache';
        $safe_date      = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $date ) : preg_replace( '/[^a-zA-Z0-9_\-]/', '', $date );
        $target_dir     = '' !== $date ? $base_cache_dir . '/' . $safe_date : $base_cache_dir;
        if ( is_dir( $target_dir ) ) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $target_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $files as $fileinfo ) {
                $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
                @$todo( $fileinfo->getRealPath() );
            }
            @rmdir( $target_dir );
        }
    }

    /**
     * Sideload binary audio data into WordPress Media Library.
     *
     * @param string $filename    Target filename (e.g. 'podcast-2026-08-26.mp3' or 'podcast-2026-08-26.wav').
     * @param string $binary_data Binary audio bytes.
     * @param int    $post_id     Optional parent post ID.
     * @param string $title       Attachment title.
     * @return array|WP_Error Array with ['id' => int, 'url' => string] or WP_Error.
     */
    public function sideload_audio_file( string $filename, string $binary_data, int $post_id = 0, string $title = '' ) {
        // Auto-detect extension from binary header
        $is_wav = ( strlen( $binary_data ) >= 4 && 'RIFF' === substr( $binary_data, 0, 4 ) );
        if ( $is_wav ) {
            if ( preg_match( '/\.mp3$/i', $filename ) ) {
                $filename = preg_replace( '/\.mp3$/i', '.wav', $filename );
            } elseif ( ! preg_match( '/\.wav$/i', $filename ) ) {
                $filename .= '.wav';
            }
        } else {
            if ( ! preg_match( '/\.mp3$/i', $filename ) ) {
                $filename .= '.mp3';
            }
        }

        $filepath = trailingslashit( get_temp_dir() ) . $filename;
        file_put_contents( $filepath, $binary_data );

        if ( defined( 'ABSPATH' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $filepath,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id, $title );
        @unlink( $filepath );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        $attachment_id = (int) $attachment_id;
        $url = wp_get_attachment_url( $attachment_id );

        return [
            'id'  => $attachment_id,
            'url' => $url ? $url : '',
        ];
    }

    /**
     * Issue #91 — Strip audio-synthesis topic scaffolding from a script
     * before it is paragraphized for publication.
     *
     * Topic markers exist solely to instruct the synthesizer to split
     * synthesis per topic; they have no business appearing in the
     * public-facing transcript. Drop entire lines matching any of the
     * four variants enumerated in PressHub_AI_Podcast_Producer::parse_script():
     *   - [TOPIC_START: Title] ... [TOPIC_END]
     *   - <!-- TOPIC_START: Title --> ... <!-- TOPIC_END -->
     *   - === TOPIC: Title === ... === END ===
     *   - ### TOPIC: Title ... ### END
     * Then collapse runs of blank lines so paragraphization only sees
     * dialogue lines.
     *
     * @param string $script Raw script text (multi-line).
     * @return string Script with topic-marker lines dropped.
     */
    public function strip_topic_markers( string $script ): string {
        if ( '' === trim( $script ) ) {
            return $script;
        }

        $lines = preg_split( '/\r?\n/', $script );
        $out   = [];

        // Mirrors PressHub_AI_Podcast_Producer::parse_script() patterns
        // (L570 for TOPIC_START and L592 for TOPIC_END) so the parser
        // and the stripper recognize the same set of variants.
        $topic_start_re = '/^\s*(?:\[|\<\!\-\-|\={2,3}|\#{2,3})?\s*TOPIC(?:_?START)?\s*:[^\]\>\=]*(?:\]|\-\-\>|\={2,3})?\s*$/iu';
        $topic_end_re   = '/^\s*(?:\[|\<\!\-\-)?\s*TOPIC_?END\s*(?:\]|\-\-\>)?\s*$/iu';

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( '' === $trimmed ) {
                $out[] = $line;
                continue;
            }
            if ( preg_match( $topic_start_re, $trimmed ) ) {
                continue;
            }
            if ( preg_match( $topic_end_re, $trimmed ) ) {
                continue;
            }
            // Strip any inline topic markers that appear on the same line as dialogue
            $line = preg_replace( '/\[\s*TOPIC(?:_?START)?\s*:[^\]]*\]/iu', '', $line );
            $line = preg_replace( '/\[\s*TOPIC_?END\s*\]/iu', '', $line );
            $line = preg_replace( '/\<\!\-\-\s*TOPIC(?:_?START)?\s*:[^\>]*\-\-\>/iu', '', $line );
            $line = preg_replace( '/\<\!\-\-\s*TOPIC_?END\s*\-\-\>/iu', '', $line );
            if ( '' !== trim( $line ) ) {
                $out[] = trim( $line );
            }
        }

        // Collapse runs of 3+ consecutive blank lines into a single blank
        // line so the resulting paragraphization doesn't leave awkward gaps.
        $collapsed = preg_replace( '/\n{3,}/', "\n\n", implode( "\n", $out ) );
        // Trim leading/trailing blank lines.
        return trim( $collapsed );
    }

    /**
     * Issue #191 — show notes under the audio player: the topic list with
     * approximate start times, a link to the same edition's written brief,
     * and that brief's sources list. Returns '' when there is nothing to show.
     *
     * Times come from each topic's share of the script's words, scaled to the
     * audio length (attachment metadata), else to an assumed speaking rate.
     */
    public function build_show_notes_html( string $script, string $date, string $edition, int $attachment_id = 0 ): string {
        $show_notes = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_podcast_show_notes_enabled() : true;
        if ( function_exists( 'apply_filters' ) ? ! apply_filters( 'presshub_ai_podcast_show_notes_enabled', $show_notes, $date, $edition ) : ! $show_notes ) {
            return '';
        }

        $blocks = [];

        $topics = $this->show_notes_topics( $script );
        if ( count( $topics ) >= 2 ) {
            $total_words = 0;
            foreach ( $topics as $t ) {
                $total_words += $t['words'];
            }
            $all_words = max( 1, $this->count_words( $this->strip_topic_markers( $script ) ) );
            $duration  = 0.0;
            if ( $attachment_id > 0 && function_exists( 'wp_get_attachment_metadata' ) ) {
                $meta     = wp_get_attachment_metadata( $attachment_id );
                $duration = is_array( $meta ) ? (float) ( $meta['length'] ?? 0 ) : 0.0;
            }
            if ( $duration <= 0 ) {
                $wpm      = function_exists( 'apply_filters' ) ? (float) apply_filters( 'presshub_ai_podcast_words_per_minute', 150 ) : 150.0;
                $duration = $all_words / max( 60.0, $wpm ) * 60.0;
            }
            $sec_per_word = $duration / $all_words;

            $items = '';
            foreach ( $topics as $t ) {
                $start  = (int) round( $t['offset_words'] * $sec_per_word );
                $items .= '<li><strong>' . esc_html( sprintf( '%d:%02d', intdiv( $start, 60 ), $start % 60 ) ) . '</strong> ' . esc_html( $t['title'] ) . '</li>';
            }
            $blocks[] = '<h3 class="wp-block-heading">' . esc_html__( 'Θέματα του επεισοδίου', 'presshub-ai-editor' ) . '</h3>'
                . '<ul class="presshub-podcast-topics">' . $items . '</ul>';
        }

        $brief = $this->find_brief_post( $date, $edition );
        if ( $brief ) {
            $status = (string) ( $brief->post_status ?? '' );
            if ( 'publish' === $status && function_exists( 'get_permalink' ) ) {
                $url = (string) get_permalink( (int) $brief->ID );
                if ( '' !== $url ) {
                    $blocks[] = '<p class="presshub-podcast-brief-link">' . esc_html__( 'Διαβάστε την πλήρη γραπτή ενημέρωση:', 'presshub-ai-editor' )
                        . ' <a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $brief->post_title ?? '' ) ) . '</a></p>';
                }
            }
            $sources_class = class_exists( 'PressHub_AI_Briefing_SEO' ) ? PressHub_AI_Briefing_SEO::SOURCES_CLASS : 'presshub-briefing-sources';
            if ( preg_match( '#<ul class="' . preg_quote( $sources_class, '#' ) . '">.*?</ul>#su', (string) ( $brief->post_content ?? '' ), $m ) ) {
                $blocks[] = '<h3 class="wp-block-heading">' . esc_html__( 'Πηγές', 'presshub-ai-editor' ) . '</h3>' . $m[0];
            }
        }

        if ( empty( $blocks ) ) {
            return '';
        }
        return "<!-- wp:html -->\n<div class=\"presshub-podcast-show-notes\">" . implode( "\n", $blocks ) . "</div>\n<!-- /wp:html -->";
    }

    /**
     * Topics marked in the script, in order, with their word counts and the
     * number of spoken words before each one. [] when there are no markers.
     */
    public function show_notes_topics( string $script ): array {
        $start_re = '/^\s*(?:\[|\<\!\-\-|\={2,3}|\#{2,3})?\s*TOPIC(?:_?START)?\s*:\s*([^\]\>\=]+?)\s*(?:\]|\-\-\>|\={2,3})?\s*$/iu';
        $end_re   = '/^\s*(?:\[|\<\!\-\-)?\s*TOPIC_?END\s*(?:\]|\-\-\>)?\s*$/iu';
        $topics   = [];
        $current  = null;
        $offset   = 0;
        foreach ( preg_split( '/\r?\n/', $script ) as $line ) {
            $trim = trim( $line );
            if ( '' === $trim ) {
                continue;
            }
            if ( preg_match( $start_re, $trim, $m ) ) {
                if ( null !== $current ) {
                    $topics[] = $current;
                }
                $current = [ 'title' => trim( $m[1] ), 'words' => 0, 'offset_words' => $offset ];
                continue;
            }
            if ( preg_match( $end_re, $trim ) ) {
                if ( null !== $current ) {
                    $topics[] = $current;
                    $current  = null;
                }
                continue;
            }
            $spoken = preg_replace( '/^\s*\[[^\]]*\]\s*:\s*/u', '', $trim );
            $n      = $this->count_words( $spoken );
            $offset += $n;
            if ( null !== $current ) {
                $current['words'] += $n;
            }
        }
        if ( null !== $current ) {
            $topics[] = $current;
        }
        return array_values( array_filter( $topics, function ( $t ) {
            return '' !== $t['title'] && $t['words'] > 0;
        } ) );
    }

    private function count_words( string $text ): int {
        $text = preg_replace( '/^\s*\[[^\]]*\]\s*:\s*/mu', '', $text );
        return (int) preg_match_all( '/[\p{L}\p{N}]+/u', $text );
    }

    /** The written brief post for this date and edition, or null. */
    private function find_brief_post( string $date, string $edition, array $statuses = [ 'publish', 'future', 'pending', 'draft' ] ) {
        if ( '' === $date || ! function_exists( 'get_posts' ) ) {
            return null;
        }
        $posts = get_posts( [
            'post_type'        => 'post',
            'post_status'      => $statuses,
            'numberposts'      => 1,
            'suppress_filters' => true,
            'meta_query'       => [
                [ 'key' => '_presshub_briefing_date', 'value' => $date ],
                [ 'key' => '_presshub_briefing_type', 'value' => 'text' ],
                [ 'key' => '_presshub_briefing_edition', 'value' => $edition ],
            ],
        ] );
        if ( empty( $posts ) ) {
            return null;
        }
        $p = $posts[0];
        if ( is_numeric( $p ) && function_exists( 'get_post' ) ) {
            $p = get_post( (int) $p );
        }
        return is_object( $p ) ? $p : null;
    }

    /**
     * Format raw dialogue transcript into structured HTML paragraphs.
     *
     * @param string $transcript Raw dialogue script.
     * @return string Formatted HTML.
     */
    public function format_transcript_html( string $transcript ): string {
        // Issue #91 — drop audio-synthesis topic scaffolding before
        // paragraphization so [TOPIC_START] / [TOPIC_END] markers and
        // their HTML/Markdown variants never leak into the published
        // post body.
        $transcript = $this->strip_topic_markers( $transcript );

        $lines = explode( "\n", trim( $transcript ) );
        $paragraphs = [];

        $female_host   = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_female() : 'Presenter 1';
        $male_host     = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_male() : 'Presenter 2';
        $tertiary_host = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_tertiary() : 'Presenter 3';
        $host_count    = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_count() : 2;

        foreach ( $lines as $line ) {
            $t = trim( $line );
            if ( '' === $t ) {
                continue;
            }

            // Match speaker lines like: [Μαρία]: ..., **[Μαρία]:** ..., **Μαρία:** ..., Μαρία: ..., [SPEAKER_1]: ...
            if ( preg_match( '/^(?:\s*[*_#\s]*)\s*(?:\[|\()?([^\n:\]\)]+)(?:\]|\))?\s*(?:[*_]*)\s*:\s*(?:[*_]*)\s*(.*)$/u', $t, $m ) ) {
                $raw_label   = trim( $m[1] );
                $speech      = trim( preg_replace( '/^\*\*|\*\*$/', '', $m[2] ) );
                $label_lower = strtolower( $raw_label );

                // Match raw speaker label before stripping punctuation
                if ( in_array( $label_lower, [ 'speaker_1', 'speaker 1', 'speaker1', 'presenter_1', 'presenter 1', 'presenter1', 'host_1', 'host 1', 'host1', 'female' ], true )
                    || ( '' !== trim( $female_host ) && function_exists( 'mb_stripos' ) && false !== mb_stripos( $raw_label, trim( $female_host ) ) )
                ) {
                    $speaker_display = $female_host;
                } elseif ( in_array( $label_lower, [ 'speaker_2', 'speaker 2', 'speaker2', 'presenter_2', 'presenter 2', 'presenter2', 'host_2', 'host 2', 'host2', 'male' ], true )
                    || ( '' !== trim( $male_host ) && function_exists( 'mb_stripos' ) && false !== mb_stripos( $raw_label, trim( $male_host ) ) )
                ) {
                    if ( 1 === $host_count && class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::error( sprintf( '[LogosAI Transcript] Unexpected presenter 2 label "%s" in solo host briefing transcript.', $raw_label ) );
                    }
                    $speaker_display = $male_host;
                } elseif ( in_array( $label_lower, [ 'speaker_3', 'speaker 3', 'speaker3', 'presenter_3', 'presenter 3', 'presenter3', 'host_3', 'host 3', 'host3', 'tertiary' ], true )
                    || ( '' !== trim( $tertiary_host ) && function_exists( 'mb_stripos' ) && false !== mb_stripos( $raw_label, trim( $tertiary_host ) ) )
                ) {
                    if ( 1 === $host_count && class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::error( sprintf( '[LogosAI Transcript] Unexpected presenter 3 label "%s" in solo host briefing transcript.', $raw_label ) );
                    }
                    $speaker_display = $tertiary_host;
                } else {
                    $speaker_display = trim( preg_replace( '/[^\p{L}\p{N}\s]+/u', '', $raw_label ) );
                }

                $paragraphs[] = sprintf( '<p><strong>%s:</strong> %s</p>', esc_html( $speaker_display ), esc_html( $speech ) );
            } elseif ( 0 !== strpos( $t, '#' ) ) {
                $paragraphs[] = sprintf( '<p>%s</p>', esc_html( $t ) );
            }
        }

        if ( empty( $paragraphs ) ) {
            return PressHub_AI_Markdown::to_html( $transcript );
        }

        return implode( "\n", $paragraphs );
    }

    /**
     * Create WordPress Podcast post with Gutenberg Audio block, transcript, category, and metadata.
     *
     * @param string $audio_url     URL of the audio attachment.
     * @param int    $attachment_id Attachment ID in Media Library.
     * @param string $transcript    Dialogue transcript text.
     * @param string $date          Briefing date (YYYY-MM-DD).
     * @param string $title         Optional post title override.
     * @param string $edition       Briefing edition ('morning' or 'noon').
     * @return int|WP_Error Created post ID or WP_Error on failure.
     */
    public function create_podcast_post( string $audio_url, int $attachment_id, string $transcript, string $date, string $title = '', string $edition = 'morning' ) {
        $edition = ( 'noon' === $edition ) ? 'noon' : 'morning';

        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        // Format title if empty
        if ( empty( trim( $title ) ) ) {
            $timestamp = strtotime( $date );
            $formatted_date = ( false !== $timestamp )
                ? ( function_exists( 'date_i18n' ) ? date_i18n( 'd/m/Y', $timestamp ) : date( 'd/m/Y', $timestamp ) )
                : $date;
            if ( 'noon' === $edition ) {
                $prefix = class_exists( 'PressHub_AI_Settings_Storage' )
                    ? PressHub_AI_Settings_Storage::get_briefing_noon_podcast_title_prefix()
                    : 'Μεσημβρινό Podcast Ενημέρωσης - ';
                $title = sprintf( '%s%s', $prefix, $formatted_date );
            } else {
                $prefix = class_exists( 'PressHub_AI_Settings_Storage' )
                    ? PressHub_AI_Settings_Storage::get_briefing_podcast_title_prefix()
                    : 'Πρωινό Podcast Ενημέρωσης - ';
                $title = sprintf( '%s%s', $prefix, $formatted_date );
            }
        }

        // Category handling
        $cat_option = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_category() : (int) get_option( self::OPTION_CATEGORY, 0 );
        $post_category = [];
        if ( ! empty( $cat_option ) && is_numeric( $cat_option ) && (int) $cat_option > 0 ) {
            $post_category = [ (int) $cat_option ];
        } elseif ( ! empty( $cat_option ) && is_array( $cat_option ) ) {
            $post_category = array_map( 'intval', $cat_option );
        }

        // Post status (default pending)
        $status_option = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_status() : (string) get_option( self::OPTION_STATUS, 'pending' );
        $post_status = ! empty( trim( $status_option ) ) ? trim( $status_option ) : 'pending';

        // Content: WordPress Audio Block (+ optional Formatted Transcript if configured)
        $include_text = class_exists( 'PressHub_AI_Settings_Storage' )
            ? PressHub_AI_Settings_Storage::get_briefing_podcast_include_text()
            : 0;

        // Lead story text first, so the post opens with the day's top story;
        // the excerpt filter shows the same text on the home page (2.63.0).
        $lead      = $this->lead_story_text( $date, $edition );
        $lead_html = $this->lead_story_html( $lead );

        $post_content = ( '' !== $lead_html ? $lead_html . "\n\n" : '' )
            . "<!-- wp:audio {\"id\":{$attachment_id}} -->\n"
            . "<figure class=\"wp-block-audio\"><audio controls src=\"" . esc_url_raw( $audio_url ) . "\"></audio></figure>\n"
            . "<!-- /wp:audio -->";

        $show_notes = $this->build_show_notes_html( $transcript, $date, $edition, $attachment_id );
        if ( '' !== $show_notes ) {
            $post_content .= "\n\n" . $show_notes;
        }

        if ( $include_text ) {
            // Issue #91 — strip audio-synthesis topic scaffolding BEFORE format_transcript_html()
            $formatted_transcript = $this->format_transcript_html( $this->strip_topic_markers( $transcript ) );
            $post_content .= "\n\n<!-- wp:heading {\"level\":3} -->\n"
                . "<h3 class=\"wp-block-heading\">" . __( 'Απομαγνητοφώνηση / Διάλογος', 'presshub-ai-editor' ) . "</h3>\n"
                . "<!-- /wp:heading -->\n\n"
                . $formatted_transcript;
        }

        $postarr = [
            'post_title'   => $title,
            'post_content' => wp_kses_post( $post_content ),
            'post_status'  => $post_status,
            'post_type'    => 'post',
            'post_excerpt' => '',
            'meta_input'   => [
                '_presshub_briefing_date'        => $date,
                '_presshub_briefing_type'        => 'podcast',
                '_presshub_briefing_edition'     => $edition,
                '_presshub_audio_attachment_id' => $attachment_id,
                '_presshub_audio_url'           => $audio_url,
                '_presshub_podcast_script'      => $transcript,
                self::META_LEAD                 => $lead,
            ],
        ];

        if ( $include_text ) {
            $clean_script  = $this->strip_topic_markers( $transcript );
            $clean_script  = preg_replace( '/\[\s*(?:SPEAKER_\d+|Presenter\s*\d+)\s*\]\s*:\s*/iu', '', $clean_script );
            $clean_excerpt = wp_trim_words( wp_strip_all_tags( $clean_script ), 35 );
            if ( ! empty( $clean_excerpt ) ) {
                $postarr['post_excerpt'] = $clean_excerpt;
            }
        }

        if ( ! empty( $post_category ) ) {
            $postarr['post_category'] = $post_category;
        }

        // QA gate: a podcast set to publish is saved as pending and released
        // by the gate once its tags, excerpt and image are in place.
        require_once __DIR__ . '/class-publish-gate.php';
        $postarr = PressHub_AI_Publish_Gate::prepare_insert( $postarr, 'podcast' );

        $post_id = wp_insert_post( $postarr, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( empty( $post_id ) || ! is_numeric( $post_id ) ) {
            return new WP_Error( 'post_creation_failed', __( 'Failed to create podcast post.', 'presshub-ai-editor' ) );
        }

        $post_id = (int) $post_id;

        // Explicitly update meta
        update_post_meta( $post_id, '_presshub_briefing_date', $date );
        update_post_meta( $post_id, '_presshub_briefing_type', 'podcast' );
        update_post_meta( $post_id, '_presshub_briefing_edition', $edition );
        update_post_meta( $post_id, '_presshub_audio_attachment_id', $attachment_id );
        update_post_meta( $post_id, '_presshub_audio_url', $audio_url );
        update_post_meta( $post_id, '_presshub_podcast_script', $transcript );
        update_post_meta( $post_id, self::META_LEAD, $lead );

        // Assign featured image: auto-generate or from configured pool (with collision avoidance)
        $img_source     = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_featured_image_source() : 'pool';
        $assigned_thumb = false;

        if ( 'auto_generate' === $img_source && class_exists( 'PressHub_AI_Settings_Storage' ) && PressHub_AI_Settings_Storage::get_image_feature_enabled() ) {
            try {
                $client     = new PressHub_AI_API_Client();
                require_once __DIR__ . '/class-image-prompt-templates.php';
                $img_tpl    = PressHub_AI_Image_Prompt_Templates::resolve( PressHub_AI_Settings_Storage::get_briefing_edition_image_template( 'podcast', $edition ), 'briefing_podcast_' . $edition );
                $gen_prompt = $client->analyze_article_for_image_prompt( PressHub_AI_SEO::clean_text( $transcript ), $title, $img_tpl['template'], $img_tpl['id'] );
                if ( ! is_wp_error( $gen_prompt ) && ! empty( $gen_prompt ) ) {
                    $img_res = $client->generate_featured_image( $gen_prompt, '', $post_id );
                    if ( ! is_wp_error( $img_res ) && ! empty( $img_res['id'] ) ) {
                        if ( function_exists( 'set_post_thumbnail' ) ) {
                            set_post_thumbnail( $post_id, (int) $img_res['id'] );
                            $assigned_thumb = true;
                        }
                    }
                }
            } catch ( Throwable $t ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( 'Auto-generation of featured image failed for podcast post: ' . $t->getMessage() );
                }
            }
        }

        if ( ! $assigned_thumb ) {
            $exclude_ids = class_exists( 'PressHub_AI_Settings_Storage' )
                ? PressHub_AI_Settings_Storage::get_used_briefing_thumbnail_ids_for_date( $date, $post_id )
                : [];
            $thumb_id = class_exists( 'PressHub_AI_Settings_Storage' )
                ? PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id( $edition, $exclude_ids )
                : 0;
            if ( $thumb_id > 0 && function_exists( 'set_post_thumbnail' ) ) {
                set_post_thumbnail( $post_id, $thumb_id );
            }
        }

        // Tags, SEO meta description (excerpt) and focus keyphrase from the dialogue script.
        if ( class_exists( 'PressHub_AI_SEO' ) ) {
            PressHub_AI_SEO::auto_optimize_post( $post_id, $title, $transcript, 'podcast' );
        }

        // QA gate on the show notes and the spoken script (editorial and
        // legal) and the post (measured SEO checks), then the configured status.
        PressHub_AI_Publish_Gate::release( $post_id, 'podcast', 'noon' === $edition ? 'podcast_noon' : 'podcast_morning' );

        return $post_id;
    }

    /**
     * Update an existing podcast post with new audio attachment and dialogue script.
     *
     * @param int    $post_id       WordPress post ID.
     * @param string $audio_url     URL of the new audio file.
     * @param int    $attachment_id Media attachment ID of the new audio file.
     * @param string $script        Corrected dialogue script text.
     * @param string $date          Briefing date (YYYY-MM-DD).
     * @param string $title         Optional post title override.
     * @param string $edition       Briefing edition ('morning' or 'noon').
     * @return int|WP_Error Updated post ID or WP_Error on failure.
     */
    public function update_podcast_post( int $post_id, string $audio_url, int $attachment_id, string $script, string $date, string $title = '', string $edition = 'morning' ) {
        $edition = ( 'noon' === $edition ) ? 'noon' : 'morning';

        $include_text = class_exists( 'PressHub_AI_Settings_Storage' )
            ? PressHub_AI_Settings_Storage::get_briefing_podcast_include_text()
            : 0;

        // Lead story text first, so the post opens with the day's top story;
        // the excerpt filter shows the same text on the home page (2.63.0).
        $lead      = $this->lead_story_text( $date, $edition );
        $lead_html = $this->lead_story_html( $lead );

        $post_content = ( '' !== $lead_html ? $lead_html . "\n\n" : '' )
            . "<!-- wp:audio {\"id\":{$attachment_id}} -->\n"
            . "<figure class=\"wp-block-audio\"><audio controls src=\"" . esc_url_raw( $audio_url ) . "\"></audio></figure>\n"
            . "<!-- /wp:audio -->";

        $show_notes = $this->build_show_notes_html( $script, $date, $edition, $attachment_id );
        if ( '' !== $show_notes ) {
            $post_content .= "\n\n" . $show_notes;
        }

        if ( $include_text ) {
            $formatted_transcript = $this->format_transcript_html( $this->strip_topic_markers( $script ) );
            $post_content .= "\n\n<!-- wp:heading {\"level\":3} -->\n"
                . "<h3 class=\"wp-block-heading\">" . __( 'Απομαγνητοφώνηση / Διάλογος', 'presshub-ai-editor' ) . "</h3>\n"
                . "<!-- /wp:heading -->\n\n"
                . $formatted_transcript;
        }

        $postarr = [
            'ID'           => $post_id,
            'post_content' => wp_kses_post( $post_content ),
            'post_excerpt' => '',
        ];
        if ( ! empty( $title ) ) {
            $postarr['post_title'] = $title;
        }
        if ( $include_text ) {
            $clean_script  = $this->strip_topic_markers( $script );
            $clean_script  = preg_replace( '/\[\s*(?:SPEAKER_\d+|Presenter\s*\d+)\s*\]\s*:\s*/iu', '', $clean_script );
            $clean_excerpt = wp_trim_words( wp_strip_all_tags( $clean_script ), 35 );
            if ( ! empty( $clean_excerpt ) ) {
                $postarr['post_excerpt'] = $clean_excerpt;
            }
        }

        // Keep the SEO meta description generated when the post was created.
        $saved_seo = get_post_meta( $post_id, '_presshub_ai_seo', true );
        if ( is_array( $saved_seo ) && ! empty( $saved_seo['meta_description'] ) ) {
            $postarr['post_excerpt'] = (string) $saved_seo['meta_description'];
        }

        $result = wp_update_post( $postarr, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        update_post_meta( $post_id, '_presshub_briefing_date', $date );
        update_post_meta( $post_id, '_presshub_briefing_type', 'podcast' );
        update_post_meta( $post_id, '_presshub_briefing_edition', $edition );
        update_post_meta( $post_id, '_presshub_audio_attachment_id', $attachment_id );
        update_post_meta( $post_id, '_presshub_audio_url', $audio_url );
        update_post_meta( $post_id, '_presshub_podcast_script', $script );
        update_post_meta( $post_id, self::META_LEAD, $lead );

        // If post lacks a featured image, assign one (auto-generate or from pool with collision avoidance)
        if ( function_exists( 'has_post_thumbnail' ) && ! has_post_thumbnail( $post_id ) ) {
            $img_source     = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_featured_image_source() : 'pool';
            $assigned_thumb = false;

            if ( 'auto_generate' === $img_source && class_exists( 'PressHub_AI_Settings_Storage' ) && PressHub_AI_Settings_Storage::get_image_feature_enabled() ) {
                try {
                    $client     = new PressHub_AI_API_Client();
                    require_once __DIR__ . '/class-image-prompt-templates.php';
                    $img_tpl    = PressHub_AI_Image_Prompt_Templates::resolve( PressHub_AI_Settings_Storage::get_briefing_edition_image_template( 'podcast', $edition ), 'briefing_podcast_' . $edition );
                    $gen_prompt = $client->analyze_article_for_image_prompt( PressHub_AI_SEO::clean_text( $script ), get_the_title( $post_id ), $img_tpl['template'], $img_tpl['id'] );
                    if ( ! is_wp_error( $gen_prompt ) && ! empty( $gen_prompt ) ) {
                        $img_res = $client->generate_featured_image( $gen_prompt, '', $post_id );
                        if ( ! is_wp_error( $img_res ) && ! empty( $img_res['id'] ) ) {
                            if ( function_exists( 'set_post_thumbnail' ) ) {
                                set_post_thumbnail( $post_id, (int) $img_res['id'] );
                                $assigned_thumb = true;
                            }
                        }
                    }
                } catch ( Throwable $t ) {
                    if ( class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::warning( 'Auto-generation of featured image failed for updated podcast post: ' . $t->getMessage() );
                    }
                }
            }

            if ( ! $assigned_thumb ) {
                $exclude_ids = class_exists( 'PressHub_AI_Settings_Storage' )
                    ? PressHub_AI_Settings_Storage::get_used_briefing_thumbnail_ids_for_date( $date, $post_id )
                    : [];
                $thumb_id = class_exists( 'PressHub_AI_Settings_Storage' )
                    ? PressHub_AI_Settings_Storage::get_random_briefing_featured_image_id( $edition, $exclude_ids )
                    : 0;
                if ( $thumb_id > 0 && function_exists( 'set_post_thumbnail' ) ) {
                    set_post_thumbnail( $post_id, $thumb_id );
                }
            }
        }

        // QA gate on the new script: a held podcast is released when it now
        // passes, and a live one that now fails goes back to Pending Review.
        require_once __DIR__ . '/class-publish-gate.php';
        PressHub_AI_Publish_Gate::release( $post_id, 'podcast', 'noon' === $edition ? 'podcast_noon' : 'podcast_morning' );

        return $post_id;
    }

    /**
     * Regenerate podcast audio from a corrected script and update the existing post.
     *
     * @param int                         $post_id    Existing podcast post ID.
     * @param string                      $script     Corrected dialogue script text.
     * @param PressHub_AI_API_Client|null $api_client Optional API client.
     * @return array|WP_Error Result array with new audio_url, attachment_id, post_id or WP_Error.
     */
    public function regenerate_podcast_for_post( int $post_id, string $script, ?PressHub_AI_API_Client $api_client = null ) {
        if ( empty( $post_id ) || ! is_numeric( $post_id ) ) {
            return new WP_Error( 'invalid_post', __( 'Invalid post ID provided.', 'presshub-ai-editor' ) );
        }
        $post_id = (int) $post_id;
        $type = get_post_meta( $post_id, '_presshub_briefing_type', true );
        if ( 'podcast' !== $type ) {
            return new WP_Error( 'not_a_podcast', __( 'The specified post is not a PressHub podcast briefing.', 'presshub-ai-editor' ) );
        }

        $date    = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        $edition = (string) get_post_meta( $post_id, '_presshub_briefing_edition', true );
        if ( empty( $edition ) ) {
            $edition = 'morning';
        }

        if ( empty( trim( $script ) ) ) {
            return new WP_Error( 'empty_script', __( 'Dialogue script cannot be empty.', 'presshub-ai-editor' ) );
        }

        // Save corrected script to snapshot so disk cache matches
        if ( class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            $producer = new PressHub_AI_Podcast_Producer();
            // save_script( date, script, context_mode, source_post_id, attempted_at, edition ).
            // An empty context_mode leaves the existing sidecar (and its generation_record_id) intact.
            $producer->save_script( $date, $script, '', 0, '', $edition );
        }

        return $this->synthesize_podcast( $date, $script, $edition, $api_client, $post_id );
    }

    /**
     * Issue #79 — Read audio-stats meta previously persisted on a podcast
     * post by synthesize_podcast(). Returns a structured array the Briefing
     * Hub aggregator uses to render the Stage 4 status box.
     *
     * Missing/legacy posts gracefully yield empty-string slots so the UI can
     * render "—" or "Unknown" without throwing.
     *
     * @param int $post_id WordPress post ID.
     * @return array{duration_sec:float,filesize:int,sample_rate:int,format:string,engine:string,
     *               female_voice:string,male_voice:string,tertiary_voice:string,
     *               host_count:int,split_by_topic:int,topic_count:int,
     *               attempted_at:string,completed_at:string} Structured audio meta.
     */
    public static function get_audio_meta( int $post_id ): array {
        $defaults = [
            'duration_sec'    => 0.0,
            'filesize'        => 0,
            'sample_rate'     => 0,
            'format'          => '',
            'engine'          => '',
            'female_voice'    => '',
            'male_voice'      => '',
            'tertiary_voice'  => '',
            'host_count'      => 0,
            'split_by_topic'  => 0,
            'topic_count'     => 0,
            'attempted_at'    => '',
            'completed_at'    => '',
        ];

        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return $defaults;
        }

        $engine_value = (string) get_post_meta( $post_id, '_presshub_audio_engine', true );
        $allowed_engines = [ 'gemini', 'google_cloud', 'google_cloud_tts' ];
        if ( ! in_array( $engine_value, $allowed_engines, true ) ) {
            $engine_value = '';
        }

        return [
            'duration_sec'    => round( (float) get_post_meta( $post_id, '_presshub_audio_duration_sec', true ), 2 ),
            'filesize'        => (int) get_post_meta( $post_id, '_presshub_audio_filesize', true ),
            'sample_rate'     => (int) get_post_meta( $post_id, '_presshub_audio_sample_rate', true ),
            'format'          => strtolower( (string) get_post_meta( $post_id, '_presshub_audio_format', true ) ),
            'engine'          => $engine_value,
            'female_voice'    => (string) get_post_meta( $post_id, '_presshub_audio_female_voice', true ),
            'male_voice'      => (string) get_post_meta( $post_id, '_presshub_audio_male_voice', true ),
            'tertiary_voice'  => (string) get_post_meta( $post_id, '_presshub_audio_tertiary_voice', true ),
            'host_count'      => (int) get_post_meta( $post_id, '_presshub_audio_host_count', true ),
            'split_by_topic'  => (int) get_post_meta( $post_id, '_presshub_audio_split_by_topic', true ),
            'topic_count'     => (int) get_post_meta( $post_id, '_presshub_audio_topic_count', true ),
            'attempted_at'    => (string) get_post_meta( $post_id, '_presshub_audio_attempted_at', true ),
            'completed_at'    => (string) get_post_meta( $post_id, '_presshub_audio_completed_at', true ),
        ];
    }

    /**
     * Synthesize Greek daily news briefing podcast end-to-end.
     *
     * In Gemini mode (logosAI replication), performs single-pass natural speech synthesis
     * where the model fluidly acts and alternates speakers in one continuous stream.
     *
     * @param string                       $date              Target briefing date (YYYY-MM-DD).
     * @param string                       $custom_script     Optional script text override.
     * @param string|PressHub_AI_API_Client $edition_or_client Optional edition ('morning'/'noon') or API client.
     * @param PressHub_AI_API_Client|null $api_client        Optional API client.
     * @param int                          $existing_post_id  Optional existing post ID to update rather than creating a new post.
     * @return array|WP_Error Result payload array or WP_Error on failure.
     */
    public function synthesize_podcast( string $date = '', ?string $custom_script = '', $edition_or_client = 'morning', ?PressHub_AI_API_Client $api_client = null, int $existing_post_id = 0 ) {
        // Issue #140: synthesize_podcast_run() opens the TTS call session (current parent
        // generation record) once the script is resolved; it is always closed here.
        // Issue #154: one run-level row per synthesis (start, end, status, edition,
        // turns), so the Briefing Hub audio card shows the whole run rather than
        // the last TTS turn. The per-turn podcast_audio rows still feed TTS stats.
        // 2.74.0 - one audio job per edition at a time. A second job stops here,
        // before any speech request and without a run row on the audio card.
        $lock_date    = '' !== $date ? $date : ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
        $lock_edition = self::resolve_run_edition( $custom_script, $edition_or_client );
        $had_lock     = self::holds_run_lock( $lock_date, $lock_edition );
        if ( ! self::acquire_run_lock( $lock_date, $lock_edition ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info( 'Podcast audio not started: another audio job is already voicing this edition.', [ 'date' => $lock_date, 'edition' => $lock_edition ] );
            }
            return new WP_Error( 'synthesis_in_progress', __( 'The audio of this edition is already being made by another job. Try again when it has finished.', 'presshub-ai-editor' ) );
        }

        $run_started    = microtime( true );
        $run_started_at = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $result         = null;
        try {
            $result = $this->synthesize_podcast_run( $date, $custom_script, $edition_or_client, $api_client, $existing_post_id );
            if ( is_array( $result ) && ! empty( $result['success'] ) && ! empty( $result['post_id'] ) ) {
                self::start_languages( $result );
            }
            return $result;
        } catch ( \Throwable $e ) {
            // Keep the exception's message for the audio card, then let it through.
            $result = new WP_Error( 'synthesis_exception', $e->getMessage() );
            throw $e;
        } finally {
            if ( class_exists( 'PressHub_AI_TTS_Call_Recorder' ) ) {
                PressHub_AI_TTS_Call_Recorder::end_session();
            }
            self::log_audio_run( $result, $date, $custom_script, $edition_or_client, $run_started, $run_started_at );
            if ( ! $had_lock ) {
                self::release_run_lock( $lock_date, $lock_edition );
            }
        }
    }

    /**
     * Starts the English and Turkish versions (those turned on) of a Greek podcast just
     * voiced, by any path: the cron step, the watchdog retry, the Hub's re-run or a
     * corrected script. A language whose Greek script did not change keeps its
     * translation. A problem here never counts as a failed Greek synthesis.
     */
    private static function start_languages( array $result ): void {
        if ( ! class_exists( 'PressHub_AI_Podcast_Translations' ) ) {
            return;
        }
        try {
            PressHub_AI_Podcast_Translations::start( (string) ( $result['date'] ?? '' ), 'noon' === ( $result['edition'] ?? '' ) ? 'noon' : 'morning', (int) $result['post_id'] );
        } catch ( \Throwable $t ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( 'Podcast languages could not start: ' . $t->getMessage(), [ 'date' => (string) ( $result['date'] ?? '' ), 'edition' => (string) ( $result['edition'] ?? '' ) ] );
            }
        }
    }

    /**
     * Edition a synthesize_podcast() call is for (same argument shuffling as the run).
     */
    public static function resolve_run_edition( ?string $custom_script, $edition_or_client ): string {
        if ( in_array( $custom_script, [ 'morning', 'noon' ], true ) && ( 'morning' === $edition_or_client || $edition_or_client instanceof PressHub_AI_API_Client || null === $edition_or_client ) ) {
            return $custom_script;
        }
        return ( is_string( $edition_or_client ) && 'noon' === $edition_or_client ) ? 'noon' : 'morning';
    }

    /**
     * Write the run-level podcast_audio_run token-log row (Issue #154). Never throws.
     *
     * @param mixed $result Return of synthesize_podcast_run(): array, WP_Error, or null when it threw.
     */
    private static function log_audio_run( $result, string $date, ?string $custom_script, $edition_or_client, float $started, string $started_at ): void {
        if ( ! class_exists( 'PressHub_AI_Token_Logger' ) ) {
            return;
        }
        try {
            $is_ok    = is_array( $result ) && ! empty( $result['success'] );
            $error    = $is_ok ? null : ( is_wp_error( $result ) ? $result->get_error_message() : __( 'Synthesis stopped before it finished.', 'presshub-ai-editor' ) );
            $edition  = $is_ok && ! empty( $result['edition'] ) ? (string) $result['edition'] : self::resolve_run_edition( $custom_script, $edition_or_client );
            $run_date = $is_ok && ! empty( $result['date'] ) ? (string) $result['date'] : ( '' !== $date ? $date : ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ) ) );
            $meta     = [
                'edition'    => $edition,
                'date'       => $run_date,
                'started_at' => $started_at,
                'turns'      => $is_ok ? (int) ( $result['turns_count'] ?? 0 ) : 0,
                'post_id'    => $is_ok ? (int) ( $result['post_id'] ?? 0 ) : 0,
            ];
            if ( ! $is_ok && is_wp_error( $result ) ) {
                $meta['error_code'] = (string) $result->get_error_code();
            }
            PressHub_AI_Token_Logger::log_tts_request(
                'podcast_audio_run',
                $is_ok ? (string) ( $result['engine'] ?? '' ) : '',
                'run',
                0, // characters are counted on the per-turn rows; do not double count.
                (int) round( ( microtime( true ) - $started ) * 1000 ),
                $is_ok ? 'success' : 'error',
                $error,
                $meta
            );
        } catch ( \Throwable $e ) {
            // Logging must never break synthesis.
        }
    }

    /**
     * Body of synthesize_podcast() (same arguments and return value).
     */
    private function synthesize_podcast_run( string $date, ?string $custom_script, $edition_or_client, ?PressHub_AI_API_Client $api_client, int $existing_post_id ) {
        // Smart parameter shift: if caller called synthesize_podcast($date, 'noon') or synthesize_podcast($date, 'morning')
        if ( in_array( $custom_script, [ 'morning', 'noon' ], true ) && ( 'morning' === $edition_or_client || $edition_or_client instanceof PressHub_AI_API_Client || null === $edition_or_client ) ) {
            if ( $edition_or_client instanceof PressHub_AI_API_Client ) {
                $api_client = $edition_or_client;
            }
            $edition       = $custom_script;
            $custom_script = '';
        } elseif ( $edition_or_client instanceof PressHub_AI_API_Client ) {
            $api_client = $edition_or_client;
            $edition    = 'morning';
        } else {
            $edition = ( is_string( $edition_or_client ) && 'noon' === $edition_or_client ) ? 'noon' : 'morning';
        }

        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'tts' );
        }
        if ( method_exists( $api_client, 'set_action' ) ) {
            $api_client->set_action( 'podcast_audio' );
        }

        // 1. Resolve script
        $script = trim( (string) $custom_script );
        if ( empty( $script ) ) {
            $producer = new PressHub_AI_Podcast_Producer();
            $stored = $producer->get_script( $date, $edition );
            if ( empty( $stored ) ) {
                return new WP_Error(
                    'no_script',
                    sprintf( __( 'No podcast script found for date: %s (%s edition). Please generate a script first.', 'presshub-ai-editor' ), $date, $edition )
                );
            }
            $script = $stored;
        }

        // 2. Parse turns for metadata & metrics
        $producer      = new PressHub_AI_Podcast_Producer();
        $female_host   = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_female() : (string) get_option( 'presshub_ai_briefing_host_female', 'Presenter 1' );
        $male_host     = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_male() : (string) get_option( 'presshub_ai_briefing_host_male', 'Presenter 2' );
        $tertiary_host = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_tertiary() : (string) get_option( 'presshub_ai_briefing_host_tertiary', 'Presenter 3' );
        if ( empty( trim( $female_host ) ) ) {
            $female_host = 'Presenter 1';
        }
        if ( empty( trim( $male_host ) ) ) {
            $male_host = 'Presenter 2';
        }
        if ( empty( trim( $tertiary_host ) ) ) {
            $tertiary_host = 'Presenter 3';
        }

        $turns = $producer->parse_script_turns( $script, $female_host, $male_host, $tertiary_host );

        if ( empty( $turns ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( 'Could not parse turns for audio synthesis on date ' . $date );
            }
            return new WP_Error(
                'invalid_script',
                __( 'Could not parse any speaker turns from the podcast script.', 'presshub-ai-editor' )
            );
        }

        // Issue #140 - every TTS call below is appended to the podcast_script record that
        // produced this script (tts_calls_json). Without one, the calls go to a tts_test record.
        if ( class_exists( 'PressHub_AI_TTS_Call_Recorder' ) ) {
            // A translation's calls go to a record of their own (a tts_test record carrying
            // the origin and language), never over the Greek podcast's calls.
            $tts_parent_id = null !== $this->audio_only ? 0 : (int) ( $producer->get_script_meta( $date, $edition )['generation_record_id'] ?? 0 );
            $tts_context   = [ 'origin' => null !== $this->audio_only ? 'podcast_translation' : 'podcast_synthesis', 'date' => $date, 'edition' => $edition ];
            if ( null !== $this->audio_only ) {
                $tts_context['language'] = (string) $this->audio_only['language'];
            }
            PressHub_AI_TTS_Call_Recorder::begin_session( $tts_parent_id, $tts_context );
        }

        $engine         = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_engine() : (string) get_option( self::OPTION_ENGINE, 'gemini' );
        $split_by_topic = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_audio_split_by_topic() : (bool) (int) get_option( 'presshub_ai_briefing_audio_split_by_topic', 1 );
        $host_count     = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_count() : (int) get_option( 'presshub_ai_briefing_host_count', 2 );

        $presenter_1_voice = $this->get_voice_for_speaker( 'presenter_1' );
        $presenter_2_voice = $this->get_voice_for_speaker( 'presenter_2' );
        $presenter_3_voice = $this->get_voice_for_speaker( 'presenter_3' );
        if ( null !== $this->audio_only ) {
            $overrides         = (array) $this->audio_only['voices'];
            $presenter_1_voice = '' !== trim( (string) ( $overrides['presenter_1'] ?? '' ) ) ? trim( (string) $overrides['presenter_1'] ) : $presenter_1_voice;
            $presenter_2_voice = '' !== trim( (string) ( $overrides['presenter_2'] ?? '' ) ) ? trim( (string) $overrides['presenter_2'] ) : $presenter_2_voice;
            $presenter_3_voice = '' !== trim( (string) ( $overrides['presenter_3'] ?? '' ) ) ? trim( (string) $overrides['presenter_3'] ) : $presenter_3_voice;
        }
        $female_voice      = $presenter_1_voice;
        $male_voice        = $presenter_2_voice;
        $tertiary_voice    = $presenter_3_voice;

        $speed          = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_speed() : (float) get_option( self::OPTION_VOICE_SPEED, 1.0 );
        $pitch          = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_pitch() : (float) get_option( self::OPTION_VOICE_PITCH, 0.0 );
        $style_key      = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_style() : (string) get_option( self::OPTION_STYLE, 'formal' );
        $custom_style   = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_custom_style() : (string) get_option( self::OPTION_CUSTOM_STYLE, '' );
        $used_style     = ( 'custom' === $style_key && ! empty( $custom_style ) ) ? $custom_style : $style_key;
        if ( null !== $this->audio_only ) {
            $used_style = $this->style_for_language( $used_style, (string) $this->audio_only['language'] );
        }

        $turn_pause_ms    = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_turn_pause_ms() : 250;
        $topic_pause_ms   = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_topic_pause_ms() : 600;
        $request_delay_ms = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_request_delay_ms() : 250;

        $is_test_env = ( defined( 'PRESSHUB_AI_UNIT_TEST' ) && PRESSHUB_AI_UNIT_TEST )
            || ! empty( $GLOBALS['HTTP_MOCK'] )
            || ! empty( $GLOBALS['WP_REMOTE_POST_CALLBACK'] )
            || ! empty( $GLOBALS['MOCK_HTTP_RESPONSES'] )
            || ! empty( $GLOBALS['CAPTURE_FILTER'] )
            || ( class_exists( 'Mock_Audio_API_Client' ) && $api_client instanceof Mock_Audio_API_Client );

        // Issue #79 — record the timestamp when audio synthesis was *attempted* so
        // the Briefing Hub stage card can later show "Synthesis Start Attempt"
        // even when the actual synthesis fails partway. completed_at is set
        // once the podcast post is created (i.e. the pipeline finished).
        $podcast_audio_attempted_at = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

        $stitched_audio = '';

        // Helper closure to resolve turn voice
        $resolve_turn_voice = function( string $speaker ) use ( $host_count, $presenter_1_voice, $presenter_2_voice, $presenter_3_voice ): string {
            if ( 1 === $host_count ) {
                return $presenter_1_voice;
            }
            if ( 'tertiary' === $speaker || 'presenter_3' === $speaker || 'speaker_3' === $speaker ) {
                return $presenter_3_voice;
            }
            if ( 'female' === $speaker || 'presenter_1' === $speaker || 'speaker_1' === $speaker ) {
                return $presenter_1_voice;
            }
            if ( 'male' === $speaker || 'presenter_2' === $speaker || 'speaker_2' === $speaker ) {
                return $presenter_2_voice;
            }
            return $this->get_voice_for_speaker( $speaker );
        };

        // 1. Topic-Based Audio Synthesis (Splits generation by topic to eliminate neural voice drift)
        // Turn lengths for the episode's caption timeline (PressHub_AI_Podcast_Timeline).
        $timeline_topics = [];
        $timeline_opts   = [];
        $timeline_mp3    = ( 'google_cloud' === $engine );

        // 2.74.0 - how turns become speech requests (temporary quota saver).
        $speech_ctx = [
            'voice_of'         => $resolve_turn_voice,
            'mode'             => class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_request_mode() : 'per_turn',
            'max_chars'        => class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_request_max_chars() : 1500,
            'host_count'       => (int) $host_count,
            'engine'           => (string) $engine,
            'labels'           => self::two_voice_labels( [ $female_host, $male_host ] ),
            'two_voices'       => [ (string) $presenter_1_voice, (string) $presenter_2_voice ],
            'speed'            => $speed,
            'pitch'            => $pitch,
            'style'            => $used_style,
            'date'             => $date,
            'api_client'       => $api_client,
            'turn_pause_ms'    => $turn_pause_ms,
            'request_delay_ms' => $request_delay_ms,
            'is_test_env'      => $is_test_env,
            'timeline_mp3'     => $timeline_mp3,
        ];

        if ( $split_by_topic ) {
            $topics = $producer->parse_script_topics( $script, $female_host, $male_host, $tertiary_host );

            if ( ! empty( $topics ) ) {
                $topic_wavs    = [];
                $total_chars   = 0;
                $overall_start = microtime( true );

                foreach ( $topics as $topic_idx => $topic ) {
                    $topic_turns  = $topic['turns'] ?? [];
                    if ( empty( $topic_turns ) ) {
                        continue;
                    }

                    $topic_script = $topic['script'] ?? '';
                    $total_chars += mb_strlen( $topic_script );

                    if ( class_exists( 'PressHub_AI_Logger' ) ) {
                        PressHub_AI_Logger::info( sprintf(
                            '[LogosAI Synthesizer] Synthesizing topic %d/%d ("%s", %d turns, %d chars)...',
                            $topic_idx + 1,
                            count( $topics ),
                            $topic['title'] ?? '',
                            count( $topic_turns ),
                            mb_strlen( $topic_script )
                        ) );
                    }

                    if ( class_exists( 'PressHub_AI_Settings_Storage' )
                        && class_exists( 'PressHub_AI_API_Client' )
                    ) {
                        $turn_assignments = [];
                        foreach ( $topic_turns as $turn_idx => $t ) {
                            $speaker_id    = (string) ( $t['speaker'] ?? '' );
                            $speaker_voice = $resolve_turn_voice( $speaker_id );
                            $turn_assignments[] = [
                                'turn'    => $turn_idx + 1,
                                'speaker' => $speaker_id,
                                'voice'   => $speaker_voice,
                                'chars'   => mb_strlen( (string) ( $t['text'] ?? '' ) ),
                            ];
                        }
                        $topic_log_payload = [
                            'date'             => $date,
                            'topic_index'      => $topic_idx + 1,
                            'topic_total'      => count( $topics ),
                            'topic_title'      => (string) ( $topic['title'] ?? '' ),
                            'turns_count'      => count( $topic_turns ),
                            'chars'            => mb_strlen( $topic_script ),
                            'host_count'       => $host_count,
                            'female_voice'     => $presenter_1_voice,
                            'male_voice'       => ( $host_count >= 2 ) ? $presenter_2_voice : '',
                            'style'            => $used_style,
                            'turn_assignments' => $turn_assignments,
                        ];
                        if ( $host_count >= 3 ) {
                            $topic_log_payload['tertiary_voice'] = $presenter_3_voice;
                        }
                        if ( class_exists( 'PressHub_AI_TTS_Call_Recorder' ) ) {
                            PressHub_AI_TTS_Call_Recorder::log_summary( 'topic', $topic_log_payload );
                        }
                        if ( PressHub_AI_Settings_Storage::get_log_tts_payloads() ) {
                            $topic_log_entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
                                'topic',
                                $topic_log_payload
                            );
                            PressHub_AI_API_Client::write_tts_payload_log( $topic_log_entry );
                        }
                    }

                    // Turn-by-turn synthesis for all host counts; 2.74.0 can join
                    // turns into fewer requests (presshub_ai_briefing_tts_request_mode).
                    $voiced = $this->synthesize_turn_list( $topic_turns, $speech_ctx );
                    if ( is_wp_error( $voiced ) ) {
                        return $voiced;
                    }
                    $chunk_wavs = $voiced['chunks'];
                    $tl_turns   = $voiced['turns'];

                    if ( 'google_cloud' === $engine ) {
                        $topic_audio = $this->stitch_audio_chunks( $chunk_wavs, $turn_pause_ms );
                    } else {
                        $topic_audio = $this->stitch_wav_chunks( $chunk_wavs, $turn_pause_ms, 24000 );
                    }

                    if ( is_wp_error( $topic_audio ) ) {
                        return $topic_audio;
                    }

                    $topic_wavs[]      = $topic_audio;
                    $timeline_topics[] = [ 'title' => (string) ( $topic['title'] ?? '' ), 'turns' => $tl_turns ];
                }

                if ( ! empty( $topic_wavs ) ) {
                    $intro_sfx_setting = PressHub_AI_Settings_Storage::get_briefing_audio_intro_sfx();
                    $trans_sfx_setting = PressHub_AI_Settings_Storage::get_briefing_audio_transition_sfx();
                    $outro_sfx_setting = PressHub_AI_Settings_Storage::get_briefing_audio_outro_sfx();

                    $intro_pcm = $this->get_sfx_pcm( $intro_sfx_setting );
                    $trans_pcm = $this->get_sfx_pcm( $trans_sfx_setting );
                    $outro_pcm = $this->get_sfx_pcm( $outro_sfx_setting );

                    // Gaps as stitched below: MP3 topics are joined by the pause alone.
                    $timeline_opts = [
                        'turn_pause_ms'      => $turn_pause_ms,
                        'topic_pause_ms'     => $topic_pause_ms,
                        'transition_seconds' => ( ! $timeline_mp3 && count( $topic_wavs ) > 1 && '' !== (string) $trans_pcm ) ? PressHub_AI_Podcast_Timeline::pcm_seconds( (string) $trans_pcm ) : 0.0,
                        'intro_seconds'      => '' !== (string) $intro_pcm ? PressHub_AI_Podcast_Timeline::pcm_seconds( (string) $intro_pcm ) : 0.0,
                        'outro_seconds'      => '' !== (string) $outro_pcm ? PressHub_AI_Podcast_Timeline::pcm_seconds( (string) $outro_pcm ) : 0.0,
                        'host_count'         => (int) $host_count,
                        'estimated'          => $timeline_mp3,
                    ];

                    // 1. Stitch topics together with Topic Transition SFX between them
                    if ( count( $topic_wavs ) > 1 ) {
                        $stitched_topics = ( 'google_cloud' === $engine )
                            ? $this->stitch_audio_chunks( $topic_wavs, $topic_pause_ms )
                            : $this->stitch_wav_chunks( $topic_wavs, $topic_pause_ms, 24000, $trans_pcm );
                    } else {
                        $stitched_topics = $topic_wavs[0];
                    }

                    // 2. Assemble Intro + Topics + Outro with clean natural silence pause (zero Transition SFX between Intro/Outro and topics)
                    $final_chunks = [];
                    if ( ! empty( $intro_pcm ) ) {
                        $final_chunks[] = $this->wrap_pcm_in_wav( $intro_pcm );
                    }
                    $final_chunks[] = $stitched_topics;
                    if ( ! empty( $outro_pcm ) ) {
                        $final_chunks[] = $this->wrap_pcm_in_wav( $outro_pcm );
                    }

                    if ( count( $final_chunks ) > 1 ) {
                        $stitched_audio = ( 'google_cloud' === $engine )
                            ? $this->stitch_audio_chunks( $final_chunks, $topic_pause_ms )
                            : $this->stitch_wav_chunks( $final_chunks, $topic_pause_ms, 24000, '' );
                    } else {
                        $stitched_audio = $final_chunks[0];
                    }

                    $duration_ms    = (int) round( ( microtime( true ) - $overall_start ) * 1000 );

                    if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                        PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', $engine, 'topic_stitched', $total_chars, $duration_ms, 'success', count( $topic_wavs ) . '_topics' );
                    }

                    // Structured "stitched" payload log entry
                    if ( class_exists( 'PressHub_AI_Settings_Storage' )
                        && class_exists( 'PressHub_AI_API_Client' )
                    ) {
                        $stitched_metrics_payload = [
                            'date'               => $date,
                            'phase'              => 'topic_stitched',
                            'topic_count'        => count( $topics ),
                            'chunks_count'       => count( $final_chunks ),
                            'total_chars'        => $total_chars,
                            'duration_ms'        => $duration_ms,
                            'audio_bytes'        => strlen( (string) $stitched_audio ),
                            'sample_rate_hz'     => 24000,
                            'pause_ms'           => $topic_pause_ms,
                            'turn_pause_ms'      => $turn_pause_ms,
                            'has_intro_sfx'      => ! empty( $intro_pcm ),
                            'has_outro_sfx'      => ! empty( $outro_pcm ),
                            'has_transition_sfx' => ! empty( $trans_pcm ),
                            'female_voice'       => $presenter_1_voice,
                            'male_voice'         => ( $host_count >= 2 ) ? $presenter_2_voice : '',
                            'host_count'         => $host_count,
                        ];
                        if ( $host_count >= 3 ) {
                            $stitched_metrics_payload['tertiary_voice'] = $presenter_3_voice;
                        }
                        if ( class_exists( 'PressHub_AI_TTS_Call_Recorder' ) ) {
                            PressHub_AI_TTS_Call_Recorder::log_summary( 'stitched', $stitched_metrics_payload );
                        }
                        if ( PressHub_AI_Settings_Storage::get_log_tts_payloads() ) {
                            $stitched_metrics_entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
                                'stitched',
                                $stitched_metrics_payload
                            );
                            PressHub_AI_API_Client::write_tts_payload_log( $stitched_metrics_entry );
                        }
                    }
                }
            }
        }

        // 2. Whole-Script Turn-by-Turn Synthesis (when topic splitting is disabled or topic pass produced no audio)
        if ( empty( $stitched_audio ) ) {
            $turn_wavs   = [];
            $start_time  = microtime( true );
            $char_count  = 0;
            $tl_turns    = [];

            foreach ( $turns as $turn ) {
                $char_count += mb_strlen( (string) ( $turn['text'] ?? '' ) );
            }
            $voiced = $this->synthesize_turn_list( $turns, $speech_ctx );
            if ( is_wp_error( $voiced ) ) {
                return $voiced;
            }
            $turn_wavs = $voiced['chunks'];
            $tl_turns  = $voiced['turns'];

            $duration_ms     = (int) round( ( microtime( true ) - $start_time ) * 1000 );
            $timeline_topics = [ [ 'title' => '', 'turns' => $tl_turns ] ];
            $timeline_opts   = [
                'turn_pause_ms'  => $turn_pause_ms,
                'topic_pause_ms' => $topic_pause_ms,
                'host_count'     => (int) $host_count,
                'estimated'      => $timeline_mp3,
            ];
            if ( 'google_cloud' === $engine ) {
                $stitched_audio = $this->stitch_audio_chunks( $turn_wavs, $turn_pause_ms );
            } else {
                $stitched_audio = $this->stitch_wav_chunks( $turn_wavs, $turn_pause_ms, 24000 );
            }

            if ( empty( $stitched_audio ) ) {
                return new WP_Error(
                    'stitching_failed',
                    __( 'Failed to stitch synthesized turn audio chunks.', 'presshub-ai-editor' )
                );
            }

            if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
                $label = ( 1 === $host_count ) ? $presenter_1_voice : ( ( 2 === $host_count ) ? $presenter_1_voice . '+' . $presenter_2_voice : $presenter_1_voice . '+' . $presenter_2_voice . '+' . $presenter_3_voice );
                PressHub_AI_Token_Logger::log_tts_request( 'podcast_audio', $engine, $label, $char_count, $duration_ms, 'success', 'whole_script_stitched' );
            }

            if ( class_exists( 'PressHub_AI_Settings_Storage' )
                && class_exists( 'PressHub_AI_API_Client' )
            ) {
                $stitched_metrics_payload = [
                    'date'               => $date,
                    'phase'              => 'whole_script_stitched',
                    'topic_count'        => 0,
                    'chunks_count'       => count( $turn_wavs ),
                    'total_chars'        => $char_count,
                    'duration_ms'        => $duration_ms,
                    'audio_bytes'        => strlen( (string) $stitched_audio ),
                    'sample_rate_hz'     => 24000,
                    'pause_ms'           => $topic_pause_ms,
                    'turn_pause_ms'      => $turn_pause_ms,
                    'has_intro_sfx'      => false,
                    'has_outro_sfx'      => false,
                    'has_transition_sfx' => false,
                    'female_voice'       => $presenter_1_voice,
                    'male_voice'         => ( $host_count >= 2 ) ? $presenter_2_voice : '',
                    'host_count'         => $host_count,
                ];
                if ( $host_count >= 3 ) {
                    $stitched_metrics_payload['tertiary_voice'] = $presenter_3_voice;
                }
                if ( class_exists( 'PressHub_AI_TTS_Call_Recorder' ) ) {
                    PressHub_AI_TTS_Call_Recorder::log_summary( 'stitched', $stitched_metrics_payload );
                }
                if ( PressHub_AI_Settings_Storage::get_log_tts_payloads() ) {
                    $stitched_metrics_entry = PressHub_AI_API_Client::build_tts_payload_log_entry(
                        'stitched',
                        $stitched_metrics_payload
                    );
                    PressHub_AI_API_Client::write_tts_payload_log( $stitched_metrics_entry );
                }
            }
        }

        if ( empty( $stitched_audio ) ) {
            return new WP_Error(
                'stitching_failed',
                __( 'Failed to generate synthesized audio stream.', 'presshub-ai-editor' )
            );
        }

        // A translation: hand back the audio and its timeline; the caller stores them.
        if ( null !== $this->audio_only ) {
            return [
                'audio'           => $stitched_audio,
                'ext'             => ( strlen( $stitched_audio ) >= 4 && 'RIFF' === substr( $stitched_audio, 0, 4 ) ) ? '.wav' : '.mp3',
                'timeline_topics' => $timeline_topics,
                'timeline_opts'   => $timeline_opts,
                'turns_count'     => count( $turns ),
                'topic_count'     => ! empty( $topics ) ? count( $topics ) : 0,
                'engine'          => (string) $engine,
                'voices'          => [
                    'presenter_1' => (string) $presenter_1_voice,
                    'presenter_2' => $host_count >= 2 ? (string) $presenter_2_voice : '',
                    'presenter_3' => $host_count >= 3 ? (string) $presenter_3_voice : '',
                ],
            ];
        }

        // Save copy to daily briefing storage
        $harvester = new PressHub_AI_News_Harvester();
        $snapshot_dir = $harvester->get_snapshot_dir( $date );
        if ( ! is_dir( $snapshot_dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( $snapshot_dir );
            } else {
                @mkdir( $snapshot_dir, 0755, true );
            }
        }
        $ext = ( strlen( $stitched_audio ) >= 4 && 'RIFF' === substr( $stitched_audio, 0, 4 ) ) ? '.wav' : '.mp3';
        $audio_filename = ( 'noon' === $edition ) ? ( 'podcast-noon' . $ext ) : ( 'podcast' . $ext );
        $local_audio_path = trailingslashit( $snapshot_dir ) . $audio_filename;
        @file_put_contents( $local_audio_path, $stitched_audio );

        // Sideload to Media Library
        $edition_slug = ( 'noon' === $edition ) ? '-noon' : '';
        $filename = sprintf( 'podcast-briefing%s-%s-%s%s', $edition_slug, $date, uniqid(), $ext );
        $formatted_date = function_exists( 'date_i18n' ) ? date_i18n( 'd/m/Y', strtotime( $date ) ) : $date;
        $title    = ( 'noon' === $edition )
            ? sprintf( '%s%s', ( class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_noon_podcast_title_prefix() : 'Μεσημβρινό Podcast Ενημέρωσης - ' ), $formatted_date )
            : sprintf( '%s%s', ( class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_title_prefix() : 'Πρωινό Podcast Ενημέρωσης - ' ), $formatted_date );
        $media    = $this->sideload_audio_file( $filename, $stitched_audio, 0, $title );

        if ( is_wp_error( $media ) ) {
            return $media;
        }

        $attachment_id = (int) $media['id'];
        $audio_url     = (string) $media['url'];

        // Create or update WordPress Podcast Post
        if ( $existing_post_id > 0 ) {
            $post_id = $this->update_podcast_post( $existing_post_id, $audio_url, $attachment_id, $script, $date, $title, $edition );
        } else {
            $post_id = $this->create_podcast_post( $audio_url, $attachment_id, $script, $date, $title, $edition );
        }
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Caption timeline of the episode, for its YouTube video. Never breaks synthesis.
        if ( function_exists( 'update_post_meta' ) && (int) $post_id > 0 ) {
            try {
                $timeline = PressHub_AI_Podcast_Timeline::build( $timeline_topics, $timeline_opts );
                if ( PressHub_AI_Podcast_Timeline::is_valid( $timeline ) ) {
                    // update_post_meta() is false both on a failed write and when the value is
                    // unchanged; only a stored value that differs counts as a failure.
                    if ( false === update_post_meta( (int) $post_id, PressHub_AI_Podcast_Timeline::META_KEY, $timeline )
                        && get_post_meta( (int) $post_id, PressHub_AI_Podcast_Timeline::META_KEY, true ) != $timeline ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
                        throw new \RuntimeException( 'the timeline could not be written to the post' );
                    }
                    if ( function_exists( 'delete_post_meta' ) ) {
                        delete_post_meta( (int) $post_id, PressHub_AI_Podcast_Timeline::ERROR_META_KEY );
                    }
                } else {
                    if ( function_exists( 'delete_post_meta' ) ) {
                        delete_post_meta( (int) $post_id, PressHub_AI_Podcast_Timeline::META_KEY );
                    }
                    update_post_meta( (int) $post_id, PressHub_AI_Podcast_Timeline::ERROR_META_KEY, 'no spoken turn had audio and text to time' );
                }
            } catch ( \Throwable $e ) {
                // An earlier timeline belongs to the earlier audio: never keep it with new audio.
                if ( function_exists( 'delete_post_meta' ) ) {
                    delete_post_meta( (int) $post_id, PressHub_AI_Podcast_Timeline::META_KEY );
                }
                // Marked, so the episode is not taken for one made before 2.54.0.
                update_post_meta( (int) $post_id, PressHub_AI_Podcast_Timeline::ERROR_META_KEY, substr( $e->getMessage(), 0, 300 ) );
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( 'Podcast timeline could not be saved: ' . $e->getMessage() );
                }
            }
        }

        // Host-count gating for saved voices
        if ( 1 === (int) $host_count ) {
            $saved_p1 = $presenter_1_voice;
            $saved_p2 = '';
            $saved_p3 = '';
        } elseif ( 2 === (int) $host_count ) {
            $saved_p1 = $presenter_1_voice;
            $saved_p2 = $presenter_2_voice;
            $saved_p3 = '';
        } else {
            $saved_p1 = $presenter_1_voice;
            $saved_p2 = $presenter_2_voice;
            $saved_p3 = $presenter_3_voice;
        }

        // Issue #131 - link the podcast post to the script generation record and
        // store the TTS synthesis configuration on that record. Must never break
        // synthesis, so everything is guarded and exceptions are swallowed.
        if ( class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            try {
                $script_meta      = $producer->get_script_meta( $date, $edition );
                $script_record_id = (int) ( $script_meta['generation_record_id'] ?? 0 );
                if ( $script_record_id > 0 && (int) $post_id > 0 ) {
                    PressHub_AI_Generation_Recorder::attach_to_post( (int) $post_id, $script_record_id );

                    // 'generated' when the synthesized script is exactly what the LLM
                    // returned; 'edited' when an editor changed it (saved edit or a
                    // custom script passed in) before synthesis.
                    $script_record = PressHub_AI_Generation_Recorder::get( $script_record_id );
                    $script_source = ( null !== $script_record && trim( (string) ( $script_record['response_text'] ?? '' ) ) === trim( (string) $script ) )
                        ? 'generated'
                        : 'edited';

                    PressHub_AI_Generation_Recorder::update_record( $script_record_id, [
                        'tts' => [
                            'engine'        => (string) $engine,
                            'model'         => class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_model() : '',
                            // The models that actually spoke (fallbacks included), from the calls logged so far.
                            'models_used'   => class_exists( 'PressHub_AI_TTS_Call_Recorder' ) ? (array) ( PressHub_AI_TTS_Call_Recorder::summarize_record( $script_record_id )['models'] ?? [] ) : [],
                            'payload_mode'  => class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_payload_mode() : '',
                            'voices'        => [
                                'presenter_1' => (string) $saved_p1,
                                'presenter_2' => (string) $saved_p2,
                                'presenter_3' => (string) $saved_p3,
                            ],
                            'host_count'    => (int) $host_count,
                            'style'         => (string) $used_style,
                            'speed'         => $speed,
                            'pitch'         => $pitch,
                            'turn_pause_ms' => (int) $turn_pause_ms,
                            'topic_pause_ms' => (int) $topic_pause_ms,
                            'split_by_topic' => (bool) $split_by_topic,
                            'topic_count'   => ! empty( $topics ) ? count( $topics ) : 0,
                            'turns_count'   => count( $turns ),
                            'script_source' => $script_source,
                            'synthesized_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
                        ],
                    ] );
                }
            } catch ( \Throwable $e ) {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( 'Generation record TTS update failed: ' . $e->getMessage() );
                }
            }
        }

        // Issue #79 — persist audio-stats meta on the podcast post so the
        // Briefing Hub stage card can later surface playback duration, file size,
        // sample rate, format, voice personas, engine, mode, and topic count.
        // Skipped silently if update_post_meta is unavailable (e.g. unit
        // tests that mock only sideload/create). All values come from
        // Settings-First helpers (PressHub_AI_Settings_Storage::get_*) so
        // operators can re-tune without code changes.
        if ( function_exists( 'update_post_meta' ) && $post_id > 0 ) {
            $audio_bytes    = strlen( (string) $stitched_audio );
            $audio_sample   = 24000; // Gemini TTS default — see synthesize_speech_via_gemini / stitch_wav_chunks
            // PCM = 16-bit signed, mono, so 2 bytes per sample; duration in
            // seconds for raw PCM (24 kHz mono). MP3 fallback uses an
            // estimated 32 kbps ≈ 4000 bytes/sec when bytesize mode heuristic
            // is needed. For WAV filesize, header is 44 bytes.
            $is_wav   = ( strlen( $stitched_audio ) >= 4 && 'RIFF' === substr( $stitched_audio, 0, 4 ) );
            $is_mp3   = ( strlen( $stitched_audio ) >= 3 && 'ID3' === substr( $stitched_audio, 0, 3 ) )
                     || ( strlen( $stitched_audio ) >= 2 && "\xFF\xFB" === substr( $stitched_audio, 0, 2 ) );
            $format   = $is_wav ? 'wav' : ( $is_mp3 ? 'mp3' : ( $ext ? ltrim( $ext, '.' ) : 'wav' ) );
            if ( $format === 'wav' ) {
                $pcm_seconds    = max( 0.0, ( $audio_bytes - 44 ) / ( $audio_sample * 2 ) );
                $duration_sec   = $pcm_seconds;
            } else {
                $duration_sec   = max( 0.0, $audio_bytes / 4000.0 );
            }

            $host_count_value = (int) $host_count;
            $engine_value     = (string) $engine;
            $split_by_topic_v = $split_by_topic ? 1 : 0;
            $topic_count_v    = ! empty( $topics ) ? count( $topics ) : 0;
            $completed_at_v   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

            update_post_meta( (int) $post_id, '_presshub_audio_duration_sec', round( (float) $duration_sec, 2 ) );
            update_post_meta( (int) $post_id, '_presshub_audio_filesize', (int) $audio_bytes );
            update_post_meta( (int) $post_id, '_presshub_audio_sample_rate', (int) $audio_sample );
            update_post_meta( (int) $post_id, '_presshub_audio_format', (string) $format );
            update_post_meta( (int) $post_id, '_presshub_audio_engine', $engine_value );
            update_post_meta( (int) $post_id, '_presshub_audio_female_voice', (string) $saved_p1 );
            update_post_meta( (int) $post_id, '_presshub_audio_male_voice', (string) $saved_p2 );
            update_post_meta( (int) $post_id, '_presshub_audio_tertiary_voice', (string) $saved_p3 );
            update_post_meta( (int) $post_id, '_presshub_audio_host_count', (int) $host_count_value );
            update_post_meta( (int) $post_id, '_presshub_audio_split_by_topic', (int) $split_by_topic_v );
            update_post_meta( (int) $post_id, '_presshub_audio_topic_count', (int) $topic_count_v );
            update_post_meta( (int) $post_id, '_presshub_audio_attempted_at', (string) $podcast_audio_attempted_at );
            update_post_meta( (int) $post_id, '_presshub_audio_completed_at', (string) $completed_at_v );
        }

        // A WAV episode is converted to MP3 by the worker (32 MB → about 5 MB for 11 minutes),
        // queued once all of the WAV's meta is saved.
        if ( class_exists( 'PressHub_AI_Podcast_Mp3' ) && (int) $post_id > 0 && '.wav' === $ext ) {
            PressHub_AI_Podcast_Mp3::queue( (int) $post_id, $attachment_id, $audio_url );
        }

        $podcast_audio_completed_at = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

        return [
            'success'         => true,
            'post_id'         => $post_id,
            'attachment_id'   => $attachment_id,
            'audio_url'       => $audio_url,
            'date'            => $date,
            'edition'         => $edition,
            'turns_count'     => count( $turns ),
            'audio_size'      => strlen( $stitched_audio ),
            'audio_data'      => $stitched_audio,
            // Issue #79 — surface audio stats in the result payload so the AJAX
            // handler can echo them into the response without re-querying.
            'engine'          => (string) $engine,
            'female_voice'    => (string) $saved_p1,
            'male_voice'      => (string) $saved_p2,
            'tertiary_voice'  => (string) $saved_p3,
            'host_count'      => (int) $host_count,
            'sample_rate'     => 24000,
            'format'          => (string) ( $is_wav ? 'wav' : ( $is_mp3 ? 'mp3' : ltrim( $ext, '.' ) ) ),
            'attempted_at'    => (string) $podcast_audio_attempted_at,
            'completed_at'    => (string) $podcast_audio_completed_at,
        ];
    }

    private function get_sfx_pcm( string $sfx_setting ): string {
        if ( empty( $sfx_setting ) || 'silence' === $sfx_setting ) {
            return '';
        }

        $sfx_file = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/audio/' . basename( $sfx_setting );
        if ( ! file_exists( $sfx_file ) ) {
            return '';
        }

        $is_mp3 = ( strtolower( pathinfo( $sfx_file, PATHINFO_EXTENSION ) ) === 'mp3' );
        $needs_ffmpeg = $is_mp3;

        if ( ! $needs_ffmpeg ) {
            $header = file_get_contents( $sfx_file, false, null, 0, 44 );
            if ( strlen( $header ) >= 44 && 'RIFF' === substr( $header, 0, 4 ) ) {
                $fmt_chunk = substr( $header, 12, 4 );
                if ( 'fmt ' === $fmt_chunk ) {
                    $channels = unpack( 'v', substr( $header, 22, 2 ) )[1] ?? 0;
                    $sample_rate = unpack( 'V', substr( $header, 24, 4 ) )[1] ?? 0;
                    if ( 1 !== $channels || 24000 !== $sample_rate ) {
                        $needs_ffmpeg = true;
                    }
                }
            }
        }

        $sfx_wav = '';

        if ( $needs_ffmpeg ) {
            $tmp_wav = wp_temp_dir() . '/sfx_tmp_' . uniqid() . '.wav';
            $cmd = 'ffmpeg -i ' . escapeshellarg( $sfx_file ) . ' -ar 24000 -ac 1 -c:a pcm_s16le -f wav -y ' . escapeshellarg( $tmp_wav ) . ' 2>&1';
            @shell_exec( $cmd );
            if ( file_exists( $tmp_wav ) ) {
                $sfx_wav = file_get_contents( $tmp_wav );
                @unlink( $tmp_wav );
            } else {
                if ( class_exists( 'PressHub_AI_Logger' ) ) {
                    PressHub_AI_Logger::warning( 'PressHub AI: Failed to decode/resample SFX using ffmpeg.', [ 'sfx_file' => basename( $sfx_file ) ] );
                }
            }
        } else {
            $sfx_wav = file_get_contents( $sfx_file );
        }

        if ( ! empty( $sfx_wav ) && strlen( $sfx_wav ) >= 44 && 'RIFF' === substr( $sfx_wav, 0, 4 ) ) {
            $offset = 12;
            $len = strlen( $sfx_wav );
            while ( $offset + 8 <= $len ) {
                $chunk_id = substr( $sfx_wav, $offset, 4 );
                $chunk_size = unpack( 'V', substr( $sfx_wav, $offset + 4, 4 ) )[1] ?? 0;
                
                if ( 'data' === $chunk_id ) {
                    return substr( $sfx_wav, $offset + 8, $chunk_size > 0 ? $chunk_size : null );
                }
                
                $offset += 8 + $chunk_size;
            }
            return substr( $sfx_wav, 44 );
        }

        return '';
    }

    private function wrap_pcm_in_wav( string $pcm_data, int $sample_rate = 24000 ): string {
        if ( empty( $pcm_data ) ) {
            return '';
        }

        $data_len = strlen( $pcm_data );
        $channels = 1;
        $bits_per_sample = 16;
        $byte_rate = $sample_rate * $channels * ( $bits_per_sample / 8 );
        $block_align = $channels * ( $bits_per_sample / 8 );
        $chunk_size = 36 + $data_len;

        $header = pack(
            'A4VA4A4VvvVVvvA4V',
            'RIFF',
            $chunk_size,
            'WAVE',
            'fmt ',
            16,
            1,
            $channels,
            $sample_rate,
            $byte_rate,
            $block_align,
            $bits_per_sample,
            'data',
            $data_len
        );

        return $header . $pcm_data;
    }
}
