<?php
/**
 * English and Turkish versions of the Daily Briefing podcast, for YouTube.
 *
 * After the Greek podcast of an edition is voiced, each language turned on under
 * Settings → Daily Briefing → Other languages goes through two cron steps, one language
 * after the other so they never compete for the TTS rate limit:
 *
 * 1. translate:<lang>  The Greek script (as voiced) is translated by the podcast-script
 *    model in one call (action `podcast_translation`, so no agenda or fiches are added:
 *    the translation must say what the Greek says). The answer carries the episode
 *    title, a YouTube description and the script, with the speaker and topic markers of
 *    the original. A translation that lost a topic, turns, text or a presenter is refused
 *    (structure_error()) and asked for once more.
 * 2. voice:<lang>      The translated script is voiced with the podcast's voice settings
 *    (PressHub_AI_Audio_Synthesizer::synthesize_script_audio()), with the delivery style
 *    in that language and the language's own voices where they are set. The audio goes
 *    to the Media Library, attached to the Greek podcast post, with its caption timeline.
 *    A long translation resumes from the turn cache in continuation steps.
 *
 * Nothing is published on the site: everything is kept on the Greek podcast post
 * (META_KEY). Once the Greek post is published and its language is voiced, a YouTube
 * episode job for that language is queued (maybe_enqueue()). It carries the language in
 * its `youtube_episode` metadata, and PressHub_Social_YouTube::attach_upload() gives it
 * the translated audio, title, description, captions and the language's playlist.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Podcast_Translations {

    /**
     * Languages the podcast can be made in. `name` is the language's English name (used
     * in prompts and TTS style instructions), `label` what the settings show.
     */
    const LANGUAGES = [
        'en' => [
            'name'     => 'English',
            'label'    => 'English',
            'example'  => 'PressHub Morning Briefing: …',
            'example_noon' => 'PressHub Midday Briefing: …',
            'end_card' => 'Full stories and sources at %s',
        ],
        'tr' => [
            'name'     => 'Turkish',
            'label'    => 'Türkçe (Turkish)',
            'example'  => 'PressHub Sabah Bülteni: …',
            'example_noon' => 'PressHub Öğle Bülteni: …',
            'end_card' => 'Haberlerin tamamı ve kaynakları: %s',
        ],
    ];

    /** Post meta on the Greek podcast post: [ lang => entry ]. */
    const META_KEY = '_presshub_podcast_translations';

    /** API client action of the translation call (no agenda, people or policy fiches). */
    const ACTION = 'podcast_translation';

    /** Fewest turns a translated topic may have, as a share of the Greek topic's turns. */
    const MIN_TURN_SHARE = 0.8;

    /** Shortest translated topic, as a share of the Greek topic's characters. */
    const MIN_LENGTH_SHARE = 0.4;

    /** Shortest translated script, as a share of the Greek script's characters. */
    const MIN_TOTAL_LENGTH_SHARE = 0.55;

    /** Low temperature: a translation, not new writing. */
    const TEMPERATURE = 0.2;

    /** Calls per translation: the first, and one more told why the first was refused. */
    const ATTEMPTS = 2;

    /** Built-in translator's notes per language (Settings → Daily Briefing → Other languages). */
    public static function default_notes(): array {
        return [
            'en' => "Place names: Nicosia, Limassol, Larnaca, Paphos, Famagusta, Kyrenia, Morphou, Ayia Napa, Paralimni, Troodos.\n"
                . "Institutions: House of Representatives (Βουλή), Presidential Palace, Council of Ministers, Central Bank of Cyprus, Electricity Authority of Cyprus (EAC), Cyprus Police, National Guard (Εθνική Φρουρά), Republic of Cyprus (Κυπριακή Δημοκρατία).\n"
                . "Keep the original's wording on the Cyprus problem: \"κατεχόμενα\" is \"the occupied areas\", \"ψευδοκράτος\" is \"the pseudo-state\", \"γραμμή κατάπαυσης του πυρός\" is \"the ceasefire line\", \"Πράσινη Γραμμή\" is \"the Green Line\", \"κατοχικός στρατός\" is \"the occupation army\", \"Τουρκοκύπριος ηγέτης\" is \"the Turkish Cypriot leader\", \"ΑΟΖ\" is \"the exclusive economic zone (EEZ)\".\n"
                . "Amounts stay in euros (\"two million euros\").",
            'tr' => "Yer adları (Kıbrıslı Türklerin kullandığı adlar): Lefkoşa, Limasol, Larnaka, Baf, Gazimağusa, Girne, Güzelyurt, Aya Napa, Paralimni, Trodos.\n"
                . "Kurumlar: Temsilciler Meclisi (Βουλή), Cumhurbaşkanlığı Sarayı, Bakanlar Kurulu, Kıbrıs Merkez Bankası, Kıbrıs Elektrik Kurumu (EAC), Kıbrıs Polisi, Ulusal Muhafız (Εθνική Φρουρά), Kıbrıs Cumhuriyeti (Κυπριακή Δημοκρατία).\n"
                . "Kıbrıs sorunuyla ilgili ifadelerde orijinalin dilini koru: \"κατεχόμενα\" = \"işgal altındaki bölgeler\", \"ψευδοκράτος\" = \"sözde devlet\" (asla \"KKTC\" veya \"Kuzey Kıbrıs\" değil), \"γραμμή κατάπαυσης του πυρός\" = \"ateşkes hattı\", \"Πράσινη Γραμμή\" = \"Yeşil Hat\", \"κατοχικός στρατός\" = \"işgal ordusu\", \"Τουρκοκύπριος ηγέτης\" = \"Kıbrıslı Türk lider\", \"ΑΟΖ\" = \"Münhasır Ekonomik Bölge (MEB)\".\n"
                . "Tutarlar euro olarak kalır (\"iki milyon euro\").",
        ];
    }

    /** Languages turned on in the settings, in LANGUAGES order. */
    public static function enabled_languages(): array {
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            return PressHub_AI_Settings_Storage::get_briefing_podcast_languages();
        }
        $stored = function_exists( 'get_option' ) ? get_option( 'presshub_ai_briefing_podcast_languages', [] ) : [];
        return array_values( array_intersect( array_keys( self::LANGUAGES ), array_map( 'strval', (array) $stored ) ) );
    }

    public static function is_language( string $lang ): bool {
        return isset( self::LANGUAGES[ $lang ] );
    }

    // ------------------------------------------------------------------
    // Stored entries.
    // ------------------------------------------------------------------

    /** Every language's entry of a podcast post. */
    public static function get_all( int $post_id ): array {
        $all = $post_id > 0 && function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, self::META_KEY, true ) : [];
        return is_array( $all ) ? $all : [];
    }

    /** One language's entry, or [] when it was never made. */
    public static function get_entry( int $post_id, string $lang ): array {
        $all = self::get_all( $post_id );
        return is_array( $all[ $lang ] ?? null ) ? $all[ $lang ] : [];
    }

    /** Replaces the given fields of a language's entry. */
    private static function update_entry( int $post_id, string $lang, array $fields ): array {
        $all          = self::get_all( $post_id );
        $entry        = array_merge( is_array( $all[ $lang ] ?? null ) ? $all[ $lang ] : [], $fields );
        $all[ $lang ] = $entry;
        if ( function_exists( 'update_post_meta' ) ) {
            update_post_meta( $post_id, self::META_KEY, $all );
        }
        return $entry;
    }

    /** A Greek podcast post deleted for good takes its English and Turkish audio files with it. */
    public static function on_before_delete_post( $post_id ): void {
        $post_id = (int) $post_id;
        foreach ( self::get_all( $post_id ) as $entry ) {
            $attachment = (int) ( is_array( $entry ) ? ( $entry['attachment_id'] ?? 0 ) : 0 );
            if ( $attachment > 0 && function_exists( 'wp_delete_attachment' ) ) {
                wp_delete_attachment( $attachment, true );
            }
        }
    }

    private static function fail( int $post_id, string $lang, string $step, WP_Error $error ): WP_Error {
        self::update_entry( $post_id, $lang, [
            'status'    => 'failed',
            'step'      => $step,
            'error'     => $error->get_error_message(),
            'failed_at' => self::now(),
        ] );
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::error( sprintf( 'Podcast %s %s failed for post #%d: %s', self::LANGUAGES[ $lang ]['name'] ?? $lang, $step, $post_id, $error->get_error_message() ), [
                'post_id'    => $post_id,
                'language'   => $lang,
                'step'       => $step,
                'error_code' => $error->get_error_code(),
            ] );
        }
        return $error;
    }

    private static function now(): string {
        return function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    // ------------------------------------------------------------------
    // Pipeline.
    // ------------------------------------------------------------------

    /**
     * Starts the languages of an edition once its Greek podcast is voiced: queues the
     * first language's translation (or runs the chain in this request when cron steps
     * cannot be queued). Does nothing when no language is turned on.
     */
    public static function start( string $date, string $edition, int $post_id ): void {
        $langs = self::enabled_languages();
        if ( empty( $langs ) || $post_id <= 0 ) {
            return;
        }
        self::queue( $date, $edition, 'translate:' . $langs[0] );
    }

    /**
     * Queues a step as its own cron event. Without cron steps (turned off) it runs now,
     * without a time budget. When the same step is already waiting (WordPress refuses an
     * identical event within ten minutes) nothing more is done; any other refusal runs it
     * now within the time budget, so a long voice step stops instead of timing out.
     */
    private static function queue( string $date, string $edition, string $step, int $part = 0 ) {
        $split = function_exists( 'presshub_ai_briefing_split_steps' ) && presshub_ai_briefing_split_steps();
        if ( $split && function_exists( 'presshub_ai_schedule_briefing_step' ) && presshub_ai_schedule_briefing_step( $date, $edition, $step, $part ) ) {
            return 'scheduled';
        }
        if ( $split ) {
            if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( 'presshub_ai_run_briefing_step', [ (string) $date, (string) $edition, (string) $step, (int) $part ] ) ) {
                return 'scheduled';
            }
            return self::run_step( $date, $edition, $step, $part, true );
        }
        return self::run_step( $date, $edition, $step, $part, false );
    }

    /** The language after $lang among the enabled ones, or ''. */
    public static function next_language( string $lang ): string {
        $langs = self::enabled_languages();
        $i     = array_search( $lang, $langs, true );
        return ( false !== $i && isset( $langs[ $i + 1 ] ) ) ? $langs[ $i + 1 ] : '';
    }

    /**
     * Runs one step (`translate:<lang>` or `voice:<lang>`) for the edition's podcast post
     * and queues the next one. A failed language is recorded on the post and the next
     * language still runs.
     *
     * @return array|WP_Error|string|null Step result, 'continued' for a voice step that
     *         resumes in a continuation, null when skipped.
     */
    public static function run_step( string $date, string $edition, string $step, int $part = 0, bool $budgeted = true ) {
        [ $kind, $lang ] = array_pad( explode( ':', $step, 2 ), 2, '' );
        if ( ! self::is_language( $lang ) || ! in_array( $kind, [ 'translate', 'voice' ], true ) ) {
            return null;
        }
        $post_id = function_exists( 'presshub_ai_find_podcast_post_id' ) ? presshub_ai_find_podcast_post_id( $date, $edition ) : 0;
        if ( $post_id <= 0 ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( sprintf( 'Podcast %s skipped: there is no Greek podcast post for %s (%s edition).', $step, $date, $edition ) );
            }
            return null;
        }

        if ( 'translate' === $kind ) {
            $result = self::translate( $post_id, $lang );
            if ( ! is_wp_error( $result ) ) {
                if ( empty( $result['unchanged'] ) || 'voiced' !== ( $result['status'] ?? '' ) || '' === (string) ( $result['audio_url'] ?? '' ) ) {
                    self::queue( $date, $edition, 'voice:' . $lang );
                    return $result;
                }
                // Already voiced from the same Greek script.
                self::maybe_enqueue( $post_id, $lang );
            }
        } else {
            $budget = ( $budgeted && function_exists( 'presshub_ai_briefing_step_time_budget_sec' ) ) ? presshub_ai_briefing_step_time_budget_sec() : 0;
            $result = self::voice( $post_id, $lang, null, $budget );
            if ( is_wp_error( $result ) && 'synthesis_time_budget' === $result->get_error_code() ) {
                $max = function_exists( 'presshub_ai_briefing_step_max_continuations' ) ? presshub_ai_briefing_step_max_continuations() : 6;
                if ( $part < $max && function_exists( 'presshub_ai_schedule_briefing_step' ) && presshub_ai_schedule_briefing_step( $date, $edition, 'voice:' . $lang, $part + 1 ) ) {
                    return 'continued';
                }
                self::fail( $post_id, $lang, 'voice', new WP_Error( 'synthesis_time_budget', __( 'Voicing ran out of continuation steps.', 'presshub-ai-editor' ) ) );
            } elseif ( ! is_wp_error( $result ) ) {
                self::maybe_enqueue( $post_id, $lang );
            }
        }

        $next = self::next_language( $lang );
        if ( '' !== $next ) {
            self::queue( $date, $edition, 'translate:' . $next );
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Translation.
    // ------------------------------------------------------------------

    /** Briefing date and edition of a podcast post. */
    private static function post_edition( int $post_id ): array {
        $date    = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        $edition = 'noon' === (string) get_post_meta( $post_id, '_presshub_briefing_edition', true ) ? 'noon' : 'morning';
        return [ $date, $edition ];
    }

    /** The Greek script the podcast post was voiced from, or ''. */
    public static function greek_script( int $post_id ): string {
        [ $date, $edition ] = self::post_edition( $post_id );
        if ( '' === $date || ! class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            return '';
        }
        return trim( (string) ( new PressHub_AI_Podcast_Producer() )->get_script( $date, $edition ) );
    }

    /**
     * The script in the form the translation is asked to keep: every topic as
     * [TOPIC_START: title] ... [TOPIC_END] and every turn as [SPEAKER_N]: text, parsed
     * the way the audio was made. Presenter names in the labels never reach the
     * translator, so it cannot translate or spell them differently.
     */
    public static function canonical_script( string $script ): string {
        if ( '' === trim( $script ) || ! class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            return trim( $script );
        }
        $hosts  = self::host_names();
        $topics = ( new PressHub_AI_Podcast_Producer() )->parse_script_topics( $script, $hosts[0], $hosts[1], $hosts[2] );
        $index  = [ 'female' => 1, 'male' => 2, 'tertiary' => 3 ];
        $blocks = [];
        foreach ( $topics as $topic ) {
            $lines = [ '[TOPIC_START: ' . trim( str_replace( [ '[', ']' ], '', (string) ( $topic['title'] ?? '' ) ) ) . ']' ];
            foreach ( (array) ( $topic['turns'] ?? [] ) as $turn ) {
                $text = trim( preg_replace( '/\s+/u', ' ', (string) ( $turn['text'] ?? '' ) ) );
                if ( '' !== $text ) {
                    $lines[] = sprintf( '[SPEAKER_%d]: %s', $index[ (string) ( $turn['speaker'] ?? '' ) ] ?? 1, $text );
                }
            }
            $lines[]  = '[TOPIC_END]';
            $blocks[] = implode( "\n", $lines );
        }
        return empty( $blocks ) ? trim( $script ) : implode( "\n\n", $blocks );
    }

    /** System prompt of the translation into $lang. */
    public static function build_system_prompt( string $lang, string $edition = 'morning' ): string {
        $template = class_exists( 'PressHub_AI_Prompt_Loader' ) ? PressHub_AI_Prompt_Loader::load( 'podcast/translate_script.txt' ) : '';
        $notes    = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_language_notes( $lang ) : '';
        return strtr( $template, [
            '{language}'      => self::LANGUAGES[ $lang ]['name'],
            '{notes}'         => '' !== trim( $notes ) ? $notes : '-',
            '{example_title}' => self::example_title( $lang, $edition ),
        ] );
    }

    /** Example episode title of an edition in $lang. */
    public static function example_title( string $lang, string $edition = 'morning' ): string {
        $info = self::LANGUAGES[ $lang ] ?? [];
        return (string) ( 'noon' === $edition ? ( $info['example_noon'] ?? $info['example'] ?? '' ) : ( $info['example'] ?? '' ) );
    }

    /**
     * Translates the Greek podcast script of a post into $lang and stores the result.
     *
     * @return array|WP_Error The stored entry.
     */
    public static function translate( int $post_id, string $lang, ?PressHub_AI_API_Client $api_client = null ) {
        if ( ! self::is_language( $lang ) ) {
            return new WP_Error( 'unknown_language', sprintf( 'Unknown podcast language: %s', $lang ) );
        }
        $source = self::greek_script( $post_id );
        if ( '' === $source ) {
            return self::fail( $post_id, $lang, 'translate', new WP_Error( 'no_script', __( 'The Greek podcast script of this post was not found.', 'presshub-ai-editor' ) ) );
        }
        $previous = self::get_entry( $post_id, $lang );
        if ( md5( $source ) === ( $previous['source_hash'] ?? '' )
            && in_array( $previous['status'] ?? '', [ 'translated', 'voiced' ], true )
            && '' !== trim( (string) ( $previous['script'] ?? '' ) )
        ) {
            // The Greek script did not change (a retry or re-run of the audio): keep it.
            return array_merge( $previous, [ 'unchanged' => true ] );
        }
        $greek              = self::canonical_script( $source );
        [ $date, $edition ] = self::post_edition( $post_id );
        $post  = get_post( $post_id );
        $title = $post ? (string) ( is_object( $post ) ? $post->post_title : ( $post['post_title'] ?? '' ) ) : '';

        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'briefing_podcast' );
        }
        if ( method_exists( $api_client, 'set_action' ) ) {
            $api_client->set_action( self::ACTION );
        }
        $system = self::build_system_prompt( $lang, $edition );
        $user   = sprintf(
            "Greek episode title: %s\nEdition: %s, %s\n\nGreek script:\n%s",
            $title,
            'noon' === $edition ? 'midday' : 'morning',
            $date,
            $greek
        );

        $error = null;
        for ( $attempt = 1; $attempt <= self::ATTEMPTS; $attempt++ ) {
            $prompt = null === $error ? $user : $user . "\n\nYour previous translation was refused: " . $error->get_error_message() . ' Translate the whole script again, keeping every topic, every turn and every marker exactly as in the Greek script.';
            $started  = microtime( true );
            $response = $api_client->call_provider( $system, $prompt, false, [], self::TEMPERATURE, [ 'edition' => $edition, 'language' => $lang, 'attempt' => $attempt ] );
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info( sprintf( 'Podcast translation into %s for post #%d (attempt %d) took %d ms.', self::LANGUAGES[ $lang ]['name'], $post_id, $attempt, (int) round( ( microtime( true ) - $started ) * 1000 ) ), [
                    'post_id'     => $post_id,
                    'language'    => $lang,
                    'attempt'     => $attempt,
                    'provider'    => method_exists( $api_client, 'get_provider' ) ? $api_client->get_provider() : '',
                    'model'       => method_exists( $api_client, 'get_model' ) ? $api_client->get_model() : '',
                    'action'      => self::ACTION,
                    'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
                ] );
            }
            if ( is_wp_error( $response ) ) {
                // A failed call (quota, network) is not retried here: the provider client retries.
                return self::fail( $post_id, $lang, 'translate', $response );
            }
            $parsed = self::parse_response( (string) $response );
            if ( is_wp_error( $parsed ) ) {
                $error = $parsed;
                continue;
            }
            $problem = self::structure_error( $greek, $parsed['script'] );
            if ( '' !== $problem ) {
                $error = new WP_Error( 'translation_structure', $problem );
                continue;
            }
            $error = null;
            break;
        }
        if ( null !== $error ) {
            return self::fail( $post_id, $lang, 'translate', $error );
        }

        return self::update_entry( $post_id, $lang, [
            'status'        => 'translated',
            'step'          => 'translate',
            'error'         => '',
            'title'         => $parsed['title'],
            'description'   => $parsed['description'],
            'script'        => $parsed['script'],
            'source_hash'   => md5( $source ),
            'translated_at' => self::now(),
        ] );
    }

    /**
     * Splits the model's answer into title, description and script.
     *
     * @return array{title:string,description:string,script:string}|WP_Error
     */
    public static function parse_response( string $response ): array|WP_Error {
        $text = trim( str_replace( "\r\n", "\n", $response ) );
        // A fenced answer: drop the fences.
        $text = (string) preg_replace( '/^```[a-z]*\n|\n```$/i', '', $text );
        if ( ! preg_match( '/^\s*\**TITLE\**\s*:\s*(.+?)\s*$/mi', $text, $t )
            || ! preg_match( '/^\s*\**DESCRIPTION\**\s*:\s*(.*?)^\s*\**SCRIPT\**\s*:\s*(.*)\z/msi', $text, $m )
        ) {
            return new WP_Error( 'translation_format', __( 'The translation did not come back as TITLE, DESCRIPTION and SCRIPT.', 'presshub-ai-editor' ) );
        }
        $title  = trim( wp_strip_all_tags( $t[1] ), " \t\"'*" );
        $script = trim( $m[2] );
        if ( '' === $title || '' === $script ) {
            return new WP_Error( 'translation_format', __( 'The translation has no title or no script.', 'presshub-ai-editor' ) );
        }
        return [
            'title'       => mb_substr( $title, 0, 100 ),
            'description' => trim( wp_strip_all_tags( $m[1] ) ),
            'script'      => $script,
        ];
    }

    /**
     * Why a translated script does not match the Greek one, or '' when it does. Both are
     * split into topics the way the audio is made. The translation must have the same
     * topics in the same order, each with at least MIN_TURN_SHARE of the Greek topic's
     * turns and MIN_LENGTH_SHARE of its text, the same presenters, and at least
     * MIN_TOTAL_LENGTH_SHARE of the whole script's text (a summary is refused).
     */
    public static function structure_error( string $greek, string $translated ): string {
        $producer = new PressHub_AI_Podcast_Producer();
        $hosts    = self::host_names();
        $a        = $producer->parse_script_topics( $greek, $hosts[0], $hosts[1], $hosts[2] );
        $b        = $producer->parse_script_topics( $translated, $hosts[0], $hosts[1], $hosts[2] );
        if ( empty( $b ) ) {
            return __( 'The translated script has no speaker turns.', 'presshub-ai-editor' );
        }
        $raw_a = preg_match_all( '/\[TOPIC_START\s*:/u', $greek );
        $raw_b = preg_match_all( '/\[TOPIC_START\s*:/u', $translated );
        if ( count( $a ) !== count( $b ) || $raw_a !== $raw_b ) {
            return sprintf( __( 'The translated script has %1$d topics; the Greek one has %2$d.', 'presshub-ai-editor' ), max( count( $b ), $raw_b ), max( count( $a ), $raw_a ) );
        }
        $len       = static fn( array $turns ): int => array_sum( array_map( static fn( $t ) => mb_strlen( trim( (string) ( $t['text'] ?? '' ) ) ), $turns ) );
        $total_a   = 0;
        $total_b   = 0;
        $speakers_a = [];
        $speakers_b = [];
        foreach ( $a as $i => $topic_a ) {
            $turns_a = (array) ( $topic_a['turns'] ?? [] );
            $turns_b = (array) ( $b[ $i ]['turns'] ?? [] );
            $n       = $i + 1;
            if ( count( $turns_b ) < max( 1, (int) ceil( count( $turns_a ) * self::MIN_TURN_SHARE - 1e-9 ) ) ) {
                return sprintf( __( 'Topic %1$d of the translated script has %2$d turns; the Greek one has %3$d.', 'presshub-ai-editor' ), $n, count( $turns_b ), count( $turns_a ) );
            }
            $la = $len( $turns_a );
            $lb = $len( $turns_b );
            if ( $la > 0 && $lb < $la * self::MIN_LENGTH_SHARE ) {
                return sprintf( __( 'Topic %1$d of the translated script is much shorter than the Greek one (%2$d characters against %3$d): it looks summarised.', 'presshub-ai-editor' ), $n, $lb, $la );
            }
            $total_a += $la;
            $total_b += $lb;
            foreach ( $turns_a as $t ) {
                $speakers_a[ (string) ( $t['speaker'] ?? '' ) ] = true;
            }
            foreach ( $turns_b as $t ) {
                $speakers_b[ (string) ( $t['speaker'] ?? '' ) ] = true;
            }
        }
        if ( $total_a > 0 && $total_b < $total_a * self::MIN_TOTAL_LENGTH_SHARE ) {
            return sprintf( __( 'The translated script is much shorter than the Greek one (%1$d characters against %2$d): it looks summarised.', 'presshub-ai-editor' ), $total_b, $total_a );
        }
        $extra   = array_diff( array_keys( $speakers_b ), array_keys( $speakers_a ) );
        $missing = array_diff( array_keys( $speakers_a ), array_keys( $speakers_b ) );
        if ( ! empty( $extra ) ) {
            return sprintf( __( 'The translated script has speakers the Greek one does not have: %s.', 'presshub-ai-editor' ), implode( ', ', $extra ) );
        }
        if ( ! empty( $missing ) ) {
            return sprintf( __( 'The translated script lost speakers of the Greek one: %s.', 'presshub-ai-editor' ), implode( ', ', $missing ) );
        }
        return '';
    }

    /** Presenter names as parse_script_turns() takes them. */
    private static function host_names(): array {
        $s = class_exists( 'PressHub_AI_Settings_Storage' );
        $n = [
            $s ? PressHub_AI_Settings_Storage::get_briefing_host_female() : '',
            $s ? PressHub_AI_Settings_Storage::get_briefing_host_male() : '',
            $s ? PressHub_AI_Settings_Storage::get_briefing_host_tertiary() : '',
        ];
        $fallback = [ 'Presenter 1', 'Presenter 2', 'Presenter 3' ];
        foreach ( $n as $i => $name ) {
            if ( '' === trim( (string) $name ) ) {
                $n[ $i ] = $fallback[ $i ];
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Voicing.
    // ------------------------------------------------------------------

    /**
     * Voices a language's translated script and stores the audio and its timeline.
     *
     * @param int $budget_sec Seconds of new TTS calls before a continuation (0 = none).
     * @return array|WP_Error The stored entry; `synthesis_time_budget` when it must resume.
     */
    public static function voice( int $post_id, string $lang, ?PressHub_AI_Audio_Synthesizer $synth = null, int $budget_sec = 0 ) {
        $entry = self::get_entry( $post_id, $lang );
        if ( ! self::is_language( $lang ) || '' === trim( (string) ( $entry['script'] ?? '' ) ) ) {
            return new WP_Error( 'no_translation', __( 'This language has no translated script to voice yet.', 'presshub-ai-editor' ) );
        }
        $voices = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_language_voices( $lang ) : [];
        $engine = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_engine() : 'gemini';
        if ( 'google_cloud' === $engine ) {
            // Google Cloud voices speak one language: every presenter needs a voice of this
            // language, or its turns would be read by a Greek voice.
            $hosts = class_exists( 'PressHub_AI_Settings_Storage' ) ? max( 1, min( 3, PressHub_AI_Settings_Storage::get_briefing_host_count() ) ) : 2;
            for ( $p = 1; $p <= $hosts; $p++ ) {
                $voice = (string) ( $voices[ 'presenter_' . $p ] ?? '' );
                if ( '' === $voice || 0 !== stripos( $voice, $lang . '-' ) ) {
                    return self::fail( $post_id, $lang, 'voice', new WP_Error( 'translation_voice_missing', sprintf( __( 'Google Cloud TTS needs a %1$s voice for presenter %2$d: choose it under Daily Briefing, Other languages.', 'presshub-ai-editor' ), self::LANGUAGES[ $lang ]['name'], $p ) ) );
                }
            }
        }
        [ $date, $edition ] = self::post_edition( $post_id );

        $synth = $synth ?? new PressHub_AI_Audio_Synthesizer();
        if ( $budget_sec > 0 ) {
            $synth->set_live_call_deadline( microtime( true ) + $budget_sec );
        }
        $audio = $synth->synthesize_script_audio( (string) $entry['script'], $date, $edition, self::LANGUAGES[ $lang ]['name'], $voices );
        if ( is_wp_error( $audio ) ) {
            if ( 'synthesis_time_budget' === $audio->get_error_code() ) {
                return $audio;
            }
            return self::fail( $post_id, $lang, 'voice', $audio );
        }

        $slug     = 'noon' === $edition ? '-noon' : '';
        $filename = sprintf( 'podcast-briefing%s-%s-%s-%s%s', $slug, $lang, $date, uniqid(), $audio['ext'] );
        $media    = $synth->sideload_audio_file( $filename, (string) $audio['audio'], $post_id, (string) ( $entry['title'] ?? '' ) );
        if ( is_wp_error( $media ) ) {
            return self::fail( $post_id, $lang, 'voice', $media );
        }

        $timeline = null;
        if ( class_exists( 'PressHub_AI_Podcast_Timeline' ) ) {
            try {
                $built    = PressHub_AI_Podcast_Timeline::build( (array) $audio['timeline_topics'], (array) $audio['timeline_opts'] );
                $timeline = PressHub_AI_Podcast_Timeline::is_valid( $built ) ? $built : null;
            } catch ( \Throwable $e ) {
                PressHub_AI_Logger::warning( 'Translated podcast timeline could not be built: ' . $e->getMessage(), [ 'post_id' => $post_id, 'language' => $lang ] );
            }
        }
        $bytes    = strlen( (string) $audio['audio'] );
        $duration = null !== $timeline
            ? (float) $timeline['duration']
            : ( '.wav' === $audio['ext'] ? max( 0.0, ( $bytes - 44 ) / 48000 ) : $bytes / 4000.0 );

        // A voice step run again replaces its earlier file.
        $old = (int) ( $entry['attachment_id'] ?? 0 );
        if ( $old > 0 && $old !== (int) $media['id'] && function_exists( 'wp_delete_attachment' ) ) {
            wp_delete_attachment( $old, true );
        }

        $saved = self::update_entry( $post_id, $lang, [
            'status'        => 'voiced',
            'step'          => 'voice',
            'error'         => '',
            'attachment_id' => (int) $media['id'],
            'audio_url'     => (string) $media['url'],
            'duration_sec'  => round( $duration, 2 ),
            'timeline'      => $timeline,
            'voices'        => (array) $audio['voices'],
            'voiced_at'     => self::now(),
        ] );
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( 'Podcast voiced in %s for post #%d (%d turns, %.0f s).', self::LANGUAGES[ $lang ]['name'], $post_id, (int) $audio['turns_count'], $duration ), [ 'post_id' => $post_id, 'language' => $lang ] );
        }
        return $saved;
    }

    // ------------------------------------------------------------------
    // YouTube.
    // ------------------------------------------------------------------

    /**
     * Queues a language's YouTube episode, once: when podcast episodes are on, the
     * language is on, the Greek post is published and the language is voiced. Returns
     * the job ID, or 0 when nothing was queued.
     */
    public static function maybe_enqueue( int $post_id, string $lang ): int {
        if ( ! class_exists( 'PressHub_Social_YouTube' ) || ! class_exists( 'PressHub_Social_Queue' ) || ! self::is_language( $lang ) ) {
            return 0;
        }
        if ( ! PressHub_Social_YouTube::wants_episode( $post_id ) || ! in_array( $lang, self::enabled_languages(), true ) || ! self::youtube_allowed( $post_id ) ) {
            return 0;
        }
        $post   = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        $status = $post ? (string) ( is_object( $post ) ? ( $post->post_status ?? '' ) : ( $post['post_status'] ?? '' ) ) : '';
        if ( 'publish' !== $status ) {
            // Held by the QA gate or not out yet: queued when the post is published.
            return 0;
        }
        $entry = self::get_entry( $post_id, $lang );
        if ( 'voiced' !== ( $entry['status'] ?? '' ) || '' === (string) ( $entry['audio_url'] ?? '' ) ) {
            return 0;
        }
        foreach ( PressHub_Social_Queue::get_jobs_for_post( $post_id ) as $job ) {
            if ( PressHub_Social_YouTube::is_episode_job( $job )
                && PressHub_Social_YouTube::episode_language( $job ) === $lang
                && ! in_array( (string) ( $job['status'] ?? '' ), [ 'failed', 'cancelled' ], true )
            ) {
                return 0;
            }
        }
        $copy = trim( (string) ( $entry['description'] ?? '' ) );
        if ( '' === $copy ) {
            $copy = (string) ( $entry['title'] ?? '' );
        }
        $job    = PressHub_Social_YouTube::episode_job( $post_id, $copy, null, null, gmdate( 'Y-m-d H:i:s' ), null, $lang );
        $job_id = PressHub_Social_Queue::enqueue( $job );
        if ( is_wp_error( $job_id ) ) {
            PressHub_Social_Logger::error( sprintf( 'Failed to enqueue the %s YouTube episode of post #%d: %s', self::LANGUAGES[ $lang ]['name'], $post_id, $job_id->get_error_message() ) );
            return 0;
        }
        PressHub_Social_Logger::info( sprintf( 'Enqueued %s YouTube podcast episode job #%d for post #%d.', self::LANGUAGES[ $lang ]['name'], (int) $job_id, $post_id ) );
        return (int) $job_id;
    }

    /**
     * Whether the post may go to YouTube at all, by the same rules as its Greek episode
     * (PressHub_Social_Dispatcher::enqueue_for_post()): YouTube ticked on the post when
     * the post picks its platforms, turned on under Where to post, and set up.
     */
    public static function youtube_allowed( int $post_id ): bool {
        $picked = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, '_presshub_social_platforms', true ) : [];
        if ( is_array( $picked ) && ! empty( $picked ) && ! in_array( 'youtube', array_map( 'strval', $picked ), true ) ) {
            return false;
        }
        if ( method_exists( 'PressHub_Social_Settings_Storage', 'is_platform_enabled' ) && ! PressHub_Social_Settings_Storage::is_platform_enabled( 'youtube' ) ) {
            return false;
        }
        if ( method_exists( 'PressHub_Social_Settings_Storage', 'is_platform_configured' ) && ! PressHub_Social_Settings_Storage::is_platform_configured( 'youtube' ) ) {
            return false;
        }
        return true;
    }

    /** Queues every voiced language of a post (when its Greek episode is queued). */
    public static function enqueue_ready( int $post_id ): array {
        $ids = [];
        foreach ( self::enabled_languages() as $lang ) {
            $id = self::maybe_enqueue( $post_id, $lang );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** The language's stored timeline, or null. */
    public static function timeline( int $post_id, string $lang ): ?array {
        $timeline = self::get_entry( $post_id, $lang )['timeline'] ?? null;
        return ( is_array( $timeline ) && class_exists( 'PressHub_AI_Podcast_Timeline' ) && PressHub_AI_Podcast_Timeline::is_valid( $timeline ) ) ? $timeline : null;
    }

    /** Length of a language's audio in seconds. */
    public static function duration( int $post_id, string $lang ): float {
        $timeline = self::timeline( $post_id, $lang );
        return null !== $timeline ? (float) $timeline['duration'] : (float) ( self::get_entry( $post_id, $lang )['duration_sec'] ?? 0 );
    }

    /**
     * The enhanced episode of a language (same shape as PressHub_Social_YouTube::
     * episode_video(), plus `title` and `end_card_line` in that language). Topic N of the
     * translation gets the images the Greek episode gives its topic N, since a
     * translation keeps the topics in order; the times are the translation's own.
     */
    public static function episode_video( int $post_id, string $lang ): ?array {
        // Enhanced episodes off: the plain video, which shows the upload's (translated) title.
        if ( ! class_exists( 'PressHub_Social_YouTube' ) || ! PressHub_Social_Settings_Storage::get_youtube_episode_enhanced() ) {
            return null;
        }
        $entry    = self::get_entry( $post_id, $lang );
        $timeline = self::timeline( $post_id, $lang );
        $groups   = PressHub_Social_YouTube::short_image_groups( $post_id );
        $images   = [];
        foreach ( $groups as $group ) {
            array_push( $images, ...$group['urls'] );
        }
        $host  = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
        $host  = '' !== $host ? preg_replace( '/^www\./i', '', $host ) : 'presshub.cy';
        $video = [
            'images'        => $images,
            'title'         => (string) ( $entry['title'] ?? '' ),
            'end_card_line' => sprintf( self::LANGUAGES[ $lang ]['end_card'] ?? '%s', $host ),
        ];
        if ( null === $timeline ) {
            return empty( $images ) ? null : $video;
        }

        $duration = (float) $timeline['duration'];
        $topics   = array_values( array_filter( (array) ( $timeline['topics'] ?? [] ), 'is_array' ) );
        $greek = class_exists( 'PressHub_AI_Podcast_Timeline' ) ? PressHub_AI_Podcast_Timeline::get( $post_id ) : null;
        if ( null !== $greek ) {
            $g_topics   = (array) ( $greek['topics'] ?? [] );
            $g_duration = (float) $greek['duration'];
        } else {
            // No Greek timeline: the Greek script's topic titles, on the translation's times,
            // still match the topics to the shorts in Greek.
            $hosts      = self::host_names();
            $parsed     = class_exists( 'PressHub_AI_Podcast_Producer' ) ? ( new PressHub_AI_Podcast_Producer() )->parse_script_topics( self::greek_script( $post_id ), $hosts[0], $hosts[1], $hosts[2] ) : [];
            $g_topics   = [];
            foreach ( array_values( $parsed ) as $i => $topic ) {
                $g_topics[] = [ 'title' => (string) ( $topic['title'] ?? '' ), 'start' => (float) ( $topics[ $i ]['start'] ?? 0 ) ];
            }
            $g_duration = $duration;
        }
        $g_scenes = ! empty( $g_topics ) ? PressHub_Social_YouTube::episode_scenes( $g_topics, $groups, $g_duration ) : [];
        if ( count( $g_scenes ) === count( $topics ) && ! empty( $topics ) ) {
            $scenes = [];
            foreach ( $topics as $i => $topic ) {
                $scenes[] = [
                    'start'  => 0 === $i ? 0.0 : (float) ( $topic['start'] ?? 0 ),
                    'end'    => $i + 1 < count( $topics ) ? (float) ( $topics[ $i + 1 ]['start'] ?? $duration ) : $duration,
                    'title'  => trim( (string) ( $topic['title'] ?? '' ) ),
                    'images' => array_values( (array) ( $g_scenes[ $i ]['images'] ?? $images ) ),
                ];
            }
        } else {
            // Only a translation made before the topic check could differ: its titles do not
            // match the Greek shorts, so the images are spread over it in order.
            if ( class_exists( 'PressHub_Social_Logger' ) ) {
                PressHub_Social_Logger::warning( sprintf( 'The %s episode of post #%d has %d topics and the Greek one %d: its images are not matched to topics.', self::LANGUAGES[ $lang ]['name'], $post_id, count( $topics ), count( $g_scenes ) ), [ 'post_id' => $post_id, 'language' => $lang ] );
            }
            $scenes = PressHub_Social_YouTube::episode_scenes( array_map( static fn( $t ) => array_merge( $t, [ 'title' => '' ] ), $topics ), $groups, $duration );
            foreach ( $scenes as $i => $scene ) {
                $scenes[ $i ]['title'] = trim( (string) ( $topics[ $i ]['title'] ?? $scene['title'] ?? '' ) );
            }
        }

        $video['duration']  = $duration;
        $video['estimated'] = ! empty( $timeline['estimated'] );
        $video['segments']  = array_values( array_map(
            static fn( $s ) => [
                'speaker' => 2 === (int) ( $s['speaker'] ?? 1 ) ? 2 : 1,
                'text'    => (string) ( $s['text'] ?? '' ),
                'start'   => (float) ( $s['start'] ?? 0 ),
                'end'     => (float) ( $s['end'] ?? 0 ),
            ],
            (array) $timeline['segments']
        ) );
        $video['scenes']    = $scenes;
        return $video;
    }
}
