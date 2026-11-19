<?php
/**
 * Podcast timeline: when each spoken turn and each topic of a Daily Briefing podcast
 * starts and ends, in seconds from the start of the episode's audio.
 *
 * Built by PressHub_AI_Audio_Synthesizer while it stitches the episode, from the length
 * of every synthesized turn, and stored on the podcast post (META_KEY). The YouTube
 * episode video uses it for its burned-in captions, its scenes (one per topic) and the
 * chapters in its description.
 *
 * WAV turns (Gemini TTS, 24 kHz 16-bit mono) are measured exactly, and the gaps follow
 * stitch_wav_chunks(): the turn pause between turns, the topic pause between topics (or
 * 150 ms + transition sound + 150 ms when one is set), and the topic pause around the
 * intro and outro sounds. MP3 turns (Google Cloud TTS) are estimated from their text and
 * the timeline is marked `estimated`; the worker then scales it to the real audio length.
 *
 * Stored shape:
 * [
 *   'version'   => 1,
 *   'estimated' => bool,
 *   'duration'  => float,                      // end of the audio as timed here
 *   'topics'    => [ [ 'title', 'start', 'end' ], ... ],
 *   'segments'  => [ [ 'speaker' => 1|2, 'text', 'start', 'end', 'topic' => index ], ... ],
 * ]
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Podcast_Timeline {

    const META_KEY = '_presshub_podcast_timeline';

    /** Why the timeline of a podcast made by 2.54.0 or later could not be built or saved. */
    const ERROR_META_KEY = '_presshub_podcast_timeline_error';

    /** Why a podcast post has no timeline: the saved failure, or '' (none, or made before 2.54.0). */
    public static function error( int $post_id ): string {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return '';
        }
        return trim( (string) get_post_meta( $post_id, self::ERROR_META_KEY, true ) );
    }

    /** Padding on each side of the transition sound, as in stitch_wav_chunks(). */
    const SFX_PAD_SECONDS = 0.15;

    /** Characters per second used to estimate an MP3 turn. */
    const MP3_CHARS_PER_SECOND = 14;

    /** YouTube shows chapters only with at least three, each at least 10 seconds long. */
    const MIN_CHAPTERS        = 3;
    const MIN_CHAPTER_SECONDS = 10;

    /**
     * Length in seconds of one synthesized turn: measured for WAV or raw 24 kHz 16-bit
     * mono PCM, estimated from the text for MP3.
     */
    public static function turn_seconds( string $audio, string $text, bool $is_mp3 ): float {
        if ( $is_mp3 ) {
            return max( 0.5, mb_strlen( trim( $text ) ) / self::MP3_CHARS_PER_SECOND );
        }
        return self::pcm_seconds( $audio );
    }

    /**
     * Length in seconds of a WAV buffer, or of raw 24 kHz 16-bit mono PCM. Reads the
     * data chunk the same way stitch_wav_chunks() does.
     */
    public static function pcm_seconds( string $buffer ): float {
        $rate     = 24000;
        $channels = 1;
        $bits     = 16;
        $bytes    = strlen( $buffer );
        if ( $bytes >= 44 && 'RIFF' === substr( $buffer, 0, 4 ) && 'WAVE' === substr( $buffer, 8, 4 ) ) {
            $fmt = strpos( $buffer, 'fmt ' );
            if ( false !== $fmt && $bytes >= $fmt + 24 ) {
                $f        = unpack( 'vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr( $buffer, $fmt + 8, 16 ) );
                $channels = max( 1, (int) ( $f['channels'] ?? 1 ) );
                $rate     = max( 1, (int) ( $f['rate'] ?? 24000 ) );
                $bits     = max( 8, (int) ( $f['bits'] ?? 16 ) );
            }
            $data = strpos( $buffer, 'data' );
            if ( false !== $data && $bytes >= $data + 8 ) {
                $size  = (int) ( unpack( 'V', substr( $buffer, $data + 4, 4 ) )[1] ?? 0 );
                $avail = $bytes - $data - 8;
                $bytes = ( $size > 0 && $size <= $avail ) ? $size : $avail;
            } else {
                $bytes -= 44;
            }
        }
        return max( 0.0, $bytes / ( $rate * $channels * ( $bits / 8 ) ) );
    }

    /** Caption colour of a parsed turn: 2 for the second (male) host, 1 otherwise. */
    public static function speaker_number( string $speaker, int $host_count ): int {
        if ( $host_count < 2 ) {
            return 1;
        }
        return in_array( $speaker, [ 'male', 'presenter_2', 'speaker_2' ], true ) ? 2 : 1;
    }

    /**
     * Builds the timeline.
     *
     * @param array $topics Topics in the order they were stitched:
     *                      [ [ 'title' => string, 'turns' => [ [ 'speaker' => string, 'text' => string, 'seconds' => float ], ... ] ], ... ]
     *                      The whole-script path passes one topic with an empty title.
     * @param array $opts   turn_pause_ms, topic_pause_ms, transition_seconds (length of
     *                      the transition sound, 0 for none), intro_seconds, outro_seconds,
     *                      host_count, estimated.
     */
    public static function build( array $topics, array $opts ): array {
        $turn_pause  = max( 0, (int) ( $opts['turn_pause_ms'] ?? 250 ) ) / 1000;
        $topic_pause = max( 0, (int) ( $opts['topic_pause_ms'] ?? 600 ) ) / 1000;
        $transition  = max( 0.0, (float) ( $opts['transition_seconds'] ?? 0 ) );
        $intro       = max( 0.0, (float) ( $opts['intro_seconds'] ?? 0 ) );
        $outro       = max( 0.0, (float) ( $opts['outro_seconds'] ?? 0 ) );
        $hosts       = (int) ( $opts['host_count'] ?? 2 );
        $between     = $transition > 0 ? ( 2 * self::SFX_PAD_SECONDS + $transition ) : $topic_pause;

        $topics = array_values( array_filter( $topics, static fn( $t ) => is_array( $t ) && ! empty( $t['turns'] ) ) );

        $t        = $intro > 0 ? $intro + $topic_pause : 0.0;
        $out      = [];
        $segments = [];
        foreach ( $topics as $ti => $topic ) {
            if ( $ti > 0 ) {
                $t += $between;
            }
            $topic_start = $t;
            $placed = 0;
            foreach ( array_values( $topic['turns'] ) as $turn ) {
                $seconds = max( 0.0, (float) ( $turn['seconds'] ?? 0 ) );
                $text    = trim( (string) ( $turn['text'] ?? '' ) );
                // A turn without audio is skipped by stitch_wav_chunks(), pause and all.
                if ( $seconds <= 0 ) {
                    continue;
                }
                if ( $placed++ > 0 ) {
                    $t += $turn_pause;
                }
                if ( '' !== $text ) {
                    $segments[] = [
                        'speaker' => self::speaker_number( (string) ( $turn['speaker'] ?? '' ), $hosts ),
                        'text'    => $text,
                        'start'   => round( $t, 3 ),
                        'end'     => round( $t + $seconds, 3 ),
                        'topic'   => $ti,
                    ];
                }
                $t += $seconds;
            }
            $out[] = [
                'title' => trim( (string) ( $topic['title'] ?? '' ) ),
                'start' => round( $topic_start, 3 ),
                'end'   => round( $t, 3 ),
            ];
        }
        if ( $outro > 0 && ! empty( $out ) ) {
            $t += $topic_pause + $outro;
        }

        return [
            'version'   => 1,
            'estimated' => ! empty( $opts['estimated'] ),
            'duration'  => round( $t, 3 ),
            'topics'    => $out,
            'segments'  => $segments,
        ];
    }

    /** Whether a stored value is a usable timeline. */
    public static function is_valid( $timeline ): bool {
        return is_array( $timeline )
            && ! empty( $timeline['segments'] ) && is_array( $timeline['segments'] )
            && (float) ( $timeline['duration'] ?? 0 ) > 0;
    }

    /** The timeline stored on a podcast post, or null. */
    public static function get( int $post_id ): ?array {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return null;
        }
        $raw = get_post_meta( $post_id, self::META_KEY, true );
        if ( is_string( $raw ) && '' !== $raw ) {
            $raw = json_decode( $raw, true );
        }
        return self::is_valid( $raw ) ? $raw : null;
    }

    /** "M:SS" (or "H:MM:SS" from an hour) for a chapter list. */
    public static function stamp( float $seconds ): string {
        $s = max( 0, (int) floor( $seconds ) );
        $h = intdiv( $s, 3600 );
        $m = intdiv( $s % 3600, 60 );
        $r = $s % 60;
        return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $m, $r ) : sprintf( '%d:%02d', $m, $r );
    }

    /**
     * Chapter list for a YouTube description ("0:00 Title" per line), or '' when YouTube
     * would not show it (fewer than three chapters). The first chapter starts at 0:00, as
     * YouTube requires, so it takes in the intro. A topic shorter than 10 seconds (YouTube's
     * minimum) is merged into the chapter before it, or into the next one when it is the
     * first. Estimated timelines are scaled to $audio_seconds when that is known.
     */
    public static function chapters( array $timeline, float $audio_seconds = 0.0 ): string {
        $topics = array_values( array_filter( (array) ( $timeline['topics'] ?? [] ), static fn( $t ) => is_array( $t ) && '' !== trim( (string) ( $t['title'] ?? '' ) ) ) );
        if ( count( $topics ) < self::MIN_CHAPTERS ) {
            return '';
        }
        $duration = (float) ( $timeline['duration'] ?? 0 );
        $ratio    = ( $audio_seconds > 0 && $duration > 0 ) ? $audio_seconds / $duration : 1.0;
        $end      = $duration * $ratio;

        $chapters = [];
        foreach ( $topics as $i => $topic ) {
            $title      = trim( (string) preg_replace( '/\s+/u', ' ', ( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $topic['title'] ) : strip_tags( (string) $topic['title'] ) ) ) );
            $chapters[] = [ 'title' => $title, 'start' => 0 === $i ? 0.0 : (float) $topic['start'] * $ratio ];
        }
        // Merge chapters under the minimum length until every one is long enough.
        for ( $i = 0; $i < count( $chapters ); ) {
            $next = $chapters[ $i + 1 ]['start'] ?? $end;
            if ( $next - $chapters[ $i ]['start'] >= self::MIN_CHAPTER_SECONDS || count( $chapters ) < 2 ) {
                $i++;
                continue;
            }
            if ( 0 === $i ) {
                // The first keeps 0:00 and takes the next chapter's place.
                $chapters[1]['start'] = 0.0;
                array_splice( $chapters, 0, 1 );
            } else {
                array_splice( $chapters, $i, 1 );
                $i = max( 0, $i - 1 );
            }
        }
        if ( count( $chapters ) < self::MIN_CHAPTERS ) {
            return '';
        }
        return implode( "\n", array_map( static fn( $c ) => self::stamp( $c['start'] ) . ' ' . $c['title'], $chapters ) );
    }
}
