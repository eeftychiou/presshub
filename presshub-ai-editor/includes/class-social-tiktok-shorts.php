<?php
/**
 * TikTok shorts: turns one podcast post into several 30-60 second TikTok videos,
 * one story each, instead of posting the whole episode.
 *
 * Flow:
 * 1. Enqueue (PressHub_Social_Dispatcher): when shorts are enabled and the post is a
 *    podcast post, the TikTok platform gets `presshub_social_tiktok_shorts_count` jobs
 *    instead of one, spaced `presshub_social_tiktok_shorts_spacing_minutes` apart. Each
 *    job carries `metadata.tiktok_short = {index, count}` and a placeholder copy.
 * 2. Claim (PressHub_Social_REST_Controller::handle_claim): the first short job claimed
 *    asks the social AI provider for a plan of N shorts (one LLM call, prompt
 *    `presshub_social_prompt_tiktok_shorts`, seeded from social_tiktok_shorts.txt). The
 *    plan is stored in post meta `_presshub_tiktok_shorts` and reused by the other jobs.
 *    Each short is a hook-first dialogue script, a 4-7 word on-screen hook and a caption.
 * 3. The job's short is synthesized turn by turn with the Daily Briefing voices
 *    (synthesize_turn), stitched with the briefing turn pause, sideloaded, and returned
 *    as `audio_url`, with `video_title` = the hook for the worker's card. The placeholder
 *    copy is replaced by the short's caption (an edited copy is kept).
 *
 * The audio is cached in job metadata keyed by a SHA-256 of voices, speed, pitch, style
 * and script, so a retry reuses it. Zero-Silent-Fallback: a failed plan, a missing short
 * or a failed synthesis fails the job instead of posting the full podcast.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_TikTok_Shorts {

    /** Post meta holding the generated plan of shorts for a podcast post. */
    public const PLAN_META = '_presshub_tiktok_shorts';

    /** Job metadata key marking a short job and caching its audio. */
    public const JOB_META_KEY = 'tiktok_short';

    /** Prefix of the placeholder copy set at enqueue time. */
    public const PLACEHOLDER_PREFIX = '[TikTok short ';

    /**
     * Optional LLM override for tests.
     * Signature: fn( string $sys_prompt, string $user_prompt ): string|WP_Error
     *
     * @var callable|null
     */
    private static $ai_caller = null;

    /**
     * Optional synthesizer override for tests.
     * Signature: fn( array $short, array $job ): array{url:string,id:int,voices?:array}|WP_Error
     *
     * @var callable|null
     */
    private static $synthesizer = null;

    public static function set_ai_caller( ?callable $caller ): void {
        self::$ai_caller = $caller;
    }

    public static function set_synthesizer( ?callable $synthesizer ): void {
        self::$synthesizer = $synthesizer;
    }

    /**
     * Whether a post is a Daily Briefing podcast post with a script to cut shorts from.
     */
    public static function is_podcast_post( int $post_id ): bool {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return false;
        }
        return 'podcast' === (string) get_post_meta( $post_id, '_presshub_briefing_type', true )
            && '' !== trim( (string) get_post_meta( $post_id, '_presshub_podcast_script', true ) );
    }

    /**
     * Whether the TikTok job for this post should be split into shorts.
     *
     * Shorts are made when the worker claims the job, so they need the automation engine
     * in Pull Mode. With shorts enabled but another engine, the full podcast is posted and
     * a WARNING says why.
     */
    public static function should_split( int $post_id, string $engine ): bool {
        if ( ! PressHub_Social_Settings_Storage::get_tiktok_shorts_enabled() || ! self::is_podcast_post( $post_id ) ) {
            return false;
        }
        $worker_mode = PressHub_Social_Settings_Storage::get_worker_mode();
        if ( 'automation' !== $engine || 'pull' !== $worker_mode ) {
            PressHub_Social_Logger::warning( sprintf(
                'TikTok shorts are enabled but post #%d is posted as the full podcast: shorts need the automation engine in Pull Mode (engine: %s, worker mode: %s).',
                $post_id,
                $engine,
                $worker_mode
            ) );
            return false;
        }
        return true;
    }

    public static function placeholder_copy( int $index, int $count ): string {
        return sprintf( '%s%d/%d: the script, audio and caption are written when the worker claims this job]', self::PLACEHOLDER_PREFIX, $index + 1, $count );
    }

    /**
     * Build the job rows for the shorts of a podcast post.
     *
     * @param int    $post_id    Podcast post.
     * @param string $engine     TikTok engine.
     * @param int    $start_time Unix time of the first short.
     * @return array<int, array> Job data arrays for PressHub_Social_Queue::enqueue().
     */
    public static function build_jobs( int $post_id, string $engine, ?string $media_url, ?string $media_type, int $start_time ): array {
        $count   = PressHub_Social_Settings_Storage::get_tiktok_shorts_count();
        $spacing = PressHub_Social_Settings_Storage::get_tiktok_shorts_spacing_minutes() * 60;
        $jobs    = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $jobs[] = [
                'post_id'      => $post_id,
                'platform'     => 'tiktok',
                'engine'       => $engine,
                'post_copy'    => self::placeholder_copy( $i, $count ),
                'media_url'    => $media_url,
                'media_type'   => $media_type,
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', $start_time + ( $i * $spacing ) ),
                'status'       => 'pending',
                'metadata'     => [ self::JOB_META_KEY => [ 'index' => $i, 'count' => $count ] ],
            ];
        }
        return $jobs;
    }

    /**
     * Whether a job is a TikTok short job.
     */
    public static function is_short_job( array $job ): bool {
        if ( 'tiktok' !== ( $job['platform'] ?? '' ) ) {
            return false;
        }
        $meta = self::decode_metadata( $job['metadata'] ?? null );
        return is_array( $meta[ self::JOB_META_KEY ] ?? null ) && isset( $meta[ self::JOB_META_KEY ]['index'] );
    }

    /**
     * Attach the short's audio, caption and on-screen hook to a claimed TikTok short job.
     *
     * @param array $job Claimed job.
     * @return array|WP_Error The job with `audio_url`, `video_title`, `post_copy` and `tiktok_short` set.
     */
    public static function attach_short( array $job ) {
        if ( ! self::is_short_job( $job ) ) {
            return $job;
        }

        $job_id   = (int) ( $job['id'] ?? 0 );
        $post_id  = (int) ( $job['post_id'] ?? 0 );
        $metadata = self::decode_metadata( $job['metadata'] ?? null );
        $index    = (int) $metadata[ self::JOB_META_KEY ]['index'];

        $plan = self::get_plan( $post_id );
        if ( is_wp_error( $plan ) ) {
            return self::fail( $job_id, $plan );
        }

        $short = $plan['shorts'][ $index ] ?? null;
        if ( ! is_array( $short ) ) {
            return self::fail( $job_id, new WP_Error(
                'tiktok_short_missing',
                sprintf( 'the plan for post #%d has %d shorts, so short %d does not exist.', $post_id, count( $plan['shorts'] ), $index + 1 )
            ) );
        }

        $audio = self::get_audio( $job, $short, $metadata );
        if ( is_wp_error( $audio ) ) {
            return self::fail( $job_id, $audio );
        }

        $metadata[ self::JOB_META_KEY ] = array_merge( $metadata[ self::JOB_META_KEY ], [
            'topic' => $short['topic'],
            'hook'  => $short['hook'],
            'audio' => $audio,
        ] );
        if ( $job_id > 0 ) {
            PressHub_Social_Queue::update_job( $job_id, [
                'metadata' => function_exists( 'wp_json_encode' ) ? wp_json_encode( $metadata ) : json_encode( $metadata ),
            ] );
        }

        // The placeholder becomes the short's caption; a copy an editor wrote stays.
        $copy = (string) ( $job['post_copy'] ?? '' );
        if ( '' === trim( $copy ) || str_starts_with( $copy, self::PLACEHOLDER_PREFIX ) ) {
            $copy = $short['caption'];
            if ( $job_id > 0 ) {
                PressHub_Social_Queue::update_job_copy( $job_id, $copy );
            }
        }

        PressHub_Social_Logger::info( sprintf( 'TikTok short %d/%d ready for job #%d (post #%d): "%s"', $index + 1, (int) ( $metadata[ self::JOB_META_KEY ]['count'] ?? 0 ), $job_id, $post_id, $short['hook'] ) );

        $job['metadata']     = function_exists( 'wp_json_encode' ) ? wp_json_encode( $metadata ) : json_encode( $metadata );
        $job['post_copy']    = $copy;
        $job['audio_url']    = (string) $audio['audio_url'];
        $job['video_title']  = $short['hook'];
        $job['tiktok_short'] = [
            'index' => $index,
            'count' => (int) ( $metadata[ self::JOB_META_KEY ]['count'] ?? 0 ),
            'topic' => $short['topic'],
            'hook'  => $short['hook'],
        ];
        return $job;
    }

    /**
     * Get the plan of shorts for a podcast post, generating it when missing or stale.
     *
     * The plan is keyed by a hash of the podcast script, the prompt, the count and the
     * word cap, so a regenerated podcast or an edited prompt makes a new plan.
     *
     * @return array{hash:string,shorts:array}|WP_Error
     */
    public static function get_plan( int $post_id ) {
        if ( ! self::is_podcast_post( $post_id ) ) {
            return new WP_Error( 'tiktok_short_no_script', sprintf( 'post #%d is not a podcast post with a script.', $post_id ) );
        }

        $prompt = PressHub_Social_Settings_Storage::get_tiktok_shorts_prompt();
        if ( '' === $prompt ) {
            PressHub_Social_Logger::error( 'TikTok shorts prompt is empty (presshub_social_prompt_tiktok_shorts).' );
            return new WP_Error( 'missing_tiktok_shorts_prompt', 'the TikTok shorts prompt (presshub_social_prompt_tiktok_shorts) is empty. Restore it in PressHub Social → Settings → Prompts.' );
        }

        $script    = (string) get_post_meta( $post_id, '_presshub_podcast_script', true );
        $count     = PressHub_Social_Settings_Storage::get_tiktok_shorts_count();
        $max_words = PressHub_Social_Settings_Storage::get_tiktok_shorts_max_words();
        $hash      = hash( 'sha256', $script . '|' . $prompt . '|' . $count . '|' . $max_words . '|' . self::host_count() );

        $stored = get_post_meta( $post_id, self::PLAN_META, true );
        if ( is_array( $stored ) && ( $stored['hash'] ?? '' ) === $hash && ! empty( $stored['shorts'] ) ) {
            return $stored;
        }

        $user_prompt = self::build_prompt( $post_id, $prompt, $script, $count, $max_words );
        $sys_prompt  = PressHub_Social_Settings_Storage::get_system_prompt();

        PressHub_Social_Logger::info( sprintf( 'Generating %d TikTok shorts for podcast post #%d.', $count, $post_id ) );
        $raw = self::call_ai( $sys_prompt, $user_prompt );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $shorts = self::parse_plan( (string) $raw, $count );
        if ( is_wp_error( $shorts ) ) {
            PressHub_Social_Logger::error( sprintf( 'TikTok shorts plan for post #%d is invalid: %s', $post_id, $shorts->get_error_message() ), [ 'response' => mb_substr( (string) $raw, 0, 500 ) ] );
            return $shorts;
        }

        $plan = [
            'hash'         => $hash,
            'generated_at' => gmdate( 'c' ),
            'shorts'       => $shorts,
        ];
        update_post_meta( $post_id, self::PLAN_META, $plan );
        return $plan;
    }

    /**
     * Fill the shorts prompt placeholders.
     */
    public static function build_prompt( int $post_id, string $template, string $script, int $count, int $max_words ): string {
        $post      = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        $title     = $post ? (string) ( is_object( $post ) ? ( $post->post_title ?? '' ) : ( $post['post_title'] ?? '' ) ) : '';
        $site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'PressHub';
        $speakers  = 1 === self::host_count() ? '[SPEAKER_1] μόνο' : '[SPEAKER_1] και [SPEAKER_2], εναλλάξ';

        return str_replace(
            [ '{count}', '{max_words}', '{speakers}', '{title}', '{site_name}', '{date}', '{script}' ],
            [ (string) $count, (string) $max_words, $speakers, $title, '' !== $site_name ? $site_name : 'PressHub', (string) get_post_meta( $post_id, '_presshub_briefing_date', true ), $script ],
            $template
        );
    }

    /**
     * Parse and validate the LLM's JSON plan.
     *
     * @return array|WP_Error List of shorts {topic, hook, script, caption}, at most $count.
     */
    public static function parse_plan( string $raw, int $count ) {
        $text = trim( $raw );
        $text = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/iu', '', $text );
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( false === $start || false === $end || $end <= $start ) {
            return new WP_Error( 'tiktok_short_invalid_json', 'the AI response is not JSON.' );
        }
        $decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
        if ( ! is_array( $decoded ) || ! is_array( $decoded['shorts'] ?? null ) || empty( $decoded['shorts'] ) ) {
            return new WP_Error( 'tiktok_short_invalid_json', 'the AI response has no "shorts" list.' );
        }

        $shorts = [];
        foreach ( array_slice( $decoded['shorts'], 0, $count ) as $i => $item ) {
            $topic   = trim( (string) ( $item['topic'] ?? '' ) );
            $hook    = trim( (string) ( $item['hook'] ?? '' ) );
            $script  = trim( (string) ( $item['script'] ?? '' ) );
            $caption = trim( (string) ( $item['caption'] ?? '' ) );
            if ( '' === $hook || '' === $script || '' === $caption ) {
                return new WP_Error( 'tiktok_short_invalid_json', sprintf( 'short %d is missing its hook, script or caption.', $i + 1 ) );
            }
            if ( ! preg_match( '/\[\s*SPEAKER_[123]\s*\]\s*:/iu', $script ) ) {
                return new WP_Error( 'tiktok_short_invalid_json', sprintf( 'short %d script has no [SPEAKER_N]: lines.', $i + 1 ) );
            }
            $shorts[] = [
                'topic'   => '' !== $topic ? $topic : $hook,
                'hook'    => $hook,
                'script'  => $script,
                'caption' => $caption,
            ];
        }
        return $shorts;
    }

    /**
     * Get the short's audio from the job cache or synthesize it.
     *
     * @return array{hash:string,audio_url:string,attachment_id:int}|WP_Error
     */
    private static function get_audio( array $job, array $short, array $metadata ) {
        $hash   = self::audio_hash( $short['script'] );
        $cached = $metadata[ self::JOB_META_KEY ]['audio'] ?? null;
        if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash && ! empty( $cached['audio_url'] ) ) {
            PressHub_Social_Logger::info( sprintf( 'TikTok short audio reused for job #%d.', (int) ( $job['id'] ?? 0 ) ) );
            return $cached;
        }

        // A short is 6-10 TTS turns paced by the shared TTS rate limiter, which can take
        // longer than PHP's default limit inside this worker claim request.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $record_id    = self::begin_record( $job, $short, $hash );
        $owns_session = false;
        if ( $record_id > 0 && class_exists( 'PressHub_AI_TTS_Call_Recorder' ) && ! PressHub_AI_TTS_Call_Recorder::is_active() ) {
            PressHub_AI_TTS_Call_Recorder::begin_session( $record_id );
            $owns_session = true;
        }
        try {
            $result = null !== self::$synthesizer
                ? call_user_func( self::$synthesizer, $short, $job )
                : self::synthesize( $short, $job, $hash );
        } finally {
            if ( $owns_session ) {
                PressHub_AI_TTS_Call_Recorder::end_session();
            }
        }
        self::finish_record( $record_id, $result );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result['url'] ) ) {
            return new WP_Error( 'tiktok_short_no_audio', 'synthesized audio has no URL.' );
        }

        return [
            'hash'                 => $hash,
            'audio_url'            => (string) $result['url'],
            'attachment_id'        => (int) ( $result['id'] ?? 0 ),
            'generated_at'         => gmdate( 'c' ),
            'generation_record_id' => $record_id,
        ];
    }

    /**
     * Synthesize a short turn by turn with the Daily Briefing voices and sideload it.
     *
     * @return array|WP_Error
     */
    private static function synthesize( array $short, array $job, string $hash ) {
        if ( ! class_exists( 'PressHub_AI_Audio_Synthesizer' ) || ! class_exists( 'PressHub_AI_Podcast_Producer' ) ) {
            return new WP_Error( 'tts_unavailable', 'PressHub_AI_Audio_Synthesizer is not loaded.' );
        }

        $synth    = new PressHub_AI_Audio_Synthesizer();
        $producer = new PressHub_AI_Podcast_Producer();
        $turns    = $producer->parse_script_turns( $short['script'] );
        if ( empty( $turns ) ) {
            return new WP_Error( 'tiktok_short_no_turns', 'the short script has no speaker turns.' );
        }

        $voices = self::voices( $synth );
        $speed  = PressHub_AI_Settings_Storage::get_voice_speed();
        $pitch  = PressHub_AI_Settings_Storage::get_voice_pitch();
        $style  = self::style();
        $engine = PressHub_AI_Settings_Storage::get_briefing_tts_engine();

        $chunks = [];
        foreach ( $turns as $turn ) {
            $voice = 'male' === ( $turn['speaker'] ?? '' ) && 1 !== self::host_count() ? $voices['speaker_2'] : $voices['speaker_1'];
            $audio = $synth->synthesize_turn( (string) $turn['text'], $voice, $speed, $pitch, null, $style, '' );
            if ( is_wp_error( $audio ) ) {
                return $audio;
            }
            $chunks[] = $audio;
        }

        $pause = PressHub_AI_Settings_Storage::get_briefing_tts_turn_pause_ms();
        $is_mp3   = 'google_cloud' === $engine;
        $stitched = $is_mp3 ? $synth->stitch_audio_chunks( $chunks, $pause ) : $synth->stitch_wav_chunks( $chunks, $pause, 24000 );
        if ( is_wp_error( $stitched ) ) {
            return $stitched;
        }
        if ( ! is_string( $stitched ) || '' === $stitched ) {
            return new WP_Error( 'tts_empty_audio', 'TTS returned empty audio for the short.' );
        }

        $meta     = self::decode_metadata( $job['metadata'] ?? null );
        $index    = (int) ( $meta[ self::JOB_META_KEY ]['index'] ?? 0 );
        $post_id  = (int) ( $job['post_id'] ?? 0 );
        $filename = sprintf( 'tiktok-short-post-%d-%d-%s.%s', $post_id, $index + 1, substr( $hash, 0, 8 ), $is_mp3 ? 'mp3' : 'wav' );
        $upload   = $synth->sideload_audio_file( $filename, $stitched, $post_id, sprintf( 'TikTok short %d (post #%d)', $index + 1, $post_id ) );
        if ( is_wp_error( $upload ) ) {
            return $upload;
        }
        $upload['voices'] = $voices;
        return $upload;
    }

    private static function audio_hash( string $script ): string {
        $voices = class_exists( 'PressHub_AI_Audio_Synthesizer' ) ? self::voices( new PressHub_AI_Audio_Synthesizer() ) : [];
        $speed  = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_speed() : 1.0;
        $pitch  = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_voice_pitch() : 0.0;
        return hash( 'sha256', implode( '|', $voices ) . '|' . $speed . '|' . $pitch . '|' . self::style() . '|' . $script );
    }

    /** @return array{speaker_1:string,speaker_2:string} */
    private static function voices( PressHub_AI_Audio_Synthesizer $synth ): array {
        return [
            'speaker_1' => $synth->get_voice_for_speaker( 'presenter_1' ),
            'speaker_2' => $synth->get_voice_for_speaker( 'presenter_2' ),
        ];
    }

    private static function style(): string {
        if ( ! class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            return '';
        }
        $key    = PressHub_AI_Settings_Storage::get_briefing_tts_style();
        $custom = PressHub_AI_Settings_Storage::get_briefing_tts_custom_style();
        return ( 'custom' === $key && '' !== trim( $custom ) ) ? $custom : $key;
    }

    private static function host_count(): int {
        return class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_count() : 2;
    }

    /**
     * Call the social AI provider (the copywriter's provider and model) in JSON mode.
     *
     * @return string|WP_Error
     */
    private static function call_ai( string $sys_prompt, string $user_prompt ) {
        if ( null !== self::$ai_caller ) {
            $result = call_user_func( self::$ai_caller, $sys_prompt, $user_prompt );
        } else {
            $config = PressHub_Social_AI_Copywriter::resolve_ai_provider_config();
            if ( is_wp_error( $config ) ) {
                return $config;
            }
            if ( ! class_exists( 'PressHub_AI_API_Client' ) ) {
                return new WP_Error( 'missing_api_client', 'PressHub_AI_API_Client is not loaded.' );
            }
            try {
                $client = new PressHub_AI_API_Client( $config );
                if ( method_exists( $client, 'set_action' ) ) {
                    $client->set_action( 'social_tiktok_shorts' );
                }
                $result = $client->call_provider( $sys_prompt, $user_prompt, true, [], $config['temperature'], [ 'platform' => 'tiktok', 'purpose' => 'tiktok_shorts' ] );
            } catch ( Exception $e ) {
                $result = new WP_Error( 'ai_dispatch_exception', $e->getMessage() );
            }
        }

        if ( is_wp_error( $result ) ) {
            PressHub_Social_Logger::error( 'TikTok shorts generation failed: ' . $result->get_error_message(), [ 'error_code' => $result->get_error_code() ] );
            return $result;
        }
        if ( ! is_string( $result ) || '' === trim( $result ) ) {
            return new WP_Error( 'empty_ai_response', 'the AI provider returned an empty response for the TikTok shorts.' );
        }
        return $result;
    }

    private static function fail( int $job_id, WP_Error $error ): WP_Error {
        $err = new WP_Error(
            'tiktok_short_failed',
            sprintf( 'TikTok short failed for job #%d: %s', $job_id, $error->get_error_message() )
        );
        PressHub_Social_Logger::error( $err->get_error_message(), [ 'job_id' => $job_id, 'error_code' => $error->get_error_code() ] );
        return $err;
    }

    /**
     * Create the `tiktok_short` generation record for a synthesis about to run.
     */
    private static function begin_record( array $job, array $short, string $hash ): int {
        if ( ! class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            return 0;
        }
        $post_id = (int) ( $job['post_id'] ?? 0 );
        $id      = (int) PressHub_AI_Generation_Recorder::record( [
            'kind'        => 'tiktok_short',
            'status'      => 'success',
            'post_id'     => $post_id,
            'config'      => [
                'module'   => 'tts',
                'action'   => 'tiktok_short',
                'platform' => 'tiktok',
                'job_id'   => (int) ( $job['id'] ?? 0 ),
            ],
            'inputs'      => [
                'job_id'     => (int) ( $job['id'] ?? 0 ),
                'post_id'    => $post_id,
                'topic'      => $short['topic'],
                'hook'       => $short['hook'],
                'cache_hash' => $hash,
            ],
            'user_prompt' => $short['script'],
        ] );
        if ( $id > 0 && $post_id > 0 ) {
            PressHub_AI_Generation_Recorder::attach_to_post( $post_id, $id );
        }
        return $id;
    }

    /**
     * @param array|WP_Error $result
     */
    private static function finish_record( int $record_id, $result ): void {
        if ( $record_id <= 0 || ! class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            return;
        }
        if ( is_wp_error( $result ) || empty( $result['url'] ) ) {
            PressHub_AI_Generation_Recorder::update_record( $record_id, [
                'status'        => 'error',
                'error_message' => is_wp_error( $result ) ? $result->get_error_message() : 'Synthesized audio has no URL.',
            ] );
            return;
        }
        PressHub_AI_Generation_Recorder::update_record( $record_id, [
            'tts' => [
                'engine'         => class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_tts_engine() : '',
                'voices'         => (array) ( $result['voices'] ?? [] ),
                'audio_url'      => (string) $result['url'],
                'attachment_id'  => (int) ( $result['id'] ?? 0 ),
                'synthesized_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
            ],
        ] );
    }

    /**
     * @param mixed $raw
     */
    private static function decode_metadata( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return [];
    }
}
