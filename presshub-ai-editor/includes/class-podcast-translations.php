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
 *    the original. A translation that lost topics or most of its turns is refused.
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
            'end_card' => 'Full stories and sources at %s',
        ],
        'tr' => [
            'name'     => 'Turkish',
            'label'    => 'Türkçe (Turkish)',
            'example'  => 'PressHub Sabah Bülteni: …',
            'end_card' => 'Haberlerin tamamı ve kaynakları: %s',
        ],
    ];

    /** Post meta on the Greek podcast post: [ lang => entry ]. */
    const META_KEY = '_presshub_podcast_translations';

    /** API client action of the translation call (no agenda, people or policy fiches). */
    const ACTION = 'podcast_translation';

    /** Shortest translated script, as a share of the Greek one's turns, that is accepted. */
    const MIN_TURN_SHARE = 0.8;

    /** Built-in translator's notes per language (Settings → Daily Briefing → Other languages). */
    public static function default_notes(): array {
        return [
            'en' => "Place names: Nicosia, Limassol, Larnaca, Paphos, Famagusta, Kyrenia, Morphou, Ayia Napa, Paralimni, Troodos.\n"
                . "Institutions: House of Representatives (Βουλή), Presidential Palace, Council of Ministers, Central Bank of Cyprus, Electricity Authority of Cyprus (EAC), Cyprus Police.\n"
                . "Keep the original's wording on the Cyprus problem: \"κατεχόμενα\" is \"the occupied areas\", \"ψευδοκράτος\" is \"the pseudo-state\", \"γραμμή κατάπαυσης του πυρός\" is \"the ceasefire line\".\n"
                . "Amounts stay in euros (\"two million euros\").",
            'tr' => "Yer adları (Kıbrıslı Türklerin kullandığı adlar): Lefkoşa, Limasol, Larnaka, Baf, Gazimağusa, Girne, Güzelyurt, Aya Napa, Trodos.\n"
                . "Kurumlar: Temsilciler Meclisi (Βουλή), Cumhurbaşkanlığı Sarayı, Bakanlar Kurulu, Kıbrıs Merkez Bankası, Kıbrıs Elektrik Kurumu (EAC), Kıbrıs Polisi.\n"
                . "Kıbrıs sorunuyla ilgili ifadelerde orijinalin dilini koru: \"κατεχόμενα\" = \"işgal altındaki bölgeler\", \"ψευδοκράτος\" = \"sözde devlet\", \"γραμμή κατάπαυσης του πυρός\" = \"ateşkes hattı\".\n"
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

    /** Queues a step as its own cron event, or runs it now (without a time budget). */
    private static function queue( string $date, string $edition, string $step, int $part = 0 ) {
        if ( function_exists( 'presshub_ai_schedule_briefing_step' ) && presshub_ai_schedule_briefing_step( $date, $edition, $step, $part ) ) {
            return 'scheduled';
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
                self::queue( $date, $edition, 'voice:' . $lang );
                return $result;
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

    /** System prompt of the translation into $lang. */
    public static function build_system_prompt( string $lang ): string {
        $template = class_exists( 'PressHub_AI_Prompt_Loader' ) ? PressHub_AI_Prompt_Loader::load( 'podcast/translate_script.txt' ) : '';
        $notes    = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_podcast_language_notes( $lang ) : '';
        return strtr( $template, [
            '{language}'      => self::LANGUAGES[ $lang ]['name'],
            '{notes}'         => '' !== trim( $notes ) ? $notes : '-',
            '{example_title}' => self::LANGUAGES[ $lang ]['example'],
        ] );
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
        $greek = self::greek_script( $post_id );
        if ( '' === $greek ) {
            return self::fail( $post_id, $lang, 'translate', new WP_Error( 'no_script', __( 'The Greek podcast script of this post was not found.', 'presshub-ai-editor' ) ) );
        }
        [ $date, $edition ] = self::post_edition( $post_id );
        $post  = get_post( $post_id );
        $title = $post ? (string) ( is_object( $post ) ? $post->post_title : ( $post['post_title'] ?? '' ) ) : '';

        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'briefing_podcast' );
        }
        if ( method_exists( $api_client, 'set_action' ) ) {
            $api_client->set_action( self::ACTION );
        }
        $system = self::build_system_prompt( $lang );
        $user   = sprintf(
            "Greek episode title: %s\nEdition: %s, %s\n\nGreek script:\n%s",
            $title,
            'noon' === $edition ? 'midday' : 'morning',
            $date,
            $greek
        );

        $started  = microtime( true );
        $response = $api_client->call_provider( $system, $user, false, [], null, [ 'edition' => $edition, 'language' => $lang ] );
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( 'Podcast translation into %s for post #%d took %d ms.', self::LANGUAGES[ $lang ]['name'], $post_id, (int) round( ( microtime( true ) - $started ) * 1000 ) ), [
                'post_id'     => $post_id,
                'language'    => $lang,
                'provider'    => method_exists( $api_client, 'get_provider' ) ? $api_client->get_provider() : '',
                'model'       => method_exists( $api_client, 'get_model' ) ? $api_client->get_model() : '',
                'action'      => self::ACTION,
                'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
            ] );
        }
        if ( is_wp_error( $response ) ) {
            return self::fail( $post_id, $lang, 'translate', $response );
        }

        $parsed = self::parse_response( (string) $response );
        if ( is_wp_error( $parsed ) ) {
            return self::fail( $post_id, $lang, 'translate', $parsed );
        }
        $problem = self::structure_error( $greek, $parsed['script'] );
        if ( '' !== $problem ) {
            return self::fail( $post_id, $lang, 'translate', new WP_Error( 'translation_structure', $problem ) );
        }

        return self::update_entry( $post_id, $lang, [
            'status'        => 'translated',
            'step'          => 'translate',
            'error'         => '',
            'title'         => $parsed['title'],
            'description'   => $parsed['description'],
            'script'        => $parsed['script'],
            'source_hash'   => md5( $greek ),
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
     * Why a translated script does not match the Greek one, or '' when it does: it must
     * have every topic of the original, speakers the original has, and at least
     * MIN_TURN_SHARE of its turns.
     */
    public static function structure_error( string $greek, string $translated ): string {
        $producer = new PressHub_AI_Podcast_Producer();
        $hosts    = self::host_names();
        $a        = $producer->parse_script_turns( $greek, $hosts[0], $hosts[1], $hosts[2] );
        $b        = $producer->parse_script_turns( $translated, $hosts[0], $hosts[1], $hosts[2] );
        if ( empty( $b ) ) {
            return __( 'The translated script has no speaker turns.', 'presshub-ai-editor' );
        }
        $topics_a = preg_match_all( '/\[TOPIC_START\s*:/u', $greek );
        $topics_b = preg_match_all( '/\[TOPIC_START\s*:/u', $translated );
        if ( $topics_a !== $topics_b ) {
            return sprintf( __( 'The translated script has %1$d topics; the Greek one has %2$d.', 'presshub-ai-editor' ), $topics_b, $topics_a );
        }
        if ( count( $b ) < (int) floor( count( $a ) * self::MIN_TURN_SHARE ) ) {
            return sprintf( __( 'The translated script has %1$d turns; the Greek one has %2$d.', 'presshub-ai-editor' ), count( $b ), count( $a ) );
        }
        $speakers_a = array_unique( array_map( static fn( $t ) => (string) ( $t['speaker'] ?? '' ), $a ) );
        $speakers_b = array_unique( array_map( static fn( $t ) => (string) ( $t['speaker'] ?? '' ), $b ) );
        $extra      = array_diff( $speakers_b, $speakers_a );
        if ( ! empty( $extra ) ) {
            return sprintf( __( 'The translated script has speakers the Greek one does not have: %s.', 'presshub-ai-editor' ), implode( ', ', $extra ) );
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
        if ( 'google_cloud' === $engine && '' === (string) ( $voices['presenter_1'] ?? '' ) ) {
            // Google Cloud voices speak one language; the Greek ones cannot read English.
            return self::fail( $post_id, $lang, 'voice', new WP_Error( 'translation_voice_missing', sprintf( __( 'Google Cloud TTS needs %s voices: choose them under Daily Briefing, Other languages.', 'presshub-ai-editor' ), self::LANGUAGES[ $lang ]['name'] ) ) );
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
        if ( ! PressHub_Social_YouTube::wants_episode( $post_id ) || ! in_array( $lang, self::enabled_languages(), true ) ) {
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
        $greek    = class_exists( 'PressHub_AI_Podcast_Timeline' ) ? PressHub_AI_Podcast_Timeline::get( $post_id ) : null;
        $g_scenes = null !== $greek
            ? PressHub_Social_YouTube::episode_scenes( (array) ( $greek['topics'] ?? [] ), $groups, (float) $greek['duration'] )
            : [];
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
            $scenes = PressHub_Social_YouTube::episode_scenes( $topics, $groups, $duration );
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
