<?php
/**
 * The story ledger (2.84.0): what each platform already told, and which stories would
 * repeat it. It is the first check of the social post gate (PressHub_Social_Post_Gate),
 * which every post on every platform passes before it is prepared and again before it
 * goes out.
 *
 * The ledger is read from the social queue itself, so there is no new store. A short, a
 * brief or photo slides keep their stories in `metadata.tiktok_short` (topic, hook,
 * `story_key`, `whats_new`, `dev_hash`; a brief per story under `stories`); any other post
 * keeps its story tag in `metadata.story` (PressHub_Social_Post_Gate::STORY_META). Jobs of
 * other posts that were published, are being posted or are waiting, inside Story Window
 * (`presshub_social_story_repeat_hours`), count, per platform.
 *
 * The story keys are the editorial memory's (PressHub_AI_Memory_Store): each daily digest
 * lists its stories with a key that stays the same across days, a development and a
 * status. When a plan or a story tag is written:
 * 1. The prompt gets the stories already told and the memory's stories
 *    (prompt_block(), asset social_story_repeats.txt), and the AI tags every story with a
 *    `story_key` and, when it retells one, the new fact in `whats_new`.
 * 2. find_repeats() checks the stories in code. A story already told in the window is a
 *    repeat unless it names what is new AND, when the memory knows the story, the memory's
 *    development or status changed since it was told (`dev_hash`). A story the memory marks
 *    resolved, a story posted Most Posts per Story per Platform times on one platform in
 *    the window, and a story twice in one plan are always repeats.
 * 3. A plan is asked for once more with the reason; repeats left after that are dropped
 *    (a podcast's short, a brief's story part, an article's short), each with an INFO log.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Story_Check {

    /** Job statuses that mean the story went out or is about to. */
    const TOLD_STATUSES = [ 'published', 'processing', 'pending' ];

    /** Most jobs read from the queue for one check. */
    const MAX_JOBS = 300;

    /** Most memory stories and told stories put in the prompt. */
    const MAX_PROMPT_STORIES = 40;

    /** Clock override for tests. */
    private static ?int $now = null;

    public static function set_now( ?int $now ): void {
        self::$now = $now;
    }

    public static function now(): int {
        return self::$now ?? time();
    }

    /** Whether the check is on (Story Repeat Window above 0). */
    public static function enabled(): bool {
        return class_exists( 'PressHub_Social_Settings_Storage' ) && PressHub_Social_Settings_Storage::get_story_repeat_hours() > 0;
    }

    /**
     * The stories other posts told (or are about to tell) on these platforms inside the
     * window, newest first: one entry per job and story, {key, topic, hook, whats_new,
     * dev_hash, platform, post_id, job_id, when (Unix time), format}.
     *
     * @param string[] $platforms Platforms to look at; empty = every platform.
     * @param string[] $statuses  Job statuses that count as told.
     */
    public static function told( int $exclude_post_id, array $platforms = [], ?int $hours = null, array $statuses = self::TOLD_STATUSES ): array {
        $hours     = null === $hours ? PressHub_Social_Settings_Storage::get_story_repeat_hours() : $hours;
        if ( $hours <= 0 ) {
            return [];
        }
        $platforms = empty( $platforms ) ? PressHub_Social_Queue::VALID_PLATFORMS : array_values( array_intersect( $platforms, PressHub_Social_Queue::VALID_PLATFORMS ) );
        $since     = gmdate( 'Y-m-d H:i:s', self::now() - $hours * 3600 );
        $jobs      = PressHub_Social_Queue::query_jobs( [
            'statuses' => $statuses,
            'since'    => $since,
            'limit'    => self::MAX_JOBS,
        ] );
        $entries = [];
        foreach ( $jobs as $job ) {
            $post_id = (int) ( $job['post_id'] ?? 0 );
            if ( $post_id === $exclude_post_id || ! in_array( (string) ( $job['platform'] ?? '' ), $platforms, true ) ) {
                continue;
            }
            $meta   = self::decode( $job['metadata'] ?? null );
            $ts     = $meta[ PressHub_Social_TikTok_Shorts::JOB_META_KEY ] ?? null;
            $status = (string) ( $job['status'] ?? '' );
            $when   = strtotime( ( 'published' === $status && ! empty( $job['dispatched_at'] ) ? $job['dispatched_at'] : ( $job['scheduled_at'] ?? '' ) ) . ' UTC' );
            $base   = [
                'platform' => (string) $job['platform'],
                'post_id'  => $post_id,
                'job_id'   => (int) ( $job['id'] ?? 0 ),
                'when'     => $when ?: self::now(),
                'format'   => 'post',
                'status'   => $status,
            ];
            if ( ! is_array( $ts ) ) {
                // Any other post (a link post, a photo post, a tweet): its story tag, set by
                // the social post gate when it was queued (PressHub_Social_Post_Gate::STORY_META).
                $story = $meta[ PressHub_Social_Post_Gate::STORY_META ] ?? null;
                if ( is_array( $story ) && ( '' !== trim( (string) ( $story['story_key'] ?? '' ) ) || '' !== trim( (string) ( $story['topic'] ?? '' ) ) ) ) {
                    $entries[] = array_merge( $base, self::story_fields( $story, '' ) );
                }
                continue;
            }
            if ( ! empty( $ts['test'] ) || '' === trim( (string) ( $ts['topic'] ?? '' ) ) ) {
                continue; // A test render, or a short not prepared yet.
            }
            $base['format'] = ! empty( $ts['brief'] ) ? 'brief' : ( PressHub_Social_TikTok_Shorts::FORMAT_SLIDES === ( $ts['format'] ?? '' ) ? 'slides' : 'short' );
            if ( ! empty( $ts['brief'] ) && is_array( $ts['stories'] ?? null ) ) {
                foreach ( $ts['stories'] as $story ) {
                    if ( ! is_array( $story ) ) {
                        continue;
                    }
                    $entries[] = array_merge( $base, self::story_fields( $story, '' ) );
                }
                continue;
            }
            $entries[] = array_merge( $base, self::story_fields( $ts, (string) ( $ts['hook'] ?? '' ) ) );
        }
        usort( $entries, fn( $a, $b ) => $b['when'] <=> $a['when'] );
        return $entries;
    }

    private static function story_fields( array $story, string $hook ): array {
        return [
            'key'       => self::key( (string) ( $story['story_key'] ?? '' ) ),
            'topic'     => trim( (string) ( $story['topic'] ?? '' ) ),
            'hook'      => trim( $hook ),
            'whats_new' => trim( (string) ( $story['whats_new'] ?? '' ) ),
            'dev_hash'  => (string) ( $story['dev_hash'] ?? '' ),
        ];
    }

    /** A story key as stored: a slug, or '' when there is none. */
    public static function key( string $key ): string {
        $key = trim( $key );
        if ( '' === $key ) {
            return '';
        }
        return function_exists( 'sanitize_title' ) ? (string) sanitize_title( $key ) : strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $key ) );
    }

    /**
     * The editorial memory's stories of the day and the day before (active daily
     * digests, later editions win): key => {title, development, status, hash}.
     */
    public static function memory_stories( string $date ): array {
        if ( ! class_exists( 'PressHub_AI_Memory_Store' ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return [];
        }
        $from = gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) - 86400 );
        try {
            $rows = PressHub_AI_Memory_Store::get_entries_for_range( $from, $date, 'daily', 'active' );
        } catch ( \Throwable $e ) {
            PressHub_Social_Logger::warning( 'Story repeats: the editorial memory could not be read (' . $e->getMessage() . '); the check uses the stories already posted only.' );
            return [];
        }
        $stories = [];
        foreach ( $rows as $row ) {
            $content = PressHub_AI_Memory_Store::decode_content( (string) ( $row['content_json'] ?? '' ) );
            foreach ( (array) ( $content['stories'] ?? [] ) as $story ) {
                $key = self::key( (string) ( $story['key'] ?? '' ) );
                if ( '' === $key ) {
                    continue;
                }
                $development     = trim( (string) ( $story['development'] ?? '' ) );
                $status          = (string) ( $story['status'] ?? '' );
                $stories[ $key ] = [
                    'title'       => trim( (string) ( $story['title'] ?? '' ) ),
                    'development' => $development,
                    'status'      => $status,
                    'hash'        => self::dev_hash( $development, $status ),
                ];
            }
        }
        return $stories;
    }

    public static function dev_hash( string $development, string $status ): string {
        return substr( hash( 'sha256', $development . '|' . $status ), 0, 16 );
    }

    /**
     * The prompt block with the stories already told and the memory's stories, or '' when
     * there are neither. One line per told story (its platforms merged), newest first.
     *
     * @param string $where Where the AI puts story_key, e.g. "σε κάθε short".
     */
    public static function prompt_block( array $told, array $memory, int $hours, string $where ) {
        if ( empty( $told ) && empty( $memory ) ) {
            return '';
        }
        $template = PressHub_Social_Prompt_Loader::load( 'social_story_repeats.txt' );
        $lines    = [];
        $index    = [];
        foreach ( $told as $entry ) {
            $group = $entry['post_id'] . '|' . ( '' !== $entry['key'] ? $entry['key'] : $entry['topic'] );
            if ( isset( $index[ $group ] ) ) {
                $lines[ $index[ $group ] ]['platforms'][ $entry['platform'] ] = true;
                $lines[ $index[ $group ] ]['when'] = min( $lines[ $index[ $group ] ]['when'], $entry['when'] );
                continue;
            }
            if ( count( $lines ) >= self::MAX_PROMPT_STORIES ) {
                continue;
            }
            $index[ $group ] = count( $lines );
            $lines[]         = $entry + [ 'platforms' => [ $entry['platform'] => true ] ];
        }
        $told_text = [];
        foreach ( $lines as $line ) {
            $text = sprintf(
                '- [%s] %s (%s): %s',
                '' !== $line['key'] ? $line['key'] : '-',
                self::time_label( (int) $line['when'] ),
                implode( ', ', array_keys( $line['platforms'] ) ),
                self::clean( $line['topic'] )
            );
            if ( '' !== $line['hook'] && $line['hook'] !== $line['topic'] ) {
                $text .= ' / ' . self::clean( $line['hook'] );
            }
            if ( '' !== $line['whats_new'] ) {
                $text .= ' (νέο τότε: ' . self::clean( $line['whats_new'] ) . ')';
            }
            $told_text[] = $text;
        }
        $memory_text = [];
        foreach ( array_slice( $memory, 0, self::MAX_PROMPT_STORIES, true ) as $key => $story ) {
            $memory_text[] = sprintf( '- %s: %s, %s (%s)', $key, self::clean( $story['title'] ), self::clean( $story['development'] ), $story['status'] );
        }
        return strtr( $template, [
            '{hours}'  => (string) $hours,
            '{told}'   => empty( $told_text ) ? '(καμία)' : implode( "\n", $told_text ),
            '{memory}' => empty( $memory_text ) ? '(κενή)' : implode( "\n", $memory_text ),
            '{where}'  => $where,
        ] );
    }

    private static function clean( string $text ): string {
        $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 240 ) : substr( $text, 0, 240 );
    }

    private static function time_label( int $ts ): string {
        return function_exists( 'wp_date' ) ? (string) wp_date( 'd/m H:i', $ts ) : gmdate( 'd/m H:i', $ts );
    }

    /**
     * Adds each story's memory development hash and status (`dev_hash`, `memory_status`),
     * so a later plan can tell whether the story moved since.
     *
     * @param array $shorts A parsed plan: shorts, or one brief with `stories`.
     */
    public static function annotate( array $shorts, array $memory ): array {
        foreach ( $shorts as $i => $short ) {
            if ( ! empty( $short['brief'] ) && is_array( $short['stories'] ?? null ) ) {
                foreach ( $short['stories'] as $j => $story ) {
                    $shorts[ $i ]['stories'][ $j ] = self::annotate_one( $story, $memory );
                }
                continue;
            }
            $shorts[ $i ] = self::annotate_one( $short, $memory );
        }
        return $shorts;
    }

    private static function annotate_one( array $story, array $memory ): array {
        $key                    = self::key( (string) ( $story['story_key'] ?? '' ) );
        $story['story_key']     = $key;
        $story['dev_hash']      = '' !== $key && isset( $memory[ $key ] ) ? $memory[ $key ]['hash'] : '';
        $story['memory_status'] = '' !== $key && isset( $memory[ $key ] ) ? $memory[ $key ]['status'] : '';
        return $story;
    }

    /**
     * The stories of a plan that repeat what was told: a list of
     * {short (index), story (index inside a brief, or null), key, topic, reason}.
     *
     * @param array $shorts A parsed and annotated plan.
     */
    public static function find_repeats( array $shorts, array $told, array $memory, ?int $max_posts = null ): array {
        $max_posts = null === $max_posts ? PressHub_Social_Settings_Storage::get_story_max_posts() : $max_posts;
        $by_key    = [];
        foreach ( $told as $entry ) {
            if ( '' !== $entry['key'] ) {
                $by_key[ $entry['key'] ][] = $entry; // Newest first, as told() sorts.
            }
        }
        $repeats = [];
        $seen    = [];
        foreach ( $shorts as $i => $short ) {
            $stories = ! empty( $short['brief'] ) && is_array( $short['stories'] ?? null ) ? $short['stories'] : [ -1 => $short ];
            foreach ( $stories as $j => $story ) {
                $key   = self::key( (string) ( $story['story_key'] ?? '' ) );
                $topic = trim( (string) ( $story['topic'] ?? '' ) );
                $where = [ 'short' => (int) $i, 'story' => -1 === $j ? null : (int) $j, 'key' => $key, 'topic' => $topic ];
                if ( '' === $key ) {
                    continue;
                }
                if ( isset( $seen[ $key ] ) ) {
                    $repeats[] = $where + [ 'reason' => 'it is already in this plan' ];
                    continue;
                }
                $seen[ $key ] = true;
                $matches      = $by_key[ $key ] ?? [];
                if ( empty( $matches ) ) {
                    continue;
                }
                $last  = $matches[0];
                $label = sprintf( '%s on %s', self::time_label( (int) $last['when'] ), $last['platform'] );
                $mem   = $memory[ $key ] ?? null;
                if ( is_array( $mem ) && 'resolved' === $mem['status'] ) {
                    $repeats[] = $where + [ 'reason' => sprintf( 'told at %s and the editorial memory marks it resolved', $label ) ];
                    continue;
                }
                // Posts per platform inside the window (the told list is the window).
                $per_platform = [];
                foreach ( $matches as $m ) {
                    $per_platform[ $m['platform'] ][ $m['post_id'] ] = true;
                }
                $most_on = '';
                $most    = 0;
                foreach ( $per_platform as $platform => $posts ) {
                    if ( count( $posts ) > $most ) {
                        $most    = count( $posts );
                        $most_on = (string) $platform;
                    }
                }
                if ( $most >= $max_posts ) {
                    $repeats[] = $where + [ 'reason' => sprintf( 'posted %d times on %s inside the story window (most: %d), last at %s', $most, $most_on, $max_posts, $label ) ];
                    continue;
                }
                $whats_new = trim( (string) ( $story['whats_new'] ?? '' ) );
                if ( '' === $whats_new ) {
                    $repeats[] = $where + [ 'reason' => sprintf( 'told at %s with no new development', $label ) ];
                    continue;
                }
                if ( is_array( $mem ) && '' !== $last['dev_hash'] && $mem['hash'] === $last['dev_hash'] ) {
                    $repeats[] = $where + [ 'reason' => sprintf( 'told at %s and the editorial memory has nothing new on it since', $label ) ];
                }
            }
        }
        return $repeats;
    }

    /** One line per repeat, for the retry prompt and the logs. */
    public static function describe( array $repeats ): string {
        return implode( '; ', array_map( static function ( $r ) {
            return sprintf( '"%s" [%s]: %s', '' !== $r['topic'] ? $r['topic'] : $r['key'], $r['key'], $r['reason'] );
        }, $repeats ) );
    }

    /**
     * What the AI is told when its plan repeats stories, so the next answer replaces them.
     */
    public static function retry_prompt( string $user_prompt, array $repeats, bool $article ): string {
        return $user_prompt . "\n\n" . sprintf(
            'Your previous answer repeats stories we already posted: %s. %s Reply with valid JSON only, in the format asked above.',
            self::describe( $repeats ),
            $article
                ? 'If the article has a new fact on that story, lead with it and put it in "whats_new"; otherwise keep the answer as it is.'
                : 'Replace each of them with a story we have not posted, or, only if there is a real new fact, lead with it and put it in "whats_new".'
        );
    }

    /**
     * A podcast's or an article's plan without the repeated shorts (indexes renumbered).
     */
    public static function drop_shorts( array $shorts, array $repeats ): array {
        $drop = [];
        foreach ( $repeats as $r ) {
            if ( null === $r['story'] ) {
                $drop[ $r['short'] ] = true;
            }
        }
        return array_values( array_filter( $shorts, fn( $i ) => ! isset( $drop[ $i ] ), ARRAY_FILTER_USE_KEY ) );
    }

    /**
     * The brief's raw JSON answer without the repeated stories, for parsing again. Null when
     * it cannot be decoded, or fewer than two stories would be left.
     */
    public static function drop_brief_stories( string $raw, array $repeats ): ?string {
        $fixes   = [];
        $error   = null;
        $decoded = PressHub_Social_AI_Json::decode( $raw, $fixes, $error );
        if ( ! is_array( $decoded ) ) {
            return null;
        }
        $in_list = is_array( $decoded['shorts'] ?? null );
        $item    = $in_list ? ( array_values( $decoded['shorts'] )[0] ?? null ) : $decoded;
        if ( ! is_array( $item ) || ! is_array( $item['stories'] ?? null ) ) {
            return null;
        }
        $drop = [];
        foreach ( $repeats as $r ) {
            if ( null !== $r['story'] ) {
                $drop[ $r['story'] ] = true;
            }
        }
        $stories = array_values( array_filter( array_values( $item['stories'] ), fn( $j ) => ! isset( $drop[ $j ] ), ARRAY_FILTER_USE_KEY ) );
        if ( count( $stories ) < 2 ) {
            return null;
        }
        $item['stories'] = $stories;
        $out = $in_list ? [ 'shorts' => [ $item ] ] : $item;
        return (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $out ) : json_encode( $out ) );
    }

    private static function decode( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        $decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
        return is_array( $decoded ) ? $decoded : [];
    }
}
